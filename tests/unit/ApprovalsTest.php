<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\StateMachine as SM;
use WorkforceOne\Approvals\Workflows as W;
use WorkforceOne\Approvals\WorkingHours as WH;

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
        $this->assertSame([['step_order' => 1, 'step_type' => 'APPROVAL', 'resolver_type' => 'SUPERVISOR', 'resolver_value' => null, 'min_amount' => null], ['step_order' => 2, 'step_type' => 'APPROVAL', 'resolver_type' => 'SPECIFIC_EMPLOYEE', 'resolver_value' => '7', 'min_amount' => null]], $p['steps']);
        $this->assertSame([['step_order' => 1, 'step_type' => 'PEER', 'resolver_type' => 'TARGET_EMPLOYEE', 'resolver_value' => null, 'min_amount' => null]], W::plan('PEER', [])['steps']);
        $this->assertSame([], W::plan('NONE', [1 => ['resolver_type' => 'junk']])['steps']);
        $this->assertSame('missing_resolver_value', W::plan('LEVEL_1', [1 => ['resolver_type' => 'SPECIFIC_USER', 'resolver_value' => 0]])['code']);
        $this->assertSame('invalid_resolver', W::plan('LEVEL_1', [1 => ['resolver_type' => 'TARGET_EMPLOYEE']])['code']);
        $this->assertSame('invalid_mode', W::plan('ALL', [])['code']);
        $r = W::plan('SEQUENTIAL', [], W::IN_USE['vacation'][1]);
        $this->assertSame(['unsupported_mode', 'This workflow supports: No approval, Level 1 approval, Level 1 + Level 2 approval, Levels 1, 2 and 3 approval, Levels 1 to 4 approval.'], [$r['code'], $r['message']]);
        $this->assertTrue(W::plan('SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR']], W::IN_USE['face_reset'][1])['ok']);
    }

    public function testStateMachine(): void
    {
        $this->assertSame(['WAITING_FOR_LEVEL_1', 'WAITING_FOR_PEER'], [SM::initialStatus('SEQUENTIAL'), SM::initialStatus('PEER')]);
        $this->assertSame(['step' => 'APPROVED', 'request' => 'WAITING_FOR_LEVEL_2', 'current_step' => 2, 'completed' => false], SM::afterDecision('approve', 2));
        $this->assertSame(['step' => 'APPROVED', 'request' => 'APPROVED', 'current_step' => null, 'completed' => true], SM::afterDecision('approve', null));
        $this->assertSame(['step' => 'REJECTED', 'request' => 'REJECTED', 'current_step' => null, 'completed' => true], SM::afterDecision('reject', 2));
    }

    /** 3.31.100: up to four levels, new approvers, a condition per level. */
    public function testFourLevels(): void
    {
        $this->assertSame([3, 4, 0], [W::levelCount('LEVEL_3', []), W::levelCount('level_4', []), W::levelCount('LEVEL_5', [])]);
        $this->assertSame([true, true, false, false], [W::isLevels('LEVEL_1'), W::isLevels('level_4'), W::isLevels('LEVEL_5'), W::isLevels('SEQUENTIAL')]);
        $this->assertSame(['SEQUENTIAL', 'NONE'], [W::modeFamily('LEVEL_4'), W::modeFamily('LEVEL_5')]);
        $this->assertSame(4, W::levelCount('SEQUENTIAL', [1 => ['resolver_type' => 'SUPERVISOR'], 4 => ['resolver_type' => 'HR']]));
        $p = W::plan('LEVEL_4', [1 => ['resolver_type' => 'SUPERVISOR'], 2 => ['resolver_type' => 'MANAGERS_MANAGER', 'min_amount' => '3'], 3 => ['resolver_type' => 'HR', 'min_amount' => 'x'], 4 => ['resolver_type' => 'FINANCE', 'min_amount' => -2]]);
        $this->assertTrue($p['ok']);
        $this->assertSame([['SUPERVISOR', null], ['MANAGERS_MANAGER', 3.0], ['HR', null], ['FINANCE', null]], array_map(static function ($s) { return [$s['resolver_type'], $s['min_amount']]; }, $p['steps']));
        $this->assertTrue(W::plan('LEVEL_1', [1 => ['resolver_type' => 'DEPARTMENT_MANAGER']])['ok']);
        $this->assertSame('invalid_resolver', W::plan('LEVEL_3', [1 => ['resolver_type' => 'SUPERVISOR'], 2 => ['resolver_type' => 'SUPERVISOR']])['code']);
        foreach (array_keys(W::IN_USE) as $key) $this->assertContains('LEVEL_4', W::IN_USE[$key][1], $key);
        foreach (W::RESOLVERS as $r) $this->assertArrayHasKey($r, W::RESOLVER_LABELS);
    }

    public function testConditionDeadlinesAndSelfRule(): void
    {
        $this->assertSame([true, true, false, false, true], [W::applies(null, 9.0), W::applies(3.0, null), W::applies(3.0, 3.0), W::applies(3.0, 2.5), W::applies(3.0, 3.5)]);
        $this->assertSame([null, null, 2.5, null], [W::minAmount(''), W::minAmount(0), W::minAmount('2.5'), W::minAmount([1])]);
        $this->assertSame(['remind' => 8, 'escalate' => 24], W::deadlines(8, 24));
        $this->assertSame(['remind' => 0, 'escalate' => 8], W::deadlines(8, 8), 'a reminder at the move up is dropped');
        $this->assertSame(['remind' => 5, 'escalate' => 0], W::deadlines('5', 0), 'a reminder alone is fine');
        $this->assertSame(['remind' => 0, 'escalate' => 720], W::deadlines(-3, 9999));
        $this->assertSame(['allow', 'next', 'admins', 'allow'], [W::selfRule('allow'), W::selfRule('next'), W::selfRule('admins'), W::selfRule('maybe')]);
    }

    public function testStart(): void
    {
        $r = SM::start([1 => ['skip' => ''], 2 => ['skip' => ''], 3 => ['skip' => '']]);
        $this->assertSame(['PENDING', 'WAITING', 'WAITING'], array_column($r['steps'], 'status'));
        $this->assertSame(['WAITING_FOR_LEVEL_1', 1], [$r['request'], $r['current_step']]);
        // Level 1 is the requester (rule "next"), level 2 does not apply: level 3 decides first.
        $r = SM::start([1 => ['skip' => 'self'], 2 => ['skip' => 'condition'], 3 => ['skip' => '']]);
        $this->assertSame(['SKIPPED', 'SKIPPED', 'PENDING'], array_column($r['steps'], 'status'));
        $this->assertSame(['self', 'condition', ''], array_column($r['steps'], 'skip'));
        $this->assertSame(['WAITING_FOR_LEVEL_3', 3], [$r['request'], $r['current_step']]);
        // The requester is the last level that applies: administrators decide it instead.
        $r = SM::start([1 => ['skip' => ''], 2 => ['skip' => 'self'], 3 => ['skip' => 'condition']]);
        $this->assertSame(['PENDING', 'WAITING', 'SKIPPED'], array_column($r['steps'], 'status'));
        $this->assertSame([false, true, false], array_column($r['steps'], 'rerouted'));
        // Nothing applies: approved at once.
        $r = SM::start([1 => ['skip' => 'condition'], 2 => ['skip' => 'condition']]);
        $this->assertSame(['APPROVED', null], [$r['request'], $r['current_step']]);
        $this->assertSame(['WAITING_FOR_PEER', 1], [SM::start([1 => ['skip' => '']], 'PEER')['request'], SM::start([1 => ['skip' => '']], 'PEER')['current_step']]);
        $this->assertSame([true, true, false, false], [SM::isWaitingForLevel('WAITING_FOR_LEVEL_1'), SM::isWaitingForLevel('WAITING_FOR_LEVEL_4'), SM::isWaitingForLevel('WAITING_FOR_PEER'), SM::isWaitingForLevel('APPROVED')]);
    }

    public function testWorkingHours(): void
    {
        $days = [0, 1, 2, 3, 4];   // Sunday–Thursday; 2026-10-15 is a Thursday, 16–17 Friday–Saturday
        $this->assertSame(8.0, WH::between('2026-10-14 09:00:00', '2026-10-15 09:00:00', $days));
        $this->assertSame(1.5, WH::between('2026-10-14 15:30:00', '2026-10-14 20:00:00', $days), 'after 17:00 does not count');
        $this->assertSame(8.0, WH::between('2026-10-15 09:00:00', '2026-10-18 09:00:00', $days), 'the weekend does not count');
        $this->assertSame(10.0, WH::between('2026-10-15 07:00:00', '2026-10-18 11:00:00', $days));
        $this->assertSame([0.0, 0.0], [WH::between('2026-10-15 10:00:00', '2026-10-15 09:00:00', $days), WH::between('2026-10-16 08:00:00', '2026-10-17 20:00:00', $days)]);
        $this->assertSame(4.0, WH::between('2026-10-14 08:00:00', '2026-10-14 23:00:00', $days, '10:00', '14:00'));
        $this->assertSame(0.0, WH::between('2026-10-14 08:00:00', '2026-10-14 23:00:00', $days, '14:00', '10:00'), 'a day that ends before it starts counts nothing');
        $this->assertSame([540, null, null], [WH::minutes('09:00'), WH::minutes('24:00'), WH::minutes('9')]);
    }
}
