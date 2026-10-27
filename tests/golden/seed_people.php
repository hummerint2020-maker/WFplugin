<?php
/**
 * The golden harness's people data (tests/golden/run.py; docs/tasks/people-store.md §5): reporting lines (one to an archived
 * manager), department and team managers, an archived team, titles, avatars, a person who does not sign in, two active
 * employees linked to one user, and the org-move history, written with plain SQL (no People code) before the snapshot is saved.
 * Needs the ids of the attendance seed (GOLDEN_CTX) and tests/golden/seed_approvals.php already run.
 * Prints one JSON line with the ids the pages need.
 * Usage: GOLDEN_CTX='{json}' wp eval-file tests/golden/seed_people.php
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
$ops = (int) $wpdb->get_var("SELECT id FROM {$p}ews_departments WHERE code='ac-ops'");
$sales = (int) $wpdb->get_var("SELECT id FROM {$p}ews_departments WHERE code='ac-sales'");
$alpha = (int) $wpdb->get_var("SELECT id FROM {$p}ews_teams WHERE name='Alpha'");
$wpdb->insert($p . 'ews_employees', ['name' => 'Old Boss', 'domain_name' => 'acoldboss', 'email' => 'acoldboss@example.com', 'active' => 0, 'attendance_enabled' => 1, 'department_id' => $ops]);
$oldboss = (int) $wpdb->insert_id;
foreach ([['omar', 'mona'], ['lina', 'mona'], ['ali', 'omar']] as [$a, $b]) {
    $wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $c[$a], 'relationship_type' => 'supervisor', 'related_employee_id' => $c[$b], 'active' => 1]);
}
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $c['nour'], 'relationship_type' => 'supervisor', 'related_employee_id' => $oldboss, 'active' => 1]);
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $c['rana'], 'relationship_type' => 'supervisor', 'related_employee_id' => $c['omar'], 'active' => 0]);   // a past line
$wpdb->insert($p . 'ews_employee_relationships', ['employee_id' => $c['rana'], 'relationship_type' => 'supervisor', 'related_employee_id' => $c['mona'], 'active' => 1]);   // the newest line wins
$wpdb->update($p . 'ews_departments', ['manager_employee_id' => $c['mona']], ['id' => $ops]);
$wpdb->update($p . 'ews_departments', ['manager_employee_id' => $c['sam']], ['id' => $sales]);
$wpdb->update($p . 'ews_teams', ['manager_employee_id' => $c['omar'], 'department_id' => $ops], ['id' => $alpha]);
$wpdb->insert($p . 'ews_teams', ['name' => 'Beta', 'manager_employee_id' => $c['sam'], 'department_id' => $sales, 'active' => 1]);
$beta = (int) $wpdb->insert_id;
$wpdb->insert($p . 'ews_team_members', ['team_id' => $beta, 'employee_id' => $c['sam'], 'active' => 1]);
$wpdb->insert($p . 'ews_team_members', ['team_id' => $beta, 'employee_id' => $c['ziad'], 'active' => 0]);
$wpdb->insert($p . 'ews_teams', ['name' => 'Gone', 'manager_employee_id' => $c['mona'], 'department_id' => $ops, 'active' => 0]);
$wpdb->insert($p . 'ews_team_members', ['team_id' => (int) $wpdb->insert_id, 'employee_id' => $c['lina'], 'active' => 1]);
$wpdb->update($p . 'ews_employees', ['job_title' => 'Operations Lead'], ['id' => $c['mona']]);
$wpdb->update($p . 'ews_employees', ['job_title' => 'Dispatcher'], ['id' => $c['omar']]);
$wpdb->update($p . 'ews_employees', ['profile_image_type' => 'avatar', 'avatar_key' => 'cat', 'profile_image_url' => 'https://example.test/avatars/cat.svg'], ['id' => $c['lina']]);
$wpdb->update($p . 'ews_employees', ['profile_image_type' => 'photo', 'profile_image_url' => 'https://example.test/photos/omar.jpg'], ['id' => $c['omar']]);
$wpdb->update($p . 'ews_employees', ['attendance_enabled' => 0], ['id' => $c['nour']]);
$wpdb->update($p . 'ews_employees', ['attendance_enabled' => null], ['id' => $c['ali']]);
// two active employees linked to one user: the lowest id wins
$subuser = (int) $wpdb->get_var("SELECT wp_user_id FROM {$p}ews_employees WHERE domain_name='gdsub'");
$wpdb->insert($p . 'ews_employees', ['name' => 'Gd Sub Twin', 'domain_name' => 'gdsubtwin', 'email' => 'gdsubtwin@example.com', 'wp_user_id' => $subuser, 'active' => 1, 'attendance_enabled' => 1]);
$twin = (int) $wpdb->insert_id;
// the user acomar is Omar (a team manager with a report); emp1 (Mona) is a department manager with ews_manage_attendance
$acomar = (int) (username_exists('acomar') ?: wp_create_user('acomar', 'acomar-pass', 'acomar@example.com'));
$wpdb->update($p . 'ews_employees', ['wp_user_id' => $acomar], ['id' => $c['omar']]);
// the org-move history: done, scheduled in the future (the chart page runs the due ones on a GET), failed
$m = new EWS_Manager_V31_1();
$r = new ReflectionMethod($m, 'ensure_org_moves_schema');
$r->setAccessible(true);
$r->invoke($m);
$now = current_time('mysql');
$future = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' +30 days'));
$past = gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -10 days'));
$wpdb->query("DELETE FROM {$p}ews_org_moves");
$wpdb->insert($p . 'ews_org_moves', ['employee_id' => $c['omar'], 'from_manager_id' => 0, 'to_manager_id' => $c['mona'], 'effective_date' => $past, 'reason' => 'joined', 'status' => 'done', 'source' => 'chart', 'requests_moved' => 2, 'note' => '', 'created_by' => 1, 'created_at' => $now, 'applied_at' => $now]);
$wpdb->insert($p . 'ews_org_moves', ['employee_id' => $c['lina'], 'from_manager_id' => $c['mona'], 'to_manager_id' => $c['omar'], 'effective_date' => $future, 'reason' => 'later', 'status' => 'scheduled', 'source' => 'chart', 'requests_moved' => 0, 'note' => '', 'created_by' => 1, 'created_at' => $now]);
$wpdb->insert($p . 'ews_org_moves', ['employee_id' => $c['ali'], 'from_manager_id' => $c['omar'], 'to_manager_id' => $oldboss, 'effective_date' => $past, 'reason' => 'bad', 'status' => 'failed', 'source' => 'import', 'requests_moved' => 0, 'note' => 'The new manager is not active', 'created_by' => 1, 'created_at' => $now]);
echo wp_json_encode(['ops' => $ops, 'sales' => $sales, 'alpha' => $alpha, 'beta' => $beta, 'oldboss' => $oldboss, 'twin' => $twin, 'acomar' => $acomar, 'subuser' => $subuser]);
