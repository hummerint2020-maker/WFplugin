<?php
namespace WorkforceOne\Approvals;

use WorkforceOne\Approvals\Data\ApprovalRequestStore;
use WorkforceOne\Approvals\Data\WorkflowStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Permissions;
use WorkforceOne\Platform\Transaction;

if (!defined('ABSPATH')) exit;

/**
 * The approval engine (3.31.128): starting a request, deciding a level, who may decide, and closing a request. It was
 * the trait EWS_Approvals_Trait; every read, write and notification happens in the order it did there.
 *
 * $manageTransaction: true = this class sends START TRANSACTION / COMMIT / ROLLBACK, and tells the administrators right after
 * the COMMIT; false = the caller owns the transaction (it may still roll back), so the administrators are told at the end of
 * the page, and only if the level still waits for them.
 *
 * Lock order (do not change): deciding locks the level (step) row, then the request row. Withdrawing (closeWithdrawn) locks the
 * request first, then its levels; closeOpen locks the levels first, then the request. The caller's own row comes before both.
 *
 * Actor and time come in as arguments or ports: nothing here asks WordPress who is signed in. Names and codes come already
 * cleaned (sanitize_key, absint) by the trait that calls this.
 */
final class ApprovalEngine
{
    /** @var ApprovalRequestStore */
    private $requests;
    /** @var WorkflowStore */
    private $workflows;
    /** @var Transaction */
    private $tx;
    /** @var ApprovalPolicy */
    private $policy;
    /** @var ApprovalDirectory */
    private $directory;
    /** @var SubstituteRouting */
    private $substitutes;
    /** @var ApprovalNotifier */
    private $notifier;
    /** @var AfterRequest */
    private $after;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;
    /** @var Permissions */
    private $permissions;

    public function __construct(
        ApprovalRequestStore $requests,
        WorkflowStore $workflows,
        Transaction $tx,
        ApprovalPolicy $policy,
        ApprovalDirectory $directory,
        SubstituteRouting $substitutes,
        ApprovalNotifier $notifier,
        AfterRequest $after,
        AuditLog $audit,
        Clock $clock,
        Permissions $permissions
    ) {
        $this->requests = $requests;
        $this->workflows = $workflows;
        $this->tx = $tx;
        $this->policy = $policy;
        $this->directory = $directory;
        $this->substitutes = $substitutes;
        $this->notifier = $notifier;
        $this->after = $after;
        $this->audit = $audit;
        $this->clock = $clock;
        $this->permissions = $permissions;
    }

