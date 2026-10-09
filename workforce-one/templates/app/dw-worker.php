<?php
/**
 * A daily worker's own page (3.31.75, ?ews_view=worker): sign in with the mobile number and the PIN
 * the site manager gave him (no email, no WordPress account), then "I am at the site" for today
 * (GPS inside the site) and his recent days and what he is owed. Styles: assets/css/app-login.css
 * and app-daily-workers.css; script: assets/js/daily-workers.js.
 *
 * @var bool $enabled
 * @var object|null $worker
 * @var object|null $site      today's site
 * @var object|null $today     today's day row
 * @var array<int,object> $days
 * @var float  $owed
 * @var bool   $can_self       the site lets workers sign in themselves
 * @var string $post_url
 * @var string $app_name
 * @var string $error
 * @var bool   $done
 * @var string $staff_url
 * @var callable $mark_label   (signed in only)
 * @var callable $money        (signed in only)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
?>
<div class="ews-login-wrap wfo-login wfo-dw-me">
    <div class="wfo-login-inner">
        <div class="wfo-login-brand"><strong class="ews-login-logo"><?php echo esc_html($app_name); ?></strong><span class="wfo-login-line"><?php esc_html_e('Daily workers', 'workforce-one'); ?></span></div>
        <div class="ews-login-card wfo-login-card">
        <?php if (!$enabled): ?>
            <h1><?php esc_html_e('Daily workers are switched off.', 'workforce-one'); ?></h1>
        <?php elseif (!$worker): ?>
            <h1><?php esc_html_e('Sign in with your mobile', 'workforce-one'); ?></h1>
            <p class="ews-login-sub"><?php esc_html_e('Use the PIN the site manager gave you.', 'workforce-one'); ?></p>
            <?php if ($error !== ''): ?><div class="ews-login-error" role="alert"><?php echo Icons::svg('alert', 16); ?><span><?php echo esc_html($error); ?></span></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>">
                <?php wp_nonce_field('ews_dw_worker_login'); ?><input type="hidden" name="action" value="ews_dw_worker_login">
                <p><label for="wfo-dw-m"><?php esc_html_e('Mobile', 'workforce-one'); ?></label><input id="wfo-dw-m" class="input" type="tel" name="mobile" dir="ltr" inputmode="tel" autocomplete="tel" required></p>
                <p><label for="wfo-dw-p"><?php esc_html_e('PIN', 'workforce-one'); ?></label><input id="wfo-dw-p" class="input" type="password" name="pin" inputmode="numeric" autocomplete="current-password" maxlength="6" required></p>
                <p class="login-submit"><button type="submit" class="ews-login-btn wfo-login-submit"><?php echo esc_html_x('Sign In', 'log in to the app', 'workforce-one'); ?></button></p>
            </form>
            <a class="ews-login-link wfo-login-alt" href="<?php echo esc_url($staff_url); ?>"><?php esc_html_e('Staff sign in', 'workforce-one'); ?></a>
        <?php else: ?>
            <h1><?php echo esc_html($worker->name); ?></h1>
            <p class="ews-login-sub"><?php echo esc_html($site ? $site->name : __('You are not assigned to a site today. Ask your foreman.', 'workforce-one')); ?></p>
            <?php if ($error !== ''): ?><div class="ews-login-error" role="alert"><?php echo Icons::svg('alert', 16); ?><span><?php echo esc_html($error); ?></span></div><?php endif; ?>
            <?php if ($done): ?><div class="ews-login-success" role="status"><?php echo Icons::svg('check', 16); ?><span><?php esc_html_e('Your day is recorded.', 'workforce-one'); ?></span></div><?php endif; ?>
            <?php if ($today): ?>
                <div class="wfo-dw-locked"><?php echo Icons::svg('check', 18, 2.2); ?><?php /* translators: 1: attendance, 2: time */ echo esc_html(sprintf(__('Today: %1$s (%2$s)', 'workforce-one'), $mark_label($today->mark), mysql2date('H:i', $today->created_at))); ?></div>
            <?php elseif ($site && $can_self): ?>
                <form method="post" action="<?php echo esc_url($post_url); ?>" data-dw-self-form>
                    <?php wp_nonce_field('ews_dw_self'); ?><input type="hidden" name="action" value="ews_dw_self">
                    <input type="hidden" name="latitude" value=""><input type="hidden" name="longitude" value=""><input type="hidden" name="accuracy" value=""><input type="hidden" name="location_timestamp" value="">
                    <button type="submit" class="ews-login-btn wfo-login-submit" data-busy="<?php esc_attr_e('Checking your position…', 'workforce-one'); ?>" data-fail="<?php esc_attr_e('Your position could not be read. Turn on location (GPS) and try again.', 'workforce-one'); ?>"><?php esc_html_e('I am at the site', 'workforce-one'); ?></button>
                </form>
            <?php elseif ($site): ?>
                <p class="ews-login-sub"><?php esc_html_e('At this site the foreman records the day; workers do not sign in themselves.', 'workforce-one'); ?></p>
            <?php endif; ?>
            <div class="wfo-dw-me-owed"><span><?php esc_html_e('Owed to you, not paid yet', 'workforce-one'); ?></span><b><?php echo esc_html($money($owed)); ?></b></div>
            <?php if ($days): ?><ul class="wfo-dw-me-days"><?php foreach ($days as $d): ?><li><span><?php echo esc_html(date_i18n('D j M', strtotime($d->work_date)) . ' · ' . $d->site_name); ?></span><b><?php echo esc_html($mark_label($d->mark) . ' · ' . $money($d->amount)); ?></b></li><?php endforeach; ?></ul><?php endif; ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_dw_worker_logout'); ?><input type="hidden" name="action" value="ews_dw_worker_logout"><button type="submit" class="ews-login-link wfo-login-alt wfo-dw-linkbtn"><?php esc_html_e('Log out', 'workforce-one'); ?></button></form>
        <?php endif; ?>
        </div>
    </div>
</div>
