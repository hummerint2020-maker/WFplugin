<?php
namespace WorkforceOne\Attendance;

use WorkforceOne\Attendance\Data\BreakStore;
use WorkforceOne\Attendance\Data\TimeLogStore;

if (!defined('ABSPATH')) exit;

/**
 * What the attendance log says (3.31.136; docs/tasks/attendance-store.md): the reads other modules and the Sign In page use. The
 * day (attendance_day) comes from the caller, which keeps the clock; nothing here asks the time.
 */
final class AttendanceLog
{
    /** @var TimeLogStore */
    private $logs;
    /** @var BreakStore */
    private $breaks;

    public function __construct(TimeLogStore $logs, BreakStore $breaks)
    {
        $this->logs = $logs;
        $this->breaks = $breaks;
    }

    /** Today's live events of one employee for the given work day, keyed by event type (the later row of a type wins). */
    public function todayEvents(int $employeeId, string $day): array
    {
        $o = [];
        foreach ($this->logs->liveDay($employeeId, $day) as $r) $o[$r->event_type] = $r;
        return $o;
    }

    public function lastDeviceLocation(int $employeeId): ?array
    {
        return $this->logs->lastDeviceLocation($employeeId);
    }

    /** True when the day has a Sign In (or Late Sign In) and no Sign Out: the shift is still open. */
    public function shiftOpenOn(int $employeeId, string $day): bool
    {
        $types = $this->logs->typesOn($employeeId, $day);
        return (in_array('sign_in', $types, true) || in_array('late_sign_in', $types, true)) && !in_array('sign_out', $types, true);
    }

    /** @return object[] */
    public function liveInOut(int $employeeId, string $day): array
    {
        return $this->logs->liveInOut($employeeId, $day);
    }

    /** @return object[] */
    public function breakSessions(int $employeeId, string $day): array
    {
        return $this->breaks->sessionsOn($employeeId, $day);
    }

    public function openBreak(int $employeeId, string $day): ?object
    {
        return $this->breaks->openOn($employeeId, $day);
    }
}
