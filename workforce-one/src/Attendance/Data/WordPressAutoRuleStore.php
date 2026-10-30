<?php
namespace WorkforceOne\Attendance\Data;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the Auto Attendance rules (3.31.138). The SQL is the SQL the plugin ran before, unchanged. The table follows the prefix of the container. */
final class WordPressAutoRuleStore implements AutoRuleStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $t;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->t = $db->prefix . 'ews_auto_attendance_rules';
    }

    public function dueRules(string $today, string $yesterday): array
    {
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->t} WHERE enabled=1 AND ((recurrence='one_time' AND run_date IN (%s,%s)) OR recurrence='weekly') ORDER BY id ASC", $today, $yesterday));
    }

    public function disable(int $id, string $now): void
    {
        $this->db->update($this->t, ['enabled' => 0, 'updated_at' => $now], ['id' => $id], ['%d', '%s'], ['%d']);
    }

    public function enabledOf(int $id): ?object
    {
        $r = $this->db->get_row($this->db->prepare("SELECT enabled FROM {$this->t} WHERE id=%d", $id));
        return is_object($r) ? $r : null;
    }

    public function setEnabled(int $id, int $enabled, string $now): void
    {
        $this->db->update($this->t, ['enabled' => $enabled, 'updated_at' => $now], ['id' => $id], ['%d', '%s'], ['%d']);
    }

    public function insert(array $row): int
    {
        return $this->db->insert($this->t, $row) === false ? 0 : (int) $this->db->insert_id;
    }

    public function update(int $id, array $row): bool
    {
        return $this->db->update($this->t, $row, ['id' => $id]) !== false;
    }

    public function remove(int $id): int
    {
        return (int) $this->db->delete($this->t, ['id' => $id], ['%d']);
    }

    public function lastError(): string
    {
        return (string) $this->db->last_error;
    }
}
