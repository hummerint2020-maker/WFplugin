<?php
/**
 * My requests → "+ New request" (3.31.105, owner-approved mockup docs/mockups/new-request/): a sheet
 * with the request types this person can ask for, each with a useful line, and each type's form in
 * its own sheet. The forms post to the same handlers as their pages (ews_return=requests brings the
 * person back here) and use the same scripts: leave.js (the leave days), overtime.js (the length),
 * corrections.js (the correction sheet, rendered by the Corrections module), assets/js/my-requests.js
 * (the swap colleagues, opening a form from the list). Styles: app-requests.css, app-approvals.css.
 *
 * @var array{kinds:array<string,array{group:string,label:string,line:string,sheet:string,off?:bool}>,advance?:array<string,mixed>,working_days?:list<int>,today?:string,tomorrow?:string,types?:list<array<string,mixed>>,early?:array{max:int,remaining:int,summary:string},cx_html?:string,swap?:array<string,mixed>} $new
 * @var string $post_url
 * @var bool $open_new
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $cx_html comes from the Corrections module's own template
$kinds = $new['kinds'] ?? [];
if (!$kinds) return;
$groups = ['time' => __('Time off and hours', 'workforce-one'), 'attendance' => __('Attendance and schedule', 'workforce-one'), 'money' => __('Money and documents', 'workforce-one')];
$icons = ['leave' => 'leave', 'early' => 'logout', 'overtime' => 'overtime', 'correction' => 'history', 'swap' => 'calendar', 'face' => 'user', 'advance' => 'cash'];
$back = '<input type="hidden" name="ews_return" value="requests">';
$x = '<button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="' . esc_attr__('Close', 'workforce-one') . '">' . Icons::svg('close', 20, 2) . '</button>';
$head = static function (string $id, string $title) use ($x): string {
    return '<div class="wfo-sheet-grip" aria-hidden="true"></div><div class="wfo-sheet-head"><button type="button" class="wfo-sheet-x" data-new-back aria-label="' . esc_attr__('Back', 'workforce-one') . '">' . Icons::svg('chevron', 20, 2) . '</button><h2 id="' . esc_attr($id) . '">' . esc_html($title) . '</h2>' . $x . '</div>';
};
?>
<dialog class="wfo-sheet wfo-new" id="wfo-new-sheet" aria-labelledby="wfo-new-title"<?php echo $open_new ? ' data-wfo-sheet-start' : ''; ?>>
    <div class="wfo-sheet-body">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-new-title"><?php esc_html_e('What do you need?', 'workforce-one'); ?></h2><?php echo $x; ?></div>
        <?php foreach ($groups as $g => $glabel): $in = array_filter($kinds, static function ($k) use ($g) { return $k['group'] === $g; }); if (!$in) continue; ?>
        <p class="wfo-new-group"><?php echo esc_html($glabel); ?></p>
        <div class="wfo-new-types">
            <?php foreach ($in as $key => $k): $off = !empty($k['off']); ?>
            <button type="button" class="wfo-new-type tone-<?php echo esc_attr($key); ?>"<?php echo $off ? ' disabled aria-disabled="true"' : ($key === 'correction' ? ' data-cx-open data-new-pick' : ' data-new-open="' . esc_attr($k['sheet']) . '"'); ?> data-new-kind="<?php echo esc_attr($key); ?>">
                <span class="wfo-new-ic" aria-hidden="true"><?php echo Icons::svg($icons[$key] ?? 'plus', 18, 2); ?></span>
                <b><?php echo esc_html($k['label']); ?></b>
                <?php if ($k['line'] !== ''): ?><small><?php echo esc_html($k['line']); ?></small><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</dialog>

<?php if (isset($kinds['leave'])): $types = $new['types']; ?>
<dialog class="wfo-sheet" id="wfo-new-leave" aria-labelledby="wfo-new-leave-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_vacation_request_create'); ?><input type="hidden" name="action" value="ews_vacation_request_create"><?php echo $back; ?>
        <?php echo $head('wfo-new-leave-title', __('Leave', 'workforce-one')); ?>
        <div class="wfo-rq-field"><label for="ews-vac-type"><?php esc_html_e('Leave Type', 'workforce-one'); ?></label><select id="ews-vac-type" name="leave_type_id" required>
            <?php foreach ($types as $t): ?><option value="<?php echo (int) $t['id']; ?>" data-earliest="<?php echo esc_attr($t['earliest']); ?>" data-hint="<?php echo esc_attr($t['unplanned'] ? ($t['after'] ? sprintf(/* translators: %d: number of days */ _n('Unplanned leave: you can ask for it on the day or up to %d day after it.', 'Unplanned leave: you can ask for it on the day or up to %d days after it.', $t['after'], 'workforce-one'), $t['after']) : __('Unplanned leave: you can ask for it on the day.', 'workforce-one')) : __('Planned leave: ask for it before the day starts.', 'workforce-one')); ?>"><?php echo esc_html($t['name'] . ($t['deducts'] && $t['available'] !== '' ? ' · ' . sprintf(/* translators: %s: days left */ __('%s left', 'workforce-one'), $t['available']) : '')); ?></option><?php endforeach; ?>
        </select></div>
        <p class="wfo-sheet-note" id="ews-vac-kind" aria-live="polite"></p>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="ews-vac-start"><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-vac-start" type="date" name="start_date" min="<?php echo esc_attr($types[0]['earliest'] ?? $new['today']); ?>" required></div>
            <div class="wfo-rq-field"><label for="ews-vac-end"><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-vac-end" type="date" name="end_date" min="<?php echo esc_attr($types[0]['earliest'] ?? $new['today']); ?>" required></div>
        </div>
        <p id="ews-vac-days" class="wfo-rq-total" role="status" data-working-days="<?php echo esc_attr(wp_json_encode($new['working_days'])); ?>" data-invalid="<?php esc_attr_e('Select a valid date range.', 'workforce-one'); ?>" data-count="<?php /* translators: %d: number of working days */ esc_attr_e('%d working day(s) requested.', 'workforce-one'); ?>"><?php esc_html_e('Select a valid date range.', 'workforce-one'); ?></p>
        <?php $sub_q = $new['substitute'] ?? null; $sub_id = 'wfo-new-leave-substitute'; $sub_class = 'wfo-rq-field'; include __DIR__ . '/substitute-field.php'; ?>
        <div class="wfo-rq-field"><label for="wfo-new-leave-reason"><?php esc_html_e('Reason', 'workforce-one'); ?> <span class="wfo-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></label><textarea id="wfo-new-leave-reason" name="reason" rows="2"></textarea></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>

