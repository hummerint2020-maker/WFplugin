<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * What LeaveReturnService reaches in the rest of the plugin (3.31.164, docs/tasks/leave-return-layers.md): the same functions the
 * trait called before. Built once, in includes/trait-services.php (leave_return_ports()).
 */
interface LeaveReturnPorts
{
    /** The first day a cancellation cancels (null: all of it), as leave_cancel_from_day() decides. */
    public function cancelFromDay(object $leave): ?string;

    /** Today, site time (Y-m-d). */
    public function today(): string;

    /** The payroll months closed between two days. @return string[] Y-m */
    public function closedMonths(string $from, string $to): array;

    /** Working days between two days (a leave kept without snapshots). @return int|float */
    public function workingDaysCount(string $start, string $end);

    /** The balance the leave is charged to, or null. */
    public function balance(object $leave): ?object;

    /** A manager's substitute follows the leave once the request ends. */
    public function substitutesSyncAtShutdown(): void;

    /** A manager's substitute ends when the leave now ends. */
    public function followLeaveEnd(int $leaveId, string $start, string $end): bool;

    /** A final (locked) week changed by this. @param string[] $days */
    public function weekLockRecord(int $employeeId, array $days, string $source, string $ref): void;

    /** @return mixed what $fn returned, or 'busy' when another change of the employee's leave holds the lock */
    public function withEmployeeLock(int $employeeId, callable $fn);

    /** The cancellation's open approval levels closed. @return mixed true, false (database error) or 'moved' */
    public function closeOpenApproval(int $leaveId);

    /** The cancellation's approval closed as withdrawn. @return mixed true, false or 'moved' */
    public function closeWithdrawnApproval(int $leaveId);

    /** The cancellation's approval is no longer waiting for a level. */
    public function approvalDecided(int $leaveId): bool;

    /** A new approval round for the cancellation, waiting for its first level. @return int|null its id; null when it could not start */
    public function startRound(int $leaveId, int $employeeId): ?int;

    /** Leave return is switched on: read when the old code read it (after the first write), so the queries keep their order. */
    public function returnOn(): bool;

    /** The user doing it (0 for none) and their name. */
    public function actorId(): int;

    public function actorName(): string;
}
