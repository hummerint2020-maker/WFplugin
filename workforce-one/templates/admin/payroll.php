<?php
/**
 * wp-admin → Payroll: Monthly Payroll (list, or one employee's breakdown), Salaries and Rules.
 * Styles: assets/css/admin-payroll.css. Confirmations: assets/js/admin-organization.js (data-ews-confirm).
 *
 * @var string $tab        month | salaries | rules
 * @var string $month      'Y-m'
 * @var array<string,mixed> $rules
 * @var string $page_url
 * @var string $notice     rate | deleted | rules | ''
 * @var string $error      message ('' = none)
 * @var array{employee:object,pay:?array<string,mixed>}|null $detail
 * @var array<int,array{employee:object,pay:?array<string,mixed>}> $people
 * @var array<int,array{employee:object,current:?array<string,mixed>,upcoming:array<int,array<string,mixed>>,history:array<int,array<string,mixed>>}> $salaries
 * @var string $export_url (month tab)
 * @var string $default_from (salaries tab)
 * @var int $selected      (salaries tab) employee to preselect
 * @var int $grace         grace period in minutes (Schedule Configuration)
 */
if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\PayCalculator;
use WorkforceOne\Payroll\PayRules;

$money = static function ($v) { return PayRules::money((float) $v); };
$minus = static function ($v) use ($money) { return (float) $v > 0 ? '−' . $money($v) : '—'; };
$plus = static function ($v) use ($money) { return (float) $v > 0 ? '+' . $money($v) : '—'; };
$day = static function ($d) { return date('D j M', strtotime($d)); };
$cur = (string) $rules['currency'];
$month_label = date('F Y', strtotime($month . '-01'));
$tabs = ['month' => 'Monthly Payroll', 'salaries' => 'Salaries', 'rules' => 'Rules'];
$num = static function ($v) { return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.'); };
?>
<div class="wrap ews-payroll"><h1>Payroll</h1>
<nav class="nav-tab-wrapper">
<?php foreach ($tabs as $key => $label): ?>
    <a class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url(add_query_arg('tab', $key, $page_url)); ?>"><?php echo esc_html($label); ?></a>
<?php endforeach; ?>
</nav>
<?php if ($notice === 'rate'): ?><div class="notice notice-success is-dismissible"><p>Salary saved.</p></div><?php endif; ?>
<?php if ($notice === 'deleted'): ?><div class="notice notice-success is-dismissible"><p>Salary entry removed.</p></div><?php endif; ?>
<?php if ($notice === 'rules'): ?><div class="notice notice-success is-dismissible"><p>Payroll rules saved. They apply to every month shown from now on.</p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<?php if ($tab === 'month' && $detail): $e = $detail['employee']; $p = $detail['pay']; ?>
    <p><a href="<?php echo esc_url(add_query_arg(['month' => $month], $page_url)); ?>">← All employees, <?php echo esc_html($month_label); ?></a></p>
    <h2><?php echo esc_html($e->name); ?> · <?php echo esc_html($month_label); ?></h2>
    <?php if (!$p): ?>
        <p>No salary set. <a href="<?php echo esc_url(add_query_arg(['tab' => 'salaries', 'employee' => (int) $e->id], $page_url)); ?>">Set a salary</a></p>
    <?php else: ?>
    <div class="ews-pay-hero"><span>Net pay · before tax and social insurance</span><strong><?php echo esc_html($cur . ' ' . $money($p['net'])); ?></strong>
        <em>Estimate from attendance. Nothing is closed or paid from this page yet.</em></div>
    <div class="ews-pay-stats">
        <div><b><?php echo (int) $p['stats']['worked_days']; ?>/<?php echo (int) $p['stats']['expected_days']; ?></b>Planned days worked</div>
        <div><b><?php echo (int) $p['stats']['late_days']; ?></b>Late days</div>
        <div><b><?php echo (int) $p['stats']['absent_days']; ?></b>Absent</div>
        <div><b><?php echo esc_html(PayCalculator::hm((int) $p['overtime']['minutes'])); ?></b>Overtime</div>
    </div>
    <?php if ($p['review']): ?>
        <div class="notice notice-warning inline ews-pay-review"><p><strong>To review before paying:</strong>
        <?php foreach ($p['review'] as $r): ?><br><?php echo esc_html($day($r['date']) . ': ' . $r['reason'] . ' (counted as a full day)'); ?><?php endforeach; ?></p></div>
    <?php endif; ?>
    <div class="ews-pay-cols">
    <table class="widefat ews-pay-lines"><thead><tr><th colspan="2">Earnings</th></tr></thead><tbody>
        <tr><td>Basic salary</td><td class="num"><?php echo esc_html($money($p['basic'])); ?></td></tr>
        <?php foreach ($p['allowances'] as $a): ?><tr><td><?php echo esc_html($a['name']); ?> <span class="ews-pay-sub">allowance</span></td><td class="num"><?php echo esc_html($money($a['amount'])); ?></td></tr><?php endforeach; ?>
        <?php if ($p['prorated']): ?><tr><td>Paid from <?php echo esc_html($day($p['prorated']['from'])); ?> <span class="ews-pay-sub"><?php echo (int) $p['prorated']['days']; ?> days × <?php echo esc_html($money($p['day_value'])); ?></span></td><td class="num"><?php echo esc_html($money($p['earned'])); ?></td></tr><?php endif; ?>
        <tr><td>Overtime (approved and worked) <span class="ews-pay-sub"><?php echo esc_html(PayCalculator::hm((int) $p['overtime']['minutes'])); ?></span></td><td class="num plus"><?php echo esc_html($plus($p['overtime']['amount'])); ?></td></tr>
        <?php foreach ($p['overtime']['days'] as $d): ?><tr class="ews-pay-day"><td><?php echo esc_html($day($d['date']) . ' · ' . PayCalculator::hm((int) $d['minutes']) . ' × ' . $num($d['rate']) . ($d['off'] ? ' (day off)' : '')); ?></td><td class="num"><?php echo esc_html($plus($d['amount'])); ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <table class="widefat ews-pay-lines"><thead><tr><th colspan="2">Deductions</th></tr></thead><tbody>
        <tr><td>Absent <span class="ews-pay-sub"><?php echo count($p['absence']['days']); ?> day(s) × <?php echo esc_html($num($rules['absence_days'])); ?> day's pay</span></td><td class="num minus"><?php echo esc_html($minus($p['absence']['amount'])); ?></td></tr>
        <?php foreach ($p['absence']['days'] as $d): ?><tr class="ews-pay-day"><td><?php echo esc_html($day($d['date'])); ?></td><td class="num"><?php echo esc_html($minus($d['amount'])); ?></td></tr><?php endforeach; ?>
        <tr><td>Late arrival <span class="ews-pay-sub"><?php echo (int) $p['late']['minutes']; ?> min, counted from the shift start once past the grace</span></td><td class="num minus"><?php echo esc_html($minus($p['late']['amount'])); ?></td></tr>
        <?php foreach ($p['late']['days'] as $d): ?><tr class="ews-pay-day"><td><?php echo esc_html($day($d['date']) . ' · in ' . $d['sign_in'] . ' · ' . $d['minutes'] . ' min'); ?></td><td class="num"><?php echo esc_html($minus($d['amount'])); ?></td></tr><?php endforeach; ?>
        <tr><td>Early leave without approval <span class="ews-pay-sub"><?php echo (int) $p['early']['minutes']; ?> min</span></td><td class="num minus"><?php echo esc_html($minus($p['early']['amount'])); ?></td></tr>
        <?php foreach ($p['early']['days'] as $d): ?><tr class="ews-pay-day"><td><?php echo esc_html($day($d['date']) . ' · out ' . $d['sign_out'] . ' · ' . $d['minutes'] . ' min'); ?></td><td class="num"><?php echo esc_html($minus($d['amount'])); ?></td></tr><?php endforeach; ?>
        <?php foreach ($p['leave']['types'] as $type => $t): ?>
            <tr><td><?php echo esc_html($type); ?> <span class="ews-pay-sub"><?php echo (int) $t['days']; ?> day(s), <?php echo (int) $t['paid']; ?>% paid</span></td><td class="num minus"><?php echo esc_html($minus($t['amount'])); ?></td></tr>
            <tr class="ews-pay-day"><td colspan="2"><?php echo esc_html(implode(', ', array_map($day, $t['dates']))); ?></td></tr>
        <?php endforeach; ?>
        <?php if ($p['cap'] !== null): ?><tr><td>Limited to <?php echo esc_html($num($rules['max_deduction_days'])); ?> day(s)' pay <span class="ews-pay-sub">deductions before the limit: <?php echo esc_html($money($p['deductions_before_cap'])); ?></span></td><td class="num minus"><?php echo esc_html($minus($p['cap'])); ?></td></tr><?php endif; ?>
    </tbody></table>
    </div>
    <table class="widefat ews-pay-total"><tr><td>Net pay</td><td class="num"><?php echo esc_html($cur . ' ' . $money($p['net'])); ?></td></tr></table>
    <p class="description">A day's pay = <?php echo esc_html($rules['day_base'] === 'basic' ? 'basic' : '(basic + allowances)'); ?> ÷ <?php echo (int) $rules['day_divisor']; ?> = <?php echo esc_html($money($p['day_value'])); ?> · an hour = <?php echo esc_html($money($p['minute_value'] * 60)); ?> (working day of <?php echo esc_html(PayCalculator::hm((int) $p['day_minutes'])); ?>). Amounts are before income tax and social insurance.</p>
    <?php endif; ?>

<?php elseif ($tab === 'month'): $total = 0.0; $paid = 0; ?>
    <form method="get" class="ews-pay-month"><input type="hidden" name="page" value="ews31-payroll">
        <label>Month <input type="month" name="month" value="<?php echo esc_attr($month); ?>"></label> <button class="button">Show</button>
        <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Export to Excel</a>
    </form>
    <p class="description">Worked out from attendance with the <a href="<?php echo esc_url(add_query_arg('tab', 'rules', $page_url)); ?>">payroll rules</a>. Amounts are before income tax and social insurance. Open an employee to see the days behind each figure.</p>
    <table class="widefat striped ews-pay-table">
        <thead><tr><th>Employee</th><th class="num">Monthly pay</th><th class="num">Overtime</th><th class="num">Deductions</th><th class="num">Net pay (<?php echo esc_html($cur); ?>)</th><th>Check</th></tr></thead>
        <tbody>
        <?php foreach ($people as $x): $e = $x['employee']; $p = $x['pay']; $link = add_query_arg(['month' => $month, 'employee' => (int) $e->id], $page_url); ?>
            <tr data-employee="<?php echo (int) $e->id; ?>">
                <td><a href="<?php echo esc_url($link); ?>"><strong><?php echo esc_html($e->name); ?></strong></a></td>
                <?php if (!$p): ?>
                    <td colspan="4" class="ews-pay-none">No salary set · <a href="<?php echo esc_url(add_query_arg(['tab' => 'salaries', 'employee' => (int) $e->id], $page_url)); ?>">Set salary</a></td><td></td>
                <?php else: $total += $p['net']; $paid++; ?>
                    <td class="num"><?php echo esc_html($money($p['earned'])); ?><?php if ($p['prorated']): ?><br><span class="ews-pay-sub">from <?php echo esc_html($day($p['prorated']['from'])); ?></span><?php endif; ?></td>
                    <td class="num plus"><?php echo esc_html($plus($p['overtime']['amount'])); ?></td>
                    <td class="num minus"><?php echo esc_html($minus($p['deductions'])); ?></td>
                    <td class="num"><strong><?php echo esc_html($money($p['net'])); ?></strong></td>
                    <td><?php if ($p['review']): ?><span class="ews-pay-flag"><?php echo count($p['review']); ?> to review</span><?php endif; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr><th>Total · <?php echo (int) $paid; ?> employees</th><th></th><th></th><th></th><th class="num"><?php echo esc_html($money($total)); ?></th><th></th></tr></tfoot>
    </table>

<?php elseif ($tab === 'salaries'): ?>
    <h2>Set a salary</h2>
    <p class="description">A new salary applies from its start date; earlier months keep the salary they had. The salary in effect on a month's last day covers the whole month, and an employee's first salary is paid from its start date. Amounts are monthly, in <?php echo esc_html($cur); ?>.</p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-pay-form">
        <input type="hidden" name="action" value="ews_payroll_rate_save"><?php wp_nonce_field('ews_payroll_rate_save'); ?>
        <p><label>Employee<br><select name="employee_id" required><option value="">Choose…</option>
            <?php foreach ($salaries as $s): ?><option value="<?php echo (int) $s['employee']->id; ?>" <?php selected($selected, (int) $s['employee']->id); ?>><?php echo esc_html($s['employee']->name); ?></option><?php endforeach; ?>
        </select></label>
        <label>Starts on<br><input type="date" name="effective_from" value="<?php echo esc_attr($default_from); ?>" required></label>
        <label>Basic salary<br><input type="number" name="basic" min="0" step="0.01" required></label></p>
        <p><strong>Allowances</strong> <span class="description">(e.g. Transport, Meals; leave empty rows blank)</span></p>
        <?php for ($i = 0; $i < 4; $i++): ?>
            <p class="ews-pay-allowance"><input type="text" name="allowance_name[]" placeholder="Name"> <input type="number" name="allowance_amount[]" min="0" step="0.01" placeholder="Amount"></p>
        <?php endfor; ?>
        <p><label>Note (optional)<br><input type="text" name="note" maxlength="190" class="regular-text" placeholder="e.g. Annual raise"></label></p>
        <p><button class="button button-primary">Save salary</button></p>
    </form>
    <h2>Salaries</h2>
    <table class="widefat striped ews-pay-table">
        <thead><tr><th>Employee</th><th class="num">Basic</th><th class="num">Allowances</th><th class="num">Monthly</th><th>Since</th><th>History</th></tr></thead>
        <tbody>
        <?php foreach ($salaries as $s): $c = $s['current']; ?>
            <tr><td><strong><?php echo esc_html($s['employee']->name); ?></strong></td>
            <?php if ($c): $allow = array_sum(array_map(static function ($a) { return (float) $a['amount']; }, $c['allowances'])); ?>
                <td class="num"><?php echo esc_html($money($c['basic'])); ?></td><td class="num"><?php echo esc_html($money($allow)); ?></td><td class="num"><strong><?php echo esc_html($money($c['basic'] + $allow)); ?></strong></td><td><?php echo esc_html($c['effective_from']); ?></td>
            <?php else: ?>
                <td colspan="4" class="ews-pay-none">No salary<?php echo $s['upcoming'] ? esc_html(' yet (one starts ' . end($s['upcoming'])['effective_from'] . ')') : ''; ?></td>
            <?php endif; ?>
            <td>
                <?php if ($s['history']): ?><details><summary><?php echo count($s['history']); ?> <?php echo count($s['history']) === 1 ? 'entry' : 'entries'; ?></summary><ul>
                <?php foreach ($s['history'] as $h): $allow = array_sum(array_map(static function ($a) { return (float) $a['amount']; }, $h['allowances'])); ?>
                    <li><?php echo esc_html($h['effective_from'] . ': ' . $money($h['basic']) . ' + ' . $money($allow) . ($h['note'] !== '' ? ' · ' . $h['note'] : '')); ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-pay-inline" data-ews-confirm="Remove this salary entry? Months it covered will use the one before it.">
                            <input type="hidden" name="action" value="ews_payroll_rate_delete"><input type="hidden" name="rate_id" value="<?php echo (int) $h['id']; ?>"><?php wp_nonce_field('ews_payroll_rate_delete'); ?>
                            <button class="button-link-delete">Remove</button></form></li>
                <?php endforeach; ?></ul></details><?php else: ?>—<?php endif; ?>
            </td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>

<?php else: ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-pay-form ews-pay-rules">
        <input type="hidden" name="action" value="ews_payroll_rules_save"><?php wp_nonce_field('ews_payroll_rules_save'); ?>
        <table class="form-table" role="presentation">
            <tr><th>Currency</th><td><input type="text" name="currency" value="<?php echo esc_attr($cur); ?>" maxlength="3" size="4"> <span class="description">3-letter code, e.g. EGP</span></td></tr>
            <tr><th>A day's pay</th><td>
                <select name="day_base"><option value="gross" <?php selected($rules['day_base'], 'gross'); ?>>Basic + allowances</option><option value="basic" <?php selected($rules['day_base'], 'basic'); ?>>Basic only</option></select>
                ÷ <input type="number" name="day_divisor" min="1" max="31" step="1" value="<?php echo (int) $rules['day_divisor']; ?>" style="width:70px"> days
                <p class="description">An hour's pay = a day's pay ÷ the hours of the employee's working day (shift less the allowed break).</p></td></tr>
            <tr><th>Absent without leave</th><td>deducts <input type="number" name="absence_days" min="0" max="5" step="0.25" value="<?php echo esc_attr($num($rules['absence_days'])); ?>" style="width:80px"> day(s)' pay per day</td></tr>
            <tr><th>Late arrival</th><td>Each minute from the shift start, once past the grace period (<?php echo (int) $grace; ?> min, set on Schedule Configuration). Within the grace: no deduction.</td></tr>
            <tr><th>Early leave</th><td>Each minute before the shift end, unless covered by an approved Early Leave request.</td></tr>
            <tr><th>Leave</th><td>Each leave type's "Paid %" (wp-admin → Leaves). 100% = paid, 0% = a full day deducted.</td></tr>
            <tr><th>Overtime</th><td>Approved and actually worked, × <input type="number" name="overtime_rate" min="1" max="5" step="0.05" value="<?php echo esc_attr($num($rules['overtime_rate'])); ?>" style="width:80px"> an hour's pay on work days,
                × <input type="number" name="overtime_rate_off" min="1" max="5" step="0.05" value="<?php echo esc_attr($num($rules['overtime_rate_off'])); ?>" style="width:80px"> on days off and company holidays.</td></tr>
            <tr><th>Limit on deductions</th><td>at most <input type="number" name="max_deduction_days" min="0" max="31" step="0.5" value="<?php echo esc_attr($num($rules['max_deduction_days'])); ?>" style="width:80px"> days' pay a month <span class="description">(0 = no limit)</span></td></tr>
        </table>
        <p class="description">Rates and limits must follow the labour law and your company policy; check them with HR or your accountant.</p>
        <p><button class="button button-primary">Save rules</button></p>
    </form>
<?php endif; ?>
</div>
