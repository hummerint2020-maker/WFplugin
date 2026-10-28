<?php
namespace WorkforceOne\People;

if (!defined('ABSPATH')) exit;

/** What the people services need from the rest of the plugin, held together so the container takes one argument (3.31.133: the memo; 3.31.134: the org moves' ports; 3.31.135: the employee writes' ports). */
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
    /** @var ShiftHistory */
    public $shifts;
    /** @var AppSessions */
    public $sessions;
    /** @var OpenAdvances */
    public $advances;
    /** @var WpUsers */
    public $users;
    /** @var OrgFeature */
    public $org;

    public function __construct(PeopleMemo $memo, OrgClock $clock, Actor $actor, OrgApprovals $approvals, EmployeeChanged $changed, ShiftHistory $shifts, AppSessions $sessions, OpenAdvances $advances, WpUsers $users, OrgFeature $org)
    {
        $this->memo = $memo;
        $this->clock = $clock;
        $this->actor = $actor;
        $this->approvals = $approvals;
        $this->changed = $changed;
        $this->shifts = $shifts;
        $this->sessions = $sessions;
        $this->advances = $advances;
        $this->users = $users;
        $this->org = $org;
    }
}
