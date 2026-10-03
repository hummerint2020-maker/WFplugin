<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Validates leave recorded directly by an administrator (wp-admin → Leaves → Record Leave).
 * Unlike an employee request, past dates are allowed. Pure: no WordPress calls.
 */
final class AdminRecordRules
{
    public const DATES = 'dates';
    public const CROSS_YEAR = 'cross_year';
    public const EMPLOYEE = 'employee';
    public const TYPE = 'type';
    public const NO_WORKING_DAYS = 'no_working_days';
    public const OVERLAP = 'overlap';
    public const BALANCE = 'balance';

    /**
     * @param array{start_valid: bool, end_valid: bool, start: string, end: string, employee_found?: bool,
     *   type_found?: bool, working_days?: int, overlaps?: bool, deducts?: bool, remaining?: float} $f
     *   Facts after the date checks are only needed once the earlier checks pass.
     */
    public static function check(array $f): ?string
    {
        if (empty($f['start_valid']) || empty($f['end_valid']) || $f['end'] < $f['start']) return self::DATES;
        if (RequestRules::year($f['start']) !== RequestRules::year($f['end'])) return self::CROSS_YEAR;
        if (array_key_exists('employee_found', $f) && !$f['employee_found']) return self::EMPLOYEE;
        if (array_key_exists('type_found', $f) && !$f['type_found']) return self::TYPE;
        if (array_key_exists('working_days', $f) && $f['working_days'] < 1) return self::NO_WORKING_DAYS;
        if (!empty($f['overlaps'])) return self::OVERLAP;
        if (!empty($f['deducts']) && isset($f['remaining'], $f['working_days']) && $f['remaining'] < $f['working_days']) return self::BALANCE;
        return null;
    }

    /** Message shown on the Leaves page for an error code. */
    public static function message(string $code, float $remaining = 0): string
    {
        switch ($code) {
            case self::DATES: return 'Please select a valid employee and date range.';
            case self::CROSS_YEAR: return 'A single leave record cannot cross calendar years. Record each year separately so leave balances remain accurate.';
            case self::EMPLOYEE: return 'Employee not found.';
            case self::TYPE: return 'Leave type not found or inactive.';
            case self::NO_WORKING_DAYS: return 'The selected range contains no configured working days.';
            case self::OVERLAP: return 'This employee already has a pending or approved leave overlapping the selected dates.';
            case self::BALANCE: return sprintf('Not enough leave balance: %s day(s) left for that year. Raise the annual balance first if this leave should still be recorded.', rtrim(rtrim(number_format($remaining, 1, '.', ''), '0'), '.'));
        }
        return 'The leave could not be recorded.';
    }

    /** Years offered by the "Assign Annual Balance" form: last year, this year and next year. */
    public static function balanceYears(int $currentYear): array
    {
        return [$currentYear - 1, $currentYear, $currentYear + 1];
    }
}
