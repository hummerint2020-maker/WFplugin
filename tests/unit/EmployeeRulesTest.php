<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Employees\EmployeeRules as R;

final class EmployeeRulesTest extends TestCase
{
    private function f(array $over = []): array
    {
        return array_merge(['id' => 7, 'name' => 'Sara', 'domain' => 'sara', 'email_valid' => true, 'department_ok' => true,
            'domain_taken' => false, 'user_taken' => false, 'supervisor_id' => 3, 'supervisor_ok' => true, 'supervisors_supervisor' => 0,
            'teams_in_department' => true, 'manages_team_elsewhere' => false], $over);
    }

    public function testValid(): void
    {
        $this->assertNull(R::check($this->f()));
        $this->assertNull(R::check($this->f(['id' => 0, 'supervisor_id' => 0])), 'a new employee without a supervisor');
    }

    public function testErrorsInOrder(): void
    {
        $this->assertSame('required', R::check($this->f(['name' => ' '])));
        $this->assertSame('required', R::check($this->f(['email_valid' => false])));
        $this->assertSame('department', R::check($this->f(['department_ok' => false])));
        $this->assertSame('domain_taken', R::check($this->f(['domain_taken' => true])));
        $this->assertSame('user', R::check($this->f(['user_ok' => false, 'user_taken' => true])));
        $this->assertSame('user_taken', R::check($this->f(['user_taken' => true])));
        $this->assertSame('self_supervisor', R::check($this->f(['supervisor_id' => 7])));
        $this->assertSame('supervisor', R::check($this->f(['supervisor_ok' => false])));
        $this->assertSame('supervisor_loop', R::check($this->f(['supervisors_supervisor' => 7])));
        $this->assertSame('team_department', R::check($this->f(['teams_in_department' => false])));
        $this->assertSame('manages_team', R::check($this->f(['manages_team_elsewhere' => true])));
    }

    public function testMessages(): void
    {
        $this->assertStringContainsString('already linked', R::errorMessage('user_taken'));
        $this->assertStringContainsString('from the list', R::errorMessage('user'));
        $this->assertSame('Employee archived.', R::noticeMessage('archived'));
        $this->assertNull(R::noticeMessage('x'));
    }
}
