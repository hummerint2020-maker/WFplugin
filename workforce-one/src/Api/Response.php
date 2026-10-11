<?php
namespace WorkforceOne\Api;

if (!defined('ABSPATH')) exit;

/**
 * The response contract of the Workforce One API (v1). Pure: builds the body and the HTTP status;
 * the controller hands them to WordPress.
 *
 *   success: {"success":true,  "data":{...},  "meta":{"request_id","server_time","api_version"}}
 *   failure: {"success":false, "error":{"code","message","details"}, "meta":{...}}
 *
 * code is a stable machine code (ErrorMap); message is translated text for display only. Database
 * errors, file paths and stack traces never go in a response: an unexpected failure is
 * INTERNAL_ERROR with the request_id, and the rest is logged.
 */
final class Response
{
    public const API_VERSION = '1.0';

    /**
     * @param mixed $data
     * @param array<string,mixed> $meta extra meta (e.g. next_cursor, unread)
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function success($data, string $requestId, string $serverTime, array $meta = [], int $status = 200): array
    {
        return ['status' => $status, 'body' => ['success' => true, 'data' => $data, 'meta' => self::meta($requestId, $serverTime) + $meta]];
    }

    /**
     * An error by its API code (ErrorMap::API_* values); the HTTP status comes from the map.
     * @param array<string,mixed> $details
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function error(string $apiCode, string $message, string $requestId, string $serverTime, array $details = []): array
    {
        $error = ['code' => $apiCode, 'message' => $message];
        if ($details) $error['details'] = $details;
        return ['status' => ErrorMap::status($apiCode), 'body' => ['success' => false, 'error' => $error, 'meta' => self::meta($requestId, $serverTime)]];
    }

    /**
     * A domain failure (SignInRules, BreakRules, LocationAssessment, AttendanceResult code) as an API error.
     * @param array<string,mixed> $details
     * @return array{status:int,body:array<string,mixed>}
     */
    public static function failure(string $domainCode, string $message, string $requestId, string $serverTime, array $details = []): array
    {
        return self::error(ErrorMap::code($domainCode), $message, $requestId, $serverTime, $details);
    }

    /** An unexpected failure: no internals in the response, only the id to find the log entry. */
    public static function internalError(string $requestId, string $serverTime, string $message = 'Something went wrong. Please try again.'): array
    {
        return self::error(ErrorMap::INTERNAL_ERROR, $message, $requestId, $serverTime);
    }

    /** A request id: 26 characters, time-ordered (48-bit milliseconds + 80 random bits, Crockford base32). */
    public static function requestId(int $unixMs, string $random10Bytes): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = $alphabet[$unixMs % 32] . $time;
            $unixMs = intdiv($unixMs, 32);
        }
        $bits = '';
        foreach (str_split(substr(str_pad($random10Bytes, 10, "\0"), 0, 10)) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        $rand = '';
        foreach (str_split($bits, 5) as $chunk) $rand .= $alphabet[bindec($chunk)];
        return $time . $rand;
    }

    /** @return array{request_id:string,server_time:string,api_version:string} */
    private static function meta(string $requestId, string $serverTime): array
    {
        return ['request_id' => $requestId, 'server_time' => $serverTime, 'api_version' => self::API_VERSION];
    }
}
