<?php
if (!defined('ABSPATH')) exit;

trait EWS_Reports_Trait {

    private function reports_content(){
        if(!$this->can('ews_view_reports'))return $this->ews_empty_state('Reports unavailable','You do not have permission to view reports.');
        [$s,$e]=$this->period(true);
        $type=sanitize_key($_GET['report_type']??'attendance');
        if(!in_array($type,['attendance','workforce'],true))$type='attendance';

        if($type==='attendance')$body=$this->attendance_report_builder($s,$e);
        else $body=$this->workforce_report_builder($s,$e);

        $base=['ews_view'=>'reports','start'=>$s,'end'=>$e];
        $tabs=[
            'attendance'=>['Attendance Report','Attendance'],
            'workforce'=>['Workforce Report','Workforce'],
        ];
        ob_start(); ?>
        <div class="ews-reports-page">
            <div class="ews-reports-head">
                <div><h2>Reports</h2><p>Build and export workforce reports</p></div>
                <div class="ews-reports-head-badge">Manager Report Center</div>
            </div>
            <div class="ews-report-tabs" role="tablist" aria-label="Report types">
                <?php foreach($tabs as $key=>$tab):
                    $url=add_query_arg(array_merge($base,['report_type'=>$key],[]),$this->app_view_url('reports'));
                ?>
                    <a class="ews-report-tab <?php echo $type===$key?'active':''; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($tab[1]); ?> Report</a>
                <?php endforeach; ?>
            </div>
            <?php echo $body; ?>
        </div>
        <?php return ob_get_clean();
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

    private function report_attendance_classification($planned,$ev,$date,$employee_id,$today,$company_leave){
        if(isset($company_leave[$date]))return ['Leave','',$company_leave[$date]];
        $rule=$this->schedule_type_attendance_rule($planned);
        if($rule==='leave')return ['Leave','',''];
        if($rule==='business_trip')return [$planned,'',''];
        if(!$this->schedule_type_requires_sign_in($planned))return ['Not Scheduled','',''];
        if(!empty($ev['sign_in'])){
            $classification=$this->sign_in_classification($ev['sign_in']->event_at,$employee_id);
            return [$classification==='Late Arrival'?'Late':'Present',date_i18n('H:i',strtotime($ev['sign_in']->event_at)),''];
        }
        if(!empty($ev['late_sign_in']))return ['Late',date_i18n('H:i',strtotime($ev['late_sign_in']->event_at)),''];
        if($date<$today)return ['Absent','',''];
        if($date===$today){
            $hours=$this->working_hours($employee_id);$now_ts=current_time('timestamp');
            $cutoff_ts=strtotime($date.' '.$hours['normal_until'].':00');
            if(!empty($hours['overnight'])&&$hours['start']>$hours['end']&&$hours['normal_until']<$hours['start'])$cutoff_ts=strtotime(date('Y-m-d',strtotime($date.' +1 day')).' '.$hours['normal_until'].':00');
            if($cutoff_ts!==false&&$now_ts>=$cutoff_ts)return ['Absent','',''];
        }
        return ['Pending','',''];
    }

    private function report_attendance_data($s,$e,$selected_team='all',$employee_id=0,$status_filter='all',$employee_status='active'){
        global $wpdb;
        [$emps,$team_options,$team_names_by_emp]=$this->report_employee_scope($selected_team,$employee_id,$employee_status);
        $ids=$emps?implode(',',array_map('intval',wp_list_pluck($emps,'id'))):'0';
        $schedule=[];$events=[];
        if($emps){
            $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s",$s,$e));
            foreach((array)$rows as $r)$schedule[(int)$r->employee_id][$r->work_date]=$r;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s ORDER BY event_at ASC",$s,$e));
            foreach((array)$rows as $r)$events[(int)$r->employee_id][$r->work_date][$r->event_type]=$r;
        }
        $company_leave=$this->company_leave_dates($s,$e);$today=current_time('Y-m-d');$dates=[];
        $t=strtotime($s);$et=strtotime($e);
        while($t!==false&&$t<=$et){$d=date('Y-m-d',$t);if($this->is_working_day($d))$dates[]=$d;$t=strtotime('+1 day',$t);}
        $rows=[];$summary=['records'=>0,'Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0,'Business Trip'=>0,'Pending'=>0,'Not Scheduled'=>0,'Missing Sign-out'=>0];
        foreach($emps as $emp){
            $eid=(int)$emp->id;
            foreach($dates as $d){
                $sch=$schedule[$eid][$d]??null;$planned=$sch?$sch->status:'Not Set';$ev=$events[$eid][$d]??[];
                [$result,$sign_in,$leave_title]=$this->report_attendance_classification($planned,$ev,$d,$eid,$today,$company_leave);
                $sign_out=!empty($ev['sign_out'])?date_i18n('H:i',strtotime($ev['sign_out']->event_at)):'';
                $missing=($result==='Present'||$result==='Late')&&!$sign_out&&$d<=$today;
                $flags=$missing?['Missing Sign-out']:[];
                if($missing){$summary['Missing Sign-out']++;}
                if(isset($summary[$result]))$summary[$result]++;
                $summary['records']++;
                if($status_filter!=='all'&&$result!==$status_filter&&!in_array($status_filter,$flags,true))continue;
                $hours='';
                if($sign_in&&$sign_out){$a=strtotime($d.' '.$sign_in);$b=strtotime($d.' '.$sign_out);if($a!==false&&$b!==false&&$b>$a)$hours=$this->format_duration_minutes((int)floor(($b-$a)/60));}
                $rows[]=[
                    'employee_id'=>$eid,'employee'=>$emp->name,'domain'=>$emp->domain_name,'teams'=>$team_names_by_emp[$eid]??[],'date'=>$d,
                    'planned'=>$planned,'sign_in'=>$sign_in,'sign_out'=>$sign_out,'result'=>$result,'flags'=>$flags,'working_hours'=>$hours,'note'=>$leave_title
                ];
            }
        }
        return [$rows,$summary,$team_options,$emps];
    }

    private function report_status_badge($status){
        $map=[
            'Present'=>['✓','present'],'Late'=>['◷','late'],'Absent'=>['×','absent'],'Leave'=>['▣','leave'],
            'Business Trip'=>['↗','trip'],'Pending'=>['◷','pending'],'Not Scheduled'=>['—','neutral'],'Missing Sign-out'=>['!','missing'],'Not Set'=>['—','neutral']
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

    private function attendance_report_builder($s,$e){
        $team=sanitize_text_field($_GET['team']??'all');$employee_id=absint($_GET['employee']??0);$status=sanitize_text_field($_GET['status']??'all');$employee_status=sanitize_key($_GET['employee_status']??'active');
        if(!in_array($employee_status,['active','inactive','all'],true))$employee_status='active';
        $valid_status=['all','Present','Late','Absent','Leave','Business Trip','Pending','Not Scheduled','Missing Sign-out'];if(!in_array($status,$valid_status,true))$status='all';
        [$rows,$summary,$team_options,$emps]=$this->report_attendance_data($s,$e,$team,$employee_id,$status,$employee_status);
        $page=max(1,absint($_GET['report_page']??1));$per=25;$total=count($rows);$pages=max(1,(int)ceil($total/$per));$page=min($page,$pages);$view=array_slice($rows,($page-1)*$per,$per);
        $base=['ews_view'=>'reports','report_type'=>'attendance','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'status'=>$status,'employee_status'=>$employee_status];
        [$this_week_start,$this_week_end]=$this->report_work_week_range();
        [$last_week_start,$last_week_end]=$this->report_work_week_range(null,-1);
        $quick=[
            'Today'=>[current_time('Y-m-d'),current_time('Y-m-d')],
            'Yesterday'=>[date('Y-m-d',strtotime('-1 day',current_time('timestamp'))),date('Y-m-d',strtotime('-1 day',current_time('timestamp')))],
            'This Week'=>[$this_week_start,$this_week_end],
            'Last Week'=>[$last_week_start,$last_week_end],
            'This Month'=>[date('Y-m-01',current_time('timestamp')),date('Y-m-t',current_time('timestamp'))],
            'Last Month'=>[date('Y-m-01',strtotime('first day of last month',current_time('timestamp'))),date('Y-m-t',strtotime('last day of last month',current_time('timestamp')))],
        ];
        ob_start(); ?>
        <form class="ews-report-filter-card" method="get">
            <input type="hidden" name="ews_view" value="reports"><input type="hidden" name="report_type" value="attendance">
            <div class="ews-report-filter-head"><div><h3>Report Period</h3><p>Select a period and filters, then generate the report.</p></div></div>
            <div class="ews-report-period-grid">
                <label>From Date<input type="date" name="start" value="<?php echo esc_attr($s); ?>"></label>
                <label>To Date<input type="date" name="end" value="<?php echo esc_attr($e); ?>"></label>
                <div class="ews-report-quick"><span>Quick Range</span><div><?php foreach($quick as $label=>$range): $qstart=$range[0];$qend=$range[1]; ?><a class="<?php echo ($s===$qstart&&$e===$qend)?'active':''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($base,['start'=>$qstart,'end'=>$qend,'report_page'=>1]),$this->app_view_url('reports'))); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></div></div>
            </div>
            <div class="ews-report-filter-grid">
                <label>Team<select name="team"><option value="all">All Teams</option><?php foreach($team_options as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($team,$v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
                <label>Employee<select name="employee"><option value="0">All Employees</option><?php foreach($emps as $emp): ?><option value="<?php echo (int)$emp->id; ?>" <?php selected($employee_id,(int)$emp->id); ?>><?php echo esc_html($emp->name); ?><?php echo !$emp->active?' (Inactive)':''; ?></option><?php endforeach; ?></select></label>
                <label>Employee Status<select name="employee_status"><option value="active" <?php selected($employee_status,'active'); ?>>Active Only</option><option value="inactive" <?php selected($employee_status,'inactive'); ?>>Inactive Only</option><option value="all" <?php selected($employee_status,'all'); ?>>All Employees</option></select></label>
                <label>Status<select name="status"><?php foreach($valid_status as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($status,$v); ?>><?php echo esc_html($v==='all'?'All Statuses':$v); ?></option><?php endforeach; ?></select></label>
                <div class="ews-report-generate"><button class="ews-btn" type="submit">▥ &nbsp;Generate Report</button></div>
            </div>
        </form>

        <div class="ews-report-summary-card">
            <div class="ews-report-section-head"><div><h3>Report Summary</h3><p><?php echo esc_html(date_i18n('d M Y',strtotime($s)).' – '.date_i18n('d M Y',strtotime($e))); ?></p></div><span><?php echo esc_html(count($emps).' Employees'); ?> <i>•</i> <?php echo esc_html(count($this->working_days()).' configured working days'); ?></span></div>
            <div class="ews-report-summary-grid">
                <?php $cards=[['Present','✓','green'],['Late','◷','amber'],['Absent','×','red'],['Leave','▣','blue'],['Business Trip','↗','purple'],['Pending','◷','gray'],['Not Scheduled','—','gray'],['Missing Sign-out','!','amber']]; foreach($cards as $c): ?><div class="ews-report-summary-item <?php echo esc_attr($c[2]); ?>"><span><?php echo esc_html($c[1]); ?></span><div><b><?php echo (int)$summary[$c[0]]; ?></b><small><?php echo esc_html($c[0]); ?></small></div></div><?php endforeach; ?></div>
        </div>

        <div class="ews-report-results-card">
            <div class="ews-report-section-head"><div><h3>Report Results <small>(<?php echo (int)$total; ?> records)</small></h3><p>Attendance classification is based on the same schedule and time-log rules used across Workforce One.</p></div><a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge(['action'=>'ews31_report_download'],$base),admin_url('admin-post.php')),'ews31_report')); ?>" data-ews-csv-export="1">⇩ &nbsp;Export CSV</a>
<a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge(['action'=>'ews31_report_xlsx'],$base),admin_url('admin-post.php')),'ews31_report')); ?>" data-ews-xlsx-export="1">▣ &nbsp;Export Excel</a></div>
            <div class="ews-report-table-wrap"><table class="ews-report-table"><thead><tr><th>Employee</th><th>Team</th><th>Date</th><th>Planned</th><th>Sign In</th><th>Sign Out</th><th>Result</th><th>Working Hours</th></tr></thead><tbody>
            <?php if(!$view): ?><tr><td colspan="8" class="ews-report-empty">No records match the selected filters.</td></tr><?php else: foreach($view as $r): ?><tr><td><strong><?php echo esc_html($r['employee']); ?></strong><small><?php echo esc_html($r['domain']); ?></small></td><td><?php echo esc_html($r['teams']?implode(' · ',$r['teams']):'—'); ?></td><td><strong><?php echo esc_html(date_i18n('d M',strtotime($r['date']))); ?></strong><small><?php echo esc_html(date_i18n('D',strtotime($r['date']))); ?></small></td><td><?php echo $this->report_planned_badge($r['planned']); ?></td><td><?php echo esc_html($r['sign_in']?:'—'); ?></td><td><?php echo esc_html($r['sign_out']?:'—'); ?></td><td><div class="ews-report-statuses"><?php echo $this->report_status_badge($r['result']); ?><?php foreach(($r['flags']??[]) as $flag)echo $this->report_status_badge($flag); ?></div></td><td><?php echo esc_html($r['working_hours']?:'—'); ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
            <?php if($pages>1): ?><div class="ews-report-pagination"><span>Showing <?php echo (int)(($page-1)*$per+1); ?> to <?php echo (int)min($page*$per,$total); ?> of <?php echo (int)$total; ?> records</span><div><?php for($i=1;$i<=$pages;$i++): if($i>5&&$i<$pages-1&&abs($i-$page)>1)continue; ?><a class="<?php echo $i===$page?'active':''; ?>" href="<?php echo esc_url(add_query_arg(array_merge($base,['report_page'=>$i]),$this->app_view_url('reports'))); ?>"><?php echo (int)$i; ?></a><?php endfor; ?></div></div><?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    private function workforce_report_builder($s,$e){
        $employee_status=sanitize_key($_GET['employee_status']??'active');
        if(!in_array($employee_status,['active','inactive','all'],true))$employee_status='active';
        [$rows,$summary,$team_options,$emps]=$this->report_attendance_data($s,$e,sanitize_text_field($_GET['team']??'all'),absint($_GET['employee']??0),'all',$employee_status);
        $days=[];$t=strtotime($s);$et=strtotime($e);while($t!==false&&$t<=$et){$d=date('Y-m-d',$t);if($this->is_working_day($d))$days[]=$d;$t=strtotime('+1 day',$t);}
        $matrix=[];$labels=['Office','WFH','Vacation','Leave','Business Trip','Training Course','Not Set','Absent'];
        foreach($days as $d)$matrix[$d]=array_fill_keys($labels,0);
        foreach($rows as $r){$p=$r['planned'];$key=in_array($p,$labels,true)?$p:($p==='Vacation'?'Vacation':$p);if($r['result']==='Absent')$key='Absent';if($p==='Not Set')$key='Not Set';if(isset($matrix[$r['date']][$key]))$matrix[$r['date']][$key]++;}
        $team=sanitize_text_field($_GET['team']??'all');$employee_id=absint($_GET['employee']??0);
        ob_start(); ?>
        <form class="ews-report-filter-card" method="get">
            <input type="hidden" name="ews_view" value="reports"><input type="hidden" name="report_type" value="workforce">
            <div class="ews-report-filter-head"><div><h3>Report Period</h3><p>Select the period and workforce scope.</p></div></div>
            <div class="ews-report-period-grid">
                <label>From Date<input type="date" name="start" value="<?php echo esc_attr($s); ?>"></label>
                <label>To Date<input type="date" name="end" value="<?php echo esc_attr($e); ?>"></label>
                <div class="ews-report-quick"><span>Quick Range</span><div><?php
                [$this_week_start,$this_week_end]=$this->report_work_week_range();
                [$last_week_start,$last_week_end]=$this->report_work_week_range(null,-1);
                $wquick=[
                    'Today'=>[current_time('Y-m-d'),current_time('Y-m-d')],
                    'Yesterday'=>[date('Y-m-d',strtotime('-1 day',current_time('timestamp'))),date('Y-m-d',strtotime('-1 day',current_time('timestamp')))],
                    'This Week'=>[$this_week_start,$this_week_end],
                    'Last Week'=>[$last_week_start,$last_week_end],
                    'This Month'=>[date('Y-m-01',current_time('timestamp')),date('Y-m-t',current_time('timestamp'))],
                    'Last Month'=>[date('Y-m-01',strtotime('first day of last month',current_time('timestamp'))),date('Y-m-t',strtotime('last day of last month',current_time('timestamp')))],
                ];
                foreach($wquick as $label=>$range):
                    $qurl=add_query_arg(['ews_view'=>'reports','report_type'=>'workforce','start'=>$range[0],'end'=>$range[1],'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],$this->app_view_url('reports'));
                ?><a class="<?php echo ($s===$range[0]&&$e===$range[1])?'active':''; ?>" href="<?php echo esc_url($qurl); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></div></div>
            </div>
            <div class="ews-report-filter-grid">
                <label>Team<select name="team"><option value="all">All Teams</option><?php foreach($team_options as $v): ?><option value="<?php echo esc_attr($v); ?>" <?php selected($team,$v); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
                <label>Employee<select name="employee"><option value="0">All Employees</option><?php foreach($emps as $emp): ?><option value="<?php echo (int)$emp->id; ?>" <?php selected($employee_id,(int)$emp->id); ?>><?php echo esc_html($emp->name); ?><?php echo !$emp->active?' (Inactive)':''; ?></option><?php endforeach; ?></select></label>
                <label>Employee Status<select name="employee_status"><option value="active" <?php selected($employee_status,'active'); ?>>Active Only</option><option value="inactive" <?php selected($employee_status,'inactive'); ?>>Inactive Only</option><option value="all" <?php selected($employee_status,'all'); ?>>All Employees</option></select></label>
                <div></div><div class="ews-report-generate"><button class="ews-btn" type="submit">▥ &nbsp;Generate Report</button></div>
            </div>
        </form>
        <div class="ews-report-summary-card"><div class="ews-report-section-head"><div><h3>Workforce Summary</h3><p>Planned workforce assignments across the selected period · <?php echo esc_html(date_i18n('d M Y',strtotime($s)).' – '.date_i18n('d M Y',strtotime($e))); ?></p></div><span><?php echo esc_html(count($emps).' Employees'); ?></span></div><div class="ews-report-summary-grid four">
            <?php foreach([['Office','▦','blue'],['WFH','⌂','purple'],['Leave','▣','amber'],['Business Trip','↗','cyan']] as $c): $count=0;foreach($rows as $r){if($r['planned']===$c[0]||($c[0]==='Leave'&&$r['planned']==='Vacation'))$count++;} ?><div class="ews-report-summary-item <?php echo esc_attr($c[2]); ?>"><span><?php echo esc_html($c[1]); ?></span><div><b><?php echo (int)$count; ?></b><small><?php echo esc_html($c[0]); ?></small></div></div><?php endforeach; ?></div></div>
        <div class="ews-report-results-card"><div class="ews-report-section-head"><div><h3>Daily Workforce Distribution</h3><p>Counts are based on planned schedule status, not inferred from sign-in location.</p></div><a class="ews-btn secondary ews-report-export-csv" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'ews31_report_download','report_type'=>'workforce','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],admin_url('admin-post.php')),'ews31_report')); ?>" data-ews-csv-export="1">⇩ &nbsp;Export CSV</a>
<a class="ews-btn secondary ews-report-export-xlsx" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'ews31_report_xlsx','report_type'=>'workforce','start'=>$s,'end'=>$e,'team'=>$team,'employee'=>$employee_id,'employee_status'=>$employee_status],admin_url('admin-post.php')),'ews31_report')); ?>" data-ews-xlsx-export="1">▣ &nbsp;Export Excel</a></div><div class="ews-report-table-wrap"><table class="ews-report-table ews-workforce-table"><thead><tr><th>Date</th><th>Office</th><th>WFH</th><th>Leave</th><th>Business Trip</th><th>Absent</th><th>Not Set</th></tr></thead><tbody><?php foreach($days as $d): ?><tr><td><strong><?php echo esc_html(date_i18n('d M',strtotime($d))); ?></strong><small><?php echo esc_html(date_i18n('D',strtotime($d))); ?></small></td><td><?php echo (int)($matrix[$d]['Office']??0); ?></td><td><?php echo (int)($matrix[$d]['WFH']??0); ?></td><td><?php echo (int)(($matrix[$d]['Vacation']??0)+($matrix[$d]['Leave']??0)); ?></td><td><?php echo (int)(($matrix[$d]['Business Trip']??0)+($matrix[$d]['Training Course']??0)); ?></td><td><?php echo (int)($matrix[$d]['Absent']??0); ?></td><td><?php echo (int)($matrix[$d]['Not Set']??0); ?></td></tr><?php endforeach; if(!$days): ?><tr><td colspan="7" class="ews-report-empty">No configured working days in this period.</td></tr><?php endif; ?></tbody></table></div></div>
        <?php return ob_get_clean();
    }

    private function overtime_report_content($s,$e){
        global $wpdb;
        $employee_id=absint($_GET['ot_employee']??0);
        $status=sanitize_key($_GET['ot_status']??'all');
        if(!in_array($status,['all','Pending','Approved','Rejected'],true))$status='all';

        $where="r.overtime_date BETWEEN %s AND %s";
        $args=[$s,$e];
        if($employee_id){$where.=" AND r.employee_id=%d";$args[]=$employee_id;}
        if($status!=='all'){$where.=" AND r.status=%s";$args[]=$status;}

        $table=$wpdb->prefix.'ews_overtime_requests';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.*,e.name employee_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id WHERE {$where} ORDER BY r.overtime_date DESC,e.name ASC,r.start_time ASC",
            ...$args
        ));
        $employees=$wpdb->get_results("SELECT id,name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");

