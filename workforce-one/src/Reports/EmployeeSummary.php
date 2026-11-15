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
        'late_minutes' => 0, 'early_minutes' => 0, 'missing_sign_out' => 0, 'other_branch' => 0, 'net_minutes' => 0, 'expected_minutes' => 0, 'first_in_total' => 0, 'first_in_days' => 0];

    /**
     * @param array<int,array<string,mixed>> $days report_days() rows
     * @return array<int,array<string,mixed>> per employee id, ordered by name
     */
    public static function byEmployee(array $days): array
    {
        return self::finishByEmployee(self::accumulate(self::start(), $days));
    }

    /** @param array<int,array<string,mixed>> $days */
    public static function totals(array $days): array
    {
        return self::finishTotals(self::accumulate(self::start(), $days));
    }

    /**
     * The same figures a part of the days at a time (3.31.149): start(), accumulate() for each part
     * in order, then finishByEmployee() / finishTotals(). Over any split of the days the result is
     * byEmployee() / totals() of all of them: the employees keep the order they first appear in and
     * are sorted once, at the end.
     * @return array{by:array<int,array<string,mixed>>,all:array<string,int>}
     */
    public static function start(): array
    {
        return ['by' => [], 'all' => self::ZERO];
    }

    /**
     * @param array{by:array<int,array<string,mixed>>,all:array<string,int>} $acc
     * @param iterable<array<string,mixed>> $days
     * @return array{by:array<int,array<string,mixed>>,all:array<string,int>}
     */
    public static function accumulate(array $acc, iterable $days): array
    {
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            if (!isset($acc['by'][$id])) $acc['by'][$id] = ['employee_id' => $id, 'employee' => (string) $d['employee'], 'domain' => (string) ($d['domain'] ?? ''), 'teams' => (array) ($d['teams'] ?? [])] + self::ZERO;
            $acc['by'][$id] = self::add($acc['by'][$id], $d);
            $acc['all'] = self::add($acc['all'], $d);
        }
        return $acc;
    }

    /**
     * @param array{by:array<int,array<string,mixed>>,all:array<string,int>} $acc
     * @return array<int,array<string,mixed>>
     */
    public static function finishByEmployee(array $acc): array
    {
        $out = $acc['by'];
        foreach ($out as $id => $row) $out[$id] = self::finish($row);
        uasort($out, static function ($a, $b) { return strcasecmp($a['employee'], $b['employee']); });
        return $out;
    }

    /** @param array{by:array<int,array<string,mixed>>,all:array<string,int>} $acc */
    public static function finishTotals(array $acc): array
    {
        return self::finish($acc['all']);
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
        $t['other_branch'] += !empty($d['other_branch']) ? 1 : 0;   // 3.31.72
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
