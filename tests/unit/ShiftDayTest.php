<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\ShiftDay as D;

final class ShiftDayTest extends TestCase
{
    public function testDayShiftsAreAlwaysToday(): void
    {
        $this->assertSame('2026-10-03', D::resolve('2026-10-03', '01:00', '09:00', '17:00', true, true));
        $this->assertSame('2026-10-03', D::resolve('2026-10-03', '01:00', '22:00', '06:00', false, true));
    }

    public function testAfterMidnightTheShiftBelongsToTheDayItStarted(): void
    {
        $this->assertSame('2026-10-02', D::resolve('2026-10-03', '00:30', '22:00', '06:00', true, false));
        $this->assertSame('2026-10-02', D::resolve('2026-10-03', '06:00', '22:00', '06:00', true, false));
        // Across a month end.
        $this->assertSame('2026-09-30', D::resolve('2026-10-01', '03:00', '22:00', '06:00', true, true));
    }

    public function testAnOpenShiftStaysOnItsDayUntilTheNextStarts(): void
    {
        $this->assertSame('2026-10-02', D::resolve('2026-10-03', '07:15', '22:00', '06:00', true, true));
        $this->assertSame('2026-10-03', D::resolve('2026-10-03', '07:15', '22:00', '06:00', true, false));
        $this->assertSame('2026-10-03', D::resolve('2026-10-03', '22:30', '22:00', '06:00', true, true));
    }
}
