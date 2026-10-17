<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/**
 * Timesheet: worked hours per employee for a period, laid out for payroll (hours also as decimals),
 * with overtime and the leave taken by type. Built from report_days() rows. Pure.
 */
final class Timesheet
{
    /**
     * @param array<int,array<string,mixed>> $days report_days() rows (with overtime)
     * @return array<int,array<string,mixed>> per employee id, ordered by name
     */
    public static function byEmployee(array $days): array
    {
        $out = [];
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            if (!isset($out[$id])) {
                $out[$id] = ['employee_id' => $id, 'employee' => (string) $d['employee'], 'domain' => (string) ($d['domain'] ?? ''), 'teams' => (array) ($d['teams'] ?? []),
                    'worked_days' => 0, 'net_minutes' => 0, 'expected_minutes' => 0, 'ot_approved' => 0, 'ot_actual' => 0, 'ot_extra' => 0,
                    'late_minutes' => 0, 'early_minutes' => 0, 'absent' => 0, 'leave_days' => 0, 'leave' => [], 'holidays' => 0, 'trips' => 0];
            }
            $t = &$out[$id];
            if ((int) $d['net_minutes'] > 0) $t['worked_days']++;
            foreach (['net_minutes', 'expected_minutes', 'ot_approved', 'ot_actual', 'ot_extra', 'late_minutes', 'early_minutes'] as $k) $t[$k] += (int) ($d[$k] ?? 0);
            if ($d['bucket'] === 'Absent') $t['absent']++;
            elseif ($d['bucket'] === 'Holiday') $t['holidays']++;
            elseif ($d['bucket'] === 'Business Trip') $t['trips']++;
            elseif ($d['bucket'] === 'Leave') {
                $t['leave_days']++;
                $type = (string) $d['planned'];
                $t['leave'][$type] = ($t['leave'][$type] ?? 0) + 1;
            }
            unset($t);
        }
        foreach ($out as $id => $t) {
            ksort($t['leave']);
            $out[$id]['leave_breakdown'] = implode('; ', array_map(static function ($type, $n) { return $type . ' ' . $n; }, array_keys($t['leave']), $t['leave']));
            $out[$id]['balance_minutes'] = $t['net_minutes'] - $t['expected_minutes'];
        }
        uasort($out, static function ($a, $b) { return strcasecmp($a['employee'], $b['employee']); });
        return $out;
    }

    /** 430 → "7.17" (hours as a decimal, for payroll). */
    public static function decimalHours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }
}