        $requested=0;$approved=0;$actual=0;$extra=0;
        foreach($rows as $r){
            $requested+=(int)$r->requested_minutes;
            if($r->status==='Approved'){
                $sum=$this->overtime_attendance_summary((int)$r->employee_id,$r->overtime_date);
                foreach($sum['approved_requests'] as $d)if((int)$d['id']===(int)$r->id){$approved+=(int)$d['approved_minutes'];$actual+=(int)$d['actual_minutes'];break;}
                $extra+=(int)$sum['unapproved_extra_minutes'];
            }
        }
        ob_start(); ?>
        <div class="ews-card ews-form" style="margin-top:18px">
            <div style="display:flex;justify-content:space-between;gap:15px;align-items:flex-start;flex-wrap:wrap">
                <div><h3 style="margin-bottom:4px">Overtime Report</h3><p style="margin:0;color:#667085">Requested, approved and actual overtime for the selected period.</p></div>
            </div>
            <form style="margin-top:16px">
                <input type="hidden" name="ews_view" value="reports">
                <input type="hidden" name="start" value="<?php echo esc_attr($s);?>">
                <input type="hidden" name="end" value="<?php echo esc_attr($e);?>">
                <div class="ews-grid2">
                    <label>Employee
                        <select name="ot_employee">
                            <option value="0">All Employees</option>
                            <?php foreach($employees as $emp): ?><option value="<?php echo (int)$emp->id;?>" <?php selected($employee_id,(int)$emp->id);?>><?php echo esc_html($emp->name);?></option><?php endforeach;?>
                        </select>
                    </label>
                    <label>Status
                        <select name="ot_status">
                            <?php foreach(['all'=>'All','Pending'=>'Pending','Approved'=>'Approved','Rejected'=>'Rejected'] as $v=>$label): ?><option value="<?php echo esc_attr($v);?>" <?php selected($status,$v);?>><?php echo esc_html($label);?></option><?php endforeach;?>
                        </select>
                    </label>
                </div>
                <button class="ews-btn">Apply Filters</button>
            </form>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px">
                <div style="padding:14px;border:1px solid #eaecf0;border-radius:12px"><small>Requested</small><strong style="display:block;font-size:20px"><?php echo esc_html($this->format_duration_minutes($requested));?></strong></div>
                <div style="padding:14px;border:1px solid #eaecf0;border-radius:12px"><small>Approved</small><strong style="display:block;font-size:20px"><?php echo esc_html($this->format_duration_minutes($approved));?></strong></div>
                <div style="padding:14px;border:1px solid #eaecf0;border-radius:12px"><small>Actual OT</small><strong style="display:block;font-size:20px"><?php echo esc_html($this->format_duration_minutes($actual));?></strong></div>
                <div style="padding:14px;border:1px solid #eaecf0;border-radius:12px"><small>Unapproved Extra</small><strong style="display:block;font-size:20px"><?php echo esc_html($this->format_duration_minutes($extra));?></strong></div>
            </div>

