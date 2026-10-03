<?php
/**
 * The kiosk's screen (a full page, outside the theme): the rotating QR of its work location.
 * Script: assets/js/kiosk.js (draws the QR, fetches the next code every slot); style: assets/css/kiosk.css.
 *
 * @var string $location_name
 * @var string $payload        the current code
 * @var string $payload_url    where the next code is fetched
 * @var int $slot_seconds
 * @var string $qr_lib         QR drawing library (assets/vendor)
 * @var string $script
 * @var string $style
 * @var string $version
 */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Workforce One Kiosk</title><link rel="stylesheet" href="<?php echo esc_url(add_query_arg('ver', $version, $style)); ?>"></head>
<body><main class="k">
    <div class="brand">WORKFORCE ONE</div>
    <div class="sub"><?php esc_html_e('Scan to continue', 'workforce-one'); ?></div>
    <div id="qr" class="qr" role="img" aria-label="<?php esc_attr_e('Dynamic Workforce One QR', 'workforce-one'); ?>"
        data-payload="<?php echo esc_attr($payload); ?>" data-url="<?php echo esc_url($payload_url); ?>" data-slot="<?php echo (int) $slot_seconds; ?>"
        data-changes="<?php /* translators: %d: seconds */ echo esc_attr(__('Changes in %ds', 'workforce-one')); ?>"></div>
    <div id="timer" class="timer"></div>
    <div class="loc"><?php echo esc_html($location_name); ?></div>
    <div id="offline" class="offline"><?php esc_html_e('Connection unavailable. Please check this kiosk connection.', 'workforce-one'); ?></div>
</main>
<script src="<?php echo esc_url($qr_lib); ?>"></script>
<script src="<?php echo esc_url(add_query_arg('ver', $version, $script)); ?>"></script>
</body></html>
