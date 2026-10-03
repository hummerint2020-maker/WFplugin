<?php
if (!defined('ABSPATH')) exit;

trait EWS_Reports_Trait {

    /** Report types of the Report Center: key => [title, what it answers]. */
    private function report_catalog(){
        $c=[
            'summary'=>['Attendance Summary','One row per employee: attendance, punctuality, lateness and hours.'],
            'attendance'=>['Daily Log','Every employee-day: plan, Sign In / Out, result, late minutes and hours.'],
            'timesheet'=>['Timesheet','Worked hours per employee for payroll: net hours, overtime and leave taken.'],
        ];
        if($this->overtime_enabled())$c['overtime']=['Overtime','Overtime requests by status, approved hours, the hours worked in them and unapproved extra time.'];
        $c['leave']=['Leave & Balances','Days on leave, leave requests and the remaining balance of each leave type.'];
        $c['workforce']=['Workforce','Planned distribution per day: Office, WFH, leave and missions.'];
        return $c;
    }

    /** The report type from the request, if the Report Center offers it. */
    private function report_request_type($default='summary'){
        $type=sanitize_key($_GET['report_type']??$default);
        return isset($this->report_catalog()[$type])?$type:$default;
    }

    /** Quick ranges of the period picker: key => [label, start, end] (site time). */
    private function report_quick_ranges(){
        $now=current_time('timestamp');
        [$tw_s,$tw_e]=$this->report_work_week_range();[$lw_s,$lw_e]=$this->report_work_week_range(null,-1);
        $yesterday=date('Y-m-d',strtotime('-1 day',$now));
        return ['today'=>['Today',current_time('Y-m-d'),current_time('Y-m-d')],'yesterday'=>['Yesterday',$yesterday,$yesterday],
            'this_week'=>['This Week',$tw_s,$tw_e],'last_week'=>['Last Week',$lw_s,$lw_e],
            'this_month'=>['This Month',current_time('Y-m-01'),current_time('Y-m-d')],'last_month'=>['Last Month',date('Y-m-01',strtotime('first day of last month',$now)),date('Y-m-t',strtotime('last day of last month',$now))]];
    }

