<?php
namespace WorkforceOne\Polls;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of Employee Polls (action names unchanged; export is new). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_poll_save', [$plugin, 'poll_save']);
        add_action('admin_post_ews_poll_toggle', [$plugin, 'poll_toggle']);
        add_action('admin_post_ews_poll_homepage', [$plugin, 'poll_homepage']);
        add_action('admin_post_ews_poll_archive', [$plugin, 'poll_archive']);
        add_action('admin_post_ews_poll_export', [$plugin, 'poll_export']);
        add_action('admin_post_ews_poll_vote', [$plugin, 'poll_vote']);
    }
}
