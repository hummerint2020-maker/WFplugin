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

    public function testSignedHours(): void
    {
        $this->assertSame(['−0:40', '+1:05', '0:00'], [M::signedHm(-40), M::signedHm(65), M::signedHm(0)]);
    }

    public function testOvertime(): void
    {
        // Approved 17:00-18:00, out at 18:30: 60 approved, 60 actual, 30 unapproved extra.
        $o = M::overtime('2026-09-30', '09:00', '17:00', '2026-09-30 09:00:00', '2026-09-30 18:30:00', [['start' => '17:00:00', 'end' => '18:00:00']]);
        $this->assertSame([60, 60, 30], [$o['approved'], $o['actual'], $o['extra']]);
        // Left at 17:30: only half of the window was worked; nothing unapproved.
        $o = M::overtime('2026-09-30', '09:00', '17:00', '2026-09-30 09:00:00', '2026-09-30 17:30:00', [['start' => '17:00', 'end' => '18:00']]);
        $this->assertSame([60, 30, 0], [$o['approved'], $o['actual'], $o['extra']]);
        // Before the shift: covered by an early Sign In.
        $o = M::overtime('2026-09-30', '09:00', '17:00', '2026-09-30 07:30:00', '2026-09-30 17:00:00', [['start' => '07:00', 'end' => '09:00']]);
        $this->assertSame([120, 90, 0], [$o['approved'], $o['actual'], $o['extra']]);
        // A window inside the shift and past its end counts only the part after the end.
        $o = M::overtime('2026-09-30', '09:00', '17:00', '2026-09-30 09:00:00', '2026-09-30 19:00:00', [['start' => '16:00', 'end' => '18:00']]);
        $this->assertSame([120, 60, 60], [$o['approved'], $o['actual'], $o['extra']]);
        // No approved window: staying late is all unapproved.
        $this->assertSame(45, M::overtime('2026-09-30', '09:00', '17:00', '2026-09-30 09:00:00', '2026-09-30 17:45:00', [])['extra']);
    }
}
