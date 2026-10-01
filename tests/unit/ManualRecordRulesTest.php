<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\ManualRecordRules as R;
use WorkforceOne\Support\Csv;

final class ManualRecordRulesTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['type' => 'sign_in', 'date_valid' => true, 'work_date' => '2026-10-01', 'event_at' => '2026-10-01 09:00:00', 'employee_found' => true, 'others' => []], $over);
    }

    public function testEventAt(): void
    {
        $this->assertSame('2026-10-01 09:05:00', R::eventAt('2026-10-01T09:05'));
        $this->assertSame('2026-10-01 09:05:30', R::eventAt('2026-10-01 09:05:30'));
        $this->assertNull(R::eventAt('2026-10-01 25:00'));
        $this->assertNull(R::eventAt('yesterday'));
    }

    public function testChecks(): void
    {
        $this->assertNull(R::check($this->f()));
        $this->assertSame('invalid', R::check($this->f(['type' => 'coffee'])));
        $this->assertSame('invalid', R::check($this->f(['event_at' => null])));
        $this->assertSame('employee', R::check($this->f(['employee_found' => false])));
        $this->assertSame('date_mismatch', R::check($this->f(['event_at' => '2026-09-30 09:00:00'])));
        $this->assertSame('duplicate', R::check($this->f(['type' => 'late_sign_in', 'others' => [['type' => 'sign_in', 'at' => '2026-10-01 08:00:00']]])));
        $this->assertSame('duplicate', R::check($this->f(['type' => 'sign_out', 'event_at' => '2026-10-01 18:00:00', 'others' => [['type' => 'sign_out', 'at' => '2026-10-01 17:00:00']]])));
        $this->assertSame('order', R::check($this->f(['type' => 'sign_out', 'event_at' => '2026-10-01 08:00:00', 'others' => [['type' => 'sign_in', 'at' => '2026-10-01 09:00:00']]])));
        $this->assertSame('order', R::check($this->f(['event_at' => '2026-10-01 18:00:00', 'others' => [['type' => 'sign_out', 'at' => '2026-10-01 17:00:00']]])));
        $this->assertNull(R::check($this->f(['type' => 'sign_out', 'event_at' => '2026-10-01 17:00:00', 'others' => [['type' => 'sign_in', 'at' => '2026-10-01 09:00:00']]])));
    }

    public function testCsvCells(): void
    {
        $this->assertSame("'=HYPERLINK(1)", Csv::cell('=HYPERLINK(1)'));
        $this->assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        $this->assertSame("'+cmd", Csv::cell('+cmd'));
        $this->assertSame('-12.5', Csv::cell('-12.5'), 'negative numbers stay numbers');
        $this->assertSame('Emp One', Csv::cell('Emp One'));
        $this->assertSame(['a', "'=b", '3'], Csv::row(['a', '=b', 3]));
    }
}
