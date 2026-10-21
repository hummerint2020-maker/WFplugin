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
 * @var bool $attempts       refused attempts are kept (3.31.92): rows of kind 'attempt' may be in $records
 * @var string $show         recorded | refused | both
 * @var array{total:int,by:array<int,object>}|null $refused_summary
 * @var array{employee:object,day:string,items:array<int,array<string,string>>}|null $timeline  one employee's day
 * @var bool $photos         a photo is kept with each Face Sign In / Out
 */
if (!defined('ABSPATH')) exit;
$range_url = add_query_arg(['start' => $start, 'end' => $end], $page_url);
$cols = 16 + ($photos ? 1 : 0);
$timeline_url = static function (int $eid, string $day) use ($range_url) { return add_query_arg(['timeline' => $eid, 'tdate' => $day], $range_url) . '#ews-timeline'; };
$thumb = static function (string $src, string $full) { return $src !== '' ? '<a href="' . esc_url($full) . '" target="_blank" rel="noopener" data-photo><img src="' . esc_url($src) . '" alt="Photo" loading="lazy" style="width:34px;height:42px;object-fit:cover;border-radius:5px;display:block"></a>' : '<span style="color:#787c82">—</span>'; };
$integrity_style = ['verified' => 'color:#008a20;font-weight:600', 'suspicious' => 'color:#b32d2e;font-weight:600', 'unreliable' => 'color:#996800;font-weight:600'];
?>
<div class="wrap"><h1>Sign In / Out Report</h1>
<?php if ($reset): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf('Attendance day reset successfully. Deleted %d attendance record(s) and %d break session(s).', $reset[0], $reset[1])); ?></p></div><?php endif; ?>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Attendance record saved successfully.</p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<p><a class="button button-primary" href="<?php echo esc_url(add_query_arg('add_time', 1, $range_url)); ?>">+ Add Manual Record</a></p>

<?php if ($timeline): ?>
<div id="ews-timeline" data-timeline style="background:#fff;border:1px solid #dcdcde;padding:16px 18px;margin:15px 0;max-width:820px">
    <h2 style="margin-top:0"><?php echo esc_html($timeline['employee']->name . ' · ' . date_i18n('l j F Y', strtotime($timeline['day']))); ?></h2>
    <?php if (!$timeline['items']): ?><p>Nothing recorded or refused on this day.</p><?php endif; ?>
    <ol style="list-style:none;margin:0;padding:0">
    <?php foreach ($timeline['items'] as $it): $color = in_array($it['kind'], ['refused', 'face_failed', 'not_reached'], true) ? ($it['kind'] === 'not_reached' ? '#787c82' : '#b32d2e') : ($it['kind'] === 'log' ? '#008a20' : '#2271b1'); ?>
        <li style="display:grid;grid-template-columns:52px 14px 1fr auto;gap:10px;align-items:start;padding:6px 0;border-bottom:1px solid #f0f0f1" data-kind="<?php echo esc_attr($it['kind']); ?>">
            <span style="font-variant-numeric:tabular-nums;color:#50575e"><?php echo esc_html(date_i18n('H:i', strtotime($it['at']))); ?></span>
            <span style="width:10px;height:10px;border-radius:50%;margin-top:5px;background:<?php echo esc_attr($color); ?>"></span>
            <span><strong><?php echo esc_html($it['title']); ?></strong><?php if ($it['detail'] !== ''): ?><br><small><?php echo esc_html($it['detail']); ?></small><?php endif; ?><?php if ($it['device'] !== '' || $it['ip'] !== ''): ?><br><small style="color:#787c82"><?php echo esc_html(trim($it['device'] . ' · ' . $it['ip'], ' ·')); ?></small><?php endif; ?></span>
            <span><?php if ($it['photo'] !== ''): ?><a class="button button-small" href="<?php echo esc_url($it['photo']); ?>" target="_blank" rel="noopener">Photo</a><?php endif; ?></span>
        </li>
    <?php endforeach; ?>
    </ol>
    <p><a class="button" href="<?php echo esc_url($range_url); ?>">Close</a></p>
