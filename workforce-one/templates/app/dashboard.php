<?php
/**
 * Employee app: Dashboard (Home). Managers ($manager) see the workforce snapshot of the employees
 * they manage; employees see their own day. The greeting is in the frame's header (layout.php).
 * Styles: assets/css/app-home.css (.wfo-home-*); the poll card keeps its own styles.
 *
 * Both:
 * @var bool $manager
 * @var int $unread
 * @var string $today_label
 * @var array<int,array{type?:string,icon:string,title:string,message:string}> $moments
 * @var string $poll_html                built by employee_poll_markup()
 * @var string $holiday                  today's company holiday ('' = none)
 * @var array<string,string> $urls       schedule, attendance, notifications, time
 * @var callable $empty                  ews_empty_state(title, text, url, label)
 * @var array<int,array<string,mixed>> $tiles  menu items the user may open (not the Dashboard), in menu order
 * @var string $notifications_label
 * Manager:
 * @var int $count
 * @var array{office:int,wfh:int,away:int} $snapshot
 * @var string[] $week_days
 * @var bool $can_attendance
 * Employee:
 * @var object|null $emp
 * @var string $first_name
 * @var string $status
 * @var bool $is_working
 * @var string $location
 * @var string $sign_in
 * @var string $sign_out
 * @var array{approved:string,actual:string,extra:string,sign_out:string}|null $overtime
 * @var array<int,array{date:string,status:string}> $upcoming
 * @var array<int,array<string,string>> $nudges
 * @var callable $dismiss_url
 */
