<?php
use PHPUnit\Framework\TestCase;
use WorkforceOne\Attendance\CorrectionApprovals;
use WorkforceOne\Attendance\CorrectionDecider;
use WorkforceOne\Attendance\CorrectionFiles;
use WorkforceOne\Attendance\CorrectionNotices;
use WorkforceOne\Attendance\CorrectionPorts;
use WorkforceOne\Attendance\CorrectionRequests;
use WorkforceOne\Attendance\CorrectionRules;
use WorkforceOne\Attendance\CorrectionSite;
use WorkforceOne\Attendance\Data\CorrectionListFilter;
use WorkforceOne\Attendance\Data\CorrectionQuery;
use WorkforceOne\Attendance\Data\CorrectionStore;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Settings\CorrectionSettings;

/**
 * Attendance correction requests (3.31.143, docs/tasks/attendance-corrections.md §11.6) on fakes that write one shared journal: the store, the
 * read model, every port, the transaction and the audit. Each test pins the order the old cx_create / cx_withdraw / corrections_tick /
 * corrections_remind followed.
 */
final class CorrectionRequestsTest extends TestCase
{
    /** @var list<string> */
    public $j = [];
    /** @var array<string,mixed> what the fakes answer */
    public $w = [];

    protected function setUp(): void
    {
        $this->j = [];
        $this->w = ['enabled' => true, 'settings' => CorrectionSettings::config([]), 'facts' => [], 'insert' => 41, 'mode' => '', 'start' => true, 'ar' => null, 'step' => null,
            'after' => 'approved', 'finalize' => 'approved', 'managers' => [5, 9], 'save' => [null, null, ''], 'own' => true, 'begin' => true, 'close' => true, 'withdraw' => 1,
            'commit' => true, 'hm' => '20:00', 'today' => '2026-11-07', 'reminded' => '', 'candidates' => [], 'hours' => ['start' => '09:00', 'end' => '17:00', 'overnight' => false]];
        $this->w['facts'] = $this->facts();
    }

    /** @param array<string,mixed> $over */
    private function facts(array $over = []): array
    {
        return $over + ['type' => 'out', 'target' => '', 'date' => '2026-11-05', 'today' => '2026-11-07', 'now' => '12:00', 'time_in' => '', 'time_out' => '17:30',
            'in' => '2026-11-05 08:00:00', 'out' => null, 'overnight' => false, 'source' => 'employee', 'settings' => CorrectionSettings::config([]),
            'pending' => false, 'month_count' => 0, 'no_work' => false, 'started' => '', 'reason' => 'forgot', 'has_photo' => false];
    }

    private function emp(): object
    {
        return (object) ['id' => '7', 'name' => 'Omar'];
    }

