<?php
namespace WorkforceOne\People;

use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\People\Data\ReportingStore;
use WorkforceOne\People\Data\TeamStore;

if (!defined('ABSPATH')) exit;

/**
 * Who reports to whom, and who manages what (3.31.133, docs/tasks/people-store.md): the reporting lines, the departments' and the
 * teams' reads for the rest of the plugin. No SQL, never the plugin object. Every method does what the plugin's function did,
 * including the order of its queries (the table check before the rows).
 */
final class OrgDirectory
{
    /** @var PeopleStore */
    private $people;
    /** @var ReportingStore */
    private $reporting;
    /** @var DepartmentStore */
    private $departments;
    /** @var TeamStore */
    private $teams;

    public function __construct(PeopleStore $people, ReportingStore $reporting, DepartmentStore $departments, TeamStore $teams)
    {
        $this->people = $people;
        $this->reporting = $reporting;
        $this->departments = $departments;
        $this->teams = $teams;
    }

    /** The active employee related to this one by $type (the newest line), or null. */
    public function relatedEmployee(int $employeeId, string $type): ?object
    {
        return $this->reporting->relatedActive($employeeId, $type);
    }

    /**
     * Each employee's active supervisor id (0 = none) for many employees, one query per 500; the same answer as
     * relatedEmployee($id,'supervisor') per employee (the newest line).
     * @param list<int> $employeeIds @return array<int,int>
     */
    public function supervisorIds(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        $out = array_fill_keys($ids, 0);
        foreach ($this->reporting->supervisorRowsOf($ids) as $r) {
            if (empty($out[(int) $r->employee_id])) $out[(int) $r->employee_id] = (int) $r->related_employee_id;
        }
        return $out;
    }

    /** The manager an employee has now (0 = none), archived or not. */
    public function managerOf(int $employeeId): int
    {
        return $this->reporting->currentManagerId($employeeId);
    }

    /** Each active employee's direct manager (0 = none), from the newest supervisor line. @return array<int,int> */
    public function managerIds(): array
    {
        $out = [];
        foreach ($this->people->activeIds(false) as $id) $out[(int) $id] = 0;
        if (!$out) return $out;
        if (!$this->reporting->tableExists()) return $out;
        foreach ($this->reporting->activeSupervisorRows() as $r) {
            $e = (int) $r->employee_id;
            if (isset($out[$e]) && !$out[$e]) $out[$e] = (int) $r->related_employee_id;
        }
        return $out;
    }

    /** Does an active employee report directly to this one? */
    public function hasReports(int $employeeId): bool
    {
        if (!$this->reporting->tableExists()) return false;
        return $this->reporting->hasActiveReport($employeeId);
    }

    /** The active employees as the chart shows them, by id. @return array<int,object> */
    public function orgRows(): array
    {
        $has = $this->departments->tableExists();
        $out = [];
        foreach ($this->people->orgRows($has) as $r) $out[(int) $r->id] = $r;
        return $out;
    }

    /** The active manager of an employee's department (null: no department, or no manager). */
    public function departmentManager(int $employeeId): ?object
    {
        return $this->departments->activeManagerOf($employeeId);
    }

    public function teamManagerFor(int $employeeId): ?object
    {
        return $this->teams->activeManagerFor($employeeId);
    }

    /** @return list<int> */
    public function teamMemberIds(int $teamId): array
    {
        return array_map('intval', $this->teams->activeMemberIds($teamId));
    }

    /** @return list<int> */
    public function teamIdsOf(int $employeeId): array
    {
        return array_map('intval', $this->teams->activeTeamIdsOf($employeeId));
    }

    /** teamIdsOf() for many employees in one query per 500. @param list<int> $employeeIds @return array<int,list<int>> */
    public function teamIdsByEmployee(array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        $out = array_fill_keys($ids, []);
        foreach ($this->teams->activeTeamRowsByEmployees($ids) as $r) $out[(int) $r->employee_id][] = (int) $r->team_id;
        return $out;
    }
}
