<?php
namespace WorkforceOne\Ui;

if (!defined('ABSPATH')) exit;

/**
 * Query keys that carry a one-time result of a form (dw_added, overtime_done, cx_error…): shown once, then dropped from
 * the menu links and from the address bar. Pure. The same rule is in assets/js/workforce-one.js (ewsOnceKeys).
 */
final class OnceKeys
{
    public const PATTERN = '/^(?:[a-z0-9]+_)*(?:error|sent|done|saved|added|moved|paid|adv|change|retry|success|rejected|updated|reset|conflict|imported)$/';

    /** A key that ends in one of those words but is a real filter, kept in links (Tasks "Show all"). */
    public const KEEP = ['task_done'];

    public static function isOnce(string $key): bool
    {
        return !in_array($key, self::KEEP, true) && (bool) preg_match(self::PATTERN, $key);
    }

    /** Query keys a link to another view keeps: WordPress's own routing and the app's mode. */
    public const ROUTING = ['page_id', 'p', 'lang', 'ews_pwa', 'preview', 'preview_id', 'preview_nonce'];
}
