<?php
/**
 * Office minimum on the Attendance page (3.31.77): the card listing the days below the minimum and,
 * for each, the teams sheet (each team's share, in the office, short / above, who could come in).
 * Opened with data-wfo-sheet (assets/js/sheet.js). Styles: assets/css/app-attendance.css (.wfo-om-*).
 *
 * With the managers' minimum on (3.31.153, $om['mgr']), the card lists the days below either minimum and a day short of managers
 * opens the Managers sheet (templates/app/office-minimum-managers.php); off, this file prints what it printed before.
 *
 * @var array{days:array<string,array<string,mixed>|null>,short:string[],statuses:string[],total:int,mgr?:array<string,mixed>,scope?:array<string,mixed>} $om
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
if (!empty($om['mgr'])) { include __DIR__ . '/office-minimum-managers.php'; return; }
if (!$om['short']) return;
$label = static function (string $d): string { return date_i18n('D j M', strtotime($d)); };
?>
<section class="wfo-om-banner" role="status" data-om-banner>
    <div class="wfo-om-banner-head"><span class="wfo-om-ico"><?php echo Icons::svg('alert', 20, 2.2); ?></span><div>
        <h3><?php echo esc_html(!empty($om['scope']['dept'])
            ? sprintf(/* translators: 1: department, 2: number of days */ _n('%1$s: %2$d day below its minimum', '%1$s: %2$d days below its minimum', count($om['short']), 'workforce-one'), $om['scope']['name'], count($om['short']))
            : sprintf(/* translators: %d: number of days */ _n('%d day below the office minimum', '%d days below the office minimum', count($om['short']), 'workforce-one'), count($om['short']))); ?></h3>
        <p><?php esc_html_e('This is only a warning: the schedule still saves.', 'workforce-one'); ?></p></div></div>
    <ul>
    <?php foreach ($om['short'] as $d): $day = $om['days'][$d]; ?>
        <li><span class="wfo-om-pill"><?php echo esc_html($label($d)); ?></span>
            <strong><?php echo esc_html(sprintf(/* translators: 1: people in the office, 2: minimum */ __('%1$d of %2$d in the office', 'workforce-one'), $day['office'], $day['min'])); ?></strong>
            <span><?php echo esc_html(sprintf(/* translators: %d: people missing */ __('%d short', 'workforce-one'), $day['short'])); ?></span>
            <button type="button" class="wfo-om-link" data-wfo-sheet="wfo-om-<?php echo esc_attr($d); ?>"><?php echo Icons::svg('people', 15, 2.2); ?><?php esc_html_e('Teams', 'workforce-one'); ?></button></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php $om_sheet_days = $om['short']; include __DIR__ . '/office-minimum-teams.php'; ?>
