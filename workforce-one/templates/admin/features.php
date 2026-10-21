<?php
/**
 * wp-admin "Feature Configuration" page. Styles: assets/css/admin-features.css.
 *
 * @var array<string,bool> $on                 feature switches (tasks, corrections, daily_workers, office_minimum, presence_qr, presence_verification, breaks, face, recognition, kudos, overtime, early_leave_office_only, early_leave_self, confirm_global, app_api, api_refresh_safe)
 * @var array{per_day:int,duration:int,escalation:int} $breaks
 * @var array{max:int,monthly:int} $early_leave
 * @var array<string,int|float> $face           face tuning values
 * @var array<string,array> $face_fields        key => [label, min, max, step]
 * @var int[] $detector_sizes
 * @var array{attempts:int,attempt_days:int,alert:int,photo:int,photo_days:int,photo_viewers:string} $evidence  Settings\SignInEvidence (3.31.92)
 * @var array<string,mixed> $splash
 * @var array<string,mixed> $offline        Settings\OfflineScreen::config() (3.31.93)
 * @var array<string,array> $offline_texts   OfflineScreen::TEXTS
 * @var array<string,string[]> $offline_sections  OfflineScreen::SECTIONS
 * @var string $offline_preview             the offline page in preview mode (add &preview=off|press|down)
 * @var array{enabled:int,audience:string,employee_view:string,wide_span:int,title_required:int} $org  Settings\OrgChartSettings::config() (3.31.97)
 * @var string $org_url                     wp-admin → People → Org chart
 * @var array{manager_decides:int,on_return:string,who:string,ask_on_leave:string} $substitute  Approvals\Substitutes::settings() (3.31.107)
 * @var string $substitutes_url             wp-admin → Substitutes
 * @var array<string,mixed> $advance       Payroll\Advances::settings() (3.31.109)
 * @var string $advances_url
 * @var int $health_rate       System health: percent of requests with query detail (3.31.110)
 * @var int $health_retention  System health: days of daily history
 * @var string $health_url
 * @var array{mode:string,limit:int} $recognition
 * @var array<string,string> $confirm_labels
 * @var array<string,int> $confirm
 * @var array<string,mixed> $branch        Settings\BranchSettings (3.31.72)
 * @var int $branch_count                  active work locations
 * @var string $locations_url
 * @var string $privacy_html                    section rendered by the Privacy module
 * @var int $presence_minutes                   time an employee has to answer a presence request
 * @var array<string,mixed> $office_minimum   Settings\OfficeMinimumSettings (3.31.77)
 * @var array<int,array{name:string,size:int,share:int}> $om_teams   teams with their size and automatic share
 * @var string[] $om_statuses                  the schedule statuses that exist
 * @var string[] $weekday_names                 0 = Sunday
 * @var int[] $working_days
 * @var bool $saved
 * @var string|null $error
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
$switch = static function (string $name, bool $checked, string $label = 'Enabled') {
    echo '<label class="wfo-feature-status"><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '> ' . esc_html($label) . '</label>';
};
?>
<div class="wrap"><h1>Feature Configuration</h1>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p>Feature configuration saved.</p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div class="wfo-features-shell" style="margin-top:18px">
<div class="wfo-features-intro"><p>Enable or disable optional Workforce One features. Disabling a feature hides it from users and blocks direct access to its functionality.</p></div>
<form method="post" action="<?php echo esc_url($post_url); ?>">
<?php wp_nonce_field('ews_features_save'); ?>
<input type="hidden" name="action" value="ews31_features_save">
<input type="hidden" name="corrections_present" value="1">
<input type="hidden" name="daily_workers_present" value="1">
<input type="hidden" name="office_minimum_present" value="1">
<input type="hidden" name="evidence_present" value="1">
<input type="hidden" name="offline_present" value="1">
<input type="hidden" name="org_present" value="1">
<input type="hidden" name="my_approvals_present" value="1">
<input type="hidden" name="app_api_present" value="1">
<input type="hidden" name="substitutes_present" value="1">
<input type="hidden" name="advances_present" value="1">
<input type="hidden" name="health_present" value="1">

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Tasks</div><div class="wfo-feature-desc">Native Workforce One task management with personal tasks, manager assignment, priorities and due dates.</div></div>
    <?php $switch('tasks_enabled', $on['tasks']); ?>
</div></div>

<div class="wfo-feature-section" id="ews-corrections"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Attendance Corrections</div><div class="wfo-feature-desc">Employees ask to correct a forgotten Sign In / Sign Out or a wrong time; the manager decides with the day's evidence; HR can correct directly. Recorded events are never changed. <a href="<?php echo esc_url(admin_url('admin.php?page=ews31-corrections&tab=settings')); ?>">Settings</a></div></div>
    <?php $switch('corrections_enabled', $on['corrections']); ?>
</div></div>

<div class="wfo-feature-section" id="ews-my-approvals"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">My approvals and My requests</div><div class="wfo-feature-desc">One inbox in the app for every request waiting for a person's decision (leave, overtime, corrections, swaps, face reset), with its level, how long it has waited and the path, and approving several at once. Each employee also gets My requests, to follow their requests level by level and withdraw one still waiting. The approval lists inside each page stay. Who decides, the reason to reject and when a request is overdue: <a href="<?php echo esc_url(admin_url('admin.php?page=ews31-approvals')); ?>">Approval Workflows</a>.</div></div>
    <?php $switch('my_approvals_enabled', $on['my_approvals']); ?>
</div></div>

<div class="wfo-feature-section" id="ews-advances"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title"><?php esc_html_e('Salary advances', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('Employees ask for an advance from My requests → New request and choose how many months to pay it back over. It is decided on the approval engine (by default the direct manager, then Finance); Finance records it as paid, and the instalments come off the pay from the month after.', 'workforce-one'); ?> <a href="<?php echo esc_url($advances_url); ?>"><?php esc_html_e('Advances', 'workforce-one'); ?></a></div></div>
    <?php $switch('advances_enabled', $on['advances']); ?>
</div>
<div class="wfo-feature-fields">
    <label><span class="wfo-field-label"><?php esc_html_e('The most per advance, in months of salary (0 = no limit by salary)', 'workforce-one'); ?></span><input type="number" name="advance_months_salary" min="0" max="24" step="0.5" value="<?php echo esc_attr((string) $advance['months_salary']); ?>"></label>
    <label><span class="wfo-field-label"><?php esc_html_e('And at most this amount (0 = none)', 'workforce-one'); ?></span><input type="number" name="advance_max_amount" min="0" step="1" value="<?php echo esc_attr((string) $advance['max_amount']); ?>"></label>
    <label><span class="wfo-field-label"><?php esc_html_e('The most instalments (months)', 'workforce-one'); ?></span><input type="number" name="advance_max_instalments" min="1" max="60" value="<?php echo (int) $advance['max_instalments']; ?>"></label>
    <label><span class="wfo-field-label"><?php esc_html_e('After how many months with the company', 'workforce-one'); ?></span><input type="number" name="advance_min_service" min="0" max="120" value="<?php echo (int) $advance['min_service_months']; ?>"></label>
    <label><span class="wfo-field-label"><?php esc_html_e('Times Finance may move an instalment to the end, per advance (0 = no limit)', 'workforce-one'); ?></span><input type="number" name="advance_max_moves" min="0" max="12" value="<?php echo (int) $advance['max_moves']; ?>"></label>
    <label><input type="checkbox" name="advance_one_open" value="1" <?php checked(!empty($advance['one_open'])); ?>> <?php esc_html_e('One open advance at a time', 'workforce-one'); ?></label>
</div></div>

<div class="wfo-feature-section" id="ews-app-api"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title"><?php esc_html_e('Mobile app API', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('Lets the Android and iPhone apps read the signed-in employee’s profile and menu, their own schedule and today’s attendance. Read only: it changes no rule, permission or request, and an employee can only ever read their own data. Off, the apps get “disabled” and nothing else changes.', 'workforce-one'); ?></div></div>
    <?php $switch('app_api_enabled', $on['app_api']); ?>
</div>
<div class="wfo-feature-fields">
    <label><input type="checkbox" name="api_refresh_safe" value="1" <?php checked(!empty($on['api_refresh_safe'])); ?>> <?php esc_html_e('Safer app sign-in refresh: a phone that refreshes its sign-in several times at once, or retries, stays signed in (up to 4 more times within 30 seconds); a database error no longer signs it out. Off: as before.', 'workforce-one'); ?></label>
</div></div>

<div class="wfo-feature-section" id="ews-system-health"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title"><?php esc_html_e('System health', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('Measures how long Workforce One’s pages, actions and scheduled jobs take, their database queries, memory and PHP errors, and lists what to look at first. The data stays in this site’s database; nothing is sent anywhere. Adds about 1 ms to a Workforce One request.', 'workforce-one'); ?> <a href="<?php echo esc_url($health_url); ?>"><?php esc_html_e('System health', 'workforce-one'); ?></a></div></div>
    <?php $switch('health_enabled', $on['health']); ?>
</div>
<?php if (is_multisite() && !is_super_admin()): ?><p class="description"><?php esc_html_e('On a network only a super admin can change this.', 'workforce-one'); ?></p><?php endif; ?>
<div class="wfo-feature-fields">
    <label><span class="wfo-field-label"><?php esc_html_e('Detailed query data from this share of requests', 'workforce-one'); ?></span><select name="health_sample_rate"><?php foreach (\WorkforceOne\Health\Settings::RATES as $r): ?><option value="<?php echo (int) $r; ?>" <?php selected($health_rate, $r); ?>><?php echo esc_html(sprintf(/* translators: %d: percent */ __('%d%% of requests', 'workforce-one'), $r)); ?></option><?php endforeach; ?></select></label>
    <label><span class="wfo-field-label"><?php esc_html_e('Keep the daily history for', 'workforce-one'); ?></span><select name="health_retention_days"><?php foreach (\WorkforceOne\Health\Settings::RETENTION as $d): ?><option value="<?php echo (int) $d; ?>" <?php selected($health_retention, $d); ?>><?php echo esc_html(sprintf(/* translators: %d: days */ __('%d days', 'workforce-one'), $d)); ?></option><?php endforeach; ?></select></label>
