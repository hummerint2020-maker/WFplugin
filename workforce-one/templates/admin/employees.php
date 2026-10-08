<?php
/**
 * wp-admin "Employees" page. Each table row is one update form: its fields point at the row's
 * <form> through the form="" attribute (a <form> cannot wrap table cells).
 * 3.31.71: 50 employees per page with a search; WordPress user and supervisor are typed into a field
 * that suggests from one shared list (Support\Picker), not a <select> with every name in every row.
 *
 * @var array<int,array{row:object,supervisor_id:int,team_ids:int[]}> $employees  this page of the list
 * @var array<int,object> $people         every employee (id, name, domain_name, active), by id
 * @var array<int,object> $teams          active teams (id, name, manager_employee_id, department_id)
 * @var array<int,string> $users          WordPress user id => label
 * @var array<int,string> $shifts         active shift id => label
 * @var array<int,string> $all_shifts     every shift id => label (an employee keeps an inactive shift until changed)
 * @var array<int,object> $departments    active departments (id, name)
 * @var string $search
 * @var int $paged
 * @var int $pages
 * @var int $total
 * @var string $page_url
 * @var string|null $notice
 * @var string|null $error
 * @var string $post_url
 * @var string $profile_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Support\Picker;
$options = static function (array $items, int $selected, string $none) {
    $html = '<option value="0">' . esc_html($none) . '</option>';
    foreach ($items as $value => $label) $html .= '<option value="' . (int) $value . '"' . selected($selected, (int) $value, false) . '>' . esc_html($label) . '</option>';
    return $html;
};
$person = static function (int $id) use ($people) {
    return isset($people[$id]) ? Picker::label($people[$id]->name . ' (' . $people[$id]->domain_name . ')', $id) : '';
};
$user_label = static function (int $id) use ($users) {
    return isset($users[$id]) ? Picker::label($users[$id], $id) : ($id ? '#' . $id : '');
};
$department_names = [];
foreach ($departments as $d) $department_names[(int) $d->id] = $d->name;
$pager = static function () use ($paged, $pages, $total, $search, $page_url) {
    if ($pages < 2) { echo '<span class="displaying-num">' . esc_html(sprintf('%d employees', $total)) . '</span>'; return; }
    $link = static function (int $n) use ($search, $page_url) { return esc_url(add_query_arg(array_filter(['paged' => $n > 1 ? $n : null, 's' => $search !== '' ? $search : null]), $page_url)); };
    echo '<span class="displaying-num">' . esc_html(sprintf('%d employees', $total)) . '</span> <span class="pagination-links">';
    echo $paged > 1 ? '<a class="button" href="' . $link($paged - 1) . '">&lsaquo; Previous</a> ' : '';
    echo '<span class="paging-input">' . esc_html(sprintf('Page %1$d of %2$d', $paged, $pages)) . '</span>';
    echo $paged < $pages ? ' <a class="button" href="' . $link($paged + 1) . '">Next &rsaquo;</a>' : '';
    echo '</span>';
};
?>
<div class="wrap"><h1>Employees</h1>
<datalist id="ews-pick-users"><?php foreach ($users as $uid => $label): ?><option value="<?php echo esc_attr(Picker::label($label, (int) $uid)); ?>"></option><?php endforeach; ?></datalist>
<datalist id="ews-pick-employees"><?php foreach ($people as $p): if (!(int) $p->active) continue; ?><option value="<?php echo esc_attr($person((int) $p->id)); ?>"></option><?php endforeach; ?></datalist>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<form method="post" action="<?php echo esc_url($post_url); ?>" style="background:#fff;padding:18px;max-width:650px">
    <input type="hidden" name="action" value="ews31_employee_save"><?php wp_nonce_field('ews31_employee_save'); ?>
    <h2 style="margin-top:0">Add Employee</h2>
    <p><input name="name" required placeholder="Employee Name" style="width:100%"></p>
    <p><input name="domain_name" required placeholder="Domain Name" style="width:100%"></p>
    <p><input type="email" name="email" required placeholder="Employee Email" style="width:100%"></p>
    <p><input name="wp_user_ref" list="ews-pick-users" autocomplete="off" placeholder="Link WordPress User (type to search)" style="width:100%"></p>
    <p><select name="department_id" style="width:100%"><?php echo $options($department_names, 0, '-- No Department --'); ?></select></p>
    <p><input name="supervisor_employee_ref" list="ews-pick-employees" autocomplete="off" placeholder="Direct Supervisor (type to search)" style="width:100%"></p>
    <p><select name="default_shift_id" style="width:100%"><?php echo $options($shifts, 0, '-- Company Working Hours --'); ?></select></p>
    <p><label><input type="checkbox" name="attendance_enabled" value="1" checked> Attendance Tracking</label><br><small>Disable this for employees who should not have attendance tracking.</small></p>
    <p><button class="button button-primary">Add Employee</button></p>
</form><br>

<p>Update an employee's details below. Each WordPress user can be linked to one employee only. In the WordPress User and Supervisor fields, type a few letters and pick from the list; clear the field to remove the link.</p>
<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="tablenav top" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;height:auto">
    <input type="hidden" name="page" value="ews31-employees">
    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Name, domain name or email" aria-label="Search employees">
    <button class="button">Search</button><?php if ($search !== ''): ?> <a class="button-link" href="<?php echo esc_url($page_url); ?>">Clear</a><?php endif; ?>
    <span style="margin-inline-start:auto"><?php $pager(); ?></span>
</form>
<table class="widefat striped"><thead><tr><th>Name</th><th>Domain Name</th><th>Email</th><th>WordPress User</th><th>Department</th><th>Supervisor</th><th>Teams</th><th>Default Shift</th><th>Attendance Tracking</th><th>Status</th><th>Action</th></tr></thead><tbody>
<?php foreach ($employees as $e): $r = $e['row']; $id = (int) $r->id; $f = 'ews-employee-' . $id; ?>
    <tr<?php echo !(int) $r->active ? ' style="opacity:.72"' : ''; ?>>
        <td><input form="<?php echo $f; ?>" name="name" value="<?php echo esc_attr($r->name); ?>" required></td>
        <td><input form="<?php echo $f; ?>" name="domain_name" value="<?php echo esc_attr($r->domain_name); ?>" required></td>
        <td><input form="<?php echo $f; ?>" type="email" name="email" value="<?php echo esc_attr($r->email); ?>" required></td>
        <td><input form="<?php echo $f; ?>" name="wp_user_ref" list="ews-pick-users" autocomplete="off" value="<?php echo esc_attr($user_label((int) $r->wp_user_id)); ?>" placeholder="Not linked" aria-label="<?php echo esc_attr('WordPress user of ' . $r->name); ?>"></td>
        <td><select form="<?php echo $f; ?>" name="department_id"><?php echo $options($department_names, (int) ($r->department_id ?? 0), '-- No Department --'); ?></select></td>
        <td><input form="<?php echo $f; ?>" name="supervisor_employee_ref" list="ews-pick-employees" autocomplete="off" value="<?php echo esc_attr($person((int) $e['supervisor_id'])); ?>" placeholder="No supervisor" aria-label="<?php echo esc_attr('Supervisor of ' . $r->name); ?>"></td>
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
<?php if (!$employees): ?><tr><td colspan="11"><?php echo $search !== '' ? 'No employee matches this search.' : 'No employees yet.'; ?></td></tr><?php endif; ?>
</tbody></table>
<div class="tablenav bottom" style="height:auto"><?php $pager(); ?></div>
</div>
