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

    /**
     * A new request (3.31.143). The row is written as given, in its key order, without formats (NULLs stay NULL).
     * @param array<string,mixed> $row
     * @return int the new id, 0 when the INSERT failed
     */
    public function insert(array $row): int;

    /** Removes a request (a request whose approval could not start, or HR's correction that could not be applied). Not checked. */
    public function delete(int $id): void;

    /** Whether the request is the employee's own and still waits (pending or pending_hr). */
    public function ownOpen(int $id, int $employeeId): bool;

    /**
     * The employee withdraws their request still waiting: cancelled, by whom and when.
     * @return int|false the rows changed (1, or 0 when it was no longer waiting), false on a database error
     */
    public function withdraw(int $id, int $employeeId, int $userId, string $now);
}
