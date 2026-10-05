<?php
/**
 * Sign In / Sign Out page. Rendered by EWS_Frontend_Trait::time_content().
 * Behaviour lives in assets/js/time.js (location gate, QR, break timer, live clock) and the Face
 * module in assets/js/workforce-one.js; styles in assets/css/app-time.css. Every form, field name,
 * id and class those scripts and the tests use is kept; the page around them is the new look.
 *
 * @var object|null $emp        Current employee.
 * @var object|null $sch        Today's schedule row.
 * @var array       $ev         Today's events keyed by event_type.
 * @var bool        $working    Today requires Sign In.
 * @var bool        $sign_in_open
 * @var array       $hours      Working hours.
 * @var bool        $face_signin_enabled
 * @var bool        $requires_location
 * @var array|null  $break_data
 * @var array       $signin_bounds       Sign-in window (start/cutoff/end timestamps).
 * @var string      $hours_start_label
 * @var string      $hours_end_label
 * @var string      $sign_in_status_label "On Time" / "Late Arrival" (translated), '' before sign in.
 * @var bool        $face_enrolled
 * @var array       $face_settings
 * @var string      $face_vendor_url
 * @var bool        $qr_enabled
 * @var bool        $presence_enabled
 * @var string      $presence_url
 * @var string      $clock_state         'out' | 'in' | 'break' | 'done' (Ui\ClockFace::state())
 * @var float       $clock_progress      0 … 1, the ring around the avatar (Ui\ClockFace::progress())
 * @var string|null $signed_in_at        Sign In time (local, MySQL format) or null
 * @var string      $picture             photo / avatar URL, '' = initials
 * @var string      $initials
 *
 * Templates only display prepared values; they do not call back into the plugin.
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
if(isset($_GET['time_success']))echo '<div id="ews-time-flash" class="ews-notice ews-time-success">'.esc_html(wp_unslash($_GET['time_success'])).'</div>';
if(isset($_GET['time_error']))echo '<div id="ews-time-flash" class="ews-notice ews-time-error">'.esc_html(wp_unslash($_GET['time_error'])).'</div>';
$signed_in = isset($ev['sign_in']) || isset($ev['late_sign_in']);
$needs_location = $working && $requires_location && !$signed_in && !isset($ev['sign_out']);
// One main action at a time: Sign In before, Sign Out after; the other form stays in the page, hidden.
$primary = $clock_state === 'out' ? 'sign_in' : (in_array($clock_state, ['in', 'break'], true) ? 'sign_out' : '');
$ring_r = 92; $ring_c = 2 * M_PI * $ring_r;
$pips = ['out' => 'clock', 'in' => 'check', 'break' => 'overtime', 'done' => 'logout'];
?>
            <?php if($needs_location): ?>
            <div id="ews-location-gate" class="ews-location-gate" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e('Location permission required','workforce-one'); ?>">
                <div class="ews-location-gate-card">
                    <div class="ews-location-icon" aria-hidden="true"><?php echo Icons::svg('pin', 30); ?></div>
                    <h2><?php esc_html_e('Location Services Required','workforce-one'); ?></h2>
                    <p id="ews-location-message"><?php esc_html_e('Please allow location access when your browser asks. Your location is required to record Sign In / Sign Out.','workforce-one'); ?></p>
                    <div class="ews-location-spinner" id="ews-location-spinner" aria-hidden="true"></div>
                    <button type="button" id="ews-location-retry" class="ews-location-retry" hidden><?php esc_html_e('Allow Location / Try Again','workforce-one'); ?></button>
                </div>
            </div>
            <?php endif; ?>
            <div class="ews-time-card wfo-clock" data-location-required="<?php echo $needs_location?'1':'0'; ?>">
            <?php if(!$emp): ?><div class="ews-time-block wfo-clock-empty"><div class="ews-time-icon"><?php echo Icons::svg('user', 30); ?></div><h2><?php esc_html_e('Employee account not linked','workforce-one'); ?></h2><p><?php esc_html_e('Your WordPress account is not linked to an active employee. Please contact your manager.','workforce-one'); ?></p></div>
            <?php elseif(!$working): ?><div class="ews-time-block wfo-clock-empty"><div class="ews-time-icon"><?php echo Icons::svg('calendar', 30); ?></div><div class="ews-time-date"><?php echo esc_html(date_i18n('l, d F Y')); ?></div><h2><?php esc_html_e('Today is not a working day for you','workforce-one'); ?></h2><p><?php printf(/* translators: %s: today's schedule status */esc_html__('Your schedule today is %s. Sign In / Sign Out is only available on working days.','workforce-one'),'<strong>'.esc_html($sch?$sch->status:__('Not Set','workforce-one')).'</strong>'); ?></p></div>
            <?php else: [$st_icon, $st_tone] = Icons::forStatus((string) $sch->status); ?>
            <section class="wfo-clock-hero is-<?php echo esc_attr($clock_state); ?>" aria-label="<?php esc_attr_e('Today','workforce-one'); ?>">
                <div class="wfo-clock-top"><span class="ews-time-date"><?php echo esc_html(date_i18n('l, d F Y')); ?></span><span class="wfo-clock-shift" dir="ltr"><?php echo esc_html($hours_start_label.' – '.$hours_end_label); ?></span></div>
                <div class="wfo-clock-face">
                    <div class="wfo-clock-avatar">
                        <svg class="wfo-clock-ring" viewBox="0 0 200 200" aria-hidden="true" focusable="false"><circle cx="100" cy="100" r="<?php echo (int) $ring_r; ?>" class="wfo-ring-track"/><circle cx="100" cy="100" r="<?php echo (int) $ring_r; ?>" class="wfo-ring-fill" stroke-dasharray="<?php echo esc_attr(round($ring_c * $clock_progress, 1) . ' ' . round($ring_c, 1)); ?>"/></svg>
                        <?php if($picture!==''): ?><img src="<?php echo esc_url($picture); ?>" alt="" class="wfo-clock-photo"><?php else: ?><span class="wfo-clock-photo wfo-clock-initials" aria-hidden="true"><?php echo esc_html($initials); ?></span><?php endif; ?>
                        <span class="wfo-clock-pip"><?php echo Icons::svg($pips[$clock_state], 18, 2.4); ?></span>
                    </div>
                    <div class="wfo-clock-who">
                        <strong class="wfo-clock-name"><?php echo esc_html($emp->name); ?></strong>
                        <span class="wfo-clock-meta"><?php echo esc_html($emp->domain_name); ?></span>
                        <span class="wfo-clock-chip is-<?php echo esc_attr($st_tone); ?>"><?php echo Icons::svg($st_icon, 14, 2); ?><?php echo esc_html($sch->status); ?></span>
                    </div>
                    <div class="wfo-clock-time">
                        <?php if($clock_state==='out'): ?>
                            <span><?php esc_html_e('Now','workforce-one'); ?></span><strong id="wfo-clock-now" dir="ltr" data-site-ts="<?php echo (int) current_time('timestamp'); ?>" data-am="<?php echo esc_attr(date_i18n('A', 32400)); ?>" data-pm="<?php echo esc_attr(date_i18n('A', 75600)); ?>"><?php echo esc_html(date_i18n('h:i A')); ?></strong>
                        <?php elseif($clock_state==='done'): ?>
                            <span><?php esc_html_e('Sign Out','workforce-one'); ?></span><strong dir="ltr"><?php echo esc_html(date_i18n('h:i A',strtotime($ev['sign_out']->event_at))); ?></strong>
                        <?php else: ?>
                            <span><?php esc_html_e('Sign In','workforce-one'); ?></span><strong dir="ltr"><?php echo esc_html(date_i18n('h:i A',strtotime((string) $signed_in_at))); ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ews-time-window <?php echo (isset($ev['sign_out'])?'closed':($signed_in?'open':($sign_in_open?'open':'closed'))); ?>" role="status">
                    <?php if(isset($ev['sign_out'])): ?>
                        <strong><?php esc_html_e('You signed out today.','workforce-one'); ?></strong> <?php printf(/* translators: 1: sign-in time, 2: sign-out time */esc_html__('Sign In was recorded at %1$s and Sign Out at %2$s.','workforce-one'),esc_html(date_i18n('h:i A',strtotime(isset($ev['sign_in'])?$ev['sign_in']->event_at:$ev['late_sign_in']->event_at))),esc_html(date_i18n('h:i A',strtotime($ev['sign_out']->event_at)))); ?>
                    <?php elseif($signed_in): ?>
                        <strong><?php esc_html_e('You are signed in.','workforce-one'); ?></strong> <?php printf(/* translators: 1: "On Time" / "Late Arrival" / "Sign In", 2: time */esc_html__('%1$s recorded at %2$s.','workforce-one'),esc_html(isset($ev['sign_in'])?$sign_in_status_label:__('Sign In','workforce-one')),esc_html(date_i18n('h:i A',strtotime(isset($ev['sign_in'])?$ev['sign_in']->event_at:$ev['late_sign_in']->event_at)))); ?>
                    <?php elseif($sign_in_open): ?>
                        <strong><?php esc_html_e('Sign In is available now.','workforce-one'); ?></strong> <?php printf(/* translators: 1: start time, 2: end time, 3: sign-in cutoff time */esc_html__('Allowed window: %1$s – %2$s, with Sign In cutoff at %3$s.','workforce-one'),esc_html($hours_start_label),esc_html($hours['end']),esc_html($signin_bounds['cutoff']?date_i18n('g:i A',$signin_bounds['cutoff']):$hours_end_label)); ?>
                    <?php else: ?>
                        <?php if($signin_bounds['start'] && current_time('timestamp')<$signin_bounds['start']): ?>
                            <strong><?php esc_html_e('Sign In is not available yet.','workforce-one'); ?></strong> <?php printf(/* translators: %s: start time */esc_html__('Your working hours start at %s.','workforce-one'),esc_html(date_i18n('g:i A',$signin_bounds['start']))); ?>
                        <?php else: ?>
                            <strong><?php esc_html_e('Sign In is no longer available.','workforce-one'); ?></strong> <?php printf(/* translators: %s: cutoff time */esc_html__('The Sign In cutoff was %s.','workforce-one'),esc_html($signin_bounds['cutoff']?date_i18n('g:i A',$signin_bounds['cutoff']):$hours_end_label)); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="ews-time-actions">
                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>" class="ews-face-signin-form" data-face-event="sign_in"<?php echo $primary==='sign_in'?'':' hidden'; ?>><input type="hidden" name="face_verified" value="0"><?php wp_nonce_field('ews_time_event_sign_in'); ?><input type="hidden" name="action" value="ews31_time_event"><input type="hidden" name="event_type" value="sign_in"><input type="hidden" name="latitude" class="ews-lat"><input type="hidden" name="longitude" class="ews-lng"><input type="hidden" name="accuracy" class="ews-accuracy"><input type="hidden" name="location_timestamp" class="ews-location-timestamp"><button class="ews-time-btn sign-in" <?php disabled($signed_in||!$sign_in_open); ?>><?php echo Icons::svg('login', 22, 2.2); ?><span><?php esc_html_e('Sign In','workforce-one'); ?></span></button></form>
                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>"<?php echo $primary==='sign_out'?'':' hidden'; ?>><?php wp_nonce_field('ews_time_event_sign_out'); ?><input type="hidden" name="action" value="ews31_time_event"><input type="hidden" name="event_type" value="sign_out"><input type="hidden" name="latitude" class="ews-lat"><input type="hidden" name="longitude" class="ews-lng"><input type="hidden" name="accuracy" class="ews-accuracy"><input type="hidden" name="location_timestamp" class="ews-location-timestamp"><button class="ews-time-btn out" <?php disabled(!$signed_in||isset($ev['sign_out'])); ?>><?php echo Icons::svg('logout', 22, 2.2); ?><span><?php esc_html_e('Sign Out','workforce-one'); ?></span></button></form>
                </div>
            </section>

            <ul class="wfo-clock-checks" aria-label="<?php esc_attr_e('Before you sign in','workforce-one'); ?>">
                <li class="<?php echo $requires_location ? 'is-info' : 'is-ok'; ?>"><span class="wfo-check-icon"><?php echo Icons::svg('pin', 18); ?></span><span><strong><?php esc_html_e('Location','workforce-one'); ?></strong><small><?php echo esc_html($requires_location ? __('Needed for this schedule','workforce-one') : __('Not needed today','workforce-one')); ?></small></span></li>
                <li class="<?php echo $signed_in ? 'is-ok' : ($sign_in_open ? 'is-ok' : 'is-warn'); ?>"><span class="wfo-check-icon"><?php echo Icons::svg('clock', 18); ?></span><span><strong><?php esc_html_e('Sign-in window','workforce-one'); ?></strong><small dir="auto"><?php
                    if ($signed_in) echo esc_html($sign_in_status_label !== '' ? $sign_in_status_label : __('Sign In','workforce-one'));
                    /* translators: %s: sign-in cutoff time */
                    elseif ($sign_in_open) echo esc_html(sprintf(__('Open until %s','workforce-one'), $signin_bounds['cutoff'] ? date_i18n('g:i A', $signin_bounds['cutoff']) : $hours_end_label));
                    else echo esc_html__('Closed','workforce-one');
                ?></small></span></li>
                <li class="<?php echo !$face_signin_enabled ? 'is-none' : ($face_enrolled ? 'is-info' : 'is-warn'); ?>"><span class="wfo-check-icon"><?php echo Icons::svg('user', 18); ?></span><span><strong><?php esc_html_e('Face check','workforce-one'); ?></strong><small><?php echo esc_html(!$face_signin_enabled ? __('Optional','workforce-one') : ($face_enrolled ? __('Camera opens on Sign In','workforce-one') : __('Set up your face first','workforce-one'))); ?></small></span></li>
            </ul>

            <?php if($break_data && $signed_in && !isset($ev['sign_out'])): ?>
            <div class="ews-break-card wfo-panel <?php echo $break_data['open']?'is-open':''; ?>">
                <div class="ews-break-head">
                    <span class="wfo-panel-icon"><?php echo Icons::svg('overtime', 22); ?></span>
                    <div><span class="ews-break-kicker"><?php esc_html_e('BREAK MANAGEMENT','workforce-one'); ?></span><h3><?php echo esc_html($break_data['open']?__('You are on break','workforce-one'):__('Take a break','workforce-one')); ?></h3>
                    <p><?php if($break_data['open']): ?><?php printf(/* translators: %s: minutes */esc_html__('Your break is being tracked. The standard break duration is %s.','workforce-one'),'<strong>'.esc_html(sprintf(__('%d min','workforce-one'),(int)$break_data['duration'])).'</strong>'); ?><?php else: ?><?php esc_html_e('Each break session is limited to the configured duration. Breaks are informative and do not affect attendance or overtime calculations.','workforce-one'); ?><?php endif; ?></p></div>
                    <div class="ews-break-count"><strong><?php echo (int)$break_data['remaining']; ?></strong><span><?php esc_html_e('remaining','workforce-one'); ?></span></div>
                </div>
                <?php if($break_data['open']): $open_start_ts=strtotime(get_gmt_from_date($break_data['open']->start_at)); ?>
                    <div class="ews-break-live">
                        <div><span class="ews-break-live-label"><?php esc_html_e('ON BREAK','workforce-one'); ?></span><strong id="ews-break-timer" data-start="<?php echo esc_attr((int)$open_start_ts*1000); ?>">00:00</strong><small><?php esc_html_e('Elapsed time','workforce-one'); ?></small></div>
                        <div class="ews-break-limit"><span><?php esc_html_e('Standard duration','workforce-one'); ?></span><strong><?php echo esc_html(sprintf(/* translators: %d: minutes */__('%d min','workforce-one'),(int)$break_data['duration'])); ?></strong><small><?php echo esc_html(sprintf(/* translators: %d: minutes */__('Manager alert at %d min','workforce-one'),(int)$break_data['escalation'])); ?></small></div>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-break-form">
                        <?php wp_nonce_field('ews_break_resume'); ?><input type="hidden" name="action" value="ews_break_resume">
                        <button class="ews-break-btn resume" type="submit"><?php echo Icons::svg('check', 20, 2.4); ?><span><?php esc_html_e('Resume Work','workforce-one'); ?></span></button>
                    </form>
                <?php elseif($break_data['remaining']>0): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-break-form">
                        <?php wp_nonce_field('ews_break_start'); ?><input type="hidden" name="action" value="ews_break_start">
                        <button class="ews-break-btn start" type="submit"><?php echo Icons::svg('overtime', 20); ?><span><?php esc_html_e('Start Break','workforce-one'); ?></span> <span class="wfo-btn-note"><?php echo esc_html(sprintf(__('%d min','workforce-one'),(int)$break_data['duration'])); ?></span></button>
                    </form>
                <?php else: ?>
                    <div class="ews-break-exhausted"><?php echo Icons::svg('check', 18, 2.4); ?><span><?php echo esc_html(sprintf(/* translators: %d: number of break sessions */__('You have used all %d break sessions available today.','workforce-one'),(int)$break_data['allowed'])); ?></span></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="ews-time-history wfo-panel"><h3><?php esc_html_e('Today\'s Record','workforce-one'); ?></h3>
            <div class="ews-time-row is-<?php echo isset($ev['sign_in']) ? 'done' : 'todo'; ?>"><span><?php esc_html_e('Sign In','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_in'])?date_i18n('h:i A',strtotime($ev['sign_in']->event_at)):__('Not recorded','workforce-one')); ?></strong></div>
            <div class="ews-time-row is-<?php echo isset($ev['sign_in']) ? 'done' : 'todo'; ?>"><span><?php esc_html_e('Attendance Status','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_in'])?$sign_in_status_label:__('Not recorded','workforce-one')); ?></strong></div>
            <div class="ews-time-row is-<?php echo isset($ev['sign_out']) ? 'done' : 'todo'; ?>"><span><?php esc_html_e('Sign Out','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_out'])?date_i18n('h:i A',strtotime($ev['sign_out']->event_at)):__('Not recorded','workforce-one')); ?></strong></div>
            <?php if($break_data && !empty($break_data['sessions'])): ?>
                <div class="ews-break-history-title"><?php esc_html_e('Break Sessions','workforce-one'); ?></div>
                <?php foreach($break_data['sessions'] as $bs): ?>
                    <div class="ews-time-row ews-break-history-row is-done"><span><?php echo esc_html(sprintf(/* translators: %d: break number */__('Break %d','workforce-one'),(int)$bs->id)); ?> · <?php echo esc_html($bs->status); ?></span><strong><?php echo esc_html(date_i18n('h:i A',strtotime($bs->start_at))); ?><?php echo $bs->end_at?' – '.esc_html(date_i18n('h:i A',strtotime($bs->end_at))):' · '.esc_html__('Open','workforce-one'); ?><?php if($bs->end_at!==null): ?> · <?php echo esc_html(sprintf(__('%d min','workforce-one'),(int)$bs->actual_minutes)); ?><?php endif; ?></strong></div>
                <?php endforeach; ?>
            <?php endif; ?></div><?php endif; ?></div>
            <?php if($working && $qr_enabled && !isset($ev['sign_in']) && !isset($ev['late_sign_in']) && $sign_in_open): ?>
            <div class="ews-face-lab wfo-panel" id="wfo-qr-signin-card">
              <div class="ews-face-lab-head"><span class="wfo-panel-icon"><?php echo Icons::svg('attendance', 22); ?></span><div><h3><?php esc_html_e('QR Sign In','workforce-one'); ?></h3><p><?php esc_html_e('Scan the dynamic QR displayed at your workplace. This is an additional Sign In method; your normal Sign In remains available.','workforce-one'); ?></p></div><span class="wfo-panel-tag"><?php esc_html_e('Rotating QR','workforce-one'); ?></span></div>
              <button type="button" class="ews-face-lab-btn" id="wfo-qr-open"><?php echo Icons::svg('more', 18); ?><span><?php esc_html_e('Scan Workplace QR','workforce-one'); ?></span></button>
              <div class="ews-face-modal" id="wfo-qr-modal" aria-hidden="true"><div class="ews-face-card"><div class="ews-face-top"><div><h3><?php esc_html_e('Scan Workplace QR','workforce-one'); ?></h3><p><?php esc_html_e('Point your camera at the QR displayed at your workplace.','workforce-one'); ?></p></div><button type="button" class="ews-face-close" id="wfo-qr-close" aria-label="<?php esc_attr_e('Close','workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button></div><div class="ews-face-stage"><video id="wfo-qr-video" autoplay muted playsinline></video></div><div class="ews-face-status" id="wfo-qr-status"><?php esc_html_e('Starting camera…','workforce-one'); ?></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wfo-qr-form" class="ews-face-signin-form" data-face-event="sign_in"><?php wp_nonce_field('ews_presence_qr_signin'); ?><input type="hidden" name="action" value="ews_presence_qr_signin"><input type="hidden" name="qr_payload" id="wfo-qr-payload"><input type="hidden" name="face_verified" value="0"><input type="hidden" name="face_token" value=""><input type="hidden" name="latitude" class="ews-lat" id="wfo-qr-lat"><input type="hidden" name="longitude" class="ews-lng" id="wfo-qr-lng"><input type="hidden" name="accuracy" class="ews-accuracy" id="wfo-qr-acc"><input type="hidden" name="location_timestamp" class="ews-location-timestamp" id="wfo-qr-ts"><button type="submit" class="ews-time-btn sign-in" id="wfo-qr-submit" disabled><?php esc_html_e('Sign In with QR','workforce-one'); ?></button></form></div></div>
            </div>
            <?php endif; ?>
            <?php if($working && $presence_enabled && $emp): ?>
            <div class="ews-face-lab wfo-panel"><div class="ews-face-lab-head"><span class="wfo-panel-icon is-blue"><?php echo Icons::svg('alert', 22); ?></span><div><h3><?php esc_html_e('Presence Verification','workforce-one'); ?></h3><p><?php esc_html_e('If a manager requests a physical presence check, the request will appear here and in your notifications.','workforce-one'); ?></p></div><span class="wfo-panel-tag"><?php esc_html_e('Optional','workforce-one'); ?></span></div><a class="ews-face-lab-btn" href="<?php echo esc_url($presence_url); ?>"><?php echo Icons::svg('arrow', 18); ?><span>Open Presence Verification</span></a></div>
            <?php endif; ?>
            <div class="ews-face-module" id="ews-face-module" data-vendor-base="<?php echo esc_attr($face_vendor_url); ?>" data-api-base="<?php echo esc_attr(rest_url('workforce-one/v1/')); ?>" data-wp-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" data-server-enrolled="<?php echo $face_enrolled?'1':'0'; ?>" data-face-required="<?php echo $face_signin_enabled?'1':'0'; ?>" data-face-config="<?php echo esc_attr(wp_json_encode($face_settings)); ?>">
            <div class="ews-face-lab wfo-panel">
              <div class="ews-face-lab-head">
                <span class="wfo-panel-icon is-purple"><?php echo Icons::svg('user', 22); ?></span>
                <div><h3><?php esc_html_e('Face Sign In','workforce-one'); ?></h3><p><?php echo esc_html($face_signin_enabled?__('Face verification is required before Sign In.','workforce-one'):__('Face verification is available but currently optional.','workforce-one')); ?> <?php esc_html_e('Face processing runs in this browser.','workforce-one'); ?></p></div>
                <span id="ews-face-state" class="wfo-panel-tag"><?php esc_html_e('Not enrolled','workforce-one'); ?></span>
              </div>
              <button type="button" class="ews-face-lab-btn" id="ews-face-open"><?php echo esc_html($face_enrolled?__('Verify Face','workforce-one'):__('Set Up / Verify Face','workforce-one')); ?></button>
            </div>
            <div class="ews-face-modal" id="ews-face-modal" aria-hidden="true">
              <div class="ews-face-card" role="dialog" aria-modal="true">
                <div class="ews-face-top"><div><h3><?php esc_html_e('Face Verification','workforce-one'); ?></h3><p><?php esc_html_e('Blink once and gently move your head. Face processing stays on this device.','workforce-one'); ?></p></div><button type="button" class="ews-face-close" id="ews-face-close" aria-label="<?php esc_attr_e('Close','workforce-one'); ?>"><?php echo Icons::svg('close', 20, 2); ?></button></div>
                <div class="ews-face-stage"><video id="ews-face-video" autoplay muted playsinline></video><div class="ews-face-guide"></div></div>
                <div class="ews-face-status" id="ews-face-status"><?php esc_html_e('Loading face model…','workforce-one'); ?></div>
                <div class="ews-face-actions">
                  <button type="button" class="ews-face-enroll" id="ews-face-enroll" <?php echo $face_enrolled?'style="display:none"':''; ?>><?php esc_html_e('Enroll Face','workforce-one'); ?></button>
                  <button type="button" class="ews-face-verify" id="ews-face-verify"><?php esc_html_e('Verify Face','workforce-one'); ?></button>
                  <button type="button" class="ews-face-clear" id="ews-face-reset"><?php esc_html_e('Request Reset','workforce-one'); ?></button>
                </div>
              </div>
            </div>
