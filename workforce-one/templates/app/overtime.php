<?php
/**
 * Employee app: Overtime. Styles: assets/css/workforce-one.css (.ews-ot-*); script:
 * assets/js/overtime.js (live duration of the requested time range). Results of the actions are
 * shown by the layout's pop-up, not here.
 *
 * @var object|null $emp
 * @var string $today
 * @var object[] $mine                       my requests (+ duration, status_text)
 * @var array{mine:bool,rows:object[]}|null $approvals   requests I decide (+ duration)
 * @var string $request_url
 * @var string $post_url
 * @var callable $empty                      ews_empty_state(title, text, url, label)
 */
if (!defined('ABSPATH')) exit;
$status_class = ['pending' => 'ews-ot-pending', 'approved' => 'ews-ot-approved'];
$decision = static function (int $id, string $value, string $label, string $class) use ($post_url): string {
    return '<form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field('ews_overtime_respond', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="' . $id . '"><input type="hidden" name="decision" value="' . esc_attr($value) . '">'
        . '<button class="' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button></form>';
};
$counter = [
    'empty' => __('Select the times to see the requested duration.', 'workforce-one'),
    'order' => __('End time must be after start time.', 'workforce-one'),
    /* translators: %s: duration */
    'req' => __('You are requesting %s.', 'workforce-one'),
    /* translators: %d: hours */
    'h' => __('%d h', 'workforce-one'),
    /* translators: 1: hours, 2: minutes */
    'hm' => __('%1$d h %2$d min', 'workforce-one'),
];
?>
<div class="ews-page ews-ot">
<div class="ews-ot-hero"><div class="ews-ot-kicker"><?php esc_html_e('EXTRA HOURS', 'workforce-one'); ?></div><h2><?php esc_html_e('Overtime', 'workforce-one'); ?></h2><p><?php esc_html_e('Request overtime in advance and know exactly what you are asking to work.', 'workforce-one'); ?></p></div>

<?php if ($emp): ?>
<div class="ews-ot-grid">
    <div class="ews-ot-card">
        <h3><?php esc_html_e('Request Overtime', 'workforce-one'); ?></h3>
        <p class="ews-ot-muted"><?php esc_html_e('Submit your overtime request before you work the extra hours. Manager approval is required.', 'workforce-one'); ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_overtime_request_create'); ?><input type="hidden" name="action" value="ews_overtime_request_create">
            <div class="ews-ot-fields">
                <div class="ews-ot-field"><label><?php esc_html_e('Date', 'workforce-one'); ?></label><input id="ews-ot-date" type="date" name="overtime_date" min="<?php echo esc_attr($today); ?>" required></div>
                <div class="ews-ot-field"><label><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-ot-start" type="time" name="start_time" required></div>
                <div class="ews-ot-field"><label><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-ot-end" type="time" name="end_time" required></div>
            </div>
            <div id="ews-ot-count" class="ews-ot-count" data-messages="<?php echo esc_attr(wp_json_encode($counter)); ?>">🕐 <span><?php echo esc_html($counter['empty']); ?></span></div>
            <div class="ews-ot-field" style="margin-top:14px"><label><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea name="reason" rows="3" placeholder="<?php esc_attr_e('Why is overtime required?', 'workforce-one'); ?>" required></textarea></div>
            <button class="ews-btn ews-ot-submit" type="submit"><?php esc_html_e('Submit Overtime Request', 'workforce-one'); ?></button>
        </form>
    </div>
    <div class="ews-ot-card">
        <h3><?php esc_html_e('My Overtime Requests', 'workforce-one'); ?></h3>
        <p class="ews-ot-muted"><?php esc_html_e('Track your pending and completed overtime requests.', 'workforce-one'); ?></p>
        <div class="ews-ot-list">
        <?php if (!$mine) echo $empty(__('No Overtime Requests Yet', 'workforce-one'), __('You haven\'t submitted any overtime requests yet.', 'workforce-one'), $request_url, __('Request Overtime', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
        <?php foreach ($mine as $r): ?>
            <div class="ews-ot-row"><div>
                <div class="ews-ot-date"><?php echo esc_html($r->overtime_date . ' · ' . substr($r->start_time, 0, 5) . ' → ' . substr($r->end_time, 0, 5)); ?></div>
                <div class="ews-ot-meta"><?php echo esc_html($r->duration . ' · ' . $r->reason); ?></div>
            </div><span class="ews-ot-status <?php echo esc_attr($status_class[strtolower((string) $r->status)] ?? 'ews-ot-rejected'); ?>"><?php echo esc_html($r->status_text); ?></span></div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($approvals): ?>
<div class="ews-ot-card" style="margin-top:18px">
    <h3><?php echo esc_html($approvals['mine'] ? __('My Pending Overtime Approvals', 'workforce-one') : __('Pending Overtime Requests', 'workforce-one')); ?></h3>
    <p class="ews-ot-muted"><?php echo esc_html($approvals['mine'] ? __('Requests currently assigned to you by the approval workflow.', 'workforce-one') : __('Review overtime requests. Any Manager can approve or reject.', 'workforce-one')); ?></p>
    <?php if (!$approvals['rows']) echo $empty($approvals['mine'] ? __('No Pending Overtime Approvals', 'workforce-one') : __('No Pending Overtime Requests', 'workforce-one'), __('You\'re all caught up.', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
    <?php foreach ($approvals['rows'] as $r): $level = isset($r->step_order) ? __('Level', 'workforce-one') . ' ' . (int) $r->step_order : ''; ?>
        <div class="ews-ot-row"><div>
            <div class="ews-ot-date"><?php echo esc_html($r->employee_name . ' · ' . $r->overtime_date . ($level !== '' ? ' · ' . $level : '')); ?></div>
            <div class="ews-ot-meta"><?php echo esc_html(substr($r->start_time, 0, 5) . ' → ' . substr($r->end_time, 0, 5)); ?> · <strong><?php echo esc_html($r->duration); ?></strong></div>
            <div class="ews-ot-meta" style="margin-top:7px">“<?php echo esc_html($r->reason); ?>”</div>
            <div style="display:flex;gap:8px;margin-top:12px"><?php echo $decision((int) $r->id, 'approve', __('Approve', 'workforce-one'), 'ews-btn') . $decision((int) $r->id, 'reject', __('Reject', 'workforce-one'), 'ews-btn secondary'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></div>
        </div><span class="ews-ot-status ews-ot-pending"><?php echo esc_html($level !== '' ? $level : __('Pending', 'workforce-one')); ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