    private function service(): CorrectionRequests
    {
        $t = $this;
        $store = new class($t) implements CorrectionStore {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function monthCount(int $employeeId, string $from, string $to): int { return 0; }
            public function pendingForDay(int $employeeId, string $date): ?object { return null; }
            public function recentFor(int $employeeId, string $from, string $to): array { return []; }
            public function latestFor(int $employeeId, int $limit): array { return []; }
            public function myRequests(int $employeeId, int $limit): array { return []; }
            public function tableExists(): bool { return true; }
            public function openForHub(): array { return []; }
            public function insert(array $row): int { $this->t->j[] = 'insert ' . implode(',', array_keys($row)) . ' status=' . $row['status'] . ' by=' . $row['requested_by'] . ' at=' . $row['requested_at'] . ' photo=' . (string) $row['photo_key']; return $this->t->w['insert']; }
            public function delete(int $id): void { $this->t->j[] = 'delete ' . $id; }
            public function ownOpen(int $id, int $employeeId): bool { $this->t->j[] = 'ownOpen ' . $id . ' ' . $employeeId; return $this->t->w['own']; }
            public function withdraw(int $id, int $employeeId, int $userId, string $now) { $this->t->j[] = 'withdraw ' . $id . ' ' . $employeeId . ' ' . $userId . ' ' . $now; return $this->t->w['withdraw']; }
        };
        $query = new class($t) implements CorrectionQuery {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function get(int $id): ?object { $this->t->j[] = 'get ' . $id; return (object) ['id' => $id]; }
            public function toDecideByApprover(int $userId): array { return []; }
            public function toDecideOpen(): array { return []; }
            public function toDecideHr(): array { return []; }
            public function departments(): array { return []; }
            public function listCounts(CorrectionListFilter $f): array { return []; }
            public function listTotal(CorrectionListFilter $f): int { return 0; }
            public function listRows(CorrectionListFilter $f, int $perPage, int $offset): array { return []; }
            public function report(string $from, string $to, int $department): array { return []; }
            public function export(string $from, string $to, int $department): array { return []; }
            public function remindCandidates(string $date): array { $this->t->j[] = 'candidates ' . $date; return $this->t->w['candidates']; }
        };
        $site = new class($t) implements CorrectionSite {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function enabled(): bool { $this->t->j[] = 'enabled'; return $this->t->w['enabled']; }
            public function settings(): array { $this->t->j[] = 'settings'; return $this->t->w['settings']; }
            public function ensureSchema(): void { $this->t->j[] = 'schema'; }
            public function ensureSchemaOnce(): void { $this->t->j[] = 'schema once'; }
            public function facts(object $employee, array $in, string $source, bool $hasPhoto): array { $this->t->j[] = 'facts ' . $source . ' ' . ($hasPhoto ? 'photo' : 'none'); return $this->t->w['facts']; }
            public function changeText(?object $request, string $lang = ''): string { return 'change' . ($lang !== '' ? '/' . $lang : ''); }
            public function timeManagerIds(): array { $this->t->j[] = 'managers'; return $this->t->w['managers']; }
            public function today(): string { $this->t->j[] = 'today'; return $this->t->w['today']; }
            public function hm(): string { $this->t->j[] = 'hm'; return $this->t->w['hm']; }
            public function remindedOn(): string { $this->t->j[] = 'remindedOn'; return $this->t->w['reminded']; }
            public function markReminded(string $day): void { $this->t->j[] = 'markReminded ' . $day; }
            public function workingHoursToday(int $employeeId): array { $this->t->j[] = 'hours ' . $employeeId; return $this->t->w['hours']; }
        };
        $notices = new class($t) implements CorrectionNotices {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function decider(int $userId, ?object $request): void { $this->t->j[] = 'tell ' . $userId . ' #' . ($request ? $request->id : 0); }
            public function signOutReminder(int $userId, string $date, string $signedIn): void { $this->t->j[] = 'remind ' . $userId . ' ' . $date . ' ' . $signedIn; }
        };
        $files = new class($t) implements CorrectionFiles {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function hasUpload($file): bool { return is_array($file); }
            public function save($file): array { $this->t->j[] = 'save ' . (is_array($file) ? 'file' : 'null'); return $this->t->w['save']; }
            public function delete(string $key): void { $this->t->j[] = 'drop ' . $key; }
        };
        $approvals = new class($t) implements CorrectionApprovals {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function mode(): string { $this->t->j[] = 'mode'; return $this->t->w['mode']; }
            public function isLevels(string $mode): bool { return strpos($mode, 'LEVEL_') === 0; }
            public function start(int $requestId, int $employeeId): bool { $this->t->j[] = 'start ' . $requestId . ' ' . $employeeId; return $this->t->w['start']; }
            public function find(int $requestId): ?object { $this->t->j[] = 'find ' . $requestId; return $this->t->w['ar']; }
            public function currentStep(int $approvalRequestId): ?object { $this->t->j[] = 'step ' . $approvalRequestId; return $this->t->w['step']; }
            public function closeOpen(int $requestId) { $this->t->j[] = 'closeOpen ' . $requestId; return $this->t->w['close']; }
        };
        $decider = new class($t) implements CorrectionDecider {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function afterManager(int $requestId): string { $this->t->j[] = 'afterManager ' . $requestId; return $this->t->w['after']; }
            public function finalizeDirect(int $requestId, string $reason): string { $this->t->j[] = 'finalize ' . $requestId . ' ' . $reason; return $this->t->w['finalize']; }
        };
        $tx = new class($t) implements Transaction {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function begin(): bool { $this->t->j[] = 'BEGIN'; return $this->t->w['begin']; }
            public function commit(): bool { $this->t->j[] = 'COMMIT'; return $this->t->w['commit']; }
            public function rollback(): void { $this->t->j[] = 'ROLLBACK'; }
        };
        $audit = new class($t) implements AuditLog {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function record(string $a, string $e, int $id, string $d): void { $this->t->j[] = 'audit ' . $a . ' ' . $e . ' ' . $id . ' ' . $d; }
        };
        $clock = new class($t) implements Clock {
            private $t;
            public function __construct($t) { $this->t = $t; }
            public function now(): string { $this->t->j[] = 'now'; return '2026-11-07 12:00:00'; }
        };
        return new CorrectionRequests($store, $query, new CorrectionPorts($site, $notices, $files, $approvals, $decider), $tx, $audit, $clock);
    }

