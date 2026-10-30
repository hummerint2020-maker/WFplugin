<?php
/**
 * The golden harness's Sign In / Sign Out and break trace (tests/golden/run.py; docs/tasks/attendance-store.md §4-5, L5b): the Web forms
 * (time_event, break_start, break_resume), the Sign In service with idempotency keys, and the two break crons, driven in process
 * through their own handlers (tests/golden/exit.php) with real nonces. For each call: what it returned or where it redirected, the
 * exact SQL (in order, dates and times as placeholders, so an extra or reordered statement shows), the query count; at the end the
 * time logs, breaks, idempotency keys, audit rows and notifications. Faults are injected into the writes (the insert, the check
 * after it, the lock, the idempotency claim, the photo update, the break close). The same script runs on the old and the new code
 * from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_signin.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/exit.php';
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- dates as placeholders
$D0 = current_time('Y-m-d');
$dates = [$D0 => 'D0', gmdate('Y-m-d', strtotime($D0 . ' -1 day')) => 'D-1'];
$fix = function ($s) use ($dates) { return strtr($s, $dates); };

// ---------------------------------------------------------------- recording (with in-process fault injection)
$sql = [];
$fault = null;
add_filter('query', function ($q) use (&$sql, $fix) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $n = preg_replace("/(_transient_timeout_\w+', ')\d+/", '$1TS', $n);
    $n = preg_replace('/location_timestamp\'?,? ?\d{13}/', 'location_timestamp TS', $n);
    $sql[] = $fix(trim(preg_replace('/\s+/', ' ', $n)));
    return $q;
}, 1);
add_filter('query', function ($q) use (&$fault) {
    if ($fault === null) return $q;
    [$re, $to] = is_array($fault) ? $fault : [$fault, 'SELECT broken_on_purpose FROM no_such_table_x'];
    return preg_match($re, $q) ? $to : $q;
}, 5);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => preg_replace('/(<|&lt;)div.*$/s', '', $v->get_error_message())];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) $out[$k] = ($k === 'nonce' && $x !== '') ? 'N' : $norm($x);
        return $out;
    }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        $v = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($v, $dates));
        $v = preg_replace('#^https?://[^/]+#', '', $v);
        $v = preg_replace('/(<|&lt;)div.*$/s', '', $v);
        $v = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $v);
    }
    return $v;
};
$trace = [];
$as = function ($uid) { wp_set_current_user((int) $uid); };
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm) {
    wp_cache_flush();
    $sql = [];
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};
$faulty = function ($label, $pattern, callable $fn) use (&$fault, $run, $wpdb) {
    $fault = $pattern;
    $quiet = $wpdb->suppress_errors(true);
    try { $run($label, $fn); } finally { $wpdb->suppress_errors($quiet); $fault = null; }
};
$post = function ($method, array $fields, $nonce, $referer = null) use ($o) { return golden_post($o, $method, $fields, $nonce, $referer); };
$cron = function () {
    $out = [];
    foreach ((array) _get_cron_array() as $ts => $hooks) foreach ($hooks as $hook => $evs) {
        if (strpos($hook, 'ews_break_') !== 0) continue;
        foreach ($evs as $ev) $out[] = [$hook, $ev['args'], (int) round(($ts - time()) / 60)];
    }
    usort($out, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
    return $out;
};

// the work location's enforcement is read through two static caches: a change resets both
$enforce = function ($on) use ($wpdb, $p) {
    $wpdb->query("UPDATE {$p}ews_locations SET enforcement=" . ($on ? 1 : 0));
    foreach (['ews_branch_locations' => null, 'ews_employee_location_cache' => []] as $prop => $empty) { $rp = new ReflectionProperty('EWS_Manager_V31_1', $prop); $rp->setAccessible(true); $rp->setValue(null, $empty); }
};
$call('ensure_api_idempotency_schema');
$wpdb->query("DELETE FROM {$p}ews_api_idempotency");  // a solo run on a used site starts clean (the harness restores its snapshot)
// ---------------------------------------------------------------- settings (options only; the people and today's plan come from seed_attendance.php)
update_option('ews_working_hours', ['start' => '00:00', 'normal_until' => '23:59', 'end' => '23:59'], false);
update_option('ews_allow_overnight_shift', 0, false);
update_option('ews_feature_breaks', 1, false);
update_option('ews_breaks_per_day', 2, false);
update_option('ews_break_duration_minutes', 15, false);
update_option('ews_break_manager_alert_minutes', 30, false);
delete_option('ews_feature_face_signin');
$call('invalidate_working_hours_runtime_cache');
$u = function ($k) use ($c) { return (int) $c['atwu_' . $k]; };
$e = function ($k) use ($c) { return (int) $c['atw_' . $k]; };
$stamp = function () { return (string) (int) (time() * 1000); };
$sin = function (array $f = []) use ($post) { return $post('time_event', ['event_type' => 'sign_in'] + $f, 'ews_time_event_sign_in', admin_url('index.php')); };
$sout = function (array $f = []) use ($post) { return $post('time_event', ['event_type' => 'sign_out'] + $f, 'ews_time_event_sign_out', admin_url('index.php')); };
$bstart = function () use ($post) { return $post('break_start', [], 'ews_break_start', admin_url('index.php')); };
$bresume = function () use ($post) { return $post('break_resume', [], 'ews_break_resume', admin_url('index.php')); };
$face = function ($k) use ($u) { set_transient('ews_face_token_' . $u($k), hash('sha256', 'T'), 300); set_transient('ews_face_photo_' . $u($k), 'photo-key-' . $k, 300); };
$gps = function ($lat, $lng) use ($stamp) { return ['latitude' => (string) $lat, 'longitude' => (string) $lng, 'accuracy' => '10', 'location_timestamp' => $stamp()]; };

// ---------------------------------------------------------------- 1. the plain flow: Sign In, Sign Out, and every refusal
$as($u('a1'));
$run('in_ok', $sin);
$run('in_again', $sin);
$run('out_ok', $sout);
$run('out_again', $sout);
$run('in_after_out', $sin);
$as($u('a2'));
$run('out_before_in', $sout);
$as($u('off'));
$run('in_attendance_off', $sin);
$as($u('leave'));
$run('in_leave_day', $sin);
$as($u('nosched'));
$run('in_no_schedule', $sin);
$as($u('ghost'));
$run('in_no_employee', $sin);
$as(0);
$run('in_not_logged_in', $sin);
$as($u('a2'));
$run('bad_nonce', function () use ($post) { return $post('time_event', ['event_type' => 'sign_in'], 'bad', admin_url('index.php')); });
$run('bad_event_type', function () use ($post) { return $post('time_event', ['event_type' => 'nope'], 'ews_time_event_sign_in', admin_url('index.php')); });

// ---------------------------------------------------------------- 2. Face: the token is consumed, the photo is kept with the row
update_option('ews_feature_face_signin', 1, false);
$as($u('a3'));
$run('face_missing', $sin);
$face('a3');
$run('face_ok_photo', function () use ($sin) { return $sin(['face_token' => 'T']); });
$run('face_token_used_up', $sout);
delete_option('ews_feature_face_signin');

// ---------------------------------------------------------------- 3. location: enforced site, outside / no position / inside; the previous position is read for the next event
$enforce(true);
$as($u('a4'));
$run('loc_outside', function () use ($sin, $gps) { return $sin($gps(31.0, 32.0)); });
$run('loc_no_position', $sin);
$run('loc_inside_after_refusals', function () use ($sin, $gps) { return $sin($gps(30.0444, 31.2357)); });
$as($u('a5'));
$run('loc_inside_in', function () use ($sin, $gps) { return $sin($gps(30.0444, 31.2357)); });
$run('loc_inside_out', function () use ($sout, $gps) { return $sout($gps(30.0445, 31.2358)); });
$as($u('a6'));
$run('loc_out_of_enforcement_on_out', function () use ($sin, $sout, $gps) { $sin($gps(30.0444, 31.2357)); return $sout($gps(31.0, 32.0)); });
$enforce(false);

// ---------------------------------------------------------------- 4. faults in the Sign In write
$as($u('a7'));
$faulty('fault_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', $sin);
$as($u('a8'));
$faulty('fault_first_row', ['/^SELECT MIN\(id\) FROM\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', 'SELECT 1'], $sin);
$run('in_after_first_row_fault', $sin);
$faulty('fault_first_row_out', ['/^SELECT MIN\(id\) FROM\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', 'SELECT 1'], $sout);
$as($u('a9'));
$faulty('fault_lock_busy', ['/^SELECT GET_LOCK/i', 'SELECT 0'], $sin);
$faulty('fault_lock_null', ['/^SELECT GET_LOCK/i', 'SELECT NULL'], $sout);
$as($u('a10'));
update_option('ews_feature_face_signin', 1, false);
$face('a10');
$faulty('fault_photo_update', '/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_time_logs`?\s+SET\s+`?photo_key/i', function () use ($sin) { return $sin(['face_token' => 'T']); });
delete_option('ews_feature_face_signin');

// ---------------------------------------------------------------- 5. idempotency keys through the service
$cmd = function ($type, $key) use ($stamp) { $m = new \WorkforceOne\Attendance\AttendanceCommand($type); $m->idempotencyKey = $key; $m->faceOk = false; return $m; };
$serv = function ($type, $key) use ($call, $cmd) { return $call('attendance_service')->record($cmd($type, $key))->toArray(); };
$as($u('a2'));
$run('idem_first', function () use ($serv) { return $serv('sign_in', 'key-1'); });
$run('idem_replay', function () use ($serv) { return $serv('sign_in', 'key-1'); });
$run('idem_conflict', function () use ($call, $cmd) { $m = $cmd('sign_in', 'key-1'); $m->latitude = 30.0; $m->longitude = 31.0; return $call('attendance_service')->record($m)->toArray(); });
$as($u('a9'));
$faulty('idem_claim_broken', '/^INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_api_idempotency/i', function () use ($serv) { return $serv('sign_in', 'key-2'); });
$as($u('a7'));
$faulty('idem_save_failed_released', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', function () use ($serv) { return $serv('sign_in', 'key-3'); });
$run('idem_retry_after_release', function () use ($serv) { return $serv('sign_in', 'key-3'); });

// ---------------------------------------------------------------- 6. breaks: start, second start, resume, none left, Sign Out on a break
$as($u('a11'));
$run('break_before_in', $bstart);
$sin();
$run('break_1_start', $bstart);
$trace['crons_after_break_1'] = $cron();
$run('break_start_while_on_break', $bstart);
$run('out_while_on_break', $sout);
$run('break_1_resume', $bresume);
$run('break_resume_again', $bresume);
$run('break_2_start', $bstart);
$run('break_2_resume', $bresume);
$run('break_3_none_left', $bstart);
$run('out_after_breaks', $sout);
$as($u('a12'));
$sin();
$faulty('fault_break_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_break_sessions/i', $bstart);
$faulty('fault_break_first_open', ['/^SELECT MIN\(id\) FROM\s+`?' . preg_quote($p, '/') . 'ews_break_sessions/i', 'SELECT 1'], $bstart);
$run('break_ok_after_faults', $bstart);
$faulty('fault_break_close', ['/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_break_sessions/i', 'UPDATE ' . $p . 'ews_break_sessions SET status=status WHERE 1=0'], $bresume);
$faulty('fault_break_lock_busy', ['/^SELECT GET_LOCK/i', 'SELECT 0'], $bresume);
update_option('ews_feature_breaks', 0, false);
$run('break_disabled_start', $bstart);
$run('break_disabled_resume', $bresume);
update_option('ews_feature_breaks', 1, false);
$as(0);
$trace['crons_at_end'] = $cron();

// ---------------------------------------------------------------- 7. the two break crons (reminder once, escalation once, archived employee, closed break)
$open = (int) $wpdb->get_var("SELECT id FROM {$p}ews_break_sessions WHERE employee_id=" . $e('a12') . " AND status='Open' ORDER BY id DESC LIMIT 1");
$run('cron_reminder', function () use ($call, $open) { return $call('break_duration_reminder', $open); });
$run('cron_reminder_again', function () use ($call, $open) { return $call('break_duration_reminder', $open); });
$run('cron_escalation', function () use ($call, $open) { return $call('break_manager_escalation', $open); });
$run('cron_escalation_again', function () use ($call, $open) { return $call('break_manager_escalation', $open); });
$run('cron_unknown_break', function () use ($call) { return $call('break_duration_reminder', 99999999); });
$closed = (int) $wpdb->get_var("SELECT id FROM {$p}ews_break_sessions WHERE employee_id=" . $e('a11') . " AND status<>'Open' ORDER BY id LIMIT 1");
$run('cron_closed_break', function () use ($call, $closed) { return $call('break_manager_escalation', $closed); });
$wpdb->query("UPDATE {$p}ews_break_sessions SET escalation_notified=0 WHERE id={$open}");
$wpdb->query("UPDATE {$p}ews_employees SET attendance_enabled=0 WHERE id=" . $e('a12'));
$run('cron_escalation_attendance_off', function () use ($call, $open) { return $call('break_manager_escalation', $open); });
$wpdb->query("UPDATE {$p}ews_employees SET attendance_enabled=1, active=0 WHERE id=" . $e('a12'));
$run('cron_escalation_archived', function () use ($call, $open) { return $call('break_manager_escalation', $open); });
update_option('ews_feature_breaks', 0, false);
$run('cron_breaks_off', function () use ($call, $open) { return $call('break_duration_reminder', $open); });

// ---------------------------------------------------------------- the tables, at the end
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
$ids = implode(',', array_map('intval', array_map($e, ['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11', 'a12'])));
foreach ([
    'time_logs' => "SELECT * FROM {$p}ews_time_logs WHERE employee_id IN ({$ids}) ORDER BY id",
    'break_sessions' => "SELECT * FROM {$p}ews_break_sessions WHERE employee_id IN ({$ids}) ORDER BY id",
    'idempotency' => "SELECT user_id,scope,key_hash,request_hash,response FROM {$p}ews_api_idempotency ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
echo wp_json_encode($trace);
