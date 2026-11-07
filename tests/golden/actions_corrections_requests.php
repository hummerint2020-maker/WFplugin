<?php
/**
 * The golden harness's attendance correction requests (tests/golden/run.py; docs/tasks/attendance-corrections.md §11.5, L5e2): cx_create for the
 * employee (every refusal of CorrectionRules::check, the feature off, no employee, the photo setting, a fake upload, each workflow mode: none set,
 * NONE, LEVEL_1) and for HR (applied at once, refused), faults on its writes (the correction INSERT, the engine's start, the second transaction of
 * the automatic approval, the manager row, the second time_logs INSERT, HR's finalize, the audit and the notifications); the three controllers
 * (the app's form, HR's direct form, the API); cx_withdraw in every state and with every fault; the end-of-day reminder (off, before the time,
 * first and second tick, other dates, overnight hours, faults). Driven in process (tests/golden/exit.php). For each call: what it returned, the
 * exact SQL (in order, dates and times as placeholders), the query count; the rows at the end. The same script runs on the old and the new code
 * from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_corrections_requests.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';

$admin = (int) get_user_by('login', 'admin')->ID;
$emp = function ($id) use ($call) { return $call('people_employee', (int) $id); };
$hrU = (int) $c['cx_hr_user'];
$mgrU = (int) $c['atru_mgr'];
$D = function ($back) use ($D0) { return gmdate('Y-m-d', strtotime($D0 . ' -' . $back . ' days')); };
$T = $p . 'ews_attendance_corrections';
$Tq = preg_quote($T, '/');
$Lq = preg_quote($p . 'ews_time_logs', '/');
$settings = function (array $more = []) {
    update_option('ews_correction_settings', array_replace_recursive(['types' => ['out' => 1, 'in' => 1, 'time' => 1, 'day' => 1], 'photo' => ['out' => 'off', 'in' => 'optional', 'time' => 'optional', 'day' => 'required'],
        'deadline_days' => 7, 'monthly_limit' => 3, 'above_limit' => 'hr', 'earlier_needs_hr' => 1, 'reminder' => 1, 'reminder_time' => '00:00'], $more), false);
};
update_option('ews_feature_corrections', 1, false);
update_option('ews_working_days', [0, 1, 2, 3, 4, 5, 6], false);   // every day a working day: a whole-day request does not depend on the weekday the harness runs
$settings();
delete_option('ews_corrections_reminded');
$wf = function ($mode) use ($wpdb, $p) {
    $id = (int) $wpdb->get_var("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key='attendance_correction'");
    $wpdb->update($p . 'ews_approval_workflows', ['approval_mode' => $mode === '' ? 'NONE' : $mode, 'active' => $mode === '' ? 0 : 1], ['id' => $id]);
    $wpdb->delete($p . 'ews_approval_workflow_steps', ['workflow_id' => $id]);
    if ($mode !== '' && $mode !== 'NONE') $wpdb->insert($p . 'ews_approval_workflow_steps', ['workflow_id' => $id, 'step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'SUPERVISOR', 'required' => 1, 'active' => 1]);
    return $id;
};
$hoursCache = new ReflectionProperty('EWS_Manager_V31_1', 'ews_working_hours_cache');
$hoursCache->setAccessible(true);
$apiSession = new ReflectionProperty('EWS_Manager_V31_1', 'api_session');
$apiSession->setAccessible(true);
// A request's fields as cx_input() gives them.
$in = function ($date, $type, array $more = []) {
    return array_merge(['date' => $date, 'type' => $type, 'target' => '', 'time_in' => '', 'time_out' => '', 'reason' => 'forgot'], $more);
};
// cx_create as a user (the employee, or HR), then back to the administrator.
$create = function ($label, $uid, $e, array $fields, $source = 'employee', $photo = null, $pattern = null) use ($run, $faulty, $call, $as, $admin) {
    $as($uid);
    $fn = function () use ($call, $e, $fields, $source, $photo) { return $call('cx_create', $e, $fields, $source, $photo); };
    if ($pattern === null) $run($label, $fn); else $faulty($label, $pattern, $fn);
    $as($admin);
};
$as($admin);
$A = $emp($c['cxq_a']); $AU = (int) $c['cxq_a_user'];
$B = $emp($c['cxq_b']); $BU = (int) $c['cxq_b_user'];
$C = $emp($c['cxq_c']); $CU = (int) $c['cxq_c_user'];
$Dd = $emp($c['cxq_d']); $DU = (int) $c['cxq_d_user'];
$r13 = $emp($c['atr_r13']); $r13U = (int) $c['atru_r13'];
$mona = $emp($c['mona']);

// a fake upload: is_uploaded_file() refuses it (a real one goes through run.py's HTTP step)
$tmp = wp_tempnam('golden-cx');
file_put_contents($tmp, "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
$fake = ['name' => 'proof.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 72];
// ---------------------------------------------------------------- every refusal (no workflow set: nothing is written)
$wf('');
$refusals = [
    'type_unknown' => $in($D(1), 'nonsense'),
    'date_bad' => $in('nonsense', 'out', ['time_out' => '17:00']),
    'future' => $in(gmdate('Y-m-d', strtotime($D0 . ' +2 days')), 'out', ['time_out' => '17:00']),
    'deadline' => $in($D(10), 'out', ['time_out' => '17:00']),
    'pending' => $in($D(3), 'out', ['time_out' => '17:00']),                      // r13 has a pending request that day (seed)
    'reason' => $in($D(1), 'out', ['time_out' => '17:00', 'reason' => '   ']),
    'photo_required' => $in($D(6), 'day', ['time_in' => '08:00', 'time_out' => '16:00']),
    'time_missing' => $in($D(1), 'out'),
    'time_bad' => $in($D(1), 'out', ['time_out' => '25:99']),
    'time_target' => $in($D(2), 'time', ['target' => 'nonsense', 'time_in' => '07:00']),
    'no_in' => $in($D(4), 'out', ['time_out' => '17:00']),
    'has_out' => $in($D(2), 'out', ['time_out' => '17:00']),
    'has_in' => $in($D(1), 'in', ['time_in' => '07:00']),
    'has_events' => $in($D(2), 'day', ['time_in' => '08:00', 'time_out' => '16:00']),
    'no_event' => $in($D(4), 'time', ['target' => 'sign_in', 'time_in' => '07:00']),
    'same' => $in($D(2), 'time', ['target' => 'sign_out', 'time_out' => '16:00']),
    'order' => $in($D(2), 'time', ['target' => 'sign_out', 'time_out' => '07:00']),
    'future_time' => $in($D0, 'in', ['time_in' => '23:59']),
];
foreach ($refusals as $k => $f) {
    $who = $k === 'pending' ? [$r13U, $r13] : [$BU, $B];
    $create('create_refused_' . $k, $who[0], $who[1], $f, 'employee', $k === 'has_events' ? $fake : null);   // a whole day needs a photo first
}
$create('create_before_start', $r13U, $r13, $in($D(7), 'out', ['time_out' => '17:00']));   // r13 was added today, first recorded day D-5
$create('create_no_employee', $BU, null, $in($D(1), 'out', ['time_out' => '17:00']));
$settings(['types' => ['in' => 0]]);
$create('create_type_switched_off', $BU, $B, $in($D(4), 'in', ['time_in' => '08:00']));
$settings(['monthly_limit' => 1, 'above_limit' => 'refuse']);
$create('create_limit_refused', $r13U, $r13, $in($D(1), 'out', ['time_out' => '17:00']));   // a month that already has r13's requests, or not: the same on both sides
$settings();
update_option('ews_feature_corrections', 0, false);
$create('create_feature_off', $BU, $B, $in($D(1), 'out', ['time_out' => '17:00']));
update_option('ews_feature_corrections', 1, false);
$create('create_fake_upload', $BU, $B, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', $fake);
$create('create_upload_error', $BU, $B, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', ['name' => 'x.png', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]);
$create('create_no_file_entry', $BU, $B, $in($D(6), 'day', ['time_in' => '08:00', 'time_out' => '16:00']), 'employee', ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]);

// ---------------------------------------------------------------- no workflow set: the managers are told (cxq_a)
$create('create_none_set_photo_off', $AU, $A, $in($D(1), 'out', ['time_out' => '17:00', 'reason' => 'forgot to sign out']), 'employee', $fake);   // the photo setting of a Sign Out is off: the file is ignored
$create('create_none_set_time_earlier', $AU, $A, $in($D(2), 'time', ['target' => 'sign_in', 'time_in' => '07:30', 'reason' => 'came early']));   // earlier Sign In: HR too
$create('create_none_set_as_manager', $mgrU, $A, $in($D(4), 'in', ['time_in' => '08:00', 'reason' => 'forgot']));   // the manager themselves is not told
$create('create_none_set_pending_again', $AU, $A, $in($D(1), 'out', ['time_out' => '17:30']));
$create('fault_none_set_insert', $BU, $B, $in($D(1), 'out', ['time_out' => '17:00']), 'employee', null, '/^\s*INSERT INTO `' . $Tq . '`/i');
$create('fault_none_set_audit', $BU, $B, $in($D(1), 'out', ['time_out' => '17:00']), 'employee', null, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_audit_log', '/') . '`/i');
$create('fault_none_set_notification', $BU, $B, $in($D(2), 'time', ['target' => 'sign_out', 'time_out' => '17:00']), 'employee', null, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_notifications', '/') . '`/i');

// ---------------------------------------------------------------- NONE: approved at once (cxq_d), and its faults
$wf('NONE');
$create('create_mode_none_out', $DU, $Dd, $in($D(1), 'out', ['time_out' => '17:00']));
$create('create_mode_none_time_earlier', $DU, $Dd, $in($D(2), 'time', ['target' => 'sign_in', 'time_in' => '07:30']));   // needs HR: pending_hr
$create('fault_mode_none_first_begin', $DU, $Dd, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', null, ['/^\s*START TRANSACTION/i', 'SELECT broken_on_purpose FROM no_such_table_x', 1]);   // the engine's NONE start has no transaction: the first is the finalize's. CX-z: status 'save', no error
$create('fault_mode_none_manager_row', $BU, $B, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', null, '/^\s*UPDATE `' . $Tq . '` SET `manager_by`/i');
$create('fault_mode_none_start_insert', $BU, $B, $in($D(1), 'out', ['time_out' => '17:00']), 'employee', null, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_approval_requests', '/') . '`/i');
$settings(['photo' => ['day' => 'optional']]);
$create('fault_mode_none_second_event', $BU, $B, $in($D(6), 'day', ['time_in' => '08:00', 'time_out' => '16:00']), 'employee', null, ['/^\s*INSERT INTO `' . $Lq . '`/i', 'SELECT broken_on_purpose FROM no_such_table_x', 2]);
$create('create_mode_none_day', $DU, $Dd, $in($D(6), 'day', ['time_in' => '08:00', 'time_out' => '16:00']));
$settings();

// ---------------------------------------------------------------- LEVEL_1: the supervisor is told (cxq_c), and its faults
$wf('LEVEL_1');
$create('create_level1_out', $CU, $C, $in($D(1), 'out', ['time_out' => '17:00']));
$create('create_level1_no_supervisor', $BU, $B, $in($D(2), 'time', ['target' => 'sign_out', 'time_out' => '16:45']));   // no supervisor: the administrators
$create('fault_level1_request_insert', $CU, $C, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', null, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_approval_requests', '/') . '`/i');
$create('fault_level1_step_insert', $CU, $C, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', null, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_approval_steps', '/') . '`/i');
$create('fault_level1_commit', $CU, $C, $in($D(4), 'in', ['time_in' => '08:00']), 'employee', null, '/^\s*COMMIT/i');
$create('create_level1_in', $CU, $C, $in($D(4), 'in', ['time_in' => '08:00']));

// ---------------------------------------------------------------- HR: applied at once, refused, and its faults (mona; HR's own source)
$wf('');
$create('create_hr_day', $hrU, $mona, $in($D(6), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'mission']), 'hr');
$create('create_hr_refused_time', $hrU, $mona, $in($D(5), 'out'), 'hr');
$create('create_hr_refused_reason', $hrU, $mona, $in($D(5), 'out', ['time_out' => '17:00', 'reason' => '']), 'hr');
$create('create_hr_old_day', $hrU, $A, $in($D(20), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'audit']), 'hr');   // no deadline for HR
$create('fault_hr_insert', $hrU, $A, $in($D(21), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'x']), 'hr', null, '/^\s*INSERT INTO `' . $Tq . '`/i');
$create('fault_hr_begin', $hrU, $A, $in($D(21), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'x']), 'hr', null, '/^\s*START TRANSACTION/i');
$create('fault_hr_second_event', $hrU, $A, $in($D(21), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'x']), 'hr', null, ['/^\s*INSERT INTO `' . $Lq . '`/i', 'SELECT broken_on_purpose FROM no_such_table_x', 2]);
$create('fault_hr_approved_update', $hrU, $A, $in($D(21), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'x']), 'hr', null, ['/^\s*UPDATE `' . $Tq . '` SET `status` = \'approved\'/i', "UPDATE `{$T}` SET status=status WHERE id=0"]);
$create('fault_hr_commit', $hrU, $A, $in($D(21), 'day', ['time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'x']), 'hr', null, '/^\s*COMMIT/i');

// ---------------------------------------------------------------- the controllers: the app's form, HR's direct form, the API
$as($CU);
$run('form_request_sent', function () use ($post, $D) { return $post('correction_request', ['date' => $D(2), 'type' => 'time', 'target' => 'sign_in', 'time_in' => '07:55', 'reason' => 'forgot'], 'ews_correction_request'); });
$as($BU);
$run('form_request_refused', function () use ($post, $D) { return $post('correction_request', ['date' => $D(10), 'type' => 'out', 'time_out' => '17:00', 'reason' => 'late'], 'ews_correction_request'); });
$run('form_request_bad_nonce', function () use ($post, $D) { return $post('correction_request', ['date' => $D(4), 'type' => 'in', 'time_in' => '08:15', 'reason' => 'x'], 'bad'); });
$as(0);
$run('form_request_logged_out', function () use ($post, $D) { return $post('correction_request', ['date' => $D(4), 'type' => 'in'], 'ews_correction_request'); });
$as((int) $c['atwu_ghost']);
$run('form_request_no_employee', function () use ($post, $D) { return $post('correction_request', ['date' => $D(4), 'type' => 'in', 'time_in' => '08:15', 'reason' => 'x'], 'ews_correction_request'); });
$as($hrU);
$run('form_direct_ok', function () use ($post, $D, $c) { return $post('correction_direct', ['employee_ref' => 'Cx cxq_b #' . (int) $c['cxq_b'], 'date' => $D(15), 'type' => 'day', 'time_in' => '09:00', 'time_out' => '17:00', 'reason' => 'mission'], 'ews_correction_direct'); });
$run('form_direct_no_employee', function () use ($post, $D) { return $post('correction_direct', ['employee_ref' => 'nobody', 'date' => $D(15), 'type' => 'day', 'reason' => 'x'], 'ews_correction_direct'); });
$run('form_direct_refused', function () use ($post, $D, $c) { return $post('correction_direct', ['employee_ref' => (string) (int) $c['cxq_b'], 'date' => $D(16), 'type' => 'day', 'time_in' => '09:00', 'reason' => 'x'], 'ews_correction_direct'); });
$as($BU);
$run('form_direct_not_hr', function () use ($post, $D, $c) { return $post('correction_direct', ['employee_ref' => (string) (int) $c['cxq_b'], 'date' => $D(16), 'type' => 'day', 'reason' => 'x'], 'ews_correction_direct'); });
$as($admin);
$api = function ($label, $uid, $e, array $params) use ($run, $o, $as, $admin, $apiSession) {
    $as($uid);
    $apiSession->setValue(null, $e ? ['employee' => $e] : null);
    $run($label, function () use ($o, $params) {
        $req = new WP_REST_Request('POST', '/workforce-one/v1/corrections');
        $req->set_body_params($params);
        $r = $o->api_corrections_create($req);
        if ($r instanceof WP_REST_Response) $r = ['status' => $r->get_status(), 'body' => $r->get_data()];
        if (is_array($r) && isset($r['body']['meta'])) $r['body']['meta'] = 'META';   // the request id and the server time
        return $r;
    });
    $apiSession->setValue(null, null);
    $as($admin);
};
$wf('');
$api('api_created', $r13U, $r13, ['date' => $D(5), 'type' => 'time', 'target' => 'sign_out', 'time_out' => '16:45', 'reason' => 'traffic']);
$api('api_refused', $BU, $B, ['date' => $D(10), 'type' => 'out', 'time_out' => '17:00', 'reason' => 'x']);
$api('api_not_linked', $BU, null, ['date' => $D(2), 'type' => 'out']);
update_option('ews_feature_corrections', 0, false);
$api('api_off', $BU, $B, ['date' => $D(2), 'type' => 'out']);
update_option('ews_feature_corrections', 1, false);

// ---------------------------------------------------------------- withdraw (My requests): rows written with plain SQL, each pending, then every state and fault
$wd = [];
foreach (['ok', 'begin', 'engine', 'update', 'update_zero', 'commit', 'audit'] as $k) {
    $wpdb->insert($T, ['employee_id' => (int) $B->id, 'work_date' => $D(3), 'type' => 'out', 'sign_out_at' => $D(3) . ' 17:00:00', 'status' => 'pending', 'source' => 'employee', 'reason' => 'wd ' . $k, 'requested_by' => $BU, 'requested_at' => $D(3) . ' 18:00:00']);
    $wd[$k] = (int) $wpdb->insert_id;
}
$withdraw = function ($label, $cid, $eid, $pattern = null) use ($run, $faulty, $call, $as, $admin, $BU) {
    $as($BU);
    $fn = function () use ($call, $cid, $eid) { return $call('cx_withdraw', (int) $cid, (int) $eid); };
    if ($pattern === null) $run($label, $fn); else $faulty($label, $pattern, $fn);
    $as($admin);
};
$withdraw('withdraw_ok', $wd['ok'], $B->id);
$withdraw('withdraw_again', $wd['ok'], $B->id);
$withdraw('withdraw_other_employee', $wd['begin'], $A->id);
$withdraw('withdraw_approved', (int) $c['cx_approved'], (int) $c['atr_r13']);
$withdraw('withdraw_rejected', (int) $c['cx_rejected'], (int) $c['lina']);
$withdraw('withdraw_unknown', 99999999, $B->id);
$withdraw('withdraw_pending_hr', (int) $c['cx_pending_hr'], (int) $c['mona']);
$withdraw('fault_withdraw_begin', $wd['begin'], $B->id, '/^\s*START TRANSACTION/i');
$withdraw('fault_withdraw_update', $wd['update'], $B->id, '/^\s*UPDATE ' . $Tq . ' SET status=\'cancelled\'/i');
$withdraw('fault_withdraw_update_zero', $wd['update_zero'], $B->id, ['/^\s*UPDATE ' . $Tq . ' SET status=\'cancelled\'/i', "UPDATE {$T} SET status=status WHERE id=0"]);
$withdraw('fault_withdraw_commit', $wd['commit'], $B->id, '/^\s*COMMIT/i');
$withdraw('fault_withdraw_audit', $wd['audit'], $B->id, '/^\s*INSERT INTO `' . preg_quote($p . 'ews_audit_log', '/') . '`/i');
// with an open approval request (LEVEL_1, from create_level1_out): the engine closes it first; then with the engine's write failing
$openId = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$T} WHERE employee_id=%d AND work_date=%s AND status='pending' ORDER BY id LIMIT 1", (int) $C->id, $D(1)));
$openId2 = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$T} WHERE employee_id=%d AND work_date=%s AND status='pending' ORDER BY id LIMIT 1", (int) $C->id, $D(4)));
$as($CU);
$faulty('fault_withdraw_engine', '/^\s*UPDATE `?' . preg_quote($p . 'ews_approval_', '/') . '(steps|requests)\b/i', function () use ($call, $openId2, $C) { return $call('cx_withdraw', $openId2, (int) $C->id); });
$run('withdraw_level1_open', function () use ($call, $openId, $C) { return $call('cx_withdraw', $openId, (int) $C->id); });
$as($admin);

// ---------------------------------------------------------------- the end-of-day reminder
$tick = function ($label, $pattern = null) use ($run, $faulty, $o) {
    $fn = function () use ($o) { $o->corrections_tick(); return get_option('ews_corrections_reminded'); };
    if ($pattern === null) $run($label, $fn); else $faulty($label, $pattern, $fn);
};
$settings(['reminder' => 0]);
$tick('tick_reminder_off');
$settings(['reminder_time' => '23:59']);
$tick('tick_before_the_time');   // unless the harness runs in the last minute of the day: the same on both sides
$settings();
update_option('ews_feature_corrections', 0, false);
$tick('tick_feature_off');
update_option('ews_feature_corrections', 1, false);
$tick('fault_tick_claim', '/^\s*(UPDATE|INSERT INTO) `' . preg_quote($p . 'options', '/') . '`.*ews_corrections_reminded/is');
delete_option('ews_corrections_reminded');
$tick('fault_tick_candidates', '/^\s*SELECT e\.id,e\.wp_user_id,MIN\(l\.event_at\)/i');
delete_option('ews_corrections_reminded');
$tick('tick_first');
$tick('tick_second');
update_option('ews_corrections_reminded', $D(1), false);
$tick('tick_claimed_yesterday');
$run('remind_today', function () use ($call, $D0) { return $call('corrections_remind', $D0); });
$run('remind_yesterday', function () use ($call, $D) { return $call('corrections_remind', $D(1)); });
$run('remind_old_day', function () use ($call, $D) { return $call('corrections_remind', $D(30)); });
$faulty('fault_remind_notification', '/^\s*INSERT INTO `' . preg_quote($p . 'ews_notifications', '/') . '`/i', function () use ($call, $D0) { return $call('corrections_remind', $D0); });
$hoursCache->setValue(null, []);
update_option('ews_working_hours', ['start' => '22:00', 'normal_until' => '06:00', 'end' => '06:00'], false);
$run('remind_overnight_hours', function () use ($call, $D0) { return $call('corrections_remind', $D0); });
delete_option('ews_working_hours');
$hoursCache->setValue(null, []);

// ---------------------------------------------------------------- the tables, at the end
@unlink($tmp);
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
$ids = implode(',', array_map('intval', [$c['cxq_a'], $c['cxq_b'], $c['cxq_c'], $c['cxq_d'], $c['mona'], $c['atr_r13'], $c['lina'], $c['cxq_nouser'], $c['cxq_inactive']]));
foreach ([
    'corrections' => "SELECT * FROM {$T} ORDER BY id",
    'time_logs' => "SELECT id,employee_id,user_id,work_date,event_type,event_at,scheduled_status,source,corrects_id,correction_id FROM {$p}ews_time_logs WHERE employee_id IN ({$ids}) ORDER BY id",
    'approval_requests' => "SELECT id,entity_type,entity_id,requester_employee_id,status,current_step FROM {$p}ews_approval_requests WHERE entity_type='attendance_correction' ORDER BY id",
    'approval_steps' => "SELECT s.id,s.approval_request_id,s.step_order,s.resolver_type,s.approver_wp_user_id,s.status,s.rerouted,s.skip_reason FROM {$p}ews_approval_steps s INNER JOIN {$p}ews_approval_requests r ON r.id=s.approval_request_id WHERE r.entity_type='attendance_correction' ORDER BY s.id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
$wf('NONE');
delete_option('ews_working_days');
echo "\n" . wp_json_encode($trace);
