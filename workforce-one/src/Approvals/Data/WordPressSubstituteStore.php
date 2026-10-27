<?php
namespace WorkforceOne\Approvals\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the approval substitutes (3.31.128). The SQL is the SQL the plugin ran before, unchanged. */
final class WordPressSubstituteStore implements SubstituteStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $table;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->table = $db->prefix . 'ews_approval_substitutes';
    }

    public function activeFor(int $managerEmployeeId, string $now): ?object
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE manager_employee_id=%d AND status IN ('scheduled','active') AND starts_at<=%s AND ends_at>%s ORDER BY id DESC LIMIT 1", $managerEmployeeId, $now, $now));
        return is_object($row) ? $row : null;
    }

    public function linkedToLeave(): array
    {
        return (array) $this->db->get_results("SELECT * FROM {$this->table} WHERE leave_request_id IS NOT NULL AND status IN ('pending','scheduled','active')");
    }

    public function current(): array
    {
        return (array) $this->db->get_results("SELECT * FROM {$this->table} WHERE status IN ('scheduled','active') ORDER BY starts_at ASC");
    }

    public function active(): array
    {
        return (array) $this->db->get_results("SELECT * FROM {$this->table} WHERE status='active'");
    }

    public function find(int $id): ?object
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id));
        return is_object($row) ? $row : null;
    }

    public function transition(int $id, string $from, array $set)
    {
        return $this->db->update($this->table, $set, ['id' => $id, 'status' => $from]);
    }

    public function incrementDecided(int $id): void
    {
        $this->db->query($this->db->prepare("UPDATE {$this->table} SET decided=decided+1 WHERE id=%d", $id));
    }

    public function decided(int $id): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT decided FROM {$this->table} WHERE id=%d", $id));
    }

    public function insert(array $row)
    {
        return $this->db->insert($this->table, $row) === false ? false : (int) $this->db->insert_id;
    }

    public function overlapForManager(int $managerEmployeeId, string $ends, string $starts, int $ignoreId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND starts_at<%s AND ends_at>%s AND id<>%d LIMIT 1", $managerEmployeeId, $ends, $starts, $ignoreId));
    }

    public function overlapForCandidate(int $candidateEmployeeId, string $ends, string $starts): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND starts_at<%s AND ends_at>%s LIMIT 1", $candidateEmployeeId, $ends, $starts));
    }

    public function forSubstitute(int $substituteEmployeeId): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE substitute_employee_id=%d AND status='active' ORDER BY ends_at ASC", $substituteEmployeeId));
    }

    public function ownOpen(int $managerEmployeeId, string $now): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND ends_at>%s ORDER BY starts_at ASC", $managerEmployeeId, $now));
    }

    public function adminList(): array
    {
        return (array) $this->db->get_results("SELECT * FROM {$this->table} ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END,starts_at DESC LIMIT 200");
    }
}
