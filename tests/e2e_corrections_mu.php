<?php
/**
 * Test only (tests/e2e_corrections.py): copied to the test site's mu-plugins and removed by the test; never part of the plugin.
 * While ews-e2e-corrections.txt next to it says "engine-moved", cancelling an approval request matches no row, as when the request
 * is decided at the same moment it is closed.
 */
add_filter('query', function ($q) {
    static $mode = null;
    if ($mode === null) $mode = trim((string) @file_get_contents(__DIR__ . '/ews-e2e-corrections.txt'));
    if ($mode === 'engine-moved' && preg_match('/^\s*UPDATE\s+`?\S*ews_approval_requests`?\s+SET\s+`status`\s*=\s*\'CANCELLED\'/i', $q)) return $q . ' AND 1=0';
    return $q;
});
