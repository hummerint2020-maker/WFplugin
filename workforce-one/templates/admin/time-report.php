<?php
/**
 * wp-admin "Sign In / Out Report".
 *
 * @var string $start
 * @var string $end
 * @var array<int,array<string,mixed>> $records   this page (100) of the period's records
 * @var string $search
 * @var int $paged
 * @var int $pages
 * @var int $total
 * @var array<string,mixed>|null $form           add/edit form values (id 0 = new record)
 * @var array<int,object> $employees
 * @var array<string,string> $event_types
 * @var bool $saved
 * @var array{0:int,1:int}|null $reset           deleted [records, breaks]
 * @var string|null $error
 * @var string $page_url
 * @var string $post_url
 * @var string $csv_url
 */
if (!defined('ABSPATH')) exit;
$range_url = add_query_arg(['start' => $start, 'end' => $end], $page_url);
$integrity_style = ['verified' => 'color:#008a20;font-weight:600', 'suspicious' => 'color:#b32d2e;font-weight:600', 'unreliable' => 'color:#996800;font-weight:600'];
?>
<div class="wrap"><h1>Sign In / Out Report</h1>
<?php if ($reset): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('Attendance day reset successfully. Deleted %d attendance record(s) and %d break session(s).', $reset[0], $reset[1])); ?></p></div><?php endif; ?>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Attendance record saved successfully.</p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<p><a class="button button-primary" href="<?php echo esc_url(add_query_arg('add_time', 1, $range_url)); ?>">+ Add Manual Record</a></p>

