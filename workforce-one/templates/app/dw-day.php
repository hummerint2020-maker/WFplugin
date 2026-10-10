<?php
/**
 * Daily workers → one site's day sheet (3.31.76): each worker present / half day / absent and
 * extra hours, the group photo from the camera, the confirmation (GPS inside the site). Saved, the
 * day is locked and a change becomes a request (the ⋯ button). Also: quick-add a worker, move a
 * worker to another site. Script: assets/js/daily-workers.js; styles: assets/css/app-daily-workers.css.
 *
 * @var object $site           EWS_Daily_Workers_Trait::dw_site()
 * @var array<int,array<string,mixed>> $rows
 * @var object|null $sheet     the saved sheet (locked)
 * @var string $saved_by
 * @var string $today
 * @var bool   $foreman_ok     the site's mode lets the foreman record
 * @var bool   $paid           the period is paid (locked)
 * @var bool   $photo          a group photo is required
 * @var string $photo_url
 * @var string $photo_thumb  the small copy for the page
 * @var array<int,string> $others   other sites (move)
 * @var list<string> $trades
 * @var list<string> $subs
 * @var callable $trade_label
 * @var callable $money
 * @var string $post_url
 * @var string $back_url
 * @var string $open            a sheet to open (add)
 * @var string $tomorrow
 * @var string $date
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
$locked = (bool) $sheet || $paid || !$foreman_ok;
$count = ['in' => 0, 'half' => 0, 'out' => 0];
$extra = 0.0;
$cost = 0.0;
foreach ($rows as $r) {
    if ($r['elsewhere']) continue;
    $count[$r['mark']]++;
    if ($r['mark'] !== 'out') $extra += $r['extra'];
    $cost += $r['mark'] === 'in' ? $r['rate'] : ($r['mark'] === 'half' ? $r['rate'] / 2 : 0);
    if ($r['mark'] !== 'out') $cost += $r['extra'] * $r['hourly'];
}
$num = static function ($v) { return number_format_i18n((float) $v, abs((float) $v - round((float) $v)) > 0.01 ? 1 : 0); };
?>
<div class="wfo-dw" data-dw-day data-money="<?php echo esc_attr($money(1234)); ?>">
    <a class="wfo-dw-back" href="<?php echo esc_url($back_url); ?>"><?php echo Icons::svg('chevron', 16, 2.2); ?><?php esc_html_e('My sites', 'workforce-one'); ?></a>
    <section class="wfo-dw-hero"><small><?php /* translators: %s: date */ echo esc_html(sprintf(__('Today · %s', 'workforce-one'), $today)); ?></small><strong><?php echo esc_html($site->name); ?></strong>
        <div class="wfo-dw-counts">
            <div><b data-dw-count="in"><?php echo (int) $count['in']; ?></b><span><?php esc_html_e('Present', 'workforce-one'); ?></span></div>
            <div><b data-dw-count="half"><?php echo (int) $count['half']; ?></b><span><?php esc_html_e('Half day', 'workforce-one'); ?></span></div>
            <div><b data-dw-count="out"><?php echo (int) $count['out']; ?></b><span><?php esc_html_e('Absent', 'workforce-one'); ?></span></div>
            <div><b data-dw-count="extra"><?php echo esc_html($num($extra)); ?></b><span><?php esc_html_e('extra h', 'workforce-one'); ?></span></div>
        </div>
    </section>

    <?php if (!$locked): ?>
        <div class="wfo-dw-gps" data-dw-gps data-wait="<?php esc_attr_e('Your position is checked when you confirm.', 'workforce-one'); ?>" data-fail="<?php esc_attr_e('Your position could not be read. Turn on location (GPS) and try again.', 'workforce-one'); ?>"><?php echo Icons::svg('pin', 20, 2.2); ?><div><?php esc_html_e('The day is saved from inside the site', 'workforce-one'); ?><small data-dw-gps-text><?php /* translators: %d: metres */ echo esc_html(sprintf(__('Your position is checked when you confirm (within %d m).', 'workforce-one'), (int) $site->radius)); ?></small></div></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data" data-dw-sheet-form>
        <?php wp_nonce_field('ews_dw_sheet'); ?>
        <input type="hidden" name="action" value="ews_dw_sheet"><input type="hidden" name="site" value="<?php echo (int) $site->location_id; ?>">
        <input type="hidden" name="latitude" value=""><input type="hidden" name="longitude" value=""><input type="hidden" name="accuracy" value=""><input type="hidden" name="location_timestamp" value="">
        <section class="wfo-dw-list">
            <div class="wfo-dw-list-head"><h3><?php /* translators: %d: number of workers */ echo esc_html(sprintf(__('Workers (%d)', 'workforce-one'), count($rows))); ?></h3>
                <?php if (!$sheet && !$paid): ?><button type="button" data-wfo-sheet="wfo-dw-add"><?php echo Icons::svg('plus', 15, 2.4); ?><?php esc_html_e('New worker', 'workforce-one'); ?></button><?php endif; ?></div>
            <?php if (!$rows): ?>
                <div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('people', 24, 2); ?></span><strong><?php esc_html_e('There are no workers at this site today.', 'workforce-one'); ?></strong><p><?php esc_html_e('Add a worker with "New worker", or move one here from another site.', 'workforce-one'); ?></p></div>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $dis = $locked || $r['elsewhere'] ? ' disabled' : ''; ?>
                <div class="wfo-dw-worker<?php echo $r['mark'] === 'out' ? ' is-out' : ''; ?>" data-dw-worker="<?php echo (int) $r['id']; ?>" data-rate="<?php echo esc_attr((string) $r['rate']); ?>" data-hourly="<?php echo esc_attr((string) $r['hourly']); ?>"<?php echo $r['elsewhere'] ? ' data-elsewhere' : ''; ?>>
                    <div class="wfo-dw-who"><span class="wfo-dw-av"><?php echo esc_html($r['initials']); ?></span><div><strong><?php echo esc_html($r['name']); ?></strong>
                        <small><?php echo esc_html(implode(' · ', array_filter([$r['trade'], $money($r['rate']), $r['sub']]))); ?><?php if ($r['self']): ?> · <span class="is-self"><?php esc_html_e('self sign-in', 'workforce-one'); ?></span><?php endif; ?><?php if ($r['elsewhere']): ?> · <?php esc_html_e('recorded at another site today', 'workforce-one'); ?><?php endif; ?><?php if ($r['pending']): ?> · <span class="is-self"><?php esc_html_e('change waiting', 'workforce-one'); ?></span><?php endif; ?></small></div>
                        <?php if (!$paid && !$r['elsewhere'] && (($sheet && $r['day_id'] && !$r['pending']) || (!$sheet && $others))): ?>
                            <button type="button" class="wfo-dw-more" aria-label="<?php esc_attr_e('More', 'workforce-one'); ?>" data-dw-more="<?php echo $sheet ? 'change' : 'move'; ?>" data-worker="<?php echo (int) $r['id']; ?>" data-name="<?php echo esc_attr($r['name']); ?>" data-day="<?php echo (int) $r['day_id']; ?>" data-mark="<?php echo esc_attr($r['mark']); ?>" data-extra="<?php echo esc_attr((string) $r['extra']); ?>"><?php echo Icons::svg('dots', 18, 2); ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="wfo-dw-mark">
                        <input type="hidden" name="mark[<?php echo (int) $r['id']; ?>]" value="<?php echo esc_attr($r['mark']); ?>"<?php echo $r['elsewhere'] ? ' disabled' : ''; ?>>
                        <input type="hidden" name="extra[<?php echo (int) $r['id']; ?>]" value="<?php echo esc_attr((string) $r['extra']); ?>"<?php echo $r['elsewhere'] ? ' disabled' : ''; ?>>
                        <div class="wfo-dw-seg" role="group" aria-label="<?php echo esc_attr($r['name']); ?>">
                            <?php foreach (['in' => __('Present', 'workforce-one'), 'half' => __('Half day', 'workforce-one'), 'out' => __('Absent', 'workforce-one')] as $k => $label): ?>
                                <button type="button" class="is-<?php echo esc_attr($k); ?>" data-dw-mark="<?php echo esc_attr($k); ?>" aria-pressed="<?php echo $r['mark'] === $k ? 'true' : 'false'; ?>"<?php echo $dis; ?>><?php echo esc_html($label); ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="wfo-dw-extra"><button type="button" data-dw-extra="-0.5" aria-label="<?php esc_attr_e('Less', 'workforce-one'); ?>"<?php echo $dis; ?>><?php echo Icons::svg('minus', 14, 2.4); ?></button><span><b data-dw-extra-val><?php echo esc_html($num($r['extra'])); ?></b><small><?php esc_html_e('extra h', 'workforce-one'); ?></small></span><button type="button" data-dw-extra="0.5" aria-label="<?php esc_attr_e('More', 'workforce-one'); ?>"<?php echo $dis; ?>><?php echo Icons::svg('plus', 14, 2.4); ?></button></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="wfo-dw-total"><span><?php esc_html_e('Cost of the day', 'workforce-one'); ?></span><b data-dw-cost><?php echo esc_html($money($cost)); ?></b></div>
        </section>

        <?php if ($sheet): ?>
            <div class="wfo-dw-photo is-set"><?php if ($photo_url): ?><a href="<?php echo esc_url($photo_url); ?>" target="_blank" rel="noopener" class="wfo-dw-shot" data-wfo-photo style="background-image:url('<?php echo esc_url($photo_thumb); ?>')" aria-label="<?php esc_attr_e('Group photo', 'workforce-one'); ?>"></a><?php else: ?><span class="wfo-dw-site-ico"><?php echo Icons::svg('check', 22, 2.2); ?></span><?php endif; ?>
                <div><strong><?php esc_html_e('Group photo', 'workforce-one'); ?></strong><?php /* translators: %s: distance */ echo esc_html(mysql2date('H:i', $sheet->saved_at) . ($sheet->distance_meters !== null ? ' · ' . sprintf(__('%s m from the site centre', 'workforce-one'), number_format_i18n((float) $sheet->distance_meters)) : '')); ?></div></div>
            <div class="wfo-dw-locked"><?php echo Icons::svg('lock', 18, 2.2); ?><?php /* translators: 1: time, 2: foreman */ echo esc_html(sprintf(__('Saved at %1$s by %2$s. Changing a saved day is a request with a reason and an approval.', 'workforce-one'), mysql2date('H:i', $sheet->saved_at), $saved_by)); ?></div>
        <?php elseif ($paid): ?>
            <div class="wfo-dw-locked"><?php echo Icons::svg('lock', 18, 2.2); ?><?php esc_html_e('This period is paid and locked.', 'workforce-one'); ?></div>
        <?php elseif (!$foreman_ok): ?>
            <div class="wfo-dw-locked"><?php echo Icons::svg('people', 18, 2.2); ?><?php esc_html_e('At this site the workers sign in themselves; the foreman does not record the day.', 'workforce-one'); ?></div>
        <?php else: ?>
            <?php if ($photo): ?>
                <label class="wfo-dw-photo" data-dw-photo data-taken="<?php esc_attr_e('Photo taken · tap to take it again', 'workforce-one'); ?>"><span class="wfo-dw-site-ico"><?php echo Icons::svg('camera', 22, 2.2); ?></span><div><strong><?php esc_html_e('Group photo of the workers', 'workforce-one'); ?></strong><span data-dw-photo-text><?php esc_html_e('Taken with the camera when you record', 'workforce-one'); ?></span></div>
                    <input type="file" name="photo" accept="image/*" capture="environment" required class="screen-reader-text"></label>
            <?php endif; ?>
            <button class="wfo-dw-confirm" type="submit"<?php echo $rows ? '' : ' disabled'; ?> data-busy="<?php esc_attr_e('Checking your position…', 'workforce-one'); ?>"><?php echo Icons::svg('check', 18, 2.4); ?><?php esc_html_e('Confirm today\'s sheet', 'workforce-one'); ?></button>
            <p class="wfo-dw-confirm-note"><?php esc_html_e('Once confirmed, the day is locked.', 'workforce-one'); ?></p>
        <?php endif; ?>
    </form>
