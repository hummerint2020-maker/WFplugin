<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Where a shift swap stands, for My approvals, My requests and the Schedule page's counts (3.31.124,
 * docs/tasks/swap-inbox.md), so the three always agree. Pure.
 */
final class SwapInbox
{
    /** The My requests state: waiting | approved | rejected | withdrawn | expired | cancelled. */
    public static function state(string $status, string $workDate, string $today): string
    {
        switch ($status) {
            case 'Pending': return self::dayPassed($workDate, $today) ? 'expired' : 'waiting';
            case 'Awaiting': return 'waiting';
            case 'Accepted': case 'Approved': return 'approved';
            case 'Rejected': case 'Declined': return 'rejected';
            case 'Cancelled': return 'withdrawn';
            case 'Expired': return 'expired';
        }
        return 'cancelled';
    }

    /** It still waits for the colleague's answer (Pending, and its day has not passed). */
    public static function waitsForPeer(string $status, string $workDate, string $today): bool
    {
        return $status === 'Pending' && !self::dayPassed($workDate, $today);
    }

    /** The requester may withdraw it: only while it waits for the colleague (owner decision 10). */
    public static function canWithdraw(string $status, string $workDate, string $today): bool
    {
        return self::waitsForPeer($status, $workDate, $today);
    }

    private static function dayPassed(string $workDate, string $today): bool
    {
        return $workDate !== '' && strcmp($workDate, $today) < 0;
    }
}
