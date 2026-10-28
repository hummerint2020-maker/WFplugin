<?php
/**
 * The People Stores on the real database (3.31.133; docs/tasks/people-store.md §6): real tables with their own prefix (MySQL's
 * SHOW TABLES does not list TEMPORARY tables, and the "table is missing" branches need to see the difference), dropped at the end,
 * so nothing of the site is touched. Run with wp eval-file (CI's MySQL; SQLite works too).
 */
use WorkforceOne\People\Data\WordPressDepartmentStore;
use WorkforceOne\People\Data\WordPressOrgMoveStore;
use WorkforceOne\People\Data\WordPressPeopleStore;
use WorkforceOne\People\Data\WordPressReportingStore;
use WorkforceOne\People\Data\WordPressTeamStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = 'ppl_contract_';
$tables = ['ews_employees', 'ews_employee_relationships', 'ews_departments', 'ews_teams', 'ews_team_members', 'ews_org_moves'];
foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $tables) { foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}"); });
$wpdb->query("CREATE TABLE {$prefix}ews_employees (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, domain_name VARCHAR(190) NOT NULL, email VARCHAR(190) NULL, wp_user_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, department_id BIGINT UNSIGNED NULL, default_shift_id BIGINT UNSIGNED NULL, attendance_enabled TINYINT NULL DEFAULT 1, job_title VARCHAR(120) NULL, profile_image_type VARCHAR(20) NULL, profile_image_url TEXT NULL, avatar_key VARCHAR(80) NULL, updated_at DATETIME NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_employee_relationships (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, relationship_type VARCHAR(40) NOT NULL, related_employee_id BIGINT UNSIGNED NULL, related_wp_user_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_departments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, code VARCHAR(80) NOT NULL DEFAULT '', description TEXT NULL, manager_employee_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (id), UNIQUE KEY department_name (name))");
$wpdb->query("CREATE TABLE {$prefix}ews_teams (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, description TEXT NULL, manager_employee_id BIGINT UNSIGNED NULL, department_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (id), UNIQUE KEY team_name (name))");
$wpdb->query("CREATE TABLE {$prefix}ews_team_members (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, team_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL, active TINYINT NOT NULL DEFAULT 1, joined_at DATETIME NULL, updated_at DATETIME NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_org_moves (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, from_manager_id BIGINT UNSIGNED NOT NULL DEFAULT 0, to_manager_id BIGINT UNSIGNED NOT NULL DEFAULT 0, effective_date DATE NOT NULL, reason VARCHAR(255) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'done', source VARCHAR(20) NOT NULL DEFAULT 'chart', requests_moved INT UNSIGNED NOT NULL DEFAULT 0, note VARCHAR(255) NOT NULL DEFAULT '', created_by BIGINT UNSIGNED NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, applied_at DATETIME NULL, PRIMARY KEY (id))");
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

// ---- 3.31.134: the writes
$dbx = clone $wpdb;
$dbx->prefix = 'ppl_missing_';
$now = '2026-10-28 09:00:00';
$later = '2026-10-28 09:30:00';
$ids = [$mona, $omar, $old, $twin];
// reporting lines
$db->query("DELETE FROM {$rt}");
$line($omar, $mona);
$check($R->deactivate($omar, 'supervisor', $now) === 1 && $R->deactivate($omar, 'supervisor', $now) === 0, 'deactivate: the changed count, then 0 when nothing is left');
$line($omar, $mona);
$line($omar, $twin, 'dotted');
$check($R->deactivateSupervisor($omar, $now) === 1 && $R->deactivateSupervisor($omar, $now) === 0 && $R->relatedActive($omar, 'dotted')->name === 'Twin', 'deactivateSupervisor: only the supervisor line');
$check($R->insertLine($omar, 'supervisor', $mona, 0, $now) === 1 && $R->insertId() > 0 && $R->currentManagerId($omar) === $mona, 'insertLine: a related employee');
$check($R->insertLine($omar, 'dotted', 0, 55, $now) === 1 && $db->get_var("SELECT related_wp_user_id FROM {$rt} WHERE id=" . $R->insertId()) === '55' && $db->get_var("SELECT related_employee_id FROM {$rt} WHERE id=" . $R->insertId()) === null, 'insertLine: a WordPress user leaves the employee NULL');
$quiet = $db->suppress_errors(true);
$bad = new WordPressReportingStore($db, $emp);
$rtName = new ReflectionProperty($bad, 'rt');
$rtName->setAccessible(true);
$rtName->setValue($bad, $prefix . 'ews_no_such_lines');
$check($bad->deactivate($omar, 'supervisor', $now) === false && $bad->deactivateSupervisor($omar, $now) === false && $bad->insertLine($omar, 'supervisor', $mona, 0, $now) === false && $bad->lastError() !== '', 'the reporting writes: false on a database error, and the error text');
$db->suppress_errors($quiet);

