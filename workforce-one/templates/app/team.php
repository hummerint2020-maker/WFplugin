<?php
/**
 * Employee app → My team (3.31.97, owner-approved mockup docs/mockups/org-chart/): one person at a
 * time, their managers above, the people who report to them below, and their teammates. Opens on the
 * signed-in person; ?person= looks at someone else (only people this person may see,
 * EWS_Org_Chart_Trait::org_app_visible). Styles: assets/css/app-team.css.
 *
 * @var int $me
 * @var array{id:int,name:string,title:string,department:string,picture:string,reports:int,url:string}|null $person
 * @var list<array{id:int,name:string,title:string,department:string,picture:string,reports:int,url:string}> $above
 * @var list<array{id:int,name:string,title:string,department:string,picture:string,reports:int,url:string}> $reports
 * @var list<array{id:int,name:string,title:string,department:string,picture:string,reports:int,url:string}> $teammates
 * @var string $home_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $avatar escapes.
$avatar = static function (array $p, string $size = ''): string {
    $cls = 'wfo-team-av' . ($size !== '' ? ' is-' . $size : '') . ' tone-' . ($p['id'] % 5);
    if ($p['picture'] !== '') return '<span class="' . esc_attr($cls) . '"><img src="' . esc_url($p['picture']) . '" alt=""></span>';
    $parts = preg_split('/\s+/u', trim($p['name'])) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $w) $ini .= function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
    return '<span class="' . esc_attr($cls) . '" aria-hidden="true">' . esc_html($ini) . '</span>';
};
$first = static function (string $name): string { $p = preg_split('/\s+/u', trim($name)) ?: ['']; return (string) $p[0]; };
?>
<div class="wfo-team">
<?php if (!$person): ?>
    <div class="wfo-team-empty"><span class="wfo-team-empty-icon"><?php echo Icons::svg('org', 26); ?></span><strong><?php esc_html_e('You are not in the organization chart yet', 'workforce-one'); ?></strong><span><?php esc_html_e('Your manager is set by HR on the Employees page.', 'workforce-one'); ?></span></div>
<?php else: ?>
    <?php if ($above): ?>
    <nav class="wfo-team-ups" aria-label="<?php esc_attr_e('Reporting line', 'workforce-one'); ?>">
        <?php foreach ($above as $i => $a): ?>
            <?php if ($i): ?><span class="wfo-team-sep" aria-hidden="true"><?php echo Icons::svg('chevron', 14, 2.2); ?></span><?php endif; ?>
            <a class="wfo-team-up" href="<?php echo esc_url($a['url']); ?>" title="<?php echo esc_attr($a['name'] . ($a['title'] !== '' ? ' · ' . $a['title'] : '')); ?>"><?php echo $avatar($a, 's'); ?><span><?php echo esc_html($first($a['name'])); ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <section class="wfo-team-focus">
        <?php echo $avatar($person, 'l'); ?>
        <strong><?php echo esc_html($person['name']); ?></strong>
        <?php $sub = array_filter([$person['title'], $person['department']]); if ($sub): ?><span class="wfo-team-sub"><?php echo esc_html(implode(' · ', $sub)); ?></span><?php endif; ?>
        <div class="wfo-team-stats"><span><?php echo esc_html(sprintf(/* translators: %d: number of people */ _n('%d direct report', '%d direct reports', count($reports), 'workforce-one'), count($reports))); ?></span><?php if ($person['id'] !== $me && $me): ?><a href="<?php echo esc_url($home_url); ?>"><?php esc_html_e('Back to me', 'workforce-one'); ?></a><?php endif; ?></div>
    </section>

    <?php if ($reports): ?>
    <section class="wfo-team-list">
        <h2><?php esc_html_e('Reports directly', 'workforce-one'); ?> · <?php echo (int) count($reports); ?></h2>
        <?php foreach ($reports as $r): ?>
        <a class="wfo-team-row" href="<?php echo esc_url($r['url']); ?>"><?php echo $avatar($r); ?><span class="wfo-team-t"><b><?php echo esc_html($r['name']); ?></b><?php if ($r['title'] !== ''): ?><small><?php echo esc_html($r['title']); ?></small><?php endif; ?></span><?php if ($r['reports']): ?><span class="wfo-team-pill"><?php echo (int) $r['reports']; ?></span><?php endif; ?><span class="wfo-team-chev" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span></a>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?php if ($teammates): ?>
    <section class="wfo-team-list">
        <h2><?php esc_html_e('Teammates (same manager)', 'workforce-one'); ?></h2>
        <div class="wfo-team-mates">
            <?php foreach ($teammates as $m): ?><a href="<?php echo esc_url($m['url']); ?>" title="<?php echo esc_attr($m['name'] . ($m['title'] !== '' ? ' · ' . $m['title'] : '')); ?>"><?php echo $avatar($m); ?><span><?php echo esc_html($first($m['name'])); ?></span></a><?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!$above && !$reports && !$teammates): ?>
    <p class="wfo-team-note"><?php esc_html_e('No manager or team is set for this person yet.', 'workforce-one'); ?></p>
    <?php endif; ?>
<?php endif; ?>
</div>
