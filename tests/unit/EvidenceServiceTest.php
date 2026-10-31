<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\EvidenceService;
use WorkforceOne\Attendance\EvidenceSite;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Tests\Fakes\MemoryAttemptStore;
use WorkforceOne\Tests\Fakes\MemoryTimeLogStore;

require_once __DIR__ . '/Fakes/MemoryAttendanceStores.php';

/** Sign In evidence (3.31.139) on in-memory stores: no WordPress, no database. */
final class EvidenceServiceTest extends TestCase
{
    /** @var MemoryAttemptStore */
    private $attempts;
    /** @var MemoryTimeLogStore */
    private $logs;
    /** @var array<string,mixed> */
    public $settings;
    /** @var list<array<int,mixed>> */
    public $audits = [];
    /** @var list<string> everything the service asked the site for, in order */
    public $site = [];

    protected function setUp(): void
    {
        $this->attempts = new MemoryAttemptStore();
        $this->logs = new MemoryTimeLogStore();
        $this->settings = ['attempts' => 1, 'attempt_days' => 60, 'alert' => 0, 'photo' => 0, 'photo_days' => 30, 'photo_viewers' => 'hr_manager'];
        $this->audits = [];
        $this->site = [];
    }

    private function service(): EvidenceService
    {
        $t = $this;
        $site = new class($t) implements EvidenceSite {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function settings(): array { $this->t->site[] = 'settings'; return $this->t->settings; }
            public function ensureSchemaOnce(): void { $this->t->site[] = 'schema'; }
            public function distance(float $meters): string { return round($meters) . ' m'; }
            public function alert(object $employee, string $day, int $n): void { $this->t->site[] = 'alert ' . $employee->id . ' ' . $day . ' ' . $n; }
            public function deletePhoto(string $key): void { $this->t->site[] = 'delete ' . $key; }
            public function sweepPhotos(int $olderThan): void { $this->t->site[] = 'sweep ' . $olderThan; }
        };
        $audit = new class($t) implements AuditLog {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function record(string $a, string $e, int $id, string $d): void { $this->t->audits[] = [$a, $e, $id, $d]; }
        };
        return new EvidenceService($this->attempts, $this->logs, $site, $audit, new class implements Clock { public function now(): string { return '2026-10-26 09:00:00'; } });
    }

    private function emp(int $id = 7): object
    {
        return (object) ['id' => (string) $id, 'name' => 'Omar', 'department_id' => '3', 'wp_user_id' => '70'];
    }

    public function testNothingHappensWhenOffOrWithoutAnEmployee(): void
    {
        $this->settings['attempts'] = 0;
        $this->assertSame(0, $this->service()->keep($this->emp(), ['event_type' => 'sign_in', 'result' => 'refused'], 70, '10.0.0.1', 'Pixel'));
        $this->assertSame(['settings'], $this->site, 'the settings are read, nothing else is touched');
        $this->assertSame([], $this->attempts->calls);
        $this->settings['attempts'] = 1;
        $this->site = [];
        $this->assertSame(0, $this->service()->keep(null, ['event_type' => 'sign_in', 'result' => 'refused'], 70, '', ''));
        $this->assertSame([], $this->site, 'no employee: not even the settings');
    }