</div>
<?php endif; ?>

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
    <?php if ($attempts): ?><label>Show <select name="show"><option value="both" <?php selected($show, 'both'); ?>>Recorded and refused</option><option value="recorded" <?php selected($show, 'recorded'); ?>>Recorded</option><option value="refused" <?php selected($show, 'refused'); ?>>Refused attempts</option></select></label><?php endif; ?>
    <button class="button button-primary">View Report</button> <a class="button" href="<?php echo esc_url($csv_url); ?>">Download CSV</a>
</form>
<?php
$time_pager = static function () use ($paged, $pages, $total, $start, $end, $search, $page_url, $show) {
    $link = static function (int $n) use ($start, $end, $search, $page_url, $show) { return esc_url(add_query_arg(array_filter(['start' => $start, 'end' => $end, 's' => $search !== '' ? $search : null, 'show' => $show !== 'both' ? $show : null, 'paged' => $n > 1 ? $n : null]), $page_url)); };
    echo '<p class="ews-time-pager" style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin:10px 0">' . esc_html(sprintf('%d records', $total));
    if ($pages > 1) {
        echo ' · ' . esc_html(sprintf('Page %1$d of %2$d', $paged, $pages));
        if ($paged > 1) echo ' <a class="button" href="' . $link($paged - 1) . '">&lsaquo; Previous</a>';
        if ($paged < $pages) echo ' <a class="button" href="' . $link($paged + 1) . '">Next &rsaquo;</a>';
    }
    echo '</p>';
};
if ($refused_summary && $refused_summary['total'] > 0): ?>
<div class="notice notice-warning inline" data-refused-summary style="margin:10px 0"><p><strong><?php echo esc_html(sprintf('%d refused attempt(s) in this period', $refused_summary['total'])); ?></strong><?php foreach ($refused_summary['by'] as $b): ?> · <?php echo esc_html($b->name . ' ' . (int) $b->n); ?><?php endforeach; ?></p></div>
<?php endif;
$time_pager();
$reset_nonce = wp_create_nonce('ews31_time_reset');
?>
<table class="widefat striped"><thead><tr><?php if ($photos): ?><th>Photo</th><?php endif; ?><th>Date</th><th>Employee</th><th>Domain</th><th>Scheduled Status</th><th>Event</th><th>Attendance Status</th><th>Time</th><th>Location</th><th>Distance</th><th>Radius</th><th>Result</th><th>Accuracy</th><th>Integrity</th><th>IP</th><th>Source</th><th>Admin Action</th></tr></thead><tbody>
<?php foreach ($records as $r): if (($r['kind'] ?? 'log') === 'attempt'): $late = $r['late_report']; ?>
    <tr data-attempt="<?php echo esc_attr($r['result']); ?>" style="background:<?php echo $r['result'] === 'not_reached' ? '#f6f7f7' : '#fcf9e8'; ?>">
        <?php if ($photos): ?><td><?php echo $thumb($r['photo'], $r['photo_full']); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $thumb ?></td><?php endif; ?>
        <td><?php echo esc_html($r['work_date']); ?></td>
        <td><a href="<?php echo esc_url($timeline_url($r['employee_id'], $r['work_date'])); ?>"><?php echo esc_html($r['name']); ?></a></td><td><?php echo esc_html($r['domain']); ?></td>
        <td>—</td><td><?php echo esc_html($r['event']); ?></td>
        <td><span style="display:inline-block;border-radius:9px;padding:0 8px;font-size:12px;font-weight:600;background:<?php echo $r['result'] === 'not_reached' ? '#dcdcde' : '#fcf0f1'; ?>;color:<?php echo $r['result'] === 'not_reached' ? '#3c434a' : '#b32d2e'; ?>"><?php echo esc_html($r['result'] === 'not_reached' ? 'Did not reach the server' : 'Refused'); ?></span><br><small><?php echo esc_html($r['reason']); ?></small></td>
        <td><?php echo esc_html($r['time']); ?><?php if ($late): ?><br><small>Reported by the phone later<?php echo $late['reported'] !== '' ? esc_html(', ' . $late['reported']) : ''; ?></small><?php if (abs($late['skew']) > 120): ?><br><small style="color:#b32d2e"><?php echo esc_html(sprintf('The phone\'s clock was off by %d min (it said %s)', (int) round(abs($late['skew']) / 60), $late['phone'])); ?></small><?php endif; ?><?php if (!$late['sure']): ?><br><small style="color:#996800">Time not certain (the page was open a long time)</small><?php endif; ?><?php endif; ?></td>
        <td colspan="6"><?php echo esc_html($r['location']); ?><?php if ($r['accuracy'] !== ''): ?> · <?php echo esc_html('GPS accuracy ' . $r['accuracy']); ?><?php endif; ?><?php if ($r['device'] !== ''): ?><br><small><?php echo esc_html($r['device']); ?></small><?php endif; ?></td>
        <td data-ip><?php echo esc_html($r['ip']); ?></td>
        <td><?php echo esc_html($r['source'] === 'offline' ? 'Phone (sent later)' : ($r['source'] === 'qr' ? 'Kiosk QR' : 'App')); ?></td>
        <td><?php if ($r['record_url'] !== ''): ?><a class="button button-small" href="<?php echo esc_url($r['record_url']); ?>" data-record-manually>Record it manually</a><?php endif; ?></td>
    </tr>
