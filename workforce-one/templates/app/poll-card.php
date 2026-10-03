<?php
/**
 * One employee poll (Dashboard card and Polls page): the vote form, the employee's vote and, when the
 * poll's settings allow, the results.
 *
 * @var array<string,mixed> $poll   built by poll_card()
 * @var int $more                   other open polls (Dashboard: a link to the Polls page)
 * @var string $polls_url
 * @var array{type:string,text:string}|null $flash  the thank-you or error after a vote
 */
if (!defined('ABSPATH')) exit;
$state_label = ['open' => 'Open', 'closed' => 'Closed', 'archived' => 'Closed'][$poll['state']] ?? '';
?>
<section class="ews-poll-card<?php echo $poll['state'] !== 'open' ? ' is-past' : ''; ?>" data-poll="<?php echo (int) $poll['id']; ?>" data-state="<?php echo esc_attr($poll['state']); ?>">
    <div class="ews-poll-head">
        <div><span class="ews-poll-kicker">EMPLOYEE POLL · <?php echo esc_html(strtoupper($state_label)); ?></span><h3><?php echo esc_html($poll['question']); ?></h3><?php if ($poll['description'] !== ''): ?><p><?php echo esc_html($poll['description']); ?></p><?php endif; ?></div>
        <span class="ews-poll-icon" aria-hidden="true">🗳️</span>
    </div>
    <?php if ($poll['closes'] !== '' || $poll['anonymous']): ?><div class="ews-poll-meta"><?php if ($poll['closes'] !== ''): ?><span>⏳ <?php echo esc_html($poll['closes']); ?></span><?php endif; ?><?php if ($poll['anonymous']): ?><span>🔒 Anonymous</span><?php endif; ?></div><?php endif; ?>
    <?php if ($flash): ?><div class="<?php echo $flash['type'] === 'success' ? 'ews-poll-success' : 'ews-poll-error'; ?>"><?php echo $flash['type'] === 'success' ? '✓ ' : ''; ?><?php echo esc_html($flash['text']); ?></div><?php endif; ?>

    <?php if ($poll['results']): ?>
        <?php if ($poll['mine']): ?><div class="ews-poll-voted">Your vote has been recorded. Here’s the current result.</div><?php endif; ?>
        <div class="ews-poll-results">
            <?php foreach ($poll['results']['rows'] as $r): ?>
            <div class="ews-poll-result<?php echo (int) $r->id === (int) $poll['mine'] ? ' is-mine' : ''; ?>" data-choice="<?php echo esc_attr($r->option_text); ?>" data-votes="<?php echo (int) $r->votes; ?>">
                <div class="ews-poll-result-top"><span><?php echo esc_html($r->option_text); ?><?php if ((int) $r->id === (int) $poll['mine']): ?> <em>· your vote</em><?php endif; ?></span><b><?php echo (int) $r->percentage; ?>%</b></div>
                <div class="ews-poll-bar"><i style="width:<?php echo (int) $r->percentage; ?>%"></i></div><small><?php echo (int) $r->votes; ?> vote<?php echo (int) $r->votes === 1 ? '' : 's'; ?></small>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="ews-poll-total"><?php echo (int) $poll['results']['total']; ?> <?php echo (int) $poll['results']['total'] === 1 ? 'person has' : 'people have'; ?> voted</div>
    <?php elseif ($poll['note'] !== ''): ?>
        <div class="ews-poll-note">✓ <?php echo esc_html($poll['note']); ?></div>
    <?php endif; ?>

    <?php if ($poll['can_vote']): ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-poll-form<?php echo $poll['changing'] ? ' is-change' : ''; ?>">
            <input type="hidden" name="ews_poll_nonce" value="<?php echo esc_attr($poll['nonce']); ?>"><input type="hidden" name="action" value="ews_poll_vote"><input type="hidden" name="poll_id" value="<?php echo (int) $poll['id']; ?>">
            <?php if ($poll['changing']): ?><details><summary>Change vote</summary><?php endif; ?>
            <div class="ews-poll-options">
                <?php foreach ($poll['choices'] as $i => $o): ?><label class="ews-poll-option"><input type="radio" name="option_id" value="<?php echo (int) $o->id; ?>" <?php checked($poll['mine'] ? (int) $o->id === (int) $poll['mine'] : $i === 0); ?>><span class="ews-poll-radio"></span><span><?php echo esc_html($o->option_text); ?></span></label><?php endforeach; ?>
            </div>
            <div class="ews-poll-footer"><span><?php echo $poll['changing'] ? 'You can change your vote until the poll closes.' : 'Be part of the decision ❤️'; ?></span><button class="ews-btn" type="submit"><?php echo $poll['changing'] ? 'Change vote' : 'Vote'; ?></button></div>
            <?php if ($poll['changing']): ?></details><?php endif; ?>
        </form>
    <?php endif; ?>
    <?php if ($more > 0 && $polls_url !== ''): ?><a class="ews-poll-more" href="<?php echo esc_url($polls_url); ?>"><?php echo (int) $more; ?> more open poll<?php echo $more === 1 ? '' : 's'; ?> → See all polls</a><?php elseif ($polls_url !== ''): ?><a class="ews-poll-more" href="<?php echo esc_url($polls_url); ?>">See all polls →</a><?php endif; ?>
</section>
