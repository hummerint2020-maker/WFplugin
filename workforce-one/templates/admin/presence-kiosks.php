<?php
/**
 * wp-admin → Presence Kiosks: create a kiosk for a work location; open or disable configured kiosks.
 *
 * @var object[] $locations   active work locations (id, name)
 * @var object[] $rows        kiosks with location_name and display_url ('' when disabled)
 * @var string $notice        confirmation after a save / disable ('' = none)
 */
if (!defined('ABSPATH')) exit;
$post = admin_url('admin-post.php');
?>
<div class="wrap"><h1>Presence Kiosks</h1>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<div class="wfo-features-shell" style="max-width:1100px;margin-top:18px">
    <div class="wfo-feature-section"><h2 style="margin-top:0">Create Kiosk</h2><p style="color:#667085">A Kiosk only displays the rotating QR code. It never contains employee credentials.</p>
        <form method="post" action="<?php echo esc_url($post); ?>">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_presence_kiosk_save')); ?>"><input type="hidden" name="action" value="ews_presence_kiosk_save">
            <table class="form-table">
                <tr><th>Kiosk Name</th><td><input class="regular-text" name="name" required placeholder="Cairo HQ Reception"></td></tr>
                <tr><th>Work Location</th><td><select name="location_id" required><option value="">Select location</option><?php foreach ($locations as $l): ?><option value="<?php echo (int) $l->id; ?>"><?php echo esc_html($l->name); ?></option><?php endforeach; ?></select></td></tr>
            </table>
            <p><button class="button button-primary">Create Kiosk</button></p>
        </form>
    </div>
    <div class="wfo-feature-section"><h2 style="margin-top:0">Configured Kiosks</h2>
        <table class="widefat striped"><thead><tr><th>Name</th><th>Location</th><th>Status</th><th>Last Seen</th><th>Display</th><th>Action</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="6">No kiosks configured.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr><td><strong><?php echo esc_html($r->name); ?></strong></td><td><?php echo esc_html($r->location_name ?: '—'); ?></td><td><?php echo esc_html(ucfirst($r->status)); ?></td><td><?php echo esc_html($r->last_seen_at ?: '—'); ?></td>
                <td><?php if ($r->display_url !== ''): ?><a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url($r->display_url); ?>">Open Kiosk</a><?php else: ?>—<?php endif; ?></td>
                <td><?php if ($r->status === 'active'): ?><form method="post" action="<?php echo esc_url($post); ?>" style="display:inline"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_presence_kiosk_revoke')); ?>"><input type="hidden" name="action" value="ews_presence_kiosk_revoke"><input type="hidden" name="kiosk_id" value="<?php echo (int) $r->id; ?>"><button class="button button-small">Disable</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
</div></div>
