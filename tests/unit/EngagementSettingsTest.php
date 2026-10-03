<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\Moments;
use WorkforceOne\Settings\Navigation;
use WorkforceOne\Settings\SmartNudges;

final class EngagementSettingsTest extends TestCase
{
    public function testNavigationIncludesPeopleAndKeepsDefaults(): void
    {
        $c = Navigation::config([]);
        $this->assertArrayHasKey('people', $c);
        $this->assertSame('People', $c['people']['label']);
        $p = Navigation::fromPost(['people' => ['label' => 'Team', 'desktop_visible' => 1, 'desktop_order' => '0']]);
        $this->assertSame('Team', $p['people']['label']);
        $this->assertSame(0, $p['people']['mobile_visible'], 'unticked = hidden');
        $this->assertSame(1, $p['people']['desktop_order'], 'order is at least 1');
        $this->assertSame('Dashboard', $p['dashboard']['label'], 'an empty label falls back');
        $this->assertSame(0, $p['dashboard']['desktop_visible']);
    }

    public function testMomentsMergeKeepsOtherEmployeesAndValidates(): void
    {
        [$m, $e] = Moments::merge([5 => ['birthday' => '1990-03-01', 'join_date' => '']], [9 => ['birthday' => '1980-01-01', 'join_date' => ''], 5 => ['birthday' => 'x', 'join_date' => '']], [5], '2026-10-01');
        $this->assertNull($e);
        $this->assertSame([5 => ['birthday' => '1990-03-01', 'join_date' => ''], 9 => ['birthday' => '1980-01-01', 'join_date' => '']], $m);
        $this->assertSame('date', Moments::merge([5 => ['birthday' => '2026-02-30']], [], [5], '2026-10-01')[1]);
        $this->assertSame('date', Moments::merge([5 => ['birthday' => '2026-10-02']], [], [5], '2026-10-01')[1]);
        $this->assertSame([], Moments::merge([5 => ['birthday' => '', 'join_date' => '']], [5 => ['birthday' => '1990-01-01']], [5], '2026-10-01')[0]);
    }

    public function testCelebrations(): void
    {
        $this->assertSame([['type' => 'birthday']], Moments::forDate('1990-10-01', '', '2026-10-01'));
        $this->assertSame([['type' => 'birthday']], Moments::forDate('1992-02-29', '', '2027-02-28'), 'Feb 29 on Feb 28 in a common year');
        $this->assertSame([], Moments::forDate('1992-02-29', '', '2028-02-28'), 'but on Feb 29 in a leap year');
        $this->assertSame([['type' => 'anniversary', 'years' => 3]], Moments::forDate('', '2023-10-01', '2026-10-01'));
        $this->assertSame([['type' => 'welcome']], Moments::forDate('', '2026-09-20', '2026-10-01'));
        $this->assertSame([], Moments::forDate('', '2026-10-05', '2026-10-01'), 'not yet joined');
    }

    public function testSmartNudges(): void
    {
        $c = SmartNudges::fromPost(['enabled' => 1, 'items' => ['tasks' => 1], 'attendance_after' => 999, 'attendance_repeat_interval' => 1, 'attendance_max_reminders' => 50]);
        $this->assertSame(['attendance' => 0, 'tasks' => 1, 'leave' => 0, 'schedule' => 0], $c['items']);
        $this->assertSame([240, 5, 10, 0], [$c['attendance_after'], $c['attendance_repeat_interval'], $c['attendance_max_reminders'], $c['attendance_repeat']]);
        $this->assertSame(SmartNudges::DEFAULTS, SmartNudges::config(null));
    }
}
