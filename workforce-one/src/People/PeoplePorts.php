<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** What the people services need from the rest of the plugin, held together so the container takes one argument (3.31.133: the memo; 3.31.134: the org moves' ports). */
final class PeoplePorts
{
    /** @var PeopleMemo */
    public $memo;
    /** @var OrgClock */
    public $clock;
    /** @var Actor */
    public $actor;
    /** @var OrgApprovals */
    public $approvals;
    /** @var EmployeeChanged */
    public $changed;

    public function __construct(PeopleMemo $memo, OrgClock $clock, Actor $actor, OrgApprovals $approvals, EmployeeChanged $changed)
    {
        $this->memo = $memo;
        $this->clock = $clock;
        $this->actor = $actor;
        $this->approvals = $approvals;
        $this->changed = $changed;
    }
}
