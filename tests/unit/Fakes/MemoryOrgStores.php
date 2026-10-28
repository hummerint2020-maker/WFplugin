<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\OrgMoveStore;
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

    /** @var OrgWorld|null */
    public $world;
    /** @var int */
    public $nextId = 100;
    /** @var string */
    public $error = '';

    private function w(string $op): bool
    {
        if ($this->world) $this->world->note('reporting ' . $op);
        return $this->world !== null && $this->world->fails('reporting.' . strtok($op, ' '));
    }

    public function deactivate(int $employeeId, string $type, string $now)
    {
        if ($this->w('deactivate ' . $employeeId . ' ' . $type)) { $this->error = 'deactivate failed'; return false; }
        $n = 0;
        foreach ($this->lines as &$l) if ($l[1] === $employeeId && $l[3] === $type && $l[4] === 1) { $l[4] = 0; $n++; }
        return $n;
    }

    public function deactivateSupervisor(int $employeeId, string $now)
    {
        if ($this->w('deactivateSupervisor ' . $employeeId)) { $this->error = 'deactivate failed'; return false; }
        $n = 0;
        foreach ($this->lines as &$l) if ($l[1] === $employeeId && $l[3] === 'supervisor' && $l[4] === 1) { $l[4] = 0; $n++; }
        return $n;
    }

    public function insertLine(int $employeeId, string $type, int $relatedEmployeeId, int $relatedWpUserId, string $now)
    {
        if ($this->w('insertLine ' . $employeeId . ' ' . $type . ' ' . $relatedEmployeeId . ' ' . $relatedWpUserId)) { $this->error = 'insert failed'; return false; }
        $this->lines[] = [++$this->nextId, $employeeId, $relatedEmployeeId ?: null, $type, 1];
        return 1;
    }

    public function insertId(): int
    {
        return $this->nextId;
    }

    public function lastError(): string
    {
        return $this->error;
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

    /** @var OrgWorld|null */
    public $world;
    /** @var array<int,object> id => row (name, code, description, manager_employee_id, active) */
    public $rows = [];
    /** @var int */
    public $nextId = 50;

    private function w(string $op): bool
    {
        if ($this->world) $this->world->note('departments ' . $op);
        return $this->world !== null && $this->world->fails('departments.' . strtok($op, ' '));
    }

    public function byId(int $id): ?object
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function adminList(): array
    {
        $rows = array_filter($this->rows, function ($r) { return (int) $r->active === 1; });
        usort($rows, function ($a, $b) { return strcmp($a->name, $b->name); });
        return array_values($rows);
    }

    public function duplicateFlags(string $name, string $code, int $exceptId): array
    {
        $out = [];
        foreach ($this->rows as $id => $r) if ($id !== $exceptId && ($r->name === $name || $r->code === $code)) $out[] = (string) $r->active;
        return $out;
    }

    public function update(int $id, string $name, string $code, string $description, int $manager, string $now)
    {
        if ($this->w('update ' . $id)) return false;
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]->name = $name;
        $this->rows[$id]->code = $code;
        $this->rows[$id]->description = $description;
        $this->rows[$id]->manager_employee_id = $manager ?: null;
        return 1;
    }

    public function insert(string $name, string $code, string $description, int $manager, string $now)
    {
        if ($this->w('insert ' . $code)) return false;
        $this->rows[++$this->nextId] = (object) ['id' => (string) $this->nextId, 'name' => $name, 'code' => $code, 'description' => $description, 'manager_employee_id' => $manager ?: null, 'active' => '1'];
        return 1;
    }

    public function insertId(): int
    {
        return $this->nextId;
    }

    public function archive(int $id, string $now)
    {
        if ($this->w('archive ' . $id)) return false;
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]->active = '0';
        return 1;
    }

    public function activeEmployeeCount(int $departmentId): int
    {
        return count(array_filter($this->people->rows, function ($r) use ($departmentId) { return $r->active === '1' && (int) $r->department_id === $departmentId; }));
    }

    public function activeId(int $id): ?string
    {
        return isset($this->rows[$id]) && (int) $this->rows[$id]->active === 1 ? (string) $id : null;
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

    /** @var OrgWorld|null */
    public $world;
    /** @var array<int,object> id => row (name, description, manager_employee_id, department_id, active), for the writes */
    public $rows = [];
    /** @var int */
    public $nextId = 70;
    /** @var int */
    public $nextMember = 700;
    /** @var bool */
    public $exists = true;
    /** @var string */
    public $error = '';

    private function w(string $op): bool
    {
        if ($this->world) $this->world->note('teams ' . $op);
        return $this->world !== null && $this->world->fails('teams.' . strtok($op, ' '));
    }

    public function tableExists(): bool
    {
        return $this->exists;
    }

    public function activeCountInDepartment(int $departmentId): int
    {
        return count(array_filter($this->rows, function ($r) use ($departmentId) { return (int) $r->active === 1 && (int) $r->department_id === $departmentId; }));
    }

    public function activeById(int $id): ?object
    {
        return isset($this->rows[$id]) && (int) $this->rows[$id]->active === 1 ? clone $this->rows[$id] : null;
    }

    public function adminList(): array
    {
        return array_values(array_filter($this->rows, function ($r) { return (int) $r->active === 1; }));
    }

    public function pageEmployees(): array
    {
        return array_values(array_filter($this->people->rows, function ($r) { return $r->active === '1'; }));
    }

    public function pageDepartments(): array
    {
        return [];
    }

    public function duplicateFlags(string $name, int $exceptId): array
    {
        $out = [];
        foreach ($this->rows as $id => $r) if ($id !== $exceptId && $r->name === $name) $out[] = (string) $r->active;
        return $out;
    }

    public function update(int $id, string $name, string $description, int $manager, int $departmentId, string $now)
    {
        if ($this->w('update ' . $id)) { $this->error = 'update failed'; return false; }
        if (!isset($this->rows[$id])) return 0;
        $r = $this->rows[$id];
        $r->name = $name;
        $r->description = $description;
        $r->manager_employee_id = $manager;
        $r->department_id = $departmentId;
        return 1;
    }

    public function insert(string $name, string $description, int $manager, int $departmentId, string $now)
    {
        if ($this->w('insert ' . $name)) { $this->error = 'insert failed'; return false; }
        $this->rows[++$this->nextId] = (object) ['id' => (string) $this->nextId, 'name' => $name, 'description' => $description, 'manager_employee_id' => $manager, 'department_id' => $departmentId, 'active' => '1'];
        $this->teams[$this->nextId] = [1, $manager];
        return 1;
    }

    public function insertId(): int
    {
        return $this->nextId;
    }

    public function deactivateMembersOfTeam(int $teamId, string $now)
    {
        $this->w('deactivateMembersOfTeam ' . $teamId);
        $n = 0;
        foreach ($this->members as &$m) if ($m[0] === $teamId && $m[2] === 1) { $m[2] = 0; $n++; }
        return $n;
    }

    public function memberId(int $teamId, int $employeeId): ?string
    {
        foreach ($this->members as $i => $m) if ($m[0] === $teamId && $m[1] === $employeeId) return (string) ($i + 1);
        return null;
    }

    public function reactivateMember(int $memberId, string $now)
    {
        if ($this->w('reactivateMember ' . $memberId)) { $this->error = 'member update failed'; return false; }
        $this->members[$memberId - 1][2] = 1;
        return 1;
    }

    public function insertMember(int $teamId, int $employeeId, string $now)
    {
        if ($this->w('insertMember ' . $teamId . ' ' . $employeeId)) { $this->error = 'member insert failed'; return false; }
        $this->members[] = [$teamId, $employeeId, 1];
        return 1;
    }

    public function archive(int $id, string $now)
    {
        if ($this->w('archive ' . $id)) { $this->error = 'archive failed'; return false; }
        if (!isset($this->rows[$id])) return 0;
        $this->rows[$id]->active = '0';
        if (isset($this->teams[$id])) $this->teams[$id][0] = 0;
        return 1;
    }

    public function deactivateAllMembers(int $teamId, string $now)
    {
        $this->w('deactivateAllMembers ' . $teamId);
        $n = 0;
        foreach ($this->members as &$m) if ($m[0] === $teamId) { $m[2] = 0; $n++; }
        return $n;
    }

    public function activeDepartmentOf(int $teamId): ?string
    {
        return isset($this->rows[$teamId]) && (int) $this->rows[$teamId]->active === 1 ? (string) $this->rows[$teamId]->department_id : null;
    }

    public function managedIn(int $employeeId, int $departmentId): array
    {
        $out = [];
        foreach ($this->rows as $id => $r) if ((int) $r->active === 1 && (int) $r->manager_employee_id === $employeeId && (int) $r->department_id === $departmentId) $out[] = (string) $id;
        return $out;
    }

    public function deactivateMembershipsOf(int $employeeId, string $now)
    {
        $this->w('deactivateMembershipsOf ' . $employeeId);
        $n = 0;
        foreach ($this->members as &$m) if ($m[1] === $employeeId && $m[2] === 1) { $m[2] = 0; $n++; }
        return $n;
    }

    public function activeId(int $teamId): ?string
    {
        return isset($this->rows[$teamId]) && (int) $this->rows[$teamId]->active === 1 ? (string) $teamId : null;
    }

    public function lastError(): string
    {
        return $this->error;
    }
}

