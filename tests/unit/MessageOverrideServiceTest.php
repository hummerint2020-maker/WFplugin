<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Messages\Data\MessageOverrideStore;
use WorkforceOne\Messages\MessageCatalog;
use WorkforceOne\Messages\MessageOverrideService;
use WorkforceOne\Platform\AuditLog;

/** System messages (3.31.165): saving and resetting, with a memory store and a fake Audit Log. */
final class MessageOverrideServiceTest extends TestCase
{
    /** @var object */
    private $store;
    /** @var object */
    private $audit;
    /** @var MessageCatalog */
    private $cat;

    protected function setUp(): void
    {
        $this->store = new class implements MessageOverrideStore {
            public $all = []; public $writes = 0;
            public function all(): array { return $this->all; }
            public function cached(): array { return $this->all; }
            public function put(array $all): void { $this->all = $all; $this->writes++; }
        };
        $this->audit = new class implements AuditLog {
            public $rows = [];
            public function record(string $action, string $entity, int $entityId, string $details): void { $this->rows[] = [$action, $details]; }
        };
        $row = function ($m, $k, $p = 0) { return ['m' => $m, 'r' => ['x.php:1'], 'n' => 1, 'c' => '', 'p' => $p, 'j' => 0, 'k' => $k]; };
        $this->cat = new MessageCatalog([
            'Correction rejected' => $row('attendance', 'notification'),
            'Your correction for %1$s was rejected: %2$s' => $row('attendance', 'notification'),
            'Save' => $row('platform', 'ui'),
            '%d day' => $row('leave', 'ui', 1),
        ], ['Correction rejected' => 'رُفض التصحيح']);
    }

    private function svc(): MessageOverrideService
    {
        return new MessageOverrideService($this->store, $this->audit);
    }

    public function testSaveWritesOnceAndAudits(): void
    {
        $r = $this->svc()->save('ar', 'Correction rejected', 'طلب التصحيح اترفض', 'رُفض التصحيح', $this->cat);
        $this->assertSame('', $r['code']);
        $this->assertSame(['ar' => ['Correction rejected' => 'طلب التصحيح اترفض']], $this->store->all);
        $this->assertSame('system_message_save', $this->audit->rows[0][0]);
        $this->assertStringContainsString('before=رُفض التصحيح; after=طلب التصحيح اترفض', $this->audit->rows[0][1]);
        // The same text again: nothing written.
        $this->svc()->save('ar', 'Correction rejected', 'طلب التصحيح اترفض', 'طلب التصحيح اترفض', $this->cat);
        $this->assertSame(1, $this->store->writes);
    }

    public function testRefusalsWriteNothing(): void
    {
        $s = $this->svc();
        $this->assertSame('placeholders', $s->save('en', 'Your correction for %1$s was rejected: %2$s', 'Rejected', 'Your correction for %1$s was rejected: %2$s', $this->cat)['code']);
        $this->assertSame('unknown', $s->save('en', 'Nope', 'x', 'Nope', $this->cat)['code']);
        $this->assertSame('unknown', $s->save('fr', 'Save', 'x', 'Save', $this->cat)['code']);
        $this->assertSame('plural', $s->save('en', '%d day', '%d days', '%d day', $this->cat)['code']);
        // Someone saved another text since the form was opened.
        $this->store->all = ['en' => ['Save' => 'Keep']];
        $this->assertSame('stale', $s->save('en', 'Save', 'Store', 'Save', $this->cat)['code']);
        $this->assertSame(['en' => ['Save' => 'Keep']], $this->store->all);
        $this->assertSame([], $this->audit->rows);
    }

    public function testTheDefaultTextIsAReset(): void
    {
        $s = $this->svc();
        $this->assertSame('reset', $s->save('en', 'Save', 'Save', 'Save', $this->cat)['code']);
        $this->assertSame(0, $this->store->writes);
        $s->save('en', 'Save', 'Keep', 'Save', $this->cat);
        $this->assertSame('reset', $s->save('en', 'Save', 'Save', 'Keep', $this->cat)['code']);
        $this->assertSame([], $this->store->all);
        $this->assertSame('system_message_reset', $this->audit->rows[1][0]);
    }

    public function testResetOneModuleAllAndReview(): void
    {
        $this->store->all = ['ar' => ['Save' => 'احفظ', 'Correction rejected' => 'x', 'Old' => 'y'], 'en' => ['Save' => 'Keep']];
        $s = $this->svc();
        $this->assertSame(1, $s->reset('ar', 'review', '', $this->cat));
        $this->assertSame(1, $s->reset('ar', 'module', 'attendance', $this->cat));
        $this->assertSame(['ar' => ['Save' => 'احفظ'], 'en' => ['Save' => 'Keep']], $this->store->all);
        $this->assertSame(0, $s->reset('ar', 'one', 'Nope', $this->cat));
        $this->assertSame(1, $s->reset('ar', 'all', '', $this->cat));
        $this->assertSame(['en' => ['Save' => 'Keep']], $this->store->all);
        $this->assertSame(1, $s->reset('en', 'one', 'Save', $this->cat));
        $this->assertSame([], $this->store->all);
        $this->assertCount(4, $this->audit->rows);
        $this->assertStringContainsString('scope=module:attendance; count=1', $this->audit->rows[1][1]);
    }
}
