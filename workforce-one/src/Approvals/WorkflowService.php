<?php
namespace WorkforceOne\Approvals;

use WorkforceOne\Approvals\Data\WorkflowStore;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * The approval workflows (3.31.128): which workflow a request type uses, its mode and its levels. Keys come already cleaned
 * (sanitize_key) by the trait that calls this.
 */
final class WorkflowService
{
    /** @var WorkflowStore */
    private $workflows;
    /** @var Transaction */
    private $tx;
    /** @var Clock */
    private $clock;

    public function __construct(WorkflowStore $workflows, Transaction $tx, Clock $clock)
    {
        $this->workflows = $workflows;
        $this->tx = $tx;
        $this->clock = $clock;
    }

    public function workflow(string $key): ?object
    {
        return $this->workflows->byKey($key);
    }

    public function keyById(int $workflowId): string
    {
        return $this->workflows->keyById($workflowId);
    }

    /** Adds the workflows of the request types that have none yet (switched off, mode NONE: the request types' own defaults). */
    public function seedDefaults(): void
    {
        $defaults = [
            'vacation' => ['Vacation', 'NONE'],
            'overtime' => ['Overtime', 'NONE'],
            'early_leave' => ['Early Leave', 'NONE'],
            'face_reset' => ['Face Reset', 'NONE'],
            'shift_swap' => ['Shift Swap', 'PEER'],
            'attendance_correction' => ['Attendance Correction', 'NONE'],
            'salary_advance' => ['Salary Advance', 'NONE'],
        ];
        foreach ($defaults as $key => $def) {
            if ($this->workflows->idByKey($key)) continue;
            $this->workflows->insertWorkflow([
                'workflow_key' => $key,
                'name' => $def[0],
                'approval_mode' => $def[1],
                'active' => 0,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ], ['%s', '%s', '%s', '%d', '%s', '%s']);
        }
    }

    /**
     * Saves a workflow's mode and levels in one transaction. $supported limits the modes (the modes the module handles);
     * the checks are Workflows::plan().
     * @param array<int,array<string,mixed>> $stepConfigs
     * @param array<int,string>|null $supported
     */
    public function configure(string $key, string $mode, array $stepConfigs, ?array $supported): ApprovalResult
    {
        $workflow = $this->workflows->byKey($key);
        if (!$workflow) return ApprovalResult::error('workflow_not_found', 'Workflow not found.');
        $plan = Workflows::plan($mode, $stepConfigs, $supported);
        if (!$plan['ok']) return ApprovalResult::error($plan['code'], $plan['message']);
        $now = $this->clock->now();
        if (!$this->tx->begin()) return ApprovalResult::error('db_error', 'Could not start configuration transaction.');
        if ($this->workflows->setModeActive((int) $workflow->id, $mode, $now) === false) return $this->failed();
        if ($this->workflows->deleteSteps((int) $workflow->id) === false) return $this->failed();
        foreach ($plan['steps'] as $step) {
            $row = ['workflow_id' => (int) $workflow->id, 'step_order' => $step['step_order'], 'step_type' => $step['step_type'], 'resolver_type' => $step['resolver_type'], 'resolver_value' => $step['resolver_value'], 'required' => 1, 'active' => 1, 'created_at' => $now, 'updated_at' => $now];
            $fmt = ['%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s'];
            if ($step['min_amount'] !== null) {
                $row['min_amount'] = $step['min_amount'];
                $fmt[] = '%f';
            }
            if ($this->workflows->insertStep($row, $fmt) === false) return $this->failed();
        }
        if (!$this->tx->commit()) {
            $this->tx->rollback();
            return ApprovalResult::error('db_error', 'Could not commit workflow configuration: ' . ($this->workflows->lastError() ?: 'database commit failed') . '.');
        }
        return ApprovalResult::ok(true);
    }

    /** Rolls back, then reports the database's message (read after the rollback, as the old code did). */
    private function failed(): ApprovalResult
    {
        $this->tx->rollback();
        return ApprovalResult::error('db_error', $this->workflows->lastError());
    }
}
