<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Tests\Fakes\ApprovalWorld;

require_once __DIR__ . '/Fakes/ApprovalWorld.php';

/** The approval workflows (3.31.128): lookup, first-run seed, and saving a workflow in one transaction. */
final class WorkflowServiceTest extends TestCase
{
    /** @var ApprovalWorld */
    private $w;

    protected function setUp(): void
    {
        $this->w = new ApprovalWorld();
        $this->w->workflow(1, 'overtime', 'NONE');
    }

    public function testLookup(): void
    {
        $s = $this->w->workflowService();
        $this->assertSame('overtime', $s->workflow('overtime')->workflow_key);
        $this->assertNull($s->workflow('nope'));
        $this->assertSame('overtime', $s->keyById(1));
        $this->assertSame('', $s->keyById(99));
    }

    public function testSeedAddsOnlyTheMissingWorkflowsSwitchedOff(): void
    {
        $this->w->workflowService()->seedDefaults();
        $keys = array_map(function ($x) {
            return $x->workflow_key;
        }, array_values($this->w->workflows->workflows));
        $this->assertSame(['overtime', 'vacation', 'early_leave', 'face_reset', 'shift_swap', 'attendance_correction', 'salary_advance'], $keys);
        $this->assertSame(0, (int) $this->w->workflows->workflows[2]->active);
        $this->assertSame('PEER', $this->w->workflows->workflows[5]->approval_mode);
        $this->w->workflowService()->seedDefaults();
        $this->assertCount(7, $this->w->workflows->workflows, 'a second run adds nothing');
    }

    public function testConfigureSavesTheModeAndTheLevelsInOneTransaction(): void
    {
        $r = $this->w->workflowService()->configure('overtime', 'LEVEL_2', [1 => ['resolver_type' => 'SUPERVISOR', 'resolver_value' => 0], 2 => ['resolver_type' => 'HR', 'resolver_value' => 0, 'min_amount' => '3.5']], null);
        $this->assertTrue($r->ok);
        $this->assertSame(['begin', 'commit'], $this->w->tx);
        $this->assertSame('LEVEL_2', $this->w->workflows->workflows[1]->approval_mode);
        $this->assertSame(1, (int) $this->w->workflows->workflows[1]->active);
        $steps = $this->w->workflows->steps[1];
        $this->assertSame(['SUPERVISOR', 'HR'], [$steps[0]->resolver_type, $steps[1]->resolver_type]);
        $this->assertSame(3.5, (float) $steps[1]->min_amount);
    }

    public function testConfigureRefusals(): void
    {
        $s = $this->w->workflowService();
        $this->assertSame('workflow_not_found', $s->configure('nope', 'NONE', [], null)->code);
        $bad = $s->configure('overtime', 'LEVEL_9', [], null);
        $this->assertFalse($bad->ok);
        $this->assertSame([], $this->w->tx, 'a refused plan never starts a transaction');
    }

    public function testConfigureRollsBackOnAFailedWrite(): void
    {
        $this->w->workflows->failUpdate = true;
        $r = $this->w->workflowService()->configure('overtime', 'LEVEL_1', [1 => ['resolver_type' => 'SUPERVISOR', 'resolver_value' => 0]], null);
        $this->assertSame(['db_error', ''], [$r->code, $r->message]);
        $this->assertSame(['begin', 'rollback'], $this->w->tx);
        $this->w->workflows->failUpdate = false;
        $this->w->tx = [];
        $this->w->workflows->failStepAt = 1;
        $r = $this->w->workflowService()->configure('overtime', 'LEVEL_1', [1 => ['resolver_type' => 'SUPERVISOR', 'resolver_value' => 0]], null);
        $this->assertSame('', $r->message);
        $this->assertSame(['begin', 'rollback'], $this->w->tx);
    }

    public function testConfigureCommitFailure(): void
    {
        $this->w->failCommit = true;
        $r = $this->w->workflowService()->configure('overtime', 'NONE', [], null);
        $this->assertSame('db_error', $r->code);
        $this->assertSame('Could not commit workflow configuration: database commit failed.', $r->message);
        $this->assertSame(['begin', 'commit', 'rollback'], $this->w->tx);
    }

    public function testBeginFailure(): void
    {
        $this->w->failBegin = true;
        $this->assertSame('Could not start configuration transaction.', $this->w->workflowService()->configure('overtime', 'NONE', [], null)->message);
    }
}
