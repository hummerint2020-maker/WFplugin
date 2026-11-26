<?php
namespace WorkforceOne\Schedule\Data;

if (!defined('ABSPATH')) exit;

/**
 * The schedule change request tables (3.31.165, docs/tasks/schedule-change-request.md §2.2): one header per request (the
 * approvals engine's entity) and one row per person and day. open_key is "employee|date" while the request waits and NULL
 * after, so the UNIQUE key allows one waiting request per person and day.
 */
final class ScheduleChangeSchema
{
    /** Creates or updates both tables of the container's prefix (the upgrade step). */
    public static function create(\wpdb $db): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $db->get_charset_collate();
        dbDelta(self::requestsSql($db->prefix . 'ews_schedule_change_requests', $c));
        dbDelta(self::daysSql($db->prefix . 'ews_schedule_change_days', $c));
    }

    public static function requestsSql(string $table, string $collate): string
    {
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requester_employee_id BIGINT UNSIGNED NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(10) NOT NULL DEFAULT 'grid',
            reason TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            applied SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_date DATE NOT NULL,
            last_date DATE NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY status (status,requested_at),
            KEY requester (requester_employee_id,id)
        ) {$collate};";
    }

    public static function daysSql(string $table, string $collate): string
    {
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            had_row TINYINT(1) NOT NULL DEFAULT 0,
            schedule_id BIGINT UNSIGNED NULL,
            old_status VARCHAR(40) NULL,
            new_status VARCHAR(40) NOT NULL,
            branch_set TINYINT(1) NOT NULL DEFAULT 0,
            old_location_id BIGINT UNSIGNED NULL,
            new_location_id BIGINT UNSIGNED NULL,
            result VARCHAR(12) NULL,
            now_status VARCHAR(40) NULL,
            open_key VARCHAR(40) NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY open_key (open_key),
            KEY request (request_id,employee_id,work_date),
            KEY employee_date (employee_id,work_date)
        ) {$collate};";
    }
}
