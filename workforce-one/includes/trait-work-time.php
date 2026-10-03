<?php
if (!defined('ABSPATH')) exit;

/**
 * Working time: shifts and working hours per employee, grace period and Sign In window, working
 * days, company holidays, which day the current (overnight) shift belongs to and today's events.
 * Shared by Sign In, breaks, schedules and every report.
 */
trait EWS_Work_Time_Trait {


    /**
     * Overtime on one day for one employee: approved, actually worked and unapproved extra minutes
     * (rules: \WorkforceOne\Reports\DayMetrics::overtime()), against the employee's own shift and
     * their first Sign In / last Sign Out.
     */
    private function overtime_attendance_summary($eid,$date){
            if(!$this->overtime_enabled())return ['approved_minutes'=>0,'actual_approved_minutes'=>0,'unapproved_extra_minutes'=>0,'approved_requests'=>[]];
            global $wpdb;
            $table=$wpdb->prefix.'ews_overtime_requests';
            $requests=(array)$wpdb->get_results($wpdb->prepare("SELECT id,start_time,end_time FROM {$table} WHERE employee_id=%d AND overtime_date=%s AND status='Approved' ORDER BY start_time ASC",$eid,$date));
            $events=$wpdb->get_results($wpdb->prepare("SELECT event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY event_at ASC",$eid,$date));
            $sign_in=null;$sign_out=null;
            foreach($events as $ev){
                if(($ev->event_type==='sign_in'||$ev->event_type==='late_sign_in') && !$sign_in)$sign_in=$ev->event_at;
                if($ev->event_type==='sign_out')$sign_out=$ev->event_at; // the last one
            }
            $cfg=$this->working_hours((int)$eid);
            $o=\WorkforceOne\Reports\DayMetrics::overtime($date,$cfg['start'],$cfg['end'],$sign_in,$sign_out,array_map(function($r){return ['start'=>$r->start_time,'end'=>$r->end_time];},$requests));
            $details=[];
            foreach(array_values(array_filter($requests,function($r)use($date){return strtotime($date.' '.$r->end_time)>strtotime($date.' '.$r->start_time);})) as $i=>$r)$details[]=['id'=>(int)$r->id,'date'=>$date,'start'=>$r->start_time,'end'=>$r->end_time,'approved_minutes'=>$o['windows'][$i]['approved'],'actual_minutes'=>$o['windows'][$i]['actual']];
            return ['approved_minutes'=>$o['approved'],'actual_approved_minutes'=>$o['actual'],'unapproved_extra_minutes'=>$o['extra'],'approved_requests'=>$details,'sign_in'=>$sign_in,'sign_out'=>$sign_out,'scheduled_start'=>$cfg['start'],'scheduled_end'=>$cfg['end']];
        }

    /**
     * The day the employee's current shift belongs to (src/Attendance/ShiftDay.php): today, or, for an
     * overnight shift after midnight (or still open after its end), the day it started. Sign In / Out,
     * breaks and the Sign In page all use it.
     */
    private function attendance_day($eid){
            $eid=(int)$eid;$today=current_time('Y-m-d');
            static $cache=[];
            $key=$eid.'|'.$today.'|'.current_time('H:i');
            if(isset($cache[$key]))return $cache[$key];
            $h=$this->working_hours($eid);
            $overnight=!empty($h['overnight']) && $h['start']>$h['end'];
            $open=false;
            if($overnight && $eid){
                global $wpdb;
                $yesterday=date('Y-m-d',strtotime($today.' -1 day'));
                $types=(array)$wpdb->get_col($wpdb->prepare("SELECT event_type FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s",$eid,$yesterday));
                $open=(in_array('sign_in',$types,true)||in_array('late_sign_in',$types,true)) && !in_array('sign_out',$types,true);
            }
            return $cache[$key]=\WorkforceOne\Attendance\ShiftDay::resolve($today,current_time('H:i'),(string)$h['start'],(string)$h['end'],$overnight,$open);
        }

