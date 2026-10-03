<?php
namespace WorkforceOne\Pwa;

if (!defined('ABSPATH')) exit;

/**
 * The installable app's (PWA) manifest and service-worker settings. Pure: URLs are passed in.
 * Changing a value here changes every installed app on employees' phones: the start URL and the
 * scope are part of how a phone recognises the app, and the cache name decides when the offline
 * files are refreshed.
 */
final class Manifest
{
    public const NAME = 'Employee Hub';
    public const THEME_COLOR = '#101828';

    /**
     * The web app manifest.
     * @param string $appUrl the page the app opens on (the [employee_app] page, or the home page)
     * @param string $scopePath the site's path ('/' for a site at the domain root)
     * @param string $root the plugin's URL, with a trailing slash
     * @return array<string,mixed>
     */
    public static function data(string $appUrl, string $scopePath, string $root): array
    {
        return [
            'name' => self::NAME,
            'short_name' => self::NAME,
            'description' => 'Employee Schedule & Attendance',
            'start_url' => $appUrl . (strpos($appUrl, '?') === false ? '?' : '&') . 'ews_view=time',
            'scope' => $scopePath,
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#ffffff',
            'theme_color' => self::THEME_COLOR,
            'icons' => [
                ['src' => $root . 'assets/icons/workforce-one-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => $root . 'assets/icons/workforce-one-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ];
    }

    /**
     * What the service worker is told: its cache (named after the plugin version, so a new version
     * replaces the offline files), the files it keeps offline and the plugin's assets path.
     * @return array{cache:string,static:string[],icon:string,assets_path:string}
     */
    public static function serviceWorker(string $root, string $rootPath, string $version): array
    {
        return [
            'cache' => 'employee-hub-v' . $version,
            'static' => [
                $root . 'assets/css/workforce-one.css?ver=' . $version,
                $root . 'assets/js/workforce-one.js?ver=' . $version,
                $root . 'assets/icons/workforce-one-180.png',
                $root . 'assets/icons/workforce-one-192.png',
                $root . 'assets/icons/workforce-one-512.png',
            ],
            'icon' => $root . 'assets/icons/workforce-one-192.png',
            'assets_path' => $rootPath . 'assets/',
        ];
    }
}
