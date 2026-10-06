<?php
/**
 * Employee app: My Profile. Styles: assets/css/app-profile.css (new look, 3.31.54);
 * script: assets/js/my-profile.js (picture and password dialogs, avatar picker; it uses the ids
 * and the .ews-avatar-filter / .ews-avatar-choice classes below).
 *
 * @var object $emp
 * @var string $planned                today's schedule (General Leave on a company holiday)
 * @var string $today_result
 * @var string $sign_in                raw event time ('' = none)
 * @var string $hours_label
 * @var array{name:string}|null $shift
 * @var object|null $supervisor
 * @var string $team_label
 * @var string $today
 * @var array<string,string> $week      date => status
 * @var array<int,array{date:string,in:string,out:string,total:string,status:string}> $recent
 * @var int $year
 * @var array<int,array{name:string,entitlement:float,available:float,used:float,pending:float}> $leave_balances
 * @var object[]|null $achievements    null = Achievements switched off
 * @var string $display_image
 * @var string $initials
 * @var array<string,array{label:string,category:string,style:string}> $catalog
 * @var array<string,string> $avatar_urls
 * @var string $avatar_key
 * @var string $profile_updated
 * @var string $profile_error
 * @var string $password_updated
 * @var string $password_error
 * @var array<string,string> $urls       schedule, vacation, time
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Support\Format;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $avatar() and $link() escape their output.
$status_label = static function (string $s): string {
    $known = ['Not Set' => __('Not Set', 'workforce-one'), 'General Leave' => __('General Leave', 'workforce-one'), 'Absent' => __('Absent', 'workforce-one')];
    return $known[$s] ?? $s;
};
$avatar = static function (string $extra = '') use ($display_image, $initials, $emp): string {
    $inner = $display_image ? '<img src="' . esc_url($display_image) . '" alt="' . esc_attr($emp->name) . '">' : esc_html($initials);
    return '<div class="ews-my-profile-avatar wfo-profile-avatar' . $extra . '">' . $inner . '</div>';
};
$link = static function (string $url, string $label): string {
    return '<a class="wfo-profile-link" href="' . esc_url($url) . '"><span>' . esc_html($label) . '</span>' . Icons::svg('arrow', 15, 2) . '</a>';
};
$notice = '';
$notice_ok = true;
if ($profile_updated) $notice = $profile_updated === 'reset' ? __('Profile picture reset to initials.', 'workforce-one') : ($profile_updated === 'avatar' ? __('Avatar selected successfully.', 'workforce-one') : __('Profile picture updated successfully.', 'workforce-one'));
if ($profile_error) { $notice_ok = false; $notice = $profile_error === 'size' ? __('Image must be 2 MB or smaller.', 'workforce-one') : ($profile_error === 'type' ? __('Please upload a JPG, PNG, or WebP image.', 'workforce-one') : __('Could not upload the profile picture. Please try again.', 'workforce-one')); }
if ($password_updated) $notice = __('Password updated successfully.', 'workforce-one');
if ($password_error) {
    $notice_ok = false;
    $notice = ['current' => __('Current password is incorrect.', 'workforce-one'), 'mismatch' => __('New passwords do not match.', 'workforce-one'),
        'weak' => __('New password must be at least 8 characters and include uppercase, lowercase, number and special character.', 'workforce-one'),
        'same' => __('New password must be different from the current password.', 'workforce-one')][$password_error] ?? __('Could not update your password. Please try again.', 'workforce-one');
}
$shift_name = $shift ? $shift['name'] : __('Company Default', 'workforce-one');
$filters = ['all' => __('All', 'workforce-one'), 'Men' => __('Men', 'workforce-one'), 'Women' => __('Women', 'workforce-one'), 'Professional' => __('Professional', 'workforce-one'), 'Casual' => __('Casual', 'workforce-one'), 'Fun' => __('Fun', 'workforce-one')];
?>
<div class="ews-my-profile wfo-profile">
    <?php if ($notice !== ''): ?><div class="ews-profile-notice wfo-profile-notice<?php echo $notice_ok ? '' : ' error'; ?>" role="status"><?php echo Icons::svg($notice_ok ? 'check' : 'alert', 18, 2.2); ?><span><?php echo esc_html($notice); ?></span></div><?php endif; ?>

    <section class="wfo-profile-hero" aria-label="<?php esc_attr_e('My Profile', 'workforce-one'); ?>">
        <div class="wfo-profile-photo">
            <?php echo $avatar(); ?>
            <button type="button" class="ews-profile-avatar-edit wfo-profile-edit" id="ews-open-profile-picture" aria-label="<?php esc_attr_e('Edit profile picture', 'workforce-one'); ?>" title="<?php esc_attr_e('Edit profile picture', 'workforce-one'); ?>"><?php echo Icons::svg('edit', 17, 2); ?></button>
        </div>
        <div class="wfo-profile-who">
            <h2 dir="auto"><?php echo esc_html($emp->name); ?></h2>
            <p class="wfo-profile-sub" dir="ltr"><?php echo esc_html($emp->email ?: $emp->domain_name); ?></p>
            <div class="wfo-profile-chips">
                <span class="wfo-chip is-office"><?php echo Icons::svg('check', 14, 2.4); ?><span><?php esc_html_e('Active', 'workforce-one'); ?></span></span>
                <span class="wfo-chip is-none"><?php echo Icons::svg('people', 14); ?><span><?php echo esc_html($team_label); ?></span></span>
            </div>
        </div>
    </section>

    <div class="wfo-profile-today">
        <div class="wfo-profile-stat tone-blue"><span class="wfo-profile-stat-icon"><?php echo Icons::svg('calendar', 20); ?></span><div><div class="label"><?php esc_html_e('Today · Planned', 'workforce-one'); ?></div><div class="value"><?php echo esc_html($status_label($planned)); ?></div><div class="sub"><?php esc_html_e('Schedule status', 'workforce-one'); ?></div></div></div>
        <div class="wfo-profile-stat tone-green"><span class="wfo-profile-stat-icon"><?php echo Icons::svg('clock', 20); ?></span><div><div class="label"><?php esc_html_e('Today · Attendance', 'workforce-one'); ?></div><div class="value"><?php echo esc_html($today_result); ?></div><div class="sub"><?php echo $sign_in ? esc_html(sprintf(/* translators: %s: sign-in time */ __('In %s', 'workforce-one'), date_i18n('g:i A', strtotime($sign_in)))) : esc_html__('Not signed in', 'workforce-one'); ?></div></div></div>
        <div class="wfo-profile-stat tone-amber"><span class="wfo-profile-stat-icon"><?php echo Icons::svg('overtime', 20); ?></span><div><div class="label"><?php esc_html_e('Working Hours', 'workforce-one'); ?></div><div class="value" dir="ltr"><?php echo esc_html($hours_label); ?></div><div class="sub"><?php echo esc_html($shift_name); ?></div></div></div>
    </div>

    <div class="wfo-profile-grid">
        <div class="wfo-profile-col">
            <section class="wfo-profile-card" aria-labelledby="wfo-info-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon"><?php echo Icons::svg('user', 20); ?></span><div><h3 id="wfo-info-title"><?php esc_html_e('My Information', 'workforce-one'); ?></h3><p><?php esc_html_e('Your personal and work information', 'workforce-one'); ?></p></div></div>
                <dl class="wfo-profile-fields">
                    <div><dt><?php esc_html_e('Name', 'workforce-one'); ?></dt><dd dir="auto"><?php echo esc_html($emp->name); ?></dd></div>
                    <div><dt><?php esc_html_e('Domain', 'workforce-one'); ?></dt><dd dir="ltr"><?php echo esc_html($emp->domain_name); ?></dd></div>
                    <div><dt><?php esc_html_e('Email', 'workforce-one'); ?></dt><dd dir="ltr"><?php echo esc_html($emp->email ?: '—'); ?></dd></div>
                    <div><dt><?php esc_html_e('Team', 'workforce-one'); ?></dt><dd><?php echo esc_html($team_label); ?></dd></div>
                    <div><dt><?php esc_html_e('Supervisor', 'workforce-one'); ?></dt><dd><?php echo $supervisor ? esc_html($supervisor->name) : '—'; ?></dd></div>
                    <div><dt><?php esc_html_e('Shift', 'workforce-one'); ?></dt><dd><?php echo esc_html($shift_name); ?></dd></div>
                </dl>
            </section>

            <section class="wfo-profile-card wfo-profile-security" aria-labelledby="wfo-sec-title">
                <span class="wfo-profile-card-icon is-slate"><?php echo Icons::svg('lock', 20); ?></span>
                <div class="wfo-profile-security-text"><h3 id="wfo-sec-title"><?php esc_html_e('Security', 'workforce-one'); ?></h3><p><?php esc_html_e('Keep your Workforce One account secure.', 'workforce-one'); ?></p></div>
                <button type="button" class="ews-profile-security-btn wfo-profile-btn is-ghost" id="ews-open-password"><?php echo Icons::svg('key', 16); ?><?php esc_html_e('Reset Password', 'workforce-one'); ?></button>
            </section>

            <?php if ($achievements !== null): ?>
            <section class="wfo-profile-card" aria-labelledby="wfo-ach-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-amber"><?php echo Icons::svg('trophy', 20); ?></span><div><h3 id="wfo-ach-title"><?php esc_html_e('Achievements', 'workforce-one'); ?></h3><p><?php esc_html_e('Your earned milestones', 'workforce-one'); ?></p></div><span class="wfo-profile-count"><?php echo esc_html(sprintf(/* translators: %d: number of achievements */ _n('%d earned', '%d earned', count($achievements), 'workforce-one'), count($achievements))); ?></span></div>
                <?php if (!$achievements): ?>
                    <p class="wfo-profile-empty"><?php esc_html_e('Your achievements will appear here as you reach milestones.', 'workforce-one'); ?></p>
                <?php else: ?>
                <ul class="wfo-profile-achievements">
                    <?php foreach ($achievements as $a): ?><li class="ews-achievement-card"><span class="ews-achievement-icon <?php echo esc_attr($a->badge_style ?: 'circle'); ?>"><?php echo esc_html($a->icon); ?></span><div><div class="ews-achievement-name"><?php echo esc_html($a->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($a->description); ?></div><div class="ews-achievement-date"><?php echo esc_html(sprintf(/* translators: %s: date */ __('Earned %s', 'workforce-one'), date_i18n(get_option('date_format'), strtotime($a->earned_at)))); ?></div></div></li><?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>

        <div class="wfo-profile-col">
            <section class="wfo-profile-card" aria-labelledby="wfo-week-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-blue"><?php echo Icons::svg('calendar', 20); ?></span><div><h3 id="wfo-week-title"><?php esc_html_e('My Week', 'workforce-one'); ?></h3><p><?php esc_html_e('Your schedule for this week', 'workforce-one'); ?></p></div><?php echo $link($urls['schedule'], __('View Schedule', 'workforce-one')); ?></div>
                <ol class="wfo-myweek-days">
                    <?php foreach ($week as $d => $status): [$icon, $tone] = Icons::forStatus((string) $status); ?>
                    <li class="is-<?php echo esc_attr($tone); ?><?php echo $d === $today ? ' is-today' : ''; ?>"<?php echo $d === $today ? ' aria-current="date"' : ''; ?>>
                        <span class="wfo-myweek-day"><?php echo esc_html(date_i18n('D', strtotime($d))); ?></span>
                        <span class="wfo-myweek-num"><?php echo esc_html(date_i18n('j', strtotime($d))); ?></span>
                        <span class="wfo-myweek-icon"><?php echo Icons::svg($icon, 18, 2); ?></span>
                        <span class="wfo-myweek-word"><?php echo esc_html($status_label((string) $status)); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </section>

            <?php if ($leave_balances): ?>
            <section class="wfo-profile-card" aria-labelledby="wfo-bal-title">
                <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-purple"><?php echo Icons::svg('leave', 20); ?></span><div><h3 id="wfo-bal-title"><?php esc_html_e('Leave Balance', 'workforce-one'); ?> · <?php echo esc_html($year); ?></h3><p><?php esc_html_e('Your leave entitlement and usage', 'workforce-one'); ?></p></div><?php echo $link($urls['vacation'], __('View Leave', 'workforce-one')); ?></div>
                <ul class="wfo-profile-balances">
                    <?php foreach ($leave_balances as $lb): $pct = $lb['entitlement'] > 0 ? (int) min(100, round(($lb['used'] / $lb['entitlement']) * 100)) : 0; ?>
                    <li>
                        <div class="wfo-profile-balance-top"><span class="wfo-profile-balance-name"><?php echo esc_html($lb['name']); ?></span><span class="wfo-profile-balance-left"><strong><?php echo esc_html(Format::number($lb['available'])); ?></strong> <?php esc_html_e('available', 'workforce-one'); ?></span></div>
                        <span class="wfo-profile-bar" role="progressbar" aria-valuenow="<?php echo (int) $pct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr(sprintf(/* translators: %d: percent */ __('%d%% used', 'workforce-one'), $pct)); ?>"><i style="width:<?php echo (int) $pct; ?>%"></i></span>
                        <small><?php echo esc_html(sprintf(/* translators: 1: days used, 2: days pending, 3: percent used */ __('Used %1$s · Pending %2$s · %3$d%% used', 'workforce-one'), Format::number($lb['used']), Format::number($lb['pending']), $pct)); ?></small>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>
    </div>

    <section class="wfo-profile-card" aria-labelledby="wfo-recent-title">
        <div class="wfo-profile-card-head"><span class="wfo-profile-card-icon is-green"><?php echo Icons::svg('attendance', 20); ?></span><div><h3 id="wfo-recent-title"><?php esc_html_e('Recent Attendance', 'workforce-one'); ?></h3><p><?php esc_html_e('Your latest attendance records', 'workforce-one'); ?></p></div><?php echo $link($urls['time'], __('View All', 'workforce-one')); ?></div>
        <div class="wfo-profile-table-wrap" tabindex="0" role="region" aria-labelledby="wfo-recent-title"><table class="ews-my-profile-table wfo-profile-table"><thead><tr><th scope="col"><?php esc_html_e('Date', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('Sign In', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('Sign Out', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('Total Hours', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('Status', 'workforce-one'); ?></th></tr></thead><tbody><?php if ($recent): foreach ($recent as $rr): ?><tr><td><?php echo esc_html($rr['date']); ?></td><td><?php echo esc_html($rr['in'] !== '' ? $rr['in'] : '—'); ?></td><td><?php echo esc_html($rr['out'] !== '' ? $rr['out'] : '—'); ?></td><td><?php echo esc_html($rr['total']); ?></td><td><span class="ews-att-status <?php echo esc_attr(in_array($rr['status'], ['Present', 'Late'], true) ? 'present' : 'incomplete'); ?>"><?php echo esc_html($rr['status']); ?></span></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="wfo-profile-empty"><?php esc_html_e('No attendance records in the selected period.', 'workforce-one'); ?></td></tr><?php endif; ?></tbody></table></div>
    </section>

    <div class="ews-profile-modal-backdrop wfo-profile-sheet" id="ews-profile-picture-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-picture-title">
        <div class="ews-profile-modal-head"><h3 id="ews-picture-title"><?php esc_html_e('Profile Picture', 'workforce-one'); ?></h3><button type="button" class="ews-profile-modal-close" id="ews-close-profile-picture" aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button></div>
        <div class="ews-profile-modal-body">
            <div class="ews-profile-picture-preview"><?php echo $avatar(' is-small'); ?><div><strong><?php esc_html_e('Choose how you appear across Workforce One.', 'workforce-one'); ?></strong><p class="wfo-profile-help"><?php esc_html_e('Your profile picture belongs to your Workforce One employee profile, not the WordPress user profile.', 'workforce-one'); ?></p></div></div>
            <div class="ews-profile-modal-actions">
                <button type="button" class="ews-profile-photo-btn wfo-profile-btn" id="ews-open-avatar-picker"><?php echo Icons::svg('sparkle', 16); ?><?php esc_html_e('Choose Avatar', 'workforce-one'); ?></button>
                <button type="button" class="ews-profile-photo-btn secondary wfo-profile-btn is-ghost" id="ews-open-photo-upload"><?php echo Icons::svg('install', 16); ?><?php esc_html_e('Upload Photo', 'workforce-one'); ?></button>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="reset"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn secondary wfo-profile-btn is-ghost"><?php echo Icons::svg('user', 16); ?><?php esc_html_e('Use Initials', 'workforce-one'); ?></button></form>
            </div>
            <div id="ews-avatar-picker" class="ews-avatar-picker" hidden>
                <div class="ews-avatar-picker-head"><strong><?php esc_html_e('Choose your avatar', 'workforce-one'); ?></strong><p class="wfo-profile-help"><?php esc_html_e('Pick an avatar to use across Workforce One.', 'workforce-one'); ?></p></div>
                <div class="ews-avatar-filters" role="group" aria-label="<?php esc_attr_e('Filter avatars', 'workforce-one'); ?>"><?php foreach ($filters as $fk => $fl): ?><button type="button" class="ews-avatar-filter<?php echo $fk === 'all' ? ' active' : ''; ?>" data-filter="<?php echo esc_attr($fk); ?>"><?php echo esc_html($fl); ?></button><?php endforeach; ?></div>
                <div class="ews-avatar-grid"><?php foreach ($catalog as $key => $meta): ?><button type="button" class="ews-avatar-choice<?php echo $avatar_key === $key ? ' selected' : ''; ?>" data-avatar-key="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr($meta['category']); ?>" data-style="<?php echo esc_attr($meta['style']); ?>" title="<?php echo esc_attr($meta['label']); ?>"><img src="<?php echo esc_url($avatar_urls[$key]); ?>" alt="<?php echo esc_attr($meta['label']); ?>"><span class="ews-avatar-check"><?php echo Icons::svg('check', 13, 3); ?></span></button><?php endforeach; ?></div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ews-avatar-form"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="avatar"><input type="hidden" name="avatar_key" id="ews-avatar-key" value="<?php echo esc_attr($avatar_key); ?>"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn wfo-profile-btn is-wide" id="ews-save-avatar" <?php echo $avatar_key ? '' : 'disabled'; ?>><?php echo Icons::svg('check', 16, 2.4); ?><?php esc_html_e('Save Avatar', 'workforce-one'); ?></button></form>
            </div>
            <div id="ews-photo-upload-panel" class="ews-photo-upload-panel" hidden><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="ews-profile-photo-actions"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="upload"><?php wp_nonce_field('ews_profile_photo_save'); ?><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required aria-label="<?php esc_attr_e('Photo', 'workforce-one'); ?>"><button type="submit" class="ews-profile-photo-btn wfo-profile-btn"><?php esc_html_e('Upload Photo', 'workforce-one'); ?></button></form><p class="wfo-profile-help"><?php esc_html_e('JPG, PNG or WebP · maximum 2 MB', 'workforce-one'); ?></p></div>
        </div>
    </div></div>

    <div class="ews-profile-modal-backdrop wfo-profile-sheet" id="ews-password-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-password-title">
        <div class="ews-profile-modal-head"><h3 id="ews-password-title"><?php esc_html_e('Reset Password', 'workforce-one'); ?></h3><button type="button" class="ews-profile-modal-close" id="ews-close-password" aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button></div>
        <div class="ews-profile-modal-body"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-password-form"><input type="hidden" name="action" value="ews_profile_password_change"><?php wp_nonce_field('ews_profile_password_change'); ?>
            <div class="ews-password-field"><label for="ews-current-password"><?php esc_html_e('Current Password', 'workforce-one'); ?></label><input id="ews-current-password" name="current_password" type="password" autocomplete="current-password" required></div>
            <div class="ews-password-field"><label for="ews-new-password"><?php esc_html_e('New Password', 'workforce-one'); ?></label><input id="ews-new-password" name="new_password" type="password" autocomplete="new-password" required aria-describedby="ews-password-rules"></div>
            <div class="ews-password-field"><label for="ews-confirm-password"><?php esc_html_e('Confirm New Password', 'workforce-one'); ?></label><input id="ews-confirm-password" name="confirm_password" type="password" autocomplete="new-password" required></div>
            <div class="ews-password-requirements" id="ews-password-rules"><strong><?php esc_html_e('Password requirements', 'workforce-one'); ?></strong><ul><li><?php esc_html_e('At least 8 characters', 'workforce-one'); ?></li><li><?php esc_html_e('One uppercase letter', 'workforce-one'); ?></li><li><?php esc_html_e('One lowercase letter', 'workforce-one'); ?></li><li><?php esc_html_e('One number', 'workforce-one'); ?></li><li><?php esc_html_e('One special character', 'workforce-one'); ?></li></ul></div>
            <div class="ews-password-submit"><button type="button" class="ews-profile-photo-btn secondary wfo-profile-btn is-ghost" id="ews-cancel-password"><?php esc_html_e('Cancel', 'workforce-one'); ?></button><button type="submit" class="ews-profile-photo-btn wfo-profile-btn"><?php echo Icons::svg('check', 16, 2.4); ?><?php esc_html_e('Update Password', 'workforce-one'); ?></button></div>
        </form></div>
    </div></div>
</div>
