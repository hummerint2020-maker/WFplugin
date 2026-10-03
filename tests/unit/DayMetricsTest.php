<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\DayMetrics as M;

final class DayMetricsTest extends TestCase
{
    private static function day(array $over = []): array
    {
        return array_merge([
            'date' => '2026-09-30', 'today' => '2026-10-03', 'now' => '2026-10-03 10:00:00', 'holiday' => null,
            'planned' => 'Office', 'rule' => 'attendance', 'requires_sign_in' => true,
            'shift_start' => '09:00', 'shift_end' => '17:00', 'normal_until' => '11:00', 'grace' => 10, 'overnight' => false, 'break_allowance' => 30,
            'first_in' => null, 'first_in_legacy_late' => false, 'last_out' => null, 'break_minutes' => 0,
        ], $over);
    }

    public function testOnTimeDayWithABreak(): void
    {
        $m = M::compute(self::day(['first_in' => '2026-09-30 09:05:00', 'last_out' => '2026-09-30 17:00:00', 'break_minutes' => 45]));
        $this->assertSame(['Present', 0, 0, 475, 45, 430, 450], [$m['result'], $m['late_minutes'], $m['early_minutes'], $m['gross_minutes'], $m['break_minutes'], $m['net_minutes'], $m['expected_minutes']]);
        $this->assertSame('7:10', M::hm($m['net_minutes']));
    }

    public function testLateMinutesCountFromShiftStartAndEarlyLeaveFromItsEnd(): void
    {
        $m = M::compute(self::day(['first_in' => '2026-09-30 09:40:00', 'last_out' => '2026-09-30 16:30:00']));
        $this->assertSame(['Late', 40, 30], [$m['result'], $m['late_minutes'], $m['early_minutes']]);
        // Inside the grace period: on time, no late minutes.
        $m = M::compute(self::day(['first_in' => '2026-09-30 09:10:00', 'last_out' => '2026-09-30 17:00:00']));
        $this->assertSame(['Present', 0], [$m['result'], $m['late_minutes']]);
    }

    public function testAnOlderStyleLateSignInIsLate(): void
    {
        $m = M::compute(self::day(['first_in' => '2026-09-30 09:05:00', 'first_in_legacy_late' => true, 'last_out' => '2026-09-30 17:00:00']));
        $this->assertSame('Late', $m['result']);
    }

    public function testOvernightShiftCountsItsHours(): void
    {
        $m = M::compute(self::day(['shift_start' => '22:00', 'shift_end' => '06:00', 'normal_until' => '00:00', 'overnight' => true,
            'first_in' => '2026-09-30 22:00:00', 'last_out' => '2026-10-01 06:00:00']));
        $this->assertSame(['Present', 480, 450, 0], [$m['result'], $m['net_minutes'], $m['expected_minutes'], $m['early_minutes']]);
    }

    public function testAbsentMissingSignOutAndPending(): void
    {
        $this->assertSame(['Absent', 0, 450], array_values(array_intersect_key(M::compute(self::day()), array_flip(['result', 'net_minutes', 'expected_minutes']))));
        $m = M::compute(self::day(['first_in' => '2026-09-30 09:00:00']));
        $this->assertTrue($m['missing_sign_out']);
        $this->assertSame(0, $m['net_minutes']);
        // Today, before the no-show cutoff: still pending.
        $m = M::compute(self::day(['date' => '2026-10-03', 'now' => '2026-10-03 10:00:00']));
        $this->assertSame('Pending', $m['result']);
        $m = M::compute(self::day(['date' => '2026-10-03', 'now' => '2026-10-03 11:30:00']));
        $this->assertSame('Absent', $m['result']);
    }

    public function testHolidayLeaveAndTrip(): void
    {
        $m = M::compute(self::day(['holiday' => 'National Day']));
        $this->assertSame(['Holiday', false, 0], [$m['result'], $m['expected'], $m['expected_minutes']]);
        $m = M::compute(self::day(['planned' => 'Vacation', 'rule' => 'leave', 'requires_sign_in' => false]));
        $this->assertSame(['Leave', false], [$m['result'], $m['expected']]);
        $m = M::compute(self::day(['planned' => 'Training Course', 'rule' => 'business_trip', 'requires_sign_in' => false]));
        $this->assertSame(['Training Course', false], [$m['result'], $m['expected']]);
    }
}
