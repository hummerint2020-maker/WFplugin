<?php
/**
 * Employee app: Dashboard. Managers ($manager) see the workforce snapshot of the employees they
 * manage; employees see their own day. Styles: assets/css/workforce-one.css (.ews-dash-*).
 *
 * Both:
 * @var bool $manager
 * @var int $unread
 * @var string $today_label
 * @var array<int,array{icon:string,title:string,message:string}> $moments
 * @var string $poll_html                built by employee_poll_markup()
 * @var string $holiday                  today's company holiday ('' = none)
 * @var array<string,string> $urls       schedule, attendance, notifications, time
 * @var callable $empty                  ews_empty_state(title, text, url, label)
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
$total = $manager ? (int) $count : 0;
$percent = static function (int $n) use ($total): int { return $total ? (int) min(100, round($n / $total * 100)) : 0; };
$notifications_link = '<a href="' . esc_url($urls['notifications']) . '"><span>🔔</span> ' . esc_html__('Notifications', 'workforce-one') . ($unread ? '<b>' . absint($unread) . '</b>' : '') . '</a>';
?>
<?php if ($manager || $emp): ?>
<div class="ews-dashboard<?php echo $manager ? '' : ' ews-employee-dashboard'; ?>">
    <div class="ews-dash-welcome">
        <div><div class="ews-dash-kicker"><?php esc_html_e('BA Team · Workforce One', 'workforce-one'); ?></div>
            <?php if ($manager): ?>
                <h2><?php esc_html_e('Good to see you.', 'workforce-one'); ?></h2><p><?php echo esc_html($today_label); ?> · <?php esc_html_e('Here’s today’s workforce snapshot.', 'workforce-one'); ?></p>
            <?php else: ?>
                <?php /* translators: %s: employee first name */ ?>
                <h2><?php echo esc_html(sprintf(__('Good to see you, %s.', 'workforce-one'), $first_name)); ?></h2><p><?php echo esc_html($today_label); ?> · <?php esc_html_e('Here’s your day at a glance.', 'workforce-one'); ?></p>
            <?php endif; ?>
            <?php if ($holiday !== ''): ?><p><strong>🎉 <?php echo esc_html($holiday); ?></strong> · <?php esc_html_e('General Leave', 'workforce-one'); ?></p><?php endif; ?>
        </div>
    </div>

    <?php if ($manager): ?>
    <div class="ews-dash-stats">
        <div class="ews-dash-stat"><div class="ews-dash-stat-icon purple">👥</div><div><div class="n"><?php echo (int) $count; ?></div><div class="l"><?php esc_html_e('Active Employees', 'workforce-one'); ?></div></div></div>
        <div class="ews-dash-stat"><div class="ews-dash-stat-icon green">🏢</div><div><div class="n"><?php echo (int) $snapshot['office']; ?></div><div class="l"><?php esc_html_e('Office Today', 'workforce-one'); ?></div></div></div>
        <div class="ews-dash-stat"><div class="ews-dash-stat-icon blue">🏠</div><div><div class="n"><?php echo (int) $snapshot['wfh']; ?></div><div class="l"><?php esc_html_e('WFH Today', 'workforce-one'); ?></div></div></div>
        <div class="ews-dash-stat"><div class="ews-dash-stat-icon orange">✈</div><div><div class="n"><?php echo (int) $snapshot['away']; ?></div><div class="l"><?php esc_html_e('Leave / Mission', 'workforce-one'); ?></div></div></div>
    </div>
    <?php endif; ?>

    <?php if ($moments): ?>
    <div class="ews-dash-card ews-moments-card">
        <div class="ews-dash-card-head"><div><h3>✨ Today's Moments</h3><p>A little celebration for the team.</p></div><span>🎊</span></div>
        <div class="ews-moments-list">
            <?php foreach ($moments as $m): ?><div class="ews-moment-item"><div class="ews-moment-icon"><?php echo esc_html($m['icon']); ?></div><div><strong><?php echo esc_html($m['title']); ?></strong><p><?php echo esc_html($m['message']); ?></p></div></div><?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php echo $poll_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by employee_poll_markup() ?>

    <?php if ($manager): ?>
    <div class="ews-dash-grid">
        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('This Week', 'workforce-one'); ?></h3><p><?php echo esc_html($week_days ? implode(' → ', $week_days) : __('No working days configured', 'workforce-one')); ?></p></div><span>📅</span></div>
            <div class="ews-dash-week-line"><div><strong><?php esc_html_e('Plan your week', 'workforce-one'); ?></strong><small><?php esc_html_e('Review schedules and keep your team aligned.', 'workforce-one'); ?></small></div><a class="ews-btn" href="<?php echo esc_url($urls['schedule']); ?>"><?php esc_html_e('View Schedule', 'workforce-one'); ?></a></div>
        </div>
        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('Quick Actions', 'workforce-one'); ?></h3><p><?php esc_html_e('Common tasks', 'workforce-one'); ?></p></div><span>⚡</span></div>
            <div class="ews-dash-actions"><a href="<?php echo esc_url($urls['schedule']); ?>"><span>📅</span> <?php esc_html_e('Schedule', 'workforce-one'); ?></a><?php if ($can_attendance): ?><a href="<?php echo esc_url($urls['attendance']); ?>"><span>📝</span> <?php esc_html_e('Attendance', 'workforce-one'); ?></a><?php endif; ?><?php echo $notifications_link; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></div>
        </div>
    </div>
    <div class="ews-dash-card ews-dash-status"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('Today at a glance', 'workforce-one'); ?></h3><p><?php esc_html_e('Current schedule distribution', 'workforce-one'); ?></p></div><span>✓</span></div>
        <div class="ews-dash-bars">
            <?php foreach ([['office', __('Office', 'workforce-one'), ''], ['wfh', __('WFH', 'workforce-one'), 'blue'], ['away', __('Leave / Mission', 'workforce-one'), 'orange']] as [$key, $label, $color]): ?>
            <div><div><span><?php echo esc_html($label); ?></span><b><?php echo (int) $snapshot[$key]; ?></b></div><i><em<?php echo $color ? ' class="' . esc_attr($color) . '"' : ''; ?> style="width:<?php echo $percent((int) $snapshot[$key]); ?>%"></em></i></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php else: ?>
    <?php if ($nudges): ?>
    <div class="ews-smart-nudges-card">
        <div class="ews-smart-nudges-head"><div><span class="ews-personal-label">SMART NUDGES</span><h3>Things that need your attention</h3></div><span>🔔</span></div>
        <div class="ews-smart-nudges-list">
            <?php foreach ($nudges as $n): ?>
            <div class="ews-smart-nudge ews-smart-nudge-<?php echo esc_attr($n['kind']); ?>">
                <div class="ews-smart-nudge-icon"><?php echo esc_html($n['icon']); ?></div>
                <div class="ews-smart-nudge-body"><strong><?php echo esc_html($n['title']); ?></strong><p><?php echo esc_html($n['message']); ?></p><div class="ews-smart-nudge-actions"><a class="ews-btn" href="<?php echo esc_url($n['url']); ?>"><?php echo esc_html($n['action']); ?></a><a class="ews-smart-nudge-dismiss" href="<?php echo esc_url($dismiss_url($n['id'])); ?>">Dismiss</a></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="ews-employee-today">
        <div class="ews-employee-today-head">
            <div><span class="ews-personal-label"><?php esc_html_e('TODAY\'S SCHEDULE', 'workforce-one'); ?></span><h3><?php echo esc_html($status === 'Not Set' ? __('Not Set', 'workforce-one') : ($status === 'General Leave' ? __('General Leave', 'workforce-one') : $status)); ?></h3><p><?php echo esc_html($location !== '' ? $location : __('Default location', 'workforce-one')); ?></p></div>
            <span class="ews-personal-status <?php echo $is_working ? 'working' : 'neutral'; ?>"><?php echo esc_html($is_working ? __('Working day', 'workforce-one') : __('No work scheduled', 'workforce-one')); ?></span>
        </div>
        <div class="ews-personal-meta">
            <div><span><?php esc_html_e('Work Location', 'workforce-one'); ?></span><b><?php echo esc_html($location !== '' ? $location : __('Default', 'workforce-one')); ?></b></div>
            <div><span><?php esc_html_e('Sign In', 'workforce-one'); ?></span><b><?php echo esc_html($sign_in !== '' ? $sign_in : __('Not recorded', 'workforce-one')); ?></b></div>
            <div><span><?php esc_html_e('Sign Out', 'workforce-one'); ?></span><b><?php echo esc_html($sign_out !== '' ? $sign_out : __('Not recorded', 'workforce-one')); ?></b></div>
        </div>
        <a class="ews-btn ews-personal-action" href="<?php echo esc_url($urls['time']); ?>"><?php echo esc_html($sign_in !== '' ? __('View Attendance', 'workforce-one') : __('Sign In / Out', 'workforce-one')); ?></a>
    </div>

    <div class="ews-dash-grid">
        <?php if ($overtime): ?>
        <div class="ews-dash-card ews-overtime-attendance-card">
            <div class="ews-dash-card-head"><div><h3><?php esc_html_e('Overtime Today', 'workforce-one'); ?></h3><p><?php esc_html_e('Approved overtime and actual attendance', 'workforce-one'); ?></p></div><span>⏱️</span></div>
            <div class="ews-personal-meta">
                <div><span><?php esc_html_e('Approved OT', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['approved']); ?></b></div>
                <div><span><?php esc_html_e('Actual OT', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['actual']); ?></b></div>
                <?php if ($overtime['extra'] !== ''): ?><div><span><?php esc_html_e('Unapproved Extra', 'workforce-one'); ?></span><b><?php echo esc_html($overtime['extra']); ?></b></div><?php endif; ?>
            </div>
            <?php if ($overtime['sign_out'] !== ''): ?><div style="margin-top:10px;color:#667085;font-size:13px"><?php esc_html_e('Sign Out:', 'workforce-one'); ?> <strong><?php echo esc_html($overtime['sign_out']); ?></strong></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="ews-dash-card">
            <div class="ews-dash-card-head"><div><h3><?php esc_html_e('My Week', 'workforce-one'); ?></h3><p><?php esc_html_e('Your upcoming schedule', 'workforce-one'); ?></p></div><span>📅</span></div>
            <?php if ($upcoming): ?>
                <div class="ews-my-week-list"><?php foreach ($upcoming as $u): ?><div><span><?php echo esc_html($u['date']); ?></span><b><?php echo esc_html($u['status'] === 'General Leave' ? __('General Leave', 'workforce-one') : $u['status']); ?></b></div><?php endforeach; ?></div>
            <?php else: echo $empty(__('No Upcoming Schedule', 'workforce-one'), __('No schedule has been set for your upcoming days.', 'workforce-one'), $urls['schedule'], __('View Schedule', 'workforce-one')); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by ews_empty_state() ?>
            <?php endif; ?>
            <a class="ews-dash-inline-link" href="<?php echo esc_url($urls['schedule']); ?>"><?php esc_html_e('View full schedule', 'workforce-one'); ?> →</a>
        </div>
        <div class="ews-dash-card">
            <div class="ews-dash-card-head"><div><h3><?php esc_html_e('Quick Actions', 'workforce-one'); ?></h3><p><?php esc_html_e('What do you need?', 'workforce-one'); ?></p></div><span>⚡</span></div>
            <div class="ews-dash-actions"><a href="<?php echo esc_url($urls['time']); ?>"><span>🕘</span> <?php esc_html_e('Sign In / Out', 'workforce-one'); ?></a><?php echo $notifications_link; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?><a href="<?php echo esc_url($urls['schedule']); ?>"><span>📅</span> <?php esc_html_e('My Schedule', 'workforce-one'); ?></a></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="ews-dashboard"><div class="ews-dash-card ews-employee-dashboard-empty"><h2><?php esc_html_e('Employee account not linked', 'workforce-one'); ?></h2><p><?php esc_html_e('Your WordPress account is not linked to an active employee record. Please contact your manager.', 'workforce-one'); ?></p></div></div>
<?php endif; ?>
