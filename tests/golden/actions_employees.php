<?php
/**
 * The golden harness's employee-writes trace (tests/golden/run.py; docs/tasks/people-employee-writes.md §4): adding, updating and archiving an
 * employee on the Employees page, and the profile picture, driven in process through their own admin-post handlers (tests/golden/exit.php). For each call: what it returned or where it redirected, the exact
 * SQL (in order, dates and times as placeholders), the query count; at the end every people table, the approval steps, the
 * notifications and the Audit Log. The same script runs on the old and the new code from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_employees.php   (prints one JSON line)
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/exit.php';
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- dates as placeholders
$D0 = current_time('Y-m-d');
$day = function ($n) use ($D0) { return gmdate('Y-m-d', strtotime($D0 . ' ' . ($n >= 0 ? '+' : '') . $n . ' days')); };
$dates = [$D0 => 'D0', $day(1) => 'D1', $day(5) => 'D5', $day(-10) => 'DP', $day(30) => 'DF', $day(-400) => 'DOLD', $day(-20) => 'DM20', $day(400) => 'DFAR'];
$dateKeys = array_keys($dates);
$fix = function ($s) use ($dates) { return strtr($s, $dates); };

// ---------------------------------------------------------------- recording (with in-process fault injection)
$sql = [];
$fault = null;
add_filter('query', function ($q) use (&$sql, $fix) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $n = preg_replace("/(_transient_timeout_\w+', ')\d+/", '$1TS', $n);
    $sql[] = $fix(trim(preg_replace('/\s+/', ' ', $n)));
    return $q;
}, 1);
add_filter('query', function ($q) use (&$fault) {
    if ($fault === null) return $q;
    [$re, $to] = is_array($fault) ? $fault : [$fault, 'SELECT broken_on_purpose FROM no_such_table_x'];
    return preg_match($re, $q) ? $to : $q;
}, 5);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => preg_replace('/(<|&lt;)div.*$/s', '', $v->get_error_message())];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) {
        $o = [];
        foreach ($v as $k => $x) $o[$k] = ($k === 'nonce' && $x !== '') ? 'N' : $norm($x);
        return $o;
    }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        $v = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($v, $dates));
        $v = preg_replace('#^https?://[^/]+#', '', $v);
        $v = preg_replace('/(<|&lt;)div.*$/s', '', $v);   // the query log a failed query appends
    }
    return $v;
};
$trace = [];
$as = function ($uid) { wp_set_current_user((int) $uid); };
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm, $wpdb) {
    wp_cache_flush();   // every call starts with a cold object cache
    $sql = [];
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};
$faulty = function ($label, $pattern, callable $fn) use (&$fault, $run, $wpdb) {
    $fault = $pattern;
    $quiet = $wpdb->suppress_errors(true);
    try { $run($label, $fn); } finally { $wpdb->suppress_errors($quiet); $fault = null; }
};
$post = function ($method, array $fields, $nonce, $referer = null) use ($o) { return golden_post($o, $method, $fields, $nonce, $referer); };

// ---------------------------------------------------------------- people and settings (plain SQL, no People code)
$admin = (int) get_user_by('login', 'admin')->ID;
$emp1 = (int) get_user_by('login', 'emp1')->ID;
$gdreq = (int) $wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE domain_name='gdreq'");
$gdmgr = (int) $wpdb->get_var("SELECT id FROM {$p}ews_employees WHERE domain_name='gdmgr'");
$shifts = array_values(array_map(function ($s) { return (int) $s['id']; }, array_values($call('shifts'))));
$shiftA = $shifts[0];
$shiftB = $shifts[1] ?? $shifts[0];
$newUser = function ($login) { return (int) (username_exists($login) ?: wp_create_user($login, 'pw-' . $login, $login . '@example.com')); };
$newEmp = function ($name, $domain, $dept = null, $active = 1, $user = null, $shift = null, $extra = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_employees', $extra + ['name' => $name, 'domain_name' => $domain, 'email' => $domain . '@example.com', 'active' => $active, 'attendance_enabled' => 1, 'department_id' => $dept, 'wp_user_id' => $user, 'default_shift_id' => $shift]);
    return (int) $wpdb->insert_id;
};
$line = function ($e, $m) use ($wpdb, $p) { $wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $e, 'relationship_type' => 'supervisor', 'related_employee_id' => $m, 'active' => 1]); };
$device = function ($user) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_api_devices', ['public_id' => substr(md5('dev' . $user . microtime()), 0, 32), 'user_id' => $user, 'installation_id' => 'inst' . $user, 'platform' => 'android', 'model' => 'm', 'app_version' => '1',
        'created_at' => current_time('mysql'), 'last_seen_at' => current_time('mysql'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400 * 30)]);
};
$boss = $newEmp('Emp Boss', 'empboss', $c['ops']);
$wA = $newEmp('Emp A', 'empa', $c['ops'], 1, $newUser('gdlinka'), $shiftA);   $line($wA, $boss);
$wB = $newEmp('Emp B', 'empb', $c['ops'], 1, $newUser('gdlinkb'), $shiftA);   $line($wB, $boss);
$wC = $newEmp('Emp C', 'empc', $c['ops'], 1, null, null);
$wD = $newEmp('Emp D', 'empd', $c['ops'], 1, $newUser('gdlinkd'), $shiftA);   // gets devices and an advance
$wE = $newEmp('Emp E', 'empe', $c['sales'], 0);                                // already archived
$wF = $newEmp('Emp F', 'empf', $c['ops'], 1, null, $shiftA);                   // manages a team in ops
$wG = $newEmp('Emp G', 'empg', $c['ops'], 1, null, $shiftA);
$wpdb->insert($p . 'ews_teams', ['name' => 'Emp Team', 'manager_employee_id' => $wF, 'department_id' => $c['ops'], 'active' => 1]);
$teamOps = (int) $wpdb->insert_id;
$wpdb->insert($p . 'ews_teams', ['name' => 'Sales Emp Team', 'manager_employee_id' => $c['sam'], 'department_id' => $c['sales'], 'active' => 1]);
$teamSales = (int) $wpdb->insert_id;
$free = $newUser('gdfree');
$free2 = $newUser('gdfree2');
$device($wD ? (int) $wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE id={$wD}") : 0);
$device((int) $wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE id={$wA}"));
$device((int) $wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE id={$wB}"));
update_option('ews_org_chart', ['enabled' => 0, 'audience' => 'managers', 'title_required' => 0], false);
delete_option('ews_feature_advances');
if (method_exists($o, 'ensure_advances_schema')) { $m = new ReflectionMethod($o, 'ensure_advances_schema'); $m->setAccessible(true); $m->invoke($o); }
$wpdb->insert($p . 'ews_salary_advances', ['employee_id' => $wD, 'amount' => 900, 'instalments' => 3, 'reason' => 'x', 'status' => 'Paid', 'requested_at' => current_time('mysql'), 'paid_on' => $D0]);
$adv = (int) $wpdb->insert_id;
foreach ([1, 2, 3] as $i) $wpdb->insert($p . 'ews_advance_instalments', ['advance_id' => $adv, 'employee_id' => $wD, 'month' => gmdate('Y-m', strtotime($D0 . " +$i month")), 'seq' => $i, 'amount' => 300, 'taken_run_id' => 0]);
$as($admin);
$referer = admin_url('admin.php?page=ews31-employees&paged=2&s=emp');
$appReferer = home_url('/app/?ews_view=employees');
$form = function (array $over = []) use ($c, $shiftA) {
    return $over + ['name' => 'Gd Form', 'domain_name' => 'gdform', 'email' => 'gdform@example.com', 'default_shift_id' => $shiftA, 'department_id' => $c['ops'], 'attendance_enabled' => '1', 'active' => '1'];
};
$save = function (array $f, $nonce = 'ews31_employee_save', $ref = null) use ($post, $referer) { return $post('employee_save', $f, $nonce, $ref ?? $referer); };
$upd = function (array $f, $nonce = 'ews31_employee_update', $ref = null) use ($post, $referer) { return $post('employee_update', $f, $nonce, $ref ?? $referer); };
$arch = function (array $f, $nonce = 'ews31_employee_archive', $ref = null) use ($post, $referer) { return $post('employee_archive', $f, $nonce, $ref ?? $referer); };
$ref = function ($e) { return '#' . $e; };
$table = function ($t) use ($p) { return '/^\s*(?:INSERT\s+INTO|UPDATE)\s+`?' . preg_quote($p . $t, '/') . '`?\s/i'; };

// ---------------------------------------------------------------- 1. save (admin-post ews31_employee_save)
$as($emp1);
$run('save_denied', function () use ($save, $form) { return $save($form()); });
$as($admin);
$run('save_bad_nonce', function () use ($save, $form) { return $save($form(), 'bad'); });
$run('save_required', function () use ($save, $form) { return $save($form(['name' => ''])); });
$run('save_bad_email', function () use ($save, $form) { return $save($form(['email' => 'nope'])); });
$run('save_department', function () use ($save, $form) { return $save($form(['department_id' => 999999])); });
update_option('ews_org_chart', ['enabled' => 1, 'audience' => 'managers', 'title_required' => 1], false);
$run('save_title_missing', function () use ($save, $form) { return $save($form(['job_title' => ''])); });
update_option('ews_org_chart', ['enabled' => 0, 'audience' => 'managers', 'title_required' => 0], false);
$run('save_domain_taken', function () use ($save, $form) { return $save($form(['domain_name' => 'empa'])); });
$run('save_user_unknown', function () use ($save, $form) { return $save($form(['wp_user_ref' => '#999999'])); });
$run('save_user_taken', function () use ($save, $form, $emp1) { return $save($form(['wp_user_ref' => '#' . $emp1])); });
$run('save_supervisor_archived', function () use ($save, $form, $c) { return $save($form(['supervisor_employee_ref' => '#' . $c['oldboss']])); });
$run('save_team_department', function () use ($save, $form, $teamSales) { return $save($form(['team_ids' => [$teamSales]])); });
$run('save_ok', function () use ($save, $form, $free, $boss, $teamOps, $shiftB) { return $save($form(['wp_user_ref' => '#' . $free, 'supervisor_employee_ref' => '#' . $boss, 'team_ids' => [$teamOps], 'default_shift_id' => $shiftB])); });
$run('save_ok_minimal', function () use ($save, $form) { return $save($form(['name' => 'Gd Min', 'domain_name' => 'gdmin', 'email' => 'gdmin@example.com', 'default_shift_id' => 0, 'department_id' => 0])); });
$run('save_ok_with_title', function () use ($save, $form) { return $save($form(['name' => 'Gd Title', 'domain_name' => 'gdtitle', 'email' => 'gdtitle@example.com', 'job_title' => 'Lead Tester'])); });
$run('save_ok_app_referer', function () use ($save, $form) { return $save($form(['name' => 'Gd App', 'domain_name' => 'gdapp', 'email' => 'gdapp@example.com']), 'ews31_employee_save', $appReferer); });
$run('save_error_app_referer', function () use ($save, $form) { return $save($form(['domain_name' => 'empa']), 'ews31_employee_save', $appReferer); });
$faulty('save_fault_insert', $table('ews_employees'), function () use ($save, $form) { return $save($form(['name' => 'Gd F1', 'domain_name' => 'gdf1', 'email' => 'gdf1@example.com'])); });
$faulty('save_fault_supervisor_line', $table('ews_employee_relationships'), function () use ($save, $form, $boss) { return $save($form(['name' => 'Gd F2', 'domain_name' => 'gdf2', 'email' => 'gdf2@example.com', 'supervisor_employee_ref' => '#' . $boss])); });
$faulty('save_fault_periods_insert', $table('ews_employee_shift_periods'), function () use ($save, $form) { return $save($form(['name' => 'Gd F3', 'domain_name' => 'gdf3', 'email' => 'gdf3@example.com'])); });

// ---------------------------------------------------------------- 2. update (admin-post ews31_employee_update)
$as($emp1);
$run('update_denied', function () use ($upd, $wC) { return $upd(['id' => $wC]); });
$as($admin);
$run('update_bad_nonce', function () use ($upd, $wC) { return $upd(['id' => $wC], 'bad'); });
$run('update_not_found_zero', function () use ($upd, $form) { return $upd($form(['id' => 0])); });
$run('update_not_found', function () use ($upd, $form) { return $upd($form(['id' => 999999])); });
$same = function ($id, array $over = []) use ($wpdb, $p, $form) {
    $r = $wpdb->get_row("SELECT * FROM {$p}ews_employees WHERE id={$id}");
    return $over + $form(['id' => $id, 'name' => $r->name, 'domain_name' => $r->domain_name, 'email' => $r->email, 'default_shift_id' => (int) $r->default_shift_id, 'department_id' => (int) $r->department_id, 'active' => (string) (int) $r->active])
        + ($r->wp_user_id ? ['wp_user_ref' => '#' . $r->wp_user_id] : []);
};
$run('update_required', function () use ($upd, $same, $wC) { return $upd($same($wC, ['name' => ''])); });
$run('update_self_supervisor', function () use ($upd, $same, $wC) { return $upd($same($wC, ['supervisor_employee_ref' => '#' . $wC])); });
$run('update_loop_direct', function () use ($upd, $same, $boss, $wA) { return $upd($same($boss, ['supervisor_employee_ref' => '#' . $wA])); });
$run('update_loop_deep', function () use ($upd, $same, $boss, $wA, $c) { $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->prefix . 'ews_employee_relationships', ['employee_id' => $wA, 'relationship_type' => 'supervisor', 'related_employee_id' => $boss, 'active' => 1]); return $upd($same($boss, ['supervisor_employee_ref' => '#' . $wA])); });
$run('update_supervisor_archived', function () use ($upd, $same, $wC, $c) { return $upd($same($wC, ['supervisor_employee_ref' => '#' . $c['oldboss']])); });
$run('update_manages_team', function () use ($upd, $same, $wF, $c) { return $upd($same($wF, ['department_id' => $c['sales']])); });
$run('update_team_department', function () use ($upd, $same, $wC, $teamSales) { return $upd($same($wC, ['team_ids' => [$teamSales]])); });
$run('update_user_taken', function () use ($upd, $same, $wC, $emp1) { return $upd($same($wC, ['wp_user_ref' => '#' . $emp1])); });
$run('update_nochange', function () use ($upd, $same, $wG) { return $upd($same($wG)); });
$run('update_rename_title_set', function () use ($upd, $same, $wG) { return $upd($same($wG, ['name' => 'Emp G2', 'job_title' => 'Tester'])); });
$run('update_title_empty', function () use ($upd, $same, $wG) { return $upd($same($wG, ['job_title' => ''])); });
$run('update_shift_change', function () use ($upd, $same, $wC, $shiftB) { return $upd($same($wC, ['default_shift_id' => $shiftB])); });
$run('update_shift_busy', function () use ($upd, $same, $wG, $shiftB) { return $upd($same($wG, ['default_shift_id' => $shiftB])); });   // replaced below by the busy variant
$faulty('update_shift_busy_lock', ['/GET_LOCK\(/i', 'SELECT 0'], function () use ($upd, $same, $wA, $shiftB) { return $upd($same($wA, ['default_shift_id' => $shiftB])); });
$faulty('update_fault_periods_insert', $table('ews_employee_shift_periods'), function () use ($upd, $same, $wB, $shiftB) { return $upd($same($wB, ['default_shift_id' => $shiftB])); });
$faulty('update_fault_employee_update', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_employees`?\s+SET\s+`?name`?/i', function () use ($upd, $same, $wC) { return $upd($same($wC, ['name' => 'Emp C Fault'])); });
$run('update_deactivate_date', function () use ($upd, $same, $wC, $day) { return $upd($same($wC, ['active' => '0', 'last_working_day' => $day(-2)])); });
$run('update_reactivate', function () use ($upd, $same, $wC) { return $upd($same($wC, ['active' => '1'])); });
$run('update_deactivate_invalid_date', function () use ($upd, $same, $wC) { return $upd($same($wC, ['active' => '0', 'last_working_day' => '2026-13-45'])); });
$run('update_deactivate_no_date', function () use ($upd, $same, $wE) { return $upd($same($wE, ['active' => '0'])); });
$run('update_deactivate_sessions', function () use ($upd, $same, $wA) { return $upd($same($wA, ['active' => '0', 'last_working_day' => $GLOBALS['D0']])); });
$run('update_relink', function () use ($upd, $same, $wB, $free2) { return $upd($same($wB, ['wp_user_ref' => '#' . $free2])); });
$run('update_unlink', function () use ($upd, $same, $wB) { $f = $same($wB); unset($f['wp_user_ref']); return $upd($f); });
update_option('ews_feature_advances', 1, false);
$run('update_deactivate_advance', function () use ($upd, $same, $wD) { return $upd($same($wD, ['active' => '0'])); });
$run('update_advance_on_no_status_change', function () use ($upd, $same, $wG) { return $upd($same($wG)); });
delete_option('ews_feature_advances');
$run('update_supervisor_chart_off', function () use ($upd, $same, $wG, $boss) { return $upd($same($wG, ['supervisor_employee_ref' => '#' . $boss])); });
$run('update_supervisor_remove_chart_off', function () use ($upd, $same, $wG) { return $upd($same($wG)); });
$faulty('update_fault_supervisor_line', $table('ews_employee_relationships'), function () use ($upd, $same, $wG, $boss) { return $upd($same($wG, ['supervisor_employee_ref' => '#' . $boss])); });
update_option('ews_org_chart', ['enabled' => 1, 'audience' => 'managers', 'title_required' => 0], false);
$run('update_supervisor_chart_on', function () use ($upd, $same, $gdreq, $c) { return $upd($same($gdreq, ['supervisor_employee_ref' => '#' . $c['mona']])); });
$run('update_supervisor_chart_on_none', function () use ($upd, $same, $gdreq) { return $upd($same($gdreq)); });
$run('update_chart_on_same_supervisor', function () use ($upd, $same, $wG, $boss) { return $upd($same($wG, ['supervisor_employee_ref' => '#' . $boss])); });
$faulty('update_fault_approval_steps', $table('ews_approval_steps'), function () use ($upd, $same, $gdreq, $gdmgr) { return $upd($same($gdreq, ['supervisor_employee_ref' => '#' . $gdmgr])); });
$faulty('update_fault_org_move_insert', $table('ews_org_moves'), function () use ($upd, $same, $wG) { return $upd($same($wG)); });
update_option('ews_org_chart', ['enabled' => 0, 'audience' => 'managers', 'title_required' => 0], false);
$faulty('update_fault_team_members_insert', $table('ews_team_members'), function () use ($upd, $same, $wG, $teamOps) { return $upd($same($wG, ['team_ids' => [$teamOps]])); });
$run('update_teams_ok', function () use ($upd, $same, $wG, $teamOps) { return $upd($same($wG, ['team_ids' => [$teamOps]])); });
$run('update_department_change_teams', function () use ($upd, $same, $wG, $c, $teamSales) { return $upd($same($wG, ['department_id' => $c['sales'], 'team_ids' => [$teamSales]])); });
$run('update_app_referer', function () use ($upd, $same, $wG) { return $upd($same($wG, ['name' => 'Emp G3']), 'ews31_employee_update', $GLOBALS['appReferer']); });

// ---------------------------------------------------------------- 3. archive (admin-post ews31_employee_archive)
$as($emp1);
$run('archive_denied', function () use ($arch, $wC) { return $arch(['id' => $wC]); });
$as($admin);
$run('archive_bad_nonce', function () use ($arch, $wC) { return $arch(['id' => $wC], 'bad'); });
$run('archive_not_found', function () use ($arch) { return $arch(['id' => 999999]); });
$run('archive_zero', function () use ($arch) { return $arch(['id' => 0]); });
$run('archive_ok', function () use ($arch, $wC) { return $arch(['id' => $wC]); });
$run('archive_with_date', function () use ($arch, $wG, $day) { return $arch(['id' => $wG, 'last_working_day' => $day(-3)]); });
$run('archive_invalid_date', function () use ($arch, $boss) { return $arch(['id' => $boss, 'last_working_day' => 'soon']); });
$run('archive_already', function () use ($arch, $wC) { return $arch(['id' => $wC]); });
$run('archive_sessions', function () use ($arch, $wB) { return $arch(['id' => $wB]); });
update_option('ews_feature_advances', 1, false);
$wpdb->update($p . 'ews_employees', ['active' => 1], ['id' => $wD]);
$run('archive_advance', function () use ($arch, $wD) { return $arch(['id' => $wD]); });
$run('archive_advance_again', function () use ($arch, $wD) { return $arch(['id' => $wD]); });
delete_option('ews_feature_advances');
$faulty('archive_fault', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_employees`?\s+SET\s+`?active`?/i', function () use ($arch, $wA) { return $arch(['id' => $wA]); });

// ---------------------------------------------------------------- 4. the profile picture (admin-post ews_profile_photo_save)
$photo = function (array $f, $nonce = 'ews_profile_photo_save', array $files = null) use ($post, $appReferer) {
    $_FILES = $files ?? [];
    try { return $post('profile_photo_save', $f, $nonce, $appReferer); } finally { $_FILES = []; }
};
// golden_post puts the nonce in _wpnonce; this handler reads that field
$as(0);
$run('profile_not_logged_in', function () use ($photo) { return $photo(['profile_photo_action' => 'reset']); });
$as($emp1);
$run('profile_bad_nonce', function () use ($photo) { return $photo(['profile_photo_action' => 'reset'], 'bad'); });
$run('profile_avatar_invalid', function () use ($photo) { return $photo(['profile_photo_action' => 'avatar', 'avatar_key' => 'no-such-avatar']); });
$catalog = (new ReflectionMethod($o, 'profile_avatar_catalog')); $catalog->setAccessible(true);
$avatarKeys = array_keys($catalog->invoke($o));
$run('profile_avatar_ok', function () use ($photo, $avatarKeys) { return $photo(['profile_photo_action' => 'avatar', 'avatar_key' => $avatarKeys[0]]); });
$faulty('profile_avatar_fault', $table('ews_employees'), function () use ($photo, $avatarKeys) { return $photo(['profile_photo_action' => 'avatar', 'avatar_key' => $avatarKeys[0]]); });
$run('profile_reset', function () use ($photo) { return $photo(['profile_photo_action' => 'reset']); });
$faulty('profile_reset_fault', $table('ews_employees'), function () use ($photo) { return $photo(['profile_photo_action' => 'reset']); });
$run('profile_upload_none', function () use ($photo) { return $photo(['profile_photo_action' => 'upload']); });
$run('profile_upload_error', function () use ($photo) { return $photo(['profile_photo_action' => 'upload'], 'ews_profile_photo_save', ['profile_photo' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '', 'error' => 4, 'size' => 0]]); });
$run('profile_upload_size', function () use ($photo) { return $photo(['profile_photo_action' => 'upload'], 'ews_profile_photo_save', ['profile_photo' => ['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => '/nonexistent', 'error' => 0, 'size' => 3 * 1024 * 1024]]); });
$txt = tempnam(sys_get_temp_dir(), 'gd');
file_put_contents($txt, 'not an image');
$run('profile_upload_type', function () use ($photo, $txt) { return $photo(['profile_photo_action' => 'upload'], 'ews_profile_photo_save', ['profile_photo' => ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $txt, 'error' => 0, 'size' => 12]]); });
@unlink($txt);
$noEmployee = $newUser('gdnoemp');
$as($noEmployee);
$run('profile_no_employee', function () use ($photo) { return $photo(['profile_photo_action' => 'reset']); });

// ---------------------------------------------------------------- the tables at the end
$as(0);
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', wp_json_encode($wpdb->get_results($q, ARRAY_A)))), true)); };
foreach ([
    'employees' => "SELECT * FROM {$p}ews_employees ORDER BY id",
    'relationships' => "SELECT * FROM {$p}ews_employee_relationships ORDER BY id",
    'team_members' => "SELECT * FROM {$p}ews_team_members ORDER BY id",
    'org_moves' => "SELECT * FROM {$p}ews_org_moves ORDER BY id",
    'shift_periods' => "SELECT employee_id,shift_id,from_date,to_date FROM {$p}ews_employee_shift_periods ORDER BY id",
    'devices' => "SELECT user_id,revoked_at,revoked_reason FROM {$p}ews_api_devices ORDER BY id",
    'approval_steps' => "SELECT id,approval_request_id,step_order,approver_employee_id,approver_wp_user_id,status FROM {$p}ews_approval_steps ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
echo wp_json_encode($trace);
