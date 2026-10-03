<?php
/**
 * Employee app → Presence Verification: scan the QR of the requested work location's kiosk.
 * Script: assets/js/presence-scan.js (camera scanning with jsQR; submits the code when found).
 *
 * @var object|null $req     the request (location_name, status, expires_at, verified_at), if any
 * @var int $left            seconds until it expires
 * @var bool $success
 * @var string $error        a known message ('' = none)
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wfo-card" style="max-width:720px;margin:0 auto"><h2 style="margin-top:0"><?php esc_html_e('Presence Verification', 'workforce-one'); ?></h2>
<?php if ($success): ?><div class="ews-notice"><?php esc_html_e('Presence verified successfully.', 'workforce-one'); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="ews-notice ews-notice-error"><?php echo esc_html($error); ?></div><?php endif; ?>
<?php if (!$req): ?>
    <p><?php esc_html_e('No active presence verification request was found.', 'workforce-one'); ?></p>
<?php elseif ($req->status === 'pending'): ?>
    <p><?php /* translators: %s: work location name */ printf(esc_html__('Please scan the QR code displayed at %s.', 'workforce-one'), '<strong>' . esc_html($req->location_name) . '</strong>'); ?></p>
    <p id="wfo-presence-countdown" style="font-weight:700"><?php /* translators: %s: mm:ss */ printf(esc_html__('Expires in %s', 'workforce-one'), esc_html(gmdate('i:s', $left))); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wfo-presence-form">
        <?php wp_nonce_field('ews_presence_verify'); ?><input type="hidden" name="action" value="ews_presence_verify"><input type="hidden" name="request_id" value="<?php echo (int) $req->id; ?>"><input type="hidden" name="qr_payload" id="wfo-presence-payload">
        <div id="wfo-presence-scanner" data-unavailable="<?php esc_attr_e('Camera scanning is unavailable. Please use a QR-capable device.', 'workforce-one'); ?>" style="background:#101828;border-radius:16px;overflow:hidden;min-height:280px;display:flex;align-items:center;justify-content:center;color:#fff"><?php esc_html_e('Starting camera…', 'workforce-one'); ?></div>
        <p style="color:#667085;font-size:12px"><?php esc_html_e('Camera scanning uses the browser camera permission. If scanning is unavailable on this device, use a QR-capable browser/device.', 'workforce-one'); ?></p>
        <button class="button button-primary" type="submit"><?php esc_html_e('Verify Presence', 'workforce-one'); ?></button>
    </form>
<?php else: ?>
    <p><?php esc_html_e('Status:', 'workforce-one'); ?> <strong><?php echo esc_html(ucfirst($req->status)); ?></strong></p>
    <p><?php esc_html_e('Location:', 'workforce-one'); ?> <?php echo esc_html($req->location_name); ?></p>
    <?php if ($req->verified_at): ?><p><?php esc_html_e('Verified at:', 'workforce-one'); ?> <?php echo esc_html($req->verified_at); ?></p><?php endif; ?>
<?php endif; ?>
</div>
