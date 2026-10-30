<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\ManualRecordService;
use WorkforceOne\Tests\Fakes\RecordsWorld;

require_once __DIR__ . '/Fakes/RecordsWorld.php';

/** ManualRecordService (3.31.138): the Sign In / Out report's add, edit and Reset Day, in the order the old trait ran them. */
final class ManualRecordServiceTest extends TestCase
{
    /** @var RecordsWorld */
    private $w;
    private const DAY = '2026-10-30';

    protected function setUp(): void
    {
        $this->w = new RecordsWorld();
        $this->w->employee(7, 'Omar', 70);
        $this->w->employee(8, 'NoLogin', 0);
        $this->w->employee(9, 'Gone', 90, false);
        $this->w->days->add(7, self::DAY, 'Office');
    }

    private function svc(): ManualRecordService
    {
        $w = $this->w;
        return new ManualRecordService($w->logs, $w->breaks, $w->dayService(), $w->peoplePort(), $w->site(), $w->manualPort(), $w->auditPort());
    }

    private function add(int $emp, string $type, string $at, ?string $day = null, int $id = 0): array
    {
        return $this->svc()->save($id, $emp, $day ?? self::DAY, $type, $at, true, null, null, null, $type === 'sign_out' ? 'Sign Out' : 'Sign In', 1);
    }

    public function testAddingARecordInsertsItWithTheEmployeesUserAndAudits(): void
    {
        $r = $this->add(7, 'sign_in', self::DAY . ' 09:00:00');
        $this->assertSame(['error' => null, 'id' => 100], $r);
        $row = $this->w->logs->rows[0];
        $this->assertSame(['70', 'admin', 'Office'], [(string) $row->user_id, $row->source, $row->scheduled_status]);
        $this->assertSame([['time_manual_add', 'time_log', 100, 'Admin added Sign In for Omar on 2026-10-30']], $this->w->audits);
    }

    public function testAnEmployeeWithoutAUserGetsTheActingUsersIdAndNoPlanMeansNotScheduled(): void
    {
        $this->add(8, 'sign_in', self::DAY . ' 09:00:00');
        $row = $this->w->logs->rows[0];
        $this->assertSame(['1', 'Not Scheduled'], [(string) $row->user_id, $row->scheduled_status]);
    }

    public function testEveryErrorCodeAndTheOrderOfTheChecks(): void
    {
        $this->assertSame('invalid', $this->svc()->save(0, 7, self::DAY, 'nope', self::DAY . ' 09:00:00', true, null, null, null, 'x', 1)['error']);
        $this->assertSame('invalid', $this->svc()->save(0, 7, 'bad', 'sign_in', null, false, null, null, null, 'x', 1)['error']);
        $this->assertSame('employee', $this->add(99, 'sign_in', self::DAY . ' 09:00:00')['error']);
        $this->assertSame('employee', $this->add(9, 'sign_in', self::DAY . ' 09:00:00')['error']);
        $this->assertSame('date_mismatch', $this->add(7, 'sign_in', '2026-10-29 09:00:00')['error']);
        $this->add(7, 'sign_in', self::DAY . ' 09:00:00');
        $this->assertSame('duplicate', $this->add(7, 'sign_in', self::DAY . ' 09:30:00')['error']);
        $this->assertSame('order', $this->add(7, 'sign_out', self::DAY . ' 08:00:00')['error']);
        $this->assertSame('not_found', $this->add(7, 'sign_in', self::DAY . ' 09:00:00', null, 555)['error']);
    }

    public function testTheOthersAreReadOnlyAfterTheFirstChecksPass(): void
    {
        $this->add(7, 'nope', self::DAY . ' 09:00:00');
        $this->assertNotContains('othersOn', $this->w->logs->calls);
        $this->add(7, 'sign_in', self::DAY . ' 09:00:00');
        $this->assertContains('othersOn', $this->w->logs->calls);
    }

