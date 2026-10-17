<?php
/**
 * Employee app: People directory. Styles: assets/css/app-people.css (new look, 3.31.55).
 *
 * @var array<int,array{name:string,domain:string,initials:string,picture:string,url:string}> $people
 * @var string $search
 * @var int $team_id
 * @var object[] $teams
 * @var string $clear_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup.
?>
<div class="wfo-people">
    <form method="get" class="wfo-people-search" role="search">
        <input type="hidden" name="ews_view" value="people">
        <label class="wfo-people-field"><?php echo Icons::svg('search', 18, 2); ?><input type="search" name="people_search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search people...', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Search people', 'workforce-one'); ?>"></label>
        <select name="people_team" aria-label="<?php esc_attr_e('Filter by team', 'workforce-one'); ?>"><option value="0"><?php esc_html_e('All Teams', 'workforce-one'); ?></option><?php foreach ($teams as $t): ?><option value="<?php echo (int) $t->id; ?>" <?php selected($team_id, (int) $t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?></select>
        <button class="ews-btn wfo-people-btn" type="submit"><?php esc_html_e('Search', 'workforce-one'); ?></button>
        <?php if ($search !== '' || $team_id): ?><a class="ews-btn secondary wfo-people-btn is-ghost" href="<?php echo esc_url($clear_url); ?>"><?php echo Icons::svg('close', 15, 2.2); ?><?php esc_html_e('Clear', 'workforce-one'); ?></a><?php endif; ?>
    </form>
    <p class="wfo-people-count"><?php echo esc_html(sprintf(/* translators: %d: number of people */ _n('%d person', '%d people', count($people), 'workforce-one'), count($people))); ?></p>

    <?php if ($people): ?>
    <ul class="wfo-people-grid">
    <?php foreach ($people as $i => $p): ?>
        <li><a class="wfo-person-card" href="<?php echo esc_url($p['url']); ?>">
            <div class="wfo-person-avatar tone-<?php echo (int) ($i % 5); ?>"><?php if ($p['picture'] !== ''): ?><img src="<?php echo esc_url($p['picture']); ?>" alt=""><?php else: ?><span><?php echo esc_html($p['initials']); ?></span><?php endif; ?></div>
            <div class="wfo-person-main"><strong><?php echo esc_html($p['name']); ?></strong><span dir="ltr"><?php echo esc_html($p['domain']); ?></span></div><span class="wfo-person-arrow" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span>
        </a></li>
    <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="wfo-people-empty"><span class="wfo-people-empty-icon"><?php echo Icons::svg('people', 26); ?></span><strong><?php esc_html_e('No people found', 'workforce-one'); ?></strong><span><?php esc_html_e('Try another name or team.', 'workforce-one'); ?></span></div>
    <?php endif; ?>
</div>
