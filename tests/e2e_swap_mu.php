<?php
/**
 * Test only (tests/e2e_swap.py): copied to the test site's mu-plugins and removed by the test; never part of the plugin.
 * While ews-e2e-swap.txt says "stale <employee id> <day> <status>", reading that employee's schedule day returns <status> whatever is
 * stored: the swap sees the day as it was a moment before someone changed it (the race the swap must not lose).
 */
add_filter('query', function ($q) {
    static $m = null;
    if ($m === null) $m = preg_split('/\s+/', trim((string) @file_get_contents(__DIR__ . '/ews-e2e-swap.txt')));
    if (count($m) !== 4 || $m[0] !== 'stale') return $q;
    $re = '/^\s*SELECT \* FROM (\S*ews_schedule) WHERE employee_id=' . (int) $m[1] . ' AND work_date=\'' . preg_quote($m[2], '/') . '\' LIMIT 1\s*$/';
    if (preg_match($re, $q, $x)) return "SELECT id,employee_id,work_date,'" . preg_replace('/[^A-Za-z ]/', '', $m[3]) . "' AS status FROM {$x[1]} WHERE employee_id=" . (int) $m[1] . " AND work_date='" . $m[2] . "' LIMIT 1";
    return $q;
});
