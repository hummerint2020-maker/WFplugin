<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Approvals\Data\ApprovalRequestStore;

/**
 * An in-memory ApprovalRequestStore for the unit tests (3.31.128): requests and levels as plain objects, with switches to make a
 * write fail and a log of the writes. It only has to behave like the SQL for the tests that use it: the real SQL is checked by
 * tests/e2e_approval_store.php and by the golden trace.
 */
final class MemoryApprovalRequestStore implements ApprovalRequestStore
{
    /** @var array<int,object> */
    public $requests = [];
    /** @var array<int,object> */
    public $steps = [];
    /** @var array<int,object> workflow id => {workflow_key, approval_mode} (the join of findByKey) */
    public $workflows = [];
    /** @var bool */
    public $failInsertRequest = false;
    /** @var int the nth step insert fails (0 = none) */
    public $failStepInsertAt = 0;
    /** @var bool */
    public $failDecide = false;
    /** @var bool the next level's update finds it no longer WAITING */
    public $failOpenNext = false;
    /** @var string */
    public $error = '';
    /** @var array<int,string> */
    public $log = [];
    /** @var int */
    private $nextRequest = 1;
    /** @var int */
    private $nextStep = 1;
    /** @var int */
    private $stepInserts = 0;

    public function requestRow(int $id): ?object
    {
        return isset($this->requests[$id]) ? clone $this->requests[$id] : null;
    }

    public function requestForCheck(int $id): ?object
    {
        return $this->requestRow($id);
    }

    public function currentStep(int $requestId): ?object
    {
        return $this->pendingStepAt($requestId, null, true);
    }

    public function pendingStepAt(int $requestId, ?int $order, bool $lowest = false): ?object
    {
        $best = null;
        foreach ($this->steps as $s) {
            if ((int) $s->approval_request_id !== $requestId || $s->status !== 'PENDING') continue;
            if ($order !== null && (int) $s->step_order !== $order) continue;
            if ($best === null || (int) $s->step_order < (int) $best->step_order) $best = $s;
        }
        return $best ? clone $best : null;
    }

    public function nextWaiting(int $requestId): ?object
    {
        $best = null;
        foreach ($this->steps as $s) {
            if ((int) $s->approval_request_id === $requestId && $s->status === 'WAITING' && ($best === null || (int) $s->step_order < (int) $best->step_order)) $best = $s;
        }
        return $best ? clone $best : null;
    }

    public function existingId(string $entityType, int $entityId, int $workflowId): ?string
    {
        foreach ($this->requests as $r) {
            if ($r->entity_type === $entityType && (int) $r->entity_id === $entityId && (int) $r->workflow_id === $workflowId) return (string) $r->id;
        }
        return null;
    }

    public function findByKey(string $workflowKey, string $entityType, int $entityId): ?object
    {
        $found = null;
        foreach ($this->requests as $r) {
            $w = $this->workflows[(int) $r->workflow_id] ?? null;
            if ($w && $w->workflow_key === $workflowKey && $r->entity_type === $entityType && (int) $r->entity_id === $entityId) $found = $r;
        }
        if (!$found) return null;
        $row = clone $found;
        $row->workflow_key = $this->workflows[(int) $found->workflow_id]->workflow_key;
        $row->approval_mode = $this->workflows[(int) $found->workflow_id]->approval_mode;
        return $row;
    }

    public function findByKeyGroups(array $groups): array
    {
        $out = [];
        foreach (array_reverse($this->requests) as $r) {
            $w = $this->workflows[(int) $r->workflow_id] ?? null;
            if (!$w) continue;
            if (isset($groups[$w->workflow_key . "\0" . $r->entity_type][(int) $r->entity_id])) {
                $row = clone $r;
                $row->workflow_key = $w->workflow_key;
                $row->approval_mode = $w->approval_mode;
                $out[] = $row;
            }
        }
        return $out;
    }

    public function currentSteps(array $requestIds): array
    {
        $out = [];
        foreach ($requestIds as $id) {
            $s = $this->currentStep((int) $id);
            if ($s) $out[(int) $id] = $s;
        }
        return $out;
    }

    public function stepsOf(array $requestIds): array
    {
        $out = [];
        foreach ($this->steps as $s) {
            if (in_array((int) $s->approval_request_id, array_map('intval', $requestIds), true)) $out[(int) $s->approval_request_id][] = clone $s;
        }
        return $out;
    }

    public function insertRequest(array $row, array $formats)
    {
        if ($this->failInsertRequest) {
            $this->error = 'insert request failed';
            return false;
        }
        $id = $this->nextRequest++;
        $this->requests[$id] = (object) ($row + ['id' => $id, 'current_step' => null, 'completed_at' => null]);
        $this->requests[$id]->id = $id;
        $this->log[] = 'insertRequest ' . $id;
        return $id;
    }

