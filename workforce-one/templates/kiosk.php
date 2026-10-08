<?php
/**
 * The kiosk's screen (a full page, outside the theme; new look 3.31.69): the brand colours from
 * wp-admin → Appearance, the clock, three steps for the employee and the rotating QR of its work
 * location with a bar until the next code. Script: assets/js/kiosk.js (draws the QR, fetches the
 * next code every slot, the clock and the bar); style: assets/css/kiosk.css.
 *
 * @var string $location_name
 * @var string $payload        the current code
 * @var string $payload_url    where the next code is fetched
 * @var int $slot_seconds
 * @var string $brand_css      CSS variables of the Appearance colours (on body)
 * @var string $app_name
 * @var string $logo           logo URL ('' = initials)
 * @var string $qr_lib         QR drawing library (assets/vendor)
 * @var string $script
 * @var string $style
 * @var string $version
 */
if (!defined('ABSPATH')) exit;
$initials = '';
foreach (array_slice(preg_split('/\s+/u', trim($app_name)) ?: [], 0, 2) as $w) $initials .= mb_strtoupper(mb_substr($w, 0, 1));
?><!doctype html>
<html <?php language_attributes(); ?>><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($app_name . ' · ' . $location_name); ?></title><link rel="stylesheet" href="<?php echo esc_url(add_query_arg('ver', $version, $style)); ?>">
<style><?php echo wp_strip_all_tags($brand_css); // phpcs:ignore WordPress.Security.EscapeOutput -- CSS variables built from validated colours ?></style></head>
<body><main class="k">
    <section class="k-info">
        <div class="k-brand"><?php if ($logo !== ''): ?><img src="<?php echo esc_url($logo); ?>" alt=""><?php else: ?><span aria-hidden="true"><?php echo esc_html($initials ?: 'WO'); ?></span><?php endif; ?><div><small><?php echo esc_html($app_name); ?></small><strong><?php echo esc_html($location_name); ?></strong></div></div>
        <div class="k-clock"><b id="k-time"><?php echo esc_html(date_i18n('H:i')); ?></b><span id="k-date"><?php echo esc_html(date_i18n('l, j F')); ?></span></div>
        <ol class="k-steps">
            <li><?php esc_html_e('Open Workforce One on your phone', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Open Sign In or the presence request', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Point the camera at this code', 'workforce-one'); ?></li>
        </ol>
    </section>
    <section class="k-card">
        <h1><?php esc_html_e('Scan to continue', 'workforce-one'); ?></h1>
        <div id="qr" class="qr" role="img" aria-label="<?php esc_attr_e('Dynamic Workforce One QR', 'workforce-one'); ?>"
            data-payload="<?php echo esc_attr($payload); ?>" data-url="<?php echo esc_url($payload_url); ?>" data-slot="<?php echo (int) $slot_seconds; ?>"
            data-changes="<?php /* translators: %d: seconds */ echo esc_attr(__('Changes in %ds', 'workforce-one')); ?>"></div>
        <div class="k-next"><div class="k-bar"><i id="k-bar"></i></div><span id="timer" class="timer"></span></div>
        <div id="offline" class="offline" role="alert"><?php esc_html_e('Connection unavailable. Please check this kiosk connection.', 'workforce-one'); ?></div>
    </section>
</main>
<script src="<?php echo esc_url($qr_lib); ?>"></script>
<script src="<?php echo esc_url(add_query_arg('ver', $version, $script)); ?>"></script>
</body></html>
