<?php
namespace WorkforceOne\Schedule\Data;

if (!defined('ABSPATH')) exit;

/**
 * SQL adapter for the schedule days and the company calendar (3.31.127); it never receives the plugin object. The SQL is
 * the SQL the plugin ran before, unchanged, so the pages and the queries are the same.
 */
final class WordPressScheduleDayStore implements ScheduleDayStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $table;
    /** @var string */
    private $calendar;

    /**
     * @param string $scheduleTable the plugin's schedule table, as it was named when the plugin object was built (the old
     *        code used that name, also inside a switch_to_blog()); the calendar is read with the current prefix, as before
     */
    public function __construct(\wpdb $db, string $scheduleTable = '')
    {
        $this->db = $db;
        $this->table = $scheduleTable !== '' ? $scheduleTable : $db->prefix . 'ews_schedule';
        $this->calendar = $db->prefix . 'ews_company_calendar';
    }

    public function find(int $employeeId, string $date): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE employee_id=%d AND work_date=%s LIMIT 1", $employeeId, $date));
        return is_object($r) ? $r : null;
    }

    public function findStatusNote(int $employeeId, string $date): ?object
    {
        $r = $this->db->get_row($this->db->prepare(
            "SELECT id,status,note FROM {$this->table} WHERE employee_id=%d AND work_date=%s LIMIT 1",
            $employeeId, $date
        ));
        return is_object($r) ? $r : null;
    }

    public function findStatusNoteById(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare(
            "SELECT id,status,note FROM {$this->table} WHERE id=%d",
            $id
        ));
        return is_object($r) ? $r : null;
    }

    public function idOf(int $employeeId, string $date): ?string
    {
        $v = $this->db->get_var($this->db->prepare("SELECT id FROM {$this->table} WHERE employee_id=%d AND work_date=%s", $employeeId, $date));
        return $v === null ? null : (string) $v;
    }

    public function statusOf(int $id): ?string
    {
        $v = $this->db->get_var($this->db->prepare("SELECT status FROM {$this->table} WHERE id=%d", $id));
        return $v === null ? null : (string) $v;
    }

    public function noteOf(int $id): ?string
    {
        $v = $this->db->get_var($this->db->prepare("SELECT note FROM {$this->table} WHERE id=%d", $id));
        return $v === null ? null : (string) $v;
    }

    public function insert(array $row): int
    {
        return $this->db->insert($this->table, $row) === false ? 0 : (int) $this->db->insert_id;
    }

    public function update(array $row, int $id)
    {
        return $this->db->update($this->table, $row, ['id' => $id]);
    }

    public function updateStatusIf(int $id, string $expected, string $status, string $note, int $by, string $at)
    {
        $r = $this->db->query($this->db->prepare(
            "UPDATE {$this->table}
                     SET status=%s,note=%s,updated_by=%d,updated_at=%s
                     WHERE id=%d AND status=%s",
            $status, $note, $by, $at,
            $id, $expected
        ));
        return $r === false ? false : (int) $r;
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }

    public function rowsOnDates(array $dates): array
    {
        if (!$dates) return [];
        $ph = implode(',', array_fill(0, count($dates), '%s'));
        return (array) $this->db->get_results($this->db->prepare("SELECT employee_id,work_date,status FROM {$this->table} WHERE work_date IN ($ph) ORDER BY work_date ASC", ...$dates));
    }

    public function calendarEvents(string $start, string $end): array
    {
        return (array) $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->calendar} WHERE event_date BETWEEN %s AND %s AND event_type='general_leave' AND active=1 ORDER BY event_date ASC,id ASC",
            $start, $end
        ));
    }
}
