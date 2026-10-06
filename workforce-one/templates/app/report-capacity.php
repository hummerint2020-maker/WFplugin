<?php
/**
 * Report Center → Location Capacity: people planned at each location per day against its seats
 * (types that require the location, e.g. Office), and on past days how many of them signed in.
 * The whole location counts; a department manager also sees their own department's share.
 *
 * @var array<int,array<string,mixed>> $grid  Capacity::grid()
 * @var bool $split                           show the viewer's department share ("yours")
 * @var string $today
 * @var int $warn_pct
 * @var string $csv_url
 * @var string $xlsx_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Reports\Capacity;

$dates = $grid ? array_keys(reset($grid)['days']) : [];
?>
<div class="ews-report-summary-card">
    <div class="ews-report-section-head"><div><h3>Location Capacity</h3><p>People planned at each location (schedule types that require the location, e.g. Office; not WFH, leave or missions) against its seats. Near capacity from <?php echo (int) $warn_pct; ?>%. Seats are set in wp-admin → Work Locations.</p></div><span><?php echo (int) count($grid); ?> Locations</span></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-summary" data-ews-sortable>
        <thead><tr><th data-sort="text">Location</th><th data-sort="num">Seats</th><th data-sort="num">Peak Planned</th><th data-sort="num">Days Over</th><th data-sort="num">Days Near</th></tr></thead>
        <tbody>
        <?php if (!$grid): ?><tr><td colspan="5" class="ews-report-empty">No active locations. Add them in wp-admin → Work Locations.</td></tr><?php endif; ?>
        <?php foreach ($grid as $l): ?>
            <tr data-location="<?php echo esc_attr($l['name']); ?>">
                <td data-col="name" data-value="<?php echo esc_attr(strtolower($l['name'])); ?>"><strong><?php echo esc_html($l['name']); ?></strong></td>
                <td data-col="seats" data-value="<?php echo $l['seats'] === null ? '' : (int) $l['seats']; ?>"><?php echo $l['seats'] === null ? 'No limit' : (int) $l['seats']; ?></td>
                <td data-col="peak" data-value="<?php echo (int) $l['peak']; ?>"><?php echo (int) $l['peak']; ?></td>
                <td data-col="days_over" data-value="<?php echo (int) $l['days_over']; ?>" class="<?php echo $l['days_over'] ? 'ews-rc-neg' : ''; ?>"><?php echo (int) $l['days_over']; ?></td>
                <td data-col="days_warn" data-value="<?php echo (int) $l['days_warn']; ?>"><?php echo (int) $l['days_warn']; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="ews-report-results-card">
    <div class="ews-report-section-head"><div><h3>Daily Plan</h3><p>Planned / seats each day<?php echo $split ? ', with how many are your employees' : ''; ?>. Past days also show how many signed in.</p></div>
        <a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url($csv_url); ?>" data-ews-csv-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('download', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export CSV', 'workforce-one'); ?></a>
        <a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url($xlsx_url); ?>" data-ews-xlsx-export="1"><?php echo \WorkforceOne\Ui\Icons::svg('reports', 16); // phpcs:ignore WordPress.Security.EscapeOutput -- static markup ?><?php esc_html_e('Export Excel', 'workforce-one'); ?></a></div>
    <div class="ews-rc-cap-legend"><span class="ews-rc-cap--ok">OK</span><span class="ews-rc-cap--warn">Near capacity (<?php echo (int) $warn_pct; ?>%+)</span><span class="ews-rc-cap--over">Over capacity</span><span class="ews-rc-cap--none">No seat limit</span></div>
    <div class="ews-report-table-wrap"><table class="ews-report-table ews-rc-cap-grid">
        <thead><tr><th>Location</th>
            <?php foreach ($dates as $d): ?><th class="<?php echo $d === $today ? 'is-today' : ''; ?>"><?php echo esc_html(date_i18n('D', strtotime($d))); ?><small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php if ($grid && !$dates): ?><tr><td class="ews-report-empty">No working days in the selected period.</td></tr><?php endif; ?>
        <?php foreach ($grid as $l): ?>
            <tr data-cap-row="<?php echo esc_attr($l['name']); ?>"><th scope="row"><?php echo esc_html($l['name']); ?><small><?php echo $l['seats'] === null ? 'No limit' : esc_html($l['seats'] . ' seats'); ?></small></th>
            <?php foreach ($l['days'] as $d => $day):
                $tip = date_i18n('D d M', strtotime($d)) . ': ' . $day['planned'] . ' planned' . ($l['seats'] !== null ? ' of ' . $l['seats'] . ' seats (' . $day['pct'] . '%)' : '') . ' · ' . Capacity::LABELS[$day['level']]
                    . ($day['actual'] !== null ? ' · ' . $day['actual'] . ' signed in' : '') . ($split ? ' · ' . $day['mine'] . ' your employees' : ''); ?>
                <td class="ews-rc-cap ews-rc-cap--<?php echo esc_attr($day['level']); ?>" data-location="<?php echo esc_attr($l['name']); ?>" data-date="<?php echo esc_attr($d); ?>" data-planned="<?php echo (int) $day['planned']; ?>" data-actual="<?php echo $day['actual'] === null ? '' : (int) $day['actual']; ?>"<?php if ($split): ?> data-mine="<?php echo (int) $day['mine']; ?>"<?php endif; ?> data-pct="<?php echo $day['pct'] === null ? -1 : (int) $day['pct']; ?>" data-level="<?php echo esc_attr($day['level']); ?>" title="<?php echo esc_attr($tip); ?>">
                    <b><?php echo (int) $day['planned']; ?></b><?php if ($l['seats'] !== null): ?><small>/<?php echo (int) $l['seats']; ?></small><?php endif; ?>
                    <?php if ($day['actual'] !== null): ?><span><?php echo (int) $day['actual']; ?> came</span><?php endif; ?>
                    <?php if ($split): ?><em><?php echo (int) $day['mine']; ?> yours</em><?php endif; ?>
                </td>
            <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
