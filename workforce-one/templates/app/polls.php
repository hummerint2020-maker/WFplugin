<?php
/**
 * Employee app → Polls: the open polls to answer, then past polls with their results.
 *
 * @var string[] $open   rendered poll cards (templates/app/poll-card.php)
 * @var string[] $past
 * @var string $error    a refused vote for a poll that is not listed ('' = none)
 */
if (!defined('ABSPATH')) exit;
?>
<div class="ews-polls-page">
    <?php if ($error !== ''): ?><div class="ews-poll-error"><?php echo esc_html($error); ?></div><?php endif; ?>
    <h2>Open polls</h2>
    <?php if (!$open): ?><p class="ews-polls-empty">No open polls right now.</p><?php endif; ?>
    <?php foreach ($open as $card) echo $card; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the card template ?>
    <?php if ($past): ?>
        <h2>Past polls</h2>
        <?php foreach ($past as $card) echo $card; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the card template ?>
    <?php endif; ?>
</div>
