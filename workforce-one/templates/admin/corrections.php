<?php
/**
 * wp-admin → Attendance Corrections (3.31.74): the requests (filters, decide), the report, the
 * settings and a direct correction by HR. Styles: assets/css/admin-corrections.css.
 *
 * @var string $tab            list | report | settings | direct
 * @var bool   $enabled
 * @var string $features_url
 * @var string $post_url
 * @var callable $url          admin page URL with arguments
 * @var callable $type_label
 * @var callable $status_label
 * @var callable $change
 * @var string $notice
 * @var string $error
 * @var object[] $departments
 * @var string $month
 * @var int    $department
 * Requests tab:
 * @var object[] $rows
 * @var array<string,int> $counts
 * @var string $status
 * @var string $type
 * @var string $q
 * @var int    $paged
 * @var int    $pages
 * @var int    $total
 * Report tab:
 * @var array<int,array<string,mixed>> $people
 * @var array<string,array<string,int>> $depts
 * @var array<string,int> $totals
 * @var int    $frequent
 * @var string $export_url
 * Settings tab:
 * @var array<string,mixed> $s
 * @var array<string,string> $types
 * @var string $workflows_url
 * Direct correction:
 * @var object[] $employees
 * @var array<string,string> $old
 */
if (!defined('ABSPATH')) exit;
$tabs = ['list' => __('Requests', 'workforce-one'), 'report' => __('Report', 'workforce-one'), 'settings' => __('Settings', 'workforce-one')];
$badge = ['pending' => 'is-wait', 'pending_hr' => 'is-hr', 'approved' => 'is-ok', 'rejected' => 'is-bad'];
$dept_select = static function (int $current) use ($departments): string {
    $o = '<select name="department"><option value="0">' . esc_html__('All departments', 'workforce-one') . '</option>';
    foreach ($departments as $d) $o .= '<option value="' . (int) $d->id . '"' . selected($current, (int) $d->id, false) . '>' . esc_html($d->name) . '</option>';
    return $o . '</select>';
};
?>
<div class="wrap ews-corrections">
    <h1 class="wp-heading-inline"><?php esc_html_e('Attendance Corrections', 'workforce-one'); ?></h1>
    <a href="<?php echo esc_url($url(['tab' => 'direct'])); ?>" class="page-title-action"><?php esc_html_e('Correct directly', 'workforce-one'); ?></a>
    <hr class="wp-header-end">
    <?php if (!$enabled): ?><div class="notice notice-warning"><p><?php esc_html_e('Attendance corrections are switched off.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Feature Configuration', 'workforce-one'); ?></a></p></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
    <?php if ($notice === 'direct'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Correction saved. The original record is kept and the correction is in the Audit Log.', 'workforce-one'); ?></p></div>
    <?php elseif (in_array($notice, ['approved', 'rejected', 'advanced', 'pending_hr'], true)): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice === 'approved' ? __('Approved: the day is corrected.', 'workforce-one') : ($notice === 'rejected' ? __('Rejected. The employee will see your note.', 'workforce-one') : __('Approved at this level.', 'workforce-one'))); ?></p></div><?php endif; ?>
    <?php if ($tab !== 'direct'): ?>
    <nav class="nav-tab-wrapper ews-cx-tabs"><?php foreach ($tabs as $k => $label): ?><a href="<?php echo esc_url($url(['tab' => $k])); ?>" class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
    <?php endif; ?>

<?php if ($tab === 'list'):
    $st = ['all' => __('All', 'workforce-one'), 'pending' => __('Waiting', 'workforce-one'), 'pending_hr' => __('Waiting for HR', 'workforce-one'), 'approved' => __('Approved', 'workforce-one'), 'rejected' => __('Rejected', 'workforce-one')];
    $keep = ['tab' => 'list', 'month' => $month, 'department' => $department ?: null, 'type' => $type ?: null, 's' => $q !== '' ? $q : null];
