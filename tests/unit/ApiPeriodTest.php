<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Api\Period;

final class ApiPeriodTest extends TestCase
{
    public function testWithoutParametersThePeriodIsTheWebWeek(): void
    {
        [$p, $e] = Period::parse([], '2026-10-17', 7);
        $this->assertSame([], $e);
        $this->assertSame(['2026-10-17', '2026-10-23'], [$p['from'], $p['to']]);
        $this->assertCount(7, $p['dates']);
        [$p] = Period::parse([], '2026-10-18', 5);
        $this->assertSame('2026-10-22', $p['to'], 'a five-day working week');
    }

    public function testFromAloneIsSevenDaysAndToAloneIsRefused(): void
    {
        [$p] = Period::parse(['from' => '2026-10-19'], '2026-10-17');
        $this->assertSame(['2026-10-19', '2026-10-25'], [$p['from'], $p['to']]);
        [$p, $e] = Period::parse(['to' => '2026-10-19'], '2026-10-17');
        $this->assertNull($p);
        $this->assertSame(['from' => 'required'], $e);
    }

    public function testDatesMustBeRealCalendarDays(): void
    {
        foreach (['2026-02-30', '2026-13-01', '19-10-2026', '2026-1-1', 'tomorrow', '1999-12-31', '2100-01-01'] as $bad) {
            [$p, $e] = Period::parse(['from' => $bad, 'to' => '2026-10-20'], '2026-10-17');
            $this->assertNull($p, $bad);
            $this->assertSame(['from' => 'invalid'], $e, $bad);
        }
        [$p, $e] = Period::parse(['from' => '2026-10-19', 'to' => '2026-02-30'], '2026-10-17');
        $this->assertSame(['to' => 'invalid'], $e);
        $this->assertTrue(Period::validDate('2028-02-29'));
        $this->assertFalse(Period::validDate('2027-02-29'));
    }

    public function testTheRangeIsOrderedAndLimited(): void
    {
        [$p, $e] = Period::parse(['from' => '2026-10-20', 'to' => '2026-10-19'], '2026-10-17');
        $this->assertSame(['to' => 'before_from'], $e);
        [$p, $e] = Period::parse(['from' => '2026-10-01', 'to' => '2026-11-11'], '2026-10-17');
        $this->assertSame([], $e, '42 days are allowed');
        $this->assertCount(42, $p['dates']);
        [$p, $e] = Period::parse(['from' => '2026-10-01', 'to' => '2026-11-12'], '2026-10-17');
        $this->assertSame(['to' => 'too_long', 'max_days' => 42], $e, '43 days are not');
        [$p] = Period::parse(['from' => '2026-10-29', 'to' => '2026-10-29'], '2026-10-17');
        $this->assertSame(['2026-10-29'], $p['dates'], 'one day, on the day the clocks change');
        [$p] = Period::parse(['from' => '2026-10-27', 'to' => '2026-11-02'], '2026-10-17');
        $this->assertSame(['2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], $p['dates'], 'days are counted by date, not by 24 hours');
    }

    public function testNoParameterCanChooseAnotherEmployee(): void
    {
        $this->assertSame([], Period::identityErrors(['from' => '2026-10-19']));
        foreach (Period::IDENTITY_PARAMS as $name) {
            $this->assertSame([$name => 'not_allowed'], Period::identityErrors([$name => '5']), $name);
        }
        $this->assertSame(['employee_id' => 'not_allowed', 'user_id' => 'not_allowed'], Period::identityErrors(['employee_id' => '1', 'user_id' => '2', 'other' => 'x']));
    }
}
