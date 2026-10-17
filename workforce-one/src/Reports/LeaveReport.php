<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\Balance;

/**
 * Leave & Balances report: per employee, the days on leave in the period by schedule type, the leave
 * requests overlapping the period by status, and the balance of each leave type for the period's
 * leave year. Pure.
 */
final class LeaveReport
{
    /**
     * @param array<int,array{employee_id:int,employee:string,domain:string,teams:string[]}> $people the report's employees, in display order
     * @param array<int,array<string,mixed>> $days report_days() rows
     * @param array<int,array{employee_id:int|string,status:string,requested_days:float|string}> $requests leave requests overlapping the period
     * @param array<int,array{employee_id:int|string,type:string,entitlement:float|string,used:float|string,pending:float|string}> $balances
     * @return array<int,array<string,mixed>> per employee id
     */
    public static function byEmployee(array $people, array $days, array $requests, array $balances): array
    {
        $out = [];
        foreach ($people as $p) {
            $out[(int) $p['employee_id']] = $p + ['leave_days' => 0, 'leave' => [], 'approved_requests' => 0, 'approved_days' => 0.0, 'pending_requests' => 0, 'pending_days' => 0.0, 'balances' => []];
        }
        foreach ($days as $d) {
            $id = (int) $d['employee_id'];
            if (!isset($out[$id]) || $d['bucket'] !== 'Leave') continue;
            $out[$id]['leave_days']++;
            $type = (string) $d['planned'];
            $out[$id]['leave'][$type] = ($out[$id]['leave'][$type] ?? 0) + 1;
        }
        foreach ($requests as $r) {
            $id = (int) $r['employee_id'];
            $status = strtolower((string) $r['status']);
            if (!isset($out[$id]) || !in_array($status, ['approved', 'pending'], true)) continue;
            $out[$id][$status . '_requests']++;
            $out[$id][$status . '_days'] += (float) $r['requested_days'];
        }
        foreach ($balances as $b) {
            $id = (int) $b['employee_id'];
            if (!isset($out[$id])) continue;
            $e = (float) $b['entitlement']; $u = (float) $b['used']; $pe = (float) $b['pending'];
            $out[$id]['balances'][(string) $b['type']] = ['entitlement' => $e, 'used' => $u, 'pending' => $pe, 'remaining' => Balance::remaining($e, $u, $pe)];
        }
        foreach ($out as $id => $p) {
            ksort($p['leave']);
            $out[$id]['leave_breakdown'] = implode('; ', array_map(static function ($type, $n) { return $type . ' ' . $n; }, array_keys($p['leave']), $p['leave']));
        }
        return $out;
    }

    /** @param array<int,array<string,mixed>> $people byEmployee() */
    public static function totals(array $people): array
    {
        $t = ['on_leave' => 0, 'leave_days' => 0, 'approved_requests' => 0, 'pending_requests' => 0, 'overdrawn' => 0];
        foreach ($people as $p) {
            if ($p['leave_days'] > 0) $t['on_leave']++;
            $t['leave_days'] += $p['leave_days'];
            $t['approved_requests'] += $p['approved_requests'];
            $t['pending_requests'] += $p['pending_requests'];
            foreach ($p['balances'] as $b) if ($b['remaining'] < 0) { $t['overdrawn']++; break; }
        }
        return $t;
    }

    /** 16.0 → "16", 2.5 → "2.5" (leave days). */
    public static function days(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** "Annual Leave" → "annual-leave" (the balance column key). */
    public static function typeKey(string $type): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($type)), '-');
    }
}
