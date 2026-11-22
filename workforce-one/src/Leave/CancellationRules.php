<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/** Whether an employee may ask to cancel an approved leave. Pure: no WordPress calls. */
final class CancellationRules
{
    public const NOT_APPROVED = 'not_approved';
    public const ALREADY_PENDING = 'already_pending';
    public const ALREADY_REJECTED = 'already_rejected';
    public const ALREADY_CANCELLED = 'already_cancelled';
    public const STARTED = 'started';
    /** A cancellation withdrawn or closed once the leave had ended (3.31.158): a leave has one cancellation. */
    public const CLOSED = 'closed';

    /** "Change the leave" (3.31.158): the day back at work is not after the first day, before today, or after the last. */
    public const DAY = 'day';

    /**
     * "Change the leave" (3.31.158, owner): $back null = cancel all of it (check()); else the day the employee is back at work:
     * after the first day, today or later, up to the last day. A leave has one change.
     * @return string|null null when it may be asked
     */
    public static function checkChange(string $status, ?string $cancellationStatus, ?string $startDate, ?string $endDate, string $today, ?string $back): ?string
    {
        if ($back === null) return self::check($status, $cancellationStatus, $startDate, $today);
        if ($status !== 'Approved') return self::NOT_APPROVED;
        $c = strtolower(trim((string) $cancellationStatus));
        if ($c === 'pending') return self::ALREADY_PENDING;
        if ($c === 'rejected') return self::ALREADY_REJECTED;
        if ($c === 'approved') return self::ALREADY_CANCELLED;
        if ($c === 'withdrawn' || $c === 'ended') return self::CLOSED;
        if (empty($startDate) || empty($endDate) || $back <= $startDate || $back > $endDate || $back < $today) return self::DAY;
        return null;
    }

    /** The first day the employee may say they are back (the day after the start, or today), or null when none is left. */
    public static function firstBackDay(?string $startDate, ?string $endDate, string $today): ?string
    {
        if (empty($startDate) || empty($endDate)) return null;
        $next = gmdate('Y-m-d', (int) strtotime($startDate . ' +1 day'));
        $first = $next > $today ? $next : $today;
        return $first <= $endDate ? $first : null;
    }

    /** @return string|null null when a cancellation may be requested. */
    public static function check(string $status, ?string $cancellationStatus, ?string $startDate, string $today): ?string
    {
        if ($status !== 'Approved') return self::NOT_APPROVED;
        $c = strtolower(trim((string) $cancellationStatus));
        if ($c === 'pending') return self::ALREADY_PENDING;
        if ($c === 'rejected') return self::ALREADY_REJECTED;
        if ($c === 'approved') return self::ALREADY_CANCELLED;
        if ($c === 'withdrawn' || $c === 'ended') return self::CLOSED;
        if (empty($startDate) || $today >= $startDate) return self::STARTED;
        return null;
    }
}
