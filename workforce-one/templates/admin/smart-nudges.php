<?php
/**
 * wp-admin "Smart Nudges". Styles: assets/css/admin-smart-nudges.css.
 *
 * @var array<string,mixed> $cfg
 * @var array<string,string> $items         optional reminders: key => description
 * @var int $last_run                       unix time of the last scheduler run (0 = never)
 * @var string[] $last_result               "Label: value" parts of the last run
 * @var string|null $notice
 * @var bool|null $test_ok                  result of a test push (null = no test)
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Smart Nudges</h1>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($test_ok !== null): ?><div class="notice <?php echo $test_ok ? 'notice-success' : 'notice-warning'; ?> is-dismissible"><p><?php echo esc_html($test_ok ? 'Test push was accepted for delivery.' : 'Test push was not sent. Check the Push subscription and Notification Policy for this admin user.'); ?></p></div><?php endif; ?>
<div class="wfo-nudge-shell" style="margin-top:18px">
<div class="wfo-nudge-intro"><p style="margin:0;color:#667085;font-size:14px;line-height:1.6">Smart Nudges provide lightweight employee reminders. Sign In Reminder is an independent attendance reminder: it can send a push notification once after the configured delay by default, with optional repeat reminders. No database tables are required.</p></div>
<?php // The diagnostic test form stays separate: nested forms make the Save button unreliable. ?>
<form id="wfo-nudge-test-form" method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_smart_nudge_test_push'); ?><input type="hidden" name="action" value="ews_smart_nudge_test_push"></form>
<form method="post" action="<?php echo esc_url($post_url); ?>">
    <?php wp_nonce_field('ews_smart_nudges_save'); ?><input type="hidden" name="action" value="ews_smart_nudges_save">
    <div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title">Enable Smart Nudges</div><div class="wfo-nudge-desc">Enable the employee-facing Smart Nudge layer for Tasks, Leave and Schedule. Sign In Reminder is independent.</div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="enabled" value="1" <?php checked(!empty($cfg['enabled'])); ?>> Enabled</label></div></div>
    <div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title">Sign In Reminder</div><div class="wfo-nudge-desc">Send a push notification when the employee has not signed in after their own scheduled start time.</div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="items[attendance]" value="1" <?php checked(!empty($cfg['items']['attendance'])); ?>> Enabled</label></div>
        <div class="wfo-nudge-subgrid">
            <label><span>Remind after</span><input type="number" name="attendance_after" min="1" max="240" value="<?php echo (int) $cfg['attendance_after']; ?>"> <em>minutes</em></label>
            <label><span>Repeat reminder</span><input type="checkbox" name="attendance_repeat" value="1" <?php checked(!empty($cfg['attendance_repeat'])); ?>> Enabled</label>
            <label><span>Repeat every</span><input type="number" name="attendance_repeat_interval" min="5" max="240" value="<?php echo (int) $cfg['attendance_repeat_interval']; ?>"> <em>minutes</em></label>
            <label><span>Maximum reminders</span><input type="number" name="attendance_max_reminders" min="1" max="10" value="<?php echo (int) $cfg['attendance_max_reminders']; ?>"><em> total, including the first</em></label>
        </div>
    </div>
    <?php foreach ($items as $key => $desc): ?>
        <div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title"><?php echo esc_html(ucfirst($key)); ?></div><div class="wfo-nudge-desc"><?php echo esc_html($desc); ?></div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="items[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($cfg['items'][$key])); ?>> Enabled</label></div></div>
    <?php endforeach; ?>
    <div class="wfo-nudge-section" style="background:#fbfcfe"><div class="wfo-nudge-title">Diagnostics</div><div class="wfo-nudge-desc">Use this to separate Push delivery problems from reminder-condition problems. Execute the scheduled event, then refresh this page.</div>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button type="submit" form="wfo-nudge-test-form" class="button">🔔 Send Test Push</button>
            <?php if ($last_run): ?><span style="color:#475467;font-size:13px">Last scheduler run: <strong><?php echo esc_html(wp_date('Y-m-d H:i:s', $last_run)); ?></strong></span><?php else: ?><span style="color:#98a2b3;font-size:13px">No scheduler run recorded yet.</span><?php endif; ?>
        </div>
        <?php if ($last_result): ?><div style="margin-top:10px;color:#667085;font-size:13px"><?php echo esc_html(implode(' · ', $last_result)); ?></div><?php endif; ?>
    </div>
    <div class="wfo-nudge-save"><span style="float:left;color:#667085;font-size:13px;line-height:40px">Sign In Reminder works independently from the Smart Nudges master switch. It is one-shot by default; if repeat is enabled, the maximum includes the first reminder. Employees must enable push notifications on their device.</span><button class="button button-primary">Save Smart Nudges</button></div>
</form>
</div></div>
