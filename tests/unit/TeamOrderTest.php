<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Schedule\TeamOrder;

final class TeamOrderTest extends TestCase
{
    private static function emp(int $id, string $name): object
    {
        return (object) ['id' => $id, 'name' => $name];
    }

    private static function names(array $emps): array
    {
        return array_map(static function ($e) { return $e->name; }, $emps);
    }

    public function testManagerThenMeThenTeamThenPeopleWithoutATeam(): void
    {
        $teams = [10 => ['name' => 'Blue', 'manager' => 2]];
        $members = [1 => [10], 2 => [10], 3 => [10]];
        $emps = [self::emp(4, 'Aaron Loner'), self::emp(3, 'Ann Alpha'), self::emp(1, 'Me Myself'), self::emp(2, 'Zed Boss')];
        $out = TeamOrder::arrange($emps, $teams, $members, 1);
        $this->assertSame(['Zed Boss', 'Me Myself', 'Ann Alpha', 'Aaron Loner'], self::names($out));
        $this->assertSame(1, $out[0]->_schedule_team_manager);
        $this->assertSame([], $out[3]->_schedule_team_names);
    }

    public function testTeamsAreGroupedByNameAndAManagerUnderTheTeamTheyManage(): void
    {
        $teams = [1 => ['name' => 'Zulu', 'manager' => 5], 2 => ['name' => 'Alpha', 'manager' => 0]];
        // 5 manages Zulu but is only listed as a member of Alpha.
        $members = [5 => [2], 6 => [1], 7 => [2, 1]];
        $out = TeamOrder::arrange([self::emp(5, 'Mona'), self::emp(6, 'Omar'), self::emp(7, 'Ali')], $teams, $members);
        $this->assertSame(['Ali', 'Mona', 'Omar'], self::names($out));
        $this->assertSame(['Alpha', 'Zulu'], $out[0]->_schedule_team_names);
        $this->assertSame('Zulu', $out[1]->_schedule_primary_team);
        $this->assertSame(['Zulu', 'Alpha'], $out[1]->_schedule_team_names);
        $this->assertSame(['Zulu'], $out[1]->_schedule_manager_teams);
    }

    public function testInactiveTeamsAreIgnored(): void
    {
        $out = TeamOrder::arrange([self::emp(1, 'A')], [], [1 => [99]]);
        $this->assertSame('', $out[0]->_schedule_primary_team);
    }

    public function testWeekLabel(): void
    {
        $this->assertSame('This week', TeamOrder::weekLabel('2026-09-27', '2026-09-27'));
        $this->assertSame('Next week', TeamOrder::weekLabel('2026-10-04', '2026-09-27'));
        $this->assertSame('Previous week', TeamOrder::weekLabel('2026-09-20', '2026-09-27'));
        $this->assertNull(TeamOrder::weekLabel('2026-10-11', '2026-09-27'));
        // Across a daylight-saving change the distance still rounds to whole days.
        $this->assertSame('Next week', TeamOrder::weekLabel('2026-11-01', '2026-10-25'));
    }
}
