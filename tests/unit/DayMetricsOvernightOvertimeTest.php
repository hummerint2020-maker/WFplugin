<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\DayMetrics;

final class DayMetricsOvernightOvertimeTest extends TestCase
{
    public function testOvertimeAfterANightShiftIsTheNextMorning(): void
    {
        // Shift 22:00–06:00 on 1 Oct; signed in 21:58, signed out 08:05 on 2 Oct; overtime approved 06:00–08:00.
        $ot = DayMetrics::overtime('2026-10-01', '22:00', '06:00', '2026-10-01 21:58:00', '2026-10-02 08:05:00', [['start' => '06:00', 'end' => '08:00']]);
        $this->assertSame(120, $ot['approved']);
        $this->assertSame(120, $ot['actual'], 'worked: it is after the shift, the next morning');
        $this->assertSame(5, $ot['extra'], 'and only the 5 minutes after it are unapproved');
    }

    public function testOvertimeBeforeANightShiftStaysBeforeIt(): void
    {
        $ot = DayMetrics::overtime('2026-10-01', '22:00', '06:00', '2026-10-01 20:00:00', '2026-10-02 06:00:00', [['start' => '20:00', 'end' => '22:00']]);
        $this->assertSame(120, $ot['actual']);
        $this->assertSame(0, $ot['extra']);
    }

    public function testADayShiftIsUnchanged(): void
    {
        $ot = DayMetrics::overtime('2026-10-01', '08:00', '16:00', '2026-10-01 08:00:00', '2026-10-01 17:30:00', [['start' => '16:00', 'end' => '18:00']]);
        $this->assertSame(['approved' => 120, 'actual' => 90, 'extra' => 0], array_intersect_key($ot, ['approved' => 1, 'actual' => 1, 'extra' => 1]));
    }
}
