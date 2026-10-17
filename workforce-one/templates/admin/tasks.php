<?php
/**
 * wp-admin → Workforce One → Tasks (3.31.70): switch each part of Tasks on or off and set its limits.
 * Saved by tasks_settings_save() through src/Settings/TaskSettings.php. Styles: assets/css/admin-features.css.
 *
 * @var array<string,mixed> $cfg
 * @var bool $enabled              Tasks itself is on (Feature Configuration)
 * @var bool $saved
 * @var string $features_url
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Settings\TaskSettings;
$switch = static function (string $name, $value) {
    echo '<label class="wfo-feature-status"><input type="checkbox" name="' . esc_attr($name) . '" value="1" ' . checked(!empty($value), true, false) . '> ' . esc_html__('On', 'workforce-one') . '</label>';
};
$section = static function (string $title, string $desc, string $key, $cfg, ?callable $fields = null) use ($switch) {
    echo '<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title">' . esc_html($title) . '</div><div class="wfo-feature-desc">' . esc_html($desc) . '</div></div>';
    $switch($key, $cfg[$key]);
    echo '</div>';
    if ($fields) { echo '<div class="wfo-feature-fields">'; $fields(); echo '</div>'; }
    echo '</div>';
};
$remind_labels = [0 => __('No reminder', 'workforce-one'), 15 => __('15 min before', 'workforce-one'), 30 => __('30 min before', 'workforce-one'), 60 => __('1 h before', 'workforce-one'), 120 => __('2 h before', 'workforce-one'), 1440 => __('1 day before', 'workforce-one')];
?>
<div class="wrap"><h1><?php esc_html_e('Tasks', 'workforce-one'); ?></h1>
<?php if ($saved): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Tasks settings saved.', 'workforce-one'); ?></p></div><?php endif; ?>
<?php if (!$enabled): ?><div class="notice notice-warning"><p><?php esc_html_e('Tasks is switched off, so nobody sees it.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Switch it on in Feature Configuration', 'workforce-one'); ?></a></p></div><?php endif; ?>
<div class="wfo-features-shell" style="margin-top:18px">
<div class="wfo-features-intro"><p><?php esc_html_e('Choose which parts of Tasks people see in the app. Switching a part off hides it; nothing that was saved is deleted.', 'workforce-one'); ?></p></div>
<form method="post" action="<?php echo esc_url($post_url); ?>">
<?php wp_nonce_field('ews_tasks_settings_save'); ?>
<input type="hidden" name="action" value="ews_tasks_settings_save">

<?php
$section(__('Personal tasks', 'workforce-one'), __('Employees can create tasks for themselves. Managers can always create and assign tasks.', 'workforce-one'), 'personal', $cfg);
$section(__('Checklist', 'workforce-one'), __('Small steps inside a task, with a progress bar.', 'workforce-one'), 'checklist', $cfg);
$section(__('Comments', 'workforce-one'), __('The people on a task can write to each other inside it.', 'workforce-one'), 'comments', $cfg, static function () use ($switch, $cfg) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Tell the people on the task about a new comment', 'workforce-one') . '</span>'; $switch('notify_comments', $cfg['notify_comments']); echo '</label>';
});
$section(__('Files with comments', 'workforce-one'), __('A file can be attached to a comment. Files are kept private: only people who can see the task can download them.', 'workforce-one'), 'attachments', $cfg, static function () use ($cfg) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Largest file (MB)', 'workforce-one') . '</span><input type="number" name="attach_max_mb" min="1" max="20" value="' . (int) $cfg['attach_max_mb'] . '"></label>';
    echo '<div><span class="wfo-field-label">' . esc_html__('Allowed file types', 'workforce-one') . '</span><div class="wfo-confirm-grid" style="margin-top:0">';
    foreach (TaskSettings::FILE_TYPES as $t) echo '<label class="wfo-confirm-item"><input type="checkbox" name="attach_types[]" value="' . esc_attr($t) . '" ' . checked(in_array($t, $cfg['attach_types'], true), true, false) . '> ' . esc_html(strtoupper($t)) . '</label>';
    echo '</div></div>';
});
$section(__('Activity', 'workforce-one'), __('Show who created, moved, edited or commented on a task, and when.', 'workforce-one'), 'activity', $cfg);
$section(__('Team tasks', 'workforce-one'), __('Managers can send a task to a whole team: a copy for each person, or one task the first person takes.', 'workforce-one'), 'teams', $cfg, static function () use ($cfg) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Chosen at first', 'workforce-one') . '</span><select name="team_mode"><option value="each" ' . selected($cfg['team_mode'], 'each', false) . '>' . esc_html__('One copy each', 'workforce-one') . '</option><option value="first" ' . selected($cfg['team_mode'], 'first', false) . '>' . esc_html__('First one takes it', 'workforce-one') . '</option></select></label>';
});
$section(__('Repeating tasks', 'workforce-one'), __('A task can come back every day, on chosen week days or on a day of the month. Each date gets its own task; a missed date is not made up.', 'workforce-one'), 'repeat', $cfg);
$section(__('Due time', 'workforce-one'), __('A time can be set with the due date.', 'workforce-one'), 'due_time', $cfg);
$section(__('Reminders', 'workforce-one'), __('A notification before the due time. Needs a due time.', 'workforce-one'), 'reminders', $cfg, static function () use ($cfg, $remind_labels) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Chosen at first', 'workforce-one') . '</span><select name="remind_default">';
    foreach ($remind_labels as $m => $label) echo '<option value="' . (int) $m . '" ' . selected((int) $cfg['remind_default'], $m, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label>';
});
$section(__('Workload', 'workforce-one'), __('A tab with each person\'s open, overdue and finished tasks.', 'workforce-one'), 'workload', $cfg, static function () use ($cfg) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Who sees it', 'workforce-one') . '</span><select name="workload_who"><option value="managers" ' . selected($cfg['workload_who'], 'managers', false) . '>' . esc_html__('Everyone who manages tasks', 'workforce-one') . '</option><option value="admins" ' . selected($cfg['workload_who'], 'admins', false) . '>' . esc_html__('Site administrators only', 'workforce-one') . '</option></select></label>';
});
$section(__('My tasks today on Home', 'workforce-one'), __('A card on the Home page with overdue tasks, tasks due today and tasks in progress, each with its next step.', 'workforce-one'), 'home_card', $cfg, static function () use ($cfg) {
    echo '<label><span class="wfo-field-label">' . esc_html__('Tasks shown', 'workforce-one') . '</span><input type="number" name="home_count" min="1" max="10" value="' . (int) $cfg['home_count'] . '"></label>';
});
$section(__('Drag on the board', 'workforce-one'), __('On a computer, a card can be dragged to the next column.', 'workforce-one'), 'drag', $cfg);
$section(__('Tell the creator about a new status', 'workforce-one'), __('When someone starts or finishes a task, the person who created it gets a notification.', 'workforce-one'), 'notify_status', $cfg);
?>
<div class="wfo-feature-section"><div class="wfo-feature-head"><div><div class="wfo-feature-title"><?php esc_html_e('Finished tasks shown', 'workforce-one'); ?></div><div class="wfo-feature-desc"><?php esc_html_e('How many finished tasks the Completed column shows before "Show all".', 'workforce-one'); ?></div></div></div>
<div class="wfo-feature-fields"><label><span class="wfo-field-label"><?php esc_html_e('Finished tasks', 'workforce-one'); ?></span><input type="number" name="done_limit" min="3" max="50" value="<?php echo (int) $cfg['done_limit']; ?>"></label></div></div>

<div class="wfo-savebar"><span style="color:#667085;font-size:13px"><?php esc_html_e('Changes apply right away.', 'workforce-one'); ?></span><button type="submit" class="button button-primary"><?php esc_html_e('Save Changes', 'workforce-one'); ?></button></div>
</form>
</div></div>
