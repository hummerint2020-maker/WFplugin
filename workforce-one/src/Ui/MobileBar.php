<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * The phone's bottom bar, built from the menu items View Navigation shows on mobile (already
 * filtered to what the user may open, in mobile order): Sign In / Out in the middle as the big
 * button when it is shown, the first items around it, and everything else under "More".
 * Pure: no WordPress calls.
 */
final class MobileBar
{
    /**
     * @param list<array<string, mixed>> $items each with at least 'key'
     * @return array{start: list<array<string, mixed>>, center: array<string, mixed>|null, end: list<array<string, mixed>>, more: list<array<string, mixed>>}
     */
    public static function split(array $items): array
    {
        $center = null;
        $rest = [];
        foreach ($items as $item) {
            if ($center === null && ($item['key'] ?? '') === 'time') {
                $center = $item;
                continue;
            }
            $rest[] = $item;
        }
        // Five places: with the big middle button, two items before it and one after it, then More;
        // without it, four items, then More.
        $slots = $center ? 3 : 4;
        $bar = array_slice($rest, 0, $slots);
        $more = array_slice($rest, $slots);
        $start = array_slice($bar, 0, 2);
        $end = array_slice($bar, 2);
        return ['start' => $start, 'center' => $center, 'end' => $end, 'more' => $more];
    }

    /**
     * The middle button's dot: 'in' while signed in, 'out' before signing in, 'done' after
     * signing out, '' when there is nothing to show.
     * @param array<string, mixed> $events today's events by type (sign_in, late_sign_in, sign_out)
     */
    public static function clockState(array $events, bool $isEmployee): string
    {
        if (!$isEmployee) return '';
        $in = isset($events['sign_in']) || isset($events['late_sign_in']);
        if ($in && isset($events['sign_out'])) return 'done';
        return $in ? 'in' : 'out';
    }
}
