<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * Every REST route of the plugin, in one table, under one versioned namespace
 * (/wp-json/workforce-one/v1/...). Routes are only ever added within v1; a breaking change gets /v2/.
 *
 * Today the table holds the four Face routes the Web Sign In page uses (moved here unchanged from
 * face_rest_routes(): same paths, methods, handlers and cookie + X-WP-Nonce authentication).
 */
final class Routes
{
    public const NAMESPACE = 'workforce-one/v1';

    /** @return array<int,array{path:string,methods:string,handler:string,access:string}> */
    public static function table(): array
    {
        return [
            ['path' => '/face/enroll', 'methods' => 'POST', 'handler' => 'face_rest_enroll', 'access' => 'logged_in'],
            ['path' => '/face/verify', 'methods' => 'POST', 'handler' => 'face_rest_verify', 'access' => 'logged_in'],
            ['path' => '/face/reset-request', 'methods' => 'POST', 'handler' => 'face_rest_reset_request', 'access' => 'logged_in'],
            ['path' => '/face/delete', 'methods' => 'POST', 'handler' => 'face_rest_delete', 'access' => 'logged_in'],
        ];
    }

    /** @param object $plugin the plugin instance that implements the handlers */
    public static function register($plugin): void
    {
        foreach (self::table() as $route) {
            register_rest_route(self::NAMESPACE, $route['path'], [
                'methods' => $route['methods'],
                'callback' => [$plugin, $route['handler']],
                'permission_callback' => self::access($route['access']),
            ]);
        }
    }

    /** Who may call a route. Handlers still check what the caller may do (employee, capability). */
    public static function access(string $kind): callable
    {
        switch ($kind) {
            case 'logged_in':
                return static function () { return is_user_logged_in(); };
        }
        throw new \InvalidArgumentException('Unknown route access: ' . $kind);
    }
}
