<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/**
 * The leave change rounds table (Leave return, 3.31.161, docs/tasks/leave-return.md): one row per round (the employee's "back at
 * work" asked, or a return HR recorded). Moved here as it was in 3.31.164 (docs/tasks/leave-return-layers.md); the DDL text is
 * unchanged, called from ensure_leave_schema() at the same place.
 */
final class LeaveReturnSchema
{
    public static function sql(string $prefix, string $collate): string
    {
        $table = $prefix . 'ews_leave_changes';
        return "CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, leave_request_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL,
                round SMALLINT UNSIGNED NOT NULL DEFAULT 1, source VARCHAR(10) NOT NULL DEFAULT 'employee', asked_from DATE NOT NULL,
                applied_from DATE NULL, status VARCHAR(20) NOT NULL DEFAULT 'Pending', days_back DECIMAL(8,2) NOT NULL DEFAULT 0,
                carry_top_up DECIMAL(8,2) NOT NULL DEFAULT 0, end_before DATE NOT NULL, end_after DATE NULL, reason TEXT NULL,
                approval_request_id BIGINT UNSIGNED NULL, requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_by BIGINT UNSIGNED NULL, decided_at DATETIME NULL,
                PRIMARY KEY(id), KEY leave_round(leave_request_id,id), KEY employee(employee_id,requested_at)) {$collate};";
    }
}
