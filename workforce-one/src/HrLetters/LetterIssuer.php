<?php
namespace WorkforceOne\HrLetters;

use WorkforceOne\HrLetters\Data\LetterQuery;
use WorkforceOne\HrLetters\Data\LetterStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;

if (!defined('ABSPATH')) exit;

/**
 * Delivering a decided letter (HL3, docs/tasks/hr-letter-hl3.md §4): the PDF made from the type's template, a file HR uploads, or
 * request only; regenerating and replacing; the preview; the download. The salary is read only while a letter is made, from Payroll,
 * never stored nor written to the Audit Log. A letter that cannot be made stays approved with the reason, and HR is told.
 * No SQL, no clock but the Clock port, no current user but the one given.
 */
final class LetterIssuer
{
    /** @var LetterStore */
    private $store;
    /** @var LetterQuery */
    private $query;
    /** @var LetterPorts */
    private $ports;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;

    public function __construct(LetterStore $store, LetterQuery $query, LetterPorts $ports, AuditLog $audit, Clock $clock)
    {
        $this->store = $store;
        $this->query = $query;
        $this->ports = $ports;
        $this->audit = $audit;
        $this->clock = $clock;
    }

    /** Once a letter is decided (approved): made at once (PDF), or HR is told to upload it or to prepare it. */
    public function afterApproval(int $id): void
    {
        $l = $this->store->find($id);
        if (!$l || (string) $l->status !== 'approved') return;
        if ((string) $l->delivery === 'pdf') { $this->issue($id, 0); return; }
        $this->ports->notices->hr((string) $l->delivery === 'upload' ? 'waiting_file' : 'request_only', $l);
        $this->ports->notices->employee('preparing', $l);
    }

    /** Makes a decided letter's PDF and attaches it. @return string '' when issued, else template_missing | no_salary | render | write | done */
    public function issue(int $id, int $by): string
    {
        $f = $this->query->facts($id);
        if (!$f || (string) $f->status !== 'approved' || (string) ($f->file_key ?? '') !== '') return 'done';
        [$pdf, $error] = $this->make($f);
        if ($error !== '') return $this->failed($f, $error);
        $key = $this->ports->files->store($pdf, 'pdf');
        if ($key === '') return $this->failed($f, 'write');
        $file = ['key' => $key, 'name' => LetterRules::reference((int) $f->id, (string) $f->requested_at) . '.pdf', 'source' => 'generated', 'size' => strlen($pdf)];
        if ($this->store->claimIssued($id, $file, $by, $this->clock->now()) !== 1) { $this->ports->files->delete($key); return 'done'; }
        $this->audit->record('hr_letter_issued', 'hr_letter', $id, (string) $f->name . ': ' . (string) $f->type_label . ' (' . (string) $f->language . '), generated');
        $this->ports->notices->employee('ready', $f);
        return '';
    }

    /** A PDF type's letter made again from today's template and data (a letter that could not be made, or an issued one). @return string '' | an issue() error | access */
    public function regenerate(int $id, int $by): string
    {
        $f = $this->query->facts($id);
        if (!$f || !LetterRules::mayRegenerate($f)) return 'access';
        if ((string) $f->status === 'approved') return $this->issue($id, $by);
        [$pdf, $error] = $this->make($f);
        if ($error !== '') return $error;
        $key = $this->ports->files->store($pdf, 'pdf');
        if ($key === '') return 'write';
        $old = (string) $f->file_key;
        $file = ['key' => $key, 'name' => LetterRules::reference((int) $f->id, (string) $f->requested_at) . '.pdf', 'source' => 'generated', 'size' => strlen($pdf)];
        if ($this->store->replaceFile($id, $old, $file, $by, $this->clock->now()) !== 1) { $this->ports->files->delete($key); return 'done'; }
        $this->ports->files->delete($old);
        $this->audit->record('hr_letter_regenerated', 'hr_letter', $id, (string) $f->name . ': ' . (string) $f->type_label . ' made again');
        $this->ports->notices->employee('updated', $f);
        return '';
    }

    /**
     * HR attaches a PDF to a decided letter, or replaces the one there (the latest file only).
     * @param mixed $upload an entry of $_FILES
     * @return string '' | access | file | size | not_pdf | write | done
     */
    public function upload(int $id, $upload, int $by): string
    {
        $l = $this->store->find($id);
        if (!$l || !LetterRules::mayUpload($l)) return 'access';
        $up = $this->ports->files->uploadPdf($upload, LetterRules::MAX_UPLOAD);
        if ($up['error'] !== '') return $up['error'];
        $file = ['key' => $up['key'], 'name' => LetterRules::reference((int) $l->id, (string) $l->requested_at) . '.pdf', 'source' => 'uploaded', 'size' => $up['size']];
        $now = $this->clock->now();
        if ((string) $l->status === 'approved') {
            if ($this->store->claimIssued($id, $file, $by, $now) !== 1) { $this->ports->files->delete($up['key']); return 'done'; }
            $this->audit->record('hr_letter_uploaded', 'hr_letter', $id, $this->who($l) . ': ' . (string) $l->type_label . ' (' . (string) $l->language . '), uploaded');
            $this->ports->notices->employee('ready', $l);
            return '';
        }
        $old = (string) $l->file_key;
        if ($this->store->replaceFile($id, $old, $file, $by, $now) !== 1) { $this->ports->files->delete($up['key']); return 'done'; }
        $this->ports->files->delete($old);
        $this->audit->record('hr_letter_replaced', 'hr_letter', $id, $this->who($l) . ': ' . (string) $l->type_label . ', file replaced');
        $this->ports->notices->employee('updated', $l);
        return '';
    }

