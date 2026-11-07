<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Approvals\AdminReason;

/** Why a level waits for the administrators (QA 8, docs/tasks/admin-fallback-reason.md). */
final class AdminReasonTest extends TestCase
{
    private function of(array $step): string
    {
        return AdminReason::of((object) ($step + ['rerouted' => 1]));
    }

    public function testEachReason(): void
    {
        $this->assertSame('self', $this->of(['skip_reason' => 'self']));
        $this->assertSame('no_login', $this->of(['skip_reason' => 'no_login', 'resolver_type' => 'SUPERVISOR']));
        foreach (['SUPERVISOR' => 'no_manager', 'MANAGERS_MANAGER' => 'no_managers_manager', 'TEAM_MANAGER' => 'no_team_manager',
                     'DEPARTMENT_MANAGER' => 'no_department_manager', 'HR' => 'no_hr', 'FINANCE' => 'no_finance',
                     'SPECIFIC_EMPLOYEE' => 'approver_gone', 'SPECIFIC_USER' => 'approver_gone', 'SOMETHING_NEW' => 'unknown'] as $type => $code) {
            $this->assertSame($code, $this->of(['skip_reason' => 'missing', 'resolver_type' => $type]), $type);
        }
    }

    public function testMovedUpComesFirst(): void
    {
        $this->assertSame('moved_up', $this->of(['skip_reason' => 'self', 'escalations' => 1, 'escalated_from_wp_user_id' => 9]));
        $this->assertSame('unknown', $this->of(['escalations' => 1, 'escalated_from_wp_user_id' => 0]), 'no one it moved from: neutral');
    }

    public function testOldRowsAndOtherLevels(): void
    {
        $this->assertSame('unknown', $this->of(['skip_reason' => null]), 'saved before the reason was kept');
        $this->assertSame('', AdminReason::of((object) ['rerouted' => 0, 'skip_reason' => 'missing']), 'not with the administrators');
        $this->assertSame('', $this->of(['approver_wp_user_id' => 5, 'skip_reason' => 'self']), 'someone decides it');
        $this->assertSame('unknown', $this->of(['skip_reason' => 'closed']));
    }

    public function testEveryCodeIsListed(): void
    {
        foreach (['self', 'no_manager', 'moved_up', 'unknown', 'no_login', 'approver_gone'] as $c) $this->assertContains($c, AdminReason::CODES);
    }
}
