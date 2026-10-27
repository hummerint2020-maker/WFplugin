<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Tests\Fakes\ApprovalWorld;

require_once __DIR__ . '/Fakes/ApprovalWorld.php';

/** The approvals engine (3.31.128) on in-memory Stores: start, decide, who may decide, close, reassign. No WordPress, no database. */
final class ApprovalEngineTest extends TestCase
{
    /** @var ApprovalWorld */
    private $w;

    protected function setUp(): void
    {
        $this->w = new ApprovalWorld();
        $this->w->person(1, 'Requester', 10, 2);   // 2 is the manager
        $this->w->person(2, 'Manager', 20, 3);     // 3 is the manager's manager
        $this->w->person(3, 'Boss', 30);
        $this->w->person(4, 'HR', 40);
        $this->w->person(5, 'Other', 50);
        $this->w->settings['hr_user'] = 40;
        $this->w->workflow(1, 'vacation', 'LEVEL_2', [['SUPERVISOR'], ['MANAGERS_MANAGER']]);
        $this->w->workflow(2, 'early_leave', 'NONE');
        $this->w->workflow(3, 'overtime', 'LEVEL_1', [['SUPERVISOR']]);
        $this->w->workflow(4, 'shift_swap', 'PEER');
        $this->w->workflow(5, 'attendance_correction', 'LEVEL_2', [['SUPERVISOR'], ['HR']]);
        $this->w->workflow(6, 'salary_advance', 'LEVEL_2', [['SUPERVISOR', null, '100.00'], ['SPECIFIC_USER', '40']]);
    }

    private function start(string $key, int $entity = 1, array $ctx = [], bool $manage = true, int $requester = 1)
    {
        return $this->w->engine()->start($key, 'x', $entity, $requester, $ctx, $manage);
    }

    private function act(int $id, string $decision, int $uid, string $note = '', bool $manage = true, bool $override = false, ?int $expected = null)
    {
        return $this->w->engine()->act($id, $decision, $note, $manage, $override, $expected, $uid, $uid > 0);
    }

    public function testStartWithNoLevelsApprovesAtOnce(): void
    {
        $r = $this->start('early_leave');
        $this->assertTrue($r->ok);
        $row = $this->w->requests->requests[$r->value];
        $this->assertSame('APPROVED', $row->status);
        $this->assertSame('2026-10-26 09:00:00', $row->completed_at);
        $this->assertSame([], $this->w->tx, 'no level, nothing to protect');
    }

    public function testStartRefusalsKeepTheirCodes(): void
    {
        $this->assertSame('approval_workflow_not_found', $this->start('nope')->code);
        $this->w->workflow(7, 'face_reset', 'LEVEL_1', [], true);
        $this->assertSame('approval_steps_missing', $this->start('face_reset')->code);
        $this->assertSame('peer_target_missing', $this->start('shift_swap')->code);
        $this->assertSame('approval_approver_missing', $this->start('shift_swap', 1, ['target_employee_id' => 99])->code);
        $this->assertTrue($this->start('vacation', 5)->ok);
        $again = $this->start('vacation', 5);
        $this->assertFalse($again->ok);
        $this->assertSame('approval_already_started', $again->code);
    }

    public function testTwoLevelsFirstPendingSecondWaiting(): void
    {
        $r = $this->start('vacation');
        $steps = array_values($this->w->requests->steps);
        $this->assertSame(['PENDING', 'WAITING'], [$steps[0]->status, $steps[1]->status]);
        $this->assertSame([20, 30], [(int) $steps[0]->approver_wp_user_id, (int) $steps[1]->approver_wp_user_id]);
        $this->assertSame('WAITING_FOR_LEVEL_1', $this->w->requests->requests[$r->value]->status);
        $this->assertSame(['begin', 'commit'], $this->w->tx);
        $this->assertSame('2026-10-26 09:00:00', $steps[0]->pending_since);
        $this->assertNull($steps[1]->pending_since);
    }

    public function testCallerOwnedTransactionIsNeverTouched(): void
    {
        $this->start('vacation', 1, [], false);
        $this->assertSame([], $this->w->tx);
    }

    public function testAmountConditionSkipsALevel(): void
    {
        $this->start('salary_advance', 1, ['amount' => 50]);
        $steps = array_values($this->w->requests->steps);
        $this->assertSame(['SKIPPED', 'PENDING'], [$steps[0]->status, $steps[1]->status]);
        $this->assertSame('condition', $steps[0]->skip_reason);
        $this->start('salary_advance', 2, ['amount' => 500]);
        $this->assertSame('PENDING', array_values($this->w->requests->steps)[2]->status);
    }

