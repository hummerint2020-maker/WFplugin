<?php
/**
 * Employee app: Leave (with Early Leave). Styles: assets/css/workforce-one.css (.ews-vac-*);
 * script: assets/js/leave.js (working-day counter, Early Leave checks before submitting).
 * Results of the actions are shown by the layout's pop-up, not here.
 *
 * @var object|null $emp
 * @var string $today
 * @var array<int,array{id:int,name:string,deducts:bool,available:string}> $types
 * @var object[] $mine                  my requests (+ can_cancel, started, status_text)
 * @var bool $has_more
 * @var bool $show_all
 * @var string $all_url
 * @var string $recent_url
 * @var string $request_url
 * @var array{mine:bool,rows:object[]}|null $approvals      leave requests I decide (null = none)
 * @var array{mine:bool,rows:object[]}|null $cancellations  cancellation requests I decide
 * @var array{max:int,remaining:int,summary:string}|null $early
 * @var object[]|null $pending_early   (+ duration) for managers of time
 * @var int[] $working_days
 * @var string $post_url
 * @var callable $empty                 ews_empty_state(title, text, url, label)
 */
if (!defined('ABSPATH')) exit;
$decision_form = static function (string $action, string $nonce, int $id, string $approve_label) use ($post_url): string {
    return '<div class="ews-vac-actions"><form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field($nonce, '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="request_id" value="' . $id . '">'
        . '<button class="button button-primary" name="decision" value="approve">' . esc_html($approve_label) . '</button><button class="button" name="decision" value="reject">' . esc_html__('Reject', 'workforce-one') . '</button></form></div>';
};
$level = static function (object $r): string {
    return isset($r->step_order) ? ' · ' . __('Level', 'workforce-one') . ' ' . (int) $r->step_order : '';
};
/* translators: %s: number of days */
$days_label = static function ($n): string { return sprintf(__('%s day(s)', 'workforce-one'), \WorkforceOne\Support\Format::number((float) $n)); };
$status_class = ['approved' => 'ews-vac-approved', 'pending' => 'ews-vac-pending', 'cancelled' => 'ews-vac-cancelled'];
$early_messages = [
    'title' => __('Early Leave Not Available', 'workforce-one'), 'ok' => __('OK', 'workforce-one'), 'close' => __('Close', 'workforce-one'),
    'date' => __('Please select a date for Early Leave.', 'workforce-one'),
    'future' => __('Early Leave must be requested for a future date.', 'workforce-one'),
    'working_day' => __('Early Leave is available only on a configured working day.', 'workforce-one'),
    'max' => __('The requested Early Leave exceeds the maximum duration allowed for one request.', 'workforce-one'),
    'remaining' => __('The requested Early Leave exceeds the remaining monthly allowance.', 'workforce-one'),
];
?>
<div class="ews-page ews-vac">
<div class="ews-vac-hero"><div class="ews-vac-kicker"><?php esc_html_e('TIME OFF', 'workforce-one'); ?></div><h2><?php esc_html_e('Leave Management', 'workforce-one'); ?></h2><p><?php esc_html_e('Request time off, track your balance and follow approvals in one place. Leave Types marked “No balance deduction” are recorded without consuming leave balance.', 'workforce-one'); ?></p></div>

