<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * WordPress hooks of the API transport: registers the route table on rest_api_init.
 * (Token authentication will hook in here in Phase 0B; today routes use the WordPress cookie and
 * the X-WP-Nonce header, as before.)
 */
final class Hooks
{
    /** @param object $plugin the plugin instance that implements the handlers */
    public static function register($plugin): void
    {
        add_action('rest_api_init', static function () use ($plugin) {
            Routes::register($plugin);
        });
    }
}
