<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\LeaveFacts;
use WorkforceOne\Approvals\SubstituteNotifier;
use WorkforceOne\Approvals\SubstitutePolicy;
use WorkforceOne\Approvals\SubstitutePorts;
use WorkforceOne\Approvals\SubstituteService;
use WorkforceOne\Tests\Fakes\ApprovalWorld;
use WorkforceOne\Tests\Fakes\MemorySubstituteStore;

require_once __DIR__ . '/Fakes/ApprovalWorld.php';
require_once __DIR__ . '/Fakes/MemorySubstituteStore.php';

/** Substitutes (3.31.128): routing, the sync, finishing and creating, on in-memory Stores. */
final class SubstituteServiceTest extends TestCase
{
    /** @var ApprovalWorld */
    private $w;
    /** @var MemorySubstituteStore */
    private $subs;
    /** @var bool */
    public $enabled = true;
    /** @var array<string,mixed> */
    public $settings = ['manager_decides' => 1, 'on_return' => 'back', 'who' => 'anyone', 'ask_on_leave' => 'no'];
    /** @var array<int,string> leave id => status */
    public $leaves = [];
    /** @var array<int,bool> employee id => away on leave */
    public $away = [];
    /** @var array<int,array<int,mixed>> */
    public $told = [];

    protected function setUp(): void
    {
        $this->w = new ApprovalWorld();
        $this->w->person(1, 'Requester', 10, 2);
        $this->w->person(2, 'Manager', 20, 3);
        $this->w->person(3, 'Boss', 30);
        $this->w->person(6, 'Sub', 60);
        $this->w->workflow(1, 'vacation', 'LEVEL_1', [['SUPERVISOR']]);
        $this->subs = new MemorySubstituteStore();
    }

    private function service(): SubstituteService
    {
        $t = $this;
        $policy = new class($t) implements SubstitutePolicy {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function enabled(): bool { return $this->t->enabled; }
            public function settings(): array { return $this->t->settings; }
        };
        $leave = new class($t) implements LeaveFacts {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function statusOf(int $id): string { return $this->t->leaves[$id] ?? ''; }
            public function awayBetween(int $e, string $from, string $to): bool { return !empty($this->t->away[$e]); }
        };
        $notifier = new class($t) implements SubstituteNotifier {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function picked(object $row): void { $this->t->told[] = ['picked', (int) $row->id]; }
            public function started(object $row, int $moved, string $manager): void { $this->t->told[] = ['started', (int) $row->id, $moved, $manager]; }
            public function ended(int $muid, object $row, int $decided, int $back, int $still, string $sub): void { $this->t->told[] = ['ended', $muid, (int) $row->id, $decided, $back, $still, $sub]; }
        };
        return new SubstituteService($this->subs, $this->w->requests, $this->w->directory(), new SubstitutePorts($policy, $leave, $notifier), $this->w->after(), $this->w->audit(), $this->w->clock());
    }

    private function cover(string $status = 'scheduled', string $from = '2026-10-26 08:00:00', string $to = '2026-10-30 18:00:00', array $more = []): int
    {
        return $this->subs->add(['manager_employee_id' => 2, 'substitute_employee_id' => 6, 'starts_at' => $from, 'ends_at' => $to, 'status' => $status] + $more);
    }

    public function testRouteOnlyWhileTheCoverIsOnAndNotForTheSubstitutesOwnRequest(): void
    {
        $id = $this->cover();
        $r = $this->service()->route(2, 1);
        $this->assertSame([60, $id], [(int) $r['employee']->wp_user_id, (int) $r['row']->id]);
        $this->assertNull($this->service()->route(2, 6), 'the substitute asking stays with the manager');
        $this->assertNull($this->service()->route(3, 1));
        $this->assertNull($this->service()->route(0, 1));
        $this->enabled = false;
        $this->assertNull($this->service()->route(2, 1));
        $this->enabled = true;
        $this->w->now = '2026-11-02 09:00:00';
        $this->assertNull($this->service()->route(2, 1), 'the cover is over');
    }

