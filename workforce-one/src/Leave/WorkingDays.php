<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/** Working days inside a date range. Pure: no WordPress calls. */
final class WorkingDays
{
    /**
     * @param string $start Y-m-d
     * @param string $end   Y-m-d
     * @param int[]  $workingWeekdays 0 = Sunday … 6 = Saturday
     * @return string[] Y-m-d dates, ascending.
     */
    public static function dates(string $start, string $end, array $workingWeekdays): array
    {
        $s = strtotime($start);
        $e = strtotime($end);
        if (!$s || !$e || $e < $s) return [];
        $out = [];
        for ($ts = $s; $ts <= $e; $ts = strtotime('+1 day', $ts)) {
            if (in_array((int) date('w', $ts), $workingWeekdays, true)) $out[] = date('Y-m-d', $ts);
        }
        return $out;
    }

    /** @param int[] $workingWeekdays */
    public static function count(string $start, string $end, array $workingWeekdays): int
    {
        return count(self::dates($start, $end, $workingWeekdays));
    }
}
