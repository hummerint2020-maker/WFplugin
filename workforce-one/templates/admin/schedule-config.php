<?php
/**
 * wp-admin "Schedule Configuration" page. Styles: assets/css/admin-schedule-config.css.
 *
 * @var array<int,array<string,mixed>> $types       schedule types (all, in form order)
 * @var int $active_types
 * @var array<int,object> $events                   upcoming company holidays (id, event_date, title)
 * @var array{start:string,end:string} $hours
 * @var int $grace_period
 * @var int $absent_after   minutes after a shift's start when a no-show counts as Absent today
 * @var bool $overnight_enabled
 * @var array<int,array<string,mixed>> $shifts
 * @var int[] $working_days
 * @var array<int,string> $day_names                 WordPress weekday => name
 * @var string|null $notice
 * @var string|null $error
 * @var string $post_url
 * @var array<int,string> $delete_urls              holiday id => nonce'd delete URL
 */
if (!defined('ABSPATH')) exit;
$rules = ['attendance' => 'Attendance', 'leave' => 'Leave', 'business_trip' => 'Business Trip'];
?>
<div class="wrap ews-sc-admin">
<div class="ews-sc-hero"><h1>Schedule Configuration</h1><p>Set the company working calendar, attendance timing, and schedule types used across Workforce One.</p>
    <div class="ews-sc-stats">
        <span class="ews-sc-stat"><strong><?php echo count($types); ?></strong> schedule types</span>
        <span class="ews-sc-stat"><strong><?php echo (int) $active_types; ?></strong> active</span>
        <span class="ews-sc-stat"><strong><?php echo count($working_days); ?></strong> working days</span>
        <span class="ews-sc-stat"><strong><?php echo count($events); ?></strong> upcoming holidays</span>
    </div>
</div>
<?php if ($notice): ?><div class="notice notice-success is-dismissible ews-sc-notice"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible ews-sc-notice" style="border-left-color:#d63638!important"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>

