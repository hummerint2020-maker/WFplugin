<?php
/**
 * The office minimum warning on a leave waiting for a decision (3.31.77, managers 3.31.153): on the Leave page and, with
 * Leave in My requests on (3.31.158), on the leave card in My approvals. Moved here unchanged from templates/app/leave.php.
 *
 * @var array<string,mixed>|null $om  om_leave_impact() for the leave, or null
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
?>
<?php if (!empty($om) && isset($om['date'])): ?><div class="wfo-om-note"><?php echo Icons::svg('office', 18, 2.2); ?><div><?php if (isset($om['dept'])): ?><span class="wfo-omd-for"><?php echo esc_html($om['dept']); ?></span><?php endif; ?><strong><?php echo esc_html(sprintf(/* translators: 1: day, 2: people in the office, 3: minimum */ __('Approving makes %1$s %2$d of %3$d in the office', 'workforce-one'), date_i18n('l j F', strtotime($om['date'])), $om['office'], $om['min'])); ?></strong><?php
                if ($om['team'] !== '') echo esc_html(sprintf(/* translators: 1: team, 2: in the office, 3: the team's share */ __('%1$s would have %2$d of their share of %3$d.', 'workforce-one'), $om['team'], $om['team_office'], $om['share']) . ' ');
                esc_html_e('This is only a warning; you can still approve.', 'workforce-one'); ?></div></div><?php endif; ?><?php if (!empty($om['mgr'])): $omm = $om['mgr']; /* a manager's leave (3.31.153) */ ?><div class="wfo-om-note is-mgr"><?php echo Icons::svg('people', 18, 2.2); ?><div><?php if (isset($om['dept'])): ?><span class="wfo-omd-for"><?php echo esc_html($om['dept']); ?></span><?php endif; ?><strong><?php echo esc_html(sprintf(/* translators: 1: day, 2: managers in the office, 3: managers' minimum */ __('Approving makes %1$s %2$d of %3$d managers in the office', 'workforce-one'), date_i18n('l j F', strtotime($omm['date'])), $omm['office'], $omm['min'])); ?></strong><?php
                if ($omm['can']) echo esc_html(sprintf(/* translators: %s: names */ __('Could come in (working from home): %s.', 'workforce-one'), implode(is_rtl() ? '، ' : ', ', array_slice($omm['can'], 0, 3)) . (count($omm['can']) > 3 ? ' ' . sprintf(/* translators: %d: more people */ __('and %d more', 'workforce-one'), count($omm['can']) - 3) : '')) . ' ');
                if (!isset($om['date'])) esc_html_e('This is only a warning; you can still approve.', 'workforce-one'); ?></div></div><?php endif; ?>
