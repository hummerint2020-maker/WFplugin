<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The break sessions (ews_break_sessions; docs/tasks/attendance-store.md): the reads of 3.31.136. */
interface BreakStore
{
    /** @return object[] the employee's break sessions of one work day, oldest first */
    public function sessionsOn(int $employeeId, string $day): array;

    /** The employee's newest open break of one work day (null when none). */
    public function openOn(int $employeeId, string $day): ?object;

    /** @param array<string,mixed> $row employee_id, user_id, work_date, start_at, status, created_at @return int the new id, 0 on failure */
    public function insert(array $row): int;

    /** The lowest id of the employee's open breaks of one work day (0 = none). */
    public function firstOpenId(int $employeeId, string $day): int;

    public function delete(int $id): void;

    /** Ends an open break; false when it was no longer open (the affected count is exactly one). */
    public function close(int $id, string $endMysql, int $minutes): bool;

    public function byId(int $id): ?object;

    /** The reminder was sent (the flag is set on the id alone, whatever the status). */
    public function markReminded(int $id): void;

    /** The manager escalation was sent (the flag is set on the id alone, whatever the status). */
    public function markEscalated(int $id): void;

    /** @return string[] the ids of the employee's break sessions of one work day */
    public function idsOn(int $employeeId, string $day): array;

    /** Deletes every break session of the employee on the day; the number of rows. */
    public function deleteDay(int $employeeId, string $day): int;
}
