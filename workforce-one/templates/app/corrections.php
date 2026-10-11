<?php
/**
 * Employee app: Corrections (3.31.74). The employee's requests (waiting / decided) under a summary
 * card, and for a manager or HR the requests they decide, each with what the system recorded that
 * day (the evidence, read-only) and approve / reject with a note. Look of the Overtime page
 * (app-requests.css) plus assets/css/app-corrections.css.
 *
 * @var object|null $emp
 * @var object[] $mine
 * @var object[] $decide       requests to decide (+ employee_name, evidence)
 * @var array<string,mixed> $settings
 * @var int $used
 * @var string $month
 * @var string $resets
 * @var string $post_url
 * @var callable $change        cx_change_text($c)
 * @var callable $type_label
 * @var callable $status_label
 * @var callable $day_label
 * @var string $sheet          templates/app/correction-sheet.php
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; the rows escape their own text
$icons = ['out' => 'logout', 'in' => 'login', 'time' => 'clock', 'day' => 'calendar'];
$state = ['pending' => 'is-pending', 'pending_hr' => 'is-pending', 'approved' => 'is-approved', 'rejected' => 'is-rejected'];
$limit = (int) $settings['monthly_limit'];
$waiting = array_values(array_filter($mine, static function ($c) { return in_array($c->status, ['pending', 'pending_hr'], true); }));
$decided = array_values(array_filter($mine, static function ($c) { return !in_array($c->status, ['pending', 'pending_hr'], true); }));
$initials = static function (string $name): string {
    $out = '';
    foreach (array_slice(array_values(array_filter(preg_split('/[\s._-]+/u', trim($name)) ?: [])), 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '·';
};
$row = static function ($c) use ($icons, $state, $change, $type_label, $status_label, $day_label): string {
    $quote = '';
    if ($c->decision_note !== null && $c->decision_note !== '' && $c->status === 'rejected') $quote = '<p class="wfo-rq-quote">' . esc_html($c->decision_note) . '</p>';
    $meta = $change($c) . ($c->status === 'pending_hr' ? ' · ' . __('with HR', 'workforce-one') : '');
    return '<div class="wfo-rq-row"><span class="wfo-rq-ico" aria-hidden="true">' . Icons::svg($icons[$c->type] ?? 'edit', 18, 2) . '</span><div class="wfo-rq-main">'
        . '<p class="wfo-rq-title">' . esc_html($day_label($c->work_date) . ' · ' . $type_label($c->type)) . '</p><p class="wfo-rq-meta">' . esc_html($meta) . '</p>' . $quote . '</div>'
        . '<span class="wfo-rq-state ' . esc_attr($state[$c->status] ?? '') . '">' . esc_html($status_label($c->status)) . '</span></div>';
};
?>
<div class="ews-page wfo-rq wfo-cx<?php echo ($decide && $emp) ? '' : ' is-narrow'; ?>">
<?php if ($emp): ?>
    <section class="wfo-rq-hero" aria-labelledby="wfo-cx-total">
        <div class="wfo-rq-hero-main">
            <p class="wfo-rq-hero-label"><?php /* translators: %s: month name */ printf(esc_html__('%s · corrections', 'workforce-one'), esc_html($month)); ?></p>
            <p class="wfo-rq-hero-value" id="wfo-cx-total"><?php echo esc_html($limit > 0 ? sprintf(/* translators: 1: used, 2: limit */ __('%1$d of %2$d', 'workforce-one'), $used, $limit) : (string) $used); ?></p>
            <div class="wfo-rq-hero-chips">
                <?php if ($waiting): ?><span class="wfo-rq-hero-chip is-wait"><?php echo Icons::svg('clock', 14, 2.2); ?><?php /* translators: %d: number of requests */ printf(esc_html(_n('%d waiting', '%d waiting', count($waiting), 'workforce-one')), count($waiting)); ?></span><?php endif; ?>
                <?php if ($limit > 0 && $used >= $limit): ?><span class="wfo-rq-hero-chip is-wait"><?php echo Icons::svg('alert', 14, 2.2); ?><?php echo esc_html($settings['above_limit'] === 'hr' ? __('Limit reached · new requests go to HR', 'workforce-one') : __('Limit reached', 'workforce-one')); ?></span><?php endif; ?>
                <?php if ($limit > 0): ?><span class="wfo-rq-hero-chip"><?php /* translators: %s: date */ printf(esc_html__('Resets on %s', 'workforce-one'), esc_html($resets)); ?></span><?php endif; ?>
            </div>
        </div>
        <button type="button" class="wfo-rq-new" data-cx-open data-type="out" aria-haspopup="dialog"><?php echo Icons::svg('plus', 18, 2.4); ?><?php esc_html_e('Request correction', 'workforce-one'); ?></button>
    </section>
<?php endif; ?>

