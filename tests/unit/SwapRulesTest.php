<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\SwapRules;

final class SwapRulesTest extends TestCase
{
    public function testRequestNeedsAnotherColleagueAndADate(): void
    {
        $this->assertSame(SwapRules::INVALID_REQUEST, SwapRules::checkRequest(5, 0, '2026-10-01'));
        $this->assertSame(SwapRules::INVALID_REQUEST, SwapRules::checkRequest(5, 5, '2026-10-01'));
        $this->assertSame(SwapRules::INVALID_REQUEST, SwapRules::checkRequest(5, 6, 'tomorrow'));
        $this->assertNull(SwapRules::checkRequest(5, 6, '2026-10-01'));
    }

    public function testOnlyDifferentOfficeAndWfhDaysAreSwappable(): void
    {
        $this->assertNull(SwapRules::checkSwappable('Office', 'WFH'));
        $this->assertNull(SwapRules::checkSwappable('WFH', 'Office'));
        $this->assertSame(SwapRules::NOT_SWAPPABLE, SwapRules::checkSwappable('Office', 'Office'));
        $this->assertSame(SwapRules::NOT_SWAPPABLE, SwapRules::checkSwappable('Office', 'Vacation'));
        $this->assertSame(SwapRules::NOT_SWAPPABLE, SwapRules::checkSwappable(null, 'WFH'));
        $this->assertSame(SwapRules::NOT_SWAPPABLE, SwapRules::checkSwappable('WFH', null));
    }

    public function testSwapIsRefusedOnceEitherScheduleChanged(): void
    {
        $this->assertNull(SwapRules::checkStillCurrent('Office', 'WFH', 'Office', 'WFH'));
        $this->assertSame(SwapRules::CHANGED, SwapRules::checkStillCurrent('Office', 'WFH', 'Office', 'Office'));
        $this->assertSame(SwapRules::CHANGED, SwapRules::checkStillCurrent('Office', 'WFH', 'WFH', 'WFH'));
        $this->assertSame(SwapRules::CHANGED, SwapRules::checkStillCurrent('Office', 'WFH', null, 'WFH'));
    }
}
