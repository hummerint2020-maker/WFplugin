<?php
/** Isolated temporary tables: run only in the CI WordPress installation with wp eval-file. */
use WorkforceOne\Notifications\Inbox;
use WorkforceOne\Notifications\WordPressInboxStore;

global $wpdb;
$source = $wpdb->prefix . 'ews_notifications';
$prefix = $wpdb->prefix . 'inbox_contract_';
$table = $prefix . 'ews_notifications';
$check = static function ($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$check($wpdb->query("CREATE TEMPORARY TABLE {$table} LIKE {$source}") !== false, 'contract table copied from the installed inbox schema');
$db = clone $wpdb;
$db->prefix = $prefix;
$inbox = new Inbox(new WordPressInboxStore($db));
$publish = static function ($user, $date) use ($inbox) {
    return $inbox->publish(['user_id' => $user, 'title' => 'Contract', 'message' => 'Test',
        'type' => 'info', 'entity' => null, 'entity_id' => null, 'is_read' => 0,
        'created_at' => $date, 'link' => null]);
};
try {
    $old = $publish(1, '2026-01-01 09:00:00');
    $new = $publish(1, '2026-01-02 09:00:00');
    $other = $publish(2, '2026-01-03 09:00:00');
    $check($old > 0 && $new > $old && $other > $new, 'insertion returns database IDs');
    $before = $db->num_queries;
    $check($inbox->unreadCount(1) === 2 && $inbox->unreadCount(1) === 2 && $db->num_queries === $before + 1, 'one count query per recipient until mutation');
    $check($inbox->findForUser($old, 2) === null && !$inbox->markRead($old, 2, '2026-01-04 09:00:00'), 'foreign notification cannot be fetched or marked read');
    $check(array_map(static function ($r) { return (int)$r->id; }, $inbox->rows(1)) === [$new, $old], 'only own notifications, newest unread first');
    $inbox->markRead($new, 1, '2026-01-04 09:00:00', true);
    $inbox->markRead($new, 1, '2026-01-05 09:00:00', true);
    $check($inbox->findForUser($new, 1)->read_at === '2026-01-04 09:00:00', 'landing twice preserves the first read timestamp');
    $check((int)$inbox->rows(1)[0]->id === $old && count($inbox->rows(1, 30, true)) === 1, 'unread first and unread filter preserved');
    $inbox->setLink($old, 'https://example.test/app/?ews_view=notifications');
    $check($inbox->findForUser($old, 1)->link === 'https://example.test/app/?ews_view=notifications', 'saved fallback link survives storage');
    $inbox->markAllRead(1, '2026-01-06 09:00:00');
    $check($inbox->unreadCount(1) === 0 && $inbox->unreadCount(2) === 1 && $inbox->findForUser($new, 1)->read_at === '2026-01-04 09:00:00', 'mark all affects only unread rows of its recipient');
    $inbox->deleteBefore('2026-01-02 00:00:00');
    $check($inbox->findForUser($old, 1) === null && $inbox->findForUser($new, 1) !== null, 'retention cutoff preserved');

    // Exercise the actual compatibility adapter across a site-prefix switch in one request.
    $manager = new EWS_Manager_V31_1();
    $factory = new ReflectionMethod($manager, 'notification_inbox');
    $factory->setAccessible(true);
    $originalPrefix = $wpdb->prefix;
    $first = $factory->invoke($manager);
    try {
        $wpdb->prefix = $prefix;
        $second = $factory->invoke($manager);
        $check($second !== $first && $second === $factory->invoke($manager), 'site switch gets a separate reusable inbox');
        $check($second->unreadCount(2) === 1, 'switched-site store uses its own table');
    } finally {
        $wpdb->prefix = $originalPrefix;
    }
    $check($first === $factory->invoke($manager), 'returning to original site restores its inbox');

    // 3.31.120: no recipient is nothing to save, not a failed write (no misleading notification_insert_failed row).
    $insert = new ReflectionMethod($manager, 'notification_insert');
    $insert->setAccessible(true);
    $audits = static function () use ($wpdb) { return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ews_audit_log WHERE action='notification_insert_failed'"); };
    $failed = $audits();
    $check($insert->invoke($manager, 0, 'general', 'Nobody', 'Test', 'info', null, null) === 0 && $audits() === $failed, 'a notification without a recipient saves nothing and logs no failure');
} finally {
    $wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$table}");
}
