<?php
/**
 * The golden harness's approvals data (tests/golden/run.py; docs/tasks/approvals-engine-move.md §3.3): people, workflows,
 * supervisors, a substitute and open requests at different levels, written with plain SQL (no engine code), before the snapshot
 * is saved. The pages of pages.json (My approvals, My requests, the Requests Hub, Approval Workflows, Substitutes) read them.
 * Users: gdmgr and gdreq, password <login>-pass.
 * Usage: wp eval-file tests/golden/seed_approvals.php
 */
global $wpdb;
$p = $wpdb->prefix;
$user = function ($login) { return (int) (username_exists($login) ?: wp_create_user($login, $login . '-pass', $login . '@example.com')); };
$emp = function ($login, $name) use ($wpdb, $p, $user) {
    $uid = $user($login);
    $wpdb->insert($p . 'ews_employees', ['name' => $name, 'domain_name' => $login, 'email' => $login . '@example.com', 'wp_user_id' => $uid, 'active' => 1, 'attendance_enabled' => 1]);
    return ['id' => (int) $wpdb->insert_id, 'uid' => $uid];
};
$req = $emp('gdreq', 'Ap Requester');
$mgr = $emp('gdmgr', 'Ap Manager');
$sub = $emp('gdsub', 'Ap Substitute');
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $req['id'], 'relationship_type' => 'supervisor', 'related_employee_id' => $mgr['id'], 'active' => 1]);
$wf = function ($key, $mode, array $levels) use ($wpdb, $p) {
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%s", $key));
    $wpdb->update($p . 'ews_approval_workflows', ['approval_mode' => $mode, 'active' => 1], ['id' => $id]);
    $wpdb->delete($p . 'ews_approval_workflow_steps', ['workflow_id' => $id]);
    foreach ($levels as $i => $l) $wpdb->insert($p . 'ews_approval_workflow_steps', ['workflow_id' => $id, 'step_order' => $i + 1, 'step_type' => 'APPROVAL', 'resolver_type' => $l, 'required' => 1, 'active' => 1]);
    return $id;
};
$vacation = $wf('vacation', 'LEVEL_1', ['SUPERVISOR']);
$overtime = $wf('overtime', 'LEVEL_2', ['SUPERVISOR', 'HR']);
$now = current_time('mysql');
$earlier = gmdate('Y-m-d H:i:s', current_time('timestamp') - 3 * DAY_IN_SECONDS);
$request = function ($workflow, $type, $entity, $status, $step) use ($wpdb, $p, $req, $now) {
    $wpdb->insert($p . 'ews_approval_requests', ['workflow_id' => $workflow, 'entity_type' => $type, 'entity_id' => $entity, 'requester_employee_id' => $req['id'], 'status' => $status, 'current_step' => $step, 'created_at' => $now]);
    return (int) $wpdb->insert_id;
};
$level = function ($request, $order, $status, array $more = []) use ($wpdb, $p, $mgr, $now) {
    $wpdb->insert($p . 'ews_approval_steps', $more + ['approval_request_id' => $request, 'step_order' => $order, 'resolver_type' => 'SUPERVISOR', 'approver_employee_id' => $mgr['id'], 'approver_wp_user_id' => $mgr['uid'], 'status' => $status, 'rerouted' => 0, 'pending_since' => $status === 'PENDING' ? $now : null, 'created_at' => $now]);
};
$a = $request($vacation, 'leave', 9001, 'WAITING_FOR_LEVEL_1', 1);          // waiting for the manager
$level($a, 1, 'PENDING', ['pending_since' => $earlier]);
$b = $request($overtime, 'overtime_request', 9002, 'WAITING_FOR_LEVEL_2', 2);   // level 1 done, level 2 (HR) waits for administrators
$level($b, 1, 'APPROVED', ['decision' => 'approve', 'acted_at' => $earlier, 'acted_by_wp_user_id' => $mgr['uid'], 'comment' => 'ok']);
$level($b, 2, 'PENDING', ['approver_employee_id' => null, 'approver_wp_user_id' => null, 'rerouted' => 1, 'resolver_type' => 'HR']);
$c = $request($overtime, 'overtime_request', 9003, 'REJECTED', null);       // rejected, with the reason
$level($c, 1, 'REJECTED', ['decision' => 'reject', 'acted_at' => $earlier, 'acted_by_wp_user_id' => $mgr['uid'], 'comment' => 'no budget']);
$wpdb->insert($p . 'ews_approval_substitutes', ['manager_employee_id' => $mgr['id'], 'substitute_employee_id' => $sub['id'], 'starts_at' => gmdate('Y-m-d H:i:s', current_time('timestamp') + 5 * DAY_IN_SECONDS), 'ends_at' => gmdate('Y-m-d H:i:s', current_time('timestamp') + 9 * DAY_IN_SECONDS),
    'status' => 'scheduled', 'source' => 'hr', 'reason' => '', 'decided' => 0, 'created_by' => 1, 'created_at' => $now]);
update_option('ews_approval_deadlines', ['vacation' => ['remind' => 4, 'escalate' => 16]], false);
echo wp_json_encode(['req' => $req, 'mgr' => $mgr]);
