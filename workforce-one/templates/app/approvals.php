<?php
/**
 * Employee app → My approvals (3.31.103, owner-approved mockup docs/mockups/my-approvals/): every
 * request waiting for this person, overdue first. Each card decides with the request's own form (its
 * module's handler) and comes back here; "Select" approves several at once (assets/js/approvals.js).
 * Styles: assets/css/app-approvals.css. Data: EWS_My_Approvals_Trait::my_approvals_items().
 *
 * @var list<array<string,mixed>> $items
 * @var int $all
 * @var int $overdue
 * @var int $week
 * @var string $kind
 * @var array<string,string> $kinds
 * @var string $base_url
 * @var string $post_url
 * @var array{type:string,text:string}|null $notice
 * @var int $bulk_max
 * @var array<string,mixed> $subs   substitutes (3.31.107, templates/app/substitutes-bar.php)
 * @var string $focus   the card a notification opened (apv-swap-ID, 3.31.124), or empty
 */
if (!defined('ABSPATH')) exit;
$ini = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $w) $out .= function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
    return $out;
};
$num = static function ($n): string { return number_format_i18n((float) $n, floor((float) $n) == (float) $n ? 0 : 1); };
?>
<div class="wfo-apv" data-apv data-bulk-max="<?php echo (int) $bulk_max; ?>" data-done-url="<?php echo esc_url(add_query_arg('apv_bulk', '__N__', $base_url)); ?>">
    <?php if ($notice): ?><div class="wfo-apv-toast is-<?php echo esc_attr($notice['type']); ?>" role="status"><?php echo esc_html($notice['text']); ?></div><?php endif; ?>
    <?php include __DIR__ . '/substitutes-bar.php'; ?>

    <div class="wfo-apv-kpis">
        <div class="wfo-apv-kpi"><b><?php echo esc_html($num($all)); ?></b><span><?php esc_html_e('Waiting for you', 'workforce-one'); ?></span></div>
        <div class="wfo-apv-kpi<?php echo $overdue ? ' is-late' : ''; ?>"><b><?php echo esc_html($num($overdue)); ?></b><span><?php esc_html_e('Overdue', 'workforce-one'); ?></span></div>
        <div class="wfo-apv-kpi"><b><?php echo esc_html($num($week)); ?></b><span><?php esc_html_e('Decided in 7 days', 'workforce-one'); ?></span></div>
    </div>

    <?php if ($all): ?>
    <div class="wfo-apv-bar">
        <nav class="wfo-apv-filters" aria-label="<?php esc_attr_e('Show', 'workforce-one'); ?>">
            <a href="<?php echo esc_url($base_url); ?>"<?php echo $kind === '' ? ' aria-current="true"' : ''; ?>><?php esc_html_e('All', 'workforce-one'); ?></a>
            <?php foreach ($kinds as $k => $label): ?><a href="<?php echo esc_url(add_query_arg('apv_kind', $k, $base_url)); ?>"<?php echo $kind === $k ? ' aria-current="true"' : ''; ?>><?php echo esc_html($label); ?></a><?php endforeach; ?>
            <?php if ($overdue): ?><a href="<?php echo esc_url(add_query_arg('apv_kind', 'late', $base_url)); ?>"<?php echo $kind === 'late' ? ' aria-current="true"' : ''; ?>><?php esc_html_e('Overdue only', 'workforce-one'); ?></a><?php endif; ?>
        </nav>
        <button type="button" class="wfo-apv-btn is-sm" data-apv-select hidden><?php esc_html_e('Select', 'workforce-one'); ?></button>
    </div>
    <?php endif; ?>

    <?php if (!$items): ?>
    <div class="wfo-apv-empty"><strong><?php echo $all ? esc_html__('Nothing of this kind is waiting.', 'workforce-one') : esc_html__('Nothing is waiting for you.', 'workforce-one'); ?></strong><span><?php esc_html_e('Requests that need your decision show here, from every page.', 'workforce-one'); ?></span></div>
    <?php endif; ?>

    <div class="wfo-apv-list">
    <?php foreach ($items as $it): $p = $it['path']; $can = $it['action'] !== ''; $peer = !empty($it['peer']); $bulk = $can && ($it['bulk'] ?? true) !== false; $anchor = (string) ($it['anchor'] ?? ''); ?>
        <article class="wfo-apv-item tone-<?php echo esc_attr($it['tone']); ?><?php echo $p['overdue'] ? ' is-late' : ''; ?><?php echo $anchor !== '' && $anchor === $focus ? ' is-focus' : ''; ?>" data-apv-item="<?php echo esc_attr((string) ($it['item_key'] ?? (int) $it['id'])); ?>"<?php echo $anchor !== '' ? ' id="' . esc_attr($anchor) . '"' : ''; ?>>
            <form method="post" action="<?php echo esc_url($post_url); ?>" data-apv-form<?php echo $bulk ? '' : ' data-apv-nobulk'; ?><?php echo $it['note_required'] ? ' data-note-required' : ''; ?>>
            <?php if ($can): ?>
                <input type="hidden" name="action" value="<?php echo esc_attr($it['action']); ?>">
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr(wp_create_nonce($it['nonce'])); ?>">
                <input type="hidden" name="ews_return" value="approvals">
                <?php foreach ($it['fields'] as $f => $v): ?><input type="hidden" name="<?php echo esc_attr($f); ?>" value="<?php echo esc_attr((string) $v); ?>"><?php endforeach; ?>
            <?php endif; ?>
            <div class="wfo-apv-who">
                <?php if ($it['picture'] !== ''): ?><span class="wfo-apv-av"><img src="<?php echo esc_url($it['picture']); ?>" alt=""></span><?php else: ?><span class="wfo-apv-av" aria-hidden="true"><?php echo esc_html($ini($it['name'])); ?></span><?php endif; ?>
                <div class="wfo-apv-t"><b><?php echo esc_html($it['name']); ?></b><small><?php echo esc_html(implode(' · ', array_filter([$it['kind'], $it['title']]))); ?></small></div>
                <?php if ($bulk): ?><label class="wfo-apv-pick" hidden><input type="checkbox" data-apv-pick aria-label="<?php echo esc_attr(sprintf(/* translators: %s: employee name */ __('Select the request of %s', 'workforce-one'), $it['name'])); ?>"></label><?php endif; ?>
            </div>
            <p class="wfo-apv-what"><?php echo esc_html($it['what']); ?></p>
            <?php if ($peer): /* a swap asked of the person (3.31.124): their answer, not an approval level */ ?>
            <div class="wfo-si-trade"><?php foreach ($it['trade'] as $i => [$who, $day]): ?><?php if ($i): ?><span class="wfo-si-arrow" aria-hidden="true"><?php echo \WorkforceOne\Ui\Icons::svg('swap', 18, 2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span><?php endif; ?><div><small><?php echo esc_html($who); ?></small><b><?php echo esc_html($day); ?></b></div><?php endforeach; ?></div>
            <div class="wfo-apv-meta"><span class="wfo-apv-pill is-brand"><?php esc_html_e('Your answer', 'workforce-one'); ?></span><span class="wfo-apv-pill"><?php echo esc_html(sprintf(/* translators: %s: time, e.g. "2 hours" */ __('%s ago', 'workforce-one'), human_time_diff((int) strtotime((string) $it['since']), (int) current_time('timestamp')))); ?></span></div>
            <?php if (($it['hint'] ?? '') !== ''): ?><p class="wfo-si-note<?php echo !empty($it['stale']) ? ' is-warn' : ''; ?>"><?php echo esc_html($it['hint']); ?></p><?php endif; ?>
            <?php else: ?>
            <div class="wfo-apv-meta">
                <?php if ($p['levels'] > 0): ?><span class="wfo-apv-pill"><?php echo esc_html(sprintf(/* translators: 1: level, 2: number of levels */ __('Level %1$s of %2$s', 'workforce-one'), $num($p['level']), $num($p['levels']))); ?></span><?php endif; ?>
                <span class="wfo-apv-pill<?php echo $p['overdue'] ? ' is-late' : ''; ?>"><?php echo esc_html(($p['overdue'] ? __('Overdue', 'workforce-one') . ' · ' : '') . sprintf(/* translators: %s: working hours */ __('Waiting %s working h', 'workforce-one'), $num(round($p['hours'], 1)))); ?></span>
                <?php if ($p['moved_from'] !== ''): ?><span class="wfo-apv-pill is-warn"><?php echo esc_html(sprintf(/* translators: %s: person */ __('Moved up from %s', 'workforce-one'), $p['moved_from'])); ?></span><?php endif; ?>
                <?php if (!empty($it['for'])): ?><span class="wfo-apv-pill is-brand" data-apv-for><?php echo esc_html(sprintf(/* translators: %s: the manager away */ __('For %s', 'workforce-one'), $it['for'])); ?></span><?php endif; ?>
                <?php if (!empty($it['with'])): ?><span class="wfo-apv-pill" data-apv-with><?php echo esc_html(sprintf(/* translators: %s: the substitute */ __('With %s while you are away', 'workforce-one'), $it['with'])); ?></span><?php endif; ?>
                <?php if ($it['admins']): ?><span class="wfo-apv-pill is-brand"><?php esc_html_e('For administrators', 'workforce-one'); ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!$peer): ?>
            <details class="wfo-apv-more">
                <summary><?php esc_html_e('Details', 'workforce-one'); ?></summary>
                <?php if ($it['facts']): ?><dl class="wfo-apv-facts"><?php foreach ($it['facts'] as [$k, $v]): ?><dt><?php echo esc_html($k); ?></dt><dd><?php echo esc_html($v); ?></dd><?php endforeach; ?></dl><?php endif; ?>
                <h3><?php esc_html_e('The path', 'workforce-one'); ?></h3>
                <ol class="wfo-apv-path">
                    <?php foreach ($p['steps'] as $st): ?><li class="is-<?php echo esc_attr($st['state']); ?>"><span class="wfo-apv-dot" aria-hidden="true"><?php echo isset($st['mark']) && $st['state'] !== 'done' ? esc_html($st['mark']) : ($st['state'] === 'done' ? '✓' : ($st['state'] === 'rejected' ? '✕' : esc_html($num($st['order'])))); ?></span><div><b><?php echo esc_html($st['label'] . ($st['who'] !== '' ? ' · ' . $st['who'] : '')); ?></b><small><?php echo esc_html($st['sub']); ?></small></div></li><?php endforeach; ?>
                </ol>
                <?php if ($can): ?>
                <label class="wfo-apv-note"><span><?php echo $it['note_required'] ? esc_html__('Note (needed to reject)', 'workforce-one') : esc_html__('Note (optional)', 'workforce-one'); ?></span><textarea name="note" rows="2" maxlength="500"></textarea></label>
                <p class="wfo-apv-err" data-apv-note-err hidden><?php esc_html_e('Write why you reject it first.', 'workforce-one'); ?></p>
                <?php endif; ?>
                <?php if (!empty($it['link'])): ?><p class="wfo-apv-link"><a href="<?php echo esc_url($it['link']); ?>"><?php echo $can ? esc_html__('Open its page', 'workforce-one') : esc_html__('Decide it on its page', 'workforce-one'); ?></a></p><?php endif; ?>
            </details>
            <?php endif; ?>
            <?php if ($can): $dec = $it['decisions'] ?? ['yes' => ['approve', __('Approve', 'workforce-one')], 'no' => ['reject', __('Reject', 'workforce-one')]]; ?>
            <div class="wfo-apv-acts">
                <?php if (!empty($dec['yes'])): ?><button type="submit" name="decision" value="<?php echo esc_attr($dec['yes'][0]); ?>" class="wfo-apv-btn is-ok"><?php echo esc_html($dec['yes'][1]); ?></button><?php endif; ?>
                <button type="submit" name="decision" value="<?php echo esc_attr($dec['no'][0]); ?>" class="wfo-apv-btn is-bad"<?php echo $peer ? '' : ' data-apv-reject'; ?>><?php echo esc_html($dec['no'][1]); ?></button>
            </div>
            <?php endif; ?>
            </form>
        </article>
    <?php endforeach; ?>
    </div>

    <div class="wfo-apv-bulk" data-apv-bulk hidden>
        <span data-apv-count></span>
        <button type="button" class="wfo-apv-btn is-ok" data-apv-bulk-go disabled><?php esc_html_e('Approve the selected', 'workforce-one'); ?></button>
        <button type="button" class="wfo-apv-btn" data-apv-select-done><?php esc_html_e('Done', 'workforce-one'); ?></button>
    </div>
    <script type="application/json" data-apv-i18n><?php echo wp_json_encode([
        /* translators: %d: number of requests */
        'selected' => __('%d selected', 'workforce-one'),
        /* translators: %d: most requests at a time */
        'max' => __('At most %d at a time.', 'workforce-one'),
        'working' => __('Approving…', 'workforce-one'),
    ]); ?></script>
</div>
