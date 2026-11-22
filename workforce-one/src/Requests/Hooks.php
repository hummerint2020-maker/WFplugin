<?php
namespace WorkforceOne\Requests;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the wp-admin request pages (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews31_requests_decision', [$plugin, 'admin_requests_decision']);
        add_action('admin_post_ews31_requests_export', [$plugin, 'admin_requests_export']);   // the Reports tab's export (3.31.159; refused while switched off)
        add_action('admin_post_ews31_face_reset_approve', [$plugin, 'face_reset_admin_approve']);
        add_action('admin_post_ews31_face_reset_reject', [$plugin, 'face_reset_admin_reject']);
    }
}
