<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * GET /meta: what an app needs before anyone signs in, to connect to this Workforce One backend.
 * Pure: the plugin passes the facts; only the keys listed here can ever be published.
 *
 * Public by design, so it never carries counts (users, employees), security settings beyond what
 * a client must know (HTTPS, token type), database or file system details, or secrets. Nothing in
 * it names the platform the backend runs on.
 */
final class Meta
{
    public const PRODUCT = 'Workforce One';
    public const API_VERSIONS = ['1'];
    /** Oldest app version the server accepts (raised when a security fix needs every app updated). */
    public const MIN_APP_VERSION = '1.0.0';
    public const RECOMMENDED_APP_VERSION = '1.0.0';

    /** Feature flags the app may use to show or hide screens; the server still enforces each one. */
    public const FEATURES = ['breaks', 'face_sign_in', 'qr_sign_in', 'presence_verification', 'overtime', 'tasks', 'achievements', 'recognition', 'my_pay'];

    /**
     * @param array{site_id:string,site_name:string,site_url:string,timezone:string,locale:string,
     *   server_version:string,features:array<string,bool>,app_name:string,theme_color:string,icon:string} $f
     * @return array<string,mixed>
     */
    public static function document(array $f): array
    {
        $features = [];
        foreach (self::FEATURES as $name) $features[$name] = !empty($f['features'][$name]);
        return [
            'product' => ['name' => self::PRODUCT],
            'site' => ['id' => $f['site_id'], 'name' => $f['site_name'], 'url' => $f['site_url'], 'timezone' => $f['timezone'], 'locale' => $f['locale']],
            'api' => ['version' => self::API_VERSIONS[count(self::API_VERSIONS) - 1], 'versions' => self::API_VERSIONS],
            'server' => ['version' => $f['server_version']],
            'app' => ['min_version' => self::MIN_APP_VERSION, 'recommended_version' => self::RECOMMENDED_APP_VERSION],
            'auth' => ['type' => 'bearer', 'https_required' => true, 'access_token_ttl' => Auth\Tokens::ACCESS_TTL],
            'features' => $features,
            'branding' => ['app_name' => $f['app_name'], 'theme_color' => $f['theme_color'], 'icon' => $f['icon'] !== '' ? $f['icon'] : null],
        ];
    }
}
