<?php
/**
 * Test only (tests/e2e_support.py break_writes()): while the file ews-e2e-break-writes.txt next to this
 * one names a table (without the prefix), every INSERT / UPDATE on it fails, as a full disk or a lost
 * connection would. Copied to the test site's mu-plugins and removed again by the test; never part of
 * the plugin. A file, not an option: reading an option here would run a query inside the query filter.
 */
add_filter('query', function ($q) {
    static $t = null;
    if ($t === null) $t = trim((string) @file_get_contents(__DIR__ . '/ews-e2e-break-writes.txt'));
    if ($t === '') return $q;
    global $wpdb;
    if (preg_match('/^\s*(INSERT\s+INTO|UPDATE)\s+`?' . preg_quote($wpdb->prefix . $t, '/') . '`?\s/i', $q)) return 'SELECT broken_on_purpose FROM no_such_table_' . $t;
    return $q;
});
