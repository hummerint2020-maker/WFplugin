<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Approvals\AfterRequest;
use WorkforceOne\Approvals\ApprovalDirectory;
use WorkforceOne\Approvals\ApprovalNotifier;
use WorkforceOne\Approvals\ApprovalPolicy;
use WorkforceOne\Approvals\ApprovalPorts;
use WorkforceOne\Approvals\DeadlineNotifier;
use WorkforceOne\Approvals\LeaveFacts;
use WorkforceOne\Approvals\SubstituteNotifier;
use WorkforceOne\Approvals\SubstitutePolicy;
use WorkforceOne\Approvals\SubstitutePorts;
use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\OnceClaim;
use WorkforceOne\People\Actor;
use WorkforceOne\People\EmployeeChanged;
use WorkforceOne\People\OrgApprovals;
use WorkforceOne\People\OrgClock;
use WorkforceOne\People\PeoplePorts;
use WorkforceOne\Platform\Permissions;
use WorkforceOne\Platform\Services;
use WorkforceOne\Platform\Transaction;
use WorkforceOne\Schedule\ScheduleNotifier;

/**
 * The layered modules' services (3.31.127, docs/tasks/layered-modules.md): one container per site (table prefix), and the
 * adapters through which the services reach the rest of the plugin (the Audit Log, the clock, People, notifications).
 * The adapters are the only code here: the services never see this object, only the closures below.
 */
trait EWS_Services_Trait {

    /** @var array<string,\WorkforceOne\Platform\Services> by table prefix */
    private $ews_services = [];

    private function services(): Services {
        global $wpdb;
        $site=$wpdb->prefix;
        if(!isset($this->ews_services[$site]))$this->ews_services[$site]=$this->services_build($wpdb);
        return $this->ews_services[$site];
    }

