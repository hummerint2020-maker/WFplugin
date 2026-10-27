<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/**
 * Where approval requests and their levels ("steps", table approval_steps) are kept (3.31.128). One method per SQL text the
 * old code ran, because the golden trace compares the SQL itself. A Store never begins or commits: the Service does.
 */
interface ApprovalRequestStore
{
    // ---- reads of one request

    /** The request row (act's read, with LIMIT 1). */
    public function requestRow(int $id): ?object;

    /** The request row (may-decide's read, without LIMIT). */
    public function requestForCheck(int $id): ?object;

    /** The first PENDING level, lowest order first. */
    public function currentStep(int $requestId): ?object;

    /** A PENDING level, optionally of one order (no ORDER BY). */
    public function pendingStepAt(int $requestId, ?int $order): ?object;

    /** The next WAITING level, lowest order first. */
    public function nextWaiting(int $requestId): ?object;

    /** The id of a request already started for the entity and workflow, or null. */
    public function existingId(string $entityType, int $entityId, int $workflowId): ?string;

    /** The newest request of an entity under a workflow key (joins its workflow's key and mode). */
    public function findByKey(string $workflowKey, string $entityType, int $entityId): ?object;

    // ---- reads for lists

    /**
     * Requests by [workflow key + "\0" + entity type => [entity id => true]] in one query (chunks of 500 ids), newest first.
     * @param array<string,array<int,bool>> $groups
     * @return array<int,object>
     */
    public function findByKeyGroups(array $groups): array;

    /**
     * The first PENDING level of each request. @param array<int,int> $requestIds @return array<int,object> by request id
     */
    public function currentSteps(array $requestIds): array;

    /**
     * Every level of each request, in order. @param array<int,int> $requestIds @return array<int,list<object>> by request id
     */
    public function stepsOf(array $requestIds): array;

    // ---- writes

    /**
     * @param array<string,mixed> $row
     * @param array<int,string|null> $formats
     * @return int|false the new id, false on a database error
     */
    public function insertRequest(array $row, array $formats);

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $formats
     * @return bool false on a database error
     */
    public function insertStep(array $row, array $formats): bool;

    /**
     * Decides a level that is still PENDING. @param array<string,mixed> $set
     * @return int|false rows changed (1 = decided), false on a database error
     */
    public function decideStep(int $stepId, array $set);

    /**
     * Opens the next level that is still WAITING. @param array<string,mixed> $set
     * @return int|false
     */
    public function openNext(int $stepId, array $set);

    /**
     * @param array<string,mixed> $set
     * @param array<int,string> $formats
     * @return int|false
     */
    public function updateRequest(int $id, array $set, array $formats);

    /** Cancels a request that still has the status that was read. @return int|false */
    public function cancelRequestIf(int $id, string $status, string $now);

    /** Cancels the levels still to decide ($closed: by the system, so they say "closed"). @return bool false on a database error */
    public function cancelOpenSteps(int $requestId, bool $closed): bool;

    /** Whether the level still waits for administrators (rerouted, no approver, still PENDING). */
    public function stillWithAdministrators(int $requestId, int $order): bool;

    // ---- organisation moves

    /**
     * Open steps waiting for a requester's manager: requester employee id => [approver employee id => steps].
     * @return array<int,array<int,int>>
     */
    public function waitingOnSupervisors(): array;

    /** @return list<int> the ids of the requester's open SUPERVISOR levels waiting for $fromEmployeeId */
    public function supervisorStepIds(int $requesterEmployeeId, int $fromEmployeeId): array;

    /**
     * @param list<int> $stepIds
     * @return int|false
     */
    public function reassignSteps(array $stepIds, int $toEmployeeId, int $toUserId);

    /**
     * Sends open levels to the cover of their new manager, "for" that manager. @param list<int> $stepIds
     * @return int|false
     */
    public function routeStepsToSubstitute(array $stepIds, int $substituteEmployeeId, int $substituteUserId, int $managerEmployeeId, int $substituteRowId);

    /** @param list<int> $stepIds */
    public function countPending(array $stepIds): int;

    // ---- My approvals

    /**
     * The levels waiting for a user (up to 200, longest waiting first), with the request's entity and the workflow's key. $admin: 1
     * adds the levels waiting for administrators; $away: the employee id of a manager who may still decide for their substitute (0 = none).
     * @return array<int,object>
     */
    public function inboxRows(int $userId, int $admin, int $away): array;

    /** Whether the user is, or was, the approver of any level, or decided one. */
    public function hasAnyStep(int $userId): bool;

    /** How many levels the user decided since $since. */
    public function decidedSince(int $userId, string $since): int;

    // ---- deadlines

    /** Levels waiting since before the deadlines existed have no start: their wait counts from $now. */
    public function backfillPendingSince(string $now): void;

    /**
     * The levels of a workflow still waiting on open requests (up to 500, longest waiting first), with the request's requester,
     * entity type and entity id.
     * @return array<int,object>
     */
    public function overdueCandidates(string $workflowKey): array;

    /** Marks a PENDING level as reminded. @return int|false 1 = marked */
    public function markReminded(int $stepId, string $now);

    /**
     * Moves a waiting level up, only while it still waits for $fromUserId. $up: [employee id, user id] of the new approver, or
     * null for the administrators. @param array{0:int,1:int}|null $up @return int|false 1 = moved
     */
    public function escalate(int $stepId, ?array $up, int $fromUserId, string $now);

    // ---- substitutes (a manager away with someone covering)

    /**
     * Levels waiting for the manager (not yet "for" anyone) go to the substitute. @return int|false levels moved
     */
    public function assignWaitingToSubstitute(int $substituteEmployeeId, int $substituteUserId, int $managerEmployeeId, int $substituteRowId);

    /** Levels that went to a substitute come back to the manager. @return int|false levels moved */
    public function returnPendingToManager(int $managerEmployeeId, int $managerUserId, int $substituteRowId);

    /** How many levels still wait for a substitute. */
    public function countPendingForSubstitute(int $substituteRowId): int;

    public function lastError(): string;

    /** Clears the last error (the caller reads it after a lookup that may fail). */
    public function clearError(): void;
}
