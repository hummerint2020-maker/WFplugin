<?php
/**
 * Employee app → Polls: the open polls to answer, then past polls with their results.
 * Styles: assets/css/app-polls.css (new look, 3.31.53).
 *
 * @var string[] $open   rendered poll cards (templates/app/poll-card.php)
 * @var string[] $past
 * @var string $error    a refused vote for a poll that is not listed ('' = none)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; the cards are escaped in their template.
?>
<div class="ews-polls-page wfo-polls">
    <?php if ($error !== ''): ?><div class="ews-poll-error wfo-poll-flash" role="status"><?php echo Icons::svg('alert', 16, 2.2); ?><span><?php echo esc_html($error); ?></span></div><?php endif; ?>
    <section class="wfo-polls-section" aria-labelledby="wfo-polls-open">
        <div class="wfo-polls-head"><span class="wfo-polls-head-icon"><?php echo Icons::svg('polls', 20, 2); ?></span><h2 id="wfo-polls-open"><?php esc_html_e('Open polls', 'workforce-one'); ?></h2><span class="wfo-polls-count"><?php echo (int) count($open); ?></span></div>
        <?php if (!$open): ?>
            <div class="ews-polls-empty wfo-polls-empty"><span class="wfo-polls-empty-icon"><?php echo Icons::svg('polls', 26, 2); ?></span><strong><?php esc_html_e('No open polls right now.', 'workforce-one'); ?></strong><span><?php esc_html_e('New polls will appear here, and you will get a notification.', 'workforce-one'); ?></span></div>
        <?php else: ?>
            <div class="wfo-polls-grid"><?php foreach ($open as $card) echo $card; ?></div>
        <?php endif; ?>
    </section>
    <?php if ($past): ?>
    <section class="wfo-polls-section" aria-labelledby="wfo-polls-past">
        <div class="wfo-polls-head"><span class="wfo-polls-head-icon is-slate"><?php echo Icons::svg('check', 20, 2); ?></span><h2 id="wfo-polls-past"><?php esc_html_e('Past polls', 'workforce-one'); ?></h2><span class="wfo-polls-count"><?php echo (int) count($past); ?></span></div>
        <div class="wfo-polls-grid"><?php foreach ($past as $card) echo $card; ?></div>
    </section>
    <?php endif; ?>
</div>
