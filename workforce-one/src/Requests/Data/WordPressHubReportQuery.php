<?php
namespace WorkforceOne\Requests\Data;

if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\HubReport;

/**
 * SQL adapter of the Reports tab (3.31.159): one UNION ALL over the request tables. Each part gives its type code, id,
 * request time and status code (HubReport::caseSql), so no text from tables of different collations meets. A table that
 * does not exist (overtime, the old vacation table) is left out.
 */
final class WordPressHubReportQuery implements HubReportQuery
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $e;
    /** @var array<string,bool>|null */
    private $present;

    public function __construct(\wpdb $db, string $employeesTable)
    {
        $this->db = $db;
        $this->e = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
    }

    /** type => [table, request time, employee column, colleague column (swaps), extra condition, join] */
    private function parts(): array
    {
        $p = $this->db->prefix;
        return [
            'leave' => [$p . 'ews_leave_requests', 'r.requested_at', 'r.employee_id', '', '', ''],
            // The cancellation's approval request: the newest under the leave workflow (one row per cancellation, whatever is stored).
            'leave_cancellation' => [$p . 'ews_leave_requests', "COALESCE((SELECT ar.created_at FROM {$p}ews_approval_requests ar WHERE ar.entity_type='leave_cancellation' AND ar.entity_id=r.id"
                . " AND ar.workflow_id=(SELECT w.id FROM {$p}ews_approval_workflows w WHERE w.workflow_key='vacation') ORDER BY ar.id DESC LIMIT 1),r.cancelled_at,r.requested_at)", 'r.employee_id', '',
                "r.cancellation_status IN ('Pending','Approved','Rejected','Withdrawn','Ended')", ''],
            'overtime' => [$p . 'ews_overtime_requests', 'r.requested_at', 'r.employee_id', '', '', ''],
            'early_leave' => [$p . 'ews_early_leave_requests', 'r.requested_at', 'r.employee_id', '', '', ''],
            'shift_swap' => [$p . 'ews_schedule_swaps', 'r.created_at', 'r.requester_employee_id', 'r.target_employee_id', '', ''],
            'legacy_vacation' => [$p . 'ews_vacation_requests', 'r.requested_at', 'r.employee_id', '', '', ''],
            'correction' => [$p . 'ews_attendance_corrections', 'r.requested_at', 'r.employee_id', '', '', ''],
            'hr_letter' => [$p . 'ews_hr_letters', 'r.requested_at', 'r.employee_id', '', '', ''],
        ];
    }

    private function exists(string $table): bool
    {
        if ($this->present === null) {
            $this->present = [];
            foreach ((array) $this->db->get_col($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($this->db->prefix . 'ews_') . '%')) as $t) $this->present[(string) $t] = true;
        }
        return isset($this->present[$table]);
    }

    /** The UNION of the filter's types; null when none can be read. */
    private function union(array $f, string $today): ?string
    {
        $quote = function ($v) { return $this->db->prepare('%s', (string) $v); };
        $from = $f['from'] . ' 00:00:00';
        $to = $f['to'] . ' 23:59:59';
        $sql = [];
        foreach ($this->parts() as $type => [$table, $at, $emp, $peer, $extra, $join]) {
            if (!in_array($type, $f['types'], true) || !$this->exists($table)) continue;
            $where = [$this->db->prepare("{$at} BETWEEN %s AND %s", $from, $to)];
            if ($extra !== '') $where[] = $extra;
            if ($f['employee']) $where[] = $peer !== '' ? $this->db->prepare("({$emp}=%d OR {$peer}=%d)", $f['employee'], $f['employee']) : $this->db->prepare("{$emp}=%d", $f['employee']);
            if ($f['department']) $join .= ' ' . $this->db->prepare("JOIN {$this->e} de ON de.id={$emp} AND de.department_id=%d", $f['department']);
            $sql[] = 'SELECT ' . (int) HubReport::TYPE_CODES[$type] . " AS t,r.id AS id,{$at} AS at," . HubReport::caseSql($type, 'r', $quote, $today) . " AS st FROM {$table} r {$join} WHERE " . implode(' AND ', $where);
        }
        return $sql ? implode(' UNION ALL ', $sql) : null;
    }

    public function counts(array $f, string $today): array
    {
        $u = $this->union($f, $today);
        if ($u === null) return [];
        $out = [];
        foreach ((array) $this->db->get_results("SELECT t,st,COUNT(*) n FROM ({$u}) x GROUP BY t,st") as $r) $out[(int) $r->t][(int) $r->st] = (int) $r->n;
        return $out;
    }

    public function keys(array $f, string $today, int $limit, int $offset = 0, ?array $after = null): array
    {
        $u = $this->union($f, $today);
        if ($u === null || $limit <= 0) return [];
        $where = [];
        if ($f['status']) $where[] = $this->db->prepare('x.st=%d', $f['status']);
        if ($after) $where[] = $this->db->prepare('(x.at<%s OR (x.at=%s AND (x.t<%d OR (x.t=%d AND x.id<%d))))', $after[0], $after[0], $after[1], $after[1], $after[2]);
        $sql = "SELECT x.t,x.id,x.at FROM ({$u}) x" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY x.at DESC,x.t DESC,x.id DESC'
            . $this->db->prepare(' LIMIT %d OFFSET %d', $limit, max(0, $offset));
        $out = [];
        foreach ((array) $this->db->get_results($sql) as $r) $out[] = ['t' => (int) $r->t, 'id' => (int) $r->id, 'at' => (string) $r->at];
        return $out;
    }

    public function people(): array
    {
        return array_values((array) $this->db->get_results("SELECT id,name,active FROM {$this->e} ORDER BY active DESC,name ASC"));
    }

    public function rows(string $type, array $ids): array
    {
        $parts = $this->parts();
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !isset($parts[$type]) || !$this->exists($parts[$type][0])) return [];
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);
            if ($type === 'leave' || $type === 'leave_cancellation') {
                $p = $this->db->prefix;
                $sql = "SELECT r.*,lt.name AS type_name FROM {$p}ews_leave_requests r LEFT JOIN {$p}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id IN ({$in})";
            } else {
                $sql = "SELECT r.* FROM {$parts[$type][0]} r WHERE r.id IN ({$in})";
            }
            foreach ((array) $this->db->get_results($sql) as $r) $out[(int) $r->id] = $r;
        }
        return $out;
    }
}
