<?php
namespace WorkforceOne\HrLetters\Data;

if (!defined('ABSPATH')) exit;

/**
 * The HR letters table (HL3, docs/tasks/hr-letter-hl3.md §1): one source for the upgrade (dbDelta) and the storage contract.
 * The reference HR-YYYY-NNNNNN is derived from the id and requested_at, never stored. open_key is "employee:type:language" while a request
 * is open and NULL otherwise, so the UNIQUE key allows one open request per type and language (owner decision 10).
 */
final class LetterSchema
{
    /** Creates or updates the table of the container's prefix (the upgrade step). */
    public static function create(\wpdb $db): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(self::sql($db->prefix . 'ews_hr_letters', $db->get_charset_collate()));
    }

    public static function sql(string $table, string $collate): string
    {
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            source VARCHAR(10) NOT NULL DEFAULT 'employee',
            type_key VARCHAR(40) NOT NULL DEFAULT '',
            type_label VARCHAR(190) NOT NULL DEFAULT '',
            other_text VARCHAR(255) NOT NULL DEFAULT '',
            addressee VARCHAR(190) NOT NULL DEFAULT '',
            language CHAR(2) NOT NULL DEFAULT 'ar',
            note VARCHAR(500) NOT NULL DEFAULT '',
            delivery VARCHAR(10) NOT NULL DEFAULT 'none',
            with_salary TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            open_key VARCHAR(80) NULL,
            requested_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            decision_note VARCHAR(500) NOT NULL DEFAULT '',
            issued_at DATETIME NULL,
            issued_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_key VARCHAR(40) NULL,
            file_name VARCHAR(190) NOT NULL DEFAULT '',
            file_source VARCHAR(10) NOT NULL DEFAULT '',
            file_size INT UNSIGNED NOT NULL DEFAULT 0,
            issue_error VARCHAR(30) NOT NULL DEFAULT '',
            downloaded_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY open_key (open_key),
            KEY employee (employee_id,id),
            KEY status (status,requested_at)
        ) {$collate};";
    }
}
