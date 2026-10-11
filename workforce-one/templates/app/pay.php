<?php
/**
 * Employee app → My Pay: the employee's own payslip for a closed month (final), or the current month
 * as an estimate (new look and translated, 3.31.69). Styles: assets/css/app-pay.css. No script: day
 * lists open with <details>.
 *
 * @var string $month          'Y-m'
 * @var string $month_label
 * @var array<string,mixed>|null $slip  src/Payroll/PayCalculator.php result (null = no payslip)
 * @var bool $final            the month is closed
 * @var string $closed_at
 * @var string $currency
 * @var array<string,mixed> $rules  rules the figures were worked out with
 * @var string $older_url
 * @var string $newer_url
 * @var string $preparing      last month's label while it is not closed ('' = nothing to say)
 * @var string $pdf_url        payslip PDF of a closed month ('' = none)
 */
if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\PayCalculator;
use WorkforceOne\Payroll\PayRules;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $line() and $days_list() escape their output.

$money = static function ($v) { return PayRules::money((float) $v); };
$day = static function ($d) { return date_i18n('D j M', strtotime($d)); };
$chev = '<span class="ews-pay-chev" aria-hidden="true">' . Icons::svg('chevron', 14, 2.2) . '</span>';
/* translators: %d: number of days */
$days_n = static function (int $n): string { return sprintf(_n('%d day', '%d days', $n, 'workforce-one'), $n); };
$line = static function ($title, $sub, $amount, $class = '') {
    return '<div class="ews-pay-ln"><div>' . $title . ($sub !== '' ? '<div class="ews-pay-ln-sub">' . esc_html($sub) . '</div>' : '') . '</div><div class="ews-pay-amt ' . esc_attr($class) . '">' . esc_html($amount) . '</div></div>';
};
$days_list = static function (array $rows) {
    if (!$rows) return '';
    $out = '<div class="ews-pay-days">';
    foreach ($rows as $r) $out .= '<div><span>' . esc_html($r[0]) . '</span><span>' . esc_html($r[1]) . '</span></div>';
    return $out . '</div>';
};
?>
<div class="ews-pay">
    <nav class="ews-pay-month" aria-label="<?php esc_attr_e('Month', 'workforce-one'); ?>">
        <?php if ($older_url): ?><a class="ews-pay-arrow" href="<?php echo esc_url($older_url); ?>" aria-label="<?php esc_attr_e('Earlier month', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a><?php else: ?><span class="ews-pay-arrow off" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span><?php endif; ?>
        <b><?php echo esc_html($month_label); ?></b>
        <?php if ($newer_url): ?><a class="ews-pay-arrow next" href="<?php echo esc_url($newer_url); ?>" aria-label="<?php esc_attr_e('Later month', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 18, 2.2); ?></a><?php else: ?><span class="ews-pay-arrow next off" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span><?php endif; ?>
    </nav>
<?php if (!$slip): ?>
    <div class="ews-pay-card ews-pay-empty"><?php /* translators: %s: month and year */ printf(esc_html__('No payslip for %s.', 'workforce-one'), esc_html($month_label)); ?></div>
