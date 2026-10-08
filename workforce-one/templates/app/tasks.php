<?php
/**
 * Employee app: Tasks (3.31.69). The tasks by status: a board of three columns on a computer, one
 * list on a phone (In progress first). Each task has one button for its next step (Start / Done /
 * Reopen) and a menu for the rest (back to To Do, Edit, Delete). New task and Edit open in a sheet.
 * Styles: assets/css/app-requests.css (Tasks part); scripts: assets/js/sheet.js, assets/js/tasks.js
 * (search, priority filter, delete confirmation).
 *
 * @var bool $manage                     can see and assign the team's tasks
 * @var string $tab                      'my' | 'team'
 * @var string $priority_filter          'all' | a priority
 * @var object|null $counter             todo_count, progress_count, completed_count, overdue_count
 * @var array<string,object[]> $cards    'todo' | 'in_progress' | 'completed' => tasks (+ is_overdue, can_status, can_edit, can_delete, edit_url)
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
$initials = static function (string $name): string {
    $out = '';
    foreach (array_slice(array_values(array_filter(preg_split('/[\s._-]+/u', trim($name)) ?: [])), 0, 2) as $p) $out .= mb_strtoupper(mb_substr($p, 0, 1));
    return $out !== '' ? $out : '·';
};
$status_form = static function (object $t, string $to, string $label, string $class, string $icon) use ($post_url): string {
    return '<form method="post" action="' . esc_url($post_url) . '">' . wp_nonce_field('ews_task_status_' . (int) $t->id, '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="ews_task_status_update"><input type="hidden" name="task_id" value="' . (int) $t->id . '"><input type="hidden" name="status" value="' . esc_attr($to) . '">'
        . '<button class="' . esc_attr($class) . '" type="submit">' . ($icon !== '' ? Icons::svg($icon, 16, 2.2) : '') . esc_html($label) . '</button></form>';
};
$card = static function (object $t) use ($priorities, $initials, $status_form, $post_url, $tab): string {
    $pr = isset($priorities[$t->priority]) ? (string) $t->priority : 'normal';
    $html = '<article class="wfo-tk-card pr-' . esc_attr($pr) . ($t->status === 'completed' ? ' is-done' : '') . '" data-ews-task="' . esc_attr(mb_strtolower((string) $t->title . ' ' . (string) $t->description)) . '">';
    $html .= '<div class="wfo-tk-body"><h4 class="wfo-tk-title">' . esc_html($t->title) . '</h4>';
    if ((string) $t->description !== '') $html .= '<p class="wfo-tk-desc">' . esc_html($t->description) . '</p>';
    $html .= '<div class="wfo-tk-meta"><span class="wfo-tk-pr pr-' . esc_attr($pr) . '">' . esc_html($priorities[$pr]) . '</span>';
    if (!empty($t->due_date)) {
        $due = date_i18n('D d M', strtotime((string) $t->due_date));
        $html .= '<span class="wfo-tk-due' . ($t->is_overdue ? ' is-late' : '') . '">' . Icons::svg($t->is_overdue ? 'alert' : 'calendar', 14, 2) . esc_html($t->is_overdue ? sprintf(/* translators: %s: due date */ __('Overdue · %s', 'workforce-one'), $due) : $due) . '</span>';
    }
    if (!empty($t->assigned_name) && $tab === 'team') $html .= '<span class="wfo-tk-who" title="' . esc_attr($t->assigned_name) . '"><b aria-hidden="true">' . esc_html($initials((string) $t->assigned_name)) . '</b>' . esc_html($t->assigned_name) . '</span>';
    $html .= '</div></div><div class="wfo-tk-side">';
    if ($t->can_status) {
        if ($t->status === 'todo') $html .= $status_form($t, 'in_progress', __('Start', 'workforce-one'), 'wfo-rq-btn is-soft', '');
        elseif ($t->status === 'in_progress') $html .= $status_form($t, 'completed', __('Done', 'workforce-one'), 'wfo-rq-btn is-done', 'check');
        else $html .= '<span class="wfo-rq-state is-approved">' . Icons::svg('check', 14, 2.4) . esc_html__('Done', 'workforce-one') . '</span>';
    }
    $menu = '';
    if ($t->can_status && $t->status === 'in_progress') $menu .= $status_form($t, 'todo', __('Back to To Do', 'workforce-one'), 'wfo-tk-menu-item', '');
    if ($t->can_status && $t->status === 'completed') $menu .= $status_form($t, 'in_progress', __('Reopen', 'workforce-one'), 'wfo-tk-menu-item', '');
    if ($t->can_edit) $menu .= '<a class="wfo-tk-menu-item" href="' . esc_url($t->edit_url) . '">' . esc_html__('Edit', 'workforce-one') . '</a>';
    if ($t->can_delete) $menu .= '<form method="post" action="' . esc_url($post_url) . '" data-ews-task-delete="' . esc_attr__('Delete this task?', 'workforce-one') . '">' . wp_nonce_field('ews_task_delete_' . (int) $t->id, '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="ews_task_delete"><input type="hidden" name="task_id" value="' . (int) $t->id . '"><button class="wfo-tk-menu-item is-danger" type="submit">' . esc_html__('Delete', 'workforce-one') . '</button></form>';
    if ($menu !== '') $html .= '<details class="wfo-tk-more"><summary aria-label="' . esc_attr(sprintf(/* translators: %s: task title */ __('More for %s', 'workforce-one'), $t->title)) . '">' . Icons::svg('dots', 18, 2) . '</summary><div class="wfo-tk-menu">' . $menu . '</div></details>';
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
];
$sheet = static function (string $id, ?object $task) use ($post_url, $priorities, $manage, $employees, $base): string {
    $pr = $task->priority ?? 'normal';
    ob_start(); ?>
    <dialog class="wfo-sheet" id="<?php echo esc_attr($id); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title"<?php echo $task ? ' data-wfo-sheet-start data-ews-task-back="' . esc_url($base) . '"' : ''; ?>>
        <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-sheet-body"><?php wp_nonce_field('ews_task_save'); ?><input type="hidden" name="action" value="ews_task_save"><input type="hidden" name="task_id" value="<?php echo $task ? (int) $task->id : 0; ?>">
            <div class="wfo-sheet-grip" aria-hidden="true"></div>
            <div class="wfo-sheet-head"><h2 id="<?php echo esc_attr($id); ?>-title"><?php echo esc_html($task ? __('Edit Task', 'workforce-one') : __('New Task', 'workforce-one')); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
            <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-name"><?php esc_html_e('Title', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-name" name="title" value="<?php echo esc_attr($task->title ?? ''); ?>" required maxlength="255" placeholder="<?php esc_attr_e('What needs to be done?', 'workforce-one'); ?>"></div>
            <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-desc"><?php esc_html_e('Description', 'workforce-one'); ?></label><textarea id="<?php echo esc_attr($id); ?>-desc" name="description" placeholder="<?php esc_attr_e('Add useful context, instructions or notes...', 'workforce-one'); ?>"><?php echo esc_textarea($task->description ?? ''); ?></textarea></div>
            <fieldset class="wfo-rq-field wfo-tk-prio"><legend class="wfo-rq-label"><?php esc_html_e('Priority', 'workforce-one'); ?></legend>
                <div><?php foreach (['low', 'normal', 'high', 'urgent'] as $p): ?><label class="pr-<?php echo esc_attr($p); ?>"><input type="radio" name="priority" value="<?php echo esc_attr($p); ?>"<?php checked($pr, $p); ?>><span><?php echo esc_html($priorities[$p]); ?></span></label><?php endforeach; ?></div>
            </fieldset>
            <div class="wfo-rq-two">
                <div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-due"><?php esc_html_e('Due Date', 'workforce-one'); ?></label><input id="<?php echo esc_attr($id); ?>-due" type="date" name="due_date" value="<?php echo esc_attr($task->due_date ?? ''); ?>"></div>
                <?php if ($manage): ?><div class="wfo-rq-field"><label for="<?php echo esc_attr($id); ?>-who"><?php esc_html_e('Assign To', 'workforce-one'); ?></label><select id="<?php echo esc_attr($id); ?>-who" name="assigned_to"><option value="0"><?php esc_html_e('Myself / Personal Task', 'workforce-one'); ?></option><?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"<?php selected((int) ($task->assigned_to ?? 0), (int) $e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <button class="wfo-sheet-submit" type="submit"><?php echo Icons::svg($task ? 'check' : 'plus', 18, 2.4); ?><?php echo esc_html($task ? __('Save Changes', 'workforce-one') : __('Create Task', 'workforce-one')); ?></button>
        </form>
    </dialog>
    <?php return (string) ob_get_clean();
};
?>
<div class="ews-page wfo-rq wfo-tk">
    <?php if ($error !== ''): ?><div class="ews-notice ews-notice-error" role="alert"><?php echo esc_html($errors[$error] ?? __('Something went wrong. Please try again.', 'workforce-one')); ?></div><?php endif; ?>

    <div class="wfo-tk-bar">
        <?php if ($manage): ?><nav class="wfo-tk-tabs" aria-label="<?php esc_attr_e('Tasks', 'workforce-one'); ?>"><a href="<?php echo esc_url($tab_url('my')); ?>"<?php echo $tab === 'my' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('My Tasks', 'workforce-one'); ?></a><a href="<?php echo esc_url($tab_url('team')); ?>"<?php echo $tab === 'team' ? ' aria-current="page"' : ''; ?>><?php esc_html_e('Team Tasks', 'workforce-one'); ?></a></nav><?php endif; ?>
        <label class="wfo-tk-search"><?php echo Icons::svg('search', 17, 2); ?><span class="screen-reader-text"><?php esc_html_e('Search tasks', 'workforce-one'); ?></span><input type="search" id="wfo-tk-search" placeholder="<?php esc_attr_e('Search tasks', 'workforce-one'); ?>"></label>
        <form method="get" action="<?php echo esc_url(remove_query_arg(['task_priority', 'task_status', 'edit_task', 'task_done'], $base)); ?>" class="wfo-tk-prfilter">
            <input type="hidden" name="ews_view" value="tasks"><input type="hidden" name="task_tab" value="<?php echo esc_attr($tab); ?>">
            <label class="screen-reader-text" for="wfo-tk-priority"><?php esc_html_e('Priority', 'workforce-one'); ?></label>
            <select id="wfo-tk-priority" name="task_priority" data-ews-autosubmit><option value="all"><?php esc_html_e('All priorities', 'workforce-one'); ?></option><?php foreach ($priorities as $k => $label): ?><option value="<?php echo esc_attr($k); ?>"<?php selected($priority_filter, $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <noscript><button type="submit" class="wfo-rq-btn"><?php esc_html_e('Filter', 'workforce-one'); ?></button></noscript>
        </form>
        <button type="button" class="wfo-rq-new wfo-tk-new" data-wfo-sheet="wfo-tk-sheet" aria-haspopup="dialog"><?php echo Icons::svg('plus', 18, 2.4); ?><?php esc_html_e('New Task', 'workforce-one'); ?></button>
    </div>
    <?php if ((int) ($counter->overdue_count ?? 0) > 0): ?><p class="wfo-tk-late"><?php echo Icons::svg('alert', 16, 2.2); ?><?php /* translators: %d: number of overdue tasks */ printf(esc_html(_n('%d task is overdue', '%d tasks are overdue', (int) $counter->overdue_count, 'workforce-one')), (int) $counter->overdue_count); ?></p><?php endif; ?>

    <div class="wfo-tk-board">
        <?php foreach ($columns as $key => [$title, $count]): ?>
        <section class="wfo-tk-col is-<?php echo esc_attr($key); ?>" aria-labelledby="wfo-tk-col-<?php echo esc_attr($key); ?>">
            <h3 class="wfo-tk-col-head" id="wfo-tk-col-<?php echo esc_attr($key); ?>"><?php echo esc_html($title); ?> <span><?php echo (int) $count; ?></span></h3>
            <?php foreach ($cards[$key] as $t) echo $card($t); ?>
            <?php if (!$cards[$key]): ?><p class="wfo-tk-none"><?php echo esc_html($key === 'completed' ? __('Nothing finished yet.', 'workforce-one') : __('Nothing here.', 'workforce-one')); ?></p><?php endif; ?>
            <?php if ($key === 'completed' && !$done_all && $done_total > count($cards['completed'])): ?><a class="wfo-tk-all" href="<?php echo esc_url($done_url); ?>"><?php /* translators: %d: number of finished tasks */ printf(esc_html__('Show all %d', 'workforce-one'), (int) $done_total); ?></a><?php endif; ?>
        </section>
        <?php endforeach; ?>
        <p class="wfo-tk-noresult" hidden><?php esc_html_e('No task matches your search.', 'workforce-one'); ?></p>
    </div>

    <?php echo $sheet('wfo-tk-sheet', null); ?>
    <?php if ($edit) echo $sheet('wfo-tk-edit', $edit); ?>
</div>
