<?php
/**
 * Employee app: Overtime, look B (3.31.69): a summary card, the requests grouped (waiting for the
 * manager / decided) and the request form in a sheet opened by the yellow button. A manager sees the
 * requests waiting for a decision first. Styles: assets/css/app-requests.css; scripts:
 * assets/js/sheet.js (the sheet) and assets/js/overtime.js (Today / Tomorrow and the live duration).
 * Results of the actions are shown by the layout's pop-up, not here.
 *
 * @var object|null $emp
 * @var string $today
 * @var string $tomorrow
 * @var object[] $mine                       my requests (+ duration, status_text)
 * @var array{month:string,approved:string,approved_minutes:int,waiting:int,waiting_time:string,month_count:int}|null $summary
 * @var array{mine:bool,rows:object[]}|null $approvals   requests I decide (+ duration)
 * @var string $request_url
 * @var string $post_url
 * @var callable $empty                      ews_empty_state(title, text, url, label)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $decision() and $row() escape their output.
$decision = static function (int $id, string $value, string $label, string $class, string $icon) use ($post_url): string {
    return '<form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field('ews_overtime_respond', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="' . $id . '"><input type="hidden" name="decision" value="' . esc_attr($value) . '">'
        . '<button class="wfo-rq-btn ' . esc_attr($class) . '" type="submit">' . Icons::svg($icon, 16, 2.2) . esc_html($label) . '</button></form>';
};
$day = static function (string $date): string { return date_i18n('D d M', strtotime($date)); };
$span = static function (object $r): string { return substr((string) $r->start_time, 0, 5) . ' → ' . substr((string) $r->end_time, 0, 5); };
$row = static function (object $r) use ($day, $span): string {
    $state = strtolower((string) $r->status);
    $class = in_array($state, ['pending', 'approved', 'rejected'], true) ? 'is-' . $state : '';
    return '<div class="wfo-rq-row"><span class="wfo-rq-ico" aria-hidden="true">' . Icons::svg('overtime', 18, 2) . '</span><div class="wfo-rq-main">'
        . '<p class="wfo-rq-title">' . esc_html($day((string) $r->overtime_date) . ' · ' . $r->duration) . '</p>'
        . '<p class="wfo-rq-meta">' . esc_html($span($r) . ($r->reason !== '' && $r->reason !== null ? ' · ' . $r->reason : '')) . '</p></div>'
        . '<span class="wfo-rq-state ' . esc_attr($class) . '">' . esc_html($r->status_text) . '</span></div>';
};
$initials = static function (string $name): string {
    $parts = preg_split('/[\s._-]+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice(array_values(array_filter($parts)), 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '·';
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
$waiting = array_values(array_filter($mine, static function ($r) { return strtolower((string) $r->status) === 'pending'; }));
$decided = array_values(array_filter($mine, static function ($r) { return strtolower((string) $r->status) !== 'pending'; }));
$reopen = isset($_GET['overtime_error']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: show the form again after a refused request
?>
<div class="ews-page wfo-rq wfo-ot<?php echo ($approvals && $emp) ? '' : ' is-narrow'; ?>">
<?php if ($emp && $summary): ?>
    <section class="wfo-rq-hero" aria-labelledby="wfo-ot-total">
        <div class="wfo-rq-hero-main">
            <p class="wfo-rq-hero-label"><?php /* translators: %s: month name */ printf(esc_html__('%s · approved overtime', 'workforce-one'), esc_html($summary['month'])); ?></p>
            <p class="wfo-rq-hero-value" id="wfo-ot-total"><?php echo esc_html($summary['approved_minutes'] ? $summary['approved'] : __('None yet', 'workforce-one')); ?></p>
            <div class="wfo-rq-hero-chips">
                <?php if ($summary['waiting']): ?><span class="wfo-rq-hero-chip is-wait"><?php echo Icons::svg('clock', 14, 2.2); ?><?php /* translators: 1: number of requests, 2: duration */ printf(esc_html(_n('%1$d waiting · %2$s', '%1$d waiting · %2$s', $summary['waiting'], 'workforce-one')), (int) $summary['waiting'], esc_html($summary['waiting_time'])); ?></span><?php endif; ?>
                <span class="wfo-rq-hero-chip"><?php /* translators: %d: number of overtime requests this month */ printf(esc_html(_n('%d request this month', '%d requests this month', $summary['month_count'], 'workforce-one')), (int) $summary['month_count']); ?></span>
            </div>
        </div>
        <button type="button" class="wfo-rq-new" data-wfo-sheet="wfo-ot-sheet" aria-haspopup="dialog"><?php echo Icons::svg('plus', 18, 2.4); ?><?php esc_html_e('Request Overtime', 'workforce-one'); ?></button>
    </section>
<?php endif; ?>

