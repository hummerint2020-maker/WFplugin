<?php
namespace WorkforceOne\Settings;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of wp-admin → Settings Overview. */
final class OverviewHooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_settings_export', [$plugin, 'settings_overview_export']);
    }
}
