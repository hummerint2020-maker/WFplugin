<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/**
 * Overtime report: per employee, the overtime requests of the period by status, the approved
 * minutes, the minutes actually worked inside them and the unapproved extra time. Built from
 * report_days() rows (days off included) and the period's overtime requests. Pure.
 */
final class OvertimeReport
{
    /**
     * @param array<int,array<string,mixed>> $days report_days() rows with ot_approved / ot_actual / ot_extra
     * @param array<int,array{employee_id:int|string,status:string}> $requests overtime requests dated in the period
     * @return array<int,array<string,mixed>> per employee id, ordered by name; only employees with overtime
     */
    public static function byEmployee(array $days, array $requests): array
    {
        $out = [];
        $seed = static function (array $d): array {
            return ['employee_id' => (int) $d['employee_id'], 'employee' => (string) $d['employee'], 'domain' => (string) ($d['domain'] ?? ''), 'teams' => (array) ($d['teams'] ?? []),
                'requests' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'approved_minutes' => 0, 'worked_minutes' => 0, 'extra_minutes' => 0, 'days' => 0];
        };
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            if (!isset($out[$id])) $out[$id] = $seed($d);
            $out[$id]['approved_minutes'] += (int) ($d['ot_approved'] ?? 0);
            $out[$id]['worked_minutes'] += (int) ($d['ot_actual'] ?? 0);
            $out[$id]['extra_minutes'] += (int) ($d['ot_extra'] ?? 0);
            if ((int) ($d['ot_actual'] ?? 0) > 0) $out[$id]['days']++;
        }
        foreach ($requests as $r) {
            $id = (int) $r['employee_id'];
            if (!isset($out[$id])) continue; // outside the report's employees
            $out[$id]['requests']++;
            $key = strtolower((string) $r['status']);
            if (in_array($key, ['approved', 'pending', 'rejected'], true)) $out[$id][$key]++;
        }
        $out = array_filter($out, static function ($p) { return $p['requests'] > 0 || $p['worked_minutes'] > 0 || $p['extra_minutes'] > 0 || $p['approved_minutes'] > 0; });
        foreach ($out as $id => $p) $out[$id]['utilisation'] = self::utilisation($p['worked_minutes'], $p['approved_minutes']);
        uasort($out, static function ($a, $b) { return strcasecmp($a['employee'], $b['employee']); });
        return $out;
    }

    /** @param array<int,array<string,mixed>> $people byEmployee() */
    public static function totals(array $people): array
    {
        $t = ['employees' => count($people), 'requests' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0, 'approved_minutes' => 0, 'worked_minutes' => 0, 'extra_minutes' => 0];
        foreach ($people as $p) foreach (array_keys($t) as $k) if ($k !== 'employees') $t[$k] += (int) $p[$k];
        $t['utilisation'] = self::utilisation($t['worked_minutes'], $t['approved_minutes']);
        return $t;
    }

    /** Worked / approved, in whole percent; null when nothing was approved. */
    public static function utilisation(int $worked, int $approved): ?int
    {
        return $approved > 0 ? (int) round($worked * 100 / $approved) : null;
    }
}