?>
    <ul class="subsubsub"><?php $i = 0; foreach ($st as $k => $label): ?><li><?php echo $i++ ? ' | ' : ''; ?><a href="<?php echo esc_url($url(array_filter($keep + ['status' => $k === 'all' ? null : $k]))); ?>"<?php echo ($status === $k || ($k === 'all' && !isset($counts[$status]))) ? ' class="current"' : ''; ?>><?php echo esc_html($label); ?> <span class="count">(<?php echo (int) ($counts[$k] ?? 0); ?>)</span></a></li><?php endforeach; ?></ul>
    <br class="clear">
    <form method="get" class="ews-cx-filters">
        <input type="hidden" name="page" value="ews31-corrections"><input type="hidden" name="tab" value="list"><?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>"><?php endif; ?>
        <select name="type"><option value=""><?php esc_html_e('All types', 'workforce-one'); ?></option><?php foreach (['out', 'in', 'time', 'day'] as $t): ?><option value="<?php echo esc_attr($t); ?>"<?php selected($type, $t); ?>><?php echo esc_html($type_label($t)); ?></option><?php endforeach; ?></select>
        <?php echo $dept_select($department); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?>
        <input type="search" name="s" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Employee', 'workforce-one'); ?>">
        <input type="month" name="month" value="<?php echo esc_attr($month); ?>">
        <button class="button"><?php esc_html_e('Filter', 'workforce-one'); ?></button>
    </form>
    <div class="tablenav top"><div class="tablenav-pages"><span class="displaying-num"><?php /* translators: %d: number of requests */ echo esc_html(sprintf(_n('%d request', '%d requests', $total, 'workforce-one'), $total)); ?></span>
        <?php if ($pages > 1): ?><span class="pagination-links"><?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url($url(array_filter($keep + ['status' => $status ?: null, 'paged' => $paged - 1]))); ?>">‹</a><?php endif; ?> <?php echo esc_html($paged . ' / ' . $pages); ?> <?php if ($paged < $pages): ?><a class="button" href="<?php echo esc_url($url(array_filter($keep + ['status' => $status ?: null, 'paged' => $paged + 1]))); ?>">›</a><?php endif; ?></span><?php endif; ?></div><br class="clear"></div>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Day', 'workforce-one'); ?></th><th><?php esc_html_e('Type', 'workforce-one'); ?></th><th><?php esc_html_e('Change', 'workforce-one'); ?></th><th><?php esc_html_e('Reason', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th><?php esc_html_e('Requested', 'workforce-one'); ?></th><th style="width:230px"><?php esc_html_e('Decision', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr class="no-items"><td colspan="8"><?php esc_html_e('No correction requests with these filters.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $c):
            $who = $c->status === 'approved' || $c->status === 'rejected' ? (string) $c->decided_name : ($c->status === 'pending_hr' ? __('HR', 'workforce-one') : '');
        ?>
            <tr>
                <td><strong><?php echo esc_html((string) $c->employee_name); ?></strong><span class="ews-cx-sub"><?php echo esc_html((string) ($c->department_name ?? '')); ?></span></td>
                <td><?php echo esc_html(date_i18n('D j M Y', strtotime($c->work_date))); ?></td>
                <td><?php echo esc_html($type_label($c->type)); ?><?php if ($c->source === 'hr'): ?><span class="ews-cx-sub"><?php esc_html_e('Direct by HR', 'workforce-one'); ?></span><?php endif; ?></td>
                <td dir="auto"><?php echo esc_html($change($c)); ?><?php if ($c->payroll_note): ?><span class="ews-cx-sub"><?php /* translators: %s: month and amount */ echo esc_html(sprintf(__('Payroll: carried to %s', 'workforce-one'), $c->payroll_note)); ?></span><?php endif; ?></td>
                <td><?php echo esc_html((string) $c->reason); ?><?php if ($c->photo_url): ?><span class="ews-cx-sub"><a href="<?php echo esc_url($c->photo_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Photo', 'workforce-one'); ?></a></span><?php endif; ?><?php if ($c->decision_note): ?><span class="ews-cx-sub">“<?php echo esc_html($c->decision_note); ?>”</span><?php endif; ?></td>
                <td><span class="ews-cx-badge <?php echo esc_attr($badge[$c->status] ?? ''); ?>"><?php echo esc_html($status_label($c->status)); ?></span><?php if ($who !== ''): ?><span class="ews-cx-sub"><?php echo esc_html($who); ?></span><?php endif; ?><?php if ((int) $c->earlier || (int) $c->above_limit): ?><span class="ews-cx-sub ews-cx-flag"><?php echo esc_html((int) $c->earlier ? __('Sign In earlier', 'workforce-one') : __('Above the limit', 'workforce-one')); ?></span><?php endif; ?></td>
                <td><?php echo esc_html(date_i18n('j M H:i', strtotime($c->requested_at))); ?></td>
                <td class="ews-cx-actions"><?php if (in_array($c->status, ['pending', 'pending_hr'], true) && (int) $c->employee_user_id !== get_current_user_id()): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_correction_decide_' . (int) $c->id); ?><input type="hidden" name="action" value="ews_correction_decide"><input type="hidden" name="in_admin" value="1"><input type="hidden" name="correction_id" value="<?php echo (int) $c->id; ?>">
                        <input type="text" name="note" placeholder="<?php esc_attr_e('Note (required to reject)', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Note to the employee', 'workforce-one'); ?>">
                        <button class="button button-primary button-small" name="decision" value="approve"><?php esc_html_e('Approve', 'workforce-one'); ?></button><button class="button button-small" name="decision" value="reject"><?php esc_html_e('Reject', 'workforce-one'); ?></button></form>
                <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php elseif ($tab === 'report'):
    $max = max(1, ...array_map(static function ($p) { return (int) $p['all']; }, $people ?: [['all' => 1]]));
