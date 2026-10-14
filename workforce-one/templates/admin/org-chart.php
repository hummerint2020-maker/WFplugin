<?php
/**
 * wp-admin → People → Org chart (3.31.97, owner-approved mockup docs/mockups/org-chart/).
 * The counts, who has no manager and who has too many direct reports are written here; the chart and
 * the person panel are drawn by assets/js/admin-org-chart.js from the data below (each branch is drawn
 * when it is opened, so a large company stays fast). Styles: assets/css/admin-org-chart.css.
 *
 * @var bool   $enabled
 * @var string $features_url
 * @var array{people:list<array{id:int,n:string,t:string,d:int,m:int,r:int,u:int,p:string}>,departments:array<int,array{name:string,n:int}>,
 *            counts:array{people:int,managers:int,levels:int,without:int},without:list<int>,wide:list<int>,wide_span:int,profile:string,set_manager:string} $data
 */
if (!defined('ABSPATH')) exit;
$by = [];
foreach ($data['people'] as $p) $by[$p['id']] = $p;
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $w) $out .= function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
    return $out;
};
$avatar = static function (array $p, string $size = '') use ($initials): string {
    $cls = 'wfo-org-av' . ($size !== '' ? ' is-' . $size : '') . ' c' . ($p['id'] % 8);
    if ($p['p'] !== '') return '<img class="' . esc_attr($cls) . '" src="' . esc_url($p['p']) . '" alt="">';
    return '<span class="' . esc_attr($cls) . '" aria-hidden="true">' . esc_html($initials($p['n'])) . '</span>';
};
$i18n = [
    'direct' => __('direct', 'workforce-one'), 'total' => __('in total', 'workforce-one'),
    'department' => __('Department', 'workforce-one'), 'manager' => __('Direct manager', 'workforce-one'),
    'reports' => __('Direct reports', 'workforce-one'), 'under' => __('Everyone under', 'workforce-one'),
    'line' => __('Reporting line', 'workforce-one'), 'profile' => __('Open profile', 'workforce-one'),
    'change' => __('Change manager', 'workforce-one'), 'none' => '—', 'noDept' => __('No department', 'workforce-one'),
    /* translators: %d: the limit of direct reports */
    'wide' => __('More than %d direct reports', 'workforce-one'),
    'teammates' => __('Teammates (same manager)', 'workforce-one'), 'fit' => __('Fit', 'workforce-one'),
    'zoomIn' => __('Zoom in', 'workforce-one'), 'zoomOut' => __('Zoom out', 'workforce-one'),
    'noMatch' => __('Nobody matches this search.', 'workforce-one'),
    'empty' => __('Nobody reports to anyone yet. Set each employee\'s direct supervisor on the Employees page and the chart appears here.', 'workforce-one'),
    'phoneHint' => __('One person at a time: their managers above, the people who report to them below.', 'workforce-one'),
];
$wide = $data['wide'];
?>
<div class="wrap wfo-org" id="wfo-org">
    <h1 class="screen-reader-text"><?php esc_html_e('Organization chart', 'workforce-one'); ?></h1>
    <?php if (!$enabled): ?><div class="notice notice-warning"><p><?php esc_html_e('The organization chart is switched off.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Turn it on in Feature Configuration', 'workforce-one'); ?></a></p></div><?php endif; ?>
    <section class="wfo-org-hero">
        <div>
            <h2><?php esc_html_e('Organization chart', 'workforce-one'); ?></h2>
            <p><?php esc_html_e('Built on each employee\'s direct supervisor (Employees page). Select anyone to see their details.', 'workforce-one'); ?></p>
        </div>
        <div class="wfo-org-kpis">
            <div class="wfo-org-kpi"><b data-kpi="people"><?php echo (int) ($data['counts']['people'] - $data['counts']['without']); ?></b><span><?php esc_html_e('in the chart', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi"><b data-kpi="managers"><?php echo (int) $data['counts']['managers']; ?></b><span><?php esc_html_e('managers', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi"><b data-kpi="levels"><?php echo (int) $data['counts']['levels']; ?></b><span><?php esc_html_e('levels', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi<?php echo $data['counts']['without'] ? ' is-warn' : ''; ?>"><b data-kpi="without"><?php echo (int) $data['counts']['without']; ?></b><span><?php esc_html_e('without a manager', 'workforce-one'); ?></span></div>
        </div>
        <label class="wfo-org-search"><span aria-hidden="true">⌕</span><input id="wfo-org-q" type="search" placeholder="<?php esc_attr_e('Search a name or a job title', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Search a name or a job title', 'workforce-one'); ?>"></label>
    </section>

    <?php if ($data['without']): ?>
    <div class="wfo-org-attn" role="status" data-attn="without">
        <div><b><?php /* translators: %d: number of employees */ echo esc_html(sprintf(_n('%d employee without a manager', '%d employees without a manager', count($data['without']), 'workforce-one'), count($data['without']))); ?></b>
            <span><?php esc_html_e('Not in the chart; their requests go to administrators.', 'workforce-one'); ?></span></div>
        <div class="wfo-org-chips">
            <?php foreach (array_slice($data['without'], 0, 30) as $id): $p = $by[$id] ?? null; if (!$p) continue; ?>
            <a class="wfo-org-chip" data-without="<?php echo (int) $id; ?>" href="<?php echo esc_url($data['set_manager'] . rawurlencode($p['n'])); ?>"><?php echo $avatar($p, 's'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $avatar ?><span><?php echo esc_html($p['n']); ?></span><em><?php esc_html_e('Set manager', 'workforce-one'); ?></em></a>
            <?php endforeach; ?>
            <?php if (count($data['without']) > 30): ?><span class="wfo-org-more"><?php /* translators: %d: number of other employees */ echo esc_html(sprintf(__('and %d more', 'workforce-one'), count($data['without']) - 30)); ?></span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($wide): ?>
    <div class="wfo-org-attn is-soft" role="status" data-attn="wide">
        <div><b><?php /* translators: 1: number of managers, 2: the limit of direct reports */ echo esc_html(sprintf(_n('%1$d manager with more than %2$d direct reports', '%1$d managers with more than %2$d direct reports', count($wide), 'workforce-one'), count($wide), $data['wide_span'])); ?></b>
            <span><?php esc_html_e('A team lead in between may help.', 'workforce-one'); ?></span></div>
        <div class="wfo-org-chips">
            <?php foreach (array_slice($wide, 0, 30) as $id): $p = $by[$id] ?? null; if (!$p) continue; ?>
            <button type="button" class="wfo-org-chip" data-wide="<?php echo (int) $id; ?>"><?php echo $avatar($p, 's'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $avatar ?><span><?php echo esc_html($p['n']); ?></span><em><?php echo (int) $p['r']; ?></em></button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="wfo-org-board">
        <div class="wfo-org-canvas">
            <div class="wfo-org-bar">
                <div class="wfo-org-legend"><?php foreach ($data['departments'] as $d): ?><span><i class="d<?php echo (int) $d['n']; ?>"></i><?php echo esc_html($d['name']); ?></span><?php endforeach; ?></div>
                <div class="wfo-org-zoom"><button type="button" data-zoom="-1" aria-label="<?php echo esc_attr($i18n['zoomOut']); ?>">−</button><button type="button" data-zoom="0" aria-label="<?php echo esc_attr($i18n['fit']); ?>">⤢</button><button type="button" data-zoom="1" aria-label="<?php echo esc_attr($i18n['zoomIn']); ?>">+</button></div>
            </div>
            <div class="wfo-org-tree" id="wfo-org-tree"><noscript><p><?php esc_html_e('The chart needs JavaScript.', 'workforce-one'); ?></p></noscript></div>
        </div>
        <aside class="wfo-org-panel" id="wfo-org-panel" aria-live="polite"></aside>
    </div>
    <script type="application/json" id="wfo-org-data"><?php echo wp_json_encode(['people' => $data['people'], 'departments' => (object) $data['departments'], 'wide' => $wide, 'wideSpan' => $data['wide_span'], 'profile' => $data['profile'], 'setManager' => $data['set_manager'], 'i18n' => $i18n], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
</div>
