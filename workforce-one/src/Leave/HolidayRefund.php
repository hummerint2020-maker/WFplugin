<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Company holidays charged as leave before 3.31.162 (docs/tasks/leave-holidays.md): which days of a leave go back to the
 * balance. Pure: no WordPress calls.
 */
final class HolidayRefund
{
    /**
     * The leave's charged days that are company holidays, ascending, without the days already given back, never more than the
     * days the leave still charges.
     * @param string[] $chargedDays Y-m-d, the leave's days (its schedule snapshots)
     * @param string[] $holidays    Y-m-d, the active company holidays
     * @param string[] $refunded    Y-m-d, already given back
     * @return string[]
     */
    public static function candidates(array $chargedDays, array $holidays, array $refunded, float $requestedDays): array
    {
        $hol = array_fill_keys(array_map('strval', $holidays), true);
        $done = array_fill_keys(array_map('strval', $refunded), true);
        $out = [];
        foreach (array_unique(array_map('strval', $chargedDays)) as $day) {
            if (isset($hol[$day]) && !isset($done[$day])) $out[] = $day;
        }
        sort($out);
        return array_slice($out, 0, max(0, (int) floor($requestedDays + 0.0001)));
    }

    /** Whether a leave may be corrected now: approved, charged to the balance, no change waiting for a decision. */
    public static function restorable(string $status, bool $deducts, ?string $cancellation): bool
    {
        return $status === 'Approved' && $deducts && $cancellation !== 'Pending';
    }
}
