<?php
/**
 * The Attendance Stores on the real database (3.31.136; docs/tasks/attendance-store.md §6): real tables with their own prefix (so the
 * table-name rules show), dropped at the end, so nothing of the site is touched. Each method's result is compared with what the old
 * inline SQL gave. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\Attendance\Data\WordPressAutoRuleStore;
use WorkforceOne\Attendance\Data\WordPressBreakStore;
use WorkforceOne\Attendance\Data\WordPressEmployeeLock;
use WorkforceOne\Attendance\Data\WordPressTimeLogStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = 'att_contract_';
$other = 'att_contract_other_';
$tables = ['ews_time_logs', 'ews_break_sessions', 'ews_auto_attendance_rules'];
foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $other, $tables) { foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}"); });
foreach ([$prefix, $other] as $px) {
    $wpdb->query("CREATE TABLE {$px}ews_time_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, event_type VARCHAR(30) NOT NULL, event_at DATETIME NOT NULL, scheduled_status VARCHAR(40) NOT NULL DEFAULT '', latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, location_timestamp BIGINT NULL, corrects_id BIGINT UNSIGNED NULL, photo_key VARCHAR(190) NULL, PRIMARY KEY (id))");
    $wpdb->query("CREATE TABLE {$px}ews_auto_attendance_rules (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, recurrence VARCHAR(20) NOT NULL DEFAULT 'one_time', run_date DATE NULL, weekdays VARCHAR(20) NOT NULL DEFAULT '', sign_in_time VARCHAR(5) NULL, sign_out_time VARCHAR(5) NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, created_by BIGINT UNSIGNED NULL, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (id))");
    $wpdb->query("CREATE TABLE {$px}ews_break_sessions (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NULL, actual_minutes INT UNSIGNED NULL, status VARCHAR(20) NOT NULL DEFAULT 'Open', duration_notified TINYINT(1) NOT NULL DEFAULT 0, escalation_notified TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NULL, PRIMARY KEY (id))");
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

// ---- the writes (3.31.137): the ids, the failures, the first row, the delete, the photo
$id1 = $S->insert(['employee_id' => 9, 'user_id' => 3, 'work_date' => '2026-10-30', 'event_type' => 'sign_in', 'event_at' => '2026-10-30 08:00:00']);
$check($id1 > 0 && $S->lastInsertId() === $id1 && $S->lastError() === '', 'insert: the new id, the same one lastInsertId gives, no error');
$quiet = $db->suppress_errors(true);
$bad = $S->insert(['employee_id' => 9, 'no_such_column' => 1]);
$db->suppress_errors($quiet);
$check($bad === 0 && $S->lastError() !== '', 'insert: 0 on failure, with the database error');
$id2 = $S->insert(['employee_id' => 9, 'user_id' => 3, 'work_date' => '2026-10-30', 'event_type' => 'late_sign_in', 'event_at' => '2026-10-30 08:00:01']);
$check($S->firstEventId(9, '2026-10-30', ['sign_in', 'late_sign_in']) === $id1 && $S->firstEventId(9, '2026-10-30', ['sign_out']) === 0 && $S->firstEventId(8, '2026-10-30', ['sign_in']) === 0, 'firstEventId: the lowest id of the types, 0 when none');
$S->delete($id2);
$check($S->firstEventId(9, '2026-10-30', ['late_sign_in']) === 0 && $S->lastInsertId() === $id2, 'delete: the row is gone and insert_id is still the last insert');
$S->setPhoto($id1, 'photo-key');
$check($db->get_var("SELECT photo_key FROM {$tl} WHERE id={$id1}") === 'photo-key', 'setPhoto: the key is kept with the row');
$b1 = $B->insert(['employee_id' => 9, 'user_id' => 3, 'work_date' => '2026-10-30', 'start_at' => '2026-10-30 10:00:00', 'status' => 'Open', 'created_at' => '2026-10-30 10:00:00']);
$check($b1 > 0 && $B->firstOpenId(9, '2026-10-30') === $b1 && $B->firstOpenId(8, '2026-10-30') === 0, 'break insert and firstOpenId');
$quiet = $db->suppress_errors(true);
$badBreak = $B->insert(['employee_id' => 9, 'user_id' => 3, 'work_date' => '2026-10-30', 'start_at' => '2026-10-30 10:00:00', 'status' => 'Open', 'created_at' => '2026-10-30 10:00:00', 'no_such_column' => 'x']);
$db->suppress_errors($quiet);
$check($badBreak === 0, 'break insert: 0 on failure');
$check($B->close($b1, '2026-10-30 10:15:00', 15) === true && $B->close($b1, '2026-10-30 10:16:00', 16) === false, 'break close: true once, false when it was no longer open');
$row = $B->byId($b1);
$check($row->status === 'Completed' && $row->actual_minutes === '15' && $B->byId(99999) === null, 'break close: the end, the minutes and the status; byId null for none');
$b2 = $B->insert(['employee_id' => 9, 'user_id' => 3, 'work_date' => '2026-10-30', 'start_at' => '2026-10-30 12:00:00', 'status' => 'Open', 'created_at' => '2026-10-30 12:00:00']);
$B->markReminded($b2);
$B->markEscalated($b2);
$after = $B->byId($b2);
$check($after->duration_notified === '1' && $after->escalation_notified === '1' && $B->byId($b1)->duration_notified === '0', 'markReminded / markEscalated: the flags on that id only');
$B->delete($b2);
$check($B->byId($b2) === null, 'break delete');

// ---- the employee lock: the name carries the site, SQLite has no such lock
$lock = new WordPressEmployeeLock($db);
$sqlite = defined('DB_ENGINE') && DB_ENGINE === 'sqlite';
if ($sqlite) {
    $check($lock->lock(9) === false, 'lock: false on SQLite (the request goes on unlocked)');
} else {
    $check($lock->lock(9, 1) === true && $lock->lock(9, 1) === true, 'lock: held, and held again by the same connection');
    $lock->unlock(9);
    $lock->unlock(9);
    $name = 'wfo_att_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $prefix), 0, 12) . '_9';
    $check($db->get_var($db->prepare('SELECT IS_FREE_LOCK(%s)', $name)) === '1', 'lock: released, under the name wfo_att_<site hash>_<employee id>');
}

// ---- L5c (3.31.138): the automatic events, the bulk Sign Out, the manual records and the rules
$auto = $S->insertAuto(['employee_id' => 11, 'user_id' => 0, 'work_date' => '2026-10-30', 'event_type' => 'sign_in', 'event_at' => '2026-10-30 09:00:00', 'scheduled_status' => 'Office', 'ip_address' => '', 'latitude' => null, 'longitude' => null, 'accuracy' => null, 'location_status' => 'auto', 'distance_meters' => null, 'created_at' => '2026-10-30 09:00:01', 'source' => 'auto']);
$autoRow = $db->get_row("SELECT * FROM {$tl} WHERE id={$auto}", ARRAY_A);
$check($auto > 0 && $autoRow['latitude'] === null && $autoRow['location_status'] === 'auto' && $autoRow['user_id'] === '0', 'insertAuto: the id, NULL coordinates stay NULL');
$check($S->anyOfKinds(11, '2026-10-30', ['sign_in', 'late_sign_in']) === true && $S->anyOfKinds(11, '2026-10-30', ['sign_out']) === false && $S->anyOfKinds(12, '2026-10-30', ['sign_in']) === false, 'anyOfKinds: any row of the kinds, corrected ones too');
$S->insert(['employee_id' => 12, 'user_id' => 0, 'work_date' => '2026-10-30', 'event_type' => 'sign_in', 'event_at' => '2026-10-30 08:00:00']);
$sin12 = $S->lastInsertId();
$S->insert(['employee_id' => 12, 'user_id' => 0, 'work_date' => '2026-10-30', 'event_type' => 'sign_in', 'event_at' => '2026-10-30 08:30:00', 'corrects_id' => $sin12]);
$sin12b = $S->lastInsertId();
$S->insert(['employee_id' => 13, 'user_id' => 0, 'work_date' => '2026-10-30', 'event_type' => 'sign_in', 'event_at' => '2026-10-30 08:00:00']);
$sin13 = $S->lastInsertId();
$S->insert(['employee_id' => 13, 'user_id' => 0, 'work_date' => '2026-10-30', 'event_type' => 'sign_out', 'event_at' => '2026-10-30 16:00:00']);
$open = $S->openSignInsOn('2026-10-30');
$byEmp = [];
foreach ($open as $o) $byEmp[(int) $o->employee_id] = (int) $o->sign_in_id;
$check($byEmp[11] === $auto && $byEmp[12] === $sin12b && !isset($byEmp[13]) && !isset($byEmp[9]), 'openSignInsOn: the newest live Sign In of anyone without a Sign Out; a replaced row is not it');
$check($S->byId($sin13)->employee_id === '13' && $S->byId(999999) === null, 'byId: the whole row, null for none');
$check($S->signOutIdOn(13, '2026-10-30') > 0 && $S->signOutIdOn(11, '2026-10-30') === 0, 'signOutIdOn: the id, 0 for none');
$ie = $S->idEmployee($sin13);
$check($ie->employee_id === '13' && !property_exists($ie, 'event_type') && $S->idEmployee(999999) === null, 'idEmployee: id and employee only, null for none');
$others = $S->othersOn(12, '2026-10-30', $sin12b);
$check(count($others) === 0, 'othersOn: without the row itself, and the replaced row is hidden');
$others13 = $S->othersOn(13, '2026-10-30', $sin13);
$check(count($others13) === 1 && $others13[0]->event_type === 'sign_out' && !property_exists($others13[0], 'id'), 'othersOn: event type and time of the others');
$check($S->update($sin13, ['event_at' => '2026-10-30 08:15:00', 'scheduled_status' => 'WFH', 'latitude' => null]) === true && $db->get_var("SELECT event_at FROM {$tl} WHERE id={$sin13}") === '2026-10-30 08:15:00', 'update: the given columns, no formats');
$quiet = $db->suppress_errors(true);
$bad = $S->update($sin13, ['no_such_column' => 1]);
$db->suppress_errors($quiet);
$check($bad === false, 'update: false only on a database error');
$check($S->deleteDay(13, '2026-10-30') === 2 && $S->deleteDay(13, '2026-10-30') === 0, 'deleteDay: the number of rows, 0 the second time');
$bb1 = $B->insert(['employee_id' => 14, 'user_id' => 0, 'work_date' => '2026-10-30', 'start_at' => '2026-10-30 10:00:00', 'status' => 'Open', 'created_at' => '2026-10-30 10:00:00']);
$bb2 = $B->insert(['employee_id' => 14, 'user_id' => 0, 'work_date' => '2026-10-30', 'start_at' => '2026-10-30 12:00:00', 'status' => 'Open', 'created_at' => '2026-10-30 12:00:00']);
$check($B->idsOn(14, '2026-10-30') === [(string) $bb1, (string) $bb2] && $B->idsOn(15, '2026-10-30') === [] && $B->deleteDay(14, '2026-10-30') === 2 && $B->deleteDay(14, '2026-10-30') === 0, 'breaks: idsOn and deleteDay');

$rt = $prefix . 'ews_auto_attendance_rules';
$R = new WordPressAutoRuleStore($db);
$r1 = $R->insert(['employee_id' => 1, 'recurrence' => 'one_time', 'run_date' => '2026-10-30', 'weekdays' => '', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 1, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00', 'updated_at' => '2026-10-30 08:00:00']);
$r2 = $R->insert(['employee_id' => 2, 'recurrence' => 'weekly', 'run_date' => null, 'weekdays' => '1,2', 'sign_in_time' => '09:00', 'sign_out_time' => '17:00', 'enabled' => 1, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00', 'updated_at' => '2026-10-30 08:00:00']);
$r3 = $R->insert(['employee_id' => 3, 'recurrence' => 'one_time', 'run_date' => '2026-10-20', 'weekdays' => '', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 1, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00', 'updated_at' => '2026-10-30 08:00:00']);
$r4 = $R->insert(['employee_id' => 4, 'recurrence' => 'weekly', 'run_date' => null, 'weekdays' => '1', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 0, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00', 'updated_at' => '2026-10-30 08:00:00']);
$due = array_map(function ($r) { return (int) $r->id; }, $R->dueRules('2026-10-30', '2026-10-29'));
$check($r1 > 0 && $due === [$r1, $r2], 'dueRules: enabled weekly rules and one-time rules of today or yesterday, lowest id first');
$R->disable($r1, '2026-10-30 09:05:00');
$check($R->enabledOf($r1)->enabled === '0' && $R->enabledOf(999999) === null, 'disable and enabledOf');
$R->setEnabled($r1, 1, '2026-10-30 09:06:00');
$check($R->enabledOf($r1)->enabled === '1' && $db->get_var("SELECT updated_at FROM {$rt} WHERE id={$r1}") === '2026-10-30 09:06:00', 'setEnabled: the flag and the time');
$check($R->update($r2, ['sign_out_time' => '18:00']) === true && $R->update(999999, ['sign_out_time' => '18:00']) === true, 'update: true even when no row changed (only an error is false)');
$quiet = $db->suppress_errors(true);
$badRule = $R->insert(['employee_id' => 1, 'no_such_column' => 1]);
$db->suppress_errors($quiet);
$check($badRule === 0 && $R->lastError() !== '', 'insert: 0 on failure with the database error');
$check($R->remove($r3) === 1 && $R->remove($r3) === 0, 'remove: 1 then 0');
$other_px = clone $wpdb;
$other_px->prefix = $other;
$check((new WordPressAutoRuleStore($other_px))->dueRules('2026-10-30', '2026-10-29') === [], 'rules table: built from the prefix it is given');
echo "attendance store contract OK\n";
