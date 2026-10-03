<?php
/**
 * wp-admin → Auto Attendance: add / edit a rule, the list of rules, and bulk Sign Out.
 * Script: assets/js/admin-organization.js (confirms Delete and bulk Sign Out).
 *
 * @var object|null $edit            the rule being edited
 * @var object[] $rows               rules with name, domain_name, when, overnight, toggle_url, delete_url
 * @var array<int,bool> $selected_days
 * @var bool $saved
 * @var array{done:int,skipped:int,failed:int}|null $bulk  result of a bulk Sign Out
 * @var object[] $emps               employees with attendance tracking
 * @var string $working_days_label
 * @var string $bulk_url
 */
if (!defined('ABSPATH')) exit;
$names = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
?>
<div class="wrap"><h1>Auto Attendance</h1><p>Automatically record real Sign In / Sign Out events for selected employees. Leave days and company holidays are skipped and recurring rules remain active.</p>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Auto Attendance Rule saved.</p></div><?php endif; ?>
<?php if ($bulk): ?><div class="notice notice-success is-dismissible"><p>Auto Sign Out completed for <strong><?php echo (int) $bulk['done']; ?></strong> signed-in employee<?php echo $bulk['done'] === 1 ? '' : 's'; ?>.<?php echo $bulk['skipped'] ? ' ' . (int) $bulk['skipped'] . ' skipped.' : ''; ?><?php echo $bulk['failed'] ? ' ' . (int) $bulk['failed'] . ' failed.' : ''; ?></p></div><?php endif; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;max-width:900px"><h2><?php echo $edit ? 'Edit Rule' : 'Add Rule'; ?></h2>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_auto_attendance_save')); ?>"><input type="hidden" name="action" value="ews_auto_attendance_save"><input type="hidden" name="rule_id" value="<?php echo (int) ($edit->id ?? 0); ?>">
    <table class="form-table">
        <tr><th>Employee</th><td><select name="employee_id" required><option value="">Select employee</option><?php foreach ($emps as $e): ?><option value="<?php echo (int) $e->id; ?>" <?php selected((int) ($edit->employee_id ?? 0), (int) $e->id); ?>><?php echo esc_html($e->name . ' (' . $e->domain_name . ')'); ?></option><?php endforeach; ?></select></td></tr>
        <tr><th>Mode</th><td><label><input type="radio" name="recurrence" value="one_time" <?php checked($edit->recurrence ?? 'one_time', 'one_time'); ?>> One-time</label> &nbsp; <label><input type="radio" name="recurrence" value="weekly" <?php checked($edit->recurrence ?? '', 'weekly'); ?>> Recurring</label></td></tr>
        <tr><th>Date</th><td><input type="date" name="run_date" value="<?php echo esc_attr($edit->run_date ?? ''); ?>"> <span class="description">Used for one-time rules.</span></td></tr>
        <tr><th>Recurring days</th><td><?php foreach ($names as $d => $n): ?><label style="display:inline-block;margin-right:12px"><input type="checkbox" name="weekdays[]" value="<?php echo (int) $d; ?>" <?php checked(isset($selected_days[$d])); ?>> <?php echo esc_html($n); ?></label><?php endforeach; ?>
            <p class="description">Defaults to the configured working days (currently <?php echo esc_html($working_days_label); ?>).</p></td></tr>
        <tr><th>Auto Sign In</th><td><input type="time" name="sign_in_time" value="<?php echo esc_attr(substr((string) ($edit->sign_in_time ?? ''), 0, 5)); ?>"></td></tr>
        <tr><th>Auto Sign Out</th><td><input type="time" name="sign_out_time" value="<?php echo esc_attr(substr((string) ($edit->sign_out_time ?? ''), 0, 5)); ?>"><p class="description">A Sign Out earlier than the Sign In (e.g. 22:00 → 06:00) is an overnight shift: it is recorded the next morning, on the day the shift started.</p></td></tr>
        <tr><th>Status</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked((int) ($edit->enabled ?? 1), 1); ?>> Enabled</label></td></tr>
    </table>
    <p><button class="button button-primary">Save Rule</button> <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-auto-attendance')); ?>">Cancel</a><?php endif; ?></p>
</form></div>

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:22px 0 10px"><h2 style="margin:0">Rules</h2>
    <a class="button button-secondary" href="<?php echo esc_url($bulk_url); ?>" data-ews-confirm="Auto Sign Out all employees who have signed in today and have not signed out yet?">Auto Sign Out All Signed-In</a></div>
<table class="widefat striped"><thead><tr><th>Employee</th><th>Mode</th><th>Days / Date</th><th>Sign In</th><th>Sign Out</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">No Auto Attendance Rules configured.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
    <tr><td><strong><?php echo esc_html($r->name); ?></strong><br><small><?php echo esc_html($r->domain_name); ?></small></td><td><?php echo esc_html($r->recurrence === 'one_time' ? 'One-time' : 'Recurring'); ?></td><td><?php echo esc_html($r->when); ?></td>
        <td><?php echo esc_html($r->sign_in_time ? substr((string) $r->sign_in_time, 0, 5) : '—'); ?></td><td><?php echo esc_html($r->sign_out_time ? substr((string) $r->sign_out_time, 0, 5) . ($r->overnight ? ' (next day)' : '') : '—'); ?></td>
        <td><?php if ($r->enabled): ?><span style="color:#16803c;font-weight:600">Enabled</span><?php else: ?><span style="color:#777">Disabled</span><?php endif; ?></td>
        <td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ews31-auto-attendance&edit_rule=' . (int) $r->id)); ?>">Edit</a> <a class="button button-small" href="<?php echo esc_url($r->toggle_url); ?>"><?php echo $r->enabled ? 'Disable' : 'Enable'; ?></a> <a class="button button-small" href="<?php echo esc_url($r->delete_url); ?>" data-ews-confirm="Delete this Auto Attendance Rule?">Delete</a></td></tr>
<?php endforeach; ?>
</tbody></table>
<p class="description">The scheduler checks every five minutes. If it runs late, the attendance event keeps the configured rule time. Existing Sign In / Sign Out events are never duplicated.</p></div>