<?php if ($emp): ?>
<div class="ews-vac-grid">
    <div class="ews-vac-card">
        <h3><?php esc_html_e('Request Leave', 'workforce-one'); ?></h3>
        <p style="color:#667085"><?php esc_html_e('Select a Leave Type and future date range. Working days are calculated automatically.', 'workforce-one'); ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_vacation_request_create'); ?><input type="hidden" name="action" value="ews_vacation_request_create">
            <div class="ews-vac-field" style="margin-top:15px"><label><?php esc_html_e('Leave Type', 'workforce-one'); ?></label><select name="leave_type_id" required>
                <?php foreach ($types as $t): ?>
                    <?php /* translators: %s: available days */ ?>
                    <option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html($t['name'] . ' — ' . ($t['deducts'] ? sprintf(__('Available: %s', 'workforce-one'), $t['available']) : __('No balance deduction', 'workforce-one'))); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="ews-vac-fields">
                <div class="ews-vac-field"><label><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-vac-start" type="date" name="start_date" min="<?php echo esc_attr($today); ?>" required></div>
                <div class="ews-vac-field"><label><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-vac-end" type="date" name="end_date" min="<?php echo esc_attr($today); ?>" required></div>
            </div>
            <?php /* translators: %d: number of working days */ ?>
            <div id="ews-vac-days" class="ews-vac-count" data-working-days="<?php echo esc_attr(wp_json_encode($working_days)); ?>" data-invalid="<?php esc_attr_e('Select a valid date range.', 'workforce-one'); ?>" data-count="<?php esc_attr_e('%d working day(s) requested.', 'workforce-one'); ?>"><?php esc_html_e('Select dates to calculate working days.', 'workforce-one'); ?></div>
            <div class="ews-vac-field" style="margin-top:14px"><label><?php esc_html_e('Reason', 'workforce-one'); ?> <span style="color:#667085"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></label><textarea name="reason" rows="3"></textarea></div>
            <button class="ews-btn ews-vac-submit" type="submit"><?php esc_html_e('Submit Leave Request', 'workforce-one'); ?></button>
        </form>
        <div class="ews-bal-grid">
            <?php foreach ($types as $t): ?><div class="ews-bal"><strong><?php echo esc_html($t['deducts'] ? $t['available'] : '—'); ?></strong><span><?php echo esc_html($t['name']); ?> <?php esc_html_e('Available', 'workforce-one'); ?></span></div><?php endforeach; ?>
        </div>
    </div>

    <div class="ews-vac-card">
        <div class="ews-vac-section-head"><h3><?php esc_html_e('My Leave Requests', 'workforce-one'); ?></h3>
            <?php if ($show_all): ?><a class="ews-vac-view-all" href="<?php echo esc_url($recent_url); ?>">← <?php esc_html_e('Back to recent requests', 'workforce-one'); ?></a><?php endif; ?>
        </div>
        <div>
        <?php if (!$mine) echo $empty(__('No Leave Requests Yet', 'workforce-one'), __('You haven\'t submitted any leave requests yet.', 'workforce-one'), $request_url, __('Request Leave', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
        <?php foreach ($mine as $r): ?>
            <div class="ews-vac-row"><div>
                <div class="ews-vac-date"><?php echo esc_html($r->type_name . ' · ' . $r->start_date . ' → ' . $r->end_date); ?></div>
                <?php /* translators: %s: number of working days */ ?>
                <div class="ews-vac-meta"><?php echo esc_html(sprintf(__('%s working day(s)', 'workforce-one'), \WorkforceOne\Support\Format::number((float) $r->requested_days)) . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
                <?php if ($r->cancellation_status === 'Pending'): ?><div class="ews-vac-meta"><?php esc_html_e('Cancellation Pending', 'workforce-one'); ?></div>
                <?php elseif ($r->cancellation_status === 'Rejected'): ?><div class="ews-vac-meta"><?php esc_html_e('Cancellation Rejected — cannot be submitted again.', 'workforce-one'); ?></div>
                <?php elseif ($r->can_cancel): ?><div class="ews-vac-actions"><form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_leave_cancel'); ?><input type="hidden" name="action" value="ews_leave_cancel"><input type="hidden" name="request_id" value="<?php echo (int) $r->id; ?>"><button class="button" type="submit"><?php esc_html_e('Request Cancellation', 'workforce-one'); ?></button></form></div>
                <?php elseif ($r->started): ?><div class="ews-vac-meta"><?php esc_html_e('Cancellation unavailable — leave has started.', 'workforce-one'); ?></div>
                <?php endif; ?>
            </div><span class="ews-vac-status <?php echo esc_attr($status_class[strtolower($r->status)] ?? 'ews-vac-rejected'); ?>"><?php echo esc_html($r->status_text); ?></span></div>
        <?php endforeach; ?>
        </div>
        <?php if ($has_more): ?><div class="ews-vac-view-all-wrap"><a class="ews-vac-view-all-button" href="<?php echo esc_url($all_url); ?>"><?php esc_html_e('View all leave requests', 'workforce-one'); ?> <span aria-hidden="true">→</span></a></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($approvals && ($approvals['rows'] || !$approvals['mine'])): ?>
<div class="ews-vac-card" style="margin-top:18px">
    <h3><?php echo esc_html($approvals['mine'] ? __('Leave Requests Awaiting Your Approval', 'workforce-one') : __('Pending Leave Requests', 'workforce-one')); ?></h3>
    <?php if (!$approvals['rows']) echo $empty(__('No Pending Leave Requests', 'workforce-one'), __('You\'re all caught up.', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
    <?php foreach ($approvals['rows'] as $r): ?>
        <div class="ews-vac-row"><div>
            <div class="ews-vac-date"><?php echo esc_html($r->employee_name . ' · ' . $r->type_name . $level($r)); ?></div>
            <div class="ews-vac-meta"><?php echo esc_html($r->start_date . ' → ' . $r->end_date . ' · ' . $days_label($r->requested_days) . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
            <?php echo $decision_form('ews_vacation_request_respond', 'ews_vacation_respond', (int) $r->id, __('Approve', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?>
        </div><span class="ews-vac-status ews-vac-pending"><?php esc_html_e('Pending', 'workforce-one'); ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cancellations && ($cancellations['rows'] || !$cancellations['mine'])): ?>
<div class="ews-vac-card" style="margin-top:18px">
    <h3><?php echo esc_html($cancellations['mine'] ? __('Leave Cancellations Awaiting Your Approval', 'workforce-one') : __('Cancellation Requests', 'workforce-one')); ?></h3>
    <?php if (!$cancellations['rows']) echo $empty(__('No Cancellation Requests', 'workforce-one'), __('You\'re all caught up.', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
    <?php foreach ($cancellations['rows'] as $r): ?>
        <div class="ews-vac-row"><div>
            <div class="ews-vac-date"><?php echo esc_html($r->employee_name . ' · ' . $r->type_name . $level($r)); ?></div>
            <div class="ews-vac-meta"><?php echo esc_html($r->start_date . ' → ' . $r->end_date); ?></div>
            <?php echo $decision_form('ews_leave_cancel_respond', 'ews_leave_cancel_respond', (int) $r->id, __('Approve Cancellation', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?>
        </div><span class="ews-vac-status ews-vac-pending"><?php esc_html_e('Pending', 'workforce-one'); ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($early): ?>
<div class="ews-vac-card" style="margin-top:18px">
    <h3><?php esc_html_e('Early Leave', 'workforce-one'); ?></h3>
    <p style="color:#667085"><?php echo esc_html($early['summary']); ?></p>
    <form method="post" action="<?php echo esc_url($post_url); ?>" id="ews-early-form" data-remaining="<?php echo (int) $early['remaining']; ?>" data-working-days="<?php echo esc_attr(wp_json_encode($working_days)); ?>" data-messages="<?php echo esc_attr(wp_json_encode($early_messages)); ?>">
        <?php wp_nonce_field('ews_early_leave_create'); ?><input type="hidden" name="action" value="ews_early_leave_create">
        <div class="ews-vac-fields">
            <div class="ews-vac-field"><label><?php esc_html_e('Date', 'workforce-one'); ?></label><input type="date" id="ews-early-date" name="work_date" min="<?php echo esc_attr($today); ?>" required></div>
            <div class="ews-vac-field"><label><?php esc_html_e('Duration (minutes)', 'workforce-one'); ?></label><input type="number" id="ews-early-minutes" name="leave_minutes" min="1" max="<?php echo (int) $early['max']; ?>" required></div>
        </div>
        <div class="ews-vac-field" style="margin-top:12px"><label><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea name="reason" rows="2"></textarea></div>
        <button class="ews-btn ews-vac-submit" type="submit"><?php esc_html_e('Request Early Leave', 'workforce-one'); ?></button>
    </form>
</div>
<?php endif; ?>

<?php if ($pending_early): ?>
<div class="ews-vac-card" style="margin-top:18px">
    <h3><?php esc_html_e('Pending Early Leave', 'workforce-one'); ?></h3>
    <?php foreach ($pending_early as $r): ?>
        <div class="ews-vac-row"><div>
            <div class="ews-vac-date"><?php echo esc_html($r->employee_name . ' · ' . $r->work_date); ?></div>
            <div class="ews-vac-meta"><?php echo esc_html($r->duration . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
            <?php echo $decision_form('ews_early_leave_respond', 'ews_early_leave_respond', (int) $r->id, __('Approve', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?>
        </div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
