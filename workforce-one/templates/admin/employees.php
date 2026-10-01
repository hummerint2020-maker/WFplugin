<?php
/**
 * wp-admin "Employees" page. Each table row is one update form: its fields point at the row's
 * <form> through the form="" attribute (a <form> cannot wrap table cells).
 *
 * @var array<int,array{row:object,supervisor_id:int,team_ids:int[]}> $employees
 * @var array<int,object> $teams          active teams (id, name, manager_employee_id, department_id)
 * @var array<int,string> $users          WordPress user id => label
 * @var array<int,string> $shifts         active shift id => label
 * @var array<int,string> $all_shifts     every shift id => label (an employee keeps an inactive shift until changed)
 * @var array<int,object> $departments    active departments (id, name)
 * @var string|null $notice
 * @var string|null $error
 * @var string $post_url
 * @var string $profile_url
 */
if (!defined('ABSPATH')) exit;
$options = static function (array $items, int $selected, string $none) {
    $html = '<option value="0">' . esc_html($none) . '</option>';
    foreach ($items as $value => $label) $html .= '<option value="' . (int) $value . '"' . selected($selected, (int) $value, false) . '>' . esc_html($label) . '</option>';
    return $html;
};
$active_names = [];
foreach ($employees as $e) if ((int) $e['row']->active) $active_names[(int) $e['row']->id] = $e['row']->name . ' (' . $e['row']->domain_name . ')';
$department_names = [];
foreach ($departments as $d) $department_names[(int) $d->id] = $d->name;
?>
<div class="wrap"><h1>Employees</h1>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="background:#fff;padding:18px;max-width:650px">
    <input type="hidden" name="action" value="ews31_employee_save"><?php wp_nonce_field('ews31_employee_save'); ?>
    <h2 style="margin-top:0">Add Employee</h2>
    <p><input name="name" required placeholder="Employee Name" style="width:100%"></p>
    <p><input name="domain_name" required placeholder="Domain Name" style="width:100%"></p>
    <p><input type="email" name="email" required placeholder="Employee Email" style="width:100%"></p>
    <p><select name="wp_user_id" style="width:100%"><?php echo $options($users, 0, '-- Link WordPress User --'); ?></select></p>
    <p><select name="department_id" style="width:100%"><?php echo $options($department_names, 0, '-- No Department --'); ?></select></p>
    <p><select name="supervisor_employee_id" style="width:100%"><?php echo $options($active_names, 0, '-- No Direct Supervisor --'); ?></select></p>
    <p><select name="default_shift_id" style="width:100%"><?php echo $options($shifts, 0, '-- Company Working Hours --'); ?></select></p>
    <p><label><input type="checkbox" name="attendance_enabled" value="1" checked> Attendance Tracking</label><br><small>Disable this for employees who should not have attendance tracking.</small></p>
    <p><button class="button button-primary">Add Employee</button></p>
</form><br>

<p>Update an employee's details below. Each WordPress user can be linked to one employee only.</p>
<table class="widefat striped"><thead><tr><th>Name</th><th>Domain Name</th><th>Email</th><th>WordPress User</th><th>Department</th><th>Supervisor</th><th>Teams</th><th>Default Shift</th><th>Attendance Tracking</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($employees as $e): $r = $e['row']; $id = (int) $r->id; $f = 'ews-employee-' . $id; ?>
    <tr<?php echo !(int) $r->active ? ' style="opacity:.72"' : ''; ?>>
        <td><input form="<?php echo $f; ?>" name="name" value="<?php echo esc_attr($r->name); ?>" required></td>
        <td><input form="<?php echo $f; ?>" name="domain_name" value="<?php echo esc_attr($r->domain_name); ?>" required></td>
        <td><input form="<?php echo $f; ?>" type="email" name="email" value="<?php echo esc_attr($r->email); ?>" required></td>
        <td><select form="<?php echo $f; ?>" name="wp_user_id"><?php echo $options($users, (int) $r->wp_user_id, '-- Not linked --'); ?></select></td>
        <td><select form="<?php echo $f; ?>" name="department_id"><?php echo $options($department_names, (int) ($r->department_id ?? 0), '-- No Department --'); ?></select></td>
        <td><select form="<?php echo $f; ?>" name="supervisor_employee_id"><?php $others = $active_names; unset($others[$id]); echo $options($others, $e['supervisor_id'], '-- No Supervisor --'); ?></select></td>
        <td><select form="<?php echo $f; ?>" name="team_ids[]" multiple size="3" style="min-width:180px">
            <?php foreach ($teams as $tm): ?><option value="<?php echo (int) $tm->id; ?>"<?php echo in_array((int) $tm->id, $e['team_ids'], true) ? ' selected' : ''; ?>><?php echo esc_html($tm->name . ((int) $tm->manager_employee_id === $id ? ' — Manager' : '')); ?></option><?php endforeach; ?>
        </select></td>
        <td><select form="<?php echo $f; ?>" name="default_shift_id"><?php $row_shifts = $shifts; if (isset($all_shifts[(int) $r->default_shift_id])) $row_shifts[(int) $r->default_shift_id] = $all_shifts[(int) $r->default_shift_id]; echo $options($row_shifts, (int) $r->default_shift_id, 'Company Hours'); ?></select></td>
        <td><label style="display:inline-flex;align-items:center;gap:5px"><input form="<?php echo $f; ?>" type="checkbox" name="attendance_enabled" value="1" <?php checked((int) ($r->attendance_enabled ?? 1), 1); ?>> Enabled</label><br><small>Attendance</small></td>
        <td><select form="<?php echo $f; ?>" name="active" style="min-width:100px"><option value="1" <?php selected((int) $r->active, 1); ?>>Active</option><option value="0" <?php selected((int) $r->active, 0); ?>>Inactive</option></select></td>
        <td style="white-space:nowrap">
            <form id="<?php echo $f; ?>" method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline"><input type="hidden" name="action" value="ews31_employee_update"><input type="hidden" name="id" value="<?php echo $id; ?>"><?php wp_nonce_field('ews31_employee_update'); ?><button class="button">Save</button></form>
            <a class="button" href="<?php echo esc_url(add_query_arg('employee_id', $id, $profile_url)); ?>">View Profile</a>
            <?php if ((int) $r->active): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline"><input type="hidden" name="action" value="ews31_employee_archive"><input type="hidden" name="id" value="<?php echo $id; ?>"><?php wp_nonce_field('ews31_employee_archive'); ?><button class="button" data-ews-confirm-key="employee_delete" style="color:#b32d2e">Archive</button></form>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody></table>
</div>
