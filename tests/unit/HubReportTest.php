<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Requests\HubReport as H;
use WorkforceOne\Schedule\SwapInbox;

/** The Requests Hub's Reports tab (3.31.159, docs/tasks/hub-reports.md). */
final class HubReportTest extends TestCase
{
    private const T = '2026-11-22';

    public function testStatusOfEachType(): void
    {
        $cases = [
            ['leave', ['status' => 'Pending'], H::WAITING], ['leave', ['status' => 'Approved', 'cancellation_status' => 'Pending'], H::APPROVED],
            ['leave', ['status' => 'Approved', 'cancellation_status' => 'Approved'], H::APPROVED],   // shortened: the leave stands
            ['leave', ['status' => 'Rejected'], H::REJECTED], ['leave', ['status' => 'Cancelled', 'cancellation_status' => 'Approved'], H::CANCELLED],
            ['leave', ['status' => 'Cancelled', 'cancellation_status' => 'Not Requested'], H::WITHDRAWN], ['leave', ['status' => 'Cancelled'], H::WITHDRAWN],
            ['leave_cancellation', ['cancellation_status' => 'Pending'], H::WAITING], ['leave_cancellation', ['cancellation_status' => 'Approved'], H::APPROVED],
            ['leave_cancellation', ['cancellation_status' => 'Ended'], H::APPROVED], ['leave_cancellation', ['cancellation_status' => 'Rejected'], H::REJECTED],
            ['leave_cancellation', ['cancellation_status' => 'Withdrawn'], H::WITHDRAWN],
            ['overtime', ['status' => 'Cancelled'], H::WITHDRAWN], ['early_leave', ['status' => 'Approved'], H::APPROVED],
            ['correction', ['status' => 'pending_hr'], H::WAITING], ['correction', ['status' => 'cancelled'], H::WITHDRAWN], ['correction', ['status' => 'rejected'], H::REJECTED],
            ['hr_letter', ['status' => 'issued'], H::APPROVED], ['hr_letter', ['status' => 'ready'], H::APPROVED], ['hr_letter', ['status' => 'cancelled'], H::CANCELLED],
            ['hr_letter', ['status' => 'withdrawn'], H::WITHDRAWN], ['legacy_vacation', ['status' => 'Approved'], H::APPROVED], ['face_reset', ['status' => 'rejected'], H::REJECTED],
        ];
        foreach ($cases as [$type, $row, $want]) $this->assertSame($want, H::status($type, $row, self::T), $type . ' ' . json_encode($row));
    }

    /** The swaps follow SwapInbox::state, except that a day passed is Cancelled ("The day passed", owner decision 9). */
    public function testSwapStatusFollowsTheInbox(): void
    {
        $map = ['waiting' => H::WAITING, 'approved' => H::APPROVED, 'rejected' => H::REJECTED, 'withdrawn' => H::WITHDRAWN, 'expired' => H::CANCELLED, 'cancelled' => H::CANCELLED];
        foreach (['Pending', 'Awaiting', 'Accepted', 'Approved', 'Rejected', 'Declined', 'Cancelled', 'Expired', 'Odd'] as $st) {
            foreach (['2026-11-21', self::T, '2026-11-23'] as $day) {
                $this->assertSame($map[SwapInbox::state($st, $day, self::T)], H::status('shift_swap', ['status' => $st, 'work_date' => $day], self::T), "$st $day");
            }
        }
    }

    /** The SQL CASE is built from the same map: it lists every rule, in order, with the default. */
    public function testCaseSqlComesFromTheMap(): void
    {
        $q = function ($v) { return "'" . $v . "'"; };
        $this->assertSame("CASE WHEN r.status IN ('Pending') AND r.work_date>='2026-11-22' THEN 1 WHEN r.status IN ('Awaiting') THEN 1 WHEN r.status IN ('Accepted','Approved') THEN 2"
            . " WHEN r.status IN ('Rejected','Declined') THEN 3 WHEN r.status IN ('Cancelled') THEN 4 ELSE 5 END", H::caseSql('shift_swap', 'r', $q, self::T));
        $this->assertStringContainsString("WHEN r.status IN ('Cancelled') AND r.cancellation_status='Approved' THEN 5 WHEN r.status IN ('Cancelled') THEN 4", H::caseSql('leave', 'r', $q, self::T));
        foreach (array_keys(H::STATUS_MAP) as $type) $this->assertStringEndsWith(' END', H::caseSql($type, 'x', $q, self::T));
    }

