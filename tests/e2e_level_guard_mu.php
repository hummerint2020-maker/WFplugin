<?php
// Test helper (tests/e2e_level_guard.py): another decision lands just before this one's UPDATE of the level.
add_filter('query',function($sql){
    global $wpdb;static $done=false;
    if(!$done && strpos($sql,'UPDATE `'.$wpdb->prefix.'ews_approval_steps`')===0 && strpos($sql,"`decision`")!==false){
        $done=true;$wpdb->query("UPDATE {$wpdb->prefix}ews_approval_steps SET status='APPROVED',decision='approve' WHERE status='PENDING' ORDER BY step_order LIMIT 1");
        $wpdb->query("UPDATE {$wpdb->prefix}ews_approval_steps SET status='PENDING' WHERE status='WAITING' ORDER BY step_order LIMIT 1");
    }
    return $sql;
});