<?php if (isset($kinds['early'])): $early = $new['early']; ?>
<dialog class="wfo-sheet" id="wfo-new-early" aria-labelledby="wfo-new-early-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_early_leave_create'); ?><input type="hidden" name="action" value="ews_early_leave_create"><?php echo $back; ?>
        <?php echo $head('wfo-new-early-title', __('Early leave', 'workforce-one')); ?>
        <p class="wfo-sheet-note"><?php echo esc_html($early['summary']); ?></p>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="wfo-new-early-date"><?php esc_html_e('Date', 'workforce-one'); ?></label><input type="date" id="wfo-new-early-date" name="work_date" min="<?php echo esc_attr($new['today']); ?>" required></div>
            <div class="wfo-rq-field"><label for="wfo-new-early-min"><?php esc_html_e('Duration (minutes)', 'workforce-one'); ?></label><input type="number" id="wfo-new-early-min" name="leave_minutes" min="1" max="<?php echo (int) $early['max']; ?>" required></div>
        </div>
        <div class="wfo-rq-field"><label for="wfo-new-early-reason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="wfo-new-early-reason" name="reason" rows="2"></textarea></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>

<?php if (isset($kinds['overtime'])): $counter = ['empty' => __('Select the times to see the requested duration.', 'workforce-one'), 'order' => __('End time must be after start time.', 'workforce-one'), /* translators: %s: duration */ 'req' => __('You are requesting %s.', 'workforce-one'), /* translators: %d: hours */ 'h' => __('%d h', 'workforce-one'), /* translators: 1: hours, 2: minutes */ 'hm' => __('%1$d h %2$d min', 'workforce-one')]; ?>
<dialog class="wfo-sheet" id="wfo-ot-sheet" aria-labelledby="wfo-ot-sheet-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_overtime_request_create'); ?><input type="hidden" name="action" value="ews_overtime_request_create"><?php echo $back; ?>
        <?php echo $head('wfo-ot-sheet-title', __('Overtime', 'workforce-one')); ?>
        <div class="wfo-rq-field" role="group" aria-labelledby="wfo-ot-day-label"><span class="wfo-rq-label" id="wfo-ot-day-label"><?php esc_html_e('Date', 'workforce-one'); ?></span>
            <div class="wfo-rq-days">
                <button type="button" data-ews-ot-day="<?php echo esc_attr($new['today']); ?>" aria-pressed="false"><?php esc_html_e('Today', 'workforce-one'); ?></button>
                <button type="button" data-ews-ot-day="<?php echo esc_attr($new['tomorrow']); ?>" aria-pressed="false"><?php esc_html_e('Tomorrow', 'workforce-one'); ?></button>
                <input id="ews-ot-date" type="date" name="overtime_date" min="<?php echo esc_attr($new['today']); ?>" required aria-labelledby="wfo-ot-day-label">
            </div>
        </div>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="ews-ot-start"><?php esc_html_e('From', 'workforce-one'); ?></label><input id="ews-ot-start" type="time" name="start_time" required></div>
            <div class="wfo-rq-field"><label for="ews-ot-end"><?php esc_html_e('To', 'workforce-one'); ?></label><input id="ews-ot-end" type="time" name="end_time" required></div>
        </div>
        <p id="ews-ot-count" class="wfo-rq-total" aria-live="polite" data-messages="<?php echo esc_attr(wp_json_encode($counter)); ?>"><?php echo Icons::svg('clock', 16, 2.2); ?><span><?php echo esc_html($counter['empty']); ?></span></p>
        <div class="wfo-rq-field"><label for="wfo-new-ot-reason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="wfo-new-ot-reason" name="reason" rows="2" placeholder="<?php esc_attr_e('Why is overtime required?', 'workforce-one'); ?>"></textarea></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>

