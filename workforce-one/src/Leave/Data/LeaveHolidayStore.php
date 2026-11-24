<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/**
 * Company holidays charged as leave (3.31.162, docs/tasks/leave-holidays.md): what HolidayRefundService reads and writes on the
 * Leave module's tables. The only SQL of the feature is in WordPressLeaveHolidayStore.
 */
interface LeaveHolidayStore
{
    /**
     * Approved leaves charged to a type that deducts, to the balance of $fromYear or later (an old leave without a balance: asked
     * in $fromYear or later), with a leave day on one of $days. @param string[] $days Y-m-d @return int[] leave ids
     */
    public function leavesOnDays(array $days, int $fromYear): array;

    /** @param int[] $ids @return object[] id, employee_id, start_date, end_date, requested_days, status, cancellation_status, type_name, deduct_balance */
    public function leaves(array $ids): array;

    /** @param int[] $ids @return array<int,string[]> leave id => its leave days (Y-m-d, ascending) */
    public function leaveDays(array $ids): array;

    /** The leave and its type, locked for this transaction (FOR UPDATE where the database has it). */
    public function lockLeave(int $id): ?object;

    /** The leave's leave days (snapshot rows), locked for this transaction. @return object[] */
    public function lockLeaveDays(int $id): array;

    /** @return string[] the days already given back for this leave */
    public function refundedDays(int $leaveId): array;

    /** One day given back; false when it was given back already (the unique key) or on a database error. */
    public function addRefund(int $leaveId, int $employeeId, string $day, int $balanceId, string $holiday, int $by, string $at): bool;

    /** @return bool true when the leave day was removed */
    public function removeLeaveDay(int $snapshotId): bool;

    /** The leave's days, only while it still counts $before and is approved. @return int|false rows changed */
    public function setRequestedDays(int $leaveId, float $before, float $after);

    public function lowerUsed(int $balanceId, float $days, string $at): bool;
}
