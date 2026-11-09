<?php
namespace WorkforceOne\HrLetters\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the HR letters' reads (HL3). */
final class WordPressLetterQuery implements LetterQuery
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;
    /** @var string */
    private $e;
    /** @var string */
    private $d;

    public function __construct(\wpdb $db, string $employeesTable)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_hr_letters';
        $this->e = $employeesTable !== '' ? $employeesTable : $db->prefix . 'ews_employees';
        $this->d = $db->prefix . 'ews_departments';
    }

    public function facts(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare(
            "SELECT l.*,e.name,e.name_ar,e.gender,e.hire_date,e.created_at,e.job_title,e.active,e.wp_user_id,d.name AS department_name
             FROM {$this->t} l LEFT JOIN {$this->e} e ON e.id=l.employee_id LEFT JOIN {$this->d} d ON d.id=e.department_id WHERE l.id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function counts(): array
    {
        $out = [];
        foreach ((array) $this->db->get_results("SELECT status,COUNT(*) n FROM {$this->t} GROUP BY status") as $r) $out[(string) $r->status] = (int) $r->n;
        $out['waiting_file'] = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->t} WHERE status='approved' AND delivery='upload' AND file_key IS NULL");
        return $out;
    }

    public function adminList(string $status, string $search, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $args = [];
        if ($status === 'waiting_file') $where[] = "l.status='approved' AND l.delivery='upload' AND l.file_key IS NULL";
        elseif ($status !== '') { $where[] = 'l.status=%s'; $args[] = $status; }
        if ($search !== '') {
            $like = '%' . $this->db->esc_like($search) . '%';
            $where[] = '(e.name LIKE %s OR e.domain_name LIKE %s OR l.type_label LIKE %s OR l.addressee LIKE %s)';
            array_push($args, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $from = "FROM {$this->t} l LEFT JOIN {$this->e} e ON e.id=l.employee_id WHERE {$w}";
        $count = "SELECT COUNT(*) {$from}";
        $total = (int) $this->db->get_var($args ? $this->db->prepare($count, $args) : $count);
        $list = "SELECT l.*,e.name AS employee_name,e.active AS employee_active {$from} ORDER BY l.id DESC LIMIT %d OFFSET %d";
        $rows = (array) $this->db->get_results($this->db->prepare($list, array_merge($args, [$perPage, max(0, ($page - 1) * $perPage)])));
        return ['rows' => $rows, 'total' => $total];
    }

    public function waiting(): array
    {
        return (array) $this->db->get_results("SELECT l.*,e.name AS employee_name FROM {$this->t} l LEFT JOIN {$this->e} e ON e.id=l.employee_id WHERE l.status='pending' ORDER BY l.requested_at ASC,l.id ASC");
    }
}
