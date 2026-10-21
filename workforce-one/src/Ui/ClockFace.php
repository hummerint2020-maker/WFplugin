<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * The Sign In / Out page's picture: where the employee is in their day, and how far round the
 * ring around their avatar goes. Display only; the rules for signing in stay in Attendance\*.
 * Pure: no WordPress calls.
 */
final class ClockFace
{
    /**
     * 'out' (not signed in), 'in', 'break' (signed in, on a break), 'done' (signed out).
     * @param array<string, mixed> $events today's events by type (sign_in, late_sign_in, sign_out)
     */
    public static function state(array $events, bool $onBreak): string
    {
        $in = isset($events['sign_in']) || isset($events['late_sign_in']);
        if (!$in) return 'out';
        if (isset($events['sign_out'])) return 'done';
        return $onBreak ? 'break' : 'in';
    }

    /**
     * 0 … 1. Before Sign In: how much of the Sign In window has passed. Signed in: how much of
     * the shift has been worked since Sign In. Signed out: full.
     * @param array{start:int,cutoff:int,end:int} $bounds sign_in_window_bounds()
     */
    public static function progress(string $state, int $now, array $bounds, ?int $signedInAt): float
    {
        if ($state === 'done') return 1.0;
        $start = (int) ($bounds['start'] ?? 0);
        if ($state === 'out') {
            $cutoff = (int) ($bounds['cutoff'] ?? 0);
            return $start && $cutoff > $start ? self::clamp(($now - $start) / ($cutoff - $start)) : 0.0;
        }
        $end = (int) ($bounds['end'] ?? 0);
        if (!$signedInAt || !$start || $end <= $start) return 0.0;
        return self::clamp(($now - $signedInAt) / ($end - $start));
    }

    private static function clamp(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
