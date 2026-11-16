<?php
namespace WorkforceOne\HrLetters;

if (!defined('ABSPATH')) exit;

/**
 * The rules of an HR letter request (HL3, docs/tasks/hr-letter-hl3.md §5): what a request may ask for, how it is delivered, when one is
 * still open, and who may do what with it. Pure: no WordPress calls.
 *
 * Statuses: pending (waiting for a decision) → approved (decided; HR or the generator still has to deliver it) → issued (a file is
 * attached) or ready (request only: ready to collect); or rejected, withdrawn (by the employee while waiting), cancelled (by HR while
 * approved and no file is attached).
 */
final class LetterRules
{
    /** A request counts as open until it is issued, ready, rejected, withdrawn or cancelled (owner decision 10). */
    public const OPEN = ['pending', 'approved'];
    public const MAX_UPLOAD = 10485760;   // 10 MB (owner decision Q6)
    public const MAX_IMAGE = 1048576;     // 1 MB

    /** HR-2026-000123: the year the request was made and its number, Western digits in every language (owner decision 8). */
    public static function reference(int $id, string $requestedAt): string
    {
        $y = preg_match('/^\d{4}/', $requestedAt, $m) ? $m[0] : '0000';
        return 'HR-' . $y . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Checks a new request and works out what it will be.
     * @param array<string,mixed> $in type, other_text, addressee, language, note
     * @param list<array<string,mixed>> $types LetterSettings::types()
     * @param array<string,mixed> $settings LetterSettings::settings()
     * @return array{error:string,type_key:string,type_label:string,other_text:string,addressee:string,language:string,note:string,delivery:string,with_salary:int,template:string}
     *   error: type, other_off, other_text, language, note_long ('' when fine)
     */
    public static function check(array $in, array $types, array $settings): array
    {
        $key = LetterSettings::key((string) ($in['type'] ?? ''));
        $lang = in_array($in['language'] ?? '', LetterSettings::LANGUAGES, true) ? (string) $in['language'] : '';
        $other = LetterSettings::text($in['other_text'] ?? '', LetterSettings::OTHER_MAX);
        $rawNote = $in['note'] ?? '';
        $note = trim((string) preg_replace('/[ \t]+/u', ' ', strip_tags(is_scalar($rawNote) ? (string) $rawNote : '')));
        $addressee = LetterSettings::text($in['addressee'] ?? '', LetterSettings::ADDRESSEE_MAX);
        $out = ['error' => '', 'type_key' => $key, 'type_label' => '', 'other_text' => '', 'addressee' => '', 'language' => $lang, 'note' => '',
            'delivery' => 'none', 'with_salary' => 0, 'template' => ''];
        if ($key === 'other') {
            if (!(int) $settings['other']) return ['error' => 'other_off'] + $out;
            if ($other === '') return ['error' => 'other_text'] + $out;
            $out['delivery'] = 'upload';   // "Other" always goes to HR to upload (owner decision Q3)
            $out['other_text'] = $other;
        } else {
            $type = LetterSettings::type($types, $key);
            if (!$type || !(int) $type['active']) return ['error' => 'type'] + $out;
            $out['delivery'] = (string) $type['delivery'];
            if ($lang !== '' && !in_array($lang, self::languagesFor($type, $settings), true)) return ['error' => 'language'] + $out;
            $out['template'] = $lang !== '' && $out['delivery'] === 'pdf' ? (string) $type['tpl_' . $lang] : '';
            $out['with_salary'] = LetterTemplate::usesSalary($out['template']) ? 1 : 0;
        }
        if ($lang === '' || !in_array($lang, (array) $settings['languages'], true)) return ['error' => 'language'] + $out;
        if (mb_strlen($note) > LetterSettings::NOTE_MAX) return ['error' => 'note_long'] + $out;
        $out['note'] = $note;
        $out['addressee'] = $addressee !== '' ? $addressee : (string) $settings['addressee_' . $lang];
        $out['type_label'] = $key === 'other' ? ($lang === 'ar' ? 'أخرى' : 'Other') : LetterSettings::name((array) LetterSettings::type($types, $key), $lang);
        return $out;
    }

    /**
     * The languages a type can be asked in: the site's languages; for a PDF type only those with a template.
     * @param array<string,mixed> $type @param array<string,mixed> $settings @return list<string>
     */
    public static function languagesFor(array $type, array $settings): array
    {
        $out = [];
        foreach ((array) $settings['languages'] as $l) {
            if ((string) $type['delivery'] === 'pdf' && trim((string) ($type['tpl_' . $l] ?? '')) === '') continue;
            $out[] = (string) $l;
        }
        return $out;
    }

    /** The key that keeps one request open per type and language, or null when any number may be open ("Other", or the setting off). */
    public static function openKey(int $employeeId, string $typeKey, string $lang, bool $oneOpen): ?string
    {
        if (!$oneOpen || $typeKey === 'other') return null;
        return $employeeId . ':' . $typeKey . ':' . $lang;
    }

    public static function isOpen(object $letter): bool
    {
        return in_array((string) $letter->status, self::OPEN, true);
    }

    /** The employee may download their own issued letter while the feature is on; HR (and administrators) at any time. */
    public static function mayDownload(object $letter, int $viewerEmployeeId, bool $canManage, bool $enabled): bool
    {
        if ((string) $letter->status !== 'issued' || (string) ($letter->file_key ?? '') === '') return false;
        if ($canManage) return true;
        return $enabled && $viewerEmployeeId > 0 && (int) $letter->employee_id === $viewerEmployeeId;
    }

    public static function mayWithdraw(object $letter, int $employeeId): bool
    {
        return (string) $letter->status === 'pending' && (int) $letter->employee_id === $employeeId;
    }

    /** HR may cancel a decided letter while no file is attached (owner decision on the design). */
    public static function mayCancel(object $letter): bool
    {
        return (string) $letter->status === 'approved' && (string) ($letter->file_key ?? '') === '';
    }

    /** Request only: HR marks it ready to collect (owner decision Q5). */
    public static function mayMarkReady(object $letter): bool
    {
        return (string) $letter->status === 'approved' && (string) $letter->delivery === 'none' && (string) ($letter->file_key ?? '') === '';
    }

    /** HR attaches a file to a decided letter, or replaces the one there (owner decision Q6: the latest file only). */
    public static function mayUpload(object $letter): bool
    {
        return in_array((string) $letter->status, ['approved', 'issued'], true);
    }

    /** A PDF type's letter is made again from today's template and data. */
    public static function mayRegenerate(object $letter): bool
    {
        return (string) $letter->delivery === 'pdf' && in_array((string) $letter->status, ['approved', 'issued'], true);
    }

    /** A PDF file starts with "%PDF-". */
    public static function isPdf(string $head): bool
    {
        return strncmp($head, '%PDF-', 5) === 0;
    }

    /**
     * Where a letter is, for My requests: the state of the shared list (waiting, approved, rejected, withdrawn) and the words' code.
     * @return array{state:string,label:string} label: waiting, ready (download), preparing (HR uploads), collect, being_made, rejected, withdrawn, cancelled
     */
    public static function stateFor(object $letter): array
    {
        switch ((string) $letter->status) {
            case 'pending': return ['state' => 'waiting', 'label' => 'waiting'];
            case 'issued': return ['state' => 'approved', 'label' => 'ready'];
            case 'ready': return ['state' => 'approved', 'label' => 'collect'];
            case 'rejected': return ['state' => 'rejected', 'label' => 'rejected'];
            case 'cancelled': return ['state' => 'rejected', 'label' => 'cancelled'];
            case 'withdrawn': return ['state' => 'withdrawn', 'label' => 'withdrawn'];
        }
        return ['state' => 'approved', 'label' => (string) $letter->delivery === 'pdf' ? 'being_made' : ((string) $letter->delivery === 'none' ? 'preparing_collect' : 'preparing')];
    }
}
