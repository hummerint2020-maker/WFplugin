<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\HrLetters\LetterApprovals;
use WorkforceOne\HrLetters\LetterDecisions;
use WorkforceOne\HrLetters\LetterFiles;
use WorkforceOne\HrLetters\LetterIssuer;
use WorkforceOne\HrLetters\LetterNotices;
use WorkforceOne\HrLetters\LetterPorts;
use WorkforceOne\HrLetters\LetterRequests;
use WorkforceOne\HrLetters\LetterSalary;
use WorkforceOne\HrLetters\LetterSettings;
use WorkforceOne\HrLetters\LetterSite;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Tests\Fakes\MemoryLetterStore;

require_once __DIR__ . '/Fakes/MemoryLetterStore.php';

/**
 * HR letters HL3 (docs/tasks/hr-letter-hl3.md §5): the request, the decisions, delivery and download on in-memory stores and fake ports that
 * write to one journal, so the order of the transaction, the approval, the row and the notices is checked.
 */
final class HrLetterServicesTest extends TestCase
{
    /** @var MemoryLetterStore */
    private $store;
    /** @var \ArrayObject<int,string> */
    private $j;
    /** @var object */
    private $site;
    /** @var object */
    private $ap;
    /** @var object */
    private $files;
    /** @var object */
    private $salary;
    /** @var list<string> */
    private $audits = [];
    /** @var LetterIssuer */
    private $issuer;
    /** @var LetterRequests */
    private $requests;
    /** @var LetterDecisions */
    private $decisions;

