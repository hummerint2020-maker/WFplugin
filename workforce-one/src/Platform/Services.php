<?php
namespace WorkforceOne\Platform;

use WorkforceOne\Approvals\ApprovalEngine;
use WorkforceOne\Approvals\DeadlineService;
use WorkforceOne\Approvals\ApprovalPorts;
use WorkforceOne\Approvals\ApprovalReads;
use WorkforceOne\Approvals\Data\ApprovalRequestStore;
use WorkforceOne\Approvals\Data\SubstituteStore;
use WorkforceOne\Approvals\Data\WordPressApprovalRequestStore;
use WorkforceOne\Approvals\Data\WordPressSubstituteStore;
use WorkforceOne\Approvals\SubstitutePorts;
use WorkforceOne\Approvals\SubstituteService;
use WorkforceOne\Approvals\Data\WordPressWorkflowStore;
use WorkforceOne\Approvals\Data\WorkflowStore;
use WorkforceOne\Approvals\WorkflowService;
use WorkforceOne\Schedule\Data\ScheduleDayStore;
use WorkforceOne\Schedule\Data\WordPressScheduleDayStore;
use WorkforceOne\Schedule\ScheduleDayService;
use WorkforceOne\Schedule\ScheduleNotifier;
use WorkforceOne\Schedule\WeekLockService;

if (!defined('ABSPATH')) exit;

/**
 * The layered modules' services for one site (docs/tasks/layered-modules.md, 3.31.127): one explicit method per service,
 * built when first asked for. The plugin object is never passed in: what the services need from the rest of the plugin
 * comes as ports. One container per table prefix (the plugin keeps them), as the notifications inbox.
 */
final class Services
{
    /** @var \wpdb */
    private $db;
    /** @var AuditLog */
    private $audit;
    /** @var Clock */
    private $clock;
    /** @var People */
    private $people;
    /** @var OnceClaim */
    private $claim;
    /** @var ScheduleNotifier */
    private $scheduleNotifier;
    /** @var Transaction */
    private $tx;
    /** @var Permissions */
    private $permissions;
    /** @var ApprovalPorts */
    private $approvalPorts;
    /** @var SubstitutePorts */
    private $substitutePorts;
    /** @var array<string,string> the plugin's table names (by short name), where the old code used them */
    private $tables;
    /** @var array<string,object> */
    private $made = [];

    /** @param array<string,string> $tables */
    public function __construct(\wpdb $db, array $tables, AuditLog $audit, Clock $clock, People $people, OnceClaim $claim, ScheduleNotifier $scheduleNotifier,
        Transaction $tx, Permissions $permissions, ApprovalPorts $approvalPorts, SubstitutePorts $substitutePorts)
    {
        $this->substitutePorts = $substitutePorts;
        $this->db = $db;
        $this->tables = $tables;
        $this->audit = $audit;
        $this->clock = $clock;
        $this->people = $people;
        $this->claim = $claim;
        $this->scheduleNotifier = $scheduleNotifier;
        $this->tx = $tx;
        $this->permissions = $permissions;
        $this->approvalPorts = $approvalPorts;
    }

    public function scheduleDayStore(): ScheduleDayStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressScheduleDayStore($this->db, (string) ($this->tables['schedule'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function scheduleDays(): ScheduleDayService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ScheduleDayService($this->scheduleDayStore(), $this->people, $this->scheduleNotifier, $this->audit, $this->clock);
        return $this->made[__FUNCTION__];
    }

    public function weekLock(): WeekLockService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WeekLockService($this->scheduleDayStore(), $this->scheduleNotifier, $this->audit, $this->claim);
        return $this->made[__FUNCTION__];
    }

    public function approvalRequestStore(): ApprovalRequestStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressApprovalRequestStore($this->db);
        return $this->made[__FUNCTION__];
    }

    public function workflowStore(): WorkflowStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressWorkflowStore($this->db);
        return $this->made[__FUNCTION__];
    }

    public function approvals(): ApprovalEngine
    {
        if (!isset($this->made[__FUNCTION__])) {
            $p = $this->approvalPorts;
            $this->made[__FUNCTION__] = new ApprovalEngine($this->approvalRequestStore(), $this->workflowStore(), $this->tx, $p->policy, $p->directory,
                $this->substitutes(), $p->notifier, $p->after, $this->audit, $this->clock, $this->permissions);
        }
        return $this->made[__FUNCTION__];
    }

    public function approvalDeadlines(): DeadlineService
    {
        if (!isset($this->made[__FUNCTION__])) {
            $p = $this->approvalPorts;
            $this->made[__FUNCTION__] = new DeadlineService($this->approvalRequestStore(), $p->policy, $p->directory, $p->deadlines, $this->audit, $this->clock);
        }
        return $this->made[__FUNCTION__];
    }

    public function substituteStore(): SubstituteStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressSubstituteStore($this->db);
        return $this->made[__FUNCTION__];
    }

    public function substitutes(): SubstituteService
    {
        if (!isset($this->made[__FUNCTION__])) {
            $this->made[__FUNCTION__] = new SubstituteService($this->substituteStore(), $this->approvalRequestStore(), $this->approvalPorts->directory,
                $this->substitutePorts, $this->approvalPorts->after, $this->audit, $this->clock);
        }
        return $this->made[__FUNCTION__];
    }

    public function approvalReads(): ApprovalReads
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ApprovalReads($this->approvalRequestStore());
        return $this->made[__FUNCTION__];
    }

    public function workflows(): WorkflowService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WorkflowService($this->workflowStore(), $this->tx, $this->clock);
        return $this->made[__FUNCTION__];
    }
}
