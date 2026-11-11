<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * What differs between the sites of a WordPress network (3.31.147, docs/tasks/saas.md §3): a private folder per site, a user setting per site,
 * the Nginx rule for the network's upload folders. Every method returns today's value for a single site (and for a network's main site, where
 * it has a path), so nothing changes where the plugin runs on its own. Pure: no WordPress calls; the callers pass is_multisite() and the rest.
 * Loaded by uninstall.php without the plugin's autoloader: no other class is used.
 */
final class SiteScope
{
    /** The private-files base for one site: the configured base on a single site and on the main site, {base}/sites/{blog id} on the other sites; '' (uploads) stays ''. */
    public static function privateBase(string $base, bool $multisite, bool $mainSite, int $blogId): string
    {
        if ($base === '' || !$multisite || $mainSite) return $base;
        return $base . '/sites/' . $blogId;
    }

    /** A user-meta key kept per site: the key itself on a single site, the site's table prefix before it on a network (as update_user_option does). */
    public static function userKey(string $key, bool $multisite, string $blogPrefix): string
    {
        return $multisite ? $blogPrefix . $key : $key;
    }

    /** The Nginx rule shown to the administrator; on a network the upload folders of the other sites are under uploads/sites/{id}/. */
    public static function nginxRule(bool $multisite): string
    {
        return 'location ~* ^/wp-content/uploads/' . ($multisite ? '(sites/\d+/)?' : '') . 'workforce-one-(corrections|tasks|daily-workers|face-photos)/ { deny all; }';
    }
}
