<?php
namespace WorkforceOne\People;

use WorkforceOne\Employees\EmployeeRules;
use WorkforceOne\Organization\OrgTree;
use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\People\Data\TeamStore;
use WorkforceOne\Platform\AuditLog;

if (!defined('ABSPATH')) exit;

/**
 * Adding, updating and archiving an employee (3.31.135, moved from trait-employee-admin.php) and the page reads of the Employees page and the
 * people directory. The controller reads and cleans the form, checks the permission and the nonce, and redirects; this class gathers the facts for
 * EmployeeRules, writes in the order of the old code and returns where the old handler would have redirected. It never opens a transaction: the
 * shift history and the org change keep their own, and MySQL's START TRANSACTION would commit an open one.
 */
final class EmployeeService
{
    /** @var PeopleStore */
    private $store;
    /** @var DepartmentStore */
    private $departments;
    /** @var TeamStore */
    private $teams;
    /** @var PeopleDirectory */
    private $people;
    /** @var OrgDirectory */
    private $org;
    /** @var OrgMoveService */
    private $moves;
    /** @var TeamService */
    private $teamService;
    /** @var AuditLog */
    private $audit;
    /** @var PeoplePorts */
    private $ports;

    public function __construct(PeopleStore $store, DepartmentStore $departments, TeamStore $teams, PeopleDirectory $people, OrgDirectory $org, OrgMoveService $moves,
        TeamService $teamService, AuditLog $audit, PeoplePorts $ports)
    {
        $this->store = $store;
        $this->departments = $departments;
        $this->teams = $teams;
        $this->people = $people;
        $this->org = $org;
        $this->moves = $moves;
        $this->teamService = $teamService;
        $this->audit = $audit;
        $this->ports = $ports;
    }

    /** The employee before an update (active, department, user, last working day, shift), or null. */
    public function former(int $id): ?object
    {
        return $this->store->former($id);
    }

    /** The employee's state for an archive (active, user), or null. */
    public function status(int $id): ?object
    {
        return $this->store->status($id);
    }

    /**
     * Checks a posted employee. The facts are read in the order of the old code and only when they are needed.
     *
     * @param array<string,mixed> $in the cleaned form
     * @return string|null error code (EmployeeRules)
     */
    public function check(array $in, bool $emailValid, int $id, ?int $oldDepartment): ?string
    {
        $facts = [
            'id' => $id, 'name' => $in['name'], 'domain' => $in['domain_name'], 'email_valid' => $emailValid,
            'department_ok' => !$in['department_id'] || (bool) $this->departments->activeIdUnlimited((int) $in['department_id']),
            'domain_taken' => (bool) $this->store->domainOwner((string) $in['domain_name'], $id),
            'user_ok' => $in['wp_user_id'] === 0 || ($in['wp_user_id'] > 0 && $this->ports->users->exists((int) $in['wp_user_id'])),
            'user_taken' => $in['wp_user_id'] > 0 && (bool) $this->store->userOwner((int) $in['wp_user_id'], $id),
            'supervisor_id' => $in['supervisor_id'],
            'title_missing' => !$id && $in['job_title'] === '' ? $this->ports->org->titleRequired() : false,
        ];
        if ($in['supervisor_id']) {
            $facts['supervisor_ok'] = (bool) $this->people->activeId((int) $in['supervisor_id']);
            $above = $this->org->relatedEmployee((int) $in['supervisor_id'], 'supervisor');
            $facts['supervisors_supervisor'] = $above ? (int) $above->id : 0;
            // At any level (3.31.97): the new supervisor must not report to this employee.
            if ($id) $facts['supervisor_reports_to_employee'] = (new OrgTree($this->org->managerIds()))->wouldLoop($id, (int) $in['supervisor_id']);
        }
        if ($in['team_ids']) {
            $facts['teams_in_department'] = $this->teams->countActiveInDepartment((int) $in['department_id'], $in['team_ids']) === count($in['team_ids']);
        }
        // Only a department change can strand the teams this employee manages.
        if ($id && $oldDepartment !== null && $oldDepartment !== $in['department_id']) $facts['manages_team_elsewhere'] = (bool) $this->teams->managesElsewhere($id, (int) $in['department_id']);
        return EmployeeRules::check($facts);
    }

