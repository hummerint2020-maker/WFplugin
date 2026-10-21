<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * Workforce: the planned distribution per day (Office, WFH, leave, missions, other types, not set),
 * with company holidays as their own column and the actual absences next to the plan. Pure.
 */
final class Workforce
{
    /** Column key => label, in display order. */
    public const COLUMNS = ['office' => 'Office', 'wfh' => 'WFH', 'leave' => 'Leave', 'trip' => 'Business Trip', 'other' => 'Other', 'not_set' => 'Not Set', 'holiday' => 'Holiday', 'absent' => 'Absent'];

    private const PLAN = ['Office' => 'office', 'WFH' => 'wfh', 'Leave' => 'leave', 'Business Trip' => 'trip', 'Other' => 'other', 'Absent' => 'other', 'Not Set' => 'not_set'];

    /**
     * @param array<int,array<string,mixed>> $days report_days() rows (with 'rule')
     * @return array<string,array<string,int>> per date, in date order
     */
    public static function byDay(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $date = (string) $d['date'];
            if (!isset($out[$date])) $out[$date] = array_fill_keys(array_keys(self::COLUMNS), 0);
            if ($d['result'] === DayMetrics::HOLIDAY) {
                $out[$date]['holiday']++;
                continue;
            }
            $planned = (string) $d['planned'] === 'Not Set' ? '' : (string) $d['planned'];
            $out[$date][self::PLAN[Insights::planCategory($planned, $d['rule'] ?? null)] ?? 'other']++;
            if ($d['result'] === 'Absent') $out[$date]['absent']++;
        }
        ksort($out);
        return $out;
    }

    /** @param array<string,array<string,int>> $byDay */
    public static function totals(array $byDay): array
    {
        $t = array_fill_keys(array_keys(self::COLUMNS), 0);
        foreach ($byDay as $row) foreach ($row as $k => $n) $t[$k] += $n;
        return $t;
    }
}
