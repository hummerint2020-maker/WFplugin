<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\WeekLock;
use WorkforceOne\Settings\WeekLockSettings;

final class WeekLockTest extends TestCase
{
    private const SUN_THU = [0, 1, 2, 3, 4];

    public function testWeekStartFollowsTheWorkingDays(): void
    {
        // 2026-10-11 is a Sunday.
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-11', self::SUN_THU));
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-15', self::SUN_THU));
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-16', self::SUN_THU), 'Friday belongs to the week before it');
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-17', self::SUN_THU), 'and Saturday');
        $this->assertSame('2026-10-12', WeekLock::weekStart('2026-10-14', [1, 2, 3, 4, 5]), 'Mon–Fri');
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-14', [0, 1, 2, 3, 6]), 'Sat–Wed: working_days() is sorted, so the week starts on Sunday');
        $this->assertSame('2026-10-11', WeekLock::weekStart('2026-10-17', [0, 1, 2, 3, 6]), '...and that Saturday is its last day');
        $this->assertSame('2026-10-07', WeekLock::weekStart('2026-10-13', [3]), 'a single working day');
        $this->assertSame('2026-10-13', WeekLock::weekStart('2026-10-13', []), 'no working days: the day itself');
        $this->assertSame('', WeekLock::weekStart('not a date', self::SUN_THU));
    }

    public function testLockedFromIsTheCutoffBeforeTheWeek(): void
    {
        $this->assertSame('2026-10-08 17:00:00', WeekLock::lockedFrom('2026-10-11', 4, '17:00'), 'Thursday before');
        $this->assertSame('2026-10-10 09:30:00', WeekLock::lockedFrom('2026-10-11', 6, '09:30'), 'Saturday before');
        $this->assertSame('2026-10-04 12:00:00', WeekLock::lockedFrom('2026-10-11', 0, '12:00'), 'the week\'s own first weekday: a week earlier');
        $this->assertSame('2026-10-05 00:00:00', WeekLock::lockedFrom('2026-10-11', 1, '00:00'));
    }

    public function testIsLocked(): void
    {
        $this->assertFalse(WeekLock::isLocked('2026-10-11', '2026-10-08 16:59:59', 4, '17:00'));
        $this->assertTrue(WeekLock::isLocked('2026-10-11', '2026-10-08 17:00:00', 4, '17:00'), 'the cutoff instant is final');
        $this->assertTrue(WeekLock::isLocked('2026-10-11', '2026-10-13 10:00:00', 4, '17:00'), 'the current week stays final');
        $this->assertTrue(WeekLock::isLocked('2026-09-27', '2026-10-13 10:00:00', 4, '17:00'), 'and past weeks');
        $this->assertFalse(WeekLock::isLocked('2026-10-18', '2026-10-13 10:00:00', 4, '17:00'), 'next week is open until its cutoff');
        $this->assertFalse(WeekLock::isLocked('', '2026-10-13 10:00:00', 4, '17:00'));
    }

    public function testChangingTheWorkingDaysKeepsTheCurrentWeekFinal(): void
    {
        $now = '2026-10-13 10:00:00';   // Tuesday of the week of 11 Oct
        foreach (['2026-10-11', '2026-10-13', '2026-10-15'] as $day) {
            foreach ([self::SUN_THU, [1, 2, 3, 4, 5], [0, 1, 2, 3, 6], [3]] as $days) {
                $this->assertTrue(WeekLock::isLocked(WeekLock::weekStart($day, $days), $now, 4, '17:00'), $day . ' with ' . implode(',', $days));
            }
        }
        // A Saturday that becomes a working day stays in the week it was in (working_days() is sorted).
        $this->assertTrue(WeekLock::isLocked(WeekLock::weekStart('2026-10-17', [0, 1, 2, 3, 6]), $now, 4, '17:00'));
    }

    public function testNextAndLatest(): void
    {
        $this->assertSame(['week' => '2026-10-11', 'at' => '2026-10-08 17:00:00'], WeekLock::next('2026-10-08 12:00:00', self::SUN_THU, 4, '17:00'));
        $this->assertSame(['week' => '2026-10-18', 'at' => '2026-10-15 17:00:00'], WeekLock::next('2026-10-08 17:00:00', self::SUN_THU, 4, '17:00'));
        $this->assertSame(['week' => '2026-10-18', 'at' => '2026-10-15 17:00:00'], WeekLock::next('2026-10-10 08:00:00', self::SUN_THU, 4, '17:00'), 'Saturday');
        $this->assertSame(['week' => '2026-10-11', 'at' => '2026-10-08 17:00:00'], WeekLock::latest('2026-10-08 17:00:00', self::SUN_THU, 4, '17:00'));
        $this->assertSame(['week' => '2026-10-04', 'at' => '2026-10-01 17:00:00'], WeekLock::latest('2026-10-08 16:59:00', self::SUN_THU, 4, '17:00'));
        $this->assertSame(['week' => '2026-10-11', 'at' => '2026-10-04 12:00:00'], WeekLock::latest('2026-10-05 08:00:00', self::SUN_THU, 0, '12:00'), 'cutoff on the first weekday');
    }

    public function testSettings(): void
    {
        $this->assertSame(WeekLockSettings::DEFAULTS, WeekLockSettings::config(null));
        $this->assertSame(['cutoff_day' => 6, 'cutoff_time' => '09:30', 'notify' => 0], WeekLockSettings::config(['cutoff_day' => '6', 'cutoff_time' => '09:30', 'notify' => '']));
        $this->assertSame(WeekLockSettings::DEFAULTS, WeekLockSettings::config(['cutoff_day' => 9, 'cutoff_time' => '25:00']), 'bad values keep the defaults');
        $this->assertSame([['cutoff_day' => 0, 'cutoff_time' => '08:15', 'notify' => 1], ''], WeekLockSettings::fromPost(['wl_cutoff_day' => '0', 'wl_cutoff_time' => '08:15', 'wl_notify' => '1']));
        $this->assertSame('day', WeekLockSettings::fromPost(['wl_cutoff_day' => '7', 'wl_cutoff_time' => '08:15'])[1]);
        $this->assertSame('day', WeekLockSettings::fromPost(['wl_cutoff_day' => '', 'wl_cutoff_time' => '08:15'])[1]);
        $this->assertSame('time', WeekLockSettings::fromPost(['wl_cutoff_day' => '4', 'wl_cutoff_time' => '5pm'])[1]);
        $this->assertSame(0, WeekLockSettings::fromPost(['wl_cutoff_day' => '4', 'wl_cutoff_time' => '17:00'])[0]['notify']);
    }
}
