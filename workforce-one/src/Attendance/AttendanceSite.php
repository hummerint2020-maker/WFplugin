<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the break services need from the rest of the plugin: the clock of a break, the person, the managers, the cron events and the notices. */
interface AttendanceSite
{
    /** The two cron events of a new break: the reminder and the manager escalation. */
    public function scheduleBreakEvents(int $breakId): void;

    /** The "Break Ended" notification, after a break was closed. */
    public function breakEnded(object $employee, int $breakId, int $minutes): void;

    /** The "Break Time Ended" notification to the employee. */
    public function breakTimeEnded(int $userId, int $breakId, string $message): void;

    /** The "Extended Break Alert" notification to one manager. */
    public function extendedBreak(int $userId, int $breakId, string $message): void;

    public function attendanceEnabled(int $employeeId): bool;

    public function employee(int $employeeId): ?object;

    /** Whole minutes since a break started (its start is a local time; the clock is UTC now). */
    public function minutesSince(string $startAtLocal): int;

    /** @return int[] the users who get the extended-break alert */
    public function managerUserIds(): array;
}
