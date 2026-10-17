<?php
/**
 * wp-admin "Leaves" page.
 *
 * @var array<int,object> $types      ews_leave_types rows
 * @var array<int,object> $employees  active employees (id, name)
 * @var int $year                     current leave year
 * @var int[] $years                  years a balance can be set for
 * @var int $shown_year               year of the balances table
 * @var array<int,array{employee:string,type:string,entitlement:float,used:float,pending:float,remaining:float}> $balances
 * @var bool $saved
 * @var bool $recorded
 * @var string $record_error
 * @var string $post_url
 * @var string $page_url
 */
if (!defined('ABSPATH')) exit;
$num = static function ($n) { return rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.'); };
// Planned / unplanned and the year-end carry-over of one type (3.31.99, src/Leave/TypeRules.php).
$rules_fields = static function ($t) use ($num): string {
    $c = \WorkforceOne\Leave\TypeRules::config($t);
    $o = static function (string $v, string $cur, string $label): string { return '<option value="' . esc_attr($v) . '"' . selected($cur, $v, false) . '>' . esc_html($label) . '</option>'; };
    return '<label>Kind<br><select name="kind" data-leave-kind>' . $o('planned', $c['kind'], 'Planned: asked before the day') . $o('unplanned', $c['kind'], 'Unplanned: on the day or after') . '</select></label>'
        . '<label data-leave-after' . ($c['kind'] === 'unplanned' ? '' : ' hidden') . '>Up to … days after the day<br><input type="number" min="0" max="' . \WorkforceOne\Leave\TypeRules::MAX_AFTER_DAYS . '" step="1" name="after_days" value="' . (int) $c['after_days'] . '" style="width:90px" title="0 = only on the day itself"></label>'
        . '<label>Left at year end<br><select name="carry_mode" data-leave-carry>' . $o('none', $c['carry'], 'Not carried over') . $o('all', $c['carry'], 'All carried over') . $o('max', $c['carry'], 'Carried over up to…') . '</select></label>'
        . '<label data-leave-carry-max' . ($c['carry'] === 'max' ? '' : ' hidden') . '>Days at most<br><input type="number" min="0" step="0.5" name="carry_max" value="' . esc_attr($num($c['carry_max'])) . '" style="width:90px"></label>';
};
?>
<div class="wrap"><h1>Leave Management</h1>
<p>Configure Leave Types and annual employee balances. Balances renew every 1 January. <strong>Planned</strong> leave is asked before its day; <strong>unplanned</strong> leave (sick, emergency) can be asked on the day or up to the days you set after it, and uses its own balance. What is left at year end is not carried over unless you choose so for a type.</p>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Leave configuration saved.</p></div><?php endif; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:16px;max-width:1000px"><h2>Leave Types</h2>
<?php foreach ($types as $t): ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="border-top:1px solid #eee;padding:16px 0">
        <?php wp_nonce_field('ews_leave_type_save'); ?><input type="hidden" name="action" value="ews_leave_type_save"><input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <label>Name<br><input name="name" value="<?php echo esc_attr($t->name); ?>" required></label>
            <label>Annual entitlement<br><input type="number" min="0" step="0.5" name="annual_entitlement" value="<?php echo esc_attr($t->annual_entitlement); ?>" style="width:110px"></label>
            <label><input type="checkbox" name="deduct_balance" value="1" <?php checked((int) $t->deduct_balance, 1); ?>> Deduct balance</label>
            <label title="Share of a day's pay kept on this leave (Payroll). 100 = fully paid, 0 = unpaid.">Paid %<br><input type="number" min="0" max="100" step="1" name="paid_percent" value="<?php echo (int) ($t->paid_percent ?? 100); ?>" style="width:80px"></label>
            <?php echo $rules_fields($t); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?>
            <label><input type="checkbox" name="active" value="1" <?php checked((int) $t->active, 1); ?>> Active</label>
            <button class="button button-primary">Save</button>
        </div>
    </form>
<?php endforeach; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="border-top:1px solid #eee;padding-top:18px">
        <?php wp_nonce_field('ews_leave_type_save'); ?><input type="hidden" name="action" value="ews_leave_type_save"><input type="hidden" name="id" value="0">
        <strong>Add Leave Type</strong> <input name="name" placeholder="e.g. Sick Leave" required> <input type="number" min="0" step="0.5" name="annual_entitlement" value="0" style="width:110px">
        <label><input type="checkbox" name="deduct_balance" value="1" checked> Deduct balance</label>
        <label title="Share of a day's pay kept on this leave (Payroll). 100 = fully paid, 0 = unpaid.">Paid % <input type="number" min="0" max="100" step="1" name="paid_percent" value="100" style="width:70px"></label>
        <span style="display:inline-flex;gap:12px;flex-wrap:wrap;align-items:end;vertical-align:bottom"><?php echo $rules_fields(null); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?></span> <button class="button">Add</button>
    </form>
    <script>
    document.querySelectorAll('[data-leave-kind],[data-leave-carry]').forEach(function (sel) {
        function show() {
            var f = sel.form;
            if (sel.hasAttribute('data-leave-kind')) { var a = f.querySelector('[data-leave-after]'); if (a) a.hidden = sel.value !== 'unplanned'; }
            else { var m = f.querySelector('[data-leave-carry-max]'); if (m) m.hidden = sel.value !== 'max'; }
        }
        sel.addEventListener('change', show);
    });
    </script>
</div>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:18px;max-width:1000px">
    <h2>Record Leave for Employee</h2>
    <p style="margin-top:0;color:#50575e">Create an approved leave record directly from Admin. The date can be in the past, today, or in the future. The employee schedule will be marked as <strong>Vacation</strong> for configured working days, and the normal leave balance rules will be applied: the days are charged to the balance of the year the leave falls in.</p>
    <?php if ($recorded): ?><div class="notice notice-success inline"><p>Leave recorded successfully.</p></div><?php endif; ?>
    <?php if ($record_error !== ''): ?><div class="notice notice-error inline"><p><?php echo esc_html($record_error); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_admin_leave_record'); ?><input type="hidden" name="action" value="ews_admin_leave_record">
        <div style="display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:16px;max-width:760px">
            <label><strong>Employee</strong><br><select name="employee_id" required style="width:100%;margin-top:5px"><option value="">Select employee</option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endforeach; ?>
            </select></label>
            <label><strong>Leave Type</strong><br><select name="leave_type_id" required style="width:100%;margin-top:5px"><option value="">Select leave type</option>
                <?php foreach ($types as $t): if ((int) $t->active !== 1) continue; ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html($t->name . ((int) $t->deduct_balance ? ' — balance deducted' : ' — no balance deduction')); ?></option><?php endforeach; ?>
            </select></label>
            <label><strong>From Date</strong><br><input type="date" name="start_date" required style="width:100%;margin-top:5px"></label>
            <label><strong>To Date</strong><br><input type="date" name="end_date" required style="width:100%;margin-top:5px"></label>
            <label style="grid-column:1/-1"><strong>Reason / Note</strong><br><textarea name="reason" rows=3 style="width:100%;margin-top:5px" placeholder="Optional admin note"></textarea></label>
        </div>
        <p style="margin:14px 0 0"><button class="button button-primary">Record Approved Leave</button></p>
    </form>
</div>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:18px;max-width:1000px"><h2>Assign Annual Balance</h2>
    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_leave_balance_save'); ?><input type="hidden" name="action" value="ews_leave_balance_save">
        <label>Employee <select name="employee_id" required><option value="">Select</option>
            <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endforeach; ?>
        </select></label>
        <label>Leave Type <select name="leave_type_id" required><option value="">Select</option>
            <?php foreach ($types as $t): ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html($t->name); ?></option><?php endforeach; ?>
        </select></label>
        <label>Year <select name="leave_year">
            <?php foreach ($years as $y): ?><option value="<?php echo (int) $y; ?>" <?php selected($y, $year); ?>><?php echo (int) $y; ?></option><?php endforeach; ?>
        </select></label>
        <label>Entitlement <input type="number" min="0" step="0.5" name="entitlement" required></label> <button class="button button-primary">Save Balance</button>
    </form>
</div>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:18px;max-width:1000px">
    <h2 style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">Balances <?php echo (int) $shown_year; ?>
        <span style="font-size:13px;font-weight:400">
        <?php foreach ($years as $y): ?>
            <?php if ($y === $shown_year): ?><strong><?php echo (int) $y; ?></strong><?php else: ?><a href="<?php echo esc_url(add_query_arg('balance_year', $y, $page_url)); ?>"><?php echo (int) $y; ?></a><?php endif; ?>
        <?php endforeach; ?>
        </span>
    </h2>
    <?php if (!$balances): ?>
        <p style="color:#646970">No balances for <?php echo (int) $shown_year; ?> yet. A balance is created from the leave type's annual entitlement the first time an employee uses it.</p>
    <?php else: ?>
        <table class="widefat striped"><thead><tr><th>Employee</th><th>Leave Type</th><th>Entitlement</th><th>Used</th><th>Pending</th><th>Remaining</th></tr></thead><tbody>
        <?php foreach ($balances as $b): ?>
            <tr><td><?php echo esc_html($b['employee']); ?></td><td><?php echo esc_html($b['type']); ?></td><td><?php echo esc_html($num($b['entitlement'])); ?></td><td><?php echo esc_html($num($b['used'])); ?></td><td><?php echo esc_html($num($b['pending'])); ?></td>
                <td<?php echo $b['remaining'] < 0 ? ' style="color:#b32d2e;font-weight:600"' : ''; ?>><?php echo esc_html($num($b['remaining'])); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</div>
</div>
