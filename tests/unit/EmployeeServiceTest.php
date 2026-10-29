<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\EmployeeService;
use WorkforceOne\People\OrgDirectory;
use WorkforceOne\People\OrgMoveService;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\ReportingService;
use WorkforceOne\People\TeamService;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** EmployeeService (3.31.135): the facts of the check, add, update and archive, on in-memory Stores. */
final class EmployeeServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var EmployeeService */
    private $svc;

    protected function setUp(): void
    {
        $s = $this->s = new OrgSet();
        $s->people->add(1, 'Mona', 11, true, ['department_id' => '10', 'default_shift_id' => '3']);
        $s->people->add(2, 'Omar', 12, true, ['department_id' => '10', 'default_shift_id' => '3']);
        $s->people->add(3, 'Lina', 13, true, ['department_id' => '10', 'default_shift_id' => '3']);
        $s->people->add(5, 'OldBoss', 15, false, ['department_id' => '10']);
        $s->people->nextId = 500;
        $s->dep->rows = [10 => (object) ['id' => '10', 'name' => 'Ops', 'code' => 'ops', 'description' => '', 'manager_employee_id' => null, 'active' => '1']];
        $s->teams->rows = [70 => (object) ['id' => '70', 'name' => 'Alpha', 'description' => '', 'manager_employee_id' => 9, 'department_id' => '10', 'active' => '1']];
        $s->teams->teams = [70 => [1, 9]];
        $s->rep->lines = [[1, 2, 1, 'supervisor', 1], [2, 3, 1, 'supervisor', 1]];
        $s->users->ids = [11, 12, 13, 14, 99];
        $people = new PeopleDirectory($s->people, $s->ports->memo);
        $org = new OrgDirectory($s->people, $s->rep, $s->dep, $s->teams);
        $moves = new OrgMoveService($s->moves, new ReportingService($s->rep, $s->clock), $people, $org, $s->tx, $s->audit, $s->ports);
        $teams = new TeamService($s->teams, $s->dep, $s->people, $people, $s->tx, $s->audit, $s->clock);
        $this->svc = new EmployeeService($s->people, $s->dep, $s->teams, $people, $org, $moves, $teams, $s->audit, $s->ports);
    }

    /** @param array<string,mixed> $over */
    private function form(array $over = []): array
    {
        return $over + ['name' => 'New', 'domain_name' => 'newone', 'email' => 'new@x.test', 'wp_user_id' => 0, 'default_shift_id' => 3, 'supervisor_id' => 0, 'department_id' => 10,
            'attendance_enabled' => 1, 'team_ids' => [], 'active' => 1, 'job_title' => null];
    }

    public function testCheckReadsOnlyWhatIsNeededInTheOldOrder(): void
    {
        $this->assertNull($this->svc->check($this->form(['department_id' => 0]), true, 0, null));
        $this->assertSame(['people domainOwner newone 0'], $this->s->w->log, 'no department, no user, no supervisor, no teams, not a department change: one read');
        $this->s->w->log = [];
        $this->svc->check($this->form(['wp_user_id' => 14, 'supervisor_id' => 1, 'team_ids' => [70]]), true, 7, 10);
        $reads = array_map(function ($l) { return implode(' ', array_slice(explode(' ', $l), 0, 2)); }, $this->s->w->log);
        $this->assertSame(['people domainOwner', 'users exists', 'people userOwner', 'teams countActiveInDepartment'], $reads, 'the department, domain, user, supervisor and team facts come in that order (the department read is not logged by its fake)');
    }

    public function testCheckCodes(): void
    {
        $this->assertSame('required', $this->svc->check($this->form(['name' => '']), true, 0, null));
        $this->assertSame('required', $this->svc->check($this->form(), false, 0, null), 'an invalid email is decided by the controller');
        $this->assertSame('department', $this->svc->check($this->form(['department_id' => 99]), true, 0, null));
        $this->s->org->title = true;
        $this->assertSame('title', $this->svc->check($this->form(['job_title' => '']), true, 0, null));
        $this->assertNull($this->svc->check($this->form(['job_title' => '']), true, 4, 10), 'a title is required only when adding');
        $this->assertSame('domain_taken', $this->svc->check($this->form(['domain_name' => 'd2']), true, 0, null));
        $this->assertNull($this->svc->check($this->form(['domain_name' => 'd2']), true, 2, null), 'the employee keeps their own domain');
        $this->assertSame('user', $this->svc->check($this->form(['wp_user_id' => 777]), true, 0, null));
        $this->assertSame('user', $this->svc->check($this->form(['wp_user_id' => -1]), true, 0, null));
        $this->assertSame('user_taken', $this->svc->check($this->form(['wp_user_id' => 11]), true, 0, null));
        $this->assertSame('supervisor', $this->svc->check($this->form(['supervisor_id' => 5]), true, 0, null));
        $this->assertSame('self_supervisor', $this->svc->check($this->form(['supervisor_id' => 3]), true, 3, 10));
        $this->assertSame('supervisor_loop', $this->svc->check($this->form(['supervisor_id' => 2]), true, 1, 10), 'Omar reports to Mona');
        $this->assertNull($this->svc->check($this->form(['supervisor_id' => 2]), true, 0, null), 'a new employee cannot make a loop');
        $this->assertSame('team_department', $this->svc->check($this->form(['team_ids' => [70], 'department_id' => 0]), true, 0, null));
        $this->assertNull($this->svc->check($this->form(['team_ids' => [70]]), true, 0, null));
        $this->s->dep->rows[20] = (object) ['id' => '20', 'name' => 'Sales', 'code' => 'sales', 'description' => '', 'manager_employee_id' => null, 'active' => '1'];
        $this->s->teams->rows[70]->manager_employee_id = 1;
        $this->assertSame('manages_team', $this->svc->check($this->form(['department_id' => 20]), true, 1, 10), 'a team manager cannot leave the department');
        $this->assertNull($this->svc->check($this->form(['department_id' => 10]), true, 1, 10), 'the same department: nothing to strand');
    }

    public function testCreateInsertsThenFirstShiftThenSupervisorThenAudit(): void
    {
        $this->assertNull($this->svc->create($this->form(['supervisor_id' => 1, 'job_title' => 'Lead'])));
        $this->assertSame(['people insert newone', 'changed 501 0', 'shifts beginning 501 3', 'tx begin', 'reporting deactivate 501 supervisor', 'reporting insertLine 501 supervisor 1 0', 'tx commit', 'audit employee_create 501'],
            array_values(array_filter($this->s->w->log, function ($l) { return strpos($l, 'audit') === 0 || preg_match('/^(people insert|changed|shifts|tx|reporting)/', $l); })));
        $this->assertSame('Lead', $this->s->people->rows[501]->job_title);
        $this->assertSame('New / newone', $this->s->audit->rows[0][3]);
    }

    public function testCreateKeepsAnEmptyTitleOutOfTheRow(): void
    {
        $this->svc->create($this->form(['job_title' => '']));
        $this->assertFalse(isset($this->s->people->rows[501]->job_title));
    }

    public function testCreateStopsAtAFailedInsertAndIgnoresTheShiftResult(): void
    {
        $this->s->w->fail['people.insert'] = true;
        $this->assertSame('save', $this->svc->create($this->form()));
        $this->assertSame([], array_merge($this->s->w->only('changed'), $this->s->w->only('shifts'), $this->s->w->only('audit')));
        $this->setUp();
        $this->s->w->fail['shifts.beginning'] = true;
        $this->assertNull($this->svc->create($this->form()), 'quirk t: a shift that could not be kept in the history is not reported');
        $this->setUp();
        $this->s->w->fail['reporting.insertLine'] = true;
        $this->assertSame('save', $this->svc->create($this->form(['supervisor_id' => 1])));
        $this->assertSame([], $this->s->w->only('audit'), 'no audit row; the employee row stays (quirk d)');
        $this->assertTrue(isset($this->s->people->rows[501]));
    }

    private function old(array $over = []): object
    {
        return (object) ($over + ['active' => '1', 'department_id' => '10', 'wp_user_id' => '11', 'last_working_day' => null, 'default_shift_id' => '3']);
    }

    public function testUpdateWithNothingChangedWritesTheRowTheSupervisorAndTheAudit(): void
    {
        $r = $this->svc->update(1, $this->old(), $this->form(['name' => 'Mona', 'domain_name' => 'd1', 'wp_user_id' => 11]), null);
        $this->assertSame(['error' => null, 'notice' => 'updated', 'advance_left' => 0.0], $r);
        $log = $this->s->w->log;
        $this->assertSame([], $this->s->w->only('shifts'), 'the shift did not change: the history is not touched');
        $this->assertSame([], $this->s->w->only('sessions'));
        $this->assertSame(['people updateFields 1 name,domain_name,email,wp_user_id,attendance_enabled,department_id,active,last_working_day,updated_at', 'changed 1 11'], array_values(array_filter($log, function ($l) { return preg_match('/^(people updateFields|changed)/', $l); })));
        $this->assertSame([['employee_update', 'employee', 1, 'Mona / d1 / new@x.test / teams=0']], $this->s->audit->rows);
        $this->assertNotContains('advances enabled', $log, 'the advance is looked at only when the status changes to inactive');
    }

    public function testAShiftIsSavedFirstAndAFailureChangesNothingElse(): void
    {
        $this->svc->update(1, $this->old(), $this->form(['name' => 'Mona', 'domain_name' => 'd1', 'wp_user_id' => 11, 'default_shift_id' => 4]), null);
        $this->assertSame('shifts from 1 4 2026-10-28', $this->s->w->log[0]);
        $this->setUp();
        $this->s->w->fail['shifts.from'] = true;
        $r = $this->svc->update(1, $this->old(), $this->form(['default_shift_id' => 4]), null);
        $this->assertSame('save', $r['error']);
        $this->assertSame([], array_merge($this->s->w->only('people updateFields'), $this->s->w->only('changed'), $this->s->w->only('audit')));
    }

    public function testAJobTitleIsKeptWhenNotPostedAndClearedWhenEmpty(): void
    {
        $this->svc->update(1, $this->old(), $this->form(['wp_user_id' => 11, 'job_title' => null]), null);
        $this->assertStringNotContainsString('job_title', implode(' ', $this->s->w->only('people updateFields')));
        $this->svc->update(1, $this->old(), $this->form(['wp_user_id' => 11, 'job_title' => '']), null);
        $this->assertNull($this->s->people->rows[1]->job_title);
        $this->svc->update(1, $this->old(), $this->form(['wp_user_id' => 11, 'job_title' => 'Lead']), null);
        $this->assertSame('Lead', $this->s->people->rows[1]->job_title);
    }

    public function testASaveThatFailsStopsBeforeTheCacheSessionsAndAudit(): void
    {
        $this->s->w->fail['people.updateFields'] = true;
        $r = $this->svc->update(1, $this->old(), $this->form(['active' => 0]), '2026-10-20');
        $this->assertSame('save', $r['error']);
        $this->assertSame([], array_merge($this->s->w->only('changed'), $this->s->w->only('sessions'), $this->s->w->only('audit'), $this->s->w->only('tx')));
    }

    public function testWhoseSessionsEndTruthTable(): void
    {
        $cases = [
            // [old active, old user, new active, new user, expected reason or null]
            ['1', '11', 1, 11, null],                       // nothing changed
            ['1', '11', 0, 11, 'employee_archived'],        // made inactive
            ['1', '11', 1, 12, 'employee_unlinked'],        // another user while active
            ['1', '11', 0, 12, 'employee_archived'],        // inactive and another user: the reason is the new state
            ['0', '11', 1, 11, null],                       // reactivated
            ['0', '11', 0, 11, null],                       // still inactive
            ['0', '11', 0, 12, 'employee_archived'],        // another user while inactive
            ['1', null, 0, 0, null],                        // no old user
        ];
        foreach ($cases as [$oa, $ou, $na, $nu, $reason]) {
            $this->setUp();
            $this->svc->update(1, $this->old(['active' => $oa, 'wp_user_id' => $ou]), $this->form(['active' => $na, 'wp_user_id' => $nu]), $na ? null : '2026-10-20');
            $this->assertSame($reason === null ? [] : ['sessions revoke ' . $ou . ' ' . $reason], $this->s->w->only('sessions'), json_encode([$oa, $ou, $na, $nu]));
        }
    }

    public function testASupervisorChangeIsAMoveWhileTheChartIsOnAndPlainWhenOff(): void
    {
        $this->s->org->on = true;
        $r = $this->svc->update(2, $this->old(['wp_user_id' => '12']), $this->form(['wp_user_id' => 12, 'supervisor_id' => 3, 'name' => 'Omar', 'domain_name' => 'd2']), null);
        $this->assertNull($r['error']);
        $this->assertSame('employees', $this->s->moves->rows[1]->source);
        $this->assertSame('done', $this->s->moves->rows[1]->status);
        $this->assertSame(['employee_update'], array_slice(array_map(function ($r) { return $r[0]; }, $this->s->audit->rows), -1));
        $this->assertSame('org_move', $this->s->audit->rows[0][0]);
        $this->assertStringEndsWith('· 2 waiting approval step(s) moved · employees', $this->s->audit->rows[0][3]);
        $this->setUp();
        $this->s->org->on = true;
        $this->svc->update(2, $this->old(['wp_user_id' => '12']), $this->form(['wp_user_id' => 12, 'supervisor_id' => 1, 'name' => 'Omar', 'domain_name' => 'd2']), null);
        $this->assertSame([], $this->s->moves->rows, 'the supervisor did not change: no move');
        $this->setUp();
        $this->svc->update(2, $this->old(['wp_user_id' => '12']), $this->form(['wp_user_id' => 12, 'supervisor_id' => 3]), null);
        $this->assertSame([], $this->s->moves->rows, 'the chart is off');
        $this->assertSame(['tx begin', 'reporting deactivate 2 supervisor', 'reporting insertLine 2 supervisor 3 0', 'tx commit'], $this->s->w->only('tx') === [] ? [] : array_values(array_filter($this->s->w->log, function ($l) { return preg_match('/^(tx|reporting)/', $l); })));
    }

    public function testAFailedMoveOrSupervisorStopsBeforeTheTeamsAndTheAudit(): void
    {
        $this->s->org->on = true;
        $this->s->w->fail['reporting.insertLine'] = true;
        $r = $this->svc->update(2, $this->old(['wp_user_id' => '12']), $this->form(['wp_user_id' => 12, 'supervisor_id' => 3]), null);
        $this->assertSame('save', $r['error']);
        $this->assertSame([], $this->s->audit->rows, 'quirk w: the row and the sessions are already changed, nothing is audited');
        $this->assertSame([], $this->s->w->only('teams'));
    }

    public function testATeamsFailureComesAfterTheSupervisorAndBeforeTheAudit(): void
    {
        $this->s->w->fail['teams.insertMember'] = true;
        $r = $this->svc->update(2, $this->old(['wp_user_id' => '12']), $this->form(['wp_user_id' => 12, 'team_ids' => [70]]), null);
        $this->assertSame('teams', $r['error']);
        $this->assertSame([], $this->s->audit->rows);
    }

    public function testUpdateAuditTextsAndTheOpenAdvance(): void
    {
        $this->s->advances->on = true;
        $this->s->advances->left = 450.5;
        $r = $this->svc->update(1, $this->old(), $this->form(['name' => 'Mona', 'domain_name' => 'd1', 'wp_user_id' => 11, 'active' => 0, 'team_ids' => [70]]), '2026-10-20');
        $this->assertSame(['error' => null, 'notice' => 'updated', 'advance_left' => 450.5], $r);
        $this->assertSame(['Mona / d1 / new@x.test / teams=1', 'Status: Active -> Inactive / last working day 2026-10-20'], [$this->s->audit->rows[0][3], $this->s->audit->rows[1][3]]);
        $this->assertSame('employee_status_change', $this->s->audit->rows[1][0]);
        // not owed when the advances are off, or when the employee was already inactive
        $this->setUp();
        $this->svc->update(1, $this->old(), $this->form(['wp_user_id' => 11, 'active' => 0]), '2026-10-20');
        $this->assertSame(0.0, $this->svc->update(1, $this->old(['active' => '0']), $this->form(['wp_user_id' => 11, 'active' => 0]), '2026-10-20')['advance_left']);
        $this->assertSame([], array_values(array_filter($this->s->w->log, function ($l) { return $l === 'advances left 1'; })));
    }

    public function testArchive(): void
    {
        $row = (object) ['active' => '1', 'wp_user_id' => '11'];
        $this->s->advances->on = true;
        $this->s->advances->left = 90.0;
        $r = $this->svc->archive(1, $row, '2026-10-28');
        $this->assertSame(['error' => null, 'notice' => 'archived', 'advance_left' => 90.0], $r);
        $this->assertSame(['people archive 1 2026-10-28', 'changed 1 0', 'sessions revoke 11 employee_archived', 'audit employee_status_change 1', 'advances enabled', 'advances left 1'],
            array_values(array_filter($this->s->w->log, function ($l) { return preg_match('/^(people archive|changed|sessions|audit|advances)/', $l); })));
        $this->assertSame('Status: Active -> Inactive / last working day 2026-10-28', $this->s->audit->rows[0][3]);
        $this->assertSame('0', $this->s->people->rows[1]->active);
    }

    public function testArchivingSomeoneAlreadyArchivedStillWritesAndRevokesButNeverAuditsOrAsksForAdvances(): void
    {
        $this->s->advances->on = true;
        $r = $this->svc->archive(5, (object) ['active' => '0', 'wp_user_id' => '15'], '2026-10-28');
        $this->assertSame('archived', $r['notice']);
        $this->assertSame(['people archive 5 2026-10-28', 'changed 5 0', 'sessions revoke 15 employee_archived'], array_values(array_filter($this->s->w->log, function ($l) { return preg_match('/^(people archive|changed|sessions|audit|advances)/', $l); })), 'quirk u');
        $this->assertSame([], $this->s->audit->rows);
    }

    public function testArchiveFailureStopsEverything(): void
    {
        $this->s->w->fail['people.archive'] = true;
        $r = $this->svc->archive(1, (object) ['active' => '1', 'wp_user_id' => '11'], '2026-10-28');
        $this->assertSame('save', $r['error']);
        $this->assertSame([], array_merge($this->s->w->only('changed'), $this->s->w->only('sessions'), $this->s->w->only('audit')));
    }
}