<div class="<?php echo ($decide && $emp) ? 'wfo-rq-layout' : 'wfo-rq-solo wfo-rq-col'; ?>">
<?php if ($decide): ?>
    <section class="wfo-rq-card" aria-labelledby="wfo-cx-decide">
        <div class="wfo-rq-card-head"><div><h3 id="wfo-cx-decide"><?php esc_html_e('Corrections to approve', 'workforce-one'); ?></h3><p><?php esc_html_e('Your team\'s requests, each with what the system knows about the day.', 'workforce-one'); ?></p></div>
            <span class="wfo-rq-state is-pending"><?php /* translators: %d: number of requests */ printf(esc_html(_n('%d waiting', '%d waiting', count($decide), 'workforce-one')), count($decide)); ?></span></div>
        <?php foreach ($decide as $i => $c): $ev = $c->evidence; ?>
            <div class="wfo-cx-req">
                <div class="wfo-cx-req-top"><span class="wfo-rq-ico is-person" aria-hidden="true"><?php echo esc_html($initials((string) $c->employee_name)); ?></span>
                    <div class="wfo-rq-main"><p class="wfo-rq-title"><?php echo esc_html($c->employee_name); ?></p><p class="wfo-rq-meta"><?php echo esc_html($day_label($c->work_date) . ' · ' . $type_label($c->type)); ?></p></div>
                    <span class="wfo-rq-state is-level"><?php echo esc_html($c->status === 'pending_hr' ? __('HR', 'workforce-one') : (isset($c->step_order) ? __('Level', 'workforce-one') . ' ' . (int) $c->step_order : __('Manager', 'workforce-one'))); ?></span></div>
                <p class="wfo-cx-ask"><b><?php echo esc_html($change($c)); ?></b><br>“<?php echo esc_html((string) $c->reason); ?>”</p>
                <?php if ((int) $c->earlier || (int) $c->above_limit): ?><p class="wfo-cx-level2"><?php echo Icons::svg('alert', 16, 2.2); ?><?php
                    echo esc_html((int) $c->earlier ? __('Moves Sign In earlier, which removes lateness: HR approves it too.', 'workforce-one') : __('Above the monthly limit: HR approves it too.', 'workforce-one')); ?></p><?php endif; ?>
                <details class="wfo-cx-evidence"<?php echo $i === 0 ? ' open' : ''; ?>>
                    <summary><?php echo Icons::svg('shield', 16, 2); ?><?php esc_html_e('What the system knows about the day', 'workforce-one'); ?><small><?php esc_html_e('read-only', 'workforce-one'); ?></small></summary>
                    <ul class="wfo-cx-tl">
                        <?php if (!$ev['events']): ?><li class="is-bad"><time>—</time><div><b><?php esc_html_e('Nothing recorded that day', 'workforce-one'); ?></b></div></li><?php endif; ?>
                        <?php foreach ($ev['events'] as $e):
                            $what = $e['type'] === 'sign_out' ? __('Signed out', 'workforce-one') : ($e['type'] === 'sign_in' || $e['type'] === 'late_sign_in' ? __('Signed in', 'workforce-one') : $e['type']);
                            $bits = [];
                            if ($e['source'] === 'correction') $bits[] = __('a correction', 'workforce-one');
                            elseif ($e['source'] === 'qr') $bits[] = __('QR', 'workforce-one');
                            elseif ($e['source'] === 'auto') $bits[] = __('automatic', 'workforce-one');
                            if ($e['place'] !== '') $bits[] = $e['place'];
                            if ($e['location_status'] === 'inside') $bits[] = $e['distance'] !== null ? sprintf(/* translators: %d: metres */ __('inside the area (%d m)', 'workforce-one'), $e['distance']) : __('inside the area', 'workforce-one');
                            elseif ($e['location_status'] === 'outside') $bits[] = $e['distance'] !== null ? sprintf(/* translators: %d: metres */ __('outside the area (%d m)', 'workforce-one'), $e['distance']) : __('outside the area', 'workforce-one');
                            if ($e['integrity'] === 'suspicious' || $e['integrity'] === 'unreliable') $bits[] = __('device check: needs a look', 'workforce-one');
                            elseif ($e['integrity'] === 'verified') $bits[] = __('device check passed', 'workforce-one');
                            if ($e['superseded']) $bits[] = __('replaced by a correction', 'workforce-one');
                            $cls = $e['location_status'] === 'outside' ? 'is-warn' : ($e['superseded'] ? '' : 'is-ok');
                        ?><li class="<?php echo esc_attr($cls); ?>"><time dir="ltr"><?php echo esc_html($e['time']); ?></time><div><b><?php echo esc_html($what); ?></b><?php echo esc_html(implode(' · ', $bits)); ?></div></li><?php endforeach; ?>
                        <?php foreach ($ev['presence'] as $p): ?><li class="<?php echo $p['status'] === 'verified' ? 'is-ok' : 'is-warn'; ?>"><time dir="ltr"><?php echo esc_html($p['time']); ?></time><div><b><?php echo esc_html($p['status'] === 'verified' ? __('Presence check: passed', 'workforce-one') : __('Presence check: not answered', 'workforce-one')); ?></b><?php echo esc_html($p['verified'] !== '' ? sprintf(/* translators: %s: time */ __('answered at %s', 'workforce-one'), $p['verified']) . ($p['kiosk'] ? ' · ' . __('kiosk', 'workforce-one') : '') : ''); ?></div></li><?php endforeach; ?>
                        <?php if ($ev['events'] && !$ev['has_out']): ?><li class="is-bad"><time>—</time><div><b><?php esc_html_e('No Sign Out recorded', 'workforce-one'); ?></b><?php esc_html_e('and no kiosk or QR event', 'workforce-one'); ?></div></li><?php endif; ?>
                        <?php if ($ev['events'] && !$ev['has_in']): ?><li class="is-bad"><time>—</time><div><b><?php esc_html_e('No Sign In recorded', 'workforce-one'); ?></b></div></li><?php endif; ?>
                        <?php if ($ev['last']): ?><li class="is-ok"><time dir="ltr"><?php echo esc_html($ev['last']['time']); ?></time><div><b><?php esc_html_e('Last device location', 'workforce-one'); ?></b><?php echo esc_html($ev['last']['status'] === 'inside' ? __('at the work location', 'workforce-one') : ($ev['last']['status'] === 'outside' ? __('away from the work location', 'workforce-one') : '')); ?></div></li><?php endif; ?>
                    </ul>
                    <div class="wfo-cx-facts">
                        <div><?php esc_html_e('Schedule', 'workforce-one'); ?><strong><?php echo esc_html($ev['schedule'] !== '' ? __($ev['schedule'], 'workforce-one') : __('Not set', 'workforce-one')); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText ?></strong></div>
                        <div><?php esc_html_e('Shift', 'workforce-one'); ?><strong dir="ltr"><?php echo esc_html($ev['shift']); ?></strong></div>
                        <div class="<?php echo $ev['limit'] > 0 && $ev['month_count'] >= $ev['limit'] ? 'is-warn' : ''; ?>"><?php esc_html_e('Corrections this month', 'workforce-one'); ?><strong><?php echo esc_html($ev['limit'] > 0 ? sprintf(/* translators: 1: count, 2: limit */ __('%1$d of %2$d', 'workforce-one'), $ev['month_count'], $ev['limit']) : (string) $ev['month_count']); ?></strong></div>
                        <div><?php esc_html_e('Photo', 'workforce-one'); ?><strong><?php if ($ev['photo_url'] !== ''): ?><a href="<?php echo esc_url($ev['photo_url']); ?>" target="_blank" rel="noopener" data-wfo-photo><?php esc_html_e('Open', 'workforce-one'); ?></a><?php else: esc_html_e('None', 'workforce-one'); endif; ?></strong></div>
                    </div>
                </details>
                <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-cx-decide">
                    <?php wp_nonce_field('ews_correction_decide_' . (int) $c->id); ?>
                    <input type="hidden" name="action" value="ews_correction_decide"><input type="hidden" name="correction_id" value="<?php echo (int) $c->id; ?>">
                    <label class="screen-reader-text" for="wfo-cx-note-<?php echo (int) $c->id; ?>"><?php esc_html_e('Note to the employee', 'workforce-one'); ?></label>
                    <textarea id="wfo-cx-note-<?php echo (int) $c->id; ?>" name="note" rows="2" placeholder="<?php esc_attr_e('Note to the employee (required to reject)', 'workforce-one'); ?>"></textarea>
                    <div class="wfo-rq-actions"><button class="wfo-rq-btn is-no" type="submit" name="decision" value="reject"><?php echo Icons::svg('close', 16, 2.2); ?><?php esc_html_e('Reject', 'workforce-one'); ?></button><button class="wfo-rq-btn is-yes" type="submit" name="decision" value="approve"><?php echo Icons::svg('check', 16, 2.2); ?><?php esc_html_e('Approve', 'workforce-one'); ?></button></div>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($emp): ?>
    <div class="wfo-rq-col">
    <?php if (!$mine): ?>
        <section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('history', 24, 2); ?></span><strong><?php esc_html_e('No correction requests', 'workforce-one'); ?></strong><p><?php
            /* translators: %d: days */
            echo esc_html(sprintf(_n('If you forget to sign in or out, or a time is wrong, ask for a correction within %d day and your manager reviews it.', 'If you forget to sign in or out, or a time is wrong, ask for a correction within %d days and your manager reviews it.', (int) $settings['deadline_days'], 'workforce-one'), (int) $settings['deadline_days'])); ?></p></div></section>
    <?php endif; ?>
    <?php if ($waiting): ?><h3 class="wfo-rq-group"><?php esc_html_e('Waiting for a decision', 'workforce-one'); ?></h3><div class="wfo-rq-card"><?php foreach ($waiting as $c) echo $row($c); ?></div><?php endif; ?>
    <?php if ($decided): ?><h3 class="wfo-rq-group"><?php esc_html_e('Decided', 'workforce-one'); ?></h3><div class="wfo-rq-card"><?php foreach ($decided as $c) echo $row($c); ?></div><?php endif; ?>
    </div>
<?php elseif (!$decide): ?>
    <section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('check', 24, 2.2); ?></span><strong><?php esc_html_e('No corrections waiting', 'workforce-one'); ?></strong><p><?php esc_html_e('You\'re all caught up.', 'workforce-one'); ?></p></div></section>
<?php endif; ?>
</div>
<?php echo $sheet; ?>
</div>
