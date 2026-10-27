<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\ReportingStore;
use WorkforceOne\People\Data\TeamStore;

/** In-memory ReportingStore for the unit tests (3.31.133): lines are [id, employee, related, type, active]. */
final class MemoryReportingStore implements ReportingStore
{
    /** @var list<array{0:int,1:int,2:int|null,3:string,4:int}> */
    public $lines = [];
    /** @var bool */
    public $exists = true;
    /** @var MemoryPeopleStore */
    public $people;
    /** @var list<string> */
    public $calls = [];

    public function __construct(MemoryPeopleStore $people)
    {
        $this->people = $people;
    }

    public function tableExists(): bool
    {
        $this->calls[] = 'tableExists';
        return $this->exists;
    }

    public function relatedActive(int $employeeId, string $type): ?object
    {
        $this->calls[] = 'relatedActive';
        $best = null;
        foreach ($this->lines as $l) {
            if ($l[1] === $employeeId && $l[3] === $type && $l[4] === 1 && isset($this->people->rows[$l[2]]) && $this->people->rows[$l[2]]->active === '1' && (!$best || $l[0] > $best[0])) $best = $l;
        }
        return $best ? clone $this->people->rows[$best[2]] : null;
    }

    public function supervisorRowsOf(array $ids): array
    {
        $this->calls[] = 'supervisorRowsOf ' . implode(',', $ids);
        $rows = array_filter($this->lines, function ($l) use ($ids) {
            return in_array($l[1], $ids, true) && $l[3] === 'supervisor' && $l[4] === 1 && isset($this->people->rows[$l[2]]) && $this->people->rows[$l[2]]->active === '1';
        });
        usort($rows, function ($a, $b) { return $b[0] <=> $a[0]; });
        return array_map(function ($l) { return (object) ['employee_id' => (string) $l[1], 'related_employee_id' => (string) $l[2]]; }, $rows);
    }

    public function currentManagerId(int $employeeId): int
    {
        $this->calls[] = 'currentManagerId';
        $best = null;
        foreach ($this->lines as $l) if ($l[1] === $employeeId && $l[3] === 'supervisor' && $l[4] === 1 && $l[2] !== null && (!$best || $l[0] > $best[0])) $best = $l;
        return $best ? (int) $best[2] : 0;
    }

    public function activeSupervisorRows(): array
    {
        $this->calls[] = 'activeSupervisorRows';
        $rows = array_filter($this->lines, function ($l) { return $l[3] === 'supervisor' && $l[4] === 1 && $l[2] !== null; });
        usort($rows, function ($a, $b) { return $b[0] <=> $a[0]; });
        return array_values(array_map(function ($l) { return (object) ['employee_id' => (string) $l[1], 'related_employee_id' => (string) $l[2]]; }, $rows));
    }

    public function hasActiveReport(int $employeeId): bool
    {
        $this->calls[] = 'hasActiveReport';
        foreach ($this->lines as $l) {
            if ($l[2] === $employeeId && $l[3] === 'supervisor' && $l[4] === 1 && isset($this->people->rows[$l[1]]) && $this->people->rows[$l[1]]->active === '1') return true;
        }
        return false;
    }
}

/** In-memory DepartmentStore for the unit tests (3.31.133). */
final class MemoryDepartmentStore implements DepartmentStore
{
    /** @var bool */
    public $exists = true;
    /** @var array<int,array{active:int,manager:int}> department id => state */
    public $departments = [];
    /** @var MemoryPeopleStore */
    public $people;

    public function __construct(MemoryPeopleStore $people)
    {
        $this->people = $people;
    }

    public function tableExists(): bool
    {
        return $this->exists;
    }

    public function activeManagerOf(int $employeeId): ?object
    {
        $e = $this->people->rows[$employeeId] ?? null;
        $d = $e ? ($this->departments[(int) $e->department_id] ?? null) : null;
        $m = $d && $d['active'] === 1 ? ($this->people->rows[$d['manager']] ?? null) : null;
        return $m && $m->active === '1' ? clone $m : null;
    }
}

/** In-memory TeamStore for the unit tests (3.31.133): teams are id => [active, manager], members [team, employee, active]. */
final class MemoryTeamStore implements TeamStore
{
    /** @var array<int,array{0:int,1:int}> */
    public $teams = [];
    /** @var list<array{0:int,1:int,2:int}> */
    public $members = [];
    /** @var MemoryPeopleStore */
    public $people;

    public function __construct(MemoryPeopleStore $people)
    {
        $this->people = $people;
    }

    public function activeManagerFor(int $employeeId): ?object
    {
        $ids = [];
        foreach ($this->members as $m) if ($m[1] === $employeeId && $m[2] === 1 && ($this->teams[$m[0]][0] ?? 0) === 1) $ids[] = $m[0];
        sort($ids);
        foreach ($ids as $t) {
            $mgr = $this->people->rows[$this->teams[$t][1]] ?? null;
            if ($mgr && $mgr->active === '1') return (object) ((array) $mgr + ['team_id' => (string) $t, 'team_name' => 'T' . $t]);
        }
        return null;
    }

    public function activeMemberIds(int $teamId): array
    {
        $out = [];
        foreach ($this->members as $m) if ($m[0] === $teamId && $m[2] === 1) $out[] = (string) $m[1];
        return $out;
    }

    public function activeTeamIdsOf(int $employeeId): array
    {
        $out = [];
        foreach ($this->members as $m) if ($m[1] === $employeeId && $m[2] === 1) $out[] = $m[0];
        sort($out);
        return array_map('strval', $out);
    }

    public function activeTeamRowsByEmployees(array $ids): array
    {
        $rows = array_filter($this->members, function ($m) use ($ids) { return in_array($m[1], $ids, true) && $m[2] === 1; });
        usort($rows, function ($a, $b) { return $a[0] <=> $b[0]; });
        return array_values(array_map(function ($m) { return (object) ['employee_id' => (string) $m[1], 'team_id' => (string) $m[0]]; }, $rows));
    }
}
