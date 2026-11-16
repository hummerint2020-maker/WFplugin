<?php
/**
 * Test only (tests/e2e_hr_letters_more.py): copied to the test site's mu-plugins and removed by the test; never part of the plugin.
 * ews-e2e-hr-letters.txt next to it names one fault, as when another request does the same thing at the same moment:
 *   open-check-blind  the "one open request" check sees nothing (two requests passed it together): the UNIQUE key must refuse the second
 *   letter-decided    the letter is no longer waiting when the decision is saved (withdrawn or decided meanwhile)
 *   engine-moved      the approval was decided while the employee withdraws (closing it matches no row)
 *   claim-lost        another request attached the letter's file first (issuing matches no row)
 *   start-fails       the approval request cannot be written (the letter row must roll back with it)
 */
add_filter('query', function ($q) {
    static $mode = null;
    if ($mode === null) $mode = trim((string) @file_get_contents(__DIR__ . '/ews-e2e-hr-letters.txt'));
    if ($mode === '') return $q;
    $t = '`?\S*ews_hr_letters`?';
    if ($mode === 'open-check-blind' && preg_match('/^\s*SELECT\s+COUNT\(\*\)\s+FROM\s+' . $t . '\s+WHERE\s+employee_id=/i', $q)) return 'SELECT 0';
    if ($mode === 'letter-decided' && preg_match('/^\s*UPDATE\s+' . $t . '\s+SET\s+status=\'(approved|rejected)\',decided_at/i', $q)) return $q . ' AND 1=0';
    if ($mode === 'engine-moved' && preg_match('/^\s*UPDATE\s+`?\S*ews_approval_requests`?\s+SET\s+`status`\s*=\s*\'CANCELLED\'/i', $q)) return $q . ' AND 1=0';
    if ($mode === 'claim-lost' && preg_match('/^\s*UPDATE\s+' . $t . '\s+SET\s+status=\'issued\'/i', $q)) return $q . ' AND 1=0';
    if ($mode === 'start-fails' && preg_match('/^\s*INSERT\s+INTO\s+`?\S*ews_approval_requests`?/i', $q)) return 'INSERT INTO ews_e2e_no_such_table VALUES (1)';
    return $q;
});
