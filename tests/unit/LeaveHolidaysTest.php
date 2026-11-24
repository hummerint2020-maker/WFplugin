<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\HolidayRefund;
use WorkforceOne\Leave\WorkingDays;

/** Company holidays are not leave days (3.31.162, docs/tasks/leave-holidays.md). */
final class LeaveHolidaysTest extends TestCase
{
    private const SUN_THU = [0, 1, 2, 3, 4];

    public function testWorkingDaysSkipCompanyHolidays(): void
    {
        // The owner's example: Sun 4 – Thu 8 Oct 2026 with 6 Oct a company holiday is 4 days, not 5.
        $this->assertSame(5, WorkingDays::count('2026-10-04', '2026-10-08', self::SUN_THU));
        $this->assertSame(4, WorkingDays::count('2026-10-04', '2026-10-08', self::SUN_THU, ['2026-10-06']));
        $this->assertSame(['2026-10-04', '2026-10-05', '2026-10-07', '2026-10-08'], WorkingDays::dates('2026-10-04', '2026-10-08', self::SUN_THU, ['2026-10-06']));
        // Twice the same day; a holiday on a weekend or outside the range changes nothing.
        $this->assertSame(4, WorkingDays::count('2026-10-04', '2026-10-08', self::SUN_THU, ['2026-10-06', '2026-10-06']));
        $this->assertSame(5, WorkingDays::count('2026-10-04', '2026-10-08', self::SUN_THU, ['2026-10-09', '2026-10-10', '2026-10-20']));
        // A leave that is only a holiday has no working day.
        $this->assertSame(0, WorkingDays::count('2026-10-06', '2026-10-06', self::SUN_THU, ['2026-10-06']));
    }

    public function testCandidates(): void
    {
        $days = ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'];
        $this->assertSame(['2026-10-06'], HolidayRefund::candidates($days, ['2026-10-06', '2026-10-25'], [], 5));
        // Already given back: never twice.
        $this->assertSame([], HolidayRefund::candidates($days, ['2026-10-06'], ['2026-10-06'], 4));
        // Two holidays, ascending, capped by the days the leave still charges.
        $this->assertSame(['2026-10-05', '2026-10-06'], HolidayRefund::candidates($days, ['2026-10-06', '2026-10-05'], [], 5));
        $this->assertSame(['2026-10-05'], HolidayRefund::candidates($days, ['2026-10-06', '2026-10-05'], [], 1));
        $this->assertSame([], HolidayRefund::candidates($days, ['2026-10-06'], [], 0));
        $this->assertSame([], HolidayRefund::candidates([], ['2026-10-06'], [], 3));
    }

    public function testSkipped(): void
    {
        $all = ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'];
        // Counted without the holiday: the holiday is kept on the leave.
        $this->assertSame(['2026-10-06'], HolidayRefund::skipped($all, ['2026-10-06'], 4));
        // Counted with every working day (the switch off, or turned on meanwhile): nothing skipped.
        $this->assertSame([], HolidayRefund::skipped($all, ['2026-10-06'], 5));
        $this->assertSame([], HolidayRefund::skipped($all, [], 5));
        // A holiday outside the working days changes nothing.
        $this->assertSame([], HolidayRefund::skipped($all, ['2026-10-10'], 5));
        // Neither reading (the calendar changed meanwhile): not saved.
        $this->assertNull(HolidayRefund::skipped($all, ['2026-10-06'], 3));
    }

    public function testRestorable(): void
    {
        $this->assertTrue(HolidayRefund::restorable('Approved', true, null));
        $this->assertTrue(HolidayRefund::restorable('Approved', true, 'Approved'));   // partly cancelled before
        $this->assertTrue(HolidayRefund::restorable('Approved', true, 'Rejected'));
        $this->assertFalse(HolidayRefund::restorable('Approved', true, 'Pending'));  // decide the change first
        $this->assertFalse(HolidayRefund::restorable('Pending', true, null));        // owner: shown once decided
        $this->assertFalse(HolidayRefund::restorable('Cancelled', true, 'Approved'));
        $this->assertFalse(HolidayRefund::restorable('Approved', false, null));      // a type that does not deduct
    }
}