    public function testEditingChangesAnyRowIncludingACorrectionRowAndKeepsItsOtherColumns(): void
    {
        $this->w->logs->add(5, 7, self::DAY, 'sign_in', self::DAY . ' 09:00:00', ['source' => 'correction', 'user_id' => '12']);
        $r = $this->add(7, 'sign_in', self::DAY . ' 08:30:00', null, 5);
        $this->assertSame(['error' => null, 'id' => 5], $r);
        $row = $this->w->logs->rows[0];
        $this->assertSame(self::DAY . ' 08:30:00', $row->event_at);
        $this->assertSame(['correction', '12'], [$row->source, (string) $row->user_id]);
        $this->assertSame('time_manual_edit', $this->w->audits[0][0]);
    }

    public function testAnArchivedEmployeesRecordCanBeEditedButNotAdded(): void
    {
        $this->w->logs->add(5, 9, self::DAY, 'sign_in', self::DAY . ' 09:00:00');
        $this->assertNull($this->add(9, 'sign_in', self::DAY . ' 09:10:00', null, 5)['error']);
    }

    public function testScopeIsAskedForTheRecordsEmployeeThenTheChosenOne(): void
    {
        $this->w->scope = [7 => true];
        $this->w->logs->add(5, 8, self::DAY, 'sign_in', self::DAY . ' 09:00:00');
        $this->assertSame('scope', $this->add(7, 'sign_in', self::DAY . ' 09:10:00', null, 5)['error']);
        $this->assertSame('scope', $this->add(8, 'sign_in', self::DAY . ' 09:10:00')['error']);
        $this->assertSame(['mayManage 8', 'mayManage 8'], $this->w->log);
        $this->assertSame(0, count($this->w->audits));
    }

    public function testAFailedInsertAuditsTheIdOfThePreviousInsert(): void
    {
        $this->add(7, 'sign_in', self::DAY . ' 09:00:00');   // id 100
        $this->w->logs->failInsert = true;
        $r = $this->add(7, 'sign_out', self::DAY . ' 17:00:00');
        $this->assertSame(['error' => 'save', 'id' => 100], $r);
        $this->assertSame(['time_manual_add_failed', 'time_log', 100, 'Table is full'], $this->w->audits[1]);
    }

    public function testAFailedUpdateIsAuditedWithTheRecordsId(): void
    {
        $this->w->logs->add(5, 7, self::DAY, 'sign_in', self::DAY . ' 09:00:00');
        $this->w->logs->failUpdate = true;
        $r = $this->add(7, 'sign_in', self::DAY . ' 08:00:00', null, 5);
        $this->assertSame('save', $r['error']);
        $this->assertSame(['time_manual_edit_failed', 'time_log', 5, 'Database error'], $this->w->audits[0]);
    }

    public function testResetDeletesTheDaysRowsAndBreaksInThatOrderAndCancelsTheBreakEvents(): void
    {
        $this->w->logs->add(1, 7, self::DAY, 'sign_in', self::DAY . ' 09:00:00');
        $this->w->logs->add(2, 7, self::DAY, 'sign_in', self::DAY . ' 09:05:00', ['source' => 'correction', 'corrects_id' => '1']);
        $this->w->breaks->add(1, 7, self::DAY, 'Open');
        $this->w->breaks->add(2, 7, self::DAY, 'Completed');
        $this->w->breaks->add(3, 7, '2026-10-29', 'Completed');
        $this->assertSame([2, 2], $this->svc()->reset(7, self::DAY));
        $this->assertSame(['ensureBreakTable', 'clearBreakEvents 1', 'clearBreakEvents 2'], $this->w->log);
        $this->assertSame([['time_reset', 'time_log', 7, 'Reset attendance and break records for 2026-10-30 (time_logs=2, breaks=2)']], $this->w->audits);
        $this->assertCount(1, $this->w->breaks->rows);
    }
}
