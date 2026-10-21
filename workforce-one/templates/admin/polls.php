<?php
/**
 * wp-admin → Employee Polls: create / edit a poll, and the list with state, audience, participation
 * and results. Style: assets/css/admin-polls.css; script: assets/js/admin-polls.js (choices, confirm).
 *
 * @var object[] $polls          with state, results, audience, audience_count, participation, links
 * @var object|null $edit
 * @var string[] $choices        the edited poll's choices (two empty ones for a new poll)
 * @var bool $locked             employees have voted: choices cannot change
 * @var object[] $departments
 * @var array<string,string> $visibility
 * @var array<string,string> $states
 * @var string $notice
 * @var string $error
 */
if (!defined('ABSPATH')) exit;
$dt = static function ($v) { return $v ? date('Y-m-d\TH:i', strtotime($v)) : ''; };
?>
<div class="wrap"><h1>Employee Polls</h1>
<?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div class="wfo-poll-admin">
<div class="card"><h2><?php echo $edit ? 'Edit Poll' : 'Create New Poll'; ?></h2><p class="wfo-poll-muted">Ask a question, add choices, choose who it is for and when the results are shown.</p>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="ews_poll_nonce" value="<?php echo esc_attr(wp_create_nonce('ews_poll_save')); ?>"><input type="hidden" name="action" value="ews_poll_save"><input type="hidden" name="poll_id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
    <div class="wfo-poll-grid"><div>
        <label for="wfo-poll-question">Question</label><input id="wfo-poll-question" type="text" name="question" required maxlength="500" value="<?php echo esc_attr($edit->question ?? ''); ?>" placeholder="What should we choose for Thursday?">
        <label for="wfo-poll-description" style="margin-top:14px">Description <span class="wfo-poll-muted">(optional)</span></label><textarea id="wfo-poll-description" name="description" rows="3" maxlength="1000" placeholder="Let’s make it a great day!"><?php echo esc_textarea($edit->description ?? ''); ?></textarea>
        <label style="margin-top:14px">Choices</label>
        <?php if ($locked): ?><p class="wfo-poll-muted">Employees have voted, so the choices are locked.</p><?php endif; ?>
        <div id="wfo-poll-options">
            <?php foreach ($choices as $v): ?><div class="wfo-poll-option-row"><input type="text" name="options[]" maxlength="255" required value="<?php echo esc_attr($v); ?>" placeholder="Choice" aria-label="Choice"<?php echo $locked ? ' readonly' : ''; ?>><?php if (!$locked): ?><button type="button" class="button wfo-poll-remove">Remove</button><?php endif; ?></div><?php endforeach; ?>
        </div>
        <?php if (!$locked): ?><button type="button" class="button wfo-poll-add" id="wfo-poll-add">+ Add choice</button><?php endif; ?>
    </div><div>
        <label for="wfo-poll-audience">Who is it for?</label><select id="wfo-poll-audience" name="audience_department_id"><option value="0">Everyone</option><?php foreach ($departments as $d): ?><option value="<?php echo (int) $d->id; ?>" <?php selected((int) ($edit->audience_department_id ?? 0), (int) $d->id); ?>><?php echo esc_html($d->name); ?> department</option><?php endforeach; ?></select>
        <label for="wfo-poll-results" style="margin-top:14px">Show results</label><select id="wfo-poll-results" name="results_visibility"><?php foreach ($visibility as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($edit->results_visibility ?? 'after_vote', $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
        <label style="margin-top:14px">Voting</label>
        <label class="wfo-poll-check"><input type="checkbox" name="allow_change" value="1" <?php checked(!empty($edit->allow_change)); ?>> Employees can change their vote until it closes</label>
        <label class="wfo-poll-check"><input type="checkbox" name="anonymous" value="1" <?php checked(!$edit || !empty($edit->anonymous)); ?>> Anonymous: nobody sees who voted for what</label>
        <span class="wfo-poll-muted">Untick to export each employee's vote.</span>
        <label style="margin-top:14px">Status</label>
        <label class="wfo-poll-check"><input type="checkbox" name="active" value="1" <?php checked(!$edit || $edit->status !== 'inactive'); ?>> Active</label>
        <label class="wfo-poll-check"><input type="checkbox" name="show_homepage" value="1" <?php checked(!$edit || !empty($edit->show_homepage)); ?>> Show on the employee Dashboard</label>
        <label class="wfo-poll-check"><input type="checkbox" name="notify" value="1" <?php checked(!$edit); ?><?php echo $edit && $edit->notified_at ? ' disabled' : ''; ?>> Notify employees when it opens<?php echo $edit && $edit->notified_at ? ' (already notified)' : ''; ?></label>
        <label for="wfo-poll-start" style="margin-top:14px">Start <span class="wfo-poll-muted">(optional)</span></label><input id="wfo-poll-start" type="datetime-local" name="starts_at" value="<?php echo esc_attr($dt($edit->starts_at ?? '')); ?>">
        <label for="wfo-poll-end" style="margin-top:12px">End <span class="wfo-poll-muted">(optional)</span></label><input id="wfo-poll-end" type="datetime-local" name="ends_at" value="<?php echo esc_attr($dt($edit->ends_at ?? '')); ?>">
    </div></div>
    <p style="margin-bottom:0"><button class="button button-primary" type="submit"><?php echo $edit ? 'Save Changes' : 'Create Poll'; ?></button><?php if ($edit): ?> <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-polls')); ?>">Cancel</a><?php endif; ?></p>
</form></div>

<div class="card"><h2>Polls</h2><p class="wfo-poll-muted">Employees see the open polls for them on the Polls page; the Dashboard shows the newest one they have not answered.</p>
<table><thead><tr><th>Poll</th><th>State</th><th>For</th><th>Participation</th><th>Results</th><th>Actions</th></tr></thead><tbody>
<?php if (!$polls): ?><tr><td colspan="6">No polls created yet.</td></tr><?php endif; ?>
<?php foreach ($polls as $p): ?>
    <tr data-poll="<?php echo (int) $p->id; ?>"><td><strong><?php echo esc_html($p->question); ?></strong><?php if (!empty($p->description)): ?><br><span class="wfo-poll-muted"><?php echo esc_html($p->description); ?></span><?php endif; ?>
        <br><span class="wfo-poll-muted">Created <?php echo esc_html(date_i18n('d M Y', strtotime($p->created_at))); ?><?php echo $p->ends_at ? ' · ends ' . esc_html(date_i18n('d M Y H:i', strtotime($p->ends_at))) : ''; ?> · <?php echo (int) $p->anonymous ? 'anonymous' : 'named'; ?> · results: <?php echo esc_html(strtolower($visibility[$p->results_visibility] ?? '')); ?></span></td>
        <td><span class="wfo-poll-pill wfo-poll-<?php echo esc_attr($p->state); ?>"><?php echo esc_html($states[$p->state]); ?></span><?php if (!empty($p->show_homepage) && $p->state !== 'archived'): ?><br><span class="wfo-poll-pill wfo-poll-home">Dashboard</span><?php endif; ?></td>
        <td><?php echo esc_html($p->audience); ?></td>
        <td><?php echo (int) $p->results['total']; ?> of <?php echo (int) $p->audience_count; ?> voted<?php echo $p->participation !== null ? ' (' . (int) $p->participation . '%)' : ''; ?></td>
        <td><?php foreach ($p->results['rows'] as $r): ?><div style="margin-top:7px"><div class="wfo-poll-result-row"><span><?php echo esc_html($r->option_text); ?></span><b><?php echo (int) $r->percentage; ?>%</b></div><div class="wfo-poll-resultbar"><i style="width:<?php echo (int) $r->percentage; ?>%"></i></div></div><?php endforeach; ?></td>
        <td><div class="wfo-poll-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ews31-polls&poll_id=' . (int) $p->id)); ?>">Edit</a>
            <?php if ($p->state !== 'archived'): ?><a class="button" href="<?php echo esc_url($p->links['toggle']); ?>"><?php echo $p->status === 'active' ? 'Hide' : 'Activate'; ?></a><a class="button" href="<?php echo esc_url($p->links['homepage']); ?>"><?php echo !empty($p->show_homepage) ? 'Hide from Dashboard' : 'Show on Dashboard'; ?></a><?php endif; ?>
            <a class="button" href="<?php echo esc_url($p->links['export']); ?>">Export CSV</a>
            <?php if ($p->state !== 'archived'): ?><a class="button" href="<?php echo esc_url($p->links['archive']); ?>" data-ews-confirm="Archive this poll? Voting ends; the votes are kept.">Archive</a><?php endif; ?></div></td></tr>
<?php endforeach; ?>
</tbody></table></div></div></div>
