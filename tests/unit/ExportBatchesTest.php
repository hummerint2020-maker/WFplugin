<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Reports\EmployeeSummary;
use WorkforceOne\Reports\Timesheet;
use WorkforceOne\Reports\Workforce;
use WorkforceOne\Support\RowSpool;

/** 3.31.149: the Report Center's exports read the employees a group at a time; the figures must be those of all the days at once. */
final class ExportBatchesTest extends TestCase
{
    /** @return array<int,array<string,mixed>> employee-days in report_days() order (by employee, then date) */
    private static function days(): array
    {
        $buckets = ['Present', 'Late', 'Absent', 'Leave', 'Holiday', 'Business Trip', 'Pending'];
        $people = [[7, 'zed'], [3, 'Amy'], [9, 'amy'], [4, 'Bob'], [5, 'Amy']];   // names that tie under strcasecmp, out of id order
        $out = [];
        foreach ($people as $pi => [$id, $name]) {
            for ($i = 0; $i < 9; $i++) {
                $b = $buckets[($i + $pi) % count($buckets)];
                $out[] = ['employee_id' => $id, 'employee' => $name, 'domain' => strtolower($name) . $id, 'teams' => ['T' . ($id % 2)], 'date' => '2026-01-0' . ($i + 1),
                    'bucket' => $b, 'result' => $b === 'Business Trip' ? 'Office' : $b, 'planned' => $b === 'Leave' ? ($i % 2 ? 'Vacation' : 'Sick') : ($i % 3 ? 'Office' : 'WFH'), 'rule' => null,
                    'expected' => in_array($b, ['Present', 'Late', 'Absent', 'Pending'], true), 'late_minutes' => $b === 'Late' ? 10 + $i : 0, 'early_minutes' => $i % 4,
                    'missing_sign_out' => $i % 5 === 0, 'other_branch' => $i === 2, 'net_minutes' => in_array($b, ['Present', 'Late'], true) ? 400 + $i : 0,
                    'expected_minutes' => in_array($b, ['Present', 'Late', 'Absent'], true) ? 450 : 0, 'sign_in' => in_array($b, ['Present', 'Late'], true) ? sprintf('%02d:%02d', 8 + $i % 2, 5 * $i) : '',
                    'ot_approved' => $i, 'ot_actual' => 2 * $i, 'ot_extra' => $i % 3];
            }
        }
        return $out;
    }

    /** @return array<int,array<int,array<string,mixed>>> the days split after whole employees, every $size employees */
    private static function split(array $days, int $size): array
    {
        $groups = [];
        $seen = [];
        foreach ($days as $d) {
            $id = $d['employee_id'];
            if (!isset($seen[$id])) $seen[$id] = count($seen);
            $groups[intdiv($seen[$id], $size)][] = $d;
        }
        return array_values($groups);
    }

    public function testEmployeeSummaryOverAnySplitIsTheWhole(): void
    {
        $days = self::days();
        foreach ([1, 2, 3, 5] as $size) {
            $acc = EmployeeSummary::start();
            foreach (self::split($days, $size) as $part) $acc = EmployeeSummary::accumulate($acc, $part);
            $this->assertSame(EmployeeSummary::byEmployee($days), EmployeeSummary::finishByEmployee($acc), 'by employee, groups of ' . $size);
            $this->assertSame(EmployeeSummary::totals($days), EmployeeSummary::finishTotals($acc), 'totals, groups of ' . $size);
        }
        $this->assertSame([3, 9, 5, 4, 7], array_keys(EmployeeSummary::byEmployee($days)), 'the names that tie keep the order they came in');
    }

    public function testTimesheetOverAnySplitIsTheWhole(): void
    {
        $days = self::days();
        foreach ([1, 2, 4] as $size) {
            $acc = [];
            foreach (self::split($days, $size) as $part) $acc = Timesheet::accumulate($acc, new ArrayIterator($part));
            $this->assertSame(Timesheet::byEmployee($days), Timesheet::finish($acc), 'groups of ' . $size);
        }
    }

    public function testWorkforceMergeIsByDayOfTheWhole(): void
    {
        $days = self::days();
        foreach ([1, 2, 3] as $size) {
            $all = [];
            foreach (self::split($days, $size) as $part) $all = Workforce::merge($all, Workforce::byDay($part));
            $this->assertSame(Workforce::byDay($days), $all, 'groups of ' . $size);
        }
        $this->assertSame([], Workforce::merge([], []));
    }

    public function testRowSpoolKeepsOrderAndTypes(): void
    {
        $rows = [['a', 1, 2.5, '', '0012', null, true], ["\xff\xfe not UTF-8", -3, '7', 'ت'], [], ['k' => 'v']];
        $s = new RowSpool();
        foreach ($rows as $r) $s->add($r);
        $this->assertCount(4, $s);
        $this->assertSame($rows, iterator_to_array($s));
        $this->assertSame($rows, iterator_to_array($s), 'read twice');
        $s->add(['after']);
        $this->assertSame(array_merge($rows, [['after']]), iterator_to_array($s), 'added after a read');
        $this->assertSame([], iterator_to_array(new RowSpool()));
    }

    public function testRowSpoolBeyondItsMemoryGoesToAFile(): void
    {
        $s = new RowSpool();
        $row = [str_repeat('x', 5000), 12345];
        for ($i = 0; $i < 1000; $i++) $s->add($row + [2 => $i]);
        $n = 0;
        foreach ($s as $i => $r) { $this->assertSame($row + [2 => $i], $r); $n++; }
        $this->assertSame(1000, $n);
    }
}