<?php else: $p = $slip; ?>
    <section class="ews-pay-hero">
        <p class="k"><?php echo $final ? esc_html__('Net pay · before tax and insurance', 'workforce-one') : esc_html(sprintf(/* translators: %s: month name */ __('Expected net · if the rest of %s goes as scheduled', 'workforce-one'), date_i18n('F', strtotime($month . '-01')))); ?></p>
        <p class="v"><span><?php echo esc_html($currency); ?></span> <?php echo esc_html($money($p['net'])); ?></p>
        <?php if ($final): ?><span class="ews-pay-pill final"><?php echo Icons::svg('check', 14, 2.4); ?><?php /* translators: %s: date the month was closed */ printf(esc_html__('Final · closed %s', 'workforce-one'), esc_html(date_i18n('j M', strtotime($closed_at)))); ?></span>
        <?php else: ?><span class="ews-pay-pill est"><?php echo Icons::svg('clock', 14, 2.2); ?><?php esc_html_e('Estimate · changes until HR closes the month', 'workforce-one'); ?></span><?php endif; ?>
    </section>
    <?php if ($preparing !== ''): ?><div class="ews-pay-note"><?php /* translators: %s: month and year */ printf(esc_html__('Your payslip for %s is being prepared.', 'workforce-one'), esc_html($preparing)); ?></div><?php endif; ?>
    <div class="ews-pay-stats">
        <div><b><?php echo (int) $p['stats']['worked_days']; ?>/<?php echo (int) $p['stats']['expected_days']; ?></b><?php esc_html_e('Days worked', 'workforce-one'); ?></div>
        <div class="<?php echo (int) $p['stats']['late_days'] ? 'is-warn' : ''; ?>"><b><?php echo (int) $p['stats']['late_days']; ?></b><?php esc_html_e('Late days', 'workforce-one'); ?></div>
        <div class="<?php echo (int) $p['stats']['absent_days'] ? 'is-bad' : ''; ?>"><b><?php echo (int) $p['stats']['absent_days']; ?></b><?php esc_html_e('Absent', 'workforce-one'); ?></div>
        <div class="<?php echo (int) $p['overtime']['minutes'] ? 'is-good' : ''; ?>"><b><?php echo esc_html(PayCalculator::hm((int) $p['overtime']['minutes'])); ?></b><?php esc_html_e('Overtime', 'workforce-one'); ?></div>
    </div>
    <?php foreach ($p['review'] as $r): ?>
        <div class="ews-pay-warn"><?php echo Icons::svg('alert', 16, 2.2); ?><span><b><?php echo esc_html($day($r['date'])); ?>:</b> <?php esc_html_e('no Sign Out recorded. HR is reviewing this day; until then it counts as a full day.', 'workforce-one'); ?></span></div>
    <?php endforeach; ?>

    <div class="ews-pay-card"><h3><?php esc_html_e('Earnings', 'workforce-one'); ?></h3>
        <?php echo $line(esc_html__('Basic salary', 'workforce-one'), '', $money($p['basic'])); ?>
        <?php if ($p['allowances']): echo $line(esc_html__('Allowances', 'workforce-one'), implode(' · ', array_map(static function ($a) use ($money) { return $a['name'] . ' ' . $money($a['amount']); }, $p['allowances'])), $money($p['allowances_total'])); endif; ?>
        <?php if ($p['prorated']): echo $line(esc_html(sprintf(/* translators: %s: date */ __('Paid from %s', 'workforce-one'), $day($p['prorated']['from']))), $days_n((int) $p['prorated']['days']), $money($p['earned'])); endif; ?>
        <?php if ($p['overtime']['days']): ?>
            <details><summary><?php echo $line(esc_html__('Overtime (approved)', 'workforce-one') . ' ' . $chev, sprintf(/* translators: %s: hours:minutes */ __('%s worked', 'workforce-one'), PayCalculator::hm((int) $p['overtime']['minutes'])), '+ ' . $money($p['overtime']['amount']), 'plus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · ' . PayCalculator::hm((int) $d['minutes']) . ($d['off'] ? ' (' . __('day off', 'workforce-one') . ')' : ''), '× ' . rtrim(rtrim(number_format((float) $d['rate'], 2, '.', ''), '0'), '.') . ' · +' . PayRules::money((float) $d['amount'])]; }, $p['overtime']['days'])); ?></details>
        <?php endif; ?>
        <?php foreach ($p['bonuses']['items'] ?? [] as $a): echo $line(esc_html__('Bonus', 'workforce-one'), $a['reason'], '+ ' . $money($a['amount']), 'plus'); endforeach; ?>
    </div>

    <?php
    $has_deductions = $p['absence']['days'] || $p['late']['days'] || $p['early']['days'] || $p['leave']['types'] || !empty($p['manual']['items']);
    ?>
    <div class="ews-pay-card"><h3><?php esc_html_e('Deductions', 'workforce-one'); ?></h3>
        <?php if (!$has_deductions): ?><div class="ews-pay-ln"><div><?php esc_html_e('None this month', 'workforce-one'); ?></div><div class="ews-pay-amt">—</div></div><?php endif; ?>
        <?php if ($p['absence']['days']): ?>
            <details><summary><?php echo $line(esc_html__('Absent without leave', 'workforce-one') . ' ' . $chev, $days_n(count($p['absence']['days'])), '− ' . $money($p['absence']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']), '−' . PayRules::money((float) $d['amount'])]; }, $p['absence']['days'])); ?></details>
        <?php endif; ?>
        <?php if ($p['late']['days']): ?>
            <details><summary><?php echo $line(esc_html__('Late arrival', 'workforce-one') . ' ' . $chev, sprintf(/* translators: 1: minutes, 2: "3 days" */ __('%1$d min on %2$s, after the grace period', 'workforce-one'), (int) $p['late']['minutes'], $days_n(count($p['late']['days']))), '− ' . $money($p['late']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · ' . sprintf(/* translators: %s: sign-in time */ __('in %s', 'workforce-one'), $d['sign_in']), sprintf(/* translators: %d: minutes */ __('%d min', 'workforce-one'), (int) $d['minutes']) . ' · −' . PayRules::money((float) $d['amount'])]; }, $p['late']['days'])); ?></details>
        <?php endif; ?>
        <?php if ($p['early']['days']): ?>
            <details><summary><?php echo $line(esc_html__('Early leave without approval', 'workforce-one') . ' ' . $chev, sprintf(/* translators: %d: minutes */ __('%d min', 'workforce-one'), (int) $p['early']['minutes']), '− ' . $money($p['early']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · ' . sprintf(/* translators: %s: sign-out time */ __('out %s', 'workforce-one'), $d['sign_out']), sprintf(/* translators: %d: minutes */ __('%d min', 'workforce-one'), (int) $d['minutes']) . ' · −' . PayRules::money((float) $d['amount'])]; }, $p['early']['days'])); ?></details>
        <?php endif; ?>
        <?php foreach ($p['leave']['types'] as $type => $t): ?>
            <details><summary><?php echo $line(esc_html($type) . ' ' . $chev, sprintf(/* translators: 1: "3 days", 2: percent paid */ __('%1$s, %2$s%% paid', 'workforce-one'), $days_n((int) $t['days']), (string) $t['paid']), '− ' . $money($t['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d), '']; }, $t['dates'])); ?></details>
        <?php endforeach; ?>
        <?php foreach ($p['manual']['items'] ?? [] as $a): echo $line(esc_html__('Deduction', 'workforce-one'), $a['reason'], '− ' . $money($a['amount']), 'minus'); endforeach; ?>
        <?php if ($p['cap'] !== null): echo $line(esc_html(sprintf(/* translators: %s: number of days */ __('Limited to %s day(s)\' pay', 'workforce-one'), (string) $rules['max_deduction_days'])), sprintf(/* translators: %s: amount */ __('attendance deductions before the limit: %s', 'workforce-one'), $money($p['deductions_before_cap'])), '− ' . $money($p['cap']), 'minus'); endif; ?>
    </div>

    <div class="ews-pay-card ews-pay-net"><div class="ews-pay-ln total"><div><?php esc_html_e('Net pay', 'workforce-one'); ?></div><div class="ews-pay-amt"><?php echo esc_html($currency . ' ' . $money($p['net'])); ?></div></div></div>
    <?php if ($pdf_url !== ''): ?><a class="ews-pay-pdf" href="<?php echo esc_url($pdf_url); ?>"><?php echo Icons::svg('download', 18, 2.2); ?><?php esc_html_e('Download payslip (PDF)', 'workforce-one'); ?></a>
    <?php elseif (!$final): ?><p class="ews-pay-foot ews-pay-center"><?php esc_html_e('The payslip PDF is ready when HR closes the month.', 'workforce-one'); ?></p><?php endif; ?>
    <p class="ews-pay-foot"><?php printf(/* translators: 1: "basic" or "(basic + allowances)", 2: days in the divisor, 3: amount */ esc_html__('A day\'s pay = %1$s ÷ %2$d = %3$s. Amounts are before income tax and social insurance. A question about this month? Contact HR.', 'workforce-one'), esc_html(($rules['day_base'] ?? 'gross') === 'basic' ? __('basic', 'workforce-one') : __('(basic + allowances)', 'workforce-one')), (int) ($rules['day_divisor'] ?? 30), esc_html($money($p['day_value']))); ?></p>
<?php endif; ?>
</div>
