<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/**
 * What LeaveReturnService reads and writes in the Leave module's tables (3.31.164, docs/tasks/leave-return-layers.md): the leave
 * return of 3.31.161 and the cancellations before it. Writes return what $wpdb returned (rows changed, or false on an error), so
 * the service decides as the old code did.
 */
interface LeaveReturnStore
{
    /** The leave with its type, locked until the transaction ends (not on SQLite). */
    public function lockLeave(int $id): ?object;

    /** The employee's own leave request, or null. @param int|string $employeeId */
    public function findOwn(int $id, $employeeId): ?object;

    /** The leave's change rounds, newest first. @return object[] */
    public function changes(int $leaveId, int $limit): array;

    public function changeCount(int $leaveId): int;

    /** @param array<string,mixed> $row @return int the new id, 0 on a database error */
    public function insertChange(array $row): int;

    /** A Pending round ends. @param array<string,mixed> $set */
    public function closeChange(int $id, array $set): bool;

    /** The round's history row after a return. @param array<string,mixed> $set */
    public function finishChange(int $id, array $set): bool;

    /** Snapshot days of a leave on or after a day. */
    public function daysFrom(int $leaveId, string $from): int;

    /** @return object[]|null all the leave's snapshot days (null on a database error) */
    public function leaveDays(int $leaveId): ?array;

    /** @return object[]|null the snapshot days on or after a day */
    public function leaveDaysFrom(int $leaveId, string $from): ?array;

    /** @return object[]|null the snapshot days on or after a day, locked */
    public function lockLeaveDaysFrom(int $leaveId, string $from): ?array;

    public function countLeaveDays(int $leaveId): int;

    /** @return int|false */
    public function deleteLeaveDay(int $snapshotId);

    public function snapshot(int $id): ?object;

    public function snapshotId(int $id): ?string;

    /** @param mixed $balanceId @param mixed $days @return int|false */
    public function lowerUsed($balanceId, $days, string $at);

    public function balanceFor(int $employeeId, int $typeId, int $year): ?object;

    public function balanceById(int $id): ?object;

    /** @param mixed $add @return int|false */
    public function addCarried(int $id, $add, string $at);

    /** @return int|false */
    public function cancelWhole(int $id, int $by, string $at);

    /** @return int|false */
    public function endLegacy(int $id, int $by, string $at);

    /** @param mixed $days @return int|false */
    public function shorten(int $id, string $end, $days, int $by, string $at);

    /** @return int|false */
    public function endPending(int $id, int $by, string $at);

    /** The conditional UPDATE of a return: only while the leave is still as locked. @param array<string,mixed> $set @return int|false */
    public function applyReturn(int $id, array $set, ?string $was);

    /** @return int|false */
    public function rejectCancellation(int $id);

    /** @return int|false */
    public function markPending(int $id, ?string $from);

    /** @return int|false */
    public function askAgain(int $id, string $back);

    /** @return int|false */
    public function withdrawCancellation(int $id, int $employeeId);

    /** An employee's approved leaves, newest first (HR's "Back at work"). @return object[] */
    public function approvedLeaves(int $employeeId): array;
}
