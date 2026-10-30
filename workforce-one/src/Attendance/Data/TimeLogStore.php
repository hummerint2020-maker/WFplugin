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
}
