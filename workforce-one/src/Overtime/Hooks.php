<?php
namespace WorkforceOne\Overtime;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the Overtime and Early Leave module (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_overtime_request_create', [$plugin, 'overtime_request_create']);
        add_action('admin_post_ews_overtime_request_respond', [$plugin, 'overtime_request_respond']);
        add_action('admin_post_ews_early_leave_create', [$plugin, 'early_leave_create']);
        add_action('admin_post_ews_early_leave_respond', [$plugin, 'early_leave_respond']);
    }
}
