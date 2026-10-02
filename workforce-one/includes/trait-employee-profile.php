<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\ProfileSummary;
use WorkforceOne\Leave\Balance;

/**
 * wp-admin employee profile (Employees → View Profile): today, 30-day attendance, achievements,
 * leave balances, organisation and presence verification requests.
 * Rules: src/Employees/ProfileSummary.php. Behaviour: tests/e2e_employee_profile.py.
 */
trait EWS_Employee_Profile_Trait {

    public function admin_employee_profile(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        global $wpdb;
        $employee_id=absint($_GET['employee_id']??0);
        $emp=$employee_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id)):null;
        if(!$emp)wp_die('Employee not found.');

        $this->ensure_teams_schema();
        $tt=$this->team_tables();
        $teams=(array)$wpdb->get_results($wpdb->prepare("SELECT t.id,t.name,t.manager_employee_id FROM {$tt['teams']} t INNER JOIN {$tt['members']} m ON m.team_id=t.id AND m.employee_id=%d AND m.active=1 WHERE t.active=1 ORDER BY t.name ASC",$employee_id));
        $team_labels=[];$manager_of=[];
        foreach($teams as $tm){
            $is_manager=(int)$tm->manager_employee_id===$employee_id;
            $team_labels[]=$tm->name.($is_manager?' · Manager':'');
            if($is_manager)$manager_of[]=$tm->name;
        }
        $supervisor=$this->approval_related_employee($employee_id,'supervisor');

        // Today: the first Sign In is judged against this employee's own shift.
        $today=current_time('Y-m-d');
        $schedule=$this->schedule_for_employee_date($employee_id,$today);
        $events=(array)$wpdb->get_results($wpdb->prepare("SELECT event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY event_at ASC",$employee_id,$today));
        $in=null;$out='';
        foreach($events as $ev){
            if(!$in && ($ev->event_type==='sign_in'||$ev->event_type==='late_sign_in'))$in=$ev;
            if(!$out && $ev->event_type==='sign_out')$out=$ev->event_at;
        }
        $planned=$schedule?$schedule->status:'Not Set';
        $today_result=ProfileSummary::todayResult($planned,$in?$this->sign_in_classification($in->event_at,$employee_id):null,$in&&$in->event_type==='late_sign_in');

        $period_start=date('Y-m-d',strtotime('-29 days',current_time('timestamp')));
        [$att_rows]=$this->report_attendance_data($period_start,$today,'all',$employee_id,'all',$emp->active?'active':'all');
        $att_rows=(array)$att_rows;
        $recent=[];
        foreach(array_slice(array_reverse($att_rows),0,10) as $ar){
            $recent[]=['date'=>date_i18n(get_option('date_format'),strtotime($ar['date'])),'planned'=>$ar['planned'],'sign_in'=>$ar['sign_in']?:'—','sign_out'=>$ar['sign_out']?:'—','result'=>$ar['result'],'class'=>strtolower(str_replace(' ','-',$ar['result']))];
        }

        $this->ensure_leave_schema();
        $year=(int)current_time('Y');
        $balances=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT t.name,b.entitlement,b.used,b.pending FROM {$wpdb->prefix}ews_leave_balances b JOIN {$wpdb->prefix}ews_leave_types t ON t.id=b.leave_type_id AND t.active=1 WHERE b.employee_id=%d AND b.leave_year=%d ORDER BY t.name ASC",$employee_id,$year)) as $b){
            $balances[]=['name'=>$b->name,'used'=>(float)$b->used,'pending'=>(float)$b->pending,'available'=>Balance::available((float)$b->entitlement,(float)$b->used,(float)$b->pending)];
        }

        $presence=null;
        if($this->presence_verification_enabled() && $this->can('ews_manage_attendance')){
            [,$presence_table]=$this->presence_tables();
            $presence=[
                'locations'=>(array)$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC"),
                'latest'=>(array)$wpdb->get_results($wpdb->prepare("SELECT v.*,l.name location_name FROM {$presence_table} v LEFT JOIN {$this->locations} l ON l.id=v.location_id WHERE v.employee_id=%d ORDER BY v.id DESC LIMIT 5",$employee_id)),
            ];
            if(!$presence['locations'])$presence=null;
        }

        $achievements=null;
        if($this->achievements_enabled()){
            $achievements=[];
            foreach((array)$this->employee_achievements($employee_id) as $a){
                $achievements[]=['icon'=>$a->icon,'name'=>$a->name,'description'=>$a->description,'style'=>$a->badge_style?:'circle',
                    'earned'=>date_i18n(get_option('date_format'),strtotime($a->earned_at)),
                    'delete_url'=>wp_nonce_url(admin_url('admin-post.php?action=ews_achievement_award_delete&award_id='.(int)$a->id.'&employee_id='.$employee_id),'ews_achievement_award_delete_'.(int)$a->id)];
            }
        }

        $shift=$this->shift_for_employee($employee_id);$hours=$this->working_hours($employee_id);
        $user=$emp->wp_user_id?get_userdata((int)$emp->wp_user_id):null;
        echo $this->render_template('admin/employee-profile',[
            'emp'=>$emp,'employee_id'=>$employee_id,
            'initials'=>ProfileSummary::initials((string)$emp->name),
            'photo'=>(($emp->profile_image_type??'')==='photo' && !empty($emp->profile_image_url))?$emp->profile_image_url:'',
            'active'=>(bool)(int)$emp->active,'teams'=>$team_labels,'manager_of'=>$manager_of,
            'supervisor'=>$supervisor?$supervisor->name.' ('.$supervisor->domain_name.')':'—',
            'user_login'=>$user?$user->user_login:'Not linked',
            'since'=>$emp->created_at?date_i18n(get_option('date_format'),strtotime($emp->created_at)):'—',
            'shift'=>$shift?$shift['name']:'Company Default',
            'hours'=>$this->format_time_label($hours['start']).' – '.$this->format_time_label($hours['end']),
            'today'=>['planned'=>$planned,'note'=>$schedule&&$schedule->note?$schedule->note:'Schedule status','result'=>$today_result,
                'in'=>$in?date_i18n('g:i A',strtotime($in->event_at)):'','out'=>$out?date_i18n('g:i A',strtotime($out)):''],
            'stats'=>ProfileSummary::attendance($att_rows),'recent'=>$recent,
            'year'=>$year,'balances'=>$balances,'presence'=>$presence,'achievements'=>$achievements,
            'achievement_deleted'=>isset($_GET['achievement_deleted']),
            'employees_url'=>admin_url('admin.php?page=ews31-employees'),'post_url'=>admin_url('admin-post.php'),
        ]);
    }
}
