<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Organization\OrgRules as R;

final class OrgRulesTest extends TestCase
{
    private static function dept(array $over = []): array
    {
        return array_merge(['name' => 'Ops', 'code' => 'ops', 'is_new' => false, 'manager' => 0, 'manager_in_department' => false, 'duplicate' => null], $over);
    }

    private static function team(array $over = []): array
    {
        return array_merge(['name' => 'Field', 'department' => 1, 'manager' => 5, 'department_active' => true, 'manager_in_department' => true, 'members_in_department' => true, 'duplicate' => null], $over);
    }

    public function testDepartment(): void
    {
        $this->assertSame('', R::departmentError(self::dept()));
        $this->assertSame('required', R::departmentError(self::dept(['code' => ''])));
        $this->assertSame('duplicate', R::departmentError(self::dept(['duplicate' => 'active'])));
        $this->assertSame('duplicate_archived', R::departmentError(self::dept(['duplicate' => 'archived'])));
        $this->assertSame('manager_outside', R::departmentError(self::dept(['manager' => 3])));
        $this->assertSame('manager_new', R::departmentError(self::dept(['manager' => 3, 'is_new' => true])));
        $this->assertSame('', R::departmentError(self::dept(['manager' => 3, 'manager_in_department' => true])));
        $this->assertSame(['has_employees', 'has_teams', ''], [R::departmentArchiveError(2, 1), R::departmentArchiveError(0, 1), R::departmentArchiveError(0, 0)]);
    }

    public function testTeam(): void
    {
        $this->assertSame('', R::teamError(self::team()));
        $this->assertSame(['required', 'required', 'department', 'manager_outside', 'members_outside', 'duplicate_archived'], [
            R::teamError(self::team(['name' => ''])), R::teamError(self::team(['manager' => 0])), R::teamError(self::team(['department_active' => false])),
            R::teamError(self::team(['manager_in_department' => false])), R::teamError(self::team(['members_in_department' => false])), R::teamError(self::team(['duplicate' => 'archived'])),
        ]);
    }

    public function testDuplicateAndMessages(): void
    {
        $this->assertSame([null, 'active', 'archived', 'active'], [R::duplicate([]), R::duplicate([1]), R::duplicate(['0']), R::duplicate([0, '1'])]);
        $this->assertSame('Could not save the team.', R::message('team', '1'));
        $this->assertSame('', R::message('department', 'Your account was hacked'));
        $this->assertSame('Department still has active employees.', R::message('department', 'has_employees'));
    }
}
