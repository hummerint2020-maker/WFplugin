<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Approvals\Data\SubstituteStore;

/** An in-memory SubstituteStore for the unit tests (3.31.128). */
final class MemorySubstituteStore implements SubstituteStore
{
    /** @var array<int,object> */
    public $rows = [];
    /** @var bool */
    public $failInsert = false;
    /** @var int */
    private $next = 1;

    /** Adds a substitute row. @param array<string,mixed> $row */
    public function add(array $row): int
    {
        $id = $this->next++;
        $this->rows[$id] = (object) ($row + ['id' => $id, 'status' => 'scheduled', 'leave_request_id' => null, 'decided' => 0, 'started_at' => null, 'ended_at' => null]);
        return $id;
    }

    public function activeFor(int $managerEmployeeId, string $now): ?object
    {
        $found = null;
        foreach ($this->rows as $r) {
            if ((int) $r->manager_employee_id === $managerEmployeeId && in_array($r->status, ['scheduled', 'active'], true) && $r->starts_at <= $now && $r->ends_at > $now) $found = $r;
        }
        return $found ? clone $found : null;
    }

    public function linkedToLeave(): array
    {
        return array_values(array_filter($this->rows, function ($r) {
            return $r->leave_request_id !== null && in_array($r->status, ['pending', 'scheduled', 'active'], true);
        }));
    }

    public function current(): array
    {
        $out = array_values(array_filter($this->rows, function ($r) {
            return in_array($r->status, ['scheduled', 'active'], true);
        }));
        usort($out, function ($a, $b) {
            return strcmp($a->starts_at, $b->starts_at);
        });
        return $out;
    }

    public function active(): array
    {
        return array_values(array_filter($this->rows, function ($r) {
            return $r->status === 'active';
        }));
    }

    public function find(int $id): ?object
    {
        return isset($this->rows[$id]) ? clone $this->rows[$id] : null;
    }

    public function transition(int $id, string $from, array $set)
    {
        if (!isset($this->rows[$id]) || $this->rows[$id]->status !== $from) return 0;
        foreach ($set as $k => $v) $this->rows[$id]->$k = $v;
        return 1;
    }

    public function incrementDecided(int $id): void
    {
        $this->rows[$id]->decided++;
    }

    public function decided(int $id): int
    {
        return (int) $this->rows[$id]->decided;
    }

    public function insert(array $row)
    {
        if ($this->failInsert) return false;
        return $this->add($row);
    }

    public function overlapForManager(int $managerEmployeeId, string $ends, string $starts, int $ignoreId): bool
    {
        foreach ($this->rows as $r) {
            if ((int) $r->manager_employee_id === $managerEmployeeId && in_array($r->status, ['pending', 'scheduled', 'active'], true) && $r->starts_at < $ends && $r->ends_at > $starts && (int) $r->id !== $ignoreId) return true;
        }
        return false;
    }

    public function overlapForCandidate(int $candidateEmployeeId, string $ends, string $starts): bool
    {
        return $this->overlapForManager($candidateEmployeeId, $ends, $starts, 0);
    }

    public function forSubstitute(int $substituteEmployeeId): array
    {
        return array_values(array_filter($this->rows, function ($r) use ($substituteEmployeeId) {
            return (int) $r->substitute_employee_id === $substituteEmployeeId && $r->status === 'active';
        }));
    }

    public function ownOpen(int $managerEmployeeId, string $now): array
    {
        return array_values(array_filter($this->rows, function ($r) use ($managerEmployeeId, $now) {
            return (int) $r->manager_employee_id === $managerEmployeeId && in_array($r->status, ['pending', 'scheduled', 'active'], true) && $r->ends_at > $now;
        }));
    }

    public function adminList(): array
    {
        return array_values($this->rows);
    }
}
