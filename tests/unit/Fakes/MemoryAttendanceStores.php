<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Attendance\Data\BreakStore;
use WorkforceOne\Attendance\Data\TimeLogStore;

/** An in-memory TimeLogStore for the unit tests (3.31.136): rows are objects with string values, as wpdb gives them; every call is recorded. */
final class MemoryTimeLogStore implements TimeLogStore
{
    /** @var list<object> */
    public $rows = [];
    /** @var list<string> */
    public $calls = [];

    /** @param array<string,mixed> $more */
    public function add(int $id, int $employeeId, string $day, string $type, string $at, array $more = []): void
    {
        $this->rows[] = (object) ($more + ['id' => (string) $id, 'employee_id' => (string) $employeeId, 'work_date' => $day, 'event_type' => $type, 'event_at' => $at,
            'latitude' => null, 'longitude' => null, 'location_timestamp' => null, 'corrects_id' => null]);
    }

    /** The live rows: no other row corrects them. @return list<object> */
    private function live(int $employeeId, string $day): array
    {
        $replaced = [];
        foreach ($this->rows as $r) if ($r->corrects_id !== null) $replaced[$r->corrects_id] = true;
        $out = array_values(array_filter($this->rows, function ($r) use ($employeeId, $day, $replaced) {
            return (int) $r->employee_id === $employeeId && $r->work_date === $day && !isset($replaced[$r->id]);
        }));
        usort($out, function ($a, $b) { return strcmp($a->event_at, $b->event_at); });
        return $out;
    }

    public function lastDeviceLocation(int $employeeId): ?array
    {
        $this->calls[] = 'lastDeviceLocation ' . $employeeId;
        $best = null;
        foreach ($this->rows as $r) {
            if ((int) $r->employee_id !== $employeeId || $r->latitude === null || $r->longitude === null) continue;
            if ($best === null || strcmp($r->event_at, $best->event_at) > 0) $best = $r;
        }
        return $best ? ['latitude' => $best->latitude, 'longitude' => $best->longitude, 'location_timestamp' => $best->location_timestamp] : null;
    }

    public function liveDay(int $employeeId, string $day): array
    {
        $this->calls[] = 'liveDay ' . $employeeId . ' ' . $day;
        return $this->live($employeeId, $day);
    }

    public function typesOn(int $employeeId, string $day): array
    {
        $this->calls[] = 'typesOn ' . $employeeId . ' ' . $day;
        return array_map(function ($r) { return $r->event_type; }, $this->live($employeeId, $day));
    }

    public function liveInOut(int $employeeId, string $day): array
    {
        $this->calls[] = 'liveInOut ' . $employeeId . ' ' . $day;
        return array_values(array_filter($this->live($employeeId, $day), function ($r) { return in_array($r->event_type, ['sign_in', 'late_sign_in', 'sign_out'], true); }));
    }

    /** @var int next id */
    public $nextId = 100;
    /** @var bool the next insert fails */
    public $failInsert = false;
    /** @var int */
    public $lastId = 0;
    /** @var list<array{0:int,1:string}> */
    public $photos = [];

    public function insert(array $row): int
    {
        $this->calls[] = 'insert ' . ($row['event_type'] ?? '');
        if ($this->failInsert) return 0;
        $this->lastId = $this->nextId++;
        $this->rows[] = (object) ($row + ['id' => (string) $this->lastId, 'corrects_id' => null]);
        return $this->lastId;
    }

    public function lastError(): string { return $this->failInsert ? 'Table is full' : ''; }

    public function lastInsertId(): int { return $this->lastId; }

    public function firstEventId(int $employeeId, string $day, array $types): int
    {
        $this->calls[] = 'firstEventId ' . implode(',', $types);
        $ids = [];
        foreach ($this->rows as $r) if ((int) $r->employee_id === $employeeId && $r->work_date === $day && in_array($r->event_type, $types, true)) $ids[] = (int) $r->id;
        return $ids ? min($ids) : 0;
    }

    public function delete(int $id): void
    {
        $this->calls[] = 'delete ' . $id;
        $this->rows = array_values(array_filter($this->rows, function ($r) use ($id) { return (int) $r->id !== $id; }));
    }

    public function setPhoto(int $id, string $key): void { $this->photos[] = [$id, $key]; }
}

/** An in-memory BreakStore for the unit tests (3.31.136). */
final class MemoryBreakStore implements BreakStore
{
    /** @var list<object> */
    public $rows = [];

    public function add(int $id, int $employeeId, string $day, string $status): void
    {
        $this->rows[] = (object) ['id' => (string) $id, 'employee_id' => (string) $employeeId, 'work_date' => $day, 'status' => $status];
    }

    public function sessionsOn(int $employeeId, string $day): array
    {
        return array_values(array_filter($this->rows, function ($r) use ($employeeId, $day) { return (int) $r->employee_id === $employeeId && $r->work_date === $day; }));
    }

    public function openOn(int $employeeId, string $day): ?object
    {
        $open = array_filter($this->sessionsOn($employeeId, $day), function ($r) { return $r->status === 'Open'; });
        return $open ? end($open) : null;
    }

    /** @var int */
    public $nextId = 500;
    /** @var bool the next insert fails */
    public $failInsert = false;
    /** @var bool the next close finds the break already ended */
    public $closeLost = false;
    /** @var list<string> */
    public $calls = [];

    public function insert(array $row): int
    {
        $this->calls[] = 'insert';
        if ($this->failInsert) return 0;
        $id = $this->nextId++;
        $this->rows[] = (object) ($row + ['id' => (string) $id, 'duration_notified' => '0', 'escalation_notified' => '0']);
        return $id;
    }

    public function firstOpenId(int $employeeId, string $day): int
    {
        $ids = [];
        foreach ($this->sessionsOn($employeeId, $day) as $r) if ($r->status === 'Open') $ids[] = (int) $r->id;
        return $ids ? min($ids) : 0;
    }

    public function delete(int $id): void
    {
        $this->calls[] = 'delete ' . $id;
        $this->rows = array_values(array_filter($this->rows, function ($r) use ($id) { return (int) $r->id !== $id; }));
    }

    public function close(int $id, string $endMysql, int $minutes): bool
    {
        $this->calls[] = 'close ' . $id;
        if ($this->closeLost) return false;
        foreach ($this->rows as $r) if ((int) $r->id === $id && $r->status === 'Open') { $r->status = 'Completed'; $r->actual_minutes = (string) $minutes; return true; }
        return false;
    }

    public function byId(int $id): ?object
    {
        foreach ($this->rows as $r) if ((int) $r->id === $id) return $r;
        return null;
    }

    public function markReminded(int $id): void { $this->calls[] = 'reminded ' . $id; if ($r = $this->byId($id)) $r->duration_notified = '1'; }

    public function markEscalated(int $id): void { $this->calls[] = 'escalated ' . $id; if ($r = $this->byId($id)) $r->escalation_notified = '1'; }
}
