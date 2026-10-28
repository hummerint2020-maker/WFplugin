<?php
namespace WorkforceOne\People;

use WorkforceOne\Organization\MoveRules;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\Platform\AuditLog;

if (!defined('ABSPATH')) exit;

/** The managers import (3.31.134, moved from trait-org-import.php): the people the file is compared with, and the saving of a previewed plan. */
final class OrgImportService
{
    /** @var PeopleStore */
    private $store;
    /** @var OrgDirectory */
    private $org;
    /** @var OrgMoveService */
    private $moves;
    /** @var AuditLog */
    private $audit;
    /** @var PeoplePorts */
    private $ports;

    public function __construct(PeopleStore $store, OrgDirectory $org, OrgMoveService $moves, AuditLog $audit, PeoplePorts $ports)
    {
        $this->store = $store;
        $this->org = $org;
        $this->moves = $moves;
        $this->audit = $audit;
        $this->ports = $ports;
    }

    /** Every employee for the import, by id. @return array<int,array{id:int,name:string,domain:string,title:string,manager:int,active:bool}> */
    public function people(): array
    {
        $managers = $this->org->managerIds();
        $out = [];
        foreach ($this->store->importRows() as $r) {
            $id = (int) $r->id;
            $out[$id] = ['id' => $id, 'name' => (string) $r->name, 'domain' => (string) $r->domain_name, 'title' => (string) ($r->job_title ?? ''), 'manager' => (int) ($managers[$id] ?? 0), 'active' => (int) $r->active === 1];
        }
        return $out;
    }

    /**
     * Saves the previewed changes. Every move is checked again (the people may have changed since the preview), in passes: a row that would make a
     * loop now may be fine once another row of the file is done (B no longer under A, then A under B).
     *
     * @param array<string,mixed> $plan ImportPlan::build()'s plan
     * @return array{moved:int,planned:int,titles:int,skipped:int}
     */
    public function apply(array $plan, string $date, string $reason, string $file): array
    {
        $scheduled = MoveRules::isScheduled($date, $this->ports->clock->today());
        $moved = 0;
        $planned = 0;
        $skipped = 0;
        $titles = 0;
        $pending = $plan['moves'];
        while ($pending) {
            $later = [];
            foreach ($pending as $m) {
                $code = $this->moves->check((int) $m['employee'], (int) $m['to'], $date, $reason, false, 0, !$scheduled);
                if ($code === 'loop') { $later[] = $m; continue; }
                if ($code) { $skipped++; continue; }
                if ($scheduled) { $this->moves->schedule((int) $m['employee'], (int) $m['to'], $date, $reason, 'import'); $planned++; continue; }
                $r = $this->moves->apply((int) $m['employee'], (int) $m['to'], $date, $reason, 'import');
                if ($r['ok']) $moved++; else $skipped++;
            }
            if (count($later) === count($pending)) { $skipped += count($later); break; }
            $pending = $later;
        }
        foreach ($plan['titles'] as $t) {
            if ($this->store->setJobTitle((int) $t['employee'], (string) $t['to'], $this->ports->clock->now())) {
                $titles++;
                $this->audit->record('employee_job_title', 'employee', (int) $t['employee'], '"' . $t['from'] . '" → "' . $t['to'] . '" · import');
            }
        }
        $this->audit->record('org_imported', 'settings', 0, $file . ': ' . $moved . ' moved, ' . $planned . ' scheduled for ' . $date . ', ' . $titles . ' job titles, ' . $skipped . ' skipped, ' . count($plan['problems']) . ' rows not used');
        return ['moved' => $moved, 'planned' => $planned, 'titles' => $titles, 'skipped' => $skipped];
    }
}
