<?php
namespace WorkforceOne\DailyWorkers;

if (!defined('ABSPATH')) exit;

/**
 * Daily workers' pay (3.31.76): what a day is worth, the pay period a day falls in, and the payout
 * lines of a period with advances deducted. Pure: no WordPress calls. Amounts in the company
 * currency, rounded to 2 decimals.
 *
 * A day: present = the daily rate, half day = rate ÷ 2, absent = 0; plus extra hours × hourly rate.
 * Advances: everything a worker was advanced and not yet deducted comes off the next payout, up to
 * what the payout is worth (the rest waits for the one after).
 */
final class PayRules
{
    public const MARKS = ['in', 'half', 'out'];
    public const MAX_EXTRA = 12.0;

    public static function money(float $v): float
    {
        return round($v + 0.0, 2);
    }

    /** Days a mark counts for: 1, 0.5 or 0. */
    public static function dayCount(string $mark): float
    {
        return $mark === 'in' ? 1.0 : ($mark === 'half' ? 0.5 : 0.0);
    }

    public static function dayAmount(string $mark, float $extraHours, float $dailyRate, float $hourlyRate): float
    {
        if (!in_array($mark, self::MARKS, true)) return 0.0;
        $base = $mark === 'in' ? $dailyRate : ($mark === 'half' ? $dailyRate / 2 : 0.0);
        $extra = $mark === 'out' ? 0.0 : max(0.0, $extraHours) * $hourlyRate;
        return self::money($base + $extra);
    }

    /** Extra hours as saved: 0 – MAX_EXTRA in half hours; anything else is null (invalid). */
    public static function extra($value): ?float
    {
        if ($value === '' || $value === null) return 0.0;
        if (!is_numeric($value)) return null;
        $v = (float) $value;
        if ($v < 0 || $v > self::MAX_EXTRA) return null;
        return round($v * 2) / 2;
    }

    /**
     * The pay period a date falls in.
     * @param string $kind daily | weekly
     * @param int $weekStart 0 = Sunday … 6 = Saturday
     * @return array{0:string,1:string} [first day, last day]
     */
    public static function period(string $date, string $kind, int $weekStart): array
    {
        $ts = strtotime($date . ' 12:00:00 UTC');
        if ($ts === false) return [$date, $date];
        if ($kind !== 'weekly') return [gmdate('Y-m-d', $ts), gmdate('Y-m-d', $ts)];
        $back = ((int) gmdate('w', $ts) - $weekStart + 7) % 7;
        $start = $ts - $back * 86400;
        return [gmdate('Y-m-d', $start), gmdate('Y-m-d', $start + 6 * 86400)];
    }

    /**
     * The payout of a period.
     * @param list<array{worker_id:int,mark:string,extra_hours:float|int|string,amount:float|int|string}> $days the period's unpaid days
     * @param array<int,float> $outstanding advances not yet deducted, per worker
     * @return array{lines:array<int,array{worker_id:int,days:float,extra_hours:float,amount:float,advance:float,net:float}>,amount:float,advance:float,net:float}
     */
    public static function payout(array $days, array $outstanding): array
    {
        $lines = [];
        foreach ($days as $d) {
            $w = (int) $d['worker_id'];
            if (!isset($lines[$w])) $lines[$w] = ['worker_id' => $w, 'days' => 0.0, 'extra_hours' => 0.0, 'amount' => 0.0, 'advance' => 0.0, 'net' => 0.0];
            $lines[$w]['days'] += self::dayCount((string) $d['mark']);
            $lines[$w]['extra_hours'] += (string) $d['mark'] === 'out' ? 0.0 : (float) $d['extra_hours'];
            $lines[$w]['amount'] = self::money($lines[$w]['amount'] + (float) $d['amount']);
        }
        $total = ['amount' => 0.0, 'advance' => 0.0, 'net' => 0.0];
        foreach ($lines as $w => $l) {
            $adv = self::money(min(max(0.0, (float) ($outstanding[$w] ?? 0)), $l['amount']));
            $lines[$w]['advance'] = $adv;
            $lines[$w]['net'] = self::money($l['amount'] - $adv);
            $total['amount'] = self::money($total['amount'] + $l['amount']);
            $total['advance'] = self::money($total['advance'] + $adv);
            $total['net'] = self::money($total['net'] + $lines[$w]['net']);
        }
        return ['lines' => $lines] + $total;
    }
}
