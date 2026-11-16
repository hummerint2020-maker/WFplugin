<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Employees\DetailsImportPlan;
use WorkforceOne\Employees\EmployeeDetailsRules as R;

/** Employee details for letters (HL2, docs/tasks/employee-details.md): the rules and the import plan. */
final class EmployeeDetailsTest extends TestCase
{
    public function testCleaning(): void
    {
        $c = R::clean(['name_ar' => "  أحمد\t سمير  ", 'gender' => 'Male', 'hire_date' => '2021-03-01'], '2026-11-08');
        $this->assertNull($c['error']);
        $this->assertSame(['name_ar' => 'أحمد سمير', 'gender' => 'male', 'hire_date' => '2021-03-01'], $c['values']);
        $this->assertSame(['name_ar' => null, 'gender' => null, 'hire_date' => null], R::clean(['name_ar' => ' ', 'gender' => '', 'hire_date' => ''], '2026-11-08')['values'], 'empty is "not set"');
        $this->assertSame([], R::clean([], '2026-11-08')['values'], 'a field not given is left out');
        $this->assertSame('too_long', R::clean(['name_ar' => str_repeat('ا', 191)], '2026-11-08')['error'], 'counted in characters, not bytes');
        $this->assertNull(R::clean(['name_ar' => str_repeat('ا', 190)], '2026-11-08')['error']);
        $this->assertSame('bad_gender', R::clean(['gender' => 'm'], '2026-11-08')['error']);
        $this->assertSame('female', R::clean(['gender' => 'أنثى'], '2026-11-08')['values']['gender'], 'the Arabic words too');
        $this->assertSame('male', R::clean(['gender' => 'ذكر'], '2026-11-08')['values']['gender']);
        $this->assertSame('bad_date', R::clean(['hire_date' => '2021-02-30'], '2026-11-08')['error']);
        $this->assertSame('bad_date', R::clean(['hire_date' => '3/1/2021'], '2026-11-08')['error'], 'ambiguous forms are refused');
        $this->assertSame('future_date', R::clean(['hire_date' => '2026-11-09'], '2026-11-08')['error']);
        $this->assertNull(R::clean(['hire_date' => '2026-11-08'], '2026-11-08')['error'], 'today is allowed');
        $this->assertSame('2021-03-01', R::date('44256', true), 'the number Excel stores a date as (from a file)');
        $this->assertSame('2021-03-01', R::date('44256.75', true), '...a time of day ignored');
        $this->assertFalse(R::date('44256'), 'not typed on the profile');
        $this->assertFalse(R::date('2019', true), 'a year alone is refused, not read as 1905');
        $this->assertFalse(R::date('18263', true), 'serials before 1950 are refused');
        $this->assertSame('1950-01-01', R::date('18264', true));
        $this->assertSame('future_date', R::clean(['hire_date' => '46335'], '2026-11-08', true)['error'], 'a serial in the future');
        $this->assertSame('bad_date', R::clean(['hire_date' => '46335'], '2026-11-08')['error']);
    }

    public function testSinceDiffAndAudit(): void
    {
        $this->assertSame('2021-03-01', R::since('2021-03-01', '2023-01-12 08:00:00'));
        $this->assertSame('2023-01-12', R::since(null, '2023-01-12 08:00:00'), 'the day the employee was added while the hiring date is not set');
        $this->assertSame('', R::since(null, '0000-00-00 00:00:00'));
        $this->assertNull(R::stored((object) ['hire_date' => '0000-00-00'], 'hire_date'), 'the zero date is "not set"');
        $this->assertSame([], R::diff((object) ['hire_date' => '0000-00-00'], ['hire_date' => null]));
        $row = (object) ['name_ar' => null, 'gender' => 'male', 'hire_date' => '2021-03-01'];
        $d = R::diff($row, ['name_ar' => 'أحمد', 'gender' => 'male', 'hire_date' => null]);
        $this->assertSame([['field' => 'name_ar', 'from' => null, 'to' => 'أحمد'], ['field' => 'hire_date', 'from' => '2021-03-01', 'to' => null]], $d);
        $this->assertSame('name_ar: — → أحمد · hire_date: 2021-03-01 → —', R::auditText($d));
    }

