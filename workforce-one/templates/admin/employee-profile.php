<?php
/**
 * wp-admin employee profile. Styles: assets/css/admin-employee-profile.css.
 *
 * @var object $emp
 * @var int $employee_id
 * @var string $initials
 * @var string $photo                  profile photo URL ('' = initials)
 * @var bool $active
 * @var string[] $teams                team labels ("Ops Team · Manager")
 * @var string[] $manager_of
 * @var string $supervisor
 * @var string $user_login
 * @var string $since
 * @var string $shift
 * @var string $hours
 * @var array{planned:string,note:string,result:string,in:string,out:string} $today
 * @var array{counted:int,attended:int,late:int,absent:int,rate:int} $stats
 * @var array<int,array<string,string>> $recent
 * @var int $year
 * @var array<int,array{name:string,used:float,pending:float,available:float}> $balances
 * @var array{locations:array<int,object>,latest:array<int,object>}|null $presence
 * @var array<int,array<string,string>>|null $achievements   null = feature off
 * @var bool $achievement_deleted
 * @var string $employees_url
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
$status = $active ? 'Active' : 'Inactive';
$money = static function ($n) { return number_format((float) $n, 2); };
?>
<div class="wrap ews-profile-wrap">
<?php if ($achievement_deleted): ?><div class="notice notice-success is-dismissible"><p>Achievement removed from the employee profile.</p></div><?php endif; ?>
<div class="ews-profile-head">
    <div class="ews-profile-person">
        <div class="ews-profile-avatar"><?php if ($photo): ?><img src="<?php echo esc_url($photo); ?>" alt="<?php echo esc_attr($emp->name); ?>"><?php else: echo esc_html($initials); endif; ?></div>
        <div class="ews-profile-title"><h1><?php echo esc_html($emp->name); ?></h1><p><?php echo esc_html($emp->domain_name . ' · ' . ($emp->email ?: 'No email')); ?></p>
            <div class="ews-profile-meta"><span class="ews-profile-pill <?php echo $active ? 'is-active' : 'is-inactive'; ?>"><?php echo esc_html($status); ?></span><?php foreach ($teams as $t): ?><span class="ews-profile-pill"><?php echo esc_html($t); ?></span><?php endforeach; ?></div>
        </div>
    </div>
    <div class="ews-profile-actions"><a class="button" href="<?php echo esc_url($employees_url); ?>">← Employees</a></div>
</div>

<?php if ($presence): ?>
<section class="ews-profile-panel" style="margin-bottom:16px"><h2>Presence Verification</h2><div class="ews-profile-body">
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap"><?php wp_nonce_field('ews_presence_request'); ?><input type="hidden" name="action" value="ews_presence_request"><input type="hidden" name="employee_id" value="<?php echo (int) $employee_id; ?>">
        <label style="font-size:12px;font-weight:600;color:#344054">Work Location<br><select name="location_id" required style="min-width:220px;margin-top:5px"><option value="">Select location</option><?php foreach ($presence['locations'] as $pl): ?><option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html($pl->name); ?></option><?php endforeach; ?></select></label>
        <button class="button button-primary" type="submit">Request Presence Verification</button>
    </form>
    <?php if ($presence['latest']): ?>
        <div style="overflow:auto;margin-top:16px"><table class="widefat striped"><thead><tr><th>Status</th><th>Location</th><th>Requested</th><th>Expires</th><th>Verified</th></tr></thead><tbody>
        <?php foreach ($presence['latest'] as $pr): ?><tr><td><strong><?php echo esc_html(ucfirst($pr->status)); ?></strong></td><td><?php echo esc_html($pr->location_name ?: '—'); ?></td><td><?php echo esc_html($pr->created_at); ?></td><td><?php echo esc_html($pr->expires_at); ?></td><td><?php echo esc_html($pr->verified_at ?: '—'); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div></section>
<?php endif; ?>

<div class="ews-profile-grid">
    <div class="ews-profile-card"><div class="label">Today · Planned</div><div class="value" style="font-size:18px"><?php echo esc_html($today['planned']); ?></div><div class="sub"><?php echo esc_html($today['note']); ?></div></div>
    <div class="ews-profile-card"><div class="label">Today · Attendance</div><div class="value" style="font-size:18px"><?php echo esc_html($today['result']); ?></div><div class="sub"><?php echo esc_html(($today['in'] ? 'In ' . $today['in'] : 'No sign in') . ($today['out'] ? ' · Out ' . $today['out'] : '')); ?></div></div>
    <div class="ews-profile-card"><div class="label">30-day Attendance Rate</div><div class="value"><?php echo (int) $stats['rate']; ?>%</div><div class="sub"><?php echo (int) $stats['attended']; ?> attended · <?php echo (int) $stats['absent']; ?> absent</div></div>
    <div class="ews-profile-card"><div class="label">Late Arrivals · 30 days</div><div class="value"><?php echo (int) $stats['late']; ?></div><div class="sub">Based on actual Sign In events</div></div>
</div>

<?php if ($achievements !== null): ?>
<section class="ews-profile-panel"><h2>🏆 Achievements <span style="font-size:12px;color:#667085;font-weight:400"><?php echo count($achievements); ?> earned</span></h2><div class="ews-profile-body">
    <?php if (!$achievements): ?><div class="ews-profile-empty">No achievements earned yet.</div><?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">
    <?php foreach ($achievements as $a): ?>
        <div style="border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd;display:flex;gap:10px;align-items:flex-start;justify-content:space-between">
            <div style="display:flex;gap:10px;align-items:flex-start;min-width:0"><div class="wfo-achievement-admin-badge <?php echo esc_attr($a['style']); ?>"><?php echo esc_html($a['icon']); ?></div><div><strong style="color:#101828"><?php echo esc_html($a['name']); ?></strong><div style="font-size:12px;color:#667085;margin-top:3px"><?php echo esc_html($a['description']); ?></div><div style="font-size:11px;color:#98a2b3;margin-top:6px">Earned <?php echo esc_html($a['earned']); ?></div></div></div>
            <a class="button button-small" style="color:#b42318;border-color:#fecdca;flex:0 0 auto" href="<?php echo esc_url($a['delete_url']); ?>" onclick="return confirm('Delete this achievement award? The badge will be removed from the employee profile. If the employee still qualifies, an automatic achievement may be awarded again later.');">Delete</a>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div></section>
<?php endif; ?>

<div class="ews-profile-layout"><div>
    <section class="ews-profile-panel"><h2>Employee Information</h2><div class="ews-profile-body"><div class="ews-profile-fields">
        <?php foreach (['Employee Name' => $emp->name, 'Domain Name' => $emp->domain_name, 'Email' => $emp->email ?: '—', 'WordPress User' => $user_login, 'Status' => $status, 'Employee Since' => $since] as $k => $v): ?>
            <div class="ews-profile-field"><div class="k"><?php echo esc_html($k); ?></div><div class="v"><?php echo esc_html($v); ?></div></div>
        <?php endforeach; ?>
    </div></div></section>
    <section class="ews-profile-panel"><h2>Organization &amp; Work Setup</h2><div class="ews-profile-body"><div class="ews-profile-fields">
        <?php foreach (['Direct Supervisor' => $supervisor, 'Teams' => $teams ? implode(', ', array_map(static function ($t) { return preg_replace('/ · Manager$/', '', $t); }, $teams)) : '—', 'Default Shift' => $shift, 'Working Hours' => $hours] as $k => $v): ?>
            <div class="ews-profile-field"><div class="k"><?php echo esc_html($k); ?></div><div class="v"><?php echo esc_html($v); ?></div></div>
        <?php endforeach; ?>
    </div></div></section>
    <section class="ews-profile-panel"><h2>Recent Attendance</h2><div class="ews-profile-body" style="padding:0"><table class="ews-profile-table"><thead><tr><th>Date</th><th>Planned</th><th>Sign In</th><th>Sign Out</th><th>Result</th></tr></thead><tbody>
        <?php if (!$recent): ?><tr><td colspan="5" class="ews-profile-empty">No attendance records in the selected period.</td></tr><?php endif; ?>
        <?php foreach ($recent as $r): ?><tr><td><?php echo esc_html($r['date']); ?></td><td><?php echo esc_html($r['planned']); ?></td><td><?php echo esc_html($r['sign_in']); ?></td><td><?php echo esc_html($r['sign_out']); ?></td><td><span class="ews-profile-badge <?php echo esc_attr($r['class']); ?>"><?php echo esc_html($r['result']); ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</div><div>
    <section class="ews-profile-panel"><h2>Leave Balance · <?php echo (int) $year; ?></h2><div class="ews-profile-body">
        <?php if (!$balances): ?><div class="ews-profile-empty">No leave balances found for this employee.</div><?php else: ?><ul class="ews-profile-list">
        <?php foreach ($balances as $b): ?><li><span><strong><?php echo esc_html($b['name']); ?></strong><br><span class="muted">Used <?php echo esc_html($money($b['used'])); ?> · Pending <?php echo esc_html($money($b['pending'])); ?></span></span><strong><?php echo esc_html($money($b['available'])); ?> available</strong></li><?php endforeach; ?>
        </ul><?php endif; ?>
    </div></section>
    <section class="ews-profile-panel"><h2>Today</h2><div class="ews-profile-body"><ul class="ews-profile-list">
        <li><span class="muted">Planned</span><strong><?php echo esc_html($today['planned']); ?></strong></li>
        <li><span class="muted">Sign In</span><strong><?php echo esc_html($today['in'] ?: '—'); ?></strong></li>
        <li><span class="muted">Sign Out</span><strong><?php echo esc_html($today['out'] ?: '—'); ?></strong></li>
        <li><span class="muted">Result</span><strong><?php echo esc_html($today['result']); ?></strong></li>
    </ul></div></section>
    <?php if ($manager_of): ?><section class="ews-profile-panel"><h2>Team Management</h2><div class="ews-profile-body"><div class="ews-profile-empty">Employee is a manager of: <strong><?php echo esc_html(implode(', ', $manager_of)); ?></strong></div></div></section><?php endif; ?>
</div></div>
</div>
