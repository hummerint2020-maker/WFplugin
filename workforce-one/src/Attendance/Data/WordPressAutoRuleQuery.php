<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the Auto Attendance rule list (3.31.139). The SQL is the SQL the plugin ran before, unchanged. */
final class WordPressAutoRuleQuery implements AutoRuleQuery
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $emps;
    /** @var string */
    private $rules;

    public function __construct(\wpdb $db, string $employeesTable)
    {
        $this->db = $db;
        $this->emps = $employeesTable;
        $this->rules = $db->prefix . 'ews_auto_attendance_rules';
    }

    public function listWithNames(): array
    {
        return (array) $this->db->get_results("SELECT r.*,e.name,e.domain_name FROM {$this->rules} r INNER JOIN {$this->emps} e ON e.id=r.employee_id ORDER BY r.enabled DESC,r.id DESC");
    }
}
