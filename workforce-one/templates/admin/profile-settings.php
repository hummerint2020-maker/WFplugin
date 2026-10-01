<?php
/**
 * wp-admin "Employee Profile" visibility settings.
 *
 * @var array<string,int> $cfg
 * @var array<string,string> $labels   setting key => label (visible information)
 * @var bool $saved
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Employee Profile</h1>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Employee Profile settings saved.</p></div><?php endif; ?>
<p>Control which work information employees can see when viewing another employee profile. Permissions still apply: disabling this feature does not grant access to anyone.</p>
<form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews31_profile_settings_save'); ?><input type="hidden" name="action" value="ews31_profile_settings_save">
    <table class="form-table" role="presentation"><tbody>
        <tr><th scope="row">Employee Profiles</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($cfg['enabled'])); ?>> Allow employees with the View People permission to open other employee profiles.</label></td></tr>
        <tr><th scope="row">Visible Information</th><td>
            <?php foreach ($labels as $key => $label): ?><label style="display:block;margin:0 0 9px"><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="1" <?php checked(!empty($cfg[$key])); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?>
            <p class="description">Sensitive operational data such as attendance, leave balance, salary, login information and employee settings is never exposed by the employee profile view.</p>
        </td></tr>
    </tbody></table>
    <p><button type="submit" class="button button-primary">Save Employee Profile Settings</button></p>
</form></div>
