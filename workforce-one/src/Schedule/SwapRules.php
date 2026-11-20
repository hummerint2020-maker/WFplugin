<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Validates a Schedule Swap and its acceptance. Pure: no WordPress calls.
 * Error codes are the ?swap_error= values shown by the Schedule page.
 */
final class SwapRules
{
    public const INVALID_REQUEST = 'invalid_request';
    public const NOT_SWAPPABLE = 'not_swappable';
    public const CHANGED = 'changed';
    public const PAST_DATE = 'past_date';
    public const PENDING = 'pending';
    /** 3.31.156: only with a colleague of one's own department, administrators included (owner, 2026-11-20). */
    public const DEPARTMENT = 'department';
    /** 3.31.156: one commitment per person and day (owner, 2026-11-20): asking, and accepting. */
    public const ONE_PER_DAY = 'one_per_day';
    public const ONE_PER_DAY_ACCEPT = 'one_per_day_accept';

    /** Only these day types can be exchanged between two colleagues. */
    public const SWAPPABLE = ['Office', 'WFH'];

    /** The form fields themselves: a colleague other than the requester and a Y-m-d date. */
    public static function checkRequest(int $requesterId, int $targetId, string $date): ?string
    {
        if (!$targetId || $targetId === $requesterId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::INVALID_REQUEST;
        return null;
    }

    /** A day that has already passed cannot be swapped (checked again when the swap is accepted). */
    public static function checkNotPast(string $date, string $today): ?string
    {
        return $date < $today ? self::PAST_DATE : null;
    }

    /** Both days must exist, be Office/WFH, and differ (otherwise the swap changes nothing). */
    public static function checkSwappable(?string $requesterStatus, ?string $targetStatus): ?string
    {
        if ($requesterStatus === null || $targetStatus === null) return self::NOT_SWAPPABLE;
        if (!in_array($requesterStatus, self::SWAPPABLE, true) || !in_array($targetStatus, self::SWAPPABLE, true)) return self::NOT_SWAPPABLE;
        if ($requesterStatus === $targetStatus) return self::NOT_SWAPPABLE;
        return null;
    }

    /**
     * A swap may only be applied while both schedules still hold the statuses captured when it
     * was requested; otherwise someone edited the schedule in between.
     */
    public static function checkStillCurrent(string $requestedRequester, string $requestedTarget, ?string $nowRequester, ?string $nowTarget): ?string
    {
        if ($nowRequester !== $requestedRequester || $nowTarget !== $requestedTarget) return self::CHANGED;
        return null;
    }

    /**
     * Both employees must be in the same department, whoever asks (3.31.156, SW-a): an administrator who is also an employee swaps
     * like any employee. An employee without a department swaps with nobody.
     */
    public static function checkSameDepartment(int $requesterDepartment, int $targetDepartment): ?string
    {
        return $requesterDepartment > 0 && $requesterDepartment === $targetDepartment ? null : self::DEPARTMENT;
    }

    /**
     * Whether a swap counts as this person's commitment for its day (3.31.156, owner 2026-11-20): one they asked for that is still
     * open (Pending or Awaiting), or one they were asked in and accepted that waits for the approvers (Awaiting). One they were asked
     * in and have not answered is not.
     */
    public static function isCommitment(int $me, int $requester, int $target, string $status): bool
    {
        if ($requester === $me) return in_array($status, ['Pending', 'Awaiting'], true);
        return $target === $me && $status === 'Awaiting';
    }

    /**
     * May $me ask $target for a swap on a day? $open: that day's open swaps (Pending or Awaiting) that involve $me, as
     * [requester, target, status] (3.31.156, SW-b). The same pair in either direction is 'pending' (as before); any other commitment
     * of $me that day is 'one_per_day'.
     * @param list<array{0:int,1:int,2:string}> $open
     */
    public static function checkCanAsk(array $open, int $me, int $target): ?string
    {
        foreach ($open as [$a, $b]) {
            if (((int) $a === $me && (int) $b === $target) || ((int) $a === $target && (int) $b === $me)) return self::PENDING;
        }
        foreach ($open as [$a, $b, $status]) {
            if (self::isCommitment($me, (int) $a, (int) $b, (string) $status)) return self::ONE_PER_DAY;
        }
        return null;
    }

    /**
     * May $me accept a swap asked of them? $open: that day's other open swaps (this one left out) that involve $me, as
     * [requester, target, status]. Accepting is refused while $me has another commitment that day; declining is always allowed.
     * @param list<array{0:int,1:int,2:string}> $open
     */
    public static function checkCanAccept(array $open, int $me): ?string
    {
        foreach ($open as [$a, $b, $status]) {
            if (self::isCommitment($me, (int) $a, (int) $b, (string) $status)) return self::ONE_PER_DAY_ACCEPT;
        }
        return null;
    }
}
