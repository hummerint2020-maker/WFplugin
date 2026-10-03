<?php
/**
 * Report Center → Attendance Summary. KPIs compared with the previous period of the same length;
 * every count links to the Daily Log days behind it. Sorting: assets/js/reports.js (data-ews-sortable).
 *
 * @var array<int,array<string,mixed>> $people   EmployeeSummary::byEmployee()
 * @var array<string,mixed> $totals              EmployeeSummary::totals() for the period
 * @var array<string,mixed> $previous            ... for the previous period
 * @var string $previous_label
 * @var callable $drill                          (employee id, result) → Daily Log URL
 * @var string $csv_url
 * @var string $xlsx_url
 * @var array<string,array{present:int,late:int,absent:int,rate:?int}> $trend  Trend::daily()
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\DayMetrics;

// [key, label, value, previous value, unit, higher is better]
$kpis = [
    ['attendance_rate', 'Attendance rate', $totals['attendance_rate'], $previous['attendance_rate'], '%', true],
    ['punctuality_rate', 'Punctuality', $totals['punctuality_rate'], $previous['punctuality_rate'], '%', true],
    ['late_minutes', 'Late minutes', $totals['late_minutes'], $previous['late_minutes'], ' min', false],
    ['absent', 'Absent days', $totals['absent'], $previous['absent'], '', false],
    ['missing_sign_out', 'Missing Sign-outs', $totals['missing_sign_out'], $previous['missing_sign_out'], '', false],
];
// A rate with no days behind it is shown as "—" (and sorts last), not 0%.
$rate = static function (int $value, int $days): array { return $days ? [$value, $value . '%'] : [-1, '—']; };
$count = static function (int $n, int $employee, string $result) use ($drill): string {
    return $n ? '<a href="' . esc_url($drill($employee, $result)) . '">' . $n . '</a>' : '0';
};
?>
<div class="ews-report-summary-card">
    <div class="ews-report-section-head"><div><h3>Attendance Summary</h3><p>Compared with the previous period (<?php echo esc_html($previous_label); ?>).</p></div><span><?php echo (int) count($people); ?> Employees</span></div>
    <div class="ews-rc-kpis">
        <?php foreach ($kpis as [$key, $label, $value, $before, $unit, $higher_better]):
            $diff = (int) $value - (int) $before; $good = $diff === 0 ? null : (($diff > 0) === $higher_better);
            $no_previous = (int) $previous['expected_days'] === 0; ?>
        <div class="ews-rc-kpi" data-kpi="<?php echo esc_attr($key); ?>">
            <small><?php echo esc_html($label); ?></small>
            <b><?php echo esc_html((int) $value . ($unit === '%' ? '%' : '')); ?></b>
            <?php if ($no_previous): ?><span class="ews-rc-delta flat">No data for the previous period</span>
            <?php else: ?><span class="ews-rc-delta <?php echo $good === null ? 'flat' : ($good ? 'good' : 'bad'); ?>"><?php echo esc_html(($diff > 0 ? '▲ ' : ($diff < 0 ? '▼ ' : '')) . abs($diff) . ($unit === '%' ? ' pts' : $unit) . ' vs previous'); ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <div class="ews-rc-kpi" data-kpi="net_minutes">
            <small>Net hours</small>
            <b><?php echo esc_html(DayMetrics::hm((int) $totals['net_minutes'])); ?></b>
            <span class="ews-rc-delta flat">of <?php echo esc_html(DayMetrics::hm((int) $totals['expected_minutes'])); ?> expected</span>
        </div>
    </div>
    <?php if ($trend):
        $bar = 18; $gap = 6; $w = max(count($trend), 14) * ($bar + $gap); $i = 0; // a short period keeps slim bars ?>
    <figure class="ews-rc-trend">
        <figcaption>Daily attendance rate <small>(Present + Late) / expected · hover a bar for the day</small></figcaption>
        <svg viewBox="0 0 <?php echo (int) $w; ?> 104" preserveAspectRatio="none" role="img" aria-label="Daily attendance rate">
            <line x1="0" y1="2" x2="<?php echo (int) $w; ?>" y2="2" class="ews-rc-trend-grid"/><line x1="0" y1="52" x2="<?php echo (int) $w; ?>" y2="52" class="ews-rc-trend-grid"/>
            <?php foreach ($trend as $date => $t):
                $day_rate = $t['rate']; $h = $day_rate === null ? 2 : max(2, (int) $day_rate);
                $level = $day_rate === null ? 'none' : ($day_rate >= 90 ? 'good' : ($day_rate >= 75 ? 'ok' : 'low')); ?>
            <rect class="ews-rc-trend-bar <?php echo esc_attr($level); ?>" data-date="<?php echo esc_attr($date); ?>" data-rate="<?php echo $day_rate === null ? -1 : (int) $day_rate; ?>" x="<?php echo (int) ($i++ * ($bar + $gap) + $gap / 2); ?>" y="<?php echo 102 - $h; ?>" width="<?php echo (int) $bar; ?>" height="<?php echo (int) $h; ?>" rx="3"><title><?php echo esc_html(date_i18n('D d M', strtotime($date)) . ': ' . ($day_rate === null ? 'nobody expected' : $day_rate . '% · ' . $t['present'] . ' present, ' . $t['late'] . ' late, ' . $t['absent'] . ' absent')); ?></title></rect>
            <?php endforeach; ?>
        </svg>
        <div class="ews-rc-trend-axis"><span><?php echo esc_html(date_i18n('d M', strtotime((string) array_key_first($trend)))); ?></span><?php if (count($trend) > 1): ?><span><?php echo esc_html(date_i18n('d M', strtotime((string) array_key_last($trend)))); ?></span><?php endif; ?></div>
    </figure>
    <?php endif; ?>
</div>

<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>By Employee</h3><p>Click a number to see the days behind it. Click a column title to sort.</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1">⇩ &nbsp;Export CSV</a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1">▣ &nbsp;Export Excel</a></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-summary" data-ews-sortable>
        <thead><tr>
            <th data-sort="text">Employee</th><th data-sort="num">Expected Days</th><th data-sort="num">Present</th><th data-sort="num">Late</th><th data-sort="num">Absent</th><th data-sort="num">Leave</th>
            <th data-sort="num">Attendance</th><th data-sort="num">Punctuality</th><th data-sort="num">Late (min)</th><th data-sort="num">Early Leave (min)</th><th data-sort="num">Missing Sign-out</th><th data-sort="text">Avg Sign In</th><th data-sort="num">Net Hours</th><th data-sort="num">Expected</th><th data-sort="num">Balance</th>
        </tr></thead>
        <tbody>
        <?php if (!$people): ?><tr><td colspan="15" class="ews-report-empty">No employees match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($people as $p): $id = (int) $p['employee_id'];
            [$att_v, $att] = $rate((int) $p['attendance_rate'], $p['present'] + $p['late'] + $p['absent']);
            [$pun_v, $pun] = $rate((int) $p['punctuality_rate'], $p['present'] + $p['late']); ?>
            <tr data-employee="<?php echo esc_attr($p['employee']); ?>">
                <td data-col="employee" data-value="<?php echo esc_attr(strtolower($p['employee'])); ?>"><strong><?php echo esc_html($p['employee']); ?></strong><small><?php echo esc_html($p['teams'] ? implode(' · ', $p['teams']) : $p['domain']); ?></small></td>
                <td data-col="expected_days" data-value="<?php echo (int) $p['expected_days']; ?>"><?php echo (int) $p['expected_days']; ?></td>
                <td data-col="present" data-value="<?php echo (int) $p['present']; ?>"><?php echo $count((int) $p['present'], $id, 'Present'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td data-col="late" data-value="<?php echo (int) $p['late']; ?>"><?php echo $count((int) $p['late'], $id, 'Late'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td data-col="absent" data-value="<?php echo (int) $p['absent']; ?>"><?php echo $count((int) $p['absent'], $id, 'Absent'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td data-col="leave" data-value="<?php echo (int) $p['leave']; ?>"><?php echo $count((int) $p['leave'], $id, 'Leave'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td data-col="attendance_rate" data-value="<?php echo (int) $att_v; ?>"><?php echo esc_html($att); ?></td>
                <td data-col="punctuality_rate" data-value="<?php echo (int) $pun_v; ?>"><?php echo esc_html($pun); ?></td>
                <td data-col="late_minutes" data-value="<?php echo (int) $p['late_minutes']; ?>"><?php echo (int) $p['late_minutes']; ?></td>
                <td data-col="early_minutes" data-value="<?php echo (int) $p['early_minutes']; ?>"><?php echo (int) $p['early_minutes']; ?></td>
                <td data-col="missing_sign_out" data-value="<?php echo (int) $p['missing_sign_out']; ?>"><?php echo $count((int) $p['missing_sign_out'], $id, 'Missing Sign-out'); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                <td data-col="avg_first_in" data-value="<?php echo esc_attr($p['avg_first_in']); ?>"><?php echo esc_html($p['avg_first_in'] !== '' ? $p['avg_first_in'] : '—'); ?></td>
                <td data-col="net" data-value="<?php echo (int) $p['net_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['net_minutes'])); ?></td>
                <td data-col="expected" data-value="<?php echo (int) $p['expected_minutes']; ?>"><?php echo esc_html(DayMetrics::hm((int) $p['expected_minutes'])); ?></td>
                <td data-col="balance" data-value="<?php echo (int) $p['balance_minutes']; ?>" class="<?php echo $p['balance_minutes'] < 0 ? 'ews-rc-neg' : ''; ?>"><?php echo esc_html(DayMetrics::signedHm((int) $p['balance_minutes'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
