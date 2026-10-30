<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the break sessions (3.31.136). The table name is built from the current prefix on every call site, as the old
 * code did (a switch_to_blog() moves it); the container is one per prefix, so this object's prefix is that prefix.
 */
final class WordPressBreakStore implements BreakStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_break_sessions';
    }

    public function sessionsOn(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s ORDER BY id ASC", $employeeId, $day));
    }

    public function openOn(int $employeeId, string $day): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND status='Open' ORDER BY id DESC LIMIT 1", $employeeId, $day));
        return is_object($r) ? $r : null;
    }

    public function insert(array $row): int
    {
        return $this->db->insert($this->t, $row, ['%d', '%d', '%s', '%s', '%s', '%s']) ? (int) $this->db->insert_id : 0;
    }

    public function firstOpenId(int $employeeId, string $day): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT MIN(id) FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND status='Open'", $employeeId, $day));
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->t, ['id' => $id], ['%d']);
    }

    public function close(int $id, string $endMysql, int $minutes): bool
    {
        return $this->db->update($this->t, ['end_at' => $endMysql, 'actual_minutes' => $minutes, 'status' => 'Completed'], ['id' => $id, 'status' => 'Open'], ['%s', '%d', '%s'], ['%d', '%s']) === 1;
    }

    public function byId(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function markReminded(int $id): void
    {
        $this->db->update($this->t, ['duration_notified' => 1], ['id' => $id], ['%d'], ['%d']);
    }

    public function markEscalated(int $id): void
    {
        $this->db->update($this->t, ['escalation_notified' => 1], ['id' => $id], ['%d'], ['%d']);
    }
}
