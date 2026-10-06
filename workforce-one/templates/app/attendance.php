<?php
/**
 * Employee app: manager Attendance grid. Styles: assets/css/app-attendance.css (new look, 3.31.56);
 * script: assets/js/attendance-grid.js (search / team filter, "Set…" for a whole day, week picker,
 * and the changed-cells-only submit). The desktop table shows above 700px and the cards below,
 * matching the script's mobile check.
 *
 * @var object[] $emps                  ordered by TeamOrder, annotated with _schedule_* fields
 * @var array<int,array{picture:string,initials:string}> $people
 * @var string[] $dates
 * @var string $today
 * @var int $current_emp_id
 * @var array<int,array<string,array{planned:string,actual:string,badge:array{class:string,label:string,detail:string}}>> $days
 * @var array<string,int> $summary      Present / Late / Absent / Leave day counts
 * @var array<string,array{recorded:int,absent:int}> $day_summary
 * @var string[] $team_options
 * @var int $rate
 * @var string[] $statuses              active schedule types
 * @var array{text:string,error:bool}|null $message
 * @var string $week
 * @var string $range
 * @var string $prev_url
 * @var string $next_url
 * @var string $preview_token
 * @var array<int,array<string,mixed>>|null $preview_rows
 * @var string $preview_cancel_url
 * @var string $sample_url
 * @var string $post_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; the helpers below escape their output.
$badges = static function (object $e) use ($current_emp_id): string {
    $html = esc_html($e->name);
    if ($current_emp_id === (int) $e->id) $html .= '<span class="ews-me-badge">' . esc_html__('You', 'workforce-one') . '</span>';
    if (!empty($e->_schedule_team_manager)) $html .= '<span class="ews-manager-badge">' . esc_html__('Manager', 'workforce-one') . '</span>';
    return $html;
};
$row_attrs = static function (object $e): string {
    return 'data-employee-name="' . esc_attr(strtolower($e->name . ' ' . $e->domain_name)) . '" data-team="' . esc_attr(strtolower(implode('|', (array) ($e->_schedule_team_names ?? [])))) . '"';
};
$status_options = static function (string $planned) use ($statuses): string {
    $html = '<option value="">' . esc_html__('Not Set', 'workforce-one') . '</option>';
    foreach ($statuses as $s) $html .= '<option value="' . esc_attr($s) . '"' . selected($planned, $s, false) . '>' . esc_html($s) . '</option>';
    return $html;
};
$avatar = static function (object $e) use ($people): string {
    $p = $people[(int) $e->id] ?? ['picture' => '', 'initials' => '·'];
    if ($p['picture'] !== '') return '<span class="ews-att-avatar wfo-att-avatar"><img src="' . esc_url($p['picture']) . '" alt=""></span>';
    return '<span class="ews-att-avatar wfo-att-avatar tone-' . ((int) $e->id % 5) . '" aria-hidden="true">' . esc_html($p['initials']) . '</span>';
};
// The result under each cell: an icon and the word (GridRules::badge() puts a symbol before the word).
$result_icons = ['present' => 'check', 'late' => 'overtime', 'absent' => 'alert', 'leave' => 'leave', 'pending' => 'clock', 'muted' => 'calendar', 'trip' => 'briefcase'];
$result_words = ['Present' => __('Present', 'workforce-one'), 'Late' => __('Late', 'workforce-one'), 'Absent' => __('Absent', 'workforce-one'), 'Leave' => __('Leave', 'workforce-one'),
    'Awaiting sign in' => __('Awaiting sign in', 'workforce-one'), 'Not scheduled' => __('Not scheduled', 'workforce-one')];
$result = static function (array $badge, string $detail_sep) use ($result_icons, $result_words): string {
    $word = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $badge['label']));
    $html = Icons::svg($result_icons[$badge['class']] ?? 'calendar', 13, 2.2) . '<span>' . esc_html($result_words[$word] ?? $word) . '</span>';
    if ($badge['detail'] !== '') $html .= $detail_sep === 'small' ? '<small>' . esc_html($badge['detail']) . '</small>' : ' · ' . esc_html($badge['detail']);
    return $html;
};
$plan_dots = ['office' => __('Office', 'workforce-one'), 'wfh' => __('WFH', 'workforce-one'), 'leave' => __('Leave', 'workforce-one'), 'trip' => __('Business Trip', 'workforce-one')];
?>
<?php if ($message): ?><div class="ews-notice wfo-att-notice<?php echo $message['error'] ? ' ews-notice-error' : ''; ?>"><?php echo esc_html($message['text']); ?></div><?php endif; ?>
<div class="ews-attendance-page wfo-att">
    <section class="wfo-att-top">
        <div class="wfo-att-intro">
            <span class="ews-dash-kicker wfo-att-kicker"><?php esc_html_e('MANAGER CONTROL CENTER', 'workforce-one'); ?></span>
            <p><?php esc_html_e('Manage your team schedule and see attendance results in one place.', 'workforce-one'); ?></p>
        </div>
        <div class="ews-att-week-nav wfo-weeknav">
            <a class="ews-week-arrow wfo-weeknav-prev" href="<?php echo esc_url($prev_url); ?>" aria-label="<?php esc_attr_e('Previous week', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 20, 2.2); ?></a>
            <form method="get" class="ews-att-week-form">
                <input type="hidden" name="ews_view" value="attendance">
                <label class="ews-att-week-range" title="<?php esc_attr_e('Choose a date', 'workforce-one'); ?>">
                    <?php echo Icons::svg('calendar', 18); ?>
                    <strong dir="ltr"><?php echo esc_html($range); ?></strong>
                    <input type="date" name="week" value="<?php echo esc_attr($dates[0]); ?>" data-ews-autosubmit aria-label="<?php esc_attr_e('Choose week', 'workforce-one'); ?>">
                </label>
            </form>
            <a class="ews-week-arrow wfo-weeknav-next" href="<?php echo esc_url($next_url); ?>" aria-label="<?php esc_attr_e('Next week', 'workforce-one'); ?>"><?php echo Icons::svg('chevron', 20, 2.2); ?></a>
        </div>
    </section>

    <div class="ews-att-summary wfo-att-stats">
        <div class="ews-att-stat people"><span><?php echo Icons::svg('people', 20); ?></span><div><b><?php echo count($emps); ?></b><small><?php esc_html_e('Employees', 'workforce-one'); ?></small></div></div>
        <div class="ews-att-stat present"><span><?php echo Icons::svg('check', 20, 2.4); ?></span><div><b><?php echo (int) $summary['Present']; ?></b><small><?php esc_html_e('Present days', 'workforce-one'); ?></small></div></div>
        <div class="ews-att-stat late"><span><?php echo Icons::svg('overtime', 20); ?></span><div><b><?php echo (int) $summary['Late']; ?></b><small><?php esc_html_e('Late days', 'workforce-one'); ?></small></div></div>
        <div class="ews-att-stat absent"><span><?php echo Icons::svg('alert', 20); ?></span><div><b><?php echo (int) $summary['Absent']; ?></b><small><?php esc_html_e('Absent days', 'workforce-one'); ?></small></div></div>
        <div class="ews-att-stat rate"><span><?php echo Icons::svg('insights', 20); ?></span><div><b><?php echo (int) $rate; ?>%</b><small><?php esc_html_e('Attendance rate', 'workforce-one'); ?></small></div></div>
    </div>

    <section class="ews-att-controls wfo-att-card">
        <div class="ews-att-control-head">
            <div class="ews-att-control-title"><strong><?php esc_html_e('Schedule Controls', 'workforce-one'); ?></strong><span><?php esc_html_e('Set the planned schedule for everyone on a specific day.', 'workforce-one'); ?></span></div>
            <div class="ews-att-tools">
                <label class="ews-att-search"><?php echo Icons::svg('search', 17, 2); ?><input type="search" id="ews-att-search" placeholder="<?php esc_attr_e('Search employee...', 'workforce-one'); ?>" autocomplete="off" aria-label="<?php esc_attr_e('Search employee...', 'workforce-one'); ?>"></label>
                <label class="ews-att-filter"><span><?php esc_html_e('Team', 'workforce-one'); ?></span><select id="ews-att-team">
                    <option value="all"><?php esc_html_e('All teams', 'workforce-one'); ?></option>
                    <?php foreach ($team_options as $team_name): ?><option value="<?php echo esc_attr($team_name); ?>"><?php echo esc_html($team_name); ?></option><?php endforeach; ?>
                </select></label>
            </div>
        </div>
        <div class="ews-day-quick-actions">
            <?php foreach ($dates as $i => $d): ?>
                <div class="ews-day-quick <?php echo $d === $today ? 'is-today' : ''; ?>">
                    <label for="wfo-fill-<?php echo (int) $i; ?>"><?php echo esc_html(date_i18n('D', strtotime($d))); ?><small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small></label>
                    <select id="wfo-fill-<?php echo (int) $i; ?>" data-ews-fill-day="<?php echo (int) $i; ?>">
                        <option value=""><?php esc_html_e('Set…', 'workforce-one'); ?></option>
                        <?php foreach ($statuses as $s): ?><option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ews-att-plan-legend"><?php foreach ($plan_dots as $k => $l): ?><span><i class="<?php echo esc_attr($k); ?>"></i> <?php echo esc_html($l); ?></span><?php endforeach; ?></div>
    </section>

    <form method="post" action="<?php echo esc_url($post_url); ?>" id="ews-grid-form">
        <input type="hidden" name="action" value="ews31_att_grid_save">
        <input type="hidden" name="week" value="<?php echo esc_attr($week); ?>">
        <input type="hidden" name="attendance_client" value="desktop">
        <input type="hidden" name="att_changes_json" id="ews-att-changes-json" value="">
        <?php wp_nonce_field('ews31_att_grid_save'); ?>

        <div class="ews-att-table-wrap wfo-att-card" tabindex="0" role="region" aria-label="<?php esc_attr_e('Attendance', 'workforce-one'); ?>">
            <table class="ews-att-table">
                <thead><tr>
                    <th class="employee-col" scope="col"><?php esc_html_e('Employee', 'workforce-one'); ?></th>
                    <?php foreach ($dates as $d): ?>
                        <th class="<?php echo $d === $today ? 'is-today' : ''; ?>" scope="col">
                            <div class="ews-att-day-name"><?php echo esc_html(date_i18n('D', strtotime($d))); ?></div>
                            <small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small>
                            <?php if ($d === $today): ?><em><?php esc_html_e('Today', 'workforce-one'); ?></em><?php endif; ?>
                            <div class="ews-att-day-meta"><?php echo esc_html(sprintf(/* translators: 1: people with a record, 2: people absent */ __('%1$d recorded · %2$d absent', 'workforce-one'), (int) $day_summary[$d]['recorded'], (int) $day_summary[$d]['absent'])); ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($emps as $e): $eid = (int) $e->id; ?>
                    <tr class="ews-att-employee-row <?php echo esc_attr(trim(($current_emp_id === $eid ? 'current-user ' : '') . (!empty($e->_schedule_team_manager) ? 'team-manager' : ''))); ?>" <?php echo $row_attrs($e); ?>>
                        <td class="ews-att-employee"><?php echo $avatar($e); ?><div><strong><?php echo $badges($e); ?></strong><small><?php echo esc_html($e->domain_name); ?></small><?php if (!empty($e->_schedule_primary_team)): ?><span class="ews-att-team-label"><?php echo esc_html($e->_schedule_primary_team); ?></span><?php endif; ?></div></td>
                        <?php foreach ($dates as $i => $d): $day = $days[$eid][$d]; ?>
                        <td class="ews-att-day <?php echo $d === $today ? 'is-today ' : ''; ?>ews-plan-<?php echo esc_attr(sanitize_title($day['planned'] ?: 'not-set')); ?>" data-status="<?php echo esc_attr($day['actual']); ?>" data-planned="<?php echo esc_attr($day['planned']); ?>">
                            <select name="att[<?php echo $eid; ?>][<?php echo (int) $i; ?>]" class="ews-att-cell ews-schedule-select" data-employee="<?php echo $eid; ?>" data-day="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr($e->name . ' · ' . date_i18n('D d M', strtotime($d))); ?>"><?php echo $status_options($day['planned']); ?></select>
                            <div class="ews-att-result <?php echo esc_attr($day['badge']['class']); ?>"><?php echo $result($day['badge'], 'small'); ?></div>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="ews-att-mobile">
            <?php foreach ($emps as $e): $eid = (int) $e->id; ?>
                <details class="ews-att-mobile-card <?php echo !empty($e->_schedule_team_manager) ? 'team-manager' : ''; ?> <?php echo $current_emp_id === $eid ? 'current-user' : ''; ?>" <?php echo $row_attrs($e); ?>>
                    <summary><?php echo $avatar($e); ?><div><strong><?php echo $badges($e); ?></strong><small><?php echo esc_html($e->domain_name); ?><?php if (!empty($e->_schedule_primary_team)): ?> · <?php echo esc_html($e->_schedule_primary_team); ?><?php endif; ?></small></div><span class="wfo-att-chev" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span></summary>
                    <div class="ews-att-mobile-days">
                    <?php foreach ($dates as $i => $d): $day = $days[$eid][$d]; ?>
                        <div class="ews-att-mobile-day <?php echo $d === $today ? 'is-today' : ''; ?>">
                            <header><b><?php echo esc_html(date_i18n('D', strtotime($d))); ?></b><small><?php echo esc_html(date_i18n('d M', strtotime($d))); ?></small></header>
                            <select name="mobile_att[<?php echo $eid; ?>][<?php echo (int) $i; ?>]" class="ews-mobile-att-cell ews-schedule-select" data-employee="<?php echo $eid; ?>" data-day="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr($e->name . ' · ' . date_i18n('D d M', strtotime($d))); ?>"><?php echo $status_options($day['planned']); ?></select>
                            <div class="ews-att-result <?php echo esc_attr($day['badge']['class']); ?>"><?php echo $result($day['badge'], 'dot'); ?></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="ews-att-savebar"><span><?php esc_html_e('Empty cells are not changed.', 'workforce-one'); ?></span><button type="submit" name="attendance_save" value="1" class="ews-btn ews-save-all"><?php echo Icons::svg('check', 17, 2.4); ?><?php esc_html_e('Save Schedule & Attendance', 'workforce-one'); ?></button></div>
    </form>

    <?php if ($preview_token !== '' && $preview_rows === null): ?>
    <div class="ews-notice ews-notice-error wfo-att-notice"><?php esc_html_e('The CSV preview has expired. Please upload the file again.', 'workforce-one'); ?></div>
    <?php elseif ($preview_rows !== null):
        $preview_valid = count(array_filter($preview_rows, static function ($r) { return !empty($r['valid']); }));
        $preview_invalid = count($preview_rows) - $preview_valid; ?>
    <section class="ews-csv-preview wfo-att-card">
        <h3><?php esc_html_e('CSV Preview', 'workforce-one'); ?></h3>
        <p><strong><?php echo (int) $preview_valid; ?></strong> row(s) ready to import<?php if ($preview_invalid): ?>, <strong class="wfo-att-bad"><?php echo (int) $preview_invalid; ?></strong> row(s) will be skipped<?php endif; ?>. <?php esc_html_e('Existing entries for the same employee and date will be replaced.', 'workforce-one'); ?></p>
        <div class="wfo-att-preview-scroll">
        <table class="ews-table ews-preview"><thead><tr><th><?php esc_html_e('Line', 'workforce-one'); ?></th><th><?php esc_html_e('Employee', 'workforce-one'); ?></th><th><?php esc_html_e('Date', 'workforce-one'); ?></th><th><?php esc_html_e('Status', 'workforce-one'); ?></th><th><?php esc_html_e('Note', 'workforce-one'); ?></th><th><?php esc_html_e('Result', 'workforce-one'); ?></th></tr></thead><tbody>
        <?php foreach ($preview_rows as $r): ?>
            <tr class="<?php echo empty($r['valid']) ? 'ews-invalid' : 'ews-valid'; ?>">
                <td><?php echo (int) $r['line']; ?></td>
                <td><?php echo esc_html($r['employee'] ?: $r['domain']); ?></td>
                <td><?php echo esc_html($r['normalized_date'] ?: $r['date']); ?></td>
                <td><?php echo esc_html($r['status']); ?></td>
                <td><?php echo esc_html($r['note']); ?></td>
                <td><?php if (empty($r['valid'])): ?><span class="ews-error wfo-att-bad"><?php echo Icons::svg('alert', 14, 2.2); ?><?php echo esc_html($r['error']); ?></span><?php else: ?><span class="wfo-att-ok"><?php echo Icons::svg('check', 14, 2.4); ?>OK</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <div class="wfo-att-preview-actions">
            <?php if ($preview_valid): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>">
                <input type="hidden" name="action" value="ews31_att_import"><?php wp_nonce_field('ews31_att_import'); ?>
                <input type="hidden" name="token" value="<?php echo esc_attr($preview_token); ?>">
                <button class="ews-btn wfo-att-btn"><?php echo Icons::svg('check', 16, 2.4); ?><?php echo esc_html(sprintf(/* translators: %d: number of rows */ __('Import %d row(s)', 'workforce-one'), $preview_valid)); ?></button>
            </form>
            <?php endif; ?>
            <a class="ews-btn secondary wfo-att-btn is-ghost" href="<?php echo esc_url($preview_cancel_url); ?>"><?php esc_html_e('Cancel', 'workforce-one'); ?></a>
        </div>
    </section>
    <?php endif; ?>

    <details class="ews-csv-details wfo-att-card">
        <summary><span class="wfo-att-csv-icon"><?php echo Icons::svg('reports', 20); ?></span><span class="wfo-att-csv-title"><strong><?php esc_html_e('Advanced: Bulk CSV Import', 'workforce-one'); ?></strong><span><?php esc_html_e('Use this when you have many rows from Excel.', 'workforce-one'); ?></span></span><span class="wfo-att-chev" aria-hidden="true"><?php echo Icons::svg('chevron', 18, 2.2); ?></span></summary>
        <div class="ews-csv-inside">
            <p><?php esc_html_e('Download the sample, fill it in Excel, upload it, review validation, then import.', 'workforce-one'); ?></p>
            <div class="ews-drop">
                <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ews31_att_preview"><?php wp_nonce_field('ews31_att_preview'); ?>
                    <label class="wfo-att-file"><?php echo Icons::svg('install', 18); ?><span><?php esc_html_e('Upload CSV', 'workforce-one'); ?></span><input type="file" name="attendance_csv" accept=".csv,text/csv" required></label>
                    <button class="ews-btn wfo-att-btn"><?php esc_html_e('Validate & Preview', 'workforce-one'); ?></button>
                </form>
            </div>
            <p><a class="ews-btn secondary wfo-att-btn is-ghost" href="<?php echo esc_url($sample_url); ?>"><?php echo Icons::svg('download', 16); ?><?php esc_html_e('Download Sample CSV', 'workforce-one'); ?></a></p>
        </div>
    </details>
</div>
