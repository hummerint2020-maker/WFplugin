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
        // Manager office minimum (3.31.153): a minimum of team managers in the office too; works only with the Office minimum on.
        'mgr' => 0,
        'mgr_min' => 2,
        'mgr_days' => [],           // weekday => its own number (0 = no managers' minimum that day)
    ];

    /** The manager keys at their defaults are never stored, so a site that does not use them saves what it saved before. */
    private const MGR_KEYS = ['mgr', 'mgr_min', 'mgr_days'];

    /** @param mixed $saved @return array{min:int,days:array<int,int>,statuses:list<string>,teams:array<int,int>,reminder:int,reminder_day:int,reminder_time:string,mgr:int,mgr_min:int,mgr_days:array<int,int>} */
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
        if (isset($s['mgr'])) $c['mgr'] = (int) (bool) $s['mgr'];
        if (isset($s['mgr_min']) && is_numeric($s['mgr_min']) && (int) $s['mgr_min'] >= 1) $c['mgr_min'] = (int) $s['mgr_min'];
        foreach ((array) ($s['mgr_days'] ?? []) as $w => $n) {
            if (is_numeric($w) && (int) $w >= 0 && (int) $w <= 6 && is_numeric($n) && (int) $n >= 0) $c['mgr_days'][(int) $w] = (int) $n;
        }
        return $c;
    }

    /**
     * From Feature Configuration (unslashed).
     * @param array<string,mixed> $post om_min, om_days[w], om_statuses[], om_teams[id], om_reminder, om_reminder_day, om_reminder_time
     * @param list<string> $known the schedule statuses that exist
     * @param mixed $saved what is stored now: without om_mgr_present (an older form) its manager keys are kept
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | min | day | team | statuses | time | mgr_min | mgr_day]
     */
    public static function fromPost(array $post, array $known, $saved = []): array
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
        [$mgr, $error] = self::managersFromPost($post, $saved);
        if ($error !== '') return [[], $error];
        return [[
            'min' => (int) $min, 'days' => $days, 'statuses' => $statuses, 'teams' => $teams,
            'reminder' => empty($post['om_reminder']) ? 0 : 1, 'reminder_day' => ($day >= 0 && $day <= 6) ? $day : 4, 'reminder_time' => $time,
        ] + $mgr, ''];
    }

    /**
     * The managers' part (om_mgr, om_mgr_min, om_mgr_days[w]); only the keys that differ from the defaults.
     * @param array<string,mixed> $post
     * @param mixed $saved
     * @return array{0:array<string,mixed>,1:string}
     */
    private static function managersFromPost(array $post, $saved): array
    {
        if (!isset($post['om_mgr_present'])) {
            $keep = [];
            foreach (self::MGR_KEYS as $k) if (is_array($saved) && array_key_exists($k, $saved)) $keep[$k] = $saved[$k];
            return [$keep, ''];
        }
        $on = empty($post['om_mgr']) ? 0 : 1;
        $min = trim((string) ($post['om_mgr_min'] ?? ''));
        $valid = static function (string $v): bool { return ctype_digit($v) && (int) $v >= 1 && (int) $v <= 100000; };
        if (!$on && !$valid($min)) $min = (string) self::config($saved)['mgr_min'];   // switched off: a blank or bad number keeps the saved one
        if (!$valid($min)) return [[], 'mgr_min'];
        $days = [];
        foreach ((array) ($post['om_mgr_days'] ?? []) as $w => $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            if (!is_numeric($w) || (int) $w < 0 || (int) $w > 6 || !ctype_digit($n) || (int) $n > 100000) {
                if ($on) return [[], 'mgr_day'];
                $days = self::config($saved)['mgr_days'];   // switched off: bad numbers keep the saved ones, as the minimum does
                break;
            }
            $days[(int) $w] = (int) $n;
        }
        $out = [];
        if ($on) $out['mgr'] = 1;
        if ((int) $min !== self::DEFAULTS['mgr_min']) $out['mgr_min'] = (int) $min;
        if ($days) $out['mgr_days'] = $days;
        return [$out, ''];
    }
}
