<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * WordPress hooks of the API transport.
 *
 * - rest_api_init: the route table (Routes).
 * - rest_request_after_callbacks: native routes answer with the API envelope even when a permission
 *   check refuses, and the WordPress user a token set is put back after the handler.
 * - Session revocation (3.31.47): a password change or reset, a deleted user and a user removed
 *   from the site end every native device session of that user. (Archiving an employee is handled
 *   where it happens, in the Employees page.)
 *
 * Deliberately not hooked: determine_current_user and rest_authentication_errors. Tokens are read
 * only by the native routes' permission callback, so they authenticate nothing else.
 */
final class Hooks
{
    /** @param object $plugin the plugin instance that implements the handlers */
    public static function register($plugin): void
    {
        add_action('rest_api_init', static function () use ($plugin) {
            Routes::register($plugin);
        });
        add_filter('rest_request_after_callbacks', [$plugin, 'api_after_callbacks'], 10, 2);
        add_action('wp_set_password', [$plugin, 'api_on_password_set'], 10, 2);
        add_action('after_password_reset', [$plugin, 'api_on_password_reset'], 10, 1);
        add_action('profile_update', [$plugin, 'api_on_profile_update'], 10, 2);
        add_action('deleted_user', [$plugin, 'api_on_user_deleted'], 10, 1);
        add_action('remove_user_from_blog', [$plugin, 'api_on_user_removed_from_site'], 10, 1);
    }
}
