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

    /** @return string|null null when a cancellation may be requested. */
    public static function check(string $status, ?string $cancellationStatus, ?string $startDate, string $today): ?string
    {
        if ($status !== 'Approved') return self::NOT_APPROVED;
        $c = strtolower(trim((string) $cancellationStatus));
        if ($c === 'pending') return self::ALREADY_PENDING;
        if ($c === 'rejected') return self::ALREADY_REJECTED;
        if ($c === 'approved') return self::ALREADY_CANCELLED;
        if (empty($startDate) || $today >= $startDate) return self::STARTED;
        return null;
    }
}
