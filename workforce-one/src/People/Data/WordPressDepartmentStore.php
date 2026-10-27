<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the departments table (3.31.133): the SQL the plugin ran before, unchanged. */
final class WordPressDepartmentStore implements DepartmentStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $d;
    /** @var string */
    private $employees;

    public function __construct(\wpdb $db, string $employeesTable = '')
    {
        $this->db = $db;
        $this->d = $db->prefix . 'ews_departments';
        $this->employees = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
    }

    public function tableExists(): bool
    {
        return $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $this->d)) === $this->d;
    }

    public function activeManagerOf(int $employeeId): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT m.* FROM {$this->employees} e JOIN {$this->d} d ON d.id=e.department_id AND d.active=1 JOIN {$this->employees} m ON m.id=d.manager_employee_id AND m.active=1 WHERE e.id=%d LIMIT 1", $employeeId));
        return is_object($r) ? $r : null;
    }
}