            <div style="overflow:auto;margin-top:18px">
                <table style="width:100%;border-collapse:collapse;min-width:820px">
                    <thead><tr>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Employee</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Date</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Requested</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Approved</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Actual OT</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Unapproved Extra</th>
                        <th style="text-align:left;padding:10px;border-bottom:1px solid #eaecf0">Status</th>
                    </tr></thead><tbody>
                    <?php if(!$rows): ?><tr><td colspan="7" style="padding:22px;text-align:center;color:#667085">No overtime records found.</td></tr>
                    <?php else: foreach($rows as $r):
                        $r_approved=0;$r_actual=0;$r_extra=0;
                        if($r->status==='Approved'){
                            $sum=$this->overtime_attendance_summary((int)$r->employee_id,$r->overtime_date);
                            foreach($sum['approved_requests'] as $d)if((int)$d['id']===(int)$r->id){$r_approved=(int)$d['approved_minutes'];$r_actual=(int)$d['actual_minutes'];break;}
                            $r_extra=(int)$sum['unapproved_extra_minutes'];
                        }
                        $cls=$r->status==='Approved'?'#027a48':($r->status==='Rejected'?'#b42318':'#9a3412');
                    ?>
                    <tr>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo esc_html($r->employee_name);?></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo esc_html(date_i18n('d M Y',strtotime($r->overtime_date)));?><br><small><?php echo esc_html(substr($r->start_time,0,5).' → '.substr($r->end_time,0,5));?></small></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo esc_html($this->format_duration_minutes($r->requested_minutes));?></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo $r->status==='Approved'?esc_html($this->format_duration_minutes($r_approved)):'—';?></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo $r->status==='Approved'?esc_html($this->format_duration_minutes($r_actual)):'—';?></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><?php echo $r->status==='Approved'?esc_html($this->format_duration_minutes($r_extra)):'—';?></td>
                        <td style="padding:10px;border-top:1px solid #f2f4f7"><strong style="color:<?php echo esc_attr($cls);?>"><?php echo esc_html($r->status);?></strong></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    private function period($today_default=false){ $today=current_time('Y-m-d');$default_start=$today_default?$today:date('Y-m-01');$s=sanitize_text_field($_GET['start']??$default_start);$e=sanitize_text_field($_GET['end']??$today);if(!$this->valid_date($s)||!$this->valid_date($e)||$e<$s){$s=$default_start;$e=$today;}return [$s,$e];}

