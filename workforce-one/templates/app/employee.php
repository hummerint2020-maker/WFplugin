<?php
/**
 * Employee app: a colleague's profile. The sections follow wp-admin → Employee Profile settings.
 * Styles: assets/css/app-profile.css (shared with My Profile) and assets/css/app-people.css (new look,
 * 3.31.55); script: assets/js/people.js (Kudos form).
 *
 * @var array<string,int> $cfg              employee_profile_settings()
 * @var object $emp
 * @var string $initials
 * @var string $picture                     '' = initials
 * @var string[] $teams
 * @var string $supervisor
 * @var object[]|null $achievements         null = section hidden
 * @var array{rows:object[],categories:array<string,string>,can_kudos:bool,sent:bool,error:string}|null $recognition
 * @var string $back_url
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
$kudos_errors = [
    'limit' => __('You have reached your weekly Kudos limit. You can send more next week.', 'workforce-one'),
    'duplicate' => __('You already sent this Kudos to this colleague today.', 'workforce-one'),
    'category' => __('Please choose what you are recognizing.', 'workforce-one'),
    'employee' => __('This colleague is no longer available.', 'workforce-one'),
];
$show_head = !empty($cfg['show_photo']) || !empty($cfg['show_name']);
$show_work = !empty($cfg['show_team']) || !empty($cfg['show_email']) || !empty($cfg['show_supervisor']);
?>
<div class="wfo-profile wfo-colleague">
    <a class="wfo-colleague-back" href="<?php echo esc_url($back_url); ?>"><?php echo Icons::svg('chevron', 16, 2.2); ?><span><?php esc_html_e('Back to People', 'workforce-one'); ?></span></a>

    <?php if ($show_head): ?>
    <section class="wfo-profile-hero" aria-label="<?php esc_attr_e('Employee Profile', 'workforce-one'); ?>">
        <?php if (!empty($cfg['show_photo'])): ?><div class="wfo-profile-photo"><div class="wfo-profile-avatar"><?php if ($picture !== ''): ?><img src="<?php echo esc_url($picture); ?>" alt=""><?php else: ?><span><?php echo esc_html($initials); ?></span><?php endif; ?></div></div><?php endif; ?>
        <div class="wfo-profile-who">
            <?php if (!empty($cfg['show_name'])): ?><h2><?php echo esc_html($emp->name); ?></h2><?php endif; ?>
            <?php if (!empty($cfg['show_team']) && $teams): ?><div class="wfo-profile-chips"><span class="wfo-chip is-none"><?php echo Icons::svg('people', 14); ?><span><?php echo esc_html(implode(', ', $teams)); ?></span></span></div><?php endif; ?>
        </div>
    </section>
    <?php endif; ?>

    <div class="wfo-profile-grid">
        <?php if ($show_work || $achievements !== null): ?>
        <div class="wfo-profile-col">
            <?php if ($show_work): ?>
            <section class="wfo-profile-card" aria-labelledby="wfo-work-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon"><?php echo Icons::svg('briefcase', 20); ?></span><div><h3 id="wfo-work-title"><?php esc_html_e('Work Profile', 'workforce-one'); ?></h3></div></div>
                <dl class="wfo-profile-fields is-single">
                    <?php if (!empty($cfg['show_team'])): ?><div><dt><?php esc_html_e('Teams', 'workforce-one'); ?></dt><dd><?php echo esc_html($teams ? implode(', ', $teams) : __('No team assigned', 'workforce-one')); ?></dd></div><?php endif; ?>
                    <?php if (!empty($cfg['show_email'])): ?><div><dt><?php esc_html_e('Work Email', 'workforce-one'); ?></dt><dd dir="ltr"><?php echo !empty($emp->email) ? '<a href="mailto:' . esc_attr($emp->email) . '">' . esc_html($emp->email) . '</a>' : esc_html__('Not provided', 'workforce-one'); ?></dd></div><?php endif; ?>
                    <?php if (!empty($cfg['show_supervisor'])): ?><div><dt><?php esc_html_e('Supervisor', 'workforce-one'); ?></dt><dd><?php echo esc_html($supervisor !== '' ? $supervisor : __('Not assigned', 'workforce-one')); ?></dd></div><?php endif; ?>
                </dl>
            </section>
            <?php endif; ?>

            <?php if ($achievements !== null): ?>
            <section class="wfo-profile-card" aria-labelledby="wfo-cach-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-amber"><?php echo Icons::svg('trophy', 20); ?></span><div><h3 id="wfo-cach-title"><?php esc_html_e('Achievements', 'workforce-one'); ?></h3><p><?php esc_html_e('Earned milestones', 'workforce-one'); ?></p></div><span class="wfo-profile-count"><?php echo esc_html(sprintf(/* translators: %d: number of achievements */ _n('%d earned', '%d earned', count($achievements), 'workforce-one'), count($achievements))); ?></span></div>
                <?php if (!$achievements): ?>
                    <p class="wfo-profile-empty"><?php esc_html_e('No achievements earned yet.', 'workforce-one'); ?></p>
                <?php else: ?>
                <ul class="wfo-profile-achievements">
                    <?php foreach ($achievements as $a): ?><li class="ews-achievement-card"><span class="ews-achievement-icon <?php echo esc_attr($a->badge_style ?: 'circle'); ?>"><?php echo esc_html($a->icon); ?></span><div><div class="ews-achievement-name"><?php echo esc_html($a->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($a->description); ?></div><div class="ews-achievement-date"><?php echo esc_html(sprintf(/* translators: %s: date */ __('Earned %s', 'workforce-one'), date_i18n(get_option('date_format'), strtotime($a->earned_at)))); ?></div></div></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($recognition !== null): ?>
        <div class="wfo-profile-col">
            <section class="wfo-profile-card wfo-recognition-panel" aria-labelledby="wfo-rec-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-purple"><?php echo Icons::svg('sparkle', 20); ?></span><div><h3 id="wfo-rec-title"><?php esc_html_e('Recognition', 'workforce-one'); ?></h3><p><?php esc_html_e('Appreciation from colleagues', 'workforce-one'); ?></p></div><span class="wfo-profile-count is-purple"><?php echo esc_html(sprintf(/* translators: %d: number of kudos */ _n('%d received', '%d received', count($recognition['rows']), 'workforce-one'), count($recognition['rows']))); ?></span></div>
                <?php if ($recognition['sent']): ?><div role="status" class="wfo-recognition-notice success"><?php echo Icons::svg('check', 16, 2.4); ?><span><?php esc_html_e('Kudos sent successfully.', 'workforce-one'); ?></span></div>
                <?php elseif ($recognition['error'] !== ''): ?><div role="status" class="wfo-recognition-notice error"><?php echo esc_html($kudos_errors[$recognition['error']] ?? __('Unable to send Kudos. Please check the details and try again.', 'workforce-one')); ?></div><?php endif; ?>
                <?php if ($recognition['can_kudos']): ?>
                <button type="button" class="ews-btn wfo-profile-btn is-wide" data-wfo-kudos="toggle"><?php echo Icons::svg('sparkle', 16); ?><?php esc_html_e('Give Kudos', 'workforce-one'); ?></button>
                <form id="wfo-kudos-form" class="wfo-kudos-form" method="post" action="<?php echo esc_url($post_url); ?>" hidden>
                    <?php wp_nonce_field('ews_kudos_submit', 'ews_kudos_nonce'); ?><input type="hidden" name="action" value="ews_kudos_submit"><input type="hidden" name="recipient_employee_id" value="<?php echo (int) $emp->id; ?>">
                    <label class="wfo-kudos-field"><span><?php esc_html_e('What are you recognizing?', 'workforce-one'); ?></span><select name="category" required><?php foreach ($recognition['categories'] as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label class="wfo-kudos-field"><span><?php esc_html_e('Message', 'workforce-one'); ?> <span class="wfo-kudos-optional"><?php esc_html_e('(optional)', 'workforce-one'); ?></span></span><textarea name="message" rows="3" maxlength="500" placeholder="<?php esc_attr_e('Write a short thank-you...', 'workforce-one'); ?>"></textarea></label>
                    <div class="wfo-kudos-form-actions"><button type="submit" class="ews-btn wfo-profile-btn"><?php echo Icons::svg('check', 16, 2.4); ?><?php esc_html_e('Send Kudos', 'workforce-one'); ?></button><button type="button" class="ews-btn secondary wfo-profile-btn is-ghost" data-wfo-kudos="close"><?php esc_html_e('Cancel', 'workforce-one'); ?></button></div>
                </form>
                <?php endif; ?>
                <?php if ($recognition['rows']): ?>
                <ul class="wfo-recognition-list"><?php foreach ($recognition['rows'] as $row): ?>
                    <li class="wfo-recognition-item"><span class="wfo-recognition-icon" aria-hidden="true"><?php echo Icons::svg('sparkle', 18); ?></span><div><div class="wfo-recognition-title"><strong><?php echo esc_html($row->sender_name ?: __('A colleague', 'workforce-one')); ?></strong> <span class="wfo-chip is-leave"><span><?php echo esc_html($row->category_label); ?></span></span></div><?php if ($row->message !== ''): ?><p class="wfo-recognition-message" dir="auto">“<?php echo esc_html($row->message); ?>”</p><?php endif; ?><div class="wfo-recognition-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($row->created_at))); ?></div></div></li>
                <?php endforeach; ?></ul>
                <?php elseif (!$recognition['can_kudos']): ?><p class="wfo-profile-empty"><?php esc_html_e('No recognition yet. Recognition from colleagues will appear here.', 'workforce-one'); ?></p><?php endif; ?>
            </section>
        </div>
        <?php endif; ?>
    </div>
</div>
