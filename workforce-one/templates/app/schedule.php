<?php
/**
 * Employee app: shared Team Schedule and the Schedule Swap panel. Styles: assets/css/app-schedule.css
 * (new look, 3.31.52); script: assets/js/schedule.js (swap form toggle, PDF print and WhatsApp share
 * via data attributes).
 *
 * @var object[] $emps                  ordered by TeamOrder, annotated with _schedule_* fields
 * @var string[] $dates
 * @var string $today
 * @var int $current_emp_id
 * @var array<int,array<string,string>> $cells   status name per employee and date
 * @var array<int,array{picture:string,initials:string}> $people
 * @var string[] $legend                status names
 * @var string $range
 * @var string $week_start
 * @var string $week_label
 * @var string $prev_url
 * @var string $next_url
 * @var string $print_title
 * @var string $email_url               '' when the user cannot send schedules
 * @var array{sent:int,skipped:int,failed:int}|null $email
 * @var array<string,string> $swap_days  date => my status
 * @var object[] $swap_requests
 * @var string $no_swaps_html
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $chip(), $avatar() and $no_swaps_html escape their output.
$status_label = static function (string $s): string {
    $known = ['Not Set' => __('Not Set', 'workforce-one'), 'General Leave' => __('General Leave', 'workforce-one'), 'Absent' => __('Absent', 'workforce-one')];
    return $known[$s] ?? $s;
};
$chip = static function (string $s) use ($status_label): string {
    [$icon, $tone] = Icons::forStatus($s);
    return '<span class="wfo-chip is-' . esc_attr($tone) . '">' . Icons::svg($icon, 14, 2) . '<span>' . esc_html($status_label($s)) . '</span></span>';
};
$avatar = static function (int $id, string $name) use ($people): string {
    $p = $people[$id] ?? ['picture' => '', 'initials' => \WorkforceOne\Employees\ProfileSummary::initials($name) ?: '·'];
    if ($p['picture'] !== '') return '<span class="wfo-sched-avatar"><img src="' . esc_url($p['picture']) . '" alt=""></span>';
    return '<span class="wfo-sched-avatar tone-' . ($id % 5) . '" aria-hidden="true">' . esc_html($p['initials']) . '</span>';
};
$week_labels = ['This week' => __('This week', 'workforce-one'), 'Next week' => __('Next week', 'workforce-one'), 'Previous week' => __('Previous week', 'workforce-one')];
$week_text = $week_labels[$week_label] ?? $week_label;
$swap_states = ['pending' => ['overtime', __('Pending', 'workforce-one')], 'accepted' => ['check', __('Accepted', 'workforce-one')], 'rejected' => ['close', __('Rejected', 'workforce-one')], 'cancelled' => ['close', __('Cancelled', 'workforce-one')]];
$by_id = [];
foreach ($emps as $e) $by_id[(int) $e->id] = $e;
?>
<?php if ($email): ?><div class="ews-notice wfo-sched-notice"><?php echo Icons::svg('mail', 18); ?><span><?php esc_html_e('Schedule emails sent:', 'workforce-one'); ?> <strong><?php echo (int) $email['sent']; ?></strong> · <?php esc_html_e('skipped:', 'workforce-one'); ?> <strong><?php echo (int) $email['skipped']; ?></strong> · <?php esc_html_e('failed:', 'workforce-one'); ?> <strong><?php echo (int) $email['failed']; ?></strong></span></div><?php endif; ?>
<div class="ews-schedule-page wfo-sched">

    <section class="wfo-sched-top">
        <div class="ews-week-nav wfo-weeknav">
            <a href="<?php echo esc_url($prev_url); ?>" aria-label="<?php esc_attr_e('Previous week', 'workforce-one'); ?>" class="wfo-weeknav-prev"><?php echo Icons::svg('chevron', 20, 2.2); ?></a>
            <div class="wfo-weeknav-text"><span><?php echo esc_html($week_text); ?></span><small dir="ltr"><?php echo esc_html($range); ?></small></div>
            <a href="<?php echo esc_url($next_url); ?>" aria-label="<?php esc_attr_e('Next week', 'workforce-one'); ?>" class="wfo-weeknav-next"><?php echo Icons::svg('chevron', 20, 2.2); ?></a>
        </div>
        <div class="ews-schedule-tools wfo-sched-tools ews-no-print">
            <form class="wfo-sched-jump">
                <input type="hidden" name="ews_view" value="schedule">
                <label><span class="screen-reader-text"><?php esc_html_e('Week', 'workforce-one'); ?></span><input type="date" name="week" value="<?php echo esc_attr($week_start); ?>" aria-label="<?php esc_attr_e('Week', 'workforce-one'); ?>"></label>
                <button class="ews-btn wfo-btn-ghost" type="submit"><?php echo Icons::svg('calendar', 16); ?><?php esc_html_e('View Week', 'workforce-one'); ?></button>
            </form>
            <div class="ews-schedule-tool-actions">
                <button type="button" class="ews-btn secondary ews-pdf-btn wfo-btn-ghost" data-ews-print="<?php echo esc_attr($print_title); ?>"><?php echo Icons::svg('download', 16); ?>PDF</button>
                <button type="button" class="ews-btn secondary ews-wa-btn wfo-btn-ghost" data-ews-print="Team Weekly Schedule" data-ews-whatsapp="1"><?php echo Icons::svg('share', 16); ?>WhatsApp</button>
                <?php if ($email_url): ?>
                    <a class="ews-btn wfo-btn-solid" href="<?php echo esc_url($email_url); ?>" data-ews-confirm="<?php esc_attr_e('Send each active employee their own schedule for this week?', 'workforce-one'); ?>"><?php echo Icons::svg('mail', 16); ?><?php esc_html_e('Send schedules', 'workforce-one'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($current_emp_id && isset($cells[$current_emp_id])): ?>
    <section class="wfo-sched-card wfo-myweek" aria-labelledby="wfo-myweek-title">
        <div class="wfo-card-title"><span class="wfo-card-icon"><?php echo Icons::svg('user', 20); ?></span><h3 id="wfo-myweek-title"><?php esc_html_e('My week', 'workforce-one'); ?></h3></div>
        <ol class="wfo-myweek-days">
            <?php foreach ($dates as $d): $s = $cells[$current_emp_id][$d]; [$icon, $tone] = Icons::forStatus($s); ?>
            <li class="is-<?php echo esc_attr($tone); ?><?php echo $d === $today ? ' is-today' : ''; ?>"<?php echo $d === $today ? ' aria-current="date"' : ''; ?>>
                <span class="wfo-myweek-day"><?php echo esc_html(date_i18n('D', strtotime($d))); ?></span>
                <span class="wfo-myweek-num"><?php echo esc_html(date_i18n('j', strtotime($d))); ?></span>
                <span class="wfo-myweek-icon"><?php echo Icons::svg($icon, 18, 2); ?></span>
                <span class="wfo-myweek-word"><?php echo esc_html($status_label($s)); ?></span>
            </li>
            <?php endforeach; ?>
        </ol>
    </section>
    <?php endif; ?>

    <section class="wfo-sched-card ews-schedule-card" aria-labelledby="wfo-team-title">
        <div class="wfo-card-title">
            <span class="wfo-card-icon is-teal"><?php echo Icons::svg('people', 20); ?></span>
            <div class="wfo-card-title-text"><h3 id="wfo-team-title"><?php esc_html_e('Team Schedule', 'workforce-one'); ?></h3><p class="wfo-help"><?php esc_html_e('Everyone can view the team\'s schedule to coordinate coverage and arrange swaps with colleagues.', 'workforce-one'); ?></p></div>
        </div>
        <div class="ews-schedule-legend wfo-legend"><?php foreach ($legend as $l) echo $chip($l); ?></div>
        <div class="ews-schedule-table-wrap wfo-sched-scroll" tabindex="0" role="region" aria-labelledby="wfo-team-title">
            <table class="ews-table ews-schedule-table wfo-sched-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Employee', 'workforce-one'); ?></th>
                        <?php foreach ($dates as $d): ?>
                            <th class="<?php echo $d === $today ? 'today' : ''; ?>">
                                <?php echo esc_html(date_i18n('D', strtotime($d))); ?>
                                <small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$emps): ?>
                        <tr><td colspan="<?php echo count($dates) + 1; ?>"><div class="ews-schedule-empty"><span class="wfo-empty-icon"><?php echo Icons::svg('calendar', 26); ?></span><strong><?php esc_html_e('No employees found', 'workforce-one'); ?></strong><span><?php esc_html_e('There are no active employees to display.', 'workforce-one'); ?></span></div></td></tr>
                    <?php else: foreach ($emps as $e): $me = $current_emp_id === (int) $e->id; $manager = !empty($e->_schedule_team_manager); ?>
                        <tr class="ews-att-employee-row <?php echo esc_attr(trim(($me ? 'current-user ' : '') . ($manager ? 'team-manager' : ''))); ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name . ' ' . $e->domain_name)); ?>">
                            <th scope="row" class="wfo-sched-who">
                                <div class="wfo-sched-person">
                                    <?php echo $avatar((int) $e->id, (string) $e->name); ?>
                                    <div class="wfo-sched-names">
                                        <div class="ews-person"><?php echo esc_html($e->name); ?><?php if ($me): ?><span class="ews-me-badge"><?php esc_html_e('You', 'workforce-one'); ?></span><?php endif; ?><?php if ($manager): ?><span class="ews-manager-badge"><?php esc_html_e('Manager', 'workforce-one'); ?></span><?php endif; ?></div>
                                        <div class="ews-domain"><?php echo !empty($e->_schedule_team_names) ? esc_html(implode(' · ', $e->_schedule_team_names)) : esc_html__('No Team', 'workforce-one'); ?></div>
                                    </div>
                                </div>
                            </th>
                            <?php foreach ($dates as $d): ?>
                                <td data-label="<?php echo esc_attr(date_i18n('D, d M', strtotime($d))); ?>" class="<?php echo $d === $today ? 'today' : ''; ?>"><?php echo $chip($cells[(int) $e->id][$d]); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($current_emp_id): ?>
    <section class="wfo-sched-card ews-swap-panel ews-no-print" aria-labelledby="wfo-swap-title">
        <div class="wfo-card-title ews-swap-panel-head">
            <span class="wfo-card-icon is-amber"><?php echo Icons::svg('swap', 20); ?></span>
            <div class="wfo-card-title-text"><h3 id="wfo-swap-title"><?php esc_html_e('Need to swap a day?', 'workforce-one'); ?></h3><p class="wfo-help"><?php esc_html_e('Choose a colleague and request a direct schedule swap. No approval is required.', 'workforce-one'); ?></p></div>
            <button type="button" class="ews-btn wfo-btn-solid" id="ews-open-swap"><?php echo Icons::svg('swap', 16); ?><?php esc_html_e('Request Swap', 'workforce-one'); ?></button>
        </div>
        <div id="ews-swap-form-wrap" class="ews-swap-form-wrap" hidden>
            <?php if ($swap_days): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-swap-form">
                <?php wp_nonce_field('ews_swap_create'); ?>
                <input type="hidden" name="action" value="ews_swap_create">
                <div class="wfo-field"><label for="wfo-swap-day"><?php esc_html_e('Day', 'workforce-one'); ?></label><select name="work_date" id="wfo-swap-day" required>
                    <?php foreach ($swap_days as $d => $mine): ?><option value="<?php echo esc_attr($d); ?>"><?php echo esc_html(date_i18n('l, d M', strtotime($d)) . ' — ' . $status_label($mine)); ?></option><?php endforeach; ?>
                </select></div>
                <div class="wfo-field"><label for="wfo-swap-with"><?php esc_html_e('Swap with', 'workforce-one'); ?></label><select name="target_employee_id" id="wfo-swap-with" required>
                    <option value=""><?php esc_html_e('Select colleague', 'workforce-one'); ?></option>
                    <?php foreach ($emps as $e): if ((int) $e->id !== $current_emp_id): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endif; endforeach; ?>
                </select></div>
                <div class="ews-swap-form-actions"><button class="ews-btn wfo-btn-solid" type="submit"><?php echo Icons::svg('arrow', 16); ?><?php esc_html_e('Send Request', 'workforce-one'); ?></button><button class="ews-btn secondary wfo-btn-ghost" type="button" id="ews-close-swap"><?php esc_html_e('Cancel', 'workforce-one'); ?></button></div>
            </form>
            <?php else: ?>
            <div class="ews-swap-week-empty"><span><?php esc_html_e('You have no Office or WFH days left this week to swap.', 'workforce-one'); ?></span> <button class="ews-btn secondary wfo-btn-ghost" type="button" id="ews-close-swap"><?php esc_html_e('Close', 'workforce-one'); ?></button></div>
            <?php endif; ?>
        </div>

        <div class="ews-swap-requests">
            <h4 class="ews-swap-requests-title"><?php esc_html_e('Swap Requests', 'workforce-one'); ?> · <?php echo esc_html($week_text); ?></h4>
            <?php if ($swap_requests): foreach ($swap_requests as $sr): $incoming = (int) $sr->target_employee_id === $current_emp_id; $other = $incoming ? (int) $sr->requester_employee_id : (int) $sr->target_employee_id; $state = strtolower((string) $sr->status); [$sicon, $stext] = $swap_states[$state] ?? ['alert', (string) $sr->status]; ?>
            <div class="ews-swap-request">
                <div class="ews-swap-request-main">
                    <?php echo $avatar($other, (string) ($incoming ? $sr->requester_name : $sr->target_name)); ?>
                    <div><strong><?php echo esc_html($incoming ? $sr->requester_name : $sr->target_name); ?></strong>
                    <span class="wfo-swap-dir"><?php echo $incoming ? esc_html__('Asked you', 'workforce-one') : esc_html__('You asked', 'workforce-one'); ?> · <?php echo esc_html(date_i18n('D, d M', strtotime($sr->work_date))); ?></span>
                    <span class="wfo-swap-trade"><?php echo $chip((string) $sr->requester_status); ?><?php echo Icons::svg('swap', 14); ?><?php echo $chip((string) $sr->target_status); ?></span></div>
                </div>
                <div class="ews-swap-request-right"><span class="ews-swap-state <?php echo esc_attr($state); ?>"><?php echo Icons::svg($sicon, 14, 2.2); ?><?php echo esc_html($stext); ?></span>
                <?php if ($incoming && $sr->status === 'Pending'): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_swap_respond_' . (int) $sr->id); ?><input type="hidden" name="action" value="ews_swap_respond"><input type="hidden" name="swap_id" value="<?php echo (int) $sr->id; ?>"><button class="ews-swap-small accept" name="decision" value="accept"><?php echo Icons::svg('check', 14, 2.4); ?><?php esc_html_e('Accept', 'workforce-one'); ?></button><button class="ews-swap-small reject" name="decision" value="reject"><?php echo Icons::svg('close', 14, 2.2); ?><?php esc_html_e('Reject', 'workforce-one'); ?></button></form>
                <?php elseif (!$incoming && $sr->status === 'Pending'): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_swap_cancel_' . (int) $sr->id); ?><input type="hidden" name="action" value="ews_swap_cancel"><input type="hidden" name="swap_id" value="<?php echo (int) $sr->id; ?>"><button class="ews-swap-small reject" type="submit"><?php echo Icons::svg('close', 14, 2.2); ?><?php esc_html_e('Cancel', 'workforce-one'); ?></button></form>
                <?php endif; ?></div>
            </div>
            <?php endforeach; else: ?>
                <?php echo $no_swaps_html; ?>
            <?php endif; ?>
        </div>
        <p class="ews-swap-hint wfo-help"><?php echo Icons::svg('sparkle', 16); ?><span><?php esc_html_e('Use this shared schedule to find a colleague with a compatible day. You can request a swap directly from this page.', 'workforce-one'); ?></span></p>
    </section>
    <?php endif; ?>
</div>