    public function insertStep(array $row, array $formats): bool
    {
        $this->stepInserts++;
        if ($this->failStepInsertAt && $this->stepInserts === $this->failStepInsertAt) {
            $this->error = 'insert step failed';
            return false;
        }
        $id = $this->nextStep++;
        $defaults = ['approver_employee_id' => null, 'approver_wp_user_id' => null, 'for_employee_id' => null, 'substitute_id' => null, 'skip_reason' => null, 'pending_since' => null, 'decision' => null, 'comment' => null,
            'reminded_at' => null, 'escalations' => 0, 'escalated_from_wp_user_id' => null, 'acted_at' => null, 'acted_by_wp_user_id' => null, 'self_decision' => 0, 'rerouted' => 0];
        $this->steps[$id] = (object) ($row + $defaults + ['id' => $id]);
        $this->steps[$id]->id = $id;
        $this->log[] = 'insertStep ' . $id;
        return true;
    }

    public function decideStep(int $stepId, array $set)
    {
        if ($this->failDecide || !isset($this->steps[$stepId]) || $this->steps[$stepId]->status !== 'PENDING') return $this->failDecide ? false : 0;
        foreach ($set as $k => $v) $this->steps[$stepId]->$k = $v;
        $this->log[] = 'decideStep ' . $stepId;
        return 1;
    }

    public function openNext(int $stepId, array $set)
    {
        if ($this->failOpenNext || !isset($this->steps[$stepId]) || $this->steps[$stepId]->status !== 'WAITING') return 0;
        foreach ($set as $k => $v) $this->steps[$stepId]->$k = $v;
        $this->log[] = 'openNext ' . $stepId;
        return 1;
    }

    public function updateRequest(int $id, array $set, array $formats)
    {
        if (!isset($this->requests[$id])) return 0;
        foreach ($set as $k => $v) $this->requests[$id]->$k = $v;
        $this->log[] = 'updateRequest ' . $id;
        return 1;
    }

    public function cancelRequestIf(int $id, string $status, string $now)
    {
        if (!isset($this->requests[$id]) || $this->requests[$id]->status !== $status) return 0;
        $this->requests[$id]->status = 'CANCELLED';
        $this->requests[$id]->current_step = null;
        $this->requests[$id]->completed_at = $now;
        $this->log[] = 'cancelRequest ' . $id;
        return 1;
    }

    public function cancelOpenSteps(int $requestId, bool $closed): bool
    {
        foreach ($this->steps as $s) {
            if ((int) $s->approval_request_id === $requestId && in_array($s->status, ['PENDING', 'WAITING'], true)) {
                $s->status = 'CANCELLED';
                if ($closed) $s->skip_reason = 'closed';
            }
        }
        $this->log[] = 'cancelSteps ' . $requestId . ($closed ? ' closed' : '');
        return true;
    }

    public function stillWithAdministrators(int $requestId, int $order): bool
    {
        foreach ($this->steps as $s) {
            if ((int) $s->approval_request_id === $requestId && (int) $s->step_order === $order && $s->status === 'PENDING' && (int) $s->rerouted === 1 && $s->approver_wp_user_id === null) return true;
        }
        return false;
    }

