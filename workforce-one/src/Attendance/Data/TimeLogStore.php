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
}
