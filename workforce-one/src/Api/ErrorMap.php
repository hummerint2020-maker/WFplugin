<?php
namespace WorkforceOne\Api;

use WorkforceOne\Attendance\AttendanceResult;
use WorkforceOne\Attendance\BreakRules;
use WorkforceOne\Attendance\LocationAssessment;
use WorkforceOne\Attendance\SignInRules;

if (!defined('ABSPATH')) exit;

/**
 * Domain result codes → stable API error codes and HTTP statuses. Pure.
 *
 * Within v1 a code keeps its meaning forever; new codes may be added (clients treat an unknown
 * code as a generic failure and show the message). The HTTP status gives the class of failure,
 * the code the reason.
 */
final class ErrorMap
{
    public const ACCOUNT_NOT_LINKED = 'ACCOUNT_NOT_LINKED';
    public const ATTENDANCE_DISABLED = 'ATTENDANCE_DISABLED';
    public const FACE_REQUIRED = 'FACE_REQUIRED';
    public const GENERAL_LEAVE = 'GENERAL_LEAVE';
    public const NOT_SCHEDULED = 'NOT_SCHEDULED';
    public const SIGN_IN_TOO_EARLY = 'SIGN_IN_TOO_EARLY';
    public const SIGN_IN_CLOSED = 'SIGN_IN_CLOSED';
    public const ALREADY_SIGNED_IN = 'ALREADY_SIGNED_IN';
    public const ALREADY_SIGNED_OUT = 'ALREADY_SIGNED_OUT';
    public const NOT_SIGNED_IN = 'NOT_SIGNED_IN';
    public const ON_BREAK = 'ON_BREAK';
    public const LOCATION_REQUIRED = 'LOCATION_REQUIRED';
    public const OUTSIDE_LOCATION = 'OUTSIDE_LOCATION';
    public const LOCATION_NOT_CONFIGURED = 'LOCATION_NOT_CONFIGURED';
    public const BREAKS_DISABLED = 'BREAKS_DISABLED';
    public const BREAK_ALREADY_OPEN = 'BREAK_ALREADY_OPEN';
    public const NO_BREAKS_LEFT = 'NO_BREAKS_LEFT';
    public const NO_OPEN_BREAK = 'NO_OPEN_BREAK';
    public const IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    public const REQUEST_IN_PROGRESS = 'REQUEST_IN_PROGRESS';
    public const RATE_LIMITED = 'RATE_LIMITED';
    public const VALIDATION_FAILED = 'VALIDATION_FAILED';
    public const NOT_FOUND = 'NOT_FOUND';
    public const FORBIDDEN = 'FORBIDDEN';
    public const INTERNAL_ERROR = 'INTERNAL_ERROR';

    /**
     * Domain code → API code. BreakRules reuses the SignInRules / AttendanceResult strings for the same
     * reasons (no_employee, attendance_disabled, not_working_day, not_signed_in, already_signed_out).
     */
    private const CODES = [
        SignInRules::NO_EMPLOYEE => self::ACCOUNT_NOT_LINKED,
        SignInRules::FACE_REQUIRED => self::FACE_REQUIRED,
        SignInRules::GENERAL_LEAVE => self::GENERAL_LEAVE,
        SignInRules::NOT_WORKING_DAY => self::NOT_SCHEDULED,
        SignInRules::TOO_EARLY => self::SIGN_IN_TOO_EARLY,
        SignInRules::TOO_LATE => self::SIGN_IN_CLOSED,
        SignInRules::ALREADY_SIGNED_IN => self::ALREADY_SIGNED_IN,
        SignInRules::ON_BREAK => self::ON_BREAK,
        SignInRules::NOT_SIGNED_IN => self::NOT_SIGNED_IN,
        SignInRules::ALREADY_SIGNED_OUT => self::ALREADY_SIGNED_OUT,
        AttendanceResult::ATTENDANCE_DISABLED => self::ATTENDANCE_DISABLED,
        AttendanceResult::OUTSIDE_LOCATION => self::OUTSIDE_LOCATION,
        AttendanceResult::SAVE_FAILED => self::INTERNAL_ERROR,
        AttendanceResult::IDEMPOTENCY_CONFLICT => self::IDEMPOTENCY_CONFLICT,
        AttendanceResult::IN_PROGRESS => self::REQUEST_IN_PROGRESS,
        LocationAssessment::QR_LOCATION_REQUIRED => self::LOCATION_REQUIRED,
        LocationAssessment::QR_OUTSIDE => self::OUTSIDE_LOCATION,
        LocationAssessment::QR_KIOSK_NO_COORDS => self::LOCATION_NOT_CONFIGURED,
        BreakRules::BREAKS_DISABLED => self::BREAKS_DISABLED,
        BreakRules::ALREADY_ON_BREAK => self::BREAK_ALREADY_OPEN,
        BreakRules::NO_BREAKS_LEFT => self::NO_BREAKS_LEFT,
        BreakRules::NO_OPEN_BREAK => self::NO_OPEN_BREAK,
        BreakRules::SAVE_FAILED => self::INTERNAL_ERROR,
        'rate_limited' => self::RATE_LIMITED,
    ];

    private const STATUS = [
        self::ACCOUNT_NOT_LINKED => 403, self::ATTENDANCE_DISABLED => 403, self::FORBIDDEN => 403, self::BREAKS_DISABLED => 403,
        self::FACE_REQUIRED => 409, self::GENERAL_LEAVE => 409, self::NOT_SCHEDULED => 409, self::SIGN_IN_TOO_EARLY => 409,
        self::SIGN_IN_CLOSED => 409, self::ALREADY_SIGNED_IN => 409, self::ALREADY_SIGNED_OUT => 409, self::NOT_SIGNED_IN => 409,
        self::ON_BREAK => 409, self::LOCATION_NOT_CONFIGURED => 409, self::BREAK_ALREADY_OPEN => 409, self::NO_BREAKS_LEFT => 409,
        self::NO_OPEN_BREAK => 409, self::IDEMPOTENCY_CONFLICT => 409, self::REQUEST_IN_PROGRESS => 409,
        self::LOCATION_REQUIRED => 422, self::OUTSIDE_LOCATION => 422,
        self::VALIDATION_FAILED => 400, self::NOT_FOUND => 404, self::RATE_LIMITED => 429, self::INTERNAL_ERROR => 500,
    ];

    /** The API code for a domain code; unknown codes are INTERNAL_ERROR (never the raw internal code). */
    public static function code(string $domainCode): string
    {
        return self::CODES[$domainCode] ?? self::INTERNAL_ERROR;
    }

    public static function status(string $apiCode): int
    {
        return self::STATUS[$apiCode] ?? 500;
    }

    /** @return array<string,string> every mapped domain code (for tests and documentation) */
    public static function all(): array
    {
        return self::CODES;
    }
}
