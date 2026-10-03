<?php
namespace WorkforceOne\Payroll;

if (!defined('ABSPATH')) exit;

/**
 * One employee's pay for a month, from their salary, the payroll rules and the report engine's
 * days (src/Reports/DayMetrics.php via report_days()). Pure: no WordPress calls.
 *
 * - Day value = (basic + allowances, or basic only) ÷ the day divisor (30 by default).
 *   Minute value = day value ÷ the minutes of the employee's working day (shift − break allowance).
 * - $rate['effective_from'] is when the employee's first salary started: if that is after the 1st,
 *   the month is paid for the days from it (day value × days, at most the full month) and days
 *   before it are not counted. The plugin passes the salary in effect on the month's last day.
 * - Absent (a planned working day with no Sign In): absence days × day value.
 * - Late: the report's late minutes (counted from the shift start, only once past the grace), each
 *   minute at the minute value; or, by tiers, the share of a day of the highest tier passed
 *   (for example more than 15 minutes = 0.25 day).
 * - Early leave: the report's early minutes, less an approved Early Leave request that day.
 * - Leave: (100 − the leave type's paid %) of a day's value for each leave day; leave without a
 *   request (planned by a manager) is paid.
 * - Overtime: the approved overtime actually worked (inside approved windows) × the minute value ×
 *   the work-day rate, or the days-off rate on a day off or company holiday.
 * - Deductions for attendance can be capped at a number of days' value (0 = no cap).
 * - Bonuses and deductions added by hand (with a reason) are added or taken off as they are; the
 *   cap does not apply to them.
 * - A day without a Sign Out counts as worked and is listed for review.
 * Every day's amount is rounded to 2 decimals; totals are sums of the rounded amounts.
 */
