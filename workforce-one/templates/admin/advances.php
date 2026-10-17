<?php
/**
 * wp-admin → Pay → Advances (3.31.109, mockup docs/mockups/new-types/): approved advances waiting to be
 * paid (record the day and how), the ones being paid back with their instalments (one can move to
 * after the last), and the ones still waiting for a decision. Data: EWS_Advances_Trait::admin_advances().
 *
 * @var bool $on
 * @var list<array<string,mixed>> $rows   EWS_Advances_Trait::advance_rows() + name, method, note
 * @var array{type:string,text:string}|null $notice
 * @var string $post_url
 * @var string $today
 * @var callable $money
 * @var string $features_url
 * @var array<string,mixed> $settings
 */
if (!defined('ABSPATH')) exit;
$waiting = array_values(array_filter($rows, static function ($r) { return $r['state'] === 'approved'; }));
$paying = array_values(array_filter($rows, static function ($r) { return $r['state'] === 'paying'; }));
$deciding = array_values(array_filter($rows, static function ($r) { return $r['state'] === 'waiting'; }));
$box = 'margin-top:10px;background:#fff;border:1px solid #dcdcde;max-width:1100px;overflow-x:auto';
?>
<div class="wrap"><h1><?php esc_html_e('Salary advances', 'workforce-one'); ?></h1>
<?php if ($notice): ?><div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div><?php endif; ?>
<?php if (!$on): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('Salary advances are off.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Turn them on in Features', 'workforce-one'); ?></a></p></div>
<?php else: ?>
    <h2><?php esc_html_e('Approved, waiting to be paid', 'workforce-one'); ?></h2>
    <div style="<?php echo esc_attr($box); ?>"><table class="widefat striped" data-advances-waiting>
        <thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th><th><?php esc_html_e('Instalments', 'workforce-one'); ?></th><th><?php esc_html_e('Reason', 'workforce-one'); ?></th><th><?php esc_html_e('Record as paid', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$waiting): ?><tr><td colspan="5"><?php esc_html_e('Nothing waits to be paid.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($waiting as $r): ?>
            <tr data-advance="<?php echo (int) $r['id']; ?>"><td><strong><?php echo esc_html($r['name']); ?></strong></td><td><?php echo esc_html($money($r['amount'])); ?></td><td><?php echo (int) $r['instalments']; ?></td><td><?php echo esc_html($r['reason']); ?></td>
                <td><form method="post" action="<?php echo esc_url($post_url); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center"><?php wp_nonce_field('ews_advance_paid_' . (int) $r['id']); ?><input type="hidden" name="action" value="ews_advance_paid"><input type="hidden" name="advance_id" value="<?php echo (int) $r['id']; ?>">
                    <input type="date" name="paid_on" value="<?php echo esc_attr($today); ?>" max="<?php echo esc_attr($today); ?>" required aria-label="<?php esc_attr_e('Paid on', 'workforce-one'); ?>">
                    <select name="paid_method" aria-label="<?php esc_attr_e('How', 'workforce-one'); ?>"><option value="cash"><?php esc_html_e('Cash', 'workforce-one'); ?></option><option value="transfer"><?php esc_html_e('Bank transfer', 'workforce-one'); ?></option></select>
                    <input type="text" name="paid_note" maxlength="190" placeholder="<?php esc_attr_e('Note (optional)', 'workforce-one'); ?>">
                    <button class="button button-primary" type="submit"><?php esc_html_e('Paid', 'workforce-one'); ?></button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <h2 style="margin-top:24px"><?php esc_html_e('Being paid back', 'workforce-one'); ?></h2>
    <div style="<?php echo esc_attr($box); ?>"><table class="widefat striped" data-advances-paying>
        <thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th><th><?php esc_html_e('Paid', 'workforce-one'); ?></th><th><?php esc_html_e('Left', 'workforce-one'); ?></th><th><?php esc_html_e('Instalments', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$paying): ?><tr><td colspan="5"><?php esc_html_e('No advance is being paid back.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($paying as $r): ?>
            <tr data-advance="<?php echo (int) $r['id']; ?>"><td><strong><?php echo esc_html($r['name']); ?></strong></td><td><?php echo esc_html($money($r['amount'])); ?></td>
                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($r['paid_on'])) . ' · ' . ($r['method'] === 'transfer' ? __('Bank transfer', 'workforce-one') : __('Cash', 'workforce-one'))); ?></td>
                <td><?php echo esc_html($money($r['left'])); ?></td>
                <td><?php echo esc_html(sprintf(/* translators: 1: taken, 2: instalments */ __('%1$d of %2$d taken', 'workforce-one'), $r['taken'], $r['count'])); ?><br>
                <?php foreach ($r['due'] as $i => $ins): ?><span style="display:inline-flex;gap:4px;align-items:center;margin:2px 8px 2px 0"><?php echo esc_html($ins->month . ': ' . $money($ins->amount) . ((int) $ins->moved ? ' (' . __('moved', 'workforce-one') . ')' : '')); ?>
                    <?php if ($i === 0): ?><form method="post" action="<?php echo esc_url($post_url); ?>" style="display:inline"><?php wp_nonce_field('ews_advance_move_' . (int) $ins->id); ?><input type="hidden" name="action" value="ews_advance_move"><input type="hidden" name="instalment_id" value="<?php echo (int) $ins->id; ?>"><button class="button-link" type="submit"><?php esc_html_e('Move to the end', 'workforce-one'); ?></button></form><?php endif; ?></span><?php endforeach; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>

    <h2 style="margin-top:24px"><?php esc_html_e('Waiting for a decision', 'workforce-one'); ?></h2>
    <div style="<?php echo esc_attr($box); ?>"><table class="widefat striped" data-advances-deciding>
        <thead><tr><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th><th><?php esc_html_e('Instalments', 'workforce-one'); ?></th><th><?php esc_html_e('Asked', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$deciding): ?><tr><td colspan="4"><?php esc_html_e('None.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($deciding as $r): ?><tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($money($r['amount'])); ?></td><td><?php echo (int) $r['instalments']; ?></td><td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($r['requested_at']))); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <p style="color:#646970;max-width:900px"><?php esc_html_e('They are decided in My approvals, like every request (who decides: Approval Workflows → Salary Advance). An instalment is taken once its month is closed in Payroll.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Limits', 'workforce-one'); ?></a></p>
<?php endif; ?>
</div>
