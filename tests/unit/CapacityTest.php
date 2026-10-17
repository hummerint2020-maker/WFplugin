<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\Capacity as C;

final class CapacityTest extends TestCase
{
    public function testLevels(): void
    {
        $this->assertSame(['ok', 'warn', 'warn', 'over', 'none', 'none'], [C::level(8, 10), C::level(9, 10), C::level(10, 10), C::level(11, 10), C::level(50, null), C::level(1, 0)]);
        $this->assertSame([133, null, 0], [C::pct(4, 3), C::pct(4, null), C::pct(0, 5)]);
    }

    public function testGrid(): void
    {
        $seat = static function (int $loc, string $date, bool $in = false, bool $mine = false): array { return ['location_id' => $loc, 'date' => $date, 'signed_in' => $in, 'mine' => $mine]; };
        $g = C::grid([['id' => 1, 'name' => 'HQ', 'seats' => 3], ['id' => 2, 'name' => 'Branch', 'seats' => null]], [
            $seat(1, '2026-01-04', true, true), $seat(1, '2026-01-04'),
            $seat(1, '2026-01-06', true, true), $seat(1, '2026-01-06', false, true), $seat(1, '2026-01-06', false, true), $seat(1, '2026-01-06'),
            $seat(2, '2026-01-06'), $seat(9, '2026-01-06'), $seat(1, '2025-12-31'),
        ], ['2026-01-04', '2026-01-05', '2026-01-06'], '2026-01-05');
        $hq = $g[1];
        $this->assertSame(['planned' => 2, 'actual' => 1, 'mine' => 1, 'pct' => 67, 'level' => 'ok'], $hq['days']['2026-01-04']);
        $this->assertSame(['planned' => 4, 'actual' => null, 'mine' => 3, 'pct' => 133, 'level' => 'over'], $hq['days']['2026-01-06'], 'a future day has no actual');
        $this->assertSame([4, 1, 0], [$hq['peak'], $hq['days_over'], $hq['days_warn']]);
        $this->assertSame(['none', null, 1], [$g[2]['days']['2026-01-06']['level'], $g[2]['days']['2026-01-06']['pct'], $g[2]['days']['2026-01-06']['planned']]);
    }

    /** Branches (3.31.72): someone planned at one branch who signs in at another counts there as actual. */
    public function testActualAtAnotherBranch(): void
    {
        $g = C::grid([['id' => 1, 'name' => 'HQ', 'seats' => 3], ['id' => 2, 'name' => 'Branch', 'seats' => null]], [
            ['location_id' => 1, 'date' => '2026-01-04', 'signed_in' => true, 'mine' => false, 'actual_location_id' => 2],
            ['location_id' => 1, 'date' => '2026-01-04', 'signed_in' => true, 'mine' => false, 'actual_location_id' => 9],
            ['location_id' => 1, 'date' => '2026-01-04', 'signed_in' => true, 'mine' => false],
        ], ['2026-01-04'], '2026-01-05');
        $this->assertSame([3, 2], [$g[1]['days']['2026-01-04']['planned'], $g[1]['days']['2026-01-04']['actual']], 'planned where planned; an unknown branch counts where planned');
        $this->assertSame([0, 1], [$g[2]['days']['2026-01-04']['planned'], $g[2]['days']['2026-01-04']['actual']]);
    }
}
