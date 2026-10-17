<?php
/**
 * wp-admin → Teams: create / edit a team (department, manager, members) and archive teams. The
 * manager and members must belong to the team's department; the manager is always a member.
 * Script: assets/js/admin-organization.js (lists only the chosen department's people; confirms Archive).
 *
 * @var object|null $edit          the team being edited
 * @var int[] $selected            its current member ids
 * @var object[] $teams            active teams with manager_name, department_name and member_count
 * @var object[] $employees        active employees with department_id and department_name
 * @var object[] $departments      active departments (id, name)
 * @var string $notice             confirmation ('' = none)
 * @var string $error              a known message ('' = none)
 */
if (!defined('ABSPATH')) exit;
$post = admin_url('admin-post.php');
$who = static function ($e) { return $e->name . ' · ' . $e->domain_name . ' · ' . ($e->department_name ?: 'No department'); };
?>
<div class="wrap"><h1>Teams</h1>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<p style="max-width:900px">Create work teams and assign one Team Manager to each team. The Team Manager is an organizational relationship, not a WordPress role, and the manager is automatically included as a team member.</p>
<div style="display:grid;grid-template-columns:minmax(320px,520px) 1fr;gap:20px;align-items:start">
    <div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0"><?php echo $edit ? 'Edit Team' : 'Create Team'; ?></h2>
        <form method="post" action="<?php echo esc_url($post); ?>" class="ews-team-form">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_team_save')); ?>"><input type="hidden" name="action" value="ews_team_save"><input type="hidden" name="team_id" value="<?php echo (int) ($edit ? $edit->id : 0); ?>">
            <p><label><strong>Name</strong><br><input type="text" name="name" value="<?php echo esc_attr($edit ? $edit->name : ''); ?>" required style="width:100%;max-width:480px"></label></p>
            <p><label><strong>Description</strong><br><textarea name="description" rows="4" style="width:100%;max-width:480px"><?php echo esc_textarea($edit ? $edit->description : ''); ?></textarea></label></p>
            <p><label><strong>Department</strong><br><select name="department_id" required class="ews-team-department" style="min-width:240px"><option value="">-- Select Department --</option>
                <?php foreach ($departments as $dep): ?><option value="<?php echo (int) $dep->id; ?>"<?php selected((int) ($edit->department_id ?? 0), (int) $dep->id); ?>><?php echo esc_html($dep->name); ?></option><?php endforeach; ?>
            </select></label><br><span class="description">The manager and the members are chosen from this department.</span></p>
            <p><label><strong>Team Manager</strong><br><select name="manager_employee_id" required style="width:100%;max-width:480px"><option value="0">— Select manager —</option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>" data-department="<?php echo (int) $e->department_id; ?>"<?php selected((int) ($edit ? $edit->manager_employee_id : 0), (int) $e->id); ?>><?php echo esc_html($who($e)); ?></option><?php endforeach; ?>
            </select></label></p>
            <p><strong>Team Members</strong><br><span class="description">Select the employees who belong to this team. The manager will always be added automatically.</span></p>
            <div style="max-height:300px;overflow:auto;border:1px solid #dcdcde;padding:10px">
                <?php foreach ($employees as $e): ?><label style="display:block;padding:6px 0" data-department="<?php echo (int) $e->department_id; ?>"><input type="checkbox" name="member_ids[]" value="<?php echo (int) $e->id; ?>"<?php checked(in_array((int) $e->id, $selected, true)); ?>> <?php echo esc_html($who($e)); ?></label><?php endforeach; ?>
            </div>
            <p><button class="button button-primary" type="submit"><?php echo $edit ? 'Save Team' : 'Create Team'; ?></button>
            <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-teams')); ?>">Cancel</a><?php endif; ?></p>
        </form>
    </div>
    <div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">Active Teams</h2>
    <?php if (!$teams): ?><p>No teams created yet.</p><?php else: ?>
        <table class="widefat striped"><thead><tr><th>Team</th><th>Department</th><th>Manager</th><th>Members</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($teams as $team): ?>
            <tr><td><strong><?php echo esc_html($team->name); ?></strong><?php if ($team->description): ?><br><span style="color:#646970"><?php echo esc_html($team->description); ?></span><?php endif; ?></td><td><?php echo esc_html($team->department_name ?: '—'); ?></td><td><?php echo esc_html($team->manager_name ?: '—'); ?></td><td><?php echo (int) $team->member_count; ?></td>
                <td><a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'ews31-teams', 'team_id' => (int) $team->id], admin_url('admin.php'))); ?>">Edit</a>
                    <form method="post" action="<?php echo esc_url($post); ?>" style="display:inline" data-ews-confirm="Archive this team?"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_team_delete_' . $team->id)); ?>"><input type="hidden" name="action" value="ews_team_delete"><input type="hidden" name="team_id" value="<?php echo (int) $team->id; ?>"><button class="button button-small" type="submit">Archive</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    </div>
</div></div>
