<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Requests\Hub;

final class RequestsHubTest extends TestCase
{
    public function testDecisionOutcomes(): void
    {
        $this->assertTrue(Hub::isDecision('approve'));
        $this->assertTrue(Hub::isDecision('reject'));
        $this->assertFalse(Hub::isDecision('maybe'));
        $this->assertSame('approved', Hub::outcome('approve'));
        $this->assertSame('rejected', Hub::outcome('reject'));
    }

    public function testWorkflowOutcomeAdvancesUntilTheLastLevel(): void
    {
        $this->assertSame('approved', Hub::workflowOutcome('APPROVED'));
        $this->assertSame('rejected', Hub::workflowOutcome('rejected'));
        $this->assertSame('advanced', Hub::workflowOutcome('WAITING_FOR_LEVEL_2'));
        $this->assertTrue(Hub::isOpenApproval('WAITING_FOR_LEVEL_1'));
        $this->assertFalse(Hub::isOpenApproval('APPROVED'));
        $this->assertFalse(Hub::isOpenApproval(null));
    }

    public function testApprovalLabel(): void
    {
        $this->assertSame('Approval #7 · Level 2 · Assigned', Hub::approvalLabel('leave', 7, 2, true));
        $this->assertSame('Approval #7', Hub::approvalLabel('leave', 7, null, false));
        $this->assertSame('Peer', Hub::approvalLabel('shift_swap', 0, null, false));
        $this->assertSame('Legacy / direct', Hub::approvalLabel('overtime', 0, null, false));
    }

    public function testDetailsAndDuration(): void
    {
        $this->assertSame('1h 30m', Hub::duration(90));
        $this->assertSame('0h 45m', Hub::duration(45));
        $this->assertSame('2026-10-01 · 1h 0m', Hub::details('2026-10-01', Hub::duration(60), ''));
    }

    public function testRowsAreSortedOldestFirst(): void
    {
        $rows = Hub::sortOldestFirst([['requested_at' => '2026-10-02 09:00:00'], ['requested_at' => '2026-09-30 10:00:00'], ['requested_at' => '']]);
        $this->assertSame(['', '2026-09-30 10:00:00', '2026-10-02 09:00:00'], array_column($rows, 'requested_at'));
    }

    public function testNotices(): void
    {
        $this->assertSame('Approval recorded and moved to the next level.', Hub::hubNotice('advanced'));
        $this->assertNull(Hub::hubNotice('nope'));
        $this->assertSame('Approval recorded and moved to the next level.', Hub::faceResetNotice('advanced'));
        $this->assertNull(Hub::faceResetNotice(''));
    }
}