    /**
     * Starts an approval request and fixes who decides each level.
     * @param array<string,mixed> $context
     * @return ApprovalResult value: the new approval request id
     */
    public function start(string $workflowKey, string $entityType, int $entityId, int $requesterEmployeeId, array $context, bool $manageTransaction): ApprovalResult
    {
        $workflow = $this->workflows->activeByKey($workflowKey);
        if (!$workflow) return ApprovalResult::error('approval_workflow_not_found', 'Approval workflow is not configured.');

        $existing = $this->requests->existingId($entityType, $entityId, (int) $workflow->id);
        if ($existing) return ApprovalResult::error('approval_already_started', 'Approval workflow already exists for this request.');

        $steps = $this->workflows->activeSteps((int) $workflow->id);

        $mode = strtoupper((string) $workflow->approval_mode);
        $family = Workflows::modeFamily($mode);
        if ($family === 'NONE') $steps = [];
        elseif (!in_array($family, ['SEQUENTIAL', 'PEER'], true)) return ApprovalResult::error('approval_invalid_mode', 'Approval workflow has an invalid approval mode.');
        elseif (!$steps && $family !== 'PEER') return ApprovalResult::error('approval_steps_missing', 'Approval workflow requires configured approval steps.');
        if ($family === 'PEER' && !$steps) {
            $targetId = abs((int) ($context['target_employee_id'] ?? 0));
            if (!$targetId) return ApprovalResult::error('peer_target_missing', 'Peer approval requires target_employee_id.');
            $steps = [(object) ['step_order' => 1, 'step_type' => 'PEER', 'resolver_type' => 'TARGET_EMPLOYEE', 'resolver_value' => null, 'required' => 1, 'active' => 1]];
        }

        if (!$steps) {
            $id = $this->requests->insertRequest([
                'workflow_id' => (int) $workflow->id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'requester_employee_id' => $requesterEmployeeId,
                'status' => 'APPROVED',
                'current_step' => null,
                'completed_at' => $this->clock->now(),
            ], ['%d', '%s', '%d', '%d', '%s', null, '%s']);
            if ($id === false) return ApprovalResult::error('approval_db_error', $this->requests->lastError());
            return ApprovalResult::ok($id);
        }

        // Who decides each level, fixed when the request starts. A level whose condition does not apply is skipped; when its
        // approver is the requester, the workflow's rule decides (3.31.100). A level with nobody to decide it (no manager, HR not
        // set, an approver without a WordPress user) goes to the next level, or to administrators when there is none: the request
        // is never refused for it (3.31.101; before, a requester without a manager could not ask at all).
        $W = Workflows::class;
        $amount = isset($context['amount']) && is_numeric($context['amount']) ? (float) $context['amount'] : null;
        $selfRule = $this->policy->selfRule($workflowKey);
        $requesterUid = $this->directory->userId($requesterEmployeeId);
        $plan = [];
        $facts = [];
        foreach ($steps as $step) {
            $order = (int) $step->step_order;
            $min = isset($step->min_amount) ? $W::minAmount($step->min_amount) : null;
            $approver = null;
            $rerouted = false;
            $skip = '';
            if ($family !== 'PEER' && !$W::applies($min, $amount)) {
                $skip = 'condition';
            } else {
                $approver = $this->resolveApprover($requesterEmployeeId, (string) $step->resolver_type, $step->resolver_value, $context);
                if ($approver && !(int) $approver->wp_user_id) $approver = null;
                if ($family === 'PEER') {
                    if (!$approver) return ApprovalResult::error('approval_approver_missing', 'The colleague of this request could not be found.');
                } elseif ($approver && $selfRule !== 'allow'
                    && (((int) ($approver->id ?? 0) && (int) $approver->id === $requesterEmployeeId) || ($requesterUid && (int) $approver->wp_user_id === $requesterUid))) {
                    $approver = null;   // decided by the next level, or by an administrator (canAct)
                    if ($selfRule === 'next') $skip = 'self'; else $rerouted = true;
                } elseif (!$approver) {
                    $skip = 'missing';  // nobody to decide it: the next level, or administrators
                }
            }
            $plan[$order] = ['step' => $step, 'approver' => $approver, 'rerouted' => $rerouted];
            $facts[$order] = ['skip' => $skip];
        }
        $start = StateMachine::start($facts, $family);
        $now = $this->clock->now();

        if ($manageTransaction && !$this->tx->begin()) return ApprovalResult::error('approval_db_error', 'Could not start approval transaction.');
        $row = ['workflow_id' => (int) $workflow->id, 'entity_type' => $entityType, 'entity_id' => $entityId, 'requester_employee_id' => $requesterEmployeeId, 'status' => $start['request']];
        $fmt = ['%d', '%s', '%d', '%d', '%s'];
        if ($start['current_step'] !== null) {
            $row['current_step'] = $start['current_step'];
            $fmt[] = '%d';
        } else {
            $row['completed_at'] = $now;
            $fmt[] = '%s';
        }
        $approvalId = $this->requests->insertRequest($row, $fmt);
        if ($approvalId === false) return $this->startFailed($manageTransaction);

        $adminsNow = 0;
        foreach ($plan as $order => $p) {
            $st = $start['steps'][$order];
            $approver = $p['approver'];
            $rerouted = $p['rerouted'] || $st['rerouted'];
            $row = ['approval_request_id' => $approvalId, 'step_order' => $order, 'resolver_type' => $p['step']->resolver_type, 'resolver_value' => $p['step']->resolver_value, 'status' => $st['status'], 'rerouted' => $rerouted ? 1 : 0];
            $fmt = ['%d', '%d', '%s', '%s', '%s', '%d'];
            // A manager away with a substitute (3.31.107): the level waits for the substitute, "for" the manager.
            $route = $approver && $st['status'] === 'PENDING' && (int) ($approver->id ?? 0) ? $this->substitutes->route((int) $approver->id, $requesterEmployeeId) : null;
            if ($route) {
                $row['for_employee_id'] = (int) $approver->id;
                $row['substitute_id'] = (int) $route['row']->id;
                $fmt[] = '%d';
                $fmt[] = '%d';
                $approver = $route['employee'];
            }
            if ($approver) {
                $row['approver_employee_id'] = $approver->id ? (int) $approver->id : null;
                $row['approver_wp_user_id'] = (int) $approver->wp_user_id;
                $fmt[] = '%d';
                $fmt[] = '%d';
            }
            if ($st['skip'] !== '') {
                $row['skip_reason'] = $st['skip'];
                $fmt[] = '%s';
            }
            if ($st['status'] === 'PENDING') {
                $row['pending_since'] = $now;
                $fmt[] = '%s';
                if ($rerouted && !$approver) $adminsNow = $order;
            }
            if (!$this->requests->insertStep($row, $fmt)) return $this->startFailed($manageTransaction);
        }
        if ($manageTransaction && !$this->tx->commit()) {
            $this->tx->rollback();
            return ApprovalResult::error('approval_db_error', 'Could not commit approval request.');
        }
        if ($adminsNow) {
            if ($manageTransaction) $this->notifier->administratorsNeeded($approvalId, $adminsNow);
            else $this->after->administratorsIfStillWaiting($approvalId, $adminsNow);
        }
        return ApprovalResult::ok($approvalId);
    }

