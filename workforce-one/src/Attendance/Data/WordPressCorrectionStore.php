<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the correction requests (3.31.140). The SQL is the SQL the plugin ran before, unchanged. The table follows the prefix of the container. */
final class WordPressCorrectionStore implements CorrectionStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_attendance_corrections';
    }

    public function monthCount(int $employeeId, string $from, string $to): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t} WHERE employee_id=%d AND source='employee' AND status IN ('pending','pending_hr','approved') AND work_date BETWEEN %s AND %s", $employeeId, $from, $to));
    }

    public function pendingForDay(int $employeeId, string $date): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND status IN ('pending','pending_hr') ORDER BY id DESC LIMIT 1", $employeeId, $date));
        return is_object($r) ? $r : null;
    }

    public function recentFor(int $employeeId, string $from, string $to): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY id ASC", $employeeId, $from, $to));
    }

    public function latestFor(int $employeeId, int $limit): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT " . (int) $limit, $employeeId));
    }

    public function myRequests(int $employeeId, int $limit): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND source='employee' ORDER BY requested_at DESC,id DESC LIMIT %d", $employeeId, $limit));
    }

    public function tableExists(): bool
    {
        return $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->t)) === $this->t;
    }

    public function openForHub(): array
    {
        return (array) $this->db->get_results("SELECT c.* FROM {$this->t} c WHERE c.status IN ('pending','pending_hr') ORDER BY c.requested_at ASC");
    }

    public function insert(array $row): int
    {
        if ($this->db->insert($this->t, $row) === false) return 0;
        return (int) $this->db->insert_id;
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->t, ['id' => $id], ['%d']);
    }

    public function ownOpen(int $id, int $employeeId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE id=%d AND employee_id=%d AND status IN ('pending','pending_hr')", $id, $employeeId));
    }

    public function withdraw(int $id, int $employeeId, int $userId, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->t} SET status='cancelled',decided_by=%d,decided_at=%s WHERE id=%d AND employee_id=%d AND status IN ('pending','pending_hr')", $userId, $now, $id, $employeeId));
    }
}
