<?php
/**
 * wp-admin "Leaves" → "Back at work" and "Changed leave days" (Leave return, 3.31.161, docs/tasks/leave-return.md §4.4, §4.6;
 * mockup docs/mockups/leave-return/). Only with Leave return on.
 *
 * @var string $tab        'return' | 'changed'
 * @var string $post_url
 * @var string $page_url
 * @var array<int,object> $employees  active employees (id, name)
 * @var string $error      a refusal, translated
 * @var string $done       'recorded' | 'restored' | ''
 * Back at work:
 * @var int $emp
 * @var array<int,object> $leaves      the employee's approved leaves with leave days (id, start_date, end_date, requested_days, type_name, cancellation_status)
 * @var object|null $leave
 * @var string $back
 * @var string $reason
 * @var array<string,mixed>|null $preview  leave_return_preview()
 * @var string $check      why it cannot be recorded ('' = it can)
 * Changed leave days:
 * @var array<int,array<string,mixed>> $rows
 * @var int $total
 * @var int $paged
 * @var int $pages
 * @var string $from
 * @var string $to
 * @var int $employee
 */
if (!defined('ABSPATH')) exit;
$num = static function ($n) { return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'); };
$tab_link = static function (string $t, string $label) use ($tab, $page_url): string {
    return '<a class="nav-tab' . ($t === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($t === '' ? $page_url : add_query_arg('tab', $t, $page_url)) . '">' . esc_html($label) . '</a>';
};
?>
<div class="wrap"><h1><?php esc_html_e('Leave Management', 'workforce-one'); ?></h1>
<nav class="nav-tab-wrapper" style="margin-bottom:12px"><?php echo $tab_link('', __('Types and balances', 'workforce-one')) . $tab_link('return', __('Back at work', 'workforce-one')) . $tab_link('changed', __('Changed leave days', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?></nav>
<?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<?php if ($done === 'recorded'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('The return was recorded. The days went back to the balance, and the employee and their manager were told.', 'workforce-one'); ?></p></div><?php endif; ?>
<?php if ($done === 'restored'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('The day is leave again in the schedule.', 'workforce-one'); ?></p></div><?php endif; ?>

<?php if ($tab === 'return'): ?>
<div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:1000px">
    <h2 style="margin-top:0"><?php esc_html_e('Record that an employee was back at work', 'workforce-one'); ?></h2>
    <p style="color:#50575e"><?php esc_html_e('For an employee who came back early and did not ask. The days from the day they were back go back to their balance and their schedule. A reason is required, and it is kept in the Audit Log. Days in a closed payroll month stay leave. You cannot record it on your own leave.', 'workforce-one'); ?></p>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" data-lr-form>
        <input type="hidden" name="page" value="ews31-leaves"><input type="hidden" name="tab" value="return">
        <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px;max-width:760px">
            <label><strong><?php esc_html_e('Employee', 'workforce-one'); ?></strong><br><select name="lr_emp" onchange="this.form.submit()" style="width:100%;margin-top:5px"><option value=""><?php esc_html_e('Select employee', 'workforce-one'); ?></option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"<?php selected($emp, (int) $e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?>
            </select></label>
            <?php if ($emp): ?>
            <label><strong><?php esc_html_e('Approved leave', 'workforce-one'); ?></strong><br><select name="lr_leave" style="width:100%;margin-top:5px"<?php echo $leaves ? '' : ' disabled'; ?>>
                <?php if (!$leaves): ?><option value=""><?php esc_html_e('No approved leave with leave days', 'workforce-one'); ?></option><?php endif; ?>
                <?php foreach ($leaves as $l): ?><option value="<?php echo (int) $l->id; ?>"<?php selected($leave ? (int) $leave->id : 0, (int) $l->id); ?>><?php echo esc_html(sprintf('#%d %s · %s → %s (%s)', (int) $l->id, $l->type_name, $l->start_date, $l->end_date, $num($l->requested_days)) . ((string) $l->cancellation_status === 'Pending' ? ' · ' . __('a change is waiting', 'workforce-one') : '')); ?></option><?php endforeach; ?>
            </select></label>
            <label><strong><?php esc_html_e('Back at work on', 'workforce-one'); ?></strong><br><input type="date" name="lr_back" value="<?php echo esc_attr($back); ?>" required style="width:100%;margin-top:5px"></label>
            <label><strong><?php esc_html_e('Reason (required)', 'workforce-one'); ?></strong><br><input type="text" name="lr_reason" value="<?php echo esc_attr($reason); ?>" required maxlength="500" style="width:100%;margin-top:5px"></label>
            <?php endif; ?>
        </div>
        <?php if ($emp && $leaves): ?><p><button class="button"><?php esc_html_e('Preview', 'workforce-one'); ?></button></p><?php endif; ?>
    </form>

    <?php if ($preview && $leave): ?>
    <div style="border:1px solid #c3c4c7;border-left:4px solid <?php echo $check === '' ? '#2271b1' : '#d63638'; ?>;padding:12px 16px;margin-top:16px;max-width:760px" data-lr-preview>
        <h3 style="margin-top:0"><?php echo esc_html(sprintf(/* translators: 1: leave number, 2: type, 3: first day, 4: last day */ __('Leave #%1$d %2$s, %3$s → %4$s', 'workforce-one'), (int) $leave->id, $leave->type_name, $leave->start_date, $leave->end_date)); ?></h3>
        <?php if ($check !== ''): ?><p style="color:#d63638"><strong><?php echo esc_html($check); ?></strong></p>
        <?php else: ?>
        <ul style="list-style:disc;margin-left:20px">
            <li><?php echo esc_html($preview['whole'] ? sprintf(/* translators: %s: number of days */ __('All of it is cancelled: %s day(s) go back to the balance.', 'workforce-one'), $num($preview['back_days'])) : sprintf(/* translators: 1: first day returned, 2: number of days, 3: new last day */ __('The days from %1$s on go back (%2$s day(s)); the leave now ends on %3$s.', 'workforce-one'), $preview['effective'], $num($preview['back_days']), $preview['end_after'])); ?></li>
            <?php if ($preview['effective'] !== $preview['asked']): ?><li><?php echo esc_html(sprintf(/* translators: 1: closed months, 2: first day returned */ __('Payroll is closed for %1$s: those days stay leave, so the return starts on %2$s.', 'workforce-one'), implode(', ', $preview['closed']), $preview['effective'])); ?></li><?php endif; ?>
            <?php if ($preview['used_after'] !== null): ?><li><?php echo esc_html(sprintf(/* translators: 1: days used after, 2: entitlement */ __('The balance after: %1$s used of %2$s.', 'workforce-one'), $num($preview['used_after']), $num($preview['entitlement']))); ?></li><?php endif; ?>
            <?php if ($preview['pending_change']): ?><li><?php esc_html_e('The employee\'s change request still waiting is closed, and they are told.', 'workforce-one'); ?></li><?php endif; ?>
        </ul>
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_leave_return_record'); ?>
            <input type="hidden" name="action" value="ews_leave_return_record"><input type="hidden" name="leave_id" value="<?php echo (int) $leave->id; ?>">
            <input type="hidden" name="back_on" value="<?php echo esc_attr($back); ?>"><input type="hidden" name="reason" value="<?php echo esc_attr($reason); ?>">
            <input type="hidden" name="expect_from" value="<?php echo esc_attr((string) $preview['effective']); ?>"><input type="hidden" name="expect_days" value="<?php echo (int) $preview['days']; ?>">
            <button class="button button-primary"><?php esc_html_e('Record the return', 'workforce-one'); ?></button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:1200px">
    <h2 style="margin-top:0"><?php esc_html_e('Changed leave days', 'workforce-one'); ?></h2>
    <p style="color:#50575e"><?php esc_html_e('Past days of approved leaves that are no longer leave in the schedule (a manager changed them before leave days were locked). They are still charged as leave. Record a return if the employee worked, or put the day back to leave.', 'workforce-one'); ?></p>
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
        <input type="hidden" name="page" value="ews31-leaves"><input type="hidden" name="tab" value="changed">
        <label><?php esc_html_e('From', 'workforce-one'); ?><br><input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
        <label><?php esc_html_e('To', 'workforce-one'); ?><br><input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
        <label><?php esc_html_e('Employee', 'workforce-one'); ?><br><select name="employee"><option value="0"><?php esc_html_e('Everyone', 'workforce-one'); ?></option><?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"<?php selected($employee, (int) $e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></label>
        <button class="button"><?php esc_html_e('Show', 'workforce-one'); ?></button>
    </form>
    <?php if (!$rows): ?><p data-lr-empty><?php esc_html_e('No changed leave day in this period.', 'workforce-one'); ?></p>
    <?php else: ?>
    <table class="widefat striped" data-lr-changed>
        <thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Leave', 'workforce-one'); ?></th><th><?php esc_html_e('Day', 'workforce-one'); ?></th><th><?php esc_html_e('Schedule now', 'workforce-one'); ?></th><th><?php esc_html_e('Changed by', 'workforce-one'); ?></th><th><?php esc_html_e('Attendance', 'workforce-one'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr data-snap="<?php echo (int) $r['snap_id']; ?>">
                <td><?php echo esc_html($r['employee']); ?></td>
                <td><?php echo esc_html(sprintf('#%d %s · %s → %s', $r['leave_id'], $r['type'], $r['start'], $r['end'])); ?></td>
                <td><?php echo esc_html($r['day']); ?></td>
                <td><?php echo esc_html($r['status'] !== '' ? $r['status'] : __('No schedule row', 'workforce-one')); ?></td>
                <td><?php echo esc_html(trim($r['by'] . ' ' . $r['at'])); ?></td>
                <td><?php echo esc_html($r['in'] !== '' ? trim($r['in'] . ' – ' . $r['out'], ' –') : __('No record', 'workforce-one')); ?></td>
                <td>
                <?php if ($r['closed']): ?><em><?php esc_html_e('Payroll closed: stays leave', 'workforce-one'); ?></em>
                <?php else: ?>
                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(['tab' => 'return', 'lr_emp' => $r['employee_id'], 'lr_leave' => $r['leave_id'], 'lr_back' => $r['day']], $page_url)); ?>"><?php esc_html_e('Back at work from this day', 'workforce-one'); ?></a>
                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline"><?php wp_nonce_field('ews_leave_day_restore'); ?><input type="hidden" name="action" value="ews_leave_day_restore"><input type="hidden" name="snap_id" value="<?php echo (int) $r['snap_id']; ?>">
                        <input type="hidden" name="from" value="<?php echo esc_attr($from); ?>"><input type="hidden" name="to" value="<?php echo esc_attr($to); ?>"><input type="hidden" name="employee" value="<?php echo (int) $employee; ?>"><input type="hidden" name="paged" value="<?php echo (int) $paged; ?>">
                        <button class="button button-small"><?php esc_html_e('Put the day back to leave', 'workforce-one'); ?></button></form>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><?php echo esc_html(sprintf(/* translators: 1: days, 2: page, 3: pages */ __('%1$d day(s) · page %2$d of %3$d', 'workforce-one'), $total, $paged, $pages)); ?>
        <?php if ($paged > 1): ?><a href="<?php echo esc_url(add_query_arg(['tab' => 'changed', 'from' => $from, 'to' => $to, 'employee' => $employee, 'paged' => $paged - 1], $page_url)); ?>"><?php esc_html_e('Newer', 'workforce-one'); ?></a><?php endif; ?>
        <?php if ($paged < $pages): ?><a href="<?php echo esc_url(add_query_arg(['tab' => 'changed', 'from' => $from, 'to' => $to, 'employee' => $employee, 'paged' => $paged + 1], $page_url)); ?>"><?php esc_html_e('Older', 'workforce-one'); ?></a><?php endif; ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>