<div class="ews-sc-grid">
<div>
    <div class="ews-sc-card"><div class="ews-sc-card-head"><h2>Working Hours</h2><p>Define when the workday starts and ends, and how long the grace period lasts before an arrival is classified as Late Arrival.</p></div><div class="ews-sc-card-body">
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_working_hours_save'); ?><input type="hidden" name="action" value="ews31_working_hours_save">
            <div class="ews-sc-fields">
                <div class="ews-sc-field"><label>Work Start</label><input type="time" name="work_start" value="<?php echo esc_attr($hours['start']); ?>" required><div class="ews-sc-help">Start of the scheduled workday.</div></div>
                <div class="ews-sc-field"><label>Grace Period</label><div style="display:flex;gap:8px;align-items:center"><input type="number" name="grace_period" value="<?php echo esc_attr($grace_period); ?>" min="0" max="180" step="1" style="max-width:180px"><span style="color:#667085;font-size:13px">minutes</span></div><div class="ews-sc-help">Time allowed after Work Start before the arrival is classified as late.</div></div>
                <div class="ews-sc-field"><label>Absent After</label><div style="display:flex;gap:8px;align-items:center"><input type="number" name="absent_after" value="<?php echo (int) $absent_after; ?>" min="15" max="720" step="5" style="max-width:180px"><span style="color:#667085;font-size:13px">minutes</span></div><div class="ews-sc-help">For employees on a shift: when someone who has not signed in shows as Absent today (dashboards, today's reports). Before that they show as Pending. Past days count as Absent anyway.</div></div>
                <div class="ews-sc-field"><label>Work End</label><input type="time" name="work_end" value="<?php echo esc_attr($hours['end']); ?>" required><div class="ews-sc-help">End of the scheduled workday.</div></div>
            </div>
            <div style="margin-top:18px;padding:14px 16px;border:1px solid #e4e7ec;border-radius:11px;background:#fafbfc;display:flex;align-items:center;justify-content:space-between;gap:16px"><div><strong style="display:block;color:#344054;font-size:13px">Allow Overnight Shift</strong><span style="display:block;margin-top:3px;color:#667085;font-size:12px;line-height:1.45">Allow working hours to cross midnight into the next day, e.g. 22:00 → 06:00.</span></div><label style="display:flex;align-items:center;gap:8px;white-space:nowrap;font-weight:600;color:#344054"><input type="checkbox" name="allow_overnight_shift" value="1" <?php checked($overnight_enabled); ?>> Enabled</label></div>
            <div class="ews-sc-actions"><button class="button button-primary">Save Working Hours</button></div>
        </form>
        <div class="ews-sc-help">Example: 08:00 start → 10 minute grace period → arrivals after 08:10 are Late Arrival. Actual Sign In time is always preserved. Overnight mode is OFF by default.</div>
    </div></div>

    <div class="ews-sc-card"><div class="ews-sc-card-head"><h2>Shifts</h2><p>Create reusable shifts. A Default Shift can then be assigned to each employee and automatically controls their work hours, grace period, and Sign In cutoff.</p></div><div class="ews-sc-card-body">
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_shifts_save'); ?><input type="hidden" name="action" value="ews31_shifts_save">
            <div class="ews-sc-table-wrap"><table class="ews-sc-table"><thead><tr><th>Name</th><th>Start</th><th>End</th><th>Grace (min)</th><th>Sign In Cutoff (min)</th><th>Overnight</th><th>Active</th></tr></thead><tbody>
            <?php foreach ($shifts as $i => $shift): $f = 'shifts[' . (int) $i . ']'; ?>
                <tr><td><input type="hidden" name="<?php echo $f; ?>[id]" value="<?php echo (int) $shift['id']; ?>"><input name="<?php echo $f; ?>[name]" value="<?php echo esc_attr($shift['name']); ?>"></td>
                    <td><input type="time" name="<?php echo $f; ?>[start]" value="<?php echo esc_attr($shift['start']); ?>"></td>
                    <td><input type="time" name="<?php echo $f; ?>[end]" value="<?php echo esc_attr($shift['end']); ?>"></td>
                    <td><input type="number" name="<?php echo $f; ?>[grace]" value="<?php echo (int) $shift['grace']; ?>" min="0" max="180"></td>
                    <td><input type="number" name="<?php echo $f; ?>[sign_in_cutoff_minutes]" value="<?php echo (int) ($shift['sign_in_cutoff_minutes'] ?? 240); ?>" min="0" max="1440"></td>
                    <td class="center"><input type="checkbox" name="<?php echo $f; ?>[overnight]" value="1" <?php checked(!empty($shift['overnight'])); ?>></td>
                    <td class="center"><input type="checkbox" name="<?php echo $f; ?>[active]" value="1" <?php checked(!empty($shift['active'])); ?>></td></tr>
            <?php endforeach; ?>
                <tr class="ews-sc-new"><td><input name="new_shift[name]" placeholder="New shift"></td><td><input type="time" name="new_shift[start]" value="08:00"></td><td><input type="time" name="new_shift[end]" value="17:00"></td><td><input type="number" name="new_shift[grace]" value="10" min="0" max="180"></td><td><input type="number" name="new_shift[sign_in_cutoff_minutes]" value="240" min="0" max="1440"></td><td class="center"><input type="checkbox" name="new_shift[overnight]" value="1"></td><td class="center"><input type="checkbox" name="new_shift[active]" value="1" checked></td></tr>
            </tbody></table></div>
            <div class="ews-sc-actions"><button class="button button-primary">Save Shifts</button></div>
        </form>
        <div class="ews-sc-help">Sign In Cutoff is measured from Shift Start. Default is 240 minutes (4 hours). Grace only controls On Time vs Late. The effective cutoff never extends past Shift End. Employees without a Default Shift use the company Working Hours with the 4-hour default cutoff. Clear a shift's name to remove it.</div>
    </div></div>

    <div class="ews-sc-card"><div class="ews-sc-card-head"><h2>Schedule Types</h2><p>Control what each schedule type requires for Sign In, location, and attendance processing.</p></div><div class="ews-sc-card-body">
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_schedule_config_save'); ?><input type="hidden" name="action" value="ews31_schedule_config_save">
            <div class="ews-sc-table-wrap"><table class="ews-sc-table"><thead><tr><th>Name</th><th>Icon</th><th>Background</th><th>Text</th><th>Border</th><th>Sign In</th><th>Location</th><th>Attendance Rule</th><th>Active</th></tr></thead><tbody>
            <?php foreach ($types as $i => $type): $f = 'types[' . (int) $i . ']'; ?>
                <tr>
                    <td><input name="<?php echo $f; ?>[name]" value="<?php echo esc_attr($type['name']); ?>" aria-label="Schedule type name"></td>
                    <td><input name="<?php echo $f; ?>[icon]" value="<?php echo esc_attr($type['icon'] ?? '•'); ?>" style="width:58px;text-align:center" maxlength="8" aria-label="Schedule type icon"></td>
                    <td><input type="color" name="<?php echo $f; ?>[bg_color]" value="<?php echo esc_attr($type['bg_color'] ?? '#f2f4f7'); ?>" aria-label="Background color"></td>
                    <td><input type="color" name="<?php echo $f; ?>[text_color]" value="<?php echo esc_attr($type['text_color'] ?? '#667085'); ?>" aria-label="Text color"></td>
                    <td><input type="color" name="<?php echo $f; ?>[border_color]" value="<?php echo esc_attr($type['border_color'] ?? '#e5e7eb'); ?>" aria-label="Border color"></td>
                    <td class="center"><input type="checkbox" name="<?php echo $f; ?>[requires_sign_in]" value="1" <?php checked(!empty($type['requires_sign_in'])); ?>></td>
                    <td class="center"><input type="checkbox" name="<?php echo $f; ?>[requires_location]" value="1" <?php checked(!empty($type['requires_location'])); ?>></td>
                    <td><select name="<?php echo $f; ?>[attendance_rule]"><?php foreach ($rules as $value => $label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($type['attendance_rule'], $value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td>
                    <td class="center"><input type="checkbox" name="<?php echo $f; ?>[active]" value="1" <?php checked(!empty($type['active'])); ?>></td>
                </tr>
            <?php endforeach; ?>
                <tr class="ews-sc-new"><td><input name="types[new][name]" placeholder="New schedule type"></td><td><input name="types[new][icon]" value="•" style="width:58px;text-align:center" maxlength="8"></td><td><input type="color" name="types[new][bg_color]" value="#f2f4f7"></td><td><input type="color" name="types[new][text_color]" value="#667085"></td><td><input type="color" name="types[new][border_color]" value="#e5e7eb"></td><td class="center"><input type="checkbox" name="types[new][requires_sign_in]" value="1"></td><td class="center"><input type="checkbox" name="types[new][requires_location]" value="1"></td>
                    <td><select name="types[new][attendance_rule]"><?php foreach ($rules as $value => $label): ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td><td class="center"><input type="checkbox" name="types[new][active]" value="1" checked></td></tr>
            </tbody></table></div>
            <div class="ews-sc-actions"><button class="button button-primary">Save Schedule Configuration</button></div>
        </form>
        <div class="ews-sc-help">Tip: WFH is configured as Sign In = Yes and Office Location = No; it is not special-cased in code. Office, WFH and Vacation are built in and cannot be renamed or removed; a type already used in schedules can only be deactivated.</div>
    </div></div>
</div>

<div>
    <div class="ews-sc-card"><div class="ews-sc-card-head"><h2>Working Days</h2><p>Choose the normal company working days. Unselected days are treated as days off.</p></div><div class="ews-sc-card-body">
        <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_working_days_save'); ?><input type="hidden" name="action" value="ews31_working_days_save">
            <div class="ews-sc-days">
            <?php foreach ($day_names as $day => $name): ?>
                <label class="ews-sc-day"><input type="checkbox" name="working_days[]" value="<?php echo (int) $day; ?>" <?php checked(in_array($day, $working_days, true)); ?>> <span><?php echo esc_html($name); ?></span></label>
            <?php endforeach; ?>
            </div>
            <div class="ews-sc-actions"><button class="button button-primary">Save Working Days</button></div>
        </form>
        <div class="ews-sc-help">Default: Sunday–Thursday. For this client, select Saturday through Thursday and leave Friday unchecked.</div>
    </div></div>

    <div class="ews-sc-card"><div class="ews-sc-card-head"><h2>General Leave</h2><p>Add company-wide holidays without changing employees’ stored schedules.</p></div><div class="ews-sc-card-body">
        <form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews31_general_leave_save"><?php wp_nonce_field('ews_general_leave_save'); ?>
            <div class="ews-sc-calendar-form"><div><label>Date</label><input type="date" name="event_date" required></div><div><label>Holiday title</label><input type="text" name="title" maxlength="190" required placeholder="e.g. National Holiday"></div><button class="button button-primary">Add Holiday</button></div>
        </form>
        <div class="ews-sc-events"><h3 style="font-size:14px;margin:16px 0 8px">Upcoming holidays</h3><table><thead><tr><th>Date</th><th>Title</th><th></th></tr></thead><tbody>
        <?php if (!$events): ?><tr><td colspan="3">No General Leave configured.</td></tr><?php endif; ?>
        <?php foreach ($events as $event): ?>
            <tr><td><?php echo esc_html(date_i18n('D, d M Y', strtotime($event->event_date))); ?></td><td><?php echo esc_html($event->title); ?></td><td style="text-align:right"><a class="ews-sc-delete" href="<?php echo esc_url($delete_urls[(int) $event->id]); ?>" data-ews-confirm-key="general_leave_delete">Remove</a></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div></div>
</div>
</div>
</div>
