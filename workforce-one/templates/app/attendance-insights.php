<?php
/**
 * Employee app: Attendance Insights. Styles: assets/css/workforce-one.css (.ews-fi-*); drawer:
 * assets/js/app-attendance-insights.js (any button with data-names).
 *
 * @var string[] $dates
 * @var string $today
 * @var string $focus
 * @var string $team
 * @var string[] $team_options
 * @var array<string,array{plan:array<string,string[]>,actual:array<string,string[]>}> $drill   per date
 * @var array{plan:array<string,string[]>,actual:array<string,string[]>} $focus_drill
 * @var string $week_label
 * @var string $focus_label
 * @var array<string,string> $nav
 */
if (!defined('ABSPATH')) exit;
$plan_style = ['Office' => ['🏢', 'ews-fi-office'], 'WFH' => ['🏠', 'ews-fi-wfh'], 'Leave' => ['🌴', 'ews-fi-leave'], 'Business Trip' => ['✈️', 'ews-fi-trip'], 'Other' => ['•', 'ews-fi-other'], 'Absent' => ['⚠️', 'ews-fi-absent'], 'Not Set' => ['—', 'ews-fi-notset']];
$drill_attrs = static function (string $kind, string $label, string $date, array $names): string {
    return 'data-kind="' . esc_attr($kind) . '" data-label="' . esc_attr($label) . '" data-date="' . esc_attr($date) . '" data-names="' . esc_attr(wp_json_encode(array_values($names))) . '"';
};
$is_today = $focus === $today;
$day_heading = $is_today ? 'Today' : 'Selected Day';
?>
<div class="ews-fi">
    <div class="ews-fi-controls">
        <a class="nav" href="<?php echo esc_url($nav['prev_week']); ?>" title="Previous week">‹</a>
        <span class="ews-fi-badge"><?php esc_html_e('Week', 'workforce-one'); ?> <?php echo esc_html($week_label); ?></span>
        <a class="nav" href="<?php echo esc_url($nav['next_week']); ?>" title="Next week">›</a>
        <form method="get">
            <input type="hidden" name="ews_view" value="attendance-insights">
            <select name="team" data-ews-autosubmit><option value="all"><?php esc_html_e('All Teams', 'workforce-one'); ?></option><?php foreach ($team_options as $team_name): ?><option value="<?php echo esc_attr($team_name); ?>" <?php selected($team, $team_name); ?>><?php echo esc_html($team_name); ?></option><?php endforeach; ?></select>
            <label class="ews-fi-date-label"><?php esc_html_e('Day', 'workforce-one'); ?></label><input type="date" name="focus_date" value="<?php echo esc_attr($focus); ?>" data-ews-autosubmit>
        </form>
        <a class="nav" href="<?php echo esc_url($nav['prev_day']); ?>" title="Previous day">‹</a>
        <span class="ews-fi-day-title"><?php echo esc_html($focus_label); ?></span>
        <a class="nav" href="<?php echo esc_url($nav['next_day']); ?>" title="Next day">›</a>
        <?php if (!$is_today): ?><a href="<?php echo esc_url($nav['today']); ?>"><?php esc_html_e('Today', 'workforce-one'); ?></a><?php endif; ?>
    </div>

    <div class="ews-fi-card">
        <div class="ews-fi-head"><h2><?php echo esc_html($day_heading); ?> · <?php esc_html_e('Workforce Overview', 'workforce-one'); ?></h2><p><?php esc_html_e('Planned workforce distribution. Click a count to see the employees behind it.', 'workforce-one'); ?></p></div>
        <div class="ews-fi-grid">
            <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): $names = $focus_drill['plan'][$label]; ?>
            <button type="button" class="ews-fi-tile <?php echo esc_attr($plan_style[$label][1]); ?>" <?php echo $drill_attrs('planned', $label, $focus_label, $names); ?>><span><?php echo esc_html($plan_style[$label][0]); ?></span><b><?php echo count($names); ?></b><small><?php echo esc_html($label); ?></small></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ews-fi-card">
        <div class="ews-fi-head"><h2><?php echo esc_html($day_heading); ?> · <?php esc_html_e('Attendance', 'workforce-one'); ?></h2><p><?php esc_html_e('Actual attendance classification based on schedule and time logs. Planned Office/WFH is not inferred from sign-in.', 'workforce-one'); ?></p></div>
        <div class="ews-fi-att">
            <?php foreach ($focus_drill['actual'] as $label => $names): ?>
            <button type="button" class="<?php echo esc_attr($label === 'Missing Sign-out' ? 'ews-fi-missing' : 'ews-fi-' . strtolower($label)); ?>" <?php echo $drill_attrs('actual', $label, $focus_label, $names); ?>><b><?php echo count($names); ?></b><span><?php echo esc_html($label === 'Missing Sign-out' ? __('Missing Sign-out', 'workforce-one') : $label); ?></span></button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ews-fi-card">
        <div class="ews-fi-head"><h2><?php esc_html_e('Weekly Workforce Overview', 'workforce-one'); ?></h2><p><?php esc_html_e('Configured working days for the selected week. Counts are planned schedule values.', 'workforce-one'); ?></p></div>
        <div class="ews-fi-week"><table><thead><tr><th><?php esc_html_e('Workforce', 'workforce-one'); ?></th>
            <?php foreach ($dates as $d): ?><th><?php echo esc_html(date_i18n('D', strtotime($d))); ?><br><small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): ?>
            <tr><td class="ews-fi-rowlabel"><?php echo esc_html($label); ?></td>
            <?php foreach ($dates as $d): $names = $drill[$d]['plan'][$label]; ?><td><button type="button" class="ews-fi-count" <?php echo $drill_attrs('planned', $label, date_i18n('D, d M Y', strtotime($d)), $names); ?> data-day="<?php echo esc_attr($d); ?>"><?php echo count($names); ?></button></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>
<div class="ews-fi-drawer" id="ews-fi-drawer" aria-hidden="true" data-empty="<?php esc_attr_e('No employees in this category.', 'workforce-one'); ?>"><div class="ews-fi-panel"><div class="ews-fi-panel-head"><div><h2 id="ews-fi-title"><?php esc_html_e('Employees', 'workforce-one'); ?></h2><p id="ews-fi-subtitle"></p></div><button type="button" class="ews-fi-close" aria-label="Close">×</button></div><div class="ews-fi-names" id="ews-fi-names"></div></div></div>
