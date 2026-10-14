<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * What the database update must leave behind (3.31.92): the tables every site has, and the columns
 * added to existing tables in later versions (an ALTER that failed used to go unnoticed: the update
 * was marked done anyway). Tables of features are created by their own routines whose errors the
 * update collects. Pure: no WordPress calls.
 */
final class SchemaCheck
{
    /** Tables without the prefix. */
    public const TABLES = [
        'ews_employees', 'ews_schedule', 'ews_time_logs', 'ews_leave_requests', 'ews_audit_log', 'ews_notifications',
        'ews_break_sessions', 'ews_locations', 'ews_departments', 'ews_teams', 'ews_team_members', 'ews_approval_workflows',
        'ews_approval_requests', 'ews_approval_steps', 'ews_leave_types', 'ews_leave_balances', 'ews_overtime_requests',
        'ews_early_leave_requests', 'ews_face_profiles', 'ews_push_subscriptions', 'ews_pay_rates', 'ews_payroll_runs',
        'ews_payslips', 'ews_pay_adjustments', 'ews_attendance_corrections', 'ews_employee_branches', 'ews_signin_attempts',
    ];

    /** Columns added to a table after it was first created. */
    public const COLUMNS = [
        'ews_employees' => ['email', 'wp_user_id', 'default_shift_id', 'attendance_enabled', 'department_id', 'last_working_day', 'job_title'],
        'ews_time_logs' => ['source', 'corrects_id', 'correction_id', 'location_id', 'branch_flag', 'photo_key'],
        'ews_schedule' => ['location_id'],
        'ews_notifications' => ['link'],
    ];

    /**
     * @param string[] $tables the plugin's tables that exist (without the prefix)
     * @param array<string,string[]> $columns table => its columns
     * @return string[] what is missing ("ews_x", "ews_x.col")
     */
    public static function missing(array $tables, array $columns): array
    {
        $have = array_flip($tables);
        $out = [];
        foreach (self::TABLES as $t) if (!isset($have[$t])) $out[] = $t;
        foreach (self::COLUMNS as $t => $cols) {
            if (!isset($have[$t])) continue;
            $present = array_flip(array_map('strtolower', $columns[$t] ?? []));
            foreach ($cols as $c) if (!isset($present[$c])) $out[] = $t . '.' . $c;
        }
        return $out;
    }
}