<div class="<?php echo ($approvals && $emp) ? 'wfo-rq-layout' : 'wfo-rq-solo wfo-rq-col'; ?>">
<?php if ($approvals): ?>
    <section class="wfo-rq-card" aria-labelledby="wfo-ot-decide">
        <div class="wfo-rq-card-head"><div>
            <h3 id="wfo-ot-decide"><?php echo esc_html($approvals['mine'] ? __('My Pending Overtime Approvals', 'workforce-one') : __('Pending Overtime Requests', 'workforce-one')); ?></h3>
            <p><?php echo esc_html($approvals['mine'] ? __('Requests currently assigned to you by the approval workflow.', 'workforce-one') : __('Review overtime requests. Any Manager can approve or reject.', 'workforce-one')); ?></p>
        </div><?php if ($approvals['rows']): ?><span class="wfo-rq-state is-pending"><?php /* translators: %d: number of requests waiting */ printf(esc_html(_n('%d waiting', '%d waiting', count($approvals['rows']), 'workforce-one')), count($approvals['rows'])); ?></span><?php endif; ?></div>
        <?php if (!$approvals['rows']): ?>
            <div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('check', 24, 2.2); ?></span><strong><?php echo esc_html($approvals['mine'] ? __('No Pending Overtime Approvals', 'workforce-one') : __('No Pending Overtime Requests', 'workforce-one')); ?></strong><p><?php esc_html_e('You\'re all caught up.', 'workforce-one'); ?></p></div>
        <?php endif; ?>
        <?php foreach ($approvals['rows'] as $r): $level = isset($r->step_order) ? __('Level', 'workforce-one') . ' ' . (int) $r->step_order : ''; ?>
            <div class="wfo-rq-row has-actions">
                <span class="wfo-rq-ico is-person" aria-hidden="true"><?php echo esc_html($initials((string) $r->employee_name)); ?></span>
                <div class="wfo-rq-main">
                    <p class="wfo-rq-title"><?php echo esc_html($r->employee_name); ?><?php if ($level !== ''): ?> <span class="wfo-rq-state is-level"><?php echo esc_html($level); ?></span><?php endif; ?></p>
                    <p class="wfo-rq-meta"><?php echo esc_html($day((string) $r->overtime_date) . ' · ' . $span($r)); ?> · <strong><?php echo esc_html($r->duration); ?></strong></p>
                    <?php if ((string) $r->reason !== ''): ?><p class="wfo-rq-quote">“<?php echo esc_html($r->reason); ?>”</p><?php endif; ?>
                </div>
                <div class="wfo-rq-actions"><?php echo $decision((int) $r->id, 'reject', __('Reject', 'workforce-one'), 'is-no', 'close') . $decision((int) $r->id, 'approve', __('Approve', 'workforce-one'), 'is-yes', 'check'); ?></div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($emp): ?>
    <div class="wfo-rq-col">
    <?php if (!$mine): ?>
        <section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('overtime', 24, 2); ?></span><strong><?php esc_html_e('No Overtime Requests Yet', 'workforce-one'); ?></strong><p><?php esc_html_e('Request overtime in advance: your manager approves it before you work the extra hours.', 'workforce-one'); ?></p></div></section>
    <?php endif; ?>
    <?php if ($waiting): ?>
        <h3 class="wfo-rq-group"><?php esc_html_e('Waiting for your manager', 'workforce-one'); ?></h3>
        <div class="wfo-rq-card"><?php foreach ($waiting as $r) echo $row($r); ?></div>
    <?php endif; ?>
    <?php if ($decided): ?>
        <h3 class="wfo-rq-group"><?php esc_html_e('Decided', 'workforce-one'); ?></h3>
        <div class="wfo-rq-card"><?php foreach ($decided as $r) echo $row($r); ?></div>
    <?php endif; ?>
    </div>

    <dialog class="wfo-sheet" id="wfo-ot-sheet" aria-labelledby="wfo-ot-sheet-title"<?php echo $reopen ? ' data-wfo-sheet-start' : ''; ?>>
        <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_overtime_request_create'); ?><input type="hidden" name="action" value="ews_overtime_request_create">
            <div class="wfo-sheet-grip" aria-hidden="true"></div>
            <div class="wfo-sheet-head"><h2 id="wfo-ot-sheet-title"><?php esc_html_e('Request Overtime', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
            <div class="wfo-rq-field" role="group" aria-labelledby="wfo-ot-day-label"><span class="wfo-rq-label" id="wfo-ot-day-label"><?php esc_html_e('Date', 'workforce-one'); ?></span>
                <div class="wfo-rq-days">
                    <button type="button" data-ews-ot-day="<?php echo esc_attr($today); ?>" aria-pressed="false"><?php esc_html_e('Today', 'workforce-one'); ?></button>
                    <button type="button" data-ews-ot-day="<?php echo esc_attr($tomorrow); ?>" aria-pressed="false"><?php esc_html_e('Tomorrow', 'workforce-one'); ?></button>
                    <input id="ews-ot-date" type="date" name="overtime_date" min="<?php echo esc_attr($today); ?>" required aria-labelledby="wfo-ot-day-label">
                </div>
            </div>
            <div class="wfo-rq-two">
                <div class="wfo-rq-field"><label for="ews-ot-start"><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-ot-start" type="time" name="start_time" required></div>
                <div class="wfo-rq-field"><label for="ews-ot-end"><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-ot-end" type="time" name="end_time" required></div>
            </div>
            <p id="ews-ot-count" class="wfo-rq-total" aria-live="polite" data-messages="<?php echo esc_attr(wp_json_encode($counter)); ?>"><?php echo Icons::svg('clock', 16, 2.2); ?><span><?php echo esc_html($counter['empty']); ?></span></p>
            <div class="wfo-rq-field"><label for="wfo-ot-reason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="wfo-ot-reason" name="reason" rows="3" placeholder="<?php esc_attr_e('Why is overtime required?', 'workforce-one'); ?>" required></textarea></div>
            <button class="wfo-sheet-submit" type="submit" data-fwd><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Submit Overtime Request', 'workforce-one'); ?></button>
            <p class="wfo-sheet-note"><?php esc_html_e('Submit your overtime request before you work the extra hours. Manager approval is required.', 'workforce-one'); ?></p>
        </form>
    </dialog>
<?php endif; ?>
</div>
</div>
