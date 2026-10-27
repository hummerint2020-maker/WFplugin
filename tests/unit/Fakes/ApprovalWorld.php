<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\Approvals\AfterRequest;
use WorkforceOne\Approvals\ApprovalDirectory;
use WorkforceOne\Approvals\ApprovalEngine;
use WorkforceOne\Approvals\ApprovalNotifier;
use WorkforceOne\Approvals\ApprovalPolicy;
use WorkforceOne\Approvals\DeadlineNotifier;
use WorkforceOne\Approvals\DeadlineService;
use WorkforceOne\Approvals\SubstituteRouting;
use WorkforceOne\Approvals\WorkflowService;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Permissions;
use WorkforceOne\Platform\Transaction;

require_once __DIR__ . '/MemoryApprovalRequestStore.php';
require_once __DIR__ . '/MemoryWorkflowStore.php';

/**
 * Everything the approvals Services need, as fakes with public state (3.31.128): the in-memory Stores and the ports, each of
 * which records what it was asked. A test builds one, sets the people and the workflows, and asks a Service.
 */
final class ApprovalWorld
{
    /** @var MemoryApprovalRequestStore */
    public $requests;
    /** @var MemoryWorkflowStore */
    public $workflows;
    /** @var array<int,string> every begin / commit / rollback, in order */
    public $tx = [];
    /** @var bool */
    public $failBegin = false;
    /** @var bool */
    public $failCommit = false;
    /** @var array<int,object> employee id => {id, wp_user_id, name, active, department_id} */
    public $employees = [];
    /** @var array<int,int> employee id => its manager's employee id */
    public $supervisor = [];
    /** @var array<int,bool> WordPress users that exist */
    public $users = [];
    /** @var array<int,bool> administrators (WordPress user ids) */
    public $admins = [];
    /** @var array<string,string> */
    public $selfRule = [];
    /** @var array<string,bool> */
    public $rejectNote = [];
    /** @var array{hr_user:int,finance_user:int,day_start:string,day_end:string} */
    public $settings = ['hr_user' => 0, 'finance_user' => 0, 'day_start' => '09:00', 'day_end' => '17:00'];
    /** @var array<int,int> */
    public $workingDays = [0, 1, 2, 3, 4, 5, 6];
    /** @var string */
    public $now = '2026-10-26 09:00:00';
    /** @var array<int,array<int,int>> administratorsNeeded(request, order) calls */
    public $told = [];
    /** @var array<int,array<int,int>> administratorsIfStillWaiting(request, order) calls */
    public $later = [];
    /** @var int */
    public $synced = 0;
    /** @var array<int,array<int,mixed>> */
    public $audits = [];
    /** @var array<int,array{employee:object,row:object}> manager employee id => the substitute that covers */
    public $routes = [];
    /** @var bool the manager away may still decide */
    public $managerMayDecide = false;
    /** @var array<int,array<int,mixed>> */
    public $afterDecisions = [];
    /** @var array<int,array<int,mixed>> reminded / movedUp calls */
    public $deadlineTold = [];

    public function __construct()
    {
        $this->requests = new MemoryApprovalRequestStore();
        $this->workflows = new MemoryWorkflowStore();
    }

    /** Adds an employee (and their WordPress user, when $userId). */
    public function person(int $id, string $name, int $userId, int $managerId = 0): void
    {
        $this->employees[$id] = (object) ['id' => $id, 'name' => $name, 'wp_user_id' => $userId, 'active' => 1, 'department_id' => 0];
        if ($userId) $this->users[$userId] = true;
        if ($managerId) $this->supervisor[$id] = $managerId;
    }

    /** Adds a workflow the requests can be found under. @param list<array<int,mixed>> $levels */
    public function workflow(int $id, string $key, string $mode, array $levels = [], bool $active = true): void
    {
        $this->workflows->add($id, $key, $mode, $active, $levels);
        $this->requests->workflows[$id] = (object) ['workflow_key' => $key, 'approval_mode' => $mode];
    }

    public function transaction(): Transaction
    {
        $w = $this;
        return new class($w) implements Transaction {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function begin(): bool { $this->w->tx[] = 'begin'; return !$this->w->failBegin; }
            public function commit(): bool { $this->w->tx[] = 'commit'; return !$this->w->failCommit; }
            public function rollback(): void { $this->w->tx[] = 'rollback'; $this->w->requests->error = ''; $this->w->workflows->error = ''; }   // as wpdb: a query clears last_error
        };
    }

