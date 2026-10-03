<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * Which day the current shift belongs to. For an overnight shift (e.g. 22:00–06:00), after
 * midnight the shift still belongs to the day it started: Sign Out, breaks and the Sign In page use
 * that day. After the shift end, a shift that is still open (signed in, not signed out) also stays on
 * its day until the next shift starts, so a late Sign Out closes it. Pure.
 */
final class ShiftDay
{
    /**
     * @param string $today     site date, Y-m-d
     * @param string $nowHm     site time, H:i
     * @param bool $yesterdayOpen yesterday has a Sign In and no Sign Out
     */
    public static function resolve(string $today, string $nowHm, string $start, string $end, bool $overnight, bool $yesterdayOpen): string
    {
        if (!$overnight || $start <= $end) return $today;
        $yesterday = date('Y-m-d', strtotime($today . ' 12:00:00') - 86400);
        if ($nowHm <= $end) return $yesterday;
        if ($nowHm < $start && $yesterdayOpen) return $yesterday;
        return $today;
    }
}