</div></div>

<div class="wfo-feature-section" id="ews-substitutes"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title"><?php esc_html_e('A substitute while a manager is away', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('While a manager is away, the requests that would wait for them wait for their substitute, marked "for" them, in every request type. A manager picks one when they ask for leave, or with "I\'ll be away" in My approvals (any start and end time); HR can set one for anyone.', 'workforce-one'); ?> <a href="<?php echo esc_url($substitutes_url); ?>"><?php esc_html_e('Substitutes', 'workforce-one'); ?></a></div></div>
    <?php $switch('substitutes_enabled', $on['substitutes']); ?>
</div>
<div class="wfo-feature-fields">
    <label><input type="checkbox" name="substitute_manager_decides" value="1" <?php checked(!empty($substitute['manager_decides'])); ?>> <?php esc_html_e('The manager can still decide while away', 'workforce-one'); ?></label>
    <label><span class="wfo-field-label"><?php esc_html_e('When the manager is back, what still waits', 'workforce-one'); ?></span><select name="substitute_on_return"><option value="back" <?php selected($substitute['on_return'], 'back'); ?>><?php esc_html_e('Goes back to the manager', 'workforce-one'); ?></option><option value="stay" <?php selected($substitute['on_return'], 'stay'); ?>><?php esc_html_e('Stays with the substitute until decided', 'workforce-one'); ?></option></select></label>
    <label><span class="wfo-field-label"><?php esc_html_e('Who can be a substitute', 'workforce-one'); ?></span><select name="substitute_who"><option value="any" <?php selected($substitute['who'], 'any'); ?>><?php esc_html_e('Any active employee', 'workforce-one'); ?></option><option value="managers" <?php selected($substitute['who'], 'managers'); ?>><?php esc_html_e('Managers only (someone reports to them)', 'workforce-one'); ?></option><option value="department" <?php selected($substitute['who'], 'department'); ?>><?php esc_html_e('Someone in the same department', 'workforce-one'); ?></option></select></label>
    <label><span class="wfo-field-label"><?php esc_html_e('Ask managers for one when they request leave', 'workforce-one'); ?></span><select name="substitute_ask"><option value="optional" <?php selected($substitute['ask_on_leave'], 'optional'); ?>><?php esc_html_e('Optional', 'workforce-one'); ?></option><option value="required" <?php selected($substitute['ask_on_leave'], 'required'); ?>><?php esc_html_e('Required', 'workforce-one'); ?></option><option value="no" <?php selected($substitute['ask_on_leave'], 'no'); ?>><?php esc_html_e('Do not ask (HR sets them)', 'workforce-one'); ?></option></select></label>
