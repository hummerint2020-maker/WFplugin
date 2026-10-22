<?php
/**
 * Test only (tests/e2e_support.py break_writes()): while the file ews-e2e-break-writes.txt next to this
 * one names a table (without the prefix), every INSERT / UPDATE / DELETE / CREATE TABLE / ALTER TABLE on it fails (or only one
 * kind: "table INSERT"), as a full disk or a lost
 * connection would. Copied to the test site's mu-plugins and removed again by the test; never part of
 * the plugin. A file, not an option: reading an option here would run a query inside the query filter.
 */
add_filter('query', function ($q) {
    static $t = null;
    if ($t === null) $t = trim((string) @file_get_contents(__DIR__ . '/ews-e2e-break-writes.txt'));
    if ($t === '') return $q;
    global $wpdb;
    // "table" breaks every write; "table INSERT" (or UPDATE, DELETE) only those (3.31.119).
    $parts = preg_split('/\s+/', $t);
    $table = $parts[0];
    $verbs = ['INSERT' => 'INSERT\s+INTO', 'UPDATE' => 'UPDATE', 'DELETE' => 'DELETE\s+FROM'];
    $only = isset($parts[1], $verbs[strtoupper($parts[1])]) ? $verbs[strtoupper($parts[1])] : 'INSERT\s+INTO|UPDATE|DELETE\s+FROM|CREATE\s+TABLE|ALTER\s+TABLE';
    if (preg_match('/^\s*(' . $only . ')\s+`?' . preg_quote($wpdb->prefix . $table, '/') . '`?\s/i', $q)) return 'SELECT broken_on_purpose FROM no_such_table_' . $table;
    return $q;
});