final class PayCalculator
{
    /**
     * @param array{basic:float,allowances:array<int,array{name:string,amount:float}>,effective_from:string} $rate
     * @param array{day_divisor:int,day_base:string,absence_days:float,overtime_rate:float,overtime_rate_off:float,max_deduction_days:float} $rules
     * @param array<int,array<string,mixed>> $days report_days() rows of this employee and month (with off days), each
     *        with 'approved_early' (minutes) and, on leave days, 'leave_paid' (0–100) and 'leave_type'
     * @param int $dayMinutes minutes of the employee's working day
     * @param string $month 'Y-m'
     * @param array<int,array{id?:int,kind:string,amount:float,reason:string}> $adjustments bonuses and deductions added by hand
     * @return array<string,mixed>
     */
    public static function month(array $rate, array $rules, array $days, int $dayMinutes, string $month, array $adjustments = []): array
    {
        $basic = round((float) $rate['basic'], 2);
        $allowances = array_values(array_filter((array) $rate['allowances'], static function ($a) { return is_array($a) && (float) ($a['amount'] ?? 0) > 0; }));
        $allowTotal = round(array_sum(array_map(static function ($a) { return (float) $a['amount']; }, $allowances)), 2);
        $monthly = $basic + $allowTotal;
        $divisor = max(1, (int) $rules['day_divisor']);
        $dayValue = (($rules['day_base'] ?? 'gross') === 'basic' ? $basic : $monthly) / $divisor;
        $minuteValue = $dayMinutes > 0 ? $dayValue / $dayMinutes : 0.0;

        $first = $month . '-01';
        $last = date('Y-m-t', (int) strtotime($first));
        $from = max($first, (string) $rate['effective_from']);
        $earned = $monthly;
        $prorated = null;
        if ($from > $first) {
            $paidDays = (int) ((strtotime($last) - strtotime($from)) / 86400) + 1;
            $earned = min($monthly, round($monthly / $divisor * $paidDays, 2));
            $prorated = ['from' => $from, 'days' => $paidDays, 'amount' => $earned];
        }

        $out = [
            'basic' => $basic, 'allowances' => $allowances, 'allowances_total' => $allowTotal, 'monthly' => round($monthly, 2),
            'earned' => round($earned, 2), 'prorated' => $prorated,
            'day_value' => round($dayValue, 2), 'minute_value' => $minuteValue, 'day_minutes' => $dayMinutes,
            'absence' => ['days' => [], 'amount' => 0.0],
            'late' => ['days' => [], 'minutes' => 0, 'amount' => 0.0],
            'early' => ['days' => [], 'minutes' => 0, 'amount' => 0.0],
            'leave' => ['days' => [], 'types' => [], 'amount' => 0.0],
            'overtime' => ['days' => [], 'minutes' => 0, 'amount' => 0.0],
            'review' => [],
            'stats' => ['worked_days' => 0, 'expected_days' => 0, 'late_days' => 0, 'absent_days' => 0, 'leave_days' => 0],
        ];
        foreach ($days as $d) {
            $date = (string) $d['date'];
            if ($date < $from || $date > $last) continue;
            $off = $d['result'] === 'Off Day' || $d['result'] === 'Holiday';
            if (!empty($d['expected'])) $out['stats']['expected_days']++;
            if (!empty($d['expected']) && ((int) ($d['net_minutes'] ?? 0) > 0 || !empty($d['missing_sign_out']))) $out['stats']['worked_days']++;

            if ($d['result'] === 'Absent') {
                $amount = round($dayValue * (float) $rules['absence_days'], 2);
                $out['absence']['days'][] = ['date' => $date, 'amount' => $amount];
                $out['absence']['amount'] += $amount;
                $out['stats']['absent_days']++;
            }
            $late = (int) ($d['late_minutes'] ?? 0);
            if ($late > 0) {
                $amount = round(($rules['late_mode'] ?? 'minute') === 'tiers' ? $dayValue * self::tier($late, (array) ($rules['late_tiers'] ?? [])) : $late * $minuteValue, 2);
                $out['late']['days'][] = ['date' => $date, 'minutes' => $late, 'sign_in' => (string) ($d['sign_in'] ?? ''), 'amount' => $amount];
                $out['late']['minutes'] += $late;
                $out['late']['amount'] += $amount;
                $out['stats']['late_days']++;
            }
            $early = max(0, (int) ($d['early_minutes'] ?? 0) - (int) ($d['approved_early'] ?? 0));
            if ($early > 0) {
                $amount = round($early * $minuteValue, 2);
                $out['early']['days'][] = ['date' => $date, 'minutes' => $early, 'sign_out' => (string) ($d['sign_out'] ?? ''), 'amount' => $amount];
                $out['early']['minutes'] += $early;
                $out['early']['amount'] += $amount;
            }
            if (($d['bucket'] ?? '') === 'Leave') {
                $out['stats']['leave_days']++;
                $paid = max(0, min(100, (int) ($d['leave_paid'] ?? 100)));
                if ($paid < 100) {
                    $amount = round($dayValue * (100 - $paid) / 100, 2);
                    $type = (string) ($d['leave_type'] ?? 'Leave');
                    $out['leave']['days'][] = ['date' => $date, 'type' => $type, 'paid' => $paid, 'amount' => $amount];
                    $out['leave']['amount'] += $amount;
                    $t = $out['leave']['types'][$type] ?? ['days' => 0, 'paid' => $paid, 'amount' => 0.0, 'dates' => []];
                    $t['days']++; $t['amount'] = round($t['amount'] + $amount, 2); $t['dates'][] = $date;
                    $out['leave']['types'][$type] = $t;
                }
            }
            $ot = (int) ($d['ot_actual'] ?? 0);
            if ($ot > 0) {
                $factor = $off ? (float) $rules['overtime_rate_off'] : (float) $rules['overtime_rate'];
                $amount = round($ot * $minuteValue * $factor, 2);
                $out['overtime']['days'][] = ['date' => $date, 'minutes' => $ot, 'off' => $off, 'rate' => $factor, 'amount' => $amount];
                $out['overtime']['minutes'] += $ot;
                $out['overtime']['amount'] += $amount;
            }
            if (!empty($d['missing_sign_out'])) $out['review'][] = ['date' => $date, 'reason' => 'No Sign Out'];
        }
        foreach (['absence', 'late', 'early', 'leave', 'overtime'] as $k) $out[$k]['amount'] = round($out[$k]['amount'], 2);

        $deductions = round($out['absence']['amount'] + $out['late']['amount'] + $out['early']['amount'] + $out['leave']['amount'], 2);
        $cap = (float) $rules['max_deduction_days'] > 0 ? round($dayValue * (float) $rules['max_deduction_days'], 2) : null;
        $out['deductions_before_cap'] = $deductions;
        $out['cap'] = $cap !== null && $deductions > $cap ? $cap : null;
        $out['deductions'] = $out['cap'] ?? $deductions;
        $out['bonuses'] = ['items' => [], 'amount' => 0.0];
        $out['manual'] = ['items' => [], 'amount' => 0.0];
        foreach ($adjustments as $a) {
            $key = $a['kind'] === 'bonus' ? 'bonuses' : 'manual';
            $amount = round(max(0, (float) $a['amount']), 2);
            $out[$key]['items'][] = ['id' => (int) ($a['id'] ?? 0), 'reason' => (string) $a['reason'], 'amount' => $amount];
            $out[$key]['amount'] = round($out[$key]['amount'] + $amount, 2);
        }
        $out['total_deductions'] = round($out['deductions'] + $out['manual']['amount'], 2);
        $out['net'] = round($out['earned'] + $out['overtime']['amount'] + $out['bonuses']['amount'] - $out['total_deductions'], 2);
        return $out;
    }

    /**
     * Share of a day for a number of late minutes: the highest tier whose minutes are passed.
     * @param array<int,array{after:int,days:float}> $tiers
     */
    public static function tier(int $minutes, array $tiers): float
    {
        $share = 0.0;
        foreach ($tiers as $t) {
            if ($minutes > (int) $t['after']) $share = max($share, (float) $t['days']);
        }
        return $share;
    }

    /** 90 → "1:30". */
    public static function hm(int $minutes): string
    {
        return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
