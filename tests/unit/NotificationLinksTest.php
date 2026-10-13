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
}
