<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * The employee app's Dashboard (?ews_view=dashboard): a workforce snapshot for managers
 * (ews_view_dashboard, limited to their department) and a personal dashboard for employees,
 * with Today's Moments, the poll and Smart Nudges. View: templates/app/dashboard.php (+ partials),
 * styles assets/css/app-home.css; the greeting goes to the frame's header ($app_header_greeting).
 * Nudge and moment rules stay in trait-frontend.php. Behaviour: tests/e2e_dashboard_view.py.
 */
trait EWS_Dashboard_View_Trait {

    /** A day's schedule as shown to the employee: a company holiday overrides it. */
    private function dashboard_day_status($status,$date,array $holidays){
        return isset($holidays[$date])?'General Leave':((string)$status!==''?(string)$status:'Not Set');
    }

    private function dashboard_content(){
        global $wpdb;
        wp_enqueue_style('workforce-one-home');
        $today=current_time('Y-m-d');
        $holidays=$this->company_leave_dates($today,$today);
        $tiles=array_values(array_filter($this->app_navigation()[0],function($x){return $x['key']!=='dashboard';}));
        $common=[
            'tiles'=>array_slice($tiles,0,7),'notifications_label'=>__('Notifications','workforce-one'),
            'unread'=>$this->notification_unread_count(),'today_label'=>date_i18n('l, d F Y'),
            'moments'=>$this->employee_moments_for_today(),'poll_html'=>$this->employee_poll_markup(),
            'holiday'=>$holidays[$today]??'',
            'urls'=>['schedule'=>$this->app_view_url('schedule'),'attendance'=>$this->app_view_url('attendance'),'notifications'=>$this->app_view_url('notifications'),'time'=>$this->app_view_url('time')],
            'empty'=>function($title,$text='',$url='',$label=''){return $this->ews_empty_state($title,$text,$url,$label);},
            'home_tasks'=>$this->task_home_list(),'tasks_url'=>$this->app_view_url('tasks'),'post_url'=>admin_url('admin-post.php'),
        ];

        /* Managers get the workforce overview of the employees they manage. */
        if($this->can('ews_view_dashboard')){
            $emps=$this->department_scoped_employees($this->emps());
            $status=[];
            if($emps){
                $ids=array_map(function($e){return (int)$e->id;},$emps);
                $ph=implode(',',array_fill(0,count($ids),'%d'));
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,status FROM {$this->schedule} WHERE work_date=%s AND employee_id IN ($ph)",array_merge([$today],$ids))) as $r)$status[(int)$r->employee_id]=trim((string)$r->status);
            }
            $plans=[];
            foreach($emps as $e){$st=$status[(int)$e->id]??'';$type=$st!==''?$this->schedule_type_config($st):null;$plans[]=[$st,$type?$type['attendance_rule']:null];}
            $names=$this->working_day_names();
            $this->app_header_greeting=[__('Good to see you.','workforce-one'),date_i18n('l, d F Y')];
            return $this->render_template('app/dashboard',$common+[
                'manager'=>true,'count'=>count($emps),'snapshot'=>Insights::snapshot($plans,isset($holidays[$today])),
                'week_days'=>array_map(function($d)use($names){return $names[$d]??'';},$this->working_days()),
                'can_attendance'=>$this->can('ews_manage_attendance'),
            ]);
        }

        /* Employees get a personal dashboard. */
        $emp=$this->current_employee();
        if(!$emp)return $this->render_template('app/dashboard',$common+['manager'=>false,'emp'=>null]);
        $sch=$this->today_schedule_for_employee($emp->id);
        $events=$this->today_events($emp->id);
        $loc=$this->ews_v321_employee_location($emp->id)?:$this->ews_default_location();
        // Branches (3.31.72): today's branch, and each upcoming day's when it is planned "By the schedule".
        $branch_today=$this->branch_today((int)$emp->id);
        $branch_names=$branch_today&&$this->branch_settings()['mode']==='schedule'?array_map(function($l){return (string)$l->name;},$this->branch_locations()):[];
        $next=(array)$wpdb->get_results($wpdb->prepare("SELECT work_date,status,location_id FROM {$this->schedule} WHERE employee_id=%d AND work_date>%s ORDER BY work_date ASC LIMIT 5",$emp->id,$today));
        $upcoming=[];
        if($next){
            $later=$this->company_leave_dates($next[0]->work_date,$next[count($next)-1]->work_date);
            foreach($next as $r){
                $st=$this->dashboard_day_status($r->status,$r->work_date,$later);
                $upcoming[]=['date'=>date_i18n('D, d M',strtotime($r->work_date)),'status'=>$st,'branch'=>$branch_names&&$r->location_id&&$this->schedule_type_requires_location($st)?($branch_names[(int)$r->location_id]??''):''];
            }
        }
        $status=$this->dashboard_day_status($sch?$sch->status:'',$today,$holidays);
        $sign_in=$events['sign_in']->event_at??($events['late_sign_in']->event_at??null);
        $sign_out=$events['sign_out']->event_at??null;
        $ot=$this->overtime_enabled()?$this->overtime_attendance_summary((int)$emp->id,$today):null;
        if($ot && !($ot['approved_minutes']>0 || $ot['actual_approved_minutes']>0 || $ot['unapproved_extra_minutes']>0 || !empty($ot['approved_requests'])))$ot=null;
        $first_name=trim(explode(' ',trim((string)$emp->name))[0]);
        /* translators: %s: employee first name */
        $this->app_header_greeting=[sprintf(__('Good to see you, %s.','workforce-one'),$first_name!==''?$first_name:(string)$emp->name),date_i18n('l, d F Y')];
        return $this->render_template('app/dashboard',$common+[
            'manager'=>false,'emp'=>$emp,'first_name'=>$first_name!==''?$first_name:(string)$emp->name,
            'status'=>$status,'is_working'=>$sch && !$holidays && $this->schedule_type_requires_sign_in($sch->status),
            'location'=>$branch_today?$branch_today['name']:($loc?(string)$loc->name:''),
            'location_note'=>$branch_today&&$branch_today['planned']?__('Planned for today','workforce-one'):'',
            'sign_in'=>$sign_in?date_i18n('h:i A',strtotime($sign_in)):'','sign_out'=>$sign_out?date_i18n('h:i A',strtotime($sign_out)):'',
            'overtime'=>$ot?['approved'=>$this->format_duration_minutes($ot['approved_minutes']),'actual'=>$this->format_duration_minutes($ot['actual_approved_minutes']),
                'extra'=>$ot['unapproved_extra_minutes']>0?$this->format_duration_minutes($ot['unapproved_extra_minutes']):'',
                'sign_out'=>$ot['sign_out']?date_i18n('g:i A',strtotime($ot['sign_out'])):'']:null,
            'upcoming'=>$upcoming,
            // Sign In Reminder is push-only; it never renders as a card.
            // The task reminders are on the "My tasks today" card when it is shown.
            'nudges'=>array_values(array_filter($this->smart_nudge_for_employee($emp),function($n)use($common){return ($n['kind']??'')!=='attendance' && !(($n['kind']??'')==='task' && !empty($common['home_tasks']));})),
            'dismiss_url'=>function($id){return wp_nonce_url(add_query_arg(['action'=>'ews_smart_nudge_dismiss','nudge_id'=>$id],admin_url('admin-post.php')),'ews_smart_nudge_dismiss_'.$id);},
        ]);
    }
}
