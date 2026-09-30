<?php
if (!defined('ABSPATH')) exit;

trait EWS_Auto_Attendance_Trait {

    private function ensure_auto_attendance_schema(){
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
        if($rule->recurrence==='one_time') return (string)$rule->run_date===$date;
        $days=[];
        foreach(explode(',',(string)$rule->weekdays) as $d){if($d!==''&&is_numeric($d))$days[]=(int)$d;}
        return in_array((int)wp_date('w',strtotime($date)),$days,true);
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

    private function auto_attendance_event($employee_id,$date,$type,$target_time,$sch,$rule_id){
        global $wpdb;
        // One indexed attendance lookup is enough for both duplicate protection
        // and Sign Out eligibility.
        $events=[];
        foreach($wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s ORDER BY event_at ASC",$employee_id,$date)) as $r)$events[$r->event_type]=$r;
        if($type==='sign_in' && (isset($events['sign_in'])||isset($events['late_sign_in']))) return ['ok'=>false,'reason'=>'already_signed_in'];
        if($type==='sign_out'){
            if(!isset($events['sign_in'])&&!isset($events['late_sign_in'])) return ['ok'=>false,'reason'=>'no_sign_in'];
            if(isset($events['sign_out'])) return ['ok'=>false,'reason'=>'already_signed_out'];
            $sign_in=isset($events['sign_in'])?$events['sign_in']:$events['late_sign_in'];
            if(strtotime($sign_in->event_at)>strtotime($date.' '.$target_time)) return ['ok'=>false,'reason'=>'sign_in_after_target'];
        }
        $event_at=$date.' '.$target_time.':00';
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
        ],['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
        if($ok===false){$this->audit('auto_'.$type.'_failed','time_log',0,$emp->name.' / Rule #'.$rule_id.' / '.$wpdb->last_error);return ['ok'=>false,'reason'=>'db_error'];}
        $this->audit('auto_'.$type,'time_log',(int)$wpdb->insert_id,$emp->name.' / '.$sch->status.' / '.$event_at.' / source=Auto / rule='.$rule_id);
        return ['ok'=>true,'id'=>(int)$wpdb->insert_id];
    }

    private function process_auto_attendance(){
        global $wpdb;
        $table=$wpdb->prefix.'ews_auto_attendance_rules';
        $today=current_time('Y-m-d');
        $now=current_time('H:i');
        // Only load rules that can possibly be due today. Weekly rules are a small
        // active set; one-time rules are narrowed to today's date by the indexed query.
        $rules=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE enabled=1 AND ((recurrence='one_time' AND run_date=%s) OR recurrence='weekly') ORDER BY id ASC",$today));
        foreach($rules as $rule){
            if(!$this->auto_attendance_rule_applies($rule,$today)) continue;
            $sch=$this->auto_attendance_working_schedule((int)$rule->employee_id,$today);
            if(!$sch) continue;
            if($rule->sign_in_time && substr((string)$rule->sign_in_time,0,5)<=$now)
                $this->auto_attendance_event((int)$rule->employee_id,$today,'sign_in',substr((string)$rule->sign_in_time,0,5),$sch,(int)$rule->id);
            if($rule->sign_out_time && substr((string)$rule->sign_out_time,0,5)<=$now)
                $this->auto_attendance_event((int)$rule->employee_id,$today,'sign_out',substr((string)$rule->sign_out_time,0,5),$sch,(int)$rule->id);
            if($rule->recurrence==='one_time' && $rule->run_date===$today){
                // Keep a one-time rule enabled until every configured action has
                // reached its target time and has been handled. Previously this
                // was disabled after the first action (e.g. Sign In), which meant
                // a later Sign Out on the same rule could never run.
                $sign_in_done = empty($rule->sign_in_time);
                $sign_out_done = empty($rule->sign_out_time);
                if(!$sign_in_done && substr((string)$rule->sign_in_time,0,5)<=$now){
                    $sign_in_done = (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in') LIMIT 1",(int)$rule->employee_id,$today));
                }
                if(!$sign_out_done && substr((string)$rule->sign_out_time,0,5)<=$now){
                    $sign_out_done = (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type='sign_out' LIMIT 1",(int)$rule->employee_id,$today));
                }
                if($sign_in_done && $sign_out_done){
                    $wpdb->update($table,['enabled'=>0,'updated_at'=>current_time('mysql')],['id'=>(int)$rule->id],['%d','%s'],['%d']);
                }
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
        $run_date=sanitize_text_field($_POST['run_date']??'');
        $sign_in=sanitize_text_field($_POST['sign_in_time']??'');
        $sign_out=sanitize_text_field($_POST['sign_out_time']??'');
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
              AND s.event_type IN ('sign_in','late_sign_in')
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
            ],['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s']);
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

    public function admin_auto_attendance(){
        if(!$this->can('ews_manage_auto_attendance'))wp_die('Access denied');
        global $wpdb;$table=$wpdb->prefix.'ews_auto_attendance_rules';
        $edit_id=absint($_GET['edit_rule']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d",$edit_id)):null;
        $emps=array_values(array_filter($this->emps(),function($e){return !isset($e->attendance_enabled) || (int)$e->attendance_enabled===1;}));
        $rows=$wpdb->get_results("SELECT r.*,e.name,e.domain_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id ORDER BY r.enabled DESC,r.id DESC");
        echo '<div class="wrap"><h1>Auto Attendance</h1><p>Automatically record real Sign In / Sign Out events for selected employees. Leave days are skipped and recurring rules remain active.</p>';
        if(isset($_GET['saved']))echo '<div class="notice notice-success is-dismissible"><p>Auto Attendance Rule saved.</p></div>';
        if(isset($_GET['bulk_sign_out'])){
            $so=absint($_GET['bulk_sign_out']);$sk=absint($_GET['bulk_skipped']??0);$fl=absint($_GET['bulk_failed']??0);
            echo '<div class="notice notice-success is-dismissible"><p>Auto Sign Out completed for <strong>'.$so.'</strong> signed-in employee'.($so===1?'':'s').'.'.($sk?' '.$sk.' skipped.':'').($fl?' '.$fl.' failed.':'').'</p></div>';
        }
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;margin:18px 0;max-width:900px"><h2>'.($edit?'Edit Rule':'Add Rule').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_auto_attendance_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_auto_attendance_save"><input type="hidden" name="rule_id" value="'.(int)($edit->id??0).'">';
        echo '<table class="form-table"><tr><th>Employee</th><td><select name="employee_id" required><option value="">Select employee</option>';
        foreach($emps as $e)echo '<option value="'.(int)$e->id.'" '.selected((int)($edit->employee_id??0),(int)$e->id,false).'>'.esc_html($e->name.' ('.$e->domain_name.')').'</option>';
        echo '</select></td></tr><tr><th>Mode</th><td><label><input type="radio" name="recurrence" value="one_time" '.checked(($edit->recurrence??'one_time'),'one_time',false).'> One-time</label> &nbsp; <label><input type="radio" name="recurrence" value="weekly" '.checked(($edit->recurrence??''),'weekly',false).'> Recurring</label></td></tr>';
        echo '<tr><th>Date</th><td><input type="date" name="run_date" value="'.esc_attr($edit->run_date??'').'"> <span class="description">Used for one-time rules.</span></td></tr>';
        $selected=[];if($edit&&$edit->weekdays!=='')foreach(explode(',',$edit->weekdays) as $d)$selected[(int)$d]=1;else foreach($this->working_days() as $d)$selected[$d]=1;
        echo '<tr><th>Recurring days</th><td>'; $dn=[0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'];foreach($dn as $d=>$n)echo '<label style="display:inline-block;margin-right:12px"><input type="checkbox" name="weekdays[]" value="'.$d.'" '.checked(isset($selected[$d]),true,false).'> '.esc_html($n).'</label>';echo '<p class="description">Defaults to the configured working days (currently '.esc_html($this->auto_attendance_days_label($this->working_days())).').</p></td></tr>';
        echo '<tr><th>Auto Sign In</th><td><input type="time" name="sign_in_time" value="'.esc_attr($edit->sign_in_time??'').'"></td></tr><tr><th>Auto Sign Out</th><td><input type="time" name="sign_out_time" value="'.esc_attr($edit->sign_out_time??'').'"></td></tr><tr><th>Status</th><td><label><input type="checkbox" name="enabled" value="1" '.checked((int)($edit->enabled??1),1,false).'> Enabled</label></td></tr></table><p><button class="button button-primary">Save Rule</button> '.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-auto-attendance')).'">Cancel</a>':'').'</p></form></div>';
        $bulk_url=wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_bulk_sign_out'),'ews_auto_attendance_bulk_sign_out');
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:22px 0 10px"><h2 style="margin:0">Rules</h2><a class="button button-secondary" href="'.esc_url($bulk_url).'" onclick="return confirm(\'Auto Sign Out all employees who have signed in today and have not signed out yet?\');">Auto Sign Out All Signed-In</a></div><table class="widefat striped"><thead><tr><th>Employee</th><th>Mode</th><th>Days / Date</th><th>Sign In</th><th>Sign Out</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="7">No Auto Attendance Rules configured.</td></tr>';
        foreach($rows as $r){$when=$r->recurrence==='one_time'?$r->run_date:$this->auto_attendance_days_label(array_filter(array_map('absint',explode(',',(string)$r->weekdays)),function($v){return $v>=0&&$v<=6;}));$toggle=wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_toggle&rule_id='.(int)$r->id),'ews_auto_attendance_toggle_'.$r->id);$del=wp_nonce_url(admin_url('admin-post.php?action=ews_auto_attendance_delete&rule_id='.(int)$r->id),'ews_auto_attendance_delete_'.$r->id);echo '<tr><td><strong>'.esc_html($r->name).'</strong><br><small>'.esc_html($r->domain_name).'</small></td><td>'.esc_html($r->recurrence==='one_time'?'One-time':'Recurring').'</td><td>'.esc_html($when).'</td><td>'.esc_html($r->sign_in_time?:'—').'</td><td>'.esc_html($r->sign_out_time?:'—').'</td><td>'.($r->enabled?'<span style="color:#16803c;font-weight:600">Enabled</span>':'<span style="color:#777">Disabled</span>').'</td><td><a class="button button-small" href="'.esc_url(admin_url('admin.php?page=ews31-auto-attendance&edit_rule='.(int)$r->id)).'">Edit</a> <a class="button button-small" href="'.esc_url($toggle).'">'.($r->enabled?'Disable':'Enable').'</a> <a class="button button-small" href="'.esc_url($del).'" onclick="return confirm(\'Delete this Auto Attendance Rule?\');">Delete</a></td></tr>';}
        echo '</tbody></table><p class="description">The scheduler checks every five minutes. If it runs late, the attendance event keeps the configured rule time. Existing Sign In / Sign Out events are never duplicated.</p></div>';
    }
}
