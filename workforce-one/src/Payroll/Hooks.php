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
        add_action('admin_post_ews_payroll_adjust_save', [$plugin, 'payroll_adjust_save']);
        add_action('admin_post_ews_payroll_adjust_delete', [$plugin, 'payroll_adjust_delete']);
        add_action('admin_post_ews_payroll_close', [$plugin, 'payroll_close']);
        add_action('admin_post_ews_payroll_reopen', [$plugin, 'payroll_reopen']);
        add_action('admin_post_ews_payroll_pdf', [$plugin, 'payroll_pdf']);
        add_action('admin_post_ews_payslip_pdf', [$plugin, 'payslip_pdf']); // My Pay (any signed-in employee, own payslip only)
    }
}