    public function waitingOnSupervisors(): array
    {
        $out = [];
        foreach ($this->steps as $s) {
            $r = $this->requests[(int) $s->approval_request_id] ?? null;
            $who = $s->for_employee_id ?: $s->approver_employee_id;
            if ($r && $s->resolver_type === 'SUPERVISOR' && in_array($s->status, ['PENDING', 'WAITING'], true) && $who !== null && !in_array($r->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) {
                $out[(int) $r->requester_employee_id][(int) $who] = ($out[(int) $r->requester_employee_id][(int) $who] ?? 0) + 1;
            }
        }
        return $out;
    }

    public function supervisorStepIds(int $requesterEmployeeId, int $fromEmployeeId): array
    {
        $ids = [];
        foreach ($this->steps as $s) {
            $r = $this->requests[(int) $s->approval_request_id] ?? null;
            if ($r && (int) $r->requester_employee_id === $requesterEmployeeId && $s->resolver_type === 'SUPERVISOR' && in_array($s->status, ['PENDING', 'WAITING'], true)
                && (int) ($s->for_employee_id ?: $s->approver_employee_id) === $fromEmployeeId && !in_array($r->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) $ids[] = (int) $s->id;
        }
        return $ids;
    }

    public function reassignSteps(array $stepIds, int $toEmployeeId, int $toUserId)
    {
        $n = 0;
        foreach ($stepIds as $id) {
            if (isset($this->steps[$id]) && in_array($this->steps[$id]->status, ['PENDING', 'WAITING'], true)) {
                $this->steps[$id]->approver_employee_id = $toEmployeeId;
                $this->steps[$id]->approver_wp_user_id = $toUserId;
                $this->steps[$id]->rerouted = 0;
                $this->steps[$id]->for_employee_id = null;
                $this->steps[$id]->substitute_id = null;
                $this->log[] = 'reassign ' . $id;
                $n++;
            }
        }
        return $n;
    }

    public function routeStepsToSubstitute(array $stepIds, int $substituteEmployeeId, int $substituteUserId, int $managerEmployeeId, int $substituteRowId)
    {
        $n = 0;
        foreach ($stepIds as $id) {
            if (isset($this->steps[$id]) && $this->steps[$id]->status === 'PENDING') {
                $this->steps[$id]->approver_employee_id = $substituteEmployeeId;
                $this->steps[$id]->approver_wp_user_id = $substituteUserId;
                $this->steps[$id]->for_employee_id = $managerEmployeeId;
                $this->steps[$id]->substitute_id = $substituteRowId;
                $this->log[] = 'route ' . $id;
                $n++;
            }
        }
        return $n;
    }

    public function countPending(array $stepIds): int
    {
        $n = 0;
        foreach ($stepIds as $id) if (isset($this->steps[$id]) && $this->steps[$id]->status === 'PENDING') $n++;
        return $n;
    }

    public function inboxRows(int $userId, int $admin, int $away): array
    {
        return [];
    }

    public function hasAnyStep(int $userId): bool
    {
        foreach ($this->steps as $s) if ((int) $s->approver_wp_user_id === $userId || (int) ($s->acted_by_wp_user_id ?? 0) === $userId) return true;
        return false;
    }

    public function decidedSince(int $userId, string $since): int
    {
        $n = 0;
        foreach ($this->steps as $s) if ((int) ($s->acted_by_wp_user_id ?? 0) === $userId && (string) ($s->acted_at ?? '') >= $since) $n++;
        return $n;
    }

    public function backfillPendingSince(string $now): void
    {
        foreach ($this->steps as $s) if ($s->status === 'PENDING' && $s->pending_since === null) $s->pending_since = $now;
    }

    public function overdueCandidates(string $workflowKey): array
    {
        $out = [];
        foreach ($this->steps as $s) {
            $r = $this->requests[(int) $s->approval_request_id] ?? null;
            $w = $r ? ($this->workflows[(int) $r->workflow_id] ?? null) : null;
            if ($s->status === 'PENDING' && $r && $w && $w->workflow_key === $workflowKey && !in_array($r->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) {
                $row = clone $s;
                $row->requester_employee_id = $r->requester_employee_id;
                $row->entity_type = $r->entity_type;
                $row->entity_id = $r->entity_id;
                $out[] = $row;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp((string) $a->pending_since, (string) $b->pending_since);
        });
        return $out;
    }

    public function markReminded(int $stepId, string $now)
    {
        if (!isset($this->steps[$stepId]) || $this->steps[$stepId]->status !== 'PENDING') return 0;
        $this->steps[$stepId]->reminded_at = $now;
        return 1;
    }

    public function escalate(int $stepId, ?array $up, int $fromUserId, string $now)
    {
        $s = $this->steps[$stepId] ?? null;
        if (!$s || $s->status !== 'PENDING' || (int) $s->approver_wp_user_id !== $fromUserId) return 0;
        if ($up) {
            $s->approver_employee_id = $up[0];
            $s->approver_wp_user_id = $up[1];
            $s->rerouted = 0;
        } else {
            $s->approver_employee_id = null;
            $s->approver_wp_user_id = null;
            $s->rerouted = 1;
        }
        $s->for_employee_id = null;
        $s->substitute_id = null;
        $s->pending_since = $now;
        $s->reminded_at = null;
        $s->escalations = (int) ($s->escalations ?? 0) + 1;
        $s->escalated_from_wp_user_id = $fromUserId;
        return 1;
    }

    public function assignWaitingToSubstitute(int $substituteEmployeeId, int $substituteUserId, int $managerEmployeeId, int $substituteRowId)
    {
        $n = 0;
        foreach ($this->steps as $s) {
            $r = $this->requests[(int) $s->approval_request_id] ?? null;
            if ($s->status === 'PENDING' && (int) $s->approver_employee_id === $managerEmployeeId && $s->for_employee_id === null && $r
                && strpos((string) $r->status, 'WAITING') === 0 && (int) $r->requester_employee_id !== $substituteEmployeeId) {
                $s->approver_employee_id = $substituteEmployeeId;
                $s->approver_wp_user_id = $substituteUserId;
                $s->for_employee_id = $managerEmployeeId;
                $s->substitute_id = $substituteRowId;
                $s->reminded_at = null;
                $n++;
            }
        }
        return $n;
    }

    public function returnPendingToManager(int $managerEmployeeId, int $managerUserId, int $substituteRowId)
    {
        $n = 0;
        foreach ($this->steps as $s) {
            if ((int) $s->substitute_id === $substituteRowId && $s->status === 'PENDING') {
                $s->approver_employee_id = $managerEmployeeId;
                $s->approver_wp_user_id = $managerUserId;
                $s->for_employee_id = null;
                $s->substitute_id = null;
                $s->reminded_at = null;
                $n++;
            }
        }
        return $n;
    }

    public function countPendingForSubstitute(int $substituteRowId): int
    {
        $n = 0;
        foreach ($this->steps as $s) if ((int) $s->substitute_id === $substituteRowId && $s->status === 'PENDING') $n++;
        return $n;
    }

    public function lastError(): string
    {
        return $this->error;
    }

    public function clearError(): void
    {
        $this->error = '';
    }
}
