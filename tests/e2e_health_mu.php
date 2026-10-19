<?php
/**
 * Test only (tests/e2e_system_health.py): copied to the test site's mu-plugins and removed by the test; never part
 * of the plugin. Keeps the outgoing mail in a file instead of sending it, and, for a request carrying ?e2e_tag=,
 * writes its final database query count and time (after every shutdown callback, the collector's own writes
 * included) to ews-e2e-health-q.txt next to this file.
 */
add_filter('pre_wp_mail', function ($null, $atts) {
    file_put_contents(__DIR__ . '/ews-e2e-health-mail.txt', wp_json_encode(['to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message']]) . "\n", FILE_APPEND | LOCK_EX);
    return true;
}, 10, 2);
add_action('admin_init', function () {   // a fatal error on demand, from this file (not the plugin's)
    if (isset($_GET['e2e_fatal'])) e2e_health_undefined_function();
});
add_action('shutdown', function () {
    register_shutdown_function(function () {
        if (!isset($_GET['e2e_tag'])) return;
        global $wpdb;
        file_put_contents(__DIR__ . '/ews-e2e-health-q.txt', wp_json_encode([sanitize_key($_GET['e2e_tag']), (int) $wpdb->num_queries, round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 1)]) . "\n", FILE_APPEND | LOCK_EX);
    });
}, PHP_INT_MAX);
