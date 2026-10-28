<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\TeamService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** TeamService (3.31.134): create, edit and archive a team with its members; the teams of one employee. */
final class TeamServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var TeamService */
    private $svc;

    protected function setUp(): void
    {
        $s = $this->s = new OrgSet();
        $s->people->add(1, 'Mona', 11, true, ['department_id' => '10']);
        $s->people->add(2, 'Omar', 12, true, ['department_id' => '10']);
        $s->people->add(3, 'Sam', 13, true, ['department_id' => '20']);
        $s->dep->rows = [10 => (object) ['id' => '10', 'name' => 'Ops', 'code' => 'ops', 'description' => '', 'manager_employee_id' => null, 'active' => '1'],
            20 => (object) ['id' => '20', 'name' => 'Sales', 'code' => 'sales', 'description' => '', 'manager_employee_id' => null, 'active' => '1'],
            30 => (object) ['id' => '30', 'name' => 'Gone', 'code' => 'gone', 'description' => '', 'manager_employee_id' => null, 'active' => '0']];
        $s->teams->rows = [70 => (object) ['id' => '70', 'name' => 'Alpha', 'description' => '', 'manager_employee_id' => 1, 'department_id' => '10', 'active' => '1']];
        $s->teams->teams = [70 => [1, 1]];
        $s->teams->nextId = 70;
        $this->svc = new TeamService($s->teams, $s->dep, $s->people, new PeopleDirectory($s->people, $s->ports->memo), $s->tx, $s->audit, $s->clock);
    }

    public function testSaveValidatesBeforeOpeningATransaction(): void
    {
        $this->assertSame('required', $this->svc->save(0, 0, '', '', 0, [])['error']);
        $this->assertSame('department', $this->svc->save(0, 30, 'T', '', 1, [1])['error']);
        $this->assertSame('manager_outside', $this->svc->save(0, 10, 'T', '', 3, [3])['error']);
        $this->assertSame('members_outside', $this->svc->save(0, 10, 'T', '', 1, [1, 3])['error']);
        $this->assertSame('duplicate', $this->svc->save(0, 10, 'Alpha', '', 1, [1])['error']);
        $r = $this->svc->save(0, 10, 'T', '', 1, [1, 3]);
        $this->assertSame(['members_outside', false], [$r['error'], $r['rolled_back']], 'no transaction was open: the caller sends its own ROLLBACK, as before');
        $this->assertSame([], $this->s->w->only('tx'));
    }

    public function testSaveWritesTheTeamAndItsMembersInOneTransaction(): void
    {
        $r = $this->svc->save(0, 10, 'Gamma', 'new', 1, [1, 2]);
        $this->assertSame(['', false, 71], [$r['error'], $r['rolled_back'], $r['id']]);
        $this->assertSame([[71, 1, 1], [71, 2, 1]], $this->s->teams->members);
        $this->assertSame(['tx begin', 'tx commit'], $this->s->w->only('tx'));
        $this->assertSame([['team_create', 'team', 71, 'Gamma / manager=1 / members=2']], $this->s->audit->rows);
        // edit: the old members are ended first, the ones kept come back
        $this->svc->save(71, 10, 'Gamma 2', 'e', 2, [2, 1]);
        $this->assertSame([[71, 1, 1], [71, 2, 1]], $this->s->teams->members);
        $this->assertSame('team_update', $this->s->audit->rows[1][0]);
    }

    public function testEveryFailureInsideRollsBackOnceAndAuditsNothing(): void
    {
        foreach (['teams.insert', 'teams.insertMember', 'tx.commit'] as $op) {
            $this->setUp();
            $this->s->w->fail[$op] = true;
            $r = $this->svc->save(0, 10, 'Broken', '', 1, [1]);
            $this->assertSame(['save', true], [$r['error'], $r['rolled_back']], $op);
            $this->assertSame(1, count($this->s->w->only('tx rollback')), $op);
            $this->assertSame([], $this->s->audit->rows, $op);
        }
        $this->setUp();
        $this->s->w->fail['tx.begin'] = true;
        $r = $this->svc->save(0, 10, 'Broken', '', 1, [1]);
        $this->assertSame(['save', false], [$r['error'], $r['rolled_back']], 'a failed start leaves the one ROLLBACK to the caller (quirk l)');
        $this->assertSame([], $this->s->w->only('tx rollback'));
    }

    public function testArchiveEndsTheMembershipsAndSaysWhatTheDatabaseSaid(): void
    {
        $this->s->teams->members = [[70, 1, 1], [70, 2, 1]];
        $this->assertNull($this->svc->archive(70));
        $this->assertSame([[70, 1, 0], [70, 2, 0]], $this->s->teams->members);
        $this->assertSame([['team_archive', 'team', 70, '']], $this->s->audit->rows);
        $this->setUp();
        $this->s->w->fail['teams.archive'] = true;
        $this->assertSame('archive failed', $this->svc->archive(70));
        $this->assertSame([], $this->s->audit->rows);
    }

    public function testSyncEmployeeChecksTheDepartmentKeepsManagedTeamsAndReAddsTheMembership(): void
    {
        $this->s->teams->rows[71] = (object) ['id' => '71', 'name' => 'Beta', 'description' => '', 'manager_employee_id' => 9, 'department_id' => '10', 'active' => '1'];
        $this->s->teams->rows[72] = (object) ['id' => '72', 'name' => 'Sales team', 'description' => '', 'manager_employee_id' => 9, 'department_id' => '20', 'active' => '1'];
        $this->assertSame('team_department_mismatch', $this->svc->syncEmployee(2, [72])['code']);
        $this->assertSame('team_department_mismatch', $this->svc->syncEmployee(2, [999])['code'], 'an unknown team');
        $this->assertNull($this->svc->syncEmployee(2, [71]));
        $this->assertNull($this->svc->syncEmployee(1, []), 'Mona manages Alpha: kept');
        $this->assertSame([[71, 2, 1], [70, 1, 1]], $this->s->teams->members);
        $this->assertNull($this->svc->syncEmployee(2, [71]));
        $this->assertSame([[71, 2, 1], [70, 1, 1]], $this->s->teams->members, 'the membership row is reused');
        $this->assertNull($this->svc->syncEmployee(2, []));
        $this->assertSame(0, $this->s->teams->members[0][2]);
        $this->s->w->fail['teams.insertMember'] = true;
        $this->assertSame(['code' => 'team_membership_save', 'message' => 'Could not save team membership: member insert failed'], $this->svc->syncEmployee(2, [70]));
    }
}
