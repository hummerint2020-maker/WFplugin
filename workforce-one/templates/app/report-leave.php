<?php
/**
 * Report Center → Leave & Balances: days on leave in the period, leave requests overlapping it and
 * the balance of each leave type for the period's leave year. Sorting: assets/js/reports.js.
 *
 * @var array<int,array<string,mixed>> $people   LeaveReport::byEmployee()
 * @var array<string,int> $totals                LeaveReport::totals()
 * @var string[] $types                          active leave types (balance columns)
 * @var int $year                                the leave year of the balances
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\LeaveReport;
?>
<div class="ews-report-summary-card">
    <div class="ews-report-section-head"><div><h3>Leave &amp; Balances</h3><p>Leave days come from the schedule; requests are those overlapping the period; balances are for <?php echo (int) $year; ?>.</p></div><span><?php echo (int) count($people); ?> Employees</span></div>
    <div class="ews-rc-kpis">
        <div class="ews-rc-kpi" data-kpi="leave_days"><small>Leave days</small><b><?php echo (int) $totals['leave_days']; ?></b><span class="ews-rc-delta flat"><?php echo esc_html($totals['on_leave'] === 1 ? '1 employee on leave' : $totals['on_leave'] . ' employees on leave'); ?></span></div>
        <div class="ews-rc-kpi" data-kpi="approved_requests"><small>Approved requests</small><b><?php echo (int) $totals['approved_requests']; ?></b><span class="ews-rc-delta flat">overlapping the period</span></div>
        <div class="ews-rc-kpi" data-kpi="pending_requests"><small>Pending requests</small><b><?php echo (int) $totals['pending_requests']; ?></b><span class="ews-rc-delta <?php echo $totals['pending_requests'] ? 'bad' : 'flat'; ?>">waiting for a decision</span></div>
        <div class="ews-rc-kpi" data-kpi="overdrawn"><small>Overdrawn balances</small><b><?php echo (int) $totals['overdrawn']; ?></b><span class="ews-rc-delta <?php echo $totals['overdrawn'] ? 'bad' : 'flat'; ?>">employees below zero</span></div>
    </div>
</div>

<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>By Employee</h3><p>Balance columns show the days remaining of the year's entitlement (pending requests already reserved).</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1">⇩ &nbsp;Export CSV</a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1">▣ &nbsp;Export Excel</a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-summary" data-ews-sortable>
        <thead><tr>
            <th data-sort="text">Employee</th><th data-sort="num">Leave Days</th><th data-sort="text">Leave Taken</th><th data-sort="num">Approved Requests</th><th data-sort="num">Approved Days</th><th data-sort="num">Pending Requests</th><th data-sort="num">Pending Days</th>
            <?php foreach ($types as $type): ?><th data-sort="num"><?php echo esc_html($type); ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="<?php echo 7 + count($types); ?>" class="ews-report-empty">No employees match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($people as $p): ?>
            <tr data-employee="<?php echo esc_attr($p['employee']); ?>">
                <td data-col="employee" data-value="<?php echo esc_attr(strtolower($p['employee'])); ?>"><strong><?php echo esc_html($p['employee']); ?></strong><small><?php echo esc_html($p['teams'] ? implode(' · ', $p['teams']) : $p['domain']); ?></small></td>
                <td data-col="leave_days" data-value="<?php echo (int) $p['leave_days']; ?>"><?php echo (int) $p['leave_days']; ?></td>
                <td data-col="leave_breakdown" data-value="<?php echo esc_attr($p['leave_breakdown']); ?>"><?php echo esc_html($p['leave_breakdown'] !== '' ? $p['leave_breakdown'] : '—'); ?></td>
                <td data-col="approved_requests" data-value="<?php echo (int) $p['approved_requests']; ?>"><?php echo (int) $p['approved_requests']; ?></td>
                <td data-col="approved_days" data-value="<?php echo esc_attr(LeaveReport::days((float) $p['approved_days'])); ?>"><?php echo esc_html(LeaveReport::days((float) $p['approved_days'])); ?></td>
                <td data-col="pending_requests" data-value="<?php echo (int) $p['pending_requests']; ?>"><?php echo (int) $p['pending_requests']; ?></td>
                <td data-col="pending_days" data-value="<?php echo esc_attr(LeaveReport::days((float) $p['pending_days'])); ?>"><?php echo esc_html(LeaveReport::days((float) $p['pending_days'])); ?></td>
                <?php foreach ($types as $type): $b = $p['balances'][$type] ?? null; ?>
                <td data-col="balance:<?php echo esc_attr(LeaveReport::typeKey($type)); ?>" data-value="<?php echo $b ? esc_attr(LeaveReport::days($b['remaining'])) : ''; ?>" class="<?php echo $b && $b['remaining'] < 0 ? 'ews-rc-neg' : ''; ?>"<?php if ($b): ?> title="<?php echo esc_attr(sprintf('Entitlement %s · used %s · pending %s', LeaveReport::days($b['entitlement']), LeaveReport::days($b['used']), LeaveReport::days($b['pending']))); ?>"<?php endif; ?>>
                    <?php if ($b): ?><strong><?php echo esc_html(LeaveReport::days($b['remaining'])); ?></strong> / <?php echo esc_html(LeaveReport::days($b['entitlement'])); ?><?php else: ?>—<?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
