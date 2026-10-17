<?php
/**
 * My approvals → substitutes (3.31.107, mockup docs/mockups/substitutes/, screen 2): who the person
 * decides for now, and for a manager "I'll be away" (a substitute from a start to an end time) with
 * their own substitutes, each one ended or cancelled here. Data: EWS_Substitutes_Trait::substitute_app_data().
 *
 * @var array<string,mixed> $subs
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
if (empty($subs['on'])) return;
$x = '<button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="' . esc_attr__('Close', 'workforce-one') . '">' . Icons::svg('close', 20, 2) . '</button>';
$labels = ['pending' => __('Waits for the leave approval', 'workforce-one'), 'scheduled' => __('Scheduled', 'workforce-one'), 'active' => __('Active now', 'workforce-one')];
?>
<?php foreach ($subs['for'] as $f): ?>
<div class="wfo-apv-away" data-substitute-for>
    <span class="wfo-apv-away-ic" aria-hidden="true"><?php echo Icons::svg('people', 18, 2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></span>
    <span><b><?php echo esc_html(sprintf(/* translators: %s: manager name */ __('Deciding for %s', 'workforce-one'), $f['name'])); ?></b> <?php echo esc_html(sprintf(/* translators: 1: date and time, 2: number of requests */ _n('until %1$s · %2$s request', 'until %1$s · %2$s requests', (int) $f['waiting'], 'workforce-one'), $f['until'], number_format_i18n((int) $f['waiting']))); ?></span>
</div>
<?php endforeach; ?>

<?php if (!empty($subs['can_set'])): ?>
<div class="wfo-apv-own" data-substitute-own>
    <?php foreach ($subs['own'] as $o): ?>
    <div class="wfo-apv-own-row">
        <span><b><?php echo esc_html(sprintf(/* translators: %s: substitute name */ __('%s decides for you', 'workforce-one'), $o['name'])); ?></b><small><?php echo esc_html($o['from'] . ' → ' . $o['to'] . ' · ' . ($labels[$o['status']] ?? '')); ?></small></span>
        <?php if (!$o['leave']): ?>
        <form method="post" action="<?php echo esc_url($subs['post_url']); ?>"><?php wp_nonce_field('ews_substitute_end_' . (int) $o['id']); ?><input type="hidden" name="action" value="ews_substitute_end"><input type="hidden" name="id" value="<?php echo (int) $o['id']; ?>">
            <button type="submit" class="wfo-apv-btn is-sm is-bad"><?php echo $o['status'] === 'active' ? esc_html__('End now', 'workforce-one') : esc_html__('Cancel', 'workforce-one'); ?></button>
        </form>
        <?php else: ?><small class="wfo-apv-own-note"><?php esc_html_e('Follows your leave', 'workforce-one'); ?></small><?php endif; ?>
    </div>
    <?php endforeach; ?>
    <button type="button" class="wfo-apv-btn is-sm" data-wfo-sheet="wfo-away-sheet"><?php esc_html_e("I'll be away", 'workforce-one'); ?></button>
</div>

<dialog class="wfo-sheet" id="wfo-away-sheet" aria-labelledby="wfo-away-title">
    <form method="post" action="<?php echo esc_url($subs['post_url']); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_substitute_save'); ?><input type="hidden" name="action" value="ews_substitute_save">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-away-title"><?php esc_html_e("I'll be away", 'workforce-one'); ?></h2><?php echo $x; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?></div>
        <p class="wfo-sheet-note"><?php esc_html_e('For a business trip, a course or anything else. Requests that would wait for you wait for the person you pick, marked "for" you. For a leave, pick them in the leave request instead.', 'workforce-one'); ?></p>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field"><label for="wfo-away-from"><?php esc_html_e('From', 'workforce-one'); ?></label><input type="datetime-local" id="wfo-away-from" name="starts_at" min="<?php echo esc_attr($subs['now']); ?>" value="<?php echo esc_attr($subs['now']); ?>" required></div>
            <div class="wfo-rq-field"><label for="wfo-away-to"><?php esc_html_e('Until', 'workforce-one'); ?></label><input type="datetime-local" id="wfo-away-to" name="ends_at" min="<?php echo esc_attr($subs['now']); ?>" required></div>
        </div>
        <?php $sub_q = ['required' => true, 'people' => $subs['people']]; $suggested = array_values(array_filter($sub_q['people'], static function ($p) { return $p['suggested']; })); $others = array_values(array_filter($sub_q['people'], static function ($p) { return !$p['suggested']; })); ?>
        <div class="wfo-rq-field"><label for="wfo-away-who"><?php esc_html_e('Who decides for you', 'workforce-one'); ?></label>
            <select id="wfo-away-who" name="substitute_employee_id" required>
                <option value=""><?php esc_html_e('Pick someone', 'workforce-one'); ?></option>
                <?php if ($suggested): ?><optgroup label="<?php esc_attr_e('Suggested', 'workforce-one'); ?>"><?php foreach ($suggested as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name'] . ($p['sub'] !== '' ? ' · ' . $p['sub'] : '')); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($others): ?><optgroup label="<?php esc_attr_e('Everyone else', 'workforce-one'); ?>"><?php foreach ($others as $p): ?><option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html($p['name']); ?></option><?php endforeach; ?></optgroup><?php endif; ?>
            </select>
        </div>
        <div class="wfo-rq-field"><label for="wfo-away-reason"><?php esc_html_e('Reason', 'workforce-one'); ?> <span class="wfo-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></label><input type="text" id="wfo-away-reason" name="reason" maxlength="190"></div>
        <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg('check', 18, 2.2); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Save', 'workforce-one'); ?></button>
    </form>
</dialog>
<?php endif; ?>
