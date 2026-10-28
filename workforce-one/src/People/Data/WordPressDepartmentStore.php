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

    public function byId(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->d} WHERE id=%d LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function adminList(): array
    {
        return array_values((array) $this->db->get_results("SELECT d.*,e.name manager_name,(SELECT COUNT(*) FROM {$this->employees} x WHERE x.department_id=d.id AND x.active=1) employee_count FROM {$this->d} d LEFT JOIN {$this->employees} e ON e.id=d.manager_employee_id WHERE d.active=1 ORDER BY d.name ASC"));
    }

    public function duplicateFlags(string $name, string $code, int $exceptId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT active FROM {$this->d} WHERE (name=%s OR code=%s) AND id<>%d", $name, $code, $exceptId));
    }

    public function update(int $id, string $name, string $code, string $description, int $manager, string $now)
    {
        return $this->db->update($this->d, ['name' => $name, 'code' => $code, 'description' => $description, 'manager_employee_id' => $manager ?: null, 'updated_at' => $now], ['id' => $id], ['%s', '%s', '%s', '%d', '%s'], ['%d']);
    }

    public function insert(string $name, string $code, string $description, int $manager, string $now)
    {
        return $this->db->insert($this->d, ['name' => $name, 'code' => $code, 'description' => $description, 'manager_employee_id' => $manager ?: null, 'active' => 1, 'created_at' => $now, 'updated_at' => $now], ['%s', '%s', '%s', '%d', '%d', '%s', '%s']);
    }

    public function insertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function archive(int $id, string $now)
    {
        return $this->db->update($this->d, ['active' => 0, 'updated_at' => $now], ['id' => $id], ['%d', '%s'], ['%d']);
    }

    public function activeEmployeeCount(int $departmentId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE department_id=%d AND active=1", $departmentId));
    }

    public function activeId(int $id): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->d} WHERE id=%d AND active=1 LIMIT 1", $id));
    }
}
