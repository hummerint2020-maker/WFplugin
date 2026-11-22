<?php
/**
 * The Requests Hub's Reports tab (3.31.159, docs/tasks/hub-reports.md): every request in every status, view only.
 *
 * @var list<array{key:string,label:string,url:string,count:int,current:bool}> $tabs
 * @var array{from:string,to:string,type:string,types:list<string>,status:int,who:string,employee:int,department:int,page:int} $filter
 * @var string $status_key
 * @var array<string,int> $tiles
 * @var list<array{type:string,title:string,employee:string,detail:string,status:int,by:string,at:string,note:string,requested:string}> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var callable $page_url
 * @var callable $tile_url
 * @var array{csv:string,xlsx:string} $export
 * @var array<string,string> $types
 * @var array<string,string> $statuses
 * @var array<int,string> $departments
 * @var list<object> $people
 * @var string $form_url
 */
if (!defined('ABSPATH')) exit;
$keys = \WorkforceOne\Requests\HubReport::STATUSES;
$tile_labels = ['all' => __('requests', 'workforce-one'), 'approved' => __('approved', 'workforce-one'), 'rejected' => __('rejected', 'workforce-one'),
    'withdrawn' => __('withdrawn', 'workforce-one'), 'cancelled' => __('cancelled', 'workforce-one'), 'waiting' => __('waiting', 'workforce-one')];
