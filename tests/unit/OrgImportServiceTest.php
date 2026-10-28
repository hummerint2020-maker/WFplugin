<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\OrgDirectory;
use WorkforceOne\People\OrgImportService;
use WorkforceOne\People\OrgMoveService;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\ReportingService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** OrgImportService (3.31.134): the people the file is compared with, and the saving of a previewed plan. */
final class OrgImportServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var OrgImportService */
    private $svc;

    protected function setUp(): void
    {
        $s = $this->s = new OrgSet();
        $s->people->add(1, 'Mona', 11, true, ['job_title' => 'Lead']);
        $s->people->add(2, 'Omar', 12);
        $s->people->add(3, 'Lina', 13);
        $s->people->add(4, 'Sam', 14);
        $s->people->add(5, 'OldBoss', 15, false);
        $s->rep->lines = [[1, 2, 1, 'supervisor', 1], [2, 3, 1, 'supervisor', 1]];
        $people = new PeopleDirectory($s->people, $s->ports->memo);
        $org = new OrgDirectory($s->people, $s->rep, $s->dep, $s->teams);
        $moves = new OrgMoveService($s->moves, new ReportingService($s->rep, $s->clock), $people, $org, $s->tx, $s->audit, $s->ports);
        $this->svc = new OrgImportService($s->people, $org, $moves, $s->audit, $s->ports);
    }

    private function plan(array $moves, array $titles = []): array
    {
        return ['rows' => 5, 'unchanged' => 0, 'moves' => $moves, 'titles' => $titles, 'problems' => [['row' => 9, 'code' => 'manager_unknown', 'value' => 'zz']], 'error' => ''];
    }

    public function testPeopleHaveTheirManagersAndAnArchivedPersonIsMarked(): void
    {
        $p = $this->svc->people();
        $this->assertSame(['id' => 2, 'name' => 'Omar', 'domain' => 'd2', 'title' => '', 'manager' => 1, 'active' => true], $p[2]);
        $this->assertSame(['Lead', 0], [$p[1]['title'], $p[1]['manager']]);
        $this->assertFalse($p[5]['active']);
    }

    public function testApplyMovesNowAndSavesTitlesAndAudits(): void
    {
        $r = $this->svc->apply($this->plan(
            [['row' => 2, 'employee' => 4, 'from' => 0, 'to' => 1], ['row' => 3, 'employee' => 2, 'from' => 1, 'to' => 1], ['row' => 4, 'employee' => 3, 'from' => 1, 'to' => 5]],
            [['row' => 2, 'employee' => 1, 'from' => 'Lead', 'to' => 'Head'], ['row' => 3, 'employee' => 5, 'from' => '', 'to' => 'Gone']]
        ), '2026-10-28', 'imported', 'f.csv');
        $this->assertSame(['moved' => 1, 'planned' => 0, 'titles' => 1, 'skipped' => 2], $r);
        $this->assertSame('Head', $this->s->people->rows[1]->job_title);
        $this->assertSame(['org_move', 'employee_job_title', 'org_imported'], array_map(function ($a) { return $a[0]; }, $this->s->audit->rows));
        $this->assertSame('f.csv: 1 moved, 0 scheduled for 2026-10-28, 1 job titles, 2 skipped, 1 rows not used', $this->s->audit->rows[2][3]);
    }

    public function testADatedImportSchedulesAndLooksForNoLoop(): void
    {
        $r = $this->svc->apply($this->plan([['row' => 2, 'employee' => 1, 'from' => 0, 'to' => 2]]), '2026-11-02', 'later', 'g.csv');
        $this->assertSame(['moved' => 0, 'planned' => 1, 'titles' => 0, 'skipped' => 0], $r, 'Mona under Omar is a loop today, but it is checked again on its day');
        $this->assertSame('scheduled', $this->s->moves->rows[1]->status);
        $this->assertSame([], $this->s->w->only('tx begin'));
    }

    public function testPassesLetARowWaitForTheOneThatUnblocksIt(): void
    {
        // Mona under Omar is a loop until Omar is moved to Sam.
        $r = $this->svc->apply($this->plan([['row' => 2, 'employee' => 1, 'from' => 0, 'to' => 2], ['row' => 3, 'employee' => 2, 'from' => 1, 'to' => 4]]), '2026-10-28', 'r', 'f.csv');
        $this->assertSame(2, $r['moved']);
        $loop = $this->svc->apply($this->plan([['row' => 2, 'employee' => 4, 'from' => 0, 'to' => 1], ['row' => 3, 'employee' => 1, 'from' => 0, 'to' => 4]]), '2026-10-28', 'r', 'f.csv');
        $this->assertSame(['moved' => 1, 'skipped' => 1], ['moved' => $loop['moved'], 'skipped' => $loop['skipped']], 'a loop that never resolves is skipped');
    }

    public function testAMoveThatCannotBeSavedIsSkipped(): void
    {
        $this->s->w->fail['moves.insert'] = true;
        $r = $this->svc->apply($this->plan([['row' => 2, 'employee' => 4, 'from' => 0, 'to' => 1]]), '2026-10-28', 'r', 'f.csv');
        $this->assertSame(['moved' => 0, 'skipped' => 1], ['moved' => $r['moved'], 'skipped' => $r['skipped']]);
    }
}
