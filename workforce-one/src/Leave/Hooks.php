<?php
namespace WorkforceOne\Leave;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the Leave module (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_leave_type_save', [$plugin, 'admin_leave_type_save']);
        add_action('admin_post_ews_leave_balance_save', [$plugin, 'admin_leave_balance_save']);
        add_action('admin_post_ews_admin_leave_record', [$plugin, 'admin_admin_leave_record']);
        add_action('admin_post_ews_vacation_request_create', [$plugin, 'leave_request_create']);
        add_action('admin_post_ews_vacation_request_respond', [$plugin, 'leave_request_respond']);
        add_action('admin_post_ews_leave_cancel', [$plugin, 'leave_cancel_request']);
        add_action('admin_post_ews_leave_cancel_respond', [$plugin, 'leave_cancel_respond']);
    }
}
