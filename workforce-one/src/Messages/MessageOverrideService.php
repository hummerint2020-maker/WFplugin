<?php
namespace WorkforceOne\Messages;

use WorkforceOne\Messages\Data\MessageOverrideStore;
use WorkforceOne\Platform\AuditLog;

if (!defined('ABSPATH')) exit;

/**
 * Saving and resetting edited texts (3.31.165, docs/tasks/system-messages.md §5). Every change is one Audit Log row.
 * Codes: '' (saved), reset (the text equals the default: the edit was removed), unknown, plural, stale, and the codes of
 * MessageRules::check().
 */
final class MessageOverrideService
{
    public const LANGS = ['ar', 'en'];

    /** @var MessageOverrideStore */
    private $store;
    /** @var AuditLog */
    private $audit;

    public function __construct(MessageOverrideStore $store, AuditLog $audit)
    {
        $this->store = $store;
        $this->audit = $audit;
    }

    /** @return array<string,array<string,string>> */
    public function all(): array
    {
        return $this->store->all();
    }

    /**
     * Saves one text. $expected is the text the form showed (the current one): when someone saved another meanwhile,
     * nothing is written ('stale').
     * @return array{code:string,text:string}
     */
    public function save(string $lang, string $key, string $text, string $expected, MessageCatalog $catalog): array
    {
        if (!in_array($lang, self::LANGS, true) || !$catalog->has($key)) return ['code' => 'unknown', 'text' => ''];
        if ($catalog->plural($key)) return ['code' => 'plural', 'text' => ''];
        $all = $this->store->all();
        $before = $all[$lang][$key] ?? null;
        $default = $catalog->defaultText($lang, $key);
        if (($before ?? $default) !== $expected) return ['code' => 'stale', 'text' => ''];
        [$clean, $code] = MessageRules::check(MessageRules::split($key)[1], $text, $catalog->kind($key));
        if ($code !== '') return ['code' => $code, 'text' => $clean];
        if ($clean === $default) {
            if ($before === null) return ['code' => 'reset', 'text' => $clean];
            unset($all[$lang][$key]);
            if (empty($all[$lang])) unset($all[$lang]);
            $this->store->put($all);
            $this->audit->record('system_message_reset', 'system_message', 0, self::detail($lang, $key) . '; scope=one; count=1');
            return ['code' => 'reset', 'text' => $clean];
        }
        if ($before === $clean) return ['code' => '', 'text' => $clean];
        $all[$lang][$key] = $clean;
        $this->store->put($all);
        $this->audit->record('system_message_save', 'system_message', 0, self::detail($lang, $key) . '; before=' . self::cut($before ?? $default, 200) . '; after=' . self::cut($clean, 200));
        return ['code' => '', 'text' => $clean];
    }

    /**
     * Back to the default: one text (scope one, $arg = key), a module's texts (module, $arg = module), every text of the
     * language (all) or the saved texts that no longer apply (review). Returns how many were removed.
     */
    public function reset(string $lang, string $scope, string $arg, MessageCatalog $catalog): int
    {
        if (!in_array($lang, self::LANGS, true)) return 0;
        $all = $this->store->all();
        $edits = $all[$lang] ?? [];
        $drop = [];
        foreach ($edits as $key => $_) {
            $key = (string) $key;
            if (($scope === 'one' && $key === $arg) || $scope === 'all' || ($scope === 'review' && !$catalog->has($key))
                || ($scope === 'module' && $catalog->has($key) && in_array($key, $catalog->keysOfModule($arg), true))) $drop[] = $key;
        }
        if (!$drop) return 0;
        foreach ($drop as $key) unset($all[$lang][$key]);
        if (empty($all[$lang])) unset($all[$lang]);
        $this->store->put($all);
        $what = $scope === 'one' ? self::detail($lang, $drop[0]) : 'lang=' . $lang;
        $this->audit->record('system_message_reset', 'system_message', 0, $what . '; scope=' . ($scope === 'module' ? 'module:' . $arg : $scope) . '; count=' . count($drop));
        return count($drop);
    }

    private static function detail(string $lang, string $key): string
    {
        [$ctx, $original] = MessageRules::split($key);
        return 'lang=' . $lang . '; key=' . self::cut(($ctx !== '' ? $ctx . ' | ' : '') . $original, 120);
    }

    private static function cut(string $s, int $max): string
    {
        $s = str_replace(["\r", "\n"], ' ', $s);
        return MessageRules::length($s) > $max ? (function_exists('mb_substr') ? mb_substr($s, 0, $max - 1, 'UTF-8') : substr($s, 0, $max - 1)) . '…' : $s;
    }
}
