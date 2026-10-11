<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Attendance corrections (3.31.74, wp-admin → Attendance Corrections → Settings, option
 * ews_correction_settings). The feature itself is switched on in Feature Configuration
 * (ews_feature_corrections). Pure: no WordPress calls.
 */
final class CorrectionSettings
{
    /** out: forgot Sign Out · in: forgot Sign In · time: a recorded time is wrong · day: the whole day is missing */
    public const TYPES = ['out', 'in', 'time', 'day'];
    public const PHOTO = ['off', 'optional', 'required'];
    public const ABOVE_LIMIT = ['refuse', 'hr'];
    public const DEFAULTS = [
        'types' => ['out' => 1, 'in' => 1, 'time' => 1, 'day' => 1],
        'photo' => ['out' => 'optional', 'in' => 'optional', 'time' => 'optional', 'day' => 'required'],
        'deadline_days' => 7,
        'monthly_limit' => 3,          // 0 = no limit
        'above_limit' => 'hr',         // refuse, or allow and send to HR (the second level)
        'earlier_needs_hr' => 1,       // moving Sign In earlier removes lateness: HR approves too
        'reminder' => 1,
        'reminder_time' => '19:00',
    ];

    /** @param mixed $saved the stored option (missing or bad values take the built-in ones) */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = self::DEFAULTS;
        foreach (self::TYPES as $t) {
            if (isset($saved['types']) && is_array($saved['types']) && array_key_exists($t, $saved['types'])) $out['types'][$t] = !empty($saved['types'][$t]) ? 1 : 0;
            $p = $saved['photo'][$t] ?? null;
            if (is_string($p) && in_array($p, self::PHOTO, true)) $out['photo'][$t] = $p;
        }
        if (isset($saved['deadline_days'])) $out['deadline_days'] = self::clamp($saved['deadline_days'], 1, 60, 7);
        if (isset($saved['monthly_limit'])) $out['monthly_limit'] = self::clamp($saved['monthly_limit'], 0, 31, 3);
        if (isset($saved['above_limit']) && in_array($saved['above_limit'], self::ABOVE_LIMIT, true)) $out['above_limit'] = $saved['above_limit'];
        foreach (['earlier_needs_hr', 'reminder'] as $k) if (array_key_exists($k, $saved)) $out[$k] = !empty($saved[$k]) ? 1 : 0;
        if (isset($saved['reminder_time']) && self::isTime((string) $saved['reminder_time'])) $out['reminder_time'] = (string) $saved['reminder_time'];
        return $out;
    }

    /**
     * The settings form. A missing checkbox means off.
     * @param array<string,mixed> $post
     * @return array{0:array<string,mixed>,1:string} [settings, error code ('' = fine)]
     */
    public static function fromPost(array $post): array
    {
        $out = self::DEFAULTS;
        foreach (self::TYPES as $t) {
            $out['types'][$t] = !empty($post['cx_type_' . $t]) ? 1 : 0;
            $p = (string) ($post['cx_photo_' . $t] ?? 'optional');
            $out['photo'][$t] = in_array($p, self::PHOTO, true) ? $p : 'optional';
        }
        $deadline = trim((string) ($post['cx_deadline_days'] ?? ''));
        $limit = trim((string) ($post['cx_monthly_limit'] ?? ''));
        $time = trim((string) ($post['cx_reminder_time'] ?? ''));
        if (!ctype_digit($deadline) || (int) $deadline < 1 || (int) $deadline > 60) return [$out, 'deadline'];
        if (!ctype_digit($limit) || (int) $limit > 31) return [$out, 'limit'];
        if (!self::isTime($time)) return [$out, 'time'];
        if (!array_sum($out['types'])) return [$out, 'types'];
        $out['deadline_days'] = (int) $deadline;
        $out['monthly_limit'] = (int) $limit;
        $out['above_limit'] = (($post['cx_above_limit'] ?? '') === 'refuse') ? 'refuse' : 'hr';
        $out['earlier_needs_hr'] = !empty($post['cx_earlier_needs_hr']) ? 1 : 0;
        $out['reminder'] = !empty($post['cx_reminder']) ? 1 : 0;
        $out['reminder_time'] = $time;
        return [$out, ''];
    }

    public static function isTime(string $t): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /** @param mixed $v */
    private static function clamp($v, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($v)) return $fallback;
        return max($min, min($max, (int) $v));
    }
}
