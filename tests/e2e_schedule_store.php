<?php
/**
 * The Schedule days Store on the real database (3.31.127; docs/tasks/layered-modules.md §4.3): temporary copies of the
 * installed tables, so nothing of the site is touched. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\Schedule\Data\WordPressScheduleDayStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = $wpdb->prefix . 'sched_contract_';
// MySQL: temporary copies of the installed tables. SQLite (local runs) has no CREATE ... LIKE: the same columns and keys.
$like = $wpdb->query("CREATE TEMPORARY TABLE {$prefix}ews_schedule LIKE {$wpdb->prefix}ews_schedule") !== false
    && $wpdb->query("CREATE TEMPORARY TABLE {$prefix}ews_company_calendar LIKE {$wpdb->prefix}ews_company_calendar") !== false;
if (!$like) {
    $wpdb->query("CREATE TABLE {$prefix}ews_schedule (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, work_date DATE NOT NULL, status VARCHAR(50) NOT NULL, note TEXT NULL, updated_by BIGINT UNSIGNED NULL, updated_at DATETIME NULL, location_id BIGINT UNSIGNED NULL, PRIMARY KEY (id), UNIQUE KEY employee_date (employee_id,work_date))");
    $wpdb->query("CREATE TABLE {$prefix}ews_company_calendar (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_date DATE NOT NULL, title VARCHAR(190) NOT NULL, event_type VARCHAR(40) NOT NULL, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (id))");
    register_shutdown_function(function () use ($wpdb, $prefix) { $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_schedule"); $wpdb->query("DROP TABLE IF EXISTS {$prefix}ews_company_calendar"); });
}
$check($wpdb->get_var("SELECT COUNT(*) FROM {$prefix}ews_schedule") === '0', 'contract tables ready (' . ($like ? 'copied from the installed schema' : 'same columns, SQLite') . ')');
$db = clone $wpdb;
$db->prefix = $prefix;
$store = new WordPressScheduleDayStore($db);
$id = $store->insert(['employee_id' => 5, 'work_date' => '2026-01-05', 'status' => 'Office', 'note' => 'n', 'updated_by' => 1, 'updated_at' => '2026-01-01 09:00:00']);
$check($id > 0, 'insert returns the database id');
$quiet = $db->suppress_errors(true);
$check($store->insert(['employee_id' => 5, 'work_date' => '2026-01-05', 'status' => 'WFH', 'note' => '', 'updated_by' => 1, 'updated_at' => '2026-01-01 09:00:00']) === 0 && $store->lastError() !== '', 'the UNIQUE employee and day refuses a second row (0, with the database message)');
$db->suppress_errors($quiet);
$full = $store->find(5, '2026-01-05');
$raw = $db->get_row("SELECT * FROM {$prefix}ews_schedule WHERE id=" . (int) $id);
$check($full == $raw && $full->status === 'Office', 'find() is the whole row (SELECT *)');
$check($store->findStatusNote(5, '2026-01-05')->note === 'n' && $store->findStatusNoteById($id)->status === 'Office' && $store->find(5, '2026-01-06') === null, 'id, status and note by day and by id; null when there is none');
$check($store->idOf(5, '2026-01-05') === (string) $id && $store->statusOf($id) === 'Office' && $store->noteOf($id) === 'n' && $store->statusOf(999999) === null, 'single values, as the database gives them');
$check($store->updateStatusIf($id, 'Leave', 'WFH', 'n', 2, '2026-01-02 09:00:00') === 0 && $store->statusOf($id) === 'Office', 'a stale expected status changes nothing (0 rows)');
$check($store->updateStatusIf($id, 'Office', 'WFH', 'n', 2, '2026-01-02 09:00:00') === 1 && $store->statusOf($id) === 'WFH', 'the expected status: changed (1 row)');
$check($store->update(['note' => 'm'], $id) !== false && $store->noteOf($id) === 'm', 'update by id');
$store->insert(['employee_id' => 6, 'work_date' => '2026-01-06', 'status' => 'Office', 'note' => '', 'updated_by' => 1, 'updated_at' => '2026-01-01 09:00:00']);
$on = $store->rowsOnDates(['2026-01-06', '2026-01-05']);
$check(count($on) === 2 && $on[0]->work_date === '2026-01-05' && isset($on[0]->employee_id, $on[0]->status) && $store->rowsOnDates([]) === [], 'rows on days, by day');
$db->insert($prefix . 'ews_company_calendar', ['event_date' => '2026-01-07', 'title' => 'Holiday', 'event_type' => 'general_leave', 'active' => 1]);
$db->insert($prefix . 'ews_company_calendar', ['event_date' => '2026-01-07', 'title' => 'Off', 'event_type' => 'general_leave', 'active' => 0]);
$db->insert($prefix . 'ews_company_calendar', ['event_date' => '2026-01-08', 'title' => 'Event', 'event_type' => 'event', 'active' => 1]);
$ev = $store->calendarEvents('2026-01-01', '2026-01-31');
$check(count($ev) === 1 && $ev[0]->title === 'Holiday', 'company holidays: active general leave only');
// One container per site: another prefix gets its own Store; back on the first, the same one.
$o = new EWS_Manager_V31_1();
$svc = new ReflectionMethod($o, 'services');
$svc->setAccessible(true);
$a = $svc->invoke($o);
$keep = $wpdb->prefix;
$wpdb->prefix = $prefix;
$b = $svc->invoke($o);
$wpdb->prefix = $keep;
$check($a !== $b && $svc->invoke($o) === $a, 'one container per table prefix (multisite), reused when switching back');
