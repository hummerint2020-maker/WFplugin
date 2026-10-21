<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Decides whether a Sign In / Sign Out may be recorded. Pure: no WordPress calls.
 *
 * The order of the checks is part of the behaviour: when several apply, the employee
 * sees the one checked last among the first group, e.g. "already signed in" wins over
 * "face verification required".
 */
final class SignInRules
{
    public const FACE_REQUIRED      = 'face_required';
    public const NO_EMPLOYEE        = 'no_employee';
    public const GENERAL_LEAVE      = 'general_leave';
    public const NOT_WORKING_DAY    = 'not_working_day';
    public const TOO_EARLY          = 'too_early';
    public const TOO_LATE           = 'too_late';
    public const ALREADY_SIGNED_IN  = 'already_signed_in';
    public const ON_BREAK           = 'on_break';
    public const NOT_SIGNED_IN      = 'not_signed_in';
    public const ALREADY_SIGNED_OUT = 'already_signed_out';

    public const WINDOW_OPEN     = 'open';
    public const WINDOW_NOT_YET  = 'not_yet';
    public const WINDOW_CLOSED   = 'closed';

    /**
     * @param string $type 'sign_in' | 'sign_out'
     * @param array{
     *   employee: bool, face_required: bool, face_ok: bool, general_leave: bool,
     *   working_day: bool, window?: string, signed_in: bool, signed_out: bool, on_break?: bool
     * } $f
     * @return string|null One of the constants above, or null when the event may be recorded.
     */
    public static function check(string $type, array $f): ?string
    {
        $error = null;
        if ($type === 'sign_in' && !empty($f['face_required']) && empty($f['face_ok'])) {
            $error = self::FACE_REQUIRED;
        }
        if (empty($f['employee'])) {
            $error = self::NO_EMPLOYEE;
        } elseif (!empty($f['general_leave'])) {
            $error = self::GENERAL_LEAVE;
        } elseif (empty($f['working_day'])) {
            $error = self::NOT_WORKING_DAY;
        } elseif ($type === 'sign_in' && ($f['window'] ?? self::WINDOW_OPEN) !== self::WINDOW_OPEN) {
            $error = $f['window'] === self::WINDOW_NOT_YET ? self::TOO_EARLY : self::TOO_LATE;
        } elseif ($type === 'sign_in' && !empty($f['signed_in'])) {
            $error = self::ALREADY_SIGNED_IN;
        } elseif ($type === 'sign_out' && !empty($f['on_break'])) {
            $error = self::ON_BREAK;
        } elseif ($type === 'sign_out' && empty($f['signed_in'])) {
            $error = self::NOT_SIGNED_IN;
        } elseif ($type === 'sign_out' && !empty($f['signed_out'])) {
            $error = self::ALREADY_SIGNED_OUT;
        }
        return $error;
    }
}
