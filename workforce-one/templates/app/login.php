<?php
/**
 * Employee app login. Styles: assets/css/workforce-one.css (.ews-login-*).
 *
 * @var string $form                 wp_login_form() HTML (marked with ews_app_login)
 * @var string $lost_password_url
 * @var string $error
 * @var string $message
 */
if (!defined('ABSPATH')) exit;
?>
<div class="ews-login-wrap">
    <div class="ews-login-card">
        <div class="ews-login-logo">BA Team<span>Workforce One</span></div>
        <h1>Welcome back</h1>
        <p class="ews-login-sub">Workforce Management Platform</p>
        <?php if ($error !== ''): ?><div class="ews-login-error"><?php echo esc_html($error); ?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="ews-login-success"><?php echo esc_html($message); ?></div><?php endif; ?>
        <?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput -- built by wp_login_form() ?>
        <div class="ews-login-forgot"><a href="<?php echo esc_url($lost_password_url); ?>">Forgot your password?</a></div>
    </div>
</div>
