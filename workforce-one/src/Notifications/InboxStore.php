<?php
namespace WorkforceOne\Notifications;

if (!defined('ABSPATH')) exit;

/** Persistence owned by Notifications. Callers provide the recipient, never an unscoped inbox. */
interface InboxStore
{
    /** @param array<string,mixed> $row @return int saved id, or 0 on failure */
    public function insert(array $row): int;
    public function setLink(int $id, string $link): void;
    public function unreadCount(int $userId): int;
    /** @return object[] */
    public function rows(int $userId, int $limit, bool $unreadOnly): array;
    public function findForUser(int $id, int $userId): ?object;
    public function markRead(int $id, int $userId, string $at, bool $unreadOnly): bool;
    public function markAllRead(int $userId, string $at): void;
    public function deleteBefore(string $cutoff): void;
}
