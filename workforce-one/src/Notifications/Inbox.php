<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/**
 * In-app notification use cases. No WordPress, shared plugin object, SQL or Push transport.
 * One instance per site and request: the adapter supplies already sanitised records and local timestamps.
 */
final class Inbox
{
    /** @var InboxStore */
    private $store;
    /** @var array<int,int> */
    private $unread = [];

    public function __construct(InboxStore $store) { $this->store = $store; }

    /** @param array<string,mixed> $row */
    public function publish(array $row): int
    {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) return 0;
        $id = $this->store->insert($row);
        if ($id > 0) $this->invalidateUnread($userId);
        return $id;
    }

    public function setLink(int $id, string $link): void { $this->store->setLink($id, $link); }

    public function unreadCount(int $userId): int
    {
        if ($userId <= 0) return 0;
        if (!array_key_exists($userId, $this->unread)) $this->unread[$userId] = $this->store->unreadCount($userId);
        return $this->unread[$userId];
    }

    /** @return object[] */
    public function rows(int $userId, int $limit = 30, bool $unreadOnly = false): array
    {
        if ($userId <= 0) return [];
        return $this->store->rows($userId, max(1, min(100, abs($limit))), $unreadOnly);
    }

    public function findForUser(int $id, int $userId): ?object
    {
        return $id > 0 && $userId > 0 ? $this->store->findForUser($id, $userId) : null;
    }

    public function markRead(int $id, int $userId, string $at, bool $unreadOnly = false): bool
    {
        if ($userId <= 0) return false;
        $changed = $this->store->markRead($id, $userId, $at, $unreadOnly);
        if (!$unreadOnly || $changed) $this->invalidateUnread($userId);
        return $changed;
    }

    public function markAllRead(int $userId, string $at): void
    {
        if ($userId <= 0) return;
        $this->store->markAllRead($userId, $at);
        $this->invalidateUnread($userId);
    }

    public function deleteBefore(string $cutoff): void { $this->store->deleteBefore($cutoff); }

    /** 0: every recipient in this instance. */
    public function invalidateUnread(int $userId = 0): void
    {
        if ($userId > 0) unset($this->unread[$userId]);
        else $this->unread = [];
    }
}
