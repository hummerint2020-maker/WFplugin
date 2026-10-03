<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\TodayStatus as T;
use WorkforceOne\Audit\AuditFilters as A;

final class DashboardAuditTest extends TestCase
{
    public function testTodayStatus(): void
    {
        $this->assertSame('no_show', T::status(null, null));
        $this->assertSame('sign_in', T::status('sign_in', 'On Time'));
        $this->assertSame('late_arrival', T::status('sign_in', 'Late Arrival'));
        $this->assertSame('late_arrival', T::status('late_sign_in', 'On Time'));
        $this->assertSame(['sign_in' => 1, 'late_arrival' => 1, 'no_show' => 2], T::counts(['sign_in', 'late_arrival', 'no_show', 'x']));
        $this->assertSame('Late Arrival', T::label('late_arrival'));
    }

    public function testAuditFilters(): void
    {
        $this->assertSame(['2026-09-14', '2026-09-21'], A::range('2026-09-21', '2026-09-14'));
        $this->assertSame(['', '2026-09-21'], A::range('2026-13-45', '2026-09-21'));
        $this->assertSame(['page' => 2, 'pages' => 2, 'offset' => 50, 'first' => 51, 'last' => 59], A::paging(59, 2));
        $this->assertSame(1, A::paging(0, 5)['page']);
        $this->assertSame(0, A::paging(0, 1)['first']);
        $this->assertSame(2, A::paging(59, 99)['page'], 'past the end clamps to the last page');
    }
}
