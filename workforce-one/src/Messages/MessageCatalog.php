<?php
namespace WorkforceOne\Messages;

if (!defined('ABSPATH')) exit;

/**
 * The texts of the plugin as the System messages page lists them (3.31.166). Pure: the catalog
 * (languages/workforce-one-catalog.php) and the shipped Arabic translations are given to it.
 */
final class MessageCatalog
{
    public const PER_PAGE = 50;

    /** @var array<string,array{m:string,r:list<string>,n:int,c:string,p:int,j:int,k:string}> */
    private $rows;
    /** @var array<string,string> key => Arabic */
    private $arabic;

    /**
     * @param array<string,array{m:string,r:list<string>,n:int,c:string,p:int,j:int,k:string}> $rows
     * @param array<string,string> $arabic
     */
    public function __construct(array $rows, array $arabic)
    {
        $this->rows = $rows;
        $this->arabic = $arabic;
    }

    public function has(string $key): bool
    {
        return isset($this->rows[$key]);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** The key of a row from its hash (md5 of the key, as the page links to it). */
    public function byHash(string $hash): ?string
    {
        foreach ($this->rows as $key => $_) if (md5((string) $key) === $hash) return (string) $key;
        return null;
    }

    /** The text shown in a language when nothing is edited: the shipped Arabic translation, or the English original. */
    public function defaultText(string $lang, string $key): string
    {
        $original = MessageRules::split($key)[1];
        if ($lang !== 'ar') return $original;
        $ar = $this->arabic[$key] ?? '';
        return $ar !== '' ? $ar : $original;
    }

    public function kind(string $key): string
    {
        $r = $this->rows[$key] ?? null;
        return ImportantMessages::kind($key, $r ? (string) $r['k'] : 'ui');
    }

    public function plural(string $key): bool
    {
        return !empty($this->rows[$key]['p']);
    }

    public function javascript(string $key): bool
    {
        return !empty($this->rows[$key]['j']);
    }

    /** @return list<string> */
    public function modules(): array
    {
        $m = array_values(array_unique(array_map(function ($r) { return (string) $r['m']; }, $this->rows)));
        sort($m);
        return $m;
    }

    /** @return list<string> */
    public function keysOfModule(string $module): array
    {
        $out = [];
        foreach ($this->rows as $key => $r) if ($r['m'] === $module) $out[] = (string) $key;
        return $out;
    }

    /** One row as the page shows it. @param array<string,string> $edits the language's saved texts @return array<string,mixed> */
    public function row(string $lang, string $key, array $edits): array
    {
        $r = $this->rows[$key];
        [$context, $original] = MessageRules::split($key);
        $default = $this->defaultText($lang, $key);
        $edited = isset($edits[$key]) && !$r['p'];
        return [
            'key' => $key, 'hash' => md5($key), 'context' => $context, 'original' => $original, 'default' => $default,
            'current' => $edited ? $edits[$key] : $default, 'edited' => $edited, 'module' => $r['m'], 'kind' => $this->kind($key),
            'places' => (int) $r['n'], 'refs' => $r['r'], 'comment' => $r['c'], 'plural' => (bool) $r['p'], 'js' => (bool) $r['j'],
            'labels' => MessageRules::labels((string) $r['c'], $original), 'limit' => MessageRules::limit($this->kind($key), $original),
        ];
    }

    /**
     * The page of rows matching the filters.
     * @param array{lang:string,kind?:string,module?:string,q?:string,edited?:bool} $f
     * @param array<string,string> $edits the language's saved texts
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,pages:int}
     */
    public function listing(array $f, array $edits, int $page, int $per = self::PER_PAGE): array
    {
        $lang = $f['lang'];
        $kind = (string) ($f['kind'] ?? '');
        $module = (string) ($f['module'] ?? '');
        $q = self::lower(trim((string) ($f['q'] ?? '')));
        $onlyEdited = !empty($f['edited']);
        $keys = [];
        foreach ($this->rows as $key => $r) {
            $key = (string) $key;
            if ($module !== '' && $r['m'] !== $module) continue;
            if ($kind !== '' && $this->kind($key) !== $kind) continue;
            if ($onlyEdited && (!isset($edits[$key]) || $r['p'])) continue;
            if ($q !== '') {
                $hay = self::lower(MessageRules::split($key)[1] . "\n" . ($edits[$key] ?? '') . "\n" . $this->defaultText($lang, $key));
                if (strpos($hay, $q) === false) continue;
            }
            $keys[] = $key;
        }
        // Edited first, then the important kinds, then the rest (each in the catalog's order).
        $rank = ['notification' => 1, 'banner' => 1, 'error' => 2, 'ui' => 3];
        $pos = array_flip($keys);
        usort($keys, function ($a, $b) use ($edits, $rank, $pos) {
            $ea = isset($edits[$a]) ? 0 : 1; $eb = isset($edits[$b]) ? 0 : 1;
            if ($ea !== $eb) return $ea - $eb;
            $ka = $rank[$this->kind($a)] ?? 3; $kb = $rank[$this->kind($b)] ?? 3;
            return $ka !== $kb ? $ka - $kb : $pos[$a] - $pos[$b];
        });
        $total = count($keys);
        $pages = max(1, (int) ceil($total / max(1, $per)));
        $page = max(1, min($page, $pages));
        $rows = [];
        foreach (array_slice($keys, ($page - 1) * $per, $per) as $key) $rows[] = $this->row($lang, $key, $edits);
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Saved texts whose original is no longer in the plugin (an update changed or removed it): they no longer apply.
     * @param array<string,array<string,string>> $all lang => key => text
     * @return list<array{lang:string,key:string,original:string,text:string,hash:string}>
     */
    public function needsReview(array $all): array
    {
        $out = [];
        foreach ($all as $lang => $edits) {
            foreach ((array) $edits as $key => $text) {
                $key = (string) $key;
                if (isset($this->rows[$key])) continue;
                $out[] = ['lang' => (string) $lang, 'key' => $key, 'original' => MessageRules::split($key)[1], 'text' => (string) $text, 'hash' => md5($key)];
            }
        }
        return $out;
    }

    private static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }
}