    /** Employee app → Reports: the Report Center shell (shared filters) around the chosen report. */
    private function reports_content(){
        if(!$this->can('ews_view_reports'))return $this->ews_empty_state('Reports unavailable','You do not have permission to view reports.');
        [$s,$e]=$this->period();
        $catalog=$this->report_catalog();
        $type=$this->report_request_type();
        [$team,$employee_id,$status,$employee_status]=$this->report_request_filters();
        [$emps,$team_options]=$this->report_employee_scope($team,0,$employee_status);
        $builders=['summary'=>'report_summary_builder','attendance'=>'attendance_report_builder','timesheet'=>'report_timesheet_builder','overtime'=>'report_overtime_builder','leave'=>'report_leave_builder','workforce'=>'workforce_report_builder'];
        $body=$this->{$builders[$type]}($s,$e);

        $url=function($args)use($s,$e,$team,$employee_id,$employee_status,$type){
            return add_query_arg(array_merge(['ews_view'=>'reports','report_type'=>$type,'start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],$args),$this->app_view_url('reports'));
        };
        $quick=$this->report_quick_ranges();
        $tabs=[];foreach($catalog as $key=>$c)$tabs[$key]=['title'=>$c[0],'hint'=>$c[1],'url'=>$url(['report_type'=>$key,'report_page'=>1])];
        $quick_urls=[];$range='';
        foreach($quick as $key=>[$label,$qs,$qe]){$active=$s===$qs&&$e===$qe;if($active&&$range==='')$range=$key;$quick_urls[$label]=['url'=>$url(['start'=>$qs,'end'=>$qe,'report_page'=>1]),'active'=>$active];}
        // Saved views (per user): open with their filters; a quick range opens on today's period.
        $ranges=[];foreach($quick as $key=>[,$qs,$qe])$ranges[$key]=[$qs,$qe];
        $views=[];
        foreach($this->report_saved_views() as $v){
            $args=\WorkforceOne\Reports\SavedViews::args($v,$ranges);
            if(!isset($catalog[$args['report_type']??'']))continue; // e.g. Overtime switched off
            $views[]=['id'=>$v['id'],'name'=>$v['name'],'url'=>add_query_arg(array_merge(['ews_view'=>'reports'],$args),$this->app_view_url('reports'))];
        }
        wp_enqueue_script('workforce-one-reports');
        return $this->render_template('app/reports',[
            'type'=>$type,'tabs'=>$tabs,'start'=>$s,'end'=>$e,'team'=>$team,'employee_id'=>$employee_id,'status'=>$status,'employee_status'=>$employee_status,
            'team_options'=>$team_options,'emps'=>$emps,'quick'=>$quick_urls,'statuses'=>\WorkforceOne\Reports\Summary::BUCKETS,'body'=>$body,'views'=>$views,'range'=>$range,
            'period_label'=>date_i18n('d M Y',strtotime($s)).' – '.date_i18n('d M Y',strtotime($e)),
        ]);
    }

    /** Timesheet: worked hours, overtime and leave per employee, for payroll. */
    private function report_timesheet_builder($s,$e){
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        [$rows]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status,true);
        $export=function($action)use($s,$e,$team,$employee_id,$employee_status){return wp_nonce_url(add_query_arg(['action'=>$action,'report_type'=>'timesheet','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],admin_url('admin-post.php')),'ews31_report');};
        return $this->render_template('app/report-timesheet',['people'=>\WorkforceOne\Reports\Timesheet::byEmployee($rows),'overtime'=>$this->overtime_enabled(),'csv_url'=>$export('ews31_report_download'),'xlsx_url'=>$export('ews31_report_xlsx')]);
    }

    /** Attendance Summary: one row per employee, KPIs compared with the previous period. */
    private function report_summary_builder($s,$e){
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        [$rows,,,$emps]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status);
        $people=\WorkforceOne\Reports\EmployeeSummary::byEmployee($rows);
        $totals=\WorkforceOne\Reports\EmployeeSummary::totals($rows);
        [$ps,$pe]=\WorkforceOne\Reports\EmployeeSummary::previousPeriod($s,$e);
        $previous=\WorkforceOne\Reports\EmployeeSummary::totals($this->report_days($emps,$ps,$pe));
        $base=['ews_view'=>'reports','report_type'=>'attendance','start'=>$s,'end'=>$e,'team'=>$team,'employee_status'=>$employee_status];
        $drill=function($employee,$status)use($base){return add_query_arg(array_merge($base,['employee'=>$employee,'status'=>$status,'report_page'=>1]),$this->app_view_url('reports'));};
        $export=function($action)use($s,$e,$team,$employee_id,$employee_status){return wp_nonce_url(add_query_arg(['action'=>$action,'report_type'=>'summary','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],admin_url('admin-post.php')),'ews31_report');};
        return $this->render_template('app/report-summary',[
            'people'=>$people,'totals'=>$totals,'previous'=>$previous,'previous_label'=>date_i18n('d M',strtotime($ps)).' – '.date_i18n('d M Y',strtotime($pe)),
            'drill'=>$drill,'csv_url'=>$export('ews31_report_download'),'xlsx_url'=>$export('ews31_report_xlsx'),
            'trend'=>\WorkforceOne\Reports\Trend::daily($rows),
        ]);
    }

    /** The export links of a report (CSV and Excel), with the request's filters. */
    private function report_export_urls($type,$s,$e){
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        $url=function($action)use($type,$s,$e,$team,$employee_id,$employee_status){return wp_nonce_url(add_query_arg(['action'=>$action,'report_type'=>$type,'start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],admin_url('admin-post.php')),'ews31_report');};
        return ['csv_url'=>$url('ews31_report_download'),'xlsx_url'=>$url('ews31_report_xlsx')];
    }

    /** Overtime report data: OvertimeReport::byEmployee() over the period, days off included. */
    private function report_overtime_data($s,$e){
        global $wpdb;
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        [$rows]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status,true);
        $this->ensure_overtime_schema();
        $requests=(array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,status FROM {$wpdb->prefix}ews_overtime_requests WHERE overtime_date BETWEEN %s AND %s",$s,$e),ARRAY_A);
        return \WorkforceOne\Reports\OvertimeReport::byEmployee($rows,$requests);
    }

    /** Overtime: requests, approved and worked hours, unapproved extra (Overtime feature on). */
    private function report_overtime_builder($s,$e){
        $people=$this->report_overtime_data($s,$e);
        return $this->render_template('app/report-overtime',['people'=>$people,'totals'=>\WorkforceOne\Reports\OvertimeReport::totals($people)]+$this->report_export_urls('overtime',$s,$e));
    }

    /**
     * Leave report data: LeaveReport::byEmployee(), the active leave types and the balance year
     * (the year of the period's end).
     * @return array{0:array<int,array<string,mixed>>,1:string[],2:int}
     */
    private function report_leave_data($s,$e){
        global $wpdb;
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        [$rows,,,$emps]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status);
        $this->ensure_leave_schema();
        $year=(int)substr($e,0,4);
        $people=[];foreach($emps as $x)$people[]=['employee_id'=>(int)$x->id,'employee'=>(string)$x->name,'domain'=>(string)$x->domain_name,'teams'=>(array)($x->_report_teams??[])];
        $requests=(array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,status,requested_days FROM {$wpdb->prefix}ews_leave_requests WHERE start_date<=%s AND end_date>=%s",$e,$s),ARRAY_A);
        $balances=(array)$wpdb->get_results($wpdb->prepare("SELECT b.employee_id,t.name type,b.entitlement,b.used,b.pending FROM {$wpdb->prefix}ews_leave_balances b JOIN {$wpdb->prefix}ews_leave_types t ON t.id=b.leave_type_id AND t.active=1 WHERE b.leave_year=%d",$year),ARRAY_A);
        $types=array_map('strval',(array)$wpdb->get_col("SELECT name FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY name ASC"));
        return [\WorkforceOne\Reports\LeaveReport::byEmployee($people,$rows,$requests,$balances),$types,$year];
    }

    /** Leave & Balances: leave days, requests and the remaining balance per leave type. */
    private function report_leave_builder($s,$e){
        [$people,$types,$year]=$this->report_leave_data($s,$e);
        return $this->render_template('app/report-leave',['people'=>$people,'totals'=>\WorkforceOne\Reports\LeaveReport::totals($people),'types'=>$types,'year'=>$year]+$this->report_export_urls('leave',$s,$e));
    }

    /** @return array<int,array<string,string>> the current user's saved report views */
    private function report_saved_views(){
        $v=get_user_meta(get_current_user_id(),'ews_report_views',true);
        return is_array($v)?array_values(array_filter($v,'is_array')):[];
    }

    /** Report Center → Save view: the current filters under a name (per user). */
    public function report_view_save(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied',403);
        check_admin_referer('ews_report_view');
        $type=sanitize_key($_POST['report_type']??'summary');
        if(!isset($this->report_catalog()[$type]))$type='summary';
        $filters=['report_type'=>$type];
        foreach(['team','employee_status','status','start','end'] as $k)$filters[$k]=sanitize_text_field(wp_unslash($_POST[$k]??''));
        $filters['employee']=(string)absint($_POST['employee']??0);
        foreach(['start','end'] as $k)if(!$this->valid_date($filters[$k]))$filters[$k]='';
        $range=sanitize_key($_POST['range']??'');
        $views=\WorkforceOne\Reports\SavedViews::add($this->report_saved_views(),sanitize_text_field(wp_unslash($_POST['view_name']??'')),$filters,$range);
        update_user_meta(get_current_user_id(),'ews_report_views',$views);
        wp_safe_redirect(add_query_arg(array_merge(['ews_view'=>'reports'],array_filter($filters,'strlen')),$this->app_view_url('reports')));exit;
    }

    /** Report Center → remove a saved view. */
    public function report_view_delete(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied',403);
        check_admin_referer('ews_report_view');
        update_user_meta(get_current_user_id(),'ews_report_views',\WorkforceOne\Reports\SavedViews::remove($this->report_saved_views(),sanitize_key($_POST['view_id']??'')));
        wp_safe_redirect(add_query_arg(['ews_view'=>'reports'],$this->app_view_url('reports')));exit;
    }

    private function report_team_context(){
        global $wpdb;
        $this->ensure_teams_schema();
        $t=$this->team_tables();
        $team_options=[];$team_names_by_emp=[];
        $teams=$wpdb->get_results("SELECT id,name FROM {$t['teams']} WHERE active=1 ORDER BY name ASC");
        foreach((array)$teams as $tm)$team_options[(string)$tm->name]=(string)$tm->name;
        $members=$wpdb->get_results("SELECT team_id,employee_id FROM {$t['members']} WHERE active=1 ORDER BY team_id ASC,employee_id ASC");
        $team_map=[];foreach((array)$teams as $tm)$team_map[(int)$tm->id]=(string)$tm->name;
        foreach((array)$members as $m){$eid=(int)$m->employee_id;$tid=(int)$m->team_id;if(isset($team_map[$tid]))$team_names_by_emp[$eid][]=$team_map[$tid];}
        foreach($team_names_by_emp as $eid=>$names)$team_names_by_emp[$eid]=array_values(array_unique($names));
        if(!current_user_can('manage_options')){
            $dept=(int)$this->current_department_id();
            if(!$dept){$team_options=[];$team_names_by_emp=[];}
            else{
                $valid_team_ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['teams']} WHERE active=1 AND department_id=%d",$dept)));
                $valid=array_fill_keys($valid_team_ids,true);
                foreach((array)$teams as $tm){if(!isset($valid[(int)$tm->id]))unset($team_options[(string)$tm->name]);}
                foreach($team_names_by_emp as $eid=>$names){$filtered=[];foreach($names as $name){if(isset($team_options[$name]))$filtered[]=$name;}if($filtered)$team_names_by_emp[$eid]=$filtered;else unset($team_names_by_emp[$eid]);}
            }
        }
        natcasesort($team_options);
        return [$team_options,$team_names_by_emp];
    }

    private function report_employee_scope($selected_team='all',$employee_id=0,$employee_status='active'){
        [$team_options,$team_names_by_emp]=$this->report_team_context();
        $employee_status=in_array($employee_status,['active','inactive','all'],true)?$employee_status:'active';
        global $wpdb;
        $where='';
        if($employee_status==='active')$where=' WHERE active=1';
        elseif($employee_status==='inactive')$where=' WHERE active=0';
        $this->ensure_departments_schema();
        $all_emps=$wpdb->get_results("SELECT * FROM {$this->employees}{$where} ORDER BY active DESC,name ASC");
        $all_emps=$this->department_scoped_employees($all_emps);
        $all_emps=array_values(array_filter($all_emps,function($e){return !isset($e->attendance_enabled) || (int)$e->attendance_enabled===1;}));
        $emps=[];
        foreach((array)$all_emps as $e){
            $eid=(int)$e->id;$names=$team_names_by_emp[$eid]??[];
            if($selected_team!=='all'&&!in_array($selected_team,$names,true))continue;
            if($employee_id>0&&$eid!==$employee_id)continue;
            $e->_report_teams=$names;
            $emps[]=$e;
        }
        return [$emps,$team_options,$team_names_by_emp];
    }

    /**
     * Every working day of a period for the given employees, measured by the report engine
     * (src/Reports/DayMetrics.php): planned status, first Sign In, last Sign Out, closed breaks,
     * and the day's result, late / early minutes and net / expected minutes.
     * With $off_days, also the days off (not configured working days) on which the employee signed
     * in or had approved overtime, as result "Off Day": the Timesheet and the Overtime report count
     * the hours worked on them.
     * @return array<int,array<string,mixed>> one row per employee and working day (and day off worked)
     */
    private function report_days(array $emps,$s,$e,$off_days=false){
        global $wpdb;
        $dates=[];$working=[];
        for($t=strtotime($s),$et=strtotime($e);$t!==false&&$t<=$et;$t=strtotime('+1 day',$t)){$d=date('Y-m-d',$t);$w=$this->is_working_day($d);if($w||$off_days)$dates[]=$d;if($w)$working[$d]=true;}
        if(!$emps||!$working)return [];
        $this->ensure_break_schema();
        $ids=array_map(function($x){return (int)$x->id;},$emps);
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        $schedule=[];$first=[];$last=[];$breaks=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s",array_merge($ids,[$s,$e]))) as $r)$schedule[(int)$r->employee_id][$r->work_date]=trim((string)$r->status);
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s ORDER BY event_at ASC",array_merge($ids,[$s,$e]))) as $r){
            $k=(int)$r->employee_id;
            if($r->event_type==='sign_out')$last[$k][$r->work_date]=$r;
            elseif(in_array($r->event_type,['sign_in','late_sign_in'],true) && !isset($first[$k][$r->work_date]))$first[$k][$r->work_date]=$r;
        }
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,start_at,end_at,actual_minutes FROM {$wpdb->prefix}ews_break_sessions WHERE employee_id IN ($ph) AND work_date BETWEEN %s AND %s AND end_at IS NOT NULL",array_merge($ids,[$s,$e]))) as $r){
            $mins=$r->actual_minutes!==null?(int)$r->actual_minutes:max(0,intdiv((int)strtotime($r->end_at)-(int)strtotime($r->start_at),60));
            $breaks[(int)$r->employee_id][$r->work_date]=($breaks[(int)$r->employee_id][$r->work_date]??0)+$mins;
        }
        // Approved overtime windows (Overtime feature on): actual and unapproved overtime per day.
        $ot_on=$this->overtime_enabled();$windows=[];
        if($ot_on){
            $this->ensure_overtime_schema();
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,overtime_date,start_time,end_time FROM {$wpdb->prefix}ews_overtime_requests WHERE status='Approved' AND employee_id IN ($ph) AND overtime_date BETWEEN %s AND %s ORDER BY start_time ASC",array_merge($ids,[$s,$e]))) as $r)$windows[(int)$r->employee_id][$r->overtime_date][]=['start'=>$r->start_time,'end'=>$r->end_time];
        }
        $holidays=$this->company_leave_dates($s,$e);
        $today=current_time('Y-m-d');$now=current_time('mysql');
        $allowance=$this->break_enabled()?$this->break_duration_minutes():0;
        $rows=[];
        foreach($emps as $emp){
            $eid=(int)$emp->id;$h=$this->working_hours($eid);
            foreach($dates as $d){
                if(!isset($working[$d])){
                    if(!isset($first[$eid][$d])&&empty($windows[$eid][$d]))continue;
                    $rows[]=$this->report_off_day($emp,$d,$h,$first[$eid][$d]??null,$last[$eid][$d]??null,(int)($breaks[$eid][$d]??0),$windows[$eid][$d]??[],$today,$now,$ot_on);
                    continue;
                }
                $planned=$schedule[$eid][$d]??'';
                $type=$planned!==''?$this->schedule_type_config($planned):null;
                $in=$first[$eid][$d]??null;$out=$last[$eid][$d]??null;
                $m=\WorkforceOne\Reports\DayMetrics::compute([
                    'date'=>$d,'today'=>$today,'now'=>$now,'holiday'=>$holidays[$d]??null,
                    'planned'=>$planned,'rule'=>$type?$type['attendance_rule']:null,'requires_sign_in'=>$type?(bool)$type['requires_sign_in']:false,
                    'shift_start'=>$h['start'],'shift_end'=>$h['end'],'normal_until'=>$h['normal_until'],'grace'=>(int)($h['grace']??$this->global_grace_period()),'overnight'=>!empty($h['overnight']),'break_allowance'=>$allowance,
                    'first_in'=>$in?$in->event_at:null,'first_in_legacy_late'=>$in&&$in->event_type==='late_sign_in','last_out'=>$out?$out->event_at:null,'break_minutes'=>(int)($breaks[$eid][$d]??0),
                ]);
                // A company holiday has no shift: all approved overtime counts, as on a day off.
                $ot=!$ot_on?['approved'=>0,'actual'=>0,'extra'=>0]:(isset($holidays[$d])?\WorkforceOne\Reports\DayMetrics::offDayOvertime($d,$in?$in->event_at:null,$out?$out->event_at:null,$windows[$eid][$d]??[])
                    :\WorkforceOne\Reports\DayMetrics::overtime($d,$h['start'],$h['end'],$in?$in->event_at:null,$out?$out->event_at:null,$windows[$eid][$d]??[]));
                $rows[]=$m+['ot_approved'=>$ot['approved'],'ot_actual'=>$ot['actual'],'ot_extra'=>$ot['extra'],'rule'=>$type?$type['attendance_rule']:null,'employee_id'=>$eid,'employee'=>$emp->name,'domain'=>$emp->domain_name,'date'=>$d,'planned'=>$planned!==''?$planned:'Not Set',
                    'bucket'=>$type && $type['attendance_rule']==='business_trip' && $m['result']===$planned?'Business Trip':$m['result'],
                    'holiday'=>$holidays[$d]??'','sign_in'=>$in?date_i18n('H:i',strtotime($in->event_at)):'','sign_out'=>$out?date_i18n('H:i',strtotime($out->event_at)):''];
            }
        }
        return $rows;
    }

    /** A report_days() row for a day off the employee worked: no plan, no expected hours. */
    private function report_off_day($emp,$d,array $h,$in,$out,$break_minutes,array $windows,$today,$now,$ot_on){
        $m=\WorkforceOne\Reports\DayMetrics::compute([
            'date'=>$d,'today'=>$today,'now'=>$now,'holiday'=>'Off Day','planned'=>'','rule'=>null,'requires_sign_in'=>false,
            'shift_start'=>$h['start'],'shift_end'=>$h['end'],'normal_until'=>$h['normal_until'],'grace'=>0,'overnight'=>!empty($h['overnight']),'break_allowance'=>0,
            'first_in'=>$in?$in->event_at:null,'first_in_legacy_late'=>false,'last_out'=>$out?$out->event_at:null,'break_minutes'=>$break_minutes,
        ]);
        $ot=$ot_on?\WorkforceOne\Reports\DayMetrics::offDayOvertime($d,$in?$in->event_at:null,$out?$out->event_at:null,$windows):['approved'=>0,'actual'=>0,'extra'=>0];
        return array_merge($m,['result'=>\WorkforceOne\Reports\DayMetrics::OFF_DAY,'ot_approved'=>$ot['approved'],'ot_actual'=>$ot['actual'],'ot_extra'=>$ot['extra'],'rule'=>null,'employee_id'=>(int)$emp->id,'employee'=>$emp->name,'domain'=>$emp->domain_name,'date'=>$d,
            'planned'=>'Not Set','bucket'=>\WorkforceOne\Reports\DayMetrics::OFF_DAY,'holiday'=>'','sign_in'=>$in?date_i18n('H:i',strtotime($in->event_at)):'','sign_out'=>$out?date_i18n('H:i',strtotime($out->event_at)):'']);
    }

    private function report_attendance_data($s,$e,$selected_team='all',$employee_id=0,$status_filter='all',$employee_status='active',$off_days=false){
        [$emps,$team_options,$team_names_by_emp]=$this->report_employee_scope($selected_team,$employee_id,$employee_status);
        $rows=[];$summary=array_fill_keys(\WorkforceOne\Reports\Summary::BUCKETS,0)+['records'=>0];
        foreach($this->report_days($emps,$s,$e,$off_days) as $day){
            $flags=$day['missing_sign_out']?['Missing Sign-out']:[];
            $summary=\WorkforceOne\Reports\Summary::add($summary,$day);
            if($status_filter!=='all'&&$day['bucket']!==$status_filter&&$day['result']!==$status_filter&&!in_array($status_filter,$flags,true))continue;
            $rows[]=$day+['teams'=>$team_names_by_emp[$day['employee_id']]??[],'flags'=>$flags,'note'=>$day['holiday']];
        }
        return [$rows,$summary,$team_options,$emps];
    }

    private function report_status_badge($status){
        $map=[
            'Present'=>['✓','present'],'Late'=>['◷','late'],'Absent'=>['×','absent'],'Leave'=>['▣','leave'],
            'Business Trip'=>['↗','trip'],'Holiday'=>['✦','leave'],'Pending'=>['◷','pending'],'Not Scheduled'=>['—','neutral'],'Missing Sign-out'=>['!','missing'],'Not Set'=>['—','neutral']
        ];
        $v=$map[$status]??['•','neutral'];
        return '<span class="ews-report-status '.$v[1].'"><b>'.esc_html($v[0]).'</b>'.esc_html($status).'</span>';
    }

    private function report_planned_badge($status){
        $type=$this->schedule_type_config($status);
        if($type)return '<span class="ews-report-planned" style="background:'.esc_attr($type['bg_color']).';color:'.esc_attr($type['text_color']).';border-color:'.esc_attr($type['border_color']).'">'.esc_html(($type['icon']??'•').' '.$status).'</span>';
        return '<span class="ews-report-planned neutral">'.esc_html($status).'</span>';
    }

    private function report_work_week_range($timestamp=null,$offset_weeks=0){
        $timestamp=$timestamp!==null?(int)$timestamp:current_time('timestamp');
        $days=$this->working_days();
        if(!$days)return [date('Y-m-d',$timestamp),date('Y-m-d',$timestamp)];
        $first=(int)$days[0];$last=(int)$days[count($days)-1];$current=(int)date('w',$timestamp);
        $back=($current-$first+7)%7;
        $start=strtotime('-'.$back.' days',strtotime(date('Y-m-d',$timestamp)));
        if($offset_weeks)$start=strtotime(($offset_weeks>0?'+':'').($offset_weeks*7).' days',$start);
        $end=strtotime('+'.(($last-$first+7)%7).' days',$start);
        return [date('Y-m-d',$start),date('Y-m-d',$end)];
    }

    /** Daily Log: every employee-day, paged. */
    private function attendance_report_builder($s,$e){
        [$team,$employee_id,$status,$employee_status]=$this->report_request_filters();
        [$rows,$summary,,$emps]=$this->report_attendance_data($s,$e,$team,$employee_id,$status,$employee_status);
        $per=25;$total=count($rows);$pages=max(1,(int)ceil($total/$per));$page=min(max(1,absint($_GET['report_page']??1)),$pages);
        $view=array_slice($rows,($page-1)*$per,$per);
        foreach($view as $i=>$r){
            $view[$i]['planned_html']=$this->report_planned_badge($r['planned']);
            $view[$i]['result_html']=$this->report_status_badge($r['result']).implode('',array_map(function($f){return $this->report_status_badge($f);},$r['flags']));
        }
        $base=['ews_view'=>'reports','report_type'=>'attendance','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'status'=>$status,'employee_status'=>$employee_status];
        $export=function($action)use($base){return wp_nonce_url(add_query_arg(array_merge(['action'=>$action],$base),admin_url('admin-post.php')),'ews31_report');};
        return $this->render_template('app/report-daily',[
            'summary'=>$summary,'cards_order'=>\WorkforceOne\Reports\Summary::BUCKETS,'employee_count'=>count($emps),'working_days'=>count($this->working_days()),
            'period_label'=>date_i18n('d M Y',strtotime($s)).' – '.date_i18n('d M Y',strtotime($e)),
            'view'=>$view,'total'=>$total,'page'=>$page,'pages'=>$pages,'per'=>$per,
            'page_url'=>function($i)use($base){return add_query_arg(array_merge($base,['report_page'=>$i]),$this->app_view_url('reports'));},
            'csv_url'=>$export('ews31_report_download'),'xlsx_url'=>$export('ews31_report_xlsx'),
        ]);
    }

    /** Workforce: planned distribution per day (src/Reports/Workforce.php). */
    private function workforce_report_builder($s,$e){
        [$team,$employee_id,,$employee_status]=$this->report_request_filters();
        [$rows,,,$emps]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status);
        $days=\WorkforceOne\Reports\Workforce::byDay($rows);
        $base=['report_type'=>'workforce','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status];
        $export=function($action)use($base){return wp_nonce_url(add_query_arg(array_merge(['action'=>$action],$base),admin_url('admin-post.php')),'ews31_report');};
        return $this->render_template('app/report-workforce',[
            'days'=>$days,'totals'=>\WorkforceOne\Reports\Workforce::totals($days),'columns'=>\WorkforceOne\Reports\Workforce::COLUMNS,'employee_count'=>count($emps),
            'period_label'=>date_i18n('d M Y',strtotime($s)).' – '.date_i18n('d M Y',strtotime($e)),
            'csv_url'=>$export('ews31_report_download'),'xlsx_url'=>$export('ews31_report_xlsx'),
        ]);
    }

    /** The report period from the request; by default this month to date (site time). */
    private function period(){
        $today=current_time('Y-m-d');$month_start=current_time('Y-m-01');
        $s=sanitize_text_field(wp_unslash($_GET['start']??$month_start));$e=sanitize_text_field(wp_unslash($_GET['end']??$today));
        if(!$this->valid_date($s)||!$this->valid_date($e)||$e<$s){$s=$month_start;$e=$today;}
        return [$s,$e];
    }


    /** Report filters from the request (the same ones the screen uses). */
    private function report_request_filters(){
        $employee_status=sanitize_key($_GET['employee_status']??'active');
        if(!in_array($employee_status,['active','inactive','all'],true))$employee_status='active';
        $status=sanitize_text_field(wp_unslash($_GET['status']??'all'));
        if($status!=='all'&&!in_array($status,\WorkforceOne\Reports\Summary::BUCKETS,true))$status='all';
        return [sanitize_text_field(wp_unslash($_GET['team']??'all')),absint($_GET['employee']??0),$status,$employee_status];
    }

    /**
     * The exported report: column headers, rows and the summary cards, so the CSV, the Excel file
     * and the screen show the same numbers.
     * @return array{headers:string[],rows:array<int,array<int,string|int>>,kpis:array<string,int>}
     */
    private function report_export_table($s,$e,$type='attendance'){
        [$team,$employee_id,$status,$employee_status]=$this->report_request_filters();
        if($type==='workforce'){
            [$rows]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status);
            $days=\WorkforceOne\Reports\Workforce::byDay($rows);$out=[];
            foreach($days as $d=>$c)$out[]=array_merge([$d],array_values($c));
            $kpis=[];foreach(\WorkforceOne\Reports\Workforce::totals($days) as $k=>$n)if($k!=='absent'&&$k!=='not_set')$kpis[\WorkforceOne\Reports\Workforce::COLUMNS[$k]]=$n;
            return ['headers'=>array_merge(['Date'],array_values(\WorkforceOne\Reports\Workforce::COLUMNS)),'rows'=>$out,'kpis'=>$kpis];
        }
        $hm=function($m){return \WorkforceOne\Reports\DayMetrics::hm((int)$m);};
        // Every employee-day, for the "Daily Details" sheet of the summary and the timesheet.
        $details=function($rows)use($hm){
            $out=[];
            foreach($rows as $r)$out[]=[$r['employee'],$r['domain'],$r['date'],$r['planned'],$r['result'],$r['sign_in'],$r['sign_out'],$r['late_minutes'],$r['early_minutes'],$r['break_minutes'],$hm($r['net_minutes']),\WorkforceOne\Reports\Timesheet::decimalHours((int)$r['net_minutes']),$r['expected']?$hm($r['expected_minutes']):'',$r['ot_actual'],$r['ot_extra']];
            return ['name'=>'Daily Details','headers'=>['Employee','Employee ID','Date','Planned','Result','First Sign In','Last Sign Out','Late (min)','Early Leave (min)','Break (min)','Net Hours','Net Hours (decimal)','Expected Hours','Overtime Worked (min)','Unapproved Extra (min)'],'rows'=>$out];
        };
        if($type==='overtime'){
            $people=$this->report_overtime_data($s,$e);$out=[];
            foreach($people as $p)$out[]=[$p['employee'],$p['domain'],implode(' · ',$p['teams']),$p['requests'],$p['approved'],$p['pending'],$p['rejected'],$hm($p['approved_minutes']),$hm($p['worked_minutes']),
                \WorkforceOne\Reports\Timesheet::decimalHours((int)$p['worked_minutes']),$p['utilisation']===null?'':$p['utilisation'].'%',$p['days'],$hm($p['extra_minutes'])];
            $t=\WorkforceOne\Reports\OvertimeReport::totals($people);
            return ['headers'=>['Employee','Employee ID','Team','Requests','Approved','Pending','Rejected','Approved Hours','Worked Hours','Worked Hours (decimal)','Utilisation','Days Worked','Unapproved Extra'],'rows'=>$out,
                'kpis'=>['Employees'=>$t['employees'],'Requests'=>$t['requests'],'Pending'=>$t['pending'],'Approved (min)'=>$t['approved_minutes'],'Worked (min)'=>$t['worked_minutes'],'Unapproved (min)'=>$t['extra_minutes']]];
        }
        if($type==='leave'){
            [$people,$types,$year]=$this->report_leave_data($s,$e);$out=[];$days=function($n){return \WorkforceOne\Reports\LeaveReport::days((float)$n);};
            foreach($people as $p){
                $r=[$p['employee'],$p['domain'],implode(' · ',$p['teams']),$p['leave_days'],$p['leave_breakdown'],$p['approved_requests'],$days($p['approved_days']),$p['pending_requests'],$days($p['pending_days'])];
                foreach($types as $type_name){$b=$p['balances'][$type_name]??null;$r[]=$b?$days($b['remaining']):'';$r[]=$b?$days($b['entitlement']):'';}
                $out[]=$r;
            }
            $headers=['Employee','Employee ID','Team','Leave Days','Leave Taken','Approved Requests','Approved Days','Pending Requests','Pending Days'];
            foreach($types as $type_name){$headers[]=$type_name.' Remaining '.$year;$headers[]=$type_name.' Entitlement '.$year;}
            $t=\WorkforceOne\Reports\LeaveReport::totals($people);
            return ['headers'=>$headers,'rows'=>$out,'kpis'=>['Employees on Leave'=>$t['on_leave'],'Leave Days'=>$t['leave_days'],'Approved Requests'=>$t['approved_requests'],'Pending Requests'=>$t['pending_requests'],'Overdrawn'=>$t['overdrawn']]];
        }
        if($type==='timesheet'){
            [$rows]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status,true);
            $out=[];$k=['Employees'=>0,'Worked Days'=>0,'Absent Days'=>0,'Leave Days'=>0,'Holidays'=>0];
            foreach(\WorkforceOne\Reports\Timesheet::byEmployee($rows) as $p){
                $dec=function($m){return \WorkforceOne\Reports\Timesheet::decimalHours((int)$m);};
                $out[]=[$p['employee'],$p['domain'],implode(' · ',$p['teams']),$p['worked_days'],$hm($p['net_minutes']),$dec($p['net_minutes']),$hm($p['expected_minutes']),$dec($p['expected_minutes']),\WorkforceOne\Reports\DayMetrics::signedHm($p['balance_minutes']),
                    $hm($p['ot_approved']),$hm($p['ot_actual']),$dec($p['ot_actual']),$hm($p['ot_extra']),$p['late_minutes'],$p['early_minutes'],$p['absent'],$p['leave_days'],$p['leave_breakdown'],$p['holidays']];
                $k['Employees']++;$k['Worked Days']+=$p['worked_days'];$k['Absent Days']+=$p['absent'];$k['Leave Days']+=$p['leave_days'];$k['Holidays']+=$p['holidays'];
            }
            return ['headers'=>['Employee','Employee ID','Team','Worked Days','Net Hours','Net Hours (decimal)','Expected Hours','Expected Hours (decimal)','Balance','Overtime Approved','Overtime Worked','Overtime Worked (decimal)','Unapproved Extra','Late (min)','Early Leave (min)','Absent Days','Leave Days','Leave Breakdown','Holidays'],
                'rows'=>$out,'kpis'=>$k,'sheets'=>[$details($rows)]];
        }
        if($type==='summary'){
            [$rows]=$this->report_attendance_data($s,$e,$team,$employee_id,'all',$employee_status);
            $out=[];
            foreach(\WorkforceOne\Reports\EmployeeSummary::byEmployee($rows) as $p)$out[]=[$p['employee'],$p['domain'],implode(' · ',$p['teams']),$p['expected_days'],$p['present'],$p['late'],$p['absent'],$p['leave'],$p['holiday'],$p['trip'],
                $p['attendance_rate'],$p['punctuality_rate'],$p['late_minutes'],$p['early_minutes'],$p['missing_sign_out'],$p['avg_first_in'],$hm($p['net_minutes']),$hm($p['expected_minutes']),\WorkforceOne\Reports\DayMetrics::signedHm($p['balance_minutes'])];
            $t=\WorkforceOne\Reports\EmployeeSummary::totals($rows);
            return ['headers'=>['Employee','Employee ID','Team','Expected Days','Present','Late','Absent','Leave','Holiday','Business Trip','Attendance %','Punctuality %','Late (min)','Early Leave (min)','Missing Sign-out','Avg First Sign In','Net Hours','Expected Hours','Balance'],
                'rows'=>$out,'kpis'=>['Attendance %'=>$t['attendance_rate'],'Punctuality %'=>$t['punctuality_rate'],'Late (min)'=>$t['late_minutes'],'Absent'=>$t['absent'],'Missing Sign-out'=>$t['missing_sign_out'],'Present'=>$t['present']],'sheets'=>[$details($rows)]];
        }
        [$rows,$summary]=$this->report_attendance_data($s,$e,$team,$employee_id,$status,$employee_status);
        $out=[];
        foreach($rows as $r)$out[]=[$r['employee'],$r['domain'],$r['teams']?implode(' · ',$r['teams']):'',$r['date'],$r['planned'],$r['sign_in'],$r['sign_out'],$r['result'],implode('; ',$r['flags']),
            $r['late_minutes'],$r['early_minutes'],$r['break_minutes'],$hm($r['net_minutes']),$r['expected']?$hm($r['expected_minutes']):''];
        return ['headers'=>['Employee','Employee ID','Team','Date','Planned','Sign In','Sign Out','Result','Flags','Late (min)','Early Leave (min)','Break (min)','Net Hours','Expected Hours'],'rows'=>$out,
            'kpis'=>array_intersect_key($summary,array_flip(['Present','Late','Absent','Leave','Holiday','Missing Sign-out']))];
    }

    private function report_detailed_csv($s,$e,$type='attendance'){
        $table=$this->report_export_table($s,$e,$type);
        $f=fopen('php://temp','w+');
        fputcsv($f,\WorkforceOne\Support\Csv::row($table['headers']));
        foreach($table['rows'] as $r)fputcsv($f,\WorkforceOne\Support\Csv::row($r));
        rewind($f);return stream_get_contents($f);
    }


    private function report_xlsx_escape($v){
        $v=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/','',(string)$v);
        return htmlspecialchars($v,ENT_XML1|ENT_QUOTES,'UTF-8');
    }
    private function report_xlsx_col($n){
        $s='';
        while($n>0){$n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26);}
        return $s;
    }
    /** A plain sheet: a header row (style 4), data rows (style 5), frozen header and a filter. */
    private function report_xlsx_table_sheet(array $headers,array $rows,array $widths=[]){
        $xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        foreach($headers as $i=>$h)$xml.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.(int)($widths[$i]??max(12,min(40,mb_strlen((string)$h)+4))).'" customWidth="1"/>';
        $xml.='</cols><sheetData>';
        $all=array_merge([$headers],$rows);
        foreach($all as $r=>$row){
            $xml.='<row r="'.($r+1).'">';
            foreach(array_values($row) as $c=>$v){
                $ref=$this->report_xlsx_col($c+1).($r+1);$style=$r===0?4:5;
                $xml.=($r>0 && is_int($v))?'<c r="'.$ref.'" s="'.$style.'"><v>'.$v.'</v></c>':'<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->report_xlsx_escape($v).'</t></is></c>';
            }
            $xml.='</row>';
        }
        $xml.='</sheetData>';
        if($headers)$xml.='<autoFilter ref="A1:'.$this->report_xlsx_col(count($headers)).count($all).'"/>';
        return $xml.'</worksheet>';
    }

    private function report_xlsx_build($type,$start,$end){
        if(!class_exists('ZipArchive')) return new WP_Error('xlsx_zip','The PHP ZipArchive extension is required for Excel export.');

        $table=$this->report_export_table($start,$end,$type);
        $titles=['workforce'=>'Workforce Report','summary'=>'Attendance Summary','timesheet'=>'Timesheet','overtime'=>'Overtime','leave'=>'Leave and Balances','attendance'=>'Attendance Report'];
        $title=$titles[$type]??'Attendance Report';
        $generated=current_time('Y-m-d H:i');
        $headers=$table['headers'];
        $data=$table['rows'];
        $maxCols=max(12,count($headers));
        $lastCol=$this->report_xlsx_col($maxCols);

        $esc=function($v){return $this->report_xlsx_escape($v);};
        $cell=function($value,$row,$col,$style=0,$numeric=false) use ($esc){
            $ref=$this->report_xlsx_col($col).$row;
            if($numeric && $value!=='' && is_numeric($value)) return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0+$value).'</v></c>';
            return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
        };
        $merge=function($range){ return '<mergeCell ref="'.$range.'"/>'; };
        // The summary cards are the screen's own counts (at most six fit the card styles).
        $kpis=[];foreach(array_slice($table['kpis'],0,6,true) as $label=>$value)$kpis[]=[$label,(int)$value];

        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $sheet.='<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $sheet.='<sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="8" topLeftCell="A9" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $sheet.='<sheetFormatPr defaultRowHeight="20"/><cols>';
        $widths=$type==='workforce'?[18,12,12,12,16,12,12]:(in_array($type,['summary','timesheet','overtime','leave'],true)?array_merge([24,16,18],array_fill(0,16,12)):[24,16,18,12,14,10,10,14,18,10,12,10,11,12]);
        for($c=1;$c<=$maxCols;$c++){ $w=$widths[$c-1]??14; $sheet.='<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>'; }
        $sheet.='</cols><sheetData>';

        // Hero header.
        $sheet.='<row r="1" ht="32">'.$cell('✓  Workforce One',1,1,1).'</row>';
        $sheet.='<row r="2" ht="30">'.$cell($title,2,1,2).'</row>';
        $sheet.='<row r="3" ht="22">'.$cell('Employee Attendance & Working Location',3,1,3).'</row>';
        $sheet.='<row r="4" ht="22">'.$cell('Period: '.$start.' to '.$end,4,1,3).$cell('Generated: '.$generated,4,5,3).'</row>';
        $sheet.='<row r="5" ht="8"></row>';

        $sheet.='<row r="6" ht="22">';
        $col=1; foreach($kpis as $ki=>$k){ $ks=13+($ki*2); $sheet.=$cell($k[0],6,$col,$ks); $sheet.=$cell('',6,$col+1,$ks); $col+=2; }
        $sheet.='</row>';
        $sheet.='<row r="7" ht="30">';
        $col=1; foreach($kpis as $ki=>$k){ $ks=14+($ki*2); $sheet.=$cell($k[1],7,$col,$ks,true); $sheet.=$cell('',7,$col+1,$ks); $col+=2; }
        $sheet.='</row>';
        $sheet.='<row r="8" ht="8"></row>';
        

        // Table header and body.
        $tableHeaderRow=9; $rnum=$tableHeaderRow;
        $sheet.='<row r="'.$rnum.'" ht="28">';
        foreach($headers as $ci=>$h)$sheet.=$cell($h,$rnum,$ci+1,4);
        $sheet.='</row>'; $rnum++;
        foreach($data as $ri=>$r){
            $sheet.='<row r="'.$rnum.'" ht="22">';
            foreach($headers as $ci=>$h){
                $v=$r[$ci]??''; $style=5; $numeric=is_numeric($v)&&$v!=='';
                if($type==='attendance' && $ci===4){
                    $style= in_array($v,['Vacation','Leave'],true)?9:(in_array($v,['Business Trip','Training Course'],true)?10:($v==='WFH'?8:($v==='Office'?7:5)));
                } elseif($type==='attendance' && $ci===7){
                    $style=$v==='Leave'?9:($v==='Absent'?11:($v==='Pending'?12:5));
                } elseif($ci===0){ $style=6; }
                $sheet.=$cell($v,$rnum,$ci+1,$style,$numeric);
            }
            $sheet.='</row>'; $rnum++;
        }
        if(!$data){ $sheet.='<row r="'.$rnum.'"><c r="A'.$rnum.'" s="5" t="inlineStr"><is><t>No records found for the selected period.</t></is></c></row>'; $rnum++; }
        $sheet.='</sheetData>';
        if($rnum>$tableHeaderRow)$sheet.='<autoFilter ref="A'.$tableHeaderRow.':'.$lastCol.($rnum-1).'"/>';
        $mergeRanges=['A1:'.$lastCol.'1','A2:'.$lastCol.'2','A3:'.$lastCol.'3','A4:D4','E4:H4']; foreach($kpis as $i=>$k){$c=1+$i*2; $mergeRanges[]=$this->report_xlsx_col($c).'6:'.$this->report_xlsx_col($c+1).'6'; $mergeRanges[]=$this->report_xlsx_col($c).'7:'.$this->report_xlsx_col($c+1).'7';}
        $sheet.='<mergeCells count="'.count($mergeRanges).'">'; foreach($mergeRanges as $range)$sheet.=$merge($range); $sheet.='</mergeCells>';
        $sheet.='<printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>&amp;CWorkforce One  |  Confidential — Internal Use Only</oddFooter></headerFooter>';

        $sheet.='</worksheet>';

        // Styles: blue corporate header, soft KPI cards, clean grid, and status/location pills.
        $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $styles.='<fonts count="6"><font><sz val="11"/><name val="Calibri"/><color rgb="FF172B4D"/></font><font><b/><sz val="20"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font><font><b/><sz val="15"/><name val="Calibri"/><color rgb="FF17365D"/></font><font><sz val="11"/><name val="Calibri"/><color rgb="FF5B6B84"/></font><font><b/><sz val="10"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font><font><sz val="10"/><name val="Calibri"/><color rgb="FF172B4D"/></font></fonts>';
        $fills=[
            'FF0B3D66','FF17365D','FFF2F7FC','FF0B3D66','FF0B3D66','FFF8FBFF','FFEAF4FF','FFEAF8F2','FFEAF4FF','FFFFF3D6','FFF1EAFE','FFFFE8EA','FFEFF2F5'
        ];
        $styles.='<fills count="15"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
        foreach($fills as $f)$styles.='<fill><patternFill patternType="solid"><fgColor rgb="'.$f.'"/><bgColor indexed="64"/></patternFill></fill>';
        $styles.='</fills><borders count="3"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"><color rgb="FFD7E2EE"/></bottom></border><border><left style="thin"><color rgb="FFD7E2EE"/></left><right style="thin"><color rgb="FFD7E2EE"/></right><top style="thin"><color rgb="FFD7E2EE"/></top><bottom style="thin"><color rgb="FFD7E2EE"/></bottom></border></borders>';
        $styles.='<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $styles.='<cellXfs count="25">';
        $styles.='<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'; // 0 base
        $styles.='<xf numFmtId="0" fontId="1" fillId="3" borderId="0" applyAlignment="1"><alignment vertical="center"/></xf>'; // 1 hero
        $styles.='<xf numFmtId="0" fontId="2" fillId="2" borderId="0" applyAlignment="1"><alignment vertical="center"/></xf>'; // 2 title
        $styles.='<xf numFmtId="0" fontId="3" fillId="2" borderId="0" applyAlignment="1"><alignment vertical="center"/></xf>'; // 3 meta
        $styles.='<xf numFmtId="0" fontId="4" fillId="3" borderId="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'; // 4 table header
$styles.='<xf numFmtId="0" fontId="5" fillId="7" borderId="2" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'; // 5 body
$styles.='<xf numFmtId="0" fontId="5" fillId="7" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 6 index
$styles.='<xf numFmtId="0" fontId="5" fillId="9" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 7 office
$styles.='<xf numFmtId="0" fontId="5" fillId="8" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 8 wfh
$styles.='<xf numFmtId="0" fontId="5" fillId="11" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 9 leave
$styles.='<xf numFmtId="0" fontId="5" fillId="12" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 10 trip
$styles.='<xf numFmtId="0" fontId="5" fillId="13" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 11 absent
$styles.='<xf numFmtId="0" fontId="5" fillId="14" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'; // 12 pending
// KPI styles 13-24: each KPI card gets a valid label/value style pair.
        $kpiStyles = [
            ['fill'=>7,  'label'=>'Total Employees'],
            ['fill'=>9,  'label'=>'Office'],
            ['fill'=>8,  'label'=>'WFH'],
            ['fill'=>11, 'label'=>'Leave'],
            ['fill'=>12, 'label'=>'Business Trip'],
            ['fill'=>13, 'label'=>'Absent'],
        ];
        foreach($kpiStyles as $cfg){
            $styles.='<xf numFmtId="0" fontId="2" fillId="'.$cfg['fill'].'" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>';
            $styles.='<xf numFmtId="0" fontId="2" fillId="'.$cfg['fill'].'" borderId="2" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>';
        }
        $styles.='</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

        $tmp=wp_tempnam('workforce-one-'.$type.'.xlsx'); $zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)return new WP_Error('xlsx_open','Could not create Excel workbook.');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        // Further sheets: the report's own (e.g. Daily Details), then the Definitions of every figure.
        $extra=$table['sheets']??[];
        $defs=[];foreach(\WorkforceOne\Reports\Definitions::all() as $term=>$text)$defs[]=[$term,$text];
        $extra[]=['name'=>'Definitions','headers'=>['Figure','Definition'],'rows'=>$defs,'widths'=>[24,110]];
        $sheets=[[$title,$sheet]];
        foreach($extra as $x)$sheets[]=[$x['name'],$this->report_xlsx_table_sheet($x['headers'],$x['rows'],$x['widths']??[])];
        $overrides='';$entries='';$rels='';
        foreach($sheets as $i=>[$name,$xml]){
            $n=$i+1;
            $overrides.='<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $entries.='<sheet name="'.$this->report_xlsx_escape($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml);
        }
        $rels.='<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$overrides.'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$entries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml',$styles); $zip->close();
        $data=file_get_contents($tmp); @unlink($tmp); return $data;
    }
    /** Who exported which report, for which period and filters (wp-admin → Audit Log). */
    private function report_export_audit($type,$format,$s,$e){
        [$team,$employee_id,$status,$employee_status]=$this->report_request_filters();
        $this->audit('report_export','report',0,sprintf('%s %s · %s to %s · team: %s · employee: %s · result: %s · employees: %s',$this->report_catalog()[$type][0]??$type,$format,$s,$e,$team,$employee_id?:'all',$status,$employee_status));
    }

    public function report_xlsx(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        check_admin_referer('ews31_report'); [$s,$e]=$this->period();
        $type=$this->report_request_type('attendance');
        $xlsx=$this->report_xlsx_build($type,$s,$e); if(is_wp_error($xlsx))wp_die(esc_html($xlsx->get_error_message()));
        $this->report_export_audit($type,'Excel',$s,$e);
        nocache_headers(); header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition:attachment; filename='.$type.'_report_'.$s.'_to_'.$e.'.xlsx'); header('Content-Length:'.strlen($xlsx)); echo $xlsx; exit;
    }

    public function report_download(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        check_admin_referer('ews31_report');
        [$s,$e]=$this->period();
        $type=$this->report_request_type('attendance');
        $csv=$this->report_detailed_csv($s,$e,$type);
        $this->report_export_audit($type,'CSV',$s,$e);
        nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename='.$type.'_report_'.$s.'_to_'.$e.'.csv');echo "\xEF\xBB\xBF".$csv;exit;
    }

    public function report_email(){
            if(!$this->can('ews_view_reports')) wp_die('Access denied');
            check_admin_referer('ews31_report');
            [$s,$e]=$this->period();
            $sent=0;$skipped=0;$errors=[];
            global $wpdb;
            // Only the employees the sender manages (everyone for administrators).
            $emps=$this->department_scoped_employees($this->emps());
            foreach($emps as $emp){
                $email=isset($emp->email)?trim($emp->email):'';
                if(!$email || !is_email($email)){ $skipped++; continue; }
                $schedule=$this->employee_schedule_rows((int)$emp->id,$s,$e);
                $subject='Your Weekly Schedule - '.date('d M Y',strtotime($s)).' to '.date('d M Y',strtotime($e));
                $body=$this->build_employee_email($emp,$schedule,$s,$e);
                $headers=['Content-Type: text/html; charset=UTF-8','From: '.get_bloginfo('name').' <'.get_option('admin_email').'>' ];
                $ok=wp_mail($email,$subject,$body,$headers);
                if($ok){$sent++;}else{$errors[]=$email;}
            }
            $this->audit('weekly_schedule_email','schedule',0,'Sent '.$sent.'; skipped '.$skipped.'; failed '.count($errors));
            $this->redirect(['ews_view'=>'schedule','email_sent'=>$sent,'email_skipped'=>$skipped,'email_failed'=>count($errors)]);
        }

    private function employee_schedule_rows($employee_id,$s,$e){
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare(
                "SELECT work_date,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY work_date ASC",
                $employee_id,$s,$e
            ));
            $map=[];
            foreach($rows as $r){$map[$r->work_date]=$r;}
            $dates=[];$t=strtotime($s);$end=strtotime($e);
            while($t<=$end){
                $dow=(int)date('w',$t);
                if(in_array($dow,$this->working_days(),true)) $dates[]=date('Y-m-d',$t);
                $t=strtotime('+1 day',$t);
            }
            $out=[];
            foreach($dates as $d){
                $out[]=['date'=>$d,'status'=>isset($map[$d])?$map[$d]->status:'Not Set','note'=>isset($map[$d])?$map[$d]->note:''];
            }
            return $out;
        }

    private function build_employee_email($emp,$rows,$s,$e){
            $html='<div style="font-family:Arial,sans-serif;max-width:700px;color:#172033">';
            $html.='<h2 style="margin-bottom:4px">Employee Weekly Schedule</h2>';
            $html.='<p style="color:#667085;margin-top:0">Workforce Management Platform</p>';
            $html.='<p><strong>Employee:</strong> '.esc_html($emp->name).'<br><strong>Domain:</strong> '.esc_html($emp->domain_name).'<br><strong>Period:</strong> '.esc_html(date('l, d M Y',strtotime($s))).' to '.esc_html(date('l, d M Y',strtotime($e))).'</p>';
            $html.='<table style="width:100%;border-collapse:collapse"><thead><tr>';
            $html.='<th style="text-align:left;padding:10px;border:1px solid #ddd;background:#f3f4f6">Day</th>';
            $html.='<th style="text-align:left;padding:10px;border:1px solid #ddd;background:#f3f4f6">Date</th>';
            $html.='<th style="text-align:left;padding:10px;border:1px solid #ddd;background:#f3f4f6">Status</th>';
            $html.='<th style="text-align:left;padding:10px;border:1px solid #ddd;background:#f3f4f6">Note</th></tr></thead><tbody>';
            foreach($rows as $r){
                $html.='<tr><td style="padding:10px;border:1px solid #ddd">'.esc_html(date('l',strtotime($r['date']))).'</td>';
                $html.='<td style="padding:10px;border:1px solid #ddd">'.esc_html(date('d M Y',strtotime($r['date']))).'</td>';
                $html.='<td style="padding:10px;border:1px solid #ddd"><strong>'.esc_html($r['status']).'</strong></td>';
                $html.='<td style="padding:10px;border:1px solid #ddd">'.esc_html($r['note']).'</td></tr>';
            }
            $html.='</tbody></table><p style="font-size:12px;color:#667085;margin-top:20px">This is an automated email from Workforce One.</p></div>';
            return $html;
        }
}
