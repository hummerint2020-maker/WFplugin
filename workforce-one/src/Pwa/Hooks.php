<?php
namespace WorkforceOne\Pwa;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the installable app (PWA): page head and footer, manifest, service worker and offline screen. */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('wp_head', [$plugin, 'pwa_head']);
        add_action('wp_footer', [$plugin, 'pwa_footer']);
        add_action('template_redirect', [$plugin, 'pwa_manifest_route']);
        add_action('template_redirect', [$plugin, 'pwa_sw_route']);
        add_action('template_redirect', [$plugin, 'pwa_offline_route']); // the offline screen's page and ping (3.31.93)
    }
}
