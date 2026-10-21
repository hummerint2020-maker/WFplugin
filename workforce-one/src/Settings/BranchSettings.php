<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Branches (3.31.72, wp-admin → Feature Configuration → Branches, option ews_branch_settings): how an
 * employee's branch is decided when they sign in. A branch is a Work Location. Pure: no WordPress calls.
 *
 * mode single:   the employee's main branch only (as before 3.31.72)
 * mode any:      the main branch or any of their other branches; the branch they were at is recorded
 * mode schedule: the branch planned for the day (Attendance grid), else the main branch;
 *                with allow_others, another of their branches is accepted and flagged
 */
final class BranchSettings
{
    public const MODES = ['single', 'any', 'schedule'];
    public const SWITCHES = [
        'allow_others' => 1,    // "By the schedule": accept another of their branches, flagged
        'kiosk_any' => 1,       // a kiosk QR is accepted at any branch the employee may sign in at today
        'show_branch' => 1,     // today's branch on Home, My week and Sign In
        'manager_scope' => 0,   // managers plan / edit only their department's branches
    ];
    public const DEFAULTS = ['mode' => 'single'] + self::SWITCHES;

    /** @param mixed $saved the stored option (missing keys take the built-in values) */
    public static function config($saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $out = self::DEFAULTS;
        if (isset($saved['mode']) && in_array($saved['mode'], self::MODES, true)) $out['mode'] = $saved['mode'];
        foreach (self::SWITCHES as $k => $v) if (array_key_exists($k, $saved)) $out[$k] = !empty($saved[$k]) ? 1 : 0;
        return $out;
    }

    /** @param array<string,mixed> $post the form (a missing checkbox means off) */
    public static function fromPost(array $post): array
    {
        $out = self::DEFAULTS;
        $mode = (string) ($post['branch_mode'] ?? '');
        $out['mode'] = in_array($mode, self::MODES, true) ? $mode : 'single';
        foreach (self::SWITCHES as $k => $v) $out[$k] = !empty($post['branch_' . $k]) ? 1 : 0;
        return $out;
    }
}
