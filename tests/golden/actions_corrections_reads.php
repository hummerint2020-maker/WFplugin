<?php
/**
 * The golden harness's attendance corrections reads (tests/golden/run.py; docs/tasks/attendance-corrections.md §4, L5e1): the day's events, the month count,
 * the pending request, the facts of a request, when an employee started, days without work, one request, who may decide and see it, the recent days, what a
 * decider has to decide (without a workflow, with levels, HR), the evidence of a request, the app's Corrections page and the wp-admin pages (list with every
 * filter, report, settings, direct) for HR, a manager and a plain user, with the feature on and off. Driven in process (tests/golden/exit.php). For each call:
 * what it returned, the exact SQL (in order, dates and times as placeholders), the query count. The same script runs on the old and the new code from the
 * same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_corrections_reads.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';

$admin = (int) get_user_by('login', 'admin')->ID;
$E = function ($k) use ($c) { return (int) $c['atr_' . $k]; };
$EU = function ($k) use ($c) { return (int) $c['atru_' . $k]; };
$emp = function ($id) use ($call) { return $call('people_employee', (int) $id); };
$gdreqU = (int) get_user_by('login', 'gdreq')->ID;
$gdmgrU = (int) get_user_by('login', 'gdmgr')->ID;
$hrU = (int) $c['cx_hr_user'];
$plainU = (int) $c['atwu_a1'];
$D = function ($back) use ($D0) { return gmdate('Y-m-d', strtotime($D0 . ' -' . $back . ' days')); };
update_option('ews_feature_corrections', 1, false);
update_option('ews_correction_settings', ['deadline_days' => 7, 'monthly_limit' => 3], false);
(new WP_User($plainU))->remove_cap('ews_manage_corrections');   // user meta is not in the snapshot: nothing this script grants outlives it
$wf = function ($mode) use ($wpdb, $p) {
    $id = (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'");
    $wpdb->update($p . 'ews_approval_workflows', ['approval_mode' => $mode === '' ? 'NONE' : $mode, 'active' => $mode === '' ? 0 : 1], ['id' => $id]);
    $wpdb->delete($p . 'ews_approval_workflow_steps', ['workflow_id' => $id]);
    if ($mode !== '' && $mode !== 'NONE') $wpdb->insert($p . 'ews_approval_workflow_steps', ['workflow_id' => $id, 'step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'SUPERVISOR', 'required' => 1, 'active' => 1]);
    return $id;
};
$page = function ($label, $uid, $method, array $get) use (&$trace, &$sql, $o, $as, $admin, $dates) {
    $as($uid);
    wp_cache_flush();
    $sql = [];
    $r = golden_page($o, $method, $get);
    $html = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', 'T', (string) $r['html']);
    $html = strtr($html, $dates);
    $html = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', $html);
    $html = preg_replace('/(name="_wpnonce"[^>]*value=")[0-9a-f]+/', '$1N', $html);
    $html = preg_replace('/(nonce["\']?\s*[:=]\s*["\'])[0-9a-f]{8,}/i', '$1N', $html);
    $html = preg_replace('/\b\d{1,2}:\d{2} ?[AP]M\b/', 'HM', $html);
    $html = preg_replace('/\b\d{2}:\d{2}\b/', 'HM', $html);
    $html = preg_replace('#https?://[^/"\']+#', '', $html);
    $trace[$label] = ['return' => ['exit' => $r['exit'], 'to' => $r['to'], 'len' => strlen($html), 'html' => md5($html), 'body' => $html], 'queries' => count($sql), 'sql' => $sql];
    $as($admin);
};
$html = function ($label, $uid, $method) use ($run, $as, $call, $admin, $dates) {
    $as($uid);
    $run($label, function () use ($call, $method, $dates) {
        $h = (string) $call($method);
        $h = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($h, $dates));
        $h = preg_replace('/(name="_wpnonce"[^>]*value=")[0-9a-f]+/', '$1N', $h);
        $h = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', 'T', $h);
        $h = preg_replace('/\b\d{1,2}:\d{2} ?[AP]M\b/', 'HM', $h);
        $h = preg_replace('/\b\d{2}:\d{2}\b/', 'HM', $h);
        return ['len' => strlen($h), 'html' => md5($h), 'body' => $h];
    });
    $as($admin);
};
$as($admin);
$r13 = $emp($E('r13'));
$omar = $emp($c['omar']);
$mona = $emp($c['mona']);
$lina = $emp($c['lina']);
$gdreq = $emp($c['cx_gdreq']);

// ---------------------------------------------------------------- the day, the counts, the facts
foreach ([['omar', $c['omar'], $D0], ['mona_back2', $c['mona'], $D(2)], ['r13_3', $E('r13'), $D(3)], ['r13_5', $E('r13'), $D(5)], ['lina_4_superseded', $c['lina'], $D(4)], ['nobody', 99999999, $D0]] as [$k, $eid, $d]) {
    $run('day_' . $k, function () use ($call, $eid, $d) { return $call('cx_day', (int) $eid, $d); });
}
$run('month_count_r13', function () use ($call, $E, $D) { return $call('cx_month_count', $E('r13'), $D(3)); });
$run('month_count_lina', function () use ($call, $c, $D) { return $call('cx_month_count', (int) $c['lina'], $D(4)); });
$run('month_count_none', function () use ($call, $D0) { return $call('cx_month_count', 99999999, $D0); });
$run('pending_for_day_yes', function () use ($call, $E, $D) { return $call('cx_pending_for_day', $E('r13'), $D(3)); });
$run('pending_for_day_no', function () use ($call, $E, $D) { return $call('cx_pending_for_day', $E('r13'), $D(5)); });
$run('started_r13', function () use ($call, $r13) { return $call('cx_started', $r13); });
$run('started_no_created_at', function () use ($call, $omar) { $x = clone $omar; $x->created_at = ''; return $call('cx_started', $x); });
$run('started_old_first_event', function () use ($call, $c) { $x = (object) ['id' => (int) $c['mona'], 'created_at' => gmdate('Y-m-d H:i:s', time() + 86400)]; return $call('cx_started', $x); });
foreach ([['today', $D0], ['yesterday', $D(1)], ['d3', $D(3)], ['d5', $D(5)], ['future', gmdate('Y-m-d', strtotime($D0 . ' +9 days'))]] as [$k, $d]) {
    $run('no_work_' . $k, function () use ($call, $r13, $d) { return $call('cx_no_work_day', $r13, $d); });
}
$run('no_work_leave_employee', function () use ($call, $c, $emp, $D0) { return $call('cx_no_work_day', $emp($c['atw_leave']), $D0); });
$facts = [
    'valid_out' => ['date' => $D(3), 'type' => 'out', 'time_out' => '17:00', 'reason' => 'forgot to sign out'],
    'valid_time' => ['date' => $D(5), 'type' => 'time', 'target' => 'out', 'time_out' => '16:30', 'reason' => 'left later'],
    'valid_day' => ['date' => $D(2), 'type' => 'day', 'time_in' => '08:00', 'time_out' => '16:00', 'reason' => 'forgot both'],
    'bad_date' => ['date' => 'nonsense', 'type' => 'out', 'reason' => ''],
    'no_date' => [],
    'future_date' => ['date' => gmdate('Y-m-d', strtotime($D0 . ' +3 days')), 'type' => 'in', 'time_in' => '08:00', 'reason' => 'x'],
    'today_out' => ['date' => $D0, 'type' => 'out', 'time_out' => '23:59', 'reason' => 'x'],
    'old_day' => ['date' => $D(30), 'type' => 'in', 'time_in' => '08:00', 'reason' => 'x'],
];
foreach ($facts as $k => $in) {
    $run('facts_' . $k, function () use ($call, $r13, $in) { return $call('cx_facts', $r13, $in, 'employee', false); });
}
$run('facts_hr_with_photo', function () use ($call, $mona, $facts) { return $call('cx_facts', $mona, $facts['valid_day'], 'hr', true); });
$faulty('facts_month_count_fault', '/SELECT COUNT\(\*\) FROM\s+`?' . preg_quote($p, '/') . 'ews_attendance_corrections/i', function () use ($call, $r13, $facts) { return $call('cx_facts', $r13, $facts['valid_out'], 'employee', false); });

// ---------------------------------------------------------------- one request, who may decide and see it (without a workflow, then with levels)
$wf('NONE');
$id = function ($k) use ($c) { return (int) $c['cx_' . $k]; };
foreach (['pending', 'approved', 'pending_hr', 'rejected', 'levels', 'other_dept', 'photo'] as $k) {
    $run('get_' . $k, function () use ($call, $id, $k) { return $call('cx_get', $id($k)); });
}
$run('get_none', function () use ($call) { return $call('cx_get', 99999999); });
$run('workflow_mode_none', function () use ($call) { return $call('cx_workflow_mode'); });
$who = ['admin' => $admin, 'mgr' => $EU('mgr'), 'hr' => $hrU, 'plain' => $plainU, 'owner' => $EU('r13'), 'logged_out' => 0];
foreach (['pending', 'pending_hr', 'approved', 'other_dept'] as $k) {
    foreach ($who as $wk => $uid) {
        $as($uid);
        $run('can_decide_' . $k . '_' . $wk, function () use ($call, $id, $k) { return $call('cx_can_decide', $call('cx_get', $id($k))); });
        $run('can_view_' . $k . '_' . $wk, function () use ($call, $id, $k) { return $call('cx_can_view', $call('cx_get', $id($k))); });
    }
}
$as($admin);
$run('can_decide_null', function () use ($call) { return $call('cx_can_decide', null); });
$wf('LEVEL_1');
$run('workflow_mode_levels', function () use ($call) { return $call('cx_workflow_mode'); });
// the approval request of the level-1 request: written with plain SQL, as seed_approvals.php does
$wfId = (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'");
$wpdb->delete($p . 'ews_approval_steps', ['approval_request_id' => (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_requests WHERE entity_type='attendance_correction' AND entity_id=" . $id('levels'))]);
$wpdb->delete($p . 'ews_approval_requests', ['entity_type' => 'attendance_correction', 'entity_id' => $id('levels')]);
$wpdb->insert($p . 'ews_approval_requests', ['workflow_id' => $wfId, 'entity_type' => 'attendance_correction', 'entity_id' => $id('levels'), 'requester_employee_id' => $gdreq->id, 'status' => 'WAITING_FOR_LEVEL_1', 'current_step' => 1, 'created_at' => $D(2) . ' 17:00:00']);
$arId = (int) $wpdb->insert_id;
$gdmgrEmp = (int) $wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE domain_name='gdmgr' ORDER BY id LIMIT 1");
$wpdb->insert($p . 'ews_approval_steps', ['approval_request_id' => $arId, 'step_order' => 1, 'resolver_type' => 'SUPERVISOR', 'approver_employee_id' => $gdmgrEmp, 'approver_wp_user_id' => $gdmgrU, 'status' => 'PENDING', 'rerouted' => 0, 'pending_since' => $D(2) . ' 17:00:00', 'created_at' => $D(2) . ' 17:00:00']);
$who2 = ['gdmgr' => $gdmgrU, 'admin' => $admin, 'mgr' => $EU('mgr'), 'gdreq' => $gdreqU];
foreach ($who2 as $wk => $uid) {
    $as($uid);
    $run('can_decide_levels_' . $wk, function () use ($call, $id) { return $call('cx_can_decide', $call('cx_get', $id('levels'))); });
    $run('can_view_levels_' . $wk, function () use ($call, $id) { return $call('cx_can_view', $call('cx_get', $id('levels'))); });
}
$as($admin);

// ---------------------------------------------------------------- the recent days, what is to decide, the evidence, the lists
$asUser = function ($uid, callable $fn) use ($as, $admin) { $as($uid); try { return $fn(); } finally { $as($admin); } };
foreach (['r13' => $r13, 'omar' => $omar, 'lina' => $lina, 'gdreq' => $gdreq] as $k => $e) {
    $run('recent_days_' . $k, function () use ($call, $e) { return $call('cx_recent_days', $e); });
}
$run('recent_days_none', function () use ($call) { return $call('cx_recent_days', null); });
foreach (['none' => 'NONE', 'levels' => 'LEVEL_1'] as $mk => $mode) {
    $wf($mode);
    foreach (['mgr' => $EU('mgr'), 'hr' => $hrU, 'gdmgr' => $gdmgrU, 'admin' => $admin, 'plain' => $plainU, 'r13' => $EU('r13')] as $wk => $uid) {
        $as($uid);
        $run('to_decide_' . $mk . '_' . $wk, function () use ($call) { return $call('cx_to_decide'); });
    }
}
$as($admin);
$wf('NONE');
foreach (['pending', 'approved', 'photo', 'levels'] as $k) {
    $run('evidence_' . $k, function () use ($call, $id, $k) { return $call('cx_evidence', $call('cx_get', $id($k))); });
}
$run('hub_rows', function () use ($call) { return $call('cx_hub_rows'); });
$run('my_requests_r13', function () use ($call, $E) { return $call('cx_my_requests', $E('r13'), 20); });
$run('my_requests_limit1', function () use ($call, $E) { return $call('cx_my_requests', $E('r13'), 1); });
$run('my_requests_none', function () use ($call) { return $call('cx_my_requests', 99999999); });
$run('manager_name', function () use ($call, $gdreq) { return $call('cx_manager_name', $gdreq); });

// ---------------------------------------------------------------- the pages
$dir = $call('private_files_dir', 'workforce-one-corrections');
file_put_contents($dir . '/' . str_repeat('p', 32) . '.jpg', 'GOLDENPROOF');
$html('app_page_admin', $admin, 'corrections_content');
$html('app_page_employee', $EU('r13'), 'corrections_content');
$html('app_page_manager', $EU('mgr'), 'corrections_content');
$html('app_page_hr', $hrU, 'corrections_content');
$html('app_page_no_employee', $plainU, 'corrections_content');
$mo = substr($D(3), 0, 7);
$tabs = ['list' => [], 'list_pending' => ['status' => 'pending'], 'list_hr' => ['status' => 'pending_hr'], 'list_all' => ['status' => 'all'], 'list_type' => ['type' => 'out'], 'list_dept' => ['department' => (int) $c['atr_deptA']],
    'list_search' => ['s' => 'rec'], 'list_search_percent' => ['s' => '5%_'], 'list_page2' => ['paged' => 2], 'list_bad_month' => ['month' => 'x'], 'report' => ['tab' => 'report'], 'report_dept' => ['tab' => 'report', 'department' => (int) $c['atr_deptA']],
    'settings' => ['tab' => 'settings'], 'direct' => ['tab' => 'direct'], 'direct_error' => ['tab' => 'direct', 'cx_error' => 'reason'], 'bad_tab' => ['tab' => 'nonsense']];
foreach ($tabs as $k => $tabGet) {
    $page('admin_' . $k, $hrU, 'admin_corrections', $tabGet + ['month' => $mo]);
}
$page('admin_plain_user', $plainU, 'admin_corrections', []);
$page('admin_manager', $EU('mgr'), 'admin_corrections', []);
update_option('ews_feature_corrections', 0, false);
$html('app_page_off', $EU('r13'), 'corrections_content');
$page('admin_list_off', $hrU, 'admin_corrections', ['month' => $mo]);
$run('recent_days_off', function () use ($call, $r13) { return $call('cx_recent_days', $r13); });
update_option('ews_feature_corrections', 1, false);

// ---------------------------------------------------------------- the photo (the answer; the file is a few bytes of text) and the export's refusals
$photo = function ($label, $uid, $cid, $nonce = null) use ($run, $o, $as, $admin) {
    $as($uid);
    $run($label, function () use ($o, $cid, $nonce) { return golden_get($o, 'correction_photo', ['id' => $cid], $nonce ?? 'ews_correction_photo_' . $cid); });   // the file's bytes are printed before the last line
    $as($admin);
};
$photo('photo_admin', $admin, $id('photo'));
$photo('photo_hr', $hrU, $id('photo'));
$photo('photo_manager_of_other_dept', $EU('mgr'), $id('photo'));
$photo('photo_owner_without_photo', $EU('r13'), $id('pending'));
$photo('photo_plain', $plainU, $id('photo'));
$photo('photo_logged_out', 0, $id('photo'));
$photo('photo_bad_nonce', $admin, $id('photo'), 'bad');
$photo('photo_unknown', $admin, 99999999);
$run('export_not_hr', function () use ($get) { wp_set_current_user(0); return $get('corrections_export', ['month' => '2020-01'], 'ews_corrections_export'); });
$as($admin);

// ---------------------------------------------------------------- the tables, at the end
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
foreach ([
    'corrections' => "SELECT * FROM {$p}ews_attendance_corrections ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
$wf('NONE');
echo "\n" . wp_json_encode($trace);
