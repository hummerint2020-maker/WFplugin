<?php
/**
 * wp-admin → People → Org chart (3.31.97, owner-approved mockup docs/mockups/org-chart/; 3.31.98: Move,
 * scheduled moves, the managers file in Excel, Needs attention).
 * The counts, what needs attention, the scheduled moves and an import's preview are written here; the
 * chart, the person panel and the Move dialog are drawn by assets/js/admin-org-chart.js from the data
 * below (each branch is drawn when it is opened, so a large company stays fast).
 * Styles: assets/css/admin-org-chart.css.
 *
 * @var bool   $enabled
 * @var string $features_url
 * @var string $post_url
 * @var string $export_url
 * @var array<string,mixed>|null $import   EWS_Org_Import_Trait::org_import_preview_data()
 * @var string $notice
 * @var string $error
 * @var int    $focus                      the person to select (after a move)
 * @var array{people:list<array{id:int,n:string,t:string,d:int,m:int,r:int,u:int,p:string,w:int}>,departments:array<int,array{name:string,n:int}>,
 *            counts:array{people:int,managers:int,levels:int,without:int},without:list<int>,archived:list<array{id:int,manager:string}>,looped:list<int>,
 *            wide:list<int>,wide_span:int,moves:array{history:array<int,list<array<string,mixed>>>,scheduled:list<array<string,mixed>>,failed:list<array<string,mixed>>},
 *            other_names:array<int,string>,today:string,profile:string,set_manager:string} $data
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Support\Picker;
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
$name = static function (int $id) use ($by, $data): string {
    if (!$id) return __('No manager', 'workforce-one');
    return (string) ($by[$id]['n'] ?? $data['other_names'][$id] ?? '#' . $id);
};
$i18n = [
    'direct' => __('direct', 'workforce-one'), 'total' => __('in total', 'workforce-one'),
    'department' => __('Department', 'workforce-one'), 'manager' => __('Direct manager', 'workforce-one'),
    'reports' => __('Direct reports', 'workforce-one'), 'under' => __('Everyone under', 'workforce-one'),
    'line' => __('Reporting line', 'workforce-one'), 'profile' => __('Open profile', 'workforce-one'),
    'move' => __('Move', 'workforce-one'), 'none' => '—', 'noDept' => __('No department', 'workforce-one'),
    /* translators: %d: the limit of direct reports */
    'wide' => __('More than %d direct reports', 'workforce-one'),
    'teammates' => __('Teammates (same manager)', 'workforce-one'), 'fit' => __('Fit', 'workforce-one'),
    'zoomIn' => __('Zoom in', 'workforce-one'), 'zoomOut' => __('Zoom out', 'workforce-one'),
    'noMatch' => __('Nobody matches this search.', 'workforce-one'),
    'empty' => __('Nobody reports to anyone yet. Set each employee\'s direct supervisor on the Employees page and the chart appears here.', 'workforce-one'),
    'phoneHint' => __('One person at a time: their managers above, the people who report to them below.', 'workforce-one'),
    'history' => __('History', 'workforce-one'), 'noManager' => __('No manager', 'workforce-one'),
    /* translators: %s: a date */
    'scheduledOn' => __('Scheduled for %s', 'workforce-one'),
    /* translators: %s: name of the person moved */
    'moveTitle' => __('Move %s', 'workforce-one'),
    /* translators: %d: number of people */
    'withThem' => __('The %d people under them move with them.', 'workforce-one'),
    /* translators: 1: number of requests, 2: name of the current manager */
    'waiting' => __('%1$d approvals waiting for %2$s go to the new manager.', 'workforce-one'),
    'waitingNone' => __('With no manager, requests waiting for the current manager stay with them.', 'workforce-one'),
    'loop' => __('Nobody can be moved under someone who reports to them.', 'workforce-one'),
    'later' => __('A date after today: the move is kept and done on that day.', 'workforce-one'),
    'same' => __('They already report to this manager.', 'workforce-one'),
];
$people_n = count($data['people']);
$wide = $data['wide'];
$attention = count($data['without']) + count($data['archived']) + count($data['looped']) + count($data['moves']['failed']);
$failed_why = ['loop' => __('it would put them under someone who reports to them', 'workforce-one'), 'employee' => __('the employee was archived', 'workforce-one'), 'manager' => __('the new manager was archived', 'workforce-one')];
$source = ['chart' => __('Org chart', 'workforce-one'), 'employees' => __('Employees page', 'workforce-one'), 'import' => __('Excel import', 'workforce-one')];
?>
<div class="wrap wfo-org" id="wfo-org">
    <h1 class="screen-reader-text"><?php esc_html_e('Organization chart', 'workforce-one'); ?></h1>
    <?php if (!$enabled): ?><div class="notice notice-warning"><p><?php esc_html_e('The organization chart is switched off.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Turn it on in Feature Configuration', 'workforce-one'); ?></a></p></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="notice notice-success is-dismissible" data-org-notice><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice-error is-dismissible" data-org-error><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
    <section class="wfo-org-hero">
        <div>
            <h2><?php esc_html_e('Organization chart', 'workforce-one'); ?></h2>
            <p><?php esc_html_e('Built on each employee\'s direct supervisor (Employees page). Select anyone to see their details, or move them under another manager.', 'workforce-one'); ?></p>
        </div>
        <div class="wfo-org-kpis">
            <div class="wfo-org-kpi"><b data-kpi="people"><?php echo (int) ($data['counts']['people'] - $data['counts']['without']); ?></b><span><?php esc_html_e('in the chart', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi"><b data-kpi="managers"><?php echo (int) $data['counts']['managers']; ?></b><span><?php esc_html_e('managers', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi"><b data-kpi="levels"><?php echo (int) $data['counts']['levels']; ?></b><span><?php esc_html_e('levels', 'workforce-one'); ?></span></div>
            <div class="wfo-org-kpi<?php echo $data['counts']['without'] ? ' is-warn' : ''; ?>"><b data-kpi="without"><?php echo (int) $data['counts']['without']; ?></b><span><?php esc_html_e('without a manager', 'workforce-one'); ?></span></div>
        </div>
        <div class="wfo-org-tools">
            <label class="wfo-org-search"><span aria-hidden="true">⌕</span><input id="wfo-org-q" type="search" placeholder="<?php esc_attr_e('Search a name or a job title', 'workforce-one'); ?>" aria-label="<?php esc_attr_e('Search a name or a job title', 'workforce-one'); ?>"></label>
            <?php if ($enabled): ?>
            <a class="wfo-org-hbtn" href="<?php echo esc_url($export_url); ?>" data-org-export><?php esc_html_e('Export', 'workforce-one'); ?></a>
            <a class="wfo-org-hbtn is-solid" href="#wfo-org-import" data-org-import-open><?php esc_html_e('Import managers', 'workforce-one'); ?></a>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($enabled): ?>
    <details class="wfo-org-card wfo-org-import" id="wfo-org-import"<?php echo $error !== '' && !$import && isset($_GET['org_error']) && in_array(sanitize_key($_GET['org_error']), ['file', 'columns', 'too_many', 'empty', 'expired', 'zip'], true) ? ' open' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reopen after an error ?>>
        <summary><?php esc_html_e('Import managers from Excel', 'workforce-one'); ?></summary>
        <ol class="wfo-org-steps">
            <li><?php esc_html_e('Export the file above: every employee with their job title, department and manager.', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Change the managers (Manager ID or Manager domain name) and the job titles. Rows can be removed; only the rows in the file are looked at.', 'workforce-one'); ?></li>
            <li><?php esc_html_e('Upload it here. You see every change, and every row that cannot be used, before anything is saved.', 'workforce-one'); ?></li>
        </ol>
        <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data" class="wfo-org-form">
            <?php wp_nonce_field('ews_org_import'); ?><input type="hidden" name="action" value="ews_org_import">
            <label><span><?php esc_html_e('File (.xlsx or .csv)', 'workforce-one'); ?></span><input type="file" name="managers_file" accept=".xlsx,.csv" required></label>
            <label><span><?php esc_html_e('From', 'workforce-one'); ?></span><input type="date" name="effective_date" value="<?php echo esc_attr($data['today']); ?>" required></label>
            <label><span><?php esc_html_e('Reason (kept in the Audit Log)', 'workforce-one'); ?></span><input type="text" name="reason" maxlength="255" placeholder="<?php esc_attr_e('Imported from Excel', 'workforce-one'); ?>"></label>
            <button class="button button-primary"><?php esc_html_e('Check the file', 'workforce-one'); ?></button>
        </form>
    </details>
    <?php endif; ?>

    <?php if ($import): ?>
    <section class="wfo-org-card wfo-org-preview" id="wfo-org-preview" aria-labelledby="wfo-org-preview-t">
        <h3 id="wfo-org-preview-t"><?php /* translators: %s: file name */ echo esc_html(sprintf(__('Check before saving: %s', 'workforce-one'), $import['file'])); ?></h3>
        <p class="wfo-org-sub"><?php echo esc_html(sprintf(/* translators: 1: rows read, 2: managers changed, 3: job titles changed, 4: rows not used, 5: rows unchanged */ __('%1$d rows read: %2$d new managers, %3$d job titles, %4$d rows cannot be used, %5$d unchanged.', 'workforce-one'), $import['rows'], count($import['moves']), count($import['titles']), count($import['problems']), $import['unchanged'])); ?>
            <?php echo esc_html($import['scheduled'] ? sprintf(/* translators: %s: date */ __('The moves are scheduled for %s.', 'workforce-one'), $import['date']) : sprintf(/* translators: %s: date */ __('The moves are done now, from %s.', 'workforce-one'), $import['date'])); ?>
            <?php /* translators: %s: reason */ echo esc_html(sprintf(__('Reason: %s', 'workforce-one'), $import['reason'])); ?></p>
        <?php if ($import['problems']): ?>
        <h4 class="is-bad"><?php esc_html_e('Rows that cannot be used', 'workforce-one'); ?></h4>
        <table class="widefat striped" data-preview="problems"><thead><tr><th style="width:70px"><?php esc_html_e('Row', 'workforce-one'); ?></th><th><?php esc_html_e('Value', 'workforce-one'); ?></th><th><?php esc_html_e('Why', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php foreach (array_slice($import['problems'], 0, 300) as $x): ?><tr><td><?php echo (int) $x['row']; ?></td><td dir="auto"><?php echo esc_html($x['value']); ?></td><td><?php echo esc_html($x['why']); ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <?php if ($import['moves']): ?>
        <h4><?php esc_html_e('New managers', 'workforce-one'); ?></h4>
        <table class="widefat striped" data-preview="moves"><thead><tr><th style="width:70px"><?php esc_html_e('Row', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Manager now', 'workforce-one'); ?></th><th><?php esc_html_e('New manager', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php foreach (array_slice($import['moves'], 0, 500) as $m): ?><tr><td><?php echo (int) $m['row']; ?></td><td><?php echo esc_html($m['name']); ?></td><td><?php echo esc_html($m['from'] !== '' ? $m['from'] : '—'); ?></td><td><strong><?php echo esc_html($m['to'] !== '' ? $m['to'] : __('No manager', 'workforce-one')); ?></strong></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <?php if ($import['titles']): ?>
        <h4><?php esc_html_e('Job titles', 'workforce-one'); ?></h4>
        <table class="widefat striped" data-preview="titles"><thead><tr><th style="width:70px"><?php esc_html_e('Row', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Now', 'workforce-one'); ?></th><th><?php esc_html_e('New', 'workforce-one'); ?></th></tr></thead><tbody>
            <?php foreach (array_slice($import['titles'], 0, 500) as $t): ?><tr><td><?php echo (int) $t['row']; ?></td><td><?php echo esc_html($t['name']); ?></td><td><?php echo esc_html($t['from'] !== '' ? $t['from'] : '—'); ?></td><td><strong><?php echo esc_html($t['to']); ?></strong></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url($post_url); ?>" class="wfo-org-actions">
            <?php wp_nonce_field('ews_org_import_apply'); ?><input type="hidden" name="action" value="ews_org_import_apply">
            <?php if ($import['moves'] || $import['titles']): ?><button class="button button-primary" name="apply" value="1"><?php esc_html_e('Save these changes', 'workforce-one'); ?></button><?php endif; ?>
            <button class="button" name="cancel" value="1"><?php esc_html_e('Cancel', 'workforce-one'); ?></button>
        </form>
    </section>
    <?php endif; ?>

    <?php if ($attention): ?>
    <section class="wfo-org-attn" role="status" data-attn="needs">
        <div class="wfo-org-attn-head"><b><?php esc_html_e('Needs attention', 'workforce-one'); ?></b>
            <span><?php esc_html_e('People without a manager are not in the chart; their requests go to administrators.', 'workforce-one'); ?></span></div>
        <?php if ($data['without']): ?>
        <div class="wfo-org-group" data-attn="without">
            <p><?php /* translators: %d: number of employees */ echo esc_html(sprintf(_n('%d employee without a manager', '%d employees without a manager', count($data['without']), 'workforce-one'), count($data['without']))); ?></p>
            <div class="wfo-org-chips">
                <?php foreach (array_slice($data['without'], 0, 30) as $id): $p = $by[$id] ?? null; if (!$p) continue; ?>
                <a class="wfo-org-chip" data-without="<?php echo (int) $id; ?>" data-move="<?php echo (int) $id; ?>" href="<?php echo esc_url($data['set_manager'] . rawurlencode($p['n'])); ?>"><?php echo $avatar($p, 's'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $avatar ?><span><?php echo esc_html($p['n']); ?></span><em><?php esc_html_e('Set manager', 'workforce-one'); ?></em></a>
                <?php endforeach; ?>
                <?php if (count($data['without']) > 30): ?><span class="wfo-org-more-n"><?php /* translators: %d: number of other employees */ echo esc_html(sprintf(__('and %d more', 'workforce-one'), count($data['without']) - 30)); ?></span><?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($data['archived']): ?>
        <div class="wfo-org-group" data-attn="archived">
            <p><?php /* translators: %d: number of employees */ echo esc_html(sprintf(_n('%d employee whose manager was archived', '%d employees whose manager was archived', count($data['archived']), 'workforce-one'), count($data['archived']))); ?></p>
            <div class="wfo-org-chips">
                <?php foreach (array_slice($data['archived'], 0, 30) as $a): $p = $by[$a['id']] ?? null; if (!$p) continue; ?>
                <a class="wfo-org-chip" data-archived="<?php echo (int) $a['id']; ?>" data-move="<?php echo (int) $a['id']; ?>" href="<?php echo esc_url($data['set_manager'] . rawurlencode($p['n'])); ?>"><?php echo $avatar($p, 's'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $avatar ?><span><?php echo esc_html($p['n']); ?> <small><?php /* translators: %s: name of the archived manager */ echo esc_html(sprintf(__('was under %s', 'workforce-one'), $a['manager'])); ?></small></span><em><?php esc_html_e('Set manager', 'workforce-one'); ?></em></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($data['looped']): ?>
        <div class="wfo-org-group" data-attn="looped">
            <p><?php esc_html_e('Managers that go round in a circle (old data): each of these is under someone who reports to them. Set the right manager for one of them.', 'workforce-one'); ?></p>
            <div class="wfo-org-chips">
                <?php foreach ($data['looped'] as $id): $p = $by[$id] ?? null; if (!$p) continue; ?>
                <a class="wfo-org-chip" data-looped="<?php echo (int) $id; ?>" data-move="<?php echo (int) $id; ?>" href="<?php echo esc_url($data['set_manager'] . rawurlencode($p['n'])); ?>"><?php echo $avatar($p, 's'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $avatar ?><span><?php echo esc_html($p['n']); ?></span><em><?php esc_html_e('Set manager', 'workforce-one'); ?></em></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($data['moves']['failed']): ?>
        <div class="wfo-org-group" data-attn="failed">
            <p><?php esc_html_e('Scheduled moves that could not be done on their day', 'workforce-one'); ?></p>
            <ul class="wfo-org-mlist">
                <?php foreach ($data['moves']['failed'] as $m): ?>
                <li data-failed="<?php echo (int) $m['id']; ?>"><span><b><?php echo esc_html($name((int) $m['e'])); ?></b> → <?php echo esc_html($name((int) $m['t'])); ?> · <?php echo esc_html($m['d']); ?> · <?php echo esc_html($failed_why[$m['n']] ?? $m['n']); ?></span>
                    <form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews_org_move_cancel"><input type="hidden" name="move_id" value="<?php echo (int) $m['id']; ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($m['nonce']); ?>"><button class="button button-small"><?php esc_html_e('Dismiss', 'workforce-one'); ?></button></form>
                    <?php if (isset($by[(int) $m['e']])): ?><a class="button button-small" href="#" data-move="<?php echo (int) $m['e']; ?>"><?php esc_html_e('Move', 'workforce-one'); ?></a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </section>
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
    <?php if ($data['moves']['scheduled']): ?>
    <section class="wfo-org-card" data-section="scheduled">
        <h3><?php esc_html_e('Scheduled moves', 'workforce-one'); ?></h3>
        <ul class="wfo-org-mlist">
            <?php foreach ($data['moves']['scheduled'] as $m): ?>
            <li data-scheduled="<?php echo (int) $m['id']; ?>"><span><b><?php echo esc_html($m['d']); ?></b> · <?php echo esc_html($name((int) $m['e'])); ?>: <?php echo esc_html($name((int) $m['f'])); ?> → <?php echo esc_html($name((int) $m['t'])); ?><?php if ($m['r'] !== ''): ?> · <em dir="auto"><?php echo esc_html($m['r']); ?></em><?php endif; ?></span>
                <?php if ($m['nonce'] !== ''): ?><form method="post" action="<?php echo esc_url($post_url); ?>"><input type="hidden" name="action" value="ews_org_move_cancel"><input type="hidden" name="move_id" value="<?php echo (int) $m['id']; ?>"><input type="hidden" name="_wpnonce" value="<?php echo esc_attr($m['nonce']); ?>"><button class="button button-small"><?php esc_html_e('Cancel', 'workforce-one'); ?></button></form><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
    </section>
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

    <?php if ($enabled): ?>
    <datalist id="wfo-org-pick"><?php foreach ($data['people'] as $p): ?><option value="<?php echo esc_attr(Picker::label($p['n'] . ($p['t'] !== '' ? ' — ' . $p['t'] : ''), $p['id'])); ?>"></option><?php endforeach; ?></datalist>
    <div class="wfo-org-dlg" id="wfo-org-dlg" hidden>
        <form class="wfo-org-dlg-box" method="post" action="<?php echo esc_url($post_url); ?>" role="dialog" aria-modal="true" aria-labelledby="wfo-org-dlg-t">
            <?php wp_nonce_field('ews_org_move'); ?><input type="hidden" name="action" value="ews_org_move"><input type="hidden" name="employee_id" value="">
            <h3 id="wfo-org-dlg-t"></h3>
            <p class="wfo-org-dlg-now"></p>
            <label class="wfo-org-fld"><span><?php esc_html_e('New manager', 'workforce-one'); ?></span><input type="text" name="manager_ref" list="wfo-org-pick" autocomplete="off" placeholder="<?php esc_attr_e('Type a name and pick from the list', 'workforce-one'); ?>"></label>
            <label class="wfo-org-check"><input type="checkbox" name="no_manager" value="1"> <?php esc_html_e('No manager', 'workforce-one'); ?></label>
            <label class="wfo-org-fld"><span><?php esc_html_e('From', 'workforce-one'); ?></span><input type="date" name="effective_date" value="<?php echo esc_attr($data['today']); ?>" required></label>
            <label class="wfo-org-fld"><span><?php esc_html_e('Reason', 'workforce-one'); ?> · <?php esc_html_e('kept in the Audit Log', 'workforce-one'); ?></span><input type="text" name="reason" maxlength="255" required placeholder="<?php esc_attr_e('e.g. new team in Alexandria', 'workforce-one'); ?>"></label>
            <div class="wfo-org-notes" aria-live="polite"></div>
            <div class="wfo-org-actions"><button type="button" class="button" data-dlg-close><?php esc_html_e('Cancel', 'workforce-one'); ?></button><button class="button button-primary" data-dlg-save><?php esc_html_e('Move', 'workforce-one'); ?></button></div>
        </form>
    </div>
    <?php endif; ?>
    <script type="application/json" id="wfo-org-data"><?php echo wp_json_encode(['people' => $data['people'], 'departments' => (object) $data['departments'], 'wide' => $wide, 'wideSpan' => $data['wide_span'],
        'profile' => $data['profile'], 'setManager' => $data['set_manager'], 'history' => (object) $data['moves']['history'], 'scheduled' => $data['moves']['scheduled'],
        'otherNames' => (object) $data['other_names'], 'today' => $data['today'], 'canMove' => $enabled, 'focus' => $focus, 'sources' => $source, 'i18n' => $i18n], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?></script>
</div>
