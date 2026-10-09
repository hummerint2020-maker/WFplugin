<?php
/**
 * wp-admin → Work Locations: add / edit a location, each employee's assigned location, and the
 * list of locations. Script: assets/js/admin-locations.js (confirms Archive).
 *
 * @var object|null $edit                the location being edited
 * @var object[] $rows                   all locations, active first
 * @var array<string,mixed> $form        the form's values
 * @var string $branches_html            the Employee Branches list (templates/admin/employee-branches.php, 3.31.72)
 * @var object[] $active_locations       active locations (id, name) for assignment
 * @var string $notice                   confirmation after a save / archive ('' = none)
 * @var int $warn_pct                    Location Capacity: a day is near capacity from this occupancy
 */
if (!defined('ABSPATH')) exit;
$post = admin_url('admin-post.php');
?>
<div class="wrap"><h1>Work Locations</h1>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

<div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:760px"><h2><?php echo $edit ? 'Edit Location' : 'Add Location'; ?></h2>
<form method="post" action="<?php echo esc_url($post); ?>">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_multi_location_save_v321')); ?>">
    <input type="hidden" name="action" value="ews_multi_location_save_v321"><?php if ($edit): ?><input type="hidden" name="location_id" value="<?php echo (int) $edit->id; ?>"><?php endif; ?>
    <table class="form-table">
        <tr><th>Location Name</th><td><input class="regular-text" name="location_name" required value="<?php echo esc_attr($form['name']); ?>"></td></tr>
        <tr><th>Latitude</th><td><input class="regular-text" type="number" step="0.0000001" name="location_latitude" required value="<?php echo esc_attr($form['lat']); ?>"></td></tr>
        <tr><th>Longitude</th><td><input class="regular-text" type="number" step="0.0000001" name="location_longitude" required value="<?php echo esc_attr($form['lng']); ?>"></td></tr>
        <tr><th>Allowed Radius</th><td><input type="number" min="10" max="5000" name="location_radius" value="<?php echo (int) $form['radius']; ?>"> meters</td></tr>
        <tr><th>Seats</th><td><input type="number" min="0" max="100000" name="location_seats" value="<?php echo esc_attr((string) $form['seats']); ?>" placeholder="No limit"><p class="description">How many people this location holds. Reports → Location Capacity warns when the plan reaches 90% and when it goes over. Leave empty for no limit.</p></td></tr>
        <tr><th>Location Enforcement</th><td><label><input type="checkbox" name="location_enforcement" value="1" <?php checked($form['enforcement'], 1); ?>> Require attendance to be inside this location</label></td></tr>
        <tr><th>Default Location</th><td><label><input type="checkbox" name="location_default" value="1" <?php checked($form['is_default'], 1); ?>> Use this as the system default location</label><p class="description">The first location added is automatically set as the default. Only one location can be the default; employees without an assigned location use it.</p></td></tr>
        <tr><th>Status</th><td><label><input type="checkbox" name="active" value="1" <?php checked($form['active'], 1); ?>> Active</label></td></tr>
    </table>
    <p><button class="button button-primary"><?php echo $edit ? 'Save Changes' : 'Add Location'; ?></button>
    <?php if ($edit): ?><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-multi-locations')); ?>">Cancel</a><?php endif; ?></p>
</form></div>

<form method="post" action="<?php echo esc_url($post); ?>" style="background:#fff;border:1px solid #dcdcde;padding:14px 20px;max-width:760px;margin-top:16px">
    <input type="hidden" name="action" value="ews_capacity_settings_save"><?php wp_nonce_field('ews_capacity_settings_save'); ?>
    <label><strong>Location Capacity report:</strong> a day is "near capacity" from <input type="number" name="capacity_warn_percent" min="50" max="100" step="1" value="<?php echo (int) $warn_pct; ?>" style="width:70px">% of the seats</label>
    <button class="button">Save</button>
</form>

<h2 style="margin-top:30px" id="ews-employee-branches">Employee Branches</h2>
<?php echo $branches_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in templates/admin/employee-branches.php ?>

<h2 style="margin-top:30px">Configured Locations</h2>
<table class="widefat striped"><thead><tr><th>Name</th><th>Coordinates</th><th>Radius</th><th>Seats</th><th>Enforcement</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">No locations configured.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?>
    <tr><td><strong><?php echo esc_html($r->name); ?></strong><?php if ($r->is_default && $r->active): ?><br><span style="color:#2563eb;font-weight:600">Default</span><?php endif; ?></td><td><?php echo esc_html($r->latitude . ', ' . $r->longitude); ?></td><td><?php echo (int) $r->radius; ?> m</td><td><?php echo !empty($r->seats) ? (int) $r->seats . ' seats' : 'No limit'; ?></td><td><?php echo $r->enforcement ? 'Enabled' : 'Disabled'; ?></td><td><?php echo $r->active ? 'Active' : 'Archived'; ?></td><td><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ews31-multi-locations&edit_location=' . (int) $r->id)); ?>">Edit</a>
        <?php if ($r->active): ?><form method="post" action="<?php echo esc_url($post); ?>" style="display:inline" data-ews-confirm="Archive this location?">
            <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce('ews_multi_location_archive_v321')); ?>">
            <input type="hidden" name="action" value="ews_multi_location_archive_v321"><input type="hidden" name="location_id" value="<?php echo (int) $r->id; ?>"><button class="button button-small">Archive</button></form><?php endif; ?>
    </td></tr>
<?php endforeach; ?>
</tbody></table></div>
