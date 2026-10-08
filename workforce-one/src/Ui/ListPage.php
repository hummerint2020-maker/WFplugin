<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * Long people lists on the app's Team Schedule and Attendance pages (3.31.71). Up to LIMIT people the
 * page shows everyone and filters in the browser, as before. Above it, the search and the team filter
 * run on the server and the page shows PER people at a time, so a week for 3,000 people is not one
 * 14 MB page. Pure: no WordPress calls.
 */
final class ListPage
{
    public const LIMIT = 60;
    public const PER = 50;

    /**
     * @param object[] $people  in display order; name, domain_name and _schedule_team_names are read
     * @param array<string,mixed> $get  the request's query values (q, team, pg)
     * @return array{rows:object[],matched:object[],paged:bool,page:int,pages:int,total:int,all:int,q:string,team:string}
     *   rows = this page; matched = everyone the search and team filter keep (all pages)
     */
    public static function apply(array $people, array $get): array
    {
        $all = count($people);
        $q = trim(self::text($get['q'] ?? ''));
        $team = trim(self::text($get['team'] ?? ''));
        if ($all <= self::LIMIT) {
            return ['rows' => array_values($people), 'matched' => array_values($people), 'paged' => false, 'page' => 1, 'pages' => 1, 'total' => $all, 'all' => $all, 'q' => '', 'team' => ''];
        }
        $needle = self::lower($q);
        $team_l = self::lower($team);
        $rows = array_values(array_filter($people, static function ($p) use ($needle, $team_l) {
            if ($needle !== '' && strpos(self::lower((string) ($p->name ?? '') . ' ' . (string) ($p->domain_name ?? '')), $needle) === false) return false;
            if ($team_l !== '' && $team_l !== 'all') {
                $teams = array_map(static function ($t) { return self::lower(trim((string) $t)); }, (array) ($p->_schedule_team_names ?? []));
                if (!in_array($team_l, $teams, true)) return false;
            }
            return true;
        }));
        $total = count($rows);
        $pages = max(1, (int) ceil($total / self::PER));
        $page = min($pages, max(1, (int) ($get['pg'] ?? 1)));
        return ['rows' => array_slice($rows, ($page - 1) * self::PER, self::PER), 'matched' => $rows, 'paged' => true, 'page' => $page, 'pages' => $pages, 'total' => $total, 'all' => $all, 'q' => $q, 'team' => $team];
    }

    /** @param mixed $v */
    private static function text($v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
