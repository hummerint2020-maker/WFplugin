<?php
/**
 * Workforce One uninstall.
 *
 * Runs when the plugin is deleted from the Plugins screen (not on deactivate).
 * Data is removed ONLY if an administrator enabled
 * "Delete all Workforce One data when the plugin is deleted" under
 * Feature Configuration → Privacy & Data Retention. Otherwise only scheduled
 * events are cleared and all data is kept, so a reinstall picks up where it left off.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

function ews_uninstall_site() {
    global $wpdb;

    foreach (['ews_notifications_cleanup', 'ews_auto_attendance_tick', 'ews_smart_nudges_tick', 'ews_privacy_cleanup', 'ews_break_duration_reminder', 'ews_break_manager_escalation', 'ews_tasks_tick', 'ews_corrections_tick'] as $hook) {
        wp_unschedule_hook($hook);
    }

    if ((int) get_option('ews_delete_data_on_uninstall', 0) !== 1) return;

    // Private folders: files attached to task comments (3.31.70), correction request photos (3.31.74).
    foreach (['workforce-one-tasks', 'workforce-one-corrections', 'workforce-one-daily-workers'] as $folder) {
        $task_dir = trailingslashit(wp_upload_dir()['basedir']) . $folder;
        if (is_dir($task_dir) && !is_link($task_dir)) {
            foreach ((array) scandir($task_dir) as $name) if ($name !== '.' && $name !== '..' && is_file($task_dir . '/' . $name)) @unlink($task_dir . '/' . $name);
            @rmdir($task_dir);
        }
    }

    // Uploaded employee profile photos (only files inside the uploads directory).
    $employees = $wpdb->prefix . 'ews_employees';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $employees)) === $employees
        && $wpdb->get_var("SHOW COLUMNS FROM `{$employees}` LIKE 'profile_image_url'")) {
        $uploads = wp_upload_dir();
        $base = rtrim((string) $uploads['baseurl'], '/') . '/';
        $root = wp_normalize_path(trailingslashit($uploads['basedir']));
        foreach ((array) $wpdb->get_col("SELECT profile_image_url FROM `{$employees}` WHERE profile_image_url IS NOT NULL AND profile_image_url<>''") as $url) {
            if (strpos($url, $base) !== 0) continue;
            $path = wp_normalize_path($root . ltrim(substr($url, strlen($base)), '/'));
            if (strpos($path, $root) === 0 && strpos($path, '..') === false && is_file($path)) @unlink($path);
        }
    }

    $tables = [
        'ews_achievements', 'ews_approval_requests', 'ews_approval_steps', 'ews_approval_workflow_steps',
        'ews_api_devices', 'ews_api_idempotency', 'ews_api_rate_limits', 'ews_api_tokens', 'ews_approval_workflows', 'ews_audit_log', 'ews_auto_attendance_rules', 'ews_break_sessions',
        'ews_company_calendar', 'ews_departments', 'ews_early_leave_requests', 'ews_employee_achievements', 'ews_employee_branches',
        'ews_employee_locations_v321', 'ews_employee_relationships', 'ews_employees', 'ews_face_profiles',
        'ews_kiosks', 'ews_kudos', 'ews_leave_balances', 'ews_leave_requests', 'ews_leave_schedule_snapshots',
        'ews_leave_types', 'ews_attendance_corrections', 'ews_dw_workers', 'ews_dw_sites', 'ews_dw_foremen', 'ews_dw_moves', 'ews_dw_days', 'ews_dw_sheets', 'ews_dw_advances', 'ews_dw_payouts', 'ews_dw_lines', 'ews_dw_changes', 'ews_locations', 'ews_notifications', 'ews_overtime_requests', 'ews_pay_adjustments', 'ews_pay_rates', 'ews_payroll_runs', 'ews_payslips', 'ews_poll_options',
        'ews_poll_votes', 'ews_polls', 'ews_presence_verifications', 'ews_push_subscriptions', 'ews_schedule',
        'ews_schedule_swaps', 'ews_shift_swaps', 'ews_tasks', 'ews_task_items', 'ews_task_comments', 'ews_task_activity', 'ews_team_members', 'ews_teams', 'ews_time_logs',
        'ews_vacation_requests',
    ];
    foreach ($tables as $t) {
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$t}`");
    }

    // Options (ews_*, including per-kiosk keys) and transients (ews_*, ews31_*).
    $like = [
        $wpdb->esc_like('ews_') . '%',
        $wpdb->esc_like('_transient_ews') . '%',
        $wpdb->esc_like('_transient_timeout_ews') . '%',
    ];
    foreach ($like as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like('ews_') . '%'));

    // Custom roles and the ews_* capabilities granted to Administrator.
    foreach (['ews_administrator', 'ews_manager', 'ews_supervisor', 'ews_employee'] as $role) {
        remove_role($role);
    }
    $admin = get_role('administrator');
    if ($admin) {
        foreach (array_keys($admin->capabilities) as $cap) {
            if (strpos($cap, 'ews_') === 0) $admin->remove_cap($cap);
        }
    }

    wp_cache_flush();
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog($site_id);
        ews_uninstall_site();
        restore_current_blog();
    }
} else {
    ews_uninstall_site();
}
