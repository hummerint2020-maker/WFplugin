<?php
/**
 * wp-admin → Departments: create / edit a department and its manager; archive a department once it
 * has no active employees or teams. Script: assets/js/admin-organization.js (confirms Archive).
 *
 * @var object|null $edit          the department being edited
 * @var object[] $departments      active departments with manager_name and employee_count
 * @var object[] $employees        active employees (id, name, domain_name)
 * @var string $notice             confirmation ('' = none)
 * @var string $error              a known message ('' = none)
 */
if (!defined('ABSPATH')) exit;
$post = admin_url('admin-post.php');
?>
<div class="wrap"><h1>Departments</h1>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div style="display:grid;grid-template-columns:minmax(320px,520px) 1fr;gap:20px;align-items:start">
    <div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0"><?php echo $edit ? 'Edit Department' : 'Create Department'; ?></h2>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_department_save')); ?>"><input type="hidden" name="action" value="ews_department_save"><input type="hidden" name="department_id" value="<?php echo (int) ($edit ? $edit->id : 0); ?>">
            <p><input name="name" required placeholder="Department Name" aria-label="Department Name" value="<?php echo esc_attr($edit->name ?? ''); ?>" style="width:100%"></p>
            <p><input name="code" required placeholder="Department Code" aria-label="Department Code" value="<?php echo esc_attr($edit->code ?? ''); ?>" style="width:100%"></p>
            <p><textarea name="description" placeholder="Description" aria-label="Description" style="width:100%;min-height:90px"><?php echo esc_textarea($edit->description ?? ''); ?></textarea></p>
            <p><select name="manager_employee_id" aria-label="Department Manager" style="width:100%"><option value="0">-- No Department Manager --</option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>" <?php selected((int) ($edit->manager_employee_id ?? 0), (int) $e->id); ?>><?php echo esc_html($e->name . ' (' . $e->domain_name . ')'); ?></option><?php endforeach; ?>
            </select><?php if (!$edit): ?><span class="description">The manager must be an employee of the department: assign one after creating it.</span><?php endif; ?></p>
            <p><button class="button button-primary"><?php echo $edit ? 'Save Department' : 'Create Department'; ?></button>
            <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-departments')); ?>">Cancel</a><?php endif; ?></p>
        </form>
    </div>
    <div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">Active Departments</h2>
    <?php if (!$departments): ?><p>No departments created yet.</p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Department</th><th>Code</th><th>Manager</th><th>Employees</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($departments as $d): ?>
            <tr><td><strong><?php echo esc_html($d->name); ?></strong><?php if ($d->description): ?><br><span style="color:#646970"><?php echo esc_html($d->description); ?></span><?php endif; ?></td><td><?php echo esc_html($d->code); ?></td><td><?php echo esc_html($d->manager_name ?: '—'); ?></td><td><?php echo (int) $d->employee_count; ?></td>
                <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'ews31-departments', 'department_id' => (int) $d->id], admin_url('admin.php'))); ?>">Edit</a>
                    <form method="post" action="<?php echo esc_url($post); ?>" style="display:inline" data-ews-confirm="Archive this department?"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_department_delete_' . $d->id)); ?>"><input type="hidden" name="action" value="ews_department_delete"><input type="hidden" name="department_id" value="<?php echo (int) $d->id; ?>"><button class="button button-small" type="submit">Archive</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    </div>
</div></div>
