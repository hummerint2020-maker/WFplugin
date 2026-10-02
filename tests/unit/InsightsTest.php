<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\Insights as I;

final class InsightsTest extends TestCase
{
    public function testPlanCategory(): void
    {
        $this->assertSame('Office', I::planCategory('Office', 'attendance'));
        $this->assertSame('Leave', I::planCategory('Vacation', 'leave'));
        $this->assertSame('Business Trip', I::planCategory('Training Course', 'business_trip'));
        $this->assertSame('Other', I::planCategory('Field Visit', 'attendance'));
        $this->assertSame('Not Set', I::planCategory('', null));
        $this->assertSame('Not Set', I::planCategory('Deleted Type', null));
        $this->assertSame('Absent', I::planCategory('Absent', null));
    }

    private function f(array $over): array
    {
        return array_merge(['holiday' => false, 'rule' => 'attendance', 'requires_sign_in' => true, 'classification' => null, 'legacy_late' => false,
            'date' => '2026-10-01', 'today' => '2026-10-02', 'past_cutoff' => false], $over);
    }

    public function testActual(): void
    {
        $this->assertSame('Present', I::actual($this->f(['classification' => 'On Time']), 'Office'));
        $this->assertSame('Late', I::actual($this->f(['classification' => 'Late Arrival']), 'Office'));
        $this->assertSame('Late', I::actual($this->f(['classification' => 'On Time', 'legacy_late' => true]), 'Office'));
        $this->assertSame('Absent', I::actual($this->f([]), 'Office'));
        $this->assertSame('Pending', I::actual($this->f(['date' => '2026-10-02']), 'Office'));
        $this->assertSame('Absent', I::actual($this->f(['date' => '2026-10-02', 'past_cutoff' => true]), 'Office'));
        $this->assertSame('Leave', I::actual($this->f(['holiday' => true]), 'Office'));
        $this->assertSame('Leave', I::actual($this->f(['rule' => 'leave']), 'Vacation'));
        $this->assertSame('Business Trip', I::actual($this->f(['rule' => 'business_trip']), 'Business Trip'));
        $this->assertSame('Not Scheduled', I::actual($this->f(['requires_sign_in' => false, 'rule' => null]), ''));
    }

    public function testRate(): void
    {
        $this->assertSame(67, I::rate(1, 1, 1));
        $this->assertSame(0, I::rate(0, 0, 0));
    }

    public function testValidDate(): void
    {
        $this->assertSame('2026-02-28', I::validDate('2026-02-28', 'x'));
        $this->assertSame('x', I::validDate('2026-02-31', 'x'));
        $this->assertSame('x', I::validDate('tomorrow', 'x'));
        $this->assertSame('x', I::validDate('2026-2-3', 'x'));
    }
}
