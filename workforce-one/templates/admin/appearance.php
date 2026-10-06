<?php
/**
 * wp-admin "Appearance": brand, theme colours, header style, font and corners of the employee app,
 * with a live preview. Script: assets/js/admin-appearance.js (preview, theme cards).
 *
 * @var array<string,string> $cfg                        current look (Appearance::config())
 * @var array<string,array<string,string>> $presets
 * @var array<string,array<string,mixed>> $fonts
 * @var array<string,array<string,mixed>> $corners
 * @var array<string,string> $header_styles
 * @var bool $is_default                                 nothing saved yet
 * @var string|null $notice
 * @var list<string> $errors                             colours that were refused on the last save
 * @var string $post_url
 * @var string $preview_css                              the saved look as CSS variables for the preview
 * @var string $font_css                                 @font-face rules of every bundled font (for the samples)
 * @var float $min_contrast
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Settings\Appearance;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- $preview_css and $font_css are built from validated values; Icons::svg() is static markup.
$color_help = ['header_start' => 'Top of the header', 'header_end' => 'Bottom of the header (gradient only)', 'highlight' => 'Main buttons on the header, badges', 'primary' => 'Links, active menu item, buttons'];
?>
<div class="wrap wfo-appearance">
<h1>Appearance</h1>
<p class="wfo-appearance-lead">How the employee app looks for everyone. The preview follows your changes; the app uses them after you save. Colours that would make text hard to read are refused (contrast below <?php echo esc_html(number_format($min_contrast, 1)); ?> : 1).</p>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="notice notice-error"><p><?php echo esc_html($e); ?></p></div><?php endforeach; ?>
<style><?php echo $font_css . $preview_css; ?></style>

<div class="wfo-appearance-grid">
<form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-appearance-form" id="wfo-appearance-form">
    <?php wp_nonce_field('ews31_appearance_save'); ?><input type="hidden" name="action" value="ews31_appearance_save">

    <section class="wfo-ap-card">
        <h2>Brand</h2>
        <div class="wfo-ap-cols3">
            <label><span>Company name</span><input type="text" name="company_name" value="<?php echo esc_attr($cfg['company_name']); ?>" maxlength="60" data-preview-text="company"></label>
            <label><span>App name</span><input type="text" name="app_name" value="<?php echo esc_attr($cfg['app_name']); ?>" maxlength="60" data-preview-text="app"></label>
            <label><span>Tagline</span><input type="text" name="tagline" value="<?php echo esc_attr($cfg['tagline']); ?>" maxlength="120"></label>
        </div>
        <label><span>Logo URL</span><input type="url" name="logo_url" value="<?php echo esc_attr($cfg['logo_url']); ?>" placeholder="https://… (square PNG or SVG, at least 256 px)" data-preview-logo></label>
        <p class="wfo-ap-help">Shown at the top of the app menu. Leave empty to show the app name's initials.</p>
        <input type="hidden" name="fullscreen" value="0">
        <label class="wfo-ap-check"><input type="checkbox" name="fullscreen" value="1" <?php checked($cfg['fullscreen'], '1'); ?>> Full-screen app page (show only the app, without the site theme's header, footer and page spacing)</label>
        <p class="wfo-ap-help">Turn this off if the app page should keep your theme's header and footer.</p>
    </section>

    <section class="wfo-ap-card">
        <h2>Theme</h2>
        <p class="wfo-ap-help">Start from a ready theme, then adjust any colour.</p>
        <div class="wfo-ap-presets" role="radiogroup" aria-label="Theme">
            <?php foreach ($presets as $key => $p): $mid = Appearance::mix($p['header_start'], $p['header_end'], .5); ?>
            <label class="wfo-ap-preset">
                <input type="radio" name="preset" value="<?php echo esc_attr($key); ?>" <?php checked($cfg['preset'], $key); ?> data-preset='<?php echo esc_attr(wp_json_encode($p)); ?>'>
                <span class="wfo-ap-preset-swatch" style="background:linear-gradient(135deg,<?php echo esc_attr($p['header_start']); ?>,<?php echo esc_attr($mid); ?> 55%,<?php echo esc_attr($p['header_end']); ?>)"><i style="background:<?php echo esc_attr($p['highlight']); ?>"></i></span>
                <strong><?php echo esc_html($p['label']); ?></strong>
                <small><?php echo esc_html($fonts[$p['font']]['label']); ?></small>
            </label>
            <?php endforeach; ?>
            <label class="wfo-ap-preset">
                <input type="radio" name="preset" value="custom" <?php checked($cfg['preset'], 'custom'); ?>>
                <span class="wfo-ap-preset-swatch wfo-ap-preset-custom"><?php echo Icons::svg('more', 20); ?></span>
                <strong>Custom</strong><small>Your own colours</small>
            </label>
        </div>
        <label class="wfo-ap-check"><input type="checkbox" name="preset_applied" value="1" id="wfo-preset-applied"> Use the selected theme's colours and font (replaces the fields below)</label>

        <div class="wfo-ap-cols4">
            <?php foreach (Appearance::COLOR_FIELDS as $f): ?>
            <label><span><?php echo esc_html(Appearance::fieldLabel($f)); ?></span>
                <span class="wfo-ap-color"><input type="color" value="<?php echo esc_attr(strtolower($cfg[$f])); ?>" aria-label="<?php echo esc_attr(Appearance::fieldLabel($f)); ?> picker" data-color-for="<?php echo esc_attr($f); ?>"><input type="text" name="<?php echo esc_attr($f); ?>" value="<?php echo esc_attr($cfg[$f]); ?>" maxlength="7" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" data-color="<?php echo esc_attr($f); ?>"></span>
                <small><?php echo esc_html($color_help[$f]); ?></small></label>
            <?php endforeach; ?>
        </div>
        <div class="wfo-ap-contrast" id="wfo-ap-contrast" aria-live="polite"></div>

        <fieldset class="wfo-ap-fieldset"><legend>Header style</legend><div class="wfo-ap-seg">
            <?php foreach ($header_styles as $key => $label): ?><label><input type="radio" name="header_style" value="<?php echo esc_attr($key); ?>" <?php checked($cfg['header_style'], $key); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?></div>
        </fieldset>
    </section>

    <section class="wfo-ap-card">
        <h2>Font &amp; shape</h2>
        <div class="wfo-ap-fonts" role="radiogroup" aria-label="Font">
            <?php foreach ($fonts as $key => $f): ?>
            <label class="wfo-ap-font"><input type="radio" name="font" value="<?php echo esc_attr($key); ?>" <?php checked($cfg['font'], $key); ?> data-stack="<?php echo esc_attr(Appearance::fontStack($key)); ?>">
                <span class="wfo-ap-font-sample" style="font-family:<?php echo esc_attr(Appearance::fontStack($key)); ?>">Aa أب</span><small><?php echo esc_html($f['label']); ?></small></label>
            <?php endforeach; ?>
        </div>
        <p class="wfo-ap-help">Fonts are part of the plugin; nothing is loaded from other sites.</p>
        <fieldset class="wfo-ap-fieldset"><legend>Corners</legend><div class="wfo-ap-seg">
            <?php foreach ($corners as $key => $c): ?><label><input type="radio" name="corners" value="<?php echo esc_attr($key); ?>" <?php checked($cfg['corners'], $key); ?> data-radius='<?php echo esc_attr(wp_json_encode($c)); ?>'><span><?php echo esc_html($c['label']); ?></span></label><?php endforeach; ?></div>
        </fieldset>
    </section>

    <div class="wfo-ap-savebar">
        <span><?php echo $is_default ? 'Using the built-in look.' : 'Menu items, labels and order are set in View Navigation.'; ?></span>
        <button type="submit" class="button button-primary">Save appearance</button>
    </div>
</form>

<aside class="wfo-ap-preview-wrap" aria-label="Preview">
    <div class="wfo-appearance-preview" id="wfo-appearance-preview" style="font-family:var(--wfo-font)" data-min-contrast="<?php echo esc_attr((string) $min_contrast); ?>">
        <div class="wfo-pv-hero">
            <div class="wfo-pv-top"><span class="wfo-pv-avatar">AM</span><span class="wfo-pv-hello"><small data-preview-out="company"><?php echo esc_html($cfg['company_name']); ?></small><b>Good morning, Ahmed</b></span><span class="wfo-pv-bell"><?php echo Icons::svg('bell', 18); ?><i>3</i></span></div>
        </div>
        <div class="wfo-pv-body">
            <div class="wfo-pv-card">
                <div class="wfo-pv-status"><i></i>Signed in 08:04 · on time</div>
                <div class="wfo-pv-big">4h 12m</div>
                <div class="wfo-pv-bar"><span></span></div>
                <div class="wfo-pv-actions"><span class="wfo-pv-btn">Sign Out</span><span class="wfo-pv-btn2">Break</span></div>
            </div>
            <div class="wfo-pv-card wfo-pv-tiles">
                <?php foreach (['clock' => 'Clock', 'calendar' => 'Schedule', 'leave' => 'Leave', 'tasks' => 'Tasks'] as $icon => $label): ?><span><i><?php echo Icons::svg($icon, 20); ?></i><?php echo esc_html($label); ?></span><?php endforeach; ?>
            </div>
            <div class="wfo-pv-card wfo-pv-row"><span>Leave request pending</span><span class="wfo-pv-link">View</span></div>
        </div>
        <div class="wfo-pv-tabbar">
            <span class="on"><i><?php echo Icons::svg('home', 18, 2.1); ?></i>Home</span>
            <span><i><?php echo Icons::svg('calendar', 18); ?></i>Schedule</span>
            <span class="wfo-pv-clock"><i><?php echo Icons::svg('clock', 22, 2); ?></i>Clock</span>
            <span><i><?php echo Icons::svg('leave', 18); ?></i>Leave</span>
            <span><i><?php echo Icons::svg('more', 18); ?></i>More</span>
        </div>
    </div>
    <p class="wfo-ap-help">Preview with sample data.</p>
</aside>
</div>

<form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-ap-reset"><?php wp_nonce_field('ews31_appearance_reset'); ?><input type="hidden" name="action" value="ews31_appearance_reset"><button type="submit" class="button">Restore the built-in look</button></form>
</div>
