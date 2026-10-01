<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the Schedule Configuration admin page (action names unchanged). */
final class ConfigHooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews31_working_hours_save', [$plugin, 'working_hours_save_handler']);
        add_action('admin_post_ews31_working_days_save', [$plugin, 'working_days_save_handler']);
        add_action('admin_post_ews31_shifts_save', [$plugin, 'shifts_save_handler']);
        add_action('admin_post_ews31_schedule_config_save', [$plugin, 'schedule_config_save']);
        add_action('admin_post_ews31_general_leave_save', [$plugin, 'general_leave_save']);
        add_action('admin_post_ews31_general_leave_delete', [$plugin, 'general_leave_delete']);
    }
}
