<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * The time-log rows (ews_time_logs; docs/tasks/attendance-store.md): the reads of 3.31.136. A day's rows are the "live" ones, the
 * ones no correction has replaced.
 */
interface TimeLogStore
{
    /** The employee's newest event that has coordinates: latitude, longitude, location_timestamp (null when none). */
    public function lastDeviceLocation(int $employeeId): ?array;

    /** @return object[] the live rows of one employee and one work day, oldest first */
    public function liveDay(int $employeeId, string $day): array;

    /** @return string[] the event types of one employee and one work day (live rows, any order) */
    public function typesOn(int $employeeId, string $day): array;

    /** @return object[] event_type and event_at of the live Sign In / Late Sign In / Sign Out rows of one day, oldest first */
    public function liveInOut(int $employeeId, string $day): array;

    /** @param array<string,mixed> $row inserted as given (no formats) @return int the new id, 0 on failure */
    public function insert(array $row): int;

    /** The last database error of this connection. */
    public function lastError(): string;

    /** The id of the last row inserted on this connection (whatever the table). */
    public function lastInsertId(): int;

    /** @param string[] $types the lowest id of the employee's events of these types on the day (0 = none) */
    public function firstEventId(int $employeeId, string $day, array $types): int;

    public function delete(int $id): void;

    /** Keeps the Face check's photo with the row (the result is not looked at). */
    public function setPhoto(int $id, string $key): void;

    /** An automatic event (Auto Attendance, bulk Sign Out): the 14 columns of the row, each as a string or integer format. @return int the new id, 0 on failure */
    public function insertAuto(array $row): int;

    /** True when the employee has a row of one of these event types on the day (any row, corrected ones too). @param string[] $types */
    public function anyOfKinds(int $employeeId, string $day, array $types): bool;

    /** @return object[] employee_id and sign_in_id of everyone with a live Sign In and no Sign Out on the day */
    public function openSignInsOn(string $day): array;

    public function byId(int $id): ?object;

    /** The id of the employee's Sign Out row on the day (any row, corrected ones too), 0 when there is none. */
    public function signOutIdOn(int $employeeId, string $day): int;

    /** A row's id and employee (null when there is no such row). */
    public function idEmployee(int $id): ?object;

    /** @return object[] event_type and event_at of the employee's other live Sign In / Out rows on the day */
    public function othersOn(int $employeeId, string $day, int $exceptId): array;

    /** Replaces the given columns of one row (as given, no formats). False only on a database error. @param array<string,mixed> $row */
    public function update(int $id, array $row): bool;

    /** Deletes every row of the employee on the day; the number of rows. */
    public function deleteDay(int $employeeId, string $day): int;

    /** True when the employee has a row of one of these event types between the two times (any row, corrected ones too). @param string[] $types */
    public function kindsBetween(int $employeeId, array $types, string $from, string $to): bool;

    /** employee_id, photo_key and `at` (the event's time) of one row (null when there is none). */
    public function photoOf(int $id): ?object;

    /** @return object[] every row of the employee on the day (corrected ones too), in storage order */
    public function allOn(int $employeeId, string $day): array;

    /** @return string[] the photo keys of the rows older than the time */
    public function photoKeysBefore(string $cut): array;

    public function clearPhotosBefore(string $cut): void;

    /** One whole row (null when there is none). */
    public function find(int $id): ?object;
}
