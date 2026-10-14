<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Organization\OrgTree;

final class OrgTreeTest extends TestCase
{
    /** 1 → 2 → 4, 5, 6; 1 → 3 → 7; 8 alone; 9 under archived 99; 10 ↔ 11 in old data. */
    private function tree(): OrgTree
    {
        return new OrgTree([1 => 0, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 2, 7 => 3, 8 => 0, 9 => 99, 10 => 11, 11 => 10]);
    }

    public function testManagersAndReports(): void
    {
        $t = $this->tree();
        $this->assertSame(1, $t->managerOf(2));
        $this->assertSame([4, 5, 6], $t->reportsOf(2));
        $this->assertSame([], $t->reportsOf(4));
        $this->assertSame(6, $t->underCount(1));
        $this->assertSame([2, 4, 5, 6, 3, 7], $t->everyoneUnder(1));
        $this->assertSame([1, 2, 3], $t->managers());
    }

    public function testAManagerWhoIsNotActiveCountsAsNone(): void
    {
        $this->assertSame(0, $this->tree()->managerOf(9));
        $this->assertSame(0, (new OrgTree([5 => 5]))->managerOf(5), 'their own manager');
    }

    public function testALoopInOldDataIsBroken(): void
    {
        $t = $this->tree();
        $this->assertSame(0, $t->managerOf(10));
        $this->assertSame(0, $t->managerOf(11));
        $t = new OrgTree([1 => 3, 2 => 1, 3 => 2, 4 => 3]);
        $this->assertSame([0, 0, 0], [$t->managerOf(1), $t->managerOf(2), $t->managerOf(3)], 'three in a ring');
        $this->assertSame(3, $t->managerOf(4), 'someone under the ring keeps their manager, now at the top');
        $this->assertSame(2, $t->levels());
    }

    public function testLevelsAndChain(): void
    {
        $t = $this->tree();
        $this->assertSame(3, $t->levels());
        $this->assertSame(1, $t->levelOf(1));
        $this->assertSame(3, $t->levelOf(5));
        $this->assertSame([1, 2, 5], $t->chain(5));
        $this->assertSame([8], $t->chain(8));
        $this->assertSame(0, (new OrgTree([]))->levels());
    }

    public function testTeammates(): void
    {
        $t = $this->tree();
        $this->assertSame([4, 6], $t->teammatesOf(5));
        $this->assertSame([], $t->teammatesOf(7), 'the only one under their manager');
        $this->assertSame([], $t->teammatesOf(1), 'nobody above');
    }

    public function testWithoutManagerLeavesOutTheHead(): void
    {
        $this->assertSame([8, 9, 10, 11], $this->tree()->withoutManager());
        $this->assertSame([1, 8, 9, 10, 11], $this->tree()->tops());
    }

    public function testWideSpans(): void
    {
        $t = $this->tree();
        $this->assertSame([2], $t->wideSpans(2));
        $this->assertSame([], $t->wideSpans(3));
        $this->assertSame([], $t->wideSpans(0), '0 = never');
    }

    public function testWouldLoop(): void
    {
        $t = $this->tree();
        $this->assertTrue($t->wouldLoop(1, 5), 'under someone two levels down');
        $this->assertTrue($t->wouldLoop(2, 4));
        $this->assertTrue($t->wouldLoop(4, 4));
        $this->assertFalse($t->wouldLoop(4, 3), 'a move across');
        $this->assertFalse($t->wouldLoop(2, 0));
        $this->assertFalse($t->wouldLoop(8, 1));
    }

    public function testAThousandLevelsDeep(): void
    {
        $m = [1 => 0];
        for ($i = 2; $i <= 1000; $i++) $m[$i] = $i - 1;
        $t = new OrgTree($m);
        $this->assertSame(1000, $t->levels());
        $this->assertSame(999, $t->underCount(1));
        $this->assertTrue($t->wouldLoop(1, 1000));
    }

    public function testLoopsInAndLooped(): void
    {
        $this->assertSame([], OrgTree::loopsIn([1 => 0, 2 => 1]));
        $this->assertEqualsCanonicalizing([2, 3], OrgTree::loopsIn([1 => 0, 2 => 3, 3 => 2, 4 => 2]));
        $this->assertSame([], OrgTree::loopsIn([2 => 99]), 'a manager outside the map ends the line');
        $this->assertEqualsCanonicalizing([10, 11], $this->tree()->looped());
        $this->assertSame([], (new OrgTree([1 => 0, 2 => 1]))->looped());
    }
}
