<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/** Smart Nudges settings. Pure: no WordPress calls. */
final class SmartNudges
{
    public const ITEMS = ['attendance', 'tasks', 'leave', 'schedule'];
    public const DEFAULTS = ['enabled' => 1, 'items' => ['attendance' => 1, 'tasks' => 1, 'leave' => 1, 'schedule' => 1],
        'attendance_after' => 15, 'attendance_repeat' => 0, 'attendance_repeat_interval' => 15, 'attendance_max_reminders' => 3];

    /** @param mixed $saved stored option (missing keys take the defaults) */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = self::DEFAULTS;
        if (array_key_exists('enabled', $saved)) $out['enabled'] = !empty($saved['enabled']) ? 1 : 0;
        if (isset($saved['items']) && is_array($saved['items'])) {
            foreach (self::ITEMS as $k) if (array_key_exists($k, $saved['items'])) $out['items'][$k] = !empty($saved['items'][$k]) ? 1 : 0;
        }
        return self::clamp($out, $saved);
    }

    /** @param array<string, mixed> $post the form (a missing checkbox means off) */
    public static function fromPost(array $post): array
    {
        $items = isset($post['items']) && is_array($post['items']) ? $post['items'] : [];
        $out = ['enabled' => !empty($post['enabled']) ? 1 : 0, 'items' => []];
        foreach (self::ITEMS as $k) $out['items'][$k] = !empty($items[$k]) ? 1 : 0;
        $post['attendance_repeat'] = !empty($post['attendance_repeat']) ? 1 : 0;
        return self::clamp($out + self::DEFAULTS, $post);
    }

    /** @param array<string, mixed> $out @param array<string, mixed> $in */
    private static function clamp(array $out, array $in): array
    {
        $num = static function (string $key, int $min, int $max) use ($in, $out): int {
            return isset($in[$key]) ? max($min, min($max, abs((int) $in[$key]))) : (int) $out[$key];
        };
        $out['attendance_after'] = $num('attendance_after', 1, 240);
        $out['attendance_repeat'] = !empty($in['attendance_repeat']) ? 1 : 0;
        $out['attendance_repeat_interval'] = $num('attendance_repeat_interval', 5, 240);
        $out['attendance_max_reminders'] = $num('attendance_max_reminders', 1, 10);
        return $out;
    }
}
