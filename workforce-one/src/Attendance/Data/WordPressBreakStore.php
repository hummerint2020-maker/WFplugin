<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the break sessions (3.31.136). The table name is built from the current prefix on every call site, as the old
 * code did (a switch_to_blog() moves it); the container is one per prefix, so this object's prefix is that prefix.
 */
final class WordPressBreakStore implements BreakStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_break_sessions';
    }

    public function sessionsOn(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s ORDER BY id ASC", $employeeId, $day));
    }

    public function openOn(int $employeeId, string $day): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND status='Open' ORDER BY id DESC LIMIT 1", $employeeId, $day));
        return is_object($r) ? $r : null;
    }
}
