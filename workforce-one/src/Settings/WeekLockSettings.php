<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Week lock settings (3.31.121, option ews_week_lock; the switch is ews_feature_week_lock).
 * Pure: no WordPress calls.
 */
final class WeekLockSettings
{
    public const DEFAULTS = [
        'cutoff_day' => 4,          // Thursday (0 = Sunday)
        'cutoff_time' => '17:00',   // site time
        'notify' => 1,              // tell everyone with a schedule that week at the cutoff
    ];

    /** @param mixed $saved @return array{cutoff_day:int,cutoff_time:string,notify:int} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $c = self::DEFAULTS;
        if (isset($s['cutoff_day']) && is_numeric($s['cutoff_day']) && (int) $s['cutoff_day'] >= 0 && (int) $s['cutoff_day'] <= 6) $c['cutoff_day'] = (int) $s['cutoff_day'];
        if (isset($s['cutoff_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $s['cutoff_time'])) $c['cutoff_time'] = (string) $s['cutoff_time'];
        if (isset($s['notify'])) $c['notify'] = (int) (bool) $s['notify'];
        return $c;
    }

    /**
     * From Feature Configuration (unslashed).
     * @param array<string,mixed> $post wl_cutoff_day, wl_cutoff_time, wl_notify
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | day | time]
     */
    public static function fromPost(array $post): array
    {
        $day = trim((string) ($post['wl_cutoff_day'] ?? ''));
        if (!ctype_digit($day) || (int) $day > 6) return [[], 'day'];
        $time = trim((string) ($post['wl_cutoff_time'] ?? ''));
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) return [[], 'time'];
        return [['cutoff_day' => (int) $day, 'cutoff_time' => $time, 'notify' => empty($post['wl_notify']) ? 0 : 1], ''];
    }
}
