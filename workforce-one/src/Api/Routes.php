<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * Every REST route of the plugin, in one table, under one versioned namespace
 * (/wp-json/workforce-one/v1/...). Routes are only ever added within v1; a breaking change gets /v2/.
 *
 * Two kinds of route:
 * - 'web': the four Face routes the Web Sign In page uses (moved here unchanged in 3.31.46: same
 *   paths, handlers, response shape and WordPress cookie + X-WP-Nonce authentication).
 * - 'native': the API for the apps (3.31.47). Responses use the Api\Response envelope, and access is
 *   one of: 'public' (GET /meta), 'credentials' (login and refresh: HTTPS, no session), 'token'
 *   (HTTPS and a Workforce One access token in the Authorization or X-WFO-Authorization header).
 *   A token is checked only by these routes' permission callback, so it can never sign anyone in
 *   anywhere else (wp-admin, admin-post.php, /wp/v2, other plugins' routes).
 */
final class Routes
{
    public const NAMESPACE = 'workforce-one/v1';

    /** @return array<int,array{path:string,methods:string,handler:string,access:string,kind:string}> */
    public static function table(): array
    {
        return [
            ['path' => '/face/enroll', 'methods' => 'POST', 'handler' => 'face_rest_enroll', 'access' => 'logged_in', 'kind' => 'web'],
            ['path' => '/face/verify', 'methods' => 'POST', 'handler' => 'face_rest_verify', 'access' => 'logged_in', 'kind' => 'web'],
            ['path' => '/face/reset-request', 'methods' => 'POST', 'handler' => 'face_rest_reset_request', 'access' => 'logged_in', 'kind' => 'web'],
            ['path' => '/face/delete', 'methods' => 'POST', 'handler' => 'face_rest_delete', 'access' => 'logged_in', 'kind' => 'web'],
            ['path' => '/meta', 'methods' => 'GET', 'handler' => 'api_meta', 'access' => 'public', 'kind' => 'native'],
            ['path' => '/auth/login', 'methods' => 'POST', 'handler' => 'api_auth_login', 'access' => 'credentials', 'kind' => 'native'],
            ['path' => '/auth/refresh', 'methods' => 'POST', 'handler' => 'api_auth_refresh', 'access' => 'credentials', 'kind' => 'native'],
            ['path' => '/auth/logout', 'methods' => 'POST', 'handler' => 'api_auth_logout', 'access' => 'token', 'kind' => 'native'],
            ['path' => '/me', 'methods' => 'GET', 'handler' => 'api_me', 'access' => 'token', 'kind' => 'native'],
            ['path' => '/me/devices', 'methods' => 'GET', 'handler' => 'api_me_devices', 'access' => 'token', 'kind' => 'native'],
            ['path' => '/me/devices/(?P<id>[a-f0-9]{32})', 'methods' => 'DELETE', 'handler' => 'api_me_device_delete', 'access' => 'token', 'kind' => 'native'],
        ];
    }

    /** @param object $plugin the plugin instance that implements the handlers */
    public static function register($plugin): void
    {
        foreach (self::table() as $route) {
            $handler = $route['handler'];
            $args = [
                'methods' => $route['methods'],
                'permission_callback' => self::access($route['access'], $plugin),
                'wfo_kind' => $route['kind'],
            ];
            $args['callback'] = $route['kind'] === 'native'
                // Native handlers return Response arrays; anything unexpected becomes INTERNAL_ERROR (never a trace).
                ? static function ($request) use ($plugin, $handler) { return $plugin->api_dispatch($handler, $request); }
                : [$plugin, $handler];
            register_rest_route(self::NAMESPACE, $route['path'], $args);
        }
    }

    /**
     * Who may call a route. Handlers still check what the caller may do (employee, capability).
     * @param object|null $plugin
     */
    public static function access(string $kind, $plugin = null): callable
    {
        switch ($kind) {
            case 'logged_in':
                return static function () { return is_user_logged_in(); };
            case 'public':
                return '__return_true';
            case 'credentials':
                return static function ($request) use ($plugin) { return $plugin->api_require_https($request); };
            case 'token':
                return static function ($request) use ($plugin) { return $plugin->api_authenticate($request); };
        }
        throw new \InvalidArgumentException('Unknown route access: ' . $kind);
    }
}
