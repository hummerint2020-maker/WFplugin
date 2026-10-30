<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** What the break services need from the rest of the plugin: the clock of a break, the person, the managers, the cron events and the notices. */
interface AttendanceSite
{
    /** The break length in minutes (read when the reminder is built, as the setting is read after the flag is set). */
    public function breakDurationMinutes(): int;

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