</div></div>

<div class="wfo-feature-section" id="ews-signin-attempts"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Refused Sign In Attempts</div><div class="wfo-feature-desc">Each time the app refuses a Sign In or Sign Out (outside the work location, no face check, not a working day, too early, ...), the attempt is kept with the reason, the time, where the phone was, the device and the IP. A Sign In pressed with no network is sent by the phone later and shown apart; it never counts as attendance. Shown in <a href="<?php echo esc_url(admin_url('admin.php?page=ews31-time-report')); ?>">Sign In / Out</a>.</div></div>
    <?php $switch('evidence_attempts', (bool) $evidence['attempts']); ?>
</div>
<div class="wfo-feature-fields">
    <label><span class="wfo-field-label">Keep them for (days)</span><input type="number" name="evidence_attempt_days" min="7" max="365" value="<?php echo (int) $evidence['attempt_days']; ?>"></label>
    <label><span class="wfo-field-label">Tell the department's managers after this many refusals in a day (0 = never)</span><input type="number" name="evidence_alert" min="0" max="20" value="<?php echo (int) $evidence['alert']; ?>"></label>
</div></div>

<div class="wfo-feature-section" id="ews-office-minimum"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title"><?php esc_html_e('Office Minimum', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('A fixed number of people in the office every working day. Whoever sets the schedule sees a warning and the teams under their share; saving is never blocked.', 'workforce-one'); ?></div></div>
    <?php $switch('office_minimum_enabled', $on['office_minimum'], __('Enabled', 'workforce-one')); ?>