    public function testNobodyToDecideSendsTheLevelToAdministrators(): void
    {
        $r = $this->start('overtime', 1, [], true, 3);   // the boss has no manager
        $step = array_values($this->w->requests->steps)[0];
        $this->assertSame('PENDING', $step->status);
        $this->assertSame(1, (int) $step->rerouted);
        $this->assertNull($step->approver_wp_user_id);
        $this->assertSame([[$r->value, 1]], $this->w->told, 'told right after COMMIT when the engine owns the transaction');
        $this->assertSame([], $this->w->later);
    }

    public function testAdministratorsAreToldLaterWhenTheCallerOwnsTheTransaction(): void
    {
        $r = $this->start('overtime', 1, [], false, 3);
        $this->assertSame([], $this->w->told);
        $this->assertSame([[$r->value, 1]], $this->w->later);
    }

    public function testSelfRuleNextSkipsToTheNextLevelAndAdminsReroutes(): void
    {
        $this->w->workflow(10, 'face_reset', 'LEVEL_2', [['SPECIFIC_EMPLOYEE', '2'], ['SPECIFIC_EMPLOYEE', '3']]);   // level 1 is the manager
        $this->w->selfRule['face_reset'] = 'next';
        $this->w->engine()->start('face_reset', 'x', 1, 2, [], true);   // the manager asks: level 1 is themselves
        $steps = array_values($this->w->requests->steps);
        $this->assertSame(['SKIPPED', 'PENDING'], [$steps[0]->status, $steps[1]->status]);
        $this->assertSame('self', $steps[0]->skip_reason);
        $this->w->selfRule['face_reset'] = 'admins';
        $this->w->engine()->start('face_reset', 'x', 2, 2, [], true);
        $steps = array_values($this->w->requests->steps);
        $this->assertSame('PENDING', $steps[2]->status);
        $this->assertSame(1, (int) $steps[2]->rerouted);
        $this->assertNull($steps[2]->approver_wp_user_id);
        $this->w->selfRule['face_reset'] = 'allow';
        $this->w->engine()->start('face_reset', 'x', 3, 2, [], true);
        $steps = array_values($this->w->requests->steps);
        $this->assertSame(20, (int) $steps[4]->approver_wp_user_id, 'allowed: they decide it');
    }

    public function testASubstituteIsRoutedAtStart(): void
    {
        $this->w->person(6, 'Sub', 60);
        $this->w->routes[2] = ['employee' => $this->w->employees[6], 'row' => (object) ['id' => 9]];
        $this->start('vacation');
        $first = array_values($this->w->requests->steps)[0];
        $this->assertSame([60, 2, 9], [(int) $first->approver_wp_user_id, (int) $first->for_employee_id, (int) $first->substitute_id]);
    }

    public function testAFailedInsertRollsBackOnlyWhenTheEngineOwnsTheTransaction(): void
    {
        $this->w->requests->failStepInsertAt = 2;
        $r = $this->start('vacation');
        $this->assertSame('approval_db_error', $r->code);
        $this->assertSame('insert step failed', $r->message);
        $this->assertSame(['begin', 'rollback'], $this->w->tx);
        $this->w->tx = [];
        $this->w->requests->failStepInsertAt = 4;
        $this->start('vacation', 2, [], false);
        $this->assertSame([], $this->w->tx);
        $this->w->requests->failStepInsertAt = 0;
        $this->w->requests->failInsertRequest = true;
        $this->assertSame('insert request failed', $this->start('vacation', 3)->message);
    }

    public function testBeginAndCommitFailuresAreReported(): void
    {
        $this->w->failBegin = true;
        $this->assertSame('Could not start approval transaction.', $this->start('vacation')->message);
        $this->assertSame([], $this->w->requests->requests);
        $this->w->failBegin = false;
        $this->w->failCommit = true;
        $this->w->tx = [];
        $this->assertSame('Could not commit approval request.', $this->start('vacation')->message);
        $this->assertSame(['begin', 'commit', 'rollback'], $this->w->tx);
    }

    public function testApproveThroughTwoLevels(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertSame(true, $this->act($id, 'approve', 20, 'ok')->value);
        $this->assertSame('WAITING_FOR_LEVEL_2', $this->w->requests->requests[$id]->status);
        $this->assertSame(2, (int) $this->w->requests->requests[$id]->current_step);
        $second = array_values($this->w->requests->steps)[1];
        $this->assertSame(['PENDING', '2026-10-26 09:00:00'], [$second->status, $second->pending_since]);
        $this->assertSame([[1, 20]], $this->w->afterDecisions);
        $this->act($id, 'approve', 30);
        $row = $this->w->requests->requests[$id];
        $this->assertSame(['APPROVED', null], [$row->status, $row->current_step]);
        $this->assertSame('2026-10-26 09:00:00', $row->completed_at);
        $this->assertSame(['begin', 'commit', 'begin', 'commit', 'begin', 'commit'], $this->w->tx);
    }

