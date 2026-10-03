<?php
namespace WorkforceOne\Reports;

if (!defined('ABSPATH')) exit;

/**
 * A manager's saved report views: a name and the report's filters. A quick range (This Month…)
 * is kept as the range, so the view always opens on the current period; other periods keep their
 * dates. Pure.
 */
final class SavedViews
{
    public const MAX = 12;
    public const RANGES = ['today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month'];
    private const FILTERS = ['report_type', 'team', 'employee', 'employee_status', 'status'];

    /**
     * @param array<int,array<string,string>> $views
     * @param array<string,mixed> $filters report_type, team, employee, employee_status, status, start, end
     * @param string $range one of RANGES when the period is that quick range, else ''
     * @return array<int,array<string,string>> the views with this one saved last (same name: replaced)
     */
    public static function add(array $views, string $name, array $filters, string $range): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') return $views;
        $name = mb_substr($name, 0, 60);
        $view = ['id' => self::id($name), 'name' => $name, 'range' => in_array($range, self::RANGES, true) ? $range : ''];
        foreach (self::FILTERS as $k) $view[$k] = (string) ($filters[$k] ?? '');
        if ($view['range'] === '') { $view['start'] = (string) ($filters['start'] ?? ''); $view['end'] = (string) ($filters['end'] ?? ''); }
        $views = self::remove($views, $view['id']);
        $views[] = $view;
        return array_slice(array_values($views), -self::MAX);
    }

    /** @param array<int,array<string,string>> $views */
    public static function remove(array $views, string $id): array
    {
        return array_values(array_filter($views, static function ($v) use ($id) { return is_array($v) && ($v['id'] ?? '') !== $id; }));
    }

    /**
     * The query arguments that open a view.
     * @param array<string,string> $view
     * @param array<string,array{0:string,1:string}> $ranges RANGES key => [start, end] today
     * @return array<string,string>
     */
    public static function args(array $view, array $ranges): array
    {
        $args = [];
        foreach (self::FILTERS as $k) if (($view[$k] ?? '') !== '') $args[$k] = (string) $view[$k];
        if (($view['range'] ?? '') !== '' && isset($ranges[$view['range']])) [$args['start'], $args['end']] = $ranges[$view['range']];
        elseif (($view['start'] ?? '') !== '') { $args['start'] = (string) $view['start']; $args['end'] = (string) ($view['end'] ?? $view['start']); }
        return $args;
    }

    public static function id(string $name): string
    {
        return substr(md5(mb_strtolower($name)), 0, 10);
    }
}
