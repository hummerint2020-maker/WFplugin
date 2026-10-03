<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\EarlyLeave\RequestRules as E;
use WorkforceOne\Overtime\RequestRules as O;

final class OvertimeEarlyLeaveRulesTest extends TestCase
{
    public function testOvertimeHelpers(): void
    {
        $this->assertSame(150, O::minutes('18:00', '20:30'));
        $this->assertSame(0, O::minutes('20:00', '18:00'));
        $this->assertSame(0, O::minutes('18:00', '18:00'));
        $this->assertSame(2.5, O::hours(150));
        $this->assertSame(0.33, O::hours(20));
        $this->assertSame('2 hours 30 min', O::durationText(150));
        $this->assertSame('1 hour', O::durationText(60));
        $this->assertSame('0 hours 45 min', O::durationText(45));
        $this->assertSame('20:30:00', O::withSeconds('20:30'));
        $this->assertSame('20:30:00', O::withSeconds('20:30:00'));
    }

    private function o(array $over = []): array
    {
        return array_merge(['date' => '2026-10-01', 'today' => '2026-10-01', 'start' => '18:00', 'end' => '20:00', 'reason' => 'deadline', 'overlaps' => false], $over);
    }

    public function testOvertimeRules(): void
    {
        $this->assertNull(O::check($this->o()), 'today is allowed');
        $this->assertSame(O::DATE, O::check($this->o(['date' => '2026-09-30'])));
        $this->assertSame(O::DATE, O::check($this->o(['date' => '1/10/2026'])));
        $this->assertSame(O::TIME, O::check($this->o(['start' => '6pm'])));
        $this->assertSame(O::TIME, O::check($this->o(['start' => '20:00', 'end' => '18:00'])));
        $this->assertSame(O::REASON, O::check($this->o(['reason' => ''])));
        $this->assertSame(O::OVERLAP, O::check($this->o(['overlaps' => true])));
        $this->assertSame(O::TIME, O::check($this->o(['end' => '17:00', 'reason' => '', 'overlaps' => true])), 'time before reason before overlap');
    }

    private function e(array $over = []): array
    {
        return array_merge(['date' => '2026-10-05', 'today' => '2026-10-01', 'working_day' => true, 'office_only' => true,
            'schedule_status' => 'Office', 'minutes' => 60, 'max_minutes' => 120, 'used_this_month' => 0, 'monthly_minutes' => 240], $over);
    }

    public function testEarlyLeaveRules(): void
    {
        $this->assertNull(E::check($this->e()));
        $this->assertSame(E::FUTURE_DATE, E::check($this->e(['date' => '2026-10-01'])), 'today is not allowed');
        $this->assertSame(E::WORKING_DAY, E::check($this->e(['working_day' => false])));
        $this->assertSame(E::OFFICE_ONLY, E::check($this->e(['schedule_status' => 'WFH'])));
        $this->assertSame(E::OFFICE_ONLY, E::check($this->e(['schedule_status' => null])));
        $this->assertNull(E::check($this->e(['office_only' => false, 'schedule_status' => 'WFH'])));
        $this->assertSame(E::MAX_DURATION, E::check($this->e(['minutes' => 121])));
        $this->assertNull(E::check($this->e(['minutes' => 120, 'used_this_month' => 120])), 'exactly the monthly allowance');
        $this->assertSame(E::MONTHLY_LIMIT, E::check($this->e(['minutes' => 120, 'used_this_month' => 121])));
        $this->assertNull(E::check(array_diff_key($this->e(), ['used_this_month' => 0])), 'monthly check skipped until usage is known');
    }
}