<?php if (isset($kinds['correction'])) echo $new['cx_html']; ?>

<?php if (isset($kinds['swap']) && empty($kinds['swap']['off'])): $swap = $new['swap']; ?>
<dialog class="wfo-sheet" id="wfo-new-swap" aria-labelledby="wfo-new-swap-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body" data-new-swap="<?php echo esc_attr(wp_json_encode(['people' => $swap['people'], 'labels' => ['Office' => __('Office', 'workforce-one'), 'WFH' => __('WFH', 'workforce-one'), '' => __('No schedule', 'workforce-one')], 'same' => __('Same as your day', 'workforce-one'), 'busy' => __('A swap with them is already waiting that day', 'workforce-one'), 'none' => __('Nobody can swap with you that day.', 'workforce-one'), 'team' => __('Your team', 'workforce-one'), /* translators: %s: the colleague's schedule that day */ 'day' => __('That day: %s', 'workforce-one')])); ?>"><?php wp_nonce_field('ews_swap_create'); ?><input type="hidden" name="action" value="ews_swap_create"><?php echo $back; ?>
        <?php echo $head('wfo-new-swap-title', __('Swap with a colleague', 'workforce-one')); ?>
        <div class="wfo-rq-field"><label for="wfo-new-swap-day"><?php esc_html_e('Day', 'workforce-one'); ?></label><select id="wfo-new-swap-day" name="work_date" required>
            <?php foreach ($swap['days'] as $d => $mine): ?><option value="<?php echo esc_attr($d); ?>"><?php echo esc_html(date_i18n('l, j M', strtotime($d)) . ' — ' . ($mine === 'WFH' ? __('WFH', 'workforce-one') : __('Office', 'workforce-one'))); ?></option><?php endforeach; ?>
        </select></div>
        <div class="wfo-rq-field" role="radiogroup" aria-labelledby="wfo-new-swap-who"><span class="wfo-rq-label" id="wfo-new-swap-who"><?php esc_html_e('Swap with', 'workforce-one'); ?></span>
            <p class="wfo-sheet-note"><?php esc_html_e('Colleagues in your department. You can swap only with someone whose day is different from yours.', 'workforce-one'); ?></p>
            <div class="wfo-new-mates" data-new-mates></div>
        </div>
        <button class="wfo-sheet-submit" type="submit" data-new-swap-send><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
        <p class="wfo-sheet-note"><?php esc_html_e('Your colleague gets your day and you get theirs once they accept.', 'workforce-one'); ?></p>
    </form>
