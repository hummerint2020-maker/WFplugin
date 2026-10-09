<?php
/**
 * Daily workers → Payout (3.31.76): what each worker of a site is owed for a period, advances
 * deducted; print the sheet (PDF), record an advance, then mark the period paid with a photo of the
 * signed sheet (locks it). Without a site: the choice of site. Styles: assets/css/app-daily-workers.css.
 *
 * @var array<int,object> $sites
 * @var object|null $site
 * @var callable $url          site id => payout URL
 * @var array<string,mixed> $p EWS_Daily_Workers_Trait::dw_payout()
 * @var string $period
 * @var string $paid_by
 * @var list<array{start:string,label:string,url:string}> $earlier
 * @var string $pdf_url
 * @var string $photo_url
 * @var array<int,object> $workers  the site's workers today (advance)
 * @var array<int,object> $advances advances given at this site in the period
 * @var callable $money
 * @var callable $days_text
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
?>
<div class="wfo-dw">
<?php if (!$site): ?>
    <?php if (!$sites): ?>
        <section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('cash', 24, 2); ?></span><strong><?php esc_html_e('No sites to pay', 'workforce-one'); ?></strong><p><?php esc_html_e('Ask the site manager to assign you to a site in wp-admin.', 'workforce-one'); ?></p></div></section>
    <?php endif; ?>
    <?php foreach ($sites as $id => $s): ?>
        <a class="wfo-dw-site" href="<?php echo esc_url($url($id)); ?>"><div class="wfo-dw-site-top"><span class="wfo-dw-site-ico"><?php echo Icons::svg('cash', 22, 2); ?></span><div><h3><?php echo esc_html($s->name); ?></h3><p><?php echo esc_html($s->eff_period === 'daily' ? __('Paid daily', 'workforce-one') : __('Paid weekly', 'workforce-one')); ?></p></div></div></a>
    <?php endforeach; ?>
<?php else: $paid = $p['paid']; ?>
    <?php if (count($sites) > 1): ?><div class="wfo-dw-sites-pick"><?php foreach ($sites as $id => $s): ?><a href="<?php echo esc_url($url($id)); ?>" class="wfo-dw-chip<?php echo (int) $id === (int) $site->location_id ? ' is-pri' : ''; ?>"><?php echo esc_html($s->name); ?></a><?php endforeach; ?></div><?php endif; ?>
    <section class="wfo-rq-hero"><div class="wfo-rq-hero-main"><p class="wfo-rq-hero-label"><?php echo esc_html($site->name . ' · ' . $period); ?></p><p class="wfo-rq-hero-value"><?php echo esc_html($money($p['net'])); ?></p>
        <div class="wfo-rq-hero-chips"><span class="wfo-rq-hero-chip"><?php /* translators: %d: number of workers */ echo esc_html(sprintf(_n('%d worker', '%d workers', count($p['lines']), 'workforce-one'), count($p['lines']))); ?></span>
            <span class="wfo-rq-hero-chip<?php echo $paid ? '' : ' is-wait'; ?>"><?php echo esc_html($paid ? __('Paid · locked', 'workforce-one') : __('Not paid yet', 'workforce-one')); ?></span></div></div></section>
    <?php if ($earlier): ?><div class="wfo-dw-earlier"><span><?php esc_html_e('Earlier periods not paid:', 'workforce-one'); ?></span><?php foreach ($earlier as $e): ?><a class="wfo-dw-chip is-warn" href="<?php echo esc_url($e['url']); ?>"><?php echo esc_html($e['label']); ?></a><?php endforeach; ?></div><?php endif; ?>

    <section class="wfo-dw-pay">
        <?php if (!$p['lines']): ?><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('cash', 24, 2); ?></span><strong><?php esc_html_e('There is nothing to pay in this period.', 'workforce-one'); ?></strong></div><?php endif; ?>
        <?php foreach ($p['lines'] as $l): ?>
            <div class="wfo-dw-pay-row" data-dw-line="<?php echo (int) $l['worker_id']; ?>"><strong><?php echo esc_html($l['name']); ?></strong>
                <small><?php echo esc_html($days_text($l['days']) . ' · ' . $money($l['amount'])); ?><?php if ($l['advance'] > 0): ?><span class="is-adv"> · <?php /* translators: %s: amount */ echo esc_html(sprintf(__('advance −%s', 'workforce-one'), $money($l['advance']))); ?></span><?php endif; ?></small>
                <div class="wfo-dw-net"><?php echo esc_html($money($l['net'])); ?><small><?php esc_html_e('net', 'workforce-one'); ?></small></div></div>
        <?php endforeach; ?>
        <div class="wfo-dw-total"><span><?php esc_html_e('Total after advances', 'workforce-one'); ?></span><b><?php echo esc_html($money($p['net'])); ?></b></div>
    </section>

    <?php if ($advances): ?>
        <section class="wfo-dw-pay wfo-dw-adv-list"><h3><?php esc_html_e('Advances in this period', 'workforce-one'); ?></h3>
            <?php foreach ($advances as $a): ?><div class="wfo-dw-pay-row"><strong><?php echo esc_html($a->name); ?></strong><small><?php echo esc_html(date_i18n('j M', strtotime($a->given_on)) . ($a->note ? ' · ' . $a->note : '')); ?></small><div class="wfo-dw-net"><?php echo esc_html($money($a->amount)); ?></div></div><?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($paid): ?>
        <div class="wfo-dw-photo is-set"><?php if ($photo_url): ?><a href="<?php echo esc_url($photo_url); ?>" target="_blank" rel="noopener" class="wfo-dw-shot" style="background-image:url('<?php echo esc_url($photo_url); ?>')" aria-label="<?php esc_attr_e('Signed sheet photo', 'workforce-one'); ?>"></a><?php endif; ?>
            <div><strong><?php esc_html_e('Signed sheet photo', 'workforce-one'); ?></strong><?php /* translators: 1: name, 2: date and time */ echo esc_html(sprintf(__('Uploaded by %1$s, %2$s', 'workforce-one'), $paid_by, mysql2date('D H:i', $paid->paid_at))); ?></div></div>
        <div class="wfo-dw-pay-actions is-one"><a class="wfo-dw-btn" href="<?php echo esc_url($pdf_url); ?>"><?php echo Icons::svg('printer', 18, 2); ?><?php esc_html_e('Print the sheet', 'workforce-one'); ?></a></div>
        <div class="wfo-dw-locked"><?php echo Icons::svg('lock', 18, 2.2); ?><?php esc_html_e('The period is paid and locked. A new advance comes off the next payout.', 'workforce-one'); ?></div>
    <?php else: ?>
        <div class="wfo-dw-pay-actions"><a class="wfo-dw-btn" href="<?php echo esc_url($pdf_url); ?>"><?php echo Icons::svg('printer', 18, 2); ?><?php esc_html_e('Print the sheet', 'workforce-one'); ?></a>
            <button class="wfo-dw-btn" type="button" data-wfo-sheet="wfo-dw-advance"<?php echo $workers ? '' : ' disabled'; ?>><?php echo Icons::svg('plus', 18, 2.2); ?><?php esc_html_e('Advance', 'workforce-one'); ?></button></div>
        <?php if ($p['lines']): ?>
        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data" class="wfo-dw" data-dw-paid-form>
            <?php wp_nonce_field('ews_dw_paid'); ?><input type="hidden" name="action" value="ews_dw_paid"><input type="hidden" name="site" value="<?php echo (int) $site->location_id; ?>"><input type="hidden" name="start" value="<?php echo esc_attr($p['start']); ?>">
            <label class="wfo-dw-photo" data-dw-photo data-taken="<?php esc_attr_e('Photo ready · tap to change it', 'workforce-one'); ?>"><span class="wfo-dw-site-ico"><?php echo Icons::svg('upload', 22, 2.2); ?></span><div><strong><?php esc_html_e('Signed sheet photo', 'workforce-one'); ?></strong><span data-dw-photo-text><?php esc_html_e('After the workers sign or thumbprint it', 'workforce-one'); ?></span></div>
                <input type="file" name="photo" accept="image/*" required class="screen-reader-text"></label>
            <button class="wfo-dw-confirm" type="submit"><?php echo Icons::svg('check', 18, 2.4); ?><?php esc_html_e('Paid · lock the period', 'workforce-one'); ?></button>
            <p class="wfo-dw-confirm-note"><?php esc_html_e('Amounts are not written to the Audit Log, only "payout recorded".', 'workforce-one'); ?></p>
        </form>
        <?php endif; ?>
        <dialog class="wfo-sheet" id="wfo-dw-advance" aria-labelledby="wfo-dw-adv-title">
            <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body">
                <?php wp_nonce_field('ews_dw_advance'); ?><input type="hidden" name="action" value="ews_dw_advance"><input type="hidden" name="site" value="<?php echo (int) $site->location_id; ?>">
                <div class="wfo-sheet-grip" aria-hidden="true"></div>
                <div class="wfo-sheet-head"><h2 id="wfo-dw-adv-title"><?php esc_html_e('Record an advance', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
                <div class="wfo-rq-field"><label for="wfo-dw-adv-w"><?php esc_html_e('Worker', 'workforce-one'); ?></label><select id="wfo-dw-adv-w" name="worker" required><?php foreach ($workers as $w): ?><option value="<?php echo (int) $w->id; ?>"><?php echo esc_html($w->name); ?></option><?php endforeach; ?></select></div>
                <div class="wfo-rq-two"><div class="wfo-rq-field"><label for="wfo-dw-adv-a"><?php esc_html_e('Amount', 'workforce-one'); ?></label><input id="wfo-dw-adv-a" type="number" name="amount" min="1" step="0.01" required inputmode="decimal"></div>
                    <div class="wfo-rq-field"><label for="wfo-dw-adv-n"><?php esc_html_e('Note (optional)', 'workforce-one'); ?></label><input id="wfo-dw-adv-n" type="text" name="note" maxlength="190"></div></div>
                <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('check', 18, 2.2); ?><?php esc_html_e('Record', 'workforce-one'); ?></button>
                <p class="wfo-sheet-note"><?php esc_html_e('It comes off his next payout.', 'workforce-one'); ?></p>
            </form>
        </dialog>
    <?php endif; ?>
<?php endif; ?>
</div>
