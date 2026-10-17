<?php
/**
 * wp-admin → Daily Workers (3.31.76): the workers list (search by name, mobile or the full national
 * ID), a worker's profile (national ID masked; "Show" for "View national IDs", audited), add / edit,
 * reports (labour cost, workers per site and day, unpaid; Excel, PDF, the insurance report), changes
 * to saved days, settings (recording, pay, sites and foremen, trades, subcontractors).
 * Styles: assets/css/admin-daily-workers.css.
 *
 * @var string $tab        list | worker | edit | days | reports | settings | changes
 * @var array<string,mixed> $settings
 * @var array<int,object> $sites
 * @var string $post_url
 * @var callable $url
 * @var callable $money
 * @var callable $trade_label
 * @var callable $masked
 * @var callable $days_text
 * @var callable $mark_label
 * @var string $notice
 * @var string $error
 * @var int    $pending_changes
 * Per tab (set only on that tab):
 * @var array<string,mixed> $filters
 * @var int $page
 * @var array<int,object> $rows
 * @var int $total
 * @var object|null $w
 * @var string $revealed
 * @var bool $can_reveal
 * @var string $reveal_url
 * @var string $photo_url
 * @var string $gov
 * @var array<int,object> $history
 * @var array<int,object> $payouts
 * @var float $unpaid
 * @var float $outstanding
 * @var int $site_today
 * @var array<string,mixed> $report
 * @var string $from
 * @var string $to
 * @var int $site
 * @var callable $export
 * @var array<int,object> $locations
 * @var array<int,int[]> $foremen
 * @var array<int,object> $people
 * @var bool $saved
 * @var string $roles_url
 * @var array<int,object> $changes
 * Days tab (3.31.85):
 * @var string $day
 * @var array<int,array{w:object,day:?object,elsewhere:?object,pending:bool}> $day_rows
 * @var object|null $sheet
 * @var bool $paid
 * @var string $sheet_by
 * @var string $today
 */
if (!defined('ABSPATH')) exit;
$stars = static function ($n) { $n = max(0, min(5, (int) $n)); return '<span class="ews-dw-stars">' . str_repeat('★', $n) . '<i>' . str_repeat('★', 5 - $n) . '</i></span>'; };
$initials = static function ($name) { $o = ''; foreach (array_slice(preg_split('/\s+/u', trim((string) $name)) ?: [], 0, 2) as $p) $o .= mb_substr($p, 0, 1); return $o; };
$tabs = ['list' => __('Workers', 'workforce-one'), 'days' => __('Days', 'workforce-one'), 'reports' => __('Reports', 'workforce-one'), 'changes' => __('Changes', 'workforce-one'), 'settings' => __('Settings', 'workforce-one')];
$current = in_array($tab, ['worker', 'edit'], true) ? 'list' : $tab;
$notices = ['day_saved' => __('Day saved. Each change is in the Changes tab with your reason, and in the Audit Log.', 'workforce-one'), 'saved' => __('Worker saved.', 'workforce-one'), 'deleted' => __('Worker and national ID deleted.', 'workforce-one'), 'approved' => __('Change approved. The original values are kept with the request.', 'workforce-one'), 'rejected' => __('Change rejected.', 'workforce-one')];
?>
<div class="wrap ews-dw-wrap">
<h1 class="wp-heading-inline"><?php esc_html_e('Daily Workers', 'workforce-one'); ?></h1> <a href="<?php echo esc_url($url(['edit' => 0])); ?>" class="page-title-action"><?php esc_html_e('Add worker', 'workforce-one'); ?></a>
<hr class="wp-header-end">
<nav class="nav-tab-wrapper"><?php foreach ($tabs as $k => $label): ?><a href="<?php echo esc_url($url(['tab' => $k])); ?>" class="nav-tab<?php echo $current === $k ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?><?php if ($k === 'changes' && $pending_changes): ?> <span class="awaiting-mod"><?php echo (int) $pending_changes; ?></span><?php endif; ?></a><?php endforeach; ?></nav>
<?php if ($error !== ''): ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<?php if (isset($notices[$notice])): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notices[$notice]); ?></p></div><?php endif; ?>