?>
<div class="wrap ews-hr"><h1><?php esc_html_e('Requests Hub', 'workforce-one'); ?></h1>
<p class="ews-hr-lead"><?php esc_html_e('Every Workforce One request in every status: who decided it and when. View only: requests are decided on the first tab and in My approvals.', 'workforce-one'); ?></p>
<?php include __DIR__ . '/requests-tabs.php'; ?>
<form class="ews-hr-filters" method="get" action="<?php echo esc_url($form_url); ?>">
    <input type="hidden" name="page" value="ews31-requests"><input type="hidden" name="tab" value="reports">
    <label class="ews-hr-f"><span><?php esc_html_e('From', 'workforce-one'); ?></span><input type="date" name="from" value="<?php echo esc_attr($filter['from']); ?>"></label>
    <label class="ews-hr-f"><span><?php esc_html_e('To', 'workforce-one'); ?></span><input type="date" name="to" value="<?php echo esc_attr($filter['to']); ?>"></label>
    <label class="ews-hr-f"><span><?php esc_html_e('Type', 'workforce-one'); ?></span><select name="type"><option value=""><?php esc_html_e('All types', 'workforce-one'); ?></option>
        <?php foreach ($types as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($filter['type'], $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
    <label class="ews-hr-f"><span><?php esc_html_e('Status', 'workforce-one'); ?></span><select name="status"><option value=""><?php esc_html_e('All statuses', 'workforce-one'); ?></option>
        <?php foreach ($statuses as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($status_key, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
    <label class="ews-hr-f"><span><?php esc_html_e('Employee or department', 'workforce-one'); ?></span><select name="who"><option value=""><?php esc_html_e('Everyone', 'workforce-one'); ?></option>
        <?php if ($departments): ?><optgroup label="<?php esc_attr_e('Departments', 'workforce-one'); ?>"><?php foreach ($departments as $id => $n): ?><option value="dept:<?php echo (int) $id; ?>" <?php selected($filter['who'], 'dept:' . (int) $id); ?>><?php echo esc_html($n); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        <optgroup label="<?php esc_attr_e('Employees', 'workforce-one'); ?>"><?php foreach ($people as $p): ?><option value="emp:<?php echo (int) $p->id; ?>" <?php selected($filter['who'], 'emp:' . (int) $p->id); ?>><?php echo esc_html((int) $p->active ? (string) $p->name : sprintf(__('%s (archived)', 'workforce-one'), (string) $p->name)); ?></option><?php endforeach; ?></optgroup></select></label>
    <div class="ews-hr-go"><button class="button" type="submit"><?php esc_html_e('Show', 'workforce-one'); ?></button></div>
    <div class="ews-hr-export"><a class="button" href="<?php echo esc_url($export['csv']); ?>"><?php esc_html_e('Export CSV', 'workforce-one'); ?></a> <a class="button" href="<?php echo esc_url($export['xlsx']); ?>"><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
</form>
<ul class="ews-hr-sum">
<?php foreach (['all', 'approved', 'rejected', 'withdrawn', 'cancelled', 'waiting'] as $k): ?>
    <li class="is-<?php echo esc_attr($k); ?><?php echo $status_key === $k ? ' is-current' : ''; ?>"><a href="<?php echo esc_url($tile_url($k)); ?>"><b><?php echo esc_html(number_format_i18n((int) $tiles[$k])); ?></b><span><?php echo esc_html($tile_labels[$k]); ?></span></a></li>
<?php endforeach; ?>
</ul>
<?php if (!$rows): ?>
<div class="ews-hr-empty"><h2><?php esc_html_e('No requests in this period', 'workforce-one'); ?></h2><p><?php esc_html_e('Change the period or the filters.', 'workforce-one'); ?></p></div>
<?php else: ?>
<div class="ews-hr-table"><table class="wp-list-table widefat striped">
    <thead><tr><th><?php esc_html_e('Request', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Details', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th><?php esc_html_e('Requested', 'workforce-one'); ?></th><th><?php esc_html_e('Decided', 'workforce-one'); ?></th><th><?php esc_html_e('Note', 'workforce-one'); ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $sk = $keys[$r['status']] ?? 'cancelled'; ?>
        <tr data-type="<?php echo esc_attr($r['type']); ?>" data-status="<?php echo esc_attr($sk); ?>">
            <td><strong><?php echo esc_html($r['title']); ?></strong></td>
            <td><?php echo esc_html($r['employee']); ?></td>
            <td><?php echo esc_html($r['detail']); ?></td>
            <td><span class="ews-hr-st is-<?php echo esc_attr($sk); ?>"><?php echo esc_html($statuses[$sk] ?? ''); ?></span></td>
            <td><?php echo esc_html($r['requested']); ?></td>
            <td><?php if ($r['by'] !== '' || $r['at'] !== ''): ?><span class="ews-hr-by"><?php echo esc_html($r['by'] !== '' ? $r['by'] : '—'); ?></span><?php if ($r['at'] !== ''): ?><small><?php echo esc_html($r['at']); ?></small><?php endif; ?><?php else: ?><span class="ews-hr-none">—</span><?php endif; ?></td>
            <td><?php echo $r['note'] !== '' ? esc_html($r['note']) : '<span class="ews-hr-none">—</span>'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num"><?php echo esc_html(sprintf(_n('%s request', '%s requests', $total, 'workforce-one'), number_format_i18n($total))); ?></span>
<?php if ($pages > 1): ?><span class="pagination-links">
    <?php if ($page > 1): ?><a class="button" href="<?php echo esc_url($page_url($page - 1)); ?>" aria-label="<?php esc_attr_e('Newer', 'workforce-one'); ?>">‹</a><?php else: ?><span class="button disabled" aria-hidden="true">‹</span><?php endif; ?>
    <span class="paging-input"><?php echo esc_html(sprintf(__('%1$s of %2$s', 'workforce-one'), number_format_i18n($page), number_format_i18n($pages))); ?></span>
    <?php if ($page < $pages): ?><a class="button" href="<?php echo esc_url($page_url($page + 1)); ?>" aria-label="<?php esc_attr_e('Older', 'workforce-one'); ?>">›</a><?php else: ?><span class="button disabled" aria-hidden="true">›</span><?php endif; ?>
</span><?php endif; ?></div></div>
<?php endif; ?>
<p class="description"><?php esc_html_e('Newest first, by the date the request was made. The export has every page of what the filters show.', 'workforce-one'); ?></p>
</div>
