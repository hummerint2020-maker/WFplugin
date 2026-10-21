<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Organization\MoveRules as R;

final class MoveRulesTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['employee_ok' => true, 'employee_id' => 5, 'manager_id' => 3, 'manager_ok' => true, 'current_manager' => 2,
            'would_loop' => false, 'date' => '2026-10-14', 'today' => '2026-10-14', 'reason' => 'New team', 'reason_required' => true, 'scheduled_exists' => false], $over);
    }

    public function testValid(): void
    {
        $this->assertNull(R::check($this->f()));
        $this->assertNull(R::check($this->f(['manager_id' => 0])), 'to no manager');
        $this->assertNull(R::check($this->f(['reason' => '', 'reason_required' => false])), 'the Employees page and imports need no reason');
        $this->assertNull(R::check($this->f(['manager_id' => 2, 'date' => '2026-10-20'])), 'the same manager on a later date: they may be moved away before');
    }

    public function testErrors(): void
    {
        $this->assertSame('employee', R::check($this->f(['employee_ok' => false])));
        $this->assertSame('self', R::check($this->f(['manager_id' => 5])));
        $this->assertSame('manager', R::check($this->f(['manager_ok' => false])));
        $this->assertSame('date', R::check($this->f(['date' => '2026-02-30'])));
        $this->assertSame('date', R::check($this->f(['date' => '2027-10-20'])), 'more than a year ahead');
        $this->assertSame('date', R::check($this->f(['date' => '2025-10-01'])), 'more than a year back');
        $this->assertNull(R::check($this->f(['date' => '2025-11-01'])), 'a past date within a year is kept');
        $this->assertSame('reason', R::check($this->f(['reason' => '  '])));
        $this->assertSame('scheduled', R::check($this->f(['scheduled_exists' => true])));
        $this->assertSame('same', R::check($this->f(['manager_id' => 2])));
        $this->assertSame('same', R::check($this->f(['manager_id' => 0, 'current_manager' => 0])));
        $this->assertSame('loop', R::check($this->f(['would_loop' => true])));
    }

    public function testScheduledAndReason(): void
    {
        $this->assertTrue(R::isScheduled('2026-10-15', '2026-10-14'));
        $this->assertFalse(R::isScheduled('2026-10-14', '2026-10-14'));
        $this->assertFalse(R::isScheduled('bad', '2026-10-14'));
        $this->assertSame('New team in Alexandria', R::reason("  New <b>team</b>\n in   Alexandria "));
        $this->assertSame(255, mb_strlen(R::reason(str_repeat('سبب ', 100))));
        $this->assertSame(-1, R::daysBetween('2026-10-14', '2026-10-13'));
        $this->assertSame(365, R::daysBetween('2026-01-01', '2027-01-01'));
    }
}
