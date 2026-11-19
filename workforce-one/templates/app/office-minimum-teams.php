<?php
/**
 * The teams sheet of each day in $om_sheet_days (3.31.77; moved here in 3.31.153 so the managers' card uses it too).
 * @var array<string,mixed> $om
 * @var string[] $om_sheet_days
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
?>
<?php foreach ($om_sheet_days as $d): $day = $om['days'][$d]; ?>
<dialog class="wfo-sheet wfo-om-sheet" id="wfo-om-<?php echo esc_attr($d); ?>" aria-labelledby="wfo-om-title-<?php echo esc_attr($d); ?>">
    <div class="wfo-sheet-body">
        <div class="wfo-sheet-grip" aria-hidden="true"></div>
        <div class="wfo-sheet-head"><h2 id="wfo-om-title-<?php echo esc_attr($d); ?>"><?php echo esc_html(sprintf(/* translators: %s: day */ __('Teams on %s', 'workforce-one'), date_i18n('l j F', strtotime($d)))); ?></h2>
            <button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
        <div class="wfo-om-sheet-sum"><span class="wfo-om-day is-short"><?php echo Icons::svg('office', 13, 2.2); ?><b><?php echo (int) $day['office']; ?></b><span>/ <?php echo (int) $day['min']; ?></span></span>
            <div><strong><?php echo esc_html(date_i18n('l j F', strtotime($d))); ?></strong><span><?php echo esc_html(sprintf(/* translators: %d: people missing */ __('%d below the minimum', 'workforce-one'), $day['short'])); ?></span></div></div>
        <table class="wfo-om-teams">
            <thead><tr><th scope="col"><?php esc_html_e('Team', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('Share', 'workforce-one'); ?></th><th scope="col"><?php esc_html_e('In office', 'workforce-one'); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e('Short / above', 'workforce-one'); ?></span></th></tr></thead>
            <tbody>
            <?php foreach ($day['teams'] as $t): $cls = $t['gap'] < 0 ? 'is-short' : ($t['gap'] > 0 ? 'is-over' : 'is-ok'); ?>
                <tr class="<?php echo esc_attr($cls); ?>"><th scope="row"><?php echo esc_html($t['name']); ?><small><?php echo esc_html(sprintf(/* translators: %d: people in the team */ _n('%d person', '%d people', $t['size'], 'workforce-one'), $t['size'])); ?></small>
                    <?php if ($t['can']): $show = array_slice($t['can'], 0, 3); $more = count($t['can']) - count($show); ?>
                        <div class="wfo-om-can"><?php echo Icons::svg('wfh', 13, 2.2); ?><span><?php esc_html_e('Could come in (working from home):', 'workforce-one'); ?></span> <?php echo esc_html(implode(is_rtl() ? '، ' : ', ', $show) . ($more > 0 ? ' ' . sprintf(/* translators: %d: more people */ __('and %d more', 'workforce-one'), $more) : '')); ?></div>
                    <?php endif; ?></th>
                    <td><?php echo (int) $t['share']; ?></td><td><b><?php echo (int) $t['office']; ?></b></td>
                    <td><span class="wfo-om-gap <?php echo esc_attr($cls); ?>"><?php echo esc_html($t['gap'] < 0 ? sprintf(/* translators: %d: people missing */ __('%d short', 'workforce-one'), -$t['gap']) : ($t['gap'] > 0 ? sprintf(/* translators: %d: people above the share */ __('+%d above', 'workforce-one'), $t['gap']) : __('On share', 'workforce-one'))); ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th scope="row"><?php esc_html_e('Total', 'workforce-one'); ?></th><td><?php echo (int) $day['min']; ?></td><td><b><?php echo (int) $day['office']; ?></b></td><td><span class="wfo-om-gap is-short"><?php echo esc_html(sprintf(/* translators: %d: people missing */ __('%d short', 'workforce-one'), $day['short'])); ?></span></td></tr></tfoot>
        </table>
        <p class="wfo-om-how"><?php echo esc_html(!empty($om['scope']['dept'])
            ? sprintf(/* translators: 1: number of the department's employees, 2: minimum */ __('Each team\'s share = minimum × team size ÷ the department\'s employees (%1$d), rounded so the shares add up to exactly %2$d.', 'workforce-one'), $om['total'], $day['min'])
            : sprintf(/* translators: 1: number of employees, 2: minimum */ __('Each team\'s share = minimum × team size ÷ all employees (%1$d), rounded so the shares add up to exactly %2$d.', 'workforce-one'), $om['total'], $day['min'])); ?></p>
        <button class="wfo-sheet-submit" type="button" data-wfo-sheet-close><?php echo Icons::svg('check', 18, 2.2); ?><?php esc_html_e('OK', 'workforce-one'); ?></button>
    </div>
</dialog>
<?php endforeach; ?>
