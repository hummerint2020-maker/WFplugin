<?php
/**
 * Test only (tests/e2e_advances.py, 3.31.116): copied to the test site's mu-plugins and removed by the test; never part of the plugin.
 * The file ews-e2e-payroll.txt next to this one names what to do with the next payroll close:
 *   take-none   the UPDATE that takes the advance instalments changes no row (as if another month had taken them)
 *   take-one    it takes only the first one
 *   slow        the close waits 2 s when its transaction starts (to close two months at the same moment)
 * A file, not an option: reading an option here would run a query inside the query filter.
 */
add_filter('query', function ($q) {
    static $mode = null;
    if ($mode === null) $mode = trim((string) @file_get_contents(__DIR__ . '/ews-e2e-payroll.txt'));
    if ($mode === '') return $q;
    if (preg_match('/^\s*UPDATE \S*ews_advance_instalments SET taken_run_id=\d+ WHERE taken_run_id=0 AND id IN \((\d+)/i', $q, $m)) {
        if ($mode === 'take-none') return $q . ' AND 1=0';
        if ($mode === 'take-one') return $q . ' AND id=' . (int) $m[1];
    }
    if ($mode === 'slow' && preg_match('/^\s*START TRANSACTION/i', $q)) usleep(2000000);
    return $q;
});
