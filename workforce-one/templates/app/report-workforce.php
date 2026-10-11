<?php
/**
 * Report Center → Workforce: planned distribution per day, holidays and actual absences.
 *
 * @var array<string,array<string,int>> $days   Workforce::byDay()
 * @var array<string,int> $totals
 * @var array<string,string> $columns           Workforce::COLUMNS
 * @var int $employee_count
 * @var string $period_label
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
$cards = [['office', 'office', 'blue'], ['wfh', 'wfh', 'purple'], ['leave', 'leave', 'amber'], ['trip', 'briefcase', 'cyan'], ['other', 'people', 'gray'], ['holiday', 'sparkle', 'blue']];
?>
<div class="ews-report-summary-card"><div class="ews-report-section-head"><div><h3>Workforce Summary</h3><p>Planned employee-days across <?php echo esc_html($period_label); ?></p></div><span><?php echo (int) $employee_count; ?> Employees</span></div>
    <div class="ews-report-summary-grid">
        <?php foreach ($cards as [$key, $icon, $color]): ?><div class="ews-report-summary-item <?php echo esc_attr($color); ?>"><span><?php echo \WorkforceOne\Ui\Icons::svg($icon, 20); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span><div><b><?php echo (int) $totals[$key]; ?></b><small><?php echo esc_html($columns[$key]); ?></small></div></div><?php endforeach; ?>
    </div>
</div>
<div class="ews-report-results-card"><div class="ews-report-section-head"><div><h3>Daily Workforce Distribution</h3><p>Counts follow the planned schedule (any schedule type; types you added are under Other). Absent shows who was planned to work and did not sign in.</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export CSV', 'workforce-one'); ?></a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('reports', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-workforce-table"><thead><tr><th>Date</th><?php foreach ($columns as $label): ?><th><?php echo esc_html($label); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($days as $date => $row): ?>
        <tr data-date="<?php echo esc_attr($date); ?>"><td><strong><?php echo esc_html(date_i18n('d M', strtotime($date))); ?></strong><small><?php echo esc_html(date_i18n('D', strtotime($date))); ?></small></td>
            <?php foreach ($columns as $key => $label): ?><td data-col="<?php echo esc_attr($key); ?>"><?php echo (int) $row[$key]; ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    <?php if (!$days): ?><tr><td colspan="<?php echo count($columns) + 1; ?>" class="ews-report-empty">No configured working days in this period.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
