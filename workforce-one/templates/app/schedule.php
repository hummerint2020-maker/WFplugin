<?php
/**
 * Employee app: shared Team Schedule and the Schedule Swap panel. Script: assets/js/schedule.js
 * (swap form toggle, PDF print and WhatsApp share via data attributes).
 *
 * @var object[] $emps                  ordered by TeamOrder, annotated with _schedule_* fields
 * @var string[] $dates
 * @var string $today
 * @var int $current_emp_id
 * @var array<int,array<string,string>> $cells   status badge HTML per employee and date
 * @var string $legend                  status badge HTML
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
?>
<?php if ($email): ?><div class="ews-notice">Schedule emails sent: <strong><?php echo (int) $email['sent']; ?></strong> · skipped: <strong><?php echo (int) $email['skipped']; ?></strong> · failed: <strong><?php echo (int) $email['failed']; ?></strong></div><?php endif; ?>
<div class="ews-schedule-page">
    <div class="ews-schedule-head">
        <div>
            <div class="ews-dash-kicker">TEAM SCHEDULE</div>
            <h2>Team Schedule</h2>
            <p>Everyone's schedule · <?php echo esc_html($range); ?></p>
        </div>
        <div class="ews-week-nav">
            <a href="<?php echo esc_url($prev_url); ?>" aria-label="Previous week">‹</a>
            <span><?php echo esc_html($week_label); ?></span>
            <a href="<?php echo esc_url($next_url); ?>" aria-label="Next week">›</a>
        </div>
    </div>

    <div class="ews-schedule-tools ews-no-print">
        <form>
            <input type="hidden" name="ews_view" value="schedule">
            <label>Week <input type="date" name="week" value="<?php echo esc_attr($week_start); ?>"></label>
            <button class="ews-btn" type="submit">View Week</button>
        </form>
        <div class="ews-schedule-tool-actions">
            <button type="button" class="ews-btn secondary ews-pdf-btn" data-ews-print="<?php echo esc_attr($print_title); ?>">⬇ PDF</button>
            <button type="button" class="ews-btn secondary ews-wa-btn" data-ews-print="Team Weekly Schedule" data-ews-whatsapp="1">WhatsApp</button>
            <?php if ($email_url): ?>
                <a class="ews-btn" href="<?php echo esc_url($email_url); ?>" data-ews-confirm="Send each active employee their own schedule for this week?">✉ Send schedules</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="ews-schedule-legend"><?php echo $legend; // phpcs:ignore WordPress.Security.EscapeOutput -- built by status_html() ?></div>

    <div class="ews-schedule-info">
        <span>👥</span>
        <div><strong>Shared team schedule</strong><small>Everyone can view the team's schedule to coordinate coverage and arrange swaps with colleagues.</small></div>
    </div>

    <div class="ews-card ews-week ews-schedule-card">
        <div class="ews-schedule-table-wrap">
            <table class="ews-table ews-schedule-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <?php foreach ($dates as $d): ?>
                            <th class="<?php echo $d === $today ? 'today' : ''; ?>">
                                <?php echo esc_html(date('D', strtotime($d))); ?>
                                <small><?php echo esc_html(date('d M', strtotime($d))); ?></small>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$emps): ?>
                        <tr><td colspan="<?php echo count($dates) + 1; ?>"><div class="ews-schedule-empty"><div>📅</div><strong>No employees found</strong><span>There are no active employees to display.</span></div></td></tr>
                    <?php else: foreach ($emps as $e): $me = $current_emp_id === (int) $e->id; $manager = !empty($e->_schedule_team_manager); ?>
                        <tr class="ews-att-employee-row <?php echo esc_attr(trim(($me ? 'current-user ' : '') . ($manager ? 'team-manager' : ''))); ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name . ' ' . $e->domain_name)); ?>">
                            <td>
                                <div class="ews-person"><?php echo esc_html($e->name); ?><?php if ($me): ?><span class="ews-me-badge">You</span><?php endif; ?><?php if ($manager): ?><span class="ews-manager-badge">Manager</span><?php endif; ?></div>
                                <div class="ews-domain"><?php echo esc_html($e->domain_name); ?></div>
                                <?php if (!empty($e->_schedule_team_names)): ?>
                                    <div class="ews-domain" style="margin-top:2px;font-weight:600">👥 <?php echo esc_html(implode(' · ', $e->_schedule_team_names)); ?></div>
                                <?php else: ?>
                                    <div class="ews-domain" style="margin-top:2px">No Team</div>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($dates as $d): ?>
                                <td data-label="<?php echo esc_attr(date('D, d M', strtotime($d))); ?>" class="<?php echo $d === $today ? 'today' : ''; ?>"><?php echo $cells[(int) $e->id][$d]; // phpcs:ignore WordPress.Security.EscapeOutput -- built by status_html() ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($current_emp_id): ?>
    <div class="ews-swap-panel ews-no-print">
        <div class="ews-swap-panel-head">
            <div><div class="ews-dash-kicker">SCHEDULE SWAP</div><h3>Need to swap a day?</h3><p>Choose a colleague and request a direct schedule swap. No approval is required.</p></div>
            <button type="button" class="ews-btn" id="ews-open-swap">⇄ Request Swap</button>
        </div>
        <div id="ews-swap-form-wrap" class="ews-swap-form-wrap" hidden>
            <?php if ($swap_days): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-swap-form">
                <?php wp_nonce_field('ews_swap_create'); ?>
                <input type="hidden" name="action" value="ews_swap_create">
                <div><label>Day</label><select name="work_date" required>
                    <?php foreach ($swap_days as $d => $mine): ?><option value="<?php echo esc_attr($d); ?>"><?php echo esc_html(date('l, d M', strtotime($d)) . ' — ' . $mine); ?></option><?php endforeach; ?>
                </select></div>
                <div><label>Swap with</label><select name="target_employee_id" required>
                    <option value="">Select colleague</option>
                    <?php foreach ($emps as $e): if ((int) $e->id !== $current_emp_id): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endif; endforeach; ?>
                </select></div>
                <div class="ews-swap-form-actions"><button class="ews-btn" type="submit">Send Request</button><button class="ews-btn secondary" type="button" id="ews-close-swap">Cancel</button></div>
            </form>
            <?php else: ?>
            <div class="ews-swap-week-empty">You have no Office or WFH days left this week to swap. <button class="ews-btn secondary" type="button" id="ews-close-swap">Close</button></div>
            <?php endif; ?>
        </div>

        <div class="ews-swap-requests">
            <div class="ews-swap-requests-title">Swap Requests · <?php echo esc_html($week_label); ?></div>
            <?php if ($swap_requests): foreach ($swap_requests as $sr): $incoming = (int) $sr->target_employee_id === $current_emp_id; ?>
            <div class="ews-swap-request">
                <div class="ews-swap-request-main"><div class="ews-swap-avatar">⇄</div><div><strong><?php echo esc_html($incoming ? $sr->requester_name : $sr->target_name); ?></strong><span><?php echo esc_html(date('D, d M', strtotime($sr->work_date))); ?> · <?php echo esc_html($sr->requester_status . ' ↔ ' . $sr->target_status); ?></span></div></div>
                <div class="ews-swap-request-right"><span class="ews-swap-state <?php echo esc_attr(strtolower($sr->status)); ?>"><?php echo esc_html($sr->status); ?></span>
                <?php if ($incoming && $sr->status === 'Pending'): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_swap_respond_' . (int) $sr->id); ?><input type="hidden" name="action" value="ews_swap_respond"><input type="hidden" name="swap_id" value="<?php echo (int) $sr->id; ?>"><button class="ews-swap-small accept" name="decision" value="accept">Accept</button><button class="ews-swap-small reject" name="decision" value="reject">Reject</button></form>
                <?php elseif (!$incoming && $sr->status === 'Pending'): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_swap_cancel_' . (int) $sr->id); ?><input type="hidden" name="action" value="ews_swap_cancel"><input type="hidden" name="swap_id" value="<?php echo (int) $sr->id; ?>"><button class="ews-swap-small reject" type="submit">Cancel</button></form>
                <?php endif; ?></div>
            </div>
            <?php endforeach; else: ?>
                <?php echo $no_swaps_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by ews_empty_state() ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ews-swap-hint">
        <span>⇄</span>
        <div><strong>Planning a swap?</strong><small>Use this shared schedule to find a colleague with a compatible day. You can request a swap directly from this page.</small></div>
    </div>
</div>
