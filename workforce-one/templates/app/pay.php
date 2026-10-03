<?php
/**
 * Employee app → My Pay: the employee's own payslip for a closed month (final), or the current month
 * as an estimate. Styles: assets/css/app-pay.css. No script: day lists open with <details>.
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
 */
if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\PayCalculator;
use WorkforceOne\Payroll\PayRules;

$money = static function ($v) { return PayRules::money((float) $v); };
$day = static function ($d) { return date('D j M', strtotime($d)); };
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
    <div class="ews-pay-month">
        <?php if ($older_url): ?><a class="ews-pay-arrow" href="<?php echo esc_url($older_url); ?>" aria-label="Earlier month">‹</a><?php else: ?><span class="ews-pay-arrow off">‹</span><?php endif; ?>
        <b><?php echo esc_html($month_label); ?></b>
        <?php if ($newer_url): ?><a class="ews-pay-arrow" href="<?php echo esc_url($newer_url); ?>" aria-label="Later month">›</a><?php else: ?><span class="ews-pay-arrow off">›</span><?php endif; ?>
    </div>
<?php if (!$slip): ?>
    <div class="ews-pay-card ews-pay-empty">No payslip for <?php echo esc_html($month_label); ?>.</div>
<?php else: $p = $slip; ?>
    <div class="ews-pay-hero">
        <div class="k"><?php echo $final ? 'Net pay · before tax and insurance' : esc_html('Expected net · if the rest of ' . date('F', strtotime($month . '-01')) . ' goes as scheduled'); ?></div>
        <div class="v"><span><?php echo esc_html($currency); ?></span><?php echo esc_html($money($p['net'])); ?></div>
        <?php if ($final): ?><span class="ews-pay-pill final">✓ Final · closed <?php echo esc_html(date('j M', strtotime($closed_at))); ?></span>
        <?php else: ?><span class="ews-pay-pill est">◷ Estimate · changes until HR closes the month</span><?php endif; ?>
    </div>
    <?php if ($preparing !== ''): ?><div class="ews-pay-note">Your payslip for <?php echo esc_html($preparing); ?> is being prepared.</div><?php endif; ?>
    <div class="ews-pay-stats">
        <div><b><?php echo (int) $p['stats']['worked_days']; ?>/<?php echo (int) $p['stats']['expected_days']; ?></b>Days worked</div>
        <div><b><?php echo (int) $p['stats']['late_days']; ?></b>Late days</div>
        <div><b><?php echo (int) $p['stats']['absent_days']; ?></b>Absent</div>
        <div><b><?php echo esc_html(PayCalculator::hm((int) $p['overtime']['minutes'])); ?></b>Overtime</div>
    </div>
    <?php foreach ($p['review'] as $r): ?>
        <div class="ews-pay-warn">⚠ <b><?php echo esc_html($day($r['date'])); ?>:</b> no Sign Out recorded. HR is reviewing this day; until then it counts as a full day.</div>
    <?php endforeach; ?>

    <div class="ews-pay-card"><h3>Earnings</h3>
        <?php echo $line('Basic salary', '', $money($p['basic'])); ?>
        <?php if ($p['allowances']): echo $line('Allowances', implode(' · ', array_map(static function ($a) use ($money) { return $a['name'] . ' ' . $money($a['amount']); }, $p['allowances'])), $money($p['allowances_total'])); endif; ?>
        <?php if ($p['prorated']): echo $line('Paid from ' . esc_html($day($p['prorated']['from'])), $p['prorated']['days'] . ' days', $money($p['earned'])); endif; ?>
        <?php if ($p['overtime']['days']): ?>
            <details><summary><?php echo $line('Overtime (approved) <span class="ews-pay-chev">▾</span>', PayCalculator::hm((int) $p['overtime']['minutes']) . ' worked', '+ ' . $money($p['overtime']['amount']), 'plus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · ' . PayCalculator::hm((int) $d['minutes']) . ($d['off'] ? ' (day off)' : ''), '× ' . rtrim(rtrim(number_format((float) $d['rate'], 2, '.', ''), '0'), '.') . ' · +' . PayRules::money((float) $d['amount'])]; }, $p['overtime']['days'])); ?></details>
        <?php endif; ?>
        <?php foreach ($p['bonuses']['items'] ?? [] as $a): echo $line('Bonus', $a['reason'], '+ ' . $money($a['amount']), 'plus'); endforeach; ?>
    </div>

    <?php
    $has_deductions = $p['absence']['days'] || $p['late']['days'] || $p['early']['days'] || $p['leave']['types'] || !empty($p['manual']['items']);
    ?>
    <div class="ews-pay-card"><h3>Deductions</h3>
        <?php if (!$has_deductions): ?><div class="ews-pay-ln"><div>None this month</div><div class="ews-pay-amt">—</div></div><?php endif; ?>
        <?php if ($p['absence']['days']): ?>
            <details><summary><?php echo $line('Absent without leave <span class="ews-pay-chev">▾</span>', count($p['absence']['days']) . ' day(s)', '− ' . $money($p['absence']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']), '−' . PayRules::money((float) $d['amount'])]; }, $p['absence']['days'])); ?></details>
        <?php endif; ?>
        <?php if ($p['late']['days']): ?>
            <details><summary><?php echo $line('Late arrival <span class="ews-pay-chev">▾</span>', $p['late']['minutes'] . ' min on ' . count($p['late']['days']) . ' day(s), after the grace period', '− ' . $money($p['late']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · in ' . $d['sign_in'], $d['minutes'] . ' min · −' . PayRules::money((float) $d['amount'])]; }, $p['late']['days'])); ?></details>
        <?php endif; ?>
        <?php if ($p['early']['days']): ?>
            <details><summary><?php echo $line('Early leave without approval <span class="ews-pay-chev">▾</span>', $p['early']['minutes'] . ' min', '− ' . $money($p['early']['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d['date']) . ' · out ' . $d['sign_out'], $d['minutes'] . ' min · −' . PayRules::money((float) $d['amount'])]; }, $p['early']['days'])); ?></details>
        <?php endif; ?>
        <?php foreach ($p['leave']['types'] as $type => $t): ?>
            <details><summary><?php echo $line(esc_html($type) . ' <span class="ews-pay-chev">▾</span>', $t['days'] . ' day(s), ' . $t['paid'] . '% paid', '− ' . $money($t['amount']), 'minus'); ?></summary>
            <?php echo $days_list(array_map(static function ($d) use ($day) { return [$day($d), '']; }, $t['dates'])); ?></details>
        <?php endforeach; ?>
        <?php foreach ($p['manual']['items'] ?? [] as $a): echo $line('Deduction', $a['reason'], '− ' . $money($a['amount']), 'minus'); endforeach; ?>
        <?php if ($p['cap'] !== null): echo $line('Limited to ' . esc_html((string) $rules['max_deduction_days']) . ' day(s)\' pay', 'attendance deductions before the limit: ' . $money($p['deductions_before_cap']), '− ' . $money($p['cap']), 'minus'); endif; ?>
    </div>

    <div class="ews-pay-card"><div class="ews-pay-ln total"><div>Net pay</div><div class="ews-pay-amt"><?php echo esc_html($currency . ' ' . $money($p['net'])); ?></div></div></div>
    <p class="ews-pay-foot">A day's pay = <?php echo esc_html(($rules['day_base'] ?? 'gross') === 'basic' ? 'basic' : '(basic + allowances)'); ?> ÷ <?php echo (int) ($rules['day_divisor'] ?? 30); ?> = <?php echo esc_html($money($p['day_value'])); ?>. Amounts are before income tax and social insurance. A question about this month? Contact HR.</p>
<?php endif; ?>
</div>
