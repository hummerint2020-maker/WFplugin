<?php
/**
 * wp-admin "Feature Configuration" page. Styles: assets/css/admin-features.css.
 *
 * @var array<string,bool> $on                 feature switches (tasks, presence_qr, presence_verification, breaks, face, recognition, kudos, overtime, early_leave_office_only, confirm_global)
 * @var array{per_day:int,duration:int,escalation:int} $breaks
 * @var array{max:int,monthly:int} $early_leave
 * @var array<string,int|float> $face           face tuning values
 * @var array<string,array> $face_fields        key => [label, min, max, step]
 * @var int[] $detector_sizes
 * @var array<string,mixed> $splash
 * @var array{mode:string,limit:int} $recognition
 * @var array<string,string> $confirm_labels
 * @var array<string,int> $confirm
 * @var string $privacy_html                    section rendered by the Privacy module
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

<div class="wfo-feature-section"><div class="wfo-feature-head">
    <div><div class="wfo-feature-title">Tasks</div><div class="wfo-feature-desc">Native Workforce One task management with personal tasks, manager assignment, priorities and due dates.</div></div>
    <?php $switch('tasks_enabled', $on['tasks']); ?>
</div></div>

<div class="wfo-feature-section">
    <div><div class="wfo-feature-title">Presence Layer</div><div class="wfo-feature-desc">Optional workplace presence tools. Dynamic QR Sign-In and manager-requested Presence Verification are independent from normal Sign In / Out.</div></div>
    <div class="wfo-feature-fields">
        <label><input type="checkbox" name="presence_qr_signin" value="1" <?php checked($on['presence_qr']); ?>> Dynamic QR Sign-In</label>
        <label><input type="checkbox" name="presence_verification" value="1" <?php checked($on['presence_verification']); ?>> Presence Verification</label>
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
</div></div>

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
