<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * Every scheduled event and role the plugin creates, in one place (3.31.119): deactivate clears every event (also those scheduled with
 * arguments, e.g. a break's reminders and the push batches), uninstall clears them and, when the data is deleted, removes every role.
 * tests/unit/LifecycleTest.php fails when a hook or a role is added elsewhere without being listed here.
 * Loaded by uninstall.php without the plugin's autoloader: no other class is used.
 */
final class Lifecycle
{
    public const CRON_HOOKS = [
        'ews_notifications_cleanup', 'ews_auto_attendance_tick', 'ews_smart_nudges_tick', 'ews_tasks_tick', 'ews_corrections_tick',
        'ews_office_minimum_tick', 'ews_approvals_tick', 'ews_privacy_cleanup', 'ews_health_rollup', 'ews_break_duration_reminder',
        'ews_break_manager_escalation', 'ews_push_deliver',
    ];

    public const ROLES = ['ews_administrator', 'ews_manager', 'ews_supervisor', 'ews_employee', 'ews_foreman'];

    /** Every event of every hook, whatever its arguments (wp_unschedule_hook). */
    public static function clearCron(): void
    {
        foreach (self::CRON_HOOKS as $hook) wp_unschedule_hook($hook);
    }

    public static function removeRoles(): void
    {
        foreach (self::ROLES as $role) remove_role($role);
    }
}
