<?php
namespace WorkforceOne\Schedule\Data;

if (!defined('ABSPATH')) exit;

/** Persistence owned by Schedule for the schedule days (ews_schedule) and the company calendar (ews_company_calendar). */
interface ScheduleDayStore
{
    /** The whole row of an employee's day, or null. */
    public function find(int $employeeId, string $date): ?object;

    /** id, status and note of an employee's day, or null. */
    public function findStatusNote(int $employeeId, string $date): ?object;

    /** id, status and note of a row, or null. */
    public function findStatusNoteById(int $id): ?object;

    /** The id of an employee's day as the database gives it (a string), or null. */
    public function idOf(int $employeeId, string $date): ?string;

    public function statusOf(int $id): ?string;

    public function noteOf(int $id): ?string;

    /** @param array<string,mixed> $row @return int the new id, 0 when the database refused it */
    public function insert(array $row): int;

    /** @param array<string,mixed> $row @return int|false rows changed, false on a database error */
    public function update(array $row, int $id);

    /** status (and note, updated_by, updated_at) of a row, only while its status is still $expected. @return int|false rows changed */
    public function updateStatusIf(int $id, string $expected, string $status, string $note, int $by, string $at);

    /** The database's message for the last statement ('' when it went through). */
    public function lastError(): string;

    /** @param string[] $dates @return object[] employee_id, work_date, status of every row on those days, by day */
    public function rowsOnDates(array $dates): array;

    /** @return object[] the active company holidays (general_leave) between the two days, by day */
    public function calendarEvents(string $start, string $end): array;
}
