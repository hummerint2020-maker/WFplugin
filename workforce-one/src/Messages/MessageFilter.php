<?php
namespace WorkforceOne\Messages;

if (!defined('ABSPATH')) exit;

/**
 * Shows the edited texts (3.31.165): the callbacks of the gettext filters of the workforce-one domain only.
 * A text the admin edited for the current language replaces the translation; any other is left as it is.
 * Registered only while the feature is on and something was edited (EWS_System_Messages_Trait::system_messages_boot()).
 * The edits of each site are read once (switch_to_blog() reads that site's own, or none when its switch is off).
 */
final class MessageFilter
{
    /** The scripts whose wp.i18n texts are the plugin's. */
    public const HANDLES = ['workforce-one', 'workforce-one-time'];

    /** @var callable():int */
    private $site;
    /** @var callable(int):?array */
    private $mapFor;
    /** @var callable():string */
    private $locale;
    /** @var array<int,array<string,array<string,string>>|null> */
    private $maps = [];
    /** @var array<int,string> site => language bucket (a site may have its own language) */
    private $buckets = [];
    /** @var bool */
    private $busy = false;
    /** @var callable():array<string,bool> */
    private $scriptKeys;

    /**
     * @param callable():int $site the current site (blog) id
     * @param callable(int):?array $mapFor that site's edits (lang => key => text), or null when the feature is off there
     * @param callable():string $locale the locale texts are shown in
     * @param callable():array<string,bool> $scriptKeys the texts of the plugin's scripts (read only when a script needs them)
     */
    public function __construct(callable $site, callable $mapFor, callable $locale, callable $scriptKeys)
    {
        $this->scriptKeys = $scriptKeys;
        $this->site = $site;
        $this->mapFor = $mapFor;
        $this->locale = $locale;
    }

    /** gettext_workforce-one */
    public function gettext($translation, $text, $domain = 'workforce-one')
    {
        return $this->find((string) $text, $translation);
    }

    /** gettext_with_context_workforce-one */
    public function gettextWithContext($translation, $text, $context, $domain = 'workforce-one')
    {
        return $this->find(MessageRules::key((string) $context, (string) $text), $translation);
    }

    /** @param mixed $translation @return mixed */
    private function find(string $key, $translation)
    {
        $edits = $this->edits();
        return ($edits !== null && isset($edits[$key])) ? $edits[$key] : $translation;
    }

    /** The current language's edits, or null. @return array<string,string>|null */
    private function edits(): ?array
    {
        if ($this->busy) return null;
        $this->busy = true;   // nothing below may translate, but a filter of another plugin might
        try {
            $site = (int) ($this->site)();
            if (!array_key_exists($site, $this->maps)) $this->maps[$site] = ($this->mapFor)($site);
            $map = $this->maps[$site];
            if (!$map) return null;
            return $map[$this->bucketOf($site)] ?? null;
        } finally {
            $this->busy = false;
        }
    }

    private function bucketOf(int $site): string
    {
        if (!isset($this->buckets[$site])) $this->buckets[$site] = MessageRules::bucket((string) ($this->locale)());
        return $this->buckets[$site];
    }

    /** change_locale, set_current_user: the language may have changed. */
    public function forgetLocale(): void
    {
        $this->buckets = [];
    }

    /**
     * load_script_translations: the edited texts of the plugin's scripts go into their Arabic JSON.
     * @param mixed $json @param mixed $file @param mixed $handle @param mixed $domain @return mixed
     */
    public function scriptTranslations($json, $file, $handle, $domain)
    {
        if ($domain !== 'workforce-one' || !in_array($handle, self::HANDLES, true) || !is_string($json)) return $json;
        $edits = $this->edits();
        if (!$edits) return $json;
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['locale_data']['messages']) || !is_array($data['locale_data']['messages'])) return $json;
        $changed = false;
        foreach ($data['locale_data']['messages'] as $key => $v) {
            if ($key !== '' && isset($edits[$key])) { $data['locale_data']['messages'][$key] = [$edits[$key]]; $changed = true; }
        }
        // Edited script texts this file has no translation for (the edit is the first one).
        $jsKeys = ($this->scriptKeys)();
        foreach ($edits as $key => $text) {
            if (isset($jsKeys[$key]) && !isset($data['locale_data']['messages'][$key])) { $data['locale_data']['messages'][$key] = [$text]; $changed = true; }
        }
        return $changed ? (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json;
    }

    /**
     * pre_load_script_translations: on an English site the plugin ships no script translations, so the edited English
     * texts of its scripts are given as their own (the first file WordPress asks for only).
     * @param mixed $pre @param mixed $file @param mixed $handle @param mixed $domain
     * @return mixed
     */
    public function preScriptTranslations($pre, $file, $handle, $domain)
    {
        if ($pre !== null || $domain !== 'workforce-one' || !in_array($handle, self::HANDLES, true)) return $pre;
        if ($this->bucketOf((int) ($this->site)()) !== 'en') return $pre;
        $edits = $this->edits();
        if (!$edits) return $pre;
        $jsKeys = ($this->scriptKeys)();
        $messages = ['' => ['domain' => 'messages', 'lang' => 'en']];
        foreach ($edits as $key => $text) if (isset($jsKeys[$key])) $messages[$key] = [$text];
        if (count($messages) === 1) return $pre;
        return (string) json_encode(['domain' => 'messages', 'locale_data' => ['messages' => $messages]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
