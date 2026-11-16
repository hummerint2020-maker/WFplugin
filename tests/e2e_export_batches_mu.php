<?php
/**
 * Test only (tests/e2e_export_batches.py): copied to the test site's mu-plugins and removed by the test; never part of the plugin.
 * The option ews_e2e_export sets the exports' batch sizes, a failing read, and records the request's peak memory:
 *   rows  the Sign In / Out CSV's window (records)       days  the Report Center's group (employee-days)
 *   fail  the second window of the Sign In / Out CSV fails  peak  save memory_get_peak_usage() at shutdown
 *   rfail the time logs of the Report Center's second group of employees cannot be read
 * Not in wp-cli (the test's own calls).
 */
if (defined('WP_CLI') && WP_CLI) return;
$ews_e2e = get_option('ews_e2e_export');
if (!is_array($ews_e2e)) return;
if (!empty($ews_e2e['rows'])) add_filter('ews_export_batch_rows', function () use ($ews_e2e) { return (int) $ews_e2e['rows']; });
if (!empty($ews_e2e['days'])) add_filter('ews_export_batch_days', function () use ($ews_e2e) { return (int) $ews_e2e['days']; });
if (!empty($ews_e2e['fail'])) add_filter('query', function ($q) {
    static $n = 0;
    if (strpos($q, ') replaced_by FROM') !== false && strpos($q, 'ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC') !== false && ++$n === 2) return 'SELECT * FROM ews_e2e_no_such_table';
    return $q;
});
if (!empty($ews_e2e['rfail'])) add_filter('query', function ($q) {
    static $n = 0;
    if (strpos($q, 'SELECT employee_id,work_date,event_type,event_at,branch_flag,correction_id FROM') !== false && ++$n === 2) return 'SELECT * FROM ews_e2e_no_such_table';
    return $q;
});
if (!empty($ews_e2e['peak'])) add_action('shutdown', function () { update_option('ews_e2e_peak', memory_get_peak_usage(false), false); }, 0);