    private function today_schedule_for_employee($eid){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$eid,$this->attendance_day($eid)));
        }

    private function today_events($eid){
            global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s ORDER BY event_at ASC",$eid,$this->attendance_day($eid)));
            $o=[];foreach($rows as $r)$o[$r->event_type]=$r;return $o;
        }

    private function default_shifts(){
            return [
                ['id'=>1,'name'=>'Standard','start'=>'08:00','end'=>'17:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1],
            ];
        }

    private function shifts(){
            if(self::$ews_shifts_cache!==null)return self::$ews_shifts_cache;
            $raw=$this->option('ews_shifts');
            if(!is_array($raw)||empty($raw)){
                $raw=$this->default_shifts();
                update_option('ews_shifts',$raw,false);
            }
            $out=[];
            foreach($raw as $i=>$shift){
                if(!is_array($shift))continue;
                $id=absint($shift['id']??($i+1));
                $name=trim(sanitize_text_field($shift['name']??''));
                $start=sanitize_text_field($shift['start']??''); $end=sanitize_text_field($shift['end']??'');
                if(!$id||$name===''||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end))continue;
                $out[]=['id'=>$id,'name'=>$name,'start'=>$start,'end'=>$end,'grace'=>max(0,min(180,(int)($shift['grace']??10))),'sign_in_cutoff_minutes'=>max(0,min(1440,(int)($shift['sign_in_cutoff_minutes']??240))),'overnight'=>!empty($shift['overnight'])?1:0,'active'=>array_key_exists('active',$shift)?(!empty($shift['active'])?1:0):1];
            }
            self::$ews_shifts_cache=$out?:$this->default_shifts();
            return self::$ews_shifts_cache;
        }

    private function shift_for_employee($employee_id=0){
            global $wpdb;
            $employee_id=absint($employee_id);
            if(!$employee_id){$emp=$this->current_employee();$employee_id=$emp?(int)$emp->id:0;}
            if(!$employee_id)return null;
            if(array_key_exists($employee_id,self::$ews_shift_for_employee_cache))return self::$ews_shift_for_employee_cache[$employee_id];
            $shift_id=(int)$wpdb->get_var($wpdb->prepare("SELECT default_shift_id FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
            if(!$shift_id)return self::$ews_shift_for_employee_cache[$employee_id]=null;
            foreach($this->shifts() as $shift){
                if((int)$shift['id']===$shift_id && !empty($shift['active']))return self::$ews_shift_for_employee_cache[$employee_id]=$shift;
            }
            return self::$ews_shift_for_employee_cache[$employee_id]=null;
        }

    /**
     * Fills the shift_for_employee() cache for many employees at once, so a page that lists them
     * (reports, payroll, the dashboard, the Sign In / Out report) does not run one query each.
     * Takes employee rows (their default_shift_id is used when loaded) or ids. The result per
     * employee is the same as shift_for_employee(): the active default shift, or null for the
     * Company Working Hours.
     * @param array<int,object|int> $employees
     */
    private function prime_shift_cache(array $employees){
            global $wpdb;
            $shift_ids=[];$missing=[];
            foreach($employees as $e){
                $id=is_object($e)?absint($e->id??0):absint($e);
                if(!$id||array_key_exists($id,self::$ews_shift_for_employee_cache))continue;
                if(is_object($e)&&property_exists($e,'default_shift_id'))$shift_ids[$id]=(int)$e->default_shift_id;
                else $missing[$id]=$id;
            }
            foreach(array_chunk(array_values($missing),500) as $chunk){
                $ph=implode(',',array_fill(0,count($chunk),'%d'));
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,default_shift_id FROM {$this->employees} WHERE id IN ($ph)",$chunk)) as $r)$shift_ids[(int)$r->id]=(int)$r->default_shift_id;
                foreach($chunk as $id){ if(!isset($shift_ids[$id]))$shift_ids[$id]=0; }
            }
            if(!$shift_ids)return;
            $active=[];
            foreach($this->shifts() as $shift){ if(!empty($shift['active']) && !isset($active[(int)$shift['id']]))$active[(int)$shift['id']]=$shift; }
            foreach($shift_ids as $id=>$shift_id)self::$ews_shift_for_employee_cache[$id]=$shift_id&&isset($active[$shift_id])?$active[$shift_id]:null;
        }

    private function default_working_hours(){
            return ['start'=>'08:00','normal_until'=>'10:00','end'=>'17:00'];
        }

    private function working_hours($employee_id=0){
            $employee_id=absint($employee_id);
            if(!$employee_id){$emp=$this->current_employee();$employee_id=$emp?(int)$emp->id:0;}
            if(array_key_exists($employee_id,self::$ews_working_hours_cache))return self::$ews_working_hours_cache[$employee_id];
            $shift=$this->shift_for_employee($employee_id);
            if($shift){
                // A no-show counts as Absent today this long after the shift starts (Schedule
                // Configuration; 2 hours by default). Past days are Absent whatever this is.
                $start_ts=strtotime('1970-01-01 '.$shift['start'].':00');
                $cutoff_ts=$start_ts!==false?$start_ts+\WorkforceOne\Schedule\ConfigRules::absentAfterMinutes($this->option('ews_absent_after_minutes'))*MINUTE_IN_SECONDS:false;
                $end_ts=strtotime('1970-01-01 '.$shift['end'].':00');
                if($cutoff_ts!==false && $end_ts!==false && empty($shift['overnight']) && $cutoff_ts>$end_ts)$cutoff_ts=$end_ts;
                $normal_until=$cutoff_ts!==false?date('H:i',$cutoff_ts):$shift['end'];
                return self::$ews_working_hours_cache[$employee_id]=['start'=>$shift['start'],'normal_until'=>$normal_until,'end'=>$shift['end'],'grace'=>$shift['grace'],'sign_in_cutoff_minutes'=>$shift['sign_in_cutoff_minutes'],'overnight'=>$shift['overnight'],'shift_id'=>$shift['id'],'shift_name'=>$shift['name']];
            }
            $cfg=$this->option('ews_working_hours'); $defaults=$this->default_working_hours(); if(!is_array($cfg))$cfg=[];
            $start=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['start']??'')?$cfg['start']:$defaults['start'];
            $end=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['end']??'')?$cfg['end']:$defaults['end'];
            $normal_until=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['normal_until']??'')?$cfg['normal_until']:$end;
            return self::$ews_working_hours_cache[$employee_id]=['start'=>$start,'normal_until'=>$normal_until,'end'=>$end,'grace'=>$this->global_grace_period(),'sign_in_cutoff_minutes'=>240,'overnight'=>(int)$this->option('ews_allow_overnight_shift'),'shift_id'=>0,'shift_name'=>'Company Default'];
        }

    private function global_grace_period(){ return max(0,min(180,(int)$this->option('ews_grace_period'))); }

    private function attendance_grace_period($employee_id=0){
            $h=$this->working_hours($employee_id); return isset($h['grace'])?(int)$h['grace']:$this->global_grace_period();
        }

    private function sign_in_window_bounds($employee_id=0){
            $now=current_time('timestamp'); $cfg=$this->working_hours($employee_id);
            $start_time=(string)($cfg['start']??''); $end_time=(string)($cfg['end']??'');
            $cutoff_minutes=max(0,min(1440,(int)($cfg['sign_in_cutoff_minutes']??240)));
            if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start_time)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end_time))return ['start'=>0,'cutoff'=>0,'end'=>0,'cutoff_minutes'=>$cutoff_minutes];
            $today=date('Y-m-d',$now);
            $start=strtotime($today.' '.$start_time.':00');
            if($start===false)return ['start'=>0,'cutoff'=>0,'end'=>0,'cutoff_minutes'=>$cutoff_minutes];
            $overnight=!empty($cfg['overnight']) && $start_time>$end_time;
            if($overnight && date('H:i',$now)<=$end_time)$start=strtotime(date('Y-m-d',strtotime($today.' -1 day')).' '.$start_time.':00');
            $end=strtotime(date('Y-m-d',$start).' '.$end_time.':00');
            if($end!==false && $overnight && $end<=$start)$end=strtotime('+1 day',$end);
            $cutoff=$start+($cutoff_minutes*60);
            if($end!==false && $end>0 && $cutoff>$end)$cutoff=$end;
            return ['start'=>$start,'cutoff'=>$cutoff,'end'=>$end?:0,'cutoff_minutes'=>$cutoff_minutes];
        }

    private function sign_in_window_open($employee_id=0){
            $b=$this->sign_in_window_bounds($employee_id);
            if(!$b['start']||!$b['cutoff'])return false;
            $now=current_time('timestamp');
            return $now>=$b['start'] && $now<=$b['cutoff'];
        }

    private function sign_in_classification($event_at=null,$employee_id=0){
            // The one "late" rule (src/Attendance/Lateness.php), shared with the reports and payroll.
            $ts=$event_at?(int)strtotime($event_at):(int)current_time('timestamp'); $cfg=$this->working_hours($employee_id);
            return \WorkforceOne\Attendance\Lateness::classify($ts,(string)$cfg['start'],(string)$cfg['end'],!empty($cfg['overnight']),(int)$this->attendance_grace_period($employee_id));
        }

    private function working_hours_save($start,$normal_until,$end){
            $valid=function($v){return is_string($v)&&preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$v);};
            if(!$valid($start)||!$valid($end))return new WP_Error('invalid_hours','Invalid working hours.');
            if($start===$end)return new WP_Error('invalid_hours','Work start and work end cannot be the same.');
            update_option('ews_working_hours',['start'=>$start,'normal_until'=>$end,'end'=>$end],false);
            return true;
        }


    private function default_working_days(){
            // PHP date('w'): Sunday=0 ... Saturday=6.
            return \WorkforceOne\Settings\Options::defaultOf('ews_working_days'); // Sunday–Thursday.
        }

    private function working_days(){
            $days=$this->option('ews_working_days');
            if(!is_array($days))$days=$this->default_working_days();
            $out=[];
            foreach($days as $day){
                $day=absint($day);
                if($day>=0&&$day<=6)$out[$day]=$day;
            }
            $out=array_values($out); sort($out,SORT_NUMERIC);
            return $out;
        }

    private function is_working_day($date){
            $t=strtotime($date);
            if(!$t)return false;
            return in_array((int)date('w',$t),$this->working_days(),true);
        }

    private function working_day_names(){
            // Display only: WordPress core provides the translated weekday names.
            global $wp_locale;
            if($wp_locale instanceof WP_Locale){$out=[];for($i=0;$i<7;$i++)$out[$i]=$wp_locale->get_weekday($i);return $out;}
            return [0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'];
        }

    private function week_dates_configured($raw=null){
            $raw=$raw?:current_time('Y-m-d'); $t=strtotime($raw);
            if(!$t)return [[],0];
            $days=$this->working_days(); if(!$days)return [[],strtotime(date('Y-m-d',$t))];
            $anchor=$t;
            for($back=0;$back<7;$back++){
                if(in_array((int)date('w',$anchor),$days,true))break;
                $anchor=strtotime('-1 day',$anchor);
            }
            $first=$days[0];
            while((int)date('w',$anchor)!==$first)$anchor=strtotime('-1 day',$anchor);
            $dates=[];$cursor=$anchor;
            while(count($dates)<count($days)){
                if(in_array((int)date('w',$cursor),$days,true))$dates[]=date('Y-m-d',$cursor);
                $cursor=strtotime('+1 day',$cursor);
            }
            return [$dates,$anchor];
        }

    private function company_calendar_events($start,$end){
            global $wpdb;
            $table=$wpdb->prefix.'ews_company_calendar';
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE event_date BETWEEN %s AND %s AND event_type='general_leave' AND active=1 ORDER BY event_date ASC,id ASC",
                $start,$end
            ));
        }

    private function company_leave_dates($start,$end){
            $key=(string)$start.'|'.(string)$end;
            if(array_key_exists($key,self::$ews_company_leave_cache))return self::$ews_company_leave_cache[$key];
            $out=[];
            foreach($this->company_calendar_events($start,$end) as $event)$out[$event->event_date]=$event->title;
            self::$ews_company_leave_cache[$key]=$out;
            return $out;
        }
}
