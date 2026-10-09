<?php
/**
 * Employee app: Leave (with Early Leave). Styles: assets/css/app-leave.css (new look, 3.31.51);
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
 * @var array{mine:bool,rows:object[]}|null $approvals      leave requests I decide (null = none); ->om: what approving does to the office minimum (3.31.77) or null
 * @var array{mine:bool,rows:object[]}|null $cancellations  cancellation requests I decide
 * @var array{max:int,remaining:int,summary:string}|null $early
 * @var object[]|null $pending_early   (+ duration) for managers of time
 * @var int[] $working_days
 * @var string $post_url
 * @var callable $empty                 ews_empty_state(title, text, url, label)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $decision_form() and $empty() escape their output.
$decision_form = static function (string $action, string $nonce, int $id, string $approve_label) use ($post_url): string {
    return '<div class="ews-vac-actions"><form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field($nonce, '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="request_id" value="' . $id . '">'
        . '<button class="button button-primary wfo-btn-approve" name="decision" value="approve">' . Icons::svg('check', 16, 2.4) . esc_html($approve_label) . '</button><button class="button wfo-btn-reject" name="decision" value="reject">' . Icons::svg('close', 16, 2.2) . esc_html__('Reject', 'workforce-one') . '</button></form></div>';
};
$level = static function (object $r): string {
    return isset($r->step_order) ? ' · ' . __('Level', 'workforce-one') . ' ' . (int) $r->step_order : '';
};
/* translators: %s: number of days */
$days_label = static function ($n): string { return sprintf(__('%s day(s)', 'workforce-one'), \WorkforceOne\Support\Format::number((float) $n)); };
$status_class = ['approved' => 'ews-vac-approved', 'pending' => 'ews-vac-pending', 'cancelled' => 'ews-vac-cancelled'];
$status_icon = ['approved' => 'check', 'pending' => 'overtime', 'cancelled' => 'close'];
$range = static function (string $a, string $b): string {
    $fa = date_i18n('d M', strtotime($a));
    return $a === $b ? date_i18n('d M Y', strtotime($a)) : $fa . ' – ' . date_i18n('d M Y', strtotime($b));
};
$early_messages = [
    'title' => __('Early Leave Not Available', 'workforce-one'), 'ok' => __('OK', 'workforce-one'), 'close' => __('Close', 'workforce-one'),
    'date' => __('Please select a date for Early Leave.', 'workforce-one'),
    'future' => __('Early Leave must be requested for a future date.', 'workforce-one'),
    'working_day' => __('Early Leave is available only on a configured working day.', 'workforce-one'),
    'max' => __('The requested Early Leave exceeds the maximum duration allowed for one request.', 'workforce-one'),
    'remaining' => __('The requested Early Leave exceeds the remaining monthly allowance.', 'workforce-one'),
];
?>
<div class="ews-page ews-vac wfo-leave">
<p class="wfo-leave-lead"><?php esc_html_e('Request time off, track your balance and follow approvals in one place. Leave Types marked “No balance deduction” are recorded without consuming leave balance.', 'workforce-one'); ?></p>

<?php if ($emp): ?>
<section class="wfo-balances" aria-label="<?php esc_attr_e('Leave balance', 'workforce-one'); ?>">
    <?php foreach ($types as $i => $t): ?><div class="ews-bal wfo-balance tone-<?php echo (int) ($i % 4); ?>"><span class="wfo-balance-icon"><?php echo Icons::svg('leave', 20); ?></span><strong><?php echo esc_html($t['deducts'] ? $t['available'] : '—'); ?></strong><span><?php echo esc_html($t['name']); ?> <?php esc_html_e('Available', 'workforce-one'); ?></span><?php if (!$t['deducts']): ?><small><?php esc_html_e('No balance deduction', 'workforce-one'); ?></small><?php endif; ?></div><?php endforeach; ?>
</section>

