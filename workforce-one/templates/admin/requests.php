<?php
/**
 * wp-admin "Requests Hub" page.
 *
 * @var array<int,array{type:string,id:int,title:string,employee:string,detail:string,approval:string,requested_at:string,nonce:string}> $rows
 * @var string|null $notice
 * @var bool $notice_is_error
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Requests Hub</h1>
<p style="max-width:980px;color:#50575e">Central request center for pending Workforce One requests. Administrators can review, approve or reject requests from one place.</p>
<?php if ($notice): ?>
    <div class="notice <?php echo $notice_is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endif; ?>
<?php if (!$rows): ?>
    <div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;padding:24px;max-width:1150px"><h2 style="margin-top:0">No pending requests</h2><p style="color:#646970">There are currently no requests waiting for action.</p></div>
<?php else: ?>
    <div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;max-width:1200px"><table class="widefat striped">
        <thead><tr><th>Request</th><th>Employee</th><th>Details</th><th>Approval</th><th>Requested</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?php echo esc_html($r['title']); ?></strong></td>
                <td><?php echo esc_html($r['employee']); ?></td>
                <td><?php echo esc_html($r['detail']); ?></td>
                <td><?php echo esc_html($r['approval']); ?></td>
                <td><?php echo esc_html($r['requested_at']); ?></td>
                <td><div style="display:flex;gap:7px;flex-wrap:wrap">
                <?php foreach (['approve' => ['Approve', 'button button-primary'], 'reject' => ['Reject', 'button']] as $decision => [$label, $class]): ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews31_requests_decision"><input type="hidden" name="request_type" value="<?php echo esc_attr($r['type']); ?>"><input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>"><input type="hidden" name="decision" value="<?php echo esc_attr($decision); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($r['nonce']); ?>"><button class="<?php echo esc_attr($class); ?>" type="submit"><?php echo esc_html($label); ?></button></form>
                <?php endforeach; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
