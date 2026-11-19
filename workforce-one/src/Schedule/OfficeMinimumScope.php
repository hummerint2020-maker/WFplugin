<?php
namespace WorkforceOne\Schedule;

if (!defined('ABSPATH')) exit;

/**
 * Office minimum by department (3.31.154, docs/tasks/office-minimum-departments.md): which department's settings a viewer
 * sees, a department's people / teams / managers, and who gets which weekly reminder. A department with its own settings is
 * checked on its own; the company check counts everyone, as before. Pure: no WordPress calls.
 */
final class OfficeMinimumScope
{
    /**
     * The departments with their own settings that are still active, with their names, in name order.
     * @param array<int,mixed> $depts department id => settings
     * @param array<int,string> $active active department id => name
     * @return array<int,string>
     */
    public static function own(array $depts, array $active): array
    {
        $out = array_intersect_key($active, $depts);
        natcasesort($out);
        return $out;
    }

    /**
     * om_people()'s shape for one department: its people, its teams sized among them (each person in their primary team, as
     * the company does), the same team order ("No team" last), and the team managers whose own department it is.
     * @param array{people:array<int,array{name:string,team:int,dept:int}>,teams:array<int,string>,sizes:array<int,int>,managers:array<int,list<string>>} $p
     * @return array{people:array<int,array{name:string,team:int,dept:int}>,teams:array<int,string>,sizes:array<int,int>,managers:array<int,list<string>>}
     */
    public static function people(array $p, int $dept): array
    {
        $people = array_filter($p['people'], static function ($w) use ($dept) { return (int) $w['dept'] === $dept; });
        $count = [];
        foreach ($people as $w) $count[(int) $w['team']] = ($count[(int) $w['team']] ?? 0) + 1;
        $sizes = [];
        $teams = [];
        foreach ($p['teams'] as $k => $name) {
            if (!isset($count[$k])) continue;
            $sizes[$k] = $count[$k];
            $teams[$k] = $name;
        }
        return ['people' => $people, 'teams' => $teams, 'sizes' => $sizes, 'managers' => array_intersect_key($p['managers'], $people)];
    }

    /**
     * The department whose numbers a viewer sees, 0 = the company. An administrator sees the company unless they picked a
     * department with its own settings; anyone else sees their own department when it has its own settings.
     * @param int[] $own departments with their own settings
     */
    public static function forViewer(bool $admin, int $viewerDept, int $picked, array $own): int
    {
        if ($admin) return in_array($picked, $own, true) ? $picked : 0;
        return in_array($viewerDept, $own, true) ? $viewerDept : 0;
    }

    /**
     * The weekly reminder's recipients: 0 => the company's, D => department D's. Each person is in one group only: an
     * administrator, someone without a department or whose department has no settings of its own gets the company's.
     * @param array<int,int> $userDept user id => their department (0 = none)
     * @param int[] $adminIds
     * @param int[] $own departments with their own settings
     * @return array<int,list<int>>
     */
    public static function reminderGroups(array $userDept, array $adminIds, array $own): array
    {
        $out = [];
        foreach ($userDept as $uid => $d) {
            $g = !in_array((int) $uid, $adminIds, true) && in_array((int) $d, $own, true) ? (int) $d : 0;
            $out[$g][] = (int) $uid;
        }
        ksort($out);
        return $out;
    }
}
