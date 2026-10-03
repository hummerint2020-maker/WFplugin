<?php
/**
 * wp-admin "Recognition" (Kudos moderation).
 *
 * @var array<int,array{id:int,date:string,from:string,to:string,category:string,message:string,status:string,delete_url:string}> $kudos
 * @var bool $deleted
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Recognition</h1>
<?php if ($deleted): ?><div class="notice notice-success is-dismissible"><p>Kudos removed.</p></div><?php endif; ?>
<p>Review and remove employee Kudos. Recognition is appreciation only and is not a performance score.</p>
<table class="widefat striped"><thead><tr><th>Date</th><th>From</th><th>To</th><th>Category</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>
<?php if (!$kudos): ?><tr><td colspan="7">No Kudos yet.</td></tr><?php endif; ?>
<?php foreach ($kudos as $k): ?>
    <tr><td><?php echo esc_html($k['date']); ?></td><td><?php echo esc_html($k['from']); ?></td><td><?php echo esc_html($k['to']); ?></td><td><?php echo esc_html($k['category']); ?></td><td><?php echo esc_html($k['message']); ?></td><td><?php echo esc_html($k['status']); ?></td>
        <td><?php if ($k['delete_url']): ?><a href="<?php echo esc_url($k['delete_url']); ?>" onclick="return confirm('Remove this Kudos?')">Delete</a><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
