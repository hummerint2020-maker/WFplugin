<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/** admin-post actions of the engagement settings pages (existing action names unchanged). */
final class EngagementHooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_smart_nudges_save', [$plugin, 'smart_nudges_save']);
        add_action('admin_post_ews_smart_nudge_test_push', [$plugin, 'smart_nudge_test_push']);
        add_action('admin_post_ews31_employee_moments_save', [$plugin, 'employee_moments_save']);
        add_action('admin_post_ews31_profile_settings_save', [$plugin, 'profile_settings_save']);
        add_action('admin_post_ews31_navigation_save', [$plugin, 'navigation_save']);
        add_action('admin_post_ews31_navigation_reset', [$plugin, 'navigation_reset']);
        add_action('admin_post_ews31_kudos_delete', [$plugin, 'kudos_delete']);
    }
}
