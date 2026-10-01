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
        echo $this->render_template('admin/schedule-config',[
            'types'=>$types,
            'active_types'=>count(array_filter($types,function($t){return !empty($t['active']);})),
            'events'=>$events,'delete_urls'=>$delete_urls,
            'hours'=>$this->working_hours(),
            'grace_period'=>ConfigRules::graceMinutes(get_option('ews_grace_period',10)),
            'overnight_enabled'=>(bool)(int)get_option('ews_allow_overnight_shift',0),
            'shifts'=>$this->shifts(),
            'working_days'=>$this->working_days(),
            'day_names'=>$this->working_day_names(),
            'notice'=>ConfigRules::noticeMessage(sanitize_key($_GET['schedule_notice']??'')),
            'error'=>isset($_GET['schedule_error'])?ConfigRules::errorMessage(sanitize_key($_GET['schedule_error'])):null,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function working_hours_save_handler(){
        $this->schedule_config_guard('ews_working_hours_save');
        $start=sanitize_text_field($_POST['work_start']??'');
        $end=sanitize_text_field($_POST['work_end']??'');
        $overnight=!empty($_POST['allow_overnight_shift']);
        // Validate first so a refused change saves nothing.
        if($error=ConfigRules::hoursError($start,$end,$overnight))$this->schedule_config_redirect(['schedule_error'=>$error]);
        update_option('ews_allow_overnight_shift',$overnight?1:0,false);
        update_option('ews_grace_period',ConfigRules::graceMinutes($_POST['grace_period']??10),false);
        $result=$this->working_hours_save($start,$end,$end);
        if(is_wp_error($result))$this->schedule_config_redirect(['schedule_error'=>'hours_invalid']);
        $this->invalidate_working_hours_runtime_cache();
        $this->audit('working_hours_update','settings',0,$start.' - '.$end);
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
        $stored=array_map(function($s){return (int)$s['id'];},$this->shifts());
        $highest=max(array_merge([(int)get_option('ews_shifts_highest_id',0)],$stored));
        [$clean,$error]=ConfigRules::shifts(wp_unslash($_POST['shifts']??[]),wp_unslash($_POST['new_shift']??[]),$highest);
        if($error)$this->schedule_config_redirect(['schedule_error'=>$error]);
        foreach($clean as &$shift)$shift['name']=sanitize_text_field($shift['name']);
        unset($shift);
        update_option('ews_shifts',$clean,false);
        update_option('ews_shifts_highest_id',max(array_merge([$highest],array_column($clean,'id'))),false);
        $this->invalidate_schedule_runtime_cache();
        $this->audit('shifts_update','settings',0,'Shifts updated');
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
