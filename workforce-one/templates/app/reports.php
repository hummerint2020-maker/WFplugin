<?php
/**
 * Employee app: Report Center. Shared filters for every report, then the chosen report's body.
 * Styles: assets/css/workforce-one.css (.ews-report-*, .ews-rc-*); script: assets/js/reports.js.
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
?>
<div class="ews-reports-page" data-report="<?php echo esc_attr($type); ?>">
    <div class="ews-reports-head">
        <div><h2>Reports</h2><p><?php echo esc_html($tabs[$type]['hint']); ?></p></div>
        <div class="ews-reports-head-badge">Report Center</div>
    </div>
    <nav class="ews-report-tabs" aria-label="Reports">
        <?php foreach ($tabs as $key => $tab): ?><a class="ews-report-tab <?php echo $type === $key ? 'active' : ''; ?>" href="<?php echo esc_url($tab['url']); ?>" title="<?php echo esc_attr($tab['hint']); ?>"><?php echo esc_html($tab['title']); ?></a><?php endforeach; ?>
    </nav>
    <?php if ($views): ?>
    <div class="ews-rc-views"><span>Saved views</span>
        <?php foreach ($views as $v): ?>
        <span class="ews-rc-view-chip"><a class="ews-rc-view" href="<?php echo esc_url($v['url']); ?>"><?php echo esc_html($v['name']); ?></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="ews_report_view_delete"><input type="hidden" name="view_id" value="<?php echo esc_attr($v['id']); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_report_view')); ?>">
            <button type="submit" aria-label="<?php echo esc_attr('Remove saved view ' . $v['name']); ?>" title="Remove">×</button></form></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form class="ews-report-filter-card" method="get">
        <input type="hidden" name="ews_view" value="reports"><input type="hidden" name="report_type" value="<?php echo esc_attr($type); ?>">
        <div class="ews-report-filter-head"><div><h3>Report Period</h3><p><?php echo esc_html($period_label); ?></p></div></div>
        <div class="ews-report-period-grid">
            <label>From Date<input type="date" name="start" value="<?php echo esc_attr($start); ?>"></label>
            <label>To Date<input type="date" name="end" value="<?php echo esc_attr($end); ?>"></label>
            <div class="ews-report-quick"><span>Quick Range</span><div><?php foreach ($quick as $label => $q): ?><a class="<?php echo $q['active'] ? 'active' : ''; ?>" href="<?php echo esc_url($q['url']); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></div></div>
        </div>
        <div class="ews-report-filter-grid">
            <label>Team<select name="team"><option value="all">All Teams</option><?php foreach ($team_options as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($team, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
            <label>Employee<select name="employee"><option value="0">All Employees</option><?php foreach ($emps as $emp): ?><option value="<?php echo (int) $emp->id; ?>" <?php selected($employee_id, (int) $emp->id); ?>><?php echo esc_html($emp->name . (!$emp->active ? ' (Inactive)' : '')); ?></option><?php endforeach; ?></select></label>
            <label>Employee Status<select name="employee_status"><option value="active" <?php selected($employee_status, 'active'); ?>>Active Only</option><option value="inactive" <?php selected($employee_status, 'inactive'); ?>>Inactive Only</option><option value="all" <?php selected($employee_status, 'all'); ?>>All Employees</option></select></label>
            <?php if ($type === 'attendance'): ?>
            <label>Result<select name="status"><option value="all">All Results</option><?php foreach ($statuses as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($status, $v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
            <?php else: ?><div></div><?php endif; ?>
            <div class="ews-report-generate"><button class="ews-btn" type="submit">▥ &nbsp;Generate Report</button></div>
        </div>
    </form>
    <form class="ews-rc-save-view" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ews_report_view_save"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_report_view')); ?>">
        <?php foreach (['report_type' => $type, 'start' => $start, 'end' => $end, 'team' => $team, 'employee' => (string) $employee_id, 'employee_status' => $employee_status, 'range' => $range] + ($type === 'attendance' ? ['status' => $status] : []) as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
        <label>Save this report as a view<input type="text" name="view_name" maxlength="60" required placeholder="e.g. Ops team this month"></label>
        <button class="ews-btn secondary" type="submit">☆ &nbsp;Save view</button>
        <small><?php echo $range !== '' ? esc_html('The period is saved as "' . ucwords(str_replace('_', ' ', $range)) . '", so the view always opens on the current one.') : 'The view keeps these dates.'; ?></small>
    </form>

    <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built (and escaped) by the report builder ?>
</div>