    /**
     * Adds an employee: the row, the first shift in the history (its result is not looked at: quirk t) and the supervisor.
     *
     * @param array<string,mixed> $in the cleaned and checked form
     * @return string|null error code ('save') or null when saved
     */
    public function create(array $in): ?string
    {
        $row = ['name' => $in['name'], 'domain_name' => $in['domain_name'], 'email' => $in['email'], 'wp_user_id' => $in['wp_user_id'], 'default_shift_id' => $in['default_shift_id'],
            'attendance_enabled' => $in['attendance_enabled'], 'department_id' => $in['department_id']] + ($in['job_title'] !== null && $in['job_title'] !== '' ? ['job_title' => $in['job_title']] : []);
        if ($this->store->insert($row) === false) return 'save';
        $id = $this->store->insertId();
        $this->ports->changed->changed($id, (int) $in['wp_user_id']);
        $this->ports->shifts->fromBeginning($id, (int) $in['default_shift_id']);
        if (!$this->moves->setSupervisorOnly($id, (int) $in['supervisor_id'])) return 'save';
        $this->audit->record('employee_create', 'employee', $id, $in['name'] . ' / ' . $in['domain_name']);
        return null;
    }

    /**
     * Saves an employee: the new shift first (3.31.119), the row, the sessions of a user who is gone, the supervisor (as a move while the chart is on),
     * the teams, the Audit Log, and the advance still owed by someone made inactive.
     *
     * @param array<string,mixed> $in the cleaned and checked form
     * @param string|null $lastDay the last working day for an inactive employee (null when active)
     * @return array{error:?string,notice:string,advance_left:float} error: where the old handler redirected with an error ('save' or 'teams')
     */
    public function update(int $id, object $old, array $in, ?string $lastDay): array
    {
        $fail = function (string $code): array { return ['error' => $code, 'notice' => '', 'advance_left' => 0.0]; };
        // A new shift here counts from today; the days before keep the old one. Saved first and checked: a shift that could not be saved changes nothing else.
        if ((int) $old->default_shift_id !== (int) $in['default_shift_id'] && $this->ports->shifts->from($id, (int) $in['default_shift_id'], $this->ports->clock->today()) !== true) return $fail('save');
        // default_shift_id is not written here (3.31.119): the shift history sets it under its lock.
        $fields = ['name' => $in['name'], 'domain_name' => $in['domain_name'], 'email' => $in['email'], 'wp_user_id' => $in['wp_user_id'], 'attendance_enabled' => $in['attendance_enabled'],
            'department_id' => $in['department_id'], 'active' => $in['active'], 'last_working_day' => $lastDay, 'updated_at' => $this->ports->clock->now()]
            + ($in['job_title'] !== null ? ['job_title' => $in['job_title'] !== '' ? $in['job_title'] : null] : []);
        if ($this->store->updateFields($id, $fields) === false) return $fail('save');
        $this->ports->changed->changed($id, (int) $in['wp_user_id']);
        // Made inactive, or linked to another WordPress user: the previous user is signed out of the app.
        if (!empty($old->wp_user_id) && (((int) $old->active === 1 && !$in['active']) || (int) $old->wp_user_id !== (int) $in['wp_user_id'])) {
            $this->ports->sessions->revoke((int) $old->wp_user_id, $in['active'] ? 'employee_unlinked' : 'employee_archived');
        }
        $oldSupervisor = $this->org->managerOf($id);
        $newSupervisor = (int) $in['supervisor_id'];
        if ($this->ports->org->enabled() && $oldSupervisor !== $newSupervisor) {
            // Kept as a move in the organization chart's history while the chart is on, with the waiting requests: all or nothing.
            $today = $this->ports->clock->today();
            $r = $this->moves->supervisorChange($id, $oldSupervisor, $newSupervisor, ['employee_id' => $id, 'from_manager_id' => $oldSupervisor, 'to_manager_id' => $newSupervisor,
                'effective_date' => $today, 'reason' => '', 'status' => 'done', 'source' => 'employees']);
            if (!$r['ok']) return $fail('save');
            $this->audit->record('org_move', 'employee', $id, $this->moves->summary($id, $oldSupervisor, $newSupervisor, $this->ports->clock->today(), '') . ($r['requests'] ? ' · ' . $r['requests'] . ' waiting approval step(s) moved' : '') . ' · employees');
        } elseif (!$this->moves->setSupervisorOnly($id, $newSupervisor)) {
            return $fail('save');
        }
        if ($this->teamService->syncEmployee($id, $in['team_ids']) !== null) return $fail('teams');
        $this->audit->record('employee_update', 'employee', $id, $in['name'] . ' / ' . $in['domain_name'] . ' / ' . $in['email'] . ' / teams=' . count($in['team_ids']));
        if ((int) $old->active !== $in['active']) {
            $this->audit->record('employee_status_change', 'employee', $id, 'Status: ' . ((int) $old->active ? 'Active' : 'Inactive') . ' -> ' . ($in['active'] ? 'Active' : 'Inactive') . ($lastDay ? ' / last working day ' . $lastDay : ''));
        }
        // Made inactive with an advance still open: the rest comes off the last month's pay.
        $left = (int) $old->active === 1 && !$in['active'] && $this->ports->advances->enabled() ? $this->ports->advances->leftFor($id) : 0.0;
        return ['error' => null, 'notice' => 'updated', 'advance_left' => $left];
    }

