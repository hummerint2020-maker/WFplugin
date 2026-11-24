<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/**
 * Leave return (3.31.161, docs/tasks/leave-return.md, owner decisions 2026-11-24). Pure: no WordPress calls.
 * - checkBack(): may the employee say they are back at work on $back (any day of the leave from today on, the first day
 *   included, more than one change, again after a refusal or a withdrawal);
 * - effectiveFrom(): the first day returned once closed payroll months are kept as leave;
 * - daysBack(): the days that go back to the balance, from the leave days actually removed;
 * - carryTopUp(): what next year's carried days grow by when days go back to a year already carried;
 * - hrCheck(): HR records that the employee was back at work.
 */
final class ReturnRules
{
    public const NOT_APPROVED = 'not_approved';
    public const ALREADY_PENDING = 'already_pending';
    public const DAY = 'day';
    public const NO_DAYS = 'no_days';
    public const CLOSED_PAYROLL = 'closed_payroll';
    public const REASON = 'reason';
    public const SELF = 'self';
    public const ENDED = 'ended';

    /** The change states after which the employee may ask again (with Leave return on). */
    public const ASK_AGAIN = ['', 'not requested', 'approved', 'rejected', 'withdrawn', 'ended'];

    /**
     * The employee's change: back at work on $back, from max(start, today) to the last day; $back == start = all of it.
     * $daysFromBack: the leave's days (snapshots) on or after $back.
     */
    public static function checkBack(string $status, ?string $cancellation, string $start, string $end, string $today, string $back, int $daysFromBack): ?string
    {
        if ($status !== 'Approved') return self::NOT_APPROVED;
        $c = strtolower(trim((string) $cancellation));
        if ($c === 'pending') return self::ALREADY_PENDING;
        if (!in_array($c, self::ASK_AGAIN, true)) return self::NOT_APPROVED;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $back) || $start === '' || $end === '') return self::DAY;
        $min = $start > $today ? $start : $today;
        if ($back < $min || $back > $end) return self::DAY;
        if ($daysFromBack < 1) return self::NO_DAYS;
        return null;
    }

    /**
     * The days the employee may pick (My requests): min = the first day or today, max = the last day; whole = the first day can
     * still be picked (all of it). Null when nothing is left.
     * @return array{min:string,max:string,whole:bool}|null
     */
    public static function backRange(string $start, string $end, string $today): ?array
    {
        if ($start === '' || $end === '') return null;
        $min = $start > $today ? $start : $today;
        if ($min > $end) return null;
        return ['min' => $min, 'max' => $end, 'whole' => $today <= $start];
    }

    /**
     * The first day returned (owner: days in a closed payroll month stay leave): the first day >= $asked outside the closed
     * months, or null when none is left up to $end.
     * @param string[] $closedMonths 'Y-m'
     */
    public static function effectiveFrom(string $asked, string $end, array $closedMonths): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked)) return null;
        $closed = array_fill_keys(array_map('strval', $closedMonths), true);
        $day = $asked;
        for ($i = 0; $i < 600 && $day <= $end; $i++) {
            $month = substr($day, 0, 7);
            if (!isset($closed[$month])) return $day;
            $day = gmdate('Y-m-01', (int) strtotime($month . '-01 00:00:00 UTC +1 month'));
        }
        return null;
    }

    /**
     * Days back to the balance (owner: never twice): from the leave days actually removed.
     * - nothing left of the leave: what it still charges ($requestedDays), as a whole cancellation always did;
     * - else the days removed, never more than it charges;
     * - a leave without any day recorded (very old data): the working days counted, as before.
     */
    public static function daysBack(int $deleted, int $remainingAfter, float $requestedDays, bool $hasSnapshots, int $workingDaysFallback): float
    {
        if (!$hasSnapshots) return max(0.0, min($requestedDays, (float) $workingDaysFallback));
        if ($deleted < 1) return 0.0;
        if ($remainingAfter < 1) return max(0.0, $requestedDays);
        return max(0.0, min($requestedDays, (float) $deleted));
    }

    /**
     * What next year's carried days grow by when $back days return to a year whose next year was already carried: the carry
     * rule on the corrected figures, minus what was carried, never more than the days returned, never less than 0.
     * @param array{carry:string,carry_max:float} $config TypeRules::config()
     */
    public static function carryTopUp(array $config, float $entitlement, float $usedAfter, float $pending, float $storedCarried, float $back): float
    {
        if ($back <= 0) return 0.0;
        $now = TypeRules::carried($config + ['kind' => 'planned', 'after_days' => 0], $entitlement, $usedAfter, $pending);
        return round(max(0.0, min($back, $now - $storedCarried)), 2);
    }

    /**
     * HR records that the employee was back at work on $f['back'] (owner: a reason is required, not on HR's own leave, days
     * in a closed payroll month stay leave).
     * @param array{reason:string,self:bool,status:string,start:string,end:string,back:string,effective:?string} $f
     */
    public static function hrCheck(array $f): ?string
    {
        if (trim((string) $f['reason']) === '') return self::REASON;
        if (!empty($f['self'])) return self::SELF;
        if ((string) $f['status'] !== 'Approved') return self::NOT_APPROVED;
        $back = (string) $f['back'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $back) || $back < (string) $f['start'] || $back > (string) $f['end']) return self::DAY;
        if ($f['effective'] === null) return self::CLOSED_PAYROLL;
        return null;
    }

    /** English source text; translated where it is shown. */
    public static function message(string $code): string
    {
        switch ($code) {
            case self::NOT_APPROVED: return 'Only an approved leave can be changed.';
            case self::ALREADY_PENDING: return 'A change of this leave is already waiting.';
            case self::DAY: return 'Pick the day you are back at work: from the first day of the leave (or today) up to its last day.';
            case self::NO_DAYS: return 'No leave day is left from that day on.';
            case self::CLOSED_PAYROLL: return 'The payroll of those days is closed, so they stay leave.';
            case self::REASON: return 'Write the reason.';
            case self::SELF: return 'You cannot record a return on your own leave. Another HR person must.';
            case self::ENDED: return 'No leave day is left to return.';
        }
        return 'The leave could not be changed.';
    }
}
