<?php
namespace WorkforceOne\Schedule\Data;

if (!defined('ABSPATH')) exit;

/**
 * The schedule change request tables (3.31.167): the only SQL on ews_schedule_change_requests / _days is its WordPress
 * implementation. Writes return what the database returned (rows changed, or false); days are always in
 * (employee_id, work_date) order, the lock order every path uses.
 */
interface ScheduleChangeStore
{
    /** @param array<string,mixed> $row @return int the new id, 0 on error */
    public function insertRequest(array $row): int;

    /** @param array<string,mixed> $row @return int the new id, 0 on error (lastWasDuplicate() tells a day already asked) */
    public function insertDay(array $row): int;

    public function lastWasDuplicate(): bool;

    public function find(int $id): ?object;

    /** The header, locked until the transaction ends (not on SQLite, whose writer lock serialises anyway). */
    public function lockRequest(int $id): ?object;

    /** @return object[] the request's days, in order */
    public function days(int $requestId): array;

    /** @return object[]|null the request's days, locked, in order; null on a database error */
    public function lockDays(int $requestId): ?array;

    /** @param int[] $ids @return array<int,object[]> request id => its days, in order */
    public function daysOf(array $ids): array;

    /**
     * The days of these people waiting in a request between two days.
     * @param int[] $employeeIds @return object[] employee_id, work_date, new_status, request_id, requester_employee_id
     */
    public function openOn(array $employeeIds, string $from, string $to): array;

    /** @param array<string,mixed> $set @return int|false the header moves from $from (rows changed: 1 means it was ours) */
    public function claim(int $id, string $from, array $set);

    /** @param array<string,mixed> $set @return int|false the requester's own waiting request moves */
    public function claimOwn(int $id, int $requesterEmployeeId, array $set);

    /** The day's result once decided; its open_key is released. */
    public function setDayResult(int $dayId, string $result, ?string $nowStatus): bool;

    /** Every day of the request released (open_key NULL). False only on a database error. */
    public function releaseDays(int $requestId): bool;

    /** @return int|false the decided header's final status and the days applied */
    public function finish(int $id, string $status, int $applied);

    /** @return object[] the waiting requests, oldest first */
    public function waiting(int $limit): array;

    /** @return object[] a requester's requests, newest first */
    public function forRequester(int $employeeId, int $limit): array;

    /** @return int how many wait */
    public function openCount(): int;

    public function lastError(): string;
}
