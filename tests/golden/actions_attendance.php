<?php
/**
 * The golden harness's attendance trace (tests/golden/run.py; docs/tasks/attendance-store.md §4-5): the attendance read API driven in
 * process through the plugin's own functions (today's events, the attendance day, the last device location, the break reads and
 * the overtime summary), as the other modules call it. For each call: what it returned (with types), the exact SQL it ran (in
 * order, dates and times as placeholders, so an extra or reordered statement shows) and its query count; at the end the attendance
 * tables. The same script runs on the old and the new code from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/actions_attendance.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };
$D0 = current_time('Y-m-d');
$dates = [$D0 => 'D0', gmdate('Y-m-d', strtotime($D0 . ' -1 day')) => 'D-1', gmdate('Y-m-d', strtotime($D0 . ' -2 days')) => 'D-2'];
// ---------------------------------------------------------------- recording
$sql = [];
add_filter('query', function ($q) use (&$sql, $dates) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $sql[] = strtr(trim(preg_replace('/\s+/', ' ', $n)), $dates);
    return $q;
}, 1);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => $v->get_error_message()];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) { $o = []; foreach ($v as $k => $x) $o[$k] = $norm($x); return $o; }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        return strtr($v, $dates);
    }
    return $v;
};
$trace = [];
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm) {
    wp_cache_flush();
    $sql = [];
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};
$ids = ['omar' => $c['omar'], 'lina' => $c['lina'], 'mona' => $c['mona'], 'ali' => $c['ali'], 'rana' => $c['rana'], 'none' => 999999, 'zero' => 0];

// ---------------------------------------------------------------- today's events (keyed by event type, the live rows only)
foreach ($ids as $name => $id) $run('today_events_' . $name, function () use ($call, $id) { return $call('today_events', $id); });
// ---------------------------------------------------------------- the attendance day (the static cache is keyed by the minute: a fresh id each time)
foreach ($ids as $name => $id) $run('attendance_day_' . $name, function () use ($call, $id) { return $call('attendance_day', $id); });
// an overnight shift: the day is read from yesterday's events (ali signed in yesterday and did not sign out; rana did both)
update_option('ews_allow_overnight_shift', 1, false);
update_option('ews_working_hours', ['start' => '22:00', 'end' => '06:00', 'normal_until' => '06:00'], false);
$run('attendance_day_overnight_open', function () use ($call, $c) { return $call('attendance_day', $c['ali']); });
$run('attendance_day_overnight_closed', function () use ($call, $c) { return $call('attendance_day', $c['rana']); });
$run('attendance_day_overnight_none', function () use ($call, $c) { return $call('attendance_day', $c['omar']); });
$run('today_events_overnight_open', function () use ($call, $c) { return $call('today_events', $c['ali']); });
delete_option('ews_allow_overnight_shift');
delete_option('ews_working_hours');
// ---------------------------------------------------------------- the last device location (NULL columns skipped, newest first)
foreach ($ids as $name => $id) $run('last_device_location_' . $name, function () use ($call, $id) { return $call('last_device_location', $id); });
// ---------------------------------------------------------------- breaks
foreach ($ids as $name => $id) {
    $run('break_sessions_' . $name, function () use ($call, $id) { return $call('break_sessions_today', $id); });
    $run('break_open_' . $name, function () use ($call, $id) { return $call('break_open_session', $id); });
    $run('break_remaining_' . $name, function () use ($call, $id) { return $call('break_remaining', $id); });
}
// ---------------------------------------------------------------- overtime summary (reads the first Sign In and the last Sign Out)
$run('overtime_summary_lina', function () use ($call, $c, $D0) { return $call('overtime_attendance_summary', $c['lina'], $D0); });
$run('overtime_summary_omar', function () use ($call, $c, $D0) { return $call('overtime_attendance_summary', $c['omar'], $D0); });
$run('overtime_summary_other_day', function () use ($call, $c, $D0) { return $call('overtime_attendance_summary', $c['rana'], gmdate('Y-m-d', strtotime($D0 . ' -1 day'))); });
$run('overtime_summary_off', function () use ($call, $c, $D0) { update_option('ews_feature_overtime', 0, false); $r = $call('overtime_attendance_summary', $c['lina'], $D0); update_option('ews_feature_overtime', 1, false); return $r; });

// ---------------------------------------------------------------- the tables, at the end
foreach (['time_logs' => 'ews_time_logs', 'break_sessions' => 'ews_break_sessions'] as $k => $t)
    $trace['_rows:' . $k] = $norm($wpdb->get_results("SELECT * FROM {$p}{$t} ORDER BY id", ARRAY_A));
echo wp_json_encode($trace);