/**
 * In-memory OrgMoveStore for the unit tests (3.31.134): rows are objects with string values. Claims follow the SQL: a conditional change counts
 * the rows it changed (1 or 0), false when the operation is set to fail.
 */
final class MemoryOrgMoveStore implements OrgMoveStore
{
    /** @var array<int,object> */
    public $rows = [];
    /** @var int */
    public $nextId = 0;
    /** @var bool */
    public $exists = true;
    /** @var OrgWorld|null */
    public $world;

    /** Adds a row; the fields not given get the table's defaults. @param array<string,mixed> $more */
    public function add(string $status, int $employee, int $to, string $date, array $more = []): int
    {
        $id = ++$this->nextId;
        $this->rows[$id] = (object) ($more + ['id' => (string) $id, 'employee_id' => (string) $employee, 'from_manager_id' => '0', 'to_manager_id' => (string) $to, 'effective_date' => $date,
            'reason' => 'r', 'status' => $status, 'source' => 'chart', 'requests_moved' => '0', 'note' => '', 'created_by' => '1', 'created_at' => '2026-10-01 00:00:00', 'applied_at' => null]);
        return $id;
    }

    private function w(string $op): bool
    {
        if ($this->world) $this->world->note('moves ' . $op);
        return $this->world !== null && $this->world->fails('moves.' . strtok($op, ' '));
    }

