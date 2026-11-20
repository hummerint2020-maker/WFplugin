<?php
/**
 * The golden harness's shift swap trace (tests/golden/run.py; docs/tasks/swap-fixes.md §10, 3.31.156; extended by the L3a move,
 * docs/tasks/schedule-rest.md §3.1): the swap handlers driven in process (tests/golden/exit.php) from the same snapshot on the old and
 * the new code. It seeds its own people and days after the snapshot, so the shared seed and the pages do not change.
 * For each call: what it returned (or where it redirected), the exact SQL in order (dates and times as placeholders), the query count,
 * the pushes queued, and the swap row it touched; at the end every swap, schedule, approval, notification and audit row of the seed.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_swap.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';

$admin = (int) get_user_by('login', 'admin')->ID;
$T = $p . 'ews_schedule_swaps';
$D = function ($n) use ($D0) { return gmdate('Y-m-d', strtotime($D0 . ($n < 0 ? ' ' : ' +') . $n . ' days')); };
for ($i = -2; $i <= 20; $i++) $dates[$D($i)] = 'D' . ($i < 0 ? '' : '+') . $i;
$fix = function ($s) use (&$dates) { return strtr($s, $dates); };
update_option('ews_working_days', [0, 1, 2, 3, 4, 5, 6], false);
delete_option('ews_feature_my_approvals');
delete_option('ews_feature_week_lock');

// ---------------------------------------------------------------- the people (direct SQL: no swap code)
$wpdb->insert($p . 'ews_departments', ['name' => 'Swap Ops', 'code' => 'swops', 'active' => 1]); $dOps = (int) $wpdb->insert_id;
$wpdb->insert($p . 'ews_departments', ['name' => 'Swap Sales', 'code' => 'swsales', 'active' => 1]); $dSales = (int) $wpdb->insert_id;
$user = function ($login, $role = 'subscriber') {
    $id = (int) (username_exists($login) ?: wp_create_user($login, $login . '-pass', $login . '@example.com'));
    (new WP_User($id))->set_role($role);
    return $id;
};
$emp = function ($login, $name, $dept, $role = 'subscriber') use ($wpdb, $p, $user) {
    $uid = $user($login, $role);
    $wpdb->insert($p . 'ews_employees', ['name' => $name, 'domain_name' => $login, 'email' => $login . '@example.com', 'wp_user_id' => $uid, 'active' => 1, 'attendance_enabled' => 1, 'department_id' => $dept]);
    return ['id' => (int) $wpdb->insert_id, 'uid' => $uid];
};
$P = ['req' => $emp('swreq', 'Sw Requester', $dOps), 'peer' => $emp('swpeer', 'Sw Peer', $dOps), 'peer2' => $emp('swpeer2', 'Sw Peer Two', $dOps),
      'other' => $emp('swother', 'Sw Other', $dSales), 'appr' => $emp('swappr', 'Sw Approver', $dOps), 'mgr' => $emp('swmgr', 'Sw Manager', $dOps),
      'sub' => $emp('swsub', 'Sw Substitute', $dOps), 'adm' => $emp('swadm', 'Sw Admin', $dOps, 'administrator')];
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $P['req']['id'], 'relationship_type' => 'supervisor', 'related_employee_id' => $P['mgr']['id'], 'active' => 1]);
$ids = array_map(function ($x) { return $x['id']; }, $P);
$names = [];
foreach ($P as $k => $x) $names['=' . $x['id'] . '\b'] = '=E_' . $k;

$day = function ($who, $n, $status) use ($wpdb, $p, $P, $D) {
    $wpdb->delete($p . 'ews_schedule', ['employee_id' => $P[$who]['id'], 'work_date' => $D($n)]);
    $wpdb->insert($p . 'ews_schedule', ['employee_id' => $P[$who]['id'], 'work_date' => $D($n), 'status' => $status]);
};
$seedSwap = function ($a, $b, $n, $sa, $sb, $status = 'Pending') use ($wpdb, $T, $P, $D) {
    $wpdb->insert($T, ['requester_employee_id' => $P[$a]['id'], 'target_employee_id' => $P[$b]['id'], 'work_date' => $D($n), 'requester_status' => $sa, 'target_status' => $sb,
        'status' => $status, 'requested_by' => $P[$a]['uid'], 'created_at' => current_time('mysql')]);
    return (int) $wpdb->insert_id;
};
$last = function () use ($wpdb, $T) { return (int) $wpdb->get_var("SELECT MAX(id) FROM {$T}"); };
$wfMode = function ($mode, $approver = 0, $active = 1) use ($wpdb, $p) {
    $id = (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='shift_swap'");
    $wpdb->update($p . 'ews_approval_workflows', ['approval_mode' => $mode, 'active' => $mode === 'OFF' ? 0 : 1], ['id' => $id]);
    $wpdb->delete($p . 'ews_approval_workflow_steps', ['workflow_id' => $id]);
    if ($mode === 'PEER') $wpdb->insert($p . 'ews_approval_workflow_steps', ['workflow_id' => $id, 'step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'TARGET_EMPLOYEE', 'resolver_value' => 0, 'required' => 1, 'active' => 1]);
    if ($mode === 'LEVEL_1') $wpdb->insert($p . 'ews_approval_workflow_steps', ['workflow_id' => $id, 'step_order' => 1, 'step_type' => 'APPROVAL',
        'resolver_type' => $approver ? 'SPECIFIC_EMPLOYEE' : 'SUPERVISOR', 'resolver_value' => $approver ?: null, 'required' => 1, 'active' => $active]);
};

// Every swap call records the pushes it queued and the swap row it left (who answered / decided set or not).
$pushes = new ReflectionProperty('EWS_Manager_V31_1', 'ews_push_queue');
$pushes->setAccessible(true);
$row = function ($id) use ($wpdb, $T) {
    $r = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$T} WHERE id=%d", (int) $id), ARRAY_A);
    if (!$r) return null;
    $out = ['status' => $r['status'], 'requester_status' => $r['requester_status'], 'target_status' => $r['target_status']];
    foreach (['responded_by', 'responded_at', 'decided_by', 'decided_at'] as $k) if (array_key_exists($k, $r)) $out[$k] = $r[$k] === null || $r[$k] === '' || $r[$k] === '0' ? 'unset' : 'set';
    return $out;
};
$sw = function ($label, $id, callable $fn, $fault = null) use (&$trace, $run, $faulty, $pushes, $row, $names) {
    $pushes->setValue(null, []);
    $_POST = [];
    $fault === null ? $run($label, $fn) : $faulty($label, $fault, $fn);
    $trace[$label]['pushes'] = count((array) $pushes->getValue());
    $trace[$label]['swap'] = $id ? $row(is_callable($id) ? $id() : $id) : null;
    foreach ($trace[$label]['sql'] as &$s) $s = preg_replace(array_map(function ($k) { return '/' . $k . '/'; }, array_keys($names)), array_values($names), $s);
};
$create = function ($as, $target, $n) use ($post, $P, $D) {
    wp_set_current_user($P[$as]['uid']);
    return $post('swap_request_create', ['target_employee_id' => is_int($target) ? $target : $P[$target]['id'], 'work_date' => is_int($n) ? $D($n) : $n], 'ews_swap_create');
};
$respond = function ($as, $id, $decision) use ($post, $P) { wp_set_current_user($P[$as]['uid']); return $post('swap_request_respond', ['swap_id' => $id, 'decision' => $decision], 'ews_swap_respond_' . $id); };
$cancel = function ($as, $id) use ($post, $P) { wp_set_current_user($P[$as]['uid']); return $post('swap_request_cancel', ['swap_id' => $id], 'ews_swap_cancel_' . $id); };
$decide = function ($as, $id, $decision) use ($post, $P) { wp_set_current_user($P[$as]['uid']); return $post('swap_manager_decide', ['swap_id' => $id, 'decision' => $decision], 'ews_swap_decide_' . $id); };
$hub = function ($id, $decision) use ($call, $admin) { wp_set_current_user($admin); $_POST = []; return $call('swap_admin_decide', $id, $decision); };
$schedRe = function ($who, $n) use ($p, $P, $D) { return '/^\s*SELECT \* FROM \S*ews_schedule WHERE employee_id=' . $P[$who]['id'] . ' AND work_date=\'' . $D($n) . '\' LIMIT 1\s*$/'; };
$schedAs = function ($who, $n, $status) use ($p, $P, $D) { return "SELECT id,employee_id,work_date,'" . $status . "' AS status FROM {$p}ews_schedule WHERE employee_id=" . $P[$who]['id'] . " AND work_date='" . $D($n) . "' LIMIT 1"; };

// ---------------------------------------------------------------- 1. asking for a swap (the colleague alone decides)
$wfMode('OFF');
foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $n) { $day('req', $n, 'Office'); $day('peer', $n, 'WFH'); $day('peer2', $n, 'WFH'); $day('other', $n, 'WFH'); $day('adm', $n, 'Office'); }
$day('req', -1, 'Office'); $day('peer', -1, 'WFH');
$sw('create_bad_nonce', 0, function () use ($post, $P) { wp_set_current_user($P['req']['uid']); return $post('swap_request_create', ['target_employee_id' => $P['peer']['id'], 'work_date' => '2030-01-01'], 'bad'); });
$sw('create_self', 0, function () use ($create) { return $create('req', 'req', 1); });
$sw('create_past', 0, function () use ($create) { return $create('req', 'peer', -1); });
$sw('create_other_department', 0, function () use ($create) { return $create('req', 'other', 1); });
$day('peer', 2, 'Office');
$sw('create_not_swappable', 0, function () use ($create) { return $create('req', 'peer', 2); });
$day('peer', 2, 'WFH');
$sw('create_ok', $last, function () use ($create) { return $create('req', 'peer', 1); });
$S1 = $last();
$sw('create_pair_duplicate', 0, function () use ($create) { return $create('req', 'peer', 1); });
$sw('create_reverse_pair', 0, function () use ($create) { return $create('peer', 'req', 1); });
$sw('create_second_colleague', 0, function () use ($create) { return $create('req', 'peer2', 1); });
$sw('create_insert_fault', 0, function () use ($create) { return $create('req', 'peer', 2); }, '/^\s*INSERT INTO `\S*ews_schedule_swaps`/');
$sw('create_busy', 0, function () use ($create) { return $create('peer2', 'req', 3); }, ['/GET_LOCK/', 'SELECT 0']);
$sw('create_admin_other_department', $last, function () use ($create) { return $create('adm', 'other', 4); });
update_option('ews_feature_my_approvals', 1, false);
$sw('create_ok_inbox', $last, function () use ($create) { return $create('req', 'peer', 5); });
delete_option('ews_feature_my_approvals');
$sw('new_choices', 0, function () use ($call, $P) { wp_set_current_user($P['req']['uid']); return $call('swap_new_choices', $call('people_employee', $P['req']['id'])); });
$sw('my_requests', 0, function () use ($call, $P) { wp_set_current_user($P['req']['uid']); return $call('swap_my_requests', $P['req']['id']); });

// ---------------------------------------------------------------- 2. the colleague answers
$sw('respond_reject', $S1, function () use ($respond, $S1) { return $respond('peer', $S1, 'reject'); });
$S2 = $seedSwap('req', 'peer', 6, 'Office', 'WFH');
$sw('respond_accept_peer', $S2, function () use ($respond, $S2) { return $respond('peer', $S2, 'accept'); });
$S3 = $seedSwap('req', 'peer', 7, 'Office', 'WFH');
$day('peer', 7, 'Office');
$sw('respond_accept_changed', $S3, function () use ($respond, $S3) { return $respond('peer', $S3, 'accept'); });
$day('peer', 7, 'WFH');
$sw('respond_accept_start_fault', $S3, function () use ($respond, $S3) { return $respond('peer', $S3, 'accept'); }, '/^START TRANSACTION/');
$sw('respond_accept_second_write_fault', $S3, function () use ($respond, $S3) { return $respond('peer', $S3, 'accept'); }, ['/^\s*UPDATE `\S*ews_schedule` SET/', 'SELECT broken_on_purpose FROM no_such_table_x', 2]);
// The colleague asked a swap of their own for that day, then is asked one (owner, 2026-11-20: one commitment per person per day).
$S4own = $seedSwap('peer', 'peer2', 8, 'WFH', 'Office');
$day('peer2', 8, 'Office');
$S4 = $seedSwap('req', 'peer', 8, 'Office', 'WFH');
$sw('respond_accept_committed', $S4, function () use ($respond, $S4) { return $respond('peer', $S4, 'accept'); });

// ---------------------------------------------------------------- 3. cancel and withdraw
$S5 = $seedSwap('req', 'peer', 9, 'Office', 'WFH');
$sw('cancel_ok', $S5, function () use ($cancel, $S5) { return $cancel('req', $S5); });
$sw('cancel_not_found', 0, function () use ($cancel) { return $cancel('req', 987654); });
$S6 = $seedSwap('req', 'peer', -1, 'Office', 'WFH');
$sw('cancel_past', $S6, function () use ($cancel, $S6) { return $cancel('req', $S6); });
$S7 = $seedSwap('req', 'peer', 10, 'Office', 'WFH');
$sw('withdraw_ok', $S7, function () use ($call, $P, $S7) { wp_set_current_user($P['req']['uid']); return $call('swap_withdraw', $S7, $P['req']['id']); });
$sw('withdraw_past', $S6, function () use ($call, $P, $S6) { wp_set_current_user($P['req']['uid']); return $call('swap_withdraw', $S6, $P['req']['id']); });

// ---------------------------------------------------------------- 4. the administrator decides (wp-admin Requests)
foreach ([11, 12, 13, 14, 15, 16, 17, 18] as $n) { $day('req', $n, 'Office'); $day('peer', $n, 'WFH'); $day('peer2', $n, 'WFH'); }
$S8 = $seedSwap('req', 'peer', 11, 'Office', 'WFH');
$sw('hub_direct_approve', $S8, function () use ($hub, $S8) { return $hub($S8, 'approve'); });
$S9 = $seedSwap('req', 'peer', 12, 'Office', 'WFH');
$sw('hub_direct_reject', $S9, function () use ($hub, $S9) { return $hub($S9, 'reject'); });
$S10 = $seedSwap('req', 'peer', -2, 'Office', 'WFH');
$day('req', -2, 'Office'); $day('peer', -2, 'WFH');
$sw('hub_direct_approve_past', $S10, function () use ($hub, $S10) { return $hub($S10, 'approve'); });

// ---------------------------------------------------------------- 5. with an approver after the colleague
$wfMode('LEVEL_1', $P['appr']['id']);
$S11 = $seedSwap('req', 'peer', 13, 'Office', 'WFH');
$sw('respond_accept_levels', $S11, function () use ($respond, $S11) { return $respond('peer', $S11, 'accept'); });
$sw('awaiting_list_approver', 0, function () use ($call, $P) { wp_set_current_user($P['appr']['uid']); return $call('swap_awaiting_my_approval'); });
$sw('decide_last_approve', $S11, function () use ($decide, $S11) { return $decide('appr', $S11, 'approve'); });
$S12 = $seedSwap('req', 'peer', 14, 'Office', 'WFH');
$respond('peer', $S12, 'accept');
$sw('decide_reject', $S12, function () use ($decide, $S12) { return $decide('appr', $S12, 'reject'); });
$S13 = $seedSwap('req', 'peer', 15, 'Office', 'WFH');
$respond('peer', $S13, 'accept');
$sw('hub_level_approve_last', $S13, function () use ($hub, $S13) { return $hub($S13, 'approve'); });
$S14 = $seedSwap('req', 'peer', 16, 'Office', 'WFH');
$respond('peer', $S14, 'accept');
$sw('hub_level_reject', $S14, function () use ($hub, $S14) { return $hub($S14, 'reject'); });
// A swap accepted and waiting for the approver is a commitment of the colleague who accepted it: they cannot ask another for that day.
$S15 = $seedSwap('req', 'peer', 17, 'Office', 'WFH');
$respond('peer', $S15, 'accept');
$day('peer2', 17, 'Office');
$sw('create_after_accepting', $last, function () use ($create) { return $create('peer', 'peer2', 17); });
$sw('my_requests_levels', 0, function () use ($call, $P) { wp_set_current_user($P['req']['uid']); return $call('swap_my_requests', $P['req']['id']); });

// Approved at once. The engine approves a request at its start only when every level is skipped by an amount condition, which a swap
// (no amount) never meets today; the branch is defensive (docs/tasks/swap-fixes.md, SW-d). Reached here by having the engine record the
// request as approved when it is created.
$approveAtStart = false;
add_filter('query', function ($q) use (&$approveAtStart) {
    return $approveAtStart && preg_match('/^\s*INSERT INTO `\S*ews_approval_requests`/', $q) ? str_replace("'WAITING_FOR_LEVEL_1'", "'APPROVED'", $q) : $q;
}, 4);
$S16 = $seedSwap('req', 'peer', 18, 'Office', 'WFH');
$approveAtStart = true;
$sw('respond_accept_auto_approved', $S16, function () use ($respond, $S16) { return $respond('peer', $S16, 'accept'); });
$day('req', 19, 'Office'); $day('peer', 19, 'WFH');
$S17 = $seedSwap('req', 'peer', 19, 'Office', 'WFH');
$sw('respond_accept_auto_changed', $S17, function () use ($respond, $S17) { return $respond('peer', $S17, 'accept'); }, [$schedRe('req', 19), $schedAs('req', 19, 'Vacation'), 2]);
$approveAtStart = false;

// ---------------------------------------------------------------- 6. a manager away with a substitute (My approvals' rule)
update_option('ews_feature_substitutes', 1, false);
update_option('ews_substitute_settings', ['manager_decides' => 1], false);
$wpdb->insert($p . 'ews_approval_substitutes', ['manager_employee_id' => $P['mgr']['id'], 'substitute_employee_id' => $P['sub']['id'], 'starts_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'ends_at' => gmdate('Y-m-d H:i:s', time() + 7 * 86400),
    'status' => 'active', 'source' => 'hr', 'reason' => '', 'decided' => 0, 'created_by' => $admin, 'created_at' => gmdate('Y-m-d H:i:s'), 'started_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
$wfMode('LEVEL_1');   // the requester's supervisor (Sw Manager), away: the level goes to the substitute
$day('req', 20, 'Office'); $day('peer', 20, 'WFH');
$S18 = $seedSwap('req', 'peer', 20, 'Office', 'WFH');
$respond('peer', $S18, 'accept');
$sw('awaiting_list_substitute', 0, function () use ($call, $P) { wp_set_current_user($P['sub']['uid']); return $call('swap_awaiting_my_approval'); });
$sw('awaiting_list_away_manager', 0, function () use ($call, $P) { wp_set_current_user($P['mgr']['uid']); return $call('swap_awaiting_my_approval'); });
update_option('ews_substitute_settings', ['manager_decides' => 0], false);
$sw('awaiting_list_away_manager_not_allowed', 0, function () use ($call, $P) { wp_set_current_user($P['mgr']['uid']); return $call('swap_awaiting_my_approval'); });
delete_option('ews_substitute_settings');
delete_option('ews_feature_substitutes');

// ---------------------------------------------------------------- the rows at the end
$in = implode(',', array_map('intval', $ids));
$rows = function ($q) use ($wpdb, $norm, $names) {
    $out = $norm($wpdb->get_results($q, ARRAY_A));
    return json_decode(preg_replace(array_map(function ($k) { return '/' . $k . '/'; }, array_keys($names)), array_values($names), wp_json_encode($out)), true);
};
$trace['rows_swaps'] = $rows("SELECT * FROM {$T} ORDER BY id");
$trace['rows_schedule'] = $rows("SELECT employee_id,work_date,status FROM {$p}ews_schedule WHERE employee_id IN ($in) ORDER BY employee_id,work_date");
$trace['rows_approvals'] = $rows("SELECT ar.entity_id,ar.status,st.step_order,st.status step_status FROM {$p}ews_approval_requests ar LEFT JOIN {$p}ews_approval_steps st ON st.approval_request_id=ar.id WHERE ar.entity_type='schedule_swap' ORDER BY ar.id,st.step_order");
$trace['rows_notifications'] = $rows("SELECT user_id,type,entity,entity_id,title,message,link FROM {$p}ews_notifications WHERE entity='swap' ORDER BY id");
$trace['rows_audit'] = $rows("SELECT action,entity,entity_id,details FROM {$p}ews_audit_log WHERE entity='schedule_swap' ORDER BY id");
echo wp_json_encode(json_decode($fix(wp_json_encode($trace)), true));
