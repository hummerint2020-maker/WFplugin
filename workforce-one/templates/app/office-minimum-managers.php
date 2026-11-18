<?php
/**
 * Office minimum with the managers' minimum on (3.31.153, docs/tasks/manager-office-minimum.md, mockups
 * docs/mockups/manager-office-minimum/): one card listing the days below either minimum (people, managers or both), the teams
 * sheet of a day short of people and the Managers sheet of a day short of managers (each manager's plan, who could come in).
 * Included by templates/app/office-minimum.php. Styles: assets/css/app-attendance.css (.wfo-om-*, .wfo-mom-*).
 *
 * @var array<string,mixed> $om  om_attendance() with 'mgr' => [ids, teams, short, any]
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
if (!$om['mgr']['any']) return;
$label = static function (string $d): string { return date_i18n('D j M', strtotime($d)); };
$plan_icon = static function (string $kind, string $status): string {
    return $kind === 'office' ? 'office' : \WorkforceOne\Ui\Icons::forStatus($status)[0];
};
?>
<section class="wfo-om-banner" role="status" data-om-banner>
    <div class="wfo-om-banner-head"><span class="wfo-om-ico"><?php echo Icons::svg('alert', 20, 2.2); ?></span><div>
        <h3><?php echo esc_html(sprintf(/* translators: %d: number of days */ _n('%d day below a minimum', '%d days below a minimum', count($om['mgr']['any']), 'workforce-one'), count($om['mgr']['any']))); ?></h3>
        <p><?php esc_html_e('This is only a warning: the schedule still saves.', 'workforce-one'); ?></p></div></div>
    <ul>
    <?php foreach ($om['mgr']['any'] as $d): $day = $om['days'][$d]; $people = $day['short'] > 0; $mgr = !empty($day['mgr']) && $day['mgr']['short'] > 0; ?>
        <li><span class="wfo-om-pill"><?php echo esc_html($label($d)); ?></span>
            <span class="wfo-mom-parts"><?php if ($people): ?><span class="wfo-mom-what"><?php echo Icons::svg('office', 13, 2.2); ?><?php echo esc_html(sprintf(/* translators: 1: people in the office, 2: minimum */ __('%1$d of %2$d in the office', 'workforce-one'), $day['office'], $day['min'])); ?></span><?php endif; ?>
            <?php if ($mgr): ?><span class="wfo-mom-what"><?php echo Icons::svg('people', 13, 2.2); ?><?php echo esc_html(sprintf(/* translators: 1: managers in the office, 2: managers' minimum */ __('%1$d of %2$d managers', 'workforce-one'), $day['mgr']['office'], $day['mgr']['min'])); ?></span><?php endif; ?></span>
            <span class="wfo-mom-btns"><?php if ($people): ?><button type="button" class="wfo-om-link" data-wfo-sheet="wfo-om-<?php echo esc_attr($d); ?>"><?php echo Icons::svg('people', 15, 2.2); ?><?php esc_html_e('Teams', 'workforce-one'); ?></button><?php endif; ?>
            <?php if ($mgr): ?><button type="button" class="wfo-om-link is-mgr" data-wfo-sheet="wfo-mom-<?php echo esc_attr($d); ?>"><?php echo Icons::svg('people', 15, 2.2); ?><?php esc_html_e('Managers', 'workforce-one'); ?></button><?php endif; ?></span></li>
    <?php endforeach; ?>
    </ul>
</section>
<?php $om_sheet_days = $om['short']; include __DIR__ . '/office-minimum-teams.php'; ?>
<?php foreach ($om['mgr']['short'] as $d): $m = $om['days'][$d]['mgr']; ?>
<dialog class="wfo-sheet wfo-om-sheet" id="wfo-mom-<?php echo esc_attr($d); ?>" aria-labelledby="wfo-mom-title-<?php echo esc_attr($d); ?>">
    <div class="wfo-sheet-body">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-mom-title-<?php echo esc_attr($d); ?>"><?php echo esc_html(sprintf(/* translators: %s: day */ __('Managers on %s', 'workforce-one'), date_i18n('l j F', strtotime($d)))); ?></h2>
            <button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <div class="wfo-om-sheet-sum"><span class="wfo-mom-day is-short"><?php echo Icons::svg('people', 13, 2.2); ?><b><?php echo (int) $m['office']; ?></b><span>/ <?php echo (int) $m['min']; ?></span></span>
            <div><strong><?php echo esc_html(date_i18n('l j F', strtotime($d))); ?></strong><span><?php echo esc_html(sprintf(/* translators: %d: managers missing */ _n('%d manager below the minimum', '%d managers below the minimum', $m['short'], 'workforce-one'), $m['short'])); ?></span></div></div>
        <?php if (!$m['rows']): ?><p class="wfo-om-how"><?php esc_html_e('No team has a manager yet: set them on the Teams page.', 'workforce-one'); ?></p><?php else: ?>
        <ul class="wfo-mom-list">
        <?php foreach ($m['rows'] as $r): $cls = 'is-' . ($r['kind'] === 'office' ? 'office' : ($r['kind'] === 'wfh' ? 'wfh' : 'other')); ?>
            <li class="<?php echo esc_attr($cls); ?>"><span class="ews-att-avatar wfo-att-avatar tone-<?php echo (int) $r['id'] % 5; ?>" aria-hidden="true"><?php echo esc_html(\WorkforceOne\Employees\ProfileSummary::initials($r['name']) ?: '·'); ?></span>
                <span class="wfo-mom-who"><b><?php echo esc_html($r['name']); ?></b><small><?php echo esc_html(sprintf(/* translators: %s: team names */ __('%s manager', 'workforce-one'), implode(is_rtl() ? '، ' : ', ', $om['mgr']['teams'][$r['id']] ?? []))); ?></small></span>
                <span class="wfo-mom-plan <?php echo esc_attr($cls); ?>"><?php echo Icons::svg($plan_icon($r['kind'], $r['status']), 13, 2.2); ?><?php echo esc_html($r['status'] !== '' ? $r['status'] : __('Not Set', 'workforce-one')); ?></span>
                <?php if ($r['kind'] === 'wfh'): ?><span class="wfo-mom-can"><?php esc_html_e('Could come in', 'workforce-one'); ?></span><?php endif; ?></li>
        <?php endforeach; ?>
        </ul><?php endif; ?>
        <p class="wfo-om-how"><?php esc_html_e('Managers = the team managers (marked "Manager" on the grid). Those working from home could come in; leave and trips never count.', 'workforce-one'); ?></p>
        <button class="wfo-sheet-submit" type="button" data-wfo-sheet-close><?php echo Icons::svg('check', 18, 2.2); ?><?php esc_html_e('OK', 'workforce-one'); ?></button>
    </div>
</dialog>
<?php endforeach; ?>