    public function tableExists(): bool
    {
        return $this->exists;
    }

    public function openIdFor(int $employeeId, int $ignoreId): ?string
    {
        $this->w('openIdFor ' . $employeeId . ' ' . $ignoreId);
        foreach ($this->rows as $id => $r) if ((int) $r->employee_id === $employeeId && in_array($r->status, ['scheduled', 'applying'], true) && $id !== $ignoreId) return (string) $id;
        return null;
    }

    public function insert(array $row)
    {
        if ($this->w('insert ' . ($row['status'] ?? '') . ' ' . ($row['employee_id'] ?? ''))) return false;
        $id = ++$this->nextId;
        $this->rows[$id] = (object) ($row + ['id' => (string) $id, 'requests_moved' => '0', 'note' => '', 'applied_at' => null]);
        return 1;
    }

    public function insertId(): int
    {
        return $this->nextId;
    }

    public function finishClaimed(int $id, string $claimAt, array $row)
    {
        if ($this->w('finishClaimed ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== 'applying' || $r->applied_at !== $claimAt) return 0;
        foreach ($row + ['note' => ''] as $k => $v) $r->$k = $v;
        return 1;
    }

    public function dueRows(string $today): array
    {
        $this->w('dueRows');
        $rows = array_filter($this->rows, function ($r) use ($today) { return $r->status === 'scheduled' && $r->effective_date <= $today; });
        usort($rows, function ($a, $b) { return [$a->effective_date, (int) $a->id] <=> [$b->effective_date, (int) $b->id]; });
        return array_values(array_map(function ($r) { return clone $r; }, $rows));
    }

    public function claim(int $id, string $claimAt)
    {
        if ($this->w('claim ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== 'scheduled') return 0;
        $r->status = 'applying';
        $r->applied_at = $claimAt;
        return 1;
    }

    public function markAlreadyDone(int $id, string $claimAt, string $now)
    {
        if ($this->w('markAlreadyDone ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== 'applying' || $r->applied_at !== $claimAt) return 0;
        $r->status = 'done';
        $r->note = 'already in place';
        $r->applied_at = $now;
        return 1;
    }

    public function release(int $id, string $claimAt)
    {
        if ($this->w('release ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== 'applying' || $r->applied_at !== $claimAt) return 0;
        $r->status = 'scheduled';
        $r->applied_at = null;
        return 1;
    }

    public function fail(int $id, ?string $claimAt, string $code, string $now)
    {
        if ($this->w('fail ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== 'applying' || ($claimAt !== null && $r->applied_at !== $claimAt)) return 0;
        $r->status = 'failed';
        $r->note = $code;
        $r->applied_at = $now;
        return 1;
    }

    public function applyingRows(): array
    {
        $this->w('applyingRows');
        return array_values(array_map(function ($r) { return clone $r; }, array_filter($this->rows, function ($r) { return $r->status === 'applying'; })));
    }

    private function holds(object $r, ?string $appliedAt): bool
    {
        return $r->status === 'applying' && ($appliedAt === null ? $r->applied_at === null : $r->applied_at === $appliedAt);
    }

    public function resume(int $id, ?string $appliedAt)
    {
        if ($this->w('resume ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || !$this->holds($r, $appliedAt)) return 0;
        $r->status = 'scheduled';
        $r->applied_at = null;
        $r->note = 'resumed';
        return 1;
    }

    public function failInterrupted(int $id, ?string $appliedAt, string $now)
    {
        if ($this->w('failInterrupted ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || !$this->holds($r, $appliedAt)) return 0;
        $r->status = 'failed';
        $r->note = 'interrupted';
        $r->applied_at = $now;
        return 1;
    }

    public function byId(int $id): ?object
    {
        $this->w('byId ' . $id);
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function cancel(int $id, string $status, string $now)
    {
        if ($this->w('cancel ' . $id)) return false;
        $r = $this->rows[$id] ?? null;
        if (!$r || $r->status !== $status) return 0;
        $r->status = 'cancelled';
        $r->applied_at = $now;
        return 1;
    }

    public function doneRows(): array
    {
        $rows = array_filter($this->rows, function ($r) { return $r->status === 'done'; });
        krsort($rows);
        return array_values(array_map(function ($r) { return clone $r; }, $rows));
    }

    public function openRows(): array
    {
        $rows = array_filter($this->rows, function ($r) { return in_array($r->status, ['scheduled', 'applying'], true); });
        usort($rows, function ($a, $b) { return [$a->effective_date, (int) $a->id] <=> [$b->effective_date, (int) $b->id]; });
        return array_values(array_map(function ($r) { return clone $r; }, $rows));
    }

    public function failedRows(): array
    {
        $rows = array_filter($this->rows, function ($r) { return $r->status === 'failed'; });
        krsort($rows);
        return array_values(array_map(function ($r) { return clone $r; }, $rows));
    }
}
