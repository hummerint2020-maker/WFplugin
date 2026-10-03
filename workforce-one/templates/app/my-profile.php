<?php
/**
 * Employee app: My Profile. Styles: assets/css/workforce-one.css (.ews-my-profile*, .ews-profile-*);
 * script: assets/js/my-profile.js (picture and password dialogs, avatar picker).
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
?>
<div class="ews-my-profile">
                <?php if($profile_updated): ?><div class="ews-profile-notice"><?php echo ($profile_updated==='reset'?'Profile picture reset to initials.':($profile_updated==='avatar'?'Avatar selected successfully.':'Profile picture updated successfully.')); ?></div><?php endif; ?>
                <?php if($profile_error): ?><div class="ews-profile-notice error"><?php echo $profile_error==='size'?'Image must be 2 MB or smaller.':($profile_error==='type'?'Please upload a JPG, PNG, or WebP image.':'Could not upload the profile picture. Please try again.'); ?></div><?php endif; ?>
                <?php if($password_updated): ?><div class="ews-profile-notice">Password updated successfully.</div><?php endif; ?>
                <?php if($password_error): ?><div class="ews-profile-notice error"><?php echo $password_error==='current'?'Current password is incorrect.':($password_error==='mismatch'?'New passwords do not match.':($password_error==='weak'?'New password must be at least 8 characters and include uppercase, lowercase, number and special character.':($password_error==='same'?'New password must be different from the current password.':'Could not update your password. Please try again.'))); ?></div><?php endif; ?>

                <div class="ews-my-profile-head">
                    <div class="ews-my-profile-person">
                        <div class="ews-my-profile-avatar" title="Edit profile picture">
                            <?php if($display_image): ?><img src="<?php echo esc_url($display_image); ?>" alt="<?php echo esc_attr($emp->name); ?>"><?php else: ?><?php echo esc_html($initials); ?><?php endif; ?>
                            <button type="button" class="ews-profile-avatar-edit" id="ews-open-profile-picture" aria-label="Edit profile picture">✎</button>
                        </div>
                        <div class="ews-my-profile-title"><h2><?php echo esc_html($emp->name); ?></h2><div class="ews-my-profile-meta"><span class="ews-my-profile-pill active">● Active</span><span class="ews-my-profile-pill">👥 <?php echo esc_html($team_label); ?></span></div></div><div class="ews-my-profile-quote"><strong>One Platform.<br>One Team. One Goal.</strong></div>
                    </div>
                </div>

                <div class="ews-my-profile-grid">
                    <div class="ews-my-profile-card planned"><div class="ews-my-profile-card-icon">⌂</div><div><div class="label">Today · Planned</div><div class="value"><?php echo esc_html($planned); ?></div><div class="sub">Schedule status</div></div></div>
                    <div class="ews-my-profile-card attendance"><div class="ews-my-profile-card-icon">◷</div><div><div class="label">Today · Attendance</div><div class="value"><?php echo esc_html($today_result); ?></div><div class="sub"><?php echo $sign_in?'In '.esc_html(date_i18n('g:i A',strtotime($sign_in))): 'Not signed in'; ?></div></div></div>
                    <div class="ews-my-profile-card hours"><div class="ews-my-profile-card-icon">▣</div><div><div class="label">Working Hours</div><div class="value"><?php echo esc_html($hours_label); ?></div><div class="sub"><?php echo esc_html($shift?$shift['name']:'Company Default'); ?></div></div></div>
                </div>

                <section class="ews-my-profile-panel ews-info-panel"><div class="ews-section-head"><div><h3>My Information</h3><p>Your personal and work information</p></div><span class="ews-section-head-icon">♙</span></div><div class="ews-my-profile-body"><div class="ews-my-profile-fields">
                    <div class="ews-my-profile-field"><div class="k">Name</div><div class="v"><?php echo esc_html($emp->name); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Domain</div><div class="v"><?php echo esc_html($emp->domain_name); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Email</div><div class="v"><?php echo esc_html($emp->email?:'—'); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Team</div><div class="v"><?php echo esc_html($team_label); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Supervisor</div><div class="v"><?php echo $supervisor?esc_html($supervisor->name):'—'; ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Shift</div><div class="v"><?php echo esc_html($shift?$shift['name']:'Company Default'); ?></div></div>
                </div></div></section>

                <section class="ews-my-profile-panel ews-security-panel"><div class="ews-my-profile-body"><div class="ews-profile-security"><div class="ews-profile-security-main"><div class="ews-profile-security-icon">🔒</div><div><div class="ews-profile-security-title">Security</div><div class="ews-profile-security-sub">Keep your Workforce One account secure.</div></div></div><button type="button" class="ews-profile-security-btn" id="ews-open-password">Reset Password&nbsp; →</button></div></div></section>

                <?php if($achievements!==null): ?>
                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>🏆 Achievements</h3><p>Your earned milestones</p></div><span class="ews-my-profile-section-link"><?php echo (int)count($achievements); ?> earned</span></div><div class="ews-my-profile-body"><div class="ews-achievements-grid"><?php if(!$achievements): ?><div class="ews-profile-photo-help">Your achievements will appear here as you reach milestones.</div><?php else: foreach($achievements as $a): ?><div class="ews-achievement-card"><div class="ews-achievement-icon <?php echo esc_attr($a->badge_style?:'circle'); ?>"><?php echo esc_html($a->icon); ?></div><div><div class="ews-achievement-name"><?php echo esc_html($a->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($a->description); ?></div><div class="ews-achievement-date">Earned <?php echo esc_html(date_i18n(get_option('date_format'),strtotime($a->earned_at))); ?></div></div></div><?php endforeach; endif; ?></div></div></section><?php endif; ?>

                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>My Week</h3><p>Your schedule for this week</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($urls['schedule']); ?>">View Schedule&nbsp; →</a></div><div class="ews-my-profile-body"><div class="ews-my-week">
                    <?php foreach($week as $d=>$status): $status_class=strtolower(str_replace(' ','-',(string)$status)); $day_class=$d===$today?' current-day':''; ?><div class="ews-my-day <?php echo esc_attr($status_class.$day_class); ?>"><div class="d"><?php echo esc_html(date('D',strtotime($d))); ?><br><?php echo esc_html(date('d M',strtotime($d))); ?></div><div class="s"><?php echo esc_html($status); ?></div></div><?php endforeach; ?>
                </div></div></section>

                <?php if($leave_balances): ?><section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>Leave Balance · <?php echo esc_html($year); ?></h3><p>Your leave entitlement and usage</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($urls['vacation']); ?>">View Leave&nbsp; →</a></div><div class="ews-my-profile-body"><div class="ews-leave-grid"><?php foreach($leave_balances as $lb): $pct=$lb['entitlement']>0?min(100,round(($lb['used']/$lb['entitlement'])*100)):0; ?><div class="ews-leave-card"><div class="ews-leave-top"><div><div class="k"><?php echo esc_html($lb['name']); ?></div><div class="v"><strong><?php echo esc_html(\WorkforceOne\Support\Format::number($lb['available'])); ?></strong> available</div><div class="ews-my-profile-photo-note">Used <?php echo esc_html(\WorkforceOne\Support\Format::number($lb['used'])); ?> · Pending <?php echo esc_html(\WorkforceOne\Support\Format::number($lb['pending'])); ?></div></div><span class="ews-leave-percent"><?php echo esc_html($pct); ?>% used</span></div><div class="ews-leave-bar"><span style="width:<?php echo esc_attr($pct); ?>%"></span></div></div><?php endforeach; ?></div></div></section><?php endif; ?>

                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>Recent Attendance</h3><p>Your latest attendance records</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($urls['time']); ?>">View All&nbsp; →</a></div><div class="ews-my-profile-body" style="padding:0"><div style="overflow:auto"><table class="ews-my-profile-table"><thead><tr><th>Date</th><th>Sign In</th><th>Sign Out</th><th>Total Hours</th><th>Status</th></tr></thead><tbody><?php if($recent): foreach($recent as $rr): ?><tr><td><?php echo esc_html($rr['date']); ?></td><td><?php echo esc_html($rr['in']!==''?$rr['in']:'—'); ?></td><td><?php echo esc_html($rr['out']!==''?$rr['out']:'—'); ?></td><td><?php echo esc_html($rr['total']); ?></td><td><span class="ews-att-status <?php echo esc_attr(in_array($rr['status'],['Present','Late'],true)?'present':'incomplete'); ?>"><?php echo esc_html($rr['status']); ?></span></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="ews-my-profile-muted">No attendance records in the selected period.</td></tr><?php endif; ?></tbody></table></div></div></section>

                <div class="ews-profile-modal-backdrop" id="ews-profile-picture-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-picture-title"><div class="ews-profile-modal-head"><h3 id="ews-picture-title">Profile Picture</h3><button type="button" class="ews-profile-modal-close" id="ews-close-profile-picture" aria-label="Close">×</button></div><div class="ews-profile-modal-body">
                    <div class="ews-profile-picture-preview"><div class="ews-my-profile-avatar"><?php if($display_image): ?><img src="<?php echo esc_url($display_image); ?>" alt="<?php echo esc_attr($emp->name); ?>"><?php else: ?><?php echo esc_html($initials); ?><?php endif; ?></div><div><strong>Choose how you appear across Workforce One.</strong><div class="ews-profile-photo-help">Your profile picture belongs to your Workforce One employee profile, not the WordPress user profile.</div></div></div>
                    <div class="ews-profile-modal-actions"><button type="button" class="ews-profile-photo-btn" id="ews-open-avatar-picker">Choose Avatar</button><button type="button" class="ews-profile-photo-btn secondary" id="ews-open-photo-upload">Upload Photo</button><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="reset"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn secondary">Use Initials</button></form></div>
                    <div id="ews-avatar-picker" class="ews-avatar-picker" hidden><div class="ews-avatar-picker-head"><div><strong>Choose your avatar</strong><div class="ews-profile-photo-help">Pick an avatar to use across Workforce One.</div></div></div><div class="ews-avatar-filters"><button type="button" class="ews-avatar-filter active" data-filter="all">All</button><button type="button" class="ews-avatar-filter" data-filter="Men">Men</button><button type="button" class="ews-avatar-filter" data-filter="Women">Women</button><button type="button" class="ews-avatar-filter" data-filter="Professional">Professional</button><button type="button" class="ews-avatar-filter" data-filter="Casual">Casual</button><button type="button" class="ews-avatar-filter" data-filter="Fun">Fun</button></div><div class="ews-avatar-grid"><?php foreach($catalog as $key=>$meta): ?><button type="button" class="ews-avatar-choice<?php echo $avatar_key===$key?' selected':''; ?>" data-avatar-key="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr($meta['category']); ?>" data-style="<?php echo esc_attr($meta['style']); ?>" title="<?php echo esc_attr($meta['label']); ?>"><img src="<?php echo esc_url($avatar_urls[$key]); ?>" alt="<?php echo esc_attr($meta['label']); ?>"><span class="ews-avatar-check">✓</span></button><?php endforeach; ?></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ews-avatar-form"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="avatar"><input type="hidden" name="avatar_key" id="ews-avatar-key" value="<?php echo esc_attr($avatar_key); ?>"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn" id="ews-save-avatar" <?php echo $avatar_key?'':'disabled'; ?>>Save Avatar</button></form></div>
                    <div id="ews-photo-upload-panel" class="ews-photo-upload-panel" hidden><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="ews-profile-photo-actions"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="upload"><?php wp_nonce_field('ews_profile_photo_save'); ?><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required><button type="submit" class="ews-profile-photo-btn">Upload Photo</button></form><div class="ews-profile-photo-help">JPG, PNG or WebP · maximum 2 MB</div></div>
                </div></div></div>

                <div class="ews-profile-modal-backdrop" id="ews-password-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-password-title"><div class="ews-profile-modal-head"><h3 id="ews-password-title">Reset Password</h3><button type="button" class="ews-profile-modal-close" id="ews-close-password" aria-label="Close">×</button></div><div class="ews-profile-modal-body"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-password-form"><input type="hidden" name="action" value="ews_profile_password_change"><?php wp_nonce_field('ews_profile_password_change'); ?><div class="ews-password-field"><label for="ews-current-password">Current Password</label><input id="ews-current-password" name="current_password" type="password" autocomplete="current-password" required></div><div class="ews-password-field"><label for="ews-new-password">New Password</label><input id="ews-new-password" name="new_password" type="password" autocomplete="new-password" required></div><div class="ews-password-field"><label for="ews-confirm-password">Confirm New Password</label><input id="ews-confirm-password" name="confirm_password" type="password" autocomplete="new-password" required></div><div class="ews-password-requirements"><strong>Password requirements</strong><ul><li>At least 8 characters</li><li>One uppercase letter</li><li>One lowercase letter</li><li>One number</li><li>One special character</li></ul></div><div class="ews-password-submit"><button type="button" class="ews-profile-photo-btn secondary" id="ews-cancel-password">Cancel</button><button type="submit" class="ews-profile-photo-btn">Update Password</button></div></form></div></div></div>
            </div>
