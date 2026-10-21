<?php
/**
 * Report Center → Daily Log: every employee-day with its plan, Sign In / Out, result, late minutes and
 * hours, 25 rows per page.
 *
 * @var array<string,int> $summary          Summary::add() counts
 * @var string[] $cards_order               Summary::BUCKETS
 * @var int $employee_count
 * @var int $working_days
 * @var string $period_label
 * @var array<int,array<string,mixed>> $view the page's rows (+ planned_html, result_html)
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int $per
 * @var callable $page_url                  (page number) → URL
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\DayMetrics;
$icons = ['Present' => ['check', 'green'], 'Late' => ['overtime', 'amber'], 'Absent' => ['alert', 'red'], 'Leave' => ['leave', 'blue'], 'Holiday' => ['sparkle', 'blue'], 'Business Trip' => ['briefcase', 'purple'], 'Pending' => ['clock', 'gray'], 'Not Scheduled' => ['calendar', 'gray'], 'Missing Sign-out' => ['alert', 'amber'], 'At Another Branch' => ['pin', 'amber']];
?>
<div class="ews-report-summary-card">
    <div class="ews-report-section-head"><div><h3>Report Summary</h3><p><?php echo esc_html($period_label); ?></p></div><span><?php echo (int) $employee_count; ?> Employees <i>•</i> <?php echo (int) $working_days; ?> configured working days</span></div>
    <div class="ews-report-summary-grid">
        <?php foreach ($cards_order as $label): $c = $icons[$label] ?? ['calendar', 'gray']; ?><div class="ews-report-summary-item <?php echo esc_attr($c[1]); ?>"><span><?php echo \WorkforceOne\Ui\Icons::svg($c[0], 20); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span><div><b><?php echo (int) ($summary[$label] ?? 0); ?></b><small><?php echo esc_html($label); ?></small></div></div><?php endforeach; ?>
    </div>
</div>

<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>Report Results <small>(<?php echo (int) $total; ?> records)</small></h3><p>Every employee-day. Net hours are Sign In to Sign Out minus breaks.</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export CSV', 'workforce-one'); ?></a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('reports', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table"><thead><tr><th>Employee</th><th>Team</th><th>Date</th><th>Planned</th><th>Sign In</th><th>Sign Out</th><th>Result</th><th>Late</th><th>Net / Expected</th></tr></thead><tbody>
    <?php if (!$view): ?><tr><td colspan="9" class="ews-report-empty">No records match the selected filters.</td></tr><?php endif; ?>
    <?php foreach ($view as $r): ?>
        <tr><td><strong><?php echo esc_html($r['employee']); ?></strong><small><?php echo esc_html($r['domain']); ?></small></td>
            <td><?php echo esc_html($r['teams'] ? implode(' · ', $r['teams']) : '—'); ?></td>
            <td><strong><?php echo esc_html(date_i18n('d M', strtotime($r['date']))); ?></strong><small><?php echo esc_html(date_i18n('D', strtotime($r['date'])) . ($r['holiday'] !== '' ? ' · ' . $r['holiday'] : '')); ?></small></td>
            <td><?php echo $r['planned_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by report_planned_badge() ?></td>
            <td><?php echo esc_html($r['sign_in'] ?: '—'); ?></td><td><?php echo esc_html($r['sign_out'] ?: '—'); ?></td>
            <td><div class="ews-report-statuses"><?php echo $r['result_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by report_status_badge() ?></div></td>
            <td><?php echo $r['late_minutes'] ? esc_html($r['late_minutes'] . ' min') : '—'; ?></td>
            <td><?php echo esc_html(DayMetrics::hm((int) $r['net_minutes']) . ($r['expected'] ? ' / ' . DayMetrics::hm((int) $r['expected_minutes']) : '')); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($pages > 1): ?><div class="ews-report-pagination"><span>Showing <?php echo (int) (($page - 1) * $per + 1); ?> to <?php echo (int) min($page * $per, $total); ?> of <?php echo (int) $total; ?> records</span><div>
        <?php for ($i = 1; $i <= $pages; $i++): if ($i > 5 && $i < $pages - 1 && abs($i - $page) > 1) continue; ?><a class="<?php echo $i === $page ? 'active' : ''; ?>" href="<?php echo esc_url($page_url($i)); ?>"><?php echo (int) $i; ?></a><?php endfor; ?>
    </div></div><?php endif; ?>
</div>
