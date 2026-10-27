<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Platform\AuditLog;
use WorkforceOne\Platform\Clock;
use WorkforceOne\Platform\OnceClaim;
use WorkforceOne\Platform\People;
use WorkforceOne\Platform\Services;
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
        $brief=function($eid){return $this->people_active_brief($eid);};
        $active=function($eid){return $this->people_employee($eid,true);};
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
        return new Services($db,['schedule'=>$this->schedule_table_name()],
            new class($audit) implements AuditLog {
                private $f;
                public function __construct(callable $f){ $this->f=$f; }
                public function record(string $action,string $entity,int $entityId,string $details): void { ($this->f)($action,$entity,$entityId,$details); }
            },
            new class implements Clock {
                public function now(): string { return current_time('mysql'); }
            },
            new class($brief,$active) implements People {
                private $brief;private $active;
                public function __construct(callable $brief,callable $active){ $this->brief=$brief;$this->active=$active; }
                public function activeBrief(int $employeeId): ?object { $r=($this->brief)($employeeId); return is_object($r)?$r:null; }
                public function activeEmployee(int $employeeId): ?object { $r=($this->active)($employeeId); return is_object($r)?$r:null; }
            },
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
            });
    }
}
