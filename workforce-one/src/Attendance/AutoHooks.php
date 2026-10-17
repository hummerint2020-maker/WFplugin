<?php
namespace WorkforceOne\Attendance;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of Auto Attendance: its wp-admin actions and the five-minute cron (names unchanged). */
final class AutoHooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_auto_attendance_save', [$plugin, 'auto_attendance_save']);
        add_action('admin_post_ews_auto_attendance_toggle', [$plugin, 'auto_attendance_toggle']);
        add_action('admin_post_ews_auto_attendance_delete', [$plugin, 'auto_attendance_delete']);
        add_action('admin_post_ews_auto_attendance_bulk_sign_out', [$plugin, 'auto_attendance_bulk_sign_out']);
        add_action('ews_auto_attendance_tick', [$plugin, 'auto_attendance_cron']);
    }
}
