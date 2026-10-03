<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * One employee's day, as every report counts it. Pure: no WordPress calls.
 *
 * Definitions (shown to users in report exports):
 * - Expected day: the schedule type requires Sign In and the day is not a company holiday.
 * - Late: first Sign In after shift start + grace; late minutes count from the shift start.
 * - Early leave: last Sign Out before the shift end (expected days only).
 * - Net minutes: (last Sign Out − first Sign In) − closed breaks. Overnight shifts work because the
 *   times are full date-times.
 * - Expected minutes: shift length − the allowed break, on expected days.
 */
final class DayMetrics
{
    public const HOLIDAY = 'Holiday';

    /**
     * @param array{
     *   date:string, today:string, now:string, holiday:?string,
     *   planned:string, rule:?string, requires_sign_in:bool,
     *   shift_start:string, shift_end:string, normal_until:string, grace:int, overnight:bool, break_allowance:int,
     *   first_in:?string, first_in_legacy_late:bool, last_out:?string, break_minutes:int
     * } $f  times are 'H:i'; first_in / last_out / now are 'Y-m-d H:i:s'
     * @return array{result:string,expected:bool,late_minutes:int,early_minutes:int,gross_minutes:int,break_minutes:int,net_minutes:int,expected_minutes:int,missing_sign_out:bool}
     */
    public static function compute(array $f): array
    {
        $start = strtotime($f['date'] . ' ' . $f['shift_start'] . ':00');
        $end = strtotime($f['date'] . ' ' . $f['shift_end'] . ':00');
        if ($end <= $start) $end += 86400; // overnight shift ends the next day
        $in = $f['first_in'] ? strtotime($f['first_in']) : null;
        $out = $f['last_out'] ? strtotime($f['last_out']) : null;
        if ($in !== null && $out !== null && $out <= $in) $out = null; // a Sign Out before the Sign In is not a day's end

        $holiday = $f['holiday'] !== null && $f['holiday'] !== '';
        $expected = !$holiday && $f['requires_sign_in'] && $f['rule'] !== 'leave' && $f['rule'] !== 'business_trip';

        if ($holiday) {
            $result = self::HOLIDAY;
        } else {
            $late = $in !== null && ($f['first_in_legacy_late'] || $in > $start + $f['grace'] * 60);
            $cutoff = strtotime($f['date'] . ' ' . $f['normal_until'] . ':00');
            if ($f['normal_until'] < $f['shift_start']) $cutoff += 86400;
            $result = Insights::actual([
                'holiday' => false, 'rule' => $f['rule'], 'requires_sign_in' => $f['requires_sign_in'],
                'classification' => $in !== null ? ($late ? 'Late Arrival' : 'On Time') : null, 'legacy_late' => false,
                'date' => $f['date'], 'today' => $f['today'], 'past_cutoff' => strtotime($f['now']) >= $cutoff,
            ], $f['planned']);
        }

        $gross = ($in !== null && $out !== null) ? intdiv($out - $in, 60) : 0;
        $break = min(max(0, $f['break_minutes']), $gross);
        $length = intdiv($end - $start, 60);
        return [
            'result' => $result,
            'expected' => $expected,
            'late_minutes' => $result === 'Late' && $in !== null ? max(0, intdiv($in - $start, 60)) : 0,
            'early_minutes' => $expected && $out !== null && $out < $end ? intdiv($end - $out, 60) : 0,
            'gross_minutes' => $gross,
            'break_minutes' => $break,
            'net_minutes' => $gross - $break,
            'expected_minutes' => $expected ? max(0, $length - max(0, $f['break_allowance'])) : 0,
            'missing_sign_out' => $in !== null && $out === null && ($f['date'] < $f['today'] || strtotime($f['now']) > $end),
        ];
    }

    /** 430 → "7:10" (hours:minutes, the timesheet convention). */
    public static function hm(int $minutes): string
    {
        $minutes = max(0, $minutes);
        return intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }

    /** A balance: −40 → "−0:40" (U+2212), 120 → "+2:00", 0 → "0:00". */
    public static function signedHm(int $minutes): string
    {
        return ($minutes < 0 ? '−' : ($minutes > 0 ? '+' : '')) . self::hm(abs($minutes));
    }

    /**
     * Overtime on one day, from approved overtime windows and the employee's own shift.
     * - Approved: the length of each approved window.
     * - Actual: the part of a window before the shift start that the first Sign In covers, plus the
     *   part after the shift end that the last Sign Out covers (time inside the shift is not overtime).
     * - Unapproved extra: presence after the shift end not covered by an approved window.
     * @param array<int,array{start:string,end:string}> $windows 'H:i' or 'H:i:s'
     * @return array{approved:int,actual:int,extra:int,windows:array<int,array{approved:int,actual:int}>}
     */
    public static function overtime(string $date, string $shiftStart, string $shiftEnd, ?string $firstIn, ?string $lastOut, array $windows): array
    {
        $start = strtotime($date . ' ' . $shiftStart);
        $end = strtotime($date . ' ' . $shiftEnd);
        if ($end <= $start) $end += 86400;
        $in = $firstIn ? strtotime($firstIn) : null;
        $out = $lastOut ? strtotime($lastOut) : null;
        $approved = 0; $actual = 0; $approvedPost = 0; $each = [];
        foreach ($windows as $w) {
            $rs = strtotime($date . ' ' . $w['start']);
            $re = strtotime($date . ' ' . $w['end']);
            if ($re <= $rs) continue;
            $mins = intdiv($re - $rs, 60);
            $real = 0;
            if ($in !== null && $rs < $start) $real += max(0, intdiv(min($re, $start) - max($rs, $in), 60));
            if ($out !== null && $re > $end) $real += max(0, intdiv(min($re, $out) - max($rs, $end), 60));
            if ($re > $end) $approvedPost += intdiv($re - max($rs, $end), 60);
            $approved += $mins; $actual += $real;
            $each[] = ['approved' => $mins, 'actual' => $real];
        }
        $presence = $out !== null && $out > $end ? intdiv($out - $end, 60) : 0;
        return ['approved' => $approved, 'actual' => $actual, 'extra' => max(0, $presence - min($presence, $approvedPost)), 'windows' => $each];
    }
}
