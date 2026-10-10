<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * The wp-admin menu in sections (3.31.88, owner-approved mockup docs/mockups/admin-menu/): the 32
 * pages grouped into 8 sections, each opened as one menu item with the section's pages as tabs.
 *
 * Every page keeps its slug and stays registered as before (WordPress refuses a page that is not in
 * the menu), so old links, bookmarks and notification links still open it. Pure: which pages a
 * person may open (registered for them) and which features are on are passed in. The names are
 * translated in includes/trait-admin-nav.php.
 */
final class AdminNav
{
    /**
     * Section id => its pages in tab order. A page with a feature shows only while that feature is
     * on (its own page still opens from a direct link).
     * @return array<string,list<array{0:string,1:string}>> section => [[slug, feature or ''], ...]
     */
    public static function sections(): array
    {
        return [
            'home'       => [['ews31', '']],
            'people'     => [['ews31-employees', ''], ['ews31-departments', ''], ['ews31-teams', ''], ['ews31-employee-branches', '']],
            'attendance' => [['ews31-time-report', ''], ['ews31-attendance-insights', ''], ['ews31-corrections', ''], ['ews31-auto-attendance', ''], ['ews31-face-reset-requests', 'face']],
            'requests'   => [['ews31-requests', ''], ['ews31-leaves', ''], ['ews31-approvals', '']],
            'schedule'   => [['ews31-schedule-config', ''], ['ews31-multi-locations', ''], ['ews31-presence-kiosks', '']],
            'pay'        => [['ews31-payroll', ''], ['ews31-daily-workers', '']],
            'engage'     => [['ews31-moments', ''], ['ews31-smart-nudges', ''], ['ews31-achievements', ''], ['ews31-polls', ''], ['ews31-recognition', 'recognition'], ['ews31-tasks', 'tasks']],
            'settings'   => [['ews31-features', ''], ['ews31-notifications', ''], ['ews31-email', ''], ['ews31-roles', ''], ['ews31-appearance', ''],
                             ['ews31-navigation', ''], ['ews31-employee-profile-settings', ''], ['ews31-settings-overview', ''], ['ews31-audit', '']],
        ];
    }

    /**
     * Pages that belong to a section without being a tab (opened from another page): highlighted
     * as that page. @return array<string,string> slug => the tab it belongs to
     */
    public static function belongsTo(): array
    {
        return ['ews31-employee-profile' => 'ews31-employees'];
    }

    /**
     * What one person sees: each section with its visible tabs, in order; a section with none is left
     * out. @param array<string,bool> $open slug => registered for this person
     * @param array<string,bool> $on feature => on
     * @return array<string,list<string>> section => slugs
     */
    public static function visible(array $open, array $on): array
    {
        $out = [];
        foreach (self::sections() as $id => $tabs) {
            $show = [];
            foreach ($tabs as [$slug, $feature]) {
                if (empty($open[$slug])) continue;
                if ($feature !== '' && empty($on[$feature])) continue;
                $show[] = $slug;
            }
            if ($show) $out[$id] = $show;
        }
        return $out;
    }

    /**
     * The section and tab a page is shown under ([section, tab slug]), or null for a page outside
     * the sections. A tab hidden by its feature is still placed in its section.
     * @return array{0:string,1:string}|null
     */
    public static function place(string $slug): ?array
    {
        $slug = self::belongsTo()[$slug] ?? $slug;
        foreach (self::sections() as $id => $tabs) {
            foreach ($tabs as [$s]) if ($s === $slug) return [$id, $s];
        }
        return null;
    }

    /** Every page slug in the sections (the tabs), in order. @return list<string> */
    public static function slugs(): array
    {
        $out = [];
        foreach (self::sections() as $tabs) foreach ($tabs as [$s]) $out[] = $s;
        return $out;
    }
}
