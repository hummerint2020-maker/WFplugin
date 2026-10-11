<?php
/**
 * Employee app → Notifications (look A: grouped by day). Styles: assets/css/app-notifications.css;
 * phone alerts strip and row clicks: assets/js/notifications.js (push subscribe / unsubscribe through
 * admin-post ews_push_subscribe / ews_push_unsubscribe, as before).
 *
 * @var string $filter                 all | unread
 * @var array<string,list<array{id:int,title:string,message:string,unread:bool,when:string,icon:string,tone:string,open_url:string}>> $groups  today | yesterday | earlier
 * @var int $unread
 * @var string $all_url
 * @var string $unread_url
 * @var string $post_url
 * @var string $push_key               VAPID public key ('' = push is not set up)
 * @var string $push_nonce
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; messages are wp_kses_post()ed.
$group_names = ['today' => __('Today', 'workforce-one'), 'yesterday' => __('Yesterday', 'workforce-one'), 'earlier' => __('Earlier', 'workforce-one')];
?>
<div class="ews-notifications-page wfo-notif">
    <div class="wfo-notif-main">
        <div class="wfo-notif-push" id="ews-notification-push-settings" data-push-key="<?php echo esc_attr($push_key); ?>" data-push-url="<?php echo esc_url($post_url); ?>" data-push-nonce="<?php echo esc_attr($push_nonce); ?>" hidden>
            <span class="wfo-notif-push-icon"><?php echo Icons::svg('bell', 18); ?></span>
            <div class="wfo-notif-push-text">
                <strong class="is-off-only"><?php esc_html_e('Alerts are off on this device', 'workforce-one'); ?></strong>
                <strong class="is-on-only"><?php esc_html_e('Alerts are on on this device', 'workforce-one'); ?></strong>
                <span><?php esc_html_e('Get leave, swap and schedule news as it happens.', 'workforce-one'); ?></span>
            </div>
            <button type="button" class="wfo-notif-push-btn is-off-only" id="ews-notification-push-enable"><?php esc_html_e('Turn on', 'workforce-one'); ?></button>
            <button type="button" class="wfo-notif-push-btn is-ghost is-on-only" id="ews-notification-push-disable"><?php esc_html_e('Turn off', 'workforce-one'); ?></button>
        </div>

        <div class="wfo-notif-bar">
            <nav class="wfo-notif-tabs ews-notification-tabs" aria-label="<?php esc_attr_e('Notification filter', 'workforce-one'); ?>">
                <a class="<?php echo $filter === 'all' ? 'active' : ''; ?>" href="<?php echo esc_url($all_url); ?>"<?php echo $filter === 'all' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('All', 'workforce-one'); ?></a>
                <a class="<?php echo $filter === 'unread' ? 'active' : ''; ?>" href="<?php echo esc_url($unread_url); ?>"<?php echo $filter === 'unread' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Unread', 'workforce-one'); ?><?php if ($unread): ?> <span class="wfo-notif-count"><?php echo (int) $unread; ?></span><?php endif; ?></a>
            </nav>
            <?php if ($unread): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-notif-readall">
                <?php wp_nonce_field('ews_notification_read_all'); ?>
                <input type="hidden" name="action" value="ews_notification_read_all">
                <button type="submit" aria-label="<?php esc_attr_e('Mark all read', 'workforce-one'); ?>"><?php echo Icons::svg('check', 16, 2.2); ?><span><?php esc_html_e('Mark all read', 'workforce-one'); ?></span></button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!$groups): ?>
        <div class="wfo-notif-empty">
            <span class="wfo-notif-empty-icon"><?php echo Icons::svg($filter === 'unread' ? 'check' : 'bell', 26); ?></span>
            <strong><?php echo $filter === 'unread' ? esc_html__("You're all caught up.", 'workforce-one') : esc_html__('No notifications yet.', 'workforce-one'); ?></strong>
            <span><?php echo $filter === 'unread' ? esc_html__('No unread notifications.', 'workforce-one') : esc_html__('News about your leave, schedule and requests will show up here.', 'workforce-one'); ?></span>
        </div>
        <?php endif; ?>

        <?php foreach ($group_names as $key => $label): if (empty($groups[$key])) continue; ?>
        <section class="wfo-notif-group" aria-label="<?php echo esc_attr($label); ?>">
            <h3><?php echo esc_html($label); ?></h3>
            <ul class="wfo-notif-list">
                <?php foreach ($groups[$key] as $n): ?>
                <li class="ews-notification wfo-notif-item <?php echo $n['unread'] ? 'unread is-unread' : 'read'; ?>">
                    <a class="wfo-notif-link" href="<?php echo esc_url($n['open_url']); ?>">
                        <span class="wfo-notif-icon is-<?php echo esc_attr($n['tone']); ?>"><?php echo Icons::svg($n['icon'], 18); ?></span>
                        <span class="wfo-notif-body">
                            <span class="wfo-notif-title"><?php echo esc_html($n['title']); ?><?php if ($n['unread']): ?><span class="screen-reader-text"> (<?php esc_html_e('unread', 'workforce-one'); ?>)</span><?php endif; ?></span>
                            <span class="wfo-notif-msg"><?php echo wp_kses_post($n['message']); ?></span>
                            <span class="wfo-notif-when"><?php echo esc_html($n['when']); ?></span>
                        </span>
                    </a>
                    <?php if ($n['unread']): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-notif-markread">
                        <?php wp_nonce_field('ews_notification_read_' . $n['id']); ?>
                        <input type="hidden" name="action" value="ews_notification_read">
                        <input type="hidden" name="notification_id" value="<?php echo (int) $n['id']; ?>">
                        <button type="submit" title="<?php esc_attr_e('Mark read', 'workforce-one'); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: notification title */ __('Mark "%s" as read', 'workforce-one'), $n['title'])); ?>"><span class="wfo-notif-dot" aria-hidden="true"></span></button>
                    </form>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endforeach; ?>
    </div>
</div>
