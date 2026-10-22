<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/** The inbox's existing schema, owned here; the plugin upgrade coordinator still controls when it runs. */
final class InboxSchema
{
    /** @var \wpdb */
    private $db;
    /** @var bool */
    private $ready = false;

    public function __construct(\wpdb $db) { $this->db = $db; }

    public function ensure(bool $force, bool $schemaCurrent): void
    {
        if ($this->ready && !$force) return;
        if (!$force && $schemaCurrent) return;
        $table = $this->db->prefix . 'ews_notifications';
        $charset = $this->db->get_charset_collate();
        $exists = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists || $force) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'info',
                entity VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                link TEXT NULL,
                PRIMARY KEY(id),
                KEY user_unread(user_id,is_read),
                KEY user_read_date(user_id,is_read,created_at),
                KEY created_at(created_at),
                KEY entity(entity,entity_id)
            ) {$charset};");
        }
        $this->ready = true;
    }
}
