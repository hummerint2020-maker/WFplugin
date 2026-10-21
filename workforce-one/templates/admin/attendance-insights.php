<?php
/**
 * wp-admin "Attendance Insights". Styles: assets/css/admin-attendance-insights.css;
 * drill-down drawer: assets/js/admin-attendance-insights.js (any button with data-names).
 *
 * @var string[] $dates
 * @var string $today
 * @var string $focus
 * @var string $team
 * @var string[] $team_options
 * @var string $week_start
 * @var string $week_label
 * @var string $focus_label
 * @var int $employee_count
 * @var int $expected_focus
 * @var int $focus_rate
 * @var array<string,array{plan:array<string,string[]>,actual:array<string,string[]>}> $drill   per date
 * @var array{plan:array<string,string[]>,actual:array<string,string[]>} $focus_drill
 * @var array<int,array<string,mixed>> $metrics
 * @var array<int,array<string,mixed>> $attention
 * @var string $profile_url
 * @var array<string,string> $nav
 */
if (!defined('ABSPATH')) exit;
$plan_style = ['Office' => ['🏢', 'ews-ai-office'], 'WFH' => ['🏠', 'ews-ai-wfh'], 'Leave' => ['🌴', 'ews-ai-leave'], 'Business Trip' => ['✈️', 'ews-ai-trip'], 'Other' => ['•', 'ews-ai-other'], 'Absent' => ['⚠️', 'ews-ai-absent-plan'], 'Not Set' => ['○', 'ews-ai-notset']];
$focus_date_label = date('D, d M Y', strtotime($focus));
$drill_attrs = static function (string $kind, string $label, string $date, array $names): string {
    return 'data-kind="' . esc_attr($kind) . '" data-label="' . esc_attr($label) . '" data-date="' . esc_attr($date) . '" data-names="' . esc_attr(wp_json_encode(array_values($names))) . '"';
};
$is_today = $focus === $today;
?>
<div class="wrap ews-ai-admin">
<div class="ews-ai-hero"><h1>Attendance Insights</h1><p>Manager view for daily workforce distribution, attendance performance and employee drill-down.</p>
    <div class="ews-ai-controls">
        <a class="nav" href="<?php echo esc_url($nav['prev_week']); ?>" aria-label="Previous week">‹</a>
        <form method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="team" value="<?php echo esc_attr($team); ?>"><input type="hidden" name="focus_date" value="<?php echo esc_attr($focus); ?>">
            <label class="ews-ai-range"><span>📅</span><strong><?php echo esc_html($week_label); ?></strong><input type="date" name="week" value="<?php echo esc_attr($week_start); ?>" onchange="this.form.submit()" aria-label="Choose week"></label></form>
        <a class="nav" href="<?php echo esc_url($nav['next_week']); ?>" aria-label="Next week">›</a>
        <a class="ews-ai-focus-nav" href="<?php echo esc_url($nav['prev_day']); ?>" aria-label="Previous focus day">‹ Day</a>
        <form class="ews-ai-focus-form" method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="week" value="<?php echo esc_attr($week_start); ?>"><input type="hidden" name="team" value="<?php echo esc_attr($team); ?>">
            <label class="ews-ai-focus-date"><span>Focus day</span><input type="date" name="focus_date" value="<?php echo esc_attr($focus); ?>" onchange="this.form.submit()" aria-label="Choose focus day"><strong><?php echo esc_html($focus_label); ?></strong></label></form>
        <a class="ews-ai-focus-nav" href="<?php echo esc_url($nav['next_day']); ?>" aria-label="Next focus day">Day ›</a>
        <form method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="week" value="<?php echo esc_attr($week_start); ?>"><input type="hidden" name="focus_date" value="<?php echo esc_attr($focus); ?>">
            <select name="team" onchange="this.form.submit()"><option value="all">All Teams</option><?php foreach ($team_options as $t): ?><option value="<?php echo esc_attr($t); ?>" <?php selected($team, $t); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?></select></form>
    </div>
</div>

<div class="ews-ai-stats">
    <div class="ews-ai-stat"><b><?php echo (int) $employee_count; ?></b><small>Employees</small></div>
    <?php foreach (['Office', 'WFH', 'Leave'] as $l): ?><div class="ews-ai-stat"><b><?php echo count($focus_drill['plan'][$l]); ?></b><small><?php echo esc_html($l . ($is_today ? ' Today' : '')); ?></small></div><?php endforeach; ?>
    <div class="ews-ai-stat"><b><?php echo (int) $focus_rate; ?>%</b><small><?php echo $is_today ? 'Today Attendance' : 'Day Attendance'; ?></small></div>
</div>

<div class="ews-ai-card ews-ai-today-card"><div class="ews-ai-card-head"><h2>Attendance Overview · <?php echo esc_html($focus_label); ?></h2><p>Click any workforce or attendance count to see the employees behind it.</p></div><div class="ews-ai-card-body">
    <div class="ews-ai-today-grid">
    <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): $names = $focus_drill['plan'][$label]; ?>
        <button type="button" class="ews-ai-today-tile <?php echo esc_attr($plan_style[$label][1]); ?>" <?php echo $drill_attrs('planned', $label, $focus_date_label, $names); ?>><span class="ews-ai-today-icon"><?php echo esc_html($plan_style[$label][0]); ?></span><span><b><?php echo count($names); ?></b><small><?php echo esc_html($label); ?></small></span></button>
    <?php endforeach; ?>
    </div>
    <div class="ews-ai-today-divider"></div>
    <div class="ews-ai-today-attendance">
    <?php foreach ($focus_drill['actual'] as $label => $names): ?>
        <button type="button" class="ews-ai-actual <?php echo esc_attr($label === 'Missing Sign-out' ? 'missing' : strtolower($label)); ?>" <?php echo $drill_attrs('actual', $label, $focus_date_label, $names); ?>><b><?php echo count($names); ?></b><span><?php echo esc_html($label); ?></span></button>
    <?php endforeach; ?>
    </div>
