<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Organization\ImportPlan as P;

final class ImportPlanTest extends TestCase
{
    /** 1 head; 2 and 3 under 1; 4 under 2; 5 archived; 6 under archived 5. */
    private function people(): array
    {
        $p = static function ($id, $name, $domain, $title, $manager, $active = true) { return compact('id', 'name', 'domain', 'title', 'manager', 'active'); };
        return [1 => $p(1, 'Ahmed', 'ahmed', 'CEO', 0), 2 => $p(2, 'Mona', 'mona', 'Director', 1), 3 => $p(3, 'Karim', 'karim', 'Director', 1),
            4 => $p(4, 'Nour', 'nour', 'Lead', 2), 5 => $p(5, 'Omar', 'omar', 'Manager', 1, false), 6 => $p(6, 'Sara', 'sara', '', 5)];
    }

    public function testChangesAndUnchangedRows(): void
    {
        $r = P::build([['Employee ID', 'Name', 'Job title', 'Manager ID'], ['4', 'Nour', 'Lead', '3'], ['3', 'Karim', 'COO', '1'], ['2', 'Mona', '', '1'], ['', '', '', '']], $this->people());
        $this->assertNull($r['error']);
        $this->assertSame(3, $r['rows'], 'empty rows are not counted');
        $this->assertSame([['row' => 2, 'employee' => 4, 'from' => 2, 'to' => 3]], $r['moves']);
        $this->assertSame([['row' => 3, 'employee' => 3, 'from' => 'Director', 'to' => 'COO']], $r['titles']);
        $this->assertSame(1, $r['unchanged'], 'Mona: same manager, empty title keeps hers');
    }

    public function testByDomainNameAnyHeaderCaseAndNoManager(): void
    {
        $r = P::build([['DOMAIN NAME', ' manager  domain name '], ['Nour', ''], ['sara', 'karim']], $this->people());
        $this->assertSame([['row' => 2, 'employee' => 4, 'from' => 2, 'to' => 0], ['row' => 3, 'employee' => 6, 'from' => 5, 'to' => 3]], $r['moves']);
    }

    public function testRowsThatCannotBeUsed(): void
    {
        $r = P::build([['Employee ID', 'Manager ID', 'Manager domain name'], ['99', '1', ''], ['5', '1', ''], ['4', '', 'nobody'], ['4', '1', ''], ['3', '5', ''], ['2', '2', ''], ['2', 'x', '']], $this->people());
        $codes = array_column($r['problems'], 'code', 'row');
        $this->assertSame([2 => 'employee_unknown', 3 => 'employee_archived', 4 => 'manager_unknown', 5 => 'duplicate', 6 => 'manager_archived', 7 => 'self', 8 => 'duplicate'], $codes);
        $this->assertSame([], $r['moves']);
    }

    public function testALoopIsCheckedWithTheWholeFile(): void
    {
        // 1 under 4 (who is under 2, under 1): refused. 2 under 3 and 3 under 2 together: both refused.
        $r = P::build([['Employee ID', 'Manager ID'], ['1', '4'], ['2', '3'], ['3', '2']], $this->people());
        $this->assertSame([2 => 'loop', 3 => 'loop', 4 => 'loop'], array_column($r['problems'], 'code', 'row'));
        $this->assertSame([], $r['moves']);
        // Swapped in one file: 2 under 4 while 4 leaves 2 for 1, fine.
        $r = P::build([['Employee ID', 'Manager ID'], ['2', '4'], ['4', '1']], $this->people());
        $this->assertSame([], $r['problems']);
        $this->assertCount(2, $r['moves']);
    }

    public function testFileErrors(): void
    {
        $this->assertSame('empty', P::build([], $this->people())['error']);
        $this->assertSame('columns', P::build([['Name', 'Title']], $this->people())['error']);
        $this->assertSame('columns', P::build([['Employee ID', 'Name']], $this->people())['error'], 'nothing to change');
        $this->assertSame('too_many', P::build(array_fill(0, P::MAX_ROWS + 2, ['Employee ID', 'Manager ID']), $this->people())['error']);
    }
}
