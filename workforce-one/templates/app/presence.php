<?php
/**
 * Employee app → Presence Verification (3.31.69): scan the QR of the requested work location's kiosk.
 * One card per state: a check is open (time left as a ring, the camera, the location status), it is
 * done (verified / expired / …), or nothing is open. Styles: assets/css/app-presence.css. Script:
 * assets/js/presence-scan.js (camera scanning with jsQR, the device location, which the server checks
 * against the requested work location, and the countdown; submits when the code is found).
 *
 * @var object|null $req     the request (location_name, status, expires_at, verified_at), if any
 * @var int $left            seconds until it expires
 * @var int $total           seconds a request is open for (the ring's full circle)
 * @var bool $success
 * @var string $error        a known message ('' = none)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
$ring = 2 * M_PI * 52;
$offset = $total > 0 ? $ring * (1 - min(1, $left / $total)) : 0;
$states = ['verified' => __('Verified', 'workforce-one'), 'expired' => __('Expired', 'workforce-one'), 'rejected' => __('Rejected', 'workforce-one'), 'cancelled' => __('Cancelled', 'workforce-one'), 'pending' => __('Pending', 'workforce-one')];
?>
<div class="ews-page wfo-pres">
<?php if ($error !== ''): ?><div class="ews-notice ews-notice-error" role="alert"><?php echo esc_html($error); ?></div><?php endif; ?>

<?php if (!$req): ?>
    <section class="wfo-pres-card wfo-pres-empty">
        <span class="wfo-pres-badge"><?php echo Icons::svg('pin', 26, 2); ?></span>
        <h2><?php esc_html_e('No presence check right now', 'workforce-one'); ?></h2>
        <p><?php esc_html_e('No active presence verification request was found.', 'workforce-one'); ?> <?php esc_html_e('When your manager asks you to confirm where you are, you get a notification that opens it here.', 'workforce-one'); ?></p>
    </section>

<?php elseif ($req->status === 'pending'): ?>
    <section class="wfo-pres-card wfo-pres-open" aria-labelledby="wfo-pres-title">
        <span class="wfo-pres-ask"><?php esc_html_e('Your manager asked for a presence check', 'workforce-one'); ?></span>
        <div class="wfo-pres-ring" data-total="<?php echo (int) $total; ?>" data-left="<?php echo (int) $left; ?>" data-circle="<?php echo esc_attr((string) round($ring, 2)); ?>">
            <svg viewBox="0 0 120 120" aria-hidden="true"><circle class="track" cx="60" cy="60" r="52"/><circle class="bar" cx="60" cy="60" r="52" stroke-dasharray="<?php echo esc_attr((string) round($ring, 2)); ?>" stroke-dashoffset="<?php echo esc_attr((string) round($offset, 2)); ?>"/></svg>
            <strong id="wfo-presence-clock"><?php echo esc_html(gmdate('i:s', $left)); ?></strong>
        </div>
        <p id="wfo-presence-countdown" class="wfo-pres-left" aria-live="off" data-label="<?php esc_attr_e('Expires in %s', 'workforce-one'); ?>"><?php /* translators: %s: mm:ss */ printf(esc_html__('Expires in %s', 'workforce-one'), esc_html(gmdate('i:s', $left))); ?></p>
        <h2 id="wfo-pres-title"><?php /* translators: %s: work location name */ printf(esc_html__('Please scan the QR code displayed at %s.', 'workforce-one'), '<strong>' . esc_html($req->location_name) . '</strong>'); ?></h2>
    </section>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wfo-presence-form" class="wfo-pres-form">
        <?php wp_nonce_field('ews_presence_verify'); ?><input type="hidden" name="action" value="ews_presence_verify"><input type="hidden" name="request_id" value="<?php echo (int) $req->id; ?>"><input type="hidden" name="qr_payload" id="wfo-presence-payload">
        <input type="hidden" name="latitude" id="wfo-presence-lat"><input type="hidden" name="longitude" id="wfo-presence-lng"><input type="hidden" name="accuracy" id="wfo-presence-acc"><input type="hidden" name="location_timestamp" id="wfo-presence-ts">
        <div class="wfo-pres-camera">
            <div id="wfo-presence-scanner" data-unavailable="<?php esc_attr_e('Camera scanning is unavailable. Please use a QR-capable device.', 'workforce-one'); ?>"><?php esc_html_e('Starting the camera…', 'workforce-one'); ?></div>
            <span class="wfo-pres-frame" aria-hidden="true"></span>
            <span class="wfo-pres-hint"><?php esc_html_e('Point your camera at the code', 'workforce-one'); ?></span>
        </div>
        <p id="wfo-presence-location" class="wfo-pres-loc" role="status" aria-live="polite"
            data-waiting="<?php esc_attr_e('Getting your location… Please allow location access when your browser asks.', 'workforce-one'); ?>"
            data-ready="<?php esc_attr_e('Location ready.', 'workforce-one'); ?>"
            data-denied="<?php esc_attr_e('Location permission was denied. Presence can only be verified with your location: allow Location Services for this site, then reload this page.', 'workforce-one'); ?>"
            data-failed="<?php esc_attr_e('Your location could not be determined. Turn on Location Services/GPS and reload this page.', 'workforce-one'); ?>"
            data-insecure="<?php esc_attr_e('Location access requires HTTPS. Please open Workforce One using a secure connection.', 'workforce-one'); ?>"></p>
        <button class="wfo-pres-submit" type="submit"><?php echo Icons::svg('check', 18, 2.4); ?><?php esc_html_e('Verify Presence', 'workforce-one'); ?></button>
        <p class="wfo-pres-note"><?php esc_html_e('Camera scanning uses the browser camera permission. If scanning is unavailable on this device, use a QR-capable browser/device.', 'workforce-one'); ?></p>
    </form>

<?php else: $ok = $req->status === 'verified'; ?>
    <section class="wfo-pres-card wfo-pres-done<?php echo $ok ? ' is-ok' : ''; ?>">
        <span class="wfo-pres-badge"><?php echo Icons::svg($ok ? 'check' : 'alert', 30, 2.4); ?></span>
        <h2><?php echo esc_html($ok ? __('You are verified', 'workforce-one') : ($states[$req->status] ?? ucfirst((string) $req->status))); ?></h2>
        <dl>
            <div><dt><?php esc_html_e('Status:', 'workforce-one'); ?></dt><dd><?php echo esc_html($states[$req->status] ?? ucfirst((string) $req->status)); ?></dd></div>
            <div><dt><?php esc_html_e('Location:', 'workforce-one'); ?></dt><dd><?php echo esc_html($req->location_name); ?></dd></div>
            <?php if ($req->verified_at): ?><div><dt><?php esc_html_e('Verified at:', 'workforce-one'); ?></dt><dd><?php echo esc_html(date_i18n('D d M · H:i', strtotime((string) $req->verified_at))); ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($success): ?><p class="wfo-pres-thanks"><?php esc_html_e('Presence verified successfully.', 'workforce-one'); ?></p><?php endif; ?>
    </section>
<?php endif; ?>
</div>