    private function create($emp = 'default', string $source = 'employee', $photo = null): array
    {
        return $this->service()->create($emp === 'default' ? $this->emp() : $emp, ['date' => '2026-11-05'], $source, $photo, 5);
    }

    public function testEarlyReturnsTouchNothingMore(): void
    {
        $this->w['enabled'] = false;
        $this->assertSame(['error' => 'disabled'], $this->create());
        $this->assertSame(['enabled'], $this->j);
        $this->w['enabled'] = true;
        $this->j = [];
        $this->assertSame(['error' => 'employee'], $this->create(null));
        $this->assertSame(['enabled'], $this->j, 'no employee: not even the schema');
        $this->j = [];
        $this->w['facts'] = $this->facts(['reason' => ' ']);
        $this->assertSame(['error' => 'reason'], $this->create());
        $this->assertSame(['enabled', 'schema', 'facts employee none'], $this->j, 'the full schema check, then the facts; a refusal saves nothing');
    }

    public function testNoWorkflowSetTellsEveryTimeManagerButTheRequester(): void
    {
        $r = $this->create('default', 'employee', ['error' => 0]);
        $this->assertSame(['id' => 41, 'status' => 'pending'], $r);
        $this->assertSame(['enabled', 'schema', 'facts employee photo', 'settings', 'save file', 'now',
            'insert employee_id,work_date,type,target,sign_in_at,sign_out_at,old_at,reason,photo_key,photo_name,status,source,needs_hr,above_limit,earlier,requested_by,requested_at status=pending by=5 at=2026-11-07 12:00:00 photo=',
            'get 41', 'audit correction_request employee 7 Omar 2026-11-05: change/en', 'mode', 'get 41', 'managers', 'tell 9 #41'], $this->j, 'a Sign Out\'s photo is optional by default: kept');
    }

    public function testThePhotoIsDroppedWhenTheSettingIsOffAndAFailedSaveStops(): void
    {
        $this->w['settings']['photo']['out'] = 'off';
        $this->create('default', 'employee', ['error' => 0]);
        $this->assertContains('save null', $this->j);
        $this->j = [];
        $this->w['settings']['photo']['out'] = 'optional';
        $this->w['save'] = [null, null, 'photo_size'];
        $this->assertSame(['error' => 'photo_size'], $this->create('default', 'employee', ['error' => 0]));
        $this->assertSame('save file', end($this->j), 'nothing after a refused photo');
    }

    public function testAFailedInsertDropsThePhoto(): void
    {
        $this->w['save'] = ['k.png', 'a.png', ''];
        $this->w['insert'] = 0;
        $this->assertSame(['error' => 'save'], $this->create());
        $this->assertSame('drop k.png', end($this->j));
    }

