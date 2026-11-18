<?php
namespace WorkforceOne\Leave\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for LeaveDayQuery: one read for all the employees (3.31.152). */
final class WordPressLeaveDayQuery implements LeaveDayQuery
{
    /** @var \wpdb */
    private $db;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
    }

    public function typesByDay(array $employeeIds, string $from, string $to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
        if (!$ids || $from > $to) return [];
        $p = $this->db->prefix;
        $ph = implode(',', array_fill(0, count($ids), '%d'));
        // From the requests (KEY employee_status), then their snapshot days in the range; the later request wins (overlaps are refused anyway).
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT r.employee_id,s.work_date,r.id,t.name FROM {$p}ews_leave_requests r
             JOIN {$p}ews_leave_schedule_snapshots s ON s.leave_request_id=r.id AND s.employee_id=r.employee_id AND s.work_date BETWEEN %s AND %s
             LEFT JOIN {$p}ews_leave_types t ON t.id=r.leave_type_id
             WHERE r.employee_id IN ($ph) AND r.status='Approved' AND r.start_date<=%s AND r.end_date>=%s ORDER BY r.id ASC",
            array_merge([$from, $to], $ids, [$to, $from])));
        $out = [];
        foreach ((array) $rows as $r) $out[(int) $r->employee_id][(string) $r->work_date] = trim((string) ($r->name ?? ''));
        return $out;
    }
}
