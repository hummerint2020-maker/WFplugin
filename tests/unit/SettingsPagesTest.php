<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\NotificationSettings as N;
use WorkforceOne\Settings\RolePermissions as R;

final class SettingsPagesTest extends TestCase
{
    public function testMatrixFromPost(): void
    {
        $m = R::fromPost(['a' => ['x' => '1']], ['a', 'b'], ['x', 'y']);
        $this->assertSame(['a' => ['x' => true, 'y' => false], 'b' => ['x' => false, 'y' => false]], $m);
    }

    public function testDesiredState(): void
    {
        $this->assertFalse(R::desired(['x' => false], ['x' => true], 'x', true), 'the saved matrix wins');
        $this->assertFalse(R::desired(null, ['x' => false], 'x', true), 'a legacy explicit false means off');
        $this->assertTrue(R::desired(null, [], 'x', true), 'otherwise the default');
    }

    public function testLockout(): void
    {
        $m = ['ews_administrator' => ['ews_manage_roles' => false], 'ews_manager' => ['ews_manage_roles' => false]];
        $this->assertTrue(R::locksOut($m, ['ews_administrator'], false));
        $this->assertFalse(R::locksOut($m, ['ews_administrator'], true), 'WordPress administrators always keep access');
        $this->assertFalse(R::locksOut($m, ['subscriber'], false), 'not managing roles through EWS roles');
        $m['ews_manager']['ews_manage_roles'] = true;
        $this->assertFalse(R::locksOut($m, ['ews_administrator', 'ews_manager'], false), 'another held role keeps it');
    }

    public function testNotificationValues(): void
    {
        $this->assertSame(30, N::retention('30'));
        $this->assertSame(90, N::retention(12));
        $this->assertSame(0, N::retention(0));
        $this->assertSame('mailto:ops@example.com', N::vapidSubject(' mailto:ops@example.com '));
        $this->assertSame('https://example.com', N::vapidSubject('https://example.com'));
        $this->assertNull(N::vapidSubject('http://example.com'));
        $this->assertNull(N::vapidSubject('not a subject'));
        $p = N::policy(['a' => ['mandatory' => 1], 'b' => ['in_app' => 1, 'push' => 1]], ['a', 'b', 'c']);
        $this->assertSame(['in_app' => 0, 'push' => 1, 'mandatory' => 1], $p['a']);
        $this->assertSame(['in_app' => 1, 'push' => 1, 'mandatory' => 0], $p['b']);
        $this->assertSame(['in_app' => 0, 'push' => 0, 'mandatory' => 0], $p['c']);
    }
}
