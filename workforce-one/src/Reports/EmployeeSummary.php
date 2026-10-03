<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * Attendance Summary: report days (DayMetrics rows) added up per employee and for the whole
 * report, plus the previous period used for comparisons. Pure.
 */
final class EmployeeSummary
{
    private const ZERO = ['expected_days' => 0, 'present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'holiday' => 0, 'trip' => 0,
        'late_minutes' => 0, 'early_minutes' => 0, 'missing_sign_out' => 0, 'net_minutes' => 0, 'expected_minutes' => 0, 'first_in_total' => 0, 'first_in_days' => 0];

    /**
     * @param array<int,array<string,mixed>> $days report_days() rows
     * @return array<int,array<string,mixed>> per employee id, ordered by name
     */
    public static function byEmployee(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            if (!isset($out[$id])) $out[$id] = ['employee_id' => $id, 'employee' => (string) $d['employee'], 'domain' => (string) ($d['domain'] ?? ''), 'teams' => (array) ($d['teams'] ?? [])] + self::ZERO;
            $out[$id] = self::add($out[$id], $d);
        }
        foreach ($out as $id => $row) $out[$id] = self::finish($row);
        uasort($out, static function ($a, $b) { return strcasecmp($a['employee'], $b['employee']); });
        return $out;
    }

    /** @param array<int,array<string,mixed>> $days */
    public static function totals(array $days): array
    {
        $t = self::ZERO;
        foreach ($days as $d) $t = self::add($t, $d);
        return self::finish($t);
    }

    /** The period of the same length that ends the day before $start. */
    public static function previousPeriod(string $start, string $end): array
    {
        $len = (int) round((strtotime($end . ' 12:00') - strtotime($start . ' 12:00')) / 86400) + 1;
        $prevEnd = date('Y-m-d', strtotime($start . ' 12:00') - 86400);
        return [date('Y-m-d', strtotime($prevEnd . ' 12:00') - ($len - 1) * 86400), $prevEnd];
    }

    private static function add(array $t, array $d): array
    {
        $bucket = (string) $d['bucket'];
        if (!empty($d['expected'])) $t['expected_days']++;
        if ($bucket === 'Present') $t['present']++;
        elseif ($bucket === 'Late') $t['late']++;
        elseif ($bucket === 'Absent') $t['absent']++;
        elseif ($bucket === 'Leave') $t['leave']++;
        elseif ($bucket === 'Holiday') $t['holiday']++;
        elseif ($bucket === 'Business Trip') $t['trip']++;
        $t['late_minutes'] += (int) $d['late_minutes'];
        $t['early_minutes'] += (int) $d['early_minutes'];
        $t['missing_sign_out'] += !empty($d['missing_sign_out']) ? 1 : 0;
        $t['net_minutes'] += (int) $d['net_minutes'];
        $t['expected_minutes'] += (int) $d['expected_minutes'];
        if (in_array($bucket, ['Present', 'Late'], true) && preg_match('/^(\d{1,2}):(\d{2})$/', (string) ($d['sign_in'] ?? ''), $m)) {
            $t['first_in_total'] += (int) $m[1] * 60 + (int) $m[2];
            $t['first_in_days']++;
        }
        return $t;
    }

    private static function finish(array $t): array
    {
        $attended = $t['present'] + $t['late'];
        $t['attendance_rate'] = Insights::rate($t['present'], $t['late'], $t['absent']);
        $t['punctuality_rate'] = $attended ? (int) round($t['present'] / $attended * 100) : 0;
        $avg = $t['first_in_days'] ? (int) round($t['first_in_total'] / $t['first_in_days']) : null;
        $t['avg_first_in'] = $avg === null ? '' : sprintf('%02d:%02d', intdiv($avg, 60), $avg % 60);
        $t['balance_minutes'] = $t['net_minutes'] - $t['expected_minutes'];
        unset($t['first_in_total'], $t['first_in_days']);
        return $t;
    }
}
