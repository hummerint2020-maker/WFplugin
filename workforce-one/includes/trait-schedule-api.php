<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Api\ErrorMap;
use WorkforceOne\Api\Period;
use WorkforceOne\Schedule\ScheduleDays;

/**
 * Mobile app API (3.31.113): GET /me/schedule. The signed-in employee's own days over a period: status, shift and times (with the site's
 * offset) and branch, from the same helpers the Web schedule uses (src/Schedule/ScheduleDays.php). Read only; off by default.
 */
trait EWS_Schedule_Api_Trait {

    /** Built-in status names, translated; anything an administrator typed is shown as entered. @return array<string,string> */
    private function api_status_labels(){
        return [ScheduleDays::NOT_SET=>__('Not Set','workforce-one'),ScheduleDays::GENERAL_LEAVE=>__('General Leave','workforce-one'),'Absent'=>__('Absent','workforce-one')];
    }

    public function api_me_schedule($request){
        if($e=$this->api_app_guard($request))return $e;
        $emp=self::$api_session['employee']??null;
        if(!$emp)return $this->api_fail(ErrorMap::ACCOUNT_NOT_LINKED);
        $eid=(int)$emp->id;
        [,$anchor]=$this->week_dates_configured();
        [$period,$errors]=Period::parse((array)$request->get_query_params(),$anchor?gmdate('Y-m-d',(int)$anchor+12*HOUR_IN_SECONDS):current_time('Y-m-d'));
        if(!$period)return $this->api_fail(ErrorMap::VALIDATION_FAILED,['fields'=>$errors]);
        global $wpdb;
        $dates=$period['dates'];
        $rows=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT work_date,status FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s",$eid,$period['from'],$period['to'])) as $r)$rows[(string)$r->work_date]=(string)$r->status;
        $leave=$this->company_leave_dates($period['from'],$period['to']);
        $requires=[];$hours=[];
        foreach($dates as $d){
            $status=$rows[$d]??'';
            if($status===''||isset($leave[$d]))continue;
            if(!array_key_exists($status,$requires))$requires[$status]=(bool)$this->schedule_type_requires_sign_in($status);
            if($requires[$status])$hours[$d]=$this->working_hours($eid,$d);
        }
        $days=ScheduleDays::build($dates,$rows,$leave,$requires,$hours,$this->branch_days($eid,$dates),$this->working_days(),wp_timezone(),$this->api_status_labels());
        return $this->api_ok(['from'=>$period['from'],'to'=>$period['to'],'timezone'=>wp_timezone_string(),'today'=>current_time('Y-m-d'),'days'=>$days]);
    }
}
