<?php
namespace WorkforceOne\Overtime;

if (!defined('ABSPATH')) exit;

/**
 * Validates an overtime request. Pure: no WordPress calls.
 * Error codes are the ?overtime_error= values shown by the Overtime page.
 */
final class RequestRules
{
    public const DATE = 'date';
    public const TIME = 'time';
    public const REASON = 'reason';
    public const OVERLAP = 'overlap';

    /** Minutes between two HH:MM times on the same day; 0 when invalid or not increasing. */
    public static function minutes(string $start, string $end): int
    {
        $a = strtotime('1970-01-01 ' . $start);
        $b = strtotime('1970-01-01 ' . $end);
        if (!$a || !$b || $b <= $a) return 0;
        return (int) round(($b - $a) / 60);
    }

    /** Overtime may be requested for today or later. */
    public static function isAllowedDate(string $date, string $today): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date >= $today;
    }

    /** "HH:MM" -> "HH:MM:00" so comparisons against TIME columns behave the same on every database. */
    public static function withSeconds(string $time): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $time) ? $time . ':00' : $time;
    }

    /** Hours shown after submitting (2 decimals, e.g. 2.5). */
    public static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /** "2 hours 30 min", "1 hour" - used in notification texts (kept English like the audit log). */
    public static function durationText(int $minutes): string
    {
        $hours = (int) floor($minutes / 60);
        $mins = $minutes % 60;
        return $hours . ($hours === 1 ? ' hour' : ' hours') . ($mins ? ' ' . $mins . ' min' : '');
    }

    /** @param array{date: string, today: string, start: string, end: string, reason: string, overlaps?: bool} $f */
    public static function check(array $f): ?string
    {
        if (!self::isAllowedDate($f['date'], $f['today'])) return self::DATE;
        if (!preg_match('/^\d{2}:\d{2}$/', $f['start']) || !preg_match('/^\d{2}:\d{2}$/', $f['end'])) return self::TIME;
        if (self::minutes($f['start'], $f['end']) < 1) return self::TIME;
        if ($f['reason'] === '') return self::REASON;
        if (!empty($f['overlaps'])) return self::OVERLAP;
        return null;
    }
}
