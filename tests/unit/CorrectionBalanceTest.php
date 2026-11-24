<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\CorrectionBalance as B;
use WorkforceOne\Settings\CorrectionSettings;

/** Corrections balance in My requests (3.31.163, docs/tasks/corrections-balance.md). */
final class CorrectionBalanceTest extends TestCase
{
    public function testLeftThisMonth(): void
    {
        $s = CorrectionSettings::config([]);
        $this->assertSame(['left' => 3, 'limit' => 3, 'used' => 0, 'after' => ''], B::card($s, 0), 'the built-in limit: 3 a month');
        $this->assertSame(['left' => 1, 'limit' => 3, 'used' => 2, 'after' => ''], B::card($s, 2));
    }

    public function testNoneLeft(): void
    {
        $hr = CorrectionSettings::config(['monthly_limit' => 2, 'above_limit' => 'hr']);
        $this->assertSame(['left' => 0, 'limit' => 2, 'used' => 2, 'after' => 'hr'], B::card($hr, 2), 'the next ones go to HR');
        $this->assertSame(0, B::card($hr, 5)['left'], 'never below zero (HR approved more than the limit)');
        $refuse = CorrectionSettings::config(['monthly_limit' => 2, 'above_limit' => 'refuse']);
        $this->assertSame('refuse', B::card($refuse, 2)['after'], 'no more this month');
        $this->assertSame('', B::card($refuse, 1)['after'], 'only once none are left');
    }

    public function testNoLimitShowsNothing(): void
    {
        $this->assertNull(B::card(CorrectionSettings::config(['monthly_limit' => 0]), 4), '0 = no limit: nothing to count down');
        $this->assertNull(B::card([], 0), 'no settings: no card');
    }

    public function testBadCountIsZero(): void
    {
        $this->assertSame(3, B::card(CorrectionSettings::config([]), -1)['left']);
    }
}