</div>

<?php if (!$sheet && !$paid): ?>
<dialog class="wfo-sheet" id="wfo-dw-add" aria-labelledby="wfo-dw-add-title"<?php echo $open === 'add' ? ' data-wfo-sheet-start' : ''; ?>>
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body">
        <?php wp_nonce_field('ews_dw_worker_add'); ?><input type="hidden" name="action" value="ews_dw_worker_add"><input type="hidden" name="site" value="<?php echo (int) $site->location_id; ?>">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-dw-add-title"><?php esc_html_e('Add a worker', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <div class="wfo-rq-field"><label for="wfo-dw-name"><?php esc_html_e('Name', 'workforce-one'); ?></label><input id="wfo-dw-name" type="text" name="name" required maxlength="190"></div>
        <div class="wfo-rq-field"><label for="wfo-dw-mobile"><?php esc_html_e('Mobile', 'workforce-one'); ?></label><input id="wfo-dw-mobile" type="tel" name="mobile" dir="ltr" inputmode="tel"></div>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="wfo-dw-trade"><?php esc_html_e('Trade', 'workforce-one'); ?></label><select id="wfo-dw-trade" name="trade"><?php foreach ($trades as $t): ?><option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($trade_label($t)); ?></option><?php endforeach; ?></select></div>
            <div class="wfo-rq-field"><label for="wfo-dw-rate"><?php esc_html_e('Daily rate', 'workforce-one'); ?></label><input id="wfo-dw-rate" type="number" name="daily_rate" min="1" step="0.01" required inputmode="decimal"></div>
        </div>
        <div class="wfo-rq-field"><label for="wfo-dw-nid"><?php esc_html_e('National ID', 'workforce-one'); ?></label><input id="wfo-dw-nid" type="text" name="nid" inputmode="numeric" dir="ltr" maxlength="30" data-dw-nid autocomplete="off"><input type="hidden" name="nid_kind" value="eg">
            <p class="wfo-dw-hint" data-dw-nid-msg data-ok="<?php esc_attr_e('Looks valid: 14 digits', 'workforce-one'); ?>" data-bad="<?php esc_attr_e('An Egyptian national ID is 14 digits', 'workforce-one'); ?>"></p></div>
        <?php if ($subs): ?>
            <div class="wfo-rq-field"><label for="wfo-dw-sub"><?php esc_html_e('Subcontractor (optional)', 'workforce-one'); ?></label><select id="wfo-dw-sub" name="subcontractor"><option value=""><?php esc_html_e('— None —', 'workforce-one'); ?></option><?php foreach ($subs as $s): ?><option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('plus', 18, 2.2); ?><?php esc_html_e('Add', 'workforce-one'); ?></button>
        <p class="wfo-sheet-note"><?php /* translators: %s: site */ echo esc_html(sprintf(__('No email or account needed. The worker is added to %s from today.', 'workforce-one'), $site->name)); ?></p>
    </form>
