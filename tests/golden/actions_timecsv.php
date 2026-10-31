<?php
/**
 * The golden harness's CSV export trace for the admin (tests/golden/run.py; docs/tasks/attendance-evidence.md §5, L5d): admin_time_csv ends in exit, so the
 * export is the last thing this script does; a shutdown function prints the file (dates and times as placeholders) and the exact SQL.
 * Usage: GOLDEN_CTX='{json}' GOLDEN_DIR=tests/golden wp eval-file tests/golden/actions_timecsv.php   (prints one JSON line)
 */
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/evidence_lib.php';
update_option('ews_signin_evidence', ['attempts' => 1], false);
$_SERVER['REMOTE_ADDR'] = '10.9.9.9';
wp_set_current_user((int) get_user_by('login', 'admin')->ID);
wp_cache_flush();
$sql = [];
ob_start();
$level = ob_get_level();
register_shutdown_function(function () use (&$sql, &$trace, $norm, $level) {
    while (ob_get_level() > $level) ob_end_flush();
    $csv = (string) ob_get_contents();
    $trace['csv_admin'] = ['return' => $norm($csv), 'queries' => count($sql), 'sql' => $sql];
    ob_end_clean();
    echo "\n" . wp_json_encode($trace);
});
$range = ['start' => gmdate('Y-m-d', strtotime($D0 . ' -2 days')), 'end' => $D0];
$_GET = wp_slash($range + ['_wpnonce' => wp_create_nonce('ews31_time_csv')]);
$_REQUEST = $_GET;
$o->admin_time_csv();
