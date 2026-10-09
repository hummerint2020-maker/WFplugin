<?php
/**
 * Daily workers → My sites (3.31.75): the foreman's sites with today's state. A card opens the
 * site's day sheet (templates/app/dw-day.php). Styles: assets/css/app-daily-workers.css.
 *
 * @var array<int,array{site:object,workers:int,sheet:?object,present:int,foreman:bool,url:string}> $cards
 * @var int    $todo      sites still to record today
 * @var int    $workers   workers at the sites today
 * @var string $today
 * @var callable $period_label
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
?>
<div class="wfo-dw">
<?php if (!$cards): ?>
    <section class="wfo-rq-card"><div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('site', 24, 2); ?></span><strong><?php esc_html_e('No sites assigned to you', 'workforce-one'); ?></strong><p><?php esc_html_e('Ask the site manager to add you as foreman of a site in wp-admin.', 'workforce-one'); ?></p></div></section>
<?php else: ?>
    <section class="wfo-rq-hero"><div class="wfo-rq-hero-main"><p class="wfo-rq-hero-label"><?php echo esc_html($today); ?></p>
        <p class="wfo-rq-hero-value"><?php /* translators: %d: number of sites */ echo esc_html(sprintf(_n('%d site', '%d sites', count($cards), 'workforce-one'), count($cards))); ?></p>
        <div class="wfo-rq-hero-chips">
            <?php if ($todo): ?><span class="wfo-rq-hero-chip is-wait"><?php echo Icons::svg('clock', 14, 2.2); ?><?php /* translators: %d: number of sites */ echo esc_html(sprintf(_n('%d site not recorded yet', '%d sites not recorded yet', $todo, 'workforce-one'), $todo)); ?></span><?php endif; ?>
            <span class="wfo-rq-hero-chip"><?php /* translators: %d: number of workers */ echo esc_html(sprintf(_n('%d worker', '%d workers', $workers, 'workforce-one'), $workers)); ?></span>
        </div></div></section>
    <?php foreach ($cards as $c): $s = $c['site']; ?>
        <a class="wfo-dw-site" href="<?php echo esc_url($c['url']); ?>">
            <div class="wfo-dw-site-top"><span class="wfo-dw-site-ico"><?php echo Icons::svg('site', 22, 2); ?></span><div><h3><?php echo esc_html($s->name); ?></h3>
                <p><?php echo esc_html(implode(' · ', array_filter([(string) $s->project, $s->start_date ? sprintf(/* translators: %s: date */ __('since %s', 'workforce-one'), date_i18n('j M', strtotime($s->start_date))) : '']))); ?></p></div></div>
            <div class="wfo-dw-site-stats">
                <span class="wfo-dw-chip is-pri"><?php /* translators: %d: number of workers */ echo esc_html(sprintf(_n('%d worker', '%d workers', $c['workers'], 'workforce-one'), $c['workers'])); ?></span>
                <span class="wfo-dw-chip"><?php echo esc_html($s->eff_period === 'daily' ? __('Paid daily', 'workforce-one') : __('Paid weekly', 'workforce-one')); ?></span>
                <?php if (!$c['foreman']): ?><span class="wfo-dw-chip is-warn"><?php esc_html_e('Workers sign in themselves', 'workforce-one'); ?></span><?php endif; ?>
            </div>
            <?php if ($c['sheet']): ?>
                <div class="wfo-dw-site-go is-done"><?php echo Icons::svg('check', 18, 2.2); ?><?php /* translators: 1: time, 2: number present */ echo esc_html(sprintf(__('Recorded %1$s · %2$d present', 'workforce-one'), mysql2date('H:i', $c['sheet']->saved_at), $c['present'])); ?></div>
            <?php elseif ($c['foreman']): ?>
                <div class="wfo-dw-site-go"><?php echo Icons::svg('edit', 18, 2.2); ?><?php esc_html_e('Record today', 'workforce-one'); ?></div>
            <?php else: ?>
                <div class="wfo-dw-site-go is-soft"><?php echo Icons::svg('people', 18, 2.2); ?><?php esc_html_e('See today', 'workforce-one'); ?></div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
<?php endif; ?>
</div>
