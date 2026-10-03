<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Leave\AdminRecordRules as R;

final class LeaveAdminRecordRulesTest extends TestCase
{
    private function facts(array $over = []): array
    {
        return array_merge(['start_valid' => true, 'end_valid' => true, 'start' => '2026-03-01', 'end' => '2026-03-02',
            'employee_found' => true, 'type_found' => true, 'working_days' => 2, 'overlaps' => false, 'deducts' => true, 'remaining' => 5.0], $over);
    }

    public function testValidRecordPasses(): void
    {
        $this->assertNull(R::check($this->facts()));
        $this->assertNull(R::check($this->facts(['start' => '2020-01-01', 'end' => '2020-01-02'])), 'past dates are allowed');
    }

    public function testErrorsInOrder(): void
    {
        $this->assertSame(R::DATES, R::check($this->facts(['end_valid' => false])));
        $this->assertSame(R::DATES, R::check($this->facts(['end' => '2026-02-28'])));
        $this->assertSame(R::CROSS_YEAR, R::check($this->facts(['start' => '2026-12-31', 'end' => '2027-01-01'])));
        $this->assertSame(R::EMPLOYEE, R::check($this->facts(['employee_found' => false])));
        $this->assertSame(R::TYPE, R::check($this->facts(['type_found' => false])));
        $this->assertSame(R::NO_WORKING_DAYS, R::check($this->facts(['working_days' => 0])));
        $this->assertSame(R::OVERLAP, R::check($this->facts(['overlaps' => true])));
        $this->assertSame(R::BALANCE, R::check($this->facts(['remaining' => 1.5])));
    }

    public function testNonDeductingTypeIgnoresTheBalance(): void
    {
        $this->assertNull(R::check($this->facts(['deducts' => false, 'remaining' => 0.0])));
    }

    public function testDateChecksWorkBeforeOtherFactsAreKnown(): void
    {
        $this->assertNull(R::check(['start_valid' => true, 'end_valid' => true, 'start' => '2026-03-01', 'end' => '2026-03-01']));
    }

    public function testMessagesAndYears(): void
    {
        $this->assertStringContainsString('1.5 day(s) left', R::message(R::BALANCE, 1.5));
        $this->assertStringContainsString('3 day(s) left', R::message(R::BALANCE, 3.0));
        $this->assertSame([2025, 2026, 2027], R::balanceYears(2026));
    }
}
