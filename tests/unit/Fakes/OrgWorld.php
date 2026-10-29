<?php
namespace WorkforceOne\Tests\Fakes;

use WorkforceOne\People\Actor;
use WorkforceOne\People\AppSessions;
use WorkforceOne\People\EmployeeChanged;
use WorkforceOne\People\OpenAdvances;
use WorkforceOne\People\OrgApprovals;
use WorkforceOne\People\OrgClock;
use WorkforceOne\People\OrgFeature;
use WorkforceOne\People\PeopleMemo;
use WorkforceOne\People\PeoplePorts;
use WorkforceOne\People\ShiftHistory;
use WorkforceOne\People\WpUsers;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\Transaction;

require_once __DIR__ . '/MemoryPeopleStore.php';
require_once __DIR__ . '/MemoryOrgStores.php';

/**
 * The shared world of the org-writes unit tests (3.31.134): one ordered log that every fake writes to (stores, the transaction, the audit log, the
 * ports), and a set of operations that fail. Stores write "store.operation", the others "tx begin", "audit action", "approvals reassign", ….
 */
final class OrgWorld
{
    /** @var list<string> */
    public $log = [];
    /** @var array<string,bool> operation => fails (false/0 from a store, false from begin/commit) */
    public $fail = [];

    public function note(string $what): void
    {
        $this->log[] = $what;
    }

    public function fails(string $what): bool
    {
        return !empty($this->fail[$what]);
    }

    /** The log entries that start with this prefix. @return list<string> */
    public function only(string $prefix): array
    {
        return array_values(array_filter($this->log, function ($l) use ($prefix) { return strpos($l, $prefix) === 0; }));
    }
}

final class OrgTx implements Transaction
{
    /** @var OrgWorld */
    private $w;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function begin(): bool
    {
        $this->w->note('tx begin');
        return !$this->w->fails('tx.begin');
    }

    public function commit(): bool
    {
        $this->w->note('tx commit');
        return !$this->w->fails('tx.commit');
    }

    public function rollback(): void
    {
        $this->w->note('tx rollback');
    }
}

final class OrgAudit implements AuditLog
{
    /** @var OrgWorld */
    private $w;
    /** @var list<array{0:string,1:string,2:int,3:string}> */
    public $rows = [];

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function record(string $action, string $entity, int $entityId, string $details): void
    {
        $this->rows[] = [$action, $entity, $entityId, $details];
        $this->w->note('audit ' . $action . ' ' . $entityId);
    }
}

final class OrgFixedClock implements OrgClock, Clock
{
    /** @var string */
    public $now = '2026-10-28 09:00:00';

    public function now(): string
    {
        return $this->now;
    }

    public function today(): string
    {
        return substr($this->now, 0, 10);
    }

    public function timestamp(): int
    {
        return (int) strtotime($this->now . ' UTC');
    }
}

final class OrgFixedActor implements Actor
{
    /** @var int */
    public $id = 7;

    public function userId(): int
    {
        return $this->id;
    }
}

final class OrgFakeApprovals implements OrgApprovals
{
    /** @var OrgWorld */
    private $w;
    /** @var int what reassignSupervisor reports as moved */
    public $moved = 2;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function reassignSupervisor(int $employeeId, int $fromEmployeeId, int $toEmployeeId, int &$pending)
    {
        $this->w->note('approvals reassign ' . $employeeId . ' ' . $fromEmployeeId . '>' . $toEmployeeId);
        if ($this->w->fails('approvals.reassign')) { $pending = 0; return false; }
        $pending = $this->moved;
        return $this->moved;
    }

    public function tellNewManager(int $employeeId, int $toEmployeeId, int $pending): void
    {
        $this->w->note('approvals tell ' . $employeeId . ' ' . $toEmployeeId . ' ' . $pending);
    }
}

final class OrgFakeChanged implements EmployeeChanged
{
    /** @var OrgWorld */
    private $w;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function changed(int $employeeId, int $wpUserId): void
    {
        $this->w->note('changed ' . $employeeId . ' ' . $wpUserId);
    }
}

