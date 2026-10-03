<?php
/**
 * wp-admin "Roles & Permissions".
 *
 * @var array<string,array{name:string}> $roles        role slug => definition
 * @var array<string,string> $permissions               capability => label
 * @var array<string,array<string,bool>> $matrix        role slug => capability => on
 * @var bool $saved
 * @var string|null $error
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Roles &amp; Permissions</h1>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Roles and permissions saved successfully.</p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<p>Control what each Employee Schedule role can access. WordPress Administrators always retain full access.</p>
<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 14px;margin:14px 0;max-width:1100px"><strong>Permission model:</strong> <em>View Dashboard</em> only controls whether the user gets the management dashboard or the personal user dashboard. All other permissions are independent. If a user does not have a module permission, that module is hidden from the Workforce One frontend and protected server-side as well. A user with several roles gets every permission any of their roles has.</div>
<form method="post" action="<?php echo esc_url($post_url); ?>">
    <?php wp_nonce_field('ews31_roles_save'); ?><input type="hidden" name="action" value="ews31_roles_save">
    <div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Permission</th>
        <?php foreach ($roles as $def): ?><th style="text-align:center"><?php echo esc_html($def['name']); ?></th><?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($permissions as $cap => $label): ?>
        <tr><td><strong><?php echo esc_html($label); ?></strong><br><code><?php echo esc_html($cap); ?></code></td>
        <?php foreach ($roles as $slug => $def): ?>
            <td style="text-align:center"><input type="checkbox" name="roles[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($cap); ?>]" value="1" <?php checked(!empty($matrix[$slug][$cap])); ?>></td>
        <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p><button class="button button-primary">Save Permissions</button></p>
</form>
<div style="background:#fff;border:1px solid #ddd;padding:15px;margin-top:20px"><h2>Role Assignment</h2><p>Assign the EWS roles to WordPress users from <strong>Users → All Users</strong>. Employees should normally use <strong>EWS Employee</strong>; managers/supervisors can be assigned the corresponding EWS role.</p></div>
</div>
