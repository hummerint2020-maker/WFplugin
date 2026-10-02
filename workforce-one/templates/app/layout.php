<?php
/**
 * Employee app frame: mobile and desktop navigation, the header with the notification bell and the
 * profile menu, and the page body. Script: assets/js/workforce-one.js (profile menu, notices).
 *
 * @var string $title
 * @var string $body                 the view's HTML
 * @var string $active               menu key of the view shown
 * @var array<int,array<string,mixed>> $desktop_items
 * @var array<int,array<string,mixed>> $mobile_items
 * @var string $notice_html          the result pop-up (ux_notice_from_query())
 * @var string $bell_html            notification_bell()
 * @var string $picture              '' = initials
 * @var string $initials
 * @var string $home_url
 * @var string $profile_url
 * @var string $logout_url
 */
if (!defined('ABSPATH')) exit;
// phpcs:disable WordPress.Security.EscapeOutput -- $body, $notice_html and $bell_html are built (and escaped) by the plugin.
?>
<div class="ews-app">
    <div class="ews-mobile-nav" aria-label="<?php esc_attr_e('Workforce One navigation', 'workforce-one'); ?>">
        <?php foreach ($mobile_items as $x): ?>
            <a class="<?php echo $active === $x['key'] ? 'active' : ''; ?>" href="<?php echo esc_url($x['url']); ?>"><span class="ews-mobile-icon" aria-hidden="true"><?php echo esc_html($x['icon']); ?></span><span><?php echo esc_html($x['mobile_label']); ?></span></a>
        <?php endforeach; ?>
    </div>
    <div class="ews-shell">
        <aside class="ews-side">
            <a class="ews-brand" href="<?php echo esc_url($home_url); ?>" title="Open Workforce One Dashboard"><span class="ews-brand-name">BA Team</span><span class="ews-brand-product">Workforce One</span></a>
            <nav class="ews-nav">
                <?php foreach ($desktop_items as $x): ?><a class="<?php echo $active === $x['key'] ? 'active' : ''; ?>" href="<?php echo esc_url($x['url']); ?>"><?php echo esc_html($x['icon'] . ' ' . $x['desktop_label']); ?></a><?php endforeach; ?>
                <a href="<?php echo esc_url($logout_url); ?>"><?php esc_html_e('Log out', 'workforce-one'); ?></a>
            </nav>
        </aside>
        <main class="ews-main"><?php echo $notice_html; ?>
            <div class="ews-pwa-help">
                <strong>📱 <?php esc_html_e('Mobile shortcut:', 'workforce-one'); ?></strong> <?php /* translators: %s: "Add to Home Screen" */ printf(esc_html__('Use your browser menu → %s to keep Workforce One one tap away.', 'workforce-one'), '<strong>' . esc_html__('Add to Home Screen', 'workforce-one') . '</strong>'); ?>
            </div>
            <div class="ews-top">
                <div>
                    <h1 class="ews-title"><?php echo esc_html($title); ?></h1>
                    <div class="ews-sub"><?php esc_html_e('Workforce Management Platform', 'workforce-one'); ?> <span class="ews-brand-tagline"><?php esc_html_e('One Platform. One Team. One Goal.', 'workforce-one'); ?></span></div>
                </div>
                <div class="ews-mobile-top-actions">
                    <?php echo $bell_html; ?>
                    <div class="ews-profile-menu">
                        <button type="button" class="ews-profile-menu-trigger" aria-label="<?php esc_attr_e('My Profile menu', 'workforce-one'); ?>" aria-expanded="false" aria-controls="ews-profile-menu-dropdown">
                            <?php if ($picture !== ''): ?><img src="<?php echo esc_url($picture); ?>" alt="" class="ews-profile-menu-avatar"><?php else: ?><span class="ews-profile-menu-initials" aria-hidden="true"><?php echo esc_html($initials); ?></span><?php endif; ?>
                        </button>
                        <div id="ews-profile-menu-dropdown" class="ews-profile-menu-dropdown" hidden>
                            <a href="<?php echo esc_url($profile_url); ?>"><span class="ews-profile-menu-item-icon">👤</span><span><?php esc_html_e('My Profile', 'workforce-one'); ?></span></a>
                            <a href="<?php echo esc_url($logout_url); ?>"><span class="ews-profile-menu-item-icon">↪</span><span><?php esc_html_e('Log out', 'workforce-one'); ?></span></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php echo $body; ?>
        </main>
    </div>
</div>
