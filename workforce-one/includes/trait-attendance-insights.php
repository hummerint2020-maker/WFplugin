<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * wp-admin "Attendance Insights" and its employee-app version: a week of planned vs actual attendance, a focus day, per-employee
 * metrics and drill-downs. Employees without attendance tracking are left out. Lateness follows
 * each employee's shift. Rules: src/Attendance/Insights.php. Behaviour: tests/e2e_insights.py.
 */
trait EWS_Attendance_Insights_Trait {

    /** Schedules and the first events per employee/day for a date range. */
    private function insights_load(array $ids,$start,$end){
        global $wpdb;
        $schedule=[];$events=[];
        if(!$ids)return [$schedule,$events];
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s",array_merge($ids,[$start,$end]))) as $r){
            $schedule[(int)$r->employee_id][$r->work_date]=trim((string)$r->status);
        }
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s".$this->tl_live()." ORDER BY event_at ASC",array_merge($ids,[$start,$end]))) as $r){
            // Keep the first Sign In and the last Sign Out of each day.
            $slot=&$events[(int)$r->employee_id][$r->work_date];
            if($r->event_type==='sign_out')$slot['sign_out']=$r;
            elseif(!isset($slot['sign_in']))$slot['sign_in']=$r;
            unset($slot);
        }
        return [$schedule,$events];
    }

    /** @return array{plan:string,actual:string,time:string,missing_sign_out:bool,expected:bool} */
    private function insights_day($employee_id,$planned,$ev,$date,$today,$holidays){
        $type=$planned!==''?$this->schedule_type_config($planned):null;
        $rule=$type?$type['attendance_rule']:null;
        $requires=$type?(bool)$type['requires_sign_in']:false;
        $in=$ev['sign_in']??null;
        $past_cutoff=false;
        if($date===$today && !$in){
            $hours=$this->working_hours($employee_id);
            $cutoff=strtotime($date.' '.$hours['normal_until'].':00');
            if(!empty($hours['overnight']) && $hours['start']>$hours['end'] && $hours['normal_until']<$hours['start'])$cutoff=strtotime($date.' +1 day '.$hours['normal_until'].':00');
            $past_cutoff=$cutoff!==false && current_time('timestamp')>=$cutoff;
        }
        $actual=Insights::actual(['holiday'=>isset($holidays[$date]),'rule'=>$rule,'requires_sign_in'=>$requires,
            'classification'=>$in?$this->sign_in_classification($in->event_at,$employee_id):null,'legacy_late'=>$in&&$in->event_type==='late_sign_in',
            'date'=>$date,'today'=>$today,'past_cutoff'=>$past_cutoff],$planned);
        return ['plan'=>Insights::planCategory($planned,$rule),'actual'=>$actual,'time'=>$in?date_i18n('H:i',strtotime($in->event_at)):'',
            'missing_sign_out'=>$in && empty($ev['sign_out']) && $date<=$today,'expected'=>$requires && !isset($holidays[$date])];
    }

    /**
     * Active teams and each employee's team names. $department limits the teams to one
     * department (0 = every team), as the employee app does for department managers.
     * @return array{0:string[],1:array<int,string[]>} [team names, team names per employee id]
     */
    private function insights_teams($department=0){
        global $wpdb;
        $this->ensure_teams_schema();$tt=$this->team_tables();
        $sql="SELECT id,name FROM {$tt['teams']} WHERE active=1".($department?' AND department_id=%d':'').' ORDER BY name ASC';
        $team_names=[];$teams_by_emp=[];
        foreach((array)$wpdb->get_results($department?$wpdb->prepare($sql,$department):$sql) as $t)$team_names[(int)$t->id]=(string)$t->name;
        foreach((array)$wpdb->get_results("SELECT team_id,employee_id FROM {$tt['members']} WHERE active=1") as $r)if(isset($team_names[(int)$r->team_id]))$teams_by_emp[(int)$r->employee_id][]=$team_names[(int)$r->team_id];
        $options=array_values(array_unique($team_names));natcasesort($options);
        return [array_values($options),$teams_by_emp];
    }

    /** Attendance-tracked employees, optionally limited to one team. */
    private function insights_employees($employees,$team,array $teams_by_emp){
        $out=[];
        foreach((array)$employees as $e){
            if(isset($e->attendance_enabled) && !(int)$e->attendance_enabled)continue;
            if($team!=='all' && !in_array($team,$teams_by_emp[(int)$e->id]??[],true))continue;
            $out[]=$e;
        }
        return $out;
    }

    /**
     * Everything both Attendance Insights pages show: names per planned/actual category for each
     * day of the week and for the focus day, per-employee metrics and who is expected on the focus day.
     */
    private function insights_compute(array $emps,array $dates,$focus,$today){
        $ids=array_map(function($e){return (int)$e->id;},$emps);
        $week_start=$dates[0]??$focus;$week_end=$dates[count($dates)-1]??$week_start;
        $range_start=min($week_start,$focus);$range_end=max($week_end,$focus);
        [$schedule,$events]=$this->insights_load($ids,$range_start,$range_end);
        $holidays=$this->company_leave_dates($range_start,$range_end);

        $empty=function($labels){return array_fill_keys($labels,[]);};
        $drill=[];$metrics=[];
        foreach($dates as $d)$drill[$d]=['plan'=>$empty(Insights::PLAN_ROWS),'actual'=>$empty(Insights::ACTUAL)];
        $focus_drill=['plan'=>$empty(Insights::PLAN_ROWS),'actual'=>$empty(array_merge(Insights::ACTUAL,['Missing Sign-out']))];
        $expected_focus=0;
        foreach($emps as $e){
            $eid=(int)$e->id;
            $m=['Present'=>0,'Late'=>0,'Absent'=>0,'missing_sign_out'=>0];
            foreach($dates as $d){
                $day=$this->insights_day($eid,$schedule[$eid][$d]??'',$events[$eid][$d]??[],$d,$today,$holidays);
                $drill[$d]['plan'][$day['plan']][]=$e->name;
                if(isset($drill[$d]['actual'][$day['actual']]))$drill[$d]['actual'][$day['actual']][]=$e->name;
                if(isset($m[$day['actual']]))$m[$day['actual']]++;
                if($day['missing_sign_out'])$m['missing_sign_out']++;
            }
            $working=$m['Present']+$m['Late']+$m['Absent'];
            $metrics[]=['id'=>$eid,'name'=>$e->name,'domain'=>$e->domain_name,'late'=>$m['Late'],'absent'=>$m['Absent'],'missing_sign_out'=>$m['missing_sign_out'],
                'rate'=>Insights::rate($m['Present'],$m['Late'],$m['Absent']),'on_time_rate'=>$working?(int)round($m['Present']/$working*100):0,
                'issue'=>$m['Absent']?$m['Absent'].' absent day(s)':($m['Late']?$m['Late'].' late day(s)':($m['missing_sign_out']?$m['missing_sign_out'].' missing sign-out(s)':''))];
            $day=$this->insights_day($eid,$schedule[$eid][$focus]??'',$events[$eid][$focus]??[],$focus,$today,$holidays);
            $focus_drill['plan'][$day['plan']][]=$e->name;
            if(isset($focus_drill['actual'][$day['actual']]))$focus_drill['actual'][$day['actual']][]=$e->name;
            if($day['missing_sign_out'])$focus_drill['actual']['Missing Sign-out'][]=$e->name;
            if($day['expected'])$expected_focus++;
        }
        return compact('drill','focus_drill','metrics','expected_focus');
    }

    public function admin_attendance_insights(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        $today=current_time('Y-m-d');
        [$dates,$sun]=$this->week_dates_configured(sanitize_text_field(wp_unslash($_GET['week']??$today)));
        $week_start=$dates[0]??$today;$week_end=$dates[count($dates)-1]??$week_start;
        $focus=Insights::validDate(sanitize_text_field(wp_unslash($_GET['focus_date']??$today)),$today);
        $team=sanitize_text_field(wp_unslash($_GET['team']??'all'));
        [$team_options,$teams_by_emp]=$this->insights_teams();
        $emps=$this->insights_employees($this->emps(),$team,$teams_by_emp);
        ['drill'=>$drill,'focus_drill'=>$focus_drill,'metrics'=>$metrics,'expected_focus'=>$expected_focus]=$this->insights_compute($emps,$dates,$focus,$today);
        $fa=$focus_drill['actual'];
        $url=function($args)use($week_start,$team,$focus){return add_query_arg(array_merge(['page'=>'ews31-attendance-insights','week'=>$week_start,'team'=>$team,'focus_date'=>$focus],$args),admin_url('admin.php'));};
        echo $this->render_template('admin/attendance-insights',[
            'dates'=>$dates,'today'=>$today,'focus'=>$focus,'team'=>$team,'team_options'=>$team_options,'week_start'=>$week_start,
            'week_label'=>date('d',strtotime($week_start)).' – '.date('d M Y',strtotime($week_end)),
            'focus_label'=>date_i18n('l, d M Y',strtotime($focus)),
            'employee_count'=>count($emps),'expected_focus'=>$expected_focus,
            'focus_rate'=>Insights::rate(count($fa['Present']),count($fa['Late']),count($fa['Absent'])),
            'drill'=>$drill,'focus_drill'=>$focus_drill,'metrics'=>$metrics,
            'attention'=>array_slice(array_values(array_filter($metrics,function($r){return $r['issue']!=='';})),0,5),
            'profile_url'=>admin_url('admin.php?page=ews31-employee-profile'),
            'nav'=>['prev_week'=>$url(['week'=>date('Y-m-d',strtotime('-7 days',$sun))]),'next_week'=>$url(['week'=>date('Y-m-d',strtotime('+7 days',$sun))]),
                'prev_day'=>$url(['focus_date'=>date('Y-m-d',strtotime($focus.' -1 day'))]),'next_day'=>$url(['focus_date'=>date('Y-m-d',strtotime($focus.' +1 day'))])],
        ]);
    }

    /**
     * Employee app → Attendance Insights (?ews_view=attendance-insights): the focus day and its week,
     * limited to the viewer's department. Same numbers as the wp-admin page (insights_compute()).
     * View: templates/app/attendance-insights.php; drawer: assets/js/app-attendance-insights.js.
     */
    private function attendance_insights_content(){
        if(!$this->can('ews_view_reports'))return $this->ews_empty_state(__('Access denied','workforce-one'),__('Attendance Insights is not available for your account.','workforce-one'));
        $today=current_time('Y-m-d');
        $focus=Insights::validDate(sanitize_text_field(wp_unslash($_GET['focus_date']??$today)),$today);
        $team=sanitize_text_field(wp_unslash($_GET['team']??'all'));
        [$dates]=$this->week_dates_configured($focus);
        if(!$dates)$dates=[$focus];
        $week_start=$dates[0];$week_end=$dates[count($dates)-1];
        $this->ensure_departments_schema();
        $all=current_user_can('manage_options');
        $department=$all?0:(int)$this->current_department_id();
        [$team_options,$teams_by_emp]=($all||$department)?$this->insights_teams($department):[[],[]];
        $emps=$this->insights_employees($this->department_scoped_employees($this->emps()),$team,$teams_by_emp);
        ['drill'=>$drill,'focus_drill'=>$focus_drill]=$this->insights_compute($emps,$dates,$focus,$today);
        $url=function($date)use($team){return add_query_arg(['focus_date'=>$date,'team'=>$team],$this->app_view_url('attendance-insights'));};
        wp_enqueue_script('workforce-one-app-attendance-insights');
        wp_enqueue_style('workforce-one-insights-page');
        return $this->render_template('app/attendance-insights',[
            'dates'=>$dates,'today'=>$today,'focus'=>$focus,'team'=>$team,'team_options'=>$team_options,
            'drill'=>$drill,'focus_drill'=>$focus_drill,
            'week_label'=>date('d M',strtotime($week_start)).' – '.date('d M Y',strtotime($week_end)),
            'focus_label'=>date_i18n('D, d M Y',strtotime($focus)),
            'nav'=>['prev_week'=>$url(date('Y-m-d',strtotime($week_start.' -7 days'))),'next_week'=>$url(date('Y-m-d',strtotime($week_start.' +7 days'))),
                'prev_day'=>$url(date('Y-m-d',strtotime($focus.' -1 day'))),'next_day'=>$url(date('Y-m-d',strtotime($focus.' +1 day'))),'today'=>$url($today)],
        ]);
    }
}
