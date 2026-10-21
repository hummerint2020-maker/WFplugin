<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Settings\TaskSettings as T;

final class TaskSettingsTest extends TestCase
{
    public function testDefaultsAndStoredValues(): void
    {
        $d = T::config(null);
        $this->assertSame(1, $d['checklist']);
        $this->assertSame(['pdf', 'jpg', 'jpeg', 'png', 'docx', 'xlsx'], $d['attach_types']);
        $c = T::config(['comments' => 0, 'attach_max_mb' => 500, 'home_count' => -3, 'team_mode' => 'nobody', 'remind_default' => 7]);
        $this->assertSame(0, $c['comments']);
        $this->assertSame(0, $c['attachments'], 'files sit in comments: no comments, no files');
        $this->assertSame(20, $c['attach_max_mb']);
        $this->assertSame(1, $c['home_count']);
        $this->assertSame('each', $c['team_mode']);
        $this->assertSame(60, $c['remind_default']);
    }

    public function testFromPost(): void
    {
        $p = T::fromPost(['checklist' => '1', 'comments' => '1', 'attachments' => '1', 'attach_types' => ['PDF', 'exe', 'txt'], 'reminders' => '1']);
        $this->assertSame(0, $p['activity'], 'a missing checkbox is off');
        $this->assertSame(['pdf', 'txt'], $p['attach_types'], 'only known types, lower case');
        $this->assertSame(1, $p['attachments']);
        $this->assertSame(0, $p['reminders'], 'no due time, no reminders');
        $this->assertSame(0, T::fromPost(['comments' => '1', 'attachments' => '1'])['attachments'], 'no file types, no files');
    }

    public function testNextDate(): void
    {
        $this->assertSame('2026-10-09', T::nextDate('daily', '2026-10-08'));
        $this->assertSame('2026-10-11', T::nextDate('weekly:0,3', '2026-10-08'), 'Thursday → the next Sunday');
        $this->assertSame('2026-10-14', T::nextDate('weekly:0,3', '2026-10-11'), 'Sunday → Wednesday');
        $this->assertSame('2026-10-15', T::nextDate('weekly:4', '2026-10-08'), 'the same weekday a week later');
        $this->assertSame('2026-10-15', T::nextDate('monthly:15', '2026-10-08'));
        $this->assertSame('2026-11-15', T::nextDate('monthly:15', '2026-10-15'));
        $this->assertSame('2026-02-28', T::nextDate('monthly:31', '2026-01-31'), 'a short month uses its last day');
        $this->assertSame('2027-01-31', T::nextDate('monthly:31', '2026-12-31'));
        $this->assertNull(T::nextDate('weekly:', '2026-10-08'));
        $this->assertNull(T::nextDate('hourly', '2026-10-08'));
        $this->assertNull(T::nextDate('daily', 'not a date'));
    }

    public function testRuleFromPost(): void
    {
        $this->assertSame('', T::ruleFromPost(['repeat' => '']));
        $this->assertSame('daily', T::ruleFromPost(['repeat' => 'daily']));
        $this->assertSame('weekly:0,3', T::ruleFromPost(['repeat' => 'weekly', 'repeat_days' => ['3', '0', '3', '9']]));
        $this->assertSame('', T::ruleFromPost(['repeat' => 'weekly', 'repeat_days' => []]), 'weekly needs a day');
        $this->assertSame('monthly:31', T::ruleFromPost(['repeat' => 'monthly', 'repeat_day' => '31']));
        $this->assertSame('', T::ruleFromPost(['repeat' => 'monthly', 'repeat_day' => '40']));
    }
}
