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

    /** Is the day a company-wide leave day (a holiday)? Asked through the plugin: it keeps a per-request cache of the calendar. */
    public function generalLeave(string $day): bool;

    /** Does a schedule type (a status such as Office) ask for a Sign In? */
    public function requiresSignIn(string $status): bool;

    /** Are breaks switched on? */
    public function breakEnabled(): bool;

    /** Cancels the two cron events of a break: the reminder, then the manager escalation. */
    public function clearBreakEvents(int $breakId): void;

    /** Makes sure the break table exists (the old code did, before it touched the table). */
    public function ensureBreakTable(): void;
}
