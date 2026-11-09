<?php
/**
 * The HR letters store and read model on the real database (HL3, docs/tasks/hr-letter-hl3.md §6): real tables with their own prefix, made
 * from LetterSchema (the same SQL as the upgrade), dropped at the end, so nothing of the site is touched. Run with wp eval-file (CI's MySQL).
 */
use WorkforceOne\HrLetters\Data\LetterSchema;
use WorkforceOne\HrLetters\Data\WordPressLetterQuery;
use WorkforceOne\HrLetters\Data\WordPressLetterStore;

global $wpdb;
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$prefix = 'hl_contract_';
$tables = ['ews_hr_letters', 'ews_employees', 'ews_departments'];
foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}");
register_shutdown_function(function () use ($wpdb, $prefix, $tables) { foreach ($tables as $t) $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$t}"); });
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
dbDelta(LetterSchema::sql($prefix . 'ews_hr_letters', $wpdb->get_charset_collate()));
$wpdb->query("CREATE TABLE {$prefix}ews_employees (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, domain_name VARCHAR(190) NOT NULL DEFAULT '', wp_user_id BIGINT UNSIGNED NULL, active TINYINT NOT NULL DEFAULT 1, department_id BIGINT UNSIGNED NULL, job_title VARCHAR(120) NULL, name_ar VARCHAR(190) NULL, gender VARCHAR(10) NULL, hire_date DATE NULL, created_at DATETIME NULL, PRIMARY KEY (id))");
$wpdb->query("CREATE TABLE {$prefix}ews_departments (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(190) NOT NULL, PRIMARY KEY (id))");
$t = $prefix . 'ews_hr_letters';
$check($wpdb->get_var("SHOW TABLES LIKE '{$t}'") === $t, 'the table is made from LetterSchema');
$keys = array_map(static function ($r) { return $r->Key_name . ':' . $r->Column_name; }, (array) $wpdb->get_results("SHOW INDEX FROM {$t}"));
$check(in_array('open_key:open_key', $keys, true) && in_array('employee:employee_id', $keys, true) && in_array('status:status', $keys, true), 'the indexes');
$check((int) $wpdb->get_var("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND INDEX_NAME='open_key' LIMIT 1") === 0, 'open_key is UNIQUE');

$wpdb->insert($prefix . 'ews_departments', ['name' => 'Accounts']);
$dept = (int) $wpdb->insert_id;
$wpdb->insert($prefix . 'ews_employees', ['name' => 'Ahmed Samir', 'domain_name' => 'asamir', 'wp_user_id' => 11, 'department_id' => $dept, 'job_title' => 'Senior Accountant', 'name_ar' => 'أحمد سمير', 'gender' => 'male', 'hire_date' => '2021-03-01', 'created_at' => '2023-01-12 08:00:00']);
$ahmed = (int) $wpdb->insert_id;
$wpdb->insert($prefix . 'ews_employees', ['name' => 'Sara 100%_old', 'domain_name' => 'sold', 'active' => 0]);
$sara = (int) $wpdb->insert_id;

$db = clone $wpdb;
$db->prefix = $prefix;
$S = new WordPressLetterStore($db);
$Q = new WordPressLetterQuery($db, $prefix . 'ews_employees');
$row = static function ($emp, $type, $lang, $open, $delivery = 'pdf') {
    return ['employee_id' => $emp, 'requested_by' => 11, 'source' => 'employee', 'type_key' => $type, 'type_label' => $type, 'other_text' => '', 'addressee' => 'Bank',
        'language' => $lang, 'note' => '', 'delivery' => $delivery, 'with_salary' => 0, 'status' => 'pending', 'open_key' => $open, 'requested_at' => '2026-11-09 10:00:00'];
};
$a = $S->insert($row($ahmed, 'salary', 'ar', "{$ahmed}:salary:ar"));
$check($a > 0 && !$S->lastWasDuplicate(), 'insert: a new id');
$check($S->insert($row($ahmed, 'salary', 'ar', "{$ahmed}:salary:ar")) === 0 && $S->lastWasDuplicate(), 'insert: the same open request is a duplicate (the UNIQUE key)');
$o1 = $S->insert($row($ahmed, 'other', 'en', null, 'upload'));
$o2 = $S->insert($row($ahmed, 'other', 'en', null, 'upload'));
$check($o1 > 0 && $o2 > 0, 'insert: many requests without an open key ("Other")');
$check($S->openExists($ahmed, 'salary', 'ar') && !$S->openExists($ahmed, 'salary', 'en') && !$S->openExists($sara, 'salary', 'ar'), 'openExists');
$check($S->find($a)->status === 'pending' && $S->find(999999) === null, 'find');
$check(array_map('intval', array_column($S->forEmployee($ahmed, 10), 'id')) === [$o2, $o1, $a], 'forEmployee: newest first');

// ---- the conditional updates
$check($S->markDecided($a, 'approved', '', '2026-11-09 11:00:00') === 1 && $S->markDecided($a, 'approved', '', '2026-11-09 11:00:00') === 0, 'markDecided: once, then 0');
$check($S->find($a)->open_key === "{$ahmed}:salary:ar" && $S->openExists($ahmed, 'salary', 'ar'), '...an approved letter stays open (decision 10)');
$file = ['key' => str_repeat('a', 32) . '.pdf', 'name' => 'HR-2026-000001.pdf', 'source' => 'generated', 'size' => 1234];
$check($S->setIssueError($a, 'no_salary') === 1 && $S->find($a)->issue_error === 'no_salary', 'setIssueError while approved');
$check($S->claimIssued($a, $file, 0, '2026-11-09 11:01:00') === 1, 'claimIssued: the first claim wins');
$check($S->claimIssued($a, ['key' => str_repeat('b', 32) . '.pdf'] + $file, 5, '2026-11-09 11:02:00') === 0 && $S->find($a)->file_key === $file['key'], '...a second claim changes nothing');
$r = $S->find($a);
$check($r->status === 'issued' && $r->open_key === null && $r->issue_error === '' && $r->file_size === '1234', '...issued: closed, the error cleared');
$a2 = $S->insert($row($ahmed, 'salary', 'ar', "{$ahmed}:salary:ar"));
$check($a2 > 0, 'the same request can be made again once the first is issued');
$check($S->markDownloaded($a, '2026-11-09 12:00:00') === 1 && $S->markDownloaded($a, '2026-11-09 12:05:00') === 0, 'markDownloaded: the first time only');
$new = ['key' => str_repeat('c', 32) . '.pdf'] + $file;
$check($S->replaceFile($a, str_repeat('z', 32) . '.pdf', $new, 5, '2026-11-09 13:00:00') === 0, 'replaceFile: not when the file changed meanwhile');
$check($S->replaceFile($a, $file['key'], $new, 5, '2026-11-09 13:00:00') === 1 && $S->find($a)->file_key === $new['key'] && $S->find($a)->downloaded_at === null, 'replaceFile: the new file, the next download counts again (decision 12)');
$check($S->setIssueError($a, 'render') === 0, 'setIssueError: not once issued');

$b = $S->insert($row($ahmed, 'bank', 'ar', "{$ahmed}:bank:ar", 'none'));
$check($S->markReady($b, 5, '2026-11-09 14:00:00', 'Office 204') === 0, 'markReady: not while waiting');
$S->markDecided($b, 'approved', '', '2026-11-09 14:00:00');
$check($S->markReady($b, 5, '2026-11-09 14:01:00', 'Office 204') === 1 && $S->find($b)->status === 'ready' && $S->find($b)->decision_note === 'Office 204' && $S->find($b)->open_key === null, 'markReady: request only, ready to collect');
$c = $S->insert($row($ahmed, 'embassy', 'en', "{$ahmed}:embassy:en", 'upload'));
$S->markDecided($c, 'approved', '', '2026-11-09 14:00:00');
$check($S->markReady($c, 5, '2026-11-09 14:01:00', '') === 0, 'markReady: not an upload type');
$check($S->cancel($c, 5, '2026-11-09 14:02:00', 'not yet') === 1 && $S->find($c)->status === 'cancelled' && $S->claimIssued($c, $file, 0, '2026-11-09 14:03:00') === 0, 'cancel: then nothing can be attached');
$w = $S->insert($row($sara, 'salary', 'en', "{$sara}:salary:en"));
$check($S->withdraw($w, $ahmed, '2026-11-09 15:00:00') === 0 && $S->withdraw($w, $sara, '2026-11-09 15:00:00') === 1 && $S->find($w)->open_key === null, 'withdraw: the employee\'s own waiting request only');
$x = $S->insert($row($sara, 'bank', 'en', "{$sara}:bank:en", 'none'));
$check($S->markDecided($x, 'rejected', 'No', '2026-11-09 15:00:00') === 1 && $S->find($x)->open_key === null && $S->find($x)->decision_note === 'No', 'markDecided rejected: closed, with the reason');

// ---- the read model
$f = $Q->facts($a);
$check($f && $f->name === 'Ahmed Samir' && $f->name_ar === 'أحمد سمير' && $f->gender === 'male' && $f->hire_date === '2021-03-01' && $f->department_name === 'Accounts' && $f->wp_user_id === '11', 'facts: the letter with the employee and the department');
$check($Q->facts(999999) === null, 'facts: none');
$check($Q->employee($sara)->name === 'Sara 100%_old' && $Q->employee($ahmed)->department_name === 'Accounts' && $Q->employee(999999) === null, 'employee: former ones too, with the department');
$counts = $Q->counts();
$check(($counts['issued'] ?? 0) === 1 && ($counts['pending'] ?? 0) === 3 && $counts['waiting_file'] === 0, 'counts by status');
$list = $Q->adminList('', '', 1, 3);
$check($list['total'] === 8 && count($list['rows']) === 3 && (int) $list['rows'][0]->id > (int) $list['rows'][2]->id && isset($list['rows'][0]->employee_name), 'adminList: all, paged, newest first');
$check($Q->adminList('', '100%_', 1, 50)['total'] === 2, 'adminList: a search with % and _ is literal');
$check($Q->adminList('ready', '', 1, 50)['total'] === 1, 'adminList: by status');
$check(array_map('intval', array_column($Q->waiting(), 'id')) === [$o1, $o2, $a2], 'waiting: oldest first (then by id)');

// ---- a database error
$quiet = $db->suppress_errors(true);
$broken = new WordPressLetterStore((function () use ($db) { $d = clone $db; $d->prefix = 'hl_none_'; return $d; })());
$check($broken->insert($row($ahmed, 'x', 'ar', null)) === 0 && !$broken->lastWasDuplicate() && $broken->markDecided(1, 'approved', '', '2026-11-09 10:00:00') === false, 'a missing table: 0 and false, not a duplicate');
$db->suppress_errors($quiet);
echo "done\n";
