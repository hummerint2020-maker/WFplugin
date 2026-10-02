<?php
/**
 * Employee app: People directory. Styles: assets/css/workforce-one.css (.wfo-people-*, .wfo-person-*).
 *
 * @var array<int,array{name:string,domain:string,initials:string,picture:string,url:string}> $people
 * @var string $search
 * @var int $team_id
 * @var object[] $teams
 * @var string $clear_url
 */
if (!defined('ABSPATH')) exit;
?>
<div class="ews-card wfo-people-head">
    <div><h3 style="margin:0">People</h3><p class="wfo-people-muted">Find colleagues and view their work profile.</p></div>
    <form method="get" class="wfo-people-filters">
        <input type="hidden" name="ews_view" value="people">
        <input type="search" name="people_search" value="<?php echo esc_attr($search); ?>" placeholder="Search people..." aria-label="Search people">
        <select name="people_team" aria-label="Filter by team"><option value="0">All Teams</option><?php foreach ($teams as $t): ?><option value="<?php echo (int) $t->id; ?>" <?php selected($team_id, (int) $t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?></select>
        <button class="ews-btn" type="submit">Search</button>
        <?php if ($search !== '' || $team_id): ?><a class="ews-btn secondary" href="<?php echo esc_url($clear_url); ?>">Clear</a><?php endif; ?>
    </form>
</div>
<div class="wfo-people-grid">
<?php foreach ($people as $p): ?>
    <a class="wfo-person-card" href="<?php echo esc_url($p['url']); ?>">
        <div class="wfo-person-avatar"><?php if ($p['picture'] !== ''): ?><img src="<?php echo esc_url($p['picture']); ?>" alt=""><?php else: ?><span><?php echo esc_html($p['initials']); ?></span><?php endif; ?></div>
        <div class="wfo-person-main"><strong><?php echo esc_html($p['name']); ?></strong><span><?php echo esc_html($p['domain']); ?></span></div><span class="wfo-person-arrow">›</span>
    </a>
<?php endforeach; ?>
</div>
<?php if (!$people): ?><div class="ews-card"><div class="ews-empty-state"><div class="ews-empty-icon">👥</div><div class="ews-empty-title">No people found</div><div class="ews-empty-text">Try another name or team.</div></div></div><?php endif; ?>