</dialog>
<?php endif; ?>

<?php if (isset($kinds['face']) && empty($kinds['face']['off'])): ?>
<dialog class="wfo-sheet" id="wfo-new-face" aria-labelledby="wfo-new-face-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_face_reset_ask'); ?><input type="hidden" name="action" value="ews_face_reset_ask">
        <?php echo $head('wfo-new-face-title', __('Face reset', 'workforce-one')); ?>
        <p class="wfo-sheet-note"><?php esc_html_e('Ask to register your face again. Your current face stays in use until the reset is approved; then you register it again on the Sign In page.', 'workforce-one'); ?></p>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Ask for a face reset', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>

<?php if (isset($kinds['advance']) && empty($kinds['advance']['off'])): $adv = $new['advance']; ?>
<dialog class="wfo-sheet" id="wfo-new-advance" aria-labelledby="wfo-new-advance-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body" data-new-advance data-currency="<?php echo esc_attr($adv['currency']); ?>" data-each="<?php /* translators: 1: amount a month, 2: months */ esc_attr_e('%1$s a month for %2$s months', 'workforce-one'); ?>"><?php wp_nonce_field('ews_advance_request_create'); ?><input type="hidden" name="action" value="ews_advance_request_create">
        <?php echo $head('wfo-new-advance-title', __('Salary advance', 'workforce-one')); ?>
        <div class="wfo-rq-field"><label for="wfo-new-advance-amount"><?php echo esc_html(sprintf(/* translators: %s: currency */ __('Amount (%s)', 'workforce-one'), $adv['currency'])); ?></label><input type="number" id="wfo-new-advance-amount" name="amount" min="1" step="0.01"<?php echo is_finite((float) $adv['limit']) ? ' max="' . esc_attr((string) $adv['limit']) . '"' : ''; ?> required></div>
        <p class="wfo-sheet-note"><?php echo esc_html(sprintf(/* translators: 1: limit, 2: most instalments */ __('Up to %1$s · back over up to %2$d months', 'workforce-one'), $adv['limit_text'], $adv['max']) . ($adv['one_open'] ? ' · ' . __('one open advance at a time', 'workforce-one') : '')); ?></p>
        <div class="wfo-rq-field"><label for="wfo-new-advance-months"><?php esc_html_e('Paid back over', 'workforce-one'); ?></label><select id="wfo-new-advance-months" name="instalments"><?php for ($m = 1; $m <= (int) $adv['max']; $m++): ?><option value="<?php echo (int) $m; ?>"<?php selected($m, min(6, (int) $adv['max'])); ?>><?php echo esc_html(sprintf(/* translators: %d: months */ _n('%d month', '%d months', $m, 'workforce-one'), $m)); ?></option><?php endfor; ?></select></div>
        <div class="wfo-rq-field"><label for="wfo-new-advance-reason"><?php esc_html_e('Reason', 'workforce-one'); ?> <span class="wfo-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></label><textarea id="wfo-new-advance-reason" name="reason" rows="2"></textarea></div>
        <p class="wfo-rq-total" data-new-advance-each role="status"></p>
        <p class="wfo-sheet-note"><?php esc_html_e('Once approved, Finance pays it, and the instalments come off your pay from the month after.', 'workforce-one'); ?></p>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>
