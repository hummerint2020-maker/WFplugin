<?php
/**
 * Change requests to HR (3.31.165, mockup docs/mockups/schedule-change/send): the sheet that lists the changed days of a final
 * week and asks why, inside the grid's form (assets/js/attendance-grid.js fills the list and opens it).
 * @var array{reason_min:int,max_days:int} $sc
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
?>
        <dialog class="wfo-sheet wfo-sc-sheet" id="wfo-sc-sheet" aria-labelledby="wfo-sc-sheet-title">
            <div class="wfo-sheet-body">
                <div class="wfo-sheet-grip" aria-hidden="true"></div>
                <div class="wfo-sheet-head"><h2 id="wfo-sc-sheet-title"><?php esc_html_e('Send the changes to HR', 'workforce-one'); ?></h2><button type="button" class="wfo-sheet-x" data-wfo-sheet-close aria-label="<?php esc_attr_e('Close', 'workforce-one'); ?>"><?php echo Icons::svg('close', 18, 2.2); ?></button></div>
                <div class="wfo-rq-field"><span class="wfo-rq-label"><?php esc_html_e('The changes', 'workforce-one'); ?></span><ul class="wfo-sc-list" data-sc-list></ul></div>
                <p class="wfo-sc-err" data-sc-too-many hidden><?php echo esc_html(sprintf(/* translators: %d: days */ __('At most %d days can be asked for in one request: undo some first.', 'workforce-one'), (int) $sc['max_days'])); ?></p>
                <div class="wfo-rq-field"><label for="wfo-sc-reason"><?php esc_html_e('Reason (required)', 'workforce-one'); ?></label><textarea id="wfo-sc-reason" name="sc_reason" rows="3" minlength="<?php echo (int) $sc['reason_min']; ?>" maxlength="2000" required></textarea>
                    <small class="wfo-sc-hint"><?php echo esc_html(sprintf(/* translators: %d: characters */ __('At least %d characters. HR sees it.', 'workforce-one'), (int) $sc['reason_min'])); ?></small></div>
                <button class="wfo-sheet-submit" type="submit" data-sc-send><?php echo Icons::svg('arrow', 18, 2.2); ?><?php esc_html_e('Send to HR', 'workforce-one'); ?></button>
                <p class="wfo-sheet-note"><?php esc_html_e('The schedule stays as it is until HR approves. You can withdraw it from My requests while it waits.', 'workforce-one'); ?></p>
                <template data-sc-arrow><?php echo Icons::svg('arrow', 14, 2.2); ?></template>
            </div>
        </dialog>
