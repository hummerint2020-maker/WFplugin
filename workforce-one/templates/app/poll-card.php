<?php
/**
 * One employee poll (Dashboard card and Polls page): the vote form, the employee's vote and, when the
 * poll's settings allow, the results. Styles: assets/css/app-polls.css (new look, 3.31.53).
 *
 * @var array<string,mixed> $poll   built by poll_card()
 * @var int $more                   other open polls (Dashboard: a link to the Polls page)
 * @var string $polls_url
 * @var array{type:string,text:string}|null $flash  the thank-you or error after a vote
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
$open = $poll['state'] === 'open';
$state_label = $open ? __('Open', 'workforce-one') : __('Closed', 'workforce-one');
$closes = (string) $poll['closes'];
if ($closes === 'Closes tomorrow') $closes = __('Closes tomorrow', 'workforce-one');
elseif ($closes === 'Closes soon') $closes = __('Closes soon', 'workforce-one');
elseif (preg_match('/^Closes in (\d+) days$/', $closes, $m)) $closes = sprintf(/* translators: %d: number of days */ _n('Closes in %d day', 'Closes in %d days', (int) $m[1], 'workforce-one'), (int) $m[1]);
elseif (preg_match('/^Closes in (\d+) hours$/', $closes, $m)) $closes = sprintf(/* translators: %d: number of hours */ _n('Closes in %d hour', 'Closes in %d hours', (int) $m[1], 'workforce-one'), (int) $m[1]);
$total = $poll['results'] ? (int) $poll['results']['total'] : 0;
?>
<section class="ews-poll-card wfo-poll<?php echo $open ? '' : ' is-past'; ?>" data-poll="<?php echo (int) $poll['id']; ?>" data-state="<?php echo esc_attr($poll['state']); ?>">
    <div class="ews-poll-head wfo-poll-head">
        <span class="wfo-poll-icon" aria-hidden="true"><?php echo Icons::svg('polls', 22, 2); ?></span>
        <div class="wfo-poll-title">
            <span class="ews-poll-kicker wfo-poll-state<?php echo $open ? ' is-open' : ''; ?>"><?php echo Icons::svg($open ? 'sparkle' : 'check', 13, 2); ?><?php esc_html_e('Employee poll', 'workforce-one'); ?> · <?php echo esc_html($state_label); ?></span>
            <h3 dir="auto"><?php echo esc_html($poll['question']); ?></h3>
            <?php if ($poll['description'] !== ''): ?><p dir="auto"><?php echo esc_html($poll['description']); ?></p><?php endif; ?>
        </div>
    </div>
    <?php if ($closes !== '' || $poll['anonymous']): ?><div class="ews-poll-meta wfo-poll-meta"><?php if ($closes !== ''): ?><span><?php echo Icons::svg('overtime', 14, 2); ?><?php echo esc_html($closes); ?></span><?php endif; ?><?php if ($poll['anonymous']): ?><span><?php echo Icons::svg('lock', 14, 2); ?><?php esc_html_e('Anonymous', 'workforce-one'); ?></span><?php endif; ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="<?php echo $flash['type'] === 'success' ? 'ews-poll-success' : 'ews-poll-error'; ?> wfo-poll-flash" role="status"><?php echo Icons::svg($flash['type'] === 'success' ? 'check' : 'alert', 16, 2.2); ?><span><?php echo esc_html($flash['text']); ?></span></div><?php endif; ?>

    <?php if ($poll['results']): ?>
        <?php if ($poll['mine']): ?><p class="ews-poll-voted wfo-poll-voted"><?php echo Icons::svg('check', 15, 2.4); ?><?php echo $open ? esc_html__('Your vote has been recorded. Here’s the current result.', 'workforce-one') : esc_html__('You voted in this poll. Here’s the final result.', 'workforce-one'); ?></p><?php endif; ?>
        <ul class="ews-poll-results wfo-poll-results">
            <?php foreach ($poll['results']['rows'] as $r): $is_mine = (int) $r->id === (int) $poll['mine']; ?>
            <li class="ews-poll-result<?php echo $is_mine ? ' is-mine' : ''; ?>" data-choice="<?php echo esc_attr($r->option_text); ?>" data-votes="<?php echo (int) $r->votes; ?>">
                <span class="ews-poll-result-top"><span class="wfo-poll-choice" dir="auto"><?php echo esc_html($r->option_text); ?><?php if ($is_mine): ?> <em><?php echo Icons::svg('check', 13, 2.4); ?><?php esc_html_e('your vote', 'workforce-one'); ?></em><?php endif; ?></span><b><?php echo (int) $r->percentage; ?>%</b></span>
                <span class="ews-poll-bar" role="presentation"><i style="width:<?php echo (int) $r->percentage; ?>%"></i></span>
                <small><?php echo esc_html(sprintf(/* translators: %d: number of votes */ _n('%d vote', '%d votes', (int) $r->votes, 'workforce-one'), (int) $r->votes)); ?></small>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="ews-poll-total wfo-poll-total"><?php echo Icons::svg('people', 15); ?><?php echo esc_html(sprintf(/* translators: %d: number of people */ _n('%d person has voted', '%d people have voted', $total, 'workforce-one'), $total)); ?></p>
    <?php elseif ($poll['note'] !== ''): ?>
        <p class="ews-poll-note wfo-poll-note"><?php echo Icons::svg('check', 15, 2.4); ?><span><?php echo esc_html($poll['note']); ?></span></p>
    <?php endif; ?>

    <?php if ($poll['can_vote']): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-poll-form<?php echo $poll['changing'] ? ' is-change' : ''; ?>">
            <input type="hidden" name="ews_poll_nonce" value="<?php echo esc_attr($poll['nonce']); ?>"><input type="hidden" name="action" value="ews_poll_vote"><input type="hidden" name="poll_id" value="<?php echo (int) $poll['id']; ?>">
            <?php if ($poll['changing']): ?><details><summary><?php echo Icons::svg('swap', 15); ?><?php esc_html_e('Change vote', 'workforce-one'); ?></summary><?php endif; ?>
            <fieldset class="ews-poll-options wfo-poll-options">
                <legend class="screen-reader-text"><?php echo esc_html($poll['question']); ?></legend>
                <?php foreach ($poll['choices'] as $i => $o): ?><label class="ews-poll-option"><input type="radio" name="option_id" value="<?php echo (int) $o->id; ?>" <?php checked($poll['mine'] ? (int) $o->id === (int) $poll['mine'] : $i === 0); ?>><span class="ews-poll-radio" aria-hidden="true"></span><span class="wfo-poll-option-text" dir="auto"><?php echo esc_html($o->option_text); ?></span></label><?php endforeach; ?>
            </fieldset>
            <div class="ews-poll-footer wfo-poll-footer"><span><?php echo $poll['changing'] ? esc_html__('You can change your vote until the poll closes.', 'workforce-one') : esc_html__('Be part of the decision', 'workforce-one'); ?></span><button class="ews-btn wfo-poll-submit" type="submit"><?php echo Icons::svg('check', 16, 2.4); ?><?php echo $poll['changing'] ? esc_html__('Change vote', 'workforce-one') : esc_html__('Vote', 'workforce-one'); ?></button></div>
            <?php if ($poll['changing']): ?></details><?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if ($polls_url !== ''): ?><a class="ews-poll-more wfo-poll-more" href="<?php echo esc_url($polls_url); ?>"><span><?php echo $more > 0 ? esc_html(sprintf(/* translators: %d: number of other open polls */ _n('%d more open poll', '%d more open polls', $more, 'workforce-one'), $more)) . ' · ' : ''; ?><?php esc_html_e('See all polls', 'workforce-one'); ?></span><?php echo Icons::svg('arrow', 15, 2); ?></a><?php endif; ?>
</section>