    /** A write of start() failed: rolls back (only when this class owns the transaction), then reports the database's message. */
    private function startFailed(bool $manageTransaction): ApprovalResult
    {
        if ($manageTransaction) $this->tx->rollback();
        return ApprovalResult::error('approval_db_error', $this->requests->lastError());
    }

    /**
     * Who a level's resolver points at.
     * @param mixed $value the level's resolver_value
     * @param array<string,mixed> $context
     */
    private function resolveApprover(int $requesterEmployeeId, string $resolverType, $value, array $context): ?object
    {
        $type = strtoupper(preg_replace('/[^a-z0-9_\-]/', '', strtolower($resolverType)));
        if ($type === 'SUPERVISOR') return $this->directory->supervisorOf($requesterEmployeeId);
        if ($type === 'TEAM_MANAGER') return $this->directory->teamManager($requesterEmployeeId);
        if ($type === 'MANAGERS_MANAGER') {
            $manager = $this->directory->supervisorOf($requesterEmployeeId);
            return $manager ? $this->directory->supervisorOf((int) $manager->id) : null;
        }
        if ($type === 'DEPARTMENT_MANAGER') return $this->directory->departmentManager($requesterEmployeeId);
        if ($type === 'HR' || $type === 'FINANCE') {
            $uid = $this->policy->settings()[$type === 'HR' ? 'hr_user' : 'finance_user'];
            if (!$uid || !$this->directory->userExists($uid)) return null;
            return $this->directory->employeeByUser($uid) ?: (object) ['id' => null, 'wp_user_id' => $uid];
        }
        if ($type === 'SPECIFIC_EMPLOYEE') {
            $id = abs((int) $value);
            return $id ? $this->directory->employee($id, true) : null;
        }
        if ($type === 'SPECIFIC_USER') {
            $uid = abs((int) $value);
            if (!$uid) return null;
            if (!$this->directory->userExists($uid)) return null;
            $employee = $this->directory->employeeByUser($uid);
            if ($employee) return $employee;
            return (object) ['id' => null, 'wp_user_id' => $uid];
        }
        if ($type === 'TARGET_EMPLOYEE') {
            $id = abs((int) ($context['target_employee_id'] ?? 0));
            return $id ? $this->directory->employee($id, true) : null;
        }
        return null;
    }

    /** Whether the user (already known to be signed in) may decide the request's open level, or the one of this order. */
    public function canAct(int $requestId, ?int $order, int $userId): bool
    {
        $step = $this->requests->pendingStepAt($requestId, $order);
        if (!$step) return false;
        return $this->mayDecide($step, $userId, false)->ok === true;
    }

    /**
     * Whether $userId may decide this pending level: its approver, or (a level sent to administrators, or $adminOverride) an
     * administrator. A requester deciding their own request needs the workflow to allow it.
     */
    public function mayDecide(object $step, int $userId, bool $adminOverride): ApprovalResult
    {
        $request = $this->requests->requestForCheck((int) $step->approval_request_id);
        if (!$request) return ApprovalResult::error('approval_not_found', 'Approval request not found.');
        $isSelf = $userId && $userId === $this->requesterUserId($request);
        if ($isSelf && $this->policy->selfRule($this->workflows->keyById((int) $request->workflow_id)) !== 'allow') {
            return ApprovalResult::error('approval_self_not_allowed', 'This workflow does not allow approving your own request.');
        }
        if ($adminOverride) return ApprovalResult::ok(true);
        if ($userId && (int) $step->approver_wp_user_id === $userId) return ApprovalResult::ok(true);
        if ($this->substitutes->managerMayDecide($step, $userId)) return ApprovalResult::ok(true);   // the manager away, deciding anyway (3.31.107)
        if (!empty($step->rerouted) && !(int) $step->approver_wp_user_id && $userId && $this->permissions->isAdmin($userId)) return ApprovalResult::ok(true);
        return ApprovalResult::error('approval_not_authorized', 'You are not the assigned approver.');
    }

