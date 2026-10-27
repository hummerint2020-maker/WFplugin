<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the reporting lines (3.31.133): the SQL the plugin ran before, unchanged. */
final class WordPressReportingStore implements ReportingStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $rt;
    /** @var string */
    private $employees;

    public function __construct(\wpdb $db, string $employeesTable = '')
    {
        $this->db = $db;
        $this->rt = $db->prefix . 'ews_employee_relationships';
        $this->employees = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
    }

    public function tableExists(): bool
    {
        return $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $this->rt)) === $this->rt;
    }

    public function relatedActive(int $employeeId, string $type): ?object
    {
        $r = $this->db->get_row($this->db->prepare(
            "SELECT e.* FROM {$this->rt} r
             JOIN {$this->employees} e ON e.id=r.related_employee_id
             WHERE r.employee_id=%d AND r.relationship_type=%s AND r.active=1 AND e.active=1
             ORDER BY r.id DESC LIMIT 1",
            $employeeId, $type
        ));
        return is_object($r) ? $r : null;
    }

    public function supervisorRowsOf(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $this->db->get_results($this->db->prepare("SELECT r.employee_id,r.related_employee_id FROM {$this->rt} r JOIN {$this->employees} e ON e.id=r.related_employee_id
                WHERE r.employee_id IN ($ph) AND r.relationship_type='supervisor' AND r.active=1 AND e.active=1 ORDER BY r.id DESC", $chunk));
            foreach ((array) $rows as $r) $out[] = $r;
        }
        return $out;
    }

    public function currentManagerId(int $employeeId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT related_employee_id FROM {$this->rt} WHERE employee_id=%d AND relationship_type='supervisor' AND active=1 AND related_employee_id IS NOT NULL ORDER BY id DESC LIMIT 1", $employeeId));
    }

    public function activeSupervisorRows(): array
    {
        return array_values((array) $this->db->get_results("SELECT employee_id,related_employee_id FROM {$this->rt} WHERE relationship_type='supervisor' AND active=1 AND related_employee_id IS NOT NULL ORDER BY id DESC"));
    }

    public function hasActiveReport(int $employeeId): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT r.id FROM {$this->rt} r JOIN {$this->employees} e ON e.id=r.employee_id AND e.active=1 WHERE r.related_employee_id=%d AND r.relationship_type='supervisor' AND r.active=1 LIMIT 1", $employeeId));
    }
}