    public function testRejectEndsTheRequest(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertTrue($this->act($id, 'reject', 20, 'no')->ok);
        $row = $this->w->requests->requests[$id];
        $this->assertSame('REJECTED', $row->status);
        $this->assertSame('WAITING', array_values($this->w->requests->steps)[1]->status, 'later levels are left as they are');
    }

    public function testRefusalsOfAct(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertSame('invalid_decision', $this->act($id, 'maybe', 20)->code);
        $this->assertSame('invalid_decision', $this->act(0, 'approve', 20)->code);
        $this->assertSame('approval_not_found', $this->act(999, 'approve', 20)->code);
        $this->assertSame('approval_not_authorized', $this->act($id, 'approve', 50)->code);
        $this->assertSame('approval_not_authorized', $this->w->engine()->act($id, 'approve', '', true, false, null, 0, false)->code);
        $this->act($id, 'approve', 20);
        $this->act($id, 'approve', 30);
        $this->assertSame('approval_not_pending', $this->act($id, 'approve', 30)->code);
    }

    public function testTheLevelTheFormShowedIsCheckedBeforeWhoMayDecide(): void
    {
        $id = $this->start('vacation')->value;
        $level1 = array_values($this->w->requests->steps)[0];
        $this->assertSame('approval_moved', $this->act($id, 'approve', 50, '', true, false, 12345)->code, 'moved wins over "not yours"');
        $this->assertSame('approval_moved', $this->act($id, 'approve', 20, '', true, false, 0)->code);
        $this->assertTrue($this->act($id, 'approve', 20, '', true, false, (int) $level1->id)->ok);
        $this->assertSame('approval_moved', $this->act($id, 'approve', 20, '', true, false, (int) $level1->id)->code, 'the same form posted twice');
    }

    public function testAStaleDecisionWithoutAnExpectedLevelIsStale(): void
    {
        $id = $this->start('vacation')->value;
        $this->w->requests->failDecide = false;
        $this->w->requests->steps[1]->status = 'APPROVED';   // decided by someone else between the read and the update
        $this->assertSame('approval_not_pending', $this->act($id, 'approve', 20)->code);
    }

    public function testRejectNeedsAReasonOnlyWhenTheWorkflowAsksAndNotForAnOverride(): void
    {
        $this->w->rejectNote['vacation'] = true;
        $id = $this->start('vacation')->value;
        $this->assertSame('approval_note_required', $this->act($id, 'reject', 20, '  ')->code);
        $this->w->admins[99] = true;
        $this->assertTrue($this->act($id, 'reject', 99, '', true, true)->ok, 'an administrator override is not held to it');
    }

    public function testRequesterDecidingTheirOwnRequestFollowsTheWorkflowRule(): void
    {
        $this->w->person(7, 'Solo', 70);
        $this->w->workflow(9, 'face_reset', 'LEVEL_1', [['SPECIFIC_USER', '70']]);
        $solo = $this->w->engine()->start('face_reset', 'x', 1, 7, [], true)->value;   // the approver is the requester, and the rule lets them
        $this->assertTrue($this->act($solo, 'approve', 70)->ok);
        $this->assertSame(1, (int) array_values($this->w->requests->steps)[count($this->w->requests->steps) - 1]->self_decision);
        $this->assertSame('approval_self_decision', $this->w->audits[0][0]);
        $this->assertSame('face_reset · level 1 · approve', $this->w->audits[0][3]);
        $this->w->selfRule['face_reset'] = 'admins';
        $solo2 = $this->w->engine()->start('face_reset', 'x', 2, 7, [], true)->value;
        $this->w->requests->steps[count($this->w->requests->steps)]->approver_wp_user_id = 70;   // as if it were assigned to the requester
        $step = $this->w->requests->currentStep($solo2);
        $this->assertSame('approval_self_not_allowed', $this->w->engine()->mayDecide($step, 70, false)->code);
        $this->assertSame('approval_self_not_allowed', $this->w->engine()->mayDecide($step, 70, true)->code, 'not even with an override');
    }

    public function testAnAdministratorMayDecideALevelSentToAdministrators(): void
    {
        $id = $this->start('overtime', 1, [], true, 3)->value;
        $this->assertSame('approval_not_authorized', $this->act($id, 'approve', 20)->code);
        $this->w->admins[99] = true;
        $this->assertTrue($this->act($id, 'approve', 99)->ok);
    }

