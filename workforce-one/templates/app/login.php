<?php
/**
 * Employee app login (look C: a white card on the brand colour, a colour or a picture). Settings:
 * wp-admin → Appearance → Login screen. Styles: assets/css/app-login.css; the eye button:
 * assets/js/login.js. Also shown, signed in, as "You are already signed in".
 *
 * @var bool $signed_in
 * @var array{app_name:string,company_name:string,tagline:string,logo_url:string,show:bool} $brand
 * @var array<string,string> $cfg            Appearance::config()
 * @var string $form                         wp_login_form() HTML (marked with ews_app_login)
 * @var string $lost_password_url            '' = hidden (the link itself is inside $form, next to Remember me)
 * @var string $error
 * @var string $message
 * @var string $open_url
 * @var string $logout_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $form is built by wp_login_form().
$mark = '';
foreach (array_slice(array_values(array_filter(preg_split('/\s+/u', trim($brand['app_name'])) ?: [])), 0, 2) as $w) $mark .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($w, 0, 1)) : strtoupper($w[0]);
$title = $cfg['login_title'] !== '' ? $cfg['login_title'] : __('Welcome back', 'workforce-one');
$subtitle = $cfg['login_subtitle'] !== '' ? $cfg['login_subtitle'] : __('Sign in with your work account', 'workforce-one');
$line = implode(' · ', array_filter([$brand['company_name'], $brand['tagline']]));
?>
<div class="ews-login-wrap wfo-login is-bg-<?php echo esc_attr($cfg['login_bg']); ?>">
    <div class="wfo-login-inner">
        <?php if ($brand['show']): ?>
        <div class="wfo-login-brand">
            <span class="wfo-login-mark"><?php if ($brand['logo_url'] !== ''): ?><img src="<?php echo esc_url($brand['logo_url']); ?>" alt=""><?php else: ?><?php echo esc_html($mark ?: 'W1'); ?><?php endif; ?></span>
            <strong class="ews-login-logo"><?php echo esc_html($brand['app_name']); ?></strong>
            <?php if ($line !== ''): ?><span class="wfo-login-line"><?php echo esc_html($line); ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ews-login-card wfo-login-card<?php echo $cfg['login_eye'] === '1' ? ' has-eye' : ''; ?>"<?php echo $cfg['login_eye'] === '1' ? ' data-show-label="' . esc_attr__('Show password', 'workforce-one') . '" data-hide-label="' . esc_attr__('Hide password', 'workforce-one') . '"' : ''; ?>>
            <?php if ($signed_in): ?>
                <h1><?php esc_html_e('You are already signed in', 'workforce-one'); ?></h1>
                <a class="ews-login-btn wfo-login-submit" href="<?php echo esc_url($open_url); ?>"><?php echo esc_html(sprintf(/* translators: %s: app name */ __('Open %s', 'workforce-one'), $brand['app_name'])); ?></a>
                <a class="ews-login-link wfo-login-alt" href="<?php echo esc_url($logout_url); ?>"><?php esc_html_e('Log out', 'workforce-one'); ?></a>
            <?php else: ?>
                <h1><?php echo esc_html($title); ?></h1>
                <p class="ews-login-sub"><?php echo esc_html($subtitle); ?></p>
                <?php if ($error !== ''): ?><div class="ews-login-error" role="alert"><?php echo Icons::svg('alert', 16); ?><span><?php echo esc_html($error); ?></span></div><?php endif; ?>
                <?php if ($message !== ''): ?><div class="ews-login-success" role="status"><?php echo Icons::svg('check', 16); ?><span><?php echo esc_html($message); ?></span></div><?php endif; ?>
                <?php echo $form; ?>
            <?php endif; ?>
        </div>

        <?php if (!$signed_in && $cfg['login_help'] !== ''): ?><p class="wfo-login-help"><?php echo esc_html($cfg['login_help']); ?></p><?php endif; ?>
    </div>
</div>