    protected function setUp(): void
    {
        $j = $this->j = new \ArrayObject();
        $this->store = new MemoryLetterStore();
        $this->store->journal = $j;
        $this->store->employees[7] = (object) ['id' => 7, 'name' => 'Ahmed Samir', 'name_ar' => 'أحمد سمير', 'gender' => 'male', 'hire_date' => '2021-03-01', 'created_at' => '2023-01-12', 'job_title' => 'Accountant', 'active' => 1, 'wp_user_id' => 70, 'department_name' => 'Accounts'];
        $this->store->employees[8] = (object) ['id' => 8, 'name' => 'Mona Adel', 'name_ar' => '', 'gender' => '', 'hire_date' => null, 'created_at' => '2024-01-01', 'job_title' => '', 'active' => 0, 'wp_user_id' => 0, 'department_name' => null];
        $this->site = new class implements LetterSite {
            public $on = true; public $requestable = true; public $raw = null; public $types = null; public $used = 0;
            public function enabled(): bool { return $this->on; }
            public function requestable(): bool { return $this->requestable; }
            public function settings(): array { return LetterSettings::settings($this->raw); }
            public function types(): array { return LetterSettings::types($this->types); }
            public function letterhead(): array { return LetterSettings::letterhead(['company_ar' => 'شركة النيل', 'company_en' => 'Nile']); }
            public function companyFallback(): string { return 'Site'; }
            public function currencyCode(): string { return 'EGP'; }
            public function today(): string { return '2026-11-09'; }
            public function markUsed(): void { $this->used++; }
        };
        $this->ap = new class($j) implements LetterApprovals {
            public $j; public $mode = 'LEVEL_1'; public $requests = []; public $failStart = false; public $actResult = ''; public $close = true; public $can = true; public $moved = false; public $ready = true;
            public function __construct($j) { $this->j = $j; }
            public function ensureReady(): bool { $this->j[] = 'ensureReady'; return $this->ready; }
            public function start(int $letterId, int $employeeId): bool { $this->j[] = 'start ' . $letterId; if ($this->failStart) return false; $this->requests[$letterId] = (object) ['id' => 100 + $letterId, 'status' => $this->mode === 'NONE' ? 'APPROVED' : 'PENDING', 'levels' => $this->mode === 'LEVEL_2' ? 2 : 1]; return true; }
            public function find(int $letterId): ?object { return isset($this->requests[$letterId]) ? clone $this->requests[$letterId] : null; }
            public function currentStep(int $approvalRequestId): ?object { return (object) ['approver_wp_user_id' => 50]; }
            public function levelMoved(int $approvalRequestId, $posted): bool { return $this->moved; }
            public function canAct(int $approvalRequestId, bool $override): bool { return $this->can; }
            public function act(int $approvalRequestId, string $decision, string $note, $posted, bool $override): string {
                $this->j[] = 'act ' . $decision;
                if ($this->actResult !== '') return $this->actResult;
                foreach ($this->requests as $r) if ($r->id === $approvalRequestId) {
                    if ($decision === 'reject') $r->status = 'REJECTED';
                    elseif (--$r->levels <= 0) $r->status = 'APPROVED';
                }
                return '';
            }
            public function closeOpen(int $letterId) { $this->j[] = 'closeOpen ' . $letterId; return $this->close; }
            public function pathNote(int $letterId): string { return 'path note'; }
        };
        $notices = new class($j) implements LetterNotices {
            public $j;
            public function __construct($j) { $this->j = $j; }
            public function decider(object $letter): void { $this->j[] = 'notify decider ' . $letter->id; }
            public function employee(string $event, object $letter, string $reason = ''): void { $this->j[] = 'notify employee ' . $event . ($reason !== '' ? ': ' . $reason : ''); }
            public function hr(string $event, object $letter): void { $this->j[] = 'notify hr ' . $event; }
        };
        $this->files = new class($j) implements LetterFiles {
            public $j; public $files = []; public $failStore = false; public $upload = null; public $n = 0;
            public function __construct($j) { $this->j = $j; }
            public function font(): string { return (string) file_get_contents(dirname(__DIR__, 2) . '/workforce-one/assets/vendor/amiri/Amiri-Regular.ttf'); }
            public function store(string $bytes, string $extension): string { if ($this->failStore) return ''; $k = str_pad((string) ++$this->n, 32, 'k') . '.' . $extension; $this->files[$k] = $bytes; $this->j[] = 'store ' . $k; return $k; }
            public function uploadPdf($file, int $maxBytes): array { $u = $this->upload; if ($u['error'] === '') { $this->files[$u['key']] = '%PDF-up'; $this->j[] = 'upload ' . $u['key']; } return $u; }
            public function read(string $key): ?string { return $this->files[$key] ?? null; }
            public function delete(string $key): void { unset($this->files[$key]); $this->j[] = 'delete ' . $key; }
        };
        $this->salary = new class implements LetterSalary {
            public $rate = ['basic' => 15000.0, 'allowances' => 3500.0, 'total' => 18500.0];
            public function on(int $employeeId, string $date): ?array { return $this->rate; }
        };
        $ports = new LetterPorts($this->site, $this->ap, $notices, $this->files, $this->salary);
        $tx = new class($j) implements Transaction {
            public $j; public $failCommit = false;
            public function __construct($j) { $this->j = $j; }
            public function begin(): bool { $this->j[] = 'BEGIN'; return true; }
            public function commit(): bool { $this->j[] = 'COMMIT'; return !$this->failCommit; }
            public function rollback(): void { $this->j[] = 'ROLLBACK'; }
        };
        $audits = &$this->audits;
        $audit = new class($audits) implements AuditLog {
            private $a;
            public function __construct(array &$a) { $this->a = &$a; }
            public function record(string $action, string $entity, int $entityId, string $details): void { $this->a[] = $action . ' ' . $details; }
        };
        $clock = new class implements Clock { public function now(): string { return '2026-11-09 10:00:00'; } };
        $this->issuer = new LetterIssuer($this->store, $this->store, $ports, $audit, $clock);
        $this->requests = new LetterRequests($this->store, $this->issuer, $ports, $tx, $audit, $clock);
        $this->decisions = new LetterDecisions($this->store, $this->issuer, $ports, $tx, $audit, $clock);
    }

    private function emp(int $id): object
    {
        return $this->store->employees[$id];
    }

