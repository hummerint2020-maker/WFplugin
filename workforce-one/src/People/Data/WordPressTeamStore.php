<?php
namespace WorkforceOne\People\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the teams (3.31.133): the SQL the plugin ran before, unchanged. */
final class WordPressTeamStore implements TeamStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $teams;
    /** @var string */
    private $members;
    /** @var string */
    private $employees;

    public function __construct(\wpdb $db, string $employeesTable = '')
    {
        $this->db = $db;
        $this->teams = $db->prefix . 'ews_teams';
        $this->members = $db->prefix . 'ews_team_members';
        $this->employees = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
    }

    public function activeManagerFor(int $employeeId): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT e.*,tm.id AS team_id,tm.name AS team_name FROM {$this->teams} tm JOIN {$this->members} m ON m.team_id=tm.id AND m.active=1 JOIN {$this->employees} e ON e.id=tm.manager_employee_id AND e.active=1 WHERE m.employee_id=%d AND tm.active=1 ORDER BY tm.id ASC LIMIT 1", $employeeId));
        return is_object($r) ? $r : null;
    }

    public function activeMemberIds(int $teamId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT employee_id FROM {$this->members} WHERE team_id=%d AND active=1", $teamId));
    }

    public function activeTeamIdsOf(int $employeeId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT team_id FROM {$this->members} WHERE employee_id=%d AND active=1 ORDER BY team_id ASC", $employeeId));
    }

    public function activeTeamRowsByEmployees(array $ids): array
    {
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '%d'));
            foreach ((array) $this->db->get_results($this->db->prepare("SELECT employee_id,team_id FROM {$this->members} WHERE employee_id IN ($ph) AND active=1 ORDER BY team_id ASC", $chunk)) as $r) $out[] = $r;
        }
        return $out;
    }

    public function tableExists(): bool
    {
        return $this->db->get_var($this->db->prepare("SHOW TABLES LIKE %s", $this->teams)) === $this->teams;
    }

    public function activeCountInDepartment(int $departmentId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->teams} WHERE department_id=%d AND active=1", $departmentId));
    }

    public function activeById(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->teams} WHERE id=%d AND active=1 LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function adminList(): array
    {
        return array_values((array) $this->db->get_results("SELECT tm.*,e.name manager_name,d.name department_name,(SELECT COUNT(*) FROM {$this->members} m WHERE m.team_id=tm.id AND m.active=1) member_count FROM {$this->teams} tm LEFT JOIN {$this->employees} e ON e.id=tm.manager_employee_id LEFT JOIN {$this->db->prefix}ews_departments d ON d.id=tm.department_id WHERE tm.active=1 ORDER BY tm.name ASC"));
    }

    public function pageEmployees(): array
    {
        return array_values((array) $this->db->get_results("SELECT e.id,e.name,e.domain_name,e.department_id,d.name department_name FROM {$this->employees} e LEFT JOIN {$this->db->prefix}ews_departments d ON d.id=e.department_id AND d.active=1 WHERE e.active=1 ORDER BY e.name ASC"));
    }

    public function pageDepartments(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name FROM {$this->db->prefix}ews_departments WHERE active=1 ORDER BY name ASC"));
    }

    public function duplicateFlags(string $name, int $exceptId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT active FROM {$this->teams} WHERE name=%s AND id<>%d", $name, $exceptId));
    }

    public function update(int $id, string $name, string $description, int $manager, int $departmentId, string $now)
    {
        return $this->db->update($this->teams, ['name' => $name, 'description' => $description, 'manager_employee_id' => $manager, 'department_id' => $departmentId, 'updated_at' => $now], ['id' => $id], ['%s', '%s', '%d', '%d', '%s'], ['%d']);
    }

    public function insert(string $name, string $description, int $manager, int $departmentId, string $now)
    {
        return $this->db->insert($this->teams, ['name' => $name, 'description' => $description, 'manager_employee_id' => $manager, 'department_id' => $departmentId, 'active' => 1, 'created_at' => $now, 'updated_at' => $now], ['%s', '%s', '%d', '%d', '%d', '%s', '%s']);
    }

    public function insertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function deactivateMembersOfTeam(int $teamId, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->members} SET active=0,updated_at=%s WHERE team_id=%d AND active=1", $now, $teamId));
    }

    public function memberId(int $teamId, int $employeeId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->members} WHERE team_id=%d AND employee_id=%d LIMIT 1", $teamId, $employeeId));
    }

    public function reactivateMember(int $memberId, string $now)
    {
        return $this->db->update($this->members, ['active' => 1, 'updated_at' => $now], ['id' => $memberId], ['%d', '%s'], ['%d']);
    }

    public function insertMember(int $teamId, int $employeeId, string $now)
    {
        return $this->db->insert($this->members, ['team_id' => $teamId, 'employee_id' => $employeeId, 'active' => 1, 'joined_at' => $now, 'updated_at' => $now], ['%d', '%d', '%d', '%s', '%s']);
    }

    public function archive(int $id, string $now)
    {
        return $this->db->update($this->teams, ['active' => 0, 'updated_at' => $now], ['id' => $id], ['%d', '%s'], ['%d']);
    }

    public function deactivateAllMembers(int $teamId, string $now)
    {
        return $this->db->update($this->members, ['active' => 0, 'updated_at' => $now], ['team_id' => $teamId], ['%d', '%s'], ['%d']);
    }

    public function activeDepartmentOf(int $teamId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT department_id FROM {$this->teams} WHERE id=%d AND active=1 LIMIT 1", $teamId));
    }

    public function managedIn(int $employeeId, int $departmentId): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT id FROM {$this->teams} WHERE manager_employee_id=%d AND active=1 AND department_id=%d", $employeeId, $departmentId));
    }

    public function deactivateMembershipsOf(int $employeeId, string $now)
    {
        return $this->db->query($this->db->prepare("UPDATE {$this->members} SET active=0,updated_at=%s WHERE employee_id=%d AND active=1", $now, $employeeId));
    }

    public function activeId(int $teamId): ?string
    {
        return $this->db->get_var($this->db->prepare("SELECT id FROM {$this->teams} WHERE id=%d AND active=1", $teamId));
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }

    public function countActiveInDepartment(int $departmentId, array $teamIds): int
    {
        $ph = implode(',', array_fill(0, count($teamIds), '%d'));
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->teams} WHERE active=1 AND department_id=%d AND id IN ($ph)", array_merge([$departmentId], $teamIds)));
    }

    public function managesElsewhere(int $employeeId, int $departmentId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->teams} WHERE manager_employee_id=%d AND active=1 AND department_id<>%d", $employeeId, $departmentId));
    }

    public function pageTeams(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,manager_employee_id,department_id FROM {$this->teams} WHERE active=1 ORDER BY name ASC"));
    }

    public function teamsOfWithManager(int $employeeId): array
    {
        return array_values((array) $this->db->get_results($this->db->prepare("SELECT t.id,t.name,t.manager_employee_id FROM {$this->teams} t INNER JOIN {$this->members} m ON m.team_id=t.id AND m.employee_id=%d AND m.active=1 WHERE t.active=1 ORDER BY t.name ASC", $employeeId)));
    }

    public function activeNameRows(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return array_values((array) $this->db->get_results($this->db->prepare("SELECT name FROM {$this->teams} WHERE id IN ($ph) AND active=1 ORDER BY name ASC", ...$ids)));
    }

    public function activeNames(array $ids): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT name FROM {$this->teams} WHERE id IN (" . implode(',', array_fill(0, count($ids), '%d')) . ") AND active=1 ORDER BY name ASC", ...$ids));
    }

    public function namesByIds(array $ids): array
    {
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        return array_values((array) $this->db->get_results($this->db->prepare("SELECT id,name FROM {$this->teams} WHERE id IN ($ph)", array_values($ids))));
    }

    public function activeList(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name FROM {$this->teams} WHERE active=1 ORDER BY name ASC"));
    }
}
