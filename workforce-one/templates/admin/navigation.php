<?php
/**
 * wp-admin "View Navigation".
 *
 * @var array<string,array<string,mixed>> $cfg       current settings per page key
 * @var array<string,array<string,mixed>> $defaults  built-in settings (page names)
 * @var string|null $notice
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>View Navigation</h1>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<p>Configure frontend navigation separately for Desktop and Mobile. You can control visibility, display names, and order. Permissions still apply: hidden navigation does not grant or remove access, and missing permission always prevents access.</p>
<form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews31_navigation_save'); ?><input type="hidden" name="action" value="ews31_navigation_save">
    <table class="widefat striped" style="max-width:1200px;margin-top:18px"><thead><tr><th style="width:18%">Page</th><th>Desktop Label</th><th style="width:10%">Desktop</th><th style="width:9%">Order</th><th>Mobile Label</th><th style="width:10%">Mobile</th><th style="width:9%">Order</th><th>Key</th></tr></thead><tbody>
    <?php foreach ($cfg as $key => $item): $f = 'nav[' . esc_attr($key) . ']'; ?>
        <tr><td><strong><?php echo esc_html($item['icon'] . ' ' . $defaults[$key]['label']); ?></strong></td>
            <td><input type="text" class="regular-text" name="<?php echo $f; ?>[label]" value="<?php echo esc_attr($item['label']); ?>" maxlength="60"></td>
            <td><label><input type="checkbox" name="<?php echo $f; ?>[desktop_visible]" value="1" <?php checked(!empty($item['desktop_visible'])); ?>> Show</label></td>
            <td><input type="number" min="1" step="1" style="width:70px" name="<?php echo $f; ?>[desktop_order]" value="<?php echo (int) $item['desktop_order']; ?>"></td>
            <td><input type="text" class="regular-text" name="<?php echo $f; ?>[mobile_label]" value="<?php echo esc_attr($item['mobile_label']); ?>" maxlength="40"></td>
            <td><label><input type="checkbox" name="<?php echo $f; ?>[mobile_visible]" value="1" <?php checked(!empty($item['mobile_visible'])); ?>> Show</label></td>
            <td><input type="number" min="1" step="1" style="width:70px" name="<?php echo $f; ?>[mobile_order]" value="<?php echo (int) $item['mobile_order']; ?>"></td>
            <td><code><?php echo esc_html($key); ?></code></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p style="margin-top:18px"><button type="submit" class="button button-primary">Save Navigation</button></p>
</form>
<form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:8px"><?php wp_nonce_field('ews31_navigation_reset'); ?><input type="hidden" name="action" value="ews31_navigation_reset"><button type="submit" class="button">Restore Defaults</button></form>
<p style="margin-top:18px;color:#667085">Notifications and My Profile remain top-right actions. Employee management remains in WordPress Admin. Navigation visibility is presentation only; permissions remain the access-control layer.</p>
</div>