if (!defined('ABSPATH')) exit;
use WorkforceOne\Ui\Icons;
// phpcs:disable WordPress.Security.EscapeOutput -- Icons::svg() is static markup; $poll_html and $empty() are built (and escaped) by the plugin.
$total = $manager ? (int) $count : 0;
$percent = static function (int $n) use ($total): int { return $total ? (int) min(100, round($n / $total * 100)) : 0; };
$status_label = static function (string $s): string {
    if ($s === 'Not Set') return __('Not Set', 'workforce-one');
    if ($s === 'General Leave') return __('General Leave', 'workforce-one');
    return $s;
};
$chip = static function (string $s) use ($status_label): string {
    [$icon, $tone] = Icons::forStatus($s);
    return '<span class="wfo-chip is-' . esc_attr($tone) . '">' . Icons::svg($icon, 14, 2) . esc_html($status_label($s)) . '</span>';
};
$tile_tones = ['time' => 'green', 'schedule' => 'blue', 'vacation' => 'purple', 'overtime' => 'amber', 'tasks' => 'teal', 'polls' => 'rose', 'pay' => 'lime', 'reports' => 'slate', 'attendance' => 'green', 'people' => 'blue', 'attendance-insights' => 'purple'];
$tiles_html = '';
foreach ($tiles as $t) {
    $tiles_html .= '<a class="wfo-tile" href="' . esc_url($t['url']) . '"><span class="wfo-tile-icon is-' . esc_attr($tile_tones[$t['key']] ?? 'slate') . '">' . Icons::forView($t['key'], 24) . '</span><span class="wfo-tile-label">' . esc_html($t['desktop_label']) . '</span></a>';
}
$tiles_html .= '<a class="wfo-tile" href="' . esc_url($urls['notifications']) . '"><span class="wfo-tile-icon is-slate">' . Icons::svg('bell', 24) . ($unread ? '<b class="wfo-badge">' . absint($unread) . '</b>' : '') . '</span><span class="wfo-tile-label">' . esc_html($notifications_label) . '</span></a>';
$moment_icons = ['birthday' => 'gift', 'anniversary' => 'sparkle', 'welcome' => 'user'];
$nudge_icons = ['attendance' => 'clock', 'tasks' => 'tasks', 'leave' => 'leave', 'schedule' => 'calendar'];
?>
<?php if ($manager || $emp): ?>
<div class="wfo-home<?php echo $manager ? ' is-manager' : ' is-employee'; ?>">

    <?php if ($holiday !== ''): ?>
    <div class="wfo-home-holiday" role="status"><?php echo Icons::svg('sun', 20); ?><span><strong><?php echo esc_html($holiday); ?></strong> · <?php esc_html_e('General Leave', 'workforce-one'); ?></span></div>
    <?php endif; ?>

    <?php if ($manager): ?>
    <section class="wfo-home-kpis" aria-label="<?php esc_attr_e('Today in numbers', 'workforce-one'); ?>">
        <div class="wfo-kpi"><span class="wfo-kpi-icon is-purple"><?php echo Icons::svg('people', 22); ?></span><div><div class="n"><?php echo (int) $count; ?></div><div class="l"><?php esc_html_e('Active Employees', 'workforce-one'); ?></div></div></div>
        <div class="wfo-kpi"><span class="wfo-kpi-icon is-green"><?php echo Icons::svg('office', 22); ?></span><div><div class="n"><?php echo (int) $snapshot['office']; ?></div><div class="l"><?php esc_html_e('Office Today', 'workforce-one'); ?></div></div></div>
        <div class="wfo-kpi"><span class="wfo-kpi-icon is-blue"><?php echo Icons::svg('wfh', 22); ?></span><div><div class="n"><?php echo (int) $snapshot['wfh']; ?></div><div class="l"><?php esc_html_e('WFH Today', 'workforce-one'); ?></div></div></div>
        <div class="wfo-kpi"><span class="wfo-kpi-icon is-amber"><?php echo Icons::svg('briefcase', 22); ?></span><div><div class="n"><?php echo (int) $snapshot['away']; ?></div><div class="l"><?php esc_html_e('Leave / Mission', 'workforce-one'); ?></div></div></div>
    </section>
    <?php else: ?>
    <section class="wfo-home-today" aria-label="<?php esc_attr_e('Today', 'workforce-one'); ?>">
        <div class="wfo-home-today-head">
            <div>
                <span class="wfo-kicker"><?php esc_html_e('TODAY\'S SCHEDULE', 'workforce-one'); ?></span>
                <h2 class="wfo-home-status"><?php echo esc_html($status_label($status)); ?></h2>
                <p class="wfo-muted"><?php echo Icons::svg('pin', 16); ?><?php echo esc_html($location !== '' ? $location : __('Default location', 'workforce-one')); ?></p>
            </div>
            <span class="wfo-chip <?php echo $is_working ? 'is-office' : 'is-none'; ?>"><?php echo Icons::svg($is_working ? 'check' : 'sun', 14, 2.2); ?><?php echo esc_html($is_working ? __('Working day', 'workforce-one') : __('No work scheduled', 'workforce-one')); ?></span>
        </div>
        <div class="wfo-home-times">
            <div><span><?php esc_html_e('Sign In', 'workforce-one'); ?></span><b><?php echo esc_html($sign_in !== '' ? $sign_in : __('Not recorded', 'workforce-one')); ?></b></div>
            <div><span><?php esc_html_e('Sign Out', 'workforce-one'); ?></span><b><?php echo esc_html($sign_out !== '' ? $sign_out : __('Not recorded', 'workforce-one')); ?></b></div>
            <div><span><?php esc_html_e('Work Location', 'workforce-one'); ?></span><b><?php echo esc_html($location !== '' ? $location : __('Default', 'workforce-one')); ?></b></div>
        </div>
        <a class="wfo-home-action" href="<?php echo esc_url($urls['time']); ?>"><?php echo Icons::svg($sign_in !== '' ? 'clock' : 'login', 20, 2); ?><?php echo esc_html($sign_in !== '' ? __('View Attendance', 'workforce-one') : __('Sign In / Out', 'workforce-one')); ?></a>
    </section>

    <?php if ($nudges): ?>
    <section class="wfo-home-nudges" aria-label="<?php esc_attr_e('Things that need your attention', 'workforce-one'); ?>">
        <?php foreach ($nudges as $n): ?>
        <div class="wfo-nudge" data-kind="<?php echo esc_attr($n['kind']); ?>">
            <span class="wfo-nudge-icon"><?php echo Icons::svg($nudge_icons[$n['kind']] ?? 'alert', 20); ?></span>
            <div class="wfo-nudge-body"><strong><?php echo esc_html($n['title']); ?></strong><p><?php echo esc_html($n['message']); ?></p></div>
            <div class="wfo-nudge-actions"><a class="wfo-btn-sm" href="<?php echo esc_url($n['url']); ?>"><?php echo esc_html($n['action']); ?></a><a class="wfo-nudge-dismiss" href="<?php echo esc_url($dismiss_url($n['id'])); ?>"><?php esc_html_e('Dismiss', 'workforce-one'); ?></a></div>
        </div>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <section class="wfo-card wfo-home-tiles" aria-label="<?php esc_attr_e('Quick Actions', 'workforce-one'); ?>"><?php echo $tiles_html; ?></section>

    <?php if ($moments): ?>
    <section class="wfo-card wfo-home-moments">
        <div class="wfo-card-head"><h3><?php esc_html_e('Today\'s Moments', 'workforce-one'); ?></h3><p class="wfo-muted"><?php esc_html_e('A little celebration for the team.', 'workforce-one'); ?></p></div>
        <div class="wfo-moments">
            <?php foreach ($moments as $m): ?><div class="wfo-moment"><span class="wfo-moment-icon"><?php echo Icons::svg($moment_icons[$m['type'] ?? ''] ?? 'sparkle', 20); ?></span><div><strong><?php echo esc_html($m['title']); ?></strong><p><?php echo esc_html($m['message']); ?></p></div></div><?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($manager): ?>
    <div class="wfo-home-grid">
        <section class="wfo-card wfo-home-glance">
            <div class="wfo-card-head"><h3><?php esc_html_e('Today at a glance', 'workforce-one'); ?></h3><p class="wfo-muted"><?php esc_html_e('Current schedule distribution', 'workforce-one'); ?></p></div>
            <div class="wfo-bars">
                <?php foreach ([['office', __('Office', 'workforce-one'), 'office', 'green'], ['wfh', __('WFH', 'workforce-one'), 'wfh', 'blue'], ['away', __('Leave / Mission', 'workforce-one'), 'briefcase', 'amber']] as [$key, $label, $icon, $tone]): ?>
                <div class="wfo-bar"><div class="wfo-bar-top"><span><?php echo Icons::svg($icon, 16); ?><?php echo esc_html($label); ?></span><b><?php echo (int) $snapshot[$key]; ?></b></div><i role="img" aria-label="<?php echo esc_attr($percent((int) $snapshot[$key]) . '%'); ?>"><em class="is-<?php echo esc_attr($tone); ?>" style="width:<?php echo $percent((int) $snapshot[$key]); ?>%"></em></i></div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="wfo-card wfo-home-week">
            <div class="wfo-card-head"><h3><?php esc_html_e('This Week', 'workforce-one'); ?></h3><p class="wfo-muted"><?php esc_html_e('Plan your week', 'workforce-one'); ?> · <?php esc_html_e('Review schedules and keep your team aligned.', 'workforce-one'); ?></p></div>
            <?php if ($week_days): ?><div class="wfo-weekdays"><?php foreach ($week_days as $wd): ?><span><?php echo esc_html($wd); ?></span><?php endforeach; ?></div><?php else: ?><p class="wfo-muted"><?php esc_html_e('No working days configured', 'workforce-one'); ?></p><?php endif; ?>
            <a class="wfo-home-action" href="<?php echo esc_url($urls['schedule']); ?>"><?php echo Icons::svg('calendar', 20); ?><?php esc_html_e('View Schedule', 'workforce-one'); ?></a>
        </section>
    </div>
    <?php else: ?>
    <div class="wfo-home-grid">
        <?php if ($overtime): ?>
        <section class="wfo-card wfo-home-overtime">
            <div class="wfo-card-head"><h3><?php esc_html_e('Overtime Today', 'workforce-one'); ?></h3><p class="wfo-muted"><?php esc_html_e('Approved overtime and actual attendance', 'workforce-one'); ?></p></div>
            <div class="wfo-home-times">
                <div><span><?php esc_html_e('Approved OT', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['approved']); ?></b></div>
                <div><span><?php esc_html_e('Actual OT', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['actual']); ?></b></div>
                <?php if ($overtime['extra'] !== ''): ?><div><span><?php esc_html_e('Unapproved Extra', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['extra']); ?></b></div><?php endif; ?>
            </div>
            <?php if ($overtime['sign_out'] !== ''): ?><p class="wfo-muted"><?php esc_html_e('Sign Out:', 'workforce-one'); ?> <strong><?php echo esc_html($overtime['sign_out']); ?></strong></p><?php endif; ?>
        </section>
        <?php endif; ?>
        <section class="wfo-card wfo-home-week">
            <div class="wfo-card-head wfo-card-head-row"><div><h3><?php esc_html_e('My Week', 'workforce-one'); ?></h3><p class="wfo-muted"><?php esc_html_e('Your upcoming schedule', 'workforce-one'); ?></p></div><a class="wfo-link" href="<?php echo esc_url($urls['schedule']); ?>"><?php esc_html_e('View full schedule', 'workforce-one'); ?></a></div>
            <?php if ($upcoming): ?>
                <ul class="wfo-week-list"><?php foreach ($upcoming as $u): ?><li data-status="<?php echo esc_attr($u['status']); ?>"><span><?php echo esc_html($u['date']); ?></span><?php echo $chip($u['status']); ?></li><?php endforeach; ?></ul>
            <?php else: echo $empty(__('No Upcoming Schedule', 'workforce-one'), __('No schedule has been set for your upcoming days.', 'workforce-one'), $urls['schedule'], __('View Schedule', 'workforce-one')); ?>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <?php echo $poll_html; ?>
</div>
<?php else: ?>
<div class="wfo-home"><section class="wfo-card wfo-home-empty"><span class="wfo-home-empty-icon"><?php echo Icons::svg('user', 28); ?></span><h2><?php esc_html_e('Employee account not linked', 'workforce-one'); ?></h2><p><?php esc_html_e('Your WordPress account is not linked to an active employee record. Please contact your manager.', 'workforce-one'); ?></p></section></div>
<?php endif; ?>
