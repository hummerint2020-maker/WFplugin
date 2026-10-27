<?php
/**
 * The golden harness's org-writes trace (tests/golden/run.py; docs/tasks/people-org-writes.md §4): the organization chart's moves,
 * the import, the reporting-line writes, the departments and the teams, driven in process through their own handlers and
 * functions (admin-post handlers through tests/golden/exit.php). For each call: what it returned or where it redirected, the exact
 * SQL (in order, dates and times as placeholders), the query count; at the end every people table, the approval steps, the
 * notifications and the Audit Log. The same script runs on the old and the new code from the same snapshot; the traces must be equal.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_org.php   (prints one JSON line)
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
    $q = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $sql[] = $fix(trim(preg_replace('/\s+/', ' ', $q)));
    return $q;
}, 1);
add_filter('query', function ($q) use (&$fault) {
    if ($fault !== null && preg_match($fault, $q)) return 'SELECT broken_on_purpose FROM no_such_table_x';
    return $q;
}, 5);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => $v->get_error_message()];
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
$eid = function ($domain) use ($wpdb, $p) { return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_employees WHERE domain_name=%s", $domain)); };
$gdreq = $eid('gdreq');
$gdmgr = $eid('gdmgr');
$newEmp = function ($name, $domain, $dept = null, $active = 1) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_employees', ['name' => $name, 'domain_name' => $domain, 'email' => $domain . '@example.com', 'active' => $active, 'attendance_enabled' => 1, 'department_id' => $dept]);
    return (int) $wpdb->insert_id;
};
$orgc = $newEmp('Org C', 'orgc', $c['ops']);
$orgd = $newEmp('Org D', 'orgd', $c['ops']);
$orge = $newEmp('Org E', 'orge', $c['ops']);
$orgf = $newEmp('Org F', 'orgf', $c['sales']);
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $orge, 'relationship_type' => 'supervisor', 'related_employee_id' => $orgd, 'active' => 1]);
$wpdb->insert($p . 'ews_departments', ['name' => 'Empty', 'code' => 'empty', 'active' => 1]);
$empty = (int) $wpdb->insert_id;
$wpdb->insert($p . 'ews_departments', ['name' => 'Gone', 'code' => 'gone', 'active' => 0]);
$gone = (int) $wpdb->insert_id;
$wpdb->insert($p . 'ews_teams', ['name' => 'Ghost', 'manager_employee_id' => $c['mona'], 'department_id' => $c['ops'], 'active' => 1]);
$wpdb->insert($p . 'ews_teams', ['name' => 'Solo', 'manager_employee_id' => $orgf, 'department_id' => $c['sales'], 'active' => 1]);
$solo = (int) $wpdb->insert_id;
update_option('ews_org_chart', ['enabled' => 1, 'audience' => 'managers', 'title_required' => 0], false);
$as($admin);
$chart = function () use ($wpdb, $p, $c) {   // the moves table as the seed left it
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ews_org_moves");
};
$referer = admin_url('admin.php?page=ews31-org-chart');
$mv = function (array $f) use ($c) { return $f + ['reason' => '']; };

// ---------------------------------------------------------------- 1. the checks (the rules read in a fixed order)
$run('check_ok', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['sam'], $D0, 'why', true); });
$run('check_employee_0', function () use ($call, $c, $D0) { return $call('org_move_check', 0, $c['sam'], $D0, 'why', true); });
$run('check_self', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['lina'], $D0, 'why', true); });
$run('check_manager_archived', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['oldboss'], $D0, 'why', true); });
$run('check_bad_date', function () use ($call, $c) { return $call('org_move_check', $c['lina'], $c['sam'], 'soon', 'why', true); });
$run('check_far_date', function () use ($call, $c, $day) { return $call('org_move_check', $c['lina'], $c['sam'], $day(400), 'why', true); });
$run('check_reason_required', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['sam'], $D0, '', true); });
$run('check_reason_optional', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['sam'], $D0, '', false); });
$run('check_scheduled_exists', function () use ($call, $c, $D0) { return $call('org_move_check', $c['lina'], $c['sam'], $D0, 'why', true); });   // lina has a scheduled move from the seed
$run('check_scheduled_ignored', function () use ($call, $c, $D0, $wpdb, $p) { return $call('org_move_check', $c['lina'], $c['sam'], $D0, 'why', true, (int) $wpdb->get_var("SELECT id FROM {$p}ews_org_moves WHERE employee_id={$c['lina']} AND status='scheduled'")); });
$run('check_same_now', function () use ($call, $c, $D0) { return $call('org_move_check', $c['omar'], $c['mona'], $D0, 'why', true); });
$run('check_same_future', function () use ($call, $c, $day) { return $call('org_move_check', $c['omar'], $c['mona'], $day(5), 'why', true); });
$run('check_loop', function () use ($call, $c, $D0) { return $call('org_move_check', $c['mona'], $c['ali'], $D0, 'why', true); });
$run('check_loop_not_checked', function () use ($call, $c, $D0) { return $call('org_move_check', $c['mona'], $c['ali'], $D0, 'why', true, 0, false); });
$run('check_no_manager', function () use ($call, $c, $D0) { return $call('org_move_check', $c['rana'], 0, $D0, 'why', true); });
$run('summary', function () use ($call, $c, $D0) { return $call('org_move_summary', $c['omar'], $c['mona'], 0, $D0, 'a "reason"'); });
$run('for_chart', function () use ($call) { return $call('org_moves_for_chart'); });

// ---------------------------------------------------------------- 2. the Move form (admin-post ews_org_move)
$sub = function ($f, $nonce = 'ews_org_move', $ref = null) use ($post, $referer) { return $post('org_move_submit', $f, $nonce, $ref ?? $referer); };
$run('submit_bad_nonce', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => 'r'], 'bad'); });
$as($emp1);
$run('submit_denied', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => 'r']); });
$as($admin);
update_option('ews_org_chart', ['enabled' => 0], false);
$run('submit_chart_off', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => 'r']); });
update_option('ews_org_chart', ['enabled' => 1, 'audience' => 'managers', 'title_required' => 0], false);
$run('submit_no_manager_picked', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'typed text', 'effective_date' => $D0, 'reason' => 'r']); });
$run('submit_reason_missing', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => '']); });
$run('submit_scheduled_exists', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['lina'], 'manager_ref' => 'Sam · #' . $c['sam'], 'effective_date' => $D0, 'reason' => 'r']); });
$run('submit_loop', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['mona'], 'manager_ref' => 'Ali · #' . $c['ali'], 'effective_date' => $D0, 'reason' => 'r']); });
$run('submit_move_now', function () use ($sub, $gdreq, $c, $D0) { return $sub(['employee_id' => $gdreq, 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => 'new team']); });
$run('submit_move_now_again_same', function () use ($sub, $gdreq, $c, $D0) { return $sub(['employee_id' => $gdreq, 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => $D0, 'reason' => 'again']); });
$run('submit_to_no_manager', function () use ($sub, $c, $D0) { return $sub(['employee_id' => $c['rana'], 'no_manager' => '1', 'effective_date' => $D0, 'reason' => 'leaves the tree']); });
$run('submit_scheduled', function () use ($sub, $c, $day) { return $sub(['employee_id' => $c['ziad'], 'manager_ref' => 'Omar · #' . $c['omar'], 'effective_date' => $day(5), 'reason' => 'later']); });
$run('submit_date_ignored_text', function () use ($sub, $c) { return $sub(['employee_id' => $c['nour'], 'manager_ref' => 'Mona · #' . $c['mona'], 'effective_date' => 'tomorrow', 'reason' => 'r']); });

// ---------------------------------------------------------------- 3. the supervisor writes
$run('set_supervisor', function () use ($call, $orgc, $c) { return $call('employee_admin_set_supervisor', $orgc, $c['omar']); });
$run('set_supervisor_replace', function () use ($call, $orgc, $c) { return $call('employee_admin_set_supervisor', $orgc, $c['mona']); });
$run('set_supervisor_none', function () use ($call, $orgc) { return $call('employee_admin_set_supervisor', $orgc, 0); });
$run('set_only', function () use ($call, $orgc, $c) { return $call('org_set_supervisor_only', $orgc, $c['sam']); });
$run('set_only_none', function () use ($call, $orgc) { return $call('org_set_supervisor_only', $orgc, 0); });
$run('change_direct', function () use ($call, $orgd, $c, $D0) { return $call('org_supervisor_change', $orgd, 0, $c['sam'], ['employee_id' => $orgd, 'from_manager_id' => 0, 'to_manager_id' => $c['sam'], 'effective_date' => $D0, 'reason' => '', 'status' => 'done', 'source' => 'employees'], null); });
$run('apply_direct', function () use ($call, $orgd, $c, $D0) { return $call('org_move_apply', $orgd, $c['mona'], $D0, 'applied', 'chart'); });
$run('apply_claim_lost', function () use ($call, $orgf, $c, $D0, $wpdb, $p) {
    $wpdb->insert($p . 'ews_org_moves', ['employee_id' => $orgf, 'from_manager_id' => 0, 'to_manager_id' => $c['mona'], 'effective_date' => $D0, 'reason' => 'x', 'status' => 'applying', 'source' => 'chart', 'created_by' => 1, 'created_at' => current_time('mysql'), 'applied_at' => '2020-01-01 00:00:00']);
    return $call('org_move_apply', $orgf, $c['mona'], $D0, 'x', 'chart', (int) $wpdb->insert_id, '2021-01-01 00:00:00');   // the claim time is not the stored one
});
$run('schedule', function () use ($call, $orgf, $c, $day) { return $call('org_move_schedule', $orgf, $c['sam'], $day(5), 'sched', 'chart'); });
$run('relationship_set', function () use ($call, $orgc, $c) { return $call('approval_set_relationship', $orgc, 'supervisor', $c['omar'], 0); });
$run('relationship_user', function () use ($call, $orgc, $c) { return $call('approval_set_relationship', $orgc, 'dotted', 0, 12345); });
$run('relationship_invalid', function () use ($call) { return $call('approval_set_relationship', 0, 'supervisor', 3, 0); });
$run('relationship_no_target', function () use ($call, $orgc) { return $call('approval_set_relationship', $orgc, 'supervisor', 0, 0); });

// ---------------------------------------------------------------- 4. faults on the move and the supervisor
$moveFields = function ($employee, $to) use ($D0) { return ['employee_id' => $employee, 'manager_ref' => '#' . $to, 'effective_date' => $D0, 'reason' => 'fault']; };
foreach ([
    'insert_org_moves' => '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_org_moves`?\s/i',
    'insert_relationships' => '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i',
    'update_relationships' => '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i',
    'update_approval_steps' => '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_approval_steps`?\s/i',
    'commit' => '/^\s*COMMIT\s*$/i',
    'start' => '/^\s*START\s+TRANSACTION\s*$/i',
] as $name => $re) {
    $faulty('fault_move_' . $name, $re, function () use ($sub, $moveFields, $c, $gdmgr, $orge) { return $sub($moveFields($c['ali'], $c['sam'])); });
}
$faulty('fault_set_only_commit', '/^\s*COMMIT\s*$/i', function () use ($call, $orgc, $c) { return $call('org_set_supervisor_only', $orgc, $c['mona']); });
$faulty('fault_set_only_start', '/^\s*START\s+TRANSACTION\s*$/i', function () use ($call, $orgc, $c) { return $call('org_set_supervisor_only', $orgc, $c['mona']); });
$faulty('fault_set_supervisor_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i', function () use ($call, $orgc, $c) { return $call('employee_admin_set_supervisor', $orgc, $c['sam']); });
$faulty('fault_set_supervisor_none_update', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i', function () use ($call, $orgc) { return $call('employee_admin_set_supervisor', $orgc, 0); });
$faulty('fault_relationship_update', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i', function () use ($call, $orgc, $c) { return $call('approval_set_relationship', $orgc, 'supervisor', $c['sam'], 0); });
$faulty('fault_schedule_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_org_moves`?\s/i', function () use ($call, $orgf, $c, $day) { return $call('org_move_schedule', $orgf, $c['mona'], $day(5), 'fault', 'chart'); });

// ---------------------------------------------------------------- 5. cancel
$ids = $wpdb->get_results("SELECT id,status,employee_id FROM {$p}ews_org_moves ORDER BY id", ARRAY_A);
$firstOf = function ($status) use ($ids) { foreach ($ids as $r) if ($r['status'] === $status) return (int) $r['id']; return 0; };
$cancel = function ($id, $nonce = null) use ($post, $referer) { return $post('org_move_cancel', ['move_id' => $id], $nonce ?? ('ews_org_move_cancel_' . $id), $referer); };
$run('cancel_bad_nonce', function () use ($cancel, $firstOf) { return $cancel($firstOf('scheduled'), 'bad'); });
$as($emp1);
$run('cancel_denied', function () use ($cancel, $firstOf) { return $cancel($firstOf('scheduled')); });
$as($admin);
$run('cancel_scheduled', function () use ($cancel, $firstOf) { return $cancel($firstOf('scheduled')); });
$run('cancel_failed', function () use ($cancel, $firstOf) { return $cancel($firstOf('failed')); });
$run('cancel_done', function () use ($cancel, $firstOf) { return $cancel($firstOf('done')); });
$run('cancel_unknown', function () use ($cancel) { return $cancel(999999); });
$run('for_chart_after', function () use ($call) { return $call('org_moves_for_chart'); });

// ---------------------------------------------------------------- 6. the daily run (cron: no user)
$mkMove = function ($employee, $to, $date, $status = 'scheduled', array $more = []) use ($wpdb, $p) {
    $wpdb->insert($p . 'ews_org_moves', $more + ['employee_id' => $employee, 'from_manager_id' => 0, 'to_manager_id' => $to, 'effective_date' => $date, 'reason' => 'due', 'status' => $status, 'source' => 'chart', 'created_by' => 1, 'created_at' => current_time('mysql')]);
    return (int) $wpdb->insert_id;
};
$wpdb->query("DELETE FROM {$p}ews_org_moves");
$ago = function ($minutes) { return gmdate('Y-m-d H:i:s', current_time('timestamp') - $minutes * 60); };
$mkMove($orgc, $c['mona'], $day(-1));                         // due, ok
$mkMove($orgd, $c['oldboss'], $D0);                           // due, the manager is archived: failed
$mkMove($c['nour'], $c['oldboss'], $day(-1), 'done');         // history
$mkMove($c['omar'], $c['mona'], $D0);                         // due, already in place
$mkMove($orge, $orgf, $day(-2));                              // a loop pair, resolvable: E under F ... below
$mkMove($orgf, $orgc, $day(-2));
$mkMove($c['sam'], $c['mona'], $day(-400));                   // an out-of-range date is still applied
$mkMove($c['ziad'], $c['omar'], $day(5));                     // not due
$mkMove($c['rana'], $c['omar'], $day(-1), 'applying', ['applied_at' => null]);           // interrupted, claimed before 3.31.119
$mkMove($c['lina'], $c['omar'], $day(-1), 'applying', ['applied_at' => $ago(20)]);       // old claim: resumed, then done in this run
$mkMove($c['ali'], $c['mona'], $day(-1), 'applying', ['applied_at' => $ago(20), 'note' => 'resumed']);   // old claim, already resumed once: failed
$mkMove($orge, $orgc, $day(-1), 'applying', ['applied_at' => $ago(1)]);                   // a fresh claim: left alone
$as(0);
$run('apply_due', function () use ($o) { return $o->org_moves_apply_due(); });
$run('apply_due_again', function () use ($o) { return $o->org_moves_apply_due(); });
delete_transient('ews_org_moves_checked');
$run('maybe_due', function () use ($o) { $o->org_moves_maybe_due(); return get_transient('ews_org_moves_checked'); });
$run('maybe_due_gate', function () use ($o) { $o->org_moves_maybe_due(); return get_transient('ews_org_moves_checked'); });
$run('for_chart_due', function () use ($call) { return $call('org_moves_for_chart'); });
$wpdb->query("RENAME TABLE {$p}ews_org_moves TO {$p}ews_org_moves_x");
$run('apply_due_no_table', function () use ($o) { return $o->org_moves_apply_due(); });
$run('for_chart_no_table', function () use ($call) { return $call('org_moves_for_chart'); });
$wpdb->query("RENAME TABLE {$p}ews_org_moves_x TO {$p}ews_org_moves");
// a due move that loses its claim, and one whose transaction fails
$as($admin);
$mkMove($c['nour'], $c['sam'], $D0);
$faulty('fault_due_commit', '/^\s*COMMIT\s*$/i', function () use ($o) { return $o->org_moves_apply_due(); });
$run('apply_due_after_fault', function () use ($o) { return $o->org_moves_apply_due(); });

// ---------------------------------------------------------------- 7. the managers import
$planFor = function (array $moves, array $titles = []) {
    return ['rows' => 5, 'unchanged' => 1, 'moves' => $moves, 'titles' => $titles, 'problems' => [['row' => 9, 'code' => 'manager_unknown', 'value' => 'zz']], 'error' => ''];
};
$key = 'ews_org_import_' . $admin;
$import = function ($fields = []) use ($post, $referer) { return $post('org_import_apply', $fields, 'ews_org_import_apply', $referer); };
$run('import_expired', function () use ($import, $key) { delete_transient($key); return $import(); });
set_transient($key, ['plan' => $planFor([['row' => 2, 'employee' => $c['sam'], 'from' => 0, 'to' => $c['mona']]]), 'date' => $D0, 'reason' => 'r', 'file' => 'f.csv'], 1800);
$run('import_cancel', function () use ($import) { return $import(['cancel' => '1']); });
set_transient($key, ['plan' => $planFor([['row' => 2, 'employee' => $c['sam'], 'from' => 0, 'to' => $c['mona']]]), 'date' => $D0, 'reason' => 'r', 'file' => 'f.csv'], 1800);
update_option('ews_org_chart', ['enabled' => 0], false);
$run('import_chart_off', function () use ($import) { return $import(); });
update_option('ews_org_chart', ['enabled' => 1, 'audience' => 'managers', 'title_required' => 0], false);
$people = $call('org_import_people');
$run('import_people', function () use ($call) { return $call('org_import_people'); });
$moves = [
    ['row' => 2, 'employee' => $c['sam'], 'from' => 0, 'to' => $c['mona']],
    ['row' => 3, 'employee' => $orge, 'from' => $orgd, 'to' => $orgf],       // a loop until the next row is done
    ['row' => 4, 'employee' => $orgf, 'from' => 0, 'to' => $orgc],
    ['row' => 5, 'employee' => $c['omar'], 'from' => $c['mona'], 'to' => $c['mona']],   // already in place by now: skipped
    ['row' => 6, 'employee' => $c['oldboss'], 'from' => 0, 'to' => $c['mona']],         // archived: skipped
];
$titles = [['row' => 2, 'employee' => $c['mona'], 'from' => 'Operations Lead', 'to' => 'Head of Ops'], ['row' => 3, 'employee' => $c['oldboss'], 'from' => '', 'to' => 'Gone']];
set_transient($key, ['plan' => $planFor($moves, $titles), 'date' => $D0, 'reason' => 'imported', 'file' => 'f.csv'], 1800);
$run('import_now', function () use ($import) { return $import(); });
set_transient($key, ['plan' => $planFor([['row' => 2, 'employee' => $c['ziad'], 'from' => 0, 'to' => $c['sam']], ['row' => 3, 'employee' => $c['ali'], 'from' => 0, 'to' => $c['sam']]]), 'date' => $day(1), 'reason' => 'later', 'file' => 'g.csv'], 1800);
$run('import_scheduled', function () use ($import) { return $import(); });
set_transient($key, ['plan' => $planFor([['row' => 2, 'employee' => $c['rana'], 'from' => 0, 'to' => $c['sam']]]), 'date' => $D0, 'reason' => 'fault', 'file' => 'h.csv'], 1800);
$faulty('fault_import_commit', '/^\s*COMMIT\s*$/i', function () use ($import) { return $import(); });

// ---------------------------------------------------------------- 8. the Approvals page's supervisor list
$relSave = function ($fields, $nonce = 'ews_approval_relationship_save', $ref = null) use ($post) { return $post('approval_relationship_save', $fields, $nonce, $ref); };
$listRef = admin_url('admin.php?page=ews31-approvals&paged=2&s=om');
$as($emp1);
$run('relsave_denied', function () use ($relSave, $c) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '#' . $c['sam']], 'ews_approval_relationship_save', null); });
$as($admin);
$run('relsave_self', function () use ($relSave, $c, $listRef) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '#' . $c['lina']], 'ews_approval_relationship_save', $listRef); });
$run('relsave_typed', function () use ($relSave, $c) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => 'typed']); });
$run('relsave_archived', function () use ($relSave, $c) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '#' . $c['oldboss']]); });
$run('relsave_ok', function () use ($relSave, $c, $listRef) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '#' . $c['sam']], 'ews_approval_relationship_save', $listRef); });
$run('relsave_none', function () use ($relSave, $c) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '']); });
$run('relsave_employee_0', function () use ($relSave, $c) { return $relSave(['employee_id' => 0, 'supervisor_employee_ref' => '#' . $c['sam']]); });
$faulty('fault_relsave_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_employee_relationships`?\s/i', function () use ($relSave, $c) { return $relSave(['employee_id' => $c['lina'], 'supervisor_employee_ref' => '#' . $c['mona']]); });

// ---------------------------------------------------------------- 9. departments
$dep = function ($f, $nonce = 'ews_department_save') use ($post) { return $post('department_save', $f, $nonce); };
$depDel = function ($id, $nonce = null) use ($post) { return $post('department_delete', ['department_id' => $id], $nonce ?? ('ews_department_delete_' . $id)); };
$as($emp1);
$run('dept_denied', function () use ($dep) { return $dep(['name' => 'X', 'code' => 'x']); });
$run('dept_delete_denied', function () use ($depDel, $empty) { return $depDel($empty); });
$as($admin);
$run('dept_bad_nonce', function () use ($dep) { return $dep(['name' => 'X', 'code' => 'x'], 'bad'); });
$run('dept_required', function () use ($dep) { return $dep(['name' => '', 'code' => '']); });
$run('dept_new', function () use ($dep, $c) { return $dep(['name' => 'Logistics', 'code' => 'LOG-1', 'description' => "Boxes\nand trucks", 'manager_employee_id' => $c['sam']]); });
$logi = (int) $wpdb->get_var("SELECT id FROM {$p}ews_departments WHERE code='log-1'");
$run('dept_duplicate', function () use ($dep) { return $dep(['name' => 'Logistics', 'code' => 'other']); });
$run('dept_duplicate_archived', function () use ($dep) { return $dep(['name' => 'Gone', 'code' => 'gone2']); });
$run('dept_manager_on_new', function () use ($dep, $c) { return $dep(['name' => 'Fresh', 'code' => 'fresh', 'manager_employee_id' => $c['mona']]); });
$run('dept_manager_outside', function () use ($dep, $logi, $c) { return $dep(['department_id' => $logi, 'name' => 'Logistics', 'code' => 'log-1', 'manager_employee_id' => $c['mona']]); });
$run('dept_edit_ok', function () use ($dep, $logi, $c) { return $dep(['department_id' => $logi, 'name' => 'Logistics 2', 'code' => 'log-2', 'description' => 'edited', 'manager_employee_id' => 0]); });
$faulty('fault_dept_update', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_departments`?\s/i', function () use ($dep, $logi) { return $dep(['department_id' => $logi, 'name' => 'Logistics 3', 'code' => 'log-3']); });
$faulty('fault_dept_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_departments`?\s/i', function () use ($dep) { return $dep(['name' => 'Broken', 'code' => 'broken']); });
$run('dept_archive_with_employees', function () use ($depDel, $c) { return $depDel($c['ops']); });
$run('dept_archive_with_teams', function () use ($depDel, $c) { return $depDel($c['sales']); });
$run('dept_archive_bad_nonce', function () use ($depDel, $empty) { return $depDel($empty, 'bad'); });
$faulty('fault_dept_archive', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_departments`?\s/i', function () use ($depDel, $empty) { return $depDel($empty); });
$run('dept_archive_empty', function () use ($depDel, $empty) { return $depDel($empty); });
$wpdb->query("RENAME TABLE {$p}ews_teams TO {$p}ews_teams_x");
$run('dept_archive_no_teams_table', function () use ($depDel, $logi) { return $depDel($logi); });
$wpdb->query("RENAME TABLE {$p}ews_teams_x TO {$p}ews_teams");

// ---------------------------------------------------------------- 10. teams
$team = function ($f, $nonce = 'ews_team_save') use ($post) { return $post('team_save', $f, $nonce); };
$teamDel = function ($id, $nonce = null) use ($post) { return $post('team_delete', ['team_id' => $id], $nonce ?? ('ews_team_delete_' . $id)); };
$as($emp1);
$run('team_denied', function () use ($team) { return $team(['name' => 'X']); });
$as($admin);
$run('team_required', function () use ($team) { return $team(['name' => '', 'department_id' => 0]); });
$run('team_department_inactive', function () use ($team, $gone, $c) { return $team(['name' => 'T1', 'department_id' => $gone, 'manager_employee_id' => $c['mona']]); });
$run('team_manager_outside', function () use ($team, $c) { return $team(['name' => 'T2', 'department_id' => $c['ops'], 'manager_employee_id' => $c['sam']]); });
$run('team_members_outside', function () use ($team, $c) { return $team(['name' => 'T3', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona'], 'member_ids' => [$c['omar'], $c['sam']]]); });
$run('team_duplicate', function () use ($team, $c) { return $team(['name' => 'Alpha', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona']]); });
$run('team_new', function () use ($team, $c) { return $team(['name' => 'Gamma', 'description' => 'new', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona'], 'member_ids' => [$c['omar'], $c['lina']]]); });
$gamma = (int) $wpdb->get_var("SELECT id FROM {$p}ews_teams WHERE name='Gamma'");
$run('team_edit', function () use ($team, $c, $gamma) { return $team(['team_id' => $gamma, 'name' => 'Gamma 2', 'description' => 'edited', 'department_id' => $c['ops'], 'manager_employee_id' => $c['omar'], 'member_ids' => [$c['lina'], $c['ali'], $c['mona']]]); });
$faulty('fault_team_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_teams`?\s/i', function () use ($team, $c) { return $team(['name' => 'Broken', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona']]); });
$faulty('fault_team_member_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_team_members`?\s/i', function () use ($team, $c) { return $team(['name' => 'Broken2', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona'], 'member_ids' => [$c['omar']]]); });
$faulty('fault_team_commit', '/^\s*COMMIT\s*$/i', function () use ($team, $c) { return $team(['name' => 'Broken3', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona']]); });
$faulty('fault_team_start', '/^\s*START\s+TRANSACTION\s*$/i', function () use ($team, $c) { return $team(['name' => 'Broken4', 'department_id' => $c['ops'], 'manager_employee_id' => $c['mona']]); });
$run('team_delete_bad_nonce', function () use ($teamDel, $gamma) { return $teamDel($gamma, 'bad'); });
$as($emp1);
$run('team_delete_denied', function () use ($teamDel, $gamma) { return $teamDel($gamma); });
$as($admin);
$faulty('fault_team_delete_members', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_team_members`?\s/i', function () use ($teamDel, $solo) { return $teamDel($solo); });
$faulty('fault_team_delete_team', '/^\s*UPDATE\s+`?' . preg_quote($p, '/') . 'ews_teams`?\s/i', function () use ($teamDel, $gamma) { return $teamDel($gamma); });
$run('team_delete_ok', function () use ($teamDel, $gamma) { return $teamDel($gamma); });

// ---------------------------------------------------------------- 11. the teams of one employee (called by the Employees page)
$run('sync_mismatch', function () use ($call, $c, $solo) { return $call('sync_employee_teams', $c['omar'], [$solo]); });
$alphaId = (int) $c['alpha'];
$run('sync_ok_merges_managed', function () use ($call, $c, $alphaId) { return $call('sync_employee_teams', $c['omar'], [$alphaId]); });
$run('sync_reactivate', function () use ($call, $c, $alphaId) { return $call('sync_employee_teams', $c['omar'], [$alphaId]); });
$run('sync_empty', function () use ($call, $c) { return $call('sync_employee_teams', $c['lina'], []); });
$faulty('fault_sync_insert', '/^\s*INSERT\s+INTO\s+`?' . preg_quote($p, '/') . 'ews_team_members`?\s/i', function () use ($call, $c, $alphaId) { return $call('sync_employee_teams', $c['ali'], [$alphaId]); });

// ---------------------------------------------------------------- the people tables, notifications and the Audit Log at the end
$as(0);
$rows = function ($q) use ($wpdb, $norm, $fix) { return $norm(json_decode($fix(wp_json_encode($wpdb->get_results($q, ARRAY_A))), true)); };
foreach ([
    'employees' => "SELECT * FROM {$p}ews_employees ORDER BY id",
    'relationships' => "SELECT * FROM {$p}ews_employee_relationships ORDER BY id",
    'departments' => "SELECT * FROM {$p}ews_departments ORDER BY id",
    'teams' => "SELECT * FROM {$p}ews_teams ORDER BY id",
    'team_members' => "SELECT * FROM {$p}ews_team_members ORDER BY id",
    'org_moves' => "SELECT * FROM {$p}ews_org_moves ORDER BY id",
    'approval_steps' => "SELECT id,approval_request_id,step_order,approver_employee_id,approver_wp_user_id,status,rerouted,for_employee_id,substitute_id FROM {$p}ews_approval_steps ORDER BY id",
    'notifications' => "SELECT user_id,type,entity,entity_id,title,message,link,is_read FROM {$p}ews_notifications ORDER BY id",
    'audit' => "SELECT user_id,action,entity,entity_id,details FROM {$p}ews_audit_log ORDER BY id",
] as $name => $q) $trace['_rows:' . $name] = $rows($q);
$trace['_rows:options'] = $norm(['checked' => get_transient('ews_org_moves_checked') ? 1 : 0, 'import' => get_transient($key) ? 1 : 0]);
echo wp_json_encode($trace);
