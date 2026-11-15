<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * The Sign In / Out CSV in windows of whole days (3.31.149), so a long period is read a few
 * thousand records at a time instead of all at once. The CSV is sorted by day first, so the
 * windows one after the other give the same rows in the same order as the whole period.
 */
final class TimeCsvWindows
{
    /**
     * @param array<string,int> $dayCounts records per day (Y-m-d), ascending; days with none may be left out
     * @return array<int,array{0:string,1:string}> [from, to] windows that cover $start..$end exactly, in order,
     *         each at most $budget records unless a single day has more. One window [$start, $end] when it all fits.
     */
    public static function pack(array $dayCounts, string $start, string $end, int $budget): array
    {
        $budget = max(1, $budget);
        $windows = [];
        $from = $start;
        $sum = 0;
        $last = null;
        foreach ($dayCounts as $day => $n) {
            $day = (string) $day;
            $n = (int) $n;
            if ($day < $start || $day > $end || $n <= 0) continue;
            if ($sum > 0 && $sum + $n > $budget && $last !== null) {
                $windows[] = [$from, $last];
                $from = self::nextDay($last);
                $sum = 0;
            }
            $sum += $n;
            $last = $day;
        }
        $windows[] = [$from, $end];
        return $windows;
    }

    private static function nextDay(string $day): string
    {
        return gmdate('Y-m-d', (int) strtotime($day . ' 00:00:00 UTC') + 86400);
    }
}
