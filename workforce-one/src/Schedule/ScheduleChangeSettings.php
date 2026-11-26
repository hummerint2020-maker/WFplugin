<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Schedule change request settings (3.31.165, option ews_schedule_change; the switch is ews_feature_schedule_change).
 * Edited on Feature Configuration → Week lock → Change requests to HR (docs/tasks/schedule-change-request.md §2.6).
 * Pure: no WordPress calls.
 */
final class ScheduleChangeSettings
{
    public const DEFAULTS = [
        'statuses' => [],          // the types a manager may ask for; empty = every active type that is not a leave, plus Absent
        'allow_leave' => 0,        // leave types (rule "leave") go through a leave request, so the balance is counted (owner answer 2)
        'reason_min' => 10,        // characters; a reason is always required (owner answer 8)
        'max_days' => 25,          // day rows in one request (owner answer 3)
        'past_days' => 14,         // days back; 0 = today and later only (owner answer 10)
        'expire_days' => 14,       // days without a decision; 0 = never (owner answer 6)
        'notify_on_request' => 0,  // also tell the employee when it is requested (owner answer 4)
        'changed_day' => 'skip',   // skip | refuse (owner answer 5)
        'import' => 1,             // final-week CSV rows can be sent to HR (owner answer 11; the CSV part is the next release)
    ];

    /**
     * @param mixed $saved the stored option
     * @param string[] $types the active schedule type names (Absent included) the statuses are checked against
     * @return array<string,mixed>
     */
    public static function config($saved, array $types): array
    {
        $s = is_array($saved) ? $saved : [];
        $c = self::DEFAULTS;
        if (isset($s['statuses']) && is_array($s['statuses'])) $c['statuses'] = array_values(array_intersect($types, array_map('strval', $s['statuses'])));
        foreach (['allow_leave', 'notify_on_request', 'import'] as $k) if (isset($s[$k])) $c[$k] = (int) (bool) $s[$k];
        $c['reason_min'] = self::range($s['reason_min'] ?? null, 1, 200, $c['reason_min']);
        $c['max_days'] = self::range($s['max_days'] ?? null, 1, 200, $c['max_days']);
        $c['past_days'] = self::range($s['past_days'] ?? null, 0, 90, $c['past_days']);
        $c['expire_days'] = self::range($s['expire_days'] ?? null, 0, 90, $c['expire_days']);
        if (isset($s['changed_day']) && in_array($s['changed_day'], ['skip', 'refuse'], true)) $c['changed_day'] = (string) $s['changed_day'];
        return $c;
    }

    /**
     * From Feature Configuration (unslashed).
     * @param array<string,mixed> $post sc_statuses[], sc_allow_leave, sc_reason_min, sc_max_days, sc_past_days, sc_expire_days, sc_notify_on_request, sc_changed_day, sc_import
     * @param string[] $types
     * @return array{0:array<string,mixed>,1:string} [settings, error: '' | reason_min | max_days | past_days | expire_days | statuses]
     */
    public static function fromPost(array $post, array $types): array
    {
        $out = [];
        foreach (['reason_min' => [1, 200], 'max_days' => [1, 200], 'past_days' => [0, 90], 'expire_days' => [0, 90]] as $k => [$min, $max]) {
            $v = trim((string) ($post['sc_' . $k] ?? ''));
            if (!ctype_digit($v) || (int) $v < $min || (int) $v > $max) return [[], $k];
            $out[$k] = (int) $v;
        }
        $picked = is_array($post['sc_statuses'] ?? null) ? array_map('strval', $post['sc_statuses']) : [];
        $out['statuses'] = array_values(array_intersect($types, $picked));
        $out['allow_leave'] = empty($post['sc_allow_leave']) ? 0 : 1;
        if (!$out['statuses']) return [[], 'statuses'];   // at least one (stored empty would mean "all")
        $out['notify_on_request'] = empty($post['sc_notify_on_request']) ? 0 : 1;
        $out['import'] = empty($post['sc_import']) ? 0 : 1;
        $out['changed_day'] = ($post['sc_changed_day'] ?? '') === 'refuse' ? 'refuse' : 'skip';
        return [$out, ''];
    }

    /** @param mixed $v */
    private static function range($v, int $min, int $max, int $default): int
    {
        if ($v === null || !is_numeric($v)) return $default;
        $v = (int) $v;
        return $v < $min || $v > $max ? $default : $v;
    }
}
