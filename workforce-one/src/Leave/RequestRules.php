<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Validates a new leave request. Pure: no WordPress calls.
 * The error codes are the ?leave_error= values shown by the Leave page.
 */
final class RequestRules
{
    public const DATE = 'date';
    public const CROSS_YEAR = 'cross_year';
    public const NO_WORKING_DAYS = 'no_working_days';
    public const OVERLAP = 'overlap';
    public const BALANCE = 'balance';

    /** Leave year a request is charged to: the calendar year of its dates. */
    public static function year(string $date): int
    {
        return (int) substr($date, 0, 4);
    }

    public static function isFutureDate(string $date, string $today): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && $date > $today;
    }

    /**
     * @param array{
     *   type_active: bool, start: string, end: string, today: string, working_days: int,
     *   overlaps: bool, deducts: bool, has_balance: bool, remaining: float
     * } $f
     */
    public static function check(array $f): ?string
    {
        if (empty($f['type_active']) || !self::isFutureDate($f['start'], $f['today'])
            || !self::isFutureDate($f['end'], $f['today']) || $f['end'] < $f['start']) {
            return self::DATE;
        }
        // Each request is charged to the balance of the year it falls in, so it must not span two years.
        if (self::year($f['start']) !== self::year($f['end'])) return self::CROSS_YEAR;
        if ($f['working_days'] < 1) return self::NO_WORKING_DAYS;
        if (!empty($f['overlaps'])) return self::OVERLAP;
        if (empty($f['has_balance'])) return self::BALANCE;
        if (!empty($f['deducts']) && $f['remaining'] < $f['working_days']) return self::BALANCE;
        return null;
    }
}
