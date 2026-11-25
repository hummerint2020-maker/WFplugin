<?php
/**
 * The golden harness's leave return and cancellation trace (tests/golden/run.py; docs/tasks/leave-return-layers.md §5, 3.31.164): the
 * cancellation applied with Leave return on (from a day, all of it, a day a manager changed, every day in a closed payroll month, next
 * year's carried days, a snapshot DELETE that fails) and off (all of it, from a day, past its end), the refusal, a cancellation asked
 * and withdrawn, the employee's change through its own handler, the return HR records and "Put the day back to leave" through their
 * admin-post handlers (tests/golden/exit.php). For each call: what it returned or where it redirected, the exact SQL in order (dates
 * and times as placeholders) and the query count; at the end the leave rows, balances, snapshots, rounds, schedule and audit. The same
 * script runs on the old and the new code from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_leave_return.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/exit.php';
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- dates as placeholders (the leaves are in the future: open payroll months)
$D0 = current_time('Y-m-d');
$day = function ($n) use ($D0) { return gmdate('Y-m-d', strtotime($D0 . ' +' . $n . ' days')); };
$dates = [];
for ($i = 40; $i >= 0; $i--) $dates[$day($i)] = 'D+' . $i;
$year = (int) substr($day(10), 0, 4);
$fix = function ($s) use ($dates) { return strtr($s, $dates); };

// ---------------------------------------------------------------- recording (with in-process fault injection)
$sql = [];
$fault = null;
add_filter('query', function ($q) use (&$sql, $fix) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $n = preg_replace("/(_transient_timeout_\w+', ')\d+/", '$1TS', $n);
    $n = preg_replace('/\b\d{13}\b/', 'TS', $n);
    $n = preg_replace("/SET `option_value` = '.*?' WHERE `option_name` = 'cron'/s", "SET `option_value` = 'CRON' WHERE `option_name` = 'cron'", $n);
    $n = preg_replace('/wfo_[0-9a-f]+_/', 'wfo_H_', $n);
    $sql[] = $fix(trim(preg_replace('/\s+/', ' ', $n)));
    return $q;
}, 1);
add_filter('query', function ($q) use (&$fault) {
    if ($fault === null) return $q;
    [$re, $to] = is_array($fault) ? $fault : [$fault, 'SELECT broken_on_purpose FROM no_such_table_x'];
    return preg_match($re, $q) ? $to : $q;
}, 5);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code()];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) $out[$k] = $norm($x);
        return $out;
    }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        $v = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($v, $dates));
        $v = preg_replace('#^https?://[^/]+#', '', $v);
        $v = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $v);
    }
    return $v;
};
$trace = [];
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm, $o) {
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
$state = function () use ($o) {
    $get = function ($n) use ($o) { $r = new ReflectionProperty($o, $n); $r->setAccessible(true); return $r->getValue($o); };
    return ['outcome' => $get('leave_cancel_outcome'), 'applied' => $get('leave_return_applied')];
};

// ---------------------------------------------------------------- the employee, a leave type, balances (plain SQL, not traced)
$ids = json_decode((string) file_get_contents(rtrim((string) getenv('S'), '/') . '/ids.json'));
$E = (int) $ids->eid;
$admin = (int) get_user_by('login', 'admin')->ID;
$empUser = (int) get_user_by('login', 'emp1')->ID;
wp_set_current_user($admin);
$call('ensure_leave_schema');
foreach (['ews_leave_requests', 'ews_leave_balances', 'ews_leave_schedule_snapshots', 'ews_leave_changes', 'ews_payroll_runs'] as $t) $wpdb->query("DELETE FROM {$p}{$t}");
$wpdb->query("DELETE FROM {$p}ews_schedule WHERE employee_id={$E} AND work_date>='{$D0}'");
$wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0 WHERE workflow_key='vacation'");
update_option('ews_working_days', [0, 1, 2, 3, 4, 5, 6], false);
update_option('ews_feature_my_approvals', 1, false);
$wpdb->insert($p . 'ews_leave_types', ['name' => 'Golden Return', 'active' => 1, 'deduct_balance' => 1, 'annual_entitlement' => 30]);
$T = (int) $wpdb->insert_id;
$wpdb->query("UPDATE {$p}ews_leave_types SET kind='planned',carry_mode='all',carry_max=0 WHERE id={$T}");
$wpdb->insert($p . 'ews_leave_balances', ['employee_id' => $E, 'leave_type_id' => $T, 'leave_year' => $year, 'entitlement' => 30, 'used' => 60, 'pending' => 0]);
$B = (int) $wpdb->insert_id;
// A leave of $n days from D+$from, its schedule (Office before, Vacation now) and its snapshots.
$leave = function ($from, $n, $cancel = 'Pending', $cancelFrom = null) use ($wpdb, $p, $E, $T, $B, $day, $empUser) {
    $wpdb->insert($p . 'ews_leave_requests', ['employee_id' => $E, 'leave_type_id' => $T, 'start_date' => $day($from), 'end_date' => $day($from + $n - 1), 'requested_days' => $n,
        'status' => 'Approved', 'cancellation_status' => $cancel, 'requested_by' => $empUser, 'balance_id' => $B, 'cancel_from' => $cancelFrom !== null ? $day($cancelFrom) : null]);
    $id = (int) $wpdb->insert_id;
    for ($i = 0; $i < $n; $i++) {
        $d = $day($from + $i);
        $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date=%s", $E, $d));
        $had = $i % 2 === 0;
        $sid = null;
        if ($had) { $wpdb->insert($p . 'ews_schedule', ['employee_id' => $E, 'work_date' => $d, 'status' => 'Vacation', 'note' => 'Approved leave #' . $id]); $sid = (int) $wpdb->insert_id; }
        else $wpdb->insert($p . 'ews_schedule', ['employee_id' => $E, 'work_date' => $d, 'status' => 'Vacation', 'note' => 'Approved leave #' . $id]);
        $wpdb->insert($p . 'ews_leave_schedule_snapshots', ['leave_request_id' => $id, 'employee_id' => $E, 'work_date' => $d, 'had_schedule' => $had ? 1 : 0, 'schedule_id' => $sid, 'status' => $had ? 'Office' : null, 'note' => $had ? 'before' : null]);
    }
    return $id;
};
$round = function ($id, $from) use ($wpdb, $p, $E, $day, $empUser) {
    $wpdb->insert($p . 'ews_leave_changes', ['leave_request_id' => $id, 'employee_id' => $E, 'round' => 1, 'source' => 'employee', 'asked_from' => $day($from), 'status' => 'Pending', 'end_before' => $day(30), 'requested_by' => $empUser]);
};
$emp = $call('people_employee', $E);
$apply = function ($id) use ($call, $emp, $wpdb, $state) {
    $r = $call('leave_lock_row', $id);
    $bal = $call('leave_request_balance', $r);
    $wpdb->query('START TRANSACTION');
    $res = $call('leave_apply_cancellation', $r, $emp, $bal, current_time('mysql'));
    $wpdb->query('COMMIT');
    return ['result' => $res] + $state();
};

// ---------------------------------------------------------------- 1. Leave return on: the cancellation applied
update_option('ews_feature_leave_return', 1, false);
$L1 = $leave(3, 5, 'Pending', 5); $round($L1, 5);
$run('return_from_a_day', function () use ($apply, $L1) { return $apply($L1); });
$L2 = $leave(10, 3, 'Pending', null);
$run('return_all_of_it', function () use ($apply, $L2) { return $apply($L2); });
$L3 = $leave(14, 4, 'Pending', 15);
$wpdb->update($p . 'ews_schedule', ['status' => 'WFH'], ['employee_id' => $E, 'work_date' => $day(16)]);
$run('return_manager_edit_kept', function () use ($apply, $L3) { return $apply($L3); });
$L4 = $leave(19, 2, 'Pending', 19);
$wpdb->insert($p . 'ews_leave_balances', ['employee_id' => $E, 'leave_type_id' => $T, 'leave_year' => $year + 1, 'entitlement' => 30, 'used' => 0, 'pending' => 0]);
$wpdb->query("UPDATE {$p}ews_leave_balances SET used=2 WHERE id={$B}");
$run('return_carry_top_up', function () use ($apply, $L4) { return $apply($L4); });
$wpdb->query("DELETE FROM {$p}ews_leave_balances WHERE leave_year=" . ($year + 1));
$wpdb->query("UPDATE {$p}ews_leave_balances SET used=60 WHERE id={$B}");
$L5 = $leave(22, 3, 'Pending', 23);
$faulty('return_fault_snapshot_delete', '/^DELETE FROM\s+`?' . preg_quote($p, '/') . 'ews_leave_schedule_snapshots/i', function () use ($apply, $L5) { return $apply($L5); });
$L6 = $leave(26, 2, 'Pending', 26);
$closeMonth = function ($m) use ($wpdb, $p) { $wpdb->insert($p . 'ews_payroll_runs', ['month' => $m, 'employees' => 1, 'total_net' => 0, 'currency' => 'EGP', 'closed_by' => 1, 'closed_at' => current_time('mysql')]); };
$closeMonth(substr($day(26), 0, 7));
if (substr($day(27), 0, 7) !== substr($day(26), 0, 7)) $closeMonth(substr($day(27), 0, 7));
$run('return_closed_month', function () use ($apply, $L6) { return $apply($L6); });
$wpdb->query("DELETE FROM {$p}ews_payroll_runs");
$L7 = $leave(29, 2, 'Approved', null);
$run('return_moved', function () use ($call, $emp, $L7) { return $call('leave_apply_cancellation', $call('leave_lock_row', $L7), $emp, null, current_time('mysql')); });

// ---------------------------------------------------------------- 2. refuse, ask, withdraw
$L8 = $leave(32, 2, 'Pending', null); $round($L8, 32);
$run('reject', function () use ($call, $L8) { return $call('leave_reject_cancellation', $L8); });
$run('reject_again', function () use ($call, $L8) { return $call('leave_reject_cancellation', $L8); });
$L9 = $leave(35, 2, null, null);
$run('mark_pending_from', function () use ($call, $L9, $day) { return $call('leave_mark_cancellation_pending', $L9, $day(36)); });
$run('withdraw', function () use ($call, $L9, $E) { return $call('leave_cancel_withdraw', $L9, $E); });
$run('withdraw_again', function () use ($call, $L9, $E) { return $call('leave_cancel_withdraw', $L9, $E); });
$run('round_and_days', function () use ($call, $L1, $day) { return [$call('leave_change_current', $L1), $call('leave_days_from', $L1, $day(3))]; });

// ---------------------------------------------------------------- 3. the employee's change (its handler; the switch and Leave in My requests on)
update_option('ews_feature_my_requests_leave', 1, false);
$L10 = $leave(38, 3, 'Rejected', null);
wp_set_current_user($empUser);
$run('employee_asks', function () use ($o, $L10, $day) { return golden_post($o, 'leave_cancel_request', ['request_id' => $L10, 'lr_mode' => 'back', 'back_on' => $day(39)], 'ews_leave_cancel'); });
$run('employee_asks_again', function () use ($o, $L10, $day) { return golden_post($o, 'leave_cancel_request', ['request_id' => $L10, 'lr_mode' => 'back', 'back_on' => $day(39)], 'ews_leave_cancel'); });
$run('employee_bad_day', function () use ($o, $L10) { return golden_post($o, 'leave_cancel_request', ['request_id' => $L10, 'lr_mode' => 'back', 'back_on' => 'x'], 'ews_leave_cancel'); });
wp_set_current_user($admin);

// ---------------------------------------------------------------- 4. HR records a return; "Put the day back to leave"
$wpdb->query("DELETE FROM {$p}ews_leave_requests WHERE id NOT IN ({$L1},{$L10})");
$H = $leave(5, 4, null, null);
$run('hr_no_reason', function () use ($o, $H, $day) { return golden_post($o, 'admin_leave_return_record', ['leave_id' => $H, 'back_on' => $day(7), 'reason' => '', 'expect_from' => $day(7), 'expect_days' => 2], 'ews_leave_return_record'); });
$run('hr_changed', function () use ($o, $H, $day) { return golden_post($o, 'admin_leave_return_record', ['leave_id' => $H, 'back_on' => $day(7), 'reason' => 'Back early', 'expect_from' => $day(7), 'expect_days' => 5], 'ews_leave_return_record'); });
$run('hr_records', function () use ($o, $H, $day, $state) { return golden_post($o, 'admin_leave_return_record', ['leave_id' => $H, 'back_on' => $day(7), 'reason' => 'Back early', 'expect_from' => $day(7), 'expect_days' => 2], 'ews_leave_return_record') + $state(); });
$snap = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date LIMIT 1", $H));
$run('put_back_kept', function () use ($o, $snap) { return golden_post($o, 'admin_leave_day_restore', ['snap_id' => $snap], 'ews_leave_day_restore'); });
$wpdb->update($p . 'ews_schedule', ['status' => 'Office'], ['employee_id' => $E, 'work_date' => $day(5)]);
$run('put_back', function () use ($o, $snap) { return golden_post($o, 'admin_leave_day_restore', ['snap_id' => $snap], 'ews_leave_day_restore'); });
$run('put_back_missing', function () use ($o) { return golden_post($o, 'admin_leave_day_restore', ['snap_id' => 999999], 'ews_leave_day_restore'); });

// ---------------------------------------------------------------- 5. Leave return off: the cancellation as before
delete_option('ews_feature_leave_return');
delete_option('ews_feature_my_requests_leave');
$K1 = $leave(3, 3, 'Pending', null);
$run('legacy_all_of_it', function () use ($apply, $K1) { return $apply($K1); });
$K2 = $leave(10, 4, 'Pending', 12);
$run('legacy_from_a_day', function () use ($apply, $K2) { return $apply($K2); });
$K3 = $leave(20, 2, 'Pending', 25);
$run('legacy_past_its_end', function () use ($apply, $K3) { return $apply($K3); });
$K4 = $leave(30, 2, 'Pending', null);
$run('legacy_no_balance', function () use ($call, $emp, $K4) { return $call('leave_apply_cancellation', $call('leave_lock_row', $K4), $emp, null, current_time('mysql')); });

// ---------------------------------------------------------------- the tables, at the end
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
foreach ([
    'leave_requests' => "SELECT * FROM {$p}ews_leave_requests ORDER BY id",
    'leave_balances' => "SELECT * FROM {$p}ews_leave_balances ORDER BY id",
    'snapshots' => "SELECT * FROM {$p}ews_leave_schedule_snapshots ORDER BY id",
    'leave_changes' => "SELECT * FROM {$p}ews_leave_changes ORDER BY id",
    'schedule' => "SELECT employee_id,work_date,status,note,updated_by FROM {$p}ews_schedule WHERE employee_id={$E} AND work_date>='{$D0}' ORDER BY work_date",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
echo wp_json_encode($trace);
