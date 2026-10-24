<?php
/**
 * Employee app → My requests (3.31.103, owner-approved mockup docs/mockups/my-approvals/): the
 * person's own leave, overtime, corrections and swaps, newest first, each with where it is level by
 * level; one still waiting can be withdrawn (leave, overtime, correction). Styles: assets/css/app-approvals.css.
 *
 * @var list<array<string,mixed>> $items
 * @var string $post_url
 * @var array{type:string,text:string}|null $notice
 * @var string $filter
 * @var string $base_url
 * @var array<string,mixed> $new     the New request sheet (3.31.105, templates/app/new-request.php)
 * @var bool $open_new                ?new=1 opens it (the home page's shortcut)
 */
if (!defined('ABSPATH')) exit;
$num = static function ($n): string { return number_format_i18n((float) $n); };
$labels = ['waiting' => __('Waiting', 'workforce-one'), 'approved' => __('Approved', 'workforce-one'), 'rejected' => __('Rejected', 'workforce-one'), 'withdrawn' => __('Withdrawn', 'workforce-one'), 'cancelled' => __('Cancelled', 'workforce-one'), 'expired' => __('Expired', 'workforce-one')];
$counts = array_count_values(array_map(static function ($it) { return $it['state']; }, $items));
$shown = $filter !== '' && isset($labels[$filter]) ? array_values(array_filter($items, static function ($it) use ($filter) { return $it['state'] === $filter; })) : $items;
?>
<div class="wfo-apv is-mine">
    <?php if ($notice): ?><div class="wfo-apv-toast is-<?php echo esc_attr($notice['type']); ?>" role="status"><?php echo esc_html($notice['text']); ?></div><?php endif; ?>
    <?php if (!empty($new['kinds'])): ?><div class="wfo-apv-bar"><span class="wfo-apv-bar-gap"></span><button type="button" class="wfo-apv-btn is-new" data-wfo-sheet="wfo-new-sheet">+ <?php esc_html_e('New request', 'workforce-one'); ?></button></div><?php endif; ?>
    <?php if ($items): ?>
    <nav class="wfo-apv-filters" aria-label="<?php esc_attr_e('Show', 'workforce-one'); ?>">
        <a href="<?php echo esc_url($base_url); ?>"<?php echo $filter === '' ? ' aria-current="true"' : ''; ?>><?php esc_html_e('All', 'workforce-one'); ?></a>
        <?php foreach ($labels as $k => $l): if (empty($counts[$k])) continue; ?><a href="<?php echo esc_url(add_query_arg('req_state', $k, $base_url)); ?>"<?php echo $filter === $k ? ' aria-current="true"' : ''; ?>><?php echo esc_html($l . ' · ' . $num($counts[$k])); ?></a><?php endforeach; ?>
    </nav>
    <?php else: ?>
    <div class="wfo-apv-empty"><strong><?php esc_html_e('You have no requests yet.', 'workforce-one'); ?></strong><span><?php esc_html_e('Leave, overtime, corrections and swaps you ask for show here, with who decides them.', 'workforce-one'); ?></span></div>
    <?php endif; ?>

    <div class="wfo-apv-list">
    <?php foreach ($shown as $it): $p = $it['path']; ?>
        <article class="wfo-apv-item tone-<?php echo esc_attr($it['tone']); ?>" data-req="<?php echo esc_attr($it['key']); ?>">
            <div class="wfo-apv-who">
                <div class="wfo-apv-t"><b><?php echo esc_html($it['title']); ?></b><small><?php echo esc_html(implode(' · ', array_filter([$it['sub'], date_i18n('D j M, H:i', strtotime((string) $it['when']))]))); ?></small></div>
                <span class="wfo-apv-state is-<?php echo esc_attr($it['state']); ?>"><?php echo esc_html($labels[$it['state']] . ($it['state'] === 'waiting' && $p && $p['level'] ? ' · ' . sprintf(/* translators: 1: level, 2: number of levels */ __('level %1$s of %2$s', 'workforce-one'), $num($p['level']), $num($p['levels'])) : '')); ?></span>
            </div>
            <?php if (($it['note'] ?? '') !== ''): ?><p class="wfo-apv-why"><?php echo esc_html($it['note']); ?></p><?php endif; ?>
            <?php if ($p && $p['steps']): ?>
            <details class="wfo-apv-more"<?php echo $it['state'] === 'waiting' ? ' open' : ''; ?>>
                <summary><?php esc_html_e('Who decides it', 'workforce-one'); ?></summary>
                <ol class="wfo-apv-path">
                    <?php foreach ($p['steps'] as $st): ?><li class="is-<?php echo esc_attr($st['state']); ?>"><span class="wfo-apv-dot" aria-hidden="true"><?php echo isset($st['mark']) && $st['state'] !== 'done' ? esc_html($st['mark']) : ($st['state'] === 'done' ? '✓' : ($st['state'] === 'rejected' ? '✕' : esc_html($num($st['order'])))); ?></span><div><b><?php echo esc_html($st['label'] . ($st['who'] !== '' ? ' · ' . $st['who'] : '')); ?></b><small><?php echo esc_html($st['sub']); ?></small></div></li><?php endforeach; ?>
                </ol>
            </details>
            <?php endif; ?>
            <?php if ($it['withdraw'] !== ''): ?>
            <details class="wfo-apv-withdraw">
                <summary class="wfo-apv-btn is-bad is-sm"><?php esc_html_e('Withdraw', 'workforce-one'); ?></summary>
                <form method="post" action="<?php echo esc_url($post_url); ?>">
                    <?php wp_nonce_field('ews_request_withdraw_' . $it['withdraw'] . '_' . (int) $it['id']); ?>
                    <input type="hidden" name="action" value="ews_request_withdraw"><input type="hidden" name="kind" value="<?php echo esc_attr($it['withdraw']); ?>"><input type="hidden" name="id" value="<?php echo (int) $it['id']; ?>">
                    <p><?php echo $it['withdraw'] === 'leave' ? esc_html__('Withdraw it? The days go back to your balance.', 'workforce-one') : ($it['withdraw'] === 'swap' ? esc_html__('Withdraw it? Your colleague will no longer be asked, and is told.', 'workforce-one') : esc_html__('Withdraw it? Nobody will need to decide it.', 'workforce-one')); ?></p>
                    <button type="submit" class="wfo-apv-btn is-bad"><?php esc_html_e('Yes, withdraw it', 'workforce-one'); ?></button>
                </form>
            </details>
            <?php elseif (!empty($it['link']) && $it['state'] === 'waiting'): ?>
            <p class="wfo-apv-link"><a href="<?php echo esc_url($it['link']); ?>"><?php esc_html_e('Open it on the Schedule page', 'workforce-one'); ?></a></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/new-request.php'; ?>