// departments
$DS = new WordPressDepartmentStore($db, $emp);
$check($DS->insert('Logistics', 'log', "Boxes\nand trucks", 0, $now) === 1 && ($logi = $DS->insertId()) > 0 && $DS->byId($logi)->manager_employee_id === null && $DS->byId(9999) === null, 'department insert: no manager is NULL; byId; null for none');
$quiet = $db->suppress_errors(true);
$check($DS->insert('Logistics', 'log2', '', 0, $now) === false, 'department insert: a duplicate name is false (unique key)');
$db->suppress_errors($quiet);
$check($DS->update($logi, 'Logistics 2', 'log', 'd', $mona, $later) === 1 && $DS->update(999999, 'x', 'x', '', 0, $later) === 0 && $DS->byId($logi)->manager_employee_id === (string) $mona, 'department update: the changed count, 0 for no such department, the manager');
$check($DS->duplicateFlags('Logistics 2', 'zzz', 0) === ['1'] && $DS->duplicateFlags('Logistics 2', 'zzz', $logi) === [] && $DS->duplicateFlags('nope', 'log', 0) === ['1'], 'department duplicateFlags: by name or code, not the department itself');
$db->update($emp, ['department_id' => $logi], ['id' => $omar]);
$check($DS->activeEmployeeCount($logi) === 1 && $DS->activeEmployeeCount(99999) === 0 && $DS->activeId($logi) === (string) $logi, 'department: the active employee count; activeId');
$list = $DS->adminList();
$check(count($list) >= 1 && $list[0]->employee_count !== null && isset($list[0]->manager_name), 'department adminList: the manager name and the employee count');
$check($DS->archive($logi, $now) === 1 && $DS->archive(999999, $now) === 0 && $DS->activeId($logi) === null && $DS->adminList() === [], 'department archive: 1, 0 for no such department; gone from the list');

// teams
$TS = new WordPressTeamStore($db, $emp);
$check($TS->tableExists() === true && (new WordPressTeamStore($dbx, $emp))->tableExists() === false, 'team tableExists follows the prefix');
$check($TS->insert('Gamma', 'new', $mona, $ops, $now) === 1 && ($gamma = $TS->insertId()) > 0 && $TS->activeById($gamma)->name === 'Gamma' && $TS->activeById(9999) === null, 'team insert, insertId, activeById');
$quiet = $db->suppress_errors(true);
$check($TS->insert('Gamma', '', $mona, $ops, $now) === false && $TS->lastError() !== '', 'team insert: a duplicate name is false and lastError says why');
$db->suppress_errors($quiet);
$check($TS->update($gamma, 'Gamma 2', 'e', $omar, $ops, $later) === 1 && $TS->update(999999, 'x', '', 0, 0, $later) === 0 && $TS->duplicateFlags('Gamma 2', 0) === ['1'] && $TS->duplicateFlags('Gamma 2', $gamma) === [], 'team update and duplicateFlags');
$check($TS->memberId($gamma, $mona) === null && $TS->insertMember($gamma, $mona, $now) === 1 && $TS->memberId($gamma, $mona) !== null && $TS->insertMember($gamma, $omar, $now) === 1, 'team members: memberId, insertMember');
$memberRow = (int) $TS->memberId($gamma, $mona);
$check($TS->deactivateMembersOfTeam($gamma, $now) === 2 && $TS->deactivateMembersOfTeam($gamma, $now) === 0 && $TS->reactivateMember($memberRow, $later) === 1 && $TS->activeMemberIds($gamma) === [(string) $mona], 'team members: deactivate (counted), reactivate');
$check($TS->activeCountInDepartment($ops) >= 3 && $TS->activeDepartmentOf($gamma) === (string) $ops && $TS->activeDepartmentOf(9999) === null && $TS->activeId($gamma) === (string) $gamma && $TS->managedIn($omar, $ops) === [(string) $alpha, (string) $gamma], 'team reads: counts, department, managedIn, activeId');
$check($TS->deactivateMembershipsOf($mona, $now) >= 1 && $TS->activeTeamIdsOf($mona) === []
    && count($TS->adminList()) >= 3 && count($TS->pageEmployees()) >= 3 && is_array($TS->pageDepartments()), 'team: all memberships of one employee ended; the page lists');
$check($TS->archive($gamma, $now) === 1 && $TS->deactivateAllMembers($gamma, $now) >= 0 && $TS->activeById($gamma) === null, 'team archive; all members ended');

