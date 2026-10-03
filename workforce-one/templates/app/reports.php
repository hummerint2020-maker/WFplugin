<?php
/**
 * Employee app: Report Center. Shared filters for every report, then the chosen report's body.
 * Styles: assets/css/workforce-one.css (.ews-report-*, .ews-rc-*); script: assets/js/reports.js.
 *
 * @var string $type                    summary | attendance | workforce
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

    <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built (and escaped) by the report builder ?>
</div>