<div class="ews-vac-grid wfo-leave-grid">
    <div class="ews-vac-card wfo-leave-card">
        <div class="wfo-card-title"><span class="wfo-card-icon"><?php echo Icons::svg('leave', 20); ?></span><h3><?php esc_html_e('Request Leave', 'workforce-one'); ?></h3></div>
        <p class="wfo-help"><?php esc_html_e('Select a Leave Type and future date range. Working days are calculated automatically.', 'workforce-one'); ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_vacation_request_create'); ?><input type="hidden" name="action" value="ews_vacation_request_create">
            <div class="ews-vac-field"><label for="ews-vac-type"><?php esc_html_e('Leave Type', 'workforce-one'); ?></label><select id="ews-vac-type" name="leave_type_id" required>
                <?php foreach ($types as $t): ?>
                    <?php /* translators: %s: available days */ ?>
                    <option value="<?php echo (int) $t['id']; ?>"><?php echo esc_html($t['name'] . ' — ' . ($t['deducts'] ? sprintf(__('Available: %s', 'workforce-one'), $t['available']) : __('No balance deduction', 'workforce-one'))); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="ews-vac-fields">
                <div class="ews-vac-field"><label for="ews-vac-start"><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-vac-start" type="date" name="start_date" min="<?php echo esc_attr($today); ?>" required></div>
                <div class="ews-vac-field"><label for="ews-vac-end"><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-vac-end" type="date" name="end_date" min="<?php echo esc_attr($today); ?>" required></div>
            </div>
            <?php /* translators: %d: number of working days */ ?>
            <div id="ews-vac-days" class="ews-vac-count" role="status" data-working-days="<?php echo esc_attr(wp_json_encode($working_days)); ?>" data-invalid="<?php esc_attr_e('Select a valid date range.', 'workforce-one'); ?>" data-count="<?php esc_attr_e('%d working day(s) requested.', 'workforce-one'); ?>"><?php esc_html_e('Select dates to calculate working days.', 'workforce-one'); ?></div>
            <div class="ews-vac-field"><label for="ews-vac-reason"><?php esc_html_e('Reason', 'workforce-one'); ?> <span class="wfo-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></label><textarea id="ews-vac-reason" name="reason" rows="3"></textarea></div>
            <button class="ews-btn ews-vac-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2); ?><span><?php esc_html_e('Submit Leave Request', 'workforce-one'); ?></span></button>
        </form>
    </div>

    <div class="ews-vac-card wfo-leave-card">
        <div class="ews-vac-section-head wfo-card-title"><span class="wfo-card-icon is-slate"><?php echo Icons::svg('reports', 20); ?></span><h3><?php esc_html_e('My Leave Requests', 'workforce-one'); ?></h3>
            <?php if ($show_all): ?><a class="ews-vac-view-all" href="<?php echo esc_url($recent_url); ?>"><?php esc_html_e('Back to recent requests', 'workforce-one'); ?></a><?php endif; ?>
        </div>
        <div class="wfo-req-list">
        <?php if (!$mine) echo $empty(__('No Leave Requests Yet', 'workforce-one'), __('You haven\'t submitted any leave requests yet.', 'workforce-one'), $request_url, __('Request Leave', 'workforce-one')); ?>
        <?php foreach ($mine as $r): $sk = strtolower($r->status); ?>
            <div class="ews-vac-row"><div class="wfo-req-main">
                <div class="ews-vac-date"><strong><?php echo esc_html($r->type_name); ?></strong><span dir="auto"><?php echo esc_html($range((string) $r->start_date, (string) $r->end_date)); ?></span></div>
                <?php /* translators: %s: number of working days */ ?>
                <div class="ews-vac-meta"><?php echo esc_html(sprintf(__('%s working day(s)', 'workforce-one'), \WorkforceOne\Support\Format::number((float) $r->requested_days)) . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
                <?php if ($r->cancellation_status === 'Pending'): ?><div class="ews-vac-meta wfo-note is-amber"><?php echo Icons::svg('overtime', 14, 2); ?><?php esc_html_e('Cancellation Pending', 'workforce-one'); ?></div>
                <?php elseif ($r->cancellation_status === 'Rejected'): ?><div class="ews-vac-meta wfo-note"><?php echo Icons::svg('alert', 14, 2); ?><?php esc_html_e('Cancellation Rejected — cannot be submitted again.', 'workforce-one'); ?></div>
                <?php elseif ($r->can_cancel): ?><div class="ews-vac-actions"><form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_leave_cancel'); ?><input type="hidden" name="action" value="ews_leave_cancel"><input type="hidden" name="request_id" value="<?php echo (int) $r->id; ?>"><button class="button wfo-btn-quiet" type="submit"><?php echo Icons::svg('close', 14, 2.2); ?><?php esc_html_e('Request Cancellation', 'workforce-one'); ?></button></form></div>
                <?php elseif ($r->started): ?><div class="ews-vac-meta wfo-note"><?php echo Icons::svg('alert', 14, 2); ?><?php esc_html_e('Cancellation unavailable — leave has started.', 'workforce-one'); ?></div>
                <?php endif; ?>
            </div><span class="ews-vac-status <?php echo esc_attr($status_class[$sk] ?? 'ews-vac-rejected'); ?>"><?php echo Icons::svg($status_icon[$sk] ?? 'close', 14, 2.2); ?><?php echo esc_html($r->status_text); ?></span></div>
        <?php endforeach; ?>
        </div>
        <?php if ($has_more): ?><div class="ews-vac-view-all-wrap"><a class="ews-vac-view-all-button" href="<?php echo esc_url($all_url); ?>"><?php esc_html_e('View all leave requests', 'workforce-one'); ?><?php echo Icons::svg('chevron', 16, 2); ?></a></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($approvals && ($approvals['rows'] || !$approvals['mine'])): ?>
<div class="ews-vac-card wfo-leave-card wfo-decide">
    <div class="wfo-card-title"><span class="wfo-card-icon is-amber"><?php echo Icons::svg('tasks', 20); ?></span><h3><?php echo esc_html($approvals['mine'] ? __('Leave Requests Awaiting Your Approval', 'workforce-one') : __('Pending Leave Requests', 'workforce-one')); ?></h3></div>
    <?php if (!$approvals['rows']) echo $empty(__('No Pending Leave Requests', 'workforce-one'), __('You\'re all caught up.', 'workforce-one')); ?>
    <?php foreach ($approvals['rows'] as $r): ?>
        <div class="ews-vac-row"><div class="wfo-req-main">
            <div class="ews-vac-date"><strong><?php echo esc_html($r->employee_name); ?></strong><span><?php echo esc_html($r->type_name . $level($r)); ?></span></div>
            <div class="ews-vac-meta"><?php echo esc_html($range((string) $r->start_date, (string) $r->end_date) . ' · ' . $days_label($r->requested_days) . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
            <?php if (!empty($r->om)): ?><div class="wfo-om-note"><?php echo Icons::svg('office', 18, 2.2); ?><div><strong><?php echo esc_html(sprintf(/* translators: 1: day, 2: people in the office, 3: minimum */ __('Approving makes %1$s %2$d of %3$d in the office', 'workforce-one'), date_i18n('l j F', strtotime($r->om['date'])), $r->om['office'], $r->om['min'])); ?></strong><?php
                if ($r->om['team'] !== '') echo esc_html(sprintf(/* translators: 1: team, 2: in the office, 3: the team's share */ __('%1$s would have %2$d of their share of %3$d.', 'workforce-one'), $r->om['team'], $r->om['team_office'], $r->om['share']) . ' ');
                esc_html_e('This is only a warning; you can still approve.', 'workforce-one'); ?></div></div><?php endif; ?>
            <?php echo $decision_form('ews_vacation_request_respond', 'ews_vacation_respond', (int) $r->id, __('Approve', 'workforce-one')); ?>
        </div><span class="ews-vac-status ews-vac-pending"><?php echo Icons::svg('overtime', 14, 2.2); ?><?php esc_html_e('Pending', 'workforce-one'); ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($cancellations && ($cancellations['rows'] || !$cancellations['mine'])): ?>
<div class="ews-vac-card wfo-leave-card wfo-decide">
    <div class="wfo-card-title"><span class="wfo-card-icon is-rose"><?php echo Icons::svg('close', 20); ?></span><h3><?php echo esc_html($cancellations['mine'] ? __('Leave Cancellations Awaiting Your Approval', 'workforce-one') : __('Cancellation Requests', 'workforce-one')); ?></h3></div>
    <?php if (!$cancellations['rows']) echo $empty(__('No Cancellation Requests', 'workforce-one'), __('You\'re all caught up.', 'workforce-one')); ?>
    <?php foreach ($cancellations['rows'] as $r): ?>
        <div class="ews-vac-row"><div class="wfo-req-main">
            <div class="ews-vac-date"><strong><?php echo esc_html($r->employee_name); ?></strong><span><?php echo esc_html($r->type_name . $level($r)); ?></span></div>
            <div class="ews-vac-meta"><?php echo esc_html($range((string) $r->start_date, (string) $r->end_date)); ?></div>
            <?php echo $decision_form('ews_leave_cancel_respond', 'ews_leave_cancel_respond', (int) $r->id, __('Approve Cancellation', 'workforce-one')); ?>
        </div><span class="ews-vac-status ews-vac-pending"><?php echo Icons::svg('overtime', 14, 2.2); ?><?php esc_html_e('Pending', 'workforce-one'); ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($early): ?>
<div class="ews-vac-card wfo-leave-card">
    <div class="wfo-card-title"><span class="wfo-card-icon is-teal"><?php echo Icons::svg('logout', 20); ?></span><h3><?php esc_html_e('Early Leave', 'workforce-one'); ?></h3></div>
    <p class="wfo-help"><?php echo esc_html($early['summary']); ?></p>
    <form method="post" action="<?php echo esc_url($post_url); ?>" id="ews-early-form" data-remaining="<?php echo (int) $early['remaining']; ?>" data-working-days="<?php echo esc_attr(wp_json_encode($working_days)); ?>" data-messages="<?php echo esc_attr(wp_json_encode($early_messages)); ?>">
        <?php wp_nonce_field('ews_early_leave_create'); ?><input type="hidden" name="action" value="ews_early_leave_create">
        <div class="ews-vac-fields">
            <div class="ews-vac-field"><label for="ews-early-date"><?php esc_html_e('Date', 'workforce-one'); ?></label><input type="date" id="ews-early-date" name="work_date" min="<?php echo esc_attr($today); ?>" required></div>
            <div class="ews-vac-field"><label for="ews-early-minutes"><?php esc_html_e('Duration (minutes)', 'workforce-one'); ?></label><input type="number" id="ews-early-minutes" name="leave_minutes" min="1" max="<?php echo (int) $early['max']; ?>" required></div>
        </div>
        <div class="ews-vac-field"><label for="ews-early-reason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="ews-early-reason" name="reason" rows="2"></textarea></div>
        <button class="ews-btn ews-vac-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2); ?><span><?php esc_html_e('Request Early Leave', 'workforce-one'); ?></span></button>
    </form>
</div>
<?php endif; ?>

<?php if ($pending_early): ?>
<div class="ews-vac-card wfo-leave-card wfo-decide">
    <div class="wfo-card-title"><span class="wfo-card-icon is-amber"><?php echo Icons::svg('tasks', 20); ?></span><h3><?php esc_html_e('Pending Early Leave', 'workforce-one'); ?></h3></div>
    <?php foreach ($pending_early as $r): ?>
        <div class="ews-vac-row"><div class="wfo-req-main">
            <div class="ews-vac-date"><strong><?php echo esc_html($r->employee_name); ?></strong><span><?php echo esc_html(date_i18n('d M Y', strtotime((string) $r->work_date))); ?></span></div>
            <div class="ews-vac-meta"><?php echo esc_html($r->duration . ($r->reason ? ' · ' . $r->reason : '')); ?></div>
            <?php echo $decision_form('ews_early_leave_respond', 'ews_early_leave_respond', (int) $r->id, __('Approve', 'workforce-one')); ?>
        </div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
