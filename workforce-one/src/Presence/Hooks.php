<?php
namespace WorkforceOne\Presence;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of kiosks, QR Sign In and presence verification (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_presence_kiosk_save', [$plugin, 'presence_kiosk_save']);
        add_action('admin_post_ews_presence_kiosk_revoke', [$plugin, 'presence_kiosk_revoke']);
        add_action('admin_post_ews_presence_request', [$plugin, 'presence_request']);
        add_action('admin_post_ews_presence_verify', [$plugin, 'presence_verify']);
        add_action('admin_post_ews_presence_qr_signin', [$plugin, 'presence_qr_signin']);
        add_action('template_redirect', [$plugin, 'presence_kiosk_payload_route'], 1);
        add_action('template_redirect', [$plugin, 'presence_kiosk_route'], 2);
    }
}
