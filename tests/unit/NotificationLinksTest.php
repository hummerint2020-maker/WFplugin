<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Notifications\Links as L;

final class NotificationLinksTest extends TestCase
{
    public function testEachKindOpensItsAppPage(): void
    {
        $this->assertSame('schedule', L::view('swap'));
        $this->assertSame('vacation', L::view('early_leave'));
        $this->assertSame('corrections', L::view('correction'));
        $this->assertSame('profile', L::view('recognition', 'kudos'));
        $this->assertSame('polls', L::view('system', 'poll'), 'the entity decides first');
        $this->assertSame('notifications', L::view('system'));
        $this->assertSame('notifications', L::view('anything-else'));
    }

    public function testTheCapabilityALinkNeeds(): void
    {
        $admin = '/wp-admin/';
        $this->assertSame('ews_manage_time', L::capability('https://x.test/wp-admin/admin.php?page=ews31-time-report&timeline=3', $admin));
        $this->assertSame('ews_manage_employees', L::capability('https://x.test/wp-admin/admin.php?page=ews31-face-reset-requests', $admin));
        $this->assertSame('manage_options', L::capability('https://x.test/wp-admin/admin.php?page=unknown', $admin), 'a wp-admin page not listed: administrators');
        $this->assertSame('manage_options', L::capability('https://x.test/wp-admin/index.php', $admin));
        $this->assertSame('ews_manage_attendance', L::capability('https://x.test/app/?ews_view=attendance&week=2026-10-12', $admin));
        $this->assertSame('', L::capability('https://x.test/app/?ews_view=schedule', $admin));
        $this->assertSame('', L::capability('https://x.test/app/', $admin));
    }

    public function testTheChoicesAreKeptAndALinkMustBeOnThisSite(): void
    {
        [$d, $refused] = L::destinations(
            ['leave' => 'dashboard', 'task' => 'recommended', 'payroll' => 'link', 'poll' => 'link', 'swap' => 'link', 'smart_nudge' => 'time', 'break' => 'nowhere'],
            ['payroll' => '/app/?ews_view=pay&month=9', 'poll' => 'https://evil.example/x', 'swap' => '//evil.example/x'],
            ['leave', 'task', 'payroll', 'poll', 'swap', 'smart_nudge', 'break'], 'https://hr.example.com/');
        $this->assertSame(['leave' => ['to' => 'dashboard'], 'payroll' => ['to' => 'link', 'link' => 'https://hr.example.com/app/?ews_view=pay&month=9']], $d);
        $this->assertSame(['poll', 'swap'], $refused, 'another site, or a link that looks like a path to one');
        $this->assertSame('https://HR.example.com/x', L::sameSite('https://HR.example.com/x', 'https://hr.example.com/'), 'the host in any case');
        $this->assertSame('', L::sameSite('javascript:alert(1)', 'https://hr.example.com/'));
        $this->assertSame('', L::sameSite('https://hr.example.com:8443/x', 'https://hr.example.com/'));
    }

    public function testAChoiceReplacesTheRecommendedPage(): void
    {
        $c = ['leave' => ['to' => 'dashboard'], 'payroll' => ['to' => 'link', 'link' => 'https://hr.example.com/pay'], 'smart_nudge' => ['to' => 'time']];
        $app = 'https://hr.example.com/app/';
        $this->assertSame('https://hr.example.com/app/?ews_view=dashboard', L::apply($c, 'leave', $app . '?ews_view=vacation', $app, 'https://hr.example.com/'));
        $this->assertSame('https://hr.example.com/pay', L::apply($c, 'payroll', $app . '?ews_view=pay', $app, 'https://hr.example.com/'));
        $this->assertSame($app . '?ews_view=tasks&task=5', L::apply($c, 'task', $app . '?ews_view=tasks&task=5', $app, 'https://hr.example.com/'), 'recommended: the exact item');
        $this->assertSame('x', L::apply($c, 'smart_nudge', 'x', $app, 'https://hr.example.com/'), 'reminders keep their own place');
        $this->assertSame('https://hr.example.com/?page_id=4&ews_view=schedule', L::apply(['swap' => ['to' => 'schedule']], 'swap', 'y', 'https://hr.example.com/?page_id=4', 'https://hr.example.com/'));
    }
}