    public function testNoneModeApprovesAtOnceAndAnErrorComesBackAsTheStatus(): void
    {
        $this->w['mode'] = 'NONE';
        $this->w['ar'] = (object) ['id' => 3, 'status' => 'APPROVED'];
        $this->assertSame(['id' => 41, 'status' => 'approved'], $this->create());
        $this->assertSame(['mode', 'start 41 7', 'find 41', 'afterManager 41'], array_slice($this->j, -4));
        $this->w['after'] = 'save';
        $this->assertSame(['id' => 41, 'status' => 'save'], $this->create(), 'CX-z, kept: no error key');
    }

    public function testLevelsTellTheApproverOnlyWhenThereIsOne(): void
    {
        $this->w['mode'] = 'LEVEL_1';
        $this->w['ar'] = (object) ['id' => 3, 'status' => 'WAITING_FOR_LEVEL_1'];
        $this->w['step'] = (object) ['approver_wp_user_id' => '12'];
        $this->assertSame(['id' => 41, 'status' => 'pending'], $this->create());
        $this->assertSame(['find 41', 'step 3', 'get 41', 'tell 12 #41'], array_slice($this->j, -4));
        $this->j = [];
        $this->w['step'] = (object) ['approver_wp_user_id' => null];
        $this->create();
        $this->assertSame('step 3', end($this->j), 'with administrators: nobody is told here');
    }

    public function testAnEngineThatCannotStartRemovesTheRequestAfterItsAudit(): void
    {
        $this->w['mode'] = 'LEVEL_2';
        $this->w['start'] = false;
        $this->w['save'] = ['k.png', 'a.png', ''];
        $this->assertSame(['error' => 'approval'], $this->create());
        $this->assertSame(['audit correction_request employee 7 Omar 2026-11-05: change/en', 'mode', 'start 41 7', 'delete 41', 'drop k.png'], array_slice($this->j, -5), 'CX-r kept: audited before the start');
    }

    public function testHrIsAppliedAtOnceOrTheRowGoes(): void
    {
        $this->w['facts'] = $this->facts(['source' => 'hr', 'reason' => 'mission']);
        $this->assertSame(['id' => 41, 'status' => 'approved'], $this->create('default', 'hr'));
        $this->assertSame(['finalize 41 mission', 'get 41', 'audit correction_direct employee 7 Omar 2026-11-05: change/en — mission'], array_slice($this->j, -3));
        $this->assertNotContains('settings', $this->j, 'HR\'s photo is never dropped by the setting');
        $this->assertNotContains('mode', $this->j);
        $this->j = [];
        $this->w['finalize'] = 'stale';
        $this->w['save'] = ['k.png', 'a.png', ''];
        $this->assertSame(['error' => 'stale'], $this->create('default', 'hr'));
        $this->assertSame(['finalize 41 mission', 'delete 41', 'drop k.png'], array_slice($this->j, -3));
    }

