<?php
/**
 * wp-admin "Face Reset Requests" page.
 *
 * @var array<int,array{employee_id:int,name:string,domain:string,requested_by:string,requested_at:string,enrolled:bool}> $pending
 * @var array<int,array{name:string,status:string,requested_at:string,handled_at:string,handled_by:string}> $history
 * @var string|null $notice
 * @var bool $notice_is_error
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Face Reset Requests</h1>
<?php if ($notice): ?>
    <div class="notice <?php echo $notice_is_error ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
<?php endif; ?>
<p style="max-width:900px;color:#50575e">Employees cannot replace an enrolled face themselves. They can request a reset here, and the configured approval workflow must approve it before the current face template is removed.</p>

<?php if (!$pending): ?>
    <div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;padding:24px;max-width:1100px"><h2 style="margin-top:0">No pending requests</h2><p style="color:#646970">There are currently no Face Reset Requests waiting for approval.</p></div>
<?php else: ?>
    <div style="margin-top:18px;background:#fff;border:1px solid #dcdcde;max-width:1100px"><table class="widefat striped">
        <thead><tr><th>Employee</th><th>Requested By</th><th>Requested At</th><th>Current Face</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($pending as $r): $eid = (int) $r['employee_id']; ?>
            <tr>
                <td><strong><?php echo esc_html($r['name']); ?></strong><br><span style="color:#646970"><?php echo esc_html($r['domain']); ?></span></td>
                <td><?php echo esc_html($r['requested_by']); ?></td>
                <td><?php echo esc_html($r['requested_at']); ?></td>
                <td><?php echo $r['enrolled'] ? '<span style="color:#008a20;font-weight:700">Enrolled</span>' : '<span style="color:#b32d2e">Not enrolled</span>'; ?></td>
                <td><div style="display:flex;gap:8px;align-items:center">
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_face_reset_approve_' . $eid); ?><input type="hidden" name="action" value="ews31_face_reset_approve"><input type="hidden" name="employee_id" value="<?php echo $eid; ?>"><button class="button button-primary" type="submit">Approve Reset</button></form>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_face_reset_reject_' . $eid); ?><input type="hidden" name="action" value="ews31_face_reset_reject"><input type="hidden" name="employee_id" value="<?php echo $eid; ?>"><button class="button" type="submit">Reject</button></form>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<?php if ($history): ?>
    <h2 style="margin-top:30px">Request History</h2>
    <div style="background:#fff;border:1px solid #dcdcde;max-width:1100px"><table class="widefat striped">
        <thead><tr><th>Employee</th><th>Status</th><th>Requested At</th><th>Handled At</th><th>Handled By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $r): ?>
            <tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($r['status']); ?></td><td><?php echo esc_html($r['requested_at']); ?></td><td><?php echo esc_html($r['handled_at']); ?></td><td><?php echo esc_html($r['handled_by']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>
</div>
