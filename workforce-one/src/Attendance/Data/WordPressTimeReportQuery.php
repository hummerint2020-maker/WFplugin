<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the Sign In / Out report (3.31.139). The SQL is the SQL the plugin ran before, unchanged. The time logs and employees tables are the plugin's own
 * table names, as they were fixed when the plugin object was built; the attempts table follows the prefix of the container.
 */
final class WordPressTimeReportQuery implements TimeReportQuery
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $logs;
    /** @var string */
    private $emps;
    /** @var string */
    private $attempts;

    public function __construct(\wpdb $db, string $timeLogsTable, string $employeesTable)
    {
        $this->db = $db;
        $this->logs = $timeLogsTable;
        $this->emps = $employeesTable;
        $this->attempts = $db->prefix . 'ews_signin_attempts';
    }

    /** The department scope, as SQL to add to a query joined on employees as "e". */
    private function scope(?int $scope): string
    {
        if ($scope === null) return '';
        return $scope ? $this->db->prepare(' AND e.department_id=%d', $scope) : ' AND 1=0';
    }

    private function like(TimeReportFilter $f): string
    {
        return '%' . $this->db->esc_like($f->search) . '%';
    }

    private function where(TimeReportFilter $f): string
    {
        $where = $this->db->prepare(' WHERE l.work_date BETWEEN %s AND %s', $f->start, $f->end) . $this->scope($f->scope);
        if ($f->search !== '') {
            $like = $this->like($f);
            $where .= $this->db->prepare(' AND (e.name LIKE %s OR e.domain_name LIKE %s)', $like, $like);
        }
        return $where;
    }

    private function attemptsWhere(TimeReportFilter $f): string
    {
        if (!$f->attempts) return '';
        $aw = $this->db->prepare(" WHERE a.work_date BETWEEN %s AND %s AND a.result<>'face_ok'", $f->start, $f->end) . $this->scope($f->scope);
        if ($f->search !== '') {
            $like = $this->like($f);
            $aw .= $this->db->prepare(' AND (e.name LIKE %s OR e.domain_name LIKE %s)', $like, $like);
        }
        return $aw;
    }

    private function union(TimeReportFilter $f): string
    {
        $parts = [];
        if ($f->show !== 'refused') $parts[] = "SELECT 'log' kind,l.id id,l.work_date d,l.event_at t FROM {$this->logs} l LEFT JOIN {$this->emps} e ON e.id=l.employee_id" . $this->where($f);
        if ($f->show !== 'recorded') $parts[] = "SELECT 'attempt' kind,a.id id,a.work_date d,a.attempted_at t FROM {$this->attempts} a LEFT JOIN {$this->emps} e ON e.id=a.employee_id" . $this->attemptsWhere($f);
        return implode(' UNION ALL ', $parts);
    }

    public function count(TimeReportFilter $f): int
    {
        return (int) $this->db->get_var("SELECT COUNT(*) FROM ({$this->union($f)}) x");
    }

    public function pageKeys(TimeReportFilter $f, int $perPage, int $offset): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT kind,id FROM ({$this->union($f)}) x ORDER BY d DESC,t DESC,id DESC LIMIT %d OFFSET %d", $perPage, $offset));
    }

    public function logRows(array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        $rows = [];
        foreach ((array) $this->db->get_results("SELECT l.*,e.name,e.domain_name,(SELECT MIN(c.correction_id) FROM {$this->logs} c WHERE c.corrects_id=l.id) replaced_by FROM {$this->logs} l LEFT JOIN {$this->emps} e ON e.id=l.employee_id WHERE l.id IN ($in)") as $r) $rows[(int) $r->id] = $r;
        return $rows;
    }

    public function attemptRows(array $ids): array
    {
        $in = implode(',', array_map('intval', $ids));
        $rows = [];
        foreach ((array) $this->db->get_results("SELECT a.*,e.name,e.domain_name FROM {$this->attempts} a LEFT JOIN {$this->emps} e ON e.id=a.employee_id WHERE a.id IN ($in)") as $r) $rows[(int) $r->id] = $r;
        return $rows;
    }

    public function refusedBy(TimeReportFilter $f): array
    {
        $aw = $this->attemptsWhere($f);
        return (array) $this->db->get_results("SELECT a.employee_id,e.name,COUNT(*) n FROM {$this->attempts} a LEFT JOIN {$this->emps} e ON e.id=a.employee_id{$aw} AND a.result IN ('refused','face_failed','not_reached') GROUP BY a.employee_id,e.name ORDER BY n DESC,e.name ASC LIMIT 6");
    }

    public function refusedTotal(TimeReportFilter $f): int
    {
        $aw = $this->attemptsWhere($f);
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->attempts} a LEFT JOIN {$this->emps} e ON e.id=a.employee_id{$aw} AND a.result IN ('refused','face_failed','not_reached')");
    }

    public function employees(int $includeId, ?int $scope): array
    {
        return (array) $this->db->get_results("SELECT e.id,e.name,e.domain_name FROM {$this->emps} e WHERE (e.active=1" . ($includeId ? $this->db->prepare(' OR e.id=%d', $includeId) : '') . ')' . $this->scope($scope) . ' ORDER BY e.name ASC');
    }

    public function csv(string $start, string $end, ?int $scope): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT l.*,e.name,e.domain_name,(SELECT MIN(c.correction_id) FROM {$this->logs} c WHERE c.corrects_id=l.id) replaced_by FROM {$this->logs} l LEFT JOIN {$this->emps} e ON e.id=l.employee_id WHERE l.work_date BETWEEN %s AND %s" . $this->scope($scope) . ' ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC',
            $start,
            $end
        ));
    }
}
