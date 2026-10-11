<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * The one rule for "late": a Sign In is late when it is after the shift start plus the grace period
 * (a Sign In exactly at the end of the grace is on time). Used by the reports and payroll
 * (src/Reports/DayMetrics.php, which know the work day) and by the dashboard, My Profile, the
 * Sign In / Out report and the Sign In page (sign_in_classification(), which know only the time).
 * Pure: no WordPress calls.
 */
final class Lateness
{
    public const ON_TIME = 'On Time';
    public const LATE = 'Late Arrival';

    /** Unix time of the shift start on a work day ('Y-m-d', 'H:i'). */
    public static function shiftStart(string $workDate, string $start): int
    {
        return (int) strtotime($workDate . ' ' . $start . ':00');
    }

    public static function isLate(int $signIn, int $shiftStart, int $graceMinutes): bool
    {
        return $signIn > $shiftStart + $graceMinutes * 60;
    }

    /**
     * The work day a Sign In counts against when only its time is known: for an overnight shift
     * (start after end), a time up to the shift end belongs to the shift that started the day
     * before. This is the same day ShiftDay gives a Sign In made in the app.
     */
    public static function dayOf(int $ts, string $start, string $end, bool $overnight): string
    {
        $date = date('Y-m-d', $ts);
        if ($overnight && $start > $end && date('H:i', $ts) <= $end) return date('Y-m-d', (int) strtotime($date . ' -1 day'));
        return $date;
    }

    /** On Time or Late Arrival for a Sign In at $ts, from its time alone. */
    public static function classify(int $ts, string $start, string $end, bool $overnight, int $graceMinutes): string
    {
        return self::isLate($ts, self::shiftStart(self::dayOf($ts, $start, $end, $overnight), $start), $graceMinutes) ? self::LATE : self::ON_TIME;
    }
}
