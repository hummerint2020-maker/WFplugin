<?php
/**
 * The Attendance Stores on the real database (3.31.136; docs/tasks/attendance-store.md §6): real tables with their own prefix (so the
 * table-name rules show), dropped at the end, so nothing of the site is touched. Each method's result is compared with what the old
 * inline SQL gave. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\Attendance\Data\TimeReportFilter;
use WorkforceOne\Attendance\Data\WordPressAttemptStore;
use WorkforceOne\Attendance\Data\WordPressAutoRuleQuery;
use WorkforceOne\Attendance\Data\WordPressAutoRuleStore;
use WorkforceOne\Attendance\Data\WordPressTimeReportQuery;
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
$tables = ['ews_time_logs', 'ews_break_sessions', 'ews_auto_attendance_rules', 'ews_signin_attempts', 'ews_employees'];
foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $other, $tables) { foreach ([$prefix, $other] as $px) foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$px}{$t}"); });
foreach ([$prefix, $other] as $px) {
    $wpdb->query("CREATE TABLE {$px}ews_time_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, event_type VARCHAR(30) NOT NULL, event_at DATETIME NOT NULL, scheduled_status VARCHAR(40) NOT NULL DEFAULT '', ip_address VARCHAR(64) NULL, latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, accuracy DECIMAL(10,2) NULL, location_status VARCHAR(30) NULL, distance_meters DECIMAL(12,2) NULL, created_at DATETIME NULL, source VARCHAR(20) NULL, location_timestamp BIGINT NULL, corrects_id BIGINT UNSIGNED NULL, photo_key VARCHAR(190) NULL, correction_id BIGINT UNSIGNED NULL, PRIMARY KEY (id))");
    $wpdb->query("CREATE TABLE {$px}ews_signin_attempts (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, work_date DATE NOT NULL, event_type VARCHAR(20) NOT NULL, result VARCHAR(20) NOT NULL, reason VARCHAR(40) NOT NULL DEFAULT '', detail VARCHAR(255) NULL, attempted_at DATETIME NOT NULL, phone_time DATETIME NULL, clock_skew INT NULL, time_sure TINYINT(1) NOT NULL DEFAULT 1, reported_at DATETIME NULL, latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, accuracy FLOAT NULL, distance_meters FLOAT NULL, location_status VARCHAR(20) NULL, ip_address VARCHAR(64) NULL, device VARCHAR(190) NULL, source VARCHAR(20) NOT NULL DEFAULT 'app', photo_key VARCHAR(64) NULL, PRIMARY KEY (id))");
    $wpdb->query("CREATE TABLE {$px}ews_employees (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL DEFAULT '', domain_name VARCHAR(190) NOT NULL DEFAULT '', active TINYINT(1) NOT NULL DEFAULT 1, department_id BIGINT UNSIGNED NULL, PRIMARY KEY (id))");
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
$seen = [];
$capture = static function ($q) use (&$seen) { $seen[] = trim(preg_replace('/\s+/', ' ', $q)); return $q; };
add_filter('query', $capture, 1);
$S->firstEventId(11, '2026-10-30', ['sign_out']);
$S->firstEventId(11, '2026-10-30', ['sign_in', 'late_sign_in']);
$S->anyOfKinds(11, '2026-10-30', ['sign_in', 'late_sign_in']);
$S->anyOfKinds(11, '2026-10-30', ['sign_out']);
remove_filter('query', $capture, 1);
$check($seen === [
    "SELECT MIN(id) FROM {$tl} WHERE employee_id=11 AND work_date='2026-10-30' AND event_type IN ('sign_out')",
    "SELECT MIN(id) FROM {$tl} WHERE employee_id=11 AND work_date='2026-10-30' AND event_type IN ('sign_in','late_sign_in')",
    "SELECT id FROM {$tl} WHERE employee_id=11 AND work_date='2026-10-30' AND event_type IN ('sign_in','late_sign_in') LIMIT 1",
    "SELECT id FROM {$tl} WHERE employee_id=11 AND work_date='2026-10-30' AND event_type IN ('sign_out') LIMIT 1",
], 'firstEventId / anyOfKinds: the prepared IN gives the same text as the old literal IN (sign_in,late_sign_in without a space)');
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
$check($byEmp[11] === $auto && $byEmp[12] === $sin12b && !isset($byEmp[13]) && $byEmp[9] === $id1, 'openSignInsOn: the newest live Sign In of anyone without a Sign Out; a replaced row is not it');
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
// ---- L5d (3.31.139): the sign-in attempts, the time-log reads of the evidence, the Sign In / Out report's read model and the rules page's list
$at = $prefix . 'ews_signin_attempts';
$em = $prefix . 'ews_employees';
$AT = new WordPressAttemptStore($db);
$seen = [];
add_filter('query', $capture, 1);
$AT->exists();
$AT->refusalsOn(7, '2026-10-30');
$AT->typeBetween(7, 'sign_in', '2026-10-30 08:00:00', '2026-10-30 09:00:00');
$AT->notReachedSince(7, '2026-10-30 00:00:00');
$AT->photoOf(5);
$AT->day(7, '2026-10-30');
$AT->photoKeysBefore('2026-09-01 00:00:00', '2026-10-01 00:00:00');
$AT->clearPhotosBefore('2026-10-01 00:00:00');
$AT->deleteBefore('2026-09-01 00:00:00');
$S->kindsBetween(7, ['sign_in', 'late_sign_in'], '2026-10-30 08:00:00', '2026-10-30 09:00:00');
$S->kindsBetween(7, ['sign_out'], '2026-10-30 08:00:00', '2026-10-30 09:00:00');
$S->photoOf(5);
$S->allOn(7, '2026-10-30');
$S->photoKeysBefore('2026-10-01 00:00:00');
$S->clearPhotosBefore('2026-10-01 00:00:00');
$S->find(5);
remove_filter('query', $capture, 1);
$check($seen === [
    "SHOW TABLES LIKE '{$at}'",
    "SELECT COUNT(*) FROM {$at} WHERE employee_id=7 AND work_date='2026-10-30' AND result IN ('refused','face_failed','not_reached')",
    "SELECT id FROM {$at} WHERE employee_id=7 AND event_type='sign_in' AND attempted_at BETWEEN '2026-10-30 08:00:00' AND '2026-10-30 09:00:00' LIMIT 1",
    "SELECT COUNT(*) FROM {$at} WHERE employee_id=7 AND result='not_reached' AND reported_at>='2026-10-30 00:00:00'",
    "SELECT employee_id,photo_key,attempted_at at FROM {$at} WHERE id=5",
    "SELECT * FROM {$at} WHERE employee_id=7 AND work_date='2026-10-30'",
    "SELECT photo_key FROM {$at} WHERE photo_key IS NOT NULL AND (attempted_at<'2026-09-01 00:00:00' OR attempted_at<'2026-10-01 00:00:00')",
    "UPDATE {$at} SET photo_key=NULL WHERE photo_key IS NOT NULL AND attempted_at<'2026-10-01 00:00:00'",
    "DELETE FROM {$at} WHERE attempted_at<'2026-09-01 00:00:00'",
    "SELECT id FROM {$tl} WHERE employee_id=7 AND event_type IN ('sign_in','late_sign_in') AND event_at BETWEEN '2026-10-30 08:00:00' AND '2026-10-30 09:00:00' LIMIT 1",
    "SELECT id FROM {$tl} WHERE employee_id=7 AND event_type IN ('sign_out') AND event_at BETWEEN '2026-10-30 08:00:00' AND '2026-10-30 09:00:00' LIMIT 1",
    "SELECT employee_id,photo_key,event_at at FROM {$tl} WHERE id=5",
    "SELECT * FROM {$tl} WHERE employee_id=7 AND work_date='2026-10-30'",
    "SELECT photo_key FROM {$tl} WHERE photo_key IS NOT NULL AND event_at<'2026-10-01 00:00:00'",
    "UPDATE {$tl} SET photo_key=NULL WHERE photo_key IS NOT NULL AND event_at<'2026-10-01 00:00:00'",
    "SELECT * FROM {$tl} WHERE id=5",
], 'attempts and the evidence reads of the time logs: the exact text of the old inline SQL');
$check($AT->exists() === true && (new WordPressAttemptStore((function () use ($wpdb) { $x = clone $wpdb; $x->prefix = 'att_contract_nope_'; return $x; })()))->exists() === false, 'attempts: exists() by the prefix it is given');
$quiet = $db->suppress_errors(true);
$AT->insert(['employee_id' => 7, 'no_such_column' => 1]);
$db->suppress_errors($quiet);
$a1 = $AT->insert(['employee_id' => 21, 'user_id' => 0, 'work_date' => '2026-11-02', 'event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_early', 'attempted_at' => '2026-11-02 07:00:00', 'ip_address' => '10.0.0.1', 'device' => 'Pixel', 'source' => 'app']);
$a2 = $AT->insert(['employee_id' => 22, 'user_id' => 0, 'work_date' => '2026-11-02', 'event_type' => 'face', 'result' => 'face_failed', 'reason' => 'face_mismatch', 'attempted_at' => '2026-11-02 08:00:00', 'photo_key' => str_repeat('a', 32) . '.jpg', 'ip_address' => '', 'device' => '']);
$a3 = $AT->insert(['employee_id' => 22, 'user_id' => 0, 'work_date' => '2026-11-02', 'event_type' => 'face', 'result' => 'face_ok', 'reason' => 'face_ok', 'attempted_at' => '2026-11-02 08:05:00', 'ip_address' => '', 'device' => '']);
$a4 = $AT->insert(['employee_id' => 22, 'user_id' => 0, 'work_date' => '2026-11-01', 'event_type' => 'sign_out', 'result' => 'not_reached', 'reason' => 'no_network', 'attempted_at' => '2026-11-01 17:00:00', 'reported_at' => '2026-11-02 09:00:00', 'phone_time' => null, 'clock_skew' => 0, 'time_sure' => 0, 'latitude' => null, 'ip_address' => '', 'device' => '']);
$row = $db->get_row("SELECT * FROM {$at} WHERE id={$a1}", ARRAY_A);
$check($a1 > 0 && $row['latitude'] === null && $row['photo_key'] === null && $row['detail'] === null && $row['time_sure'] === '1', 'attempts insert: the id; what was not given stays NULL or its default');
$check($AT->refusalsOn(22, '2026-11-02') === 1 && $AT->refusalsOn(22, '2026-11-01') === 1 && $AT->refusalsOn(21, '2026-11-02') === 1 && $AT->refusalsOn(99, '2026-11-02') === 0, 'refusalsOn: refused, face failed and unsent count; a matched face check does not');
$check($AT->typeBetween(21, 'sign_in', '2026-11-02 06:50:00', '2026-11-02 07:10:00') === true && $AT->typeBetween(21, 'sign_out', '2026-11-02 06:50:00', '2026-11-02 07:10:00') === false && $AT->typeBetween(21, 'sign_in', '2026-11-02 07:01:00', '2026-11-02 07:10:00') === false, 'typeBetween');
$check($AT->notReachedSince(22, '2026-11-02 00:00:00') === 1 && $AT->notReachedSince(22, '2026-11-03 00:00:00') === 0, 'notReachedSince: by the time it was reported');
$check($AT->photoOf($a2)->photo_key === str_repeat('a', 32) . '.jpg' && $AT->photoOf($a2)->at === '2026-11-02 08:00:00' && (int) $AT->photoOf($a2)->employee_id === 22 && $AT->photoOf(999999) === null, 'photoOf: employee, key and time; null when none');
$check(count($AT->day(22, '2026-11-02')) === 2 && $AT->day(22, '2026-11-03') === [], 'day: every attempt of the day, an array');
$check($AT->photoKeysBefore('2026-11-01 00:00:00', '2026-11-03 00:00:00') === [str_repeat('a', 32) . '.jpg'] && $AT->photoKeysBefore('2026-11-01 00:00:00', '2026-11-01 00:00:00') === [], 'photoKeysBefore: the keys older than either time');
$AT->clearPhotosBefore('2026-11-03 00:00:00');
$check($AT->photoOf($a2)->photo_key === null, 'clearPhotosBefore: the key is cleared, the row stays');
$gone = $AT->insert(['employee_id' => 30, 'user_id' => 0, 'work_date' => '2026-10-01', 'event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_late', 'attempted_at' => '2026-10-01 08:00:00', 'ip_address' => '', 'device' => '']);
$check($AT->photoOf($gone) !== null, 'attempts: an old row to delete');
$AT->deleteBefore('2026-10-15 00:00:00');
$check($AT->photoOf($gone) === null && $AT->photoOf($a2) !== null, 'deleteBefore: older rows go, newer stay');

// the time-log reads (the photo key an earlier check left is cleared first)
$db->query("UPDATE {$tl} SET photo_key=NULL");
$l1 = $add(21, '2026-11-02', 'sign_in', '2026-11-02 08:00:00', ['photo_key' => str_repeat('b', 32) . '.jpg', 'source' => 'app']);
$l2 = $add(22, '2026-11-02', 'sign_in', '2026-11-02 08:10:00');
$l3 = $add(22, '2026-11-02', 'sign_in', '2026-11-02 08:30:00', ['corrects_id' => $l2, 'correction_id' => 9, 'source' => 'correction']);
$check($S->kindsBetween(21, ['sign_in', 'late_sign_in'], '2026-11-02 07:50:00', '2026-11-02 08:10:00') === true && $S->kindsBetween(21, ['sign_out'], '2026-11-02 07:50:00', '2026-11-02 08:10:00') === false && $S->kindsBetween(22, ['sign_in'], '2026-11-02 08:05:00', '2026-11-02 08:15:00') === true, 'kindsBetween: any row of the kinds in the window, a corrected one too');
$check($S->photoOf($l1)->photo_key === str_repeat('b', 32) . '.jpg' && $S->photoOf($l1)->at === '2026-11-02 08:00:00' && $S->photoOf(999999) === null, 'photoOf (time logs)');
$check(count($S->allOn(22, '2026-11-02')) === 2 && $S->allOn(22, '2026-11-09') === [], 'allOn: every row of the day, corrected ones too');
$check($S->photoKeysBefore('2026-11-03 00:00:00') === [str_repeat('b', 32) . '.jpg'] && $S->photoKeysBefore('2026-11-02 00:00:00') === [], 'photoKeysBefore (time logs)');
$S->clearPhotosBefore('2026-11-03 00:00:00');
$check($S->photoOf($l1)->photo_key === null && $S->find($l1)->id == $l1 && $S->find(999999) === null, 'clearPhotosBefore (time logs) and find');

// the Sign In / Out report's read model
$db->insert($em, ['id' => 21, 'name' => 'Omar One', 'domain_name' => 'omar1', 'active' => 1, 'department_id' => 5]);
$db->insert($em, ['id' => 22, 'name' => 'Lina Two', 'domain_name' => 'lina2', 'active' => 1, 'department_id' => 6]);
$db->insert($em, ['id' => 23, 'name' => 'Old Three', 'domain_name' => 'old3', 'active' => 0, 'department_id' => 5]);
$Q = new WordPressTimeReportQuery($db, $tl, $em);
$seen = [];
add_filter('query', $capture, 1);
$Q->count(new TimeReportFilter('2026-10-29', '2026-10-30', '', 'both', true, null));
$Q->count(new TimeReportFilter('2026-10-29', '2026-10-30', '', 'recorded', false, 5));
$Q->pageKeys(new TimeReportFilter('2026-10-29', '2026-10-30', '', 'refused', true, 0), 100, 200);
$Q->refusedTotal(new TimeReportFilter('2026-10-29', '2026-10-30', '', 'both', true, null));
$Q->refusedBy(new TimeReportFilter('2026-10-29', '2026-10-30', '', 'both', true, 5));
$Q->employees(23, 5);
$Q->csv('2026-10-29', '2026-10-30', null);
remove_filter('query', $capture, 1);
$lj = "FROM {$tl} l LEFT JOIN {$em} e ON e.id=l.employee_id";
$aj = "FROM {$at} a LEFT JOIN {$em} e ON e.id=a.employee_id";
$check($seen === [
    "SELECT COUNT(*) FROM (SELECT 'log' kind,l.id id,l.work_date d,l.event_at t {$lj} WHERE l.work_date BETWEEN '2026-10-29' AND '2026-10-30' UNION ALL SELECT 'attempt' kind,a.id id,a.work_date d,a.attempted_at t {$aj} WHERE a.work_date BETWEEN '2026-10-29' AND '2026-10-30' AND a.result<>'face_ok') x",
    "SELECT COUNT(*) FROM (SELECT 'log' kind,l.id id,l.work_date d,l.event_at t {$lj} WHERE l.work_date BETWEEN '2026-10-29' AND '2026-10-30' AND e.department_id=5) x",
    "SELECT kind,id FROM (SELECT 'attempt' kind,a.id id,a.work_date d,a.attempted_at t {$aj} WHERE a.work_date BETWEEN '2026-10-29' AND '2026-10-30' AND a.result<>'face_ok' AND 1=0) x ORDER BY d DESC,t DESC,id DESC LIMIT 100 OFFSET 200",
    "SELECT COUNT(*) FROM {$at} a LEFT JOIN {$em} e ON e.id=a.employee_id WHERE a.work_date BETWEEN '2026-10-29' AND '2026-10-30' AND a.result<>'face_ok' AND a.result IN ('refused','face_failed','not_reached')",
    "SELECT a.employee_id,e.name,COUNT(*) n FROM {$at} a LEFT JOIN {$em} e ON e.id=a.employee_id WHERE a.work_date BETWEEN '2026-10-29' AND '2026-10-30' AND a.result<>'face_ok' AND e.department_id=5 AND a.result IN ('refused','face_failed','not_reached') GROUP BY a.employee_id,e.name ORDER BY n DESC,e.name ASC LIMIT 6",
    "SELECT e.id,e.name,e.domain_name FROM {$em} e WHERE (e.active=1 OR e.id=23) AND e.department_id=5 ORDER BY e.name ASC",
    "SELECT l.*,e.name,e.domain_name,(SELECT MIN(c.correction_id) FROM {$tl} c WHERE c.corrects_id=l.id) replaced_by {$lj} WHERE l.work_date BETWEEN '2026-10-29' AND '2026-10-30' ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC",
], 'report read model: the exact text of the old inline SQL (union, scope, paging, summary, picker, CSV)');
$day = '2026-11-02';
$F = function ($show, $search = '', $scope = null, $attempts = true) use ($day) { return new TimeReportFilter($day, $day, $search, $show, $attempts, $scope); };
$check($Q->count($F('both')) === 5 && $Q->count($F('recorded')) === 3 && $Q->count($F('refused')) === 2 && $Q->count($F('recorded', '', null, false)) === 3, 'report count: records, refusals (a matched face check is not one) and both');
$check($Q->count($F('both', 'lina')) === 3 && $Q->count($F('both', 'omar1')) === 2 && $Q->count($F('both', '', 5)) === 2 && $Q->count($F('both', '', 6)) === 3 && $Q->count($F('both', '', 0)) === 0 && $Q->count($F('both', 'no one')) === 0, 'report count: a search by name or domain, a department, nobody');
$keys = array_map(function ($k) { return $k->kind . $k->id; }, $Q->pageKeys($F('both'), 100, 0));
$check($keys === ['log' . $l3, 'log' . $l2, 'log' . $l1, 'attempt' . $a2, 'attempt' . $a1], 'report page: newest first (day, time, then the larger id: the record before the attempt at 08:00)');
$check(count($Q->pageKeys($F('both'), 2, 0)) === 2 && count($Q->pageKeys($F('both'), 2, 4)) === 1 && $Q->pageKeys($F('both'), 2, 10) === [], 'report page: the page size and the offset');
$rows = $Q->logRows([$l1, $l2]);
$ks = array_keys($rows);
sort($ks);
$check($ks === [$l1, $l2] && $rows[$l1]->name === 'Omar One' && (int) $rows[$l2]->replaced_by === 9 && $rows[$l1]->replaced_by === null, 'logRows: by id, with the employee and the correction that replaced it');
$ar = $Q->attemptRows([$a1, $a2]);
$check(isset($ar[$a1], $ar[$a2]) && $ar[$a2]->name === 'Lina Two' && $ar[$a1]->domain_name === 'omar1', 'attemptRows: by id, with the employee');
$by = $Q->refusedBy($F('both'));
$check(count($by) === 2 && $Q->refusedTotal($F('both')) === 2 && (int) $by[0]->n === 1, 'refusedBy / refusedTotal: refused, face failed and unsent in the period');
$ids = array_map(function ($e) { return (int) $e->id; }, $Q->employees(0, null));
$check(in_array(21, $ids, true) && in_array(22, $ids, true) && !in_array(23, $ids, true), 'employees: the active ones');
$check(array_map(function ($e) { return (int) $e->id; }, $Q->employees(23, 5)) === [23, 21] && array_map(function ($e) { return (int) $e->id; }, $Q->employees(0, 6)) === [22] && $Q->employees(0, 0) === [], 'employees: the one asked for too, by department, nobody');
$csv = $Q->csv($day, $day, 5);
$check(count($csv) === 1 && $csv[0]->name === 'Omar One' && count($Q->csv($day, $day, null)) === 3 && $Q->csv($day, $day, 0) === [], 'csv: the records of the period in scope with the employee');

// the rules page's list
$rq = new WordPressAutoRuleQuery($db, $em);
$ra = $R->insert(['employee_id' => 21, 'recurrence' => 'weekly', 'run_date' => null, 'weekdays' => '1', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 0, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00']);
$rb = $R->insert(['employee_id' => 22, 'recurrence' => 'weekly', 'run_date' => null, 'weekdays' => '2', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 1, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00']);
$rc = $R->insert(['employee_id' => 999, 'recurrence' => 'weekly', 'run_date' => null, 'weekdays' => '3', 'sign_in_time' => '09:00', 'sign_out_time' => null, 'enabled' => 1, 'created_by' => 3, 'created_at' => '2026-10-30 08:00:00']);
$list = $rq->listWithNames();
$mine = array_values(array_filter($list, function ($r) use ($ra, $rb, $rc) { return in_array((int) $r->id, [$ra, $rb, $rc], true); }));
$check(count($mine) === 2 && (int) $mine[0]->id === $rb && (int) $mine[1]->id === $ra && $mine[0]->name === 'Lina Two' && $mine[1]->domain_name === 'omar1', 'rules list: switched-on first, then newest; a rule of an employee that is gone is not listed');
$check($R->find($ra)->employee_id === '21' && $R->find(999999) === null, 'AutoRuleStore::find');
echo "attendance store contract OK\n";
