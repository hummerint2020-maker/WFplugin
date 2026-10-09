<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\ProfileSummary;

/**
 * The employee app's My Profile page (?ews_view=profile): today, this week, recent attendance,
 * leave balances and achievements, plus the picture and password dialogs (their actions live in
 * trait-profile-account.php: ews_profile_photo_save, ews_profile_password_change).
 * View: templates/app/my-profile.php; script: assets/js/my-profile.js. Behaviour: tests/e2e_my_profile.py.
 */
trait EWS_My_Profile_Trait {

    /** The last seven days with Sign In / Out events: first Sign In, last Sign Out, total and result. */
    private function my_profile_recent($employee_id,$today){
        global $wpdb;
        $days=[];
        $rows=$wpdb->get_results($wpdb->prepare("SELECT work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date BETWEEN %s AND %s".$this->tl_live()." ORDER BY work_date DESC,event_at ASC",(int)$employee_id,date('Y-m-d',strtotime($today.' -6 days')),$today));
        foreach((array)$rows as $r){
            $day=&$days[$r->work_date];
            if(!isset($day))$day=['in'=>null,'out'=>null];
            if(in_array($r->event_type,['sign_in','late_sign_in'],true) && !$day['in'])$day['in']=$r;
            if($r->event_type==='sign_out')$day['out']=$r;
            unset($day);
        }
        $out=[];
        foreach($days as $date=>$day){
            $in=$day['in'];$sign_out=$day['out'];
            $total='—';
            if($in && $sign_out){$mins=max(0,(int)round((strtotime($sign_out->event_at)-strtotime($in->event_at))/60));$total=intdiv($mins,60).'h '.($mins%60).'m';}
            if(!$in)$status='No Sign In';
            elseif(!$sign_out)$status='Incomplete';
            else $status=ProfileSummary::todayResult('Office',$this->sign_in_classification($in->event_at,(int)$employee_id),$in->event_type==='late_sign_in');
            $out[]=['date'=>date('F j, Y',strtotime($date)),'in'=>$in?date_i18n('g:i A',strtotime($in->event_at)):'','out'=>$sign_out?date_i18n('g:i A',strtotime($sign_out->event_at)):'','total'=>$total,'status'=>$status];
        }
        return $out;
    }

    private function my_profile_content(){
        global $wpdb;
        $emp=$this->current_employee();
        if(!$emp)return $this->ews_empty_state('Profile unavailable','Your Workforce One employee account is not linked to this login.');
        $eid=(int)$emp->id;
        $today=current_time('Y-m-d');
        [$week_dates]=$this->week_dates_configured($today);
        $week_dates=(array)$week_dates;
        $holidays=$week_dates?$this->company_leave_dates(min($week_dates[0],$today),max($week_dates[count($week_dates)-1],$today)):$this->company_leave_dates($today,$today);

        // Today: a company holiday overrides the schedule.
        $schedule=$this->today_schedule_for_employee($eid);
        $events=$this->today_events($eid);
        $first_in=$events['sign_in']??($events['late_sign_in']??null);
        $planned=isset($holidays[$today])?'General Leave':($schedule?(string)$schedule->status:'Not Set');
        if($first_in)$today_result=$this->sign_in_classification_label($first_in->event_type==='late_sign_in'?'Late Arrival':$this->sign_in_classification($first_in->event_at,$eid));
        elseif($planned!=='General Leave' && $this->schedule_type_requires_sign_in($planned))$today_result='Not Signed In';
        else $today_result=$planned;

        $week=[];
        if($week_dates){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT work_date,status FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s",$eid,$week_dates[0],$week_dates[count($week_dates)-1]));
            $map=[];foreach((array)$rows as $r)$map[$r->work_date]=(string)$r->status;
            foreach($week_dates as $d)$week[$d]=isset($holidays[$d])?'General Leave':($map[$d]??'Not Set');
        }

        $teams=[];
        $team_ids=$this->team_ids_for_employee($eid);
        if($team_ids){
            $tt=$this->team_tables();
            $ph=implode(',',array_fill(0,count($team_ids),'%d'));
            $teams=(array)$wpdb->get_results($wpdb->prepare("SELECT name FROM {$tt['teams']} WHERE id IN ($ph) AND active=1 ORDER BY name ASC",...$team_ids));
        }

        $year=(int)current_time('Y');
        $leave_balances=[];
        $this->ensure_leave_schema();
        foreach((array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY name") as $t){
            $b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",$eid,(int)$t->id,$year));
            if($b)$leave_balances[]=['name'=>$t->name,'entitlement'=>(float)$b->entitlement,'available'=>max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending),'used'=>(float)$b->used,'pending'=>(float)$b->pending];
        }

        $image_type=!empty($emp->profile_image_type)?$emp->profile_image_type:'initials';
        $image_url=!empty($emp->profile_image_url)?$emp->profile_image_url:'';
        $avatar_key=!empty($emp->avatar_key)?$emp->avatar_key:'';
        $avatar_url=$image_type==='avatar'&&$avatar_key?$this->profile_avatar_url($avatar_key):'';
        $catalog=$this->profile_avatar_catalog();
        $avatar_urls=[];foreach(array_keys($catalog) as $key)$avatar_urls[$key]=$this->profile_avatar_url($key);
        $hours=$this->working_hours($eid);
        $initials=ProfileSummary::initials((string)$emp->name);

        wp_enqueue_script('workforce-one-my-profile');
        wp_enqueue_style('workforce-one-profile-page');
        return $this->render_template('app/my-profile',[
            'emp'=>$emp,'today'=>$today,'planned'=>$planned,'today_result'=>$today_result,'sign_in'=>$first_in?$first_in->event_at:'',
            'hours_label'=>$this->format_time_label($hours['start']).' – '.$this->format_time_label($hours['end']),
            'shift'=>$this->shift_for_employee($eid),'supervisor'=>$this->approval_related_employee($eid,'supervisor'),
            'team_label'=>$teams?implode(', ',array_map(function($x){return $x->name;},$teams)):'—',
            'week'=>$week,'recent'=>$this->my_profile_recent($eid,$today),'year'=>$year,'leave_balances'=>$leave_balances,
            'achievements'=>$this->achievements_enabled()?$this->employee_achievements($eid):null,
            'display_image'=>$image_type==='photo'&&$image_url?$image_url:($image_type==='avatar'?$avatar_url:''),
            'initials'=>$initials!==''?$initials:'ME',
            'catalog'=>$catalog,'avatar_urls'=>$avatar_urls,'avatar_key'=>$avatar_key,
            'profile_updated'=>sanitize_key($_GET['profile_updated']??''),'profile_error'=>sanitize_key($_GET['profile_error']??''),
            'password_updated'=>sanitize_key($_GET['password_updated']??''),'password_error'=>sanitize_key($_GET['password_error']??''),
            'urls'=>['schedule'=>$this->app_view_url('schedule'),'vacation'=>$this->app_view_url('vacation'),'time'=>$this->app_view_url('time')],
        ]);
    }
}
