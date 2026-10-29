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
}