// the org moves
$M = new WordPressOrgMoveStore($db);
$mv = $prefix . 'ews_org_moves';
$check($M->tableExists() === true && (new WordPressOrgMoveStore($dbx))->tableExists() === false, 'org moves tableExists follows the prefix');
$row = static function ($employee, $to, $status, $date = '2026-10-28') use ($now) { return ['employee_id' => $employee, 'from_manager_id' => 0, 'to_manager_id' => $to, 'effective_date' => $date, 'reason' => 'r', 'status' => $status, 'source' => 'chart', 'created_by' => 1, 'created_at' => $now]; };
$check($M->insert($row($omar, $mona, 'scheduled', '2026-10-27')) === 1 && ($m1 = $M->insertId()) > 0 && $M->openIdFor($omar, 0) === (string) $m1 && $M->openIdFor($omar, $m1) === null && $M->openIdFor($mona, 0) === null, 'openIdFor: a scheduled move of the employee, not the ignored one');
$check(count($M->dueRows('2026-10-28')) === 1 && $M->dueRows('2026-10-26') === [], 'dueRows: the scheduled ones whose day has come');
$check($M->claim($m1, $now) === 1 && $M->claim($m1, $now) === 0 && $M->applyingRows()[0]->applied_at === $now && $M->openIdFor($omar, 0) === (string) $m1, 'claim: 1 for the run that took it, 0 for the next');
$check($M->release($m1, $later) === 0 && $M->release($m1, $now) === 1 && $M->byId($m1)->status === 'scheduled' && $M->byId($m1)->applied_at === null, 'release: only with the claim time; back to scheduled');
$M->claim($m1, $now);
$check($M->markAlreadyDone($m1, $later, $later) === 0 && $M->markAlreadyDone($m1, $now, $later) === 1 && $M->byId($m1)->note === 'already in place', 'markAlreadyDone: only with the claim time');
$M->insert($row($omar, $old, 'scheduled', '2026-10-27'));
$m2 = $M->insertId();
$M->claim($m2, $now);
$check($M->fail($m2, $later, 'loop', $later) === 0 && $M->fail($m2, $now, 'loop', $later) === 1 && $M->byId($m2)->note === 'loop', 'fail: with the claim time');
$M->insert($row($omar, $old, 'scheduled', '2026-10-27'));
$m3 = $M->insertId();
$M->claim($m3, $now);
$check($M->fail($m3, null, 'manager', $later) === 1 && $M->fail($m3, null, 'manager', $later) === 0, 'fail: without a claim time, whatever it was');
$M->insert($row($omar, $mona, 'scheduled', '2026-10-27'));
$m4 = $M->insertId();
$M->claim($m4, $now);
$check($M->finishClaimed($m4, $later, ['status' => 'done', 'requests_moved' => 3, 'applied_at' => $later]) === 0 && $M->finishClaimed($m4, $now, ['status' => 'done', 'requests_moved' => 3, 'applied_at' => $later]) === 1 && $M->byId($m4)->requests_moved === '3', 'finishClaimed: 1 only while the claim holds (0 otherwise)');
$M->insert($row($omar, $mona, 'applying', '2026-10-27'));
$m5 = $M->insertId();
$db->query("UPDATE {$mv} SET applied_at=NULL WHERE id={$m5}");
$check($M->resume($m5, $now) === 0 && $M->resume($m5, null) === 1 && $M->byId($m5)->status === 'scheduled' && $M->byId($m5)->note === 'resumed' && $M->byId($m5)->applied_at === null, 'resume: IS NULL for a move claimed without a time; the note');
$M->claim($m5, $now);
$check($M->failInterrupted($m5, $later, $later) === 0 && $M->failInterrupted($m5, $now, $later) === 1 && $M->byId($m5)->note === 'interrupted', 'failInterrupted: with the claim time');
$M->insert($row($omar, $mona, 'applying', '2026-10-27'));
$m6 = $M->insertId();
$db->query("UPDATE {$mv} SET applied_at=NULL WHERE id={$m6}");
$check($M->failInterrupted($m6, null, $later) === 1, 'failInterrupted: a move claimed without a time');
$M->insert($row($mona, $omar, 'scheduled', '2026-11-30'));
$m7 = $M->insertId();
$check($M->cancel($m7, 'failed', $now) === 0 && $M->cancel($m7, 'scheduled', $now) === 1 && $M->byId($m7)->status === 'cancelled' && $M->byId(999999) === null, 'cancel: only from the status given');
for ($i = 0; $i < 3; $i++) $M->insert($row($twin, $mona, 'done'));
$M->insert($row($twin, $mona, 'scheduled', '2026-12-01'));
$check(count($M->doneRows()) >= 4 && (int) $M->doneRows()[0]->id > (int) $M->doneRows()[1]->id && count($M->openRows()) >= 1 && $M->openRows()[0]->effective_date <= $M->openRows()[count($M->openRows()) - 1]->effective_date && count($M->failedRows()) >= 3, 'the lists: done newest first, open by day, failed newest first');
$db->query("DROP TABLE {$mv}");
$check($M->tableExists() === false && $M->dueRows('2026-10-28') === [] , 'a dropped org moves table is missing (a real table), and reads of it are empty');

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
