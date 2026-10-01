<?php
/**
 * wp-admin "Notification Settings" (Notification Policy Center). Styles: assets/css/admin-notifications.css.
 *
 * @var array{ready:int,missing:int,not_configured:int,configured:bool} $health
 * @var array<string,array{label:string,description:string}> $categories
 * @var array<string,array{in_app:int,push:int,mandatory:int}> $policy
 * @var int $retention
 * @var int[] $retention_choices
 * @var string $vapid_subject
 * @var string|null $notice
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
$switch = static function (string $name, int $on) {
    echo '<label class="wfo-switch"><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($on, 1, false) . '><span></span><em>' . ($on ? 'ON' : 'OFF') . '</em></label>';
};
?>
<div class="wrap wfo-notification-policy"><h1>Notification Policy Center</h1>
<p class="description wfo-policy-intro">Control how Workforce One delivers notifications across the organization.</p>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html($notice); ?></strong></p></div><?php endif; ?>

<div class="wfo-policy-health">
    <div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong><?php echo (int) $health['ready']; ?></strong><span>Push Ready</span></div></div>
    <div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong><?php echo (int) $health['missing']; ?></strong><span>No Active Device</span></div></div>
    <div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong><?php echo (int) $health['not_configured']; ?></strong><span>Not Configured</span></div></div>
    <div class="wfo-health-meta"><strong>Push Delivery Health</strong><span><?php echo $health['configured'] ? 'Push infrastructure is configured.' : 'Push infrastructure is not configured yet.'; ?></span></div>
</div>

<form method="post" action="<?php echo esc_url($post_url); ?>">
    <?php wp_nonce_field('ews_notification_policy_save'); ?>
    <input type="hidden" name="action" value="ews_notification_policy_save">
    <div class="wfo-policy-panel">
        <div class="wfo-policy-panel-head"><div><h2>Notification Policy</h2><p>In-App notifications are retained as part of the notification history. Push controls device delivery.</p></div><span class="wfo-policy-admin">Admin controlled</span></div>
        <div class="wfo-policy-table-head"><span>Category</span><span>In-App</span><span>Push</span><span>Mandatory Push</span></div>
        <?php foreach ($categories as $key => $cat): $row = $policy[$key]; ?>
            <div class="wfo-policy-row">
                <div class="wfo-policy-category"><strong><?php echo esc_html($cat['label']); ?></strong><span><?php echo esc_html($cat['description']); ?></span></div>
                <?php $switch('policy[' . $key . '][in_app]', (int) $row['in_app']); $switch('policy[' . $key . '][push]', (int) $row['push']); $switch('policy[' . $key . '][mandatory]', (int) $row['mandatory']); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="wfo-policy-note"><strong>About Mandatory Push</strong><span>Mandatory Push means Workforce One will attempt Push delivery for this category even when optional Push is turned off. It cannot bypass browser or operating-system permissions.</span></div>
    <div class="wfo-policy-actions"><button class="button button-primary button-large" type="submit">Save Notification Policy</button></div>
</form>

<div class="wfo-policy-secondary">
    <div><h2>Push Notifications</h2><p>Send a test push to every subscribed device. This infrastructure test is not affected by the Notification Policy.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <?php wp_nonce_field('ews_push_send_test'); ?><input type="hidden" name="action" value="ews_push_send_test">
            <p><label><strong>VAPID Subject</strong><br><input type="text" name="vapid_subject" value="<?php echo esc_attr($vapid_subject); ?>" class="regular-text" placeholder="mailto:admin@example.com"></label></p>
            <p class="description">Use a mailto: address or your HTTPS site URL. It is saved when you send the test.</p>
            <p><button class="button" type="submit">Save &amp; Send Test Push</button></p>
        </form>
    </div>
    <div><h2>Notification Retention</h2><p>Choose how long notifications remain in the system.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>">
            <?php wp_nonce_field('ews_notification_settings_save'); ?><input type="hidden" name="action" value="ews_notification_settings_save">
            <select name="retention_days"><?php foreach ($retention_choices as $v): ?><option value="<?php echo (int) $v; ?>" <?php selected($retention, $v); ?>><?php echo esc_html($v === 0 ? 'Forever' : $v . ' days'); ?></option><?php endforeach; ?></select>
            <p><button class="button" type="submit">Save Retention</button></p>
        </form>
    </div>
</div>
</div>