    public function testManagerMayDecideFollowsTheSetting(): void
    {
        $step = (object) ['for_employee_id' => 2];
        $this->assertTrue($this->service()->managerMayDecide($step, 20));
        $this->assertFalse($this->service()->managerMayDecide($step, 50));
        $this->assertFalse($this->service()->managerMayDecide((object) ['for_employee_id' => null], 20));
        $this->settings['manager_decides'] = 0;
        $this->assertFalse($this->service()->managerMayDecide($step, 20));
    }

    public function testAfterDecisionCountsOnlyTheSubstitutesOwnDecisionAndSyncsAtTheEnd(): void
    {
        $id = $this->cover('active');
        $this->service()->afterDecision((object) ['substitute_id' => $id, 'approver_wp_user_id' => 60], 60);
        $this->service()->afterDecision((object) ['substitute_id' => $id, 'approver_wp_user_id' => 60], 20);
        $this->service()->afterDecision((object) ['substitute_id' => null, 'approver_wp_user_id' => 20], 20);
        $this->assertSame(1, $this->subs->decided($id));
        $this->assertSame(3, $this->w->synced);
    }

    public function testSyncStartsAScheduledCoverAndMovesWhatWaits(): void
    {
        $this->w->engine()->start('vacation', 'leave', 1, 1, [], false);
        $id = $this->cover('scheduled');
        // The level moved when the cover started is told with the start; "moved" counts the levels the later pass takes.
        $this->assertSame(['started' => 1, 'ended' => 0, 'cancelled' => 0, 'moved' => 0], $this->service()->sync(null));
        $this->assertSame('active', $this->subs->rows[$id]->status);
        $step = $this->w->requests->steps[1];
        $this->assertSame([60, 2, $id], [(int) $step->approver_wp_user_id, (int) $step->for_employee_id, (int) $step->substitute_id]);
        $this->assertSame([['started', $id, 1, 'Manager']], $this->told);
        $this->assertSame('substitute_started', $this->w->audits[0][0]);
        $this->assertSame('employee #6 for #2 · 1 waiting moved', $this->w->audits[0][3]);
        $this->assertSame(['started' => 0, 'ended' => 0, 'cancelled' => 0, 'moved' => 0], $this->service()->sync(null), 'nothing more to move');
    }

    public function testSyncEndsAnActiveCoverAndSendsWhatWaitsBackToTheManager(): void
    {
        $this->w->engine()->start('vacation', 'leave', 1, 1, [], false);
        $id = $this->cover('scheduled');
        $this->service()->sync(null);
        $this->subs->rows[$id]->decided = 2;
        $this->told = [];
        $this->assertSame(['started' => 0, 'ended' => 1, 'cancelled' => 0, 'moved' => 0], $this->service()->sync('2026-10-31 09:00:00'));
        $this->assertSame('ended', $this->subs->rows[$id]->status);
        $step = $this->w->requests->steps[1];
        $this->assertSame([20, 2], [(int) $step->approver_wp_user_id, (int) $step->approver_employee_id]);
        $this->assertNull($step->substitute_id);
        $this->assertSame([['ended', 20, $id, 2, 1, 0, 'Sub']], $this->told);
    }

    public function testWhenTheSettingKeepsItWhatWaitsStaysWithTheSubstitute(): void
    {
        $this->w->engine()->start('vacation', 'leave', 1, 1, [], false);
        $id = $this->cover('scheduled');
        $this->service()->sync(null);
        $this->settings['on_return'] = 'stay';
        $this->told = [];
        $this->service()->sync('2026-10-31 09:00:00');
        $this->assertSame([60, 0], [(int) $this->w->requests->steps[1]->approver_wp_user_id, 0]);
        $this->assertSame([['ended', 20, $id, 0, 0, 1, 'Sub']], $this->told);
    }