    private function requesterUserId(?object $request): int
    {
        if (!$request) return 0;
        return $this->directory->userId((int) $request->requester_employee_id);
    }

    /**
     * Applies a decision to the open level and moves the request on. $expectedStep: the level the form showed (3.31.122); when
     * the open level is another one the decision is refused (approval_moved), and the update touches only that level. null = the
     * form did not say: the open level, as before.
     */
    public function act(int $requestId, string $decision, string $comment, bool $manageTransaction, bool $adminOverride, ?int $expectedStep, int $actorUserId, bool $loggedIn): ApprovalResult
    {
        if (!$requestId || !in_array($decision, ['approve', 'reject'], true)) return ApprovalResult::error('invalid_decision', 'Invalid approval decision.');
        $request = $this->requests->requestRow($requestId);
        if (!$request) return ApprovalResult::error('approval_not_found', 'Approval request not found.');
        $step = $this->requests->currentStep($requestId);
        // Before the approver check, so the approver of a level already decided is told it moved, not that it is not theirs.
        if (LevelGuard::check($expectedStep, $step ? (int) $step->id : 0) !== LevelGuard::OK) return self::moved();
        if (!$step) return ApprovalResult::error('approval_not_pending', 'No pending approval step.');
        $uid = $actorUserId;
        if (!$adminOverride && !$loggedIn) return ApprovalResult::error('approval_not_authorized', 'You are not the assigned approver.');
        $may = $this->mayDecide($step, $uid, $adminOverride);
        if (!$may->ok) return $may;
        // A reason to reject, when the workflow asks for one (3.31.103); an administrator's override on the Requests Hub is not held to it.
        if ($decision === 'reject' && trim($comment) === '' && !$adminOverride && $this->policy->rejectNoteRequired($this->workflows->keyById((int) $request->workflow_id))) {
            return ApprovalResult::error('approval_note_required', 'Write why you reject it.');
        }
        $isSelf = $uid && $uid === $this->requesterUserId($request);

        $now = $this->clock->now();
        $next = $decision === 'approve' ? $this->requests->nextWaiting($requestId) : null;
        $to = StateMachine::afterDecision($decision, $next ? (int) $next->step_order : null);
        if ($manageTransaction && !$this->tx->begin()) return ApprovalResult::error('approval_db_error', 'Could not start approval transaction.');
        $ok = $this->requests->decideStep((int) $step->id, [
            'status' => $to['step'],
            'decision' => $decision,
            'comment' => $comment,
            'acted_at' => $now,
            'acted_by_wp_user_id' => $uid ?: null,
            'self_decision' => $isSelf ? 1 : 0,
        ]);
        if ($ok !== 1) {
            if ($manageTransaction) $this->tx->rollback();
            return $expectedStep !== null ? self::moved() : ApprovalResult::error('approval_stale', 'Approval step was already handled.');
        }
        if ($next) {
            $set = ['status' => 'PENDING', 'pending_since' => $now];
            $route = (int) $next->approver_employee_id ? $this->substitutes->route((int) $next->approver_employee_id, (int) $request->requester_employee_id) : null;
            if ($route) {
                $set += ['approver_employee_id' => (int) $route['employee']->id, 'approver_wp_user_id' => (int) $route['employee']->wp_user_id, 'for_employee_id' => (int) $next->approver_employee_id, 'substitute_id' => (int) $route['row']->id];
            }
            $ok = $this->requests->openNext((int) $next->id, $set);
            if ($ok !== 1) return $this->actFailed($manageTransaction);
            $ok = $this->requests->updateRequest($requestId, ['status' => $to['request'], 'current_step' => $to['current_step']], ['%s', '%d']);
        } elseif ($decision === 'reject') {
            $ok = $this->requests->updateRequest($requestId, ['status' => $to['request'], 'completed_at' => $now], ['%s', '%s']);
        } else {
            $ok = $this->requests->updateRequest($requestId, ['status' => $to['request'], 'current_step' => null, 'completed_at' => $now], ['%s', '%d', '%s']);
        }
        if ($ok !== 1) return $this->actFailed($manageTransaction);
        if ($manageTransaction && !$this->tx->commit()) {
            $this->tx->rollback();
            return ApprovalResult::error('approval_db_error', 'Could not commit approval decision.');
        }
        $this->substitutes->afterDecision($step, $uid);
        if ($isSelf) {
            $this->audit->record('approval_self_decision', 'approval_request', $requestId,
                $this->workflows->keyById((int) $request->workflow_id) . ' · level ' . (int) $step->step_order . ' · ' . $decision);
        }
        if ($next && !empty($next->rerouted) && !(int) $next->approver_wp_user_id) {
            if ($manageTransaction) $this->notifier->administratorsNeeded($requestId, (int) $next->step_order);
            else $this->after->administratorsIfStillWaiting($requestId, (int) $next->step_order);
        }
        return ApprovalResult::ok(true);
    }

