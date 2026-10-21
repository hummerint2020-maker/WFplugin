<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\ConfigRules;

/**
 * wp-admin "Schedule Configuration": working hours, working days, shifts, schedule types and
 * company holidays (General Leave). Option and table names are unchanged.
 * Rules: src/Schedule/ConfigRules.php. Behaviour: tests/e2e_schedule_config.py.
 */
trait EWS_Schedule_Config_Trait {

    private function schedule_config_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-schedule-config')));
        exit;
    }

    private function schedule_config_guard($nonce){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer($nonce);
    }

    public function admin_schedule_config(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $types=$this->schedule_types_config(false);
        $events=(array)$this->company_calendar_events(current_time('Y-m-d'),'2099-12-31');
        $delete_urls=[];
        foreach($events as $event)$delete_urls[(int)$event->id]=wp_nonce_url(add_query_arg(['action'=>'ews31_general_leave_delete','id'=>(int)$event->id],admin_url('admin-post.php')),'ews_general_leave_delete_'.(int)$event->id);
        // The company hours of today, all four from one source (3.31.116): a change saved for a later day is in the options already, and
        // the form showed today's start and end with that later grace and overnight; saving it unchanged then undid or refused that change.
        $company=$this->shift_times_on(0,current_time('Y-m-d'))?:$this->company_hours_now();
        echo $this->render_template('admin/schedule-config',[
            'types'=>$types,
            'active_types'=>count(array_filter($types,function($t){return !empty($t['active']);})),
            'events'=>$events,'delete_urls'=>$delete_urls,
            'hours'=>['start'=>(string)$company['start'],'end'=>(string)$company['end']],
            'grace_period'=>ConfigRules::graceMinutes($company['grace']),
            'absent_after'=>ConfigRules::absentAfterMinutes($this->option('ews_absent_after_minutes')),
            'overnight_enabled'=>!empty($company['overnight']),
            'shifts'=>$this->shifts(),'versions'=>$this->shift_versions_all(),'today'=>current_time('Y-m-d'),'can_past'=>$this->can('ews_manage_settings'),
            'working_days'=>$this->working_days(),
            'day_names'=>$this->working_day_names(),
            'notice'=>ConfigRules::noticeMessage(sanitize_key($_GET['schedule_notice']??'')),
            'error'=>isset($_GET['schedule_error'])?ConfigRules::errorMessage(sanitize_key($_GET['schedule_error'])):null,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function working_hours_save_handler(){
        $this->schedule_config_guard('ews_working_hours_save');
        $start=sanitize_text_field(wp_unslash($_POST['work_start']??''));
        $end=sanitize_text_field(wp_unslash($_POST['work_end']??''));
        $overnight=!empty($_POST['allow_overnight_shift']);
        $grace=ConfigRules::graceMinutes($_POST['grace_period']??10);
        $absent=isset($_POST['absent_after'])?ConfigRules::absentAfterMinutes($_POST['absent_after']):null;
        // Every check first, so a refused change saves nothing (3.31.116: grace and overnight were saved before the day was checked).
        if($error=ConfigRules::hoursError($start,$end,$overnight))$this->schedule_config_redirect(['schedule_error'=>$error]);
        // The new times count from a day (today by default); the days before keep the old ones (3.31.108).
        $from=$this->shift_times_from();
        if($from===null)$this->schedule_config_redirect(['schedule_error'=>'times_from']);
        // Read before any write: what the days before $from count with now.
        $live=$this->company_hours_now();
        $before=$this->shift_times_on(0,$from)?:$live;
        $after=['start'=>$start,'end'=>$end,'grace'=>$grace,'sign_in_cutoff_minutes'=>240,'overnight'=>$overnight?1:0];
        $changed=\WorkforceOne\Schedule\ShiftPeriods::timesChanged($before,$after);
        // New times from a day before a change already saved for later would overwrite it (owner decision 2026-10-21: refused).
        if($changed && $this->shift_version_later(0,$from)!==null)$this->schedule_config_redirect(['schedule_error'=>'times_later']);
        // The dated versions first, then the options: if an option write fails, nothing counts differently. Unchanged times are not
        // written: the options may hold a change saved for a later day, and writing today's times there would undo it.
        if($changed){
            $this->shift_version_settle(0,$from,$live);
            $this->shift_version_insert(0,$from,$after);
            $result=$this->working_hours_save($start,$end,$end);
            if(is_wp_error($result))$this->schedule_config_redirect(['schedule_error'=>'hours_invalid']);
            update_option('ews_allow_overnight_shift',$overnight?1:0,false);
            update_option('ews_grace_period',$grace,false);
        }
        if($absent!==null)update_option('ews_absent_after_minutes',$absent,false);
        $this->invalidate_working_hours_runtime_cache();
        $this->audit('working_hours_update','settings',0,$start.' - '.$end.' · grace '.$grace.' · overnight '.($overnight?'on':'off').($changed?' · from '.$from:''));
        $this->schedule_config_redirect(['schedule_notice'=>'hours_saved']);
    }

    public function working_days_save_handler(){
        $this->schedule_config_guard('ews_working_days_save');
        $days=ConfigRules::workingDays($_POST['working_days']??[]);
        if(!$days)$this->schedule_config_redirect(['schedule_error'=>'days_empty']);
        update_option('ews_working_days',$days,false);
        $this->audit('working_days_update','settings',0,implode(',',$days));
        $this->schedule_config_redirect(['schedule_notice'=>'days_saved']);
    }

    public function shifts_save_handler(){
        $this->schedule_config_guard('ews_shifts_save');
        $from=$this->shift_times_from();
        if($from===null)$this->schedule_config_redirect(['schedule_error'=>'times_from']);
        $before=$this->shifts();
        $stored=array_map(function($s){return (int)$s['id'];},$before);
        $highest=max(array_merge([(int)$this->option('ews_shifts_highest_id')],$stored));
        [$clean,$error]=ConfigRules::shifts(wp_unslash($_POST['shifts']??[]),wp_unslash($_POST['new_shift']??[]),$highest);
        if($error)$this->schedule_config_redirect(['schedule_error'=>$error]);
        foreach($clean as &$shift)$shift['name']=sanitize_text_field($shift['name']);
        unset($shift);
        // Each shift as saved (the option, which may hold a change saved for a later day) and as it counts on $from (3.31.116).
        $live=[];$on=[];
        foreach($clean as $c){$id=(int)$c['id'];$v=$this->shift_saved($id);if($v){$live[$id]=$v;$on[$id]=$this->shift_times_on($id,$from)?:$v;}}
        $times=array_flip(['start','end','grace','sign_in_cutoff_minutes','overnight']);
        foreach($clean as &$c){
            $id=(int)$c['id'];
            if(!isset($on[$id]))continue;   // a new shift
            // Unchanged: the option keeps its times (the form shows today's; writing them would undo a change saved for a later day).
            if(!\WorkforceOne\Schedule\ShiftPeriods::timesChanged($on[$id],$c)){$c=array_merge($c,array_intersect_key($live[$id],$times));continue;}
            // New times from a day before a change already saved for later would overwrite it (owner decision 2026-10-21: refused).
            if($this->shift_version_later($id,$from)!==null)$this->schedule_config_redirect(['schedule_error'=>'times_later']);
        }
        unset($c);
        update_option('ews_shifts',$clean,false);
        // Shifts whose times changed get them from $from; the days before keep the old ones (3.31.108).
        $changed=$this->shift_versions_record($live,$on,$clean,$from);
        update_option('ews_shifts_highest_id',max(array_merge([$highest],array_column($clean,'id'))),false);
        $this->invalidate_schedule_runtime_cache();
        $this->audit('shifts_update','settings',0,'Shifts updated'.($changed?' · new times for '.$changed.' shift(s) from '.$from:''));
        $this->schedule_config_redirect(['schedule_notice'=>'shifts_saved']);
    }

    public function schedule_config_save(){
        $this->schedule_config_guard('ews_schedule_config_save');
        global $wpdb;
        $used=array_map('strval',(array)$wpdb->get_col("SELECT DISTINCT status FROM {$this->schedule}"));
        $posted=wp_unslash($_POST['types']??[]);
        if(is_array($posted))foreach($posted as $k=>$row)if(is_array($row))foreach(['name','icon'] as $f)if(isset($row[$f]))$posted[$k][$f]=sanitize_text_field($row[$f]);
        [$clean,$error]=ConfigRules::types($posted,array_values($this->schedule_types_config(false)),$used);
        if($error)$this->schedule_config_redirect(['schedule_error'=>$error]);
        update_option('ews_schedule_types_config',$clean,false);
        $this->invalidate_schedule_runtime_cache();
        $this->audit('schedule_config_update','settings',0,'Schedule Types updated');
        $this->schedule_config_redirect(['schedule_notice'=>'types_saved']);
    }

    public function general_leave_save(){
        $this->schedule_config_guard('ews_general_leave_save');
        $date=$this->normalize_date($_POST['event_date']??'');
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
        if(!$date||$title==='')$this->schedule_config_redirect(['schedule_error'=>'holiday_invalid']);
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->company_calendar} WHERE event_date=%s AND active=1 LIMIT 1",$date)))$this->schedule_config_redirect(['schedule_error'=>'holiday_duplicate']);
        $ok=$wpdb->insert($this->company_calendar,['event_date'=>$date,'title'=>$title,'event_type'=>'general_leave','active'=>1,'created_by'=>get_current_user_id()],['%s','%s','%s','%d','%d']);
        if($ok===false)wp_die('Could not save General Leave: '.esc_html($wpdb->last_error));
        $this->invalidate_company_leave_runtime_cache();
        $this->audit('general_leave_add','company_calendar',(int)$wpdb->insert_id,$date.' => '.$title);
        $this->schedule_config_redirect(['schedule_notice'=>'holiday_added']);
    }

    public function general_leave_delete(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $id=absint($_GET['id']??0);
        check_admin_referer('ews_general_leave_delete_'.$id);
        global $wpdb;
        $ok=$wpdb->delete($this->company_calendar,['id'=>$id],['%d']);
        if($ok===false)wp_die('Could not remove General Leave.');
        $this->invalidate_company_leave_runtime_cache();
        if($ok)$this->audit('general_leave_delete','company_calendar',$id,'Removed General Leave');
        $this->schedule_config_redirect(['schedule_notice'=>'holiday_removed']);
    }
}
