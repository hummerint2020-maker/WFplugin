<?php
/**
 * Report Center → Timesheet: worked hours per employee for payroll, with overtime and leave taken.
 * Sorting: assets/js/reports.js (data-ews-sortable).
 *
 * @var array<int,array<string,mixed>> $people   Timesheet::byEmployee()
 * @var bool $overtime                           the Overtime feature is on
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\DayMetrics;
use WorkforceOne\Reports\Timesheet;
?>
<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>Timesheet</h3><p>Net hours are Sign In to Sign Out minus breaks. Decimal hours are for payroll (7:10 = 7.17).</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export CSV', 'workforce-one'); ?></a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('reports', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-summary" data-ews-sortable>
        <thead><tr>
            <th data-sort="text">Employee</th><th data-sort="num">Worked Days</th><th data-sort="num">Net Hours</th><th data-sort="num">Decimal</th><th data-sort="num">Expected</th><th data-sort="num">Balance</th>
            <?php if ($overtime): ?><th data-sort="num">OT Approved</th><th data-sort="num">OT Worked</th><th data-sort="num">Unapproved Extra</th><?php endif; ?>
            <th data-sort="num">Late (min)</th><th data-sort="num">Early Leave (min)</th><th data-sort="num">Absent</th><th data-sort="num">Leave Days</th><th data-sort="text">Leave Taken</th><th data-sort="num">Holidays</th>
        </tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="15" class="ews-report-empty">No employees match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($people as $p): ?>
            <tr data-employee="<?php echo esc_attr($p['employee']); ?>">
                <td data-col="employee" data-value="<?php echo esc_attr(strtolower($p['employee'])); ?>"><strong><?php echo esc_html($p['employee']); ?></strong><small><?php echo esc_html($p['domain']); ?></small></td>
                <td data-col="worked_days" data-value="<?php echo (int) $p['worked_days']; ?>"><?php echo (int) $p['worked_days']; ?></td>
                <td data-col="net" data-value="<?php echo (int) $p['net_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['net_minutes'])); ?></td>
                <td data-col="net_decimal" data-value="<?php echo (int) $p['net_minutes']; ?>"><?php echo esc_html(Timesheet::decimalHours((int) $p['net_minutes'])); ?></td>
                <td data-col="expected" data-value="<?php echo (int) $p['expected_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['expected_minutes'])); ?></td>
                <td data-col="balance" data-value="<?php echo (int) $p['balance_minutes']; ?>" class="<?php echo $p['balance_minutes'] < 0 ? 'ews-rc-neg' : ''; ?>"><?php echo esc_html(DayMetrics::signedHm((int) $p['balance_minutes'])); ?></td>
                <?php if ($overtime): ?>
                <td data-col="ot_approved" data-value="<?php echo (int) $p['ot_approved']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['ot_approved'])); ?></td>
                <td data-col="ot_actual" data-value="<?php echo (int) $p['ot_actual']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['ot_actual'])); ?></td>
                <td data-col="ot_extra" data-value="<?php echo (int) $p['ot_extra']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['ot_extra'])); ?></td>
                <?php endif; ?>
                <td data-col="late_minutes" data-value="<?php echo (int) $p['late_minutes']; ?>"><?php echo (int) $p['late_minutes']; ?></td>
                <td data-col="early_minutes" data-value="<?php echo (int) $p['early_minutes']; ?>"><?php echo (int) $p['early_minutes']; ?></td>
                <td data-col="absent" data-value="<?php echo (int) $p['absent']; ?>"><?php echo (int) $p['absent']; ?></td>
                <td data-col="leave_days" data-value="<?php echo (int) $p['leave_days']; ?>"><?php echo (int) $p['leave_days']; ?></td>
                <td data-col="leave_breakdown" data-value="<?php echo esc_attr($p['leave_breakdown']); ?>"><?php echo esc_html($p['leave_breakdown'] !== '' ? $p['leave_breakdown'] : '—'); ?></td>
                <td data-col="holidays" data-value="<?php echo (int) $p['holidays']; ?>"><?php echo (int) $p['holidays']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
