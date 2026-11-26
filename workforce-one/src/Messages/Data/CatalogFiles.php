<?php
namespace WorkforceOne\Messages\Data;

if (!defined('ABSPATH')) exit;

/**
 * The shipped files the System messages page lists from: languages/workforce-one-catalog.php (made by
 * tools/build_message_catalog.php) and the Arabic translations WordPress itself loads (workforce-one-ar.l10n.php).
 * Read only for the page and for a save, never on other requests.
 */
final class CatalogFiles
{
    /** @var string */
    private $dir;
    /** @var array<string,mixed>|null */
    private $catalog;
    /** @var array<string,string>|null */
    private $arabic;

    public function __construct(string $languagesDir)
    {
        $this->dir = rtrim($languagesDir, '/');
    }

    /** @return array<string,array{m:string,r:list<string>,n:int,c:string,p:int,j:int,k:string}> */
    public function catalog(): array
    {
        if ($this->catalog === null) {
            $f = $this->dir . '/workforce-one-catalog.php';
            $c = is_readable($f) ? include $f : [];
            $this->catalog = is_array($c) ? $c : [];
        }
        return $this->catalog;
    }

    /** The texts of the plugin's scripts (the keys of the shipped script translations). @return array<string,bool> */
    public function scriptKeys(): array
    {
        $out = [];
        foreach (glob($this->dir . '/workforce-one-*.json') ?: [] as $f) {
            $j = json_decode((string) file_get_contents($f), true);
            foreach (array_keys((array) ($j['locale_data']['messages'] ?? [])) as $k) if ($k !== '') $out[(string) $k] = true;
        }
        return $out;
    }

    /** @return array<string,string> key => Arabic */
    public function arabic(): array
    {
        if ($this->arabic === null) {
            $f = $this->dir . '/workforce-one-ar.l10n.php';
            $l = is_readable($f) ? include $f : [];
            $m = is_array($l) && is_array($l['messages'] ?? null) ? $l['messages'] : [];
            $this->arabic = [];
            foreach ($m as $k => $v) {
                // Plural translations are joined by "\0"; the first form is enough for the list.
                $v = is_array($v) ? (string) reset($v) : (string) $v;
                $this->arabic[(string) $k] = explode("\0", $v)[0];
            }
            // The scripts' texts are translated in their own JSON files (wp.i18n), not in the .l10n.php.
            foreach (glob($this->dir . '/workforce-one-ar-*.json') ?: [] as $f) {
                $j = json_decode((string) file_get_contents($f), true);
                foreach ((array) ($j['locale_data']['messages'] ?? []) as $k => $v) {
                    if ($k !== '' && is_array($v) && isset($v[0]) && !isset($this->arabic[(string) $k])) $this->arabic[(string) $k] = (string) $v[0];
                }
            }
        }
        return $this->arabic;
    }
}
