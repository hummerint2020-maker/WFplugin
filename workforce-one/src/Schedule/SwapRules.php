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

    /** Only these day types can be exchanged between two colleagues. */
    public const SWAPPABLE = ['Office', 'WFH'];

    /** The form fields themselves: a colleague other than the requester and a Y-m-d date. */
    public static function checkRequest(int $requesterId, int $targetId, string $date): ?string
    {
        if (!$targetId || $targetId === $requesterId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return self::INVALID_REQUEST;
        return null;
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
}
