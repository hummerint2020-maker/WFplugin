<?php
/**
 * wp-admin → Settings → System health (3.31.110, mockup docs/mockups/system-health/). Data: EWS_System_Health_Trait::admin_system_health().
 *
 * @var bool $on
 * @var string $tab overview|route|jobs|queries|errors|settings
 * @var int $days
 * @var string $area
 * @var list<string> $areas
 * @var string $sort
 * @var string $route
 * @var string $base
 * @var string $features_url
 * @var bool $can_change
 * @var string $post_url
 * @var array<string,int|string> $settings
 * @var string $done
 * @var list<array<string,mixed>> $findings
 * @var list<array<string,mixed>> $rows
 * @var array<string,mixed> $facts
 * @var list<array<string,mixed>> $jobs
 * @var list<array<string,mixed>> $queries
 * @var list<array<string,mixed>> $errors
 * @var array<string,mixed>|null $detail
 * @var int $rate
 * @var int $retention
 * @var bool $full
 * @var bool $multisite
 * @var callable $seconds
 * @var callable $label
 * @var callable $spark
 * @var bool $wp_cron_off
 */
if (!defined('ABSPATH')) exit;
$url = static function (array $args) use ($base) { return add_query_arg($args, $base); };
$tabs = ['overview' => __('Overview', 'workforce-one'), 'jobs' => __('Scheduled jobs', 'workforce-one'), 'queries' => __('Queries', 'workforce-one'), 'errors' => __('PHP errors', 'workforce-one'), 'settings' => __('Settings', 'workforce-one')];
$box = 'margin-top:12px;background:#fff;border:1px solid #dcdcde;padding:0 14px 14px;max-width:1200px;overflow-x:auto';
$tones = ['critical' => ['#b32d2e', __('Critical', 'workforce-one')], 'warning' => ['#d18a00', __('Warning', 'workforce-one')], 'note' => ['#8c8f94', __('Note', 'workforce-one')]];
$ago = static function (int $ts) { return $ts > 0 ? sprintf(/* translators: %s: time */ __('%s ago', 'workforce-one'), human_time_diff($ts, time())) : '—'; };
?>
<div class="wrap" data-health>
<h1><?php esc_html_e('System health', 'workforce-one'); ?></h1>
<?php if ($done === 'saved'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'workforce-one'); ?></p></div><?php endif; ?>
<?php if ($done === 'purged'): ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('The collected data was deleted.', 'workforce-one'); ?></p></div><?php endif; ?>
<?php if (!$on): ?>
    <div class="notice notice-info inline"><p><?php esc_html_e('System health is off. It measures how long Workforce One’s pages, actions and scheduled jobs take, their database queries, memory and PHP errors. The data stays in this site’s database.', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Turn it on in Features', 'workforce-one'); ?></a></p></div>
</div>
<?php return; endif; ?>

<nav class="nav-tab-wrapper" style="margin-bottom:8px">
<?php foreach ($tabs as $k => $l): ?><a class="nav-tab<?php echo $tab === $k || ($tab === 'route' && $k === 'overview') ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($url(['tab' => $k])); ?>"><?php echo esc_html($l); ?></a><?php endforeach; ?>
</nav>
<p style="color:#646970;max-width:1100px">
    <?php echo esc_html(sprintf(/* translators: 1: date, 2: rows */ __('Collecting since %1$s · %2$s rows stored · detailed query data from 1 in %3$d requests', 'workforce-one'), $facts['since'], number_format_i18n($facts['rows']), (int) round(100 / max(1, $rate)))); ?>
    · <?php echo esc_html(sprintf(/* translators: %s: time */ __('last summary %s', 'workforce-one'), $facts['rollup'] ? $ago((int) $facts['rollup']) : __('not yet (within the hour)', 'workforce-one'))); ?>
    <?php if (!empty($facts['detail_off'])): ?> · <strong><?php esc_html_e('Query detail is off: SAVEQUERIES is set to false on this site.', 'workforce-one'); ?></strong><?php endif; ?>
    <?php if ($full): ?> · <strong style="color:#b32d2e"><?php esc_html_e('The row limit was reached: new query and error rows are paused. Lower the retention or delete data.', 'workforce-one'); ?></strong><?php endif; ?>
</p>

<?php if ($tab === 'overview'): ?>
    <p>
    <?php foreach ([1 => __('24 hours', 'workforce-one'), 7 => __('7 days', 'workforce-one'), 30 => __('30 days', 'workforce-one'), 90 => __('90 days', 'workforce-one')] as $d => $l): ?>
        <a class="button<?php echo $days === $d ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url(['days' => $d, 'area' => $area, 'sort' => $sort])); ?>"><?php echo esc_html($l); ?></a>
    <?php endforeach; ?>
    &nbsp;
    <?php foreach ($areas as $a): ?>
        <a class="button<?php echo $area === $a ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url(['days' => $days, 'area' => $a, 'sort' => $sort])); ?>"><?php echo esc_html($a === '' ? __('All', 'workforce-one') : $a); ?></a>
    <?php endforeach; ?>
    </p>
    <h2><?php esc_html_e('Look at this first', 'workforce-one'); ?></h2>
    <div style="<?php echo esc_attr($box); ?>padding-top:14px" data-health-findings>
    <?php if (!$findings): ?><p><?php echo esc_html($facts['rollup'] ? __('Nothing needs attention.', 'workforce-one') : __('The first summary is made within the hour.', 'workforce-one')); ?></p><?php endif; ?>
    <?php foreach ($findings as $f): $tone = $tones[$f['severity']] ?? $tones['note']; ?>
        <div style="border-inline-start:5px solid <?php echo esc_attr($tone[0]); ?>;padding:6px 12px;margin-bottom:10px;background:#f6f7f7" data-finding="<?php echo esc_attr($f['rule']); ?>">
            <span style="font-weight:700;color:<?php echo esc_attr($tone[0]); ?>"><?php echo esc_html($tone[1]); ?></span> <strong><?php echo esc_html($f['title']); ?></strong>
            <?php if ($f['sentence'] !== ''): ?><div style="color:#50575e"><?php echo esc_html($f['sentence']); ?></div><?php endif; ?>
            <?php if ($f['nums']): ?><div style="color:#646970;font-size:12px"><?php echo esc_html(implode(' · ', $f['nums'])); ?></div><?php endif; ?>
            <?php if (strpos((string) $f['route'], 'cron:') === 0): ?><a href="<?php echo esc_url($url(['tab' => 'jobs'])); ?>"><?php esc_html_e('Scheduled jobs', 'workforce-one'); ?> →</a>
            <?php elseif ($f['route'] !== ''): ?><a href="<?php echo esc_url($url(['tab' => 'route', 'route' => $f['route']])); ?>"><?php esc_html_e('Details', 'workforce-one'); ?> →</a><?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
    <h2 style="margin-top:22px"><?php esc_html_e('Pages and actions', 'workforce-one'); ?></h2>
    <div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped" data-health-pages>
        <thead><tr>
            <th><a href="<?php echo esc_url($url(['days' => $days, 'area' => $area, 'sort' => 'name'])); ?>"><?php esc_html_e('Page or action', 'workforce-one'); ?></a></th>
            <th><a href="<?php echo esc_url($url(['days' => $days, 'area' => $area, 'sort' => 'runs'])); ?>"><?php esc_html_e('Runs', 'workforce-one'); ?></a></th><th><?php esc_html_e('Typical', 'workforce-one'); ?></th>
            <th><a href="<?php echo esc_url($url(['days' => $days, 'area' => $area, 'sort' => 'slow'])); ?>"><?php esc_html_e('Slow (1 in 20)', 'workforce-one'); ?></a></th><th><?php esc_html_e('Worst', 'workforce-one'); ?></th>
            <th><?php esc_html_e('Queries', 'workforce-one'); ?></th><th><?php esc_html_e('Memory', 'workforce-one'); ?></th><th><?php esc_html_e('Errors', 'workforce-one'); ?></th><th><?php esc_html_e('Failed', 'workforce-one'); ?></th><th><?php esc_html_e('14 days', 'workforce-one'); ?></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="10"><?php esc_html_e('Nothing measured in this period yet.', 'workforce-one'); ?></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr data-route="<?php echo esc_attr($r['route']); ?>">
                <td><a href="<?php echo esc_url($url(['tab' => 'route', 'route' => $r['route']])); ?>"><strong><?php echo esc_html($label($r['route'])); ?></strong></a><br><code style="font-size:11px"><?php echo esc_html($r['route']); ?></code></td>
                <td><?php echo esc_html(number_format_i18n($r['n'])); ?></td><td><?php echo esc_html($seconds($r['p50'])); ?></td><td><?php echo esc_html($seconds($r['p95'])); ?></td><td><?php echo esc_html($seconds($r['ms_max'])); ?></td>
                <td><?php echo esc_html(number_format_i18n($r['q_avg'])); ?></td><td><?php echo esc_html(size_format($r['mem_max'] * 1024)); ?></td>
                <td><?php echo $r['err'] ? '<strong style="color:#b32d2e">' . esc_html(number_format_i18n($r['err'])) . '</strong>' : '0'; ?></td><td><?php echo $r['failed'] ? '<strong style="color:#b32d2e">' . esc_html(number_format_i18n($r['failed'])) . '</strong>' : '0'; ?></td>
                <td><?php echo $spark($r['spark'], $r['marks']); // phpcs:ignore WordPress.Security.EscapeOutput -- inline SVG built with escaped attributes ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <p class="description" style="max-width:1000px"><?php esc_html_e('Sorted by total time (runs × time) unless you pick a heading. The small line is the typical time per day; a dotted line marks a new version. Not measured on purpose: network and phone rendering time, and whether the site as a whole is reachable. This screen sees only what runs on this server.', 'workforce-one'); ?></p>

<?php elseif ($tab === 'route'): ?>
    <p><a href="<?php echo esc_url($url(['tab' => 'overview'])); ?>">← <?php esc_html_e('All pages', 'workforce-one'); ?></a></p>
    <h2><?php echo esc_html($label($route)); ?> <code style="font-size:12px"><?php echo esc_html($route); ?></code></h2>
    <?php if (!empty($detail['stat'])): $st = $detail['stat']; ?>
        <p><?php echo esc_html(sprintf(/* translators: 1: runs, 2: typical, 3: slow, 4: worst, 5: queries */ __('%1$s runs · typical %2$s · slow %3$s · worst %4$s · %5$d queries per load', 'workforce-one'), number_format_i18n($st['n']), $seconds($st['p50']), $seconds($st['p95']), $seconds($st['ms_max']), $st['q_avg'])); ?></p>
    <?php endif; ?>
    <div style="display:flex;flex-wrap:wrap;gap:20px;max-width:1100px">
        <div style="flex:1 1 380px"><strong><?php esc_html_e('Load time per day', 'workforce-one'); ?></strong><?php echo $detail['chart_time']; // phpcs:ignore WordPress.Security.EscapeOutput -- inline SVG ?></div>
        <div style="flex:1 1 380px"><strong><?php esc_html_e('Database queries per load', 'workforce-one'); ?></strong><?php echo $detail['chart_q']; // phpcs:ignore WordPress.Security.EscapeOutput -- inline SVG ?></div>
    </div>
    <h3><?php esc_html_e('Queries on this page', 'workforce-one'); ?></h3>
    <div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped"><thead><tr><th><?php esc_html_e('Query (values removed)', 'workforce-one'); ?></th><th><?php esc_html_e('Called from', 'workforce-one'); ?></th><th><?php esc_html_e('Per load', 'workforce-one'); ?></th><th><?php esc_html_e('Average', 'workforce-one'); ?></th><th><?php esc_html_e('Slowest', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php if (!$queries): ?><tr><td colspan="5"><?php esc_html_e('No query detail yet (it is taken from a share of requests).', 'workforce-one'); ?></td></tr><?php endif; ?>
    <?php foreach ($queries as $q): ?><tr><td><code style="font-size:11px"><?php echo esc_html($q['shape']); ?></code></td><td><code><?php echo esc_html($q['caller']); ?></code><?php echo (int) $q['max_repeat'] >= (int) $settings['repeat'] ? ' <strong style="color:#d18a00">' . esc_html__('repeated', 'workforce-one') . '</strong>' : ''; ?></td>
        <td><?php echo esc_html(number_format_i18n($q['requests'] ? $q['n'] / $q['requests'] : 0, 1)); ?></td><td><?php echo esc_html($seconds((int) round($q['us_sum'] / max(1, $q['n']) / 1000))); ?></td><td><?php echo esc_html($seconds((int) round($q['us_max'] / 1000))); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($errors): ?><h3><?php esc_html_e('PHP errors on this page', 'workforce-one'); ?></h3><div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped"><tbody>
    <?php foreach ($errors as $e): ?><tr><td><?php echo esc_html($e['level']); ?></td><td><code><?php echo esc_html($e['file'] . ':' . $e['line']); ?></code></td><td><?php echo esc_html($e['message']); ?></td><td><?php echo esc_html(number_format_i18n($e['n'])); ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>

<?php elseif ($tab === 'jobs'): ?>
    <?php if (!empty($wp_cron_off)): ?><div class="notice notice-warning inline"><p><?php esc_html_e('DISABLE_WP_CRON is set. Scheduled jobs run only if the server calls wp-cron.php itself.', 'workforce-one'); ?></p></div><?php endif; ?>
    <?php if (!empty($insert_error)): ?><div class="notice notice-warning inline" data-health-insert-error><p><?php echo esc_html(sprintf(/* translators: %s: time ago */ __('The daily summary was written one row at a time (%s): the database refused writing the rows together. Its answer:', 'workforce-one'), $ago($insert_error['at']))); ?> <code><?php echo esc_html($insert_error['error']); ?></code></p></div><?php endif; ?>
    <div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped" data-health-jobs><thead><tr><th><?php esc_html_e('Job', 'workforce-one'); ?></th><th><?php esc_html_e('Every', 'workforce-one'); ?></th><th><?php esc_html_e('Last run', 'workforce-one'); ?></th><th><?php esc_html_e('Took', 'workforce-one'); ?></th><th><?php esc_html_e('Runs (24 h)', 'workforce-one'); ?></th><th><?php esc_html_e('Slow (1 in 20)', 'workforce-one'); ?></th><th><?php esc_html_e('Failed (7 days)', 'workforce-one'); ?></th><th><?php esc_html_e('Next', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php foreach ($jobs as $j): $label_status = ['ok' => [__('OK', 'workforce-one'), '#0b7a4b'], 'overdue' => [__('Overdue', 'workforce-one'), '#d18a00'], 'failed' => [__('Failed', 'workforce-one'), '#b32d2e'], 'none' => [__('Not scheduled', 'workforce-one'), '#646970'], 'idle' => [__('Waits for work', 'workforce-one'), '#646970']][$j['status']]; ?>
        <tr data-job="<?php echo esc_attr($j['hook']); ?>"><td><code><?php echo esc_html($j['hook']); ?></code></td><td><?php echo esc_html($j['every'] ?: '—'); ?></td><td><?php echo esc_html($ago((int) ($j['last']['at'] ?? 0))); ?></td><td><?php echo isset($j['last']['ms']) ? esc_html($seconds((int) $j['last']['ms'])) : '—'; ?></td>
        <td><?php echo esc_html(number_format_i18n($j['runs'])); ?></td><td><?php echo $j['p95'] !== null ? esc_html($seconds($j['p95'])) : '—'; ?></td><td><?php echo $j['failed'] ? '<strong style="color:#b32d2e">' . esc_html($j['failed']) . '</strong>' : '0'; ?></td>
        <td><?php echo $j['next'] ? esc_html($j['next'] < time() ? sprintf(/* translators: %s: time */ __('%s late', 'workforce-one'), human_time_diff($j['next'], time())) : sprintf(/* translators: %s: time */ __('in %s', 'workforce-one'), human_time_diff(time(), $j['next']))) : '—'; ?></td>
        <td><strong style="color:<?php echo esc_attr($label_status[1]); ?>"><?php echo esc_html($label_status[0]); ?></strong></td></tr>
    <?php endforeach; ?></tbody></table></div>

<?php elseif ($tab === 'queries'): ?>
    <div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped" data-health-queries><thead><tr><th><?php esc_html_e('Query (values removed)', 'workforce-one'); ?></th><th><?php esc_html_e('Called from', 'workforce-one'); ?></th><th><?php esc_html_e('Page', 'workforce-one'); ?></th><th><?php esc_html_e('Times', 'workforce-one'); ?></th><th><?php esc_html_e('Average', 'workforce-one'); ?></th><th><?php esc_html_e('Slowest', 'workforce-one'); ?></th><th><?php esc_html_e('Most in one load', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php if (!$queries): ?><tr><td colspan="7"><?php esc_html_e('No query detail yet (it is taken from a share of requests).', 'workforce-one'); ?></td></tr><?php endif; ?>
    <?php foreach ($queries as $q): ?><tr><td><code style="font-size:11px"><?php echo esc_html($q['shape']); ?></code></td><td><code><?php echo esc_html($q['caller']); ?></code></td><td><?php echo esc_html($label($q['route'])); ?></td><td><?php echo esc_html(number_format_i18n($q['n'])); ?></td><td><?php echo esc_html($seconds((int) round($q['us_sum'] / max(1, $q['n']) / 1000))); ?></td><td><?php echo esc_html($seconds((int) round($q['us_max'] / 1000))); ?></td><td><?php echo esc_html(number_format_i18n($q['max_repeat'])); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="description"><?php esc_html_e('Only the shape of a query is kept: names, numbers and dates are replaced by “?”, so nothing about a person can be read back.', 'workforce-one'); ?></p>

<?php elseif ($tab === 'errors'): ?>
    <div style="<?php echo esc_attr($box); ?>padding-top:0"><table class="widefat striped" data-health-errors><thead><tr><th><?php esc_html_e('Level', 'workforce-one'); ?></th><th><?php esc_html_e('Message', 'workforce-one'); ?></th><th><?php esc_html_e('File', 'workforce-one'); ?></th><th><?php esc_html_e('Times', 'workforce-one'); ?></th><th><?php esc_html_e('First seen', 'workforce-one'); ?></th><th><?php esc_html_e('Last seen', 'workforce-one'); ?></th></tr></thead><tbody>
    <?php if (!$errors): ?><tr><td colspan="6"><?php esc_html_e('No PHP errors from Workforce One code.', 'workforce-one'); ?></td></tr><?php endif; ?>
    <?php foreach ($errors as $e): ?><tr><td><?php echo esc_html($e['level']); ?></td><td><?php echo esc_html($e['message']); ?></td><td><code><?php echo esc_html($e['file'] . ':' . $e['line']); ?></code></td><td><?php echo esc_html(number_format_i18n($e['n'])); ?></td><td><?php echo esc_html($ago((int) strtotime($e['first_at'] . ' UTC'))); ?></td><td><?php echo esc_html($ago((int) strtotime($e['last_at'] . ' UTC'))); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>

<?php else: ?>
    <p><?php esc_html_e('The switch, how many requests give detailed query data, and how long the daily history is kept are on', 'workforce-one'); ?> <a href="<?php echo esc_url($features_url); ?>"><?php esc_html_e('Features', 'workforce-one'); ?></a>
        (<?php echo esc_html(sprintf(/* translators: 1: percent, 2: days */ __('now %1$d%% of requests, %2$d days', 'workforce-one'), $rate, $retention)); ?>).</p>
    <?php if (!$can_change): ?><div class="notice notice-info inline"><p><?php esc_html_e('You can see these settings. Changing them needs the Manage Settings permission.', 'workforce-one'); ?></p></div><?php endif; ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="<?php echo esc_attr($box); ?>padding-top:14px"><?php wp_nonce_field('ews_health_settings_save'); ?><input type="hidden" name="action" value="ews_health_settings_save">
        <h2 style="margin-top:0"><?php esc_html_e('When to flag something', 'workforce-one'); ?></h2>
        <table class="form-table" role="presentation"><tbody>
        <?php foreach ([['slow_ms', __('A page is slow when 1 in 20 loads is longer than (ms)', 'workforce-one')], ['heavy_ms', __('The same for heavy operations and scheduled jobs (ms)', 'workforce-one')], ['queries', __('Many queries: more than this per load', 'workforce-one')], ['repeat', __('The same query repeated this many times in one load', 'workforce-one')],
            ['slow_query_ms', __('A single query slower than (ms)', 'workforce-one')], ['memory_pct', __('Peak memory above (% of the limit)', 'workforce-one')], ['regression_pct', __('Slower than before: at least (%)', 'workforce-one')], ['regression_ms', __('…and at least (ms)', 'workforce-one')],
            ['fail_pct', __('Failed requests above (%)', 'workforce-one')], ['cron_late_min', __('A scheduled job is late after (minutes)', 'workforce-one')], ['min_runs', __('Judge a page only after this many runs', 'workforce-one')]] as [$k, $l]): ?>
            <tr><th scope="row"><label for="health_<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></label></th><td><input type="number" id="health_<?php echo esc_attr($k); ?>" name="health_<?php echo esc_attr($k); ?>" value="<?php echo (int) $settings[$k]; ?>" class="small-text"<?php disabled(!$can_change); ?>></td></tr>
        <?php endforeach; ?></tbody></table>
        <h2><?php esc_html_e('Alerts', 'workforce-one'); ?></h2>
        <p><label><input type="checkbox" name="health_notice" value="1" <?php checked(!empty($settings['notice'])); disabled(!$can_change); ?>> <?php esc_html_e('Show a notice on Workforce One pages and the WordPress Dashboard (can be hidden for 7 days)', 'workforce-one'); ?></label></p>
        <p><label><input type="checkbox" name="health_email" value="1" <?php checked(!empty($settings['email'])); disabled(!$can_change); ?>> <?php esc_html_e('Also send an email (page names and numbers only, never queries or file paths). Off by default; sent through this site’s own mail.', 'workforce-one'); ?></label></p>
        <p><label for="health_email_to"><?php esc_html_e('Send to', 'workforce-one'); ?></label> <input type="email" id="health_email_to" name="health_email_to" value="<?php echo esc_attr((string) $settings['email_to']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text"<?php disabled(!$can_change); ?>></p>
        <?php if ($can_change): ?><p><button class="button button-primary" type="submit"><?php esc_html_e('Save', 'workforce-one'); ?></button></p><?php endif; ?>
    </form>
    <?php if ($can_change): ?>
    <form method="post" action="<?php echo esc_url($post_url); ?>" style="<?php echo esc_attr($box); ?>padding-top:14px" data-ews-confirm="<?php esc_attr_e('Delete all collected System health data?', 'workforce-one'); ?>"><?php wp_nonce_field('ews_health_purge'); ?><input type="hidden" name="action" value="ews_health_purge">
        <h2 style="margin-top:0"><?php esc_html_e('Data', 'workforce-one'); ?></h2>
        <p><?php echo esc_html(sprintf(/* translators: %s: rows */ __('%s rows stored. Hourly detail is kept 7 days, query and error detail 30 days, the daily history as chosen in Features.', 'workforce-one'), number_format_i18n($facts['rows']))); ?></p>
        <p><button class="button" type="submit" style="color:#b32d2e"><?php esc_html_e('Delete collected data', 'workforce-one'); ?></button></p>
    </form>
    <?php endif; ?>
    <p class="description" style="max-width:900px"><?php echo esc_html($multisite ? __('On a network only super admins see this screen.', 'workforce-one') : __('Who sees this screen: site administrators; the permission “View System Health” can be given to others on Roles & permissions.', 'workforce-one')); ?></p>
<?php endif; ?>
</div>
