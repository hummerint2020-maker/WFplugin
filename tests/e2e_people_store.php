<?php
/**
 * The People Stores on the real database (3.31.133; docs/tasks/people-store.md §6): real tables with their own prefix (MySQL's
 * SHOW TABLES does not list TEMPORARY tables, and the "table is missing" branches need to see the difference), dropped at the end,
 * so nothing of the site is touched. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\People\Data\WordPressDepartmentStore;
use WorkforceOne\People\Data\WordPressPeopleStore;
use WorkforceOne\People\Data\WordPressReportingStore;
use WorkforceOne\People\Data\WordPressTeamStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = 'ppl_contract_';
$tables = ['ews_employees', 'ews_employee_relationships', 'ews_departments', 'ews_teams', 'ews_team_members'];
foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $tables) { foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}"); });
$wpdb->query("CREATE TABLE {$prefix}ews_employees (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, domain_name VARCHAR(190) NOT NULL, email VARCHAR(190) NULL, wp_user_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, department_id BIGINT UNSIGNED NULL, default_shift_id BIGINT UNSIGNED NULL, attendance_enabled TINYINT NULL DEFAULT 1, job_title VARCHAR(120) NULL, profile_image_type VARCHAR(20) NULL, profile_image_url TEXT NULL, avatar_key VARCHAR(80) NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_employee_relationships (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, relationship_type VARCHAR(40) NOT NULL, related_employee_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_departments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, manager_employee_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_teams (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, manager_employee_id BIGINT UNSIGNED NULL, department_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_team_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, team_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, PRIMARY KEY (id))");
$db = clone $wpdb;
$db->prefix = $prefix;
$emp = $prefix . 'ews_employees';
$check($db->get_var("SELECT COUNT(*) FROM {$emp}") === '0', 'contract tables ready');

$add = static function ($name, $uid, $active, $dept = null, $shift = null, $att = 1, $title = null) use ($db, $emp) {
    $db->insert($emp, ['name' => $name, 'domain_name' => strtolower($name), 'wp_user_id' => $uid, 'active' => $active, 'department_id' => $dept, 'default_shift_id' => $shift, 'attendance_enabled' => $att, 'job_title' => $title]);
    return (int) $db->insert_id;
};
$mona = $add('Mona', 101, 1, 5, null, 1, 'Lead');
$omar = $add('Omar', 102, 1, 5, 201);
$old = $add('Old', 103, 0, 5);
$twin = $add('Twin', 101, 1, null, null, null);   // the same user as Mona, attendance NULL
$S = new WordPressPeopleStore($db, $emp);

// ---- single reads: the same rows and the same raw types as the old inline SQL
$check($S->byId($omar, false) == $db->get_row("SELECT * FROM {$emp} WHERE id={$omar} LIMIT 1") && $S->byId($old, true) === null && $S->byId($old, false)->name === 'Old' && $S->byId(999, false) === null, 'byId: the whole row, the active flag, null for none');
$check($S->activeByUser(101)->name === 'Mona' && $S->activeByUser(103) === null, 'activeByUser: the lowest id of two employees on one user; archived ones never');
$check($S->activeId($mona) === (string) $mona && $S->activeId($old) === null && $S->name($old) === 'Old' && $S->userId($mona, false) === '101' && $S->userId($old, true) === null && $S->userId($old, false) === '103', 'activeId, name, userId: strings as the database gives them, null for none');
$check($S->activeBrief($mona)->wp_user_id === '101' && !isset($S->activeBrief($mona)->id) && $S->activeBrief($old) === null, 'activeBrief: name and user only');
$check($S->shiftIdOf($omar) === '201' && $S->shiftIdOf($mona) === null && $S->shiftIdNow($omar) === '201' && $S->shiftIdNow(999) === null, 'shift id: a string, null when none or unknown');
$check($S->attendanceEnabledValue($mona) === '1' && $S->attendanceEnabledValue($twin) === null && $S->attendanceEnabledValue(999) === null, 'attendance_enabled: the stored value, NULL as null');
$check($S->activeInDepartment($mona, 5) === (string) $mona && $S->activeInDepartment($old, 5) === null && $S->activeInDepartment($mona, 6) === null, 'activeInDepartment');

// ---- lists
$check($S->activeIds(false) === [(string) $mona, (string) $omar, (string) $twin] && $S->activeIds(true) === [(string) $mona, (string) $omar, (string) $twin], 'activeIds: all, and with attendance NULL counted as tracked');
$db->update($emp, ['attendance_enabled' => 0], ['id' => $omar]);
$check($S->activeIds(true) === [(string) $mona, (string) $twin] && count($S->activeIds(false)) === 3, 'activeIds(attendance): without the ones not tracked');
$check($S->activeIdsInDepartment(5) === [(string) $mona, (string) $omar] && $S->activeUserIds(5) === ['101', '102'] && count($S->activeUserIds(0)) === 3, 'department members and users');
$check($S->activeCount(5, false) === 2 && $S->activeCount(0, false) === 3 && $S->activeCount(0, true) === 3 && $S->activeCount(99, true) === 0, 'activeCount');
$check(array_map(function ($r) { return $r->name; }, $S->activeList(0)) === ['Mona', 'Omar', 'Twin'] && count($S->activeList(2)) === 2 && count($S->activeUsers()) === 3, 'activeList by name, with and without a limit; activeUsers');
$all = $S->activeAll();
$check(is_array($all) && count($all) === 3 && $all[0]->name === 'Mona' && property_exists($all[0], 'default_shift_id'), 'activeAll: the whole active rows by name');
$check(array_map('intval', array_column($S->allShiftIds(), 'id')) == [$mona, $omar, $old, $twin], 'allShiftIds: every employee, archived too');

// ---- many at once, with the chunks of 500 (a boundary at 500 and 501 ids)
$ids500 = array_merge([$mona, $omar, $old, 999], range(1000, 1495));   // 500
$ids501 = array_merge($ids500, [$twin]);                               // 501: the second chunk holds one id
$check(count($ids500) === 500 && count($ids501) === 501, 'the two boundary lists');
$byIds = static function ($rows) { $o = []; foreach ($rows as $r) $o[(int) $r->id] = $r->name; return $o; };
$check($byIds($S->rowsByIds($ids500, false)) === [$mona => 'Mona', $omar => 'Omar', $old => 'Old'] && $byIds($S->rowsByIds($ids501, false)) === [$mona => 'Mona', $omar => 'Omar', $old => 'Old', $twin => 'Twin'], 'rowsByIds: 500 ids in one query, 501 in two, none lost');
$check($byIds($S->rowsByIds($ids501, true)) === [$mona => 'Mona', $omar => 'Omar', $twin => 'Twin'] && $S->rowsByIds([], false) === [], 'rowsByIds: active only; none for no ids');
$check(count($S->briefsByIds($ids501)) === 4 && $S->briefsByIds([$mona])[0]->wp_user_id === '101' && !isset($S->briefsByIds([$mona])[0]->active), 'briefsByIds: id, name and user, across the chunks');
$check(count($S->shiftIdsOf($ids501)) === 4 && $S->shiftIdsOf([$omar])[0]->default_shift_id === '201', 'shiftIdsOf: across the chunks');
$check(array_map(function ($r) { return $r->name; }, $S->activeNamesByUsers([101, 102, 103])) === ['Mona', 'Omar', 'Twin'], 'activeNamesByUsers: the active ones, by id');

$late = $add('Late', 9999, 1);
$users501 = array_merge([101, 102, 103], range(5000, 5496), [9999]);   // 501 distinct ids: the last one is alone in the second chunk
$check(count($users501) === 501 && count(array_unique($users501)) === 501, 'the user id list is across two chunks');
$check(array_map(function ($r) { return $r->name; }, $S->activeNamesByUsers($users501)) === ['Mona', 'Omar', 'Twin', 'Late'], 'activeNamesByUsers: across the chunks, the active ones in id order');

// ---- the shift write
$check($S->setShiftId($omar, 300) === true && $S->shiftIdOf($omar) === '300' && $S->setShiftId($omar, 300) === true && $S->setShiftId($omar, 0) === true && $S->shiftIdOf($omar) === null && $S->setShiftId($omar, 0) === true, 'setShiftId: set, the same again, cleared, cleared again (all true: no database error)');

$quiet = $db->suppress_errors(true);
$broken = new WordPressPeopleStore($db, $prefix . 'ews_no_such_employees');
$check($broken->setShiftId($omar, 5) === false && $broken->setShiftId($omar, 0) === false, 'setShiftId: false on a database error, for both statements');
$check($broken->activeAll() === [], 'activeAll: wpdb gives an empty list when the query fails (as the old emps() got), which the memo then remembers');
$db->suppress_errors($quiet);

// ---- the org chart rows
$byName = static function ($rows) { $o = []; foreach ($rows as $r) $o[$r->name] = $r; return $o; };
$dep = $prefix . 'ews_departments';
$db->insert($dep, ['name' => 'Ops', 'manager_employee_id' => $mona, 'active' => 1]);
$ops = (int) $db->insert_id;
$db->update($emp, ['department_id' => $ops], ['id' => $mona]);
$rows = $S->orgRows(true);
$check(count($rows) === 4 && $byName($rows)['Mona']->department_name === 'Ops' && $byName($rows)['Omar']->department_name === null, 'orgRows with the departments table: the department name, null for none');
$rows = $S->orgRows(false);
$check(count($rows) === 4 && !property_exists($rows[0], 'department_name') && $byName($rows)['Mona']->job_title === 'Lead', 'orgRows without it: no department_name column');

// ---- reporting lines
$rt = $prefix . 'ews_employee_relationships';
$R = new WordPressReportingStore($db, $emp);
$line = static function ($e, $r, $type = 'supervisor', $active = 1) use ($db, $rt) { $db->insert($rt, ['employee_id' => $e, 'relationship_type' => $type, 'related_employee_id' => $r, 'active' => $active]); };
$line($omar, $old);        // 1: an archived manager
$line($omar, $mona);       // 2: the newest wins
$line($twin, $mona, 'supervisor', 0);
$line($twin, $omar, 'dotted');
$check($R->tableExists() === true, 'tableExists: the relationships table is there');
$check($R->relatedActive($omar, 'supervisor')->name === 'Mona' && $R->relatedActive($twin, 'supervisor') === null && $R->relatedActive($twin, 'dotted')->name === 'Omar', 'relatedActive: the newest line to an active employee, by type');
$line($omar, $old);        // 5: now the newest line is the archived manager
$check($R->relatedActive($omar, 'supervisor')->name === 'Mona' && $R->currentManagerId($omar) === $old && $R->currentManagerId($mona) === 0, 'relatedActive skips an archived manager; currentManagerId does not');
$rowsOf = $R->supervisorRowsOf($ids501);
$check(count($rowsOf) === 1 && $rowsOf[0]->employee_id === (string) $omar && $rowsOf[0]->related_employee_id === (string) $mona, 'supervisorRowsOf: active supervisors of active employees only, across the chunks');
$check(count($R->activeSupervisorRows()) === 3 && $R->activeSupervisorRows()[0]->related_employee_id === (string) $old, 'activeSupervisorRows: newest first, archived managers included');
$check($R->hasActiveReport($mona) === true && $R->hasActiveReport($old) === true && $R->hasActiveReport($omar) === false && $R->hasActiveReport($twin) === false, 'hasActiveReport: an active employee reports to them by an active supervisor line (even to an archived manager); a dotted line does not count');

// ---- departments and teams
$D = new WordPressDepartmentStore($db, $emp);
$check($D->tableExists() === true && $D->activeManagerOf($mona)->name === 'Mona' && $D->activeManagerOf($omar) === null, 'department: the active manager of an active department');
$db->update($dep, ['active' => 0], ['id' => $ops]);
$check($D->activeManagerOf($mona) === null, 'an archived department has no manager');
$tt = $prefix . 'ews_teams';
$tm = $prefix . 'ews_team_members';
$db->insert($tt, ['name' => 'Alpha', 'manager_employee_id' => $omar, 'department_id' => $ops, 'active' => 1]);
$alpha = (int) $db->insert_id;
$db->insert($tt, ['name' => 'Beta', 'manager_employee_id' => $old, 'department_id' => $ops, 'active' => 1]);
$beta = (int) $db->insert_id;
foreach ([[$alpha, $mona, 1], [$beta, $mona, 1], [$alpha, $twin, 1], [$beta, $twin, 0]] as [$tid, $eid, $a]) $db->insert($tm, ['team_id' => $tid, 'employee_id' => $eid, 'active' => $a]);
$T = new WordPressTeamStore($db, $emp);
$check($T->activeManagerFor($mona)->name === 'Omar' && $T->activeManagerFor($mona)->team_id === (string) $alpha && $T->activeManagerFor($mona)->team_name === 'Alpha' && $T->activeManagerFor($omar) === null, 'activeManagerFor: the first active team with an active manager, with its id and name');
$check($T->activeMemberIds($alpha) === [(string) $mona, (string) $twin] && $T->activeTeamIdsOf($mona) === [(string) $alpha, (string) $beta] && $T->activeTeamIdsOf($twin) === [(string) $alpha], 'team members and teams of an employee');
$tr = $T->activeTeamRowsByEmployees($ids501);
$check(count($tr) === 3 && $tr[0]->team_id === (string) $alpha, 'activeTeamRowsByEmployees: by team, across the chunks');

// ---- the table names: employees from the argument, the others from the prefix
$db2 = clone $wpdb;
$db2->prefix = 'ppl_missing_';
$check((new WordPressPeopleStore($db2, $emp))->byId($mona, false)->name === 'Mona', 'the employees table is the one given, whatever the prefix');
$quiet = $db2->suppress_errors(true);
$check((new WordPressReportingStore($db2, $emp))->tableExists() === false && (new WordPressDepartmentStore($db2, $emp))->tableExists() === false, 'the relationships and departments tables follow the prefix: missing there');
$db2->suppress_errors($quiet);
$wpdb->query("DROP TABLE {$rt}");
$check($R->tableExists() === false, 'tableExists is false once the table is gone (a real table, not a temporary one)');

// ---- one container per site, with the employees table of the plugin
$o = new EWS_Manager_V31_1();
$svc = new ReflectionMethod($o, 'services');
$svc->setAccessible(true);
$a = $svc->invoke($o);
$keep = $wpdb->prefix;
$wpdb->prefix = $prefix;   // a container built under another prefix still reads the plugin's own employees table
$b = $svc->invoke($o);
$wpdb->prefix = $keep;
$first = (int) $wpdb->get_var("SELECT MIN(id) FROM {$wpdb->prefix}ews_employees");
$check($b !== $a && $b->people()->employee($first) == $a->people()->employee($first) && ($first === 0 || $b->people()->employee($first) !== null), 'a container for another prefix reads the same employees table (the plugin property)');
$check($a->people() === $a->people() && $a->org() === $a->org() && $a->people() instanceof \WorkforceOne\Platform\People, 'the container builds one PeopleDirectory (also the Platform\People port) and one OrgDirectory');
echo "ALL PASS\n";
