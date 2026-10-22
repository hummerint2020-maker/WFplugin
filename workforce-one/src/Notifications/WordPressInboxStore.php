<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/** SQL adapter for the Notifications-owned inbox table; it never receives the plugin object. */
final class WordPressInboxStore implements InboxStore
{
    /** @var \wpdb */
    private $db;
    /** @var string */
    private $table;

    public function __construct(\wpdb $db)
    {
        $this->db = $db;
        $this->table = $db->prefix . 'ews_notifications';
    }

    public function insert(array $row): int
    {
        $ok = $this->db->insert($this->table, $row, ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']);
        return $ok === false ? 0 : (int) $this->db->insert_id;
    }

    public function setLink(int $id, string $link): void
    {
        $this->db->update($this->table, ['link' => $link], ['id' => $id], ['%s'], ['%d']);
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE user_id=%d AND is_read=0", $userId));
    }

    public function rows(int $userId, int $limit, bool $unreadOnly): array
    {
        $where = 'user_id=%d' . ($unreadOnly ? ' AND is_read=0' : '');
        return (array) $this->db->get_results($this->db->prepare("SELECT * FROM {$this->table} WHERE {$where} ORDER BY is_read ASC, created_at DESC LIMIT %d", $userId, $limit));
    }

    public function findForUser(int $id, int $userId): ?object
    {
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->table} WHERE id=%d AND user_id=%d LIMIT 1", $id, $userId)) ?: null;
    }

    public function markRead(int $id, int $userId, string $at, bool $unreadOnly): bool
    {
        $sql = "UPDATE {$this->table} SET is_read=1,read_at=%s WHERE id=%d AND user_id=%d" . ($unreadOnly ? ' AND is_read=0' : '');
        return (int) $this->db->query($this->db->prepare($sql, $at, $id, $userId)) > 0;
    }

    public function markAllRead(int $userId, string $at): void
    {
        $this->db->query($this->db->prepare("UPDATE {$this->table} SET is_read=1,read_at=%s WHERE user_id=%d AND is_read=0", $at, $userId));
    }

    public function deleteBefore(string $cutoff): void
    {
        $this->db->query($this->db->prepare("DELETE FROM {$this->table} WHERE created_at < %s", $cutoff));
    }
}