    public function testFilter(): void
    {
        $f = H::filter([], self::T);
        $this->assertSame(['2026-11-01', '2026-11-30', '', 0, '', 1], [$f['from'], $f['to'], $f['type'], $f['status'], $f['who'], $f['page']], 'the current month by default');
        $this->assertCount(9, $f['types']);
        $this->assertSame(['2024-02-01', '2024-02-29'], array_slice(array_values(H::filter([], '2024-02-10')), 0, 2), 'a leap February');
        $this->assertSame(['2025-02-01', '2025-02-28'], array_slice(array_values(H::filter([], '2025-02-10')), 0, 2));
        $f = H::filter(['from' => '2026-11-30', 'to' => '2026-11-02', 'type' => 'leave', 'status' => 'rejected', 'who' => 'dept:4', 'paged' => '3'], self::T);
        $this->assertSame(['2026-11-02', '2026-11-30', ['leave', 'legacy_vacation'], H::REJECTED, 'dept:4', 0, 4, 3],
            [$f['from'], $f['to'], $f['types'], $f['status'], $f['who'], $f['employee'], $f['department'], $f['page']], 'swapped dates; the old vacation table is in Leave');
        $f = H::filter(['from' => '2026-02-30', 'type' => 'x', 'status' => 'nope', 'who' => 'emp:abc', 'paged' => '-2'], self::T);
        $this->assertSame(['2026-11-01', '', 0, '', 1], [$f['from'], $f['type'], $f['status'], $f['who'], $f['page']], 'bad input falls back');
        $this->assertSame(['emp', 12], H::parseWho('emp:12'));
        $this->assertSame(['', 0], H::parseWho('emp:0'));
    }

    public function testOutcome(): void
    {
        $s = function ($order, $status, $by = 0, $for = 0, $comment = '') {
            return (object) ['step_order' => $order, 'status' => $status, 'acted_by_wp_user_id' => $by, 'approver_wp_user_id' => $for, 'acted_at' => '2026-11-10 10:00:00', 'comment' => $comment];
        };
        $o = H::outcome([$s(1, 'REJECTED', 7, 7, 'Busy week'), $s(2, 'WAITING')]);
        $this->assertSame(['REJECTED', 7, 0, 1, 2, 'Busy week', 0], [$o['decision'], $o['by'], $o['for'], $o['level'], $o['levels'], $o['comment'], $o['current']], 'rejected at level 1 of 2');
        $o = H::outcome([$s(2, 'APPROVED', 9, 8), $s(1, 'SKIPPED'), $s(3, 'APPROVED', 5, 5)]);
        $this->assertSame(['APPROVED', 5, 0, 2, 2], [$o['decision'], $o['by'], $o['for'], $o['level'], $o['levels']], 'the last approval; skipped levels do not count');
        $o = H::outcome([$s(1, 'APPROVED', 9, 8)]);
        $this->assertSame([9, 8], [$o['by'], $o['for']], 'an administrator or substitute for the approver');
        $o = H::outcome([$s(1, 'APPROVED', 3, 3), $s(2, 'PENDING')]);
        $this->assertSame(['APPROVED', 2], [$o['decision'], $o['current']], 'waiting at level 2');
        $this->assertSame('', H::outcome([])['decision']);
    }

    public function testFacesMergeAndTiles(): void
    {
        $entries = [5 => ['status' => 'pending', 'requested_at' => '2026-11-10 09:00:00'], 6 => ['status' => 'approved', 'requested_at' => '2026-11-12 09:00:00'],
            7 => ['status' => 'rejected', 'requested_at' => '2026-10-30 09:00:00'], 'x' => 'bad'];
        $f = H::filter([], self::T);
        $faces = H::faces($entries, $f, self::T, null);
        $this->assertSame([[6, H::APPROVED], [5, H::WAITING]], array_map(function ($x) { return [$x['id'], $x['status']]; }, $faces), 'in the month, newest first');
        $this->assertSame([5], array_column(H::faces($entries, $f, self::T, [5 => true]), 'id'), 'a department');
        $this->assertSame([], H::faces($entries, H::filter(['type' => 'leave'], self::T), self::T, null), 'another type');
        $keys = [['t' => 1, 'id' => 3, 'at' => '2026-11-15 08:00:00'], ['t' => 3, 'id' => 9, 'at' => '2026-11-11 08:00:00'], ['t' => 1, 'id' => 2, 'at' => '2026-11-11 08:00:00']];
        $all = H::merge($keys, $faces, 0, 10);
        $this->assertSame([[1, 3], [6, 6], [3, 9], [1, 2], [6, 5]], array_map(function ($k) { return [$k['t'], $k['id']]; }, $all), 'one order; equal times by type then id');
        $this->assertSame([[3, 9], [1, 2]], array_map(function ($k) { return [$k['t'], $k['id']]; }, H::merge($keys, $faces, 2, 2)), 'a page of it');
        $t = H::tiles([1 => [H::APPROVED => 4, H::REJECTED => 1], 8 => [H::WAITING => 2]], $faces);
        $this->assertSame(['all' => 9, 'waiting' => 3, 'approved' => 5, 'rejected' => 1, 'withdrawn' => 0, 'cancelled' => 0], $t);
        $this->assertCount(1, H::facesOfStatus($faces, H::WAITING));
        $this->assertSame('face_reset', H::typeName(6));
    }
}
