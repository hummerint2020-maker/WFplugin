<?php
/**
 * Employee app: Attendance Insights. Styles: assets/css/app-insights.css (new look, 3.31.58) over the
 * older .ews-fi-* rules in workforce-one.css; drawer: assets/js/app-attendance-insights.js (any
 * button with data-names inside .ews-fi).
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
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $drill_attrs() escapes its output.
$plan_style = ['Office' => ['office', 'ews-fi-office'], 'WFH' => ['wfh', 'ews-fi-wfh'], 'Leave' => ['leave', 'ews-fi-leave'], 'Business Trip' => ['briefcase', 'ews-fi-trip'], 'Other' => ['people', 'ews-fi-other'], 'Absent' => ['alert', 'ews-fi-absent'], 'Not Set' => ['calendar', 'ews-fi-notset']];
$plan_words = ['Office' => __('Office', 'workforce-one'), 'WFH' => __('WFH', 'workforce-one'), 'Leave' => __('Leave', 'workforce-one'), 'Business Trip' => __('Business Trip', 'workforce-one'), 'Other' => __('Other', 'workforce-one'), 'Absent' => __('Absent', 'workforce-one'), 'Not Set' => __('Not Set', 'workforce-one')];
$actual_icons = ['Present' => 'check', 'Late' => 'overtime', 'Absent' => 'alert', 'Leave' => 'leave', 'Pending' => 'clock', 'Missing Sign-out' => 'alert'];
$actual_words = ['Present' => __('Present', 'workforce-one'), 'Late' => __('Late', 'workforce-one'), 'Absent' => __('Absent', 'workforce-one'), 'Leave' => __('Leave', 'workforce-one'), 'Pending' => __('Pending', 'workforce-one'), 'Missing Sign-out' => __('Missing Sign-out', 'workforce-one')];
$drill_attrs = static function (string $kind, string $label, string $date, array $names): string {
    return 'data-kind="' . esc_attr($kind) . '" data-label="' . esc_attr($label) . '" data-date="' . esc_attr($date) . '" data-names="' . esc_attr(wp_json_encode(array_values($names))) . '"';
};
$is_today = $focus === $today;
$day_heading = $is_today ? __('Today', 'workforce-one') : __('Selected Day', 'workforce-one');
?>
<div class="ews-fi wfo-insights">
    <section class="ews-fi-controls wfo-ins-controls">
        <div class="wfo-weeknav wfo-ins-nav">
            <a class="nav wfo-weeknav-prev" href="<?php echo esc_url($nav['prev_week']); ?>" aria-label="<?php esc_attr_e('Previous week', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a>
            <span class="ews-fi-badge"><?php esc_html_e('Week', 'workforce-one'); ?> <bdi><?php echo esc_html($week_label); ?></bdi></span>
            <a class="nav wfo-weeknav-next" href="<?php echo esc_url($nav['next_week']); ?>" aria-label="<?php esc_attr_e('Next week', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a>
        </div>
        <div class="wfo-weeknav wfo-ins-nav">
            <a class="nav wfo-weeknav-prev" href="<?php echo esc_url($nav['prev_day']); ?>" aria-label="<?php esc_attr_e('Previous day', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a>
            <span class="ews-fi-day-title"><?php echo esc_html($focus_label); ?></span>
            <a class="nav wfo-weeknav-next" href="<?php echo esc_url($nav['next_day']); ?>" aria-label="<?php esc_attr_e('Next day', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a>
        </div>
        <?php if (!$is_today): ?><a class="wfo-ins-today" href="<?php echo esc_url($nav['today']); ?>"><?php echo Icons::svg('sun', 15); ?><?php esc_html_e('Today', 'workforce-one'); ?></a><?php endif; ?>
        <form method="get" class="wfo-ins-filters">
            <input type="hidden" name="ews_view" value="attendance-insights">
            <label><span class="screen-reader-text"><?php esc_html_e('Team', 'workforce-one'); ?></span><select name="team" data-ews-autosubmit><option value="all"><?php esc_html_e('All Teams', 'workforce-one'); ?></option><?php foreach ($team_options as $team_name): ?><option value="<?php echo esc_attr($team_name); ?>" <?php selected($team, $team_name); ?>><?php echo esc_html($team_name); ?></option><?php endforeach; ?></select></label>
            <label class="ews-fi-date-label"><span><?php esc_html_e('Day', 'workforce-one'); ?></span><input type="date" name="focus_date" value="<?php echo esc_attr($focus); ?>" data-ews-autosubmit></label>
        </form>
    </section>

    <section class="ews-fi-card wfo-ins-card">
        <div class="ews-fi-head"><span class="wfo-ins-icon"><?php echo Icons::svg('people', 20); ?></span><div><h2><?php echo esc_html($day_heading); ?> · <?php esc_html_e('Workforce Overview', 'workforce-one'); ?></h2><p><?php esc_html_e('Planned workforce distribution. Click a count to see the employees behind it.', 'workforce-one'); ?></p></div></div>
        <div class="ews-fi-grid">
            <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): $names = $focus_drill['plan'][$label]; ?>
            <button type="button" class="ews-fi-tile <?php echo esc_attr($plan_style[$label][1]); ?>" <?php echo $drill_attrs('planned', $label, $focus_label, $names); ?>><span><?php echo Icons::svg($plan_style[$label][0], 20); ?></span><b><?php echo count($names); ?></b><small><?php echo esc_html($plan_words[$label] ?? $label); ?></small></button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="ews-fi-card wfo-ins-card">
        <div class="ews-fi-head"><span class="wfo-ins-icon is-green"><?php echo Icons::svg('attendance', 20); ?></span><div><h2><?php echo esc_html($day_heading); ?> · <?php esc_html_e('Attendance', 'workforce-one'); ?></h2><p><?php esc_html_e('Actual attendance classification based on schedule and time logs. Planned Office/WFH is not inferred from sign-in.', 'workforce-one'); ?></p></div></div>
        <div class="ews-fi-att">
            <?php foreach ($focus_drill['actual'] as $label => $names): ?>
            <button type="button" class="<?php echo esc_attr($label === 'Missing Sign-out' ? 'ews-fi-missing' : 'ews-fi-' . strtolower($label)); ?>" <?php echo $drill_attrs('actual', $label, $focus_label, $names); ?>><i><?php echo Icons::svg($actual_icons[$label] ?? 'calendar', 18, 2.2); ?></i><b><?php echo count($names); ?></b><span><?php echo esc_html($actual_words[$label] ?? $label); ?></span></button>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="ews-fi-card wfo-ins-card">
        <div class="ews-fi-head"><span class="wfo-ins-icon is-blue"><?php echo Icons::svg('calendar', 20); ?></span><div><h2><?php esc_html_e('Weekly Workforce Overview', 'workforce-one'); ?></h2><p><?php esc_html_e('Configured working days for the selected week. Counts are planned schedule values.', 'workforce-one'); ?></p></div></div>
        <div class="ews-fi-week" tabindex="0" role="region" aria-label="<?php esc_attr_e('Weekly Workforce Overview', 'workforce-one'); ?>"><table><thead><tr><th scope="col"><?php esc_html_e('Workforce', 'workforce-one'); ?></th>
            <?php foreach ($dates as $d): ?><th scope="col" class="<?php echo $d === $focus ? 'is-focus' : ''; ?>"><?php echo esc_html(date_i18n('D', strtotime($d))); ?><br><small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small></th><?php endforeach; ?>
        </tr></thead><tbody>
        <?php foreach (\WorkforceOne\Attendance\Insights::PLAN_ROWS as $label): ?>
            <tr><td class="ews-fi-rowlabel"><span class="wfo-ins-row <?php echo esc_attr($plan_style[$label][1]); ?>"><?php echo Icons::svg($plan_style[$label][0], 15); ?></span><?php echo esc_html($plan_words[$label] ?? $label); ?></td>
            <?php foreach ($dates as $d): $names = $drill[$d]['plan'][$label]; ?><td class="<?php echo $d === $focus ? 'is-focus' : ''; ?>"><button type="button" class="ews-fi-count<?php echo $names ? '' : ' is-zero'; ?>" <?php echo $drill_attrs('planned', $label, date_i18n('D, d M Y', strtotime($d)), $names); ?> data-day="<?php echo esc_attr($d); ?>"><?php echo count($names); ?></button></td><?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
</div>
<div class="ews-fi-drawer wfo-ins-drawer" id="ews-fi-drawer" aria-hidden="true" data-empty="<?php esc_attr_e('No employees in this category.', 'workforce-one'); ?>"><div class="ews-fi-panel" role="dialog" aria-labelledby="ews-fi-title"><div class="ews-fi-panel-head"><div><h2 id="ews-fi-title"><?php esc_html_e('Employees', 'workforce-one'); ?></h2><p id="ews-fi-subtitle"></p></div><button type="button" class="ews-fi-close" aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button></div><div class="ews-fi-names" id="ews-fi-names"></div></div></div>
