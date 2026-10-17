<?php
namespace WorkforceOne\Organization;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of wp-admin → Departments and Teams (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_department_save', [$plugin, 'department_save']);
        add_action('admin_post_ews_department_delete', [$plugin, 'department_delete']);
        add_action('admin_post_ews_team_save', [$plugin, 'team_save']);
        add_action('admin_post_ews_team_delete', [$plugin, 'team_delete']);
    }
}
