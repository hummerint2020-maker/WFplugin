<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the time-log rows (3.31.136); it never receives the plugin object. The SQL is the SQL the plugin ran before,
 * unchanged. The table is the plugin's own table name, as it was fixed when the plugin object was built.
 */
final class WordPressTimeLogStore implements TimeLogStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db, string $timeLogsTable = '')
    {
        $this->db = $db;
        $this->t = $timeLogsTable !== '' ? $timeLogsTable : $db->prefix . 'ews_time_logs';
    }

    /** The "live" filter: no correction replaces the row (what the old tl_live() returned). */
    private function live(string $col = ''): string
    {
        $col = $col !== '' ? $col : $this->t . '.id';
        return " AND NOT EXISTS (SELECT 1 FROM {$this->t} tl_cx WHERE tl_cx.corrects_id={$col})";
    }

    public function lastDeviceLocation(int $employeeId): ?array
    {
        $r = $this->db->get_row($this->db->prepare("SELECT latitude,longitude,location_timestamp FROM {$this->t} WHERE employee_id=%d AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY event_at DESC LIMIT 1", $employeeId), ARRAY_A);
        return is_array($r) ? $r : null;
    }

    public function liveDay(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s" . $this->live() . ' ORDER BY event_at ASC', $employeeId, $day));
    }

    public function typesOn(int $employeeId, string $day): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT event_type FROM {$this->t} WHERE employee_id=%d AND work_date=%s" . $this->live(), $employeeId, $day));
    }

    public function liveInOut(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT event_type,event_at FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out')" . $this->live() . ' ORDER BY event_at ASC', $employeeId, $day));
    }
}
