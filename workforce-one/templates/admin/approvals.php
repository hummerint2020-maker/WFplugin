<?php
/**
 * wp-admin → Approval Workflows: the approval mode and approvers of each workflow a module uses,
 * and each employee's supervisor (the "Employee Supervisor" approver).
 * Script: assets/js/admin-approvals.js (shows the fields of the chosen mode and approver source).
 *
 * @var array<string,array{label:string,modes:string[],none_means:string,mode:string,levels:array<int,array{resolver_type:string,resolver_value:string}>,unsupported:string}> $cards
 * @var array<string,string> $not_in_use        workflow key => label
 * @var array<string,string> $mode_labels       mode => label
 * @var object[] $employees                     active employees (id, name, domain_name)
 * @var object[] $users                         WordPress users (ID, display_name, user_email)
 * @var array<int,int> $supervisors             employee id => supervisor employee id (0 = none)
 * @var bool $saved
 * @var string $error
 */
if (!defined('ABSPATH')) exit;

$help = [
    'LEVEL_1' => 'One approver decides.',
    'LEVEL_2' => 'Two approvers decide in order: Level 1, then Level 2.',
    'SEQUENTIAL' => 'The configured levels decide in order.',
];
$sources = ['SUPERVISOR' => 'Employee Supervisor', 'TEAM_MANAGER' => 'Team Manager', 'SPECIFIC_EMPLOYEE' => 'Specific Workforce Employee', 'SPECIFIC_USER' => 'Specific WordPress User'];
?>
<div class="wrap"><h1>Approval Workflows</h1>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Approval configuration saved.</p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<p style="max-width:1000px">Configure approval authority independently from WordPress roles. Choose the approval mode for each workflow and then configure only the fields required by that mode. Approvers are fixed when a request starts.</p>

<?php foreach ($cards as $key => $c): ?>
<div class="ews-approval-card" data-approval-card="ews-approval-workflow-<?php echo esc_attr($key); ?>" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px;margin:0 0 18px">
    <h2 style="margin-top:0"><?php echo esc_html($c['label']); ?> Approval</h2>
    <?php if ($c['unsupported'] !== ''): ?>
    <div class="notice notice-warning inline"><p><?php echo esc_html('The saved mode "' . ($mode_labels[$c['unsupported']] ?? $c['unsupported']) . '" is not supported by ' . $c['label'] . ' requests, so managers decide them in the app and on the Requests page. Choose a mode and save.'); ?></p></div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_approval_workflow_save')); ?>">
        <input type="hidden" name="action" value="ews_approval_workflow_save"><input type="hidden" name="workflow_key" value="<?php echo esc_attr($key); ?>">
        <table class="form-table"><tr><th>Approval Mode</th><td>
            <select class="ews-approval-mode" name="approval_mode" aria-label="Approval Mode">
                <?php foreach ($c['modes'] as $m): ?><option value="<?php echo esc_attr($m); ?>"<?php selected($c['mode'], $m); ?>><?php echo esc_html($mode_labels[$m]); ?></option><?php endforeach; ?>
            </select>
            <div class="ews-approval-help" style="margin-top:8px;line-height:1.6">
                <?php foreach ($c['modes'] as $m): ?><div data-mode="<?php echo esc_attr($m); ?>"><strong><?php echo esc_html($mode_labels[$m]); ?>:</strong> <?php echo esc_html($m === 'NONE' ? $c['none_means'] : $help[$m]); ?></div><?php endforeach; ?>
            </div>
        </td></tr></table>
        <?php for ($i = 1; $i <= 2; $i++): $lv = $c['levels'][$i]; ?>
        <div class="ews-approval-level" data-visible-modes="<?php echo esc_attr($i === 1 ? 'LEVEL_1 LEVEL_2 SEQUENTIAL' : 'LEVEL_2 SEQUENTIAL'); ?>" style="border:1px solid #dcdcde;padding:16px;margin:12px 0">
            <h3 style="margin-top:0">Level <?php echo (int) $i; ?></h3>
            <p><label>Approver source <select class="ews-approval-source" name="level_<?php echo (int) $i; ?>_type">
                <?php foreach ($sources as $v => $l): ?><option value="<?php echo esc_attr($v); ?>"<?php selected($lv['resolver_type'], $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select></label></p>
            <p class="ews-specific-employee"><label>Specific Employee <select name="level_<?php echo (int) $i; ?>_employee"><option value="0">— Select —</option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"<?php selected((int) $lv['resolver_value'], (int) $e->id); ?>><?php echo esc_html($e->name . ' · ' . $e->domain_name); ?></option><?php endforeach; ?>
            </select></label></p>
            <p class="ews-specific-user"><label>Specific User <select name="level_<?php echo (int) $i; ?>_user"><option value="0">— Select —</option>
                <?php foreach ($users as $u): ?><option value="<?php echo (int) $u->ID; ?>"<?php selected((int) $lv['resolver_value'], (int) $u->ID); ?>><?php echo esc_html($u->display_name . ' · ' . $u->user_email); ?></option><?php endforeach; ?>
            </select></label></p>
        </div>
        <?php endfor; ?>
        <p><button type="submit" class="button button-primary">Save <?php echo esc_html($c['label']); ?> Approval</button></p>
    </form>
</div>
<?php endforeach; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:16px 22px;max-width:1100px;margin:0 0 18px">
    <p style="margin:0"><strong><?php echo esc_html(implode(' and ', $not_in_use)); ?></strong> requests do not use approval workflows yet: managers decide them in the app and on the Requests page.</p>
</div>

<div style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px"><h2 style="margin-top:0">Supervisor Relationships</h2><p>Assign the direct supervisor used by the <strong>Employee Supervisor</strong> approver.</p>
<?php foreach ($employees as $e): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_approval_relationship_save')); ?>">
        <input type="hidden" name="action" value="ews_approval_relationship_save"><input type="hidden" name="employee_id" value="<?php echo (int) $e->id; ?>">
        <strong style="min-width:220px"><?php echo esc_html($e->name); ?></strong>
        <select name="supervisor_employee_id" style="min-width:280px" aria-label="<?php echo esc_attr('Supervisor of ' . $e->name); ?>"><option value="0">— No supervisor —</option>
            <?php foreach ($employees as $x): if ((int) $x->id === (int) $e->id) continue; ?><option value="<?php echo (int) $x->id; ?>"<?php selected($supervisors[(int) $e->id] ?? 0, (int) $x->id); ?>><?php echo esc_html($x->name); ?></option><?php endforeach; ?>
        </select>
        <button class="button" type="submit">Save</button>
    </form>
<?php endforeach; ?>
</div></div>
