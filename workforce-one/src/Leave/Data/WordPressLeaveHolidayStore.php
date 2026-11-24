<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for LeaveHolidayStore (3.31.162): the Leave module's tables only. */
final class WordPressLeaveHolidayStore implements LeaveHolidayStore
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

    public function leavesOnDays(array $days, int $fromYear): array
    {
        $p = $this->db->prefix;
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('strval', $days))), 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            $ids = $this->db->get_col($this->db->prepare(
                "SELECT DISTINCT s.leave_request_id FROM {$p}ews_leave_schedule_snapshots s JOIN {$p}ews_leave_requests r ON r.id=s.leave_request_id
                 JOIN {$p}ews_leave_types lt ON lt.id=r.leave_type_id LEFT JOIN {$p}ews_leave_balances b ON b.id=r.balance_id
                 WHERE s.work_date IN ({$in}) AND r.status='Approved' AND lt.deduct_balance=1
                   AND (b.leave_year>=%d OR (b.id IS NULL AND YEAR(r.requested_at)>=%d))", array_merge($chunk, [$fromYear, $fromYear])));
            foreach ((array) $ids as $id) $out[(int) $id] = true;
        }
        return array_keys($out);
    }

    public function leaves(array $ids): array
    {
        $p = $this->db->prefix;
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            foreach ((array) $this->db->get_results($this->db->prepare(
                "SELECT r.id,r.employee_id,r.start_date,r.end_date,r.requested_days,r.status,r.cancellation_status,lt.name type_name,lt.deduct_balance
                 FROM {$p}ews_leave_requests r JOIN {$p}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id IN ({$in})", $chunk)) as $r) $out[] = $r;
        }
        return $out;
    }

    public function leaveDays(array $ids): array
    {
        $p = $this->db->prefix;
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 300) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%d'));
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT leave_request_id,work_date FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id IN ({$in}) ORDER BY work_date ASC", $chunk)) as $s)
                $out[(int) $s->leave_request_id][] = (string) $s->work_date;
        }
        return $out;
    }

    public function lockLeave(int $id): ?object
    {
        $p = $this->db->prefix;
        $r = $this->db->get_row($this->db->prepare("SELECT r.*,lt.name type_name,lt.deduct_balance,lt.kind,lt.carry_mode,lt.carry_max FROM {$p}ews_leave_requests r
            JOIN {$p}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id=%d LIMIT 1" . $this->lock(), $id));
        return is_object($r) ? $r : null;
    }

    public function lockLeaveDays(int $id): array
    {
        $p = $this->db->prefix;
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$p}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date ASC" . $this->lock(), $id));
    }

    public function refundedDays(int $leaveId): array
    {
        return array_map('strval', (array) $this->db->get_col($this->db->prepare("SELECT work_date FROM {$this->db->prefix}ews_leave_holiday_refunds WHERE leave_request_id=%d", $leaveId)));
    }

    public function addRefund(int $leaveId, int $employeeId, string $day, int $balanceId, string $holiday, int $by, string $at): bool
    {
        $prev = $this->db->suppress_errors(true);
        $q = $this->db->insert($this->db->prefix . 'ews_leave_holiday_refunds', ['leave_request_id' => $leaveId, 'employee_id' => $employeeId, 'work_date' => $day, 'days' => 1,
            'balance_id' => $balanceId, 'holiday' => mb_substr($holiday, 0, 190), 'created_by' => $by, 'created_at' => $at], ['%d', '%d', '%s', '%f', '%d', '%s', '%d', '%s']);
        $this->db->suppress_errors($prev);
        return $q === 1;
    }

    public function removeLeaveDay(int $snapshotId): bool
    {
        return $this->db->delete($this->db->prefix . 'ews_leave_schedule_snapshots', ['id' => $snapshotId]) === 1;
    }

    public function setRequestedDays(int $leaveId, float $before, float $after)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->db->prefix}ews_leave_requests SET requested_days=%f WHERE id=%d AND status='Approved' AND requested_days=%f", $after, $leaveId, $before));
    }

    public function lowerUsed(int $balanceId, float $days, string $at): bool
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->db->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d", $days, $at, $balanceId)) !== false;
    }
}