    public function testARefusalIsKeptWithItsRowAndAudited(): void
    {
        $id = $this->service()->keep($this->emp(), ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'outside_location', 'detail' => str_repeat('x', 300), 'distance_meters' => 850.4,
            'latitude' => 30.5, 'longitude' => '', 'accuracy' => 0, 'location_status' => 'outside', 'source' => 'qr'], 70, '10.0.0.1', 'Pixel');
        $this->assertSame(1, $id);
        $row = $this->attempts->rows[0];
        $this->assertSame('2026-10-26 09:00:00', $row->attempted_at, 'the clock gives the time when the caller gave none');
        $this->assertSame('2026-10-26', $row->work_date);
        $this->assertSame(255, mb_strlen($row->detail));
        $this->assertSame(0, $row->accuracy, 'a zero is kept');
        $this->assertFalse(isset($row->longitude), 'an empty value is left out');
        $this->assertSame('qr', $row->source);
        $this->assertSame([['signin_refused', 'signin_attempt', 1, 'Omar / Sign In / Outside the work location (850 m away) / Pixel 10.0.0.1']], $this->audits);
        $this->assertSame(['settings', 'schema', 'settings'], $this->site, 'settings, the schema, then the alert setting (0: nobody is told)');
    }

    public function testAMatchedFaceCheckIsKeptButNotAudited(): void
    {
        $this->assertSame(1, $this->service()->keep($this->emp(), ['event_type' => 'face', 'result' => 'face_ok', 'reason' => 'face_ok'], 70, '', ''));
        $this->assertSame([], $this->audits);
        $this->assertSame(['settings', 'schema'], $this->site);
    }

    public function testAFailedInsertKeepsNothingAndTellsNobody(): void
    {
        $this->attempts->failInsert = true;
        $this->settings['alert'] = 1;
        $this->assertSame(0, $this->service()->keep($this->emp(), ['event_type' => 'sign_in', 'result' => 'refused'], 70, '', ''));
        $this->assertSame([], $this->audits);
        $this->assertNotContains('alert 7 2026-10-26 1', $this->site);
    }

    public function testTheManagersAreToldAtExactlyN(): void
    {
        $this->settings['alert'] = 2;
        $s = $this->service();
        $a = ['event_type' => 'sign_in', 'result' => 'refused', 'reason' => 'too_early'];
        $s->keep($this->emp(), $a, 70, '', '');
        $this->assertNotContains('alert 7 2026-10-26 2', $this->site);
        $s->keep($this->emp(), $a, 70, '', '');
        $this->assertContains('alert 7 2026-10-26 2', $this->site);
        $this->site = [];
        $s->keep($this->emp(), $a, 70, '', '');
        $this->assertNotContains('alert 7 2026-10-26 2', $this->site, 'the third refusal does not tell again');
    }

    public function testAnUnsentAttemptIsDroppedWhenItReachedUsAfterAll(): void
    {
        $this->logs->add(1, 7, '2026-10-26', 'sign_in', '2026-10-26 08:05:00');
        $r = $this->service()->offline($this->emp(), 'sign_in', '2026-10-26 07:50:00', '2026-10-26 08:20:00', ['sign_in', 'late_sign_in'], '2026-10-26 00:00:00', ['event_type' => 'sign_in', 'result' => 'not_reached'], 70, '', '');
        $this->assertSame('reached', $r);
        $this->assertSame([], $this->attempts->rows);
        $this->assertSame(['schema'], $this->site);
        $this->assertNotContains('typeBetween', $this->attempts->calls, 'a record is enough: the attempts are not asked');
    }

    public function testAnUnsentAttemptIsDroppedWhenAnAttemptOfThatKindIsThere(): void
    {
        $this->attempts->add(7, 'sign_out', 'refused', '2026-10-26 17:05:00');
        $r = $this->service()->offline($this->emp(), 'sign_out', '2026-10-26 16:50:00', '2026-10-26 17:20:00', ['sign_out'], '2026-10-26 00:00:00', ['event_type' => 'sign_out', 'result' => 'not_reached'], 70, '', '');
        $this->assertSame('reached', $r);
    }

    public function testAtMostFiveUnsentAttemptsADay(): void
    {
        for ($i = 0; $i < 5; $i++) $this->attempts->add(7, 'sign_in', 'not_reached', '2026-10-2' . $i . ' 08:00:00', ['reported_at' => '2026-10-26 08:30:00']);
        $r = $this->service()->offline($this->emp(), 'sign_in', 'a', 'b', ['sign_in'], '2026-10-26 00:00:00', ['event_type' => 'sign_in', 'result' => 'not_reached'], 70, '', '');
        $this->assertSame('limit', $r);
        $this->assertCount(5, $this->attempts->rows);
        $this->assertSame('kept', $this->service()->offline($this->emp(8), 'sign_in', 'a', 'b', ['sign_in'], '2026-10-26 00:00:00', ['event_type' => 'sign_in', 'result' => 'not_reached', 'reason' => 'no_network'], 70, '', ''), 'someone else is not limited');
    }

    public function testAnUnsentAttemptIsKeptAndAudited(): void
    {
        $r = $this->service()->offline($this->emp(), 'sign_in', 'a', 'b', ['sign_in'], '2026-10-26 00:00:00', ['event_type' => 'sign_in', 'result' => 'not_reached', 'reason' => 'no_network', 'attempted_at' => '2026-10-26 08:01:00', 'time_sure' => 1], 70, '', 'Pixel');
        $this->assertSame('kept', $r);
        $this->assertSame('signin_not_reached', $this->audits[0][0]);
        $this->assertSame('Omar / Sign In / Did not reach the server (no network) / pressed at 08:01 / Pixel', $this->audits[0][3]);
        $this->attempts->failInsert = true;
        $this->assertSame('not_kept', $this->service()->offline($this->emp(9), 'sign_in', 'a', 'b', ['sign_in'], '2026-10-26 00:00:00', ['event_type' => 'sign_in', 'result' => 'not_reached'], 70, '', ''));
    }

    public function testWhichPhotoARequestPointsAt(): void
    {
        $this->logs->add(4, 7, '2026-10-26', 'sign_out', '2026-10-26 17:00:00', ['photo_key' => 'k1']);
        $id = $this->attempts->add(7, 'face', 'face_failed', '2026-10-26 08:00:00', ['photo_key' => 'k2']);
        $s = $this->service();
        $this->assertSame('k1', $s->photo('log', 4)->photo_key);
        $this->assertSame('k2', $s->photo('attempt', $id)->photo_key);
        $this->assertNull($s->photo('log', 99));
        $this->assertNull($s->photo('x', 4), 'any other kind: no row');
    }

    public function testCleanupDoesNothingWithoutTheTable(): void
    {
        $this->attempts->table = false;
        $this->assertFalse($this->service()->cleanup(1000000, 2000000));
        $this->assertSame([], $this->site, 'the settings are not even read');
    }

    public function testCleanupDeletesFilesThenKeysThenRowsAndSweepsLast(): void
    {
        $now = strtotime('2026-10-26 12:00:00 UTC');
        $this->attempts->add(7, 'face', 'face_failed', '2026-05-01 08:00:00', ['photo_key' => 'old-attempt']);   // older than both
        $this->attempts->add(7, 'face', 'face_failed', '2026-09-15 08:00:00', ['photo_key' => 'mid-attempt']);   // older than the photo days only
        $this->attempts->add(7, 'face', 'face_failed', '2026-10-25 08:00:00', ['photo_key' => 'new-attempt']);
        $this->logs->add(1, 7, '2026-09-01', 'sign_in', '2026-09-01 08:00:00', ['photo_key' => 'old-log']);
        $this->logs->add(2, 7, '2026-10-25', 'sign_in', '2026-10-25 08:00:00', ['photo_key' => 'new-log']);
        $this->assertTrue($this->service()->cleanup($now, $now));
        $this->assertSame(['settings', 'delete old-attempt', 'delete mid-attempt', 'delete old-log', 'sweep ' . ($now - 30 * 86400)], $this->site);
        $this->assertSame(['exists', 'photoKeysBefore', 'clearPhotosBefore', 'deleteBefore'], $this->attempts->calls);
        $this->assertCount(2, $this->attempts->rows, 'the attempt older than 60 days is gone');
        $this->assertNull($this->attempts->rows[0]->photo_key, 'the one older than 30 days keeps its row, not its photo');
        $this->assertSame('new-attempt', $this->attempts->rows[1]->photo_key);
        $this->assertNull($this->logs->find(1)->photo_key);
        $this->assertSame('new-log', $this->logs->find(2)->photo_key);
    }

    public function testADaysRows(): void
    {
        $this->logs->add(1, 7, '2026-10-26', 'sign_in', '2026-10-26 08:00:00');
        $this->logs->add(2, 8, '2026-10-26', 'sign_in', '2026-10-26 08:00:00');
        $this->attempts->add(7, 'sign_in', 'refused', '2026-10-26 07:00:00');
        $s = $this->service();
        $this->assertCount(1, $s->dayLogs(7, '2026-10-26'));
        $this->assertTrue($s->tableExists());
        $this->assertCount(1, $s->dayAttempts(7, '2026-10-26'));
        $this->assertSame([], $s->dayAttempts(7, '2026-10-27'));
    }
}
