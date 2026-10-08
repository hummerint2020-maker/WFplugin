<?php
namespace WorkforceOne\Support;

if (!defined('ABSPATH')) exit;

/**
 * A searchable pick list for long lists (employees, WordPress users) on wp-admin pages: one shared
 * <datalist> per page and a text field per row, instead of a <select> with every option in every row
 * (3,000 employees made 9 million options and ran out of memory, 3.31.71). The field holds
 * "Name · #id"; parse() turns it back into the id. Pure: no WordPress calls.
 */
final class Picker
{
    public static function label(string $name, int $id): string
    {
        return $id > 0 ? trim($name) . ' · #' . $id : '';
    }

    /** '' → 0 (none); "… #12" or "12" → 12; anything else → -1 (typed, not picked from the list). */
    public static function parse(string $ref): int
    {
        $ref = trim($ref);
        if ($ref === '') return 0;
        if (preg_match('/#(\d+)\s*$/', $ref, $m)) return (int) $m[1];
        if (ctype_digit($ref)) return (int) $ref;
        return -1;
    }
}
