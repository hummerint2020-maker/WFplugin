<?php
/**
 * wp-admin "Employee Moments".
 *
 * @var array<int,object> $employees     active employees (id, name, domain_name)
 * @var array<int,array{birthday:string,join_date:string}> $saved
 * @var bool $enabled
 * @var int $configured
 * @var string|null $notice
 * @var string|null $error
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Employee Moments</h1><p>Give employees a small celebration for birthdays, work anniversaries, and recent joiners. Dates are stored as Workforce One employee settings and are not tied to the WordPress user profile.</p>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<form method="post" action="<?php echo esc_url($post_url); ?>">
    <?php wp_nonce_field('ews31_employee_moments_save'); ?><input type="hidden" name="action" value="ews31_employee_moments_save">
    <div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;margin:18px 0;max-width:1100px"><label style="display:flex;gap:10px;align-items:center"><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> <strong>Enable Employee Moments</strong></label><p style="margin:8px 0 0;color:#646970">When enabled, Workforce One shows a small Moments card when an employee birthday, work anniversary, or recent joiner celebration applies today.</p></div>
    <table class="widefat striped" style="max-width:1100px"><thead><tr><th>Employee</th><th>Birthday</th><th>Joining Date</th></tr></thead><tbody>
    <?php foreach ($employees as $e): $id = (int) $e->id; $row = $saved[$id] ?? ['birthday' => '', 'join_date' => '']; ?>
        <tr><td><strong><?php echo esc_html($e->name); ?></strong><br><span style="color:#646970"><?php echo esc_html($e->domain_name); ?></span></td>
            <td><input type="date" name="moments[<?php echo $id; ?>][birthday]" value="<?php echo esc_attr($row['birthday']); ?>" style="max-width:180px"><br><small>Month/day are used each year (29 Feb is celebrated on 28 Feb in other years).</small></td>
            <td><input type="date" name="moments[<?php echo $id; ?>][join_date]" value="<?php echo esc_attr($row['join_date']); ?>" style="max-width:180px"><br><small>Used for work anniversaries and recent-joiner greetings.</small></td></tr>
    <?php endforeach; ?>
    <?php if (!$employees): ?><tr><td colspan="3">No active employees found.</td></tr><?php endif; ?>
    </tbody></table>
    <p style="margin-top:16px"><button type="submit" class="button button-primary">Save Moments</button> <span style="margin-left:10px;color:#646970"><?php echo (int) $configured; ?> employee(s) have moment dates configured.</span></p>
</form></div>
