<?php
namespace WorkforceOne\Payroll;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of wp-admin → Payroll. */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_payroll_rate_save', [$plugin, 'payroll_rate_save']);
        add_action('admin_post_ews_payroll_rate_delete', [$plugin, 'payroll_rate_delete']);
        add_action('admin_post_ews_payroll_rules_save', [$plugin, 'payroll_rules_save']);
        add_action('admin_post_ews_payroll_export', [$plugin, 'payroll_export']);
    }
}
