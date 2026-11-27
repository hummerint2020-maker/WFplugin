<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * Skeleton loading (3.31.168, docs/tasks/skeleton-loading.md): the grey outline shown in
 * the app's content while the next view loads after a menu tap. Which outline a view gets; the outlines
 * themselves are drawn by assets/js/skeleton.js. Pure: no WordPress calls.
 */
final class Skeleton
{
    /** The outlines assets/js/skeleton.js knows. */
    public const SHAPES = ['list', 'approvals', 'table', 'generic'];

    /** @var array<string, string> view key => outline; any other view gets 'generic'. */
    private const BY_VIEW = [
        'approvals' => 'approvals',
        'schedule' => 'table',
        'attendance' => 'table',
        'requests' => 'list',
        'vacation' => 'list',
        'overtime' => 'list',
        'corrections' => 'list',
        'tasks' => 'list',
        'notifications' => 'list',
        'people' => 'list',
        'employees' => 'list',
        'polls' => 'list',
        'sites' => 'list',
        'payout' => 'list',
    ];

    public static function shape(string $view): string
    {
        return self::BY_VIEW[$view] ?? 'generic';
    }
}
