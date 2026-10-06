<?php
/**
 * The app's page without the site theme's header, footer and page padding (wp-admin → Appearance →
 * Full-screen app page; chosen by app_fullscreen_template() in includes/trait-appearance.php).
 * wp_head() and wp_footer() still run, so styles, the PWA tags and other plugins keep working.
 * Styles: .wfo-fullscreen in assets/css/app-shell.css.
 */
if (!defined('ABSPATH')) exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php wp_head(); ?>
</head>
<body <?php body_class('wfo-fullscreen'); ?>>
<?php if (function_exists('wp_body_open')) wp_body_open(); ?>
<main class="wfo-fullscreen-main">
<?php while (have_posts()) { the_post(); the_content(); } ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