</dialog>
<?php endif; ?>

<?php if (!$sheet && $others): ?>
<dialog class="wfo-sheet" id="wfo-dw-move" aria-labelledby="wfo-dw-move-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body">
        <?php wp_nonce_field('ews_dw_move'); ?><input type="hidden" name="action" value="ews_dw_move"><input type="hidden" name="worker" value="">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-dw-move-title"><?php esc_html_e('Move to another site', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <p class="wfo-dw-sheet-who" data-dw-who></p>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="wfo-dw-from-site"><?php esc_html_e('From', 'workforce-one'); ?></label><input id="wfo-dw-from-site" type="text" value="<?php echo esc_attr($site->name); ?>" readonly></div>
            <div class="wfo-rq-field"><label for="wfo-dw-to"><?php esc_html_e('To', 'workforce-one'); ?></label><select id="wfo-dw-to" name="to" required><?php foreach ($others as $id => $name): ?><option value="<?php echo (int) $id; ?>"><?php echo esc_html($name); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="wfo-rq-field" role="group" aria-labelledby="wfo-dw-start-label"><span class="wfo-rq-label" id="wfo-dw-start-label"><?php esc_html_e('Starting', 'workforce-one'); ?></span>
            <div class="wfo-dw-when"><label><input type="radio" name="from" value="<?php echo esc_attr($date); ?>"> <?php esc_html_e('Today', 'workforce-one'); ?></label><label><input type="radio" name="from" value="<?php echo esc_attr($tomorrow); ?>" checked> <?php esc_html_e('Tomorrow', 'workforce-one'); ?></label></div></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Move', 'workforce-one'); ?></button>
        <p class="wfo-sheet-note"><?php esc_html_e('Days at this site stay in the worker\'s history and in this project\'s cost.', 'workforce-one'); ?></p>
    </form>
