<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** Serialises the attendance writes of one employee across requests (a named database lock; docs/tasks/attendance-store.md). */
interface EmployeeLock
{
    /** True when the lock is held. False means "not held", whether it timed out or the database has no such lock: the caller goes on unlocked. */
    public function lock(int $employeeId, int $waitSeconds = 5): bool;

    public function unlock(int $employeeId): void;
}