    private function services_build(\wpdb $db): Services {
        $audit=function($a,$e,$id,$d){$this->audit($a,$e,$id,$d);};
        $day_changed=function($uid,$date,$status,$id){
            $label=date_i18n('l, d M Y',strtotime($date));
            $this->notify($uid,'schedule','Schedule Updated','Your schedule for <strong>'.esc_html($label).'</strong> is now <strong>'.esc_html($status).'</strong>.',
                ['type'=>'info','entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','schedule',$this->app_home_url())]);
        };
        $week_final=function($uid,$days,$link){
            $parts=[];
            foreach($days as $d=>$st)$parts[]=date_i18n('D',strtotime($d)).' '.$st;
            $this->notify($uid,'schedule',__('Next week\'s schedule is final','workforce-one'),implode(' · ',$parts),['url'=>$link,'entity'=>'week_lock']);
        };
        $reassign=function($employee,$from,$to,&$pending){return $this->approval_reassign_supervisor($employee,$from,$to,$pending);};
        $tell_manager=function($employee,$to,$pending){$this->approval_reassign_notify($employee,$to,$pending);};
        $employee_changed=function($employee,$wp_user){$this->invalidate_employee_runtime_cache($employee,$wp_user);};
        return new Services($db,['schedule'=>$this->schedule_table_name(),'employees'=>$this->employees],
            new class($audit) implements AuditLog {
                private $f;
                public function __construct(callable $f){ $this->f=$f; }
                public function record(string $action,string $entity,int $entityId,string $details): void { ($this->f)($action,$entity,$entityId,$details); }
            },
            new class implements Clock {
                public function now(): string { return current_time('mysql'); }
            },
            new PeoplePorts(self::people_memo(),
                new class implements OrgClock {
                    public function now(): string { return current_time('mysql'); }
                    public function today(): string { return current_time('Y-m-d'); }
                    public function timestamp(): int { return (int)current_time('timestamp'); }
                },
                new class implements Actor {
                    public function userId(): int { return get_current_user_id(); }
                },
                new class($reassign,$tell_manager) implements OrgApprovals {
                    private $reassign; private $tell;
                    public function __construct(callable $reassign,callable $tell){ $this->reassign=$reassign; $this->tell=$tell; }
                    public function reassignSupervisor(int $employeeId,int $fromEmployeeId,int $toEmployeeId,int &$pending){ return ($this->reassign)($employeeId,$fromEmployeeId,$toEmployeeId,$pending); }
                    public function tellNewManager(int $employeeId,int $toEmployeeId,int $pending): void { ($this->tell)($employeeId,$toEmployeeId,$pending); }
                },
                new class($employee_changed) implements EmployeeChanged {
                    private $f;
                    public function __construct(callable $f){ $this->f=$f; }
                    public function changed(int $employeeId,int $wpUserId): void { ($this->f)($employeeId,$wpUserId); }
                }),
            new class($db) implements OnceClaim {
                private $db;
                public function __construct(\wpdb $db){ $this->db=$db; }
                public function current(string $name): string { return (string)get_option($name,''); }
                public function claim(string $name,string $value): bool {
                    add_option($name,'','','no');
                    $claimed=$this->db->query($this->db->prepare("UPDATE {$this->db->options} SET option_value=%s WHERE option_name=%s AND option_value<>%s",$value,$name,$value));
                    wp_cache_delete($name,'options');
                    return $claimed===1;
                }
            },
            new class($day_changed,$week_final) implements ScheduleNotifier {
                private $changed;private $final;
                public function __construct(callable $changed,callable $final){ $this->changed=$changed;$this->final=$final; }
                public function dayChanged(int $wpUserId,string $date,string $status,int $scheduleId): void { ($this->changed)($wpUserId,$date,$status,$scheduleId); }
                public function weekFinal(int $wpUserId,array $days,string $link): void { ($this->final)($wpUserId,$days,$link); }
            },
            new class($db) implements Transaction {
                private $db;
                public function __construct(\wpdb $db){ $this->db=$db; }
                public function begin(): bool { return $this->db->query('START TRANSACTION')!==false; }
                public function commit(): bool { return $this->db->query('COMMIT')!==false; }
                public function rollback(): void { $this->db->query('ROLLBACK'); }
            },
            new class implements Permissions {
                public function isAdmin(int $userId): bool { return (bool)user_can($userId,'manage_options'); }
            },
            $this->approval_ports(),$this->substitute_ports());
    }

    /** The approvals engine's ports (3.31.128): the adapters through which it reaches settings, people, messages and the end of the page. */
    private function approval_ports(): ApprovalPorts {
        $call=function($fn,...$args){return $this->$fn(...$args);};
        $after_page=function($request_id,$order){
            // At the end of the page, once the caller's transaction is over (it may have rolled back): tell the administrators only if the level still waits for them.
            add_action('shutdown',function()use($request_id,$order){
                if($this->services()->approvals()->stillWithAdministrators($request_id,$order))$this->approval_notify_administrators($request_id,$order);
            },5);
        };
        return new ApprovalPorts(
            new class($call) implements ApprovalPolicy {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function selfRule(string $workflowKey): string { return (string)($this->call)('approval_self_rule',$workflowKey); }
                public function rejectNoteRequired(string $workflowKey): bool { return (bool)($this->call)('approval_reject_note_required',$workflowKey); }
                public function settings(): array { return ($this->call)('approval_settings'); }
                public function workingDays(): array { return (array)($this->call)('working_days'); }            },
            new class($call) implements ApprovalNotifier {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function administratorsNeeded(int $approvalRequestId,int $stepOrder): void { ($this->call)('approval_notify_administrators',$approvalRequestId,$stepOrder); }
            },
            new class($call,$after_page) implements AfterRequest {
                private $call;private $later;
                public function __construct(callable $call,callable $later){ $this->call=$call;$this->later=$later; }
                public function syncSubstitutes(): void { ($this->call)('substitutes_sync_at_shutdown'); }
                public function administratorsIfStillWaiting(int $approvalRequestId,int $stepOrder): void { ($this->later)($approvalRequestId,$stepOrder); }
            },
            new class($call) implements ApprovalDirectory {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                private function obj($r): ?object { return is_object($r)?$r:null; }
                public function userId(int $employeeId): int { return (int)($this->call)('people_user_id',$employeeId); }
                public function employee(int $employeeId,bool $activeOnly): ?object { return $this->obj(($this->call)('people_employee',$employeeId,$activeOnly)); }
                public function employeeByUser(int $userId): ?object { return $this->obj(($this->call)('people_employee_by_user',$userId)); }
                public function name(int $employeeId): string { return (string)($this->call)('people_name',$employeeId); }
                public function userExists(int $userId): bool { return (bool)get_user_by('id',$userId); }
                public function supervisorOf(int $employeeId): ?object { return $this->obj(($this->call)('approval_related_employee',$employeeId,'supervisor')); }
                public function teamManager(int $employeeId): ?object { return $this->obj(($this->call)('team_manager_for_employee',$employeeId)); }
                public function departmentManager(int $employeeId): ?object { return $this->obj(($this->call)('people_department_manager',$employeeId)); }
                public function hasReports(int $employeeId): bool { return (bool)($this->call)('people_has_reports',$employeeId); }
            },
            new class($call) implements DeadlineNotifier {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function reminded(object $step,int $remindHours): void { ($this->call)('approval_tell_reminded',$step,$remindHours); }
                public function movedUp(int $fromUserId,object $step,?object $up,string $requesterName): void { ($this->call)('approval_tell_moved_up',$fromUserId,$step,$up,$requesterName); }
            });
    }

    /** The substitutes' ports (3.31.128): their switch and settings, leave facts and the messages they send. */
    private function substitute_ports(): SubstitutePorts {
        $call=function($fn,...$args){return $this->$fn(...$args);};
        return new SubstitutePorts(
            new class($call) implements SubstitutePolicy {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function enabled(): bool { return (bool)($this->call)('substitutes_enabled'); }
                public function settings(): array { return ($this->call)('substitute_settings'); }
            },
            new class($call) implements LeaveFacts {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function statusOf(int $leaveRequestId): string { return (string)($this->call)('leave_status_of',$leaveRequestId); }
                public function awayBetween(int $employeeId,string $from,string $to): bool { return (bool)($this->call)('leave_away_between',$employeeId,$from,$to); }
            },
            new class($call) implements SubstituteNotifier {
                private $call;
                public function __construct(callable $call){ $this->call=$call; }
                public function picked(object $row): void { ($this->call)('substitute_tell_picked',$row); }
                public function started(object $row,int $moved,string $managerName): void { ($this->call)('substitute_tell_started',$row,$moved,$managerName); }
                public function ended(int $managerUserId,object $row,int $decided,int $back,int $still,string $substituteName): void { ($this->call)('substitute_tell_ended',$managerUserId,$row,$decided,$back,$still,$substituteName); }
            });
    }
}
