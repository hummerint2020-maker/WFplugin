<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** The refused and unsent Sign In attempts (ews_signin_attempts; docs/tasks/attendance-evidence.md). No method computes a time: the caller gives it. */
interface AttemptStore
{
    /** Whether the table is there (a site that never switched the feature on may not have it). */
    public function exists(): bool;

    /** @param array<string,mixed> $row inserted as given (no formats) @return int the new id, 0 when the insert failed */
    public function insert(array $row): int;

    /** How many refusals (refused, face check failed, unsent) the employee has on the day. */
    public function refusalsOn(int $employeeId, string $day): int;

    /** True when the employee has an attempt of the type between the two times. */
    public function typeBetween(int $employeeId, string $type, string $from, string $to): bool;

    /** How many unsent attempts were reported since the time. */
    public function notReachedSince(int $employeeId, string $since): int;

    /** employee_id, photo_key and `at` (the attempt's time) of one attempt (null when there is none). */
    public function photoOf(int $id): ?object;

    /** @return object[] every attempt of the employee on the day, in storage order */
    public function day(int $employeeId, string $day): array;

    /** @return string[] the photo keys of the attempts older than either time */
    public function photoKeysBefore(string $attemptCut, string $photoCut): array;

    public function clearPhotosBefore(string $cut): void;

    public function deleteBefore(string $cut): void;
}
