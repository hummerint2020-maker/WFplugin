<?php
namespace WorkforceOne\Approvals;

if (!defined('ABSPATH')) exit;

/** WordPress hooks of wp-admin → Approval Workflows (action names unchanged). */
final class Hooks
{
    /** @param object $plugin The plugin instance that implements the handlers. */
    public static function register($plugin): void
    {
        add_action('admin_post_ews_approval_workflow_save', [$plugin, 'approval_workflow_save']);
        add_action('admin_post_ews_approval_relationship_save', [$plugin, 'approval_relationship_save']);
    }
}
