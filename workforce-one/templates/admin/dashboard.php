<?php
/**
 * wp-admin home: today's attendance.
 *
 * @var string $today_label
 * @var string[] $holidays                     today's company holidays (nobody is expected)
 * @var array<int,array{name:string,domain:string,schedule:string,status:string,label:string}> $rows
 * @var array{sign_in:int,late_arrival:int,no_show:int} $counts
 * @var string $report_url
 * @var array<string,string> $links
 */
if (!defined('ABSPATH')) exit;
$cards = [['Sign In', $counts['sign_in'], 'Normal sign-in recorded', '#2271b1'], ['Late Arrival', $counts['late_arrival'], 'Arrived after grace period', '#dba617'],
    ['No Show', $counts['no_show'], 'Expected today but no sign-in yet', '#d63638'], ['Scheduled Today', count($rows), 'Employees expected to sign in', '#50575e']];
?>
<div class="wrap"><h1>Employee Schedule Dashboard</h1>
<p><strong>Today:</strong> <?php echo esc_html($today_label); ?> &nbsp; <strong>Expected to sign in:</strong> <?php echo esc_html(number_format_i18n(count($rows))); ?></p>
<?php if ($holidays): ?><div class="notice notice-info inline"><p>Today is a company holiday: <strong><?php echo esc_html(implode(', ', $holidays)); ?></strong>. Nobody is expected to sign in.</p></div><?php endif; ?>
<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">
<?php foreach ($cards as [$title, $number, $subtitle, $color]): ?>
    <div style="background:#fff;border:1px solid #dcdcde;border-left:5px solid <?php echo esc_attr($color); ?>;border-radius:6px;padding:18px;min-width:210px;flex:1;box-sizing:border-box"><div style="font-size:32px;font-weight:700;line-height:1.1"><?php echo (int) $number; ?></div><div style="font-size:15px;font-weight:600;margin-top:6px"><?php echo esc_html($title); ?></div><div style="font-size:12px;color:#667085;margin-top:4px"><?php echo esc_html($subtitle); ?></div><p style="margin:12px 0 0"><a href="<?php echo esc_url($report_url); ?>">View details</a></p></div>
<?php endforeach; ?>
</div>
<div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin-top:18px"><h2 style="margin-top:0">Today Attendance Status</h2>
<?php if (!$rows): ?>
    <p><strong>Nobody is expected to sign in today.</strong> Employees are expected when today's schedule type requires Sign In, attendance tracking is on for them, and today is not a company holiday.</p>
<?php else: ?>
    <table class="widefat striped"><thead><tr><th>Employee</th><th>Domain</th><th>Schedule</th><th>Attendance</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?php echo esc_html($r['name']); ?></td><td><?php echo esc_html($r['domain']); ?></td><td><?php echo esc_html($r['schedule']); ?></td><td><strong><?php echo esc_html($r['label']); ?></strong></td></tr><?php endforeach; ?>
    </tbody></table>
<?php endif; ?>
</div>
<p style="margin-top:18px"><?php $first = true; foreach ($links as $label => $url): ?><a class="button<?php echo $first ? ' button-primary' : ''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a> <?php $first = false; endforeach; ?><a class="button" href="<?php echo esc_url($report_url); ?>">Today Sign In / Out Report</a></p>
</div>
