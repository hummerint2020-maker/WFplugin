<?php
/**
 * Employee Branches (3.31.72): each employee's main branch and other branches. Shown on Work Locations
 * and, for a manager with "Manage Employee Branches", on its own page (their department only). 50 a
 * page with a search; each row is one form (admin_employee_location_save_v321).
 *
 * @var object[] $rows                 this page: id, name, domain_name, location_id (main, null = default)
 * @var array<int,int[]> $others       other branch ids per employee
 * @var array<int,object> $choices     branches this user may give
 * @var array<int,object> $locations   every active branch
 * @var string $default_name           the default location (an employee without a main branch uses it)
 * @var string $search
 * @var int $paged
 * @var int $pages
 * @var int $total
 * @var string $mode                   Settings\BranchSettings mode
 * @var string $page_url
 * @var string $page_slug
 * @var string $post_url
 * @var string $features_url
 */
if (!defined('ABSPATH')) exit;
$modes = ['single' => 'One branch: other branches are kept but not used until the mode changes.', 'any' => 'Any of their branches: an employee can sign in at their main branch or any other branch below.', 'schedule' => 'By the schedule: the Attendance planner can plan a day at any of these branches.'];
$link = static function (int $n) use ($search, $page_url) { return esc_url(add_query_arg(array_filter(['paged' => $n > 1 ? $n : null, 's' => $search !== '' ? $search : null]), $page_url) . '#ews-employee-branches'); };
$nonce = wp_create_nonce('ews_employee_location_save_v321');
?>
<p>Mode: <strong><?php echo esc_html($modes[$mode] ?? $mode); ?></strong> <a href="<?php echo esc_url($features_url); ?>">Change</a></p>
<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:0 0 10px">
    <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Name or domain name" aria-label="Search employees">
    <button class="button">Search</button><?php if ($search !== ''): ?> <a class="button-link" href="<?php echo esc_url($page_url . '#ews-employee-branches'); ?>">Clear</a><?php endif; ?>
    <span style="margin-inline-start:auto"><?php echo esc_html(sprintf('%d employees', $total)); ?><?php if ($pages > 1): ?> · <?php echo esc_html(sprintf('Page %1$d of %2$d', $paged, $pages)); ?>
        <?php if ($paged > 1): ?><a class="button" href="<?php echo $link($paged - 1); ?>">&lsaquo; Previous</a><?php endif; ?>
        <?php if ($paged < $pages): ?><a class="button" href="<?php echo $link($paged + 1); ?>">Next &rsaquo;</a><?php endif; ?><?php endif; ?></span>
</form>
<table class="widefat striped"><thead><tr><th>Employee</th><th>Domain</th><th>Main Branch</th><th>Other Branches</th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="5"><?php echo $search !== '' ? 'No employee matches this search.' : 'No active employees configured.'; ?></td></tr><?php endif; ?>
<?php foreach ($rows as $emp): $eid = (int) $emp->id; $f = 'ews-branches-' . $eid; $main = (int) $emp->location_id; $mine = $others[$eid] ?? []; ?>
    <tr>
        <td><?php echo esc_html($emp->name); ?></td><td><?php echo esc_html($emp->domain_name); ?></td>
        <td><select form="<?php echo esc_attr($f); ?>" name="location_id" aria-label="<?php echo esc_attr('Main branch of ' . $emp->name); ?>">
            <option value="0"><?php echo esc_html('-- Default location' . ($default_name !== '' ? ' (' . $default_name . ')' : '') . ' --'); ?></option>
            <?php foreach ($locations as $lid => $loc): if (!isset($choices[$lid]) && $lid !== $main) continue; ?><option value="<?php echo (int) $lid; ?>" <?php selected($main, (int) $lid); ?><?php echo isset($choices[$lid]) ? '' : ' disabled'; ?>><?php echo esc_html($loc->name); ?></option><?php endforeach; ?>
        </select></td>
        <td><select form="<?php echo esc_attr($f); ?>" name="other_location_ids[]" multiple size="<?php echo (int) min(4, max(2, count($choices))); ?>" style="min-width:180px" aria-label="<?php echo esc_attr('Other branches of ' . $emp->name); ?>">
            <?php foreach ($choices as $lid => $loc): ?><option value="<?php echo (int) $lid; ?>" <?php echo in_array((int) $lid, $mine, true) ? 'selected' : ''; ?>><?php echo esc_html($loc->name); ?></option><?php endforeach; ?>
        </select>
        <?php $hidden = array_diff($mine, array_keys($choices)); if ($hidden): ?><br><small>Also: <?php echo esc_html(implode(', ', array_map(static function ($id) use ($locations) { return isset($locations[$id]) ? $locations[$id]->name : '#' . $id; }, $hidden))); ?></small><?php endif; ?></td>
        <td><form id="<?php echo esc_attr($f); ?>" method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>"><input type="hidden" name="action" value="ews_employee_location_save_v321"><input type="hidden" name="employee_id" value="<?php echo $eid; ?>"><input type="hidden" name="branches_form" value="1"><button class="button button-small">Save</button></form></td>
    </tr>
<?php endforeach; ?>
</tbody></table>
<p class="description">Hold Ctrl (Cmd on a Mac) to pick more than one branch. An employee without a main branch uses the default location.</p>
