<?php
namespace WorkforceOne\EarlyLeave;

if (!defined('ABSPATH')) exit;

/**
 * Validates an Early Leave request and its approval. Pure: no WordPress calls.
 * Error codes are the ?early_error= values shown by the Leave page.
 */
final class RequestRules
{
    public const FUTURE_DATE = 'future_date';
    public const WORKING_DAY = 'working_day';
    public const OFFICE_ONLY = 'office_only';
    public const MAX_DURATION = 'max_duration';
    public const MONTHLY_LIMIT = 'monthly_limit';

    /**
     * @param array{
     *   date: string, today: string, working_day: bool, office_only: bool, schedule_status: ?string,
     *   minutes: int, max_minutes: int, used_this_month?: int, monthly_minutes: int
     * } $f  used_this_month is only needed once the earlier checks pass.
     */
    public static function check(array $f): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['date']) || $f['date'] <= $f['today']) return self::FUTURE_DATE;
        if (empty($f['working_day'])) return self::WORKING_DAY;
        if (!self::scheduleAllows($f['office_only'], $f['schedule_status'])) return self::OFFICE_ONLY;
        if ($f['minutes'] > $f['max_minutes']) return self::MAX_DURATION;
        if (isset($f['used_this_month']) && $f['used_this_month'] + $f['minutes'] > $f['monthly_minutes']) return self::MONTHLY_LIMIT;
        return null;
    }

    /** With "Office only" on, the day must still be scheduled as Office (checked again at approval). */
    public static function scheduleAllows(bool $officeOnly, ?string $scheduleStatus): bool
    {
        return !$officeOnly || $scheduleStatus === 'Office';
    }
}
