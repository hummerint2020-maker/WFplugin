<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/**
 * Substitutes while a manager is away (3.31.107, owner decisions 2026-10-17, docs/tasks/approvals.md,
 * mockup docs/mockups/substitutes/): the settings, the checks on a new substitute, and the state a
 * substitute is in at a given time. Pure: no WordPress calls.
 *
 * A substitute row: manager, substitute, starts_at, ends_at (local "Y-m-d H:i:s", the end excluded),
 * status: pending (its leave is not approved yet), scheduled, active, ended, cancelled.
 */
final class Substitutes
{
    public const STATUSES = ['pending', 'scheduled', 'active', 'ended', 'cancelled'];

    /** Who may be a substitute. */
    public const WHO = ['any', 'managers', 'department'];

    /** Whether a manager requesting leave is asked for one. */
    public const ASK = ['optional', 'required', 'no'];

    public const DEFAULTS = [
        'manager_decides' => 1,  // the manager can still decide while away (owner decision 1)
        'on_return' => 'back',   // back: what still waits goes back to the manager; stay: it stays with the substitute
        'who' => 'any',
        'ask_on_leave' => 'optional',
    ];

    /** @param mixed $saved @return array{manager_decides:int,on_return:string,who:string,ask_on_leave:string} */
    public static function settings($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        return [
            'manager_decides' => array_key_exists('manager_decides', $s) ? (empty($s['manager_decides']) ? 0 : 1) : self::DEFAULTS['manager_decides'],
            'on_return' => in_array($s['on_return'] ?? '', ['back', 'stay'], true) ? (string) $s['on_return'] : self::DEFAULTS['on_return'],
            'who' => in_array($s['who'] ?? '', self::WHO, true) ? (string) $s['who'] : self::DEFAULTS['who'],
            'ask_on_leave' => in_array($s['ask_on_leave'] ?? '', self::ASK, true) ? (string) $s['ask_on_leave'] : self::DEFAULTS['ask_on_leave'],
        ];
    }

    /** A "Y-m-d H:i" or "Y-m-d H:i:s" or "Y-m-dTH:i" value as "Y-m-d H:i:s", or '' when it is not one. */
    public static function dateTime($value): string
    {
        $v = str_replace('T', ' ', trim((string) $value));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $v, $m)) return '';
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) return '';
        return $v;
    }

    /** Leave days (dates, both included) as the period a substitute covers: from the first day's start to the day after the last. @return array{0:string,1:string} */
    public static function leavePeriod(string $start_date, string $end_date): array
    {
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $end_date);
        return [$start_date . ' 00:00:00', $end ? $end->modify('+1 day')->format('Y-m-d') . ' 00:00:00' : $end_date . ' 23:59:59'];
    }

    /**
     * Why a new substitute cannot be saved, or null.
     * @param array{manager:int,substitute:int,starts:string,ends:string,now:string,eligible:bool,away:bool,overlap:bool} $f
     *   eligible: the substitute fits the "who" setting; away: the substitute is away themselves then;
     *   overlap: the manager already has a substitute for part of these days.
     */
    public static function check(array $f): ?string
    {
        if ($f['manager'] <= 0) return 'manager';
        if ($f['substitute'] <= 0) return 'substitute';
        if ($f['manager'] === $f['substitute']) return 'same';
        if ($f['starts'] === '' || $f['ends'] === '' || $f['ends'] <= $f['starts']) return 'dates';
        if ($f['ends'] <= $f['now']) return 'past';
        if (!$f['eligible']) return 'who';
        if ($f['away']) return 'away';
        if ($f['overlap']) return 'overlap';
        return null;
    }

    /** Whether two periods (end excluded) share any time. */
    public static function overlaps(string $a_start, string $a_end, string $b_start, string $b_end): bool
    {
        return $a_start < $b_end && $b_start < $a_end;
    }

    /**
     * What a substitute row becomes at $now: 'start' (scheduled and its time came), 'end' (active and its
     * time is over), 'expire' (scheduled but already over: it never started), or '' (nothing to do).
     */
    public static function transition(string $status, string $starts, string $ends, string $now): string
    {
        if ($status === 'scheduled') {
            if ($ends <= $now) return 'expire';
            if ($starts <= $now) return 'start';
        }
        if ($status === 'active' && $ends <= $now) return 'end';
        return '';
    }

    /** What a substitute linked to a leave becomes when that leave's status is $leave_status: 'schedule', 'cancel' or ''. */
    public static function forLeave(string $status, string $leave_status): string
    {
        if (in_array($leave_status, ['Rejected', 'Cancelled'], true) && in_array($status, ['pending', 'scheduled', 'active'], true)) return 'cancel';
        if ($leave_status === 'Approved' && $status === 'pending') return 'schedule';
        return '';
    }

    /**
     * Whether a person may be a substitute under the "who" setting.
     * @param array{active:bool,has_reports:bool,same_department:bool} $c
     */
    public static function eligible(string $who, array $c): bool
    {
        if (!$c['active']) return false;
        if ($who === 'managers') return $c['has_reports'];
        if ($who === 'department') return $c['same_department'];
        return true;
    }
}
