<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the correction pages (3.31.140). The SQL is the SQL the plugin ran before, unchanged. The employees table is the plugin's own table name, as it was fixed when the plugin object was built. */
final class WordPressCorrectionQuery implements CorrectionQuery
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;
    /** @var string */
    private $emps;

    public function __construct(\wpdb $db, string $employeesTable)
    {
        $this->db = $db;
        $this->emps = $employeesTable;
        $this->t = $db->prefix . 'ews_attendance_corrections';
    }

    public function get(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$this->t} c LEFT JOIN {$this->emps} e ON e.id=c.employee_id WHERE c.id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function toDecideByApprover(int $userId): array
    {
        $requests = $this->db->prefix . 'ews_approval_requests';
        $instances = $this->db->prefix . 'ews_approval_steps';
        return (array) $this->db->get_results($this->db->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id,ars.step_order FROM {$this->t} c INNER JOIN {$this->emps} e ON e.id=c.employee_id
                INNER JOIN {$requests} ar ON ar.entity_type='attendance_correction' AND ar.entity_id=c.id INNER JOIN {$instances} ars ON ars.approval_request_id=ar.id AND ars.status='PENDING'
                WHERE c.status='pending' AND ars.approver_wp_user_id=%d ORDER BY c.requested_at ASC LIMIT 100", $userId));
    }

    public function toDecideOpen(): array
    {
        return (array) $this->db->get_results("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$this->t} c INNER JOIN {$this->emps} e ON e.id=c.employee_id WHERE c.status='pending' ORDER BY c.requested_at ASC LIMIT 200");
    }

    public function toDecideHr(): array
    {
        return (array) $this->db->get_results("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$this->t} c INNER JOIN {$this->emps} e ON e.id=c.employee_id WHERE c.status='pending_hr' ORDER BY c.requested_at ASC LIMIT 200");
    }

    public function departments(): array
    {
        return (array) $this->db->get_results("SELECT id,name FROM {$this->db->prefix}ews_departments WHERE active=1 ORDER BY name ASC");
    }

    /** The WHERE of the list: the days, then the kind, the department and the search; the status last, only when asked. */
    private function where(CorrectionListFilter $f): string
    {
        $where = $this->db->prepare(' WHERE c.work_date BETWEEN %s AND %s', $f->from, $f->to);
        if ($f->type !== '') $where .= $this->db->prepare(' AND c.type=%s', $f->type);
        if ($f->department) $where .= $this->db->prepare(' AND e.department_id=%d', $f->department);
        if ($f->search !== '') $where .= $this->db->prepare(' AND (e.name LIKE %s OR e.domain_name LIKE %s)', '%' . $this->db->esc_like($f->search) . '%', '%' . $this->db->esc_like($f->search) . '%');
        if ($f->status !== '') $where .= $this->db->prepare(' AND c.status=%s', $f->status);
        return $where;
    }

    public function listCounts(CorrectionListFilter $f): array
    {
        $where = $this->where($f);
        return (array) $this->db->get_results("SELECT c.status,COUNT(*) n FROM {$this->t} c LEFT JOIN {$this->emps} e ON e.id=c.employee_id{$where} GROUP BY c.status");
    }

    public function listTotal(CorrectionListFilter $f): int
    {
        $where = $this->where($f);
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t} c LEFT JOIN {$this->emps} e ON e.id=c.employee_id{$where}");
    }

    public function listRows(CorrectionListFilter $f, int $perPage, int $offset): array
    {
        $where = $this->where($f);
        return (array) $this->db->get_results($this->db->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id,d.name department_name,u.display_name decided_name,m.display_name manager_name FROM {$this->t} c
                LEFT JOIN {$this->emps} e ON e.id=c.employee_id LEFT JOIN {$this->db->prefix}ews_departments d ON d.id=e.department_id LEFT JOIN {$this->db->users} u ON u.ID=c.decided_by LEFT JOIN {$this->db->users} m ON m.ID=c.manager_by
                {$where} ORDER BY c.requested_at DESC LIMIT %d OFFSET %d", $perPage, $offset));
    }

    /** The report's and the export's WHERE: the days and, when asked, one department. */
    private function whereDays(string $from, string $to, int $department): string
    {
        return $this->db->prepare(' WHERE c.work_date BETWEEN %s AND %s', $from, $to) . ($department ? $this->db->prepare(' AND e.department_id=%d', $department) : '');
    }

    public function report(string $from, string $to, int $department): array
    {
        $where = $this->whereDays($from, $to, $department);
        return (array) $this->db->get_results("SELECT c.employee_id,c.type,c.status,e.name,COALESCE(d.name,'') dept FROM {$this->t} c LEFT JOIN {$this->emps} e ON e.id=c.employee_id LEFT JOIN {$this->db->prefix}ews_departments d ON d.id=e.department_id{$where}");
    }

    public function export(string $from, string $to, int $department): array
    {
        $where = $this->whereDays($from, $to, $department);
        return (array) $this->db->get_results("SELECT c.*,e.name employee_name,COALESCE(d.name,'') dept,u.display_name decided_name FROM {$this->t} c LEFT JOIN {$this->emps} e ON e.id=c.employee_id LEFT JOIN {$this->db->prefix}ews_departments d ON d.id=e.department_id LEFT JOIN {$this->db->users} u ON u.ID=c.decided_by{$where} ORDER BY c.work_date ASC,e.name ASC");
    }
}
