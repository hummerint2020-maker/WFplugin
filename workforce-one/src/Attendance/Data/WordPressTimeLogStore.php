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

    public function insert(array $row): int
    {
        return $this->db->insert($this->t, $row) === false ? 0 : (int) $this->db->insert_id;
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }

    public function lastInsertId(): int
    {
        return (int) $this->db->insert_id;
    }

    public function firstEventId(int $employeeId, string $day, array $types): int
    {
        $ph = implode(',', array_fill(0, count($types), '%s'));
        return (int) $this->db->get_var($this->db->prepare("SELECT MIN(id) FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND event_type IN ($ph)", array_merge([$employeeId, $day], $types)));
    }

    public function delete(int $id): void
    {
        $this->db->delete($this->t, ['id' => $id], ['%d']);
    }

    public function setPhoto(int $id, string $key): void
    {
        $this->db->update($this->t, ['photo_key' => $key], ['id' => $id]);
    }

    public function insertAuto(array $row): int
    {
        $ok = $this->db->insert($this->t, $row, ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function anyOfKinds(int $employeeId, string $day, array $types): bool
    {
        $ph = implode(',', array_fill(0, count($types), '%s'));
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND event_type IN ($ph) LIMIT 1", array_merge([$employeeId, $day], $types)));
    }

    public function openSignInsOn(string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT s.employee_id, MAX(s.id) AS sign_in_id
            FROM {$this->t} s
            WHERE s.work_date=%s
              AND s.event_type IN ('sign_in','late_sign_in')" . $this->live('s.id') . "
              AND NOT EXISTS (
                  SELECT 1 FROM {$this->t} o
                  WHERE o.employee_id=s.employee_id AND o.work_date=s.work_date AND o.event_type='sign_out'
              )
            GROUP BY s.employee_id", $day));
    }

    public function byId(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d LIMIT 1", $id));
        return is_object($r) ? $r : null;
    }

    public function signOutIdOn(int $employeeId, string $day): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND event_type='sign_out' LIMIT 1", $employeeId, $day));
    }

    public function idEmployee(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT id,employee_id FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function othersOn(int $employeeId, string $day, int $exceptId): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT event_type,event_at FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND id<>%d AND event_type IN ('sign_in','late_sign_in','sign_out')" . $this->live() . '', $employeeId, $day, $exceptId));
    }

    public function update(int $id, array $row): bool
    {
        return $this->db->update($this->t, $row, ['id' => $id]) !== false;
    }

    public function deleteDay(int $employeeId, string $day): int
    {
        return (int) $this->db->query($this->db->prepare("DELETE FROM {$this->t} WHERE employee_id=%d AND work_date=%s", $employeeId, $day));
    }

    public function kindsBetween(int $employeeId, array $types, string $from, string $to): bool
    {
        $ph = implode(',', array_fill(0, count($types), '%s'));
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE employee_id=%d AND event_type IN ($ph) AND event_at BETWEEN %s AND %s LIMIT 1", array_merge([$employeeId], $types, [$from, $to])));
    }

    public function photoOf(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT employee_id,photo_key,event_at at FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function allOn(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s", $employeeId, $day));
    }

    public function photoKeysBefore(string $cut): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT photo_key FROM {$this->t} WHERE photo_key IS NOT NULL AND event_at<%s", $cut));
    }

    public function clearPhotosBefore(string $cut): void
    {
        $this->db->query($this->db->prepare("UPDATE {$this->t} SET photo_key=NULL WHERE photo_key IS NOT NULL AND event_at<%s", $cut));
    }

    public function find(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }
}
