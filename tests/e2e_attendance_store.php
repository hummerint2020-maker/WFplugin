<?php
/**
 * The Attendance Stores on the real database (3.31.136; docs/tasks/attendance-store.md §6): real tables with their own prefix (so the
 * table-name rules show), dropped at the end, so nothing of the site is touched. Each method's result is compared with what the old
 * inline SQL gave. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\Attendance\Data\WordPressBreakStore;
use WorkforceOne\Attendance\Data\WordPressTimeLogStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = 'att_contract_';
$other = 'att_contract_other_';
$tables = ['ews_time_logs', 'ews_break_sessions'];
foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $other, $tables) { foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}"); });
foreach ([$prefix, $other] as $px) {
    $wpdb->query("CREATE TABLE {$px}ews_time_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, event_type VARCHAR(30) NOT NULL, event_at DATETIME NOT NULL, scheduled_status VARCHAR(40) NOT NULL DEFAULT '', latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, location_timestamp BIGINT NULL, corrects_id BIGINT UNSIGNED NULL, PRIMARY KEY (id))");
    $wpdb->query("CREATE TABLE {$px}ews_break_sessions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NULL, actual_minutes INT UNSIGNED NULL, status VARCHAR(20) NOT NULL DEFAULT 'Open', PRIMARY KEY (id))");
}
$db = clone $wpdb;
$db->prefix = $prefix;
$tl = $prefix . 'ews_time_logs';
$bs = $prefix . 'ews_break_sessions';
$add = static function ($eid, $day, $type, $at, array $more = []) use ($db, $tl) {
    $db->insert($tl, ['employee_id' => $eid, 'work_date' => $day, 'event_type' => $type, 'event_at' => $at] + $more);
    return (int) $db->insert_id;
};
$a = $add(7, '2026-10-29', 'sign_in', '2026-10-29 09:30:00', ['latitude' => 30.1, 'longitude' => 31.2, 'location_timestamp' => 100]);
$b = $add(7, '2026-10-29', 'sign_in', '2026-10-29 08:05:00', ['corrects_id' => $a, 'latitude' => 30.5, 'longitude' => 31.6, 'location_timestamp' => 200]);
$c = $add(7, '2026-10-29', 'sign_out', '2026-10-29 17:00:00');
$d = $add(7, '2026-10-28', 'late_sign_in', '2026-10-28 22:20:00');
$e = $add(8, '2026-10-29', 'sign_in', '2026-10-29 08:00:00');
$S = new WordPressTimeLogStore($db, $tl);

// ---- the live filter: the exact text the old tl_live() gave, and the rows it leaves
$live = " AND NOT EXISTS (SELECT 1 FROM {$tl} tl_cx WHERE tl_cx.corrects_id={$tl}.id)";
$old = $db->get_results($db->prepare("SELECT * FROM {$tl} WHERE employee_id=%d AND work_date=%s" . $live . ' ORDER BY event_at ASC', 7, '2026-10-29'));
$new = $S->liveDay(7, '2026-10-29');
$check($new == $old && count($new) === 2 && $new[0]->id == $b && $new[1]->id == $c, 'liveDay: the same rows as the old SQL, the corrected one hidden, oldest first');
$check($S->liveDay(99, '2026-10-29') === [] && $S->liveDay(7, '2026-01-01') === [], 'liveDay: an array, empty when none');
$check($S->typesOn(7, '2026-10-29') == (array) $db->get_col($db->prepare("SELECT event_type FROM {$tl} WHERE employee_id=%d AND work_date=%s" . $live, 7, '2026-10-29')) && $S->typesOn(7, '2026-10-28') === ['late_sign_in'], 'typesOn: the live event types');
$io = $S->liveInOut(7, '2026-10-29');
$check(count($io) === 2 && $io[0]->event_type === 'sign_in' && $io[1]->event_type === 'sign_out' && !property_exists($io[0], 'id'), 'liveInOut: event type and time only, oldest first');

// ---- the last device location: newest event with coordinates, raw strings, null when none
$loc = $S->lastDeviceLocation(7);
$check($loc === $db->get_row("SELECT latitude,longitude,location_timestamp FROM {$tl} WHERE employee_id=7 AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY event_at DESC LIMIT 1", ARRAY_A) && is_array($loc) && $loc['location_timestamp'] === '100', 'lastDeviceLocation: the old SQL\'s array (newest event_at wins, even a superseded row)');
$check($S->lastDeviceLocation(8) === null && $S->lastDeviceLocation(99) === null, 'lastDeviceLocation: null when there is none');

// ---- the time-log table is the name it was given, whatever the prefix says (the old code fixed it when the plugin object was built)
$fixed = new WordPressTimeLogStore($db, $other . 'ews_time_logs');
$check($fixed->liveDay(7, '2026-10-29') === [], 'time-log table: the given name is used, not the prefix');
$default = new WordPressTimeLogStore($db);
$check(count($default->liveDay(7, '2026-10-29')) === 2, 'time-log table: the prefix\'s when no name is given');

// ---- breaks follow the prefix
$db->insert($bs, ['employee_id' => 7, 'work_date' => '2026-10-29', 'start_at' => '2026-10-29 10:00:00', 'end_at' => '2026-10-29 10:15:00', 'actual_minutes' => 15, 'status' => 'Closed']);
$db->insert($bs, ['employee_id' => 7, 'work_date' => '2026-10-29', 'start_at' => '2026-10-29 12:00:00', 'status' => 'Open']);
$open2 = (int) $db->insert_id;
$db->insert($bs, ['employee_id' => 7, 'work_date' => '2026-10-29', 'start_at' => '2026-10-29 14:00:00', 'status' => 'Open']);
$open3 = (int) $db->insert_id;
$B = new WordPressBreakStore($db);
$rows = $B->sessionsOn(7, '2026-10-29');
$check($rows == (array) $db->get_results($db->prepare("SELECT * FROM {$bs} WHERE employee_id=%d AND work_date=%s ORDER BY id ASC", 7, '2026-10-29')) && count($rows) === 3, 'sessionsOn: the old SQL\'s rows, oldest id first');
$check($B->sessionsOn(8, '2026-10-29') === [], 'sessionsOn: an array, empty when none');
$o = $B->openOn(7, '2026-10-29');
$check(is_object($o) && (int) $o->id === $open3 && $o->status === 'Open', 'openOn: the newest open break');
$check($B->openOn(7, '2026-10-28') === null && $B->openOn(8, '2026-10-29') === null, 'openOn: null when none');
$moved = clone $wpdb;
$moved->prefix = $other;
$check((new WordPressBreakStore($moved))->sessionsOn(7, '2026-10-29') === [], 'break table: built from the prefix it is given (a switched site has its own)');
echo "attendance store contract OK\n";
