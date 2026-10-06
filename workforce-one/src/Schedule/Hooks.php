<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of the Team Schedule and Schedule Swap module (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_swap_create', [$plugin, 'swap_request_create']);
        add_action('admin_post_ews_swap_respond', [$plugin, 'swap_request_respond']);
        add_action('admin_post_ews_swap_cancel', [$plugin, 'swap_request_cancel']);
        add_action('admin_post_ews_schedule_pdf', [$plugin, 'schedule_pdf']); // the Team Schedule week as a PDF (PDF / WhatsApp buttons)
    }
}