    public function policy(): ApprovalPolicy
    {
        $w = $this;
        return new class($w) implements ApprovalPolicy {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function selfRule(string $k): string { return $this->w->selfRule[$k] ?? 'allow'; }
            public function rejectNoteRequired(string $k): bool { return !empty($this->w->rejectNote[$k]); }
            public function settings(): array { return $this->w->settings; }
            public function workingDays(): array { return $this->w->workingDays; }
        };
    }

    public function directory(): ApprovalDirectory
    {
        $w = $this;
        return new class($w) implements ApprovalDirectory {
            private $w;
            public function __construct($w) { $this->w = $w; }
            private function emp(int $id): ?object { return $this->w->employees[$id] ?? null; }
            public function userId(int $id): int { $e = $this->emp($id); return $e ? (int) $e->wp_user_id : 0; }
            public function employee(int $id, bool $activeOnly): ?object { $e = $this->emp($id); return $e && (!$activeOnly || (int) $e->active === 1) ? clone $e : null; }
            public function employeeByUser(int $uid): ?object { foreach ($this->w->employees as $e) if ((int) $e->wp_user_id === $uid) return clone $e; return null; }
            public function name(int $id): string { $e = $this->emp($id); return $e ? (string) $e->name : ''; }
            public function userExists(int $uid): bool { return !empty($this->w->users[$uid]); }
            public function hasReports(int $id): bool { return in_array($id, $this->w->supervisor, true); }
            public function supervisorOf(int $id): ?object { $m = $this->w->supervisor[$id] ?? 0; return $m ? $this->employee($m, true) : null; }
            public function teamManager(int $id): ?object { return null; }
            public function departmentManager(int $id): ?object { return null; }
        };
    }

    public function substitutes(): SubstituteRouting
    {
        $w = $this;
        return new class($w) implements SubstituteRouting {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function route(int $manager, int $requester): ?array { $r = $this->w->routes[$manager] ?? null; return $r && (int) $r['employee']->id !== $requester ? $r : null; }
            public function managerMayDecide(object $step, int $uid): bool { return $this->w->managerMayDecide && !empty($step->for_employee_id) && (int) $this->w->employees[(int) $step->for_employee_id]->wp_user_id === $uid; }
            public function afterDecision(object $step, int $uid): void { $this->w->afterDecisions[] = [(int) $step->id, $uid]; }
        };
    }

    public function notifier(): ApprovalNotifier
    {
        $w = $this;
        return new class($w) implements ApprovalNotifier {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function administratorsNeeded(int $request, int $order): void { $this->w->told[] = [$request, $order]; }
        };
    }

    public function after(): AfterRequest
    {
        $w = $this;
        return new class($w) implements AfterRequest {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function syncSubstitutes(): void { $this->w->synced++; }
            public function administratorsIfStillWaiting(int $request, int $order): void { $this->w->later[] = [$request, $order]; }
        };
    }

    public function audit(): AuditLog
    {
        $w = $this;
        return new class($w) implements AuditLog {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function record(string $a, string $e, int $id, string $d): void { $this->w->audits[] = [$a, $e, $id, $d]; }
        };
    }

    public function clock(): Clock
    {
        $w = $this;
        return new class($w) implements Clock {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function now(): string { return $this->w->now; }
        };
    }

    public function permissions(): Permissions
    {
        $w = $this;
        return new class($w) implements Permissions {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function isAdmin(int $uid): bool { return !empty($this->w->admins[$uid]); }
        };
    }

    public function deadlineNotifier(): DeadlineNotifier
    {
        $w = $this;
        return new class($w) implements DeadlineNotifier {
            private $w;
            public function __construct($w) { $this->w = $w; }
            public function reminded(object $step, int $hours): void { $this->w->deadlineTold[] = ['reminded', (int) $step->id, $hours]; }
            public function movedUp(int $from, object $step, ?object $up, string $who): void { $this->w->deadlineTold[] = ['moved', (int) $step->id, $from, $up ? (int) $up->id : 0, $who]; }
        };
    }

    public function engine(): ApprovalEngine
    {
        return new ApprovalEngine($this->requests, $this->workflows, $this->transaction(), $this->policy(), $this->directory(), $this->substitutes(),
            $this->notifier(), $this->after(), $this->audit(), $this->clock(), $this->permissions());
    }

    public function workflowService(): WorkflowService
    {
        return new WorkflowService($this->workflows, $this->transaction(), $this->clock());
    }

    public function deadlines(): DeadlineService
    {
        return new DeadlineService($this->requests, $this->policy(), $this->directory(), $this->deadlineNotifier(), $this->audit(), $this->clock());
    }
}
