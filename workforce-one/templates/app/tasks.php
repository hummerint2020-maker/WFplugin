<?php
/**
 * Employee app: Tasks (3.31.69; more in 3.31.70). The tasks by status: a board of three columns on a
 * computer (cards can be dragged to the next column), one list on a phone (In progress first). Each
 * task has one button for its next step (Start / Done, or Take it for a team task) and a menu for the
 * rest. A task opens in a sheet with its checklist, comments (with a file) and who did what. New task
 * can go to a person, a whole team (a copy each, or the first one takes it), repeat, and have a due
 * time with a reminder. Managers have a Workload tab. Each part can be switched off in wp-admin →
 * Tasks ($cfg). Styles: assets/css/app-requests.css (Tasks part); scripts: assets/js/sheet.js,
 * assets/js/tasks.js (search, priority filter, delete confirmation, the New task form, dragging).
 *
 * @var array<string,mixed> $cfg         src/Settings/TaskSettings.php
 * @var bool $manage                     can see and assign the team's tasks
 * @var bool $can_create
 * @var bool $can_workload
 * @var string $tab                      'my' | 'team' | 'workload'
 * @var string $priority_filter          'all' | a priority
 * @var object|null $counter             todo_count, progress_count, completed_count, overdue_count
 * @var array<string,object[]> $cards    'todo' | 'in_progress' | 'completed' => tasks (+ is_overdue, is_pool, can_take, can_status, status_nonce, can_edit, can_delete, edit_url, view_url, team_name)
 * @var array<int,int[]> $item_counts    task id => [done, all]
 * @var array<int,int> $comment_counts
 * @var array<string,mixed>|null $detail the opened task (task_details())
 * @var array<string,mixed>|null $workload
 * @var object[] $teams                  id, name, members (for New task)
 * @var int $done_total
 * @var bool $done_all
 * @var string $done_url
 * @var object[] $employees              id, name (people a manager can assign)
 * @var object|null $edit                the task being edited
 * @var string $base
 * @var callable $tab_url
 * @var callable $filter_url
 * @var string $error                    task_error code ('' = none)
 * @var string $post_url
 * @var string $today
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; the closures below escape their output.
$priorities = ['urgent' => __('Urgent', 'workforce-one'), 'high' => __('High', 'workforce-one'), 'normal' => __('Normal', 'workforce-one'), 'low' => __('Low', 'workforce-one')];
$statuses = ['todo' => __('To Do', 'workforce-one'), 'in_progress' => __('In Progress', 'workforce-one'), 'completed' => __('Completed', 'workforce-one')];
$weekdays = [0 => __('Sun', 'workforce-one'), 1 => __('Mon', 'workforce-one'), 2 => __('Tue', 'workforce-one'), 3 => __('Wed', 'workforce-one'), 4 => __('Thu', 'workforce-one'), 5 => __('Fri', 'workforce-one'), 6 => __('Sat', 'workforce-one')];
$initials = static function (string $name): string {
    $out = '';
    foreach (array_slice(array_values(array_filter(preg_split('/[\s._-]+/u', trim($name)) ?: [])), 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '·';
};
$hidden = static function (array $fields): string {
    $out = '';
    foreach ($fields as $k => $v) $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
    return $out;
};
$form = static function (string $action, string $nonce, array $fields, string $button, string $extra = '') use ($post_url, $hidden): string {
    return '<form method="post" action="' . esc_url($post_url) . '"' . $extra . '>' . wp_nonce_field($nonce, '_wpnonce', true, false) . $hidden(['action' => $action] + $fields) . $button . '</form>';
};
$status_form = static function (object $t, string $to, string $label, string $class, string $icon) use ($form): string {
    return $form('ews_task_status_update', 'ews_task_status_' . (int) $t->id, ['task_id' => (int) $t->id, 'status' => $to], '<button class="' . esc_attr($class) . '" type="submit">' . ($icon !== '' ? Icons::svg($icon, 16, 2.2) : '') . esc_html($label) . '</button>');
};
$take_form = static function (object $t, string $class) use ($form): string {
    return $form('ews_task_take', 'ews_task_take_' . (int) $t->id, ['task_id' => (int) $t->id], '<button class="' . esc_attr($class) . '" type="submit">' . Icons::svg('check', 16, 2.2) . esc_html__('Take it', 'workforce-one') . '</button>');
};
$repeat_text = static function (string $rule) use ($weekdays): string {
    if ($rule === 'daily') return __('Repeats every day', 'workforce-one');
    if (strpos($rule, 'weekly:') === 0) return sprintf(/* translators: %s: week days */ __('Repeats every week on %s', 'workforce-one'), implode(', ', array_map(static function ($d) use ($weekdays) { return $weekdays[(int) $d] ?? ''; }, explode(',', substr($rule, 7)))));
    if (strpos($rule, 'monthly:') === 0) return sprintf(/* translators: %d: day of the month */ __('Repeats every month on day %d', 'workforce-one'), (int) substr($rule, 8));
    return '';
};
$due_text = static function (object $t): string {
    $d = date_i18n('D d M', strtotime((string) $t->due_date));
    if (!empty($t->due_time)) $d .= ' · ' . substr((string) $t->due_time, 0, 5);
    return $t->is_overdue ? sprintf(/* translators: %s: due date */ __('Overdue · %s', 'workforce-one'), $d) : $d;
};
$card = static function (object $t) use ($priorities, $initials, $status_form, $take_form, $form, $tab, $item_counts, $comment_counts, $due_text): string {
    $pr = isset($priorities[$t->priority]) ? (string) $t->priority : 'normal';
    $drag = $t->can_status ? ' draggable="true" data-ews-task-id="' . (int) $t->id . '" data-ews-status="' . esc_attr($t->status) . '" data-ews-nonce="' . esc_attr($t->status_nonce) . '"' : '';
    $html = '<article class="wfo-tk-card pr-' . esc_attr($pr) . ($t->status === 'completed' ? ' is-done' : '') . ($t->is_pool ? ' is-pool' : '') . '" data-ews-task="' . esc_attr(mb_strtolower((string) $t->title . ' ' . (string) $t->description)) . '"' . $drag . '>';
    $html .= '<div class="wfo-tk-body"><h4 class="wfo-tk-title"><a href="' . esc_url($t->view_url) . '">' . esc_html($t->title) . '</a></h4>';
    if ((string) $t->description !== '') $html .= '<p class="wfo-tk-desc">' . esc_html($t->description) . '</p>';
    $html .= '<div class="wfo-tk-meta"><span class="wfo-tk-pr pr-' . esc_attr($pr) . '">' . esc_html($priorities[$pr]) . '</span>';
    if (!empty($t->due_date)) $html .= '<span class="wfo-tk-due' . ($t->is_overdue ? ' is-late' : '') . '">' . Icons::svg($t->is_overdue ? 'alert' : 'calendar', 14, 2) . esc_html($due_text($t)) . '</span>';
    if (isset($item_counts[(int) $t->id])) { [$d, $n] = $item_counts[(int) $t->id]; $html .= '<span class="wfo-tk-count' . ($d === $n ? ' is-full' : '') . '" title="' . esc_attr__('Checklist', 'workforce-one') . '">' . Icons::svg('check', 13, 2.4) . (int) $d . '/' . (int) $n . '</span>'; }
    if (!empty($comment_counts[(int) $t->id])) $html .= '<span class="wfo-tk-count" title="' . esc_attr__('Comments', 'workforce-one') . '">' . Icons::svg('mail', 13, 2) . (int) $comment_counts[(int) $t->id] . '</span>';
    if (!empty($t->repeat_of)) $html .= '<span class="wfo-tk-count" title="' . esc_attr__('Repeating task', 'workforce-one') . '">' . Icons::svg('swap', 13, 2) . '</span>';
    if ($t->is_pool) $html .= '<span class="wfo-tk-team">' . Icons::svg('people', 13, 2) . esc_html(sprintf(/* translators: %s: team name */ __('For %s', 'workforce-one'), (string) $t->team_name)) . '</span>';
    elseif (!empty($t->assigned_name) && $tab === 'team') $html .= '<span class="wfo-tk-who" title="' . esc_attr($t->assigned_name) . '"><b aria-hidden="true">' . esc_html($initials((string) $t->assigned_name)) . '</b>' . esc_html($t->assigned_name) . '</span>';
    $html .= '</div></div><div class="wfo-tk-side">';
    if ($t->can_take) $html .= $take_form($t, 'wfo-rq-btn is-soft');
    elseif ($t->can_status) {
        if ($t->status === 'todo') $html .= $status_form($t, 'in_progress', __('Start', 'workforce-one'), 'wfo-rq-btn is-soft', '');
        elseif ($t->status === 'in_progress') $html .= $status_form($t, 'completed', __('Done', 'workforce-one'), 'wfo-rq-btn is-done', 'check');
        else $html .= '<span class="wfo-rq-state is-approved">' . Icons::svg('check', 14, 2.4) . esc_html__('Done', 'workforce-one') . '</span>';
    }
    $menu = '<a class="wfo-tk-menu-item" href="' . esc_url($t->view_url) . '">' . esc_html__('Open', 'workforce-one') . '</a>';
    if ($t->can_status && $t->status === 'in_progress') $menu .= $status_form($t, 'todo', __('Back to To Do', 'workforce-one'), 'wfo-tk-menu-item', '');
    if ($t->can_status && $t->status === 'completed') $menu .= $status_form($t, 'in_progress', __('Reopen', 'workforce-one'), 'wfo-tk-menu-item', '');
    if ($t->can_edit) $menu .= '<a class="wfo-tk-menu-item" href="' . esc_url($t->edit_url) . '">' . esc_html__('Edit', 'workforce-one') . '</a>';
    if ($t->can_delete) $menu .= $form('ews_task_delete', 'ews_task_delete_' . (int) $t->id, ['task_id' => (int) $t->id], '<button class="wfo-tk-menu-item is-danger" type="submit">' . esc_html__('Delete', 'workforce-one') . '</button>', ' data-ews-task-delete="' . esc_attr__('Delete this task?', 'workforce-one') . '"');
    $html .= '<details class="wfo-tk-more"><summary aria-label="' . esc_attr(sprintf(/* translators: %s: task title */ __('More for %s', 'workforce-one'), $t->title)) . '">' . Icons::svg('dots', 18, 2) . '</summary><div class="wfo-tk-menu">' . $menu . '</div></details>';
    return $html . '</div></article>';
};
$columns = [
    'todo' => [__('To Do', 'workforce-one'), (int) ($counter->todo_count ?? 0)],
    'in_progress' => [__('In Progress', 'workforce-one'), (int) ($counter->progress_count ?? 0)],
    'completed' => [__('Completed', 'workforce-one'), (int) ($counter->completed_count ?? 0)],
];
$errors = [
    'transition' => __('That task can’t move to that status yet. Start it first, then you can complete it.', 'workforce-one'),
    'status' => __('We couldn’t update the task status. No changes were applied.', 'workforce-one'),
    'save' => __('We couldn’t save the task. No partial changes were applied.', 'workforce-one'),
    'delete' => __('We couldn’t delete the task. No changes were applied.', 'workforce-one'),
    'title' => __('Please enter a task title.', 'workforce-one'),
    'personal' => __('Only managers can create tasks here.', 'workforce-one'),
    'take' => __('Take this task first: it was sent to the whole team.', 'workforce-one'),
    'taken' => __('Someone from your team already took this task.', 'workforce-one'),
    'team_empty' => __('That team has nobody who can sign in, so no task was created.', 'workforce-one'),
    'file' => __('The file could not be uploaded. Please try again.', 'workforce-one'),
    /* translators: %d: megabytes */
    'file_size' => sprintf(__('The file is too big. The limit is %d MB.', 'workforce-one'), (int) $cfg['attach_max_mb']),
    /* translators: %s: list of file types */
    'file_type' => sprintf(__('This type of file is not allowed. Allowed: %s.', 'workforce-one'), strtoupper(implode(', ', $cfg['attach_types']))),
];
$activity_text = static function (object $a) use ($statuses, $repeat_text): string {
    $detail = (string) $a->detail;
    switch ($a->action) {
        case 'create': return __('created the task', 'workforce-one') . ($detail !== '' ? ' · ' . $repeat_text($detail) : '');
        /* translators: %s: status */
        case 'status': return sprintf(__('moved it to %s', 'workforce-one'), $statuses[$detail] ?? $detail);
        case 'edit': return __('edited the task', 'workforce-one');
        /* translators: %s: checklist step */
        case 'item_add': return sprintf(__('added a step: %s', 'workforce-one'), $detail);
        /* translators: %s: checklist step */
        case 'item_done': return sprintf(__('ticked %s', 'workforce-one'), $detail);
        /* translators: %s: checklist step */
        case 'item_undone': return sprintf(__('unticked %s', 'workforce-one'), $detail);
        /* translators: %s: checklist step */
        case 'item_delete': return sprintf(__('removed the step %s', 'workforce-one'), $detail);
        case 'comment': return $detail !== '' ? sprintf(/* translators: %s: file name */ __('commented with %s', 'workforce-one'), $detail) : __('commented', 'workforce-one');
        case 'take': return __('took the task', 'workforce-one');
        case 'repeat_stop': return __('stopped the repeat', 'workforce-one');
    }
    return (string) $a->action;
};
$remind_choices = [15 => __('15 min before', 'workforce-one'), 30 => __('30 min before', 'workforce-one'), 60 => __('1 h before', 'workforce-one'), 120 => __('2 h before', 'workforce-one'), 1440 => __('1 day before', 'workforce-one')];
$sheet = static function (string $id, ?object $task) use ($post_url, $priorities, $manage, $employees, $cfg, $teams, $weekdays, $remind_choices, $today): string {
    $pr = $task->priority ?? 'normal';
    $kind = $task && !empty($task->assigned_to) ? 'person' : 'me';
    $remind = $task ? (int) ($task->remind_minutes ?? 0) : (int) $cfg['remind_default'];
    ob_start(); ?>
    <dialog class="wfo-sheet" id="<?php echo esc_attr($id); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title"<?php echo $task ? ' data-wfo-sheet-start data-ews-forget="edit_task"' : ''; ?>>
        <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body wfo-tk-form"><?php wp_nonce_field('ews_task_save'); ?><input type="hidden" name="action" value="ews_task_save"><input type="hidden" name="task_id" value="<?php echo $task ? (int) $task->id : 0; ?>">
            <div class="wfo-sheet-grip" aria-hidden="true"></div>
            <div class="wfo-sheet-head"><h2 id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($task ? __('Edit Task', 'workforce-one') : __('New Task', 'workforce-one')); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
            <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-name"><?php esc_html_e('Title', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-name" name="title" value="<?php echo esc_attr($task->title ?? ''); ?>" required maxlength="255" placeholder="<?php esc_attr_e('What needs to be done?', 'workforce-one'); ?>"></div>
            <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-desc"><?php esc_html_e('Description', 'workforce-one'); ?></label><textarea id="<?php echo esc_attr($id); ?>-desc" name="description" placeholder="<?php esc_attr_e('Add useful context, instructions or notes...', 'workforce-one'); ?>"><?php echo esc_textarea($task->description ?? ''); ?></textarea></div>
            <fieldset class="wfo-rq-field wfo-tk-prio"><legend class="wfo-rq-label"><?php esc_html_e('Priority', 'workforce-one'); ?></legend>
                <div><?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?><label class="pr-<?php echo esc_attr($p); ?>"><input type="radio" name="priority" value="<?php echo esc_attr($p); ?>"<?php checked($pr, $p); ?>><span><?php echo esc_html($priorities[$p]); ?></span></label><?php endforeach; ?></div>
            </fieldset>
            <?php if ($manage): ?>
            <fieldset class="wfo-rq-field wfo-tk-seg" data-ews-task-kind><legend class="wfo-rq-label"><?php esc_html_e('Assign To', 'workforce-one'); ?></legend>
                <div>
                    <label><input type="radio" name="assign_kind" value="person"<?php checked($kind, 'person'); ?>><span><?php esc_html_e('A person', 'workforce-one'); ?></span></label>
                    <?php if ($cfg['teams'] && $teams && !$task): ?><label><input type="radio" name="assign_kind" value="team"><span><?php esc_html_e('A whole team', 'workforce-one'); ?></span></label><?php endif; ?>
                    <label><input type="radio" name="assign_kind" value="me"<?php checked($kind, 'me'); ?>><span><?php esc_html_e('Myself', 'workforce-one'); ?></span></label>
                </div>
            </fieldset>
            <div class="wfo-rq-field" data-ews-for-kind="person"><label for="<?php echo esc_attr($id); ?>-who"><?php esc_html_e('Person', 'workforce-one'); ?></label><select id="<?php echo esc_attr($id); ?>-who" name="assigned_to"><option value="0"><?php esc_html_e('Choose a person', 'workforce-one'); ?></option><?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"<?php selected((int) ($task->assigned_to ?? 0), (int) $e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></div>
            <?php if ($cfg['teams'] && $teams && !$task): ?>
            <div class="wfo-rq-field" data-ews-for-kind="team"><label for="<?php echo esc_attr($id); ?>-team"><?php esc_html_e('Team', 'workforce-one'); ?></label><select id="<?php echo esc_attr($id); ?>-team" name="team_id"><?php foreach ($teams as $tm): ?><option value="<?php echo (int) $tm->id; ?>"><?php /* translators: 1: team, 2: number of people */ echo esc_html(sprintf(_n('%1$s · %2$d person', '%1$s · %2$d people', (int) $tm->members, 'workforce-one'), $tm->name, (int) $tm->members)); ?></option><?php endforeach; ?></select>
                <div class="wfo-tk-seg is-small" role="radiogroup" aria-label="<?php esc_attr_e('How the team gets it', 'workforce-one'); ?>"><div>
                    <label><input type="radio" name="team_mode" value="each"<?php checked($cfg['team_mode'], 'each'); ?>><span><?php esc_html_e('One copy each', 'workforce-one'); ?></span></label>
                    <label><input type="radio" name="team_mode" value="first"<?php checked($cfg['team_mode'], 'first'); ?>><span><?php esc_html_e('First one takes it', 'workforce-one'); ?></span></label>
                </div></div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php if ($cfg['repeat'] && !$task): ?>
            <fieldset class="wfo-rq-field wfo-tk-seg" data-ews-task-repeat><legend class="wfo-rq-label"><?php esc_html_e('Repeat', 'workforce-one'); ?></legend>
                <div><?php foreach (['' => __('Never', 'workforce-one'), 'daily' => __('Daily', 'workforce-one'), 'weekly' => __('Weekly', 'workforce-one'), 'monthly' => __('Monthly', 'workforce-one')] as $k => $label): ?><label><input type="radio" name="repeat" value="<?php echo esc_attr($k); ?>"<?php checked($k, ''); ?>><span><?php echo esc_html($label); ?></span></label><?php endforeach; ?></div>
            </fieldset>
            <fieldset class="wfo-tk-days" data-ews-for-repeat="weekly"><legend class="screen-reader-text"><?php esc_html_e('Days of the week', 'workforce-one'); ?></legend><?php $dow = (int) gmdate('w', strtotime($today)); foreach ([6, 0, 1, 2, 3, 4, 5] as $d): ?><label><input type="checkbox" name="repeat_days[]" value="<?php echo (int) $d; ?>"<?php checked($d, $dow); ?>><span><?php echo esc_html($weekdays[$d]); ?></span></label><?php endforeach; ?></fieldset>
            <div class="wfo-rq-field" data-ews-for-repeat="monthly"><label for="<?php echo esc_attr($id); ?>-mday"><?php esc_html_e('Day of the month', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-mday" type="number" name="repeat_day" min="1" max="31" value="<?php echo (int) gmdate('j', strtotime($today)); ?>"></div>
            <?php endif; ?>
            <div class="wfo-rq-two">
                <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-due"><?php esc_html_e('Due Date', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-due" type="date" name="due_date" value="<?php echo esc_attr($task->due_date ?? ''); ?>"></div>
                <?php if ($cfg['due_time']): ?><div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-time"><?php esc_html_e('Time', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-time" type="time" name="due_time" value="<?php echo esc_attr(isset($task->due_time) ? substr((string) $task->due_time, 0, 5) : ''); ?>"></div><?php endif; ?>
            </div>
            <?php if ($cfg['reminders']): ?>
            <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-remind"><?php esc_html_e('Reminder', 'workforce-one'); ?></label><select id="<?php echo esc_attr($id); ?>-remind" name="remind_minutes"><option value="0"><?php esc_html_e('No reminder', 'workforce-one'); ?></option><?php foreach ($remind_choices as $m => $label): ?><option value="<?php echo (int) $m; ?>"<?php selected($remind, $m); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select><small class="wfo-tk-hint"><?php esc_html_e('Needs a time.', 'workforce-one'); ?></small></div>
            <?php endif; ?>
            <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg($task ? 'check' : 'plus', 18, 2.4); ?><?php echo esc_html($task ? __('Save Changes', 'workforce-one') : __('Create Task', 'workforce-one')); ?></button>
        </form>
    </dialog>
    <?php return (string) ob_get_clean();
};
?>
<div class="ews-page wfo-rq wfo-tk">
    <?php if ($error !== ''): ?><div class="ews-notice ews-notice-error" role="alert"><?php echo esc_html($errors[$error] ?? __('Something went wrong. Please try again.', 'workforce-one')); ?></div><?php endif; ?>

    <div class="wfo-tk-bar">
        <?php if ($manage || $can_workload): ?><nav class="wfo-tk-tabs" aria-label="<?php esc_attr_e('Tasks', 'workforce-one'); ?>">
            <a href="<?php echo esc_url($tab_url('my')); ?>"<?php echo $tab === 'my' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('My Tasks', 'workforce-one'); ?></a>
            <?php if ($manage): ?><a href="<?php echo esc_url($tab_url('team')); ?>"<?php echo $tab === 'team' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Team Tasks', 'workforce-one'); ?></a><?php endif; ?>
            <?php if ($can_workload): ?><a href="<?php echo esc_url($tab_url('workload')); ?>"<?php echo $tab === 'workload' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Workload', 'workforce-one'); ?></a><?php endif; ?>
        </nav><?php endif; ?>
        <?php if ($tab !== 'workload'): ?>
        <label class="wfo-tk-search"><?php echo Icons::svg('search', 17, 2); ?><span class="screen-reader-text"><?php esc_html_e('Search tasks', 'workforce-one'); ?></span><input type="search" id="wfo-tk-search" placeholder="<?php esc_attr_e('Search tasks', 'workforce-one'); ?>"></label>
        <form method="get" action="<?php echo esc_url(remove_query_arg(['task_priority', 'task_status', 'edit_task', 'task_done', 'task'], $base)); ?>" class="wfo-tk-prfilter">
            <input type="hidden" name="ews_view" value="tasks"><input type="hidden" name="task_tab" value="<?php echo esc_attr($tab); ?>">
            <label class="screen-reader-text" for="wfo-tk-priority"><?php esc_html_e('Priority', 'workforce-one'); ?></label>
            <select id="wfo-tk-priority" name="task_priority" data-ews-autosubmit><option value="all"><?php esc_html_e('All priorities', 'workforce-one'); ?></option><?php foreach ($priorities as $k => $label): ?><option value="<?php echo esc_attr($k); ?>"<?php selected($priority_filter, $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <noscript><button type="submit" class="wfo-rq-btn"><?php esc_html_e('Filter', 'workforce-one'); ?></button></noscript>
        </form>
        <?php endif; ?>
        <?php if ($can_create): ?><button type="button" class="wfo-rq-new wfo-tk-new" data-wfo-sheet="wfo-tk-sheet" aria-haspopup="dialog"><?php echo Icons::svg('plus', 18, 2.4); ?><?php esc_html_e('New Task', 'workforce-one'); ?></button><?php endif; ?>
    </div>

<?php if ($tab === 'workload' && $workload): $wt = $workload['total']; $max = max(1, (int) $wt['max']); ?>
    <div class="wfo-tk-stats">
        <div><span><?php esc_html_e('Open tasks', 'workforce-one'); ?></span><b><?php echo (int) $wt['open']; ?></b></div>
        <div class="is-late"><span><?php esc_html_e('Overdue', 'workforce-one'); ?></span><b><?php echo (int) $wt['late']; ?></b></div>
        <div class="is-done"><span><?php esc_html_e('Done this month', 'workforce-one'); ?></span><b><?php echo (int) $wt['done']; ?></b></div>
        <div><span><?php esc_html_e('On time', 'workforce-one'); ?></span><b><?php echo $workload['on_time'] === null ? '—' : (int) $workload['on_time'] . '%'; ?></b></div>
    </div>
    <section class="wfo-rq-card wfo-tk-load" aria-labelledby="wfo-tk-load-title">
        <div class="wfo-rq-card-head"><div><h3 id="wfo-tk-load-title"><?php esc_html_e('Team workload', 'workforce-one'); ?></h3><?php if ($workload['pool']): ?><p><?php /* translators: %d: number of team tasks nobody took yet */ printf(esc_html(_n('%d team task is waiting to be taken.', '%d team tasks are waiting to be taken.', (int) $workload['pool'], 'workforce-one')), (int) $workload['pool']); ?></p><?php endif; ?></div>
            <span class="wfo-tk-legend" aria-hidden="true"><i class="is-todo"></i><?php esc_html_e('To Do', 'workforce-one'); ?> <i class="is-prog"></i><?php esc_html_e('In Progress', 'workforce-one'); ?> <i class="is-late"></i><?php esc_html_e('Overdue', 'workforce-one'); ?></span></div>
        <?php foreach ($workload['rows'] as $r): $late_todo = min($r->late, $r->todo); ?>
        <div class="wfo-tk-load-row">
            <span class="wfo-tk-load-who"><span class="wfo-rq-ico is-person" aria-hidden="true"><?php echo esc_html($initials((string) $r->name)); ?></span><b><?php echo esc_html($r->name); ?></b></span>
            <span class="wfo-tk-bar-track" role="img" aria-label="<?php /* translators: 1: to do, 2: in progress, 3: overdue */ echo esc_attr(sprintf(__('%1$d to do, %2$d in progress, %3$d overdue', 'workforce-one'), $r->todo, $r->progress, $r->late)); ?>"><i class="is-todo" style="width:<?php echo (int) round(100 * ($r->todo - $late_todo) / $max); ?>%"></i><i class="is-prog" style="width:<?php echo (int) round(100 * $r->progress / $max); ?>%"></i><i class="is-late" style="width:<?php echo (int) round(100 * $late_todo / $max); ?>%"></i></span>
            <span><?php /* translators: %d: open tasks */ printf(esc_html__('%d open', 'workforce-one'), (int) $r->open); ?></span>
            <span class="<?php echo $r->late ? 'is-late' : 'is-zero'; ?>"><?php /* translators: %d: overdue tasks */ printf(esc_html__('%d overdue', 'workforce-one'), (int) $r->late); ?></span>
            <span class="is-done"><?php /* translators: %d: tasks done this month */ printf(esc_html__('%d done this month', 'workforce-one'), (int) $r->done); ?></span>
        </div>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <?php if ((int) ($counter->overdue_count ?? 0) > 0): ?><p class="wfo-tk-late"><?php echo Icons::svg('alert', 16, 2.2); ?><?php /* translators: %d: number of overdue tasks */ printf(esc_html(_n('%d task is overdue', '%d tasks are overdue', (int) $counter->overdue_count, 'workforce-one')), (int) $counter->overdue_count); ?></p><?php endif; ?>

    <div class="wfo-tk-board"<?php echo $cfg['drag'] ? ' data-ews-drag data-action="' . esc_url($post_url) . '"' : ''; ?>>
        <?php foreach ($columns as $key => [$title, $count]): ?>
        <section class="wfo-tk-col is-<?php echo esc_attr($key); ?>" data-ews-col="<?php echo esc_attr($key); ?>" aria-labelledby="wfo-tk-col-<?php echo esc_attr($key); ?>">
            <h3 class="wfo-tk-col-head" id="wfo-tk-col-<?php echo esc_attr($key); ?>"><?php echo esc_html($title); ?> <span><?php echo (int) $count; ?></span></h3>
            <?php foreach ($cards[$key] as $t) echo $card($t); ?>
            <?php if (!$cards[$key]): ?><p class="wfo-tk-none"><?php echo esc_html($key === 'completed' ? __('Nothing finished yet.', 'workforce-one') : __('Nothing here.', 'workforce-one')); ?></p><?php endif; ?>
            <?php if ($key === 'completed' && !$done_all && $done_total > count($cards['completed'])): ?><a class="wfo-tk-all" href="<?php echo esc_url($done_url); ?>"><?php /* translators: %d: number of finished tasks */ printf(esc_html__('Show all %d', 'workforce-one'), (int) $done_total); ?></a><?php endif; ?>
            <p class="wfo-tk-drop" aria-hidden="true"><?php echo esc_html($key === 'in_progress' ? __('Drop here to start', 'workforce-one') : ($key === 'completed' ? __('Drop here to finish', 'workforce-one') : __('Drop here to put it back', 'workforce-one'))); ?></p>
        </section>
        <?php endforeach; ?>
        <p class="wfo-tk-noresult" hidden><?php esc_html_e('No task matches your search.', 'workforce-one'); ?></p>
    </div>
<?php endif; ?>

    <?php if ($can_create) echo $sheet('wfo-tk-sheet', null); ?>
    <?php if ($edit) echo $sheet('wfo-tk-edit', $edit); ?>

<?php if ($detail): $t = $detail['task']; $t->is_overdue = !empty($t->due_date) && $t->status !== 'completed' && $t->due_date < $today; $pr = isset($priorities[$t->priority]) ? $t->priority : 'normal';
    $done_n = count(array_filter($detail['items'], static function ($i) { return (int) $i->done === 1; })); $all_n = count($detail['items']); ?>
    <dialog class="wfo-sheet wfo-tk-detail" id="wfo-tk-detail" aria-labelledby="wfo-tk-detail-title" data-wfo-sheet-start data-ews-forget="task">
        <div class="wfo-sheet-body">
            <div class="wfo-sheet-grip" aria-hidden="true"></div>
            <div class="wfo-sheet-head"><h2 id="wfo-tk-detail-title"><?php echo esc_html($t->title); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
            <div class="wfo-tk-meta">
                <span class="wfo-tk-pr pr-<?php echo esc_attr($pr); ?>"><?php echo esc_html($priorities[$pr]); ?></span>
                <span class="wfo-rq-state<?php echo $t->status === 'completed' ? ' is-approved' : ($t->status === 'in_progress' ? ' is-level' : ''); ?>"><?php echo esc_html($statuses[$t->status] ?? $t->status); ?></span>
                <?php if (!empty($t->due_date)): ?><span class="wfo-tk-due<?php echo $t->is_overdue ? ' is-late' : ''; ?>"><?php echo Icons::svg($t->is_overdue ? 'alert' : 'calendar', 14, 2); ?><?php echo esc_html($due_text($t)); ?></span><?php endif; ?>
            </div>
            <p class="wfo-tk-by"><?php
                if ($detail['is_pool']) printf(/* translators: 1: creator, 2: team */ esc_html__('From %1$s to the team %2$s: the first one to take it does it.', 'workforce-one'), '<b>' . esc_html((string) $t->creator_name) . '</b>', '<b>' . esc_html($detail['team']) . '</b>');
                elseif (!empty($t->assigned_name)) printf(/* translators: 1: creator, 2: assignee */ esc_html__('From %1$s to %2$s', 'workforce-one'), '<b>' . esc_html((string) $t->creator_name) . '</b>', '<b>' . esc_html((string) $t->assigned_name) . '</b>');
                else printf(/* translators: %s: creator */ esc_html__('Personal task of %s', 'workforce-one'), '<b>' . esc_html((string) $t->creator_name) . '</b>');
            ?></p>
            <?php if ((string) $t->description !== ''): ?><p class="wfo-tk-text"><?php echo nl2br(esc_html($t->description)); ?></p><?php endif; ?>
            <?php if ($detail['template']): ?><p class="wfo-tk-repeat"><?php echo Icons::svg('swap', 15, 2); ?><span><?php echo esc_html($repeat_text((string) $detail['template']->repeat_rule)); ?></span><?php if ($detail['can_stop']) echo $form('ews_task_repeat_stop', 'ews_task_repeat_stop_' . (int) $detail['template']->id, ['template_id' => (int) $detail['template']->id, 'task_id' => (int) $t->id], '<button class="wfo-tk-link" type="submit">' . esc_html__('Stop repeating', 'workforce-one') . '</button>', ' data-ews-task-delete="' . esc_attr__('Stop making new copies of this task? This one stays.', 'workforce-one') . '"'); ?></p><?php endif; ?>
            <div class="wfo-tk-actions">
                <?php if ($detail['can_take']) echo $take_form($t, 'wfo-rq-btn is-yes');
                elseif ($detail['can_work']) {
                    if ($t->status === 'todo') echo $status_form($t, 'in_progress', __('Start', 'workforce-one'), 'wfo-rq-btn is-yes', '');
                    elseif ($t->status === 'in_progress') echo $status_form($t, 'completed', __('Done', 'workforce-one'), 'wfo-rq-btn is-yes', 'check') . $status_form($t, 'todo', __('Back to To Do', 'workforce-one'), 'wfo-rq-btn', '');
                    else echo $status_form($t, 'in_progress', __('Reopen', 'workforce-one'), 'wfo-rq-btn', '');
                } ?>
                <?php if ($detail['can_edit']): ?><a class="wfo-rq-btn" href="<?php echo esc_url(add_query_arg(['edit_task' => (int) $t->id], remove_query_arg('task', $base))); ?>"><?php echo Icons::svg('edit', 16, 2); ?><?php esc_html_e('Edit', 'workforce-one'); ?></a><?php endif; ?>
            </div>

            <?php if ($cfg['checklist'] && ($all_n || $detail['can_work'])): ?>
            <section class="wfo-tk-sec" aria-labelledby="wfo-tk-check-title">
                <h3 id="wfo-tk-check-title"><?php esc_html_e('Checklist', 'workforce-one'); ?> <span><?php echo (int) $done_n; ?> / <?php echo (int) $all_n; ?></span></h3>
                <?php if ($all_n): ?><span class="wfo-tk-progress" aria-hidden="true"><i style="width:<?php echo (int) round(100 * $done_n / max(1, $all_n)); ?>%"></i></span><?php endif; ?>
                <ul class="wfo-tk-items">
                <?php foreach ($detail['items'] as $it): ?>
                    <li class="<?php echo $it->done ? 'is-done' : ''; ?>">
                        <?php if ($detail['can_work']) echo $form('ews_task_item_toggle', 'ews_task_item_toggle_' . (int) $it->id, ['item_id' => (int) $it->id], '<button type="submit" class="wfo-tk-tick" aria-pressed="' . ($it->done ? 'true' : 'false') . '" aria-label="' . esc_attr(sprintf(/* translators: %s: step */ __('Tick %s', 'workforce-one'), $it->title)) . '">' . ($it->done ? Icons::svg('check', 14, 3) : '') . '</button>');
                        else echo '<span class="wfo-tk-tick" aria-hidden="true">' . ($it->done ? Icons::svg('check', 14, 3) : '') . '</span>'; ?>
                        <span class="wfo-tk-item-text"><?php echo esc_html($it->title); ?></span>
                        <?php if ($detail['can_edit'] || (int) $t->created_by === get_current_user_id()) echo $form('ews_task_item_delete', 'ews_task_item_delete_' . (int) $it->id, ['item_id' => (int) $it->id], '<button type="submit" class="wfo-tk-x" aria-label="' . esc_attr(sprintf(/* translators: %s: step */ __('Remove %s', 'workforce-one'), $it->title)) . '">' . Icons::svg('close', 14, 2.2) . '</button>'); ?>
                    </li>
                <?php endforeach; ?>
                </ul>
                <?php if ($detail['can_work']) echo $form('ews_task_item_add', 'ews_task_item_' . (int) $t->id, ['task_id' => (int) $t->id], '<label class="screen-reader-text" for="wfo-tk-additem">' . esc_html__('New step', 'workforce-one') . '</label><input id="wfo-tk-additem" name="title" maxlength="255" required placeholder="' . esc_attr__('Add a step', 'workforce-one') . '"><button type="submit" class="wfo-rq-btn is-soft">' . Icons::svg('plus', 16, 2.4) . esc_html__('Add', 'workforce-one') . '</button>', ' class="wfo-tk-addform"'); ?>
            </section>
            <?php endif; ?>

            <?php if ($cfg['comments']): ?>
            <section class="wfo-tk-sec" aria-labelledby="wfo-tk-com-title">
                <h3 id="wfo-tk-com-title"><?php esc_html_e('Comments', 'workforce-one'); ?> <span><?php echo count($detail['comments']); ?></span></h3>
                <?php foreach ($detail['comments'] as $c): ?>
                <div class="wfo-tk-comment<?php echo (int) $c->user_id === get_current_user_id() ? ' is-mine' : ''; ?>">
                    <span class="wfo-rq-ico is-person" aria-hidden="true"><?php echo esc_html($initials((string) $c->display_name)); ?></span>
                    <div><p class="wfo-tk-comment-head"><b><?php echo esc_html((string) $c->display_name); ?></b> · <?php echo esc_html(date_i18n('D d M · H:i', strtotime((string) $c->created_at))); ?></p>
                        <?php if ((string) $c->body !== ''): ?><p class="wfo-tk-text"><?php echo nl2br(esc_html((string) $c->body)); ?></p><?php endif; ?>
                        <?php if (!empty($c->file_url)): ?><a class="wfo-tk-file" href="<?php echo esc_url($c->file_url); ?>"><?php echo Icons::svg('download', 15, 2.2); ?><?php echo esc_html((string) $c->file_name); ?> <small><?php echo esc_html(size_format((int) $c->file_size)); ?></small></a><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-tk-comment-form"<?php echo $cfg['attachments'] ? ' enctype="multipart/form-data"' : ''; ?>>
                    <?php wp_nonce_field('ews_task_comment_' . (int) $t->id); ?><input type="hidden" name="action" value="ews_task_comment"><input type="hidden" name="task_id" value="<?php echo (int) $t->id; ?>">
                    <label class="screen-reader-text" for="wfo-tk-comment"><?php esc_html_e('Comment', 'workforce-one'); ?></label>
                    <textarea id="wfo-tk-comment" name="body" rows="2" placeholder="<?php esc_attr_e('Write a comment…', 'workforce-one'); ?>"></textarea>
                    <div class="wfo-tk-comment-bar">
                        <?php if ($cfg['attachments']): ?><label class="wfo-tk-attach"><?php echo Icons::svg('download', 15, 2.2); ?><span data-ews-file-label><?php esc_html_e('Attach a file', 'workforce-one'); ?></span><input type="file" name="file" accept="<?php echo esc_attr('.' . implode(',.', $cfg['attach_types'])); ?>"></label>
                        <small><?php /* translators: 1: file types, 2: megabytes */ printf(esc_html__('%1$s · up to %2$d MB', 'workforce-one'), esc_html(strtoupper(implode(', ', $cfg['attach_types']))), (int) $cfg['attach_max_mb']); ?></small><?php endif; ?>
                        <button type="submit" class="wfo-rq-btn is-yes"><?php esc_html_e('Send', 'workforce-one'); ?></button>
                    </div>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($cfg['activity'] && $detail['activity']): ?>
            <section class="wfo-tk-sec" aria-labelledby="wfo-tk-act-title">
                <h3 id="wfo-tk-act-title"><?php esc_html_e('Activity', 'workforce-one'); ?></h3>
                <ul class="wfo-tk-activity">
                <?php foreach ($detail['activity'] as $a): ?><li><b><?php echo esc_html((string) ($a->display_name ?: __('System', 'workforce-one'))); ?></b> <?php echo esc_html($activity_text($a)); ?> <time><?php echo esc_html(date_i18n('D d M · H:i', strtotime((string) $a->created_at))); ?></time></li><?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>
        </div>
    </dialog>
<?php endif; ?>
</div>
