<?php
namespace WorkforceOne\Organization;

if (!defined('ABSPATH')) exit;

/**
 * Moving an employee under another manager (3.31.98, owner-approved mockup docs/mockups/org-chart/):
 * a new manager (or none), from a date, and a reason kept in the Audit Log. The people under them move
 * with them (their manager does not change); requests waiting for the old manager go to the new one.
 * A date after today is kept as a scheduled move and done on that day. The caller gathers the facts;
 * this class decides. Pure: no WordPress calls. Error codes are the ?org_error= values (their messages:
 * EWS_Org_Moves_Trait::org_error_messages()).
 */
final class MoveRules
{
    /** How far back a move may be dated (it is done at once, the date is kept). */
    public const MAX_PAST_DAYS = 365;
    /** How far ahead a move may be scheduled. */
    public const MAX_FUTURE_DAYS = 366;
    public const REASON_MAX = 255;

    /**
     * @param array{
     *   employee_ok: bool, employee_id: int, manager_id: int, manager_ok?: bool, current_manager: int,
     *   would_loop?: bool, date: string, today: string, reason: string, reason_required?: bool, scheduled_exists?: bool
     * } $f
     */
    public static function check(array $f): ?string
    {
        if (empty($f['employee_ok'])) return 'employee';
        $to = (int) $f['manager_id'];
        if ($to && $to === (int) $f['employee_id']) return 'self';
        if ($to && array_key_exists('manager_ok', $f) && !$f['manager_ok']) return 'manager';
        if (!self::validDate($f['date'])) return 'date';
        $days = self::daysBetween($f['today'], $f['date']);
        if ($days < -self::MAX_PAST_DAYS || $days > self::MAX_FUTURE_DAYS) return 'date';
        if (!empty($f['reason_required']) && trim($f['reason']) === '') return 'reason';
        if (!empty($f['scheduled_exists'])) return 'scheduled';
        if ($to === (int) $f['current_manager'] && $days <= 0) return 'same';
        if (!empty($f['would_loop'])) return 'loop';
        return null;
    }

    /** A move dated after today waits for its day. */
    public static function isScheduled(string $date, string $today): bool
    {
        return self::validDate($date) && self::validDate($today) && $date > $today;
    }

    public static function reason($v): string
    {
        $v = is_scalar($v) ? trim(preg_replace('/\s+/', ' ', strip_tags((string) $v))) : '';
        return function_exists('mb_substr') ? mb_substr($v, 0, self::REASON_MAX) : substr($v, 0, self::REASON_MAX);
    }

    public static function validDate($d): bool
    {
        if (!is_string($d) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Whole days from $a to $b (negative when $b is earlier). */
    public static function daysBetween(string $a, string $b): int
    {
        return (int) round((strtotime($b . ' 00:00:00 UTC') - strtotime($a . ' 00:00:00 UTC')) / 86400);
    }
}
