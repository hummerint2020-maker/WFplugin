<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Orders the shared Team Schedule. Pure: no WordPress calls.
 *
 * Each employee is grouped under one primary team: the first team they manage, otherwise their
 * first team by name. Within a team the manager comes first, then the signed-in employee, then
 * everyone else by name. People without a team come last.
 */
final class TeamOrder
{
    /**
     * @param object[] $employees  rows with ->id and ->name; annotated in place with
     *                             _schedule_team_names, _schedule_primary_team, _schedule_team_manager, _schedule_manager_teams
     * @param array<int,array{name:string,manager:int}> $teams   active teams by id
     * @param array<int,int[]> $memberships  team ids per employee id
     * @return object[]
     */
    public static function arrange(array $employees, array $teams, array $memberships, int $currentId = 0): array
    {
        $byName = static function (int $a, int $b) use ($teams): int {
            return strcasecmp($teams[$a]['name'], $teams[$b]['name']);
        };
        $managed = [];
        foreach ($teams as $tid => $team) {
            if ((int) $team['manager'] > 0) $managed[(int) $team['manager']][] = (int) $tid;
        }
        foreach ($employees as $e) {
            $eid = (int) $e->id;
            $teamIds = array_values(array_unique(array_filter(array_map('intval', $memberships[$eid] ?? []), static function ($t) use ($teams) { return isset($teams[$t]); })));
            usort($teamIds, $byName);
            $managedIds = $managed[$eid] ?? [];
            usort($managedIds, $byName);
            // A manager is grouped under the (first) team they manage, even if not listed as a member.
            if ($managedIds) $teamIds = array_values(array_unique(array_merge([$managedIds[0]], $teamIds)));
            $primary = $teamIds[0] ?? null;
            $e->_schedule_team_names = array_map(static function ($t) use ($teams) { return $teams[$t]['name']; }, $teamIds);
            $e->_schedule_primary_team = $primary !== null ? $teams[$primary]['name'] : '';
            $e->_schedule_team_manager = $primary !== null && (int) $teams[$primary]['manager'] === $eid ? 1 : 0;
            $e->_schedule_manager_teams = array_map(static function ($t) use ($teams) { return $teams[$t]['name']; }, $managedIds);
        }
        usort($employees, static function ($a, $b) use ($currentId): int {
            $ga = (string) $a->_schedule_primary_team;
            $gb = (string) $b->_schedule_primary_team;
            if (($ga === '') !== ($gb === '')) return $ga === '' ? 1 : -1;
            $c = strcasecmp($ga, $gb);
            if ($c !== 0) return $c;
            if ($a->_schedule_team_manager !== $b->_schedule_team_manager) return $b->_schedule_team_manager <=> $a->_schedule_team_manager;
            $ca = (int) $a->id === $currentId ? 1 : 0;
            $cb = (int) $b->id === $currentId ? 1 : 0;
            if ($ca !== $cb) return $cb <=> $ca;
            return strcasecmp((string) $a->name, (string) $b->name);
        });
        return $employees;
    }

    /**
     * "This week" / "Next week" / "Previous week" for the shown week relative to today's week,
     * or null for any other week (the page then shows the date range).
     */
    public static function weekLabel(string $shownWeekStart, string $currentWeekStart): ?string
    {
        if ($shownWeekStart === $currentWeekStart) return 'This week';
        $days = (int) round((strtotime($shownWeekStart . ' 12:00:00') - strtotime($currentWeekStart . ' 12:00:00')) / 86400);
        if ($days === 7) return 'Next week';
        if ($days === -7) return 'Previous week';
        return null;
    }
}
