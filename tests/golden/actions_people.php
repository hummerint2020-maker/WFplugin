<?php
/**
 * The golden harness's people trace (tests/golden/run.py; docs/tasks/people-store.md §5): the people module's read API driven in
 * process through its own functions, as the other modules call it. For each call it records what it returned (with types), the
 * exact SQL it ran (in order, times as placeholders, so an extra or reordered statement shows) and its query count; at the end
 * every people table. The same script runs on the old and the new code from the same snapshot; the two traces must be equal.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/actions_people.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };
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
    wp_cache_flush();   // every call starts with a cold object cache, so when WordPress happens to have loaded a user earlier does not show
    $sql = [];
    $q0 = $wpdb->num_queries;
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};

$dump = function ($prefix) use (&$trace, $wpdb, $p, $norm) {
    foreach ([
        'employees' => "SELECT * FROM {$p}ews_employees ORDER BY id",
        'relationships' => "SELECT * FROM {$p}ews_employee_relationships ORDER BY id",
        'departments' => "SELECT * FROM {$p}ews_departments ORDER BY id",
        'teams' => "SELECT * FROM {$p}ews_teams ORDER BY id",
        'team_members' => "SELECT * FROM {$p}ews_team_members ORDER BY id",
        'org_moves' => "SELECT * FROM {$p}ews_org_moves ORDER BY id",
    ] as $name => $query) $trace[$prefix . $name] = $norm($wpdb->get_results($query, ARRAY_A));
};
$ids = [$c['mona'], $c['omar'], $c['lina'], $c['ali'], $c['rana'], $c['nour'], $c['ziad'], $c['sam'], $c['oldboss'], $c['twin']];
$big = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE domain_name LIKE 'acbig%' ORDER BY id"));
$many = range(1, 1200);   // three chunks of 500: ids that do not exist are part of the call
$omarUser = (int) $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$p}ews_employees WHERE id=%d", $c['omar']));
$as(0);

// ---------------------------------------------------------------- 1. one employee
$run('employee', function () use ($call, $c) { return $call('people_employee', $c['omar']); });
$run('employee_active_only', function () use ($call, $c) { return $call('people_employee', $c['oldboss'], true); });
$run('employee_archived', function () use ($call, $c) { return $call('people_employee', $c['oldboss']); });
$run('employee_zero', function () use ($call) { return $call('people_employee', 0); });
$run('employee_by_user', function () use ($call, $c) { return $call('people_employee_by_user', $GLOBALS['wpdb']->get_var("SELECT wp_user_id FROM {$GLOBALS['wpdb']->prefix}ews_employees WHERE id=" . (int) $c['omar'])); });
$run('employee_by_user_twin', function () use ($call, $c) { return $call('people_employee_by_user', $c['subuser']); });
$run('employee_by_user_zero', function () use ($call) { return $call('people_employee_by_user', 0); });
$run('active_id', function () use ($call, $c) { return $call('people_active_id', $c['omar']); });
$run('active_id_archived', function () use ($call, $c) { return $call('people_active_id', $c['oldboss']); });
$run('name', function () use ($call, $c) { return $call('people_name', $c['mona']); });
$run('name_missing', function () use ($call) { return $call('people_name', 999999); });
$run('user_id', function () use ($call, $c) { return $call('people_user_id', $c['omar']); });
$run('user_id_active', function () use ($call, $c) { return $call('people_user_id', $c['oldboss'], true); });
$run('active_brief', function () use ($call, $c) { return $call('people_active_brief', $c['lina']); });
$run('active_brief_archived', function () use ($call, $c) { return $call('people_active_brief', $c['oldboss']); });
$run('attendance_enabled_one', function () use ($call, $c) { return $call('employee_attendance_enabled', $c['omar']); });
$run('attendance_enabled_zero', function () use ($call, $c) { return $call('employee_attendance_enabled', $c['nour']); });
$run('attendance_enabled_null', function () use ($call, $c) { return $call('employee_attendance_enabled', $c['ali']); });
$run('attendance_enabled_missing', function () use ($call) { return $call('employee_attendance_enabled', 999999); });

// ---------------------------------------------------------------- 2. many at once
$run('names_by_user', function () use ($call, $c, $omarUser) { return $call('people_names_by_user', [$omarUser, $c['subuser'], 0, $omarUser, 999999]); });
$run('names_by_user_empty', function () use ($call) { return $call('people_names_by_user', []); });
$run('by_ids', function () use ($call, $ids) { return $call('people_by_ids', $ids); });
$run('by_ids_active', function () use ($call, $ids) { return $call('people_by_ids', $ids, true); });
$run('by_ids_many', function () use ($call, $many) { return $call('people_by_ids', $many); });
$run('brief_by_ids', function () use ($call, $ids) { return $call('people_brief_by_ids', $ids); });
$run('brief_by_ids_many', function () use ($call, $many) { return $call('people_brief_by_ids', $many); });
$run('brief_by_ids_empty', function () use ($call) { return $call('people_brief_by_ids', [0, 0]); });

// ---------------------------------------------------------------- 3. lists
$run('active_list', function () use ($call) { return $call('people_active_list', 3); });
$run('active_users', function () use ($call) { return $call('people_active_users'); });
$run('active_ids_all', function () use ($call) { return $call('people_active_ids', false); });
$run('active_ids_attendance', function () use ($call) { return $call('people_active_ids', true); });
$run('active_user_ids', function () use ($call, $c) { return $call('people_active_user_ids', $c['ops']); });
$run('active_count', function () use ($call, $c) { return $call('people_active_count', $c['ops'], false); });
$run('active_count_with_user', function () use ($call, $c) { return $call('people_active_count', $c['ops'], true); });
$run('department_member_ids', function () use ($call, $c) { return $call('people_department_member_ids', $c['ops']); });
$run('in_department', function () use ($call, $c) { return $call('people_in_department', $c['omar'], $c['ops']); });
$run('in_department_no', function () use ($call, $c) { return $call('people_in_department', $c['sam'], $c['ops']); });

// ---------------------------------------------------------------- 4. the shift memo and the active list (request caches)
$run('shift_id', function () use ($call, $c) { return $call('people_shift_id', $c['ziad']); });
$run('shift_id_again', function () use ($call, $c) { return $call('people_shift_id', $c['ziad']); });   // remembered: no query
$run('shift_id_none', function () use ($call, $c) { return $call('people_shift_id', $c['omar']); });
$run('shift_ids_plain', function () use ($call, $ids) { return $call('people_shift_ids', array_merge($ids, [0, 999999])); });
$run('emps', function () use ($call) { return $call('emps'); });
$run('emps_again', function () use ($call) { return $call('emps'); });   // remembered: no query
$run('shift_ids_with_rows', function () use ($call) { return $call('people_shift_ids', $call('emps')); });   // rows already carry default_shift_id: nothing read
$run('shift_ids_big', function () use ($call, $many) { return $call('people_shift_ids', $many); });
$run('all_shift_ids', function () use ($call) { return $call('people_all_shift_ids'); });
$run('set_shift_id', function () use ($call, $c) { return $call('people_set_shift_id', $c['omar'], 201); });
$run('shift_id_after_set', function () use ($call, $c) { return $call('people_shift_id', $c['omar']); });   // forgotten by the set: read again
$run('set_shift_id_null', function () use ($call, $c) { return $call('people_set_shift_id', $c['omar'], 0); });
$run('shift_id_after_null', function () use ($call, $c) { return $call('people_shift_id', $c['omar']); });
$run('invalidate_then_emps', function () use ($call, $c) { $call('invalidate_employee_runtime_cache', $c['omar']); return $call('emps'); });
$run('shift_for_employee', function () use ($call, $c) { return $call('shift_for_employee', $c['ziad']); });
$run('shift_for_employee_none', function () use ($call, $c) { return $call('shift_for_employee', $c['omar']); });
$run('shift_prime_people', function () use ($call, $ids) { return $call('shift_prime_people', array_map(function ($i) { return (object) ['id' => $i]; }, $ids)); });
$run('prime_shift_cache_big', function () use ($call, $big) { return $call('prime_shift_cache', array_map(function ($i) { return (object) ['id' => $i]; }, $big)); });

// ---------------------------------------------------------------- 5. reporting lines
$run('related_employee', function () use ($call, $c) { return $call('approval_related_employee', $c['omar'], 'supervisor'); });
$run('related_employee_archived', function () use ($call, $c) { return $call('approval_related_employee', $c['nour'], 'supervisor'); });
$run('related_employee_none', function () use ($call, $c) { return $call('approval_related_employee', $c['mona'], 'supervisor'); });
$run('supervisor_ids', function () use ($call, $ids) { return $call('approval_supervisor_ids', $ids); });
$run('supervisor_ids_many', function () use ($call, $many) { return $call('approval_supervisor_ids', $many); });
$run('manager_of', function () use ($call, $c) { return $call('people_manager_of', $c['omar']); });
$run('manager_of_archived_boss', function () use ($call, $c) { return $call('people_manager_of', $c['nour']); });
$run('manager_of_none', function () use ($call, $c) { return $call('people_manager_of', $c['mona']); });
$run('manager_ids', function () use ($call) { return $call('people_manager_ids'); });
$run('has_reports', function () use ($call, $c) { return $call('people_has_reports', $c['mona']); });
$run('has_reports_no', function () use ($call, $c) { return $call('people_has_reports', $c['ziad']); });
$run('org_rows', function () use ($call) { return $call('people_org_rows'); });

// ---------------------------------------------------------------- 6. departments and teams
$run('department_manager', function () use ($call, $c) { return $call('people_department_manager', $c['omar']); });
$run('department_manager_other', function () use ($call, $c) { return $call('people_department_manager', $c['sam']); });
$run('team_manager_for_employee', function () use ($call, $c) { return $call('team_manager_for_employee', $c['lina']); });
$run('team_manager_for_employee_none', function () use ($call, $c) { return $call('team_manager_for_employee', $c['mona']); });
$run('team_member_ids', function () use ($call, $c) { return $call('team_member_ids', $c['alpha']); });
$run('team_ids_for_employee', function () use ($call, $c) { return $call('team_ids_for_employee', $c['omar']); });
$run('team_ids_by_employee', function () use ($call, $ids) { return $call('team_ids_by_employee', $ids); });
$run('team_ids_by_employee_many', function () use ($call, $many) { return $call('team_ids_by_employee', $many); });

// ---------------------------------------------------------------- 7. the signed-in employee
$as($c['acomar']);
$run('current_employee', function () use ($call) { return $call('current_employee'); });
$run('current_employee_again', function () use ($call) { return $call('current_employee'); });
$as(0);
$dump('_rows:');
echo wp_json_encode($trace);
