<?php
/**
 * The golden harness's corrections export (tests/golden/run.py; docs/tasks/attendance-corrections.md §4, L5e1): corrections_export ends in Download::send, so
 * the export is the last thing this script does; the file is kept by a buffer callback and a shutdown function prints it (dates and times as placeholders)
 * with the exact SQL. As HR, for a month with requests and a department.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_cxcsv.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';
update_option('ews_feature_corrections', 1, false);
wp_set_current_user((int) $c['cx_hr_user']);
wp_cache_flush();
$sql = [];
$csv = '';
$done = false;
// Download::send empties every buffer it can remove, then echoes the file: this buffer cannot be removed or cleaned (only flushed), so the file lands in it.
ob_start(function ($chunk) use (&$csv, &$done) {
    if ($done) return $chunk;
    $csv .= $chunk;
    return '';
}, 0, PHP_OUTPUT_HANDLER_FLUSHABLE);
register_shutdown_function(function () use (&$sql, &$trace, $norm, &$csv, &$done) {
    @ob_flush();
    $done = true;
    $csv = preg_split('/\r?\n(?:<br \/>\n)?(?:<b>)?(?:PHP )?(?:Notice|Warning|Deprecated)/', $csv)[0];   // WordPress's own shutdown hook tries to close this buffer first and may print a notice
    $trace['csv_corrections'] = ['return' => $norm($csv), 'queries' => count($sql), 'sql' => $sql];
    echo "\n" . wp_json_encode($trace);
});
$_GET = wp_slash(['month' => substr(gmdate('Y-m-d', strtotime($D0 . ' -3 days')), 0, 7), 'department' => 0, '_wpnonce' => wp_create_nonce('ews_corrections_export')]);
$_REQUEST = $_GET;
$o->corrections_export();
