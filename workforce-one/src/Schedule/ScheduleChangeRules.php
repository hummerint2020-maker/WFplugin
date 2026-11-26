<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Schedule change request rules (3.31.165, docs/tasks/schedule-change-request.md): which day a manager may ask HR to change,
 * and when a waiting request closes by itself. Pure PHP.
 */
final class ScheduleChangeRules
{
    /** Why a day is not sent, in the order they are checked. */
    public const CODES = ['scope', 'not_locked', 'status', 'leave_status', 'same', 'holiday', 'past', 'closed_month', 'leave_day', 'attendance', 'pending', 'branch'];

    /**
     * May this day be asked for?
     * @param array<string,mixed> $f final (the week is final for the requester), scope (the requester manages the person),
     *        status (asked), rule (the type's attendance rule; null: not an active type), old (the day's status now, null: none),
     *        branch_change (a branch was asked that differs), holiday, date, today, closed (its payroll month is closed),
     *        leave (a pending or approved leave covers it), attendance (attendance is recorded that day), pending (already asked),
     *        branch_ok (the branch is one of the person's)
     * @param array<string,mixed> $cfg ScheduleChangeSettings::config()
     * @return string '' or one of CODES
     */
    public static function check(array $f, array $cfg): string
    {
        if (empty($f['scope'])) return 'scope';
        if (empty($f['final'])) return 'not_locked';
        $status = (string) ($f['status'] ?? '');
        $rule = $f['rule'] ?? null;
        if ($rule === null || $status === '') return 'status';
        if ($rule === 'leave') {
            if (empty($cfg['allow_leave'])) return 'leave_status';
        } elseif (!empty($cfg['statuses']) && !in_array($status, (array) $cfg['statuses'], true)) {
            return 'status';
        }
        $old = $f['old'] ?? null;
        if ($old !== null && trim((string) $old) === $status && empty($f['branch_change'])) return 'same';
        if (!empty($f['holiday'])) return 'holiday';
        $date = (string) ($f['date'] ?? '');
        $today = (string) ($f['today'] ?? '');
        if ($date < self::addDays($today, -(int) ($cfg['past_days'] ?? 14))) return 'past';
        if (!empty($f['closed'])) return 'closed_month';
        if (!empty($f['leave'])) return 'leave_day';
        if ($rule === 'leave' && !empty($f['attendance'])) return 'attendance';
        if (!empty($f['pending'])) return 'pending';
        if (array_key_exists('branch_ok', $f) && !$f['branch_ok']) return 'branch';
        return '';
    }

    /** The day's key while its request is open: one open request per person and day (the UNIQUE open_key). */
    public static function openKey(int $employeeId, string $date): string
    {
        return $employeeId . '|' . $date;
    }

    /** A waiting request older than $days (0: never). */
    public static function expired(string $requestedAt, string $now, int $days): bool
    {
        if ($days <= 0) return false;
        $from = strtotime($requestedAt);
        $to = strtotime($now);
        return $from !== false && $to !== false && $to - $from >= $days * 86400;
    }

    /** The header's status once decided: every day applied (or already so), or some skipped. */
    public static function finalStatus(int $days, int $skipped): string
    {
        return $skipped > 0 && $skipped <= $days ? 'partial' : 'approved';
    }

    /** Y-m-d plus (or minus) whole days. */
    public static function addDays(string $date, int $days): string
    {
        $t = strtotime($date . ' 12:00:00');
        return $t === false ? $date : gmdate('Y-m-d', $t + $days * 86400);
    }
}
