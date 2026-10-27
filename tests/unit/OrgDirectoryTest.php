<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\OrgDirectory;
use WorkforceOne\Tests\Fakes\MemoryDepartmentStore;
use WorkforceOne\Tests\Fakes\MemoryPeopleStore;
use WorkforceOne\Tests\Fakes\MemoryReportingStore;
use WorkforceOne\Tests\Fakes\MemoryTeamStore;

require_once __DIR__ . '/Fakes/MemoryPeopleStore.php';
require_once __DIR__ . '/Fakes/MemoryOrgStores.php';

/** OrgDirectory (3.31.133): reporting lines, department and team reads, on in-memory Stores. */
final class OrgDirectoryTest extends TestCase
{
    /** @var MemoryPeopleStore */
    private $people;
    /** @var MemoryReportingStore */
    private $rep;
    /** @var MemoryDepartmentStore */
    private $dep;
    /** @var MemoryTeamStore */
    private $teams;
    /** @var OrgDirectory */
    private $org;

    protected function setUp(): void
    {
        $this->people = new MemoryPeopleStore();
        $this->people->add(1, 'Mona', 11, true, ['department_id' => '5']);
        $this->people->add(2, 'Omar', 12, true, ['department_id' => '5']);
        $this->people->add(3, 'Lina', 13, true);
        $this->people->add(4, 'OldBoss', 14, false);
        $this->rep = new MemoryReportingStore($this->people);
        $this->dep = new MemoryDepartmentStore($this->people);
        $this->teams = new MemoryTeamStore($this->people);
        $this->org = new OrgDirectory($this->people, $this->rep, $this->dep, $this->teams);
        // lines: [id, employee, related, type, active]
        $this->rep->lines = [[1, 2, 1, 'supervisor', 1], [2, 3, 4, 'supervisor', 1], [3, 3, 2, 'supervisor', 0], [4, 3, 1, 'supervisor', 1]];
    }

    public function testManagerIdsTheNewestLineWinsAndAnArchivedManagerStillCounts(): void
    {
        $this->assertSame([1 => 0, 2 => 1, 3 => 1], $this->org->managerIds(), 'the newest active line (4) wins over the archived boss (2)');
        $this->rep->lines[] = [5, 3, 4, 'supervisor', 1];
        $this->assertSame(4, $this->org->managerIds()[3], 'the newest line is the archived manager: still the manager chart-wise');
    }

    public function testManagerIdsOrderOfReads(): void
    {
        $this->org->managerIds();
        $this->assertSame(['activeIds all'], $this->people->calls);
        $this->assertSame(['tableExists', 'activeSupervisorRows'], $this->rep->calls);
    }

    public function testManagerIdsWithNoActivePeopleReadsNoLines(): void
    {
        $empty = new MemoryPeopleStore();
        $rep = new MemoryReportingStore($empty);
        $org = new OrgDirectory($empty, $rep, new MemoryDepartmentStore($empty), new MemoryTeamStore($empty));
        $this->assertSame([], $org->managerIds());
        $this->assertSame([], $rep->calls, 'not even the table check');
    }

    public function testManagerIdsWithoutTheTableAreAllZero(): void
    {
        $this->rep->exists = false;
        $this->assertSame([1 => 0, 2 => 0, 3 => 0], $this->org->managerIds());
        $this->assertSame(['tableExists'], $this->rep->calls);
    }

    public function testHasReportsChecksTheTableFirst(): void
    {
        $this->assertTrue($this->org->hasReports(1));
        $this->assertFalse($this->org->hasReports(3));
        $this->rep->exists = false;
        $this->rep->calls = [];
        $this->assertFalse($this->org->hasReports(1));
        $this->assertSame(['tableExists'], $this->rep->calls, 'no second read');
    }

    public function testSupervisorIdsAreZeroFilledAndTheNewestActiveWins(): void
    {
        $this->assertSame([2 => 1, 3 => 1, 4 => 0], $this->org->supervisorIds([2, 3, 4, 0, 3]));
        $this->assertSame([], $this->org->supervisorIds([]));
    }

    public function testRelatedEmployeeAndManagerOf(): void
    {
        $this->assertSame('Mona', $this->org->relatedEmployee(2, 'supervisor')->name);
        $this->assertNull($this->org->relatedEmployee(1, 'supervisor'));
        $this->assertSame(1, $this->org->managerOf(3));
        $this->assertSame(0, $this->org->managerOf(1));
    }

    public function testOrgRowsAskTheDepartmentsTableFirst(): void
    {
        $rows = $this->org->orgRows();
        $this->assertSame([1, 2, 3], array_keys($rows));
        $this->assertTrue($this->people->lastWithDepartments);
        $this->dep->exists = false;
        $this->org->orgRows();
        $this->assertFalse($this->people->lastWithDepartments);
    }

    public function testDepartmentManager(): void
    {
        $this->dep->departments = [5 => ['active' => 1, 'manager' => 1], 6 => ['active' => 0, 'manager' => 2]];
        $this->assertSame('Mona', $this->org->departmentManager(2)->name);
        $this->assertNull($this->org->departmentManager(3), 'no department');
        $this->people->rows[2]->department_id = '6';
        $this->assertNull($this->org->departmentManager(2), 'an archived department has no manager');
    }

    public function testTeams(): void
    {
        $this->teams->teams = [10 => [1, 1], 11 => [1, 4]];   // team => [active, manager]
        $this->teams->members = [[10, 2, 1], [11, 2, 1], [10, 3, 1], [11, 3, 0]];
        $this->assertSame('Mona', $this->org->teamManagerFor(2)->name, 'the first active team with an active manager');
        $this->assertSame('10', $this->org->teamManagerFor(2)->team_id);
        $this->assertNull($this->org->teamManagerFor(1));
        $this->assertSame([2, 3], $this->org->teamMemberIds(10));
        $this->assertSame([10, 11], $this->org->teamIdsOf(2));
        $this->assertSame([2 => [10, 11], 3 => [10], 1 => []], $this->org->teamIdsByEmployee([2, 3, 1, 0]), 'every id is keyed, also the ones with no team');
        $this->assertSame([], $this->org->teamIdsByEmployee([]));
    }
}
