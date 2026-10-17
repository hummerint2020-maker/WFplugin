<?php
/**
 * wp-admin → Approvals → Substitutes (3.31.107, mockup docs/mockups/substitutes/, screen 4): who
 * decides for whom and when, set by the manager (with a leave or "I'll be away") or here by HR; end
 * an active one now, cancel one not started. Data: EWS_Substitutes_Trait::admin_substitutes().
 *
 * @var bool $on
 * @var object[] $rows   ews_approval_substitutes rows (+ manager, substitute, from, to)
 * @var object[] $people active employees with a WordPress user (id, name)
 * @var array{type:string,text:string}|null $notice
 * @var string $post_url
 * @var string $features_url
 * @var array{manager_decides:int,on_return:string,who:string,ask_on_leave:string} $settings
 */
if (!defined('ABSPATH')) exit;
$status = ['pending' => __('Waits for the leave approval', 'workforce-one'), 'scheduled' => __('Scheduled', 'workforce-one'), 'active' => __('Active now', 'workforce-one'), 'ended' => __('Ended', 'workforce-one'), 'cancelled' => __('Cancelled', 'workforce-one')];
$colors = ['pending' => '#8a4f00', 'scheduled' => '#8a4f00', 'active' => '#008a20', 'ended' => '#646970', 'cancelled' => '#646970'];
$source = ['leave' => __('The manager, with a leave request', 'workforce-one'), 'self' => __('The manager ("I\'ll be away")', 'workforce-one'), 'hr' => __('HR', 'workforce-one')];
$now = substr(str_replace(' ', 'T', current_time('mysql')), 0, 16);
?>
<div class="wrap"><h1><?php esc_html_e('Substitutes', 'workforce-one'); ?></h1>
<?php if ($notice): ?><div class="notice <?php echo $notice['type'] === 'ok' ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div><?php endif; ?>
<p style="max-width:900px;color:#50575e"><?php esc_html_e('While a manager is away, the requests that would wait for them wait for their substitute, marked "for" them. One set with a leave request follows that leave: it starts when the leave is approved and stops if it is cancelled.', 'workforce-one'); ?></p>

<?php if (!$on): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('Substitutes are off.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Turn them on in Features', 'workforce-one'); ?></a></p></div>
<?php else: ?>
    <div style="margin-top:14px;background:#fff;border:1px solid #dcdcde;max-width:1100px;overflow-x:auto"><table class="widefat striped" data-substitutes>
        <thead><tr><th><?php esc_html_e('Manager', 'workforce-one'); ?></th><th><?php esc_html_e('Substitute', 'workforce-one'); ?></th><th><?php esc_html_e('From', 'workforce-one'); ?></th><th><?php esc_html_e('Until', 'workforce-one'); ?></th><th><?php esc_html_e('Set by', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7"><?php esc_html_e('No substitutes yet.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $open = in_array($r->status, ['pending', 'scheduled', 'active'], true); ?>
            <tr data-substitute="<?php echo (int) $r->id; ?>">
                <td><strong><?php echo esc_html($r->manager); ?></strong></td>
                <td><?php echo esc_html($r->substitute); ?></td>
                <td><?php echo esc_html($r->from); ?></td>
                <td><?php echo esc_html($r->to); ?></td>
                <td><?php echo esc_html($source[$r->source] ?? $r->source); ?><?php if ((string) $r->reason !== ''): ?><br><span style="color:#646970"><?php echo esc_html($r->reason); ?></span><?php endif; ?></td>
                <td><span style="color:<?php echo esc_attr($colors[$r->status] ?? '#646970'); ?>;font-weight:600"><?php echo esc_html($status[$r->status] ?? $r->status); ?></span><?php if ((int) $r->decided): ?><br><span style="color:#646970"><?php echo esc_html(sprintf(/* translators: %d: number of requests */ _n('Decided %d', 'Decided %d', (int) $r->decided, 'workforce-one'), (int) $r->decided)); ?></span><?php endif; ?></td>
                <td><?php if ($open): ?><form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_substitute_end_' . (int) $r->id); ?><input type="hidden" name="action" value="ews_substitute_end"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><button class="button" type="submit"><?php echo $r->status === 'active' ? esc_html__('End now', 'workforce-one') : esc_html__('Cancel', 'workforce-one'); ?></button></form><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <h2 style="margin-top:26px"><?php esc_html_e('Add a substitute', 'workforce-one'); ?></h2>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="background:#fff;border:1px solid #dcdcde;padding:16px;max-width:1100px;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end"><?php wp_nonce_field('ews_substitute_save'); ?><input type="hidden" name="action" value="ews_substitute_save">
        <label><?php esc_html_e('Manager', 'workforce-one'); ?><br><select name="manager_employee_id" required><option value=""></option><?php foreach ($people as $p): ?><option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option><?php endforeach; ?></select></label>
        <label><?php esc_html_e('Substitute', 'workforce-one'); ?><br><select name="substitute_employee_id" required><option value=""></option><?php foreach ($people as $p): ?><option value="<?php echo (int) $p->id; ?>"><?php echo esc_html($p->name); ?></option><?php endforeach; ?></select></label>
        <label><?php esc_html_e('From', 'workforce-one'); ?><br><input type="datetime-local" name="starts_at" value="<?php echo esc_attr($now); ?>" required></label>
        <label><?php esc_html_e('Until', 'workforce-one'); ?><br><input type="datetime-local" name="ends_at" required></label>
        <label><?php esc_html_e('Reason (optional)', 'workforce-one'); ?><br><input type="text" name="reason" maxlength="190"></label>
        <button class="button button-primary" type="submit"><?php esc_html_e('Save', 'workforce-one'); ?></button>
    </form>
    <p style="color:#646970;max-width:900px"><?php echo esc_html(sprintf(/* translators: 1: who can be a substitute, 2: what happens on return */ __('Who can be a substitute: %1$s. When the manager is back: %2$s.', 'workforce-one'),
        ['any' => __('any active employee', 'workforce-one'), 'managers' => __('managers only', 'workforce-one'), 'department' => __('someone in the same department', 'workforce-one')][$settings['who']],
        $settings['on_return'] === 'back' ? __('what still waits goes back to them', 'workforce-one') : __('it stays with the substitute', 'workforce-one'))); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Settings', 'workforce-one'); ?></a></p>
<?php endif; ?>
</div>
