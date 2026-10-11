<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/**
 * WordPress hooks of the Sign In / Sign Out module.
 *
 * Modules register their own hooks instead of the plugin constructor listing every
 * add_action(). Action names are unchanged so existing forms, links and cron events keep working.
 */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews31_time_event', [$plugin, 'time_event']);
        add_action('admin_post_ews_break_start', [$plugin, 'break_start']);
        add_action('admin_post_ews_break_resume', [$plugin, 'break_resume']);
        add_action('ews_break_duration_reminder', [$plugin, 'break_duration_reminder'], 10, 1);
        add_action('ews_break_manager_escalation', [$plugin, 'break_manager_escalation'], 10, 1);
    }
}
