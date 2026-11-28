<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * What HolidayRefundService reaches in the rest of the plugin (3.31.162, docs/tasks/leave-holidays.md). Built once, in
 * includes/trait-services.php (leave_holiday_ports()).
 */
interface HolidayRefundPorts
{
    /** The active company holidays between two days (the Schedule module's calendar). @return array<string,string> Y-m-d => title, one per day */
    public function holidays(string $start, string $end): array;

    /** The leave year that is current now. */
    public function leaveYear(): int;

    /** The employee (id, name, wp_user_id), or null. */
    public function employee(int $employeeId): ?object;

    /**
     * Runs $fn under the employee's leave lock (the one every change of the employee's leave takes).
     * @return mixed what $fn returned, or 'busy' when another change holds the lock
     */
    public function withEmployeeLock(int $employeeId, callable $fn);

    /** The balance the leave is charged to (made for an old leave without one), or null. */
    public function balance(object $leave): ?object;

    /** The day's schedule goes back as it was before the leave (unless a manager changed it meanwhile). */
    public function restoreDay(object $leaveDay, object $employee, string $now, int $leaveId): bool;

    /** Next year's carried days follow days given back to this year (Leave return). @return float|false the days added */
    public function carryTopUp(object $leave, object $balance, float $days, string $now);

    /** A final (locked) week changed by this: recorded in the Audit Log. @param string[] $days */
    public function weekLockRecord(int $employeeId, array $days, string $ref): void;

    /**
     * Tells the employee that days went back (3.31.166: the words are chosen, and translated, by the plugin side).
     * @param array{days:int,type:string,start:string,end:string,holidays:string[],after:string} $facts
     */
    public function notifyGivenBack(int $wpUserId, array $facts, int $leaveId): void;

    /** The user doing it (0 for none). */
    public function actorId(): int;
}