</div>
<div class="wfo-om-admin"><table class="form-table" role="presentation">
    <tr><th scope="row"><label for="ews-om-min"><?php esc_html_e('Minimum each day', 'workforce-one'); ?></label></th><td><input id="ews-om-min" type="number" min="1" name="om_min" class="small-text" value="<?php echo (int) $office_minimum['min']; ?>"> <?php esc_html_e('people in the office', 'workforce-one'); ?>
        <p class="description"><?php esc_html_e('Working days only; company holidays and days off are not checked.', 'workforce-one'); ?></p></td></tr>
    <tr><th scope="row"><?php esc_html_e('A different day (optional)', 'workforce-one'); ?></th><td><div class="wfo-om-wds">
        <?php foreach ($working_days as $w): ?><label class="wfo-om-wd"><span><?php echo esc_html($weekday_names[$w] ?? (string) $w); ?></span><input type="number" min="0" name="om_days[<?php echo (int) $w; ?>]" class="small-text" placeholder="<?php echo (int) $office_minimum['min']; ?>" value="<?php echo isset($office_minimum['days'][$w]) ? (int) $office_minimum['days'][$w] : ''; ?>"></label><?php endforeach; ?>
        </div><p class="description"><?php esc_html_e('Empty = the minimum above. 0 = no minimum that day.', 'workforce-one'); ?></p></td></tr>
    <tr><th scope="row"><?php esc_html_e('Who counts as in the office', 'workforce-one'); ?></th><td>
        <?php foreach ($om_statuses as $st): ?><label><input type="checkbox" name="om_statuses[]" value="<?php echo esc_attr($st); ?>" <?php checked(in_array($st, $office_minimum['statuses'], true)); ?>> <?php echo esc_html($st); ?></label><br><?php endforeach; ?>
        <p class="description"><?php esc_html_e('Leave, trips and training count only if ticked here.', 'workforce-one'); ?></p></td></tr>
    <tr><th scope="row"><?php esc_html_e('Each team\'s share', 'workforce-one'); ?></th><td>
        <p><?php esc_html_e('Automatic, by team size. You can fix a number for a team.', 'workforce-one'); ?></p>
        <?php if (!$om_teams): ?><p class="description"><?php esc_html_e('No teams yet: the minimum is checked for the whole company.', 'workforce-one'); ?></p><?php else: ?>
        <table class="widefat striped wfo-om-share"><thead><tr><th><?php esc_html_e('Team', 'workforce-one'); ?></th><th><?php esc_html_e('People', 'workforce-one'); ?></th><th><?php esc_html_e('Automatic share', 'workforce-one'); ?></th><th><?php esc_html_e('Fixed number', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php foreach ($om_teams as $tid => $t): ?><tr><td><?php echo esc_html($t['name']); ?></td><td><?php echo (int) $t['size']; ?></td><td><?php echo (int) $t['share']; ?></td>
            <td><?php if ($tid > 0): ?><input type="number" min="0" class="small-text" name="om_teams[<?php echo (int) $tid; ?>]" placeholder="<?php esc_attr_e('Auto', 'workforce-one'); ?>" value="<?php echo isset($office_minimum['teams'][$tid]) ? (int) $office_minimum['teams'][$tid] : ''; ?>"><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <p class="description"><?php esc_html_e('People in no team share one line ("No team").', 'workforce-one'); ?></p><?php endif; ?></td></tr>
    <tr><th scope="row"><?php esc_html_e('Reminder', 'workforce-one'); ?></th><td><label><input type="checkbox" name="om_reminder" value="1" <?php checked((int) $office_minimum['reminder'], 1); ?>> <?php esc_html_e('Every week, send schedule managers the days below the minimum next week', 'workforce-one'); ?></label><br>
        <label><?php esc_html_e('Reminder day', 'workforce-one'); ?> <select name="om_reminder_day"><?php foreach ($weekday_names as $w => $n): ?><option value="<?php echo (int) $w; ?>" <?php selected((int) $office_minimum['reminder_day'], (int) $w); ?>><?php echo esc_html($n); ?></option><?php endforeach; ?></select></label>
        <input type="time" name="om_reminder_time" value="<?php echo esc_attr($office_minimum['reminder_time']); ?>"></td></tr>
</table></div></div>

<div class="wfo-feature-section" id="ews-daily-workers"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Daily Workers</div><div class="wfo-feature-desc">Day labourers without an email or an account: foremen record each site's day from inside the site with a group photo, workers can sign in with their mobile and a PIN, payout sheets and labour cost per project. Never mixed with staff. <a href="<?php echo esc_url(admin_url('admin.php?page=ews31-daily-workers&tab=settings')); ?>">Settings</a></div></div>
    <?php $switch('daily_workers_enabled', $on['daily_workers']); ?>
</div></div>

<div class="wfo-feature-section">
    <div><div class="wfo-feature-title">Presence Layer</div><div class="wfo-feature-desc">Optional workplace presence tools. Dynamic QR Sign-In and manager-requested Presence Verification are independent from normal Sign In / Out.</div></div>
    <div class="wfo-feature-fields">
        <label><input type="checkbox" name="presence_qr_signin" value="1" <?php checked($on['presence_qr']); ?>> Dynamic QR Sign-In</label>
        <label><input type="checkbox" name="presence_verification" value="1" <?php checked($on['presence_verification']); ?>> Presence Verification</label>
        <label>Time to answer a request <input type="number" name="presence_request_minutes" min="1" max="60" step="1" value="<?php echo (int) $presence_minutes; ?>" style="width:80px"> minutes</label>
    </div>
    <p class="description">Create and manage workplace Kiosks from <strong>Presence Kiosks</strong>. Kiosks only display a rotating QR code and never contain employee credentials.</p>
</div>

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Vacation Requests</div><div class="wfo-feature-desc">Employees can submit vacation requests and managers can approve them.</div></div>
    <span style="font-weight:700;color:#008a20">Enabled</span>
</div></div>

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Break Management</div><div class="wfo-feature-desc">Informative break tracking with employee notifications and Manager escalation for extended breaks.</div>
        <div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <label>Breaks per day<br><input type="number" min="1" max="20" name="breaks_per_day" value="<?php echo (int) $breaks['per_day']; ?>" style="width:90px"></label>
            <label>Break duration (minutes)<br><input type="number" min="1" max="480" name="break_duration" value="<?php echo (int) $breaks['duration']; ?>" style="width:120px"></label>
            <label>Manager alert after (minutes)<br><input type="number" min="2" max="1440" name="break_escalation" value="<?php echo (int) $breaks['escalation']; ?>" style="width:150px"></label>
        </div>
        <div class="wfo-feature-desc">The manager alert always comes after the break duration.</div>
    </div>
    <?php $switch('break_enabled', $on['breaks']); ?>
</div></div>

<div class="wfo-feature-section">
    <div><div class="wfo-feature-title">Early Leave</div><div class="wfo-feature-desc">Manager-approved attendance exception with configurable monthly allowance.</div>
        <div style="margin-top:10px;display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <label>Max per request (minutes)<br><input type="number" min="1" max="480" name="early_leave_max" value="<?php echo (int) $early_leave['max']; ?>" style="width:120px"></label>
            <label>Monthly allowance (minutes)<br><input type="number" min="1" max="7440" name="early_leave_monthly" value="<?php echo (int) $early_leave['monthly']; ?>" style="width:140px"></label>
            <label><input type="checkbox" name="early_leave_office_only" value="1" <?php checked($on['early_leave_office_only']); ?>> Office workdays only</label>
            <label><input type="hidden" name="early_leave_self_shown" value="1"><input type="checkbox" name="early_leave_self" value="1" <?php checked($on['early_leave_self']); ?>> A manager may decide their own request</label>
        </div>
    </div>
</div>

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Face Verification for Sign In</div><div class="wfo-feature-desc">Require local browser face verification before Sign In. Face data stays in the employee browser.</div></div>
    <?php $switch('face_signin_enabled', $on['face']); ?>
</div>
<div class="wfo-subpanel">
    <div class="wfo-subpanel-title">Face Algorithm Tuning</div><div class="wfo-subpanel-desc">Advanced tuning. Lower values generally make detection easier; higher values make it stricter.</div>
    <div class="wfo-feature-fields">
    <?php foreach ($face_fields as $key => [$label, $min, $max, $step]): ?>
        <label><span class="wfo-field-label"><?php echo esc_html($label); ?></span><input type="number" name="face_signin_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($face[$key]); ?>" min="<?php echo esc_attr($min); ?>" max="<?php echo esc_attr($max); ?>" step="<?php echo esc_attr($step); ?>"></label>
    <?php endforeach; ?>
        <label><span class="wfo-field-label">Detector input size</span><select name="face_signin_settings[detector_input_size]" style="width:100%;min-height:40px">
        <?php foreach ($detector_sizes as $size): ?><option value="<?php echo (int) $size; ?>" <?php selected((int) $face['detector_input_size'], $size); ?>><?php echo (int) $size; ?></option><?php endforeach; ?>
        </select></label>
    </div>
</div>
<div class="wfo-subpanel" id="ews-face-photo">
    <div class="wfo-subpanel-title"><label><input type="checkbox" name="evidence_photo" value="1" <?php checked((bool) $evidence['photo']); ?>> Keep a photo with each Face Sign In / Out</label></div>
    <div class="wfo-subpanel-desc">A small photo from the same camera frame as the face check is kept with the Sign In or Sign Out (and with a refused face check), for HR to check a doubtful record. Employees see a line on the camera screen saying a photo is kept and for how long; check that your staff policy covers photos at work. Each time a photo is opened it is written to the Audit Log.</div>
    <div class="wfo-feature-fields">
        <label><span class="wfo-field-label">Keep photos for (days)</span><input type="number" name="evidence_photo_days" min="7" max="90" value="<?php echo (int) $evidence['photo_days']; ?>"></label>
        <label><span class="wfo-field-label">Who can see them</span><select name="evidence_photo_viewers" style="width:100%;min-height:40px"><option value="hr_manager" <?php selected($evidence['photo_viewers'], 'hr_manager'); ?>>HR and the employee's department manager</option><option value="hr" <?php selected($evidence['photo_viewers'], 'hr'); ?>>HR only</option></select></label>
    </div>
</div></div>

<div class="wfo-feature-section" id="ews-offline-screen"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Installed App: Offline Screen</div><div class="wfo-feature-desc">When the phone has no internet, or the server does not answer, the installed app shows this screen instead of the browser's blank page. It is kept on the phone, so it works even when the server is down; it holds no employee data (the employee's own schedule is read from their phone). It shows from the first time the app is opened after this is turned on. Every text below can be changed; an empty box uses the default. In the texts, <code>{time}</code> is the time of the Sign In / Out that did not reach the server, <code>{action}</code> is "Sign In" or "Sign Out", and <code>{updated}</code> is when the schedule was last refreshed. The "pressed with no connection" part needs <a href="#ews-signin-attempts">Refused Sign In Attempts</a> on.</div></div>
    <?php $switch('offline_enabled', (bool) $offline['enabled']); ?>
