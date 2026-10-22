<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Notifications\Inbox;
use WorkforceOne\Notifications\InboxStore;

final class NotificationInboxTest extends TestCase
{
    public function testZeroCountsAreCachedAndSuccessfulPublishInvalidatesOnlyRecipient(): void
    {
        $store = new InboxMemoryStore();
        $inbox = new Inbox($store);
        $this->assertSame(0, $inbox->unreadCount(1));
        $this->assertSame(0, $inbox->unreadCount(1));
        $inbox->unreadCount(2);
        $this->assertSame(2, $store->countQueries);
        $this->assertSame(1, $inbox->publish(['user_id' => 1]));
        $this->assertSame(1, $inbox->unreadCount(1));
        $this->assertSame(0, $inbox->unreadCount(2));
        $this->assertSame(3, $store->countQueries);
        $store->failInsert = true;
        $this->assertSame(0, $inbox->publish(['user_id' => 1]));
        $this->assertSame(1, $inbox->unreadCount(1));
        $this->assertSame(3, $store->countQueries);
    }

    public function testReadsAreScopedAndOnlyUnreadLandingPreservesTheFirstTimestamp(): void
    {
        $store = new InboxMemoryStore();
        $inbox = new Inbox($store);
        $id = $inbox->publish(['user_id' => 1]);
        $this->assertNull($inbox->findForUser($id, 2));
        $this->assertFalse($inbox->markRead($id, 2, 'wrong', true));
        $this->assertSame(1, $inbox->unreadCount(1));
        $this->assertTrue($inbox->markRead($id, 1, 'first', true));
        $this->assertSame(0, $inbox->unreadCount(1));
        $this->assertFalse($inbox->markRead($id, 1, 'second', true));
        $this->assertSame('first', $inbox->findForUser($id, 1)->read_at);
        $this->assertSame(2, $store->countQueries);
        // The explicit legacy Mark read action also updates an already read row.
        $this->assertTrue($inbox->markRead($id, 1, 'explicit'));
        $this->assertSame('explicit', $inbox->findForUser($id, 1)->read_at);
        $this->assertSame(0, $inbox->unreadCount(1));
        $this->assertSame(3, $store->countQueries);
    }

    public function testMarkAllReadPreservesOtherUsersAndExistingReadTimes(): void
    {
        $inbox = new Inbox(new InboxMemoryStore());
        $a = $inbox->publish(['user_id' => 1]);
        $b = $inbox->publish(['user_id' => 1]);
        $c = $inbox->publish(['user_id' => 2]);
        $inbox->markRead($a, 1, 'first', true);
        $this->assertSame(1, $inbox->unreadCount(1));
        $inbox->markAllRead(1, 'all');
        $this->assertSame(0, $inbox->unreadCount(1));
        $this->assertSame('first', $inbox->findForUser($a, 1)->read_at);
        $this->assertSame('all', $inbox->findForUser($b, 1)->read_at);
        $this->assertSame(0, $inbox->findForUser($c, 2)->is_read);
    }

    public function testListingBoundsAndIndependentSiteCaches(): void
    {
        $store = new InboxMemoryStore();
        $inbox = new Inbox($store);
        $inbox->rows(7, -200, true);
        $this->assertSame([7, 100, true], $store->lastList);
        $inbox->rows(7, 0);
        $this->assertSame([7, 1, false], $store->lastList);
        $this->assertSame([], $inbox->rows(0));
        $this->assertSame(0, $inbox->unreadCount(0));
        $this->assertSame(0, $store->countQueries);
        $inbox->publish(['user_id' => 7]);
        $otherSite = new Inbox(new InboxMemoryStore());
        $this->assertSame(1, $inbox->unreadCount(7));
        $this->assertSame(0, $otherSite->unreadCount(7));
    }
}

/** Test double for the storage contract; WordPress/MySQL behaviour is covered by e2e_notification_inbox.php. */
final class InboxMemoryStore implements InboxStore
{
    public $records = [];
    public $countQueries = 0;
    public $failInsert = false;
    public $lastList;
    public function insert(array $row): int {
        if ($this->failInsert) return 0;
        $id = count($this->records) + 1;
        $this->records[$id] = (object) ($row + ['id' => $id, 'is_read' => 0, 'read_at' => null]);
        return $id;
    }
    public function setLink(int $id, string $link): void { $this->records[$id]->link = $link; }
    public function unreadCount(int $userId): int {
        $this->countQueries++;
        return count(array_filter($this->records, static function ($r) use ($userId) { return $r->user_id === $userId && !$r->is_read; }));
    }
    public function rows(int $userId, int $limit, bool $unreadOnly): array { $this->lastList = [$userId, $limit, $unreadOnly]; return []; }
    public function findForUser(int $id, int $userId): ?object { $r = $this->records[$id] ?? null; return $r && $r->user_id === $userId ? $r : null; }
    public function markRead(int $id, int $userId, string $at, bool $unreadOnly): bool {
        $r = $this->findForUser($id, $userId);
        if (!$r || ($unreadOnly && $r->is_read)) return false;
        $r->is_read = 1; $r->read_at = $at; return true;
    }
    public function markAllRead(int $userId, string $at): void { foreach ($this->records as $id => $r) $this->markRead($id, $userId, $at, true); }
    public function deleteBefore(string $cutoff): void {}
}