</dialog>
<?php endif; ?>

<?php if ($sheet && !$paid): ?>
<dialog class="wfo-sheet" id="wfo-dw-change" aria-labelledby="wfo-dw-change-title">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body">
        <?php wp_nonce_field('ews_dw_change'); ?><input type="hidden" name="action" value="ews_dw_change"><input type="hidden" name="day" value="">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-dw-change-title"><?php esc_html_e('Ask to change a saved day', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <p class="wfo-dw-sheet-who" data-dw-who></p>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="wfo-dw-cmark"><?php esc_html_e('Attendance', 'workforce-one'); ?></label><select id="wfo-dw-cmark" name="mark"><option value="in"><?php esc_html_e('Present', 'workforce-one'); ?></option><option value="half"><?php esc_html_e('Half day', 'workforce-one'); ?></option><option value="out"><?php esc_html_e('Absent', 'workforce-one'); ?></option></select></div>
            <div class="wfo-rq-field"><label for="wfo-dw-cextra"><?php esc_html_e('Extra hours', 'workforce-one'); ?></label><input id="wfo-dw-cextra" type="number" name="extra" min="0" max="12" step="0.5" inputmode="decimal"></div>
        </div>
        <div class="wfo-rq-field"><label for="wfo-dw-creason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="wfo-dw-creason" name="reason" rows="3" required></textarea></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
        <p class="wfo-sheet-note"><?php esc_html_e('The day stays as saved until a manager approves the change. The original values are kept.', 'workforce-one'); ?></p>
    </form>
</dialog>
<?php endif; ?>
