<?php
/**
 * Employee app frame: the menu rail (desktop), the header with the notification bell and the
 * profile menu, the page body, and on phones the bottom bar (Sign In / Out in the middle) with the
 * "More" sheet. Colours and font come from wp-admin → Appearance (CSS variables).
 * Script: assets/js/workforce-one.js (profile menu, More sheet, notices); styles: assets/css/app-shell.css.
 *
 * @var string $title
 * @var array{0:string,1:string}|null $greeting  a view's greeting and date line (Dashboard); replaces the brand line
 * @var string $body                 the view's HTML
 * @var string $active               menu key of the view shown
 * @var array<int,array<string,mixed>> $desktop_items
 * @var array<int,array<string,mixed>> $mobile_items
 * @var array{start:list<array<string,mixed>>,center:array<string,mixed>|null,end:list<array<string,mixed>>,more:list<array<string,mixed>>} $bar  MobileBar::split($mobile_items)
 * @var string $clock_state          'in' | 'out' | 'done' | '' (MobileBar::clockState())
 * @var string $notice_html          the result pop-up (ux_notice_from_query())
 * @var string $bell_html            notification_bell()
 * @var string $picture              '' = initials
 * @var string $initials
 * @var string $user_name
 * @var string $company_name
 * @var string $app_name
 * @var string $tagline
 * @var string $logo_url             '' = the app name's initials
 * @var string $home_url
 * @var string $profile_url
 * @var string $logout_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- $body, $notice_html and $bell_html are built (and escaped) by the plugin; Icons::svg() is static markup.
$more_keys = array_map(function ($x) { return $x['key']; }, $bar['more']);
$more_active = in_array($active, $more_keys, true) || in_array($active, ['profile', 'notifications'], true);
$mark = '';
foreach (array_slice(array_values(array_filter(preg_split('/\s+/u', trim($app_name)) ?: [])), 0, 2) as $w) $mark .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($w, 0, 1)) : strtoupper($w[0]);
$clock_labels = ['in' => __('signed in', 'workforce-one'), 'out' => __('not signed in yet', 'workforce-one'), 'done' => __('signed out', 'workforce-one')];
$tab = function ($x) use ($active) {
    $on = $active === $x['key'];
    return '<a class="wfo-tab' . ($on ? ' active' : '') . '" href="' . esc_url($x['url']) . '"' . ($on ? ' aria-current="page"' : '') . '><span class="wfo-tab-icon">' . Icons::forView($x['key'], 22, $on ? 2.1 : 1.8) . '</span><span class="wfo-tab-label">' . esc_html($x['mobile_label']) . '</span></a>';
};
?>
<div class="ews-app wfo-app">
    <div class="wfo-shell">
        <aside class="wfo-rail" aria-label="<?php esc_attr_e('Workforce One navigation', 'workforce-one'); ?>">
            <a class="wfo-rail-brand" href="<?php echo esc_url($home_url); ?>" title="<?php echo esc_attr($company_name . ' · ' . $app_name); ?>">
                <?php if ($logo_url !== ''): ?><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($app_name); ?>"><?php else: ?><span aria-hidden="true"><?php echo esc_html($mark ?: 'W1'); ?></span><span class="screen-reader-text"><?php echo esc_html($app_name); ?></span><?php endif; ?>
            </a>
            <nav class="ews-nav">
                <?php foreach ($desktop_items as $x): $on = $active === $x['key']; ?><a class="<?php echo $on ? 'active' : ''; ?>" href="<?php echo esc_url($x['url']); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><?php echo Icons::forView($x['key'], 22, $on ? 2.1 : 1.8); ?><span class="wfo-rail-label"><?php echo esc_html($x['desktop_label']); ?></span></a><?php endforeach; ?>
            </nav>
            <a class="wfo-rail-logout" href="<?php echo esc_url($logout_url); ?>"><?php echo Icons::svg('logout', 20); ?><span class="wfo-rail-label"><?php esc_html_e('Log out', 'workforce-one'); ?></span></a>
        </aside>
        <main class="ews-main"><?php echo $notice_html; ?>
            <header class="wfo-header<?php echo $greeting ? ' has-greeting' : ''; ?>">
                <div class="wfo-header-text">
                    <?php if ($greeting): ?>
                    <div class="wfo-header-kicker"><h1 class="ews-title"><?php echo esc_html($title); ?></h1><span><?php echo esc_html($greeting[1]); ?></span></div>
                    <p class="wfo-header-greeting"><?php echo esc_html($greeting[0]); ?></p>
                    <?php else: ?>
                    <div class="wfo-header-brand"><?php echo esc_html($company_name); ?> · <?php echo esc_html($app_name); ?></div>
                    <h1 class="ews-title"><?php echo esc_html($title); ?></h1>
                    <?php if ($tagline !== ''): ?><div class="ews-sub"><?php echo esc_html($tagline); ?></div><?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="ews-mobile-top-actions">
                    <?php echo $bell_html; ?>
                    <div class="ews-profile-menu">
                        <button type="button" class="ews-profile-menu-trigger" aria-label="<?php esc_attr_e('My Profile menu', 'workforce-one'); ?>" aria-expanded="false" aria-controls="ews-profile-menu-dropdown">
                            <?php if ($picture !== ''): ?><img src="<?php echo esc_url($picture); ?>" alt="" class="ews-profile-menu-avatar"><?php else: ?><span class="ews-profile-menu-initials" aria-hidden="true"><?php echo esc_html($initials); ?></span><?php endif; ?>
                        </button>
                        <div id="ews-profile-menu-dropdown" class="ews-profile-menu-dropdown" hidden>
                            <a href="<?php echo esc_url($profile_url); ?>"><span class="ews-profile-menu-item-icon"><?php echo Icons::svg('user', 18); ?></span><span><?php esc_html_e('My Profile', 'workforce-one'); ?></span></a>
                            <a href="<?php echo esc_url($logout_url); ?>"><span class="ews-profile-menu-item-icon"><?php echo Icons::svg('logout', 18); ?></span><span><?php esc_html_e('Log out', 'workforce-one'); ?></span></a>
                        </div>
                    </div>
                </div>
            </header>
            <div class="wfo-content"><?php echo $body; ?></div>
        </main>
    </div>

    <nav class="wfo-tabbar" aria-label="<?php esc_attr_e('Workforce One navigation', 'workforce-one'); ?>">
        <?php foreach ($bar['start'] as $x) echo $tab($x); ?>
        <?php if ($bar['center']): $x = $bar['center']; $on = $active === $x['key']; ?>
            <a class="wfo-tab wfo-tab-clock<?php echo $on ? ' active' : ''; ?>" href="<?php echo esc_url($x['url']); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>>
                <span class="wfo-tab-clock-btn"><?php echo Icons::svg('clock', 26, 2); ?><?php if ($clock_state !== ''): ?><i class="wfo-clock-dot is-<?php echo esc_attr($clock_state); ?>"></i><span class="screen-reader-text"> (<?php echo esc_html($clock_labels[$clock_state]); ?>)</span><?php endif; ?></span>
                <span class="wfo-tab-label"><?php echo esc_html($x['mobile_label']); ?></span>
            </a>
        <?php endif; ?>
        <?php foreach ($bar['end'] as $x) echo $tab($x); ?>
        <button type="button" class="wfo-tab<?php echo $more_active ? ' active' : ''; ?>" data-wfo-more-open aria-haspopup="dialog" aria-controls="wfo-more" aria-expanded="false"><span class="wfo-tab-icon"><?php echo Icons::svg('more', 22, $more_active ? 2.1 : 1.8); ?></span><span class="wfo-tab-label"><?php esc_html_e('More', 'workforce-one'); ?></span></button>
    </nav>

    <dialog class="wfo-more" id="wfo-more" aria-labelledby="wfo-more-title">
        <div class="wfo-more-sheet">
            <div class="wfo-more-handle" aria-hidden="true"></div>
            <div class="wfo-more-head">
                <h2 id="wfo-more-title" class="screen-reader-text"><?php esc_html_e('More', 'workforce-one'); ?></h2>
                <a class="wfo-more-me" href="<?php echo esc_url($profile_url); ?>">
                    <?php if ($picture !== ''): ?><img src="<?php echo esc_url($picture); ?>" alt=""><?php else: ?><span class="wfo-more-initials" aria-hidden="true"><?php echo esc_html($initials); ?></span><?php endif; ?>
                    <span class="wfo-more-me-text"><strong><?php echo esc_html($user_name); ?></strong><small><?php esc_html_e('My Profile', 'workforce-one'); ?></small></span>
                    <?php echo Icons::svg('chevron', 18, 2); ?>
                </a>
                <button type="button" class="wfo-more-close" data-wfo-more-close autofocus aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button>
            </div>
            <?php if ($bar['more']): ?>
            <div class="wfo-more-grid">
                <?php foreach ($bar['more'] as $x): $on = $active === $x['key']; ?><a class="wfo-more-item<?php echo $on ? ' active' : ''; ?>" href="<?php echo esc_url($x['url']); ?>"<?php echo $on ? ' aria-current="page"' : ''; ?>><span class="wfo-more-icon"><?php echo Icons::forView($x['key'], 24); ?></span><span><?php echo esc_html($x['mobile_label']); ?></span></a><?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="wfo-more-rows">
                <div class="wfo-more-row wfo-more-install"><?php echo Icons::svg('install', 20); ?><span><strong><?php esc_html_e('Mobile shortcut:', 'workforce-one'); ?></strong> <?php /* translators: %s: "Add to Home Screen" */ printf(esc_html__('Use your browser menu → %s to keep Workforce One one tap away.', 'workforce-one'), '<strong>' . esc_html__('Add to Home Screen', 'workforce-one') . '</strong>'); ?></span></div>
                <a class="wfo-more-row wfo-more-logout" href="<?php echo esc_url($logout_url); ?>"><?php echo Icons::svg('logout', 20); ?><span><?php esc_html_e('Log out', 'workforce-one'); ?></span></a>
            </div>
        </div>
    </dialog>
</div>
