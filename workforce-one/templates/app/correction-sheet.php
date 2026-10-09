<?php
/**
 * The attendance correction request form, in the app's bottom sheet (3.31.74). Opened by a day's
 * button in "My recent days", by "Request correction" on the Corrections page, or by the end-of-day
 * push (?cx=out&cx_date=…). assets/js/corrections.js fills the day and shows the right fields.
 *
 * @var array<string,array{label:string,photo:string}> $types  enabled types
 * @var array<int,array<string,mixed>> $days   recent days (the day picker)
 * @var array<string,mixed> $settings
 * @var int    $used
 * @var string $post_url
 * @var string $manager     the employee's supervisor ('' = not set)
 * @var string $open        the type to open with ('' = closed)
 * @var string $open_date
 * @var string $error       the refused request's message
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
$icons = ['out' => 'logout', 'in' => 'login', 'time' => 'clock', 'day' => 'calendar'];
$limit = (int) $settings['monthly_limit'];
?>
<dialog class="wfo-sheet wfo-cx-sheet" id="wfo-cx-sheet" aria-labelledby="wfo-cx-sheet-title" data-open="<?php echo esc_attr($open); ?>" data-open-date="<?php echo esc_attr($open_date); ?>" data-show-error="<?php echo $error !== '' ? '1' : ''; ?>">
    <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body" enctype="multipart/form-data">
        <?php wp_nonce_field('ews_correction_request'); ?>
        <input type="hidden" name="action" value="ews_correction_request">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-cx-sheet-title"><?php esc_html_e('Request a correction', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <?php if ($error !== ''): ?><p class="wfo-cx-error" role="alert"><?php echo Icons::svg('alert', 18, 2.2); ?><span><?php echo esc_html($error); ?></span></p><?php endif; ?>

        <div class="wfo-rq-field"><label for="wfo-cx-date"><?php esc_html_e('Day', 'workforce-one'); ?></label>
            <select id="wfo-cx-date" name="date" required>
                <?php foreach ($days as $d): ?><option value="<?php echo esc_attr($d['date']); ?>" data-in="<?php echo esc_attr($d['in']); ?>" data-out="<?php echo esc_attr($d['out']); ?>"<?php disabled(!$d['can']); ?>><?php echo esc_html(date_i18n('l j F', strtotime($d['date'])) . ($d['can'] ? '' : ' · ' . __('waiting', 'workforce-one'))); ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="wfo-cx-record" aria-live="polite"><div><span><?php esc_html_e('Sign In', 'workforce-one'); ?></span><strong data-cx-rec-in>—</strong></div><div><span><?php esc_html_e('Sign Out', 'workforce-one'); ?></span><strong data-cx-rec-out>—</strong></div></div>

        <div class="wfo-rq-field" role="group" aria-labelledby="wfo-cx-type-label"><span class="wfo-rq-label" id="wfo-cx-type-label"><?php esc_html_e('What happened?', 'workforce-one'); ?></span>
            <div class="wfo-cx-types">
                <?php $first = true; foreach ($types as $t => $info): ?>
                    <label class="wfo-cx-type"><input type="radio" name="type" value="<?php echo esc_attr($t); ?>" data-photo="<?php echo esc_attr($info['photo']); ?>"<?php checked($first); ?>><span><?php echo Icons::svg($icons[$t] ?? 'edit', 18, 2); ?><?php echo esc_html($info['label']); ?></span></label>
                <?php $first = false; endforeach; ?>
            </div>
        </div>
        <div class="wfo-rq-field" data-cx-show="time"><label for="wfo-cx-target"><?php esc_html_e('Which time is wrong?', 'workforce-one'); ?></label>
            <select id="wfo-cx-target" name="target"><option value="sign_in"><?php esc_html_e('Sign In', 'workforce-one'); ?></option><option value="sign_out"><?php esc_html_e('Sign Out', 'workforce-one'); ?></option></select>
        </div>
        <div class="wfo-rq-two">
            <div class="wfo-rq-field" data-cx-show="in day time-in"><label for="wfo-cx-in"><?php esc_html_e('Correct Sign In time', 'workforce-one'); ?></label><input id="wfo-cx-in" type="time" name="time_in"></div>
            <div class="wfo-rq-field" data-cx-show="out day time-out"><label for="wfo-cx-out"><?php esc_html_e('Correct Sign Out time', 'workforce-one'); ?></label><input id="wfo-cx-out" type="time" name="time_out"></div>
        </div>
        <div class="wfo-rq-field"><label for="wfo-cx-reason"><?php esc_html_e('Reason', 'workforce-one'); ?></label><textarea id="wfo-cx-reason" name="reason" rows="3" required placeholder="<?php esc_attr_e('What happened?', 'workforce-one'); ?>"></textarea></div>
        <label class="wfo-cx-photo" data-cx-photo><?php echo Icons::svg('camera', 22, 2); ?><div><strong data-cx-photo-label data-optional="<?php esc_attr_e('Photo (optional)', 'workforce-one'); ?>" data-required="<?php esc_attr_e('Photo (required)', 'workforce-one'); ?>"><?php esc_html_e('Photo (optional)', 'workforce-one'); ?></strong><span data-cx-photo-name><?php esc_html_e('For example, a photo from the customer\'s site', 'workforce-one'); ?></span></div><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="screen-reader-text"></label>
        <?php if ($limit > 0): ?><p class="wfo-cx-limit<?php echo $used >= $limit ? ' is-warn' : ''; ?>"><?php echo Icons::svg($used >= $limit ? 'alert' : 'check', 16, 2.4); ?><?php
            if ($used >= $limit) echo esc_html($settings['above_limit'] === 'hr' ? __('You have used this month\'s corrections: this request goes to HR.', 'workforce-one') : __('You have used all your corrections for this month.', 'workforce-one'));
            /* translators: 1: this request's number, 2: monthly limit */
            else echo esc_html(sprintf(__('This is request %1$d of the %2$d allowed this month', 'workforce-one'), $used + 1, $limit));
        ?></p><?php endif; ?>
        <button class="wfo-sheet-submit" type="submit" data-fwd><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send request', 'workforce-one'); ?></button>
        <p class="wfo-sheet-note"><?php echo esc_html($manager !== '' ? sprintf(/* translators: %s: manager's name */ __('Your manager %s reviews it. The original record never changes.', 'workforce-one'), $manager) : __('Your manager reviews it. The original record never changes.', 'workforce-one')); ?></p>
    </form>
</dialog>
