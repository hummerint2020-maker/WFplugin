<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/**
 * Read model of the report "Changed leave days" (Leave return, 3.31.161, docs/tasks/leave-return.md §4.6): the past days of
 * approved leaves whose schedule is no longer Vacation (a manager changed them, or the day was cleared). Reads only.
 */
final class WordPressChangedDaysQuery implements ChangedDaysQuery
{
    /** @var \wpdb */
    private $db;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
    }

    /**
     * Days from $from to $to, before $today, newest first; $employee 0 = everyone.
     * @return array{rows:object[],total:int}
     */
    public function page(string $from, string $to, string $today, int $employee, int $limit, int $offset): array
    {
        $p = $this->db->prefix;
        $where = "s.work_date BETWEEN %s AND %s AND s.work_date<%s AND (sc.id IS NULL OR sc.status<>'Vacation')" . ($employee ? ' AND s.employee_id=%d' : '');
        $args = $employee ? [$from, $to, $today, $employee] : [$from, $to, $today];
        $join = "FROM {$p}ews_leave_schedule_snapshots s
             JOIN {$p}ews_leave_requests r ON r.id=s.leave_request_id AND r.status='Approved'
             LEFT JOIN {$p}ews_schedule sc ON sc.employee_id=s.employee_id AND sc.work_date=s.work_date";
        $total = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) {$join} WHERE {$where}", $args));
        $rows = $total ? (array) $this->db->get_results($this->db->prepare(
            "SELECT s.id snap_id,s.employee_id,s.work_date,r.id leave_id,r.start_date,r.end_date,r.cancellation_status,t.name type_name,e.name employee_name,
                    sc.status,sc.updated_by,sc.updated_at
             {$join}
             LEFT JOIN {$p}ews_leave_types t ON t.id=r.leave_type_id
             LEFT JOIN {$p}ews_employees e ON e.id=s.employee_id
             WHERE {$where} ORDER BY s.work_date DESC,s.id DESC LIMIT %d OFFSET %d",
            array_merge($args, [max(1, $limit), max(0, $offset)]))) : [];
        return ['rows' => $rows, 'total' => $total];
    }
}