    /**
     * A letter as it would be made today, not saved (HR's preview on Types & templates, and the direct letter's Preview).
     * @return array{pdf:string,error:string} error: employee | template_missing | no_salary | render
     */
    public function preview(int $employeeId, string $typeLabel, string $language, string $template, string $addressee): array
    {
        $e = $this->query->employee($employeeId);
        if (!$e) return ['pdf' => '', 'error' => 'employee'];
        $f = (object) ((array) $e + ['id' => 0, 'requested_at' => $this->clock->now(), 'language' => $language, 'addressee' => $addressee, 'type_label' => $typeLabel, 'other_text' => '', 'employee_id' => $employeeId]);
        [$pdf, $error] = $this->make($f, $template);
        return ['pdf' => $pdf, 'error' => $error];
    }

    /**
     * A letter's file for a viewer: the employee (their own, while letters are on) or HR (any time). The owner's first download and every
     * other person's download are in the Audit Log.
     * @return array{bytes:string,name:string,error:string} error: access | missing
     */
    public function download(int $id, int $viewerEmployeeId, bool $canManage, string $viewerName): array
    {
        $l = $this->store->find($id);
        $none = ['bytes' => '', 'name' => '', 'error' => 'access'];
        if (!$l || !LetterRules::mayDownload($l, $viewerEmployeeId, $canManage, $this->ports->site->enabled())) return $none;
        $bytes = $this->ports->files->read((string) $l->file_key);
        if ($bytes === null) return ['error' => 'missing'] + $none;
        if ($viewerEmployeeId > 0 && (int) $l->employee_id === $viewerEmployeeId) {
            if ($this->store->markDownloaded($id, $this->clock->now()) === 1) $this->audit->record('hr_letter_downloaded', 'hr_letter', $id, 'First download by the employee');
        } else {
            $this->audit->record('hr_letter_downloaded', 'hr_letter', $id, 'Downloaded by ' . $viewerName);
        }
        return ['bytes' => $bytes, 'name' => LetterRules::reference((int) $l->id, (string) $l->requested_at) . '.pdf', 'error' => ''];
    }

    /**
     * The letter's PDF from its facts (and a template, or the type's own in the letter's language today).
     * @return array{0:string,1:string} [pdf, error]
     */
    private function make(object $f, ?string $template = null): array
    {
        $site = $this->ports->site;
        $lang = (string) $f->language === 'ar' ? 'ar' : 'en';
        if ($template === null) {
            $type = (string) $f->type_key === 'other' ? null : LetterSettings::type($site->types(), (string) $f->type_key);
            $template = $type ? (string) $type['tpl_' . $lang] : '';
        }
        if (trim($template) === '') return ['', 'template_missing'];
        $today = $site->today();
        $salary = null;
        if (LetterTemplate::usesSalary($template)) {
            $salary = $this->ports->salary->on((int) $f->employee_id, $today);
            if ($salary === null) return ['', 'no_salary'];
        }
        $head = $site->letterhead();
        $values = LetterFacts::values($f, $f, $salary, $head, $site->settings(), $site->currencyCode(), $site->companyFallback(), $today);
        $paragraphs = LetterTemplate::render($template, $values, (string) ($f->gender ?? ''));
        $images = [];
        foreach (['logo', 'signature', 'stamp'] as $k) {
            $key = (string) ($head[$k . '_key'] ?? '');
            if ($key !== '') $images[$k] = (string) $this->ports->files->read($key);
        }
        try {
            $pdf = (new LetterPdf($this->ports->files->font()))->render([
                'lang' => $lang, 'company' => $values['company_name'], 'address' => (string) ($head['address_' . $lang] ?? ''), 'ref' => $values['ref_no'],
                'date' => $values['date'], 'date_hijri' => $values['date_hijri'], 'addressee' => $values['addressee'], 'subject' => $values['letter_type'],
                'paragraphs' => $paragraphs, 'signatory_title' => (string) ($head['signatory_title_' . $lang] ?? ''), 'signatory_name' => (string) ($head['signatory_name_' . $lang] ?? ''),
                'footer' => (string) ($head['footer_' . $lang] ?? ''),
            ], $images);
        } catch (\Throwable $e) {
            return ['', 'render'];
        }
        return [$pdf, ''];
    }

    /** A letter that could not be made: it stays approved with the reason, and HR is told. */
    private function failed(object $f, string $error): string
    {
        $this->store->setIssueError((int) $f->id, $error);
        $f->issue_error = $error;
        $this->audit->record('hr_letter_issue_failed', 'hr_letter', (int) $f->id, (string) ($f->name ?? '') . ': ' . $error);
        $this->ports->notices->hr('issue_failed', $f);
        return $error;
    }

    private function who(object $l): string
    {
        $e = $this->query->employee((int) $l->employee_id);
        return $e ? (string) $e->name : '#' . (int) $l->employee_id;
    }
}