<?php else: ?>
    <tr>
        <?php if ($photos): ?><td><?php echo $thumb($r['photo'] ?? '', $r['photo_full'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $thumb ?></td><?php endif; ?>
        <td><?php echo esc_html($r['work_date']); ?></td><td><?php if ($attempts): ?><a href="<?php echo esc_url($timeline_url((int) $r['employee_id'], (string) $r['work_date'])); ?>"><?php echo esc_html($r['name']); ?></a><?php else: echo esc_html($r['name']); endif; ?></td><td><?php echo esc_html($r['domain']); ?></td>
        <td><?php echo esc_html($r['scheduled']); ?></td><td><?php echo esc_html($r['event']); ?></td><td><strong><?php echo esc_html($r['status']); ?></strong></td>
        <td><?php echo esc_html($r['time']); ?></td><td><?php echo esc_html($r['location']); ?></td><td><?php echo esc_html($r['distance']); ?></td><td><?php echo esc_html($r['radius']); ?></td>
        <td><?php echo esc_html($r['result']); ?></td><td><?php echo esc_html($r['accuracy']); ?></td>
        <td><span style="<?php echo esc_attr($integrity_style[$r['integrity_key']] ?? ''); ?>"><?php echo esc_html($r['integrity']); ?></span><?php if ($r['integrity_reason']): ?><br><small><?php echo esc_html($r['integrity_reason']); ?></small><?php endif; ?></td>
        <td data-ip><?php echo esc_html($r['ip']); ?></td>
        <td><?php echo esc_html($r['source']); ?><?php if ($r['correction_id']): ?><br><small>#<?php echo (int) $r['correction_id']; ?></small><?php endif; ?><?php if ($r['replaced_by']): ?><br><small style="color:#b32d2e"><?php echo esc_html('Replaced by correction #' . $r['replaced_by'] . ' (kept)'); ?></small><?php endif; ?></td>
        <td>
            <a class="button button-small" href="<?php echo esc_url(add_query_arg('edit_time_id', $r['id'], $range_url)); ?>">Edit</a>
            <form method="post" action="<?php echo esc_url($post_url); ?>" data-ews-confirm-key="attendance_reset" style="display:inline"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($reset_nonce); ?>"><input type="hidden" name="action" value="ews31_time_reset"><input type="hidden" name="employee_id" value="<?php echo (int) $r['employee_id']; ?>"><input type="hidden" name="work_date" value="<?php echo esc_attr($r['work_date']); ?>"><button class="button button-small">Reset Day</button></form>
        </td>
    </tr>
<?php endif; endforeach; ?>
<?php if (!$records): ?><tr><td colspan="<?php echo (int) $cols; ?>"><?php echo $search !== '' ? 'No record matches this search.' : 'No records in this period.'; ?></td></tr><?php endif; ?>
</tbody></table>
<?php $time_pager(); ?>
<p><em>Reset Day removes all time events for the selected employee and date, allowing the employee to sign in again.</em></p>
</div>
