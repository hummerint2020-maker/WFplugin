<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\ReportingService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** ReportingService (3.31.134): one active line per type, the supervisor, and the Approvals page's save. */
final class ReportingServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var ReportingService */
    private $svc;

    protected function setUp(): void
    {
        $this->s = new OrgSet();
        $this->s->rep->lines = [[1, 2, 1, 'supervisor', 1], [2, 2, 9, 'dotted', 1]];
        $this->svc = new ReportingService($this->s->rep, $this->s->clock);
    }

    public function testSetRelationshipEndsTheOldLineOfThatTypeAndAddsTheNewOne(): void
    {
        $this->assertSame(101, $this->svc->setRelationship(2, 'supervisor', 3, 0));
        $this->assertSame([[1, 2, 1, 'supervisor', 0], [2, 2, 9, 'dotted', 1], [101, 2, 3, 'supervisor', 1]], $this->s->rep->lines);
        $this->assertSame(['reporting deactivate 2 supervisor', 'reporting insertLine 2 supervisor 3 0'], $this->s->w->log);
    }

    public function testBadInputIsRefusedBeforeAnyWrite(): void
    {
        $this->assertSame('invalid_relationship', $this->svc->setRelationship(0, 'supervisor', 3, 0)['code']);
        $this->assertSame('invalid_relationship', $this->svc->setRelationship(2, '', 3, 0)['code']);
        $this->assertSame('invalid_relationship', $this->svc->setRelationship(2, '0', 3, 0)['code'], 'the type "0" is empty to the old code too');
        $this->assertSame('invalid_target', $this->svc->setRelationship(2, 'supervisor', 0, 0)['code']);
        $this->assertSame([], $this->s->w->log);
        $this->assertSame(102 - 1, $this->svc->setRelationship(2, 'dotted', 0, 55), 'a WordPress user is a target too');
    }

    public function testADatabaseErrorComesBackWithItsText(): void
    {
        $this->s->w->fail['reporting.deactivate'] = true;
        $this->assertSame(['code' => 'db_error', 'message' => 'deactivate failed'], $this->svc->setRelationship(2, 'supervisor', 3, 0));
        $this->assertSame(['reporting deactivate 2 supervisor'], $this->s->w->log, 'no insert after a failed update');
        $this->s->w->fail = ['reporting.insertLine' => true];
        $this->assertSame('insert failed', $this->svc->setRelationship(2, 'supervisor', 3, 0)['message']);
    }

    public function testSetSupervisorOrEndIt(): void
    {
        $this->assertTrue($this->svc->setSupervisor(2, 3));
        $this->assertTrue($this->svc->setSupervisor(2, 0));
        $this->assertSame([], array_filter($this->s->rep->lines, function ($l) { return $l[3] === 'supervisor' && $l[4] === 1; }));
        $this->assertFalse($this->svc->setSupervisor(0, 3), 'no employee');
        $this->s->w->fail['reporting.deactivateSupervisor'] = true;
        $this->assertFalse($this->svc->setSupervisor(2, 0));
    }

    public function testSavePickedIgnoresBothResults(): void
    {
        $this->s->w->fail = ['reporting.deactivateSupervisor' => true, 'reporting.insertLine' => true];
        $this->svc->savePicked(2, 3);
        $this->assertSame(['reporting deactivateSupervisor 2', 'reporting deactivate 2 supervisor', 'reporting insertLine 2 supervisor 3 0'], $this->s->w->log, 'both are tried and nothing is reported');
        $this->s->w->log = [];
        $this->svc->savePicked(0, 3);
        $this->assertSame([], $this->s->w->log);
        $this->svc->savePicked(2, 0);
        $this->assertSame(['reporting deactivateSupervisor 2'], $this->s->w->log);
    }
}
