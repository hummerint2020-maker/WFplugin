<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for approval requests and levels (3.31.128); it never receives the plugin object. The SQL is the SQL the plugin
 * ran before, unchanged (same text, same order of arguments), so the golden trace is the same.
 */
final class WordPressApprovalRequestStore implements ApprovalRequestStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $requests;
    /** @var string */
    private $steps;
    /** @var string */
    private $workflows;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->requests = $db->prefix . 'ews_approval_requests';
        $this->steps = $db->prefix . 'ews_approval_steps';
        $this->workflows = $db->prefix . 'ews_approval_workflows';
    }

    private function one($r): ?object
    {
        return is_object($r) ? $r : null;
    }

    public function requestRow(int $id): ?object
    {
        return $this->one($this->db->get_row($this->db->prepare("SELECT * FROM {$this->requests} WHERE id=%d LIMIT 1", $id)));
    }

    public function requestForCheck(int $id): ?object
    {
        return $this->one($this->db->get_row($this->db->prepare("SELECT * FROM {$this->requests} WHERE id=%d", $id)));
    }

    public function currentStep(int $requestId): ?object
    {
        return $this->one($this->db->get_row($this->db->prepare("SELECT * FROM {$this->steps} WHERE approval_request_id=%d AND status='PENDING' ORDER BY step_order ASC LIMIT 1", $requestId)));
    }

    public function pendingStepAt(int $requestId, ?int $order): ?object
    {
        $sql = "SELECT * FROM {$this->steps} WHERE approval_request_id=%d AND status='PENDING'";
        $args = [$requestId];
        if ($order !== null) {
            $sql .= ' AND step_order=%d';
            $args[] = $order;
        }
        $sql .= ' LIMIT 1';
        return $this->one($this->db->get_row($this->db->prepare($sql, $args)));
    }

    public function nextWaiting(int $requestId): ?object
    {
        return $this->one($this->db->get_row($this->db->prepare("SELECT * FROM {$this->steps} WHERE approval_request_id=%d AND status='WAITING' ORDER BY step_order ASC LIMIT 1", $requestId)));
    }

    public function existingId(string $entityType, int $entityId, int $workflowId): ?string
    {
        $v = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->requests} WHERE entity_type=%s AND entity_id=%d AND workflow_id=%d LIMIT 1", $entityType, $entityId, $workflowId));
        return $v === null ? null : (string) $v;
    }

    public function findByKey(string $workflowKey, string $entityType, int $entityId): ?object
    {
        return $this->one($this->db->get_row($this->db->prepare(
            "SELECT ar.*,w.workflow_key,w.approval_mode FROM {$this->requests} ar JOIN {$this->workflows} w ON w.id=ar.workflow_id WHERE w.workflow_key=%s AND ar.entity_type=%s AND ar.entity_id=%d ORDER BY ar.id DESC LIMIT 1",
            $workflowKey, $entityType, $entityId
        )));
    }

    public function findByKeyGroups(array $groups): array
    {
        $where = [];
        foreach ($groups as $g => $ids) {
            [$wk, $et] = explode("\0", (string) $g);
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $where[] = $this->db->prepare('(w.workflow_key=%s AND ar.entity_type=%s AND ar.entity_id IN (' . implode(',', array_fill(0, count($chunk), '%d')) . '))', array_merge([$wk, $et], $chunk));
            }
        }
        return (array) $this->db->get_results("SELECT ar.*,w.workflow_key,w.approval_mode FROM {$this->requests} ar JOIN {$this->workflows} w ON w.id=ar.workflow_id WHERE " . implode(' OR ', $where) . ' ORDER BY ar.id DESC');
    }

    public function currentSteps(array $requestIds): array
    {
        $out = [];
        $ids = array_values(array_unique(array_filter(array_map('intval', $requestIds))));
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->steps} WHERE status='PENDING' AND approval_request_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY approval_request_id ASC,step_order ASC', $chunk)) as $r) {
                if (!isset($out[(int) $r->approval_request_id])) $out[(int) $r->approval_request_id] = $r;
            }
        }
        return $out;
    }

    public function stepsOf(array $requestIds): array
    {
        $by = [];
        foreach (array_chunk(array_values($requestIds), 500) as $chunk) {
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->steps} WHERE approval_request_id IN (" . implode(',', array_fill(0, count($chunk), '%d')) . ') ORDER BY approval_request_id ASC,step_order ASC', $chunk)) as $r) {
                $by[(int) $r->approval_request_id][] = $r;
            }
        }
        return $by;
    }

    public function insertRequest(array $row, array $formats)
    {
        return $this->db->insert($this->requests, $row, $formats) === false ? false : (int) $this->db->insert_id;
    }

    public function insertStep(array $row, array $formats): bool
    {
        return $this->db->insert($this->steps, $row, $formats) !== false;
    }

    public function decideStep(int $stepId, array $set)
    {
        return $this->db->update($this->steps, $set, ['id' => $stepId, 'status' => 'PENDING'], ['%s', '%s', '%s', '%s', '%d', '%d'], ['%d', '%s']);
    }

    public function openNext(int $stepId, array $set)
    {
        return $this->db->update($this->steps, $set, ['id' => $stepId, 'status' => 'WAITING'], null, ['%d', '%s']);
    }

    public function updateRequest(int $id, array $set, array $formats)
    {
        return $this->db->update($this->requests, $set, ['id' => $id], $formats, ['%d']);
    }

    public function cancelRequestIf(int $id, string $status, string $now)
    {
        return $this->db->update($this->requests, ['status' => 'CANCELLED', 'current_step' => null, 'completed_at' => $now], ['id' => $id, 'status' => $status]);
    }

    public function cancelOpenSteps(int $requestId, bool $closed): bool
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->steps} SET status='CANCELLED'" . ($closed ? ",skip_reason='closed'" : '') . " WHERE approval_request_id=%d AND status IN ('PENDING','WAITING')", $requestId)) !== false;
    }

    public function stillWithAdministrators(int $requestId, int $order): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT i.id FROM {$this->steps} i JOIN {$this->requests} r ON r.id=i.approval_request_id
                WHERE i.approval_request_id=%d AND i.step_order=%d AND i.status='PENDING' AND i.rerouted=1 AND i.approver_wp_user_id IS NULL", $requestId, $order));
    }

    public function waitingOnSupervisors(): array
    {
        $out = [];
        foreach ((array) $this->db->get_results("SELECT r.requester_employee_id req,i.approver_employee_id appr,COUNT(*) n FROM {$this->steps} i JOIN {$this->requests} r ON r.id=i.approval_request_id
            WHERE i.resolver_type='SUPERVISOR' AND i.status IN ('PENDING','WAITING') AND i.approver_employee_id IS NOT NULL AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED')
            GROUP BY r.requester_employee_id,i.approver_employee_id") as $x) {
            $out[(int) $x->req][(int) $x->appr] = (int) $x->n;
        }
        return $out;
    }

    public function supervisorStepIds(int $requesterEmployeeId, int $fromEmployeeId): array
    {
        return array_map('intval', (array) $this->db->get_col($this->db->prepare("SELECT i.id FROM {$this->steps} i JOIN {$this->requests} r ON r.id=i.approval_request_id
            WHERE r.requester_employee_id=%d AND i.resolver_type='SUPERVISOR' AND i.status IN ('PENDING','WAITING') AND i.approver_employee_id=%d AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED')", $requesterEmployeeId, $fromEmployeeId)));
    }

    public function reassignSteps(array $stepIds, int $toEmployeeId, int $toUserId)
    {
        $ph = implode(',', array_fill(0, count($stepIds), '%d'));
        return $this->db->query($this->db->prepare("UPDATE {$this->steps} SET approver_employee_id=%d,approver_wp_user_id=%d,rerouted=0 WHERE id IN ($ph) AND status IN ('PENDING','WAITING')", array_merge([$toEmployeeId, $toUserId], $stepIds)));
    }

    public function countPending(array $stepIds): int
    {
        $ph = implode(',', array_fill(0, count($stepIds), '%d'));
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->steps} WHERE id IN ($ph) AND status='PENDING'", $stepIds));
    }

    public function inboxRows(int $userId, int $admin, int $away): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT i.id step_id,i.approval_request_id,i.pending_since,i.approver_wp_user_id,i.for_employee_id,r.entity_type,r.entity_id,r.requester_employee_id,w.workflow_key FROM {$this->steps} i
            JOIN {$this->requests} r ON r.id=i.approval_request_id JOIN {$this->workflows} w ON w.id=r.workflow_id
            WHERE i.status='PENDING' AND r.status LIKE 'WAITING%%' AND (i.approver_wp_user_id=%d OR (%d=1 AND i.rerouted=1 AND i.approver_wp_user_id IS NULL) OR (%d>0 AND i.for_employee_id=%d))
            ORDER BY i.pending_since ASC,i.id ASC LIMIT 200", $userId, $admin, $away, $away));
    }

    public function hasAnyStep(int $userId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->steps} WHERE approver_wp_user_id=%d OR acted_by_wp_user_id=%d LIMIT 1", $userId, $userId));
    }

    public function decidedSince(int $userId, string $since): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->steps} WHERE acted_by_wp_user_id=%d AND acted_at>=%s", $userId, $since));
    }

    public function backfillPendingSince(string $now): void
    {
        $this->db->query($this->db->prepare("UPDATE {$this->steps} SET pending_since=%s WHERE status='PENDING' AND pending_since IS NULL", $now));
    }

    public function overdueCandidates(string $workflowKey): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT i.*,r.requester_employee_id,r.entity_type,r.entity_id FROM {$this->steps} i JOIN {$this->requests} r ON r.id=i.approval_request_id JOIN {$this->workflows} w ON w.id=r.workflow_id
                WHERE w.workflow_key=%s AND i.status='PENDING' AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED') ORDER BY i.pending_since ASC LIMIT 500", $workflowKey));
    }

    public function markReminded(int $stepId, string $now)
    {
        return $this->db->update($this->steps, ['reminded_at' => $now], ['id' => $stepId, 'status' => 'PENDING'], ['%s'], ['%d', '%s']);
    }

    public function escalate(int $stepId, ?array $up, int $fromUserId, string $now)
    {
        $set = ($up ? $this->db->prepare('approver_employee_id=%d,approver_wp_user_id=%d,rerouted=0', $up[0], $up[1]) : 'approver_employee_id=NULL,approver_wp_user_id=NULL,rerouted=1') . ',for_employee_id=NULL,substitute_id=NULL';
        return $this->db->query($this->db->prepare("UPDATE {$this->steps} SET {$set},pending_since=%s,reminded_at=NULL,escalations=escalations+1,escalated_from_wp_user_id=%d WHERE id=%d AND status='PENDING' AND approver_wp_user_id=%d",
            $now, $fromUserId, $stepId, $fromUserId));
    }

    public function assignWaitingToSubstitute(int $substituteEmployeeId, int $substituteUserId, int $managerEmployeeId, int $substituteRowId)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->steps} SET approver_employee_id=%d,approver_wp_user_id=%d,for_employee_id=%d,substitute_id=%d,reminded_at=NULL
            WHERE status='PENDING' AND approver_employee_id=%d AND for_employee_id IS NULL
            AND approval_request_id IN (SELECT id FROM {$this->requests} WHERE status LIKE 'WAITING%%' AND requester_employee_id<>%d)",
            $substituteEmployeeId, $substituteUserId, $managerEmployeeId, $substituteRowId, $managerEmployeeId, $substituteEmployeeId));
    }

    public function returnPendingToManager(int $managerEmployeeId, int $managerUserId, int $substituteRowId)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->steps} SET approver_employee_id=%d,approver_wp_user_id=%d,for_employee_id=NULL,substitute_id=NULL,reminded_at=NULL WHERE substitute_id=%d AND status='PENDING'",
            $managerEmployeeId, $managerUserId, $substituteRowId));
    }

    public function countPendingForSubstitute(int $substituteRowId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->steps} WHERE substitute_id=%d AND status='PENDING'", $substituteRowId));
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }

    public function clearError(): void
    {
        $this->db->last_error = '';
    }
}
