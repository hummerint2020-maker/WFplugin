<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\PayRules;
use WorkforceOne\Reports\Capacity;

/**
 * Every setting Workforce One keeps in the WordPress options table: what it is, where it is edited
 * and the value used when nothing is saved. Code reads a setting through the plugin's option()
 * helper, which falls back to the default here, so a default is changed in this file only.
 * tests/unit/OptionsTest.php fails when code reads an option that is not listed here, or passes
 * get_option() a different default.
 *
 * Kinds:
 *  - bool, int, number (decimal), text, days (weekday numbers, Sunday = 0): plain values.
 *  - grouped: an array with its own built-in defaults, kept with the code that uses them
 *    ('builtin' describes them); the plugin compares the effective value with them. 'default' is only
 *    what get_option() falls back to.
 *  - secret: never shown or exported, only whether it is set.
 *  - data: employee data stored as an option; only counted.
 * 'internal' marks bookkeeping the plugin writes itself; 'legacy' marks values kept for old data.
 * Names and stored shapes must not change: saved data on every site depends on them.
 *
 * Pure: no WordPress calls.
 */
final class Options
{
    /** Per-kiosk secrets are stored as this prefix + the kiosk id. */
    public const KIOSK_KEY_PREFIX = 'ews_presence_kiosk_key_';
    /** The row standing for all kiosk secrets. */
    public const KIOSK_KEYS = 'ews_presence_kiosk_key_*';

    public const GROUPS = [
        'schedule' => 'Attendance & Schedule',
        'locations' => 'Locations & Presence',
        'requests' => 'Early Leave & Breaks',
        'features' => 'Features',
        'face' => 'Face Sign In',
        'app' => 'Employee App',
        'engagement' => 'Engagement',
        'notifications' => 'Notifications',
        'access' => 'Access',
        'privacy' => 'Privacy',
        'payroll' => 'Payroll',
        'system' => 'System',
    ];

