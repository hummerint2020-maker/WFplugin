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
 * @var object[] $listed                        this page of Supervisor Relationships (50, searched)
 * @var string $search
 * @var int $paged
 * @var int $pages
 * @var int $total
 * @var string $page_url
 * @var object[] $users                         WordPress users (ID, display_name, user_email)
 * @var array<int,int> $supervisors             employee id => supervisor employee id (0 = none)
 * @var bool $saved
 * @var string $error
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Support\Picker;
// One shared pick list per kind instead of a <select> with every employee in every row (3.31.71).
$emp_label = [];
foreach ($employees as $e) $emp_label[(int) $e->id] = Picker::label($e->name . ' · ' . $e->domain_name, (int) $e->id);
$user_label = [];
foreach ($users as $u) $user_label[(int) $u->ID] = Picker::label($u->display_name . ' · ' . $u->user_email, (int) $u->ID);

$help = [
    'LEVEL_1' => 'One approver decides.',
    'LEVEL_2' => 'Two approvers decide in order: Level 1, then Level 2.',
    'SEQUENTIAL' => 'The configured levels decide in order.',
];
$sources = ['SUPERVISOR' => 'Employee Supervisor', 'TEAM_MANAGER' => 'Team Manager', 'SPECIFIC_EMPLOYEE' => 'Specific Workforce Employee', 'SPECIFIC_USER' => 'Specific WordPress User'];
?>
<div class="wrap"><h1>Approval Workflows</h1>
<datalist id="ews-pick-employees"><?php foreach ($emp_label as $l): ?><option value="<?php echo esc_attr($l); ?>"></option><?php endforeach; ?></datalist>
<datalist id="ews-pick-users"><?php foreach ($user_label as $l): ?><option value="<?php echo esc_attr($l); ?>"></option><?php endforeach; ?></datalist>
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
            <p class="ews-specific-employee"><label>Specific Employee <input name="level_<?php echo (int) $i; ?>_employee_ref" list="ews-pick-employees" autocomplete="off" placeholder="Type to search" style="min-width:320px" value="<?php echo esc_attr($lv['resolver_type'] === 'SPECIFIC_EMPLOYEE' ? ($emp_label[(int) $lv['resolver_value']] ?? '') : ''); ?>"></label></p>
            <p class="ews-specific-user"><label>Specific User <input name="level_<?php echo (int) $i; ?>_user_ref" list="ews-pick-users" autocomplete="off" placeholder="Type to search" style="min-width:320px" value="<?php echo esc_attr($lv['resolver_type'] === 'SPECIFIC_USER' ? ($user_label[(int) $lv['resolver_value']] ?? '') : ''); ?>"></label></p>
        </div>
        <?php endfor; ?>
        <p><button type="submit" class="button button-primary">Save <?php echo esc_html($c['label']); ?> Approval</button></p>
    </form>
</div>
<?php endforeach; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:16px 22px;max-width:1100px;margin:0 0 18px">
    <p style="margin:0"><strong><?php echo esc_html(implode(' and ', $not_in_use)); ?></strong> requests do not use approval workflows yet: managers decide them in the app and on the Requests page.</p>
</div>

<div id="ews-supervisors" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px"><h2 style="margin-top:0">Supervisor Relationships</h2><p>Assign the direct supervisor used by the <strong>Employee Supervisor</strong> approver. Type a few letters and pick from the list; clear the field to remove the supervisor.</p>
<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 8px">
    <input type="hidden" name="page" value="ews31-approvals">
    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Name or domain name" aria-label="Search employees">
    <button class="button">Search</button><?php if ($search !== ''): ?> <a class="button-link" href="<?php echo esc_url($page_url . '#ews-supervisors'); ?>">Clear</a><?php endif; ?>
    <span style="margin-inline-start:auto"><?php echo esc_html(sprintf('%d employees', $total)); ?><?php if ($pages > 1): ?> · <?php echo esc_html(sprintf('Page %1$d of %2$d', $paged, $pages)); ?>
        <?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url(add_query_arg(array_filter(['paged' => $paged - 1, 's' => $search ?: null]), $page_url) . '#ews-supervisors'); ?>">&lsaquo; Previous</a><?php endif; ?>
        <?php if ($paged < $pages): ?><a class="button" href="<?php echo esc_url(add_query_arg(array_filter(['paged' => $paged + 1, 's' => $search ?: null]), $page_url) . '#ews-supervisors'); ?>">Next &rsaquo;</a><?php endif; ?><?php endif; ?></span>
</form>
<?php $nonce = wp_create_nonce('ews_approval_relationship_save'); ?>
<?php foreach ($listed as $e): ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
        <input type="hidden" name="action" value="ews_approval_relationship_save"><input type="hidden" name="employee_id" value="<?php echo (int) $e->id; ?>">
        <strong style="min-width:220px"><?php echo esc_html($e->name); ?></strong>
        <input name="supervisor_employee_ref" list="ews-pick-employees" autocomplete="off" style="min-width:320px" placeholder="No supervisor" aria-label="<?php echo esc_attr('Supervisor of ' . $e->name); ?>" value="<?php echo esc_attr($emp_label[(int) ($supervisors[(int) $e->id] ?? 0)] ?? ''); ?>">
        <button class="button" type="submit">Save</button>
    </form>
<?php endforeach; ?>
<?php if (!$listed): ?><p><?php echo $search !== '' ? 'No employee matches this search.' : 'No active employees yet.'; ?></p><?php endif; ?>
</div></div>
