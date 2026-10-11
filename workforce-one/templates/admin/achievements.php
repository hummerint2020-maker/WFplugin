<?php
/**
 * wp-admin "Achievements". Styles: assets/css/admin-achievements.css; script: assets/js/admin-achievements.js.
 *
 * @var bool $enabled
 * @var string $search
 * @var string $progress_type          all | attendance | swap
 * @var array{tracked:int,near:int,earned:int} $stats
 * @var array<int,array<string,mixed>> $rows  one per employee: name, domain, initials, attendance, swap (null = column hidden)
 * @var array<int,object> $employees
 * @var array<int,object> $automatic   built-in achievement definitions
 * @var string[] $icons
 * @var array<string,string> $styles
 * @var string|null $notice
 * @var string|null $error
 * @var string $page_url
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
$cell = static function ($p, string $remaining_label, string $complete_label) {
    if ($p === null) { echo '<td>—</td>'; return; }
    if (!$p['achievement']) { echo '<td><span class="wfo-ach-complete">🏆 ' . esc_html($complete_label) . '</span></td>'; return; }
    echo '<td><div class="wfo-ach-progress-cell"><div class="wfo-ach-progress-head"><span class="wfo-ach-progress-title">' . esc_html($p['achievement']) . '</span><span class="wfo-ach-progress-value">' . (int) $p['current'] . ' / ' . (int) $p['target'] . '</span></div>';
    echo '<div class="wfo-ach-progress-track"><div class="wfo-ach-progress-fill" style="width:' . (int) $p['pct'] . '%"></div></div>';
    $text = esc_html(sprintf($remaining_label, (int) $p['remaining']));
    echo '<div class="wfo-ach-progress-sub">' . ($p['near'] ? '<span class="wfo-ach-near"><span class="wfo-ach-near-dot"></span>' . $text . '</span>' : $text) . '</div>';
    if ($p['earned']) {
        echo '<div class="wfo-ach-progress-earned">';
        foreach ($p['earned'] as $label) echo '<span class="wfo-ach-earned-pill">' . esc_html($label) . '</span>';
        echo '</div>';
    }
    echo '</div></td>';
};
?>
<div class="wrap"><h1>Achievements</h1>
<?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
<?php if ($error): ?><div class="notice notice-error is-dismissible"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
<div class="wfo-ach-shell">

<div class="wfo-ach-card"><div class="wfo-ach-head"><div><h2 style="margin:0 0 5px">Achievements Feature</h2><div class="wfo-ach-muted">Controls automatic achievements, profile display, and recognition notifications. Existing earned records are preserved if the feature is disabled.</div></div><strong style="color:<?php echo $enabled ? '#067647' : '#b42318'; ?>;font-size:14px"><?php echo $enabled ? 'Enabled' : 'Disabled'; ?></strong></div>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top:18px"><?php wp_nonce_field('ews_achievements_settings_save'); ?><input type="hidden" name="action" value="ews_achievements_settings_save">
        <label><input type="checkbox" name="achievements_enabled" value="1" <?php checked($enabled); ?>> Enable Achievements</label>
        <p><button class="button button-primary">Save Achievement Settings</button></p>
    </form>
</div>

<?php if ($enabled): ?>
<div class="wfo-ach-card">
    <div class="wfo-ach-progress-intro"><div><h2 style="margin:0 0 5px">Employee Achievement Progress</h2><div class="wfo-ach-muted">Admin-only view of each employee's current progress toward the next Attendance and Swap achievement. This is progress visibility, not a ranking.</div></div><div class="wfo-ach-legend"><span>🔥 Attendance streak</span><span>🤝 Successful swaps</span></div></div>
    <div class="wfo-ach-stat-grid">
        <div class="wfo-ach-stat"><div class="k">Employees tracked</div><div class="v"><?php echo (int) $stats['tracked']; ?></div></div>
        <div class="wfo-ach-stat"><div class="k">Near an achievement</div><div class="v"><?php echo (int) $stats['near']; ?></div></div>
        <div class="wfo-ach-stat"><div class="k">Earned achievements</div><div class="v"><?php echo (int) $stats['earned']; ?></div></div>
    </div>
    <form method="get" class="wfo-ach-toolbar"><input type="hidden" name="page" value="ews31-achievements">
        <input type="text" name="achievement_search" value="<?php echo esc_attr($search); ?>" placeholder="Search employee name or domain">
        <select name="achievement_type"><option value="all" <?php selected($progress_type, 'all'); ?>>Attendance + Swap</option><option value="attendance" <?php selected($progress_type, 'attendance'); ?>>Attendance</option><option value="swap" <?php selected($progress_type, 'swap'); ?>>Swap</option></select>
        <button class="button">Filter</button>
        <?php if ($search !== '' || $progress_type !== 'all'): ?><a class="button" href="<?php echo esc_url($page_url); ?>">Reset</a><?php endif; ?>
    </form>
    <div class="wfo-ach-progress-scroll"><table class="wfo-ach-progress-table"><thead><tr><th>Employee</th><th>Attendance Streak</th><th>Successful Swaps</th></tr></thead><tbody>
    <?php foreach ($rows as $row): ?>
        <tr><td><div class="wfo-ach-employee"><span class="wfo-ach-avatar"><?php echo esc_html($row['initials']); ?></span><span><strong><?php echo esc_html($row['name']); ?></strong><span><?php echo esc_html($row['domain']); ?></span></span></div></td>
        <?php $cell($row['attendance'], '%d more consecutive days', 'All Attendance achievements earned'); $cell($row['swap'], '%d more successful swaps', 'All Swap achievements earned'); ?>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="wfo-ach-empty">No active employees match the current filter.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>

<div class="wfo-ach-card"><h2 style="margin-top:0">Grant Achievement to Employee</h2><p class="wfo-ach-muted">Manual achievements are stored as real earned achievements. The employee receives the same in-app and push congratulations flow as an automatic achievement.</p>
    <form method="post" action="<?php echo esc_url($post_url); ?>"><?php wp_nonce_field('ews_achievement_manual_grant'); ?><input type="hidden" name="action" value="ews_achievement_manual_grant">
        <div class="wfo-ach-form-grid">
            <label>Employee<select name="employee_id" required><option value="">Select employee</option><?php foreach ($employees as $e): ?><option value="<?php echo (int) $e->id; ?>"><?php echo esc_html($e->name . ($e->domain_name ? ' · ' . $e->domain_name : '')); ?></option><?php endforeach; ?></select></label>
            <label>Achievement Name<input type="text" name="achievement_name" maxlength="120" required placeholder="e.g. Team Player"></label>
            <label class="full">Description<textarea name="achievement_description" rows="3" maxlength="500" placeholder="e.g. Helped a teammate when they needed a shift swap."></textarea></label>
            <label class="full">Badge Icon<input type="hidden" id="wfo-ach-icon" name="achievement_icon" value="<?php echo esc_attr($icons[0]); ?>"><div class="wfo-ach-icons">
                <?php foreach ($icons as $i => $ic): ?><button type="button" class="wfo-ach-icon<?php echo $i === 0 ? ' selected' : ''; ?>" data-icon="<?php echo esc_attr($ic); ?>"><?php echo esc_html($ic); ?></button><?php endforeach; ?>
            </div><span class="wfo-ach-muted">Choose the badge symbol shown on the employee profile.</span></label>
            <label>Badge Shape<select name="badge_style" id="wfo-ach-style"><?php foreach ($styles as $value => $label): ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                <div class="wfo-ach-preview"><span class="wfo-ach-preview-badge circle" id="wfo-ach-preview"><?php echo esc_html($icons[0]); ?></span><span class="wfo-ach-muted">Preview</span></div></label>
        </div>
        <p><button class="button button-primary">Grant Achievement &amp; Notify Employee</button></p>
    </form>
</div>
<?php endif; ?>

<div class="wfo-ach-card"><h2 style="margin-top:0">Automatic Achievements</h2><p class="wfo-ach-muted">These are the built-in achievements evaluated by Workforce One.</p>
    <table class="wfo-ach-list"><thead><tr><th>Badge</th><th>Name</th><th>Category</th><th>Rule</th><th>Threshold</th></tr></thead><tbody>
    <?php foreach ($automatic as $d): ?>
        <tr><td><span class="wfo-ach-badge <?php echo esc_attr($d->badge_style); ?>"><?php echo esc_html($d->icon); ?></span></td><td><strong><?php echo esc_html($d->name); ?></strong><br><span class="wfo-ach-muted"><?php echo esc_html($d->description); ?></span></td><td><?php echo esc_html(ucwords(str_replace('_', ' ', $d->category))); ?></td><td><?php echo esc_html(ucwords(str_replace('_', ' ', $d->rule_type))); ?></td><td><?php echo (int) $d->threshold; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
</div></div>
