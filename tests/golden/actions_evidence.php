<?php
/**
 * The golden harness's sign-in evidence and time report trace (tests/golden/run.py; docs/tasks/attendance-evidence.md §5, L5d): keeping an attempt (off, refused,
 * face check ok, sent late, a failed insert, the alert at exactly N, the department's managers or the administrators), the offline report (every answer), the
 * photo serve (every refusal and the audited full view), the daily cleanup (rows, keys and files, with faults), one employee's day timeline, the Sign In / Out
 * report page (admin and manager, every filter, the edit and add forms, attempts off, the table missing, a failed count) and the Auto Attendance page, driven
 * in process through their own functions (tests/golden/exit.php). For each call: what it returned, the exact SQL (in order, dates and times as placeholders),
 * the query count; at the end the attempts, the audit, the notifications and the photo files. The same script runs on the old and the new code from the same
 * snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_evidence.php   (prints one JSON line, after anything a photo serve printed)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';

$admin = (int) get_user_by('login', 'admin')->ID;
$W = function ($k) use ($c) { return (int) $c['atw_' . $k]; };
$WU = function ($k) use ($c) { return (int) $c['atwu_' . $k]; };
$E = function ($k) use ($c) { return (int) $c['atr_' . $k]; };
$EU = function ($k) use ($c) { return (int) $c['atru_' . $k]; };
$emp = function ($id) use ($call) { return $call('people_employee', (int) $id); };
$nowTs = (int) current_time('timestamp');
$localNow = function ($offsetSeconds = 0) use ($nowTs) { return gmdate('Y-m-d H:i:s', $nowTs + $offsetSeconds); };
$cfg = function (array $o) { update_option('ews_signin_evidence', array_merge(['attempts' => 1, 'photo' => 0, 'alert' => 0, 'attempt_days' => 60, 'photo_days' => 30, 'photo_viewers' => 'hr_manager'], $o), false); };
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14; Golden) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36';
update_option('ews_feature_face_signin', 1, false);

// ---------------------------------------------------------------- photo files (the snapshot restores the database, not the files)
$dir = $call('private_files_dir', 'workforce-one-face-photos');
$files = function () use ($dir) {
    foreach (glob($dir . '/*.{jpg,webp}', GLOB_BRACE) as $f) unlink($f);
    foreach (['a', 'b', 'c', 'g'] as $ch) file_put_contents($dir . '/' . str_repeat($ch, 32) . '.jpg', 'GOLDENPHOTO');
    file_put_contents($dir . '/' . str_repeat('d', 32) . '.jpg', 'GOLDENPHOTO');   // nothing points to it, and it is old
    touch($dir . '/' . str_repeat('d', 32) . '.jpg', time() - 100 * DAY_IN_SECONDS);
    file_put_contents($dir . '/' . str_repeat('e', 32) . '.jpg', 'GOLDENPHOTO');   // nothing points to it, and it is new
};
$listing = function () use ($dir) { $l = array_map('basename', glob($dir . '/*.{jpg,webp}', GLOB_BRACE)); sort($l); return array_map(function ($n) { return substr($n, 0, 2) . '..' . substr($n, -4); }, $l); };
$files();
$attId = function ($key) use ($wpdb, $p) { return (int) $wpdb->get_var("SELECT id FROM {$p}ews_signin_attempts WHERE photo_key='" . str_repeat($key, 32) . ".jpg' ORDER BY id LIMIT 1"); };
$logId = function ($key) use ($wpdb, $p) { return (int) $wpdb->get_var("SELECT id FROM {$p}ews_time_logs WHERE photo_key='" . str_repeat($key, 32) . ".jpg' ORDER BY id LIMIT 1"); };
$attemptRow = function ($eid, $type, $result, $reason, $when, array $extra = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_signin_attempts', array_merge(['employee_id' => $eid, 'user_id' => 0, 'work_date' => substr($when, 0, 10), 'event_type' => $type, 'result' => $result, 'reason' => $reason, 'attempted_at' => $when, 'ip_address' => '10.0.0.8', 'device' => 'Golden phone', 'source' => 'app'], $extra));
    return (int) $wpdb->insert_id;
};
$logRow = function ($eid, $type, $when, array $extra = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_time_logs', array_merge(['employee_id' => $eid, 'user_id' => 0, 'work_date' => substr($when, 0, 10), 'event_type' => $type, 'event_at' => $when, 'scheduled_status' => 'Office', 'ip_address' => ''], $extra));
    return (int) $wpdb->insert_id;
};
$page = function ($label, $uid, $method, array $get) use (&$trace, &$sql, $o, $as, $norm, $admin) {
    $as($uid);
    wp_cache_flush();
    $sql = [];
    $r = golden_page($o, $method, $get);
    $html = strtr((string) $r['html'], $dates);   // not $norm(): it cuts everything from the first <div (a database error's markup) on
    $html = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', $html);
    $html = preg_replace('/(name="_wpnonce"[^>]*value=")[0-9a-f]+/', '$1N', $html);
    $html = preg_replace('/(nonce["\']?\s*[:=]\s*["\'])[0-9a-f]{8,}/i', '$1N', $html);
    $html = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', 'T', $html);
    $html = preg_replace('/\b\d{1,2}:\d{2} ?[AP]M\b/', 'HM', $html);
    $html = preg_replace('/\b\d{2}:\d{2}\b/', 'HM', $html);
    $html = preg_replace('#https?://[^/"\']+#', '', $html);
    $trace[$label] = ['return' => ['exit' => $r['exit'], 'to' => $norm($r['to']), 'len' => strlen($html), 'html' => md5($html)], 'queries' => count($sql), 'sql' => $sql];
    $as($admin);
};
$as($admin);

// ---------------------------------------------------------------- keeping an attempt
$cfg(['attempts' => 0]);
$run('keep_off', function () use ($call, $emp, $W) { return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_early']); });
$cfg([]);
$run('keep_no_employee', function () use ($call) { return $call('evidence_keep', null, ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_early']); });
$as($WU('a1'));
$run('keep_refused', function () use ($call, $emp, $W) {
    return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'outside_location', 'detail' => str_repeat('x', 300), 'latitude' => 30.1234567, 'longitude' => 31.2, 'accuracy' => 12.5, 'distance_meters' => 850.5, 'location_status' => 'outside', 'source' => 'qr', 'photo_key' => str_repeat('f', 32) . '.jpg']);
});
$run('keep_face_ok', function () use ($call, $emp, $W) { return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'face', 'result' => 'face_ok', 'reason' => 'face_ok']); });
$run('keep_face_failed', function () use ($call, $emp, $W) { return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'face', 'result' => 'face_failed', 'reason' => 'face_mismatch', 'detail' => 'zero values stay', 'accuracy' => 0, 'time_sure' => 0]); });
$run('keep_not_reached', function () use ($call, $emp, $W, $D0) {
    return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'sign_out', 'result' => 'not_reached', 'reason' => 'no_network', 'attempted_at' => $D0 . ' 08:00:00', 'phone_time' => $D0 . ' 08:09:00', 'clock_skew' => 540, 'time_sure' => 0, 'reported_at' => $D0 . ' 09:00:00', 'source' => 'offline']);
});
$faulty('keep_insert_fault', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_signin_attempts/i', function () use ($call, $emp, $W) { return $call('evidence_keep', $emp($W('a1')), ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_late']); });
$as($admin);
// the alert: told once, when the day's refusals reach exactly N
$cfg(['alert' => 2]);
$refuse = function ($eid) use ($call, $emp) { return $call('evidence_keep', $emp($eid), ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_early']); };
$run('alert_first', function () use ($refuse, $E) { return $refuse($E('r13')); });
$run('alert_second_tells_the_manager', function () use ($refuse, $E) { return $refuse($E('r13')); });
$run('alert_third_is_quiet', function () use ($refuse, $E) { return $refuse($E('r13')); });
$cfg(['alert' => 1]);
$run('alert_no_manager_tells_the_administrators', function () use ($refuse, $E) { return $refuse($E('other')); });
$run('alert_the_manager_is_the_employee', function () use ($refuse, $E) { return $refuse($E('mgr')); });
$cfg(['alert' => 0]);
$run('alert_never_when_zero', function () use ($refuse, $E) { return $refuse($E('r1')); });
// a refused Sign In reaching the evidence from record_time_event (with the Face photo held for the user)
$cfg([]);
$as($WU('a2'));
set_transient('ews_face_photo_' . $WU('a2'), str_repeat('g', 32) . '.jpg', 120);
$run('refused_with_photo', function () use ($call) {
    return $call('evidence_refused', 'sign_in', (object) ['latitude' => 30.5, 'longitude' => 31.5, 'accuracy' => 20, 'qrKiosk' => false], (object) ['code' => 'outside_location', 'details' => ['distance' => 420]], '<b>You are outside</b> the work location');
});
$run('refused_without_photo_by_qr', function () use ($call) {
    return $call('evidence_refused', 'sign_out', (object) ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'qrKiosk' => true], (object) ['code' => 'outside_kiosk', 'details' => []], 'Not at the kiosk');
});
$cfg(['attempts' => 0]);
$run('refused_off', function () use ($call) { return $call('evidence_refused', 'sign_in', (object) ['latitude' => null, 'longitude' => null, 'accuracy' => null, 'qrKiosk' => false], (object) ['code' => 'too_early', 'details' => []], 'Too early'); });
$cfg([]);
$as($admin);

// ---------------------------------------------------------------- the offline report (an attempt the phone could not send)
$offline = function ($label, $uid, array $post, $nonce = 'ews_signin_offline') use ($run, $o, $as, $admin) {
    $as($uid);
    $run($label, function () use ($o, $post, $nonce) { return golden_json($o, 'evidence_offline_report', $post, $nonce); });
    $as($admin);
};
$body = function ($uid, array $over = []) {
    $srv = (time() - 120) * 1000;
    return array_merge(['user' => $uid, 'event_type' => 'sign_in', 'server_ms' => $srv, 'elapsed_ms' => 60000, 'phone_ms' => $srv + 60000 + 300000, 'latitude' => '30.0444', 'longitude' => '31.2357', 'accuracy' => '15'], $over);
};
$offline('offline_logged_out', 0, $body(0));
$offline('offline_bad_nonce', $WU('a3'), $body($WU('a3')), 'bad');
$cfg(['attempts' => 0]);
$offline('offline_off', $WU('a3'), $body($WU('a3')));
$cfg([]);
$offline('offline_other_account', $WU('a4'), $body(999999));
$offline('offline_no_employee', (int) $c['atwu_ghost'], $body((int) $c['atwu_ghost']));
$offline('offline_bad_type', $WU('a5'), $body($WU('a5'), ['event_type' => 'face']));
$offline('offline_old', $WU('a6'), $body($WU('a6'), ['server_ms' => (time() - 10 * DAY_IN_SECONDS) * 1000]));
$logRow($W('a7'), 'sign_in', $localNow(-60));
$offline('offline_reached_by_a_record', $WU('a7'), $body($WU('a7')));
$attemptRow($W('a8'), 'sign_in', 'refused', 'too_early', $localNow(-60));
$offline('offline_reached_by_an_attempt', $WU('a8'), $body($WU('a8')));
for ($i = 0; $i < 5; $i++) $attemptRow($W('a9'), 'sign_in', 'not_reached', 'no_network', $localNow(-3 * DAY_IN_SECONDS), ['reported_at' => $localNow(-30)]);
$offline('offline_limit', $WU('a9'), $body($WU('a9')));
$offline('offline_kept', $WU('a10'), $body($WU('a10')));
$offline('offline_kept_not_sure_sign_out', $WU('a11'), $body($WU('a11'), ['event_type' => 'sign_out', 'server_ms' => 0, 'elapsed_ms' => 0, 'phone_ms' => (time() - 90) * 1000, 'latitude' => '', 'accuracy' => 'x']));
$as($WU('a12'));
$faulty('offline_insert_fault', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_signin_attempts/i', function () use ($o, $WU, $body) { return golden_json($o, 'evidence_offline_report', $body($WU('a12')), 'ews_signin_offline'); });
$as($admin);

// ---------------------------------------------------------------- the photo serve
$omarAttempt = $attId('a');
$linaLog = $logId('b');
$serve = function ($label, $uid, $kind, $id, array $extra = [], $nonce = null) use ($run, $o, $as, $admin) {
    $as($uid);
    $run($label, function () use ($o, $kind, $id, $extra, $nonce) { return golden_get($o, 'face_photo_serve', ['kind' => $kind, 'id' => $id] + $extra, $nonce ?? 'ews_face_photo_' . $kind . '_' . $id); });
    $as($admin);
};
$serve('serve_logged_out', 0, 'attempt', $omarAttempt);
$serve('serve_bad_nonce', $admin, 'attempt', $omarAttempt, [], 'bad');
$serve('serve_unknown_kind', $admin, 'x', 1);
$serve('serve_no_such_row', $admin, 'log', 99999999);
$serve('serve_admin_log_full', $admin, 'log', $linaLog, ['full' => 1]);
$serve('serve_admin_log_thumbnail', $admin, 'log', $linaLog);
$serve('serve_admin_attempt', $admin, 'attempt', $omarAttempt);
$serve('serve_plain_user', $WU('a1'), 'attempt', $omarAttempt);
$serve('serve_manager', $EU('mgr'), 'attempt', $omarAttempt, ['full' => 1]);
$cfg(['photo_viewers' => 'hr']);
$serve('serve_manager_when_hr_only', $EU('mgr'), 'attempt', $omarAttempt);
$cfg([]);
$badKey = $attemptRow($W('a1'), 'face', 'face_failed', 'face_mismatch', $localNow(-100), ['photo_key' => 'not-a-key.jpg']);
$missing = $attemptRow($W('a1'), 'face', 'face_failed', 'face_mismatch', $localNow(-90), ['photo_key' => str_repeat('z', 32) . '.jpg']);
$serve('serve_bad_key', $admin, 'attempt', $badKey);
$serve('serve_missing_file', $admin, 'attempt', $missing);

// ---------------------------------------------------------------- the daily cleanup
$cfg(['attempt_days' => 60, 'photo_days' => 30]);
$wpdb->query("DELETE FROM {$p}ews_time_logs WHERE photo_key='" . str_repeat('g', 32) . ".jpg'");
$oldDay = gmdate('Y-m-d H:i:s', $nowTs - 40 * DAY_IN_SECONDS);
$logRow($W('a1'), 'sign_in', $oldDay, ['photo_key' => str_repeat('g', 32) . '.jpg']);
$attemptRow($W('a1'), 'face', 'face_failed', 'face_mismatch', gmdate('Y-m-d H:i:s', $nowTs - 45 * DAY_IN_SECONDS), ['photo_key' => str_repeat('c', 32) . '.jpg']);
$trace['files_before_cleanup'] = $listing();
$faulty('cleanup_no_attempts_table', ['/^SHOW TABLES LIKE/i', "SELECT 'x'"], function () use ($call) { return $call('evidence_cleanup'); });
$trace['files_after_no_table'] = $listing();
$run('cleanup', function () use ($call) { return $call('evidence_cleanup'); });
$trace['files_after_cleanup'] = $listing();
$files();
$attemptRow($W('a1'), 'face', 'face_failed', 'face_mismatch', gmdate('Y-m-d H:i:s', $nowTs - 45 * DAY_IN_SECONDS), ['photo_key' => str_repeat('a', 32) . '.jpg']);
$faulty('cleanup_update_fault', '/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_signin_attempts/i', function () use ($call) { return $call('evidence_cleanup'); });
$trace['files_after_update_fault'] = $listing();
$files();
$attemptRow($W('a1'), 'face', 'face_failed', 'face_mismatch', gmdate('Y-m-d H:i:s', $nowTs - 90 * DAY_IN_SECONDS), ['photo_key' => str_repeat('b', 32) . '.jpg']);
$faulty('cleanup_delete_fault', '/^DELETE FROM\s+`?' . preg_quote($p, '/') . 'ews_signin_attempts/i', function () use ($call) { return $call('evidence_cleanup'); });
$trace['files_after_delete_fault'] = $listing();
$files();

// ---------------------------------------------------------------- one employee's day
$run('timeline_admin_today', function () use ($call, $c, $D0) { return $call('evidence_timeline', (int) $c['omar'], $D0); });
$run('timeline_admin_not_reached', function () use ($call, $c, $DM1) { return $call('evidence_timeline', (int) $c['mona'], $DM1); });
$run('timeline_with_photos', function () use ($call, $c, $D0) { return $call('evidence_timeline', (int) $c['lina'], $D0); });
$as($EU('mgr'));
$run('timeline_manager_same_department', function () use ($call, $E, $D0) { return $call('evidence_timeline', $E('r13'), $D0); });
$run('timeline_manager_other_department', function () use ($call, $E, $D0) { return $call('evidence_timeline', $E('other'), $D0); });
$as($admin);
$run('timeline_bad_date', function () use ($call, $c) { return $call('evidence_timeline', (int) $c['omar'], 'not-a-date'); });
$run('timeline_no_employee', function () use ($call) { return $call('evidence_timeline', 99999999, '2020-01-01'); });
$faulty('timeline_no_attempts_table', ['/^SHOW TABLES LIKE/i', "SELECT 'x'"], function () use ($call, $c, $D0) { return $call('evidence_timeline', (int) $c['omar'], $D0); });

// ---------------------------------------------------------------- the Sign In / Out report page
$cfg(['photo' => 1, 'alert' => 3]);
$range = ['start' => $gmDm2 = gmdate('Y-m-d', strtotime($D0 . ' -2 days')), 'end' => $D0];
(new WP_User($WU('a1')))->add_cap('ews_manage_time');   // a manager whose employee has no department
$page('report_admin_both', $admin, 'admin_time_report', $range);
$page('report_admin_refused', $admin, 'admin_time_report', $range + ['show' => 'refused']);
$page('report_admin_recorded', $admin, 'admin_time_report', $range + ['show' => 'recorded']);
$page('report_admin_unknown_show', $admin, 'admin_time_report', $range + ['show' => 'nonsense']);
$page('report_admin_search', $admin, 'admin_time_report', $range + ['s' => 'omar']);
$page('report_admin_search_percent', $admin, 'admin_time_report', $range + ['s' => '50%_']);
$page('report_admin_page_two', $admin, 'admin_time_report', $range + ['paged' => 2]);
$page('report_admin_bad_dates', $admin, 'admin_time_report', ['start' => 'x', 'end' => 'y']);
$page('report_admin_edit_form', $admin, 'admin_time_report', $range + ['edit_time_id' => $linaLog]);
$page('report_admin_edit_unknown', $admin, 'admin_time_report', $range + ['edit_time_id' => 99999999]);
$page('report_admin_add_form_prefilled', $admin, 'admin_time_report', $range + ['add_time' => 1, 'prefill_employee' => (int) $c['omar'], 'prefill_type' => 'sign_out', 'prefill_at' => $D0 . 'T17:30', 'prefill_date' => $D0]);
$page('report_admin_add_form_bad_prefill', $admin, 'admin_time_report', $range + ['add_time' => 1, 'prefill_type' => 'face', 'prefill_at' => 'x', 'prefill_date' => 'x']);
$page('report_admin_timeline', $admin, 'admin_time_report', $range + ['timeline' => (int) $c['omar'], 'tdate' => $D0]);
$page('report_admin_notices', $admin, 'admin_time_report', $range + ['time_notice' => 'saved', 'time_error' => 'duplicate', 'time_reset' => 2, 'break_reset' => 1]);
$page('report_manager_both', $EU('mgr'), 'admin_time_report', $range);
$page('report_manager_timeline_own', $EU('mgr'), 'admin_time_report', $range + ['timeline' => $E('r13'), 'tdate' => $D0]);
$page('report_manager_timeline_other', $EU('mgr'), 'admin_time_report', $range + ['timeline' => $E('other'), 'tdate' => $D0]);
$page('report_manager_edit_out_of_scope', $EU('mgr'), 'admin_time_report', $range + ['edit_time_id' => $linaLog, 'add_time' => 1, 'prefill_employee' => $E('other')]);
$cfg(['photo' => 1, 'photo_viewers' => 'hr']);
$page('report_manager_hr_only_photos', $EU('mgr'), 'admin_time_report', $range);
$cfg(['photo' => 1, 'alert' => 3]);
$page('report_manager_without_department', $WU('a1'), 'admin_time_report', $range);
$page('report_plain_user', $WU('a2'), 'admin_time_report', $range);
$cfg(['attempts' => 0]);
$page('report_attempts_off', $admin, 'admin_time_report', $range + ['show' => 'refused']);
$cfg(['photo' => 1, 'alert' => 3]);
$fault = ['/^SHOW TABLES LIKE/i', "SELECT 'x'"];
$page('report_attempts_table_missing', $admin, 'admin_time_report', $range);
$fault = null;
$fault = ['/^SELECT COUNT\(\*\) FROM \(/i', 'SELECT broken_on_purpose FROM no_such_table_x'];
$quiet = $wpdb->suppress_errors(true);
$page('report_count_fault', $admin, 'admin_time_report', $range);
$wpdb->suppress_errors($quiet);
$fault = null;

// ---------------------------------------------------------------- the Auto Attendance page and the CSV's refusals
$ruleId = (int) $wpdb->get_var("SELECT id FROM {$p}ews_auto_attendance_rules ORDER BY id LIMIT 1");
$page('auto_page', $admin, 'admin_auto_attendance', []);
$page('auto_page_edit', $admin, 'admin_auto_attendance', ['edit_rule' => $ruleId, 'saved' => 1, 'bulk_sign_out' => 2, 'bulk_skipped' => 1, 'bulk_failed' => 0]);
$page('auto_page_edit_unknown', $admin, 'admin_auto_attendance', ['edit_rule' => 99999999]);
$page('auto_page_no_capability', $WU('a2'), 'admin_auto_attendance', []);
$run('csv_invalid_range', function () use ($get) { return $get('admin_time_csv', ['start' => 'x', 'end' => '2020-01-01'], 'ews31_time_csv'); });
$run('csv_reversed_range', function () use ($get) { return $get('admin_time_csv', ['start' => '2020-02-01', 'end' => '2020-01-01'], 'ews31_time_csv'); });
$run('csv_bad_nonce', function () use ($get, $D0) { return $get('admin_time_csv', ['start' => $D0, 'end' => $D0], 'bad'); });
$as($WU('a2'));
$run('csv_no_capability', function () use ($get, $D0) { return $get('admin_time_csv', ['start' => $D0, 'end' => $D0], 'ews31_time_csv'); });
$as($admin);

// ---------------------------------------------------------------- the tables, at the end
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
foreach ([
    'attempts' => "SELECT id,employee_id,user_id,event_type,result,reason,detail,latitude,longitude,accuracy,distance_meters,location_status,ip_address,device,source,photo_key,clock_skew,time_sure FROM {$p}ews_signin_attempts ORDER BY id",
    'time_logs_photo' => "SELECT id,employee_id,event_type,photo_key FROM {$p}ews_time_logs WHERE photo_key IS NOT NULL ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
$trace['files_at_the_end'] = $listing();
echo "\n" . wp_json_encode($trace);
