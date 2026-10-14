<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\RequestRules as R;
use WorkforceOne\Leave\TypeRules as T;

final class LeaveTypeRulesTest extends TestCase
{
    public function testConfigDefaultsAreTodaysBehaviour(): void
    {
        $this->assertSame(['kind' => 'planned', 'after_days' => 0, 'carry' => 'none', 'carry_max' => 0.0], T::config(null));
        $this->assertSame(['kind' => 'planned', 'after_days' => 0, 'carry' => 'none', 'carry_max' => 0.0], T::config(['kind' => 'x', 'after_days' => 9, 'carry_mode' => 'y']), 'planned has no days after');
        $c = T::config((object) ['kind' => 'unplanned', 'after_days' => '500', 'carry_mode' => 'max', 'carry_max' => '-2']);
        $this->assertSame(T::MAX_AFTER_DAYS, $c['after_days']);
        $this->assertSame(0.0, $c['carry_max']);
    }

    public function testEarliestStart(): void
    {
        $this->assertSame('2026-10-15', T::earliestStart(T::config(null), '2026-10-14'), 'planned: tomorrow');
        $this->assertSame('2026-10-14', T::earliestStart(T::config(['kind' => 'unplanned']), '2026-10-14'), 'unplanned: the day itself');
        $this->assertSame('2026-09-30', T::earliestStart(T::config(['kind' => 'unplanned', 'after_days' => 3]), '2026-10-03'), 'across a month');
    }

    public function testCarried(): void
    {
        $this->assertSame(0.0, T::carried(T::config(null), 21, 15, 0));
        $this->assertSame(6.0, T::carried(T::config(['carry_mode' => 'all']), 21, 15, 0));
        $this->assertSame(5.0, T::carried(T::config(['carry_mode' => 'max', 'carry_max' => 5]), 15, 4, 1));
        $this->assertSame(2.5, T::carried(T::config(['carry_mode' => 'max', 'carry_max' => 5]), 10, 7, 0.5));
        $this->assertSame(0.0, T::carried(T::config(['carry_mode' => 'all']), 10, 12, 0), 'more used than given: nothing, not negative');
    }

    public function testFromPost(): void
    {
        $this->assertSame(['kind' => 'unplanned', 'after_days' => 3, 'carry_mode' => 'max', 'carry_max' => 5.5], T::fromPost(['kind' => 'unplanned', 'after_days' => '3', 'carry_mode' => 'max', 'carry_max' => '5.5']));
        $this->assertSame(['kind' => 'planned', 'after_days' => 0, 'carry_mode' => 'none', 'carry_max' => 0.0], T::fromPost([]));
    }

    private function f(array $over): array
    {
        return array_merge(['type_active' => true, 'start' => '2026-10-15', 'end' => '2026-10-15', 'today' => '2026-10-14', 'working_days' => 1,
            'overlaps' => false, 'deducts' => true, 'has_balance' => true, 'remaining' => 10.0], $over);
    }

    public function testRequestWindow(): void
    {
        $this->assertNull(R::check($this->f([])), 'without a type rule: from tomorrow, as before');
        $this->assertSame('date', R::check($this->f(['start' => '2026-10-14', 'end' => '2026-10-14'])));
        $this->assertSame('date', R::check($this->f(['start' => '2026-10-14', 'end' => '2026-10-14', 'earliest' => '2026-10-15'])), 'planned');
        $this->assertNull(R::check($this->f(['start' => '2026-10-11', 'end' => '2026-10-12', 'earliest' => '2026-10-11', 'unplanned' => true])), 'unplanned, days before today');
        $this->assertSame('too_late', R::check($this->f(['start' => '2026-10-10', 'end' => '2026-10-12', 'earliest' => '2026-10-11', 'unplanned' => true])));
        $this->assertSame('date', R::check($this->f(['start' => '2026-10-12', 'end' => '2026-10-11', 'earliest' => '2026-10-01', 'unplanned' => true])), 'end before start');
        $this->assertSame('date', R::check($this->f(['start' => 'x', 'earliest' => '2026-10-01'])));
    }
}
