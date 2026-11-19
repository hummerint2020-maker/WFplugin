<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\OfficeMinimumScope as Scope;

final class OfficeMinimumScopeTest extends TestCase
{
    private function people(): array
    {
        return ['people' => [1 => ['name' => 'Adel', 'team' => 10, 'dept' => 5], 2 => ['name' => 'Badr', 'team' => 10, 'dept' => 5], 3 => ['name' => 'Cyrine', 'team' => 11, 'dept' => 5],
            4 => ['name' => 'Dina', 'team' => 10, 'dept' => 6], 5 => ['name' => 'Eman', 'team' => 0, 'dept' => 5], 6 => ['name' => 'Fady', 'team' => 12, 'dept' => 6], 7 => ['name' => 'Gad', 'team' => 0, 'dept' => 0]],
            'teams' => [11 => 'Alex', 10 => 'Cairo', 12 => 'Field', 0 => 'No team'], 'sizes' => [11 => 1, 10 => 3, 12 => 1, 0 => 2],
            'managers' => [1 => ['Cairo'], 4 => ['Alex'], 6 => ['Field']]];
    }

    public function testADepartmentsPeopleTeamsAndManagers(): void
    {
        $s = Scope::people($this->people(), 5);
        $this->assertSame([1, 2, 3, 5], array_keys($s['people']));
        $this->assertSame([11 => 1, 10 => 2, 0 => 1], $s['sizes'], 'team sizes counted among the department\'s people, in the same order');
        $this->assertSame([11 => 'Alex', 10 => 'Cairo', 0 => 'No team'], $s['teams']);
        $this->assertSame([1 => ['Cairo']], $s['managers'], 'a manager counts in their own department (Dina manages Alex but is in department 6)');
        $o = Scope::people($this->people(), 6);
        $this->assertSame([10 => 1, 12 => 1], $o['sizes'], 'a team spanning departments appears in each with the members it has there');
        $this->assertSame([4 => ['Alex'], 6 => ['Field']], $o['managers']);
        $this->assertSame([], Scope::people($this->people(), 9)['people']);
    }

    public function testWhoseNumbersAViewerSees(): void
    {
        $this->assertSame(0, Scope::forViewer(true, 0, 0, [5]), 'an administrator: the company');
        $this->assertSame(5, Scope::forViewer(true, 0, 5, [5]), '...or the department they picked');
        $this->assertSame(0, Scope::forViewer(true, 0, 6, [5]), '...not one without its own settings');
        $this->assertSame(5, Scope::forViewer(false, 5, 0, [5]), 'a department viewer: their department when it has its own');
        $this->assertSame(5, Scope::forViewer(false, 5, 6, [5, 6]), '...and cannot pick another');
        $this->assertSame(0, Scope::forViewer(false, 6, 0, [5]), '...the company when theirs has none');
        $this->assertSame(0, Scope::forViewer(false, 0, 5, [5]), 'no department: the company');
    }

    public function testOwnAndReminderGroups(): void
    {
        $this->assertSame([6 => 'HR', 5 => 'Sales'], Scope::own([5 => [], 6 => [], 9 => []], [5 => 'Sales', 6 => 'HR', 7 => 'Ops']), 'active ones only, by name');
        $g = Scope::reminderGroups([100 => 5, 101 => 6, 102 => 0, 103 => 5, 104 => 5], [104], [5]);
        $this->assertSame([0 => [101, 102, 104], 5 => [100, 103]], $g, 'each person once; an administrator gets the company\'s');
        $this->assertSame([0 => [1]], Scope::reminderGroups([1 => 5], [], []));
    }
}
