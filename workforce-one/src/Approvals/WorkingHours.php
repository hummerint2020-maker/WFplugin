<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * Working hours between two times (3.31.100): a request waiting for its approver is reminded, then
 * moved up, after so many working hours, not clock hours (mockup docs/mockups/approvals/). Working
 * hours are the hours between the day's start and end on the site's working days. Pure: times are
 * site-local "Y-m-d H:i:s" strings, read as they are.
 */
final class WorkingHours
{
    /** The furthest apart two times are counted (longer waits are cut there). */
    private const MAX_DAYS = 400;

    /**
     * @param list<int> $days working weekdays, 0 = Sunday … 6 = Saturday
     * @param string $start the working day's start, "HH:MM"
     * @param string $end its end, "HH:MM" (after the start)
     */
    public static function between(string $from, string $to, array $days, string $start = '09:00', string $end = '17:00'): float
    {
        $a = strtotime($from . ' UTC');
        $b = strtotime($to . ' UTC');
        $s = self::minutes($start);
        $e = self::minutes($end);
        if ($a === false || $b === false || $b <= $a || $s === null || $e === null || $e <= $s) return 0.0;
        $days = array_map('intval', $days);
        $total = 0;
        $day = (int) (floor($a / 86400) * 86400);
        for ($i = 0; $i <= self::MAX_DAYS && $day < $b; $i++, $day += 86400) {
            if (!in_array((int) gmdate('w', $day), $days, true)) continue;
            $open = max($a, $day + $s * 60);
            $close = min($b, $day + $e * 60);
            if ($close > $open) $total += $close - $open;
        }
        return round($total / 3600, 2);
    }

    /** "HH:MM" as minutes after midnight, or null. */
    public static function minutes(string $hm): ?int
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hm, $m)) return null;
        return (int) $m[1] * 60 + (int) $m[2];
    }
}
