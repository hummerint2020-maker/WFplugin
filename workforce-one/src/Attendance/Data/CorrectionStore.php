<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The attendance correction requests (ews_attendance_corrections; docs/tasks/attendance-corrections.md). No method computes a time: the caller gives it. */
interface CorrectionStore
{
    /** How many requests the employee made for days between the two dates that count towards the monthly limit (employee requests that are waiting or approved). */
    public function monthCount(int $employeeId, string $from, string $to): int;

    /** The employee's newest request for the day that still waits (pending or pending_hr); null when there is none. */
    public function pendingForDay(int $employeeId, string $date): ?object;

    /** @return object[] the employee's requests for days between the two dates, oldest first */
    public function recentFor(int $employeeId, string $from, string $to): array;

    /** @return object[] the employee's newest requests, any source, newest first */
    public function latestFor(int $employeeId, int $limit): array;

    /** @return object[] the employee's own (employee-source) requests for My requests, newest first */
    public function myRequests(int $employeeId, int $limit): array;

    /** Whether the table is there. */
    public function tableExists(): bool;

    /** @return object[] every request that still waits (pending or pending_hr), oldest first */
    public function openForHub(): array;
}
