<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Approvals\Data\WorkflowStore;

/** An in-memory WorkflowStore for the unit tests (3.31.128). */
final class MemoryWorkflowStore implements WorkflowStore
{
    /** @var array<int,object> */
    public $workflows = [];
    /** @var array<int,list<object>> workflow id => its levels */
    public $steps = [];
    /** @var string */
    public $error = '';
    /** @var bool the next setModeActive fails */
    public $failUpdate = false;
    /** @var int the nth level insert fails (0 = none) */
    public $failStepAt = 0;
    /** @var int */
    private $inserts = 0;

    /** Adds a workflow and its levels (resolver_type, resolver_value, min_amount). @param list<array<int,mixed>> $levels */
    public function add(int $id, string $key, string $mode, bool $active, array $levels = []): void
    {
        $this->workflows[$id] = (object) ['id' => $id, 'workflow_key' => $key, 'name' => $key, 'approval_mode' => $mode, 'active' => $active ? 1 : 0];
        $this->steps[$id] = [];
        foreach ($levels as $i => $l) {
            $this->steps[$id][] = (object) ['workflow_id' => $id, 'step_order' => $i + 1, 'step_type' => 'APPROVAL', 'resolver_type' => $l[0], 'resolver_value' => $l[1] ?? null, 'min_amount' => $l[2] ?? null, 'required' => 1, 'active' => 1];
        }
    }

    public function byKey(string $key): ?object
    {
        foreach ($this->workflows as $w) if ($w->workflow_key === $key) return clone $w;
        return null;
    }

    public function activeByKey(string $key): ?object
    {
        $w = $this->byKey($key);
        return $w && (int) $w->active === 1 ? $w : null;
    }

    public function keyById(int $id): string
    {
        return isset($this->workflows[$id]) ? $this->workflows[$id]->workflow_key : '';
    }

    public function activeSteps(int $workflowId): array
    {
        return array_values(array_filter($this->steps[$workflowId] ?? [], function ($s) {
            return (int) $s->active === 1;
        }));
    }

    public function idByKey(string $key): ?string
    {
        $w = $this->byKey($key);
        return $w ? (string) $w->id : null;
    }

    public function insertWorkflow(array $row, array $formats): void
    {
        $id = count($this->workflows) + 1;
        $this->workflows[$id] = (object) ($row + ['id' => $id]);
        $this->steps[$id] = [];
    }

    public function setModeActive(int $id, string $mode, string $now)
    {
        if ($this->failUpdate) {
            $this->error = 'update failed';
            return false;
        }
        $this->workflows[$id]->approval_mode = $mode;
        $this->workflows[$id]->active = 1;
        return 1;
    }

    public function deleteSteps(int $workflowId)
    {
        $n = count($this->steps[$workflowId] ?? []);
        $this->steps[$workflowId] = [];
        return $n;
    }

    public function insertStep(array $row, array $formats)
    {
        $this->inserts++;
        if ($this->failStepAt && $this->inserts === $this->failStepAt) {
            $this->error = 'insert failed';
            return false;
        }
        $this->steps[(int) $row['workflow_id']][] = (object) ($row + ['min_amount' => null]);
        return 1;
    }

    public function lastError(): string
    {
        return $this->error;
    }
}
