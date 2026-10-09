<?php
/**
 * "My recent days" on Sign In / Out (attendance corrections, 3.31.74): the days within the
 * correction deadline, newest first; a day that needs it has the yellow "Request correction" button,
 * the others a smaller "Correct" one. The buttons open templates/app/correction-sheet.php
 * (assets/js/corrections.js). Styles: assets/css/app-corrections.css.
 *
 * @var array<int,array<string,mixed>> $days     EWS_Corrections_Trait::cx_recent_days()
 * @var string $corrections_url
 * @var int    $deadline   days
 * @var int    $used       requests this month
 * @var int    $limit      monthly limit (0 = none)
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Attendance\CorrectionRules;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup
$arrow = is_rtl() ? '←' : '→';
?>
<section class="wfo-panel wfo-cx-days" aria-labelledby="wfo-cx-days-title">
    <div class="wfo-cx-days-head"><h3 id="wfo-cx-days-title"><?php esc_html_e('My recent days', 'workforce-one'); ?></h3><a href="<?php echo esc_url($corrections_url); ?>"><?php esc_html_e('My corrections', 'workforce-one'); ?></a></div>
    <?php if (!$days): ?>
        <div class="wfo-rq-empty"><span class="wfo-rq-ico" aria-hidden="true"><?php echo Icons::svg('calendar', 24, 2); ?></span><strong><?php esc_html_e('No days recorded yet', 'workforce-one'); ?></strong><p><?php esc_html_e('Your days appear here after your first Sign In, with a button to request a correction if you forget one.', 'workforce-one'); ?></p></div>
    <?php endif; ?>
    <?php foreach ($days as $d):
        $req = $d['request'];
        // "none" is red only on a day that needs a correction (a day off or leave has no Sign In by design).
        $none = '<span class="' . ($d['problem'] && $d['problem'] !== 'today' ? 'is-missing' : 'is-none') . '">' . esc_html__('none', 'workforce-one') . '</span>';
        $times = ($d['in'] !== '' ? esc_html($d['in']) : $none) . ' ' . $arrow . ' ' . ($d['out'] !== '' ? esc_html($d['out']) : $none);
        $note = '';
        $chip = '';
        if ($req && $req->status === 'approved') { $note = __('Corrected', 'workforce-one'); $chip = '<span class="wfo-cx-chip is-ok">' . esc_html__('Corrected', 'workforce-one') . '</span>'; }
        elseif ($req && in_array($req->status, ['pending', 'pending_hr'], true)) { $chip = '<span class="wfo-cx-chip is-warn">' . esc_html__('Correction waiting', 'workforce-one') . '</span>'; }
        if ($d['problem'] === 'today') $note = __('Today · not signed out yet', 'workforce-one');
        elseif ($d['problem'] === 'no_out') $note = __('No Sign Out recorded', 'workforce-one');
        elseif ($d['problem'] === 'absent') $note = __('No Sign In recorded', 'workforce-one');
        // A rejected request can be asked again (with what the manager wrote in mind).
        if ($req && $req->status === 'rejected') $note = __('Correction rejected', 'workforce-one') . ($note !== '' ? ' · ' . $note : '');
        elseif ($note === '') {
            // Only a day with a Sign In is "On time"; a day without one says why it needs none.
            $kind = CorrectionRules::dayNote($d['result'], $d['in'] !== '', $d['late']);
            if ($kind === 'late') $note = $d['late'] > 0 ? sprintf(/* translators: %d: minutes */ __('Late %d min', 'workforce-one'), $d['late']) : __('Late', 'workforce-one');
            elseif ($kind === 'on_time') $note = __('On time', 'workforce-one');
            elseif ($kind === 'day_off') $note = __('Day off', 'workforce-one');
            elseif ($kind === 'leave') $note = __('On leave', 'workforce-one');
            elseif ($kind === 'holiday') $note = $d['holiday'] !== '' && $d['holiday'] !== 'Off Day' ? $d['holiday'] : __('Holiday', 'workforce-one');
            elseif ($kind === 'pending') $note = __('Not signed in yet', 'workforce-one');
            elseif ($kind === 'absent') $note = __('No Sign In recorded', 'workforce-one');
            elseif ($kind === 'planned') $note = $d['result'];
            else $note = __('Not scheduled', 'workforce-one');
        }
        $type = in_array($d['problem'], ['today', 'no_out'], true) ? 'out' : ($d['problem'] === 'absent' ? ($d['in'] === '' && $d['out'] === '' ? 'day' : 'in') : 'time');
        $attrs = ' data-cx-open data-date="' . esc_attr($d['date']) . '" data-in="' . esc_attr($d['in']) . '" data-out="' . esc_attr($d['out']) . '" data-type="' . esc_attr($type) . '" data-label="' . esc_attr(date_i18n('l j F', strtotime($d['date']))) . '"';
        $ts = strtotime($d['date']);
    ?>
        <div class="wfo-cx-day<?php echo $d['problem'] && $d['problem'] !== 'today' && $d['can'] ? ' is-problem' : ''; ?>">
            <div class="wfo-cx-date"><b><?php echo esc_html(date_i18n('j', $ts)); ?></b><small><?php echo esc_html(date_i18n('D', $ts)); ?></small></div>
            <div class="wfo-cx-day-main"><p class="wfo-cx-times"><?php echo $times; ?></p><p class="wfo-cx-note"><?php echo esc_html($note); ?></p></div>
            <?php if ($chip): echo $chip; elseif ($d['can'] && $d['problem'] && $d['problem'] !== 'today'): ?>
                <button type="button" class="wfo-cx-fix"<?php echo $attrs; ?>><?php echo Icons::svg('edit', 15, 2.2); ?><?php esc_html_e('Request correction', 'workforce-one'); ?></button>
            <?php elseif ($d['can']): ?>
                <button type="button" class="wfo-cx-fix is-soft"<?php echo $attrs; ?>><?php esc_html_e('Correct', 'workforce-one'); ?></button>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if ($days): ?>
        <div class="wfo-cx-days-foot"><?php
            /* translators: %d: number of days */
            echo esc_html(sprintf(_n('You can ask to correct a day within %d day', 'You can ask to correct a day within %d days', $deadline, 'workforce-one'), $deadline));
            if ($limit > 0) echo ' · ' . esc_html(sprintf(/* translators: 1: used, 2: monthly limit */ __('%1$d of %2$d requests used this month', 'workforce-one'), $used, $limit));
        ?></div>
    <?php endif; ?>
</section>
