<?php
/**
 * The golden harness's attendance corrections data (tests/golden/run.py; docs/tasks/attendance-corrections.md §4, L5e): requests in every status for
 * people of two departments, one with a photo, the events of their days (one superseded by a correction row), written with plain SQL (no corrections
 * code) before the snapshot is saved. Needs the ids of the attendance seed (GOLDEN_CTX). The feature itself is switched on by the traces and the pages
 * (options), not here. Prints one JSON line with the ids the traces and pages need.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/seed_corrections.php
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
$m = new EWS_Manager_V31_1();
foreach (['ensure_corrections_schema'] as $fn) { $r = new ReflectionMethod($m, $fn); $r->setAccessible(true); $r->invoke($m); }
$today = current_time('Y-m-d');
$day = function ($back) use ($today) { return gmdate('Y-m-d', strtotime($today . ' -' . $back . ' days')); };
$at = function ($back, $hhmm) use ($day) { return $day($back) . ' ' . $hhmm . ':00'; };
$gdreq = (int) $wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE domain_name='gdreq' ORDER BY id LIMIT 1");
$wpdb->query("DELETE FROM {$p}ews_attendance_corrections");
$ev = function ($eid, $back, $type, $hhmm, array $more = []) use ($wpdb, $p, $day, $at) {
    $wpdb->insert($p . 'ews_time_logs', array_merge(['employee_id' => $eid, 'user_id' => 0, 'work_date' => $day($back), 'event_type' => $type, 'event_at' => $at($back, $hhmm), 'scheduled_status' => 'Office', 'ip_address' => ''], $more));
    return (int) $wpdb->insert_id;
};
$r13 = (int) $c['atr_r13'];
$ev($r13, 3, 'sign_in', '08:00');                                                   // a Sign In and no Sign Out
$ev($r13, 5, 'sign_in', '08:00');
$ev($r13, 5, 'sign_out', '16:00');
$ev($gdreq, 2, 'sign_in', '08:05');
$wrong = $ev((int) $c['lina'], 4, 'sign_in', '09:00');                                // superseded by a correction row
$wpdb->insert($p . 'ews_time_logs', ['employee_id' => (int) $c['lina'], 'user_id' => 0, 'work_date' => $day(4), 'event_type' => 'sign_in', 'event_at' => $at(4, '08:30'), 'scheduled_status' => 'Office', 'ip_address' => '', 'source' => 'correction', 'corrects_id' => $wrong]);
$ev((int) $c['lina'], 4, 'sign_out', '17:00');
$row = function (array $v) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_attendance_corrections', array_merge(['target' => null, 'source' => 'employee', 'needs_hr' => 0, 'above_limit' => 0, 'earlier' => 0, 'reason' => 'forgot'], $v));
    return (int) $wpdb->insert_id;
};
$ids = [];
$ids['pending'] = $row(['employee_id' => $r13, 'work_date' => $day(3), 'type' => 'out', 'sign_out_at' => $at(3, '17:00'), 'status' => 'pending', 'requested_by' => (int) $c['atru_r13'], 'requested_at' => $at(3, '18:00')]);
$ids['approved'] = $row(['employee_id' => $r13, 'work_date' => $day(5), 'type' => 'time', 'target' => 'out', 'old_at' => $at(5, '16:00'), 'sign_out_at' => $at(5, '16:30'), 'status' => 'approved', 'requested_by' => (int) $c['atru_r13'], 'requested_at' => $at(5, '18:00'), 'manager_by' => (int) $c['atru_mgr'], 'manager_at' => $at(5, '19:00'), 'decided_by' => (int) $c['atru_mgr'], 'decided_at' => $at(5, '19:00'), 'applied_ids' => '', 'payroll_note' => '']);
$ids['pending_hr'] = $row(['employee_id' => (int) $c['mona'], 'work_date' => $day(2), 'type' => 'in', 'sign_in_at' => $at(2, '07:00'), 'status' => 'pending_hr', 'needs_hr' => 1, 'earlier' => 1, 'requested_by' => 1, 'requested_at' => $at(2, '09:00'), 'manager_by' => 1, 'manager_at' => $at(2, '09:30')]);
$ids['rejected'] = $row(['employee_id' => (int) $c['lina'], 'work_date' => $day(4), 'type' => 'day', 'sign_in_at' => $at(4, '08:00'), 'sign_out_at' => $at(4, '16:00'), 'status' => 'rejected', 'requested_by' => 1, 'requested_at' => $at(4, '18:30'), 'decided_by' => 1, 'decided_at' => $at(4, '20:00'), 'decision_note' => 'no evidence']);
$ids['levels'] = $row(['employee_id' => $gdreq, 'work_date' => $day(2), 'type' => 'out', 'sign_out_at' => $at(2, '16:00'), 'status' => 'pending', 'requested_by' => (int) $wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE id={$gdreq}"), 'requested_at' => $at(2, '17:00')]);
$ids['other_dept'] = $row(['employee_id' => (int) $c['atr_other'], 'work_date' => $day(3), 'type' => 'out', 'sign_out_at' => $at(3, '17:30'), 'status' => 'pending', 'requested_by' => 1, 'requested_at' => $at(3, '17:45')]);
$ids['photo'] = $row(['employee_id' => (int) $c['omar'], 'work_date' => $day(1), 'type' => 'out', 'sign_out_at' => $at(1, '17:00'), 'status' => 'pending', 'photo_key' => str_repeat('p', 32) . '.jpg', 'photo_name' => 'proof.jpg', 'requested_by' => 1, 'requested_at' => $at(1, '18:00')]);
// HR for the corrections pages: a user with the capability (user meta is in the snapshot, so the other traces see it too; none of them asks)
$hr = (int) (username_exists('cxhr') ?: wp_create_user('cxhr', 'cxhr-pass', 'cxhr@example.com'));
(new WP_User($hr))->add_cap('ews_manage_corrections');
$out = ['cx_hr_user' => $hr, 'cx_gdreq' => $gdreq];
foreach ($ids as $k => $v) $out['cx_' . $k] = $v;
echo wp_json_encode($out);
