<?php
/**
 * Employee app: Report Center. Shared filters for every report, then the chosen report's body.
 * Styles: assets/css/app-reports.css (new look, 3.31.57) over the older .ews-report-* / .ews-rc-* rules
 * in workforce-one.css; script: assets/js/reports.js.
 *
 * @var string $type                    summary | attendance | timesheet | overtime | leave | workforce
 * @var array<string,array{title:string,hint:string,url:string}> $tabs
 * @var string $start
 * @var string $end
 * @var string $team
 * @var int $employee_id
 * @var string $status                  Daily Log result filter
 * @var string $employee_status
 * @var string[] $team_options
 * @var object[] $emps
 * @var array<string,array{url:string,active:bool}> $quick
 * @var string[] $statuses
 * @var string $body                    the report's own HTML
 * @var string $period_label
 * @var array<int,array{id:string,name:string,url:string}> $views  the user's saved views
 * @var string $range                   the quick range the period matches ('' = custom dates)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
$tab_icons = ['summary' => 'insights', 'attendance' => 'attendance', 'timesheet' => 'clock', 'overtime' => 'overtime', 'leave' => 'leave', 'workforce' => 'people', 'capacity' => 'office'];
?>
<div class="ews-reports-page wfo-reports" data-report="<?php echo esc_attr($type); ?>">
    <nav class="ews-report-tabs wfo-report-tabs" aria-label="<?php esc_attr_e('Reports', 'workforce-one'); ?>">
        <?php foreach ($tabs as $key => $tab): ?><a class="ews-report-tab <?php echo $type === $key ? 'active' : ''; ?>" href="<?php echo esc_url($tab['url']); ?>" title="<?php echo esc_attr($tab['hint']); ?>"<?php echo $type === $key ? ' aria-current="page"' : ''; ?>><?php echo Icons::svg($tab_icons[$key] ?? 'reports', 16); ?><span><?php echo esc_html($tab['title']); ?></span></a><?php endforeach; ?>
    </nav>
    <p class="wfo-report-hint"><?php echo esc_html($tabs[$type]['hint']); ?></p>

    <?php if ($views): ?>
    <div class="ews-rc-views wfo-report-views"><span class="wfo-report-views-label"><?php echo Icons::svg('sparkle', 15); ?><?php esc_html_e('Saved views', 'workforce-one'); ?></span>
        <?php foreach ($views as $v): ?>
        <span class="ews-rc-view-chip"><a class="ews-rc-view" href="<?php echo esc_url($v['url']); ?>"><?php echo esc_html($v['name']); ?></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ews_report_view_delete"><input type="hidden" name="view_id" value="<?php echo esc_attr($v['id']); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_report_view')); ?>">
            <button type="submit" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: view name */ __('Remove saved view %s', 'workforce-one'), $v['name'])); ?>" title="<?php esc_attr_e('Remove', 'workforce-one'); ?>"><?php echo Icons::svg('close', 13, 2.4); ?></button></form></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form class="ews-report-filter-card wfo-report-filters" method="get">
        <input type="hidden" name="ews_view" value="reports"><input type="hidden" name="report_type" value="<?php echo esc_attr($type); ?>">
        <div class="ews-report-filter-head"><span class="wfo-report-filter-icon"><?php echo Icons::svg('calendar', 20); ?></span><div><h3><?php esc_html_e('Report Period', 'workforce-one'); ?></h3><p dir="ltr"><?php echo esc_html($period_label); ?></p></div></div>
        <div class="ews-report-quick"><span class="screen-reader-text"><?php esc_html_e('Quick Range', 'workforce-one'); ?></span><div><?php foreach ($quick as $label => $q): ?><a class="<?php echo $q['active'] ? 'active' : ''; ?>" href="<?php echo esc_url($q['url']); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></div></div>
        <div class="ews-report-filter-grid wfo-report-filter-grid">
            <label><?php esc_html_e('From Date', 'workforce-one'); ?><input type="date" name="start" value="<?php echo esc_attr($start); ?>"></label>
            <label><?php esc_html_e('To Date', 'workforce-one'); ?><input type="date" name="end" value="<?php echo esc_attr($end); ?>"></label>
            <label><?php esc_html_e('Team', 'workforce-one'); ?><select name="team"><option value="all"><?php esc_html_e('All Teams', 'workforce-one'); ?></option><?php foreach ($team_options as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($team, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
            <label><?php esc_html_e('Employee', 'workforce-one'); ?><select name="employee"><option value="0"><?php esc_html_e('All Employees', 'workforce-one'); ?></option><?php foreach ($emps as $emp): ?><option value="<?php echo (int) $emp->id; ?>" <?php selected($employee_id, (int) $emp->id); ?>><?php echo esc_html($emp->name . (!$emp->active ? ' (' . __('Inactive', 'workforce-one') . ')' : '')); ?></option><?php endforeach; ?></select></label>
            <label><?php esc_html_e('Employee Status', 'workforce-one'); ?><select name="employee_status"><option value="active" <?php selected($employee_status, 'active'); ?>><?php esc_html_e('Active Only', 'workforce-one'); ?></option><option value="inactive" <?php selected($employee_status, 'inactive'); ?>><?php esc_html_e('Inactive Only', 'workforce-one'); ?></option><option value="all" <?php selected($employee_status, 'all'); ?>><?php esc_html_e('All Employees', 'workforce-one'); ?></option></select></label>
            <?php if ($type === 'attendance'): ?>
            <label><?php esc_html_e('Result', 'workforce-one'); ?><select name="status"><option value="all"><?php esc_html_e('All Results', 'workforce-one'); ?></option><?php foreach ($statuses as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($status, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
            <div class="ews-report-generate"><button class="ews-btn wfo-report-btn" type="submit"><?php echo Icons::svg('reports', 17); ?><?php esc_html_e('Generate Report', 'workforce-one'); ?></button></div>
        </div>
    </form>
    <details class="wfo-report-save">
        <summary><?php echo Icons::svg('sparkle', 15); ?><span><?php esc_html_e('Save this report as a view', 'workforce-one'); ?></span></summary>
        <form class="ews-rc-save-view" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ews_report_view_save"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_report_view')); ?>">
            <?php foreach (['report_type' => $type, 'start' => $start, 'end' => $end, 'team' => $team, 'employee' => (string) $employee_id, 'employee_status' => $employee_status, 'range' => $range] + ($type === 'attendance' ? ['status' => $status] : []) as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <label><span class="screen-reader-text"><?php esc_html_e('View name', 'workforce-one'); ?></span><input type="text" name="view_name" maxlength="60" required placeholder="<?php esc_attr_e('e.g. Ops team this month', 'workforce-one'); ?>"></label>
            <button class="ews-btn secondary wfo-report-btn is-ghost" type="submit"><?php esc_html_e('Save view', 'workforce-one'); ?></button>
            <small><?php echo $range !== '' ? esc_html(sprintf(/* translators: %s: range name, e.g. This Month */ __('The period is saved as "%s", so the view always opens on the current one.', 'workforce-one'), ucwords(str_replace('_', ' ', $range)))) : esc_html__('The view keeps these dates.', 'workforce-one'); ?></small>
        </form>
    </details>

    <?php echo $body; // built (and escaped) by the report builder ?>
</div>