    private const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    private const ALL = [
        // Attendance & Schedule
        'ews_grace_period' => ['group' => 'schedule', 'label' => 'Grace period before Late', 'kind' => 'int', 'default' => 10, 'unit' => 'min', 'page' => 'ews31-schedule-config'],
        'ews_absent_after_minutes' => ['group' => 'schedule', 'label' => 'A no-show on a shift is Absent today after', 'kind' => 'int', 'default' => 120, 'unit' => 'min', 'page' => 'ews31-schedule-config'],
        'ews_allow_overnight_shift' => ['group' => 'schedule', 'label' => 'Overnight shifts allowed', 'kind' => 'bool', 'default' => 0, 'page' => 'ews31-schedule-config'],
        'ews_working_days' => ['group' => 'schedule', 'label' => 'Working days', 'kind' => 'days', 'default' => [0, 1, 2, 3, 4], 'page' => 'ews31-schedule-config'],
        'ews_shifts' => ['group' => 'schedule', 'label' => 'Shifts', 'kind' => 'grouped', 'default' => [], 'builtin' => 'one Standard shift, 08:00–17:00', 'page' => 'ews31-schedule-config'],
        'ews_working_hours' => ['group' => 'schedule', 'label' => 'Company working hours (employees without a shift)', 'kind' => 'grouped', 'default' => [], 'builtin' => '08:00–17:00', 'page' => 'ews31-schedule-config'],
        'ews_schedule_types_config' => ['group' => 'schedule', 'label' => 'Schedule types', 'kind' => 'grouped', 'default' => [], 'builtin' => 'Office, WFH, Vacation, Business Trip, Training Course', 'page' => 'ews31-schedule-config'],
        'ews_attendance_emails' => ['group' => 'schedule', 'label' => 'Attendance email recipients', 'kind' => 'text', 'default' => '', 'page' => 'ews31-email'],

        // Locations & Presence
        'ews_location_latitude' => ['group' => 'locations', 'label' => 'Fallback location latitude (employees without a work location)', 'kind' => 'text', 'default' => '', 'legacy' => true],
        'ews_location_longitude' => ['group' => 'locations', 'label' => 'Fallback location longitude', 'kind' => 'text', 'default' => '', 'legacy' => true],
        'ews_location_radius' => ['group' => 'locations', 'label' => 'Fallback location radius', 'kind' => 'int', 'default' => 200, 'unit' => 'm', 'legacy' => true],
        'ews_location_name' => ['group' => 'locations', 'label' => 'Old single-location name (no longer used)', 'kind' => 'text', 'default' => 'Office HQ', 'legacy' => true],
        'ews_location_enforcement' => ['group' => 'locations', 'label' => 'Old single-location enforcement (no longer used)', 'kind' => 'int', 'default' => 0, 'legacy' => true],
        'ews_presence_qr_signin' => ['group' => 'locations', 'label' => 'QR Sign In at kiosks', 'kind' => 'bool', 'default' => 0, 'page' => 'ews31-features'],
        'ews_presence_verification' => ['group' => 'locations', 'label' => 'Presence verification requests', 'kind' => 'bool', 'default' => 0, 'page' => 'ews31-features'],
        'ews_presence_request_minutes' => ['group' => 'locations', 'label' => 'Time to answer a presence request', 'kind' => 'int', 'default' => 3, 'unit' => 'min', 'page' => 'ews31-features'],
        'ews_capacity_warn_percent' => ['group' => 'locations', 'label' => 'Location Capacity: near capacity from', 'kind' => 'int', 'default' => Capacity::WARN_PCT, 'unit' => '% of seats', 'page' => 'ews31-multi-locations'],
        self::KIOSK_KEYS => ['group' => 'locations', 'label' => 'Kiosk secrets', 'kind' => 'secret', 'default' => '', 'page' => 'ews31-presence-kiosks'],

        // Early Leave & Breaks
        'ews_early_leave_max_minutes' => ['group' => 'requests', 'label' => 'Early leave: max per request', 'kind' => 'int', 'default' => 120, 'unit' => 'min', 'page' => 'ews31-features'],
        'ews_early_leave_monthly_minutes' => ['group' => 'requests', 'label' => 'Early leave: monthly allowance', 'kind' => 'int', 'default' => 240, 'unit' => 'min', 'page' => 'ews31-features'],
        'ews_early_leave_office_only' => ['group' => 'requests', 'label' => 'Early leave only on Office days', 'kind' => 'bool', 'default' => 1, 'page' => 'ews31-features'],
        'ews_feature_breaks' => ['group' => 'requests', 'label' => 'Breaks', 'kind' => 'bool', 'default' => false, 'page' => 'ews31-features'],
        'ews_breaks_per_day' => ['group' => 'requests', 'label' => 'Breaks per day', 'kind' => 'int', 'default' => 3, 'page' => 'ews31-features'],
        'ews_break_duration_minutes' => ['group' => 'requests', 'label' => 'Break length', 'kind' => 'int', 'default' => 30, 'unit' => 'min', 'page' => 'ews31-features'],
        'ews_break_manager_alert_minutes' => ['group' => 'requests', 'label' => 'Alert the manager after a break of', 'kind' => 'int', 'default' => 45, 'unit' => 'min', 'page' => 'ews31-features'],

        // Features
        'ews_feature_overtime' => ['group' => 'features', 'label' => 'Overtime requests', 'kind' => 'bool', 'default' => false, 'page' => 'ews31-features'],
        'ews_feature_tasks' => ['group' => 'features', 'label' => 'Tasks', 'kind' => 'bool', 'default' => false, 'page' => 'ews31-features'],
        'ews_branch_settings' => ['group' => 'features', 'label' => 'Branches: how the branch is decided', 'kind' => 'grouped', 'default' => [], 'builtin' => 'one branch per employee', 'page' => 'ews31-features'],
        'ews_tasks_settings' => ['group' => 'features', 'label' => 'Tasks: parts and limits', 'kind' => 'grouped', 'default' => [], 'builtin' => 'every part on; files up to 5 MB; reminder 1 h before', 'page' => 'ews31-tasks'],
        'ews_feature_achievements' => ['group' => 'features', 'label' => 'Achievements', 'kind' => 'bool', 'default' => true, 'page' => 'ews31-achievements'],
        'ews_feature_recognition' => ['group' => 'features', 'label' => 'Recognition', 'kind' => 'bool', 'default' => true, 'page' => 'ews31-features'],
        'ews_recognition_allow_kudos' => ['group' => 'features', 'label' => 'Kudos between colleagues', 'kind' => 'bool', 'default' => true, 'page' => 'ews31-features'],
        'ews_recognition_weekly_limit_mode' => ['group' => 'features', 'label' => 'Kudos weekly limit', 'kind' => 'text', 'default' => 'limited', 'page' => 'ews31-features'],
        'ews_recognition_weekly_limit' => ['group' => 'features', 'label' => 'Kudos per week', 'kind' => 'int', 'default' => 5, 'page' => 'ews31-features'],
        'ews_confirm_global' => ['group' => 'features', 'label' => 'Ask for confirmation before actions', 'kind' => 'bool', 'default' => 1, 'page' => 'ews31-features'],
        'ews_confirmation_actions' => ['group' => 'features', 'label' => 'Actions that ask for confirmation', 'kind' => 'grouped', 'default' => [], 'builtin' => 'all actions', 'page' => 'ews31-features'],

        // Face Sign In
        'ews_feature_face_signin' => ['group' => 'face', 'label' => 'Face Sign In', 'kind' => 'bool', 'default' => false, 'page' => 'ews31-features'],
        'ews_face_signin_settings' => ['group' => 'face', 'label' => 'Face Sign In settings', 'kind' => 'grouped', 'default' => [], 'builtin' => 'built-in tuning values', 'page' => 'ews31-features'],
        'ews_face_reset_requests' => ['group' => 'face', 'label' => 'Face reset requests', 'kind' => 'data', 'default' => [], 'page' => 'ews31-face-reset-requests'],

        // Employee App
        'ews_pwa_splash_settings' => ['group' => 'app', 'label' => 'Splash screen', 'kind' => 'grouped', 'default' => [], 'builtin' => 'on, "Workforce One", 650 ms', 'page' => 'ews31-features'],
        'ews_frontend_navigation' => ['group' => 'app', 'label' => 'App menu (labels, order, hidden items)', 'kind' => 'grouped', 'default' => [], 'builtin' => 'all items, built-in labels and order', 'page' => 'ews31-navigation'],
        'ews_appearance' => ['group' => 'app', 'label' => 'Appearance (brand, theme colours, font, corners)', 'kind' => 'grouped', 'default' => [], 'builtin' => 'Indigo Night theme, Alexandria font, round corners', 'page' => 'ews31-appearance'],
        'ews_employee_profile_settings' => ['group' => 'app', 'label' => 'Employee profile fields', 'kind' => 'grouped', 'default' => [], 'builtin' => 'photo, name, team and achievements shown', 'page' => 'ews31-employee-profile-settings'],

        // Engagement
        'ews_employee_moments_enabled' => ['group' => 'engagement', 'label' => 'Employee Moments', 'kind' => 'bool', 'default' => 1, 'page' => 'ews31-moments'],
        'ews_employee_moments' => ['group' => 'engagement', 'label' => 'Birthdays and join dates', 'kind' => 'data', 'default' => [], 'page' => 'ews31-moments'],
        'ews_smart_nudges' => ['group' => 'engagement', 'label' => 'Smart Nudges', 'kind' => 'grouped', 'default' => [], 'builtin' => 'on for attendance, tasks, leave and schedule', 'page' => 'ews31-smart-nudges'],

        // Notifications
        'ews_notification_policy' => ['group' => 'notifications', 'label' => 'Notification channels per category', 'kind' => 'grouped', 'default' => [], 'builtin' => 'in-app on; push as set per category', 'page' => 'ews31-notifications'],
        'ews_notification_retention_days' => ['group' => 'notifications', 'label' => 'Keep notifications for', 'kind' => 'int', 'default' => NotificationSettings::DEFAULT_RETENTION, 'unit' => 'days', 'zero' => 'Forever', 'page' => 'ews31-notifications'],
        'ews_vapid_subject' => ['group' => 'notifications', 'label' => 'Push contact (VAPID subject)', 'kind' => 'text', 'default' => null, 'builtin' => 'mailto: + the site admin email', 'page' => 'ews31-notifications'],
        'ews_vapid_public_key' => ['group' => 'notifications', 'label' => 'Push public key', 'kind' => 'text', 'default' => '', 'internal' => true],
        'ews_vapid_private_key' => ['group' => 'notifications', 'label' => 'Push private key', 'kind' => 'secret', 'default' => ''],
        'ews_push_last_delivery' => ['group' => 'notifications', 'label' => 'Last push delivery (sent, failed, last error)', 'kind' => 'data', 'default' => [], 'internal' => true],

        // Access
        'ews_role_permissions' => ['group' => 'access', 'label' => 'Roles & permissions', 'kind' => 'grouped', 'default' => [], 'builtin' => 'each role keeps its WordPress capabilities', 'page' => 'ews31-roles'],

        // Privacy
        'ews_location_retention_days' => ['group' => 'privacy', 'label' => 'Keep sign-in locations for', 'kind' => 'int', 'default' => 0, 'unit' => 'days', 'zero' => 'Forever', 'page' => 'ews31-features'],
        'ews_face_delete_inactive' => ['group' => 'privacy', 'label' => 'Delete face data of inactive employees', 'kind' => 'bool', 'default' => 1, 'page' => 'ews31-features'],
        'ews_delete_data_on_uninstall' => ['group' => 'privacy', 'label' => 'Delete all data when the plugin is deleted', 'kind' => 'bool', 'default' => 0, 'page' => 'ews31-features'],

        // Payroll (wp-admin → Payroll → Rules; defaults in src/Payroll/PayRules.php)
        'ews_payroll_currency' => ['group' => 'payroll', 'label' => 'Currency', 'kind' => 'text', 'default' => PayRules::DEFAULTS['currency'], 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_day_divisor' => ['group' => 'payroll', 'label' => 'A day\'s pay = monthly pay ÷', 'kind' => 'int', 'default' => PayRules::DEFAULTS['day_divisor'], 'unit' => 'days', 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_day_base' => ['group' => 'payroll', 'label' => 'A day\'s pay is based on (gross = basic + allowances)', 'kind' => 'text', 'default' => PayRules::DEFAULTS['day_base'], 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_absence_days' => ['group' => 'payroll', 'label' => 'An absent day deducts', 'kind' => 'number', 'default' => PayRules::DEFAULTS['absence_days'], 'unit' => 'days\' pay', 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_overtime_rate' => ['group' => 'payroll', 'label' => 'Overtime on work days', 'kind' => 'number', 'default' => PayRules::DEFAULTS['overtime_rate'], 'unit' => '× hourly pay', 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_overtime_rate_off' => ['group' => 'payroll', 'label' => 'Overtime on days off and holidays', 'kind' => 'number', 'default' => PayRules::DEFAULTS['overtime_rate_off'], 'unit' => '× hourly pay', 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_late_mode' => ['group' => 'payroll', 'label' => 'Late arrival is deducted (minute = by the minute, tiers = by tiers)', 'kind' => 'text', 'default' => PayRules::DEFAULTS['late_mode'], 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_late_tiers' => ['group' => 'payroll', 'label' => 'Late arrival tiers', 'kind' => 'grouped', 'default' => PayRules::DEFAULTS['late_tiers'], 'builtin' => 'none', 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_employee_view' => ['group' => 'payroll', 'label' => 'Employees see their pay in the app (My Pay)', 'kind' => 'bool', 'default' => PayRules::DEFAULTS['employee_view'], 'page' => 'ews31-payroll&tab=rules'],
        'ews_payroll_max_deduction_days' => ['group' => 'payroll', 'label' => 'Deductions limited to', 'kind' => 'number', 'default' => PayRules::DEFAULTS['max_deduction_days'], 'unit' => 'days\' pay', 'zero' => 'No limit', 'page' => 'ews31-payroll&tab=rules'],

        // System (written by the plugin itself)
        'ews_schema_version' => ['group' => 'system', 'label' => 'Database version', 'kind' => 'text', 'default' => '', 'internal' => true],
        'ews_schedule_config_schema' => ['group' => 'system', 'label' => 'Schedule settings format', 'kind' => 'text', 'default' => '', 'internal' => true],
        'ews_integrity_repair_done' => ['group' => 'system', 'label' => 'One-time data repair done', 'kind' => 'bool', 'default' => false, 'internal' => true],
        'ews_app_page_id' => ['group' => 'system', 'label' => 'Employee app page', 'kind' => 'int', 'default' => 0, 'internal' => true],
        'ews_shifts_highest_id' => ['group' => 'system', 'label' => 'Highest shift id used', 'kind' => 'int', 'default' => 0, 'internal' => true],
        'ews_notification_policy_updated_at' => ['group' => 'system', 'label' => 'Notification settings last saved', 'kind' => 'text', 'default' => '', 'internal' => true],
        'ews_notification_policy_updated_by' => ['group' => 'system', 'label' => 'Notification settings saved by (user id)', 'kind' => 'int', 'default' => 0, 'internal' => true],
        'ews_privacy_cleanup_last_run' => ['group' => 'system', 'label' => 'Privacy cleanup last run', 'kind' => 'text', 'default' => '', 'internal' => true],
        'ews_smart_nudges_last_run' => ['group' => 'system', 'label' => 'Smart Nudges last run', 'kind' => 'int', 'default' => 0, 'internal' => true],
        'ews_smart_nudges_last_result' => ['group' => 'system', 'label' => 'Smart Nudges last result', 'kind' => 'grouped', 'default' => [], 'internal' => true],
    ];

    /**
     * All settings, in display order.
     * @return array<string, array{group:string,label:string,kind:string,default:mixed,page:?string,unit:string,zero:?string,builtin:?string,internal:bool,legacy:bool}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (self::ALL as $name => $d) {
            $out[$name] = $d + ['page' => null, 'unit' => '', 'zero' => null, 'builtin' => null, 'internal' => false, 'legacy' => false];
        }
        return $out;
    }

    /** Whether $name is a Workforce One setting (kiosk secrets included). */
    public static function has(string $name): bool
    {
        return isset(self::ALL[$name]) || self::isKioskKey($name);
    }

    public static function isKioskKey(string $name): bool
    {
        return strpos($name, self::KIOSK_KEY_PREFIX) === 0 && ctype_digit(substr($name, strlen(self::KIOSK_KEY_PREFIX)));
    }

    /**
     * What get_option() falls back to for $name.
     * @return mixed
     */
    public static function defaultOf(string $name)
    {
        if (self::isKioskKey($name)) return '';
        if (!isset(self::ALL[$name])) throw new \InvalidArgumentException('Unknown Workforce One option: ' . $name);
        return self::ALL[$name]['default'];
    }

    /**
     * Whether a stored plain value differs from its default. Grouped, secret, data, internal and
     * legacy settings are never "changed" here (the plugin compares grouped ones itself).
     * @param mixed $value
     * @param mixed $default overrides the registered default (for defaults known only at runtime)
     */
    public static function isChanged(string $name, $value, $default = null): bool
    {
        $d = self::all()[$name] ?? null;
        if (!$d || $d['internal'] || $d['legacy'] || in_array($d['kind'], ['grouped', 'secret', 'data'], true)) return false;
        $default = $default ?? $d['default'];
        switch ($d['kind']) {
            case 'bool':
                return (bool) $value !== (bool) $default;
            case 'int':
                return (int) $value !== (int) $default;
            case 'number':
                return abs((float) $value - (float) $default) > 1e-9;
            case 'days':
                return self::days($value) !== self::days($default);
            default:
                return trim((string) (is_scalar($value) ? $value : '')) !== (string) $default;
        }
    }

    /**
     * A plain value as text for the overview page. Grouped settings are described by the plugin.
     * @param mixed $value
     */
    public static function display(string $name, $value): string
    {
        $d = self::all()[$name] ?? ['kind' => 'text', 'unit' => '', 'zero' => null];
        switch ($d['kind']) {
            case 'bool':
                return $value ? 'On' : 'Off';
            case 'int':
                if ((int) $value === 0 && $d['zero'] !== null) return $d['zero'];
                return trim((int) $value . ' ' . $d['unit']);
            case 'number':
                if ((float) $value == 0 && $d['zero'] !== null) return $d['zero'];
                return trim(rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.') . ' ' . $d['unit']);
            case 'days':
                $days = self::days($value);
                return $days ? implode(', ', array_map(static function ($n) { return self::DAYS[$n]; }, $days)) : 'None';
            case 'secret':
                return $value !== '' && $value !== null && $value !== false ? 'Set' : 'Not set';
            case 'data':
                $n = is_array($value) ? count($value) : 0;
                return $n . ' ' . ($n === 1 ? 'item' : 'items');
            case 'grouped':
                return is_array($value) ? count($value) . ' ' . (count($value) === 1 ? 'entry' : 'entries') : '—';
            default:
                if (!is_scalar($value) || (string) $value === '') return '(empty)';
                $s = (string) $value;
                return strlen($s) > 120 ? substr($s, 0, 117) . '...' : $s;
        }
    }

    /**
     * The value as written to the export file: secrets become "(set)" or "", employee data a count.
     * @param mixed $value
     * @return mixed
     */
    public static function exportValue(string $name, $value)
    {
        $kind = self::isKioskKey($name) ? 'secret' : (self::all()[$name]['kind'] ?? 'text');
        if ($kind === 'secret') return $value !== '' && $value !== null && $value !== false ? '(set)' : '';
        if ($kind === 'data') return ['count' => is_array($value) ? count($value) : 0];
        if ($value === null) return null;
        if ($kind === 'bool') return (bool) $value;
        if ($kind === 'int' && is_numeric($value)) return (int) $value;
        if ($kind === 'number' && is_numeric($value)) return (float) $value;
        if ($kind === 'days') return self::days($value);
        return $value;
    }

    /**
     * Whether a stored settings array, laid over its built-in defaults, differs from them. Keys the
     * defaults do not have are ignored; values compare loosely ("1" equals 1), as the forms store them.
     * @param mixed $stored
     * @param array<string, mixed> $defaults
     */
    public static function differsFrom($stored, array $defaults): bool
    {
        if (!is_array($stored)) return false;
        $merged = array_intersect_key(array_replace($defaults, $stored), $defaults);
        foreach ($defaults as $key => $value) {
            if (is_array($value) || is_array($merged[$key])) {
                if (self::differsFrom(is_array($merged[$key]) ? $merged[$key] : [], is_array($value) ? $value : [])) return true;
                continue;
            }
            $a = is_bool($merged[$key]) ? (int) $merged[$key] : $merged[$key];
            $b = is_bool($value) ? (int) $value : $value;
            if (is_numeric($a) && is_numeric($b) ? (float) $a !== (float) $b : (string) $a !== (string) $b) return true;
        }
        return false;
    }

    /**
     * Weekday numbers, sorted and unique.
     * @param mixed $value
     * @return int[]
     */
    public static function days($value): array
    {
        $out = [];
        foreach ((array) $value as $v) {
            if (is_numeric($v) && (int) $v >= 0 && (int) $v <= 6) $out[(int) $v] = (int) $v;
        }
        ksort($out);
        return array_values($out);
    }
}
