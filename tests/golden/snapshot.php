<?php
/**
 * The golden harness's database snapshot (tests/golden/run.py): every ews_* table and the plugin's options, saved to a
 * JSON file and put back exactly, so the old and the new code start from the same data. Works on MySQL and SQLite.
 * Usage: GOLDEN_MODE=save|restore GOLDEN_FILE=/path.json wp eval-file tests/golden/snapshot.php
 */
global $wpdb;
$mode = getenv('GOLDEN_MODE');
$file = getenv('GOLDEN_FILE');
$like = $wpdb->esc_like($wpdb->prefix . 'ews_') . '%';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
$optWhere = "option_name LIKE 'ews\\_%' OR option_name LIKE '\\_transient\\_ews%' OR option_name LIKE '\\_transient\\_timeout\\_ews%' OR option_name='cron'";
if ($mode === 'save') {
    $out = ['tables' => [], 'options' => $wpdb->get_results("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE $optWhere ORDER BY option_name", ARRAY_A)];
    foreach ($tables as $t) $out['tables'][substr($t, strlen($wpdb->prefix))] = $wpdb->get_results("SELECT * FROM `$t`", ARRAY_A);
    file_put_contents($file, wp_json_encode($out));
    echo 'saved ' . count($out['tables']) . " tables\n";
} elseif ($mode === 'restore') {
    $in = json_decode(file_get_contents($file), true);
    foreach ($tables as $t) {
        $short = substr($t, strlen($wpdb->prefix));
        $wpdb->query("TRUNCATE TABLE `$t`");   // the ids start where the snapshot's end, on both sides
        foreach ((array) ($in['tables'][$short] ?? []) as $row) $wpdb->insert($t, $row);
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE $optWhere");
    foreach ($in['options'] as $o) $wpdb->insert($wpdb->options, $o);
    wp_cache_flush();
    echo "restored\n";
}
