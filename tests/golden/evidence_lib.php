<?php
/**
 * The recording part shared by the evidence traces (actions_evidence.php, actions_timecsv.php, actions_timecsv_mgr.php; docs/tasks/attendance-evidence.md §5):
 * dates as placeholders, the SQL log with times normalised, fault injection by a priority-5 query filter, $run / $faulty / $post / $get. Same as actions_records.php.
 * Needs $wpdb, $p, $c (GOLDEN_CTX) in scope; defines $o, $call, $D0, $DM1, $dates, $fix, $sql, $trace, $norm, $as, $run, $faulty, $post, $get.
 */
global $wpdb;
$p = $wpdb->prefix;
$c = json_decode((string) getenv('GOLDEN_CTX'), true);
require_once rtrim((string) getenv('GOLDEN_DIR'), '/') . '/exit.php';
$o = new EWS_Manager_V31_1();
$call = function ($name, ...$args) use ($o) { $m = new ReflectionMethod($o, $name); $m->setAccessible(true); return $m->invokeArgs($o, $args); };

// ---------------------------------------------------------------- dates as placeholders
$D0 = current_time('Y-m-d');
$DM1 = gmdate('Y-m-d', strtotime($D0 . ' -1 day'));
$dates = [$D0 => 'D0', $DM1 => 'D-1', gmdate('Y-m-d', strtotime($D0 . ' +1 day')) => 'D+1'];
$fix = function ($s) use ($dates) { return strtr($s, $dates); };

// ---------------------------------------------------------------- recording (with in-process fault injection)
$sql = [];
$fault = null;
add_filter('query', function ($q) use (&$sql, $fix) {
    $n = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $q);
    $n = preg_replace("/(_transient_timeout_\w+', ')\d+/", '$1TS', $n);
    $n = preg_replace('/\b\d{13}\b/', 'TS', $n);
    $n = preg_replace('/`actual_minutes` = \d+/', '`actual_minutes` = N', $n);   // minutes since a break started follow "now"
    $n = preg_replace('#/ \d+m /#', '/ Nm /', $n);   // ... and so does the audit text of a closed break
    $n = preg_replace("/SET `option_value` = '.*?' WHERE `option_name` = 'cron'/s", "SET `option_value` = 'CRON' WHERE `option_name` = 'cron'", $n);
    $n = preg_replace('/(<|&lt;)div.*?(?=\', \'T\'\))/s', '', $n);
    $sql[] = $fix(trim(preg_replace('/\s+/', ' ', $n)));
    return $q;
}, 1);
add_filter('query', function ($q) use (&$fault) {
    if ($fault === null) return $q;
    [$re, $to] = is_array($fault) ? $fault : [$fault, 'SELECT broken_on_purpose FROM no_such_table_x'];
    return preg_match($re, $q) ? $to : $q;
}, 5);
$norm = function ($v) use (&$norm, $dates) {
    if ($v instanceof WP_Error) return ['wp_error' => $v->get_error_code(), 'message' => preg_replace('/(<|&lt;)div.*$/s', '', $v->get_error_message())];
    if (is_object($v)) $v = get_object_vars($v);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $x) $out[$k] = (($k === 'nonce' || $k === 'actual_minutes') && $x !== '' && $x !== null) ? 'N' : $norm($x);
        return $out;
    }
    if (is_string($v)) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return 'T';
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $v)) return 'HM';
        if (preg_match('/^\d{13}$/', $v)) return 'TS';
        $v = preg_replace('/_wpnonce=[0-9a-f]+/', '_wpnonce=N', strtr($v, $dates));
        $v = preg_replace('#^https?://[^/]+#', '', $v);
        $v = preg_replace('/(<|&lt;)div.*$/s', '', $v);
        $v = preg_replace('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', 'T', $v);
        $v = preg_replace('/\b\d{2}:\d{2}\b/', 'HM', $v);
        $v = preg_replace('/\b\d+m\b/', 'Nm', $v);   // a time of day that follows "now" (the rules' times, the audit texts)
    }
    return $v;
};
$trace = [];
$as = function ($uid) { wp_set_current_user((int) $uid); };
$run = function ($label, callable $fn) use (&$trace, &$sql, $norm) {
    wp_cache_flush();
    $sql = [];
    $ret = $fn();
    $trace[$label] = ['return' => $norm($ret), 'queries' => count($sql), 'sql' => $sql];
};
$faulty = function ($label, $pattern, callable $fn) use (&$fault, $run, $wpdb) {
    $fault = $pattern;
    $quiet = $wpdb->suppress_errors(true);
    try { $run($label, $fn); } finally { $wpdb->suppress_errors($quiet); $fault = null; }
};
$post = function ($method, array $fields, $nonce) use ($o) { return golden_post($o, $method, $fields, $nonce, admin_url('admin.php')); };
$get = function ($method, array $fields, $nonce) use ($o) { return golden_get($o, $method, $fields, $nonce); };