</div></div>

<div class="ews-ai-grid"><div>
    <div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Daily Workforce Overview</h2><p>Planned distribution. Click any number to see the employees behind it.</p></div><div class="ews-ai-week"><table><thead><tr><th>Planned</th>
        <?php foreach ($dates as $d): ?><th class="ews-ai-day-head"><strong><?php echo esc_html(date('D', strtotime($d))); ?></strong><small><?php echo esc_html(date('d M', strtotime($d))); ?></small><?php if ($d === $today): ?><em>Today</em><?php endif; ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): ?>
        <tr><td class="ews-ai-rowlabel"><?php echo esc_html($label); ?><span>Planned</span></td>
        <?php foreach ($dates as $d): $names = $drill[$d]['plan'][$label]; ?><td><button type="button" class="ews-ai-count <?php echo esc_attr($plan_style[$label][1]); ?>" <?php echo $drill_attrs('planned', $label, date('D, d M Y', strtotime($d)), $names); ?>><?php echo count($names); ?></button></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <div class="ews-ai-card ews-ai-secondary-today"><div class="ews-ai-card-head"><h2><?php echo $is_today ? 'Today’s Attendance' : 'Selected Day Attendance'; ?></h2><p><?php echo (int) $expected_focus; ?> employees are expected to sign in on <?php echo esc_html($is_today ? 'Today' : $focus_date_label); ?>.</p></div><div class="ews-ai-card-body"><div class="ews-ai-actual-list">
    <?php foreach (\WorkforceOne\Attendance\Insights::ACTUAL as $label): $names = $focus_drill['actual'][$label]; ?>
        <button type="button" class="ews-ai-actual <?php echo esc_attr(strtolower($label)); ?>" <?php echo $drill_attrs('actual', $label, $focus_date_label, $names); ?>><b><?php echo count($names); ?></b><span><?php echo esc_html($label); ?></span></button>
    <?php endforeach; ?>
    </div></div></div>
</div><div>
    <div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Workforce Distribution</h2><p>Planned location/status mix on the focus day.</p></div><div class="ews-ai-card-body">
    <?php foreach (['Office', 'WFH', 'Leave', 'Business Trip', 'Other'] as $label): $names = $focus_drill['plan'][$label]; ?>
        <button type="button" class="ews-ai-attn" style="width:100%;border:0;background:#fff;cursor:pointer;text-align:left" <?php echo $drill_attrs('planned', $label, $focus_date_label, $names); ?>><span><strong><?php echo esc_html($plan_style[$label][0] . ' ' . $label); ?></strong><small>Planned employees</small></span><b><?php echo count($names); ?></b></button>
    <?php endforeach; ?>
    </div></div>
    <div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Requires Attention</h2><p>Employees with absence, lateness or incomplete sign-out in the selected week.</p></div><div class="ews-ai-card-body"><div class="ews-ai-attention">
    <?php foreach ($attention as $row): ?>
        <div class="ews-ai-attn"><span><strong><?php echo esc_html($row['name']); ?></strong><small><?php echo esc_html($row['issue']); ?></small></span><a class="button button-small" href="<?php echo esc_url(add_query_arg('employee_id', $row['id'], $profile_url)); ?>">Review</a></div>
    <?php endforeach; ?>
    <?php if (!$attention): ?><div class="ews-ai-empty">No attention items for this period.</div><?php endif; ?>
    </div></div></div>
</div></div>

<div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Employee Attendance Metrics</h2><p>Selected period: <?php echo esc_html($week_label); ?></p></div><div class="ews-ai-emp-table"><table><thead><tr><th>Employee</th><th>Rate</th><th>On-Time</th><th>Late</th><th>Absent</th><th>Missing Sign-out</th></tr></thead><tbody>
<?php if (!$metrics): ?><tr><td colspan="6" class="ews-ai-empty">No employees match the selected filter.</td></tr><?php endif; ?>
<?php foreach ($metrics as $row): ?><tr><td><span class="ews-ai-emp-name"><?php echo esc_html($row['name']); ?></span><br><span class="ews-ai-subtle"><?php echo esc_html($row['domain']); ?></span></td><td><span class="ews-ai-rate"><?php echo (int) $row['rate']; ?>%</span></td><td><?php echo (int) $row['on_time_rate']; ?>%</td><td><?php echo (int) $row['late']; ?></td><td><?php echo (int) $row['absent']; ?></td><td><?php echo (int) $row['missing_sign_out']; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<div id="ews-ai-drawer" class="ews-ai-drawer" aria-hidden="true"><div class="ews-ai-panel"><div class="ews-ai-panel-head"><div><h2 id="ews-ai-title">Employees</h2><p id="ews-ai-subtitle"></p></div><button type="button" class="ews-ai-close" aria-label="Close">×</button></div><div id="ews-ai-names" class="ews-ai-names"></div></div></div>
</div>