    /**
     * Archives an employee (inactive, with the last working day), ends the sessions of their user and audits the change. An already archived employee
     * is archived again without an audit row (quirk u).
     *
     * @return array{error:?string,notice:string,advance_left:float}
     */
    public function archive(int $id, object $row, string $lastDay): array
    {
        if ($this->store->archive($id, $lastDay, $this->ports->clock->now()) === false) return ['error' => 'save', 'notice' => '', 'advance_left' => 0.0];
        $this->ports->changed->changed($id, 0);
        // An archived employee is signed out of the app on every device.
        if (!empty($row->wp_user_id)) $this->ports->sessions->revoke((int) $row->wp_user_id, 'employee_archived');
        $old = $row->active;
        if ((int) $old !== 0) $this->audit->record('employee_status_change', 'employee', $id, 'Status: Active -> Inactive / last working day ' . $lastDay);
        // Still owing a salary advance: the rest comes off the last pay.
        $left = (int) $old !== 0 && $this->ports->advances->enabled() ? $this->ports->advances->leftFor($id) : 0.0;
        return ['error' => null, 'notice' => 'archived', 'advance_left' => $left];
    }

    /** @return int how many employees match the search (any state) */
    public function adminCount(string $search): int
    {
        return $this->store->adminCount($search);
    }

    /** @return list<object> */
    public function adminPage(string $search, int $perPage, int $offset): array
    {
        return $this->store->adminPage($search, $perPage, $offset);
    }

    /** @return list<object> every employee for the supervisor pick list */
    public function pickList(): array
    {
        return $this->store->pickList();
    }

    /** @return list<object> */
    public function pageTeams(): array
    {
        return $this->teams->pageTeams();
    }

    /** @return list<object> */
    public function pageDepartments(): array
    {
        return $this->teams->pageDepartments();
    }

    /** @return list<string> */
    public function activeTitles(): array
    {
        return $this->store->activeTitles();
    }

    /** @return list<object> */
    public function contactList(): array
    {
        return $this->store->contactList();
    }

    /** @return list<object> id and name of these teams, any state */
    public function teamNamesByIds(array $ids): array
    {
        return $this->teams->namesByIds($ids);
    }

    /** @param list<int>|null $ids @return list<object> */
    public function directory(string $search, ?array $ids): array
    {
        return $this->store->directory($search, $ids);
    }

    /** @return list<object> */
    public function activeTeams(): array
    {
        return $this->teams->activeList();
    }

    /** @return list<string> the active members of a team */
    public function teamMemberIds(int $teamId): array
    {
        return $this->teams->activeMemberIds($teamId);
    }

    public function publicProfile(int $id): ?object
    {
        return $this->store->publicProfile($id);
    }

    /** @param list<int> $ids @return list<string> */
    public function activeTeamNames(array $ids): array
    {
        return $this->teams->activeNames($ids);
    }

    /** @param list<int> $ids @return list<object> */
    public function activeTeamNameRows(array $ids): array
    {
        return $this->teams->activeNameRows($ids);
    }

    /** @return list<object> the active teams of an employee, with their managers */
    public function teamsOfWithManager(int $employeeId): array
    {
        return $this->teams->teamsOfWithManager($employeeId);
    }
}
