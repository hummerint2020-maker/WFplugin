<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Office minimum settings (3.31.77, option ews_office_minimum; the switch is ews_feature_office_minimum).
 * Pure: no WordPress calls.
 */
final class OfficeMinimumSettings
{
    public const DEFAULTS = [
        'min' => 10,
        'days' => [],               // weekday (0 = Sunday) => its own minimum
        'statuses' => ['Office'],   // schedule statuses that count as in the office
        'teams' => [],              // team id => fixed number (else proportional)
        'reminder' => 1,
        'reminder_day' => 4,        // Thursday
        'reminder_time' => '12:00',
    ];

    /** @param mixed $saved @return array{min:int,days:array<int,int>,statuses:list<string>,teams:array<int,int>,reminder:int,reminder_day:int,reminder_time:string} */
    public static function config($saved): array
    {
        $s = is_array($saved) ? $saved : [];
        $c = self::DEFAULTS;
        if (isset($s['min']) && is_numeric($s['min']) && (int) $s['min'] >= 1) $c['min'] = (int) $s['min'];
        foreach ((array) ($s['days'] ?? []) as $w => $n) {
            if (is_numeric($w) && (int) $w >= 0 && (int) $w <= 6 && is_numeric($n) && (int) $n >= 0) $c['days'][(int) $w] = (int) $n;
        }
        if (isset($s['statuses']) && is_array($s['statuses'])) {
            $c['statuses'] = array_values(array_unique(array_filter(array_map(static function ($v) { return trim((string) $v); }, $s['statuses']), 'strlen')));
        }
        foreach ((array) ($s['teams'] ?? []) as $t => $n) {
            if (is_numeric($t) && (int) $t > 0 && is_numeric($n) && (int) $n >= 0) $c['teams'][(int) $t] = (int) $n;
        }
        if (isset($s['reminder'])) $c['reminder'] = (int) (bool) $s['reminder'];
        if (isset($s['reminder_day']) && is_numeric($s['reminder_day']) && (int) $s['reminder_day'] >= 0 && (int) $s['reminder_day'] <= 6) $c['reminder_day'] = (int) $s['reminder_day'];
        if (isset($s['reminder_time']) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $s['reminder_time'])) $c['reminder_time'] = (string) $s['reminder_time'];
        return $c;
    }

    /**
     * From Feature Configuration (unslashed).
     * @param array<string,mixed> $post om_min, om_days[w], om_statuses[], om_teams[id], om_reminder, om_reminder_day, om_reminder_time
     * @param list<string> $known the schedule statuses that exist
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | min | day | team | statuses | time]
     */
    public static function fromPost(array $post, array $known): array
    {
        $min = trim((string) ($post['om_min'] ?? ''));
        if (!ctype_digit($min) || (int) $min < 1 || (int) $min > 100000) return [[], 'min'];
        $days = [];
        foreach ((array) ($post['om_days'] ?? []) as $w => $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            if (!is_numeric($w) || (int) $w < 0 || (int) $w > 6 || !ctype_digit($n) || (int) $n > 100000) return [[], 'day'];
            $days[(int) $w] = (int) $n;
        }
        $teams = [];
        foreach ((array) ($post['om_teams'] ?? []) as $t => $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            if (!is_numeric($t) || (int) $t <= 0 || !ctype_digit($n) || (int) $n > 100000) return [[], 'team'];
            $teams[(int) $t] = (int) $n;
        }
        $statuses = array_values(array_intersect($known, array_map('strval', (array) ($post['om_statuses'] ?? []))));
        if (!$statuses) return [[], 'statuses'];
        $time = (string) ($post['om_reminder_time'] ?? '12:00');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) return [[], 'time'];
        $day = (int) ($post['om_reminder_day'] ?? 4);
        return [[
            'min' => (int) $min, 'days' => $days, 'statuses' => $statuses, 'teams' => $teams,
            'reminder' => empty($post['om_reminder']) ? 0 : 1, 'reminder_day' => ($day >= 0 && $day <= 6) ? $day : 4, 'reminder_time' => $time,
        ], ''];
    }
}
