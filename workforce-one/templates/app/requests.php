<?php
/**
 * Employee app → My requests (3.31.103, owner-approved mockup docs/mockups/my-approvals/): the
 * person's own leave, overtime, corrections and swaps, newest first, each with where it is level by
 * level; one still waiting can be withdrawn (leave, overtime, early leave, correction). Styles: assets/css/app-approvals.css.
 *
 * @var list<array<string,mixed>> $items
 * @var string $post_url
 * @var array{type:string,text:string}|null $notice
 * @var string $filter
 * @var string $base_url
 * @var array<string,mixed> $new     the New request sheet (3.31.105, templates/app/new-request.php)
 * @var bool $open_new                ?new=1 opens it (the home page's shortcut)
 * @var array{balances:list<array<string,mixed>>,kind:string,page:int,more:bool,all_url:string,leave_url:string,shown:bool}|null $leave
 *      Leave in My requests (3.31.158, docs/tasks/leave-in-requests.md): null while it is off.
 * @var array{left:int,limit:int,used:int,after:string}|null $cx_balance
 *      Corrections balance in My requests (3.31.163, docs/tasks/corrections-balance.md): null while it is off or without a monthly limit.
 */
if (!defined('ABSPATH')) exit;
$leave = $leave ?? null;
$cx_balance = $cx_balance ?? null;
$leave_bals = $leave ? (array) $leave['balances'] : [];
$num = static function ($n): string { return number_format_i18n((float) $n); };
$labels = ['waiting' => __('Waiting', 'workforce-one'), 'approved' => __('Approved', 'workforce-one'), 'rejected' => __('Rejected', 'workforce-one'), 'withdrawn' => __('Withdrawn', 'workforce-one'), 'cancelled' => __('Cancelled', 'workforce-one'), 'expired' => __('Expired', 'workforce-one')];
$counts = array_count_values(array_map(static function ($it) { return $it['state']; }, $items));
$shown = $filter !== '' && isset($labels[$filter]) ? array_values(array_filter($items, static function ($it) use ($filter) { return $it['state'] === $filter; })) : $items;
?>
<div class="wfo-apv is-mine">
    <?php if ($notice): ?><div class="wfo-apv-toast is-<?php echo esc_attr($notice['type']); ?>" role="status"><?php echo esc_html($notice['text']); ?></div><?php endif; ?>
