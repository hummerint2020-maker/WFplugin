<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\OrgDirectory;
use WorkforceOne\People\OrgMoveService;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\ReportingService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** OrgMoveService (3.31.134): the checks, the move in one transaction, the dated move, the daily run with its claims, on in-memory Stores. */
final class OrgMoveServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var OrgMoveService */
    private $svc;

    protected function setUp(): void
    {
        $s = $this->s = new OrgSet();
        $s->people->add(1, 'Mona', 11);
        $s->people->add(2, 'Omar', 12);
        $s->people->add(3, 'Lina', 13);
        $s->people->add(4, 'Sam', 14);
        $s->people->add(5, 'OldBoss', 15, false);
        // lines: [id, employee, related, type, active] — Omar and Lina report to Mona
        $s->rep->lines = [[1, 2, 1, 'supervisor', 1], [2, 3, 1, 'supervisor', 1]];
        $people = new PeopleDirectory($s->people, $s->ports->memo);
        $org = new OrgDirectory($s->people, $s->rep, $s->dep, $s->teams);
        $this->svc = new OrgMoveService($s->moves, new ReportingService($s->rep, $s->clock), $people, $org, $s->tx, $s->audit, $s->ports);
    }

    public function testCheckReadsInTheOrderOfTheOldCodeAndStopsNowhereEarly(): void
    {
        $this->assertNull($this->svc->check(3, 4, '2026-10-28', 'why', true));
        $this->assertSame(['employee', 'manager', 'self', 'date', 'reason', 'same', 'loop'], [
            $this->svc->check(0, 4, '2026-10-28', 'why', true),
            $this->svc->check(3, 5, '2026-10-28', 'why', true),
            $this->svc->check(3, 3, '2026-10-28', 'why', true),
            $this->svc->check(3, 4, 'soon', 'why', true),
            $this->svc->check(3, 4, '2026-10-28', '', true),
            $this->svc->check(3, 1, '2026-10-28', 'why', true),
            $this->svc->check(1, 2, '2026-10-28', 'why', true),
        ]);
        $this->assertNull($this->svc->check(3, 4, '2026-10-28', '', false), 'a move from an import needs no reason');
        $this->assertNull($this->svc->check(1, 2, '2026-10-28', 'why', true, 0, false), 'the loop is not looked for when it is not asked for');
    }

    public function testCheckSeesAScheduledMoveUnlessItIsTheOneBeingDone(): void
    {
        $id = $this->s->moves->add('scheduled', 3, 4, '2026-11-01');
        $this->assertSame('scheduled', $this->svc->check(3, 4, '2026-10-28', 'why', true));
        $this->assertNull($this->svc->check(3, 4, '2026-10-28', 'why', true, $id));
        $this->s->moves->rows[$id]->status = 'applying';
        $this->assertSame('scheduled', $this->svc->check(3, 4, '2026-10-28', 'why', true), 'a claimed move still counts');
        $this->s->moves->rows[$id]->status = 'done';
        $this->assertNull($this->svc->check(3, 4, '2026-10-28', 'why', true));
    }

    public function testApplyDoesEverythingInOneTransactionAndTellsAfterTheCommit(): void
    {
        $r = $this->svc->apply(3, 4, '2026-10-28', 'new team', 'chart');
        $this->assertSame(['ok' => true, 'requests' => 2, 'id' => 1], $r);
        $log = $this->s->w->log;
        $this->assertSame([
            'tx begin', 'reporting deactivate 3 supervisor', 'reporting insertLine 3 supervisor 4 0', 'approvals reassign 3 1>4',
            'moves insert done 3', 'tx commit', 'approvals tell 3 4 2', 'changed 3 13', 'audit org_move 3',
        ], array_values(array_filter($log, function ($l) { return strpos($l, 'moves openIdFor') !== 0; })));
        $row = $this->s->moves->rows[1];
        $this->assertSame(['done', 'chart', '2', 7, '2026-10-28 09:00:00'], [$row->status, $row->source, (string) $row->requests_moved, $row->created_by, $row->applied_at]);
        $this->assertSame('Lina: Mona → Sam from 2026-10-28 · "new team" · 2 waiting approval step(s) moved · chart', $this->s->audit->rows[0][3]);
        $this->assertSame([[101, 3, 4, 'supervisor', 1]], array_values(array_filter($this->s->rep->lines, function ($l) { return $l[1] === 3 && $l[4] === 1; })));
    }

    public function testApplyRollsBackAtEveryWriteAndNothingElseHappens(): void
    {
        foreach (['reporting.deactivate' => 'tx rollback', 'reporting.insertLine' => 'tx rollback', 'approvals.reassign' => 'tx rollback', 'moves.insert' => 'tx rollback', 'tx.commit' => 'tx rollback'] as $op => $expected) {
            $this->setUp();
            $this->s->w->fail[$op] = true;
            $r = $this->svc->apply(3, 4, '2026-10-28', 'x', 'chart');
            $this->assertFalse($r['ok'], $op);
            $this->assertSame(1, count($this->s->w->only('tx rollback')), $op . ': one rollback');
            $this->assertSame([], array_merge($this->s->w->only('approvals tell'), $this->s->w->only('changed'), $this->s->w->only('audit')), $op . ': nobody told, nothing audited');
        }
    }

    public function testAFailedBeginSendsNoRollbackAndChangesNothing(): void
    {
        $this->s->w->fail['tx.begin'] = true;
        $this->assertFalse($this->svc->apply(3, 4, '2026-10-28', 'x', 'chart')['ok']);
        $this->assertSame([], $this->s->w->only('tx rollback'));
        $this->assertSame([], $this->s->w->only('reporting'));
        $this->assertFalse($this->svc->setSupervisorOnly(3, 4));
        $this->assertSame([], $this->s->w->only('tx rollback'));
    }

    public function testAMoveToNoManagerEndsTheLineWithoutAddingOne(): void
    {
        $r = $this->svc->apply(3, 0, '2026-10-28', 'leaves the tree', 'chart');
        $this->assertTrue($r['ok']);
        $this->assertSame(['reporting deactivateSupervisor 3'], $this->s->w->only('reporting'));
        $this->assertStringContainsString('Lina: Mona → no manager from', $this->s->audit->rows[0][3]);
    }

    public function testSetSupervisorOnlyIsOneSmallTransaction(): void
    {
        $this->assertTrue($this->svc->setSupervisorOnly(3, 4));
        $this->assertSame(['tx begin', 'reporting deactivate 3 supervisor', 'reporting insertLine 3 supervisor 4 0', 'tx commit'], $this->s->w->log);
        $this->setUp();
        $this->s->w->fail['reporting.insertLine'] = true;
        $this->assertFalse($this->svc->setSupervisorOnly(3, 4));
        $this->assertSame(1, count($this->s->w->only('tx rollback')));
    }

    public function testScheduleKeepsTheMoveAndReturnsTheIdWhateverTheInsertSaid(): void
    {
        $this->assertSame(1, $this->svc->schedule(3, 4, '2026-11-02', 'later', 'chart'));
        $this->assertSame('scheduled', $this->s->moves->rows[1]->status);
        $this->assertSame('org_move_scheduled', $this->s->audit->rows[0][0]);
        $this->s->w->fail['moves.insert'] = true;
        $this->assertSame(1, $this->svc->schedule(2, 4, '2026-11-02', 'later', 'chart'), 'the failed insert is not looked at (quirk j): the last id, and an audit row');
        $this->assertCount(2, $this->s->audit->rows);
    }

    public function testApplyDueDoesTheDueMovesInPassesAndFailsTheRest(): void
    {
        $a = $this->s->moves->add('scheduled', 3, 4, '2026-10-27');        // Lina under Sam: a loop once Sam is under Lina
        $b = $this->s->moves->add('scheduled', 2, 5, '2026-10-27');        // the manager is archived: failed
        $c = $this->s->moves->add('scheduled', 4, 3, '2026-10-26');        // the older day goes first: Sam under Lina is fine
        $d = $this->s->moves->add('scheduled', 1, 4, '2026-11-30');        // not due
        $this->assertSame(1, $this->svc->applyDue());
        $this->assertSame('done', $this->s->moves->rows[$c]->status);
        $this->assertSame(['failed', 'manager'], [$this->s->moves->rows[$b]->status, $this->s->moves->rows[$b]->note]);
        $this->assertSame(['failed', 'loop'], [$this->s->moves->rows[$a]->status, $this->s->moves->rows[$a]->note], 'a loop that never resolves fails with the reason');
        $this->assertSame('scheduled', $this->s->moves->rows[$d]->status);
        $this->assertSame(['org_move', 'org_move_failed', 'org_move_failed'], array_map(function ($r) { return $r[0]; }, $this->s->audit->rows));
    }

    public function testAPassCanResolveALoopOfTheSameDay(): void
    {
        // Mona -> Omar would loop (Omar reports to Mona); first Omar goes to Sam, then Mona can go under Omar.
        $first = $this->s->moves->add('scheduled', 1, 2, '2026-10-26');
        $second = $this->s->moves->add('scheduled', 2, 4, '2026-10-27');
        $this->assertSame(2, $this->svc->applyDue());
        $this->assertSame(['done', 'done'], [$this->s->moves->rows[$first]->status, $this->s->moves->rows[$second]->status]);
    }

    public function testAMoveAlreadyInPlaceIsDoneWithoutChangingAnything(): void
    {
        $id = $this->s->moves->add('scheduled', 2, 1, '2026-10-28');
        $this->assertSame(0, $this->svc->applyDue());
        $this->assertSame(['done', 'already in place'], [$this->s->moves->rows[$id]->status, $this->s->moves->rows[$id]->note]);
        $this->assertSame([], $this->s->w->only('reporting'));
    }

    public function testTheClaimDecidesWhoDoesAMove(): void
    {
        $id = $this->s->moves->add('scheduled', 3, 4, '2026-10-27');
        $this->s->w->fail['moves.claim'] = true;
        $this->assertSame(0, $this->svc->applyDue(), 'a claim the database refused: not done by this run');
        $this->assertSame('scheduled', $this->s->moves->rows[$id]->status);
        $this->s->w->fail = [];
        // someone else took the claim between the read and the claim
        $this->s->moves->rows[$id]->status = 'applying';
        $this->s->moves->rows[$id]->applied_at = '2026-10-28 08:59:59';
        $this->assertSame(0, $this->svc->applyDue());
        $this->assertSame([], $this->s->w->only('tx begin'));
    }

    public function testAMoveThatCannotBeSavedGoesBackToScheduled(): void
    {
        $id = $this->s->moves->add('scheduled', 3, 4, '2026-10-27');
        $this->s->w->fail['reporting.insertLine'] = true;
        $this->assertSame(0, $this->svc->applyDue());
        $this->assertSame(['scheduled', null], [$this->s->moves->rows[$id]->status, $this->s->moves->rows[$id]->applied_at]);
        $this->assertSame([], $this->s->audit->rows);
    }

    public function testRecoverWaitsResumesOrFailsAnInterruptedRun(): void
    {
        $wait = $this->s->moves->add('applying', 3, 4, '2026-10-27', ['applied_at' => '2026-10-28 08:55:00']);   // 5 minutes: the run may still be going
        $resume = $this->s->moves->add('applying', 2, 4, '2026-10-27', ['applied_at' => '2026-10-28 08:00:00']);  // an hour: done again once
        $second = $this->s->moves->add('applying', 4, 1, '2026-10-27', ['applied_at' => '2026-10-28 08:00:00', 'note' => 'resumed']);   // already resumed: failed
        $old = $this->s->moves->add('applying', 1, 2, '2026-10-27', ['applied_at' => null]);   // claimed before there was a claim time: failed
        $this->svc->applyDue();
        $this->assertSame('applying', $this->s->moves->rows[$wait]->status);
        $this->assertSame(['failed', 'interrupted'], [$this->s->moves->rows[$second]->status, $this->s->moves->rows[$second]->note]);
        $this->assertSame(['failed', 'interrupted'], [$this->s->moves->rows[$old]->status, $this->s->moves->rows[$old]->note]);
        $this->assertSame('done', $this->s->moves->rows[$resume]->status, 'resumed, then done in the same run');
        $actions = array_map(function ($r) { return $r[0]; }, $this->s->audit->rows);
        $this->assertContains('org_move_resumed', $actions);
        $this->assertSame(2, count(array_keys($actions, 'org_move_failed', true)));
    }

    public function testNoTableMeansNothingToDo(): void
    {
        $this->s->moves->exists = false;
        $this->assertSame(0, $this->svc->applyDue());
        $this->assertSame([], $this->svc->forChart(function ($id) { return 'n'; })['scheduled']);
        $this->assertSame([], $this->s->w->log);
    }

    public function testCancelWritesAnAuditRowEvenWhenNothingWasChanged(): void
    {
        $id = $this->s->moves->add('scheduled', 3, 4, '2026-11-01');
        $this->assertSame(3, $this->svc->cancel($id));
        $this->assertSame('cancelled', $this->s->moves->rows[$id]->status);
        $done = $this->s->moves->add('done', 2, 4, '2026-10-01');
        $this->assertSame(2, $this->svc->cancel($done), 'a finished move is reported too');
        $this->assertSame('done', $this->s->moves->rows[$done]->status);
        $this->assertSame(0, $this->svc->cancel(999));
        $this->assertSame(['org_move_cancelled'], array_map(function ($r) { return $r[0]; }, $this->s->audit->rows), 'only the first one is audited');
        $this->assertStringStartsWith('Scheduled move #' . $id . ' cancelled: Lina:', $this->s->audit->rows[0][3]);
    }

    public function testForChartKeepsFiveMovesPerPersonAndTheNoncesOnOpenOnes(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->s->moves->add('done', 3, 4, '2026-10-0' . $i);
        $sched = $this->s->moves->add('scheduled', 2, 4, '2026-11-01');
        $failed = $this->s->moves->add('failed', 4, 1, '2026-10-01');
        $out = $this->svc->forChart(function ($id) { return 'nonce' . $id; });
        $this->assertSame([7, 6, 5, 4, 3], array_column($out['history'][3], 'id'));
        $this->assertSame([''], array_unique(array_column($out['history'][3], 'nonce')));
        $this->assertSame(['nonce' . $sched], array_column($out['scheduled'], 'nonce'));
        $this->assertSame(['nonce' . $failed], array_column($out['failed'], 'nonce'));
    }
}
