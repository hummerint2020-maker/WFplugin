<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\Insights;

/**
 * wp-admin "Attendance Insights": a week of planned vs actual attendance, a focus day, per-employee
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
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s ORDER BY event_at ASC",array_merge($ids,[$start,$end]))) as $r){
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

    public function admin_attendance_insights(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        global $wpdb;
        $today=current_time('Y-m-d');
        [$dates,$sun]=$this->week_dates_configured(sanitize_text_field(wp_unslash($_GET['week']??$today)));
        $week_start=$dates[0]??$today;$week_end=$dates[count($dates)-1]??$week_start;
        $focus=sanitize_text_field(wp_unslash($_GET['focus_date']??$today));
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$focus,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1]))$focus=$today;
        $team=sanitize_text_field(wp_unslash($_GET['team']??'all'));

        // Teams and the employees on this page (attendance-tracked only).
        $this->ensure_teams_schema();$tt=$this->team_tables();
        $team_names=[];$teams_by_emp=[];
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$tt['teams']} WHERE active=1 ORDER BY name ASC") as $t)$team_names[(int)$t->id]=(string)$t->name;
        foreach((array)$wpdb->get_results("SELECT team_id,employee_id FROM {$tt['members']} WHERE active=1") as $r)if(isset($team_names[(int)$r->team_id]))$teams_by_emp[(int)$r->employee_id][]=$team_names[(int)$r->team_id];
        $team_options=array_values(array_unique($team_names));natcasesort($team_options);
        $emps=[];
        foreach((array)$this->emps() as $e){
            if(isset($e->attendance_enabled) && !(int)$e->attendance_enabled)continue;
            if($team!=='all' && !in_array($team,$teams_by_emp[(int)$e->id]??[],true))continue;
            $emps[]=$e;
        }
        $ids=array_map(function($e){return (int)$e->id;},$emps);

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
}
