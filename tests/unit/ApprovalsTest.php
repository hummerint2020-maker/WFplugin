<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\StateMachine as SM;
use WorkforceOne\Approvals\Workflows as W;

final class ApprovalsTest extends TestCase
{
    public function testFamiliesAndLevels(): void
    {
        $this->assertSame(['SEQUENTIAL', 'SEQUENTIAL', 'SEQUENTIAL', 'PEER', 'NONE', 'NONE'], [W::modeFamily('LEVEL_1'), W::modeFamily('level_2'), W::modeFamily('SEQUENTIAL'), W::modeFamily('PEER'), W::modeFamily('NONE'), W::modeFamily('x')]);
        $this->assertSame([1, 2, 1, 0, 2, 1, 0], [W::levelCount('LEVEL_1', []), W::levelCount('LEVEL_2', []), W::levelCount('PEER', []), W::levelCount('NONE', []),
            W::levelCount('SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR'], 2 => ['resolver_type' => 'TEAM_MANAGER']]), W::levelCount('SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR'], 2 => ['resolver_type' => '']]), W::levelCount('SEQUENTIAL', [])]);
    }

    public function testPlan(): void
    {
        $p = W::plan('LEVEL_2', [1 => ['resolver_type' => 'SUPERVISOR', 'resolver_value' => 9], 2 => ['resolver_type' => 'SPECIFIC_EMPLOYEE', 'resolver_value' => '7']]);
        $this->assertTrue($p['ok']);
        $this->assertSame([['step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'SUPERVISOR', 'resolver_value' => null], ['step_order' => 2, 'step_type' => 'APPROVAL', 'resolver_type' => 'SPECIFIC_EMPLOYEE', 'resolver_value' => '7']], $p['steps']);
        $this->assertSame([['step_order' => 1, 'step_type' => 'PEER', 'resolver_type' => 'TARGET_EMPLOYEE', 'resolver_value' => null]], W::plan('PEER', [])['steps']);
        $this->assertSame([], W::plan('NONE', [1 => ['resolver_type' => 'junk']])['steps']);
        $this->assertSame('missing_resolver_value', W::plan('LEVEL_1', [1 => ['resolver_type' => 'SPECIFIC_USER', 'resolver_value' => 0]])['code']);
        $this->assertSame('invalid_resolver', W::plan('LEVEL_1', [1 => ['resolver_type' => 'TARGET_EMPLOYEE']])['code']);
        $this->assertSame('invalid_mode', W::plan('ALL', [])['code']);
        $r = W::plan('SEQUENTIAL', [], W::IN_USE['vacation'][1]);
        $this->assertSame(['unsupported_mode', 'This workflow supports: No approval, Level 1 approval, Level 1 + Level 2 approval.'], [$r['code'], $r['message']]);
        $this->assertTrue(W::plan('SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR']], W::IN_USE['face_reset'][1])['ok']);
    }

    public function testStateMachine(): void
    {
        $this->assertSame(['WAITING_FOR_LEVEL_1', 'WAITING_FOR_PEER'], [SM::initialStatus('SEQUENTIAL'), SM::initialStatus('PEER')]);
        $this->assertSame(['PENDING', 'WAITING', 'SKIPPED'], [SM::stepStatus(1, true), SM::stepStatus(2, true), SM::stepStatus(1, false)]);
        $this->assertSame(['step' => 'APPROVED', 'request' => 'WAITING_FOR_LEVEL_2', 'current_step' => 2, 'completed' => false], SM::afterDecision('approve', 2));
        $this->assertSame(['step' => 'APPROVED', 'request' => 'APPROVED', 'current_step' => null, 'completed' => true], SM::afterDecision('approve', null));
        $this->assertSame(['step' => 'REJECTED', 'request' => 'REJECTED', 'current_step' => null, 'completed' => true], SM::afterDecision('reject', 2));
    }
}
