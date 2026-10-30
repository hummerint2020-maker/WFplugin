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

    /** @var bool the next insertAuto fails */
    public $failAuto = false;
    /** @var bool the next update fails */
    public $failUpdate = false;

    public function insertAuto(array $row): int
    {
        $this->calls[] = 'insertAuto ' . ($row['event_type'] ?? '');
        if ($this->failAuto) return 0;
        $this->lastId = $this->nextId++;
        $this->rows[] = (object) ($row + ['id' => (string) $this->lastId, 'corrects_id' => null]);
        return $this->lastId;
    }

    public function anyOfKinds(int $employeeId, string $day, array $types): bool
    {
        $this->calls[] = 'anyOfKinds ' . implode(',', $types);
        foreach ($this->rows as $r) if ((int) $r->employee_id === $employeeId && $r->work_date === $day && in_array($r->event_type, $types, true)) return true;
        return false;
    }

    public function openSignInsOn(string $day): array
    {
        $this->calls[] = 'openSignInsOn';
        $by = [];
        foreach ($this->rows as $r) {
            if ($r->work_date !== $day || !in_array($r->event_type, ['sign_in', 'late_sign_in'], true)) continue;
            $by[(int) $r->employee_id] = max($by[(int) $r->employee_id] ?? 0, (int) $r->id);
        }
        $out = [];
        foreach ($by as $eid => $id) if (!$this->signOutIdOn($eid, $day)) $out[] = (object) ['employee_id' => (string) $eid, 'sign_in_id' => (string) $id];
        return $out;
    }

    public function byId(int $id): ?object
    {
        foreach ($this->rows as $r) if ((int) $r->id === $id) return $r;
        return null;
    }

    public function signOutIdOn(int $employeeId, string $day): int
    {
        foreach ($this->rows as $r) if ((int) $r->employee_id === $employeeId && $r->work_date === $day && $r->event_type === 'sign_out') return (int) $r->id;
        return 0;
    }

    public function idEmployee(int $id): ?object
    {
        $r = $this->byId($id);
        return $r ? (object) ['id' => $r->id, 'employee_id' => $r->employee_id] : null;
    }

    public function othersOn(int $employeeId, string $day, int $exceptId): array
    {
        $this->calls[] = 'othersOn';
        return array_values(array_filter($this->liveInOut($employeeId, $day), function ($r) use ($exceptId) { return (int) $r->id !== $exceptId; }));
    }

    public function update(int $id, array $row): bool
    {
        $this->calls[] = 'update ' . $id;
        if ($this->failUpdate) return false;
        foreach ($this->rows as $r) if ((int) $r->id === $id) foreach ($row as $k => $v) $r->$k = $v;
        return true;
    }

    public function deleteDay(int $employeeId, string $day): int
    {
        $this->calls[] = 'deleteDay';
        $n = count($this->rows);
        $this->rows = array_values(array_filter($this->rows, function ($r) use ($employeeId, $day) { return !((int) $r->employee_id === $employeeId && $r->work_date === $day); }));
        return $n - count($this->rows);
    }
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

    public function idsOn(int $employeeId, string $day): array
    {
        return array_map(function ($r) { return (string) $r->id; }, $this->sessionsOn($employeeId, $day));
    }

    public function deleteDay(int $employeeId, string $day): int
    {
        $this->calls[] = 'breaks deleteDay';
        $n = count($this->rows);
        $this->rows = array_values(array_filter($this->rows, function ($r) use ($employeeId, $day) { return !((int) $r->employee_id === $employeeId && $r->work_date === $day); }));
        return $n - count($this->rows);
    }
}

/** An in-memory AutoRuleStore for the unit tests (3.31.138). */
final class MemoryAutoRuleStore implements \WorkforceOne\Attendance\Data\AutoRuleStore
{
    /** @var array<int,object> */
    public $rows = [];
    /** @var list<string> */
    public $calls = [];
    /** @var bool the next insert / update fails */
    public $fail = false;
    /** @var int */
    private $next = 1;

    /** @param array<string,mixed> $more */
    public function add(int $employeeId, string $recurrence, ?string $runDate, ?string $in, ?string $out, int $enabled = 1, array $more = []): int
    {
        $id = $this->next++;
        $this->rows[$id] = (object) ($more + ['id' => (string) $id, 'employee_id' => (string) $employeeId, 'recurrence' => $recurrence, 'run_date' => $runDate, 'weekdays' => '0,1,2,3,4,5,6', 'sign_in_time' => $in, 'sign_out_time' => $out, 'enabled' => (string) $enabled]);
        return $id;
    }

    public function dueRules(string $today, string $yesterday): array
    {
        $this->calls[] = 'dueRules';
        return array_values(array_filter($this->rows, function ($r) use ($today, $yesterday) {
            return $r->enabled === '1' && (($r->recurrence === 'one_time' && in_array($r->run_date, [$today, $yesterday], true)) || $r->recurrence === 'weekly');
        }));
    }

    public function disable(int $id, string $now): void { $this->calls[] = 'disable ' . $id; if (isset($this->rows[$id])) $this->rows[$id]->enabled = '0'; }

    public function enabledOf(int $id): ?object { return isset($this->rows[$id]) ? (object) ['enabled' => $this->rows[$id]->enabled] : null; }

    public function setEnabled(int $id, int $enabled, string $now): void { $this->calls[] = 'setEnabled ' . $id . ' ' . $enabled; if (isset($this->rows[$id])) $this->rows[$id]->enabled = (string) $enabled; }

    public function insert(array $row): int
    {
        $this->calls[] = 'insert';
        if ($this->fail) return 0;
        $id = $this->next++;
        $this->rows[$id] = (object) ($row + ['id' => (string) $id]);
        return $id;
    }

    public function update(int $id, array $row): bool
    {
        $this->calls[] = 'update ' . $id;
        if ($this->fail) return false;
        if (isset($this->rows[$id])) foreach ($row as $k => $v) $this->rows[$id]->$k = $v;
        return true;
    }

    public function remove(int $id): int { $this->calls[] = 'remove ' . $id; if (!isset($this->rows[$id])) return 0; unset($this->rows[$id]); return 1; }

    public function lastError(): string { return $this->fail ? 'Disk full' : ''; }
}
