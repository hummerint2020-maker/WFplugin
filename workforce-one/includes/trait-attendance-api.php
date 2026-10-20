<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Api\AttendanceToday;
use WorkforceOne\Api\ErrorMap;
use WorkforceOne\Schedule\ScheduleDays;

/**
 * Mobile app API (3.31.113): GET /attendance/today. Where the signed-in employee is in the current work day and what they could do now,
 * from AttendanceService::today(): the same rules and facts as recording (src/Attendance/AttendanceService.php). Read only; off by default.
 */
trait EWS_Attendance_Api_Trait {

    public function api_attendance_today($request){
        if($e=$this->api_app_guard($request))return $e;
        $emp=self::$api_session['employee']??null;
        if(!$emp)return $this->api_fail(ErrorMap::ACCOUNT_NOT_LINKED);
        $eid=(int)$emp->id;
        $state=$this->attendance_service()->today();
        $day=(string)$state->get('day');
        $shift=null;
        if($state->get('requires_sign_in') && !$state->get('general_leave')){
            $built=ScheduleDays::build([$day],[$day=>(string)$state->get('status')],[],[(string)$state->get('status')=>true],[$day=>$this->working_hours($eid,$day)],[],$this->working_days(),wp_timezone());
            $shift=$built[0]['shift'];
        }
        $b=$this->branch_today($eid);
        $branch=$b?['id'=>(int)$b['id'],'name'=>(string)$b['name'],'planned'=>(bool)$b['planned']]:null;
        $breaks=['enabled'=>false,'per_day'=>0,'duration_minutes'=>0,'remaining'=>0,'sessions'=>[]];
        if($this->break_enabled()){
            $d=$this->break_ui_data($eid);
            $sessions=[];
            foreach($d['sessions'] as $s)$sessions[]=['start_at'=>(string)$s->start_at,'end_at'=>$s->status==='Open'||empty($s->end_at)?null:(string)$s->end_at,'minutes'=>$s->status==='Open'?null:(int)($s->actual_minutes??0)];
            $breaks=['enabled'=>true,'per_day'=>(int)$d['allowed'],'duration_minutes'=>(int)$d['duration'],'remaining'=>(int)$d['remaining'],'sessions'=>$sessions];
        }
        $labels=$this->api_status_labels()+['On Time'=>__('On Time','workforce-one'),'Late Arrival'=>__('Late Arrival','workforce-one')];
        return $this->api_ok(AttendanceToday::document($state,current_time('Y-m-d'),wp_timezone(),$shift,$branch,$breaks,$labels));
    }
}
