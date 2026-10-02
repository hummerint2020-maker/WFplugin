<?php
/**
 * Employee app: a colleague's profile. The sections follow wp-admin → Employee Profile settings.
 * Styles: assets/css/workforce-one.css (.wfo-employee-profile-*); script: assets/js/people.js (Kudos form).
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
$kudos_errors = [
    'limit' => 'You have reached your weekly Kudos limit. You can send more next week.',
    'duplicate' => 'You already sent this Kudos to this colleague today.',
    'category' => 'Please choose what you are recognizing.',
    'employee' => 'This colleague is no longer available.',
];
?>
<div class="wfo-profile-back"><a href="<?php echo esc_url($back_url); ?>">← Back to People</a></div>
<?php if (!empty($cfg['show_photo']) || !empty($cfg['show_name'])): ?>
<div class="wfo-employee-profile-head ews-card">
    <div class="wfo-employee-profile-person">
        <?php if (!empty($cfg['show_photo'])): ?><div class="wfo-employee-profile-avatar"><?php if ($picture !== ''): ?><img src="<?php echo esc_url($picture); ?>" alt=""><?php else: ?><span><?php echo esc_html($initials); ?></span><?php endif; ?></div><?php endif; ?>
        <?php if (!empty($cfg['show_name'])): ?><div class="wfo-employee-profile-title"><h2><?php echo esc_html($emp->name); ?></h2></div><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($cfg['show_team']) || !empty($cfg['show_email']) || !empty($cfg['show_supervisor'])): ?>
<div class="ews-card wfo-employee-profile-work"><h3>Work Profile</h3><div class="wfo-profile-fields">
    <?php if (!empty($cfg['show_team'])): ?><div><span>Teams</span><strong><?php echo esc_html($teams ? implode(', ', $teams) : 'No team assigned'); ?></strong></div><?php endif; ?>
    <?php if (!empty($cfg['show_email'])): ?><div><span>Work Email</span><strong><?php echo esc_html(!empty($emp->email) ? $emp->email : 'Not provided'); ?></strong></div><?php endif; ?>
    <?php if (!empty($cfg['show_supervisor'])): ?><div><span>Supervisor</span><strong><?php echo esc_html($supervisor !== '' ? $supervisor : 'Not assigned'); ?></strong></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($achievements !== null): ?>
<section class="ews-my-profile-panel">
    <div class="ews-section-head"><div><h3>🏆 Achievements</h3><p>Earned milestones</p></div><span class="ews-my-profile-section-link"><?php echo (int) count($achievements); ?> earned</span></div>
    <div class="ews-my-profile-body"><div class="ews-achievements-grid">
        <?php if (!$achievements): ?><div class="ews-profile-photo-help">No achievements earned yet.</div>
        <?php else: foreach ($achievements as $a): ?>
            <div class="ews-achievement-card"><div class="ews-achievement-icon <?php echo esc_attr($a->badge_style ?: 'circle'); ?>"><?php echo esc_html($a->icon); ?></div><div><div class="ews-achievement-name"><?php echo esc_html($a->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($a->description); ?></div><div class="ews-achievement-date">Earned <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($a->earned_at))); ?></div></div></div>
        <?php endforeach; endif; ?>
    </div></div>
</section>
<?php endif; ?>

<?php if ($recognition !== null): ?>
<section class="ews-my-profile-panel wfo-recognition-panel">
    <div class="ews-section-head"><div><h3>👏 Recognition</h3><p>Appreciation from colleagues</p></div><span class="ews-my-profile-section-link"><?php echo (int) count($recognition['rows']); ?> received</span></div>
    <div class="ews-my-profile-body">
        <?php if ($recognition['sent']): ?><div class="wfo-recognition-notice success">Kudos sent successfully.</div>
        <?php elseif ($recognition['error'] !== ''): ?><div class="wfo-recognition-notice error"><?php echo esc_html($kudos_errors[$recognition['error']] ?? 'Unable to send Kudos. Please check the details and try again.'); ?></div><?php endif; ?>
        <?php if ($recognition['can_kudos']): ?>
        <div class="wfo-kudos-action"><button type="button" class="ews-btn" data-wfo-kudos="toggle">👏 Give Kudos</button></div>
        <form id="wfo-kudos-form" class="wfo-kudos-form" method="post" action="<?php echo esc_url($post_url); ?>" hidden>
            <?php wp_nonce_field('ews_kudos_submit', 'ews_kudos_nonce'); ?><input type="hidden" name="action" value="ews_kudos_submit"><input type="hidden" name="recipient_employee_id" value="<?php echo (int) $emp->id; ?>">
            <label>What are you recognizing?<select name="category" required><?php foreach ($recognition['categories'] as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
            <label>Message <span class="wfo-kudos-optional">(optional)</span><textarea name="message" rows="3" maxlength="500" placeholder="Write a short thank-you..."></textarea></label>
            <div class="wfo-kudos-form-actions"><button type="submit" class="ews-btn">Send Kudos</button><button type="button" class="ews-btn secondary" data-wfo-kudos="close">Cancel</button></div>
        </form>
        <?php endif; ?>
        <?php if ($recognition['rows']): ?>
        <div class="wfo-recognition-list"><?php foreach ($recognition['rows'] as $row): ?>
            <article class="wfo-recognition-item"><div class="wfo-recognition-icon">👏</div><div><div class="wfo-recognition-title"><strong><?php echo esc_html($row->sender_name ?: 'A colleague'); ?></strong> · <?php echo esc_html($row->category_label); ?></div><?php if ($row->message !== ''): ?><div class="wfo-recognition-message">“<?php echo esc_html($row->message); ?>”</div><?php endif; ?><div class="wfo-recognition-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($row->created_at))); ?></div></div></article>
        <?php endforeach; ?></div>
        <?php elseif (!$recognition['can_kudos']): ?><div class="ews-empty-state"><div class="ews-empty-icon">👏</div><div class="ews-empty-title">No recognition yet</div><div class="ews-empty-text">Recognition from colleagues will appear here.</div></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