    public function testARequestWaitsForTheApproverInOneTransaction(): void
    {
        $r = $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'ar', 'addressee' => 'بنك مصر'], 70);
        $this->assertSame(['', 'pending'], [$r['error'], $r['status']]);
        $this->assertSame(['ensureReady', 'BEGIN', 'insert', 'start 1', 'COMMIT', 'notify decider 1'], $this->j->getArrayCopy(), 'the workflow set before the transaction; the decider told after it');
        $row = $this->store->rows[1];
        $this->assertSame(['7:salary:ar', 'pdf', 1, 'شهادة مرتب', 'employee'], [$row->open_key, $row->delivery, $row->with_salary, $row->type_label, $row->source]);
        $this->assertSame(1, $this->site->used);
        $this->assertSame('open', $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'ar'], 70)['error'], 'one open request per type and language');
        $this->assertSame('', $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'en'], 70)['error'], 'another language is another request');
    }

    public function testRefusals(): void
    {
        $this->site->requestable = false;
        $this->assertSame('access', $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70)['error']);
        $this->site->requestable = true;
        $this->salary->rate = null;
        $this->assertSame('no_salary', $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'ar'], 70)['error'], 'a salary certificate needs a pay rate');
        $this->assertSame('', $this->requests->request($this->emp(7), ['type' => 'employment', 'language' => 'ar'], 70)['error'], '...the employment certificate does not');
        $this->ap->ready = false;
        $this->assertSame('approval', $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70)['error']);
        $this->ap->ready = true;
        $this->ap->failStart = true;
        $this->j->exchangeArray([]);
        $this->assertSame('save', $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70)['error']);
        $this->assertSame(['ensureReady', 'BEGIN', 'insert', 'start 2', 'ROLLBACK'], $this->j->getArrayCopy(), 'the approval did not start: rolled back');
        $this->ap->failStart = false;
        $this->store->failInsert = true;
        $this->assertSame('save', $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'en'], 70)['error']);
    }

    public function testNoApprovalNeededIssuesAPdfAtOnce(): void
    {
        $this->ap->mode = 'NONE';
        $r = $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'ar', 'addressee' => 'بنك مصر'], 70);
        $this->assertSame(['', 'issued'], [$r['error'], $r['status']]);
        $row = $this->store->rows[1];
        $this->assertSame(['generated', null, 'HR-2026-000001.pdf'], [$row->file_source, $row->open_key, $row->file_name]);
        $this->assertStringStartsWith('%PDF-', $this->files->files[$row->file_key]);
        $this->assertContains('notify employee ready', $this->j->getArrayCopy());
        $this->assertSame(0, count(array_filter($this->audits, static function ($a) { return strpos($a, '18') !== false && strpos($a, 'hr_letter_issued') === 0; })), 'no amount in the Audit Log');
    }

    public function testDecisions(): void
    {
        $this->requests->request($this->emp(7), ['type' => 'experience', 'language' => 'ar'], 70);
        $this->j->exchangeArray([]);
        $this->assertSame('', $this->decisions->decide(1, 'approve', '', null, false));
        $this->assertSame(['BEGIN', 'act approve', 'markDecided:approved 1', 'COMMIT', 'notify hr waiting_file', 'notify employee preparing'], $this->j->getArrayCopy(), 'an upload type: HR is told to upload it');
        $this->assertSame('done', $this->decisions->decide(1, 'approve', '', null, false), 'decided already');
        $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70);
        $this->ap->actResult = 'note';
        $this->assertSame('note', $this->decisions->decide(2, 'reject', '', null, false), 'the engine wants a reason');
        $this->assertSame('pending', $this->store->rows[2]->status);
        $this->ap->actResult = '';
        $this->j->exchangeArray([]);
        $this->assertSame('', $this->decisions->decide(2, 'reject', 'Not for loans', null, false));
        $this->assertSame(['rejected', null, 'Not for loans'], [$this->store->rows[2]->status, $this->store->rows[2]->open_key, $this->store->rows[2]->decision_note]);
        $this->assertContains('notify employee rejected: Not for loans', $this->j->getArrayCopy());
        $this->ap->mode = 'LEVEL_2';
        $this->requests->request($this->emp(7), ['type' => 'employment', 'language' => 'en'], 70);
        $this->j->exchangeArray([]);
        $this->decisions->decide(3, 'approve', '', null, false);
        $this->assertSame(['BEGIN', 'act approve', 'COMMIT', 'notify decider 3'], $this->j->getArrayCopy(), 'the first of two levels: the next approver');
        $this->ap->moved = true;
        $this->assertSame('moved', $this->decisions->decide(3, 'approve', '', 5, false));
        $this->ap->moved = false;
        $this->ap->can = false;
        $this->assertSame('access', $this->decisions->decide(3, 'approve', '', null, false));
        $this->site->on = false;
        $this->assertSame('access', $this->decisions->decide(3, 'approve', '', null, false), 'nothing is decided while letters are off');
    }

    public function testWithdrawClosesTheApprovalFirst(): void
    {
        $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70);
        $this->assertSame('done', $this->requests->withdraw(1, 8), 'not another employee\'s');
        $this->ap->close = 'moved';
        $this->assertSame('done', $this->requests->withdraw(1, 7), 'decided meanwhile');
        $this->assertSame('pending', $this->store->rows[1]->status);
        $this->ap->close = true;
        $this->j->exchangeArray([]);
        $this->assertSame('', $this->requests->withdraw(1, 7));
        $this->assertSame(['BEGIN', 'closeOpen 1', 'withdraw 1', 'COMMIT'], $this->j->getArrayCopy(), 'the engine first, then the row (as a decision)');
        $this->assertSame([null, 'withdrawn'], [$this->store->rows[1]->open_key, $this->store->rows[1]->status]);
    }

    public function testIssueFailuresUploadsAndRegenerate(): void
    {
        $this->ap->mode = 'NONE';
        $types = LetterSettings::types(null);
        $types[1]['tpl_en'] = '';
        $types[1]['delivery'] = 'pdf';
        $this->site->types = $types;
        $this->requests->request($this->emp(7), ['type' => 'salary', 'language' => 'ar'], 70);
        $this->salary->rate = null;
        $this->store->rows[1]->status = 'approved';
        $this->store->rows[1]->file_key = null;
        $this->assertSame('no_salary', $this->issuer->issue(1, 0));
        $this->assertSame(['approved', 'no_salary'], [$this->store->rows[1]->status, $this->store->rows[1]->issue_error], 'stays approved with the reason');
        $this->assertContains('notify hr issue_failed', $this->j->getArrayCopy());
        // HR uploads instead.
        $this->files->upload = ['key' => str_repeat('u', 32) . '.pdf', 'name' => 'x.pdf', 'size' => 10, 'error' => ''];
        $this->assertSame('', $this->issuer->upload(1, ['tmp' => 1], 5));
        $this->assertSame(['issued', 'uploaded', ''], [$this->store->rows[1]->status, $this->store->rows[1]->file_source, $this->store->rows[1]->issue_error]);
        // Regenerate an issued letter: a new file replaces the old one, the employee is told, the next download counts as new.
        $this->salary->rate = ['basic' => 1.0, 'allowances' => 0.0, 'total' => 1.0];
        $this->store->rows[1]->downloaded_at = '2026-11-09 11:00:00';
        $old = $this->store->rows[1]->file_key;
        $this->j->exchangeArray([]);
        $this->assertSame('', $this->issuer->regenerate(1, 5));
        $this->assertNotSame($old, $this->store->rows[1]->file_key);
        $this->assertNull($this->store->rows[1]->downloaded_at);
        $this->assertArrayNotHasKey($old, $this->files->files, 'the old file is deleted');
        $this->assertContains('notify employee updated', $this->j->getArrayCopy());
        // A claim lost to someone else: the new file is deleted.
        $this->requests->request($this->emp(7), ['type' => 'experience', 'language' => 'ar'], 70);
        $this->store->rows[2]->file_key = 'someone-else';
        $this->files->upload = ['key' => str_repeat('v', 32) . '.pdf', 'name' => 'y.pdf', 'size' => 10, 'error' => ''];
        $this->assertSame('done', $this->issuer->upload(2, ['tmp' => 1], 5));
        $this->assertArrayNotHasKey(str_repeat('v', 32) . '.pdf', $this->files->files);
        $this->files->upload = ['key' => '', 'name' => '', 'size' => 0, 'error' => 'not_pdf'];
        $this->assertSame('not_pdf', $this->issuer->upload(1, ['tmp' => 1], 5), 'not a PDF');
        $this->assertSame('template_missing', $this->issuer->preview(7, 'x', 'en', '  ', '')['error']);
        $this->assertSame('employee', $this->issuer->preview(99, 'x', 'en', 'Hi', '')['error']);
        $this->assertStringStartsWith('%PDF-', $this->issuer->preview(8, 'Letter', 'en', 'Dear {title_mr_ms} {employee_name}, {g:he|she} works here since {hire_date}.', 'Bank')['pdf'], 'a former employee, nothing filled');
    }

    public function testReadyCancelAndDownload(): void
    {
        $this->requests->request($this->emp(7), ['type' => 'bank', 'language' => 'ar'], 70);
        $this->decisions->decide(1, 'approve', '', null, false);
        $this->assertSame('note', $this->decisions->cancel(1, '  ', 5), 'a reason is needed');
        $this->assertSame('', $this->decisions->markReady(1, 'Office 204', 5));
        $this->assertSame(['ready', 'Office 204'], [$this->store->rows[1]->status, $this->store->rows[1]->decision_note]);
        $this->assertSame('done', $this->decisions->cancel(1, 'late', 5), 'not once ready');
        $this->requests->request($this->emp(7), ['type' => 'experience', 'language' => 'en'], 70);
        $this->decisions->decide(2, 'approve', '', null, false);
        $this->assertSame('done', $this->decisions->markReady(2, '', 5), 'not an upload type');
        $this->assertSame('', $this->decisions->cancel(2, 'Ask again next month', 5));
        $this->assertContains('notify employee cancelled: Ask again next month', $this->j->getArrayCopy());
        // Downloads
        $this->ap->mode = 'NONE';
        $this->requests->request($this->emp(7), ['type' => 'employment', 'language' => 'en'], 70);
        $this->assertSame('access', $this->issuer->download(3, 8, false, 'Mona')['error'], 'another employee');
        $mine = $this->issuer->download(3, 7, false, 'Ahmed');
        $this->assertSame(['', 'HR-2026-000003.pdf'], [$mine['error'], $mine['name']]);
        $this->issuer->download(3, 7, false, 'Ahmed');
        $this->assertCount(1, array_filter($this->audits, static function ($a) { return strpos($a, 'hr_letter_downloaded First download') === 0; }), 'the owner\'s first download only');
        $this->assertSame('', $this->issuer->download(3, 0, true, 'Heba')['error']);
        $this->assertContains('hr_letter_downloaded Downloaded by Heba', $this->audits);
        $this->site->on = false;
        $this->assertSame('access', $this->issuer->download(3, 7, false, 'Ahmed')['error'], 'the employee, while letters are off');
        $this->assertSame('', $this->issuer->download(3, 0, true, 'Heba')['error'], 'HR, while letters are off');
    }

    public function testHrLetterForAnEmployee(): void
    {
        $this->assertSame('self', $this->requests->createForEmployee($this->emp(7), ['type' => 'employment', 'language' => 'ar'], null, 70, 7, true)['error'], 'not for one\'s own record');
        $this->assertSame('payroll', $this->requests->createForEmployee($this->emp(7), ['type' => 'salary', 'language' => 'ar'], null, 5, 0, false)['error'], 'a salary letter needs Manage Payroll');
        $r = $this->requests->createForEmployee($this->emp(8), ['type' => 'employment', 'language' => 'en'], null, 5, 0, false);
        $this->assertSame(['', 'issued'], [$r['error'], $r['status']], 'a former employee: issued directly');
        $this->assertSame(['hr', null], [$this->store->rows[$r['id']]->source, $this->store->rows[$r['id']]->open_key]);
        $this->assertNotContains('start ' . $r['id'], $this->j->getArrayCopy(), 'no approval');
        $this->files->upload = ['key' => str_repeat('w', 32) . '.pdf', 'name' => 'z.pdf', 'size' => 10, 'error' => ''];
        $u = $this->requests->createForEmployee($this->emp(7), ['type' => 'salary', 'language' => 'ar', 'make' => 'upload'], ['tmp' => 1], 5, 0, false);
        $this->assertSame(['', 'issued', 'uploaded', 0], [$u['error'], $u['status'], $this->store->rows[$u['id']]->file_source, $this->store->rows[$u['id']]->with_salary], 'HR\'s own file: no salary from the template, so no Manage Payroll needed');
        $o = $this->requests->createForEmployee($this->emp(7), ['type' => 'other', 'other_text' => 'Club letter', 'language' => 'ar'], null, 5, 0, false);
        $this->assertSame(['', 'approved', 'upload'], [$o['error'], $o['status'], $this->store->rows[$o['id']]->delivery], '"Other" without a file waits on the HR letters page');
    }
}