    public function testWithdraw(): void
    {
        $this->assertTrue($this->service()->withdraw(41, 7, 5));
        $this->assertSame(['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'now', 'withdraw 41 7 5 2026-11-07 12:00:00', 'COMMIT', 'audit correction_withdrawn employee 7 Correction #41 withdrawn by the employee'], $this->j);
        $cases = [
            ['own', false, 'done', ['ownOpen 41 7']],
            ['begin', false, 'save', ['ownOpen 41 7', 'BEGIN']],
            ['close', 'moved', 'done', ['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'ROLLBACK']],
            ['close', 'save', 'save', ['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'ROLLBACK']],
            ['withdraw', false, 'save', ['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'now', 'withdraw 41 7 5 2026-11-07 12:00:00', 'ROLLBACK']],
            ['withdraw', 0, 'done', ['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'now', 'withdraw 41 7 5 2026-11-07 12:00:00', 'ROLLBACK']],
            ['commit', false, 'save', ['ownOpen 41 7', 'BEGIN', 'closeOpen 41', 'now', 'withdraw 41 7 5 2026-11-07 12:00:00', 'COMMIT', 'ROLLBACK']],
        ];
        foreach ($cases as [$k, $v, $want, $journal]) {
            $this->setUp();
            $this->w[$k] = $v;
            $this->assertSame($want, $this->service()->withdraw(41, 7, 5), $k);
            $this->assertSame($journal, $this->j, $k);
        }
    }

    public function testTickGuardsThenClaimsThenReminds(): void
    {
        $this->w['enabled'] = false;
        $this->service()->tick();
        $this->assertSame(['enabled'], $this->j);
        $this->setUp();
        $this->w['settings']['reminder'] = 0;
        $this->service()->tick();
        $this->assertSame(['enabled', 'settings'], $this->j, 'reminder off: the clock is not read');
        $this->setUp();
        $this->w['hm'] = '18:59';
        $this->service()->tick();
        $this->assertSame(['enabled', 'settings', 'hm'], $this->j);
        $this->setUp();
        $this->w['reminded'] = '2026-11-07';
        $this->service()->tick();
        $this->assertSame(['enabled', 'settings', 'hm', 'today', 'remindedOn'], $this->j);
        $this->setUp();
        $this->service()->tick();
        $this->assertSame(['enabled', 'settings', 'hm', 'today', 'remindedOn', 'markReminded 2026-11-07', 'schema once', 'candidates 2026-11-07'], $this->j);
    }

    public function testRemindSkipsUserZeroAndOvernightHours(): void
    {
        $this->w['candidates'] = [(object) ['id' => '1', 'wp_user_id' => '0', 'signed_in' => '2026-11-07 08:00:00'], (object) ['id' => '2', 'wp_user_id' => '20', 'signed_in' => '2026-11-07 08:10:00']];
        $this->assertSame(1, $this->service()->remind('2026-11-07'));
        $this->assertSame(['candidates 2026-11-07', 'hours 2', 'remind 20 2026-11-07 2026-11-07 08:10:00'], $this->j, 'user 0 is skipped before the hours are read');
        $this->setUp();
        $this->w['candidates'] = [(object) ['id' => '2', 'wp_user_id' => '20', 'signed_in' => '2026-11-07 08:10:00']];
        $this->w['hours'] = ['start' => '22:00', 'end' => '06:00', 'overnight' => true];
        $this->assertSame(0, $this->service()->remind('2026-11-07'));
    }

    public function testRequestRowKeepsTheInsertColumnOrder(): void
    {
        $f = $this->facts(['type' => 'time', 'target' => 'sign_in', 'time_in' => '07:30', 'in' => '2026-11-05 08:00:00', 'out' => '2026-11-05 16:00:00', 'reason' => str_repeat('r', 1200)]);
        $plan = CorrectionRules::plan($f);
        $row = CorrectionRules::requestRow(7, $f, $plan, CorrectionRules::times($f), null, null, 'employee', 5, '2026-11-07 12:00:00');
        $this->assertSame(['employee_id', 'work_date', 'type', 'target', 'sign_in_at', 'sign_out_at', 'old_at', 'reason', 'photo_key', 'photo_name', 'status', 'source', 'needs_hr', 'above_limit', 'earlier', 'requested_by', 'requested_at'], array_keys($row));
        $this->assertSame(['sign_in', '2026-11-05 07:30:00', null, '2026-11-05 08:00:00', 'pending', 1, 1, 0], [$row['target'], $row['sign_in_at'], $row['sign_out_at'], $row['old_at'], $row['status'], $row['earlier'], $row['needs_hr'], $row['above_limit']]);
        $this->assertSame(1000, mb_strlen($row['reason']));
        $out = CorrectionRules::requestRow(7, $this->facts(), CorrectionRules::plan($this->facts()), CorrectionRules::times($this->facts()), 'k.png', 'a.png', 'hr', 5, 'now');
        $this->assertSame([null, null, '2026-11-05 17:30:00', null, 'pending', 'hr', 'k.png'], [$out['target'], $out['sign_in_at'], $out['sign_out_at'], $out['old_at'], $out['status'], $out['source'], $out['photo_key']]);
    }
}
