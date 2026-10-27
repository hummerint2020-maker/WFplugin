<?php
/**
 * The golden harness's approval trace (tests/golden/run.py; docs/tasks/approvals-engine-move.md §3): the approvals engine
 * driven in process through its own functions, for every request type and the paths that matter (start, decide, reject,
 * levels, the self rule, a substitute, withdraw, deadlines, org-move reassign, the path and inbox reads, workflow save).
 * For each call it records what it returned, the exact SQL it ran (in order, times and the clock as placeholders, so a
 * reordered or extra statement shows), and at the end every approval, notification and audit row and the approval options.
 * The same script runs on the old and the new code from the same snapshot; the two traces must be equal.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/actions_approvals.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- the people, the workflows and the settings (direct SQL: no engine code)
$user = function ($login) { return (int) (username_exists($login) ?: wp_create_user($login, $login . '-pass', $login . '@example.com')); };
$emp = function ($login, $name) use ($wpdb, $p, $user) {
    $uid = $user($login);
    $wpdb->insert($p . 'ews_employees', ['name' => $name, 'domain_name' => $login, 'email' => $login . '@example.com', 'wp_user_id' => $uid, 'active' => 1, 'attendance_enabled' => 1]);
    return ['id' => (int) $wpdb->insert_id, 'uid' => $uid];
};
$P = ['req' => $emp('apreq', 'Ap Requester'), 'mgr' => $emp('apmgr', 'Ap Manager'), 'boss' => $emp('apboss', 'Ap Boss'), 'hr' => $emp('aphr', 'Ap HR'),
      'sub' => $emp('apsub', 'Ap Substitute'), 'other' => $emp('apother', 'Ap Other'), 'lone' => $emp('aplone', 'Ap Lone')];
$admin = (int) get_user_by('login', 'admin')->ID;
foreach ([['req', 'mgr'], ['mgr', 'boss']] as [$a, $b]) $wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $P[$a]['id'], 'relationship_type' => 'supervisor', 'related_employee_id' => $P[$b]['id'], 'active' => 1]);
update_option('ews_working_days', [0, 1, 2, 3, 4, 5, 6], false);
update_option('ews_approval_settings', ['hr_user' => $P['hr']['uid'], 'finance_user' => 0, 'day_start' => '09:00', 'day_end' => '17:00'], false);
update_option('ews_feature_substitutes', 1, false);
$wf = function ($key, $mode, array $steps = []) use ($wpdb, $p) {
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%s", $key));
    $wpdb->update($p . 'ews_approval_workflows', ['approval_mode' => $mode, 'active' => 1], ['id' => $id]);
    $wpdb->delete($p . 'ews_approval_workflow_steps', ['workflow_id' => $id]);
    foreach ($steps as $i => $s) {
        $row = ['workflow_id' => $id, 'step_order' => $i + 1, 'step_type' => 'APPROVAL', 'resolver_type' => $s[0], 'resolver_value' => $s[1] ?? null, 'required' => 1, 'active' => 1];
        if (isset($s[2])) $row['min_amount'] = $s[2];
        $wpdb->insert($p . 'ews_approval_workflow_steps', $row);
    }
};
$wf('vacation', 'SEQUENTIAL', [['SUPERVISOR'], ['MANAGERS_MANAGER']]);
$wf('overtime', 'SEQUENTIAL', [['SUPERVISOR']]);
$wf('early_leave', 'NONE');
$wf('attendance_correction', 'SEQUENTIAL', [['SUPERVISOR'], ['HR']]);
$wf('salary_advance', 'SEQUENTIAL', [['SUPERVISOR', null, '100.00'], ['SPECIFIC_USER', (string) $P['hr']['uid']]]);
$wf('shift_swap', 'PEER');
$wf('face_reset', 'SEQUENTIAL', [['SPECIFIC_EMPLOYEE', (string) $P['lone']['id']]]);

// ---------------------------------------------------------------- recording
$sql = [];
add_filter('query', function ($q) use (&$sql) {
    $q = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $sql[] = trim(preg_replace('/\s+/', ' ', $q));
    return $q;
}, 1);
$norm = function ($v) use (&$norm) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => $v->get_error_message()];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) $o[$k] = $norm($x); return $o; }
    if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
    return $v;
};
$trace = [];
$as = function ($uid) { wp_set_current_user((int) $uid); };
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm, $wpdb) {
    $sql = [];
    $q0 = $wpdb->num_queries;
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};
$start = function ($key, $et, $eid, $req, $ctx = [], $manage = true) use ($call) { return $call('approval_start', $key, $et, $eid, $req, $ctx, $manage); };
$act = function ($id, $decision, $comment = '', $manage = true, $override = false, $expected = null) use ($call) { return $call('approval_act', $id, $decision, $comment, $manage, $override, $expected); };
$rid = function ($key, $et, $eid) use ($wpdb, $p) { return (int) $wpdb->get_var($wpdb->prepare("SELECT ar.id FROM {$p}ews_approval_requests ar JOIN {$p}ews_approval_workflows w ON w.id=ar.workflow_id WHERE w.workflow_key=%s AND ar.entity_type=%s AND ar.entity_id=%d ORDER BY ar.id DESC LIMIT 1", $key, $et, $eid)); };

// ---------------------------------------------------------------- 1. a leave request through two levels
$as($P['req']['uid']);
$run('leave_start', function () use ($start, $P) { return $start('vacation', 'leave', 901, $P['req']['id']); });
$run('leave_start_again', function () use ($start, $P) { return $start('vacation', 'leave', 901, $P['req']['id']); });
$L = $rid('vacation', 'leave', 901);
$run('leave_find', function () use ($call) { return $call('approval_find_request', 'vacation', 'leave', 901); });
$run('leave_current_step', function () use ($call, $L) { return $call('approval_current_step', $L); });
$run('leave_current_steps', function () use ($call, $L) { return $call('approval_current_steps', [$L, 999999]); });
$run('leave_find_many', function () use ($call) { return $call('approval_find_requests', [['vacation', 'leave', 901], ['vacation', 'leave', 902], ['overtime', 'overtime_request', 1]]); });
foreach (['req' => $P['req']['uid'], 'mgr' => $P['mgr']['uid'], 'boss' => $P['boss']['uid'], 'admin' => $admin] as $who => $uid) {
    $as($uid);
    $run('leave_can_act_' . $who, function () use ($call, $L) { return $call('approval_can_act', $L); });
}
$as(0);
$run('leave_can_act_nobody', function () use ($call, $L) { return $call('approval_can_act', $L); });
$as($P['boss']['uid']);
$run('leave_act_wrong_user', function () use ($act, $L) { return $act($L, 'approve'); });
$as($P['mgr']['uid']);
$run('leave_act_bad_decision', function () use ($act, $L) { return $act($L, 'maybe'); });
$run('leave_act_unknown', function () use ($act) { return $act(999999, 'approve'); });
$run('leave_act_moved', function () use ($act, $L) { return $act($L, 'approve', '', true, false, 12345); });
$run('leave_level_moved', function () use ($call, $L) { return [$call('approval_level_moved', $L, 12345), $call('approval_level_moved', $L, null)]; });
$run('leave_act_level1', function () use ($act, $L) { return $act($L, 'approve', 'ok'); });
$run('leave_act_level1_again', function () use ($act, $L) { return $act($L, 'approve'); });
$as($P['boss']['uid']);
$run('leave_shown_steps', function () use ($call) { return $call('approval_shown_steps', [['vacation', 'leave', 901], ['vacation', 'leave', 902]]); });
$run('leave_act_level2', function () use ($act, $L) { return $act($L, 'approve', 'fine'); });
$run('leave_act_after_done', function () use ($act, $L) { return $act($L, 'approve'); });

// ---------------------------------------------------------------- 2. overtime: reject, with and without a reason; an administrator's override
$as($P['req']['uid']);
$run('ot_start', function () use ($start, $P) { return $start('overtime', 'overtime_request', 911, $P['req']['id']); });
$O = $rid('overtime', 'overtime_request', 911);
update_option('ews_approval_reject_note', ['overtime' => 1], false);
$as($P['mgr']['uid']);
$run('ot_reject_no_note', function () use ($act, $O) { return $act($O, 'reject'); });
$run('ot_reject', function () use ($act, $O) { return $act($O, 'reject', 'no budget'); });
$as($P['req']['uid']);
$run('ot2_start', function () use ($start, $P) { return $start('overtime', 'overtime_request', 912, $P['req']['id']); });
$O2 = $rid('overtime', 'overtime_request', 912);
$as($admin);
$run('ot2_override_reject', function () use ($act, $O2) { return $act($O2, 'reject', '', true, true); });
delete_option('ews_approval_reject_note');

// ---------------------------------------------------------------- 3. the self rule: the approver is the requester
foreach (['allow', 'next', 'admins'] as $rule) {
    update_option('ews_approval_self_rule', ['attendance_correction' => $rule], false);
    $as($P['mgr']['uid']);   // apmgr's own correction: level 1 is their manager (apboss), level 2 is HR
    $wf('attendance_correction', 'SEQUENTIAL', [['SPECIFIC_EMPLOYEE', (string) $P['mgr']['id']], ['HR']]);
    $eid = 920 + ($rule === 'allow' ? 0 : ($rule === 'next' ? 1 : 2));
    $run('self_' . $rule . '_start', function () use ($start, $P, $eid) { return $start('attendance_correction', 'attendance_correction', $eid, $P['mgr']['id'], [], false); });
    $R = $rid('attendance_correction', 'attendance_correction', $eid);
    $run('self_' . $rule . '_act_self', function () use ($act, $R) { return $act($R, 'approve', '', false); });
    $as($P['hr']['uid']);
    $run('self_' . $rule . '_act_hr', function () use ($act, $R) { return $act($R, 'approve', '', false); });
    $as($admin);
    $run('self_' . $rule . '_act_admin', function () use ($act, $R) { return $act($R, 'approve', '', false); });
}
$wf('attendance_correction', 'SEQUENTIAL', [['SUPERVISOR'], ['HR']]);
delete_option('ews_approval_self_rule');

// ---------------------------------------------------------------- 4. a caller that owns the transaction: kept, and rolled back
$as($P['req']['uid']);
$run('tx_start_committed', function () use ($start, $P, $wpdb) { $wpdb->query('START TRANSACTION'); $r = $start('face_reset', 'face_reset_request', 931, $P['lone']['id'], [], false); $wpdb->query('COMMIT'); return $r; });
$run('tx_start_rolled_back', function () use ($start, $P, $wpdb) { $wpdb->query('START TRANSACTION'); $r = $start('face_reset', 'face_reset_request', 932, $P['lone']['id'], [], false); $wpdb->query('ROLLBACK'); return $r; });
$run('tx_act_rolled_back', function () use ($act, $P, $wpdb, $rid) { $as = wp_set_current_user($P['lone']['uid']); $wpdb->query('START TRANSACTION'); $r = $act($rid('face_reset', 'face_reset_request', 931), 'approve', '', false); $wpdb->query('ROLLBACK'); return $r; });
$run('tx_act_committed', function () use ($act, $P, $wpdb, $rid) { wp_set_current_user($P['lone']['uid']); $wpdb->query('START TRANSACTION'); $r = $act($rid('face_reset', 'face_reset_request', 931), 'approve', '', false); $wpdb->query('COMMIT'); return $r; });

// ---------------------------------------------------------------- 5. levels with a condition, HR, nobody to decide, a missing colleague, no workflow
$as($P['req']['uid']);
$run('advance_small_start', function () use ($start, $P) { return $start('salary_advance', 'salary_advance', 941, $P['req']['id'], ['amount' => 50]); });
$run('advance_big_start', function () use ($start, $P) { return $start('salary_advance', 'salary_advance', 942, $P['req']['id'], ['amount' => 500]); });
$run('correction_start_hr', function () use ($start, $P) { return $start('attendance_correction', 'attendance_correction', 943, $P['lone']['id']); });   // no manager: administrators
$run('swap_start_no_target', function () use ($start, $P) { return $start('shift_swap', 'schedule_swap', 944, $P['req']['id']); });
$run('swap_start', function () use ($start, $P) { return $start('shift_swap', 'schedule_swap', 945, $P['req']['id'], ['target_employee_id' => $P['other']['id']]); });
$run('swap_start_missing_target', function () use ($start, $P) { return $start('shift_swap', 'schedule_swap', 946, $P['req']['id'], ['target_employee_id' => 987654]); });
$run('early_leave_start_none', function () use ($start, $P) { return $start('early_leave', 'early_leave', 947, $P['req']['id']); });
$run('unknown_workflow', function () use ($start, $P) { return $start('no_such', 'x', 1, $P['req']['id']); });
$as($P['other']['uid']);
$S = $rid('shift_swap', 'schedule_swap', 945);
$run('swap_act_peer', function () use ($act, $S) { return $act($S, 'approve'); });

// ---------------------------------------------------------------- 6. a substitute while the manager is away
$wpdb->insert($p . 'ews_approval_substitutes', ['manager_employee_id' => $P['mgr']['id'], 'substitute_employee_id' => $P['sub']['id'], 'starts_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'ends_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
    'status' => 'active', 'source' => 'hr', 'reason' => '', 'decided' => 0, 'created_by' => $admin, 'created_at' => gmdate('Y-m-d H:i:s'), 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
$as($P['req']['uid']);
$run('sub_start', function () use ($start, $P) { return $start('vacation', 'leave', 951, $P['req']['id'], [], false); });
$SB = $rid('vacation', 'leave', 951);
foreach (['sub' => $P['sub']['uid'], 'mgr' => $P['mgr']['uid'], 'other' => $P['other']['uid']] as $who => $uid) {
    $as($uid);
    $run('sub_can_act_' . $who, function () use ($call, $SB) { return $call('approval_can_act', $SB); });
}
update_option('ews_substitute_settings', ['manager_decides' => 0], false);
$as($P['mgr']['uid']);
$run('sub_can_act_mgr_not_allowed', function () use ($call, $SB) { return $call('approval_can_act', $SB); });
delete_option('ews_substitute_settings');
$as($P['sub']['uid']);
$run('sub_act_level1', function () use ($act, $SB) { return $act($SB, 'approve', 'covering'); });
$as($P['boss']['uid']);
$run('sub_act_level2', function () use ($act, $SB) { return $act($SB, 'approve'); });

// ---------------------------------------------------------------- 7. withdraw and close
$as($P['req']['uid']);
$run('withdraw_start', function () use ($start, $P) { return $start('overtime', 'overtime_request', 961, $P['req']['id'], [], false); });
$run('withdraw_close', function () use ($call, $wpdb) { $wpdb->query('START TRANSACTION'); $r = $call('approval_close', 'overtime', 'overtime_request', 961); $wpdb->query('COMMIT'); return $r; });
$run('withdraw_close_decided', function () use ($call) { return $call('approval_close', 'vacation', 'leave', 901); });
$run('withdraw_close_none', function () use ($call) { return $call('approval_close', 'overtime', 'overtime_request', 424242); });
$run('close_open_start', function () use ($start, $P) { return $start('attendance_correction', 'attendance_correction', 962, $P['req']['id'], [], false); });
$run('close_open_system', function () use ($call, $wpdb) { $wpdb->query('START TRANSACTION'); $r = $call('approval_close_open', 'attendance_correction', 'attendance_correction', 962, true); $wpdb->query('COMMIT'); return $r; });
$run('close_open_again', function () use ($call) { return $call('approval_close_open', 'attendance_correction', 'attendance_correction', 962, false); });
$run('close_open_none', function () use ($call) { return $call('approval_close_open', 'attendance_correction', 'attendance_correction', 424242, false); });
$run('close_open_decided', function () use ($call) { return $call('approval_close_open', 'vacation', 'leave', 901, false); });

// ---------------------------------------------------------------- 8. deadlines: remind, then move up, to the manager's manager and to the administrators
update_option('ews_approval_deadlines', ['vacation' => ['remind' => 4, 'escalate' => 16], 'overtime' => ['remind' => 4, 'escalate' => 8]], false);
$as($P['req']['uid']);
$run('dl_start_vacation', function () use ($start, $P) { return $start('vacation', 'leave', 971, $P['req']['id'], [], false); });
$run('dl_start_overtime_no_mgr', function () use ($start, $P) { return $start('overtime', 'overtime_request', 972, $P['boss']['id'], [], false); });   // boss has no manager
$base = time();
$tick = function ($hours) use ($call, $base) { return $call('approval_deadlines_tick', gmdate('Y-m-d H:i:s', $base + $hours * 3600)); };
$run('dl_tick_early', function () use ($tick) { return $tick(1); });
$run('dl_tick_remind', function () use ($tick) { return $tick(100 * 0 + 5 * 24); });
$run('dl_tick_remind_again', function () use ($tick) { return $tick(5 * 24 + 1); });
$run('dl_tick_escalate', function () use ($tick) { return $tick(30 * 24); });
$run('dl_tick_escalate_admins', function () use ($tick) { return $tick(90 * 24); });
delete_option('ews_approval_deadlines');

// ---------------------------------------------------------------- 9. an employee moved under another manager
$as($admin);
$run('reassign_start', function () use ($start, $P) { return $start('vacation', 'leave', 981, $P['req']['id'], [], false); });
$run('reassign_waiting', function () use ($call) { return $call('approval_waiting_on_supervisors'); });
$run('reassign_move', function () use ($call, $P) { $pending = 0; $r = $call('approval_reassign_supervisor', $P['req']['id'], $P['mgr']['id'], $P['other']['id'], $pending); return [$r, $pending]; });
$run('reassign_none', function () use ($call, $P) { $pending = 0; $r = $call('approval_reassign_supervisor', $P['req']['id'], $P['mgr']['id'], $P['other']['id'], $pending); return [$r, $pending]; });
$run('reassign_notify', function () use ($call, $P) { return $call('approval_reassign_notify', $P['req']['id'], $P['other']['id'], 1); });

// ---------------------------------------------------------------- 10. reads for pages
$keys = [['vacation', 'leave', 901], ['vacation', 'leave', 951], ['vacation', 'leave', 971], ['overtime', 'overtime_request', 911], ['attendance_correction', 'attendance_correction', 962], ['vacation', 'leave', 555]];
$nowfix = gmdate('Y-m-d H:i:s', $base + 3 * 86400);
$run('paths', function () use ($call, $keys, $nowfix) { return $call('approval_paths', $keys, $nowfix); });
$run('path_one', function () use ($call) { return $call('approval_path', 'vacation', 'leave', 971); });
$run('workflow_one', function () use ($call) { return $call('approval_workflow', 'vacation'); });
$run('workflow_key_by_id', function () use ($call, $wpdb, $p) { return $call('approval_workflow_key_by_id', (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='overtime'")); });
$as($P['mgr']['uid']);
update_option('ews_feature_my_approvals', 1, false);
$run('inbox_items_mgr', function () use ($call) { return $call('my_approvals_items'); });
$run('inbox_allowed_mgr', function () use ($call) { return $call('my_approvals_allowed'); });

// ---------------------------------------------------------------- 11. saving a workflow
$run('configure_ok', function () use ($call) { return $call('approval_configure_workflow', 'overtime', 'SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR', 'resolver_value' => 0], 2 => ['resolver_type' => 'HR', 'resolver_value' => 0, 'min_amount' => '3.5']], \WorkforceOne\Approvals\Workflows::IN_USE['overtime'][1]); });
$run('configure_bad_mode', function () use ($call) { return $call('approval_configure_workflow', 'overtime', 'LEVEL_9', [], \WorkforceOne\Approvals\Workflows::IN_USE['overtime'][1]); });
$run('configure_unknown', function () use ($call) { return $call('approval_configure_workflow', 'no_such', 'NONE', [], null); });
$run('configure_no_steps', function () use ($call) { return $call('approval_configure_workflow', 'overtime', 'SEQUENTIAL', [], \WorkforceOne\Approvals\Workflows::IN_USE['overtime'][1]); });

// ---------------------------------------------------------------- the deferred work (administrators told later, the substitutes sync), then what was left behind
global $wp_filter;
$keep = [];
if (isset($wp_filter['shutdown'])) foreach ($wp_filter['shutdown']->callbacks as $prio => $cbs) foreach ($cbs as $cb) {
    $f = $cb['function'];
    if ($f instanceof Closure && (new ReflectionFunction($f))->getClosureThis() === $o) $keep[] = [$prio, $f];
}
remove_all_actions('shutdown');
foreach ($keep as [$prio, $f]) add_action('shutdown', $f, $prio);
$run('shutdown_deferred', function () { do_action('shutdown'); return count($GLOBALS['wp_filter']['shutdown']->callbacks ?? []) >= 0; });
remove_all_actions('shutdown');
$as(0);
$rows = function ($sqlstr) use ($wpdb, $norm) { return $norm($wpdb->get_results($sqlstr, ARRAY_A)); };
$trace['_rows'] = [
    'requests' => $rows("SELECT * FROM {$p}ews_approval_requests ORDER BY id"),
    'steps' => $rows("SELECT * FROM {$p}ews_approval_steps ORDER BY id"),
    'substitutes' => $rows("SELECT * FROM {$p}ews_approval_substitutes ORDER BY id"),
    'workflow_steps' => $rows("SELECT * FROM {$p}ews_approval_workflow_steps ORDER BY id"),
    'workflows' => $rows("SELECT * FROM {$p}ews_approval_workflows ORDER BY id"),
    'notifications' => $rows("SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id"),
    'audit' => $rows("SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id"),
];
echo wp_json_encode($trace);
