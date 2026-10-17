<?php
/**
 * wp-admin → Approval Workflows: the approval mode and approvers of each workflow a module uses,
 * and each employee's supervisor (the "Employee Supervisor" approver).
 * Script: assets/js/admin-approvals.js (shows the fields of the chosen mode and approver source).
 *
 * @var array<string,array{label:string,modes:string[],none_means:string,mode:string,levels:array<int,array{resolver_type:string,resolver_value:string,min_amount:?float}>,unsupported:string,allow_self:bool,self_rule:string,deadlines:array{remind:int,escalate:int},overdue:int,overdue_setting:int,reject_note:bool,unit:string,active:bool,not_set:string,mode_labels:array<string,string>,mode_help:array<string,string>,hr_cases:?array{on:bool,cases:list<string>,url:string}}> $cards
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
 * @var object[] $self_decisions                the latest decisions taken by the requester themself (50)
 * @var array{hr_user:int,finance_user:int,day_start:string,day_end:string} $settings  (3.31.100)
 * @var array<string,string> $resolver_labels   approver kind => label
 * @var array<string,string> $self_rules        rule => label
 * @var int $max_levels
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
    'LEVEL_3' => 'Three approvers decide in order: each level approves before the next.',
    'LEVEL_4' => 'Four approvers decide in order (the most): each level approves before the next.',
    'SEQUENTIAL' => 'The configured levels decide in order.',
];
$sources = $resolver_labels;
$units = ['days' => 'days', 'hours' => 'hours', 'of the currency' => (string) get_option('ews_payroll_currency', 'EGP') ?: 'EGP'];
/** The modes in which level $i is used. */
$level_modes = static function (int $i) use ($max_levels): string {
    $m = ['SEQUENTIAL'];
    for ($j = $i; $j <= $max_levels; $j++) $m[] = 'LEVEL_' . $j;
    return implode(' ', $m);
};
$hr_missing = !$settings['hr_user'];
$fin_missing = !$settings['finance_user'];
?>
<style>
.ews-apv-types{border-collapse:collapse;width:100%;max-width:1100px;background:#fff;border:1px solid #dcdcde;margin:0 0 18px}
.ews-apv-types th,.ews-apv-types td{padding:10px 14px;text-align:start;border-bottom:1px solid #f0f0f1;vertical-align:top}
.ews-approval-level{border:1px solid #dcdcde;border-radius:12px;padding:14px 16px;margin:12px 0;display:grid;grid-template-columns:36px minmax(0,1fr);gap:12px}
.ews-approval-level .ews-lv-n{width:36px;height:36px;border-radius:10px;background:#eef0ff;color:#3c2fbf;display:grid;place-items:center;font-weight:700}
.ews-approval-level .ews-lv-f{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center}
.ews-approval-level .ews-lv-f p{margin:0}
.ews-apv-path{list-style:none;margin:8px 0 0;padding:0;display:grid;gap:6px;max-width:720px}
.ews-apv-path li{display:flex;gap:10px;align-items:center;border:1px dashed #c3c4c7;border-radius:10px;padding:8px 12px}
.ews-apv-path li b{display:inline-grid;place-items:center;min-width:24px;height:24px;border-radius:8px;background:#eef0ff;color:#3c2fbf}
.ews-apv-path li small{color:#646970}
.ews-apv-inline{display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap}
.ews-apv-inline input[type=number]{width:80px}
.ews-apv-self{display:inline-block;white-space:nowrap;margin-block:2px;margin-inline-end:16px}
.ews-apv-sup strong{min-width:220px}
.ews-apv-sup input,.ews-approval-card input[list]{max-width:100%;min-width:0!important;width:320px}
</style>
<div class="wrap"><h1>Approval Workflows</h1>
<datalist id="ews-pick-employees"><?php foreach ($emp_label as $l): ?><option value="<?php echo esc_attr($l); ?>"></option><?php endforeach; ?></datalist>
<datalist id="ews-pick-users"><?php foreach ($user_label as $l): ?><option value="<?php echo esc_attr($l); ?>"></option><?php endforeach; ?></datalist>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Approval configuration saved.</p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<p style="max-width:1000px">Configure approval authority independently from WordPress roles. Choose the approval mode for each workflow and then configure only the fields required by that mode. Approvers are fixed when a request starts.</p>

<table class="ews-apv-types" data-apv-types><thead><tr><th>Request type</th><th>Approval</th><th>If nobody decides</th><th></th></tr></thead><tbody>
<?php foreach ($cards as $key => $c): $d = $c['deadlines']; ?>
<tr data-apv-type="<?php echo esc_attr($key); ?>"><td><strong><?php echo esc_html($c['label']); ?></strong></td>
<td><?php echo esc_html(!$c['active'] && $c['not_set'] !== '' ? 'Not set yet' : ($c['mode_labels'][$c['mode']] ?? $c['mode'])); ?></td>
<td><?php echo esc_html(($d['remind'] ? 'Reminder after ' . $d['remind'] . ' h' : '') . ($d['remind'] && $d['escalate'] ? ' · ' : '') . ($d['escalate'] ? 'moves up after ' . $d['escalate'] . ' h' : '') ?: 'Waits'); ?></td>
<td><a class="button button-small" href="#ews-approval-workflow-<?php echo esc_attr($key); ?>">Set up</a></td></tr>
<?php endforeach; ?>
</tbody></table>

<div id="ews-approval-settings" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px;margin:0 0 18px">
    <h2 style="margin-top:0">HR, Finance and the working day</h2>
    <p class="description" style="max-width:820px">The <strong>HR</strong> and <strong>Finance</strong> approvers are these people. Reminders and moving a request up count working hours: these hours on the working days (Schedule settings), not clock hours.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_approval_settings_save')); ?>"><input type="hidden" name="action" value="ews_approval_settings_save">
        <table class="form-table">
            <tr><th>HR</th><td><input name="hr_user_ref" list="ews-pick-users" autocomplete="off" placeholder="Not set" style="min-width:320px" aria-label="HR" value="<?php echo esc_attr($user_label[$settings['hr_user']] ?? ''); ?>"></td></tr>
            <tr><th>Finance</th><td><input name="finance_user_ref" list="ews-pick-users" autocomplete="off" placeholder="Not set" style="min-width:320px" aria-label="Finance" value="<?php echo esc_attr($user_label[$settings['finance_user']] ?? ''); ?>"></td></tr>
            <tr><th>Working day</th><td><span class="ews-apv-inline"><input type="time" name="day_start" value="<?php echo esc_attr($settings['day_start']); ?>" aria-label="Starts"> – <input type="time" name="day_end" value="<?php echo esc_attr($settings['day_end']); ?>" aria-label="Ends"></span></td></tr>
        </table>
        <p><button class="button button-primary" type="submit">Save</button></p>
    </form>
</div>

<?php foreach ($cards as $key => $c): ?>
<div class="ews-approval-card" data-approval-card="ews-approval-workflow-<?php echo esc_attr($key); ?>" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px;margin:0 0 18px">
    <h2 style="margin-top:0"><?php echo esc_html($c['label']); ?> Approval</h2>
    <?php if (!$c['active'] && $c['not_set'] !== ''): ?>
    <div class="notice notice-info inline" data-not-set><p><strong>Not set yet.</strong> Until this card is saved: <?php echo esc_html($c['not_set']); ?></p></div>
    <?php endif; ?>
    <?php if ($c['unsupported'] !== ''): ?>
    <div class="notice notice-warning inline"><p><?php echo esc_html('The saved mode "' . ($mode_labels[$c['unsupported']] ?? $c['unsupported']) . '" is not supported by ' . $c['label'] . ' requests, so managers decide them in the app and on the Requests page. Choose a mode and save.'); ?></p></div>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_approval_workflow_save')); ?>">
        <input type="hidden" name="action" value="ews_approval_workflow_save"><input type="hidden" name="workflow_key" value="<?php echo esc_attr($key); ?>">
        <table class="form-table"><tr><th>Approval Mode</th><td>
            <select class="ews-approval-mode" name="approval_mode" aria-label="Approval Mode">
                <?php foreach ($c['modes'] as $m): ?><option value="<?php echo esc_attr($m); ?>"<?php selected($c['mode'], $m); ?>><?php echo esc_html($c['mode_labels'][$m]); ?></option><?php endforeach; ?>
            </select>
            <div class="ews-approval-help" style="margin-top:8px;line-height:1.6">
                <?php foreach ($c['modes'] as $m): ?><div data-mode="<?php echo esc_attr($m); ?>"><strong><?php echo esc_html($c['mode_labels'][$m]); ?>:</strong> <?php echo esc_html($c['mode_help'][$m] ?? ($m === 'NONE' ? $c['none_means'] : ($help[$m] ?? ''))); ?></div><?php endforeach; ?>
            </div>
            <?php if ($c['hr_cases'] && $c['hr_cases']['on']): ?>
            <p class="description" data-hr-cases style="max-width:720px;margin-top:10px"><?php
                if ($c['hr_cases']['cases']) echo esc_html('Whatever the mode, these requests also go to HR: ' . implode('; ', $c['hr_cases']['cases']) . '. ');
                else echo esc_html('No request goes to HR: the HR cases are turned off. ');
            ?><a href="<?php echo esc_url($c['hr_cases']['url']); ?>">Change in Attendance Corrections → Settings</a></p>
            <?php endif; ?>
        </td></tr>
        <tr data-visible-modes="<?php echo esc_attr($level_modes(1)); ?>" class="ews-apv-mode-row"><th>If the approver is the requester</th><td>
            <?php foreach ($self_rules as $rv => $rl): ?><label class="ews-apv-self"><input type="radio" name="self_rule" value="<?php echo esc_attr($rv); ?>"<?php checked($c['self_rule'], $rv); ?>> <?php echo esc_html($rl); ?></label><?php endforeach; ?>
            <p class="description" style="max-width:720px">They decide it: the person who made the request decides that level (for example the owner or the head of HR); it is listed under Self-decisions below and in the Audit Log. The next level: that level is skipped (with no level after it, an administrator decides it). Administrators: that level waits for an administrator on the Requests Hub.</p>
        </td></tr>
        <tr data-visible-modes="<?php echo esc_attr($level_modes(1)); ?>" class="ews-apv-mode-row"><th>If nobody decides</th><td>
            <span class="ews-apv-inline">Remind after <input type="number" min="0" max="720" name="remind_hours" value="<?php echo (int) $c['deadlines']['remind']; ?>" aria-label="Remind after (working hours)"> working hours,</span>
            <span class="ews-apv-inline">and move it up after <input type="number" min="0" max="720" name="escalate_hours" value="<?php echo (int) $c['deadlines']['escalate']; ?>" aria-label="Move up after (working hours)"> working hours</span>
            <p class="description" style="max-width:720px">0 = never. Moving up: the request goes to the approver's own manager (to the administrators when they have none); the first approver is told, and the request keeps its whole history.</p>
            <p class="ews-apv-inline" style="margin-top:8px">Mark it overdue in My approvals after <input type="number" min="0" max="720" name="overdue_hours" value="<?php echo (int) $c['overdue_setting']; ?>" aria-label="Overdue after (working hours)"> working hours <span class="description">(0 = the reminder time, or 24; now <?php echo (int) $c['overdue']; ?>)</span></p>
        </td></tr>
        <tr data-visible-modes="<?php echo esc_attr($level_modes(1)); ?>" class="ews-apv-mode-row"><th>Rejecting</th><td>
            <label><input type="checkbox" name="reject_note" value="1"<?php checked($c['reject_note']); ?>> A reason is needed to reject</label>
            <p class="description" style="max-width:720px">The approver writes why, and the employee sees it. An administrator deciding on the Requests Hub is not asked for one.</p>
        </td></tr></table>
        <?php for ($i = 1; $i <= $max_levels; $i++): $lv = $c['levels'][$i]; ?>
        <div class="ews-approval-level" data-level="<?php echo (int) $i; ?>" data-visible-modes="<?php echo esc_attr($level_modes($i)); ?>">
            <span class="ews-lv-n" aria-hidden="true"><?php echo (int) $i; ?></span>
            <div><h3 style="margin:0 0 8px">Level <?php echo (int) $i; ?></h3>
            <div class="ews-lv-f">
            <p><label>Who approves <select class="ews-approval-source" name="level_<?php echo (int) $i; ?>_type">
                <?php foreach ($sources as $v => $l): ?><option value="<?php echo esc_attr($v); ?>"<?php selected($lv['resolver_type'], $v); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select></label></p>
            <?php if ($c['unit'] !== ''): ?>
            <p class="ews-apv-inline"><label>When <select class="ews-approval-when" name="level_<?php echo (int) $i; ?>_when"><option value="always"<?php selected($lv['min_amount'] === null); ?>>Always</option><option value="above"<?php selected($lv['min_amount'] !== null); ?>>Only if above</option></select></label>
                <span class="ews-approval-min"><input type="number" min="0" step="0.5" name="level_<?php echo (int) $i; ?>_min" value="<?php echo esc_attr($lv['min_amount'] !== null ? rtrim(rtrim(number_format($lv['min_amount'], 2, '.', ''), '0'), '.') : ''); ?>" aria-label="<?php echo esc_attr('Level ' . $i . ': only if above'); ?>"> <?php echo esc_html($units[$c['unit']]); ?></span></p>
            <?php endif; ?>
            </div>
            <p class="ews-specific-employee"><label>Specific Employee <input name="level_<?php echo (int) $i; ?>_employee_ref" list="ews-pick-employees" autocomplete="off" placeholder="Type to search" style="min-width:320px" value="<?php echo esc_attr($lv['resolver_type'] === 'SPECIFIC_EMPLOYEE' ? ($emp_label[(int) $lv['resolver_value']] ?? '') : ''); ?>"></label></p>
            <p class="ews-specific-user"><label>Specific User <input name="level_<?php echo (int) $i; ?>_user_ref" list="ews-pick-users" autocomplete="off" placeholder="Type to search" style="min-width:320px" value="<?php echo esc_attr($lv['resolver_type'] === 'SPECIFIC_USER' ? ($user_label[(int) $lv['resolver_value']] ?? '') : ''); ?>"></label></p>
            <?php if ($lv['resolver_type'] === 'HR' && $hr_missing || $lv['resolver_type'] === 'FINANCE' && $fin_missing): ?><p class="description" data-apv-missing style="color:#b32d2e">Not set yet in HR, Finance and the working day above: until it is, the next level decides instead (an administrator when there is none).</p><?php endif; ?>
            </div>
        </div>
        <?php endfor; ?>
        <div data-visible-modes="<?php echo esc_attr($level_modes(1)); ?>" class="ews-apv-mode-row">
            <h3 style="margin-bottom:0">The path</h3>
            <ol class="ews-apv-path" data-apv-path>
            <?php for ($i = 1; $i <= $max_levels; $i++): $lv = $c['levels'][$i]; ?>
                <li data-path-level="<?php echo (int) $i; ?>" data-visible-modes="<?php echo esc_attr($level_modes($i)); ?>"><b><?php echo (int) $i; ?></b><span data-path-who><?php echo esc_html($sources[$lv['resolver_type']] ?? $lv['resolver_type']); ?></span><small data-path-when><?php echo esc_html($c['unit'] !== '' && $lv['min_amount'] !== null ? 'only if above ' . rtrim(rtrim(number_format($lv['min_amount'], 2, '.', ''), '0'), '.') . ' ' . $units[$c['unit']] . '; skipped otherwise' : ''); ?></small></li>
            <?php endfor; ?>
            </ol>
        </div>
        <p><button type="submit" class="button button-primary">Save <?php echo esc_html($c['label']); ?> Approval</button></p>
    </form>
</div>
<?php endforeach; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:16px 22px;max-width:1100px;margin:0 0 18px">
    <p style="margin:0"><strong><?php echo esc_html(implode(' and ', $not_in_use)); ?></strong> requests do not use approval workflows yet: managers with Manage Time decide them in the app, and administrators on the Requests Hub.</p>
</div>

 <div id="ews-self-decisions" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px;margin:0 0 18px">
    <h2 style="margin-top:0">Self-decisions</h2>
    <p>Requests decided by the person who made them, newest first (latest 50). Also in the Audit Log as "approval_self_decision".</p>
    <?php if ($self_decisions): ?>
    <table class="widefat striped"><thead><tr><th>Date</th><th>Employee</th><th>Workflow</th><th>Request</th><th>Level</th><th>Decision</th></tr></thead><tbody>
    <?php foreach ($self_decisions as $sd): ?>
        <tr data-self-decision="<?php echo (int) $sd->approval_request_id; ?>"><td><?php echo esc_html((string) $sd->acted_at); ?></td><td><?php echo esc_html((string) ($sd->employee_name ?? '')); ?></td><td><?php echo esc_html((string) $sd->workflow_name); ?></td><td><?php echo esc_html('Approval #' . (int) $sd->approval_request_id . ' · ' . $sd->entity_type . ' #' . (int) $sd->entity_id); ?></td><td><?php echo (int) $sd->step_order; ?></td><td><?php echo esc_html($sd->decision === 'approve' ? 'Self-approved' : 'Self-rejected'); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php else: ?><p><em>No self-decisions yet.</em></p><?php endif; ?>
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
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-apv-sup" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
        <input type="hidden" name="action" value="ews_approval_relationship_save"><input type="hidden" name="employee_id" value="<?php echo (int) $e->id; ?>">
        <strong style="min-width:220px"><?php echo esc_html($e->name); ?></strong>
        <input name="supervisor_employee_ref" list="ews-pick-employees" autocomplete="off" style="min-width:320px" placeholder="No supervisor" aria-label="<?php echo esc_attr('Supervisor of ' . $e->name); ?>" value="<?php echo esc_attr($emp_label[(int) ($supervisors[(int) $e->id] ?? 0)] ?? ''); ?>">
        <button class="button" type="submit">Save</button>
    </form>
<?php endforeach; ?>
<?php if (!$listed): ?><p><?php echo $search !== '' ? 'No employee matches this search.' : 'No active employees yet.'; ?></p><?php endif; ?>
</div></div>
