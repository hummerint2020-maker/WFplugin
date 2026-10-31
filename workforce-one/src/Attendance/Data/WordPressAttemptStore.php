<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the Sign In attempts (3.31.139). The SQL is the SQL the plugin ran before, unchanged. The table follows the prefix of the container. */
final class WordPressAttemptStore implements AttemptStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_signin_attempts';
    }

    public function exists(): bool
    {
        return $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->t)) === $this->t;
    }

    public function insert(array $row): int
    {
        if (!$this->db->insert($this->t, $row)) return 0;
        return (int) $this->db->insert_id;
    }

    public function refusalsOn(int $employeeId, string $day): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t} WHERE employee_id=%d AND work_date=%s AND result IN ('refused','face_failed','not_reached')", $employeeId, $day));
    }

    public function typeBetween(int $employeeId, string $type, string $from, string $to): bool
    {
        return (bool) $this->db->get_var($this->db->prepare("SELECT id FROM {$this->t} WHERE employee_id=%d AND event_type=%s AND attempted_at BETWEEN %s AND %s LIMIT 1", $employeeId, $type, $from, $to));
    }

    public function notReachedSince(int $employeeId, string $since): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->t} WHERE employee_id=%d AND result='not_reached' AND reported_at>=%s", $employeeId, $since));
    }

    public function photoOf(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT employee_id,photo_key,attempted_at at FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function day(int $employeeId, string $day): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE employee_id=%d AND work_date=%s", $employeeId, $day));
    }

    public function photoKeysBefore(string $attemptCut, string $photoCut): array
    {
        return (array) $this->db->get_col($this->db->prepare("SELECT photo_key FROM {$this->t} WHERE photo_key IS NOT NULL AND (attempted_at<%s OR attempted_at<%s)", $attemptCut, $photoCut));
    }

    public function clearPhotosBefore(string $cut): void
    {
        $this->db->query($this->db->prepare("UPDATE {$this->t} SET photo_key=NULL WHERE photo_key IS NOT NULL AND attempted_at<%s", $cut));
    }

    public function deleteBefore(string $cut): void
    {
        $this->db->query($this->db->prepare("DELETE FROM {$this->t} WHERE attempted_at<%s", $cut));
    }
}