    private function actFailed(bool $manageTransaction): ApprovalResult
    {
        if ($manageTransaction) $this->tx->rollback();
        return ApprovalResult::error('approval_db_error', $this->requests->lastError());
    }

    private static function moved(): ApprovalResult
    {
        return ApprovalResult::error('approval_moved', 'This request moved to another level since the page was opened; nothing was changed.');
    }

    /**
     * The employee withdrew a request still waiting: the approval request is closed and the levels still to decide are marked
     * withdrawn. The caller owns the transaction.
     */
    public function closeWithdrawn(string $workflowKey, string $entityType, int $entityId): bool
    {
        $ar = $this->requests->findByKey($workflowKey, $entityType, $entityId);
        $this->after->syncSubstitutes();   // a withdrawn leave cancels its substitute (3.31.107)
        if (!$ar) return true;
        if (in_array($ar->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) return false;
        $now = $this->clock->now();
        if ($this->requests->cancelRequestIf((int) $ar->id, (string) $ar->status, $now) !== 1) return false;
        return $this->requests->cancelOpenSteps((int) $ar->id, false);
    }

    /**
     * Cancels an entity's approval request if it is still open (3.31.117, attendance corrections). Unlike closeWithdrawn(), a
     * request already decided when read is left as it is and counts as done. The caller owns the transaction. $closed: closed by
     * the system, not withdrawn.
     * @return true|string|false true: cancelled or nothing open; 'moved': it was decided while being closed; false: a database error
     */
    public function closeOpen(string $workflowKey, string $entityType, int $entityId, bool $closed)
    {
        $this->requests->clearError();
        $ar = $this->requests->findByKey($workflowKey, $entityType, $entityId);
        if (!$ar && $this->requests->lastError() !== '') return false;   // not read: not "nothing open"
        $this->after->syncSubstitutes();
        if (!$ar || in_array($ar->status, ['APPROVED', 'REJECTED', 'CANCELLED'], true)) return true;
        // The levels first, then the request: the order act() locks them in, so a decision at the same moment waits instead of
        // deadlocking. Both roll back with the caller's transaction when the request was decided meanwhile.
        if (!$this->requests->cancelOpenSteps((int) $ar->id, $closed)) return false;
        $n = $this->requests->cancelRequestIf((int) $ar->id, (string) $ar->status, $this->clock->now());
        if ($n === false) return false;
        return $n === 1 ? true : 'moved';
    }

    /**
     * An employee was moved under another manager (3.31.98): the levels of their open requests waiting for the old manager now
     * wait for the new one. Levels already decided keep who decided them. The new manager is told after the caller's commit.
     * @param int $pending set to the moved levels waiting now
     * @return int|false levels moved, false on a database error
     */
    public function reassignSupervisor(int $employeeId, int $fromEmployeeId, int $toEmployeeId, int &$pending)
    {
        $pending = 0;
        $to = $toEmployeeId ? $this->directory->employee($toEmployeeId, true) : null;
        if (!$fromEmployeeId || !$to || (int) $to->id === $fromEmployeeId) return 0;
        $ids = $this->requests->supervisorStepIds($employeeId, $fromEmployeeId);
        if (!$ids) return 0;
        if ($this->requests->reassignSteps($ids, (int) $to->id, (int) $to->wp_user_id) === false) return false;
        $pending = $this->requests->countPending($ids);
        return count($ids);
    }

    /** Whether the level still waits for administrators (the check after a caller that owned the transaction). */
    public function stillWithAdministrators(int $requestId, int $order): bool
    {
        return $this->requests->stillWithAdministrators($requestId, $order);
    }
}
