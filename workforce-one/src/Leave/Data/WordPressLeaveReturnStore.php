<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for LeaveReturnStore (3.31.164): the Leave module's tables only, every statement as it was in the trait. */
final class WordPressLeaveReturnStore implements LeaveReturnStore
{
    /** @var \wpdb */
    private $db;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
    }

    private function lock(): string
    {
        return (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') ? '' : ' FOR UPDATE';
    }

    public function lockLeave(int $id): ?object
    {
        $p = $this->db->prefix;
        $lock = $this->lock();
        $r = $this->db->get_row($this->db->prepare("SELECT r.*,lt.name type_name,lt.deduct_balance,lt.kind,lt.carry_mode,lt.carry_max FROM {$p}ews_leave_requests r JOIN {$p}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id=%d LIMIT 1{$lock}", $id));
        return is_object($r) ? $r : null;
    }

    public function findOwn(int $id, $employeeId): ?object
    {
        $p = $this->db->prefix;
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$p}ews_leave_requests WHERE id=%d AND employee_id=%d LIMIT 1", $id, $employeeId));
        return is_object($r) ? $r : null;
    }

    public function changes(int $leaveId, int $limit): array
    {
        $p = $this->db->prefix;
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$p}ews_leave_changes WHERE leave_request_id=%d ORDER BY id DESC LIMIT %d", $leaveId, $limit));
    }

    public function changeCount(int $leaveId): int
    {
        $p = $this->db->prefix;
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$p}ews_leave_changes WHERE leave_request_id=%d", $leaveId));
    }

    public function insertChange(array $row): int
    {
        return $this->db->insert($this->db->prefix . 'ews_leave_changes', $row) === false ? 0 : (int) $this->db->insert_id;
    }

    public function closeChange(int $id, array $set): bool
    {
        return $this->db->update($this->db->prefix . 'ews_leave_changes', $set, ['id' => $id, 'status' => 'Pending']) !== false;
    }

    public function finishChange(int $id, array $set): bool
    {
        return $this->db->update($this->db->prefix . 'ews_leave_changes', $set, ['id' => $id]) !== false;
    }

    public function daysFrom(int $leaveId, string $from): int
    {
        $p = $this->db->prefix;
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d AND work_date>=%s", $leaveId, $from));
    }

    public function leaveDays(int $leaveId): ?array
    {
        $p = $this->db->prefix;
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date ASC", $leaveId));
        return $rows === null ? null : (array) $rows;
    }

    public function leaveDaysFrom(int $leaveId, string $from): ?array
    {
        $p = $this->db->prefix;
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d AND work_date>=%s ORDER BY work_date ASC", $leaveId, $from));
        return $rows === null ? null : (array) $rows;
    }

    public function lockLeaveDaysFrom(int $leaveId, string $from): ?array
    {
        $p = $this->db->prefix;
        $lock = $this->lock();
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d AND work_date>=%s ORDER BY work_date ASC{$lock}", $leaveId, $from));
        return $rows === null ? null : (array) $rows;
    }

    public function countLeaveDays(int $leaveId): int
    {
        $p = $this->db->prefix;
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d", $leaveId));
    }

    public function deleteLeaveDay(int $snapshotId)
    {
        return $this->db->delete($this->db->prefix . 'ews_leave_schedule_snapshots', ['id' => $snapshotId]);
    }

    public function snapshot(int $id): ?object
    {
        $p = $this->db->prefix;
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$p}ews_leave_schedule_snapshots WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function snapshotId(int $id): ?string
    {
        $p = $this->db->prefix;
        $v = $this->db->get_var($this->db->prepare("SELECT id FROM {$p}ews_leave_schedule_snapshots WHERE id=%d", $id));
        return $v === null ? null : (string) $v;
    }

    public function lowerUsed($balanceId, $days, string $at)
    {
        $p = $this->db->prefix;
        return $this->db->query($this->db->prepare("UPDATE {$p}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d", $days, $at, $balanceId));
    }

    public function balanceFor(int $employeeId, int $typeId, int $year): ?object
    {
        $bt = $this->db->prefix . 'ews_leave_balances';
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1", $employeeId, $typeId, $year));
        return is_object($r) ? $r : null;
    }

    public function balanceById(int $id): ?object
    {
        $bt = $this->db->prefix . 'ews_leave_balances';
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$bt} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function addCarried(int $id, $add, string $at)
    {
        $bt = $this->db->prefix . 'ews_leave_balances';
        return $this->db->query($this->db->prepare("UPDATE {$bt} SET carried=carried+%f,entitlement=entitlement+%f,updated_at=%s WHERE id=%d", $add, $add, $at, $id));
    }

    public function cancelWhole(int $id, int $by, string $at)
    {
        return $this->db->update($this->db->prefix . 'ews_leave_requests', ['status' => 'Cancelled', 'cancellation_status' => 'Approved', 'cancelled_by' => $by, 'cancelled_at' => $at], ['id' => $id, 'status' => 'Approved', 'cancellation_status' => 'Pending'], ['%s', '%s', '%d', '%s'], ['%d', '%s', '%s']);
    }

    public function endLegacy(int $id, int $by, string $at)
    {
        return $this->db->update($this->db->prefix . 'ews_leave_requests', ['cancellation_status' => 'Ended', 'cancelled_by' => $by, 'cancelled_at' => $at], ['id' => $id, 'status' => 'Approved', 'cancellation_status' => 'Pending'], ['%s', '%d', '%s'], ['%d', '%s', '%s']);
    }

    public function shorten(int $id, string $end, $days, int $by, string $at)
    {
        return $this->db->update($this->db->prefix . 'ews_leave_requests', ['end_date' => $end, 'requested_days' => $days, 'cancellation_status' => 'Approved', 'cancelled_by' => $by, 'cancelled_at' => $at], ['id' => $id, 'status' => 'Approved', 'cancellation_status' => 'Pending'], ['%s', '%f', '%s', '%d', '%s'], ['%d', '%s', '%s']);
    }

    public function endPending(int $id, int $by, string $at)
    {
        $t = $this->db->prefix . 'ews_leave_requests';
        return $this->db->query($this->db->prepare("UPDATE {$t} SET cancellation_status='Ended',cancelled_by=%d,cancelled_at=%s WHERE id=%d AND status='Approved' AND cancellation_status='Pending'", $by, $at, $id));
    }

    public function applyReturn(int $id, array $set, ?string $was)
    {
        $t = $this->db->prefix . 'ews_leave_requests';
        $where = "id=%d AND status='Approved' AND " . ($was === null ? 'cancellation_status IS NULL' : 'cancellation_status=%s');
        $parts = [];
        $vals = [];
        foreach ($set as $k => $v) { $parts[] = "`{$k}`=" . (is_int($v) ? '%d' : (is_float($v) ? '%f' : '%s')); $vals[] = $v; }
        $vals[] = $id;
        if ($was !== null) $vals[] = $was;
        return $this->db->query($this->db->prepare("UPDATE {$t} SET " . implode(',', $parts) . " WHERE {$where}", $vals));
    }

    public function rejectCancellation(int $id)
    {
        return $this->db->update($this->db->prefix . 'ews_leave_requests', ['cancellation_status' => 'Rejected'], ['id' => $id, 'status' => 'Approved', 'cancellation_status' => 'Pending'], ['%s'], ['%d', '%s', '%s']);
    }

    public function markPending(int $id, ?string $from)
    {
        $p = $this->db->prefix;
        if ($from !== null) return $this->db->query($this->db->prepare("UPDATE {$p}ews_leave_requests SET cancellation_status='Pending',cancel_from=%s WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status='Not Requested')", $from, $id));
        return $this->db->query($this->db->prepare("UPDATE {$p}ews_leave_requests SET cancellation_status='Pending' WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status='Not Requested')", $id));
    }

    public function askAgain(int $id, string $back)
    {
        $p = $this->db->prefix;
        return $this->db->query($this->db->prepare("UPDATE {$p}ews_leave_requests SET cancellation_status='Pending',cancel_from=%s WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status IN ('Not Requested','Approved','Rejected','Withdrawn','Ended'))", $back, $id));
    }

    public function withdrawCancellation(int $id, int $employeeId)
    {
        return $this->db->update($this->db->prefix . 'ews_leave_requests', ['cancellation_status' => 'Withdrawn'], ['id' => $id, 'employee_id' => $employeeId, 'status' => 'Approved', 'cancellation_status' => 'Pending'], ['%s'], ['%d', '%d', '%s', '%s']);
    }

    public function approvedLeaves(int $employeeId): array
    {
        $p = $this->db->prefix;
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT r.id,r.start_date,r.end_date,r.requested_days,r.cancellation_status,t.name type_name FROM {$p}ews_leave_requests r JOIN {$p}ews_leave_types t ON t.id=r.leave_type_id
             WHERE r.employee_id=%d AND r.status='Approved' ORDER BY r.start_date DESC LIMIT 50", $employeeId));
    }
}