final class OrgFakeShifts implements ShiftHistory
{
    /** @var OrgWorld */
    private $w;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function fromBeginning(int $employeeId, int $shiftId)
    {
        $this->w->note('shifts beginning ' . $employeeId . ' ' . $shiftId);
        return $this->w->fails('shifts.beginning') ? 'busy' : true;
    }

    public function from(int $employeeId, int $shiftId, string $date)
    {
        $this->w->note('shifts from ' . $employeeId . ' ' . $shiftId . ' ' . $date);
        return $this->w->fails('shifts.from') ? 'busy' : true;
    }
}

final class OrgFakeSessions implements AppSessions
{
    /** @var OrgWorld */
    private $w;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function revoke(int $wpUserId, string $reason): void
    {
        $this->w->note('sessions revoke ' . $wpUserId . ' ' . $reason);
    }
}

final class OrgFakeAdvances implements OpenAdvances
{
    /** @var OrgWorld */
    private $w;
    /** @var bool */
    public $on = false;
    /** @var float */
    public $left = 0.0;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function enabled(): bool
    {
        $this->w->note('advances enabled');
        return $this->on;
    }

    public function leftFor(int $employeeId): float
    {
        $this->w->note('advances left ' . $employeeId);
        return $this->left;
    }
}

final class OrgFakeUsers implements WpUsers
{
    /** @var OrgWorld */
    private $w;
    /** @var list<int> */
    public $ids = [];

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function exists(int $userId): bool
    {
        $this->w->note('users exists ' . $userId);
        return in_array($userId, $this->ids, true);
    }
}

final class OrgFakeFeature implements OrgFeature
{
    /** @var OrgWorld */
    private $w;
    /** @var bool */
    public $on = false;
    /** @var bool */
    public $title = false;

    public function __construct(OrgWorld $w)
    {
        $this->w = $w;
    }

    public function enabled(): bool
    {
        $this->w->note('org enabled');
        return $this->on;
    }

    public function titleRequired(): bool
    {
        $this->w->note('org title required');
        return $this->title;
    }
}

/** Everything an org-writes test needs, wired the way the container does it. */
final class OrgSet
{
    /** @var OrgWorld */
    public $w;
    /** @var MemoryPeopleStore */
    public $people;
    /** @var MemoryReportingStore */
    public $rep;
    /** @var MemoryDepartmentStore */
    public $dep;
    /** @var MemoryTeamStore */
    public $teams;
    /** @var MemoryOrgMoveStore */
    public $moves;
    /** @var OrgTx */
    public $tx;
    /** @var OrgAudit */
    public $audit;
    /** @var OrgFixedClock */
    public $clock;
    /** @var OrgFixedActor */
    public $actor;
    /** @var OrgFakeApprovals */
    public $approvals;
    /** @var OrgFakeShifts */
    public $shifts;
    /** @var OrgFakeSessions */
    public $sessions;
    /** @var OrgFakeAdvances */
    public $advances;
    /** @var OrgFakeUsers */
    public $users;
    /** @var OrgFakeFeature */
    public $org;
    /** @var PeoplePorts */
    public $ports;

    public function __construct()
    {
        $this->w = new OrgWorld();
        $this->people = new MemoryPeopleStore();
        $this->rep = new MemoryReportingStore($this->people);
        $this->dep = new MemoryDepartmentStore($this->people);
        $this->teams = new MemoryTeamStore($this->people);
        $this->moves = new MemoryOrgMoveStore();
        foreach ([$this->rep, $this->dep, $this->teams, $this->moves, $this->people] as $s) $s->world = $this->w;
        $this->tx = new OrgTx($this->w);
        $this->audit = new OrgAudit($this->w);
        $this->clock = new OrgFixedClock();
        $this->actor = new OrgFixedActor();
        $this->approvals = new OrgFakeApprovals($this->w);
        $this->shifts = new OrgFakeShifts($this->w);
        $this->sessions = new OrgFakeSessions($this->w);
        $this->advances = new OrgFakeAdvances($this->w);
        $this->users = new OrgFakeUsers($this->w);
        $this->org = new OrgFakeFeature($this->w);
        $this->ports = new PeoplePorts(new PeopleMemo(), $this->clock, $this->actor, $this->approvals, new OrgFakeChanged($this->w), $this->shifts, $this->sessions, $this->advances, $this->users, $this->org);
    }
}