<?php if ($tab === 'list'): ?>
    <form method="get" class="ews-dw-filters"><input type="hidden" name="page" value="ews31-daily-workers">
        <input type="search" name="q" value="<?php echo esc_attr($filters['q']); ?>" placeholder="<?php esc_attr_e('Name, mobile or full national ID', 'workforce-one'); ?>">
        <select name="trade"><option value=""><?php esc_html_e('All trades', 'workforce-one'); ?></option><?php foreach ($settings['trades'] as $t): ?><option value="<?php echo esc_attr($t); ?>"<?php selected($filters['trade'], $t); ?>><?php echo esc_html($trade_label($t)); ?></option><?php endforeach; ?></select>
        <select name="sub"><option value=""><?php esc_html_e('All subcontractors', 'workforce-one'); ?></option><option value="-"<?php selected($filters['sub'], '-'); ?>><?php esc_html_e('None', 'workforce-one'); ?></option><?php foreach ($settings['subcontractors'] as $s): ?><option value="<?php echo esc_attr($s); ?>"<?php selected($filters['sub'], $s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?></select>
        <select name="site"><option value="0"><?php esc_html_e('All sites', 'workforce-one'); ?></option><?php foreach ($sites as $id => $s): ?><option value="<?php echo (int) $id; ?>"<?php selected((int) $filters['site'], (int) $id); ?>><?php echo esc_html($s->name); ?></option><?php endforeach; ?></select>
        <select name="status"><option value=""><?php esc_html_e('All statuses', 'workforce-one'); ?></option><option value="active"<?php selected($filters['status'], 'active'); ?>><?php esc_html_e('Active', 'workforce-one'); ?></option><option value="no_rehire"<?php selected($filters['status'], 'no_rehire'); ?>><?php esc_html_e('Do not rehire', 'workforce-one'); ?></option></select>
        <select name="rating"><option value="0"><?php esc_html_e('Any rating', 'workforce-one'); ?></option><?php foreach ([4, 3, 2] as $r): ?><option value="<?php echo (int) $r; ?>"<?php selected((int) $filters['rating'], $r); ?>><?php echo esc_html(str_repeat('★', $r) . '+'); ?></option><?php endforeach; ?></select>
        <button class="button"><?php esc_html_e('Filter', 'workforce-one'); ?></button>
    </form>
    <div class="tablenav top"><div class="tablenav-pages"><span class="displaying-num"><?php /* translators: %d: number of workers */ echo esc_html(sprintf(_n('%d worker', '%d workers', $total, 'workforce-one'), $total)); ?></span>
        <?php $pages = (int) ceil($total / 50); if ($pages > 1): ?><span class="pagination-links"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="button<?php echo $i === $page ? ' disabled' : ''; ?>" href="<?php echo esc_url(add_query_arg('paged', $i)); ?>"><?php echo (int) $i; ?></a> <?php endfor; ?></span><?php endif; ?></div><br class="clear"></div>
    <table class="wp-list-table widefat fixed striped ews-dw-table"><thead><tr><th><?php esc_html_e('Worker', 'workforce-one'); ?></th><th><?php esc_html_e('Trade', 'workforce-one'); ?></th><th><?php esc_html_e('Mobile', 'workforce-one'); ?></th><th><?php esc_html_e('National ID', 'workforce-one'); ?></th><th><?php esc_html_e('Daily rate', 'workforce-one'); ?></th><th><?php esc_html_e('Subcontractor', 'workforce-one'); ?></th><th><?php esc_html_e('Site today', 'workforce-one'); ?></th><th><?php esc_html_e('Rating', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php if (!$rows): ?><tr class="no-items"><td colspan="9"><?php echo esc_html($filters['q'] !== '' || $filters['trade'] !== '' || $filters['site'] ? __('No workers match these filters.', 'workforce-one') : __('No workers yet. Add the first one, or let a foreman add them on site.', 'workforce-one')); ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><td><span class="ews-dw-name"><span class="ews-dw-av"><?php echo esc_html($initials($r->name)); ?></span><span><strong><a href="<?php echo esc_url($url(['worker' => (int) $r->id])); ?>"><?php echo esc_html($r->name); ?></a></strong><span class="ews-dw-sub">dw-<?php echo (int) $r->id; ?></span></span></span></td>
            <td><?php echo esc_html($trade_label($r->trade) ?: '—'); ?></td><td dir="ltr"><?php echo esc_html($r->mobile ?: '—'); ?></td><td><span class="ews-dw-id"><?php echo esc_html($masked($r) ?: '—'); ?></span></td>
            <td><?php echo esc_html($money($r->daily_rate)); ?></td><td><?php echo esc_html($r->subcontractor ?: '—'); ?></td><td><?php echo esc_html(isset($sites[(int) $r->site_id]) ? $sites[(int) $r->site_id]->name : '—'); ?></td>
            <td><?php echo $stars($r->rating); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></td>
            <td><span class="ews-dw-badge <?php echo $r->status === 'active' ? 'is-ok' : 'is-bad'; ?>"><?php echo esc_html($r->status === 'active' ? __('Active', 'workforce-one') : __('Do not rehire', 'workforce-one')); ?></span></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="description"><?php esc_html_e('Daily workers do not appear in staff lists, Attendance or monthly Payroll. National IDs are encrypted and shown in full only with "View national IDs".', 'workforce-one'); ?></p>

<?php elseif ($tab === 'worker'): ?>
    <h2 class="ews-dw-h"><?php echo esc_html($w->name); ?></h2>
    <?php if ($revealed !== ''): ?><div class="notice notice-info"><p><?php esc_html_e('The full national ID is shown because you have "View national IDs". This reveal is logged under your name.', 'workforce-one'); ?></p></div><?php endif; ?>
    <div class="ews-dw-grid"><div><div class="ews-dw-card">
        <div class="ews-dw-photo"><?php if ($photo_url): ?><img src="<?php echo esc_url($photo_url); ?>" alt=""><?php else: echo esc_html($initials($w->name)); endif; ?></div>
        <p class="ews-dw-sub">dw-<?php echo (int) $w->id; ?> · <?php /* translators: %s: date */ echo esc_html(sprintf(__('since %s', 'workforce-one'), mysql2date('j F Y', $w->created_at))); ?></p>
        <dl class="ews-dw-dl">
            <dt><?php esc_html_e('Trade', 'workforce-one'); ?></dt><dd><?php echo esc_html($trade_label($w->trade) ?: '—'); ?></dd>
            <dt><?php esc_html_e('Mobile', 'workforce-one'); ?></dt><dd dir="ltr"><?php echo esc_html($w->mobile ?: '—'); ?></dd>
            <dt><?php esc_html_e('National ID', 'workforce-one'); ?></dt><dd><span class="ews-dw-id" data-dw-nid-shown><?php echo esc_html($revealed !== '' ? $revealed : ($masked($w) ?: '—')); ?></span>
                <?php if ($revealed === '' && $can_reveal): ?> <a class="button button-small" href="<?php echo esc_url($reveal_url); ?>"><?php esc_html_e('Show', 'workforce-one'); ?></a><?php endif; ?></dd>
            <?php if ($w->birth_date): ?><dt><?php esc_html_e('Born', 'workforce-one'); ?></dt><dd><?php echo esc_html(mysql2date('j F Y', $w->birth_date) . ($gov !== '' ? ' · ' . $gov : '')); ?></dd><?php endif; ?>
            <dt><?php esc_html_e('Daily rate', 'workforce-one'); ?></dt><dd><?php echo esc_html($money($w->daily_rate)); ?></dd>
            <dt><?php esc_html_e('Extra hour', 'workforce-one'); ?></dt><dd><?php echo esc_html($w->hourly_rate !== null ? $money($w->hourly_rate) : __('Company default', 'workforce-one')); ?></dd>
            <dt><?php esc_html_e('Subcontractor', 'workforce-one'); ?></dt><dd><?php echo esc_html($w->subcontractor ?: '—'); ?></dd>
            <dt><?php esc_html_e('Site today', 'workforce-one'); ?></dt><dd><?php echo esc_html(isset($sites[$site_today]) ? $sites[$site_today]->name : '—'); ?></dd>
            <dt><?php esc_html_e('Rating', 'workforce-one'); ?></dt><dd><?php echo $stars($w->rating); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></dd>
            <dt><?php esc_html_e('Status', 'workforce-one'); ?></dt><dd><span class="ews-dw-badge <?php echo $w->status === 'active' ? 'is-ok' : 'is-bad'; ?>"><?php echo esc_html($w->status === 'active' ? __('Active', 'workforce-one') : __('Do not rehire', 'workforce-one')); ?></span></dd>
            <dt><?php esc_html_e('Self sign-in', 'workforce-one'); ?></dt><dd><?php echo esc_html($w->pin_hash ? __('PIN set', 'workforce-one') : __('No PIN', 'workforce-one')); ?></dd>
        </dl>
        <?php if ($w->notes): ?><p class="ews-dw-notes"><?php echo esc_html($w->notes); ?></p><?php endif; ?>
        <p><a class="button" href="<?php echo esc_url($url(['edit' => (int) $w->id])); ?>"><?php esc_html_e('Edit', 'workforce-one'); ?></a></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" onsubmit="return confirm(this.getAttribute('data-q'))" data-q="<?php esc_attr_e('Delete this worker, the national ID and all their days? This cannot be undone.', 'workforce-one'); ?>">
            <?php wp_nonce_field('ews_dw_worker_delete_' . (int) $w->id); ?><input type="hidden" name="action" value="ews_dw_worker_delete"><input type="hidden" name="id" value="<?php echo (int) $w->id; ?>"><button class="button-link button-link-delete"><?php esc_html_e('Delete worker', 'workforce-one'); ?></button></form>
    </div></div><div>
        <div class="ews-dw-kpis is-3"><div class="ews-dw-kpi"><b><?php echo esc_html($money($unpaid)); ?></b><span><?php esc_html_e('Owed, not paid yet', 'workforce-one'); ?></span></div><div class="ews-dw-kpi"><b><?php echo esc_html($money($outstanding)); ?></b><span><?php esc_html_e('Advances not deducted yet', 'workforce-one'); ?></span></div></div>
        <div class="ews-dw-card"><h2><?php esc_html_e('Sites', 'workforce-one'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Site', 'workforce-one'); ?></th><th><?php esc_html_e('Period', 'workforce-one'); ?></th><th><?php esc_html_e('Days', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php if (!$history): ?><tr><td colspan="4"><?php esc_html_e('No days recorded yet.', 'workforce-one'); ?></td></tr><?php endif; ?>
            <?php foreach ($history as $h): ?><tr><td><?php echo esc_html($h->name); ?></td><td><?php echo esc_html(mysql2date('j M', $h->first) . ' – ' . mysql2date('j M Y', $h->last)); ?></td><td><?php echo esc_html($days_text($h->days)); ?></td><td><?php echo esc_html($money($h->amount)); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="ews-dw-card"><h2><?php esc_html_e('Payouts', 'workforce-one'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Period', 'workforce-one'); ?></th><th><?php esc_html_e('Site', 'workforce-one'); ?></th><th><?php esc_html_e('Days', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th><th><?php esc_html_e('Advance', 'workforce-one'); ?></th><th><?php esc_html_e('Net', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php if (!$payouts): ?><tr><td colspan="6"><?php esc_html_e('No payouts yet.', 'workforce-one'); ?></td></tr><?php endif; ?>
            <?php foreach ($payouts as $p): ?><tr><td><?php echo esc_html(mysql2date('j M', $p->period_start) . ' – ' . mysql2date('j M Y', $p->period_end)); ?></td><td><?php echo esc_html($p->site_name); ?></td><td><?php echo esc_html($days_text($p->days)); ?></td><td><?php echo esc_html($money($p->amount)); ?></td><td><?php echo esc_html((float) $p->advance > 0 ? $money($p->advance) : '—'); ?></td><td><strong><?php echo esc_html($money($p->net)); ?></strong></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div></div>

<?php elseif ($tab === 'edit'): $e = $w; ?>
    <h2 class="ews-dw-h"><?php echo esc_html($e ? $e->name : __('Add worker', 'workforce-one')); ?></h2>
    <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data" class="ews-dw-card ews-dw-form">
        <?php wp_nonce_field('ews_dw_admin_worker'); ?><input type="hidden" name="action" value="ews_dw_admin_worker"><input type="hidden" name="id" value="<?php echo $e ? (int) $e->id : 0; ?>">
        <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="dw-name"><?php esc_html_e('Name', 'workforce-one'); ?></label></th><td><input id="dw-name" class="regular-text" type="text" name="name" required value="<?php echo esc_attr($e ? $e->name : ''); ?>"></td></tr>
            <tr><th scope="row"><label for="dw-mobile"><?php esc_html_e('Mobile', 'workforce-one'); ?></label></th><td><input id="dw-mobile" class="regular-text" type="tel" dir="ltr" name="mobile" value="<?php echo esc_attr($e ? (string) $e->mobile : ''); ?>"></td></tr>
            <tr><th scope="row"><label for="dw-trade"><?php esc_html_e('Trade', 'workforce-one'); ?></label></th><td><select id="dw-trade" name="trade"><option value="">—</option><?php $tr = $settings['trades']; if ($e && $e->trade && !in_array($e->trade, $tr, true)) $tr[] = $e->trade; foreach ($tr as $t): ?><option value="<?php echo esc_attr($t); ?>"<?php selected($e ? (string) $e->trade : '', $t); ?>><?php echo esc_html($trade_label($t)); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th scope="row"><label for="dw-rate"><?php esc_html_e('Daily rate', 'workforce-one'); ?></label></th><td><input id="dw-rate" class="small-text" type="number" min="1" step="0.01" name="daily_rate" required value="<?php echo esc_attr($e ? (string) (float) $e->daily_rate : ''); ?>"></td></tr>
            <tr><th scope="row"><label for="dw-hourly"><?php esc_html_e('Extra hour rate', 'workforce-one'); ?></label></th><td><input id="dw-hourly" class="small-text" type="number" min="0" step="0.01" name="hourly_rate" value="<?php echo esc_attr($e && $e->hourly_rate !== null ? (string) (float) $e->hourly_rate : ''); ?>"> <span class="description"><?php /* translators: %s: amount */ echo esc_html(sprintf(__('Empty: the company default (%s).', 'workforce-one'), $money($settings['hourly_rate']))); ?></span></td></tr>
            <tr><th scope="row"><?php esc_html_e('National ID', 'workforce-one'); ?></th><td>
                <?php if ($e && $e->nid_hash): ?><p><span class="ews-dw-id"><?php echo esc_html($masked($e)); ?></span> <span class="description"><?php esc_html_e('Saved. Enter a number only to replace it.', 'workforce-one'); ?></span></p><?php endif; ?>
                <label><input type="radio" name="nid_kind" value="eg" checked> <?php esc_html_e('Egyptian (14 digits)', 'workforce-one'); ?></label> &nbsp; <label><input type="radio" name="nid_kind" value="other"> <?php esc_html_e('Other nationality (passport or ID)', 'workforce-one'); ?></label><br>
                <input class="regular-text ews-dw-id" type="text" name="nid" dir="ltr" autocomplete="off" inputmode="numeric" maxlength="30"></td></tr>
            <tr><th scope="row"><label for="dw-sub"><?php esc_html_e('Subcontractor', 'workforce-one'); ?></label></th><td><select id="dw-sub" name="subcontractor"><option value=""><?php esc_html_e('— None —', 'workforce-one'); ?></option><?php $sb = $settings['subcontractors']; if ($e && $e->subcontractor && !in_array($e->subcontractor, $sb, true)) $sb[] = $e->subcontractor; foreach ($sb as $s): ?><option value="<?php echo esc_attr($s); ?>"<?php selected($e ? (string) $e->subcontractor : '', $s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?></select></td></tr>
            <tr><th scope="row"><label for="dw-site"><?php esc_html_e('Site from today', 'workforce-one'); ?></label></th><td><select id="dw-site" name="site"><option value="0">—</option><?php foreach ($sites as $id => $s): ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html($s->name); ?></option><?php endforeach; ?></select> <span class="description"><?php esc_html_e('Leave empty to keep the current site.', 'workforce-one'); ?></span></td></tr>
            <tr><th scope="row"><label for="dw-rating"><?php esc_html_e('Rating', 'workforce-one'); ?></label></th><td><select id="dw-rating" name="rating"><?php for ($i = 0; $i <= 5; $i++): ?><option value="<?php echo (int) $i; ?>"<?php selected($e ? (int) $e->rating : 0, $i); ?>><?php echo esc_html($i ? str_repeat('★', $i) : '—'); ?></option><?php endfor; ?></select></td></tr>
            <tr><th scope="row"><label for="dw-status"><?php esc_html_e('Status', 'workforce-one'); ?></label></th><td><select id="dw-status" name="status"><option value="active"><?php esc_html_e('Active', 'workforce-one'); ?></option><option value="no_rehire"<?php selected($e ? $e->status : '', 'no_rehire'); ?>><?php esc_html_e('Do not rehire', 'workforce-one'); ?></option></select></td></tr>
            <tr><th scope="row"><label for="dw-pin"><?php esc_html_e('PIN for self sign-in', 'workforce-one'); ?></label></th><td><input id="dw-pin" class="small-text" type="text" name="pin" inputmode="numeric" maxlength="6" autocomplete="off"> <span class="description"><?php echo esc_html($e && $e->pin_hash ? __('A PIN is set. Enter 4 to 6 digits to change it.', 'workforce-one') : __('4 to 6 digits. The worker signs in with their mobile number and this PIN.', 'workforce-one')); ?></span></td></tr>
            <tr><th scope="row"><label for="dw-photo"><?php esc_html_e('Photo', 'workforce-one'); ?></label></th><td><input id="dw-photo" type="file" name="photo" accept="image/jpeg,image/png,image/webp"> <span class="description"><?php esc_html_e('For the future gate tablet with face recognition.', 'workforce-one'); ?></span></td></tr>
            <tr><th scope="row"><label for="dw-notes"><?php esc_html_e('Notes', 'workforce-one'); ?></label></th><td><textarea id="dw-notes" class="large-text" rows="3" name="notes"><?php echo esc_textarea($e ? (string) $e->notes : ''); ?></textarea></td></tr>
        </table>
        <p class="submit"><button class="button button-primary"><?php esc_html_e('Save Changes', 'workforce-one'); ?></button></p>
    </form>

<?php elseif ($tab === 'reports'): $r = $report; $maxOf = static function ($rows) { $m = 0; foreach ($rows as $x) $m = max($m, (float) $x->amount); return $m ?: 1; }; ?>
    <form method="get" class="ews-dw-filters"><input type="hidden" name="page" value="ews31-daily-workers"><input type="hidden" name="tab" value="reports">
        <input type="date" name="from" value="<?php echo esc_attr($from); ?>"> <input type="date" name="to" value="<?php echo esc_attr($to); ?>">
        <select name="site"><option value="0"><?php esc_html_e('All projects', 'workforce-one'); ?></option><?php foreach ($sites as $id => $s): ?><option value="<?php echo (int) $id; ?>"<?php selected($site, (int) $id); ?>><?php echo esc_html($s->project ? $s->project . ' — ' . $s->name : $s->name); ?></option><?php endforeach; ?></select>
        <button class="button"><?php esc_html_e('Show', 'workforce-one'); ?></button><span class="ews-dw-grow"></span>
        <a class="button" href="<?php echo esc_url($export('xlsx')); ?>"><?php esc_html_e('Export to Excel', 'workforce-one'); ?></a> <a class="button" href="<?php echo esc_url($export('pdf')); ?>">PDF</a> <a class="button" href="<?php echo esc_url($export('insurance')); ?>"><?php esc_html_e('Insurance report for the accountant', 'workforce-one'); ?></a>
    </form>
    <div class="ews-dw-kpis">
        <?php if ($r['hero_site']): ?><div class="ews-dw-kpi is-hero"><b><?php echo esc_html($money($r['hero'])); ?></b><span><?php /* translators: %s: project or site */ echo esc_html(sprintf(__('%s: labour so far', 'workforce-one'), $r['hero_site']->project ? $r['hero_site']->project . ' (' . $r['hero_site']->name . ')' : $r['hero_site']->name)); ?></span></div><?php endif; ?>
        <div class="ews-dw-kpi"><b><?php echo esc_html($money($r['total'])); ?></b><span><?php esc_html_e('Labour cost in the period', 'workforce-one'); ?> · <?php echo esc_html($days_text($r['days'])); ?></span></div>
        <div class="ews-dw-kpi"><b><?php echo esc_html($money($r['owed'])); ?></b><span><?php esc_html_e('Owed, not paid yet', 'workforce-one'); ?></span></div>
    </div>
    <div class="ews-dw-two"><div>
        <?php foreach ([[__('By project / site', 'workforce-one'), $r['by_site'], 'site'], [__('By subcontractor', 'workforce-one'), $r['by_sub'], 'sub']] as [$title, $rows, $kind]): $mx = $maxOf($rows); ?>
            <div class="ews-dw-card"><h2><?php echo esc_html($title); ?></h2><table class="widefat striped"><tbody>
                <?php if (!$rows): ?><tr><td><?php esc_html_e('No days in this period.', 'workforce-one'); ?></td></tr><?php endif; ?>
                <?php foreach ($rows as $x): $label = $kind === 'sub' ? ((string) $x->label ?: __('None (direct)', 'workforce-one')) : ((string) $x->label . ($x->project ? ' · ' . $x->project : '')); ?>
                    <tr><td><?php echo esc_html($label); ?></td><td class="ews-dw-bar-cell"><span class="ews-dw-bar" style="width:<?php echo (int) round(140 * (float) $x->amount / $mx); ?>px"></span><?php echo esc_html($money($x->amount)); ?></td><td><?php echo esc_html($days_text($x->days)); ?></td></tr>
                <?php endforeach; ?></tbody></table></div>
        <?php endforeach; ?>
    </div><div>
        <?php $mx = $maxOf($r['by_trade']); ?>
        <div class="ews-dw-card"><h2><?php esc_html_e('By trade', 'workforce-one'); ?></h2><table class="widefat striped"><tbody>
            <?php if (!$r['by_trade']): ?><tr><td><?php esc_html_e('No days in this period.', 'workforce-one'); ?></td></tr><?php endif; ?>
            <?php foreach ($r['by_trade'] as $x): ?><tr><td><?php echo esc_html($trade_label($x->label) ?: '—'); ?></td><td class="ews-dw-bar-cell"><span class="ews-dw-bar" style="width:<?php echo (int) round(140 * (float) $x->amount / $mx); ?>px"></span><?php echo esc_html($money($x->amount)); ?></td><td><?php echo esc_html($days_text($x->days)); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="ews-dw-card"><h2><?php esc_html_e('Workers per site and day', 'workforce-one'); ?></h2><div class="ews-dw-scroll"><table class="widefat ews-dw-heat"><thead><tr><th></th><?php foreach ($r['dates'] as $d): ?><th><?php echo esc_html(date_i18n('j M', strtotime($d))); ?></th><?php endforeach; ?></tr></thead><tbody>
            <?php foreach ($r['grid'] as $loc => $byDay): ?><tr><th><?php echo esc_html(isset($sites[$loc]) ? $sites[$loc]->name : '#' . $loc); ?></th><?php foreach ($r['dates'] as $d): $n = (int) ($byDay[$d] ?? 0); ?><td class="<?php echo esc_attr($n === 0 ? 'h0' : ($n < 7 ? 'h1' : ($n < 12 ? 'h2' : 'h3'))); ?>"><?php echo $n ? (int) $n : '—'; ?></td><?php endforeach; ?></tr><?php endforeach; ?>
            <?php if (!$r['grid']): ?><tr><td colspan="<?php echo count($r['dates']) + 1; ?>"><?php esc_html_e('No days in this period.', 'workforce-one'); ?></td></tr><?php endif; ?>
        </tbody></table></div></div>
    </div></div>
    <div class="ews-dw-card"><h2><?php esc_html_e('Unpaid balances', 'workforce-one'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Worker', 'workforce-one'); ?></th><th><?php esc_html_e('Trade', 'workforce-one'); ?></th><th><?php esc_html_e('Days', 'workforce-one'); ?></th><th><?php esc_html_e('Amount', 'workforce-one'); ?></th><th><?php esc_html_e('Advance', 'workforce-one'); ?></th><th><?php esc_html_e('Net', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php if (!$r['unpaid']): ?><tr><td colspan="6"><?php esc_html_e('Everything is paid.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($r['unpaid'] as $x): ?><tr><td><a href="<?php echo esc_url($url(['worker' => (int) $x->worker_id])); ?>"><?php echo esc_html($x->name); ?></a></td><td><?php echo esc_html($trade_label($x->trade)); ?></td><td><?php echo esc_html($days_text($x->days)); ?></td><td><?php echo esc_html($money($x->amount)); ?></td><td><?php echo esc_html($x->advance > 0 ? $money($x->advance) : '—'); ?></td><td><strong><?php echo esc_html($money($x->net)); ?></strong></td></tr><?php endforeach; ?>
    </tbody></table></div>

<?php elseif ($tab === 'days'):
    $go = static function ($d) use ($url, $site) { return $url(['tab' => 'days', 'site' => $site, 'day' => $d]); };
    $prev = gmdate('Y-m-d', strtotime($day . ' 12:00:00 UTC') - 86400); $next = gmdate('Y-m-d', strtotime($day . ' 12:00:00 UTC') + 86400); ?>
    <p class="description"><?php esc_html_e('A site\'s day as recorded, for any earlier day or today. Set a day the foreman did not record, or correct one: a reason is required, and each change is kept in Changes (old → new) and the Audit Log. A paid period cannot be changed.', 'workforce-one'); ?></p>
    <?php if (!$sites): ?><p><?php esc_html_e('No project sites yet: add one in Settings.', 'workforce-one'); ?></p><?php else: ?>
    <form method="get" class="ews-dw-filters"><input type="hidden" name="page" value="ews31-daily-workers"><input type="hidden" name="tab" value="days">
        <select name="site"><?php foreach ($sites as $id => $s_): ?><option value="<?php echo (int) $id; ?>"<?php selected($site, (int) $id); ?>><?php echo esc_html($s_->name); ?></option><?php endforeach; ?></select>
        <input type="date" name="day" value="<?php echo esc_attr($day); ?>" max="<?php echo esc_attr($today); ?>">
        <button class="button"><?php esc_html_e('Show', 'workforce-one'); ?></button>
        <a class="button" href="<?php echo esc_url($go($prev)); ?>">‹ <?php esc_html_e('Previous day', 'workforce-one'); ?></a>
        <?php if ($day < $today): ?><a class="button" href="<?php echo esc_url($go($next)); ?>"><?php esc_html_e('Next day', 'workforce-one'); ?> ›</a><?php endif; ?>
    </form>
    <p><strong><?php echo esc_html(date_i18n('l j F Y', strtotime($day))); ?></strong> ·
        <?php if ($paid): ?><span class="ews-dw-badge is-ok"><?php esc_html_e('Paid · locked', 'workforce-one'); ?></span>
        <?php elseif ($sheet): ?><span class="ews-dw-badge"><?php /* translators: 1: name, 2: time */ echo esc_html(sprintf($sheet->integrity_status === 'admin' ? __('Set by %1$s in wp-admin, %2$s', 'workforce-one') : __('Recorded by %1$s at %2$s', 'workforce-one'), $sheet_by, mysql2date('H:i', $sheet->saved_at))); ?></span>
        <?php else: ?><span class="ews-dw-badge is-wait"><?php esc_html_e('No day sheet saved', 'workforce-one'); ?></span><?php endif; ?></p>
    <form method="post" action="<?php echo esc_url($post_url); ?>">
        <?php wp_nonce_field('ews_dw_admin_day'); ?><input type="hidden" name="action" value="ews_dw_admin_day"><input type="hidden" name="site" value="<?php echo (int) $site; ?>"><input type="hidden" name="day" value="<?php echo esc_attr($day); ?>">
        <table class="widefat striped"><thead><tr><th><?php esc_html_e('Worker', 'workforce-one'); ?></th><th><?php esc_html_e('Recorded', 'workforce-one'); ?></th><th><?php esc_html_e('Attendance', 'workforce-one'); ?></th><th><?php esc_html_e('Extra hours', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php if (!$day_rows): ?><tr><td colspan="4"><?php esc_html_e('No workers at this site on this day.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($day_rows as $r): $w_ = $r['w']; $d_ = $r['day']; $id_ = (int) $w_->id;
            $off = $paid || $r['elsewhere'] || $r['pending'] || ($d_ && $d_->payout_id); ?>
            <tr><td><strong><?php echo esc_html($w_->name); ?></strong><span class="ews-dw-sub"><?php echo esc_html(implode(' · ', array_filter([$trade_label($w_->trade), $money($d_ ? $d_->daily_rate : $w_->daily_rate)]))); ?></span></td>
                <td><?php if ($r['elsewhere']): /* translators: %s: site */ echo esc_html(sprintf(__('At %s that day', 'workforce-one'), $r['elsewhere']->name));
                    elseif ($d_): echo esc_html($mark_label($d_->mark) . ((float) $d_->extra_hours > 0 ? ' +' . (float) $d_->extra_hours . 'h' : '') . ' · ' . $money($d_->amount)); ?><?php if ($r['pending']): ?> <span class="ews-dw-badge is-wait"><?php esc_html_e('Change waiting', 'workforce-one'); ?></span><?php endif; ?>
                    <?php else: echo '—'; endif; ?></td>
                <td><select name="mark[<?php echo $id_; ?>]"<?php disabled($off); ?>><?php if (!$d_): ?><option value=""><?php esc_html_e('— Not recorded —', 'workforce-one'); ?></option><?php endif; ?>
                    <?php foreach (['in', 'half', 'out'] as $m_): ?><option value="<?php echo esc_attr($m_); ?>"<?php selected($d_ ? (string) $d_->mark : '', $m_); ?>><?php echo esc_html($mark_label($m_)); ?></option><?php endforeach; ?></select></td>
                <td><input type="number" class="small-text" name="extra[<?php echo $id_; ?>]" min="0" max="12" step="0.5" value="<?php echo esc_attr((string) ($d_ ? (float) $d_->extra_hours : 0)); ?>"<?php disabled($off); ?>></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php if ($day_rows && !$paid): ?>
        <p><label for="ews-dw-day-reason"><strong><?php esc_html_e('Reason', 'workforce-one'); ?></strong></label><br><textarea id="ews-dw-day-reason" name="reason" rows="2" class="large-text" required placeholder="<?php esc_attr_e('e.g. The foreman\'s phone was broken; attendance from the paper list.', 'workforce-one'); ?>"></textarea></p>
        <?php submit_button(__('Save the day', 'workforce-one'), 'primary', 'submit', false); ?>
        <?php endif; ?>
    </form>
    <?php endif; ?>

<?php elseif ($tab === 'changes'): ?>
    <table class="widefat striped ews-dw-changes"><thead><tr><th><?php esc_html_e('Worker', 'workforce-one'); ?></th><th><?php esc_html_e('Site', 'workforce-one'); ?></th><th><?php esc_html_e('Day', 'workforce-one'); ?></th><th><?php esc_html_e('Change', 'workforce-one'); ?></th><th><?php esc_html_e('Reason', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php if (!$changes): ?><tr><td colspan="6"><?php esc_html_e('No change requests.', 'workforce-one'); ?></td></tr><?php endif; ?>
    <?php foreach ($changes as $c):
        $from_t = ((string) $c->old_mark === '' ? __('Not recorded', 'workforce-one') : $mark_label($c->old_mark)) . ((float) $c->old_extra > 0 ? ' +' . (float) $c->old_extra . 'h' : '');
        $to_t = $mark_label($c->new_mark) . ((float) $c->new_extra > 0 ? ' +' . (float) $c->new_extra . 'h' : ''); $by = get_userdata((int) $c->requested_by); ?>
        <tr><td><?php echo esc_html($c->name); ?></td><td><?php echo esc_html($c->site_name); ?></td><td><?php echo esc_html(mysql2date('D j M', $c->work_date)); ?></td><td><?php echo esc_html($from_t . ' → ' . $to_t); ?></td>
            <td><?php echo esc_html($c->reason); ?><br><span class="ews-dw-sub"><?php echo esc_html(($by ? $by->display_name : '') . ' · ' . mysql2date('j M H:i', $c->requested_at)); ?></span></td>
            <td><?php if ($c->status === 'pending'): ?>
                <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-dw-decide"><?php wp_nonce_field('ews_dw_change_decide'); ?><input type="hidden" name="action" value="ews_dw_change_decide"><input type="hidden" name="change" value="<?php echo (int) $c->id; ?>">
                    <input type="text" name="note" placeholder="<?php esc_attr_e('Note (required to reject)', 'workforce-one'); ?>"> <button class="button button-primary" name="decision" value="approve"><?php esc_html_e('Approve', 'workforce-one'); ?></button> <button class="button" name="decision" value="reject"><?php esc_html_e('Reject', 'workforce-one'); ?></button></form>
            <?php else: ?><span class="ews-dw-badge <?php echo $c->status === 'approved' ? 'is-ok' : 'is-bad'; ?>"><?php echo esc_html($c->status === 'approved' ? __('Approved', 'workforce-one') : __('Rejected', 'workforce-one')); ?></span><?php if ($c->note): ?><br><span class="ews-dw-sub"><?php echo esc_html($c->note); ?></span><?php endif; ?><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>

<?php elseif ($tab === 'settings'): ?>
    <?php if ($saved): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'workforce-one'); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="ews-dw-card ews-dw-settings"><?php wp_nonce_field('ews_dw_settings'); ?><input type="hidden" name="action" value="ews_dw_settings">
    <table class="form-table" role="presentation">
        <tr><th scope="row"><?php esc_html_e('Who records attendance', 'workforce-one'); ?></th><td><?php foreach (['foreman', 'self', 'both'] as $m): ?><label><input type="radio" name="dw_mode" value="<?php echo esc_attr($m); ?>"<?php checked($settings['mode'], $m); ?>> <?php echo esc_html(['foreman' => __('Foreman only records attendance', 'workforce-one'), 'self' => __('Workers sign in themselves (mobile + PIN, no email)', 'workforce-one'), 'both' => __('Both', 'workforce-one')][$m]); ?></label><br><?php endforeach; ?><p class="description"><?php esc_html_e('Each site can override it below.', 'workforce-one'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Pay', 'workforce-one'); ?></th><td><p><?php esc_html_e('Full day = daily rate · half day = rate ÷ 2 · extra hours × hourly rate', 'workforce-one'); ?></p>
            <label><?php esc_html_e('Pay period', 'workforce-one'); ?> <select name="dw_period"><option value="weekly"<?php selected($settings['period'], 'weekly'); ?>><?php esc_html_e('Weekly', 'workforce-one'); ?></option><option value="daily"<?php selected($settings['period'], 'daily'); ?>><?php esc_html_e('Daily', 'workforce-one'); ?></option></select></label>
            &nbsp; <label><?php esc_html_e('Week starts on', 'workforce-one'); ?> <select name="dw_week_start"><?php global $wp_locale; for ($i = 0; $i < 7; $i++): ?><option value="<?php echo (int) $i; ?>"<?php selected((int) $settings['week_start'], $i); ?>><?php echo esc_html($wp_locale->get_weekday($i)); ?></option><?php endfor; ?></select></label><br>
            <label><?php esc_html_e('Default hourly rate', 'workforce-one'); ?> <input type="number" class="small-text" min="0" step="0.01" name="dw_hourly_rate" value="<?php echo esc_attr((string) $settings['hourly_rate']); ?>"></label></td></tr>
        <tr><th scope="row"><?php esc_html_e('When recording', 'workforce-one'); ?></th><td><label><input type="checkbox" checked disabled> <?php esc_html_e('The foreman must be inside the site (same rules as Sign In)', 'workforce-one'); ?></label><br><label><input type="checkbox" name="dw_photo" value="1"<?php checked((int) $settings['photo'], 1); ?>> <?php esc_html_e('A group photo with the camera (not the gallery)', 'workforce-one'); ?></label></td></tr>
        <tr><th scope="row"><?php esc_html_e('Sites', 'workforce-one'); ?></th><td>
            <?php if (!$locations): ?><p><?php esc_html_e('Add work locations first (Work Locations).', 'workforce-one'); ?></p><?php else: ?>
            <div class="ews-dw-scroll"><table class="widefat striped ews-dw-sites"><thead><tr><th><?php esc_html_e('Project site', 'workforce-one'); ?></th><th><?php esc_html_e('Project', 'workforce-one'); ?></th><th><?php esc_html_e('Dates', 'workforce-one'); ?></th><th><?php esc_html_e('Foremen / cashiers', 'workforce-one'); ?></th><th><?php esc_html_e('Recording', 'workforce-one'); ?></th><th><?php esc_html_e('Pay period', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php foreach ($locations as $l): $n = 'sites[' . (int) $l->id . ']'; $on = $l->is_site && (int) $l->active === 1; ?>
                <tr><td><label><input type="checkbox" name="<?php echo esc_attr($n); ?>[on]" value="1"<?php checked($on); ?>> <strong><?php echo esc_html($l->name); ?></strong></label></td>
                    <td><input type="text" name="<?php echo esc_attr($n); ?>[project]" value="<?php echo esc_attr((string) $l->project); ?>" placeholder="<?php esc_attr_e('Project name', 'workforce-one'); ?>"></td>
                    <td><input type="date" name="<?php echo esc_attr($n); ?>[start]" value="<?php echo esc_attr((string) $l->start_date); ?>"> <input type="date" name="<?php echo esc_attr($n); ?>[end]" value="<?php echo esc_attr((string) $l->end_date); ?>"></td>
                    <td><select multiple size="3" name="<?php echo esc_attr($n); ?>[foremen][]"><?php foreach ($people as $u): ?><option value="<?php echo (int) $u->ID; ?>"<?php selected(in_array((int) $u->ID, $foremen[(int) $l->id] ?? [], true)); ?>><?php echo esc_html($u->display_name); ?></option><?php endforeach; ?></select></td>
                    <td><select name="<?php echo esc_attr($n); ?>[mode]"><option value=""><?php esc_html_e('Company setting', 'workforce-one'); ?></option><option value="foreman"<?php selected((string) $l->mode, 'foreman'); ?>><?php esc_html_e('Foreman', 'workforce-one'); ?></option><option value="self"<?php selected((string) $l->mode, 'self'); ?>><?php esc_html_e('Workers themselves', 'workforce-one'); ?></option><option value="both"<?php selected((string) $l->mode, 'both'); ?>><?php esc_html_e('Both', 'workforce-one'); ?></option></select></td>
                    <td><select name="<?php echo esc_attr($n); ?>[period]"><option value=""><?php esc_html_e('Company setting', 'workforce-one'); ?></option><option value="weekly"<?php selected((string) $l->period, 'weekly'); ?>><?php esc_html_e('Weekly', 'workforce-one'); ?></option><option value="daily"<?php selected((string) $l->period, 'daily'); ?>><?php esc_html_e('Daily', 'workforce-one'); ?></option></select></td></tr>
            <?php endforeach; ?></tbody></table></div>
            <?php if (!$people): ?><p class="description"><?php esc_html_e('No one has "Foreman (daily workers)" or "Pay daily workers" yet.', 'workforce-one'); ?></p><?php endif; ?>
            <?php endif; ?></td></tr>
        <tr><th scope="row"><label for="dw-trades"><?php esc_html_e('Trades', 'workforce-one'); ?></label></th><td><textarea id="dw-trades" name="dw_trades" rows="6" class="regular-text"><?php echo esc_textarea(implode("\n", $settings['trades'])); ?></textarea><p class="description"><?php esc_html_e('One per line.', 'workforce-one'); ?></p></td></tr>
        <tr><th scope="row"><label for="dw-subs"><?php esc_html_e('Subcontractors', 'workforce-one'); ?></label></th><td><textarea id="dw-subs" name="dw_subcontractors" rows="4" class="regular-text"><?php echo esc_textarea(implode("\n", $settings['subcontractors'])); ?></textarea><p class="description"><?php esc_html_e('One per line.', 'workforce-one'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Permissions', 'workforce-one'); ?></th><td><p><?php esc_html_e('"Foreman (daily workers)", "Pay daily workers" and "View national IDs" are in', 'workforce-one'); ?> <a href="<?php echo esc_url($roles_url); ?>"><?php esc_html_e('Roles & Permissions', 'workforce-one'); ?></a>.</p></td></tr>
    </table>
    <p class="submit"><button class="button button-primary"><?php esc_html_e('Save Changes', 'workforce-one'); ?></button></p></form>
<?php endif; ?>
</div>
