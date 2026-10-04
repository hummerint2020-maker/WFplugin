<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Whether a break may start or end. Pure: no WordPress calls.
 *
 * The order of the checks is part of the behaviour (it decides which message the employee sees
 * when several apply) and is the order of the checks in break_start() / break_resume() up to 3.31.45.
 */
final class BreakRules
{
    public const BREAKS_DISABLED     = 'breaks_disabled';
    public const ATTENDANCE_DISABLED = 'attendance_disabled';
    public const NO_EMPLOYEE         = 'no_employee';
    public const NOT_WORKING_DAY     = 'not_working_day';
    public const NOT_SIGNED_IN       = 'not_signed_in';
    public const ALREADY_SIGNED_OUT  = 'already_signed_out';
    public const ALREADY_ON_BREAK    = 'already_on_break';
    public const NO_BREAKS_LEFT      = 'no_breaks_left';
    public const NO_OPEN_BREAK       = 'no_open_break';
    public const SAVE_FAILED         = 'break_save_failed';

    /**
     * @param array{enabled:bool, employee:bool, attendance_enabled?:bool, working_day?:bool, signed_in?:bool,
     *   signed_out?:bool, on_break?:bool, remaining?:int} $f
     */
    public static function checkStart(array $f): ?string
    {
        if (empty($f['enabled'])) return self::BREAKS_DISABLED;
        if (!empty($f['employee']) && empty($f['attendance_enabled'])) return self::ATTENDANCE_DISABLED;
        if (empty($f['employee'])) return self::NO_EMPLOYEE;
        if (empty($f['working_day'])) return self::NOT_WORKING_DAY;
        if (empty($f['signed_in'])) return self::NOT_SIGNED_IN;
        if (!empty($f['signed_out'])) return self::ALREADY_SIGNED_OUT;
        if (!empty($f['on_break'])) return self::ALREADY_ON_BREAK;
        if ((int) ($f['remaining'] ?? 0) <= 0) return self::NO_BREAKS_LEFT;
        return null;
    }

    /** @param array{enabled:bool, employee:bool, attendance_enabled?:bool, open_break?:bool} $f */
    public static function checkResume(array $f): ?string
    {
        if (empty($f['enabled'])) return self::BREAKS_DISABLED;
        if (!empty($f['employee']) && empty($f['attendance_enabled'])) return self::ATTENDANCE_DISABLED;
        if (empty($f['employee'])) return self::NO_EMPLOYEE;
        if (empty($f['open_break'])) return self::NO_OPEN_BREAK;
        return null;
    }

    /** Whole minutes of a break, never negative. */
    public static function minutes(int $startTs, int $endTs): int
    {
        return max(0, (int) floor(($endTs - $startTs) / 60));
    }
}
