<?php
/**
 * The golden harness's auto-attendance and manual-record trace (tests/golden/run.py; docs/tasks/attendance-auto-manual.md §d, L5c): the five-minute
 * tick (rules due, a one-time rule disabled, leave, attendance off, an archived employee, an overnight Sign Out), one automatic event through its own
 * function with every reason and fault, the rules page's save / toggle / delete, the bulk Sign Out (breaks open, the lock busy, a failed insert, a lost
 * break close) and the Sign In / Out report's manual add / edit / Reset Day (scope, every error code, faults), driven in process through their own
 * handlers (tests/golden/exit.php). For each call: what it returned or where it redirected, the exact SQL (in order, dates and times as placeholders),
 * the query count; at the end the time logs, breaks, rules, audit and notifications. The same script runs on the old and the new code from the same
 * snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_records.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/exit.php';
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- dates as placeholders
$D0 = current_time('Y-m-d');
$DM1 = gmdate('Y-m-d', strtotime($D0 . ' -1 day'));
$dates = [$D0 => 'D0', $DM1 => 'D-1', gmdate('Y-m-d', strtotime($D0 . ' +1 day')) => 'D+1'];
$fix = function ($s) use ($dates) { return strtr($s, $dates); };

// ---------------------------------------------------------------- recording (with in-process fault injection)
$sql = [];
$fault = null;
add_filter('query', function ($q) use (&$sql, $fix) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $n = preg_replace("/(_transient_timeout_\w+', ')\d+/", '$1TS', $n);
    $n = preg_replace('/\b\d{13}\b/', 'TS', $n);
    $n = preg_replace('/`actual_minutes` = \d+/', '`actual_minutes` = N', $n);   // minutes since a break started follow "now"
    $n = preg_replace('#/ \d+m /#', '/ Nm /', $n);   // ... and so does the audit text of a closed break
    $n = preg_replace("/SET `option_value` = '.*?' WHERE `option_name` = 'cron'/s", "SET `option_value` = 'CRON' WHERE `option_name` = 'cron'", $n);
    $n = preg_replace('/(<|&lt;)div.*?(?=\', \'T\'\))/s', '', $n);
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
        foreach ($v as $k => $x) $out[$k] = (($k === 'nonce' || $k === 'actual_minutes') && $x !== '' && $x !== null) ? 'N' : $norm($x);
        return $out;
    }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) return 'HM';
        if (preg_match('/^\d{13}$/', $v)) return 'TS';
        $v = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($v, $dates));
        $v = preg_replace('#^https?://[^/]+#', '', $v);
        $v = preg_replace('/(<|&lt;)div.*$/s', '', $v);
        $v = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $v);
        $v = preg_replace('/\b\d{2}:\d{2}\b/', 'HM', $v);
        $v = preg_replace('/\b\d+m\b/', 'Nm', $v);   // a time of day that follows "now" (the rules' times, the audit texts)
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
$post = function ($method, array $fields, $nonce) use ($o) { return golden_post($o, $method, $fields, $nonce, admin_url('admin.php')); };
$get = function ($method, array $fields, $nonce) use ($o) { return golden_get($o, $method, $fields, $nonce); };
$baseBreak = (int) $wpdb->get_var("SELECT COALESCE(MAX(id),0) FROM {$p}ews_break_sessions");
$cron = function () use ($baseBreak) {
    $out = [];
    foreach ((array) _get_cron_array() as $ts => $hooks) foreach ($hooks as $hook => $evs) {
        if (strpos($hook, 'ews_break_') !== 0) continue;
        foreach ($evs as $ev) if ((int) ($ev['args'][0] ?? 0) > $baseBreak) $out[] = [$hook, $ev['args'], (int) round(($ts - time()) / 60)];
    }
    usort($out, function ($a, $b) { return strcmp(json_encode($a), json_encode($b)); });
    return $out;
};

// ---------------------------------------------------------------- people, settings, rules (plain SQL; times follow "now", clamped to today)
$admin = (int) get_user_by('login', 'admin')->ID;
$plain = (int) $c['atwu_a1'];   // a user with no Auto Attendance / time capability
$asUser = function ($uid, callable $fn) use ($admin) { wp_set_current_user((int) $uid); try { return $fn(); } finally { wp_set_current_user($admin); } };
$E = function ($k) use ($c) { return (int) $c['atr_' . $k]; };
$W = function ($k) use ($c) { return (int) $c['atw_' . $k]; };
$nowTs = (int) current_time('timestamp');
$hm = function ($offsetMinutes) use ($nowTs, $D0) {
    $t = $nowTs + $offsetMinutes * 60;
    if (gmdate('Y-m-d', $t) !== $D0) return $offsetMinutes < 0 ? '00:00' : '23:59';
    return gmdate('H:i', $t);
};
update_option('ews_working_hours', ['start' => '00:00', 'normal_until' => '23:59', 'end' => '23:59'], false);
update_option('ews_feature_breaks', 1, false);
update_option('ews_break_duration_minutes', 15, false);
$call('invalidate_working_hours_runtime_cache');
$call('ensure_auto_attendance_schema');
$wpdb->query("DELETE FROM {$p}ews_auto_attendance_rules");
$rule = function ($emp, $recurrence, $runDate, $weekdays, $in, $out, $enabled = 1) use ($wpdb, $p, $admin) {
    $wpdb->insert($p . 'ews_auto_attendance_rules', ['employee_id' => $emp, 'recurrence' => $recurrence, 'run_date' => $runDate, 'weekdays' => $weekdays, 'sign_in_time' => $in, 'sign_out_time' => $out, 'enabled' => $enabled, 'created_by' => $admin, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')]);
    return (int) $wpdb->insert_id;
};
$sched = function ($emp, $date, $status = 'Office') use ($wpdb, $p) { $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date=%s", $emp, $date)); $wpdb->insert($p . 'ews_schedule', ['employee_id' => $emp, 'work_date' => $date, 'status' => $status]); };
$log = function ($emp, $type, $date, $at, $extra = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_time_logs', array_merge(['employee_id' => $emp, 'user_id' => 0, 'work_date' => $date, 'event_type' => $type, 'event_at' => $at, 'scheduled_status' => 'Office', 'ip_address' => '', 'location_status' => 'auto', 'source' => 'app'], $extra));
    return (int) $wpdb->insert_id;
};
$brk = function ($emp, $start) use ($wpdb, $p, $D0) {
    $wpdb->insert($p . 'ews_break_sessions', ['employee_id' => $emp, 'user_id' => 0, 'work_date' => $D0, 'start_at' => $start, 'status' => 'Open']);
    $id = (int) $wpdb->insert_id;
    wp_schedule_single_event(time() + 900, 'ews_break_duration_reminder', [$id]);
    wp_schedule_single_event(time() + 1800, 'ews_break_manager_escalation', [$id]);
    return $id;
};
$wpdb->query("UPDATE {$p}ews_employees SET active=0 WHERE id=" . $E('r10'));
$sched($E('r12'), $DM1);
$log($E('r12'), 'sign_in', $DM1, $DM1 . ' 22:00:00');
$ruleIds = [
    'due_one_time' => $rule($E('r1'), 'one_time', $D0, '', $hm(-10), $hm(-5)),
    'weekly_in' => $rule($E('r2'), 'weekly', null, '0,1,2,3,4,5,6', $hm(-10), null),
    'future' => $rule($E('r3'), 'one_time', $D0, '', $hm(30), null),
    'leave_day' => $rule($E('r9'), 'one_time', $D0, '', $hm(-10), null),
    'disabled' => $rule($E('r5'), 'weekly', null, '0,1,2,3,4,5,6', $hm(-10), null, 0),
    'attendance_off' => $rule($W('off'), 'weekly', null, '0,1,2,3,4,5,6', $hm(-10), null),
    'archived' => $rule($E('r10'), 'weekly', null, '0,1,2,3,4,5,6', $hm(-10), null),
    'out_only' => $rule($E('r11'), 'one_time', $D0, '', null, $hm(-5)),
    'overnight' => $rule($E('r12'), 'one_time', $DM1, '', '22:00', '06:00'),
];

// ---------------------------------------------------------------- 1. the tick (twice: the second finds nothing left to do)
$as(0);
$run('tick_1', function () use ($call) { return $call('process_auto_attendance'); });
$run('tick_2', function () use ($call) { return $call('process_auto_attendance'); });
$trace['rules_after_ticks'] = $norm($wpdb->get_results("SELECT id,employee_id,enabled FROM {$p}ews_auto_attendance_rules ORDER BY id", ARRAY_A));

// ---------------------------------------------------------------- 2. one automatic event: every reason, and the faults
$sch = (object) ['status' => 'Office'];
$ev = function ($emp, $type, $time, $date = null, $eventDate = null) use ($call, $D0, $sch) { return $call('auto_attendance_event', $emp, $date ?: $D0, $type, $time, $sch, 77, $eventDate); };
$t5 = $hm(-5); $t20 = $hm(-20); $t2 = $hm(-2);
$run('event_in_ok', function () use ($ev, $E, $t5) { return $ev($E('r6'), 'sign_in', $t5); });
$run('event_in_again', function () use ($ev, $E, $t5) { return $ev($E('r6'), 'sign_in', $t5); });
$run('event_out_without_in', function () use ($ev, $E, $t2) { return $ev($E('r7'), 'sign_out', $t2); });
$run('event_out_before_in', function () use ($ev, $E, $t5, $t20) { $ev($E('r7'), 'sign_in', $t5); return $ev($E('r7'), 'sign_out', $t20); });
$run('event_out_ok', function () use ($ev, $E, $t2) { return $ev($E('r7'), 'sign_out', $t2); });
$run('event_out_again', function () use ($ev, $E, $t2) { return $ev($E('r7'), 'sign_out', $t2); });
$run('event_employee_missing', function () use ($ev, $t5) { return $ev(999999, 'sign_in', $t5); });
$run('event_attendance_disabled', function () use ($ev, $W, $t5) { return $ev($W('off'), 'sign_in', $t5); });
$run('event_employee_with_user', function () use ($ev, $E, $t5) { return $ev($E('r13'), 'sign_in', $t5); });
$faulty('event_fault_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', function () use ($ev, $W, $t5) { return $ev($W('a1'), 'sign_in', $t5); });
$faulty('event_fault_first_row', ['/^SELECT MIN\(id\) FROM\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', 'SELECT 1'], function () use ($ev, $W, $t5) { return $ev($W('a2'), 'sign_in', $t5); });
$faulty('event_lock_busy', ['/^SELECT GET_LOCK/i', 'SELECT 0'], function () use ($ev, $W, $t5) { return $ev($W('a3'), 'sign_in', $t5); });
$faulty('event_lock_null', ['/^SELECT GET_LOCK/i', 'SELECT NULL'], function () use ($ev, $W, $t5) { return $ev($W('a4'), 'sign_in', $t5); });
$rule($E('r4'), 'one_time', $D0, '', $hm(-10), null);
$log($E('r4'), 'sign_in', $D0, $D0 . ' 00:00:01', ['source' => 'auto']);   // done already: the rule is to be disabled, and that write is broken
$faulty('tick_fault_rules_update', '/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_auto_attendance_rules/i', function () use ($call) { return $call('process_auto_attendance'); });

// ---------------------------------------------------------------- 3. the rules page: save, toggle, delete
$as($admin);
$form = function (array $f = []) use ($E, $D0) { return $f + ['employee_id' => $E('r8'), 'recurrence' => 'one_time', 'run_date' => $D0, 'sign_in_time' => '09:00', 'sign_out_time' => '17:00']; };
$save = function (array $f, $nonce = 'ews_auto_attendance_save') use ($post) { return $post('auto_attendance_save', $f, $nonce); };
$run('rule_save_denied', function () use ($asUser, $plain, $save, $form) { return $asUser($plain, function () use ($save, $form) { return $save($form()); }); });
$run('rule_save_bad_nonce', function () use ($save, $form) { return $save($form(), 'bad'); });
$run('rule_save_no_employee', function () use ($save, $form) { return $save($form(['employee_id' => 0])); });
$run('rule_save_bad_time', function () use ($save, $form) { return $save($form(['sign_in_time' => '25:00'])); });
$run('rule_save_no_times', function () use ($save, $form) { return $save($form(['sign_in_time' => '', 'sign_out_time' => ''])); });
$run('rule_save_bad_date', function () use ($save, $form) { return $save($form(['run_date' => 'tomorrow'])); });
$run('rule_save_archived_employee', function () use ($save, $form, $E) { return $save($form(['employee_id' => $E('r10')])); });
$run('rule_save_attendance_off', function () use ($save, $form, $W) { return $save($form(['employee_id' => $W('off')])); });
$run('rule_save_create', function () use ($save, $form) { return $save($form()); });
$run('rule_save_create_weekly', function () use ($save, $form, $E) { return $save($form(['employee_id' => $E('r8'), 'recurrence' => 'weekly', 'weekdays' => ['1', '3', '9'], 'sign_out_time' => '', 'enabled' => '1'])); });
$run('rule_save_weekly_default_days', function () use ($save, $form, $E) { return $save($form(['employee_id' => $E('r8'), 'recurrence' => 'weekly', 'sign_in_time' => '', 'sign_out_time' => '18:00'])); });
$newRule = (int) $wpdb->get_var("SELECT MAX(id) FROM {$p}ews_auto_attendance_rules");
$run('rule_save_update', function () use ($save, $form, $newRule) { return $save($form(['rule_id' => $newRule, 'sign_out_time' => '19:00', 'enabled' => '1'])); });
$run('rule_save_update_deleted_id', function () use ($save, $form) { return $save($form(['rule_id' => 987654])); });
$faulty('rule_save_fault_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_auto_attendance_rules/i', function () use ($save, $form) { return $save($form()); });
$faulty('rule_save_fault_update', '/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_auto_attendance_rules/i', function () use ($save, $form, $newRule) { return $save($form(['rule_id' => $newRule])); });
$run('rule_toggle_off', function () use ($get, $newRule) { return $get('auto_attendance_toggle', ['rule_id' => $newRule], 'ews_auto_attendance_toggle_' . $newRule); });
$run('rule_toggle_on', function () use ($get, $newRule) { return $get('auto_attendance_toggle', ['rule_id' => $newRule], 'ews_auto_attendance_toggle_' . $newRule); });
$run('rule_toggle_missing', function () use ($get) { return $get('auto_attendance_toggle', ['rule_id' => 987654], 'ews_auto_attendance_toggle_987654'); });
$run('rule_toggle_bad_nonce', function () use ($get, $newRule) { return $get('auto_attendance_toggle', ['rule_id' => $newRule], 'bad'); });
$run('rule_delete', function () use ($get, $newRule) { return $get('auto_attendance_delete', ['rule_id' => $newRule], 'ews_auto_attendance_delete_' . $newRule); });
$run('rule_delete_missing', function () use ($get, $newRule) { return $get('auto_attendance_delete', ['rule_id' => $newRule], 'ews_auto_attendance_delete_' . $newRule); });
$trace['cron_tick_event'] = wp_next_scheduled('ews_auto_attendance_tick') ? 'scheduled' : 'none';

// ---------------------------------------------------------------- 4. the bulk Sign Out
$bulk = function () use ($post) { return $post('auto_attendance_bulk_sign_out', [], 'ews_auto_attendance_bulk_sign_out'); };
$open = function ($k, $withBreak = false) use ($log, $brk, $E, $D0) { $log($E($k), 'sign_in', $D0, $D0 . ' 00:00:05'); return $withBreak ? $brk($E($k), $D0 . ' 00:00:10') : 0; };
$wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date='{$D0}' AND employee_id IN (" . implode(',', array_map('intval', [$E('r1'), $E('r2'), $E('r3'), $E('r4'), $E('r5'), $E('r6'), $E('r7'), $E('r8'), $E('r9'), $E('r10'), $E('r11'), $E('r12'), $E('r13'), $W('a1'), $W('a2'), $W('a3'), $W('a4'), $W('off')])) . ')');
$wpdb->query("UPDATE {$p}ews_employees SET active=1 WHERE id=" . $E('r10'));
$b1 = $open('r1', true);
$open('r2');
$open('r3');
$log($E('r4'), 'sign_in', $D0, $D0 . ' 00:00:05'); $log($E('r4'), 'sign_out', $D0, $D0 . ' 00:00:06');   // already out: not a candidate
$open('r5');
$wpdb->query("UPDATE {$p}ews_employees SET attendance_enabled=0 WHERE id=" . $E('r5'));   // attendance off: skipped
$run('bulk_denied', function () use ($asUser, $plain, $bulk) { return $asUser($plain, $bulk); });
$trace['crons_before_bulk'] = $cron();
$run('bulk_ok', $bulk);
$trace['crons_after_bulk'] = $cron();
$run('bulk_again', $bulk);
$open('r6', true); $open('r7', true); $open('r8'); $open('r9');
$faulty('bulk_fault_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', $bulk);
$faulty('bulk_fault_first_row', ['/^SELECT MIN\(id\) FROM\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', 'SELECT 1'], $bulk);
$faulty('bulk_lock_busy', ['/^SELECT GET_LOCK/i', 'SELECT 0'], $bulk);
$open('r10', true); $open('r11', true);
$faulty('bulk_fault_break_close', ['/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_break_sessions/i', 'UPDATE ' . $p . 'ews_break_sessions SET status=status WHERE 1=0'], $bulk);
$trace['crons_after_faulty_bulk'] = $cron();
update_option('ews_feature_breaks', 0, false);
$open('r12', true); $open('r13', true);
$run('bulk_breaks_off', $bulk);
update_option('ews_feature_breaks', 1, false);
$trace['crons_at_end_of_bulk'] = $cron();

// ---------------------------------------------------------------- 5. manual records: add / edit with every code, scope, faults
$wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date='{$D0}' AND employee_id IN (" . implode(',', array_map('intval', [$E('r13'), $E('r8'), $E('r2'), $E('other'), $E('mgr')])) . ')');
$wpdb->query("DELETE FROM {$p}ews_break_sessions WHERE employee_id IN (" . implode(',', array_map('intval', [$E('r13'), $E('other')])) . ')');
$t = function ($hhmm) use ($D0) { return $D0 . 'T' . $hhmm; };
$man = function (array $f, $nonce = 'ews31_time_save') use ($post) { return $post('admin_time_save', $f, $nonce); };
$mf = function (array $f = []) use ($E, $D0, $t) { return $f + ['employee_id' => $E('r13'), 'work_date' => $D0, 'event_type' => 'sign_in', 'event_at' => $t('09:00')]; };
$as($admin);
$run('man_denied', function () use ($asUser, $plain, $man, $mf) { return $asUser($plain, function () use ($man, $mf) { return $man($mf()); }); });
$run('man_bad_nonce', function () use ($man, $mf) { return $man($mf(), 'bad'); });
$run('man_invalid_type', function () use ($man, $mf) { return $man($mf(['event_type' => 'nope'])); });
$run('man_invalid_date', function () use ($man, $mf) { return $man($mf(['work_date' => '2026-13-45'])); });
$run('man_invalid_time', function () use ($man, $mf) { return $man($mf(['event_at' => 'noon'])); });
$run('man_employee_missing', function () use ($man, $mf) { return $man($mf(['employee_id' => 999999])); });
$run('man_employee_archived_on_add', function () use ($man, $mf, $E, $wpdb, $p) { $wpdb->query("UPDATE {$p}ews_employees SET active=0 WHERE id=" . $E('r10')); return $man($mf(['employee_id' => $E('r10')])); });
$run('man_date_mismatch', function () use ($man, $mf, $DM1) { return $man($mf(['event_at' => $DM1 . 'T09:00'])); });
$run('man_add_in', function () use ($man, $mf) { return $man($mf()); });
$run('man_add_in_duplicate', function () use ($man, $mf, $t) { return $man($mf(['event_at' => $t('09:30')])); });
$run('man_add_out_before_in', function () use ($man, $mf, $t) { return $man($mf(['event_type' => 'sign_out', 'event_at' => $t('08:00')])); });
$run('man_add_out', function () use ($man, $mf, $t) { return $man($mf(['event_type' => 'sign_out', 'event_at' => $t('17:00'), 'latitude' => '30.0444', 'longitude' => '31.2357', 'accuracy' => '10'])); });
$inId = (int) $wpdb->get_var("SELECT id FROM {$p}ews_time_logs WHERE employee_id=" . $E('r13') . " AND event_type='sign_in' AND work_date='{$D0}' ORDER BY id LIMIT 1");
$run('man_edit', function () use ($man, $mf, $inId, $t) { return $man($mf(['time_id' => $inId, 'event_at' => $t('08:45')])); });
$run('man_edit_not_found', function () use ($man, $mf) { return $man($mf(['time_id' => 987654])); });
$run('man_edit_other_employee', function () use ($man, $mf, $inId, $E, $t) { return $man($mf(['time_id' => $inId, 'employee_id' => $E('r8'), 'event_at' => $t('08:50')])); });
$archivedRow = $log($E('r10'), 'sign_in', $DM1, $DM1 . ' 09:00:00');
$run('man_edit_archived_employee', function () use ($man, $E, $archivedRow, $DM1) { return $man(['time_id' => $archivedRow, 'employee_id' => $E('r10'), 'work_date' => $DM1, 'event_type' => 'sign_in', 'event_at' => $DM1 . 'T09:15']); });
$run('man_edit_correction_row', function () use ($man, $c, $D0, $t) { return $man(['time_id' => $c['att_corrected'], 'employee_id' => $c['mona'] ?? 0, 'work_date' => $D0, 'event_type' => 'sign_in', 'event_at' => $t('08:10')]); });
$faulty('man_fault_insert', '/^INSERT INTO\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', function () use ($man, $mf, $E, $t) { return $man($mf(['employee_id' => $E('r2'), 'event_at' => $t('09:05')])); });
$faulty('man_fault_update', '/^UPDATE\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', function () use ($man, $mf, $inId, $t) { return $man($mf(['time_id' => $inId, 'event_at' => $t('08:40')])); });
$as((int) $c['atru_mgr']);
$run('man_mgr_in_scope', function () use ($man, $mf, $E, $t) { return $man($mf(['employee_id' => $E('mgr'), 'event_at' => $t('09:10')])); });
$run('man_mgr_other_department', function () use ($man, $mf, $E) { return $man($mf(['employee_id' => $E('other')])); });
$otherRow = $log($E('other'), 'sign_in', $D0, $D0 . ' 09:20:00');
$run('man_mgr_edit_other_departments_row', function () use ($man, $mf, $otherRow, $E) { return $man($mf(['time_id' => $otherRow, 'employee_id' => $E('mgr')])); });
$run('man_mgr_move_to_other_department', function () use ($man, $mf, $inId, $E) { return $man($mf(['time_id' => $inId, 'employee_id' => $E('other')])); });
$as($admin);
$bOpen = $brk($E('r13'), $D0 . ' 08:50:00');
$run('man_sign_out_with_open_break', function () use ($man, $mf, $E, $t, $wpdb, $p) { $wpdb->query("DELETE FROM {$p}ews_time_logs WHERE employee_id=" . $E('r13') . " AND event_type='sign_out'"); return $man($mf(['event_type' => 'sign_out', 'event_at' => $t('17:30')])); });
$trace['crons_after_manual'] = $cron();

// ---------------------------------------------------------------- 6. Reset Day
$reset = function (array $f, $nonce = 'ews31_time_reset') use ($post) { return $post('admin_time_reset', $f, $nonce); };
$run('reset_denied', function () use ($asUser, $plain, $reset, $E, $D0) { return $asUser($plain, function () use ($reset, $E, $D0) { return $reset(['employee_id' => $E('r13'), 'work_date' => $D0]); }); });
$run('reset_bad_nonce', function () use ($reset, $E, $D0) { return $reset(['employee_id' => $E('r13'), 'work_date' => $D0], 'bad'); });
$run('reset_invalid', function () use ($reset) { return $reset(['employee_id' => 0, 'work_date' => 'x']); });
$as((int) $c['atru_mgr']);
$run('reset_scope', function () use ($reset, $E, $D0) { return $reset(['employee_id' => $E('other'), 'work_date' => $D0]); });
$as($admin);
$brk($E('r13'), $D0 . ' 12:00:00');
$trace['crons_before_reset'] = $cron();
$run('reset_with_breaks', function () use ($reset, $E, $D0) { return $reset(['employee_id' => $E('r13'), 'work_date' => $D0]); });
$trace['crons_after_reset'] = $cron();
$run('reset_nothing_left', function () use ($reset, $E, $D0) { return $reset(['employee_id' => $E('r13'), 'work_date' => $D0]); });
$run('reset_with_correction_rows', function () use ($reset, $c, $D0) { return $reset(['employee_id' => (int) ($c['mona'] ?? 0), 'work_date' => $D0]); });
$faulty('reset_fault_delete', '/^DELETE FROM\s+`?' . preg_quote($p, '/') . 'ews_time_logs/i', function () use ($reset, $E, $D0, $log) { $log($E('r2'), 'sign_in', $D0, $D0 . ' 00:00:07'); return $reset(['employee_id' => $E('r2'), 'work_date' => $D0]); });
// a weekly rule records the day again after a reset (AT-r)
$wpdb->query("DELETE FROM {$p}ews_auto_attendance_rules WHERE employee_id=" . $E('r2'));
$rule($E('r2'), 'weekly', null, '0,1,2,3,4,5,6', $hm(-10), null);
$log($E('r2'), 'sign_in', $D0, $D0 . ' 00:00:07');
$reset(['employee_id' => $E('r2'), 'work_date' => $D0]);
$as(0);
$run('tick_after_reset', function () use ($call) { return $call('process_auto_attendance'); });

// ---------------------------------------------------------------- the tables, at the end
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
$ids = implode(',', array_map('intval', array_merge(array_map($E, ['r1', 'r2', 'r3', 'r4', 'r5', 'r6', 'r7', 'r8', 'r9', 'r10', 'r11', 'r12', 'r13', 'other', 'mgr']), array_map($W, ['a1', 'a2', 'a3', 'a4', 'off']), [(int) ($c['mona'] ?? 0)])));
foreach ([
    'time_logs' => "SELECT * FROM {$p}ews_time_logs WHERE employee_id IN ({$ids}) AND work_date IN ('{$D0}','{$DM1}') ORDER BY id",
    'break_sessions' => "SELECT * FROM {$p}ews_break_sessions WHERE id>{$baseBreak} ORDER BY id",
    'rules' => "SELECT * FROM {$p}ews_auto_attendance_rules ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
echo wp_json_encode($trace);
