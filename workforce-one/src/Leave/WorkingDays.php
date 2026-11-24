<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Working days inside a date range. Pure: no WordPress calls.
 * Company holidays (3.31.162, docs/tasks/leave-holidays.md): the days in $holidays are not working days either; with none given,
 * exactly as before.
 */
final class WorkingDays
{
    /**
     * @param string $start Y-m-d
     * @param string $end   Y-m-d
     * @param int[]  $workingWeekdays 0 = Sunday … 6 = Saturday
     * @param string[] $holidays Y-m-d company holidays to skip
     * @return string[] Y-m-d dates, ascending.
     */
    public static function dates(string $start, string $end, array $workingWeekdays, array $holidays = []): array
    {
        $skip = array_fill_keys(array_map('strval', $holidays), true);
        $s = strtotime($start);
        $e = strtotime($end);
        if (!$s || !$e || $e < $s) return [];
        $out = [];
        for ($ts = $s; $ts <= $e; $ts = strtotime('+1 day', $ts)) {
            $day = date('Y-m-d', $ts);
            if (in_array((int) date('w', $ts), $workingWeekdays, true) && !isset($skip[$day])) $out[] = $day;
        }
        return $out;
    }

    /** @param int[] $workingWeekdays @param string[] $holidays */
    public static function count(string $start, string $end, array $workingWeekdays, array $holidays = []): int
    {
        return count(self::dates($start, $end, $workingWeekdays, $holidays));
    }
}