    public function testANextLevelWaitingForAdministratorsTellsThemAfterTheDecision(): void
    {
        $id = $this->start('vacation')->value;
        // Level 2 was reserved for the administrators when the request started (its approver is gone).
        $this->w->requests->steps[2]->approver_wp_user_id = null;
        $this->w->requests->steps[2]->rerouted = 1;
        $this->w->told = [];
        $this->act($id, 'approve', 20);
        $this->assertSame([[$id, 2]], $this->w->told, 'told right after the COMMIT');
        $this->w->told = [];
        $id2 = $this->start('vacation', 2)->value;
        $this->w->requests->steps[4]->approver_wp_user_id = null;
        $this->w->requests->steps[4]->rerouted = 1;
        $this->act($id2, 'approve', 20, '', false);
        $this->assertSame([], $this->w->told);
        $this->assertSame([[$id2, 2]], $this->w->later, 'the caller owns the transaction: told at the end of the page');
    }

    public function testCanActAndMayDecide(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertTrue($this->w->engine()->canAct($id, null, 20));
        $this->assertTrue($this->w->engine()->canAct($id, 1, 20));
        $this->assertFalse($this->w->engine()->canAct($id, 2, 20), 'level 2 is not open');
        $this->assertFalse($this->w->engine()->canAct($id, null, 30), 'the boss decides level 2, not yet');
        $this->assertFalse($this->w->engine()->canAct(999, null, 20));
        $step = $this->w->requests->currentStep($id);
        $this->assertTrue($this->w->engine()->mayDecide($step, 50, true)->ok, 'an override');
        $this->w->managerMayDecide = true;
        $this->w->requests->steps[1]->for_employee_id = 5;
        $this->assertTrue($this->w->engine()->mayDecide($this->w->requests->currentStep($id), 50, false)->ok, 'the manager away, deciding anyway');
    }

    public function testCloseWithdrawn(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertTrue($this->w->engine()->closeWithdrawn('vacation', 'x', 1));
        $this->assertSame('CANCELLED', $this->w->requests->requests[$id]->status);
        $this->assertSame(['CANCELLED', 'CANCELLED'], array_column(array_map('get_object_vars', array_values($this->w->requests->steps)), 'status'));
        $this->assertSame(1, $this->w->synced);
        $this->assertFalse($this->w->engine()->closeWithdrawn('vacation', 'x', 1), 'already closed');
        $this->assertTrue($this->w->engine()->closeWithdrawn('vacation', 'x', 424242), 'nothing to close');
    }

    public function testCloseOpen(): void
    {
        $id = $this->start('vacation')->value;
        $this->assertTrue($this->w->engine()->closeOpen('vacation', 'x', 1, true));
        $this->assertSame('closed', array_values($this->w->requests->steps)[0]->skip_reason);
        $this->assertTrue($this->w->engine()->closeOpen('vacation', 'x', 1, false), 'a request already closed counts as done');
        $this->assertTrue($this->w->engine()->closeOpen('vacation', 'x', 424242, false));
        $this->assertSame(3, $this->w->synced);
        $id2 = $this->start('vacation', 2)->value;
        $this->w->requests->requests[$id2]->status = 'WAITING_FOR_LEVEL_1';
        $this->assertSame(true, $this->w->engine()->closeOpen('vacation', 'x', 2, false));
    }

    public function testReassignSupervisor(): void
    {
        $this->w->person(8, 'New manager', 80);
        $id = $this->start('vacation')->value;
        $pending = 0;
        $this->assertSame(0, $this->w->engine()->reassignSupervisor(1, 2, 0, $pending), 'no new manager');
        $this->assertSame(0, $this->w->engine()->reassignSupervisor(1, 2, 2, $pending), 'the same manager');
        $this->assertSame(1, $this->w->engine()->reassignSupervisor(1, 2, 8, $pending));
        $this->assertSame(1, $pending);
        $this->assertSame(80, (int) array_values($this->w->requests->steps)[0]->approver_wp_user_id);
        $this->assertSame(0, $this->w->engine()->reassignSupervisor(1, 2, 8, $pending), 'nothing left for the old manager');
        $this->assertSame(0, $pending);
    }

    public function testStillWithAdministrators(): void
    {
        $id = $this->start('overtime', 1, [], false, 3)->value;
        $this->assertTrue($this->w->engine()->stillWithAdministrators($id, 1));
        $this->w->requests->steps[1]->status = 'CANCELLED';   // the caller rolled back or withdrew meanwhile
        $this->assertFalse($this->w->engine()->stillWithAdministrators($id, 1));
    }
}