    /** @return array<int,object> */
    private function people(): array
    {
        $p = [];
        foreach ([[1, 'Ahmed Samir', 'asamir', 1, null, null, null], [2, 'Mona Adel', 'madel', 1, 'منى عادل', 'female', '2022-01-10'], [3, 'Sara Old', 'sold', 0, null, null, null]] as $r) {
            $p[$r[0]] = (object) ['id' => $r[0], 'name' => $r[1], 'domain_name' => $r[2], 'active' => $r[3], 'name_ar' => $r[4], 'gender' => $r[5], 'hire_date' => $r[6]];
        }
        return $p;
    }

    public function testImportPlan(): void
    {
        $rows = [
            ["\xEF\xBB\xBFEmployee ID", 'Domain name', 'Name', 'name_ar', 'gender', 'hire_date'],
            ['1', 'asamir', 'Ahmed Samir', 'أحمد سمير', 'male', '2021-03-01'],
            ['', 'MADEL', 'Mona Adel', '', '', '2021-12-10'],                  // by domain, any case; empty cells leave the rest
            ['', 'knagy', '', '', 'male', ''],                                   // nobody with that domain
            ['3', '', '', '', 'm', ''],                                          // a former employee, a wrong gender: the whole row refused
            ['1', '', '', 'x', '', ''],                                          // twice
            ['2', 'madel', '', 'منى عادل', 'female', ''],                         // the same as saved? no: it is the second row of employee 2 → duplicate
            ['', '', '', '', '', ''],                                            // blank rows are skipped
            ['3', '', '', '', 'أنثى', ''],
        ];
        $p = DetailsImportPlan::build($rows, $this->people(), '2026-11-08');
        $this->assertNull($p['error']);
        $this->assertSame(7, $p['rows']);
        $this->assertSame([[2, 1, 'name_ar'], [2, 1, 'gender'], [2, 1, 'hire_date'], [3, 2, 'hire_date']], array_map(static function ($c) { return [$c['row'], $c['employee'], $c['field']]; }, $p['changes']));
        $this->assertSame('2022-01-10', $p['changes'][3]['from']);
        $this->assertSame([[4, 'employee_unknown'], [5, 'bad_gender'], [6, 'duplicate'], [7, 'duplicate'], [9, 'duplicate']], array_map(static function ($x) { return [$x['row'], $x['code']]; }, $p['problems']));
        $this->assertSame(0, $p['unchanged'], 'employee 3 was refused first, so its second row is a duplicate');
        $same = DetailsImportPlan::build([['Domain name', 'gender'], ['madel', 'female']], $this->people(), '2026-11-08');
        $this->assertSame([1, []], [$same['unchanged'], $same['changes']], 'a value equal to the saved one is no change');
        $nbsp = DetailsImportPlan::build([['Domain name', 'name_ar', 'hire_date'], ['madel', "\u{00A0} ", '44571']], $this->people(), '2026-11-08');
        $this->assertSame([], $nbsp['changes'], 'a cell of spaces (a no-break space too) leaves the name; the date serial equals the saved 2022-01-10');
        $this->assertSame(1, $nbsp['unchanged']);
        $this->assertSame('columns', DetailsImportPlan::build([['Name', 'gender']], $this->people(), '2026-11-08')['error']);
        $this->assertSame('columns', DetailsImportPlan::build([['Employee ID', 'Name']], $this->people(), '2026-11-08')['error']);
        $this->assertSame('empty', DetailsImportPlan::build([], $this->people(), '2026-11-08')['error']);
        $this->assertSame('too_many', DetailsImportPlan::build(array_merge([['Employee ID', 'gender']], array_fill(0, 5001, ['1', 'male'])), $this->people(), '2026-11-08')['error']);
    }
}
