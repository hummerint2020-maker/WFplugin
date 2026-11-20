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

    public function testAPastDayCannotBeSwapped(): void
    {
        $this->assertSame(SwapRules::PAST_DATE, SwapRules::checkNotPast('2026-10-01', '2026-10-02'));
        $this->assertNull(SwapRules::checkNotPast('2026-10-02', '2026-10-02'));
        $this->assertNull(SwapRules::checkNotPast('2026-10-03', '2026-10-02'));
    }

    /** 3.31.156 (SW-a): the same department, whoever asks; no department, nobody. */
    public function testOnlyAColleagueOfTheSameDepartment(): void
    {
        $this->assertNull(SwapRules::checkSameDepartment(3, 3));
        $this->assertSame(SwapRules::DEPARTMENT, SwapRules::checkSameDepartment(3, 4));
        $this->assertSame(SwapRules::DEPARTMENT, SwapRules::checkSameDepartment(0, 0));
        $this->assertSame(SwapRules::DEPARTMENT, SwapRules::checkSameDepartment(0, 3));
        $this->assertSame(SwapRules::DEPARTMENT, SwapRules::checkSameDepartment(3, 0));
    }

    /** 3.31.156 (SW-b, owner 2026-11-20): what counts as a person's commitment for a day. */
    public function testACommitmentIsWhatIAskedOrAcceptedAndStillOpen(): void
    {
        $this->assertTrue(SwapRules::isCommitment(1, 1, 2, 'Pending'));
        $this->assertTrue(SwapRules::isCommitment(1, 1, 2, 'Awaiting'));
        $this->assertTrue(SwapRules::isCommitment(1, 2, 1, 'Awaiting'));     // accepted, waiting for the approvers
        $this->assertFalse(SwapRules::isCommitment(1, 2, 1, 'Pending'));     // asked of me, not answered
        $this->assertFalse(SwapRules::isCommitment(1, 1, 2, 'Accepted'));
        $this->assertFalse(SwapRules::isCommitment(1, 1, 2, 'Cancelled'));
        $this->assertFalse(SwapRules::isCommitment(1, 3, 2, 'Pending'));     // not mine
    }

    public function testAskingIsRefusedForTheSamePairOrAnotherCommitmentThatDay(): void
    {
        $this->assertNull(SwapRules::checkCanAsk([], 1, 2));
        $this->assertSame(SwapRules::PENDING, SwapRules::checkCanAsk([[1, 2, 'Pending']], 1, 2));
        $this->assertSame(SwapRules::PENDING, SwapRules::checkCanAsk([[2, 1, 'Pending']], 1, 2));      // the colleague asked me already
        $this->assertSame(SwapRules::PENDING, SwapRules::checkCanAsk([[1, 3, 'Pending'], [2, 1, 'Pending']], 1, 2));
        $this->assertSame(SwapRules::ONE_PER_DAY, SwapRules::checkCanAsk([[1, 3, 'Pending']], 1, 2));
        $this->assertSame(SwapRules::ONE_PER_DAY, SwapRules::checkCanAsk([[1, 3, 'Awaiting']], 1, 2));
        $this->assertSame(SwapRules::ONE_PER_DAY, SwapRules::checkCanAsk([[3, 1, 'Awaiting']], 1, 2));  // I accepted another
        $this->assertNull(SwapRules::checkCanAsk([[3, 1, 'Pending']], 1, 2));                          // asked of me, not answered
    }

    public function testAcceptingIsRefusedWhileAnotherCommitmentIsOpen(): void
    {
        $this->assertNull(SwapRules::checkCanAccept([], 1));
        $this->assertNull(SwapRules::checkCanAccept([[3, 1, 'Pending']], 1));                          // another asked of me: not mine yet
        $this->assertSame(SwapRules::ONE_PER_DAY_ACCEPT, SwapRules::checkCanAccept([[1, 3, 'Pending']], 1));
        $this->assertSame(SwapRules::ONE_PER_DAY_ACCEPT, SwapRules::checkCanAccept([[3, 1, 'Awaiting']], 1));
    }
}
