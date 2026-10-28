<?php
namespace WorkforceOne\People;

use WorkforceOne\Organization\OrgRules;
use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\People\Data\TeamStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/** The teams page's writes and reads, and the teams of one employee (3.31.134, moved from trait-teams.php). */
final class TeamService
{
    /** @var TeamStore */
    private $store;
    /** @var DepartmentStore */
    private $departments;
    /** @var PeopleStore */
    private $peopleStore;
    /** @var PeopleDirectory */
    private $people;
    /** @var Transaction */
    private $tx;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(TeamStore $store, DepartmentStore $departments, PeopleStore $employees, PeopleDirectory $people, Transaction $tx, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->departments = $departments;
        $this->peopleStore = $employees;
        $this->people = $people;
        $this->tx = $tx;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    public function activeById(int $id): ?object
    {
        return $this->store->activeById($id);
    }

    /** @return list<int> */
    public function memberIds(int $teamId): array
    {
        return array_map('intval', $this->store->activeMemberIds($teamId));
    }

    /** @return list<object> */
    public function adminList(): array
    {
        return $this->store->adminList();
    }

    /** @return list<object> */
    public function pageEmployees(): array
    {
        return $this->store->pageEmployees();
    }

    /** @return list<object> */
    public function pageDepartments(): array
    {
        return $this->store->pageDepartments();
    }

    /**
     * Creates or edits a team with its members, in one transaction. The caller has cleaned the values and put the manager among the members.
     *
     * @param list<int> $members
     * @return array{error:string,rolled_back:bool,id:int} error '' when saved; rolled_back: the transaction was undone here (else none was open, and
     *         the caller's own ROLLBACK, kept from before, is the only one sent)
     */
    public function save(int $id, int $departmentId, string $name, string $description, int $manager, array $members): array
    {
        $inDepartment = function (int $employeeId) use ($departmentId): bool { return (bool) $this->people->inDepartment($employeeId, $departmentId); };
        $error = OrgRules::teamError([
            'name' => $name, 'department' => $departmentId, 'manager' => $manager,
            'department_active' => (bool) $this->departments->activeId($departmentId),
            'manager_in_department' => $manager && $inDepartment($manager),
            'members_in_department' => count(array_filter($members, $inDepartment)) === count($members),
            'duplicate' => OrgRules::duplicate($this->store->duplicateFlags($name, $id)),
        ]);
        if ($error !== '') return ['error' => $error, 'rolled_back' => false, 'id' => $id];
        $now = $this->clock->now();
        $isNew = !$id;
        if (!$this->tx->begin()) return ['error' => 'save', 'rolled_back' => false, 'id' => $id];
        if ($id) {
            $ok = $this->store->update($id, $name, $description, $manager, $departmentId, $now);
        } else {
            $ok = $this->store->insert($name, $description, $manager, $departmentId, $now);
            $id = $this->store->insertId();
        }
        if ($ok === false || !$id) return $this->undone($id);
        $this->store->deactivateMembersOfTeam($id, $now);
        foreach ($members as $employeeId) {
            $existing = $this->store->memberId($id, $employeeId);
            $ok = $existing ? $this->store->reactivateMember((int) $existing, $now) : $this->store->insertMember($id, $employeeId, $now);
            if ($ok === false) return $this->undone($id);
        }
        if (!$this->tx->commit()) return $this->undone($id);
        $this->audit->record($isNew ? 'team_create' : 'team_update', 'team', $id, $name . ' / manager=' . $manager . ' / members=' . count($members));
        return ['error' => '', 'rolled_back' => false, 'id' => $id];
    }

    /** @return array{error:string,rolled_back:bool,id:int} */
    private function undone(int $id): array
    {
        $this->tx->rollback();
        return ['error' => 'save', 'rolled_back' => true, 'id' => $id];
    }

    /**
     * Archives a team and ends its memberships (no transaction, as before).
     *
     * @return string|null null when the team was archived, else the database's error text
     */
    public function archive(int $id): ?string
    {
        if ($this->store->archive($id, $this->clock->now()) === false) return $this->store->lastError();
        $this->store->deactivateAllMembers($id, $this->clock->now());
        $this->audit->record('team_archive', 'team', $id, '');
        return null;
    }

    /**
     * Sets the teams of one employee (the Employees page). Every team must be an active team of the employee's department; the teams the employee
     * manages there are kept. Every membership is ended first, then the chosen ones are added back (no transaction, as before).
     *
     * @param list<int> $teamIds
     * @return array{code:string,message:string}|null null when saved, else the error
     */
    public function syncEmployee(int $employeeId, array $teamIds): ?array
    {
        $teamIds = array_values(array_unique(array_filter($teamIds)));
        $now = $this->clock->now();
        $department = (int) $this->peopleStore->departmentIdOf($employeeId);
        foreach ($teamIds as $teamId) {
            $teamDepartment = $this->store->activeDepartmentOf($teamId);
            if ($teamDepartment === null || (int) $teamDepartment !== $department) return ['code' => 'team_department_mismatch', 'message' => 'Selected team does not belong to the employee department.'];
        }
        $managed = $this->store->managedIn($employeeId, $department);
        $teamIds = array_values(array_unique(array_merge($teamIds, array_map('intval', $managed))));
        $this->store->deactivateMembershipsOf($employeeId, $now);
        foreach ($teamIds as $teamId) {
            if (!$this->store->activeId($teamId)) continue;
            $memberId = $this->store->memberId($teamId, $employeeId);
            $ok = $memberId ? $this->store->reactivateMember((int) $memberId, $now) : $this->store->insertMember($teamId, $employeeId, $now);
            if ($ok === false) return ['code' => 'team_membership_save', 'message' => 'Could not save team membership: ' . $this->store->lastError()];
        }
        return null;
    }
}
