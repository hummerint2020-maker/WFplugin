<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Payroll\Advances as A;

/** Salary advances (3.31.109): limits, checks, instalments. */
final class AdvancesTest extends TestCase
{
    public function testSettingsDefaults(): void
    {
        $this->assertSame(['months_salary' => 1.0, 'max_amount' => 0.0, 'max_instalments' => 12, 'one_open' => 1, 'min_service_months' => 0], A::settings(null));
        $this->assertSame(60, A::settings(['max_instalments' => 500])['max_instalments']);
        $this->assertSame(0, A::settings(['one_open' => 0])['one_open']);
    }

    public function testLimit(): void
    {
        $s = A::settings(null);
        $this->assertSame(12000.0, A::limit(12000.0, $s), 'one month of salary');
        $this->assertSame(5000.0, A::limit(12000.0, ['max_amount' => 5000] + $s), 'the fixed most is lower');
        $this->assertNull(A::limit(null, $s), 'no salary on file and no fixed most');
        $this->assertSame(5000.0, A::limit(null, ['max_amount' => 5000] + $s));
        $this->assertTrue(is_infinite(A::limit(null, ['months_salary' => 0] + $s)), 'no limit at all');
        $this->assertSame(18000.0, A::limit(12000.0, ['months_salary' => 1.5] + $s));
    }

    public function testCheck(): void
    {
        $s = A::settings(null);
        $f = ['amount' => 8000.0, 'instalments' => 8, 'limit' => 12000.0, 'open' => false, 'service_months' => 36];
        $this->assertNull(A::check($f, $s));
        $this->assertSame('amount', A::check(['amount' => 0.0] + $f, $s));
        $this->assertSame('instalments', A::check(['instalments' => 13] + $f, $s));
        $this->assertSame('no_salary', A::check(['limit' => null] + $f, $s));
        $this->assertSame('limit', A::check(['amount' => 12000.01] + $f, $s));
        $this->assertNull(A::check(['amount' => 12000.0] + $f, $s), 'exactly the limit');
        $this->assertSame('open', A::check(['open' => true] + $f, $s));
        $this->assertNull(A::check(['open' => true] + $f, ['one_open' => 0] + $s));
        $this->assertSame('service', A::check(['service_months' => 2] + $f, ['min_service_months' => 3] + $s));
    }

    public function testSplitAddsUp(): void
    {
        $this->assertSame([1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1000.0, 1000.0], A::split(8000, 8));
        $p = A::split(1000, 3);
        $this->assertSame([333.33, 333.33, 333.34], $p);
        $this->assertSame(1000.0, round(array_sum($p), 2));
    }

    public function testMonthsStartTheMonthAfterPayment(): void
    {
        $this->assertSame(['2026-11', '2026-12', '2027-01'], A::months('2026-10-31', 3));
        $this->assertSame(['2026-02'], A::months('2026-01-31', 1), 'end of January: February');
        $this->assertSame('2027-02', A::after(['2026-12', '2027-01']));
    }

    public function testServiceMonths(): void
    {
        $this->assertSame(31, A::serviceMonths('2023-03-01 09:00:00', '2025-10-17'));
        $this->assertSame(0, A::serviceMonths('2026-10-01', '2026-10-17'));
    }
}