    private function report_csv($s,$e){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT e.name,e.domain_name,s.work_date,s.status FROM {$this->employees} e LEFT JOIN {$this->schedule} s ON s.employee_id=e.id AND s.work_date BETWEEN %s AND %s WHERE e.active=1 ORDER BY e.name,s.work_date",$s,$e));$by=[];foreach($rows as $r){$k=$r->domain_name;if(!isset($by[$k]))$by[$k]=['name'=>$r->name,'domain'=>$k,'days'=>[],'c'=>array_fill_keys($this->statuses(),0)];if($r->work_date){$by[$k]['days'][$r->work_date]=$r->status;if(isset($by[$k]['c'][$r->status]))$by[$k]['c'][$r->status]++;}}$dates=[];$t=strtotime($s);$et=strtotime($e);while($t<=$et){if(in_array((int)date('w',$t),$this->working_days(),true))$dates[]=date('Y-m-d',$t);$t+=86400;}$f=fopen('php://temp','w+');fputcsv($f,array_merge(['Employee Name','Domain Name'],$dates,array_keys(array_combine(array_map(function($x){ return $x.' Count'; },$this->statuses()),$this->statuses()))));foreach($by as $x){$r=[$x['name'],$x['domain']];foreach($dates as $d)$r[]=$x['days'][$d]??'Not Set';foreach($this->statuses() as $st)$r[]=$x['c'][$st];fputcsv($f,$r);}rewind($f);return stream_get_contents($f);}

    private function report_detailed_csv($s,$e,$type='attendance'){
        if($type==='workforce'){
            $employee_status=sanitize_key($_GET['employee_status']??'active');if(!in_array($employee_status,['active','inactive','all'],true))$employee_status='active';
            [$rows]= $this->report_attendance_data($s,$e,sanitize_text_field($_GET['team']??'all'),absint($_GET['employee']??0),'all',$employee_status);
            $days=[];$t=strtotime($s);$et=strtotime($e);while($t!==false&&$t<=$et){$d=date('Y-m-d',$t);if($this->is_working_day($d))$days[]=$d;$t=strtotime('+1 day',$t);}
            $f=fopen('php://temp','w+');fputcsv($f,['Date','Office','WFH','Leave','Business Trip','Absent','Not Set']);
            foreach($days as $d){$c=['Office'=>0,'WFH'=>0,'Leave'=>0,'Business Trip'=>0,'Absent'=>0,'Not Set'=>0];foreach($rows as $r){if($r['date']!==$d)continue;$p=$r['planned'];if($p==='Office')$c['Office']++;elseif($p==='WFH')$c['WFH']++;elseif(in_array($p,['Vacation','Leave'],true))$c['Leave']++;elseif(in_array($p,['Business Trip','Training Course'],true))$c['Business Trip']++;elseif($r['result']==='Absent')$c['Absent']++;elseif($p==='Not Set')$c['Not Set']++;}fputcsv($f,[$d,$c['Office'],$c['WFH'],$c['Leave'],$c['Business Trip'],$c['Absent'],$c['Not Set']]);}
            rewind($f);return stream_get_contents($f);
        }
        $team=sanitize_text_field($_GET['team']??'all');$employee_id=absint($_GET['employee']??0);$status=sanitize_text_field($_GET['status']??'all');$employee_status=sanitize_key($_GET['employee_status']??'active');if(!in_array($employee_status,['active','inactive','all'],true))$employee_status='active';
        [$rows]= $this->report_attendance_data($s,$e,$team,$employee_id,$status,$employee_status);
        $f=fopen('php://temp','w+');fputcsv($f,['Employee','Employee ID','Team','Date','Planned','Sign In','Sign Out','Result','Flags','Working Hours']);
        foreach($rows as $r)fputcsv($f,[$r['employee'],$r['domain'],$r['teams']?implode(' · ',$r['teams']):'',$r['date'],$r['planned'],$r['sign_in']?:'',$r['sign_out']?:'',$r['result'],!empty($r['flags'])?implode('; ',$r['flags']):'',$r['working_hours']?:'']);
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
    private function report_xlsx_cell($value,$row,$col,$style=0,$numeric=false){
        $ref=$this->report_xlsx_col($col).$row;
        if($numeric && $value!=='' && is_numeric($value)){
            return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0+$value).'</v></c>';
        }
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->report_xlsx_escape($value).'</t></is></c>';
    }
    private function report_xlsx_build($type,$start,$end){
        if(!class_exists('ZipArchive')) return new WP_Error('xlsx_zip','The PHP ZipArchive extension is required for Excel export.');

        $csv=$this->report_detailed_csv($start,$end,$type);
        $lines=preg_split("/\r\n|\n|\r/",$csv,-1,PREG_SPLIT_NO_EMPTY);
        $rows=[]; foreach($lines as $line)$rows[]=str_getcsv($line);
        if(!$rows) $rows=[['No data available']];

        $title=$type==='workforce'?'Workforce Report':'Attendance Report';
        $generated=current_time('Y-m-d H:i');
        $headers=$rows[0];
        $data=array_slice($rows,1);
        $maxCols=$type==='attendance'?max(12,count($headers)):max(10,count($headers));
        $lastCol=$this->report_xlsx_col($maxCols);

        $esc=function($v){return $this->report_xlsx_escape($v);};
        $cell=function($value,$row,$col,$style=0,$numeric=false) use ($esc){
            $ref=$this->report_xlsx_col($col).$row;
            if($numeric && $value!=='' && is_numeric($value)) return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0+$value).'</v></c>';
            return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
        };
        $merge=function($range){ return '<mergeCell ref="'.$range.'"/>'; };

        // Summary metrics for the polished executive header.
        $summary=['Total Employees'=>0,'Office'=>0,'WFH'=>0,'Leave'=>0,'Business Trip'=>0,'Absent'=>0,'Pending'=>0];
        if($type==='attendance'){
            $ids=[]; foreach($data as $r){ if(isset($r[1]) && $r[1]!=='')$ids[$r[1]]=true; $result=$r[7]??''; $planned=$r[4]??'';
                if($planned==='Office')$summary['Office']++; elseif($planned==='WFH')$summary['WFH']++; elseif(in_array($planned,['Vacation','Leave'],true))$summary['Leave']++; elseif(in_array($planned,['Business Trip','Training Course'],true))$summary['Business Trip']++;
                if($result==='Absent')$summary['Absent']++; if($result==='Pending')$summary['Pending']++;
            }
            $summary['Total Employees']=count($ids);
        } else {
            $summary['Total Employees']=0;
            foreach($data as $r){ $summary['Office']+=(int)($r[1]??0); $summary['WFH']+=(int)($r[2]??0); $summary['Leave']+=(int)($r[3]??0); $summary['Business Trip']+=(int)($r[4]??0); $summary['Absent']+=(int)($r[5]??0); }
            // Workforce rows are daily aggregates; Total Employees is not a sum of daily rows.
            $summary['Total Employees']=''; $summary['Pending']='';
        }

        $sheet='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $sheet.='<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        $sheet.='<sheetViews><sheetView showGridLines="0" workbookViewId="0"><pane ySplit="8" topLeftCell="A9" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $sheet.='<sheetFormatPr defaultRowHeight="20"/><cols>';
        $widths=$type==='workforce'?[18,12,12,12,16,12,12]:[5,24,20,18,14,14,14,18,24,16];
        for($c=1;$c<=$maxCols;$c++){ $w=$widths[$c-1]??14; $sheet.='<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>'; }
        $sheet.='</cols><sheetData>';

        // Hero header.
        $sheet.='<row r="1" ht="32">'.$cell('✓  Workforce One',1,1,1).'</row>';
        $sheet.='<row r="2" ht="30">'.$cell($title,2,1,2).'</row>';
        $sheet.='<row r="3" ht="22">'.$cell('Employee Attendance & Working Location',3,1,3).'</row>';
        $sheet.='<row r="4" ht="22">'.$cell('Period: '.$start.' to '.$end,4,1,3).$cell('Generated: '.$generated,4,5,3).'</row>';
        $sheet.='<row r="5" ht="8"></row>';

        // KPI cards: one card per merged pair.
        $kpis=$type==='attendance'
            ? [['Total Employees',$summary['Total Employees'],6],['Office',$summary['Office'],7],['WFH',$summary['WFH'],8],['Leave',$summary['Leave'],9],['Business Trip',$summary['Business Trip'],10],['Absent',$summary['Absent'],11]]
            : [['Office',$summary['Office'],7],['WFH',$summary['WFH'],8],['Leave',$summary['Leave'],9],['Business Trip',$summary['Business Trip'],10],['Absent',$summary['Absent'],11]];
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
        $styles.='</cellXfs></styleSheet>';

        $tmp=wp_tempnam('workforce-one-'.$type.'.xlsx'); $zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)return new WP_Error('xlsx_open','Could not create Excel workbook.');
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $sheetName=$type==='workforce'?'Workforce Report':'Attendance Report';
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$sheet);
        $zip->addFromString('xl/styles.xml',$styles); $zip->close();
        $data=file_get_contents($tmp); @unlink($tmp); return $data;
    }
    public function report_xlsx(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        check_admin_referer('ews31_report'); [$s,$e]=$this->period(true);
        $type=sanitize_key($_GET['report_type']??'attendance'); if(!in_array($type,['attendance','workforce'],true))$type='attendance';
        $xlsx=$this->report_xlsx_build($type,$s,$e); if(is_wp_error($xlsx))wp_die(esc_html($xlsx->get_error_message()));
        nocache_headers(); header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition:attachment; filename='.$type.'_report_'.$s.'_to_'.$e.'.xlsx'); header('Content-Length:'.strlen($xlsx)); echo $xlsx; exit;
    }

    public function report_download(){
        if(!$this->can('ews_view_reports'))wp_die('Access denied');
        check_admin_referer('ews31_report');
        [$s,$e]=$this->period(true);
        $type=sanitize_key($_GET['report_type']??'attendance');
        if(!in_array($type,['attendance','workforce'],true))$type='attendance';
        $csv=$this->report_detailed_csv($s,$e,$type);
        nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename='.$type.'_report_'.$s.'_to_'.$e.'.csv');echo "\xEF\xBB\xBF".$csv;exit;
    }

    public function report_email(){
            if(!$this->can('ews_view_reports')) wp_die('Access denied');
            check_admin_referer('ews31_report');
            [$s,$e]=$this->period();
            $sent=0;$skipped=0;$errors=[];
            global $wpdb;
            $emps=$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
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