<?php if ($leave_bals || $cx_balance): /* no indent: nothing printed while it is off (golden) */ ?>
    <section class="wfo-lr-bals" aria-labelledby="wfo-lr-bals-h">
        <div class="wfo-lr-bals-h"><?php echo \WorkforceOne\Ui\Icons::svg($leave_bals ? 'leave' : 'time', 16, 2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><b id="wfo-lr-bals-h"><?php esc_html_e('Your balance', 'workforce-one'); ?></b><?php if ($leave_bals): ?><small><?php esc_html_e('This year · after requests waiting', 'workforce-one'); ?></small><?php endif; ?></div>
        <div class="wfo-lr-bals-row">
        <?php foreach ($leave_bals as $i => $t): ?>
            <div class="wfo-lr-bal tone-<?php echo (int) ($i % 4); ?>"><span dir="auto"><?php echo esc_html($t['name']); ?></span><?php if ($t['deducts'] && $t['available'] !== ''): ?><b><?php echo esc_html($t['available']); ?></b><small><?php echo esc_html(sprintf(/* translators: %s: days a year */ __('of %s days', 'workforce-one'), $t['of'] ?? '')); ?></small><?php else: ?><b>—</b><small><?php esc_html_e('No balance deduction', 'workforce-one'); ?></small><?php endif; ?></div>
        <?php endforeach; ?>
<?php if ($cx_balance): /* no indent: nothing printed while it is off (golden) */ ?>
            <div class="wfo-lr-bal is-cx<?php echo $cx_balance['after'] !== '' ? ' is-' . esc_attr($cx_balance['after']) : ''; ?>"><span><?php esc_html_e('Attendance corrections', 'workforce-one'); ?></span><b><?php echo esc_html($num($cx_balance['left'])); ?></b><small><?php
            if ($cx_balance['after'] === 'hr') esc_html_e('None left this month · the next ones go to HR', 'workforce-one');
            elseif ($cx_balance['after'] === 'refuse') esc_html_e('None left this month', 'workforce-one');
            else echo esc_html(sprintf(/* translators: %s: corrections a month */ __('of %s this month', 'workforce-one'), $num($cx_balance['limit'])));
            ?></small></div>
<?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
    <?php if (!empty($new['kinds'])): ?><div class="wfo-apv-bar"><span class="wfo-apv-bar-gap"></span><button type="button" class="wfo-apv-btn is-new" data-wfo-sheet="wfo-new-sheet">+ <?php esc_html_e('New request', 'workforce-one'); ?></button></div><?php endif; ?>
    <?php if ($items || ($leave && $leave['kind'] !== '')): ?>
    <nav class="wfo-apv-filters" aria-label="<?php esc_attr_e('Show', 'workforce-one'); ?>">
        <a href="<?php echo esc_url($leave ? $leave['all_url'] : $base_url); ?>"<?php echo $filter === '' && (!$leave || $leave['kind'] === '') ? ' aria-current="true"' : ''; ?>><?php esc_html_e('All', 'workforce-one'); ?></a><?php if ($leave): ?><a href="<?php echo esc_url($leave['leave_url']); ?>"<?php echo $filter === '' && $leave['kind'] === 'leave' ? ' aria-current="true"' : ''; ?>><?php esc_html_e('Leave', 'workforce-one'); ?></a><?php endif; ?>

        <?php foreach ($labels as $k => $l): if (empty($counts[$k])) continue; ?><a href="<?php echo esc_url(add_query_arg('req_state', $k, $base_url)); ?>"<?php echo $filter === $k ? ' aria-current="true"' : ''; ?>><?php echo esc_html($l . ' · ' . $num($counts[$k])); ?></a><?php endforeach; ?>
    </nav>
    <?php else: ?>
    <div class="wfo-apv-empty"><strong><?php esc_html_e('You have no requests yet.', 'workforce-one'); ?></strong><span><?php esc_html_e('Leave, overtime, early leave, corrections and swaps you ask for show here, with who decides them.', 'workforce-one'); ?></span></div>
    <?php endif; ?>

    <div class="wfo-apv-list">
    <?php foreach ($shown as $it): $p = $it['path']; ?>
        <article class="wfo-apv-item tone-<?php echo esc_attr($it['tone']); ?>" data-req="<?php echo esc_attr($it['key']); ?>">
            <div class="wfo-apv-who">
                <div class="wfo-apv-t"><b><?php echo esc_html($it['title']); ?></b><small><?php echo esc_html(implode(' · ', array_filter([$it['sub'], date_i18n('D j M, H:i', strtotime((string) $it['when']))]))); ?></small></div>
                <span class="wfo-apv-state is-<?php echo esc_attr($it['state']); ?>"><?php echo esc_html((($it['state_label'] ?? '') !== '' ? $it['state_label'] : $labels[$it['state']]) . ($it['state'] === 'waiting' && $p && $p['level'] ? ' · ' . sprintf(/* translators: 1: level, 2: number of levels */ __('level %1$s of %2$s', 'workforce-one'), $num($p['level']), $num($p['levels'])) : '')); ?></span>
            </div>
            <?php if (($it['note'] ?? '') !== ''): ?><p class="wfo-apv-why"><?php echo esc_html($it['note']); ?></p><?php endif; ?>
<?php if (!empty($it['sc_days'])): $sc_days = $it['sc_days']; $sc_class = 'is-mini'; $sc_change = false; include __DIR__ . '/sc-days.php'; endif; /* a schedule change's days not applied (3.31.165; no indent: nothing printed without it) */ ?>
            <?php if ($p && $p['steps']): ?>
            <details class="wfo-apv-more"<?php echo $it['state'] === 'waiting' ? ' open' : ''; ?>>
                <summary><?php esc_html_e('Who decides it', 'workforce-one'); ?></summary>
                <ol class="wfo-apv-path">
                    <?php foreach ($p['steps'] as $st): ?><li class="is-<?php echo esc_attr($st['state']); ?>"><span class="wfo-apv-dot" aria-hidden="true"><?php echo isset($st['mark']) && $st['state'] !== 'done' ? esc_html($st['mark']) : ($st['state'] === 'done' ? '✓' : ($st['state'] === 'rejected' ? '✕' : esc_html($num($st['order'])))); ?></span><div><b><?php echo esc_html($st['label'] . ($st['who'] !== '' ? ' · ' . $st['who'] : '')); ?></b><small><?php echo esc_html($st['sub']); ?></small><?php if (($st['why'] ?? '') !== ''): ?><small class="wfo-apv-pwhy"><?php echo esc_html($st['why']); ?></small><?php endif; ?></div></li><?php endforeach; ?>
                </ol>
            </details>
            <?php endif; ?>
            <?php if (($it['download'] ?? '') !== ''): /* HR letters (HL3): the letter's PDF */ ?><a class="wfo-apv-btn is-ok is-sm wfo-hl-dl" href="<?php echo esc_url($it['download']); ?>" target="_blank" rel="noopener"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16, 2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Download the letter (PDF)', 'workforce-one'); ?></a><?php endif; ?>
            <?php if ($it['withdraw'] !== ''): ?>
            <details class="wfo-apv-withdraw">
                <summary class="wfo-apv-btn is-bad is-sm"><?php esc_html_e('Withdraw', 'workforce-one'); ?></summary>
                <form method="post" action="<?php echo esc_url($post_url); ?>">
                    <?php wp_nonce_field('ews_request_withdraw_' . $it['withdraw'] . '_' . (int) $it['id']); ?>
                    <input type="hidden" name="action" value="ews_request_withdraw"><input type="hidden" name="kind" value="<?php echo esc_attr($it['withdraw']); ?>"><input type="hidden" name="id" value="<?php echo (int) $it['id']; ?>">
                    <p><?php echo $it['withdraw'] === 'leave_cancel' ? (!empty($it['again']) ? esc_html__('Withdraw the change? The leave stays as it is; you can ask again later.', 'workforce-one') : esc_html__('Withdraw the cancellation? The leave stays as it is, and a leave can be cancelled only once.', 'workforce-one')) : ($it['withdraw'] === 'leave' ? esc_html__('Withdraw it? The days go back to your balance.', 'workforce-one') : ($it['withdraw'] === 'swap' ? esc_html__('Withdraw it? Your colleague will no longer be asked, and is told.', 'workforce-one') : esc_html__('Withdraw it? Nobody will need to decide it.', 'workforce-one'))); ?></p>
                    <button type="submit" class="wfo-apv-btn is-bad"><?php esc_html_e('Yes, withdraw it', 'workforce-one'); ?></button>
                </form>
            </details>
            <?php elseif (!empty($it['change'])): $ch = $it['change']; /* "Change the leave" (3.31.158, owner): all of it before it starts, or the day back at work */ ?>
            <details class="wfo-apv-withdraw wfo-lr-cancel">
                <summary class="wfo-apv-btn is-sm wfo-lr-back"><?php esc_html_e('Change the leave', 'workforce-one'); ?></summary>
                <form class="wfo-lr-change" method="post" action="<?php echo esc_url($post_url); ?>" data-lr-change data-start="<?php echo esc_attr($ch['start']); ?>" data-end="<?php echo esc_attr($ch['max']); ?>" data-days="<?php echo esc_attr((string) $ch['days']); ?>"
                    data-wd="<?php echo esc_attr(wp_json_encode(array_values((array) ($new['working_days'] ?? [])))); ?>"
                    data-t-all="<?php /* translators: %s: number of days */ esc_attr_e('The whole leave is cancelled; %s days go back to your balance.', 'workforce-one'); ?>"
                    data-t-back="<?php /* translators: 1: first day, 2: last day, 3: days left, 4: days back */ esc_attr_e('The leave becomes %1$s – %2$s (%3$s days). %4$s days go back to your balance.', 'workforce-one'); ?>"
                    data-t-pick="<?php esc_attr_e('Pick the day you are back at work.', 'workforce-one'); ?>">
                    <?php wp_nonce_field('ews_leave_cancel'); ?>
                    <input type="hidden" name="action" value="ews_leave_cancel"><input type="hidden" name="request_id" value="<?php echo (int) $it['id']; ?>"><input type="hidden" name="ews_return" value="requests">
                    <?php if ($ch['whole']): ?>
                    <label class="wfo-lr-opt"><input type="radio" name="lr_mode" value="all"<?php echo $ch['min'] === '' ? ' checked' : ''; ?>><span><b><?php esc_html_e('Cancel all of it', 'workforce-one'); ?></b><small><?php echo esc_html(sprintf(/* translators: %s: number of days */ _n('The %s day goes back to your balance.', 'The %s days go back to your balance.', (int) ceil($ch['days']), 'workforce-one'), $num($ch['days']))); ?></small></span></label>
                    <?php endif; ?>
                    <?php if ($ch['min'] !== ''): ?>
                    <label class="wfo-lr-opt"><input type="radio" name="lr_mode" value="back" checked><span><b><?php echo $ch['whole'] ? esc_html__("I'll be back at work on", 'workforce-one') : esc_html__("I'm back at work on", 'workforce-one'); ?></b><small><?php echo $ch['whole'] ? esc_html__('After its first day, up to its last.', 'workforce-one') : esc_html__('Today or later, up to the last day of the leave.', 'workforce-one'); ?></small>
                        <span class="wfo-lr-date"><input type="date" name="back_on" value="<?php echo esc_attr($ch['min']); ?>" min="<?php echo esc_attr($ch['min']); ?>" max="<?php echo esc_attr($ch['max']); ?>" required aria-label="<?php esc_attr_e('The day you are back at work', 'workforce-one'); ?>"></span></span></label>
                    <?php endif; ?>
                    <p class="wfo-lr-sum" data-lr-sum aria-live="polite"></p>
                    <p class="wfo-lr-note"><?php echo !empty($ch['again']) ? esc_html__('Your manager decides; until then the leave stands as it is. You can change it again later while leave days remain.', 'workforce-one') : esc_html__('Your manager decides; until then the leave stands as it is. This is the one change this leave can have.', 'workforce-one'); ?></p>
                    <button type="submit" class="wfo-apv-btn is-ok"><?php esc_html_e('Send the change', 'workforce-one'); ?></button>
                </form>
            </details>
            <?php elseif (!empty($it['link']) && $it['state'] === 'waiting'): ?>
            <p class="wfo-apv-link"><a href="<?php echo esc_url($it['link']); ?>"><?php esc_html_e('Open it on the Schedule page', 'workforce-one'); ?></a></p>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    </div>
<?php if ($leave && $leave['kind'] === 'leave' && ($leave['page'] > 1 || $leave['more'])): ?>
    <nav class="wfo-lr-pages" aria-label="<?php esc_attr_e('Pages', 'workforce-one'); ?>">
        <?php $page_base = $filter !== '' ? add_query_arg('req_state', $filter, $base_url) : $base_url; ?>
        <?php if ($leave['page'] > 1): ?><a href="<?php echo esc_url(add_query_arg('req_page', $leave['page'] - 1, $page_base)); ?>"><?php esc_html_e('Newer', 'workforce-one'); ?></a><?php endif; ?>
        <span><?php echo esc_html(sprintf(/* translators: %s: page number */ __('Page %s', 'workforce-one'), $num($leave['page']))); ?></span>
        <?php if ($leave['more']): ?><a href="<?php echo esc_url(add_query_arg('req_page', $leave['page'] + 1, $page_base)); ?>"><?php esc_html_e('Older', 'workforce-one'); ?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/new-request.php'; ?>