</div>
<div class="wfo-offline-grid" style="display:grid;grid-template-columns:minmax(0,1.4fr) minmax(280px,1fr);gap:18px;align-items:start;margin-top:12px">
<div class="wfo-offline-form" style="display:grid;gap:12px;min-width:0">
    <fieldset style="border:1px solid #dcdcde;border-radius:8px;padding:10px 14px 12px"><legend style="font-weight:600;padding:0 4px">When to show it</legend>
        <label>Server not answering <input type="number" name="offline_timeout" min="5" max="60" value="<?php echo (int) $offline['timeout']; ?>" style="width:70px"> seconds, then show "not responding"</label><br>
        <label><input type="checkbox" name="offline_schedule" value="1" <?php checked((bool) $offline['schedule']); ?> data-offline-live> Show the employee's schedule (today and the days after it, as last seen with the internet on; nothing else is kept on the phone)</label><br>
        <label><input type="checkbox" name="offline_keep_note" value="1" <?php checked((bool) $offline['keep_note']); ?> data-offline-live> Show the "keep the app open" note</label>
    </fieldset>
    <?php foreach ($offline_sections as $title => $keys): ?>
    <fieldset style="border:1px solid #dcdcde;border-radius:8px;padding:10px 14px 12px;display:grid;gap:8px"><legend style="font-weight:600;padding:0 4px"><?php echo esc_html($title); ?></legend>
        <?php foreach ($keys as $k): [$label, $default, $max, $multi] = $offline_texts[$k]; $id = 'ews-offline-' . $k; $val = $offline['saved'][$k] ?? ''; ?>
        <div style="display:grid;grid-template-columns:170px minmax(0,1fr);gap:4px 10px;align-items:start">
            <label for="<?php echo esc_attr($id); ?>" style="padding-top:5px"><?php echo esc_html($label); ?></label>
            <?php if ($multi): ?><textarea id="<?php echo esc_attr($id); ?>" name="offline_texts[<?php echo esc_attr($k); ?>]" rows="2" maxlength="<?php echo (int) $max; ?>" placeholder="<?php echo esc_attr($default); ?>" data-offline-text="<?php echo esc_attr($k); ?>" data-default="<?php echo esc_attr($default); ?>" style="width:100%"><?php echo esc_textarea($val); ?></textarea>
            <?php else: ?><input type="text" id="<?php echo esc_attr($id); ?>" name="offline_texts[<?php echo esc_attr($k); ?>]" maxlength="<?php echo (int) $max; ?>" placeholder="<?php echo esc_attr($default); ?>" value="<?php echo esc_attr($val); ?>" data-offline-text="<?php echo esc_attr($k); ?>" data-default="<?php echo esc_attr($default); ?>" style="width:100%"><?php endif; ?>
            <button type="button" class="button-link" data-offline-reset="<?php echo esc_attr($id); ?>" style="grid-column:2;justify-self:start;font-size:12px">Reset to default</button>
        </div>
        <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
    <p class="description">Phones get new texts the next time the app is opened with the internet on.</p>