<?php if ($form): $is_edit = $form['id'] > 0; ?>
<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:15px 0"><h2><?php echo $is_edit ? 'Edit Attendance Record' : 'Add Manual Attendance Record'; ?></h2>
    <form method="post" action="<?php echo esc_url($post_url); ?>">
        <?php wp_nonce_field('ews31_time_save'); ?>
        <input type="hidden" name="action" value="ews31_time_save">
        <?php if ($is_edit): ?><input type="hidden" name="time_id" value="<?php echo (int) $form['id']; ?>"><?php endif; ?>
        <table class="form-table">
            <tr><th>Employee</th><td><select name="employee_id" required><option value="">Select employee</option>
                <?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>" <?php selected((int) $form['employee_id'], (int) $e->id); ?>><?php echo esc_html($e->name . ' — ' . $e->domain_name); ?></option><?php endforeach; ?>
            </select></td></tr>
            <tr><th>Work Date</th><td><input type="date" name="work_date" value="<?php echo esc_attr($form['work_date']); ?>" required></td></tr>
            <tr><th>Event</th><td><select name="event_type">
                <?php foreach ($event_types as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($form['event_type'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select></td></tr>
            <tr><th>Date &amp; Time</th><td><input type="datetime-local" name="event_at" value="<?php echo esc_attr($form['event_at']); ?>" required></td></tr>
            <tr><th>Location (optional)</th><td><input type="text" name="latitude" placeholder="Latitude" value="<?php echo esc_attr($form['latitude']); ?>"> <input type="text" name="longitude" placeholder="Longitude" value="<?php echo esc_attr($form['longitude']); ?>"> <input type="number" step="0.01" name="accuracy" placeholder="Accuracy (m)" value="<?php echo esc_attr($form['accuracy']); ?>"><p class="description">Leave blank for a manual record without GPS data. Coordinates are checked against the employee's Work Location.</p></td></tr>
        </table>
        <p><button class="button button-primary"><?php echo $is_edit ? 'Save Changes' : 'Add Record'; ?></button> <a class="button" href="<?php echo esc_url($range_url); ?>">Cancel</a></p>
    </form>
</div>
<?php endif; ?>

<form method="get"><input type="hidden" name="page" value="ews31-time-report">
    <label>From <input type="date" name="start" value="<?php echo esc_attr($start); ?>"></label> <label>To <input type="date" name="end" value="<?php echo esc_attr($end); ?>"></label>
    <label>Employee <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Name or domain name"></label>
    <button class="button button-primary">View Report</button> <a class="button" href="<?php echo esc_url($csv_url); ?>">Download CSV</a>
</form>
<?php
$time_pager = static function () use ($paged, $pages, $total, $start, $end, $search, $page_url) {
    $link = static function (int $n) use ($start, $end, $search, $page_url) { return esc_url(add_query_arg(array_filter(['start' => $start, 'end' => $end, 's' => $search !== '' ? $search : null, 'paged' => $n > 1 ? $n : null]), $page_url)); };
    echo '<p class="ews-time-pager" style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin:10px 0">' . esc_html(sprintf('%d records', $total));
    if ($pages > 1) {
        echo ' · ' . esc_html(sprintf('Page %1$d of %2$d', $paged, $pages));
        if ($paged > 1) echo ' <a class="button" href="' . $link($paged - 1) . '">&lsaquo; Previous</a>';
        if ($paged < $pages) echo ' <a class="button" href="' . $link($paged + 1) . '">Next &rsaquo;</a>';
    }
    echo '</p>';
};
$time_pager();
$reset_nonce = wp_create_nonce('ews31_time_reset');
?>
<table class="widefat striped"><thead><tr><th>Date</th><th>Employee</th><th>Domain</th><th>Scheduled Status</th><th>Event</th><th>Attendance Status</th><th>Time</th><th>Location</th><th>Distance</th><th>Radius</th><th>Result</th><th>Accuracy</th><th>Integrity</th><th>Source</th><th>Admin Action</th></tr></thead><tbody>
<?php foreach ($records as $r): ?>
    <tr>
        <td><?php echo esc_html($r['work_date']); ?></td><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($r['domain']); ?></td>
        <td><?php echo esc_html($r['scheduled']); ?></td><td><?php echo esc_html($r['event']); ?></td><td><strong><?php echo esc_html($r['status']); ?></strong></td>
        <td><?php echo esc_html($r['time']); ?></td><td><?php echo esc_html($r['location']); ?></td><td><?php echo esc_html($r['distance']); ?></td><td><?php echo esc_html($r['radius']); ?></td>
        <td><?php echo esc_html($r['result']); ?></td><td><?php echo esc_html($r['accuracy']); ?></td>
        <td><span style="<?php echo esc_attr($integrity_style[$r['integrity_key']] ?? ''); ?>"><?php echo esc_html($r['integrity']); ?></span><?php if ($r['integrity_reason']): ?><br><small><?php echo esc_html($r['integrity_reason']); ?></small><?php endif; ?></td>
        <td><?php echo esc_html($r['source']); ?><?php if ($r['correction_id']): ?><br><small>#<?php echo (int) $r['correction_id']; ?></small><?php endif; ?><?php if ($r['replaced_by']): ?><br><small style="color:#b32d2e"><?php echo esc_html('Replaced by correction #' . $r['replaced_by'] . ' (kept)'); ?></small><?php endif; ?></td>
        <td>
            <a class="button button-small" href="<?php echo esc_url(add_query_arg('edit_time_id', $r['id'], $range_url)); ?>">Edit</a>
            <form method="post" action="<?php echo esc_url($post_url); ?>" data-ews-confirm-key="attendance_reset" style="display:inline"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($reset_nonce); ?>"><input type="hidden" name="action" value="ews31_time_reset"><input type="hidden" name="employee_id" value="<?php echo (int) $r['employee_id']; ?>"><input type="hidden" name="work_date" value="<?php echo esc_attr($r['work_date']); ?>"><button class="button button-small">Reset Day</button></form>
        </td>
    </tr>
<?php endforeach; ?>
<?php if (!$records): ?><tr><td colspan="15"><?php echo $search !== '' ? 'No record matches this search.' : 'No records in this period.'; ?></td></tr><?php endif; ?>
</tbody></table>
<?php $time_pager(); ?>
<p><em>Reset Day removes all time events for the selected employee and date, allowing the employee to sign in again.</em></p>
</div>