    public function testACoverTiedToALeaveFollowsTheLeave(): void
    {
        $a = $this->cover('pending', '2026-10-26 08:00:00', '2026-10-30 18:00:00', ['leave_request_id' => 5]);
        $this->leaves[5] = 'Approved';
        $this->service()->sync(null);
        $this->assertSame('active', $this->subs->rows[$a]->status, 'approved: scheduled, and its time has come');
        $this->assertContains(['picked', $a], $this->told);
        $b = $this->cover('pending', '2026-11-26 08:00:00', '2026-11-30 18:00:00', ['leave_request_id' => 6]);
        $this->leaves[6] = 'Rejected';
        $this->service()->sync(null);
        $this->assertSame('cancelled', $this->subs->rows[$b]->status);
    }

    public function testASyncDoesNothingWhileSubstitutesAreOff(): void
    {
        $this->cover();
        $this->enabled = false;
        $this->assertSame(['started' => 0, 'ended' => 0, 'cancelled' => 0, 'moved' => 0], $this->service()->sync(null));
        $this->assertSame('scheduled', $this->subs->rows[1]->status);
    }

    public function testCheckRefusals(): void
    {
        $s = $this->service();
        $this->assertSame('same', $s->check(2, 2, '2026-10-27 00:00:00', '2026-10-28 00:00:00'));
        $this->assertSame('dates', $s->check(2, 6, '2026-10-28 00:00:00', '2026-10-27 00:00:00'));
        $this->assertSame('past', $s->check(2, 6, '2026-10-20 00:00:00', '2026-10-21 00:00:00'));
        $this->assertSame('manager', $s->check(99, 6, '2026-10-27 00:00:00', '2026-10-28 00:00:00'));
        $this->assertSame('substitute', $s->check(2, 99, '2026-10-27 00:00:00', '2026-10-28 00:00:00'));
        $this->assertNull($s->check(2, 6, '2026-10-27 00:00:00', '2026-10-28 00:00:00'));
        $this->away[6] = true;
        $this->assertSame('away', $s->check(2, 6, '2026-10-27 00:00:00', '2026-10-28 00:00:00'));
        $this->away = [];
        $this->cover('scheduled', '2026-10-27 00:00:00', '2026-10-29 00:00:00');
        $this->assertSame('overlap', $s->check(2, 6, '2026-10-28 00:00:00', '2026-10-30 00:00:00'));
        $this->assertNull($s->check(2, 6, '2026-10-28 00:00:00', '2026-10-30 00:00:00', 1), 'ignoring the one being edited');
    }

    public function testCreateSavesAuditsTellsAndSyncs(): void
    {
        $id = $this->service()->create(2, 6, '2026-10-27 00:00:00', '2026-10-28 00:00:00', 'hr', str_repeat('x', 300), 0, 'scheduled', 77);
        $this->assertSame(1, $id);
        $row = $this->subs->rows[1];
        $this->assertSame([77, 255], [(int) $row->created_by, strlen($row->reason)]);
        $this->assertSame('substitute_set', $this->w->audits[0][0]);
        $this->assertSame('employee #6 for #2 · 2026-10-27 00:00:00 → 2026-10-28 00:00:00 · hr', $this->w->audits[0][3]);
        $this->assertSame([['picked', 1]], $this->told);
        $this->assertSame('same', $this->service()->create(2, 2, '2026-10-27 00:00:00', '2026-10-28 00:00:00', 'hr', '', 0, 'scheduled', 77));
        $this->subs->failInsert = true;
        $this->assertSame('save', $this->service()->create(2, 6, '2026-11-27 00:00:00', '2026-11-28 00:00:00', 'hr', '', 0, 'scheduled', 77));
    }

    public function testDecidingForShowsEachCoverWithHowManyWait(): void
    {
        $this->w->engine()->start('vacation', 'leave', 1, 1, [], false);
        $this->cover('scheduled');
        $this->service()->sync(null);
        $rows = $this->service()->decidingFor(6, function ($r, $n) {
            return [(int) $r->manager_employee_id, $n];
        });
        $this->assertSame([[2, 1]], $rows);
        $this->assertSame([], $this->service()->decidingFor(2, function () { return 1; }));
    }
}
