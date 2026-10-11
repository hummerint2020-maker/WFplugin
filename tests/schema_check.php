<?php
/**
 * Runs the plugin's schema upgrade twice (wp eval-file) and prints JSON for tests/e2e_schema.py:
 * the ALTERs and database errors of each run, and every index a CREATE TABLE statement defines
 * that the database does not have (or has with other columns).
 */
global $wpdb, $EZSQL_ERROR;
$wpdb->suppress_errors(true);
$GLOBALS['e2e_creates'] = [];
$GLOBALS['e2e_alters'] = [];
add_filter('dbdelta_create_queries', static function ($q) { $GLOBALS['e2e_creates'] = array_merge($GLOBALS['e2e_creates'], $q); return $q; });
add_filter('query', static function ($q) { if (preg_match('/^\s*ALTER\s+TABLE/i', $q)) $GLOBALS['e2e_alters'][] = substr(preg_replace('/\s+/', ' ', $q), 0, 200); return $q; });

$runs = [];
for ($i = 0; $i < 2; $i++) {
    $GLOBALS['e2e_alters'] = [];
    $EZSQL_ERROR = [];
    delete_option('ews_schema_version');
    new EWS_Manager_V31_1();   // the constructor runs maybe_upgrade_schema()
    $runs[] = [
        'alters' => $GLOBALS['e2e_alters'],
        'errors' => array_map(static function ($e) { return substr($e['error_str'], 0, 160) . ' :: ' . substr(preg_replace('/\s+/', ' ', $e['query']), 0, 200); }, (array) $EZSQL_ERROR),
        'version' => get_option('ews_schema_version'),
    ];
}

$missing = [];
$checked = 0;
foreach ($GLOBALS['e2e_creates'] as $table => $sql) {
    if (strpos($table, $wpdb->prefix . 'ews_') !== 0) continue;
    $want = [];
    foreach (preg_split('/\n/', $sql) as $line) {
        if (preg_match('/^\s*(PRIMARY KEY|UNIQUE KEY|KEY)\s+(?:(\w+)\s+)?\((.+)\),?\s*$/i', $line, $m)) {
            $name = strtoupper($m[1]) === 'PRIMARY KEY' ? 'primary' : strtolower($m[2]);
            $want[$name] = strtolower(preg_replace('/\s+|`|\(\d+\)/', '', $m[3]));
        }
    }
    $have = [];
    foreach ((array) $wpdb->get_results("SHOW INDEX FROM `{$table}`") as $r) $have[strtolower($r->Key_name)][(int) $r->Seq_in_index] = strtolower($r->Column_name);
    foreach ($want as $name => $cols) {
        $checked++;
        if (!isset($have[$name])) { $missing[] = "{$table}.{$name} ({$cols}) missing"; continue; }
        ksort($have[$name]);
        if (implode(',', $have[$name]) !== $cols) $missing[] = "{$table}.{$name} is (" . implode(',', $have[$name]) . "), expected ({$cols})";
    }
}
echo wp_json_encode(['runs' => $runs, 'tables' => count($GLOBALS['e2e_creates']), 'indexes' => $checked, 'missing' => $missing]);
