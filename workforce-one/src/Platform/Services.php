<?php
namespace WorkforceOne\Platform;

use WorkforceOne\Approvals\ApprovalEngine;
use WorkforceOne\Attendance\AttendanceLog;
use WorkforceOne\Attendance\Data\BreakStore;
use WorkforceOne\Attendance\Data\TimeLogStore;
use WorkforceOne\Attendance\Data\WordPressBreakStore;
use WorkforceOne\Attendance\Data\WordPressTimeLogStore;
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
use WorkforceOne\People\Data\DepartmentStore;
use WorkforceOne\People\Data\OrgMoveStore;
use WorkforceOne\People\Data\PeopleStore;
use WorkforceOne\People\Data\ReportingStore;
use WorkforceOne\People\Data\TeamStore;
use WorkforceOne\People\Data\WordPressDepartmentStore;
use WorkforceOne\People\Data\WordPressOrgMoveStore;
use WorkforceOne\People\Data\WordPressPeopleStore;
use WorkforceOne\People\Data\WordPressReportingStore;
use WorkforceOne\People\Data\WordPressTeamStore;
use WorkforceOne\People\DepartmentService;
use WorkforceOne\People\EmployeeService;
use WorkforceOne\People\OrgDirectory;
use WorkforceOne\People\OrgImportService;
use WorkforceOne\People\OrgMoveService;
use WorkforceOne\People\PeopleDirectory;
use WorkforceOne\People\PeoplePorts;
use WorkforceOne\People\ProfileService;
use WorkforceOne\People\ReportingService;
use WorkforceOne\People\TeamService;
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
    /** @var PeoplePorts */
    private $peoplePorts;
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
    public function __construct(\wpdb $db, array $tables, AuditLog $audit, Clock $clock, PeoplePorts $peoplePorts, OnceClaim $claim, ScheduleNotifier $scheduleNotifier,
        Transaction $tx, Permissions $permissions, ApprovalPorts $approvalPorts, SubstitutePorts $substitutePorts)
    {
        $this->substitutePorts = $substitutePorts;
        $this->db = $db;
        $this->tables = $tables;
        $this->audit = $audit;
        $this->clock = $clock;
        $this->peoplePorts = $peoplePorts;
        $this->claim = $claim;
        $this->scheduleNotifier = $scheduleNotifier;
        $this->tx = $tx;
        $this->permissions = $permissions;
        $this->approvalPorts = $approvalPorts;
    }

    public function peopleStore(): PeopleStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressPeopleStore($this->db, (string) ($this->tables['employees'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function reportingStore(): ReportingStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressReportingStore($this->db, (string) ($this->tables['employees'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function departmentStore(): DepartmentStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressDepartmentStore($this->db, (string) ($this->tables['employees'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function teamStore(): TeamStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressTeamStore($this->db, (string) ($this->tables['employees'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    /** Who the employees are (3.31.133): the people module's reads, also the Platform\People port the Schedule services use. */
    public function people(): PeopleDirectory
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new PeopleDirectory($this->peopleStore(), $this->peoplePorts->memo);
        return $this->made[__FUNCTION__];
    }

    /** Who reports to whom, and who manages what (3.31.133). */
    public function org(): OrgDirectory
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new OrgDirectory($this->peopleStore(), $this->reportingStore(), $this->departmentStore(), $this->teamStore());
        return $this->made[__FUNCTION__];
    }

    public function orgMoveStore(): OrgMoveStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressOrgMoveStore($this->db);
        return $this->made[__FUNCTION__];
    }

    /** Reporting lines, written (3.31.134). */
    public function reporting(): ReportingService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ReportingService($this->reportingStore(), $this->peoplePorts->clock);
        return $this->made[__FUNCTION__];
    }

    /** The org chart's moves (3.31.134). */
    public function orgMoves(): OrgMoveService
    {
        if (!isset($this->made[__FUNCTION__])) {
            $this->made[__FUNCTION__] = new OrgMoveService($this->orgMoveStore(), $this->reporting(), $this->people(), $this->org(), $this->tx, $this->audit, $this->peoplePorts);
        }
        return $this->made[__FUNCTION__];
    }

    /** The managers import (3.31.134). */
    public function orgImport(): OrgImportService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new OrgImportService($this->peopleStore(), $this->org(), $this->orgMoves(), $this->audit, $this->peoplePorts);
        return $this->made[__FUNCTION__];
    }

    /** The departments page (3.31.134). */
    public function departments(): DepartmentService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new DepartmentService($this->departmentStore(), $this->teamStore(), $this->people(), $this->audit, $this->clock);
        return $this->made[__FUNCTION__];
    }

    /** The teams page, and the teams of one employee (3.31.134). */
    public function teams(): TeamService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new TeamService($this->teamStore(), $this->departmentStore(), $this->peopleStore(), $this->people(), $this->tx, $this->audit, $this->clock);
        return $this->made[__FUNCTION__];
    }

    /** Adding, updating and archiving employees, and the Employees page's reads (3.31.135). */
    public function employees(): EmployeeService
    {
        if (!isset($this->made[__FUNCTION__])) {
            $this->made[__FUNCTION__] = new EmployeeService($this->peopleStore(), $this->departmentStore(), $this->teamStore(), $this->people(), $this->org(), $this->orgMoves(), $this->teams(), $this->audit, $this->peoplePorts);
        }
        return $this->made[__FUNCTION__];
    }

    /** The signed-in employee's profile picture (3.31.135). */
    public function profiles(): ProfileService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ProfileService($this->peopleStore());
        return $this->made[__FUNCTION__];
    }

    public function timeLogStore(): TimeLogStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressTimeLogStore($this->db, (string) ($this->tables['time_logs'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function breakStore(): BreakStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressBreakStore($this->db);
        return $this->made[__FUNCTION__];
    }

    /** What the attendance log says (3.31.136): today's events, the last location, the shift state, the break reads. */
    public function attendanceLog(): AttendanceLog
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new AttendanceLog($this->timeLogStore(), $this->breakStore());
        return $this->made[__FUNCTION__];
    }

    public function scheduleDayStore(): ScheduleDayStore
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new WordPressScheduleDayStore($this->db, (string) ($this->tables['schedule'] ?? ''));
        return $this->made[__FUNCTION__];
    }

    public function scheduleDays(): ScheduleDayService
    {
        if (!isset($this->made[__FUNCTION__])) $this->made[__FUNCTION__] = new ScheduleDayService($this->scheduleDayStore(), $this->people(), $this->scheduleNotifier, $this->audit, $this->clock);
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
