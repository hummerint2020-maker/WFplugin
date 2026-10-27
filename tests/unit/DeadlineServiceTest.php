<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Tests\Fakes\ApprovalWorld;

require_once __DIR__ . '/Fakes/ApprovalWorld.php';

/** The approval deadlines (3.31.128): remind, then move up to the approver's manager or to the administrators. */
final class DeadlineServiceTest extends TestCase
{
    /** @var ApprovalWorld */
    private $w;
    /** @var array<string,array{remind:int,escalate:int}> */
    private $keys = ['vacation' => ['remind' => 4, 'escalate' => 16]];

    protected function setUp(): void
    {
        $this->w = new ApprovalWorld();
        $this->w->person(1, 'Requester', 10, 2);
        $this->w->person(2, 'Manager', 20, 3);
        $this->w->person(3, 'Boss', 30);
        $this->w->workflow(1, 'vacation', 'LEVEL_1', [['SUPERVISOR']]);
        $this->w->engine()->start('vacation', 'leave', 1, 1, [], false);
    }

    public function testNothingHappensBeforeTheReminderTime(): void
    {
        $this->assertSame(['reminded' => 0, 'moved' => 0], $this->w->deadlines()->tick($this->keys, '2026-10-26 10:00:00'));
        $this->assertSame([], $this->w->deadlineTold);
    }

    public function testRemindsOnceThenMovesUpToTheManagersManager(): void
    {
        $this->assertSame(['reminded' => 1, 'moved' => 0], $this->w->deadlines()->tick($this->keys, '2026-10-27 09:00:00'));
        $this->assertSame([['reminded', 1, 4]], $this->w->deadlineTold);
        $this->assertSame(['reminded' => 0, 'moved' => 0], $this->w->deadlines()->tick($this->keys, '2026-10-27 10:00:00'), 'reminded once');
        $this->w->deadlineTold = [];
        $this->assertSame(['reminded' => 0, 'moved' => 1], $this->w->deadlines()->tick($this->keys, '2026-10-30 09:00:00'));
        $step = $this->w->requests->steps[1];
        $this->assertSame([3, 30, 0, 1], [(int) $step->approver_employee_id, (int) $step->approver_wp_user_id, (int) $step->rerouted, (int) $step->escalations]);
        $this->assertSame(20, (int) $step->escalated_from_wp_user_id);
        $this->assertSame([['moved', 1, 20, 3, 'Requester']], $this->w->deadlineTold);
        $this->assertSame('approval_escalated', $this->w->audits[0][0]);
        $this->assertSame('vacation · level 1 · user #20 → employee #3', $this->w->audits[0][3]);
    }

    public function testWithNoManagerAboveItGoesToTheAdministrators(): void
    {
        $this->w->deadlines()->tick($this->keys, '2026-10-30 09:00:00');   // to the boss
        $this->w->requests->steps[1]->pending_since = '2026-10-26 09:00:00';
        $this->w->deadlineTold = [];
        $this->w->audits = [];
        $this->assertSame(['reminded' => 0, 'moved' => 1], $this->w->deadlines()->tick($this->keys, '2026-11-05 09:00:00'));
        $step = $this->w->requests->steps[1];
        $this->assertSame([1, 2], [(int) $step->rerouted, (int) $step->escalations]);
        $this->assertNull($step->approver_wp_user_id);
        $this->assertSame([['moved', 1, 30, 0, 'Requester']], $this->w->deadlineTold);
        $this->assertSame('vacation · level 1 · user #30 → administrators', $this->w->audits[0][3]);
        // A level that waits for administrators is not reminded or moved again.
        $this->w->requests->steps[1]->pending_since = '2026-10-26 09:00:00';
        $this->assertSame(['reminded' => 0, 'moved' => 0], $this->w->deadlines()->tick($this->keys, '2026-12-05 09:00:00'));
    }

    public function testALevelWithoutAStartCountsFromNow(): void
    {
        $this->w->requests->steps[1]->pending_since = null;
        $this->assertSame(['reminded' => 0, 'moved' => 0], $this->w->deadlines()->tick($this->keys, '2026-11-20 09:00:00'));
        $this->assertSame('2026-11-20 09:00:00', $this->w->requests->steps[1]->pending_since);
    }

    public function testASubstitutesLevelMovesUpFromTheManagerItWaitsFor(): void
    {
        $this->w->person(6, 'Sub', 60);
        $s = $this->w->requests->steps[1];
        $s->approver_employee_id = 6;
        $s->approver_wp_user_id = 60;
        $s->for_employee_id = 2;
        $s->substitute_id = 9;
        $this->w->deadlines()->tick($this->keys, '2026-10-30 09:00:00');
        $this->assertSame([3, 30], [(int) $s->approver_employee_id, (int) $s->approver_wp_user_id]);
        $this->assertNull($s->for_employee_id);
        $this->assertNull($s->substitute_id);
    }

    public function testNeverMovesUpToTheRequesterOrToTheSameApprover(): void
    {
        $this->w->supervisor[2] = 1;   // the approver's manager is the requester
        $this->w->deadlines()->tick($this->keys, '2026-10-30 09:00:00');
        $this->assertSame(1, (int) $this->w->requests->steps[1]->rerouted, 'to the administrators instead');
    }
}
