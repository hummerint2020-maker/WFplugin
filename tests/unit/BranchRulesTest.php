<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\BranchRules as B;
use WorkforceOne\Settings\BranchSettings;

final class BranchRulesTest extends TestCase
{
    public function testCandidates(): void
    {
        $this->assertSame(['expected' => 5, 'allowed' => [5], 'flag_other' => false], B::candidates('single', 5, [6, 7], 0, true));
        $this->assertSame(['expected' => 5, 'allowed' => [5, 6, 7], 'flag_other' => false], B::candidates('any', 5, [6, 5, 7], 0, true));
        $this->assertSame(['expected' => 6, 'allowed' => [6, 5, 7], 'flag_other' => true], B::candidates('schedule', 5, [6, 7], 6, true));
        $this->assertSame(['expected' => 6, 'allowed' => [6], 'flag_other' => true], B::candidates('schedule', 5, [6, 7], 6, false));
        $this->assertSame(5, B::candidates('schedule', 5, [6], 0, true)['expected'], 'no branch planned: their main branch');
        $this->assertSame(['expected' => 0, 'allowed' => [0], 'flag_other' => false], B::candidates('nonsense', 0, [], 0, false));
    }

    public function testPick(): void
    {
        $sites = [1 => ['distance' => 900.0, 'radius' => 300.0], 2 => ['distance' => 120.0, 'radius' => 300.0], 3 => ['distance' => 80.0, 'radius' => 300.0]];
        $this->assertSame([3, B::OTHER], B::pick([1, 2, 3], 1, $sites, true), 'the nearest accepted branch they are inside');
        $this->assertSame([3, ''], B::pick([1, 2, 3], 1, $sites, false), '"Any of their branches" flags nothing');
        $this->assertSame([2, ''], B::pick([2, 3], 2, $sites, true), 'inside the expected branch: that one');
        $this->assertSame([1, ''], B::pick([1], 1, $sites, true), 'nowhere accepted: the expected branch (its geofence decides)');
        $this->assertSame([1, ''], B::pick([1, 2], 1, [1 => ['distance' => null, 'radius' => 300.0], 2 => ['distance' => null, 'radius' => 300.0]], true), 'no position');
    }

    public function testKiosk(): void
    {
        $this->assertTrue(B::kioskAllowed(2, [1, 2], true, 1));
        $this->assertFalse(B::kioskAllowed(3, [1, 2], true, 1));
        $this->assertFalse(B::kioskAllowed(2, [1, 2], false, 1));
        $this->assertTrue(B::kioskAllowed(1, [1, 2], false, 1));
    }

    public function testSettings(): void
    {
        $this->assertSame(['mode' => 'single', 'allow_others' => 1, 'kiosk_any' => 1, 'show_branch' => 1, 'manager_scope' => 0], BranchSettings::config(null));
        $this->assertSame('schedule', BranchSettings::config(['mode' => 'schedule'])['mode']);
        $this->assertSame('single', BranchSettings::config(['mode' => 'everywhere'])['mode']);
        $this->assertSame(['mode' => 'any', 'allow_others' => 0, 'kiosk_any' => 1, 'show_branch' => 0, 'manager_scope' => 1],
            BranchSettings::fromPost(['branch_mode' => 'any', 'branch_kiosk_any' => '1', 'branch_manager_scope' => 'on']));
    }
}
