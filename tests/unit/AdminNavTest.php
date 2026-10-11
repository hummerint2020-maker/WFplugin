<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Ui\AdminNav;

final class AdminNavTest extends TestCase
{
    public function testEveryPageIsInOneSectionOnce(): void
    {
        $slugs = AdminNav::slugs();
        $this->assertSame($slugs, array_values(array_unique($slugs)));
        $this->assertCount(8, AdminNav::sections());
        $this->assertSame('ews31', $slugs[0]);
    }

    public function testVisibleKeepsOrderAndDropsWhatThePersonCannotOpen(): void
    {
        $open = array_fill_keys(AdminNav::slugs(), true);
        $all = AdminNav::visible($open, ['face' => true, 'recognition' => true, 'tasks' => true]);
        $this->assertSame(['home', 'people', 'attendance', 'requests', 'schedule', 'pay', 'engage', 'settings'], array_keys($all));
        $this->assertSame(['ews31-employees', 'ews31-departments', 'ews31-teams', 'ews31-employee-branches'], $all['people']);

        // A manager with two pages: only those, and only their sections.
        $mgr = AdminNav::visible(['ews31' => true, 'ews31-time-report' => true, 'ews31-attendance-insights' => true], []);
        $this->assertSame(['home' => ['ews31'], 'attendance' => ['ews31-time-report', 'ews31-attendance-insights']], $mgr);
    }

    public function testATabWhoseFeatureIsOffIsLeftOut(): void
    {
        $open = array_fill_keys(AdminNav::slugs(), true);
        $off = AdminNav::visible($open, ['face' => false, 'recognition' => true, 'tasks' => false]);
        $this->assertNotContains('ews31-tasks', $off['engage']);
        $this->assertNotContains('ews31-face-reset-requests', $off['attendance']);
        $this->assertContains('ews31-recognition', $off['engage']);
    }

    public function testPlace(): void
    {
        $this->assertSame(['people', 'ews31-teams'], AdminNav::place('ews31-teams'));
        $this->assertSame(['people', 'ews31-employees'], AdminNav::place('ews31-employee-profile'));
        $this->assertSame(['engage', 'ews31-tasks'], AdminNav::place('ews31-tasks'));
        $this->assertNull(AdminNav::place('something-else'));
    }
}
