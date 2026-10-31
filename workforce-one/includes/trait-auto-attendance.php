<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\AutoRules;

/**
 * Auto Attendance: rules that record Sign In / Sign Out for an employee at set times (one day or every
 * week; overnight when the Sign Out is earlier than the Sign In), the five-minute cron that runs them,
 * and the bulk Sign Out. Rules: src/Attendance/AutoRules.php; page: templates/admin/auto-attendance.php.
 */

trait EWS_Auto_Attendance_Trait {

    private function ensure_auto_attendance_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $t=$wpdb->prefix.'ews_auto_attendance_rules';
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            recurrence VARCHAR(20) NOT NULL DEFAULT 'one_time',
            run_date DATE NULL,
            weekdays VARCHAR(30) NULL,
            sign_in_time TIME NULL,
            sign_out_time TIME NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY(id),
            KEY employee_enabled(employee_id,enabled),
            KEY due_rules(enabled,recurrence,run_date),
            KEY run_date(run_date),
            KEY recurrence(recurrence)
        ) {$c};");
    }

    private function auto_attendance_schedule(){
        if(!wp_next_scheduled('ews_auto_attendance_tick'))
            wp_schedule_event(time()+300,'ews_auto_five_minutes','ews_auto_attendance_tick');
    }

    public function auto_attendance_cron(){
        $this->process_auto_attendance();
    }

    private function auto_attendance_days_label($days){
        $names=[0=>'Sun',1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat'];
        $out=[];
        foreach((array)$days as $d){$d=(int)$d;if(isset($names[$d]))$out[]=$names[$d];}
        return implode(', ',$out);
    }

    private function auto_attendance_rule_applies($rule,$date){
        return AutoRules::applies((string)$rule->recurrence,$rule->run_date,(string)$rule->weekdays,(string)$date);
    }

    /**
     * Record one automatic Sign In / Sign Out on the attendance day $date, at $target_time on
     * $event_date (the next day for an overnight rule's Sign Out). Never duplicates an event.
     * Under the same per-employee lock as a Sign In / Out from the app (AutoAttendanceService, 3.31.138).
     */
    private function auto_attendance_event($employee_id,$date,$type,$target_time,$sch,$rule_id,$event_date=null){
        return $this->services()->autoAttendance()->event((int)$employee_id,(string)$date,(string)$type,(string)$target_time,$sch,(int)$rule_id,$event_date===null?null:(string)$event_date);
    }

    /** The five-minute cron: record what each enabled rule has due (src/Attendance/AutoRules.php). */
    private function process_auto_attendance(){
        $today=current_time('Y-m-d');
        $yesterday=date('Y-m-d',strtotime($today.' 12:00:00 -1 day'));
        $now=current_time('H:i');
        $this->services()->autoAttendance()->tick($today,$yesterday,$now);
    }

    public function auto_attendance_save(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        check_admin_referer('ews_auto_attendance_save');
        $id=absint($_POST['rule_id']??0);
        $employee_id=absint($_POST['employee_id']??0);
        $recurrence=sanitize_key($_POST['recurrence']??'one_time');
        $run_date=sanitize_text_field(wp_unslash($_POST['run_date']??''));
        $sign_in=sanitize_text_field(wp_unslash($_POST['sign_in_time']??''));
        $sign_out=sanitize_text_field(wp_unslash($_POST['sign_out_time']??''));
        $valid_time=function($v){return $v===''||preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$v);};
        if(!$employee_id || !in_array($recurrence,['one_time','weekly'],true) || !$valid_time($sign_in) || !$valid_time($sign_out) || ($sign_in===''&&$sign_out==='')) wp_die('Please provide a valid employee and at least one valid Auto Sign In / Auto Sign Out time.');
        if($recurrence==='one_time'){
            if(!$this->valid_date($run_date))wp_die('Please provide a valid date.');
            $weekdays='';
        }else{
            $run_date=null;$raw=$_POST['weekdays']??[];$days=[];
            if(is_array($raw))foreach($raw as $d){$d=absint($d);if($d>=0&&$d<=6)$days[$d]=$d;}
            if(!$days) $days=$this->working_days();
            sort($days,SORT_NUMERIC);$weekdays=implode(',',$days);
        }
        $exists=$this->people_active_id($employee_id);
        if(!$exists)wp_die('Invalid active employee.');
        if(!$this->employee_attendance_enabled($employee_id))wp_die('Attendance tracking is disabled for this employee.');
        $data=['employee_id'=>$employee_id,'recurrence'=>$recurrence,'run_date'=>$run_date,'weekdays'=>$weekdays,'sign_in_time'=>$sign_in?:null,'sign_out_time'=>$sign_out?:null,'enabled'=>!empty($_POST['enabled'])?1:0,'updated_at'=>current_time('mysql')];
        $create=$id?null:['created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')];
        $r=$this->services()->autoAttendance()->saveRule($id,$data,$create,'Employee #'.$employee_id.' / '.$recurrence.' / Sign In '.($sign_in?:'—').' / Sign Out '.($sign_out?:'—'));
        if(!$r['ok'])wp_die('Unable to save Auto Attendance Rule. '.esc_html($r['error']));
        $this->auto_attendance_schedule();
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance&saved=1'));exit;
    }

    public function auto_attendance_toggle(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        $id=absint($_GET['rule_id']??0);check_admin_referer('ews_auto_attendance_toggle_'.$id);
        if($this->services()->autoAttendance()->toggleRule($id)===null)wp_die('Rule not found.');
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance'));exit;
    }

    public function auto_attendance_delete(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        $id=absint($_GET['rule_id']??0);check_admin_referer('ews_auto_attendance_delete_'.$id);
        $this->services()->autoAttendance()->deleteRule($id);
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance'));exit;
    }

    public function auto_attendance_bulk_sign_out(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        check_admin_referer('ews_auto_attendance_bulk_sign_out');
        $date=current_time('Y-m-d');
        $now=current_time('mysql');
        $r=$this->services()->autoAttendance()->bulkSignOut($date,$now);
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance&bulk_sign_out='.$r['signed_out'].'&bulk_skipped='.$r['skipped'].'&bulk_failed='.$r['failed']));
        exit;
    }

    /** wp-admin → Auto Attendance: add / edit rules, switch them on or off, bulk Sign Out. */
    public function admin_auto_attendance(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        $edit_id=absint($_GET['edit_rule']??0);
        $edit=$edit_id?$this->services()->autoRuleStore()->find($edit_id):null;
        $rows=$this->services()->autoRuleQuery()->listWithNames();
        foreach($rows as $r){
            $r->when=$r->recurrence==='one_time'?(string)$r->run_date:$this->auto_attendance_days_label(array_filter(array_map('absint',explode(',',(string)$r->weekdays)),function($v){return $v>=0&&$v<=6;}));
            $r->overnight=AutoRules::overnight($r->sign_in_time,$r->sign_out_time);
            $r->toggle_url=wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_toggle&rule_id='.(int)$r->id),'ews_auto_attendance_toggle_'.$r->id);
            $r->delete_url=wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_delete&rule_id='.(int)$r->id),'ews_auto_attendance_delete_'.$r->id);
        }
        $selected=[];
        if($edit&&(string)$edit->weekdays!=='')foreach(explode(',',$edit->weekdays) as $d)$selected[(int)$d]=true;else foreach($this->working_days() as $d)$selected[(int)$d]=true;
        $bulk=null;
        if(isset($_GET['bulk_sign_out']))$bulk=['done'=>absint($_GET['bulk_sign_out']),'skipped'=>absint($_GET['bulk_skipped']??0),'failed'=>absint($_GET['bulk_failed']??0)];
        echo $this->render_template('admin/auto-attendance',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'edit'=>$edit,'rows'=>$rows,'selected_days'=>$selected,'saved'=>isset($_GET['saved']),'bulk'=>$bulk,
            'emps'=>array_values(array_filter($this->emps(),function($e){return !isset($e->attendance_enabled) || (int)$e->attendance_enabled===1;})),
            'working_days_label'=>$this->auto_attendance_days_label($this->working_days()),
            'bulk_url'=>wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_bulk_sign_out'),'ews_auto_attendance_bulk_sign_out'),
        ]);
    }
}
