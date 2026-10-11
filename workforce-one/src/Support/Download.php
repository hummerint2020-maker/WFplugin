<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * Sends a generated file (PDF, Excel) as the whole response.
 *
 * Cache, minify and compression plugins (and some hosts) wrap the response in an output buffer
 * that rewrites it. A file sent through such a buffer arrives changed, and when its size no longer
 * matches Content-Length the browser waits for the missing bytes until the host's proxy gives up
 * (reported as "The web page loads very slowly … 32000ms"). So every buffer is dropped first, and
 * Content-Length is only sent when nothing can change the bytes on the way out.
 */
final class Download
{
    public static function send(string $body, string $type, string $filename): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        if (!headers_sent()) {
            @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky -- binary file, must not be re-compressed
            if (function_exists('nocache_headers')) nocache_headers();
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
            header('X-Content-Type-Options: nosniff');
            if (!ini_get('zlib.output_compression') && ob_get_level() === 0) header('Content-Length: ' . strlen($body));
        }
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file
        flush();
    }

    /**
     * Sends a stored image to be shown (an <img>, a tap that opens it), not saved as a download: a
     * download is ignored by the installed app on phones, and without caching the photo is fetched
     * again on every visit. Cached privately (only this browser), as the file never changes.
     */
    public static function image(string $body, string $type, string $filename, int $maxAge = 86400): void
    {
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        if (!headers_sent()) {
            @ini_set('zlib.output_compression', 'Off'); // phpcs:ignore WordPress.PHP.IniSet.Risky -- binary file, must not be re-compressed
            header_remove('Pragma');
            header_remove('Expires');
            header('Cache-Control: private, max-age=' . max(0, $maxAge));
            header('Content-Type: ' . $type);
            header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $filename) . '"');
            header('X-Content-Type-Options: nosniff');
            if (!ini_get('zlib.output_compression') && ob_get_level() === 0) header('Content-Length: ' . strlen($body));
        }
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file
        flush();
    }
}
