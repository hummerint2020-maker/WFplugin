<?php
namespace WorkforceOne\Approvals;

use WorkforceOne\Approvals\Data\ApprovalRequestStore;

if (!defined('ABSPATH')) exit;

/**
 * Reads of approval requests and their levels, for pages and for the modules' checks (3.31.128). No transaction, no writes.
 * Names and keys come already cleaned (sanitize_key) by the trait that calls this.
 */
final class ApprovalReads
{
    /** @var ApprovalRequestStore */
    private $requests;

    public function __construct(ApprovalRequestStore $requests)
    {
        $this->requests = $requests;
    }

    /** The key a request is found under in the batch reads (the same text sanitize_key() gives). */
    public static function requestKey(string $workflowKey, string $entityType, int $entityId): string
    {
        return self::clean($workflowKey) . '|' . self::clean($entityType) . '|' . $entityId;
    }

    private static function clean(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }

    public function findRequest(string $workflowKey, string $entityType, int $entityId): ?object
    {
        return $this->requests->findByKey($workflowKey, $entityType, $entityId);
    }

    /**
     * The newest request of each [workflow key, entity type, entity id], one query: "workflow|entity|id" => the row
     * (a request with none is missing from the map).
     * @param array<int,array{0:string,1:string,2:int}> $keys
     * @return array<string,object>
     */
    public function findRequests(array $keys): array
    {
        $groups = [];
        foreach ($keys as $k) {
            if (!is_array($k) || count($k) < 3) continue;
            $groups[self::clean((string) $k[0]) . "\0" . self::clean((string) $k[1])][(int) $k[2]] = true;
        }
        if (!$groups) return [];
        $out = [];
        foreach ($this->requests->findByKeyGroups($groups) as $r) {
            $key = self::requestKey((string) $r->workflow_key, (string) $r->entity_type, (int) $r->entity_id);
            if (!isset($out[$key])) $out[$key] = $r;
        }
        return $out;
    }

    public function currentStep(int $requestId): ?object
    {
        return $this->requests->currentStep($requestId);
    }

    /**
     * @param array<int,int> $requestIds
     * @return array<int,object> request id => its first PENDING level
     */
    public function currentSteps(array $requestIds): array
    {
        return $this->requests->currentSteps($requestIds);
    }

    /**
     * Every level of each request. @param array<int,int> $requestIds @return array<int,list<object>> by request id
     */
    public function stepsOf(array $requestIds): array
    {
        return $this->requests->stepsOf($requestIds);
    }

    /**
     * The open level of each request, for the forms of a list: request key => step id (0 = none open).
     * @param array<int,array{0:string,1:string,2:int}> $keys
     * @return array<string,int>
     */
    public function shownSteps(array $keys): array
    {
        $found = $this->findRequests($keys);
        $current = $this->currentSteps(array_map(function ($ar) {
            return (int) $ar->id;
        }, array_values($found)));
        $out = [];
        foreach ($keys as $k) {
            $key = self::requestKey((string) $k[0], (string) $k[1], (int) $k[2]);
            $ar = $found[$key] ?? null;
            $out[$key] = $ar && isset($current[(int) $ar->id]) ? (int) $current[(int) $ar->id]->id : 0;
        }
        return $out;
    }

    /** Whether the level the form showed is no longer the open one (the engine's level guard, see LevelGuard). */
    public function levelMoved(int $requestId, ?int $posted): bool
    {
        if ($posted === null) return false;
        $step = $requestId ? $this->currentStep($requestId) : null;
        return LevelGuard::check($posted, $step ? (int) $step->id : 0) !== LevelGuard::OK;
    }

    /** levelMoved() for a request by its key. */
    public function levelMovedFor(string $workflowKey, string $entityType, int $entityId, ?int $posted): bool
    {
        if ($posted === null) return false;
        $ar = $this->findRequest($workflowKey, $entityType, $entityId);
        return $this->levelMoved($ar ? (int) $ar->id : 0, $posted);
    }

    /**
     * The levels waiting for a user, for My approvals (up to 200, longest waiting first).
     * @return array<int,object>
     */
    public function inboxRows(int $userId, bool $admin, int $awayEmployeeId): array
    {
        return $this->requests->inboxRows($userId, $admin ? 1 : 0, $awayEmployeeId);
    }

    /** Whether the user is, or was, the approver of any level, or decided one. */
    public function hasAnyStep(int $userId): bool
    {
        return $this->requests->hasAnyStep($userId);
    }

    /** How many levels the user decided since $since. */
    public function decidedSince(int $userId, string $since): int
    {
        return $this->requests->decidedSince($userId, $since);
    }

    /**
     * Open steps waiting for a requester's manager, for the Org chart's Move.
     * @return array<int,array<int,int>> requester employee id => [approver employee id => steps]
     */
    public function waitingOnSupervisors(): array
    {
        return $this->requests->waitingOnSupervisors();
    }
}
