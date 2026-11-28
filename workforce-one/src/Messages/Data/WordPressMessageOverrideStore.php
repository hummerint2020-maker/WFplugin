<?php
namespace WorkforceOne\Messages\Data;

if (!defined('ABSPATH')) exit;

/**
 * The edited texts in one option, ews_message_overrides (not autoloaded; the plugin primes it with its other options).
 * The only place that reads or writes it.
 */
final class WordPressMessageOverrideStore implements MessageOverrideStore
{
    public const OPTION = 'ews_message_overrides';

    public function all(): array
    {
        wp_cache_delete(self::OPTION, 'options');
        return self::clean(get_option(self::OPTION, []));
    }

    /** What the gettext filter reads for each request (the primed value, no extra query). @return array<string,array<string,string>> */
    public function cached(): array
    {
        return self::clean(get_option(self::OPTION, []));
    }

    public function put(array $all): void
    {
        $all = self::clean($all);
        if (!$all) delete_option(self::OPTION);
        else update_option(self::OPTION, $all, false);
    }

    /** @param mixed $v @return array<string,array<string,string>> */
    private static function clean($v): array
    {
        $out = [];
        foreach (is_array($v) ? $v : [] as $lang => $edits) {
            if (!in_array($lang, ['ar', 'en'], true) || !is_array($edits)) continue;
            foreach ($edits as $key => $text) {
                if (is_string($text) && $text !== '') $out[$lang][(string) $key] = $text;
            }
        }
        return $out;
    }
}
