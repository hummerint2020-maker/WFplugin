<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Week lock (3.31.121, docs/tasks/week-lock.md): from a weekly cutoff the coming week's schedule is final and stays final,
 * so the current and past weeks are final too. Pure: no WordPress calls; dates are site wall-clock strings.
 */
final class WeekLock
{
    /**
     * The first day of the week $date is in, anchored on the first working day like week_dates_configured(): a day that is
     * not a working day belongs to the week of the working day before it.
     * @param int[] $workingDays weekdays (0 = Sunday), in the company's order
     */
    public static function weekStart(string $date, array $workingDays): string
    {
        $t = strtotime($date);
        if ($t === false) return '';
        $days = array_values(array_map('intval', $workingDays));
        if (!$days) return date('Y-m-d', $t);
        for ($back = 0; $back < 7 && !in_array((int) date('w', $t), $days, true); $back++) $t = strtotime('-1 day', $t);
        while ((int) date('w', $t) !== $days[0]) $t = strtotime('-1 day', $t);
        return date('Y-m-d', $t);
    }

    /**
     * When the week starting $weekStart becomes final: the last $cutoffDay strictly before it, at $cutoffTime
     * ("Y-m-d H:i:00"). A cutoff on the week's own first weekday is the one a week earlier.
     */
    public static function lockedFrom(string $weekStart, int $cutoffDay, string $cutoffTime): string
    {
        $t = strtotime($weekStart . ' 00:00:00');
        if ($t === false) return '';
        $t = strtotime('-1 day', $t);
        while ((int) date('w', $t) !== $cutoffDay) $t = strtotime('-1 day', $t);
        return date('Y-m-d', $t) . ' ' . $cutoffTime . ':00';
    }

    /** Is the week starting $weekStart final at $now? The cutoff instant itself counts as final. */
    public static function isLocked(string $weekStart, string $now, int $cutoffDay, string $cutoffTime): bool
    {
        $from = self::lockedFrom($weekStart, $cutoffDay, $cutoffTime);
        return $from !== '' && strcmp($now, $from) >= 0;
    }

    /**
     * The next week that becomes final after $now: its start and the cutoff instant. Weekdays are the company's working
     * days; the week after the current one, or the one after that when its cutoff has passed already.
     * @param int[] $workingDays
     * @return array{week:string,at:string}
     */
    public static function next(string $now, array $workingDays, int $cutoffDay, string $cutoffTime): array
    {
        $week = self::weekStart(substr($now, 0, 10), $workingDays);
        for ($i = 0; $i < 3; $i++) {
            $week = self::weekStart(date('Y-m-d', (int) strtotime($week . ' +7 days')), $workingDays);
            $at = self::lockedFrom($week, $cutoffDay, $cutoffTime);
            if (strcmp($now, $at) < 0) return ['week' => $week, 'at' => $at];
        }
        return ['week' => $week, 'at' => self::lockedFrom($week, $cutoffDay, $cutoffTime)];
    }

    /**
     * The most recent cutoff at or before $now: the week it made final. Used once per cutoff to tell employees.
     * @param int[] $workingDays
     * @return array{week:string,at:string}
     */
    public static function latest(string $now, array $workingDays, int $cutoffDay, string $cutoffTime): array
    {
        $n = self::next($now, $workingDays, $cutoffDay, $cutoffTime);
        $week = self::weekStart(date('Y-m-d', (int) strtotime($n['week'] . ' -7 days')), $workingDays);
        return ['week' => $week, 'at' => self::lockedFrom($week, $cutoffDay, $cutoffTime)];
    }
}