?>
    <form method="get" class="ews-cx-filters"><input type="hidden" name="page" value="ews31-corrections"><input type="hidden" name="tab" value="report">
        <input type="month" name="month" value="<?php echo esc_attr($month); ?>"><?php echo $dept_select($department); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?>
        <button class="button"><?php esc_html_e('Show', 'workforce-one'); ?></button><span style="flex:1"></span><a class="button" href="<?php echo esc_url($export_url); ?>"><?php esc_html_e('Download CSV', 'workforce-one'); ?></a></form>
    <div class="ews-cx-kpis">
        <div class="ews-cx-kpi"><b><?php echo (int) $totals['all']; ?></b><span><?php esc_html_e('requests', 'workforce-one'); ?></span></div>
        <div class="ews-cx-kpi"><b><?php echo (int) $totals['approved']; ?></b><span><?php echo esc_html($totals['all'] ? sprintf(/* translators: %d: percent */ __('approved (%d%%)', 'workforce-one'), (int) round(100 * $totals['approved'] / $totals['all'])) : __('approved', 'workforce-one')); ?></span></div>
        <div class="ews-cx-kpi"><b><?php echo (int) $totals['rejected']; ?></b><span><?php esc_html_e('rejected', 'workforce-one'); ?></span></div>
        <div class="ews-cx-kpi"><b><?php echo (int) $totals['open']; ?></b><span><?php esc_html_e('waiting', 'workforce-one'); ?></span></div>
    </div>
    <h2><?php esc_html_e('By employee', 'workforce-one'); ?></h2>
    <p class="description"><?php /* translators: %d: number of requests */ echo esc_html(sprintf(__('"Frequent" = %d or more requests in a month: a pattern worth a look.', 'workforce-one'), $frequent)); ?></p>
    <table class="wp-list-table widefat striped"><thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Department', 'workforce-one'); ?></th><?php foreach (['out', 'in', 'time', 'day'] as $t): ?><th><?php echo esc_html($type_label($t)); ?></th><?php endforeach; ?><th><?php esc_html_e('Approved', 'workforce-one'); ?></th><th><?php esc_html_e('Rejected', 'workforce-one'); ?></th><th><?php esc_html_e('Total', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php if (!$people): ?><tr class="no-items"><td colspan="9"><?php esc_html_e('No correction requests this month.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($people as $p): ?><tr><td><strong><?php echo esc_html($p['name']); ?></strong><?php if ($p['all'] >= $frequent): ?> <span class="ews-cx-flag">· <?php esc_html_e('frequent', 'workforce-one'); ?></span><?php endif; ?></td><td><?php echo esc_html($p['dept']); ?></td>
            <?php foreach (['out', 'in', 'time', 'day'] as $t): ?><td><?php echo (int) $p[$t]; ?></td><?php endforeach; ?><td><?php echo (int) $p['approved']; ?></td><td><?php echo (int) $p['rejected']; ?></td>
            <td><span class="ews-cx-bar" style="width:<?php echo (int) round(80 * $p['all'] / $max); ?>px"></span><?php echo (int) $p['all']; ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <h2 style="margin-top:22px"><?php esc_html_e('By department', 'workforce-one'); ?></h2>
    <table class="widefat striped" style="max-width:640px"><thead><tr><th><?php esc_html_e('Department', 'workforce-one'); ?></th><th><?php esc_html_e('Requests', 'workforce-one'); ?></th><th><?php esc_html_e('Approved', 'workforce-one'); ?></th><th><?php esc_html_e('Rejected', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php if (!$depts): ?><tr class="no-items"><td colspan="4">—</td></tr><?php endif; ?>
        <?php foreach ($depts as $name => $d): ?><tr><td><?php echo esc_html($name !== '' ? $name : __('No department', 'workforce-one')); ?></td><td><?php echo (int) $d['all']; ?></td><td><?php echo (int) $d['approved']; ?></td><td><?php echo (int) $d['rejected']; ?></td></tr><?php endforeach; ?>
    </tbody></table>

<?php elseif ($tab === 'settings'):
    $serr = sanitize_key($_GET['cx_settings_error'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
    $msgs = ['deadline' => __('The deadline must be between 1 and 60 days.', 'workforce-one'), 'limit' => __('The monthly limit must be between 0 and 31.', 'workforce-one'), 'time' => __('Enter the reminder time as HH:MM.', 'workforce-one'), 'types' => __('Keep at least one kind of correction.', 'workforce-one')];
?>
    <?php if (isset($_GET['cx_saved'])): // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'workforce-one'); ?></p></div><?php endif; ?>
    <?php if (isset($msgs[$serr])): ?><div class="notice notice-error"><p><?php echo esc_html($msgs[$serr]); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-cx-card">
        <?php wp_nonce_field('ews_corrections_settings_save'); ?><input type="hidden" name="action" value="ews_corrections_settings_save">
        <table class="form-table" role="presentation">
            <tr><th scope="row"><?php esc_html_e('Kinds and photo', 'workforce-one'); ?></th><td>
                <table class="widefat ews-cx-types-table" style="max-width:520px"><thead><tr><th><?php esc_html_e('Kind', 'workforce-one'); ?></th><th><?php esc_html_e('Photo', 'workforce-one'); ?></th></tr></thead><tbody>
                <?php foreach ($types as $t => $label): ?><tr><td><label><input type="checkbox" name="cx_type_<?php echo esc_attr($t); ?>" value="1"<?php checked(!empty($s['types'][$t])); ?>> <?php echo esc_html($label); ?></label></td>
                    <td><select name="cx_photo_<?php echo esc_attr($t); ?>"><?php foreach (['off' => __('Off', 'workforce-one'), 'optional' => __('Optional', 'workforce-one'), 'required' => __('Required', 'workforce-one')] as $v => $vl): ?><option value="<?php echo esc_attr($v); ?>"<?php selected($s['photo'][$t], $v); ?>><?php echo esc_html($vl); ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
                </tbody></table></td></tr>
            <tr><th scope="row"><label for="cx_deadline_days"><?php esc_html_e('Deadline', 'workforce-one'); ?></label></th><td><input type="number" class="small-text" id="cx_deadline_days" name="cx_deadline_days" min="1" max="60" value="<?php echo (int) $s['deadline_days']; ?>"> <?php esc_html_e('days after the day', 'workforce-one'); ?><p class="description"><?php esc_html_e('After that, only HR can correct the day, directly.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><label for="cx_monthly_limit"><?php esc_html_e('Monthly limit per employee', 'workforce-one'); ?></label></th><td><input type="number" class="small-text" id="cx_monthly_limit" name="cx_monthly_limit" min="0" max="31" value="<?php echo (int) $s['monthly_limit']; ?>"> <span class="description"><?php esc_html_e('0 = no limit', 'workforce-one'); ?></span>
                <fieldset style="margin-top:8px"><label><input type="radio" name="cx_above_limit" value="refuse"<?php checked($s['above_limit'], 'refuse'); ?>> <?php esc_html_e('Above it: refused', 'workforce-one'); ?></label><br><label><input type="radio" name="cx_above_limit" value="hr"<?php checked($s['above_limit'], 'hr'); ?>> <?php esc_html_e('Above it: allowed, and HR approves it too (second level)', 'workforce-one'); ?></label></fieldset></td></tr>
            <tr><th scope="row"><?php esc_html_e('Earlier Sign In', 'workforce-one'); ?></th><td><label><input type="checkbox" name="cx_earlier_needs_hr" value="1"<?php checked(!empty($s['earlier_needs_hr'])); ?>> <?php esc_html_e('A request that moves Sign In earlier needs the second level (HR)', 'workforce-one'); ?></label><p class="description"><?php esc_html_e('It removes lateness, which changes pay.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><?php esc_html_e('End-of-day reminder', 'workforce-one'); ?></th><td><label><input type="checkbox" name="cx_reminder" value="1"<?php checked(!empty($s['reminder'])); ?>> <?php esc_html_e('Notify anyone still without a Sign Out at', 'workforce-one'); ?></label> <input type="time" name="cx_reminder_time" value="<?php echo esc_attr($s['reminder_time']); ?>"><p class="description"><?php esc_html_e('"You did not sign out today. Request a correction?" The notification opens the request form.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><?php esc_html_e('Approvals', 'workforce-one'); ?></th><td><p><?php esc_html_e('Who approves, and how many levels:', 'workforce-one'); ?> <a href="<?php echo esc_url($workflows_url); ?>"><?php esc_html_e('Approval Workflows → Attendance Correction', 'workforce-one'); ?></a>. <?php esc_html_e('Without an active workflow, managers with "Manage Time" decide for their department. The second level is anyone with "Manage Attendance Corrections".', 'workforce-one'); ?></p></td></tr>
        </table>
        <p class="submit"><button class="button button-primary"><?php esc_html_e('Save Changes', 'workforce-one'); ?></button></p>
    </form>

<?php else:
    $o = static function ($k) use ($old) { return (string) ($old[$k] ?? ''); };
?>
    <h2><?php esc_html_e('Correct attendance directly', 'workforce-one'); ?></h2>
    <p><?php esc_html_e('For cases the employee cannot request from the app (for example after the deadline). Same path as requests: the original record never changes.', 'workforce-one'); ?> <a href="<?php echo esc_url($url(['tab' => 'list'])); ?>"><?php esc_html_e('Back to the requests', 'workforce-one'); ?></a></p>
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-cx-card">
        <?php wp_nonce_field('ews_correction_direct'); ?><input type="hidden" name="action" value="ews_correction_direct">
        <datalist id="ews-pick-employees"><?php foreach ($employees as $e): ?><option value="<?php echo esc_attr(\WorkforceOne\Support\Picker::label((string) $e->name . ' (' . $e->domain_name . ')', (int) $e->id)); ?>"></option><?php endforeach; ?></datalist>
        <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="cx-emp"><?php esc_html_e('Employee', 'workforce-one'); ?></label></th><td><input type="text" id="cx-emp" class="regular-text" name="employee_ref" list="ews-pick-employees" value="<?php echo esc_attr($o('employee_ref')); ?>" required><p class="description"><?php esc_html_e('Type a few letters and pick from the list.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><label for="cx-day"><?php esc_html_e('Day', 'workforce-one'); ?></label></th><td><input type="date" id="cx-day" name="date" value="<?php echo esc_attr($o('date')); ?>" max="<?php echo esc_attr(current_time('Y-m-d')); ?>" required></td></tr>
            <tr><th scope="row"><label for="cx-type"><?php esc_html_e('Kind', 'workforce-one'); ?></label></th><td><select id="cx-type" name="type"><?php foreach ($types as $t => $label): ?><option value="<?php echo esc_attr($t); ?>"<?php selected($o('type'), $t); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                <select name="target" aria-label="<?php esc_attr_e('Which time is wrong?', 'workforce-one'); ?>"><option value="sign_in"<?php selected($o('target'), 'sign_in'); ?>><?php esc_html_e('Sign In', 'workforce-one'); ?></option><option value="sign_out"<?php selected($o('target'), 'sign_out'); ?>><?php esc_html_e('Sign Out', 'workforce-one'); ?></option></select>
                <p class="description"><?php esc_html_e('"Which time" is used for "Wrong time" only.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><?php esc_html_e('Correct times', 'workforce-one'); ?></th><td><label><?php esc_html_e('Sign In', 'workforce-one'); ?> <input type="time" name="time_in" value="<?php echo esc_attr($o('time_in')); ?>"></label> &nbsp; <label><?php esc_html_e('Sign Out', 'workforce-one'); ?> <input type="time" name="time_out" value="<?php echo esc_attr($o('time_out')); ?>"></label><p class="description"><?php esc_html_e('Fill the time the kind needs: Sign Out for "Forgot Sign Out", Sign In for "Forgot Sign In", both for a whole day.', 'workforce-one'); ?></p></td></tr>
            <tr><th scope="row"><label for="cx-reason"><?php esc_html_e('Reason', 'workforce-one'); ?> <span style="color:#d63638">*</span></label></th><td><textarea id="cx-reason" class="large-text" rows="3" name="reason" required><?php echo esc_textarea($o('reason')); ?></textarea><p class="description"><?php esc_html_e('Required. The employee sees it, and it goes to the Audit Log.', 'workforce-one'); ?></p></td></tr>
        </table>
        <p class="submit"><button class="button button-primary"><?php esc_html_e('Save correction', 'workforce-one'); ?></button></p>
    </form>
<?php endif; ?>
</div>
