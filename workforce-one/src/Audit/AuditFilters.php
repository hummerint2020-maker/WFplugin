<?php
namespace WorkforceOne\Audit;

if (!defined('ABSPATH')) exit;

/** Filters and paging of the Audit Log page. Pure: no WordPress calls. */
final class AuditFilters
{
    public const PER_PAGE = 50;

    public static function date(string $value): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
    }

    /** @return array{0: string, 1: string} valid dates, swapped when entered the wrong way round */
    public static function range(string $from, string $to): array
    {
        $from = self::date($from);
        $to = self::date($to);
        return ($from !== '' && $to !== '' && $to < $from) ? [$to, $from] : [$from, $to];
    }

    /** @return array{page: int, pages: int, offset: int, first: int, last: int} */
    public static function paging(int $total, int $page, int $perPage = self::PER_PAGE): array
    {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        return ['page' => $page, 'pages' => $pages, 'offset' => $offset, 'first' => $total ? $offset + 1 : 0, 'last' => min($offset + $perPage, $total)];
    }
}