</div>
<div style="position:sticky;top:40px">
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px" role="group" aria-label="Preview">
        <button type="button" class="button button-primary" data-offline-state="off">No internet</button>
        <button type="button" class="button" data-offline-state="press">Sign In pressed</button>
        <button type="button" class="button" data-offline-state="down">Server down</button>
    </div>
    <iframe id="ews-offline-preview" title="Offline screen preview" src="<?php echo esc_url(add_query_arg('preview', 'off', $offline_preview)); ?>" style="width:100%;max-width:380px;height:640px;border:10px solid #101225;border-radius:30px;background:#f2f3f8"></iframe>
    <p class="description">Preview with sample data. "Back online" is a short message shown in the app: <?php echo esc_html($offline['texts']['toast0']); ?></p>
</div>
</div>
<script>
(function(){
  var f=document.getElementById('ews-offline-preview');if(!f)return;
  var box=document.getElementById('ews-offline-screen');
  function send(){
    var t={};box.querySelectorAll('[data-offline-text]').forEach(function(el){t[el.getAttribute('data-offline-text')]=el.value.trim()||el.getAttribute('data-default');});
    var q=function(n){var i=box.querySelector('[name="'+n+'"]');return i&&i.checked?1:0;};
    try{f.contentWindow.postMessage({wfoOffline:1,t:t,keep:q('offline_keep_note'),schedule:q('offline_schedule')},location.origin);}catch(e){}
  }
  box.addEventListener('input',send);box.addEventListener('change',send);f.addEventListener('load',send);
  box.querySelectorAll('[data-offline-reset]').forEach(function(b){b.addEventListener('click',function(){var el=document.getElementById(b.getAttribute('data-offline-reset'));el.value='';send();});});
  box.querySelectorAll('[data-offline-text]').forEach(function(el){el.addEventListener('focus',function(){var fs=el.closest('fieldset'),i=Array.prototype.indexOf.call(box.querySelectorAll('.wfo-offline-form fieldset'),fs);var st=[null,'off','down','press'][i];if(st)go(st);});});
  function go(st){var u=new URL(f.src);if(u.searchParams.get('preview')===st)return;u.searchParams.set('preview',st);f.src=u.toString();box.querySelectorAll('[data-offline-state]').forEach(function(b){b.className=b.getAttribute('data-offline-state')===st?'button button-primary':'button';});}
  box.querySelectorAll('[data-offline-state]').forEach(function(b){b.addEventListener('click',function(){go(b.getAttribute('data-offline-state'));});});
})();
</script>
<style>@media (max-width:1100px){#ews-offline-screen .wfo-offline-grid{grid-template-columns:minmax(0,1fr)!important}}</style>
</div>

<div class="wfo-feature-section" id="ews-org-chart"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Organization Chart</div><div class="wfo-feature-desc">A chart of who reports to whom, built on each employee's Direct Supervisor (Employees page): nothing is entered twice. Administrators see it in wp-admin → People → <a href="<?php echo esc_url($org_url); ?>">Org chart</a>; employees and managers can see it in the app as "My team" when chosen below. While it is on, the Employees page also has a Job Title for each employee. A supervisor cannot be set under someone who reports to the employee, at any level.</div></div>
    <?php $switch('org_enabled', (bool) $org['enabled']); ?>
</div>
<div class="wfo-feature-fields">
    <label><span class="wfo-field-label">Who sees the chart</span><select name="org_audience" style="width:100%;min-height:40px">
        <option value="admins" <?php selected($org['audience'], 'admins'); ?>>Administrators only (wp-admin)</option>
        <option value="managers" <?php selected($org['audience'], 'managers'); ?>>Administrators and managers (managers see their part in the app)</option>
        <option value="everyone" <?php selected($org['audience'], 'everyone'); ?>>Everyone (in the app)</option>
    </select></label>
    <label><span class="wfo-field-label">An employee sees</span><select name="org_employee_view" style="width:100%;min-height:40px">
        <option value="line" <?php selected($org['employee_view'], 'line'); ?>>Their reporting line, teammates and the people under them</option>
        <option value="company" <?php selected($org['employee_view'], 'company'); ?>>The whole company</option>
    </select><span style="display:block;color:#646970;font-size:12px;margin-top:4px">Used when everyone sees the chart. Managers always see the people under them.</span></label>
    <label><span class="wfo-field-label">Flag a manager with more than</span><input type="number" name="org_wide_span" min="0" max="100" value="<?php echo (int) $org['wide_span']; ?>" style="width:90px"> direct reports<span style="display:block;color:#646970;font-size:12px;margin-top:4px">0 = never.</span></label>
    <label><span class="wfo-field-label">Job title</span><input type="checkbox" name="org_title_required" value="1" <?php checked((bool) $org['title_required']); ?>> Required when adding an employee</label>
</div>
</div>

<div class="wfo-feature-section">
    <div class="wfo-feature-title">PWA Splash Screen</div>
    <div class="wfo-feature-desc">Customize the splash shown when Workforce One opens as a PWA. This affects the installed PWA only.</div>
    <div class="wfo-feature-fields" style="max-width:760px">
        <label><strong>Enable Splash</strong><br><input type="checkbox" name="pwa_splash[enabled]" value="1" <?php checked(!empty($splash['enabled'])); ?>> Enabled</label>
        <label><strong>Minimum duration (ms)</strong><br><input type="number" name="pwa_splash[duration_ms]" value="<?php echo esc_attr($splash['duration_ms']); ?>" min="0" max="3000" step="50"></label>
        <label><strong>App title</strong><br><input type="text" name="pwa_splash[title]" value="<?php echo esc_attr($splash['title']); ?>" maxlength="60"></label>
        <label><strong>Subtitle</strong><br><input type="text" name="pwa_splash[subtitle]" value="<?php echo esc_attr($splash['subtitle']); ?>" maxlength="100"></label>
        <label><strong>Background</strong><br><input type="text" name="pwa_splash[background]" value="<?php echo esc_attr($splash['background']); ?>" placeholder="#f7f7fb"></label>
        <label><strong>Accent / loader color</strong><br><input type="text" name="pwa_splash[accent]" value="<?php echo esc_attr($splash['accent']); ?>" placeholder="#6125c9"></label>
        <label style="grid-column:1/-1"><strong>Logo URL</strong><br><input type="url" name="pwa_splash[logo]" value="<?php echo esc_attr($splash['logo']); ?>" placeholder="https://..."><span style="display:block;color:#646970;font-size:12px;margin-top:4px">Leave empty to use the default app icon.</span></label>
    </div>
</div>

<div class="wfo-feature-section">
    <div class="wfo-feature-head"><div><div class="wfo-feature-title">Recognition &amp; Kudos</div><div class="wfo-feature-desc">Peer-to-peer appreciation. Recognition is not a performance score.</div></div><?php $switch('recognition_enabled', $on['recognition']); ?></div>
    <div class="wfo-feature-fields">
        <label><span class="wfo-field-label">Allow employees to give Kudos</span><input type="checkbox" name="recognition_allow_kudos" value="1" <?php checked($on['kudos']); ?>> Employees can send Kudos to colleagues.</label>
        <label><span class="wfo-field-label">Weekly sending limit</span><select name="recognition_weekly_limit_mode" style="width:100%;min-height:40px;border:1px solid #cfd3d8;border-radius:8px;padding:7px 11px;box-sizing:border-box"><option value="limited" <?php selected($recognition['mode'], 'limited'); ?>>Limited</option><option value="unlimited" <?php selected($recognition['mode'], 'unlimited'); ?>>Unlimited</option></select></label>
        <label><span class="wfo-field-label">Maximum Kudos per employee / week</span><input type="number" min="1" max="1000" name="recognition_weekly_limit" value="<?php echo (int) $recognition['limit']; ?>"><span style="display:block;color:#667085;font-size:12px;margin-top:4px">Calendar week: Monday through Sunday. Ignored when Unlimited is selected.</span></label>
    </div>
</div>

<div class="wfo-feature-section" id="ews-branches">
    <div class="wfo-feature-title">Branches</div>
    <div class="wfo-feature-desc">How an employee's branch is decided when they sign in. A branch is a <a href="<?php echo esc_url($locations_url); ?>">Work Location</a> (<?php echo (int) $branch_count; ?> active); each employee's branches are set on Work Locations.</div>
    <div class="wfo-branch-modes" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:12px">
    <?php foreach (['single' => ['One branch', 'Each employee signs in at their main branch only.', 'One office, or people who never move.'], 'any' => ['Any of their branches', 'An employee can sign in at their main branch or any of their other branches. The branch they were at is recorded.', 'Sales, support, supervisors who move between branches.'], 'schedule' => ['By the schedule', 'Each Office day in the Attendance planner has a branch. The employee signs in at that day\'s branch; a day without one uses their main branch.', 'Shifts across branches: clinics, shops, security.']] as $mode => [$title, $desc, $fits]): ?>
        <label style="border:<?php echo $branch['mode'] === $mode ? '2px solid #2271b1;background:#f0f6fc' : '1px solid #dcdcde'; ?>;border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:6px;cursor:pointer">
            <span style="display:flex;gap:8px;align-items:center;font-weight:600"><input type="radio" name="branch_mode" value="<?php echo esc_attr($mode); ?>" <?php checked($branch['mode'], $mode); ?>> <?php echo esc_html($title); ?></span>
            <span style="font-size:13px;color:#50575e"><?php echo esc_html($desc); ?></span>
            <span style="font-size:12px;background:#f6f7f7;border-radius:6px;padding:6px 8px">Fits: <?php echo esc_html($fits); ?></span>
        </label>
    <?php endforeach; ?>
    </div>
    <div class="wfo-feature-fields" style="margin-top:12px">
        <label><input type="checkbox" name="branch_allow_others" value="1" <?php checked(!empty($branch['allow_others'])); ?>> <strong>Also allow their other branches</strong><span style="display:block;color:#646970;font-size:12px">With "By the schedule": if the employee is at one of their other branches instead, accept it and flag it for the manager.</span></label>
        <label><input type="checkbox" name="branch_kiosk_any" value="1" <?php checked(!empty($branch['kiosk_any'])); ?>> <strong>Kiosk QR at any allowed branch</strong><span style="display:block;color:#646970;font-size:12px">A branch's kiosk counts as the employee's place for everyone allowed there today, not only people whose main branch it is.</span></label>
        <label><input type="checkbox" name="branch_show_branch" value="1" <?php checked(!empty($branch['show_branch'])); ?>> <strong>Show today's branch to employees</strong><span style="display:block;color:#646970;font-size:12px">On Home and the Sign In page (when there is more than one branch).</span></label>
        <label><input type="checkbox" name="branch_manager_scope" value="1" <?php checked(!empty($branch['manager_scope'])); ?>> <strong>Managers see their department's branches only</strong><span style="display:block;color:#646970;font-size:12px">Limits the branches a manager can plan or give to the branches their people use.</span></label>
    </div>
</div>

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Overtime Requests</div><div class="wfo-feature-desc">Allow employees to request overtime and managers to review it.</div></div>
    <?php $switch('overtime_enabled', $on['overtime']); ?>
</div></div>

<div class="wfo-feature-section">
    <div class="wfo-feature-title">Confirmation Dialogs</div>
    <div class="wfo-feature-desc">Control confirmation prompts for actions that can change or remove data. The global switch overrides all individual settings.</div>
    <p><label><input type="checkbox" name="confirm_global" value="1" <?php checked($on['confirm_global']); ?>> <strong>Enable Confirmation Dialogs</strong></label></p>
    <div class="wfo-confirm-grid">
    <?php foreach ($confirm_labels as $key => $label): ?>
        <label class="wfo-confirm-item"><input type="checkbox" name="confirm_actions[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($confirm[$key])); ?>> <?php echo esc_html($label); ?></label>
    <?php endforeach; ?>
    </div>
</div>

<?php echo $privacy_html; // Built and escaped by privacy_settings_section(). ?>

<div class="wfo-savebar"><span style="color:#667085;font-size:13px">Changes apply after saving this configuration.</span><button class="button button-primary">Save Feature Configuration</button></div>
</form>
</div>
</div>
