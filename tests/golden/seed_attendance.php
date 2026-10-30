<?php
/**
 * The golden harness's attendance data (tests/golden/run.py; docs/tasks/attendance-store.md §5): today's Sign In / Sign Out rows, a
 * superseded (corrected) event, a past row with coordinates, and break sessions (one open, one closed), written with plain SQL (no
 * Attendance code) before the snapshot is saved. Needs the ids of the attendance seed (GOLDEN_CTX).
 * Prints one JSON line with the ids the pages need.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/seed_attendance.php
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
$m = new EWS_Manager_V31_1();
foreach (['ensure_break_schema', 'ensure_corrections_schema'] as $fn) {
    if (method_exists($m, $fn)) { $r = new ReflectionMethod($m, $fn); $r->setAccessible(true); $r->invoke($m); }
}
$today = current_time('Y-m-d');
$yesterday = gmdate('Y-m-d', strtotime($today . ' -1 day'));
$at = function ($hhmm, $date = null) use ($today) { return ($date ?: $today) . ' ' . $hhmm . ':00'; };
$wpdb->query("DELETE FROM {$p}ews_time_logs WHERE work_date IN ('{$today}','{$yesterday}') AND employee_id IN (" . implode(',', array_map('intval', [$c['omar'], $c['lina'], $c['mona'], $c['ali'], $c['rana'], $c['sam'], $c['twin']])) . ')');
$wpdb->query("DELETE FROM {$p}ews_break_sessions");
$row = function ($eid, $type, $when, $date, array $extra = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_time_logs', array_merge(['employee_id' => $eid, 'user_id' => 0, 'work_date' => $date, 'event_type' => $type, 'event_at' => $when, 'scheduled_status' => 'Office', 'ip_address' => '10.0.0.1'], $extra));
    return (int) $wpdb->insert_id;
};
// omar: signed in today, with coordinates; on an open break
$row($c['omar'], 'sign_in', $at('08:01'), $today, ['latitude' => 30.0444, 'longitude' => 31.2357, 'accuracy' => 12, 'location_status' => 'inside', 'location_timestamp' => 1700000000000]);
// lina: in and out today
$row($c['lina'], 'sign_in', $at('07:55'), $today);
$row($c['lina'], 'sign_out', $at('08:20'), $today);
// mona: a corrected event (the superseded one is hidden by the "live" filter), and an event with coordinates two days back
$old = $row($c['mona'], 'sign_in', $at('09:30'), $today);
$new = $row($c['mona'], 'sign_in', $at('08:05'), $today, ['source' => 'correction', 'corrects_id' => $old]);
$row($c['mona'], 'sign_in', $at('08:00', gmdate('Y-m-d', strtotime($today . ' -2 days'))), gmdate('Y-m-d', strtotime($today . ' -2 days')), ['latitude' => 30.1, 'longitude' => 31.3, 'accuracy' => 20, 'location_timestamp' => 1699990000000]);
// ali: yesterday still open (an overnight shift reads this), rana: yesterday in + out
$row($c['ali'], 'sign_in', $at('22:00', $yesterday), $yesterday);
$row($c['rana'], 'sign_in', $at('08:00', $yesterday), $yesterday);
$row($c['rana'], 'sign_out', $at('16:30', $yesterday), $yesterday);
// the overnight cases use people no other call asks about (the attendance day is cached per employee and minute): sam open, twin closed, nour none (none of them has a shift of their own: the company hours decide)
$row($c['sam'], 'sign_in', $at('23:00', $yesterday), $yesterday);
$row($c['twin'], 'sign_in', $at('23:00', $yesterday), $yesterday);
$row($c['twin'], 'sign_out', $at('23:30', $yesterday), $yesterday);
$brk = function ($eid, $start, $end, $min, $status) use ($wpdb, $p, $today) {
    $wpdb->insert($p . 'ews_break_sessions', ['employee_id' => $eid, 'user_id' => 0, 'work_date' => $today, 'start_at' => $start, 'end_at' => $end, 'actual_minutes' => $min, 'status' => $status]);
};
$brk($c['omar'], $at('10:00'), null, null, 'Open');
$brk($c['lina'], $at('10:00'), $at('10:15'), 15, 'Closed');
$brk($c['lina'], $at('12:00'), $at('12:20'), 20, 'Closed');
update_option('ews_feature_breaks', 1, false);
update_option('ews_breaks_per_day', 2, false);
update_option('ews_feature_overtime', 1, false);
$wpdb->query("DELETE FROM {$p}ews_overtime_requests WHERE overtime_date='{$today}'");
$wpdb->insert($p . 'ews_overtime_requests', ['employee_id' => $c['lina'], 'overtime_date' => $today, 'start_time' => '17:00:00', 'end_time' => '19:00:00', 'requested_minutes' => 120, 'status' => 'Approved', 'requested_by' => 1]);
// a default work location when the site has none (the usual seed has one)
if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ews_locations WHERE active=1 AND is_default=1")) $wpdb->insert($p . 'ews_locations', ['name' => 'Cairo HQ', 'latitude' => '30.0444', 'longitude' => '31.2357', 'radius' => 200, 'enforcement' => 0, 'is_default' => 1, 'active' => 1]);
// ---- people for the write traces (actions_signin.php): each has a user, one case each, and today's plan
$mk = function ($key, $att = 1) use ($wpdb, $p) {
    $login = 'atw_' . $key;
    $uid = (int) (username_exists($login) ?: wp_create_user($login, $login . '-pass', $login . '@example.com'));
    $have = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE wp_user_id=%d ORDER BY id LIMIT 1", $uid));
    if ($have) return [$have, $uid];   // a used site: the same person again, not a second employee on one user
    $wpdb->insert($p . 'ews_employees', ['name' => 'At ' . $key, 'domain_name' => $login, 'email' => $login . '@example.com', 'wp_user_id' => $uid, 'active' => 1, 'attendance_enabled' => $att]);
    return [(int) $wpdb->insert_id, $uid];
};
$ids = [];
foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'a10', 'a11', 'a12'] as $k) $ids[$k] = $mk($k, 1);
$ids['off'] = $mk('off', 0);
$ids['leave'] = $mk('leave', 1);
$ids['nosched'] = $mk('nosched', 1);
$ids['ghost'] = [0, (int) (username_exists('atw_ghost') ?: wp_create_user('atw_ghost', 'atw_ghost-pass', 'atw_ghost@example.com'))];   // a user with no employee
$wpdb->query("DELETE FROM {$p}ews_schedule WHERE work_date='{$today}' AND employee_id IN (" . implode(',', array_map(function ($v) { return (int) $v[0]; }, $ids)) . ')');
foreach ($ids as $k => $v) {
    if (!$v[0] || $k === 'nosched') continue;
    $wpdb->insert($p . 'ews_schedule', ['employee_id' => $v[0], 'work_date' => $today, 'status' => $k === 'leave' ? 'Leave' : 'Office']);
}
$out = ['att_today' => $today, 'att_yesterday' => $yesterday, 'att_corrected' => $new];
foreach ($ids as $k => $v) { $out['atw_' . $k] = $v[0]; $out['atwu_' . $k] = $v[1]; }
echo wp_json_encode($out);
