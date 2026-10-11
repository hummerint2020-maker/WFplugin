<?php
/**
 * Report Center → Overtime: requests by status, approved hours, hours worked inside them and the
 * unapproved extra time, per employee. Days off and company holidays count (no shift on them).
 * Sorting: assets/js/reports.js (data-ews-sortable).
 *
 * @var array<int,array<string,mixed>> $people   OvertimeReport::byEmployee()
 * @var array<string,mixed> $totals              OvertimeReport::totals()
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\DayMetrics;

$util = static function (?int $u): string { return $u === null ? '—' : $u . '%'; };
?>
<div class="ews-report-summary-card">
    <div class="ews-report-section-head"><div><h3>Overtime</h3><p>Approved overtime against the time actually worked in it. On days off and company holidays the whole approved window counts.</p></div><span><?php echo (int) $totals['employees']; ?> Employees</span></div>
    <div class="ews-rc-kpis">
        <div class="ews-rc-kpi" data-kpi="approved_minutes"><small>Approved</small><b><?php echo esc_html(DayMetrics::hm((int) $totals['approved_minutes'])); ?></b><span class="ews-rc-delta flat"><?php echo (int) $totals['approved']; ?> approved requests</span></div>
        <div class="ews-rc-kpi" data-kpi="worked_minutes"><small>Worked</small><b><?php echo esc_html(DayMetrics::hm((int) $totals['worked_minutes'])); ?></b><span class="ews-rc-delta flat"><?php echo esc_html($util($totals['utilisation'])); ?> of approved</span></div>
        <div class="ews-rc-kpi" data-kpi="extra_minutes"><small>Unapproved extra</small><b><?php echo esc_html(DayMetrics::hm((int) $totals['extra_minutes'])); ?></b><span class="ews-rc-delta <?php echo $totals['extra_minutes'] > 0 ? 'bad' : 'flat'; ?>">after the shift, without approval</span></div>
        <div class="ews-rc-kpi" data-kpi="pending"><small>Pending requests</small><b><?php echo (int) $totals['pending']; ?></b><span class="ews-rc-delta flat"><?php echo (int) $totals['rejected']; ?> rejected</span></div>
    </div>
</div>

<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>By Employee</h3><p>Only employees with overtime requests or extra time in the period. Click a column title to sort.</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export CSV', 'workforce-one'); ?></a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('reports', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-summary" data-ews-sortable>
        <thead><tr>
            <th data-sort="text">Employee</th><th data-sort="num">Requests</th><th data-sort="num">Approved</th><th data-sort="num">Pending</th><th data-sort="num">Rejected</th>
            <th data-sort="num">Approved Hours</th><th data-sort="num">Worked Hours</th><th data-sort="num">Utilisation</th><th data-sort="num">Days Worked</th><th data-sort="num">Unapproved Extra</th>
        </tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="10" class="ews-report-empty">No overtime in the selected period.</td></tr><?php endif; ?>
        <?php foreach ($people as $p): ?>
            <tr data-employee="<?php echo esc_attr($p['employee']); ?>">
                <td data-col="employee" data-value="<?php echo esc_attr(strtolower($p['employee'])); ?>"><strong><?php echo esc_html($p['employee']); ?></strong><small><?php echo esc_html($p['teams'] ? implode(' · ', $p['teams']) : $p['domain']); ?></small></td>
                <?php foreach (['requests', 'approved', 'pending', 'rejected'] as $k): ?><td data-col="<?php echo esc_attr($k); ?>" data-value="<?php echo (int) $p[$k]; ?>"><?php echo (int) $p[$k]; ?></td><?php endforeach; ?>
                <td data-col="approved_minutes" data-value="<?php echo (int) $p['approved_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['approved_minutes'])); ?></td>
                <td data-col="worked_minutes" data-value="<?php echo (int) $p['worked_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['worked_minutes'])); ?></td>
                <td data-col="utilisation" data-value="<?php echo $p['utilisation'] === null ? -1 : (int) $p['utilisation']; ?>"><?php echo esc_html($util($p['utilisation'])); ?></td>
                <td data-col="days" data-value="<?php echo (int) $p['days']; ?>"><?php echo (int) $p['days']; ?></td>
                <td data-col="extra_minutes" data-value="<?php echo (int) $p['extra_minutes']; ?>" class="<?php echo $p['extra_minutes'] > 0 ? 'ews-rc-neg' : ''; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['extra_minutes'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
