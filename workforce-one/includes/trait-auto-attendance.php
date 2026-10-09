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

    private function auto_attendance_working_schedule($employee_id,$date){
        global $wpdb;
        $sch=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$employee_id,$date));
        if(!$sch) return null;
        $status=(string)$sch->status;
        if($status===''||$status==='Not Set'||$status==='Vacation'||$status==='Leave'||$status==='Absent') return null;
        if($this->company_leave_dates($date,$date)) return null;
        if(!$this->schedule_type_requires_sign_in($status)) return null;
        return $sch;
    }

    /**
     * Record one automatic Sign In / Sign Out on the attendance day $date, at $target_time on
     * $event_date (the next day for an overnight rule's Sign Out). Never duplicates an event.
     */
    private function auto_attendance_event($employee_id,$date,$type,$target_time,$sch,$rule_id,$event_date=null){
        $event_date=$event_date?:$date;
        global $wpdb;
        // One indexed attendance lookup is enough for both duplicate protection
        // and Sign Out eligibility.
        $events=[];
        foreach($wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s".$this->tl_live()." ORDER BY event_at ASC",$employee_id,$date)) as $r)$events[$r->event_type]=$r;
        if($type==='sign_in' && (isset($events['sign_in'])||isset($events['late_sign_in']))) return ['ok'=>false,'reason'=>'already_signed_in'];
        if($type==='sign_out'){
            if(!isset($events['sign_in'])&&!isset($events['late_sign_in'])) return ['ok'=>false,'reason'=>'no_sign_in'];
            if(isset($events['sign_out'])) return ['ok'=>false,'reason'=>'already_signed_out'];
            $sign_in=isset($events['sign_in'])?$events['sign_in']:$events['late_sign_in'];
            if(strtotime($sign_in->event_at)>strtotime($event_date.' '.$target_time)) return ['ok'=>false,'reason'=>'sign_in_after_target'];
        }
        $event_at=$event_date.' '.$target_time.':00';
        $now=current_time('mysql');
        $user_id=0;
        $emp=$wpdb->get_row($wpdb->prepare("SELECT name,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
        if(!$emp)return ['ok'=>false,'reason'=>'employee_missing'];
        if(!$this->employee_attendance_enabled($employee_id))return ['ok'=>false,'reason'=>'attendance_disabled'];
        if($emp->wp_user_id)$user_id=(int)$emp->wp_user_id;
        $ok=$wpdb->insert($this->time_logs,[
            'employee_id'=>(int)$employee_id,
            'user_id'=>$user_id,
            'work_date'=>$date,
            'event_type'=>$type,
            'event_at'=>$event_at,
            'scheduled_status'=>$sch->status,
            'ip_address'=>'',
            'latitude'=>null,
            'longitude'=>null,
            'accuracy'=>null,
            'location_status'=>'auto',
            'distance_meters'=>null,
            'created_at'=>$now,
            'source'=>'auto',
        ],['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
        if($ok===false){$this->audit('auto_'.$type.'_failed','time_log',0,$emp->name.' / Rule #'.$rule_id.' / '.$wpdb->last_error);return ['ok'=>false,'reason'=>'db_error'];}
        $this->audit('auto_'.$type,'time_log',(int)$wpdb->insert_id,$emp->name.' / '.$sch->status.' / '.$event_at.' / source=Auto / rule='.$rule_id);
        return ['ok'=>true,'id'=>(int)$wpdb->insert_id];
    }

    /** The five-minute cron: record what each enabled rule has due (src/Attendance/AutoRules.php). */
    private function process_auto_attendance(){
        global $wpdb;
        $table=$wpdb->prefix.'ews_auto_attendance_rules';
        $today=current_time('Y-m-d');
        $yesterday=date('Y-m-d',strtotime($today.' 12:00:00 -1 day'));
        $now=current_time('H:i');
        // Only rules that can be due: weekly ones, and one-time rules of today or (overnight) yesterday.
        $rules=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE enabled=1 AND ((recurrence='one_time' AND run_date IN (%s,%s)) OR recurrence='weekly') ORDER BY id ASC",$today,$yesterday));
        foreach($rules as $rule){
            $eid=(int)$rule->employee_id;
            foreach(AutoRules::due((array)$rule,$today,$yesterday,$now) as $a){
                $sch=$this->auto_attendance_working_schedule($eid,$a['work_date']);
                if($sch)$this->auto_attendance_event($eid,$a['work_date'],$a['type'],$a['time'],$sch,(int)$rule->id,$a['event_date']);
            }
            if($rule->recurrence!=='one_time')continue;
            // A one-time rule stays enabled until each of its actions has reached its time and been
            // handled (an overnight Sign Out is due the next day).
            $run=(string)$rule->run_date;
            $done=function($type,$time,$when)use($wpdb,$eid,$run,$today,$now){
                if(empty($time))return true;
                if($when>$today||($when===$today&&substr((string)$time,0,5)>$now))return false;
                $types=$type==='sign_in'?"'sign_in','late_sign_in'":"'sign_out'";
                return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ($types) LIMIT 1",$eid,$run));
            };
            $out_day=AutoRules::signOutDate($run,$rule->sign_in_time,$rule->sign_out_time);
            $missed=!$this->auto_attendance_working_schedule($eid,$run); // nothing to record on a day off / leave
            if($missed||($done('sign_in',$rule->sign_in_time,$run)&&$done('sign_out',$rule->sign_out_time,$out_day))){
                if($missed&&($run>=$today||$out_day>=$today))continue; // keep it until its day has passed
                $wpdb->update($table,['enabled'=>0,'updated_at'=>current_time('mysql')],['id'=>(int)$rule->id],['%d','%s'],['%d']);
            }
        }
    }

    public function auto_attendance_save(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        check_admin_referer('ews_auto_attendance_save');
        global $wpdb;$table=$wpdb->prefix.'ews_auto_attendance_rules';
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
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$employee_id));
        if(!$exists)wp_die('Invalid active employee.');
        if(!$this->employee_attendance_enabled($employee_id))wp_die('Attendance tracking is disabled for this employee.');
        $data=['employee_id'=>$employee_id,'recurrence'=>$recurrence,'run_date'=>$run_date,'weekdays'=>$weekdays,'sign_in_time'=>$sign_in?:null,'sign_out_time'=>$sign_out?:null,'enabled'=>!empty($_POST['enabled'])?1:0,'updated_at'=>current_time('mysql')];
        if($id){$ok=$wpdb->update($table,$data,['id'=>$id]);$action='auto_attendance_rule_update';}else{$data['created_by']=get_current_user_id();$data['created_at']=current_time('mysql');$ok=$wpdb->insert($table,$data);$id=(int)$wpdb->insert_id;$action='auto_attendance_rule_create';}
        if($ok===false)wp_die('Unable to save Auto Attendance Rule. '.esc_html($wpdb->last_error));
        $this->audit($action,'auto_attendance_rule',(int)$id,'Employee #'.$employee_id.' / '.$recurrence.' / Sign In '.($sign_in?:'—').' / Sign Out '.($sign_out?:'—'));
        $this->auto_attendance_schedule();
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance&saved=1'));exit;
    }

    public function auto_attendance_toggle(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        $id=absint($_GET['rule_id']??0);check_admin_referer('ews_auto_attendance_toggle_'.$id);
        global $wpdb;$table=$wpdb->prefix.'ews_auto_attendance_rules';
        $row=$wpdb->get_row($wpdb->prepare("SELECT enabled FROM {$table} WHERE id=%d",$id));
        if(!$row)wp_die('Rule not found.');
        $new=empty($row->enabled)?1:0;$wpdb->update($table,['enabled'=>$new,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);
        $this->audit('auto_attendance_rule_toggle','auto_attendance_rule',$id,'Enabled='.($new?'yes':'no'));
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance'));exit;
    }

    public function auto_attendance_delete(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        $id=absint($_GET['rule_id']??0);check_admin_referer('ews_auto_attendance_delete_'.$id);
        global $wpdb;$table=$wpdb->prefix.'ews_auto_attendance_rules';
        $ok=$wpdb->delete($table,['id'=>$id],['%d']);
        if($ok)$this->audit('auto_attendance_rule_delete','auto_attendance_rule',$id,'Rule deleted');
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance'));exit;
    }

    public function auto_attendance_bulk_sign_out(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        check_admin_referer('ews_auto_attendance_bulk_sign_out');
        global $wpdb;
        $date=current_time('Y-m-d');
        $now=current_time('mysql');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT s.employee_id, MAX(s.id) AS sign_in_id
            FROM {$this->time_logs} s
            WHERE s.work_date=%s
              AND s.event_type IN ('sign_in','late_sign_in')".$this->tl_live('s.id')."
              AND NOT EXISTS (
                  SELECT 1 FROM {$this->time_logs} o
                  WHERE o.employee_id=s.employee_id AND o.work_date=s.work_date AND o.event_type='sign_out'
              )
            GROUP BY s.employee_id",$date));
        $signed_out=0; $skipped=0; $failed=0;
        foreach($rows as $row){
            $sign_in=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE id=%d LIMIT 1",(int)$row->sign_in_id));
            if(!$sign_in){$failed++;continue;}
            if(!$this->employee_attendance_enabled((int)$sign_in->employee_id)){$skipped++;continue;}
            $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type='sign_out' LIMIT 1",(int)$row->employee_id,$date));
            if($existing){$skipped++;continue;}
            $ok=$wpdb->insert($this->time_logs,[
                'employee_id'=>(int)$sign_in->employee_id,
                'user_id'=>(int)$sign_in->user_id,
                'work_date'=>$date,
                'event_type'=>'sign_out',
                'event_at'=>$now,
                'scheduled_status'=>(string)$sign_in->scheduled_status,
                'ip_address'=>'',
                'latitude'=>null,
                'longitude'=>null,
                'accuracy'=>null,
                'location_status'=>'auto',
                'distance_meters'=>null,
                'created_at'=>$now,
                'source'=>'auto',
            ],['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
            if($ok===false){$failed++;continue;}
            $signed_out++;
            $emp=$wpdb->get_row($wpdb->prepare("SELECT name FROM {$this->employees} WHERE id=%d LIMIT 1",(int)$sign_in->employee_id));
            $this->audit('auto_sign_out','time_log',(int)$wpdb->insert_id,($emp?$emp->name:('Employee #'.(int)$sign_in->employee_id)).' / '.$sign_in->scheduled_status.' / '.$now.' / source=Auto / bulk=1');

            // If an employee is still on an open break, close it at the bulk sign-out
            // time so the break state cannot remain open after attendance is closed.
            if($this->break_enabled()){
                $bt=$wpdb->prefix.'ews_break_sessions';
                $open=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND work_date=%s AND status='Open' ORDER BY id DESC LIMIT 1",(int)$sign_in->employee_id,$date));
                if($open){
                    $minutes=max(0,(int)floor((strtotime($now)-strtotime($open->start_at))/60));
                    $wpdb->update($bt,['end_at'=>$now,'actual_minutes'=>$minutes,'status'=>'Completed'],['id'=>(int)$open->id],['%s','%d','%s'],['%d']);
                    wp_clear_scheduled_hook('ews_break_duration_reminder',[(int)$open->id]);
                    wp_clear_scheduled_hook('ews_break_manager_escalation',[(int)$open->id]);
                    $this->audit('break_auto_closed','break',(int)$open->id,($emp?$emp->name:('Employee #'.(int)$sign_in->employee_id)).' / '.$now.' / '.$minutes.'m / bulk_sign_out');
                }
            }
        }
        $this->audit('auto_sign_out_bulk','time_log',0,'Date='.$date.' / signed_out='.$signed_out.' / skipped='.$skipped.' / failed='.$failed);
        wp_safe_redirect(admin_url('admin.php?page=ews31-auto-attendance&bulk_sign_out='.$signed_out.'&bulk_skipped='.$skipped.'&bulk_failed='.$failed));
        exit;
    }

    /** wp-admin → Auto Attendance: add / edit rules, switch them on or off, bulk Sign Out. */
    public function admin_auto_attendance(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        global $wpdb;$table=$wpdb->prefix.'ews_auto_attendance_rules';
        $edit_id=absint($_GET['edit_rule']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$edit_id)):null;
        $rows=(array)$wpdb->get_results("SELECT r.*,e.name,e.domain_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id ORDER BY r.enabled DESC,r.id DESC");
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
