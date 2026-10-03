<?php
namespace WorkforceOne\Locations;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of wp-admin → Work Locations (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_multi_location_save_v321', [$plugin, 'admin_multi_location_save_v321']);
        add_action('admin_post_ews_multi_location_archive_v321', [$plugin, 'admin_multi_location_archive_v321']);
        add_action('admin_post_ews_employee_location_save_v321', [$plugin, 'admin_employee_location_save_v321']);
    }
}
