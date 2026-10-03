<?php
/**
 * Employee app: manager Attendance grid. Script: assets/js/attendance-grid.js (search / team
 * filter, "Set…" for a whole day, week picker, and the changed-cells-only submit).
 *
 * @var object[] $emps                  ordered by TeamOrder, annotated with _schedule_* fields
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
$badges = static function (object $e) use ($current_emp_id): string {
    $html = esc_html($e->name);
    if ($current_emp_id === (int) $e->id) $html .= '<span class="ews-me-badge">You</span>';
    if (!empty($e->_schedule_team_manager)) $html .= '<span class="ews-manager-badge">Manager</span>';
    return $html;
};
$row_attrs = static function (object $e): string {
    return 'data-employee-name="' . esc_attr(strtolower($e->name . ' ' . $e->domain_name)) . '" data-team="' . esc_attr(strtolower(implode('|', (array) ($e->_schedule_team_names ?? [])))) . '"';
};
$status_options = static function (string $planned) use ($statuses): string {
    $html = '<option value="">Not Set</option>';
    foreach ($statuses as $s) $html .= '<option value="' . esc_attr($s) . '"' . selected($planned, $s, false) . '>' . esc_html($s) . '</option>';
    return $html;
};
?>
<?php if ($message): ?><div class="ews-notice<?php echo $message['error'] ? ' ews-notice-error' : ''; ?>"><?php echo esc_html($message['text']); ?></div><?php endif; ?>
<div class="ews-attendance-page">
    <div class="ews-att-head">
        <div>
            <div class="ews-dash-kicker">MANAGER CONTROL CENTER</div>
            <h1>Attendance</h1>
            <p>Manage your team schedule and see attendance results in one place.</p>
        </div>
        <div class="ews-att-week-nav">
            <a class="ews-week-arrow" href="<?php echo esc_url($prev_url); ?>" aria-label="Previous week">‹</a>
            <div class="ews-att-week-control">
                <form method="get" class="ews-att-week-form">
                    <input type="hidden" name="ews_view" value="attendance">
                    <label class="ews-att-week-range" title="Choose a date">
                        <span>📅</span>
                        <strong><?php echo esc_html($range); ?></strong>
                        <input type="date" name="week" value="<?php echo esc_attr($dates[0]); ?>" data-ews-autosubmit aria-label="Choose week">
                    </label>
                </form>
            </div>
            <a class="ews-week-arrow" href="<?php echo esc_url($next_url); ?>" aria-label="Next week">›</a>
        </div>
    </div>

    <div class="ews-att-summary">
        <div class="ews-att-stat people"><span>👥</span><div><b><?php echo count($emps); ?></b><small>Employees</small></div></div>
        <div class="ews-att-stat present"><span>✓</span><div><b><?php echo (int) $summary['Present']; ?></b><small>Present days</small></div></div>
        <div class="ews-att-stat late"><span>◷</span><div><b><?php echo (int) $summary['Late']; ?></b><small>Late days</small></div></div>
        <div class="ews-att-stat absent"><span>×</span><div><b><?php echo (int) $summary['Absent']; ?></b><small>Absent days</small></div></div>
        <div class="ews-att-stat rate"><span>%</span><div><b><?php echo (int) $rate; ?>%</b><small>Attendance rate</small></div></div>
    </div>

    <div class="ews-att-controls">
        <div class="ews-att-control-head">
            <div class="ews-att-control-title"><strong>Schedule Controls</strong><span>Set the planned schedule for everyone on a specific day.</span></div>
            <div class="ews-att-tools">
                <label class="ews-att-search"><span>⌕</span><input type="search" id="ews-att-search" placeholder="Search employee..." autocomplete="off"></label>
                <label class="ews-att-filter"><span>Team</span><select id="ews-att-team">
                    <option value="all">All teams</option>
                    <?php foreach ($team_options as $team_name): ?><option value="<?php echo esc_attr($team_name); ?>"><?php echo esc_html($team_name); ?></option><?php endforeach; ?>
                </select></label>
            </div>
        </div>
        <div class="ews-day-quick-actions">
            <?php foreach ($dates as $i => $d): ?>
                <div class="ews-day-quick <?php echo $d === $today ? 'is-today' : ''; ?>">
                    <label><?php echo esc_html(date('D', strtotime($d))); ?><small><?php echo esc_html(date('d M', strtotime($d))); ?></small></label>
                    <select data-ews-fill-day="<?php echo (int) $i; ?>">
                        <option value="">Set…</option>
                        <?php foreach ($statuses as $s): ?><option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="ews-att-plan-legend"><span><i class="office"></i> Office</span><span><i class="wfh"></i> WFH</span><span><i class="leave"></i> Leave</span><span><i class="trip"></i> Business Trip</span></div>
    </div>

    <form method="post" action="<?php echo esc_url($post_url); ?>" id="ews-grid-form">
        <input type="hidden" name="action" value="ews31_att_grid_save">
        <input type="hidden" name="week" value="<?php echo esc_attr($week); ?>">
        <input type="hidden" name="attendance_client" value="desktop">
        <input type="hidden" name="att_changes_json" id="ews-att-changes-json" value="">
        <?php wp_nonce_field('ews31_att_grid_save'); ?>

        <div class="ews-att-table-wrap">
            <table class="ews-att-table">
                <thead><tr>
                    <th class="employee-col">Employee</th>
                    <?php foreach ($dates as $d): ?>
                        <th class="<?php echo $d === $today ? 'is-today' : ''; ?>">
                            <div class="ews-att-day-name"><?php echo esc_html(date('D', strtotime($d))); ?></div>
                            <small><?php echo esc_html(date('d M', strtotime($d))); ?></small>
                            <?php if ($d === $today): ?><em>Today</em><?php endif; ?>
                            <div class="ews-att-day-meta"><b><?php echo (int) $day_summary[$d]['recorded']; ?></b> recorded · <b><?php echo (int) $day_summary[$d]['absent']; ?></b> absent</div>
                        </th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($emps as $e): $eid = (int) $e->id; ?>
                    <tr class="ews-att-employee-row <?php echo esc_attr(trim(($current_emp_id === $eid ? 'current-user ' : '') . (!empty($e->_schedule_team_manager) ? 'team-manager' : ''))); ?>" <?php echo $row_attrs($e); ?>>
                        <td class="ews-att-employee"><div class="ews-att-avatar"><?php echo esc_html(strtoupper(substr(trim($e->name), 0, 1))); ?></div><div><strong><?php echo $badges($e); ?></strong><small><?php echo esc_html($e->domain_name); ?></small><?php if (!empty($e->_schedule_primary_team)): ?><span class="ews-att-team-label"><?php echo esc_html($e->_schedule_primary_team); ?></span><?php endif; ?></div></td>
                        <?php foreach ($dates as $i => $d): $day = $days[$eid][$d]; ?>
                        <td class="ews-att-day <?php echo $d === $today ? 'is-today ' : ''; ?>ews-plan-<?php echo esc_attr(sanitize_title($day['planned'] ?: 'not-set')); ?>" data-status="<?php echo esc_attr($day['actual']); ?>" data-planned="<?php echo esc_attr($day['planned']); ?>">
                            <select name="att[<?php echo $eid; ?>][<?php echo (int) $i; ?>]" class="ews-att-cell ews-schedule-select" data-employee="<?php echo $eid; ?>" data-day="<?php echo (int) $i; ?>"><?php echo $status_options($day['planned']); ?></select>
                            <div class="ews-att-result <?php echo esc_attr($day['badge']['class']); ?>"><?php echo esc_html($day['badge']['label']); ?><?php if ($day['badge']['detail'] !== ''): ?><small><?php echo esc_html($day['badge']['detail']); ?></small><?php endif; ?></div>
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
                    <summary><div class="ews-att-avatar"><?php echo esc_html(strtoupper(substr(trim($e->name), 0, 1))); ?></div><div><strong><?php echo $badges($e); ?></strong><small><?php echo esc_html($e->domain_name); ?><?php if (!empty($e->_schedule_primary_team)): ?> · <?php echo esc_html($e->_schedule_primary_team); ?><?php endif; ?></small></div><span>›</span></summary>
                    <div class="ews-att-mobile-days">
                    <?php foreach ($dates as $i => $d): $day = $days[$eid][$d]; ?>
                        <div class="ews-att-mobile-day <?php echo $d === $today ? 'is-today' : ''; ?>">
                            <header><b><?php echo esc_html(date('D', strtotime($d))); ?></b><small><?php echo esc_html(date('d M', strtotime($d))); ?></small></header>
                            <select name="mobile_att[<?php echo $eid; ?>][<?php echo (int) $i; ?>]" class="ews-mobile-att-cell ews-schedule-select" data-employee="<?php echo $eid; ?>" data-day="<?php echo (int) $i; ?>"><?php echo $status_options($day['planned']); ?></select>
                            <div class="ews-att-result <?php echo esc_attr($day['badge']['class']); ?>"><?php echo esc_html($day['badge']['label']); ?><?php if ($day['badge']['detail'] !== ''): ?> · <?php echo esc_html($day['badge']['detail']); ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>

        <div class="ews-att-savebar"><span>Empty cells are not changed.</span><button type="submit" name="attendance_save" value="1" class="ews-btn ews-save-all">✓ Save Schedule & Attendance</button></div>
    </form>

    <?php if ($preview_token !== '' && $preview_rows === null): ?>
    <div class="ews-notice ews-notice-error">The CSV preview has expired. Please upload the file again.</div>
    <?php elseif ($preview_rows !== null):
        $preview_valid = count(array_filter($preview_rows, static function ($r) { return !empty($r['valid']); }));
        $preview_invalid = count($preview_rows) - $preview_valid; ?>
    <div class="ews-card ews-csv-preview">
        <h3 style="margin-top:0">CSV Preview</h3>
        <p><strong><?php echo (int) $preview_valid; ?></strong> row(s) ready to import<?php if ($preview_invalid): ?>, <strong style="color:#b42318"><?php echo (int) $preview_invalid; ?></strong> row(s) will be skipped<?php endif; ?>. Existing entries for the same employee and date will be replaced.</p>
        <div style="overflow:auto;max-height:420px">
        <table class="ews-table ews-preview"><thead><tr><th>Line</th><th>Employee</th><th>Date</th><th>Status</th><th>Note</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($preview_rows as $r): ?>
            <tr class="<?php echo empty($r['valid']) ? 'ews-invalid' : 'ews-valid'; ?>">
                <td><?php echo (int) $r['line']; ?></td>
                <td><?php echo esc_html($r['employee'] ?: $r['domain']); ?></td>
                <td><?php echo esc_html($r['normalized_date'] ?: $r['date']); ?></td>
                <td><?php echo esc_html($r['status']); ?></td>
                <td><?php echo esc_html($r['note']); ?></td>
                <td><?php if (empty($r['valid'])): ?><span class="ews-error">✕ <?php echo esc_html($r['error']); ?></span><?php else: ?><span style="color:#067647">✓ OK</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        </div>
        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
            <?php if ($preview_valid): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>">
                <input type="hidden" name="action" value="ews31_att_import"><?php wp_nonce_field('ews31_att_import'); ?>
                <input type="hidden" name="token" value="<?php echo esc_attr($preview_token); ?>">
                <button class="ews-btn">✓ Import <?php echo (int) $preview_valid; ?> row(s)</button>
            </form>
            <?php endif; ?>
            <a class="ews-btn secondary" href="<?php echo esc_url($preview_cancel_url); ?>">Cancel</a>
        </div>
    </div>
    <?php endif; ?>

    <details class="ews-card ews-csv-details">
        <summary><strong>Advanced: Bulk CSV Import</strong><span>Use this when you have many rows from Excel.</span></summary>
        <div class="ews-csv-inside">
            <p>Download the sample, fill it in Excel, upload it, review validation, then import.</p>
            <div class="ews-drop">📄 <b>Upload CSV</b>
                <form method="post" action="<?php echo esc_url($post_url); ?>" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="ews31_att_preview"><?php wp_nonce_field('ews31_att_preview'); ?>
                    <input type="file" name="attendance_csv" accept=".csv,text/csv" required>
                    <button class="ews-btn">Validate & Preview</button>
                </form>
            </div>
            <p><a class="ews-btn secondary" href="<?php echo esc_url($sample_url); ?>">Download Sample CSV</a></p>
        </div>
    </details>
</div>
