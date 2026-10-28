<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\People\DepartmentService;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\Tests\Fakes\OrgSet;

require_once __DIR__ . '/Fakes/OrgWorld.php';

/** DepartmentService (3.31.134): create, edit and archive a department. */
final class DepartmentServiceTest extends TestCase
{
    /** @var OrgSet */
    private $s;
    /** @var DepartmentService */
    private $svc;

    protected function setUp(): void
    {
        $s = $this->s = new OrgSet();
        $s->people->add(1, 'Mona', 11, true, ['department_id' => '10']);
        $s->people->add(2, 'Omar', 12, true, ['department_id' => '10']);
        $s->people->add(3, 'Sam', 13, true);
        $s->dep->rows = [10 => (object) ['id' => '10', 'name' => 'Ops', 'code' => 'ops', 'description' => '', 'manager_employee_id' => null, 'active' => '1'],
            11 => (object) ['id' => '11', 'name' => 'Old', 'code' => 'old', 'description' => '', 'manager_employee_id' => null, 'active' => '0']];
        $this->svc = new DepartmentService($s->dep, $s->teams, new PeopleDirectory($s->people, $s->ports->memo), $s->audit, $s->clock);
    }

    public function testSaveValidatesThenWritesThenAudits(): void
    {
        $this->assertSame('required', $this->svc->save(0, '', '', '', 0));
        $this->assertSame('manager_new', $this->svc->save(0, 'Logistics', 'log', '', 1), 'a new department has no manager yet');
        $this->assertSame('duplicate', $this->svc->save(0, 'Ops', 'x', '', 0));
        $this->assertSame('duplicate_archived', $this->svc->save(0, 'Old', 'x', '', 0));
        $this->assertSame('manager_outside', $this->svc->save(10, 'Ops', 'ops', '', 3));
        $this->assertSame([], $this->s->audit->rows);
        $this->assertSame('', $this->svc->save(0, 'Logistics', 'log', 'boxes', 0));
        $this->assertSame('', $this->svc->save(10, 'Ops 2', 'ops2', 'd', 2));
        $this->assertSame(['department_create', 'department_update'], array_map(function ($r) { return $r[0]; }, $this->s->audit->rows));
        $this->assertSame('Logistics / log', $this->s->audit->rows[0][3]);
        $this->assertSame('2', (string) $this->s->dep->rows[10]->manager_employee_id);
    }

    public function testSaveReportsADatabaseErrorAndAuditsNothing(): void
    {
        $this->s->w->fail['departments.insert'] = true;
        $this->assertSame('save', $this->svc->save(0, 'Logistics', 'log', '', 0));
        $this->s->w->fail = ['departments.update' => true];
        $this->assertSame('save', $this->svc->save(10, 'Ops 3', 'ops3', '', 0));
        $this->assertSame([], $this->s->audit->rows);
    }

    public function testArchiveOnlyAnEmptyDepartment(): void
    {
        $this->assertSame('has_employees', $this->svc->archive(10));
        $this->s->dep->rows[12] = (object) ['id' => '12', 'name' => 'Empty', 'code' => 'empty', 'description' => '', 'manager_employee_id' => null, 'active' => '1'];
        $this->s->teams->rows[70] = (object) ['id' => '70', 'name' => 'T', 'description' => '', 'manager_employee_id' => 1, 'department_id' => '12', 'active' => '1'];
        $this->assertSame('has_teams', $this->svc->archive(12));
        $this->s->teams->rows[70]->active = '0';
        $this->assertSame('', $this->svc->archive(12));
        $this->assertSame('0', $this->s->dep->rows[12]->active);
        $this->assertSame([['department_archive', 'department', 12, '']], $this->s->audit->rows);
    }

    public function testArchiveWithoutATeamsTableAndWithADatabaseError(): void
    {
        $this->s->dep->rows[12] = (object) ['id' => '12', 'name' => 'Empty', 'code' => 'empty', 'description' => '', 'manager_employee_id' => null, 'active' => '1'];
        $this->s->teams->exists = false;
        $this->s->w->fail['departments.archive'] = true;
        $this->assertSame('db', $this->svc->archive(12));
        $this->assertSame([], $this->s->audit->rows);
    }
}
