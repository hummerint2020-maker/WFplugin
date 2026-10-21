<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\OfficeMinimum;
use WorkforceOne\Settings\OfficeMinimumSettings;

final class OfficeMinimumTest extends TestCase
{
    public function testSharesAreProportionalAndAddUp(): void
    {
        // 12 of 40: Sales 12 → 3.6, Customer Service 10 → 3, Accounts 8 → 2.4, IT 6 → 1.8, no team 4 → 1.2
        $s = OfficeMinimum::shares(12, [1 => 12, 2 => 10, 3 => 8, 4 => 6, 0 => 4]);
        $this->assertSame([1 => 4, 2 => 3, 3 => 2, 4 => 2, 0 => 1], $s);
        $this->assertSame(12, array_sum($s));
        $this->assertSame(7, array_sum(OfficeMinimum::shares(7, [1 => 3, 2 => 3, 3 => 3])), 'ties still add up');
        $this->assertSame([1 => 3, 2 => 2, 3 => 2], OfficeMinimum::shares(7, [1 => 3, 2 => 3, 3 => 3]), 'ties: the order given');
        $this->assertSame([1 => 0, 2 => 0], OfficeMinimum::shares(0, [1 => 5, 2 => 5]));
    }

    public function testFixedNumbers(): void
    {
        // Customer Service fixed at 5: the other 7 shared by the rest (30 people).
        $s = OfficeMinimum::shares(12, [1 => 12, 2 => 10, 3 => 8, 4 => 6, 0 => 4], [2 => 5]);
        $this->assertSame(5, $s[2]);
        $this->assertSame(12, array_sum($s));
        $this->assertSame([1 => 3, 2 => 5, 3 => 2, 4 => 1, 0 => 1], $s);
        // Fixed numbers above the minimum: the others get nothing.
        $this->assertSame([1 => 0, 2 => 15], OfficeMinimum::shares(12, [1 => 10, 2 => 10], [2 => 15]));
        $this->assertSame([1 => 4], OfficeMinimum::shares(4, [1 => 10], [9 => 3]), 'a fixed number for an unknown team is ignored');
    }

    public function testDay(): void
    {
        $d = OfficeMinimum::day(12, [1 => 4, 2 => 3, 3 => 2, 4 => 2, 0 => 1], [1 => 2, 2 => 3, 3 => 1, 4 => 3, 0 => 1]);
        $this->assertSame(10, $d['office']);
        $this->assertSame(2, $d['short']);
        $this->assertSame([1, 3, 0, 2, 4], array_column($d['teams'], 'team'), 'biggest shortfall first');
        $this->assertSame([-2, -1, 0, 0, 1], array_column($d['teams'], 'gap'));
        $this->assertSame(0, OfficeMinimum::day(5, [1 => 5], [1 => 7])['short']);
    }

    public function testMinimumForADay(): void
    {
        $cfg = ['min' => 12, 'days' => [4 => 8]];
        $this->assertSame(8, OfficeMinimum::minimumFor($cfg, '2026-10-15'));   // Thursday
        $this->assertSame(12, OfficeMinimum::minimumFor($cfg, '2026-10-13'));  // Tuesday
    }

    public function testSettings(): void
    {
        $c = OfficeMinimumSettings::config([]);
        $this->assertSame(['Office'], $c['statuses']);
        $this->assertSame(4, $c['reminder_day']);
        $known = ['Office', 'WFH', 'Business Trip'];
        [$s, $e] = OfficeMinimumSettings::fromPost(['om_min' => '12', 'om_days' => ['4' => '8', '1' => ''], 'om_teams' => ['3' => '2', '5' => ''],
            'om_statuses' => ['Office', 'Nope'], 'om_reminder' => '1', 'om_reminder_day' => '0', 'om_reminder_time' => '09:30'], $known);
        $this->assertSame('', $e);
        $this->assertSame([4 => 8], $s['days']);
        $this->assertSame([3 => 2], $s['teams']);
        $this->assertSame(['Office'], $s['statuses']);
        $this->assertSame(0, $s['reminder_day']);
        $this->assertSame('min', OfficeMinimumSettings::fromPost(['om_min' => '0'], $known)[1]);
        $this->assertSame('min', OfficeMinimumSettings::fromPost(['om_min' => 'x'], $known)[1]);
        $this->assertSame('day', OfficeMinimumSettings::fromPost(['om_min' => '5', 'om_days' => ['4' => '-1']], $known)[1]);
        $this->assertSame('statuses', OfficeMinimumSettings::fromPost(['om_min' => '5', 'om_statuses' => []], $known)[1]);
        $this->assertSame('time', OfficeMinimumSettings::fromPost(['om_min' => '5', 'om_statuses' => ['Office'], 'om_reminder_time' => '25:00'], $known)[1]);
        $this->assertSame(['min' => 7, 'days' => [2 => 3]], array_intersect_key(OfficeMinimumSettings::config(['min' => '7', 'days' => ['2' => '3', '9' => '1']]), ['min' => 1, 'days' => 1]));
    }
}
