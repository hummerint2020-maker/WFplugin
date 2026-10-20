<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * Site-local times as ISO 8601 with the site's UTC offset (3.31.113). Pure: the time zone is passed in, never read from the PHP
 * default. The plugin reasons in local wall-clock time (as the Web does); conversion happens only when something is sent out.
 * A time that does not exist (the hour skipped when clocks go forward) moves forward by the gap, and one that exists twice
 * (the hour repeated when clocks go back) takes the first offset: PHP's rule, kept on purpose.
 */
final class LocalTime
{
    /** "2026-10-19 22:00:00" (or without seconds) in the zone, as "2026-10-19T22:00:00+03:00". */
    public static function iso(string $local, \DateTimeZone $tz): string
    {
        $local = trim($local);
        if (strlen($local) === 16) $local .= ':00';
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, $tz);
        return $d ? $d->format('c') : '';
    }

    /** current_time('timestamp') style: a Unix-like number whose UTC fields are the site's wall clock. */
    public static function isoFromWall(int $wallTs, \DateTimeZone $tz): string
    {
        return self::iso(gmdate('Y-m-d H:i:s', $wallTs), $tz);
    }

    /**
     * A shift's start and end on a date. The end is on the next day for an overnight shift whose end is not after its start
     * (as sign_in_window_bounds()); a day shift ends the same day.
     * @return array{starts_at:string,ends_at:string}
     */
    public static function shiftSpan(string $date, string $start, string $end, bool $overnight, \DateTimeZone $tz): array
    {
        $endDate = ($overnight && $end <= $start) ? Period::addDays($date, 1) : $date;
        return ['starts_at' => self::iso($date . ' ' . $start . ':00', $tz), 'ends_at' => self::iso($endDate . ' ' . $end . ':00', $tz)];
    }
}
