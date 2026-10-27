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
}
