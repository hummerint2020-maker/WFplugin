<?php
/**
 * wp-admin "Audit Log".
 *
 * @var string $from
 * @var string $to
 * @var int $user_id
 * @var string $action
 * @var int $total
 * @var array{page:int,pages:int,offset:int,first:int,last:int} $paging
 * @var array<int,array{label:string,url:string,current:bool}> $page_links
 * @var array<int,object> $users
 * @var string[] $actions
 * @var array<int,array<string,mixed>> $rows
 * @var string $reset_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap"><h1>Audit Log</h1>
<p>Use the filters below to find logs by date, user or action.</p>
<form method="get" style="background:#fff;border:1px solid #dcdcde;padding:14px;margin:15px 0"><input type="hidden" name="page" value="ews31-audit">
    <label style="margin-right:12px"><strong>From:</strong> <input type="date" name="audit_from" value="<?php echo esc_attr($from); ?>"></label>
    <label style="margin-right:12px"><strong>To:</strong> <input type="date" name="audit_to" value="<?php echo esc_attr($to); ?>"></label>
    <label style="margin-right:12px"><strong>User:</strong> <select name="audit_user"><option value="0">All Users</option><?php foreach ($users as $u): ?><option value="<?php echo (int) $u->ID; ?>" <?php selected($user_id, (int) $u->ID); ?>><?php echo esc_html($u->user_login); ?></option><?php endforeach; ?></select></label>
    <label style="margin-right:12px"><strong>Action:</strong> <select name="audit_action"><option value="">All Actions</option><?php foreach ($actions as $a): ?><option value="<?php echo esc_attr($a); ?>" <?php selected($action, $a); ?>><?php echo esc_html($a); ?></option><?php endforeach; ?></select></label>
    <button class="button button-primary">Search Logs</button> <a class="button" href="<?php echo esc_url($reset_url); ?>">Reset Filters</a>
</form>
<p><strong><?php echo esc_html(number_format_i18n($total)); ?></strong> log(s) found. Showing <?php echo (int) $paging['first']; ?>–<?php echo (int) $paging['last']; ?> on page <?php echo (int) $paging['page']; ?> of <?php echo (int) $paging['pages']; ?>.</p>
<?php if ($page_links): ?><div class="tablenav top"><div class="tablenav-pages"><?php foreach ($page_links as $l): ?><a class="button<?php echo $l['current'] ? ' button-primary' : ''; ?>" style="margin-left:4px" href="<?php echo esc_url($l['url']); ?>"><?php echo esc_html($l['label']); ?></a><?php endforeach; ?></div></div><?php endif; ?>
<table class="widefat striped"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Entity</th><th>ID</th><th>Target</th><th>Details</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">No audit logs found for the selected filters.</td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr><td><?php echo esc_html($r['date']); ?></td><td><?php echo esc_html($r['actor']); ?></td><td><?php echo esc_html($r['action']); ?></td><td><?php echo esc_html($r['entity']); ?></td><td><?php echo (int) $r['id']; ?></td><td><?php echo esc_html($r['target']); ?></td><td><?php echo esc_html($r['details']); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
