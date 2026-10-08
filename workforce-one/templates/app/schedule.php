<?php
/**
 * Employee app: shared Team Schedule and the Schedule Swap panel. Styles: assets/css/app-schedule.css
 * (new look, 3.31.52); script: assets/js/schedule.js (swap form toggle, PDF print and WhatsApp share
 * via data attributes).
 *
 * @var array{rows:object[],paged:bool,page:int,pages:int,total:int,all:int,q:string,team:string} $list  Ui\ListPage (3.31.71)
 * @var array<string,string> $list_keep  query values the server search keeps (view, week)
 * @var object[] $swap_people  everyone in the list (the swap form offers them all)
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
 * @var string $pdf_url               the week as a PDF (schedule_pdf())
 * @var string $pdf_name
 * @var string $email_url               '' when the user cannot send schedules
 * @var array{sent:int,skipped:int,failed:int}|null $email
 * @var array<string,string> $swap_days  date => my status
 * @var object[] $swap_requests
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $chip() and $avatar() escape their output.
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
        </div>
    </section>

    <?php if ($current_emp_id && isset($cells[$current_emp_id])): $me_emp = $by_id[$current_emp_id] ?? null; $today_status = $cells[$current_emp_id][$today] ?? ''; ?>
    <section class="wfo-sched-card wfo-myweek" aria-labelledby="wfo-myweek-title">
        <div class="wfo-myweek-head">
            <?php echo $avatar($current_emp_id, $me_emp ? (string) $me_emp->name : ''); ?>
            <div class="wfo-myweek-who"><h3 id="wfo-myweek-title"><?php esc_html_e('My week', 'workforce-one'); ?></h3><?php if ($me_emp): ?><strong><?php echo esc_html($me_emp->name); ?></strong><?php endif; ?></div>
            <?php if ($today_status !== ''): ?><span class="wfo-myweek-today"><?php echo $chip($today_status); ?></span><?php endif; ?>
        </div>
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
        <button type="button" class="ews-btn wfo-btn-solid wfo-myweek-swap ews-no-print" data-wfo-sheet="wfo-swap-sheet" aria-haspopup="dialog"><?php echo Icons::svg('swap', 16); ?><?php esc_html_e('Request a swap', 'workforce-one'); ?></button>
    </section>
    <?php endif; ?>

    <section class="wfo-sched-card ews-schedule-card" aria-labelledby="wfo-team-title">
        <div class="wfo-card-title">
            <span class="wfo-card-icon is-teal wfo-desktop-only"><?php echo Icons::svg('people', 20); ?></span>
            <div class="wfo-card-title-text"><h3 id="wfo-team-title"><?php esc_html_e('Team Schedule', 'workforce-one'); ?> <span class="wfo-sched-count"><?php echo esc_html(sprintf(/* translators: %d: number of people */ _n('%d person', '%d people', $list['total'], 'workforce-one'), $list['total'])); ?></span></h3></div>
        </div>
        <div class="wfo-sched-bar ews-no-print">
            <?php if ($list['paged']): // a long list: the search runs on the server (Enter) ?>
            <form method="get" class="wfo-list-search" data-ews-server-list><?php foreach ($list_keep as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
                <label class="wfo-sched-search"><?php echo Icons::svg('search', 17, 2); ?><input type="search" name="q" id="wfo-sched-search" value="<?php echo esc_attr($list['q']); ?>" placeholder="<?php esc_attr_e('Search colleague', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Search colleague', 'workforce-one'); ?>" autocomplete="off"></label>
            </form>
            <?php else: ?>
            <label class="wfo-sched-search"><?php echo Icons::svg('search', 17, 2); ?><input type="search" id="wfo-sched-search" placeholder="<?php esc_attr_e('Search colleague', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Search colleague', 'workforce-one'); ?>" autocomplete="off"></label>
            <?php endif; ?>
            <div class="ews-schedule-tool-actions wfo-sched-actions">
                <a class="ews-btn secondary ews-pdf-btn wfo-btn-ghost" href="<?php echo esc_url($pdf_url); ?>" download><?php echo Icons::svg('download', 16); ?><span>PDF</span></a>
                <button type="button" class="ews-btn secondary ews-wa-btn wfo-btn-ghost" data-ews-share-pdf="<?php echo esc_url($pdf_url); ?>" data-ews-share-name="<?php echo esc_attr($pdf_name); ?>" data-ews-share-text="<?php echo esc_attr(__('Team Schedule', 'workforce-one') . ' · ' . $range); ?>" data-ews-share-fallback="<?php esc_attr_e('The schedule PDF was downloaded. Attach it to your WhatsApp message.', 'workforce-one'); ?>" data-ews-share-error="<?php esc_attr_e('The PDF could not be prepared. Please try again.', 'workforce-one'); ?>"><?php echo Icons::svg('share', 16); ?><span>WhatsApp</span></button>
                <?php if ($email_url): ?>
                    <a class="ews-btn secondary wfo-btn-ghost" href="<?php echo esc_url($email_url); ?>" data-ews-confirm="<?php esc_attr_e('Send each active employee their own schedule for this week?', 'workforce-one'); ?>"><?php echo Icons::svg('mail', 16); ?><span class="wfo-desktop-only"><?php esc_html_e('Email everyone', 'workforce-one'); ?></span><span class="wfo-phone-only"><?php echo esc_html_x('Email', 'short button label', 'workforce-one'); ?></span></a>
                <?php endif; ?>
            </div>
        </div>
        <div class="ews-schedule-legend wfo-legend wfo-desktop-only"><?php foreach ($legend as $l) echo $chip($l); ?></div>
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
        <ul class="wfo-sched-cards">
            <?php foreach ($emps as $e): if ((int) $e->id === $current_emp_id) continue; $manager = !empty($e->_schedule_team_manager); ?>
            <li class="wfo-sched-pcard<?php echo $manager ? ' is-manager' : ''; ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name . ' ' . $e->domain_name)); ?>">
                <div class="wfo-sched-pcard-head">
                    <?php echo $avatar((int) $e->id, (string) $e->name); ?>
                    <div class="wfo-sched-pcard-who"><strong><?php echo esc_html($e->name); ?></strong><?php if ($manager): ?> <span class="ews-manager-badge"><?php esc_html_e('Manager', 'workforce-one'); ?></span><?php endif; ?><small><?php echo !empty($e->_schedule_team_names) ? esc_html(implode(' · ', $e->_schedule_team_names)) : esc_html__('No Team', 'workforce-one'); ?></small></div>
                </div>
                <ol class="wfo-sched-pdays" style="--days:<?php echo (int) count($dates); ?>">
                    <?php foreach ($dates as $d): $st = $cells[(int) $e->id][$d]; [, $tone] = Icons::forStatus($st); ?>
                    <li class="is-<?php echo esc_attr($tone); ?><?php echo $d === $today ? ' is-today' : ''; ?>"<?php echo $d === $today ? ' aria-current="date"' : ''; ?>><span><?php echo $d === $today ? esc_html__('Today', 'workforce-one') : esc_html(date_i18n('D', strtotime($d))); ?></span><b><?php echo esc_html($status_label($st)); ?></b></li>
                    <?php endforeach; ?>
                </ol>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="wfo-sched-noresult"<?php echo $list['paged'] && !$emps ? '' : ' hidden'; ?>><?php esc_html_e('No colleague matches your search.', 'workforce-one'); ?></p>
        <?php include __DIR__ . '/list-pager.php'; ?>
    </section>

    <?php if ($current_emp_id):
        $has_myweek = isset($cells[$current_emp_id]);
        $incoming = $outgoing = $decided = [];
        foreach ($swap_requests as $sr) {
            if ((string) $sr->status !== 'Pending') $decided[] = $sr;
            elseif ((int) $sr->target_employee_id === $current_emp_id) $incoming[] = $sr;
            else $outgoing[] = $sr;
        }
        $swap_row = static function (object $sr, string $actions) use ($current_emp_id, $avatar, $chip, $swap_states): string {
            $in = (int) $sr->target_employee_id === $current_emp_id;
            $name = (string) ($in ? $sr->requester_name : $sr->target_name);
            $state = strtolower((string) $sr->status);
            [$sicon, $stext] = $swap_states[$state] ?? ['alert', (string) $sr->status];
            $side = $actions !== '' ? '<div class="wfo-rq-actions">' . $actions . '</div>'
                : '<span class="wfo-rq-state is-' . esc_attr($state) . '">' . Icons::svg($sicon, 14, 2.2) . esc_html($stext) . '</span>';
            return '<div class="wfo-rq-row' . ($actions !== '' ? ' has-actions' : '') . '">' . $avatar($in ? (int) $sr->requester_employee_id : (int) $sr->target_employee_id, $name)
                . '<div class="wfo-rq-main"><p class="wfo-rq-title">' . esc_html($name) . '</p>'
                . '<p class="wfo-rq-meta">' . esc_html(($in ? __('Asked you', 'workforce-one') : __('You asked', 'workforce-one')) . ' · ' . date_i18n('D, d M', strtotime((string) $sr->work_date))) . '</p>'
                . '<span class="wfo-swap-trade">' . $chip((string) $sr->requester_status) . Icons::svg('swap', 14) . $chip((string) $sr->target_status) . '</span></div>' . $side . '</div>';
        };
        $form_open = static function (string $action, string $nonce, int $id) use ($post_url): string {
            return '<form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field($nonce, '_wpnonce', true, false)
                . '<input type="hidden" name="action" value="' . esc_attr($action) . '"><input type="hidden" name="swap_id" value="' . $id . '">';
        };
        $show_panel = !$has_myweek || $swap_requests;
        ?>
    <?php if ($show_panel): ?>
    <section class="wfo-rq ews-swap-panel ews-no-print" aria-labelledby="wfo-swap-title">
        <div class="wfo-rq-hero">
            <div class="wfo-rq-hero-main">
                <p class="wfo-rq-hero-label"><?php /* translators: %s: "This week", "Next week" or a date range */ printf(esc_html__('Shift swaps · %s', 'workforce-one'), esc_html($week_text)); ?></p>
                <h3 class="wfo-rq-hero-value" id="wfo-swap-title"><?php
                    if ($incoming) printf(esc_html(/* translators: %d: number of swap requests */ _n('%d swap waiting for you', '%d swaps waiting for you', count($incoming), 'workforce-one')), count($incoming));
                    elseif ($outgoing) printf(esc_html(/* translators: %d: number of swap requests */ _n('%d request sent', '%d requests sent', count($outgoing), 'workforce-one')), count($outgoing));
                    else esc_html_e('Need to swap a day?', 'workforce-one');
                ?></h3>
                <?php if ($incoming && $outgoing): ?><div class="wfo-rq-hero-chips"><span class="wfo-rq-hero-chip is-wait"><?php printf(esc_html(/* translators: %d: number of swap requests */ _n('%d request sent', '%d requests sent', count($outgoing), 'workforce-one')), count($outgoing)); ?></span></div><?php endif; ?>
            </div>
            <?php if (!$has_myweek): /* with My week, its own "Request a swap" button opens the form */ ?><button type="button" class="wfo-rq-new" id="ews-open-swap" data-wfo-sheet="wfo-swap-sheet" aria-haspopup="dialog"><?php echo Icons::svg('swap', 18, 2.2); ?><?php esc_html_e('Request Swap', 'workforce-one'); ?></button><?php endif; ?>
        </div>
        <?php if ($incoming): ?>
            <h4 class="wfo-rq-group"><?php esc_html_e('Waiting for you', 'workforce-one'); ?></h4>
            <div class="wfo-rq-card"><?php foreach ($incoming as $sr) echo $swap_row($sr, $form_open('ews_swap_respond', 'ews_swap_respond_' . (int) $sr->id, (int) $sr->id)
                . '<button class="wfo-rq-btn is-no" name="decision" value="reject">' . Icons::svg('close', 16, 2.2) . esc_html__('Reject', 'workforce-one') . '</button></form>'
                . $form_open('ews_swap_respond', 'ews_swap_respond_' . (int) $sr->id, (int) $sr->id)
                . '<button class="wfo-rq-btn is-yes" name="decision" value="accept">' . Icons::svg('check', 16, 2.4) . esc_html__('Accept', 'workforce-one') . '</button></form>'); ?></div>
        <?php endif; ?>
        <?php if ($outgoing): ?>
            <h4 class="wfo-rq-group"><?php esc_html_e('Sent by you', 'workforce-one'); ?></h4>
            <div class="wfo-rq-card"><?php foreach ($outgoing as $sr) echo $swap_row($sr, $form_open('ews_swap_cancel', 'ews_swap_cancel_' . (int) $sr->id, (int) $sr->id)
                . '<button class="wfo-rq-btn is-no" type="submit">' . Icons::svg('close', 16, 2.2) . esc_html__('Cancel', 'workforce-one') . '</button></form>'); ?></div>
        <?php endif; ?>
        <?php if ($decided): ?>
            <h4 class="wfo-rq-group"><?php esc_html_e('Decided', 'workforce-one'); ?></h4>
            <div class="wfo-rq-card"><?php foreach ($decided as $sr) echo $swap_row($sr, ''); ?></div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <dialog class="wfo-sheet ews-no-print" id="wfo-swap-sheet" aria-labelledby="wfo-swap-sheet-title">
        <div class="wfo-sheet-body">
            <div class="wfo-sheet-grip" aria-hidden="true"></div>
            <div class="wfo-sheet-head"><h2 id="wfo-swap-sheet-title"><?php esc_html_e('Request a swap', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
            <?php if ($swap_days): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-form">
                <?php wp_nonce_field('ews_swap_create'); ?>
                <input type="hidden" name="action" value="ews_swap_create">
                <div class="wfo-rq-field"><label for="wfo-swap-day"><?php esc_html_e('Day', 'workforce-one'); ?></label><select name="work_date" id="wfo-swap-day" required>
                    <?php foreach ($swap_days as $d => $mine): ?><option value="<?php echo esc_attr($d); ?>"><?php echo esc_html(date_i18n('l, d M', strtotime($d)) . ' — ' . $status_label($mine)); ?></option><?php endforeach; ?>
                </select></div>
                <div class="wfo-rq-field"><label for="wfo-swap-with"><?php esc_html_e('Swap with', 'workforce-one'); ?></label><select name="target_employee_id" id="wfo-swap-with" required>
                    <option value=""><?php esc_html_e('Select colleague', 'workforce-one'); ?></option>
                    <?php foreach ($swap_people as $e): if ((int) $e->id !== $current_emp_id): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endif; endforeach; ?>
                </select></div>
                <button class="wfo-sheet-submit" type="submit" data-fwd><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send Request', 'workforce-one'); ?></button>
                <p class="wfo-sheet-note"><?php esc_html_e('Your colleague gets your day and you get theirs once they accept.', 'workforce-one'); ?></p>
            </form>
            <?php else: ?>
            <p class="wfo-sheet-note" style="font-size:14px"><?php esc_html_e('You have no Office or WFH days left this week to swap.', 'workforce-one'); ?></p>
            <button type="button" class="wfo-sheet-submit" data-wfo-sheet-close><?php esc_html_e('Close', 'workforce-one'); ?></button>
            <?php endif; ?>
        </div>
    </dialog>
    <?php endif; ?>
</div>
