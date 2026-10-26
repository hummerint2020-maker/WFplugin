<?php
/**
 * The golden harness's actions (tests/golden/run.py): writes of the layered slices, run on the old and the new code from
 * the same snapshot. Prints a trace (what each call returned, then the rows it left) that must be the same on both sides.
 * Times are replaced by placeholders; ids are kept (both sides start from the same data).
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/actions.php
 */
global $wpdb;
$p = $wpdb->prefix;
$ctx = json_decode((string) getenv('GOLDEN_CTX'), true);
wp_set_current_user((int) get_user_by('login', 'admin')->ID);
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };
$D = $ctx['dates'];
$trace = [];
// The Attendance grid's guarded save: an empty day, the right expected status, a stale one, the same value again.
$wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date=%s", $ctx['omar'], $D[2]));
$trace['grid_insert'] = $call('save_att_if_unchanged', $ctx['omar'], $D[2], 'WFH', null);
$trace['grid_taken'] = $call('save_att_if_unchanged', $ctx['omar'], $D[2], 'Office', null);
$trace['grid_update'] = $call('save_att_if_unchanged', $ctx['lina'], $D[0], 'WFH', 'Office');
$trace['grid_stale'] = $call('save_att_if_unchanged', $ctx['lina'], $D[0], 'Office', 'Office');
$trace['grid_same'] = $call('save_att_if_unchanged', $ctx['lina'], $D[0], 'WFH', 'WFH');
// A free save: a new day, a changed day, the same day again (no notification), a note only.
$wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%d AND work_date=%s", $ctx['ali'], $D[1]));
$trace['save_new'] = (string) $call('save_att', $ctx['ali'], $D[1], 'Office', 'new');
$trace['save_change'] = (string) $call('save_att', $ctx['ali'], $D[1], 'WFH', 'new');
$trace['save_same'] = (string) $call('save_att', $ctx['ali'], $D[1], 'WFH', 'new');
$trace['save_note'] = (string) $call('save_att', $ctx['ali'], $D[1], 'WFH', 'other note');
$day = (array) $call('schedule_for_employee_date', $ctx['ali'], $D[1]);
$holidays = array_map(function ($r) { return (array) $r; }, (array) $call('company_calendar_events', $D[0], $D[count($D) - 1]));
// Week lock: the cutoff that just passed is told once (a second run tells nobody).
update_option('ews_feature_week_lock', 1, false);
$now = current_time('timestamp');
update_option('ews_week_lock', ['cutoff_day' => (int) gmdate('w', $now), 'cutoff_time' => gmdate('H:i', $now - 600), 'notify' => 1], false);
delete_option('ews_week_lock_notified');
$call('week_lock_tick');
$trace['week_lock_notified'] = (string) get_option('ews_week_lock_notified', '');
$call('week_lock_tick');
// What was left behind.
$norm = function ($rows) {
    return array_map(function ($r) {
        foreach ($r as $k => $v) if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) $r[$k] = 'T';
        return $r;
    }, $rows);
};
$trace['day'] = $norm([$day]);
$trace['holidays'] = $norm($holidays);
$trace['schedule'] = $norm($wpdb->get_results("SELECT * FROM {$p}ews_schedule ORDER BY id", ARRAY_A));
$trace['notifications'] = $norm($wpdb->get_results("SELECT * FROM {$p}ews_notifications ORDER BY id", ARRAY_A));
$trace['audit'] = $norm($wpdb->get_results("SELECT * FROM {$p}ews_audit_log ORDER BY id", ARRAY_A));
echo wp_json_encode($trace);
