<?php
/**
 * wp-admin "Leaves" → "Company holidays" (3.31.162, docs/tasks/leave-holidays.md; mockup docs/mockups/leave-holidays/).
 * Only with "Company holidays are not leave days" on.
 *
 * @var string $tab          'holidays'
 * @var bool   $return_tabs  Leave return is on: its two tabs too
 * @var int    $year         the leave year
 * @var array<int,array<string,mixed>> $rows  HolidayRefundService::report()
 * @var string $post_url
 * @var string $page_url
 * @var int|null $done_days  days given back by the last action (null: none)
 * @var int    $done_leaves
 * @var string $error        translated
 */
if (!defined('ABSPATH')) exit;
$num = static function ($n) { return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.'); };
$tab_link = static function (string $t, string $label) use ($tab, $page_url): string {
    return '<a class="nav-tab' . ($t === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url($t === '' ? $page_url : add_query_arg('tab', $t, $page_url)) . '">' . esc_html($label) . '</a>';
};
$open = array_values(array_filter($rows, static function ($r) { return $r['restorable']; }));
$total = array_sum(array_map(static function ($r) { return $r['back']; }, $rows));
?>
<div class="wrap"><h1><?php esc_html_e('Leave Management', 'workforce-one'); ?></h1>
<nav class="nav-tab-wrapper" style="margin-bottom:12px"><?php echo $tab_link('', __('Types and balances', 'workforce-one')) . ($return_tabs ? $tab_link('return', __('Back at work', 'workforce-one')) . $tab_link('changed', __('Changed leave days', 'workforce-one')) : '') . $tab_link('holidays', __('Company holidays', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- built escaped above ?></nav>
<?php if ($error !== ''): ?><div class="notice notice-warning"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<?php if ($done_days !== null && $done_days > 0): ?><div class="notice notice-success is-dismissible" data-lh-done><p><?php echo esc_html(sprintf(/* translators: 1: days, 2: leaves */ __('%1$d day(s) of %2$d leave(s) went back to the balance. The employees were told, and it is in the Audit Log.', 'workforce-one'), $done_days, $done_leaves)); ?></p></div><?php endif; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:1200px">
    <h2 style="margin-top:0"><?php echo esc_html(sprintf(/* translators: %d: the leave year */ __('Company holidays charged as leave (%d)', 'workforce-one'), $year)); ?></h2>
    <p style="color:#50575e;max-width:760px"><?php esc_html_e('Approved leaves charged for a company holiday inside them: asked before the switch was on, or the holiday was added after the leave was asked. Give the day back and it goes back to the employee\'s balance, and the holiday is no longer a leave day in the schedule. New leaves no longer count the company holidays. Leaves still waiting for a decision show here once they are approved.', 'workforce-one'); ?></p>
    <?php if (!$rows): ?><p data-lh-empty><?php esc_html_e('No leave of this year was charged for a company holiday.', 'workforce-one'); ?></p>
    <?php else: ?>
    <p><strong><?php echo esc_html(sprintf(/* translators: 1: leaves, 2: days */ __('%1$d leave(s), %2$d day(s) to give back', 'workforce-one'), count($rows), $total)); ?></strong></p>
    <form method="post" action="<?php echo esc_url($post_url); ?>" data-lh-form onsubmit="return window.confirm(<?php echo esc_attr(wp_json_encode(__('Give these days back to the balance?', 'workforce-one'), JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS | JSON_HEX_TAG)); ?>);">
        <?php wp_nonce_field('ews_leave_holiday_restore'); ?><input type="hidden" name="action" value="ews_leave_holiday_restore">
        <div style="overflow-x:auto">
        <table class="widefat striped" data-lh-table>
            <thead><tr>
                <td class="check-column"><?php if ($open): ?><input type="checkbox" onclick="var c=this.checked;this.closest('table').querySelectorAll('input[name=&quot;leave_ids[]&quot;]').forEach(function(x){x.checked=c;});" aria-label="<?php esc_attr_e('Select all', 'workforce-one'); ?>"><?php endif; ?></td>
                <th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Leave type', 'workforce-one'); ?></th><th><?php esc_html_e('Period', 'workforce-one'); ?></th>
                <th><?php esc_html_e('Days charged', 'workforce-one'); ?></th><th><?php esc_html_e('Charged', 'workforce-one'); ?></th><th><?php esc_html_e('Should be', 'workforce-one'); ?></th><th><?php esc_html_e('Goes back', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-lh-leave="<?php echo (int) $r['leave_id']; ?>">
                    <th scope="row" class="check-column"><?php if ($r['restorable']): ?><input type="checkbox" name="leave_ids[]" value="<?php echo (int) $r['leave_id']; ?>" aria-label="<?php esc_attr_e('Select', 'workforce-one'); ?>"><?php endif; ?></th>
                    <td><?php echo esc_html($r['employee']); ?></td>
                    <td><?php echo esc_html($r['type']); ?></td>
                    <td style="white-space:nowrap"><?php echo esc_html($r['start'] . ' – ' . $r['end']); ?></td>
                    <td><?php foreach ($r['days'] as $d): ?><span style="display:inline-block;margin:0 4px 4px 0;padding:0 6px;border-radius:4px;<?php echo $d['holiday'] !== null ? 'background:#fcf0f1;color:#b32d2e;font-weight:600' : 'border:1px solid #dcdcde'; ?>"<?php echo $d['holiday'] !== null ? ' data-lh-holiday' : ''; ?>><?php echo esc_html($d['day'] . ($d['holiday'] !== null ? ' ' . $d['holiday'] : '')); ?></span><?php endforeach; ?></td>
                    <td><?php echo esc_html($num($r['charged'])); ?></td>
                    <td><?php echo esc_html($num($r['should'])); ?></td>
                    <td><strong><?php echo (int) $r['back']; ?></strong></td>
                    <td><?php
                        if (!$r['restorable']) esc_html_e('A change is waiting', 'workforce-one');
                        elseif ($r['partly']) esc_html_e('Partly cancelled', 'workforce-one');
                        else esc_html_e('Approved', 'workforce-one'); ?></td>
                    <td><?php if ($r['restorable']): ?><button class="button button-small" name="one" value="<?php echo (int) $r['leave_id']; ?>" data-lh-one><?php esc_html_e('Give back', 'workforce-one'); ?></button>
                        <?php else: ?><em><?php esc_html_e('Decide the change first', 'workforce-one'); ?></em><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($open): ?><p><button class="button button-primary" data-lh-many><?php esc_html_e('Give back the selected', 'workforce-one'); ?></button></p><?php endif; ?>
    </form>
    <?php endif; ?>
</div>
</div>
