<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\SwapRules;
use WorkforceOne\Schedule\TeamOrder;

/**
 * The employee app's shared Team Schedule (?ews_view=schedule) with the Schedule Swap panel.
 * View: templates/app/schedule.php; script: assets/js/schedule.js; ordering: src/Schedule/TeamOrder.php.
 * Swap actions live in trait-swap.php. Behaviour: tests/e2e_schedule_view.py.
 */
trait EWS_Schedule_View_Trait {

    /** The shared Team Schedule order (rules: src/Schedule/TeamOrder.php). */
    private function schedule_employees_ordered($emps,$current_emp_id=0){
        global $wpdb;
        $emps=array_values((array)$emps);
        if(!$emps) return $emps;
        $t=$this->team_tables();
        // Team tables are created by maybe_upgrade_schema(); only probe on a not-yet-upgraded install.
        if(!$this->ews_schema_is_current()){
            foreach(['teams','members'] as $k)if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t[$k]))!==$t[$k])return $emps;
        }
        $teams=[];$members=[];
        foreach((array)$wpdb->get_results("SELECT id,name,manager_employee_id FROM {$t['teams']} WHERE active=1") as $r)$teams[(int)$r->id]=['name'=>(string)$r->name,'manager'=>(int)$r->manager_employee_id];
        if(!$teams) return $emps;
        foreach((array)$wpdb->get_results("SELECT team_id,employee_id FROM {$t['members']} WHERE active=1") as $r)$members[(int)$r->employee_id][]=(int)$r->team_id;
        return \WorkforceOne\Schedule\TeamOrder::arrange($emps,$teams,$members,(int)$current_emp_id);
    }

    private function schedule_content(){
        global $wpdb;
        $current_emp=$this->current_employee();
        $current_emp_id=$current_emp?(int)$current_emp->id:0;
        $emps=$this->schedule_employees_ordered($this->department_scoped_employees(),$current_emp_id);
        [$dates,$sun]=$this->week_dates();
        $today=current_time('Y-m-d');
        if(!$dates)$dates=[$today];
        $week_end=$dates[count($dates)-1];
        $ph=implode(',',array_fill(0,count($dates),'%s'));
        $map=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE work_date IN ($ph)",...$dates)) as $r)$map[(int)$r->employee_id][$r->work_date]=$r->status;
        $company_leave=$this->company_leave_dates($dates[0],$week_end);

        $cells=[];$people=[];
        foreach($emps as $e){
            foreach($dates as $d)$cells[(int)$e->id][$d]=isset($company_leave[$d])?'General Leave':($map[(int)$e->id][$d]??'Not Set');
            $people[(int)$e->id]=['picture'=>$this->employee_picture_url($e),'initials'=>\WorkforceOne\Employees\ProfileSummary::initials((string)$e->name)?:'·'];
        }
        $legend=[];
        foreach($this->schedule_types_config(true) as $stype)$legend[]=(string)$stype['name'];
        foreach(['General Leave','Absent','Not Set'] as $extra)$legend[]=$extra;
        $legend=array_values(array_unique($legend));

        // Days I can offer: my Office/WFH days from today on that are not a company holiday.
        $swap_days=[];
        foreach($dates as $d){
            $mine=$map[$current_emp_id][$d]??'';
            if($current_emp_id && in_array($mine,SwapRules::SWAPPABLE,true) && !isset($company_leave[$d]) && !SwapRules::checkNotPast($d,$today))$swap_days[$d]=$mine;
        }
        [,$this_week]=$this->week_dates_configured($today);
        $range=date('d M',strtotime($dates[0])).' – '.date('d M Y',strtotime($week_end));
        $email=null;
        if(isset($_GET['email_sent'])||isset($_GET['email_skipped'])||isset($_GET['email_failed']))$email=['sent'=>absint($_GET['email_sent']??0),'skipped'=>absint($_GET['email_skipped']??0),'failed'=>absint($_GET['email_failed']??0)];
        wp_enqueue_script('workforce-one-schedule');
        wp_enqueue_style('workforce-one-schedule-page');
        return $this->render_template('app/schedule',[
            'emps'=>$emps,'dates'=>$dates,'today'=>$today,'current_emp_id'=>$current_emp_id,'cells'=>$cells,'people'=>$people,'legend'=>$legend,
            'range'=>$range,'week_start'=>date('Y-m-d',$sun),
            'week_label'=>TeamOrder::weekLabel(date('Y-m-d',$sun),date('Y-m-d',$this_week))??$range,
            'prev_url'=>add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('-7 days',$sun))]),
            'next_url'=>add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('+7 days',$sun))]),
            'print_title'=>'Team Schedule - '.date('d M Y',strtotime($dates[0])).' to '.date('d M Y',strtotime($week_end)),
            'email_url'=>$this->can('ews_view_reports')?wp_nonce_url(add_query_arg(['action'=>'ews31_report_email','start'=>$dates[0],'end'=>$week_end],admin_url('admin-post.php')),'ews31_report'):'',
            'email'=>$email,'swap_days'=>$swap_days,
            'swap_requests'=>$current_emp_id?$this->swap_requests_for_user($current_emp_id,$dates[0],$week_end):[],
            'no_swaps_html'=>$this->ews_empty_state('No Swap Requests','There are no swap requests for this week.'),
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }
}
