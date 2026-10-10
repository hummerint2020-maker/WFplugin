<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\CorrectionRules;
use WorkforceOne\Settings\CorrectionSettings;
use WorkforceOne\Support\Download;
use WorkforceOne\Requests\Hub;

/**
 * Attendance corrections (3.31.74, Feature Configuration → Attendance Corrections).
 *
 * An employee who forgot to sign in or out, or whose time is wrong, asks for a correction; the
 * manager decides with the day's evidence in front of them; HR can correct directly. A correction
 * never changes or deletes a recorded event: an approved one adds its own ews_time_logs rows
 * (source 'correction', correction_id), and a wrong time adds a row whose corrects_id names the
 * original, which every reader then skips (tl_live()). Rules: src/Attendance/CorrectionRules.php;
 * settings: src/Settings/CorrectionSettings.php. Behaviour: tests/e2e_corrections.py.
 *
 * Statuses: pending (the manager / approval workflow 'attendance_correction'), pending_hr (the second
 * level: a Sign In moved earlier or a request above the monthly limit), approved, rejected.
 */
trait EWS_Corrections_Trait {

    private function corrections_enabled(){ return (bool)$this->option('ews_feature_corrections'); }

    private function correction_settings(){ return CorrectionSettings::config(get_option('ews_correction_settings',[])); }

    private function cx_table(){ global $wpdb; return $wpdb->prefix.'ews_attendance_corrections'; }

    /**
     * SQL to add to a WHERE on ews_time_logs: only events no correction has replaced.
     * @param string $col the event id column of the outer query (e.g. "l.id"); default: the table's own
     */
    private function tl_live($col=''){
        $col=$col!==''?$col:$this->time_logs.'.id';
        return " AND NOT EXISTS (SELECT 1 FROM {$this->time_logs} tl_cx WHERE tl_cx.corrects_id={$col})";
    }

    private function ensure_corrections_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->cx_table()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            type VARCHAR(10) NOT NULL,
            target VARCHAR(10) NULL,
            sign_in_at DATETIME NULL,
            sign_out_at DATETIME NULL,
            old_at DATETIME NULL,
            reason TEXT NULL,
            photo_key VARCHAR(64) NULL,
            photo_name VARCHAR(190) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            source VARCHAR(10) NOT NULL DEFAULT 'employee',
            needs_hr TINYINT(1) NOT NULL DEFAULT 0,
            above_limit TINYINT(1) NOT NULL DEFAULT 0,
            earlier TINYINT(1) NOT NULL DEFAULT 0,
            requested_by BIGINT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            manager_by BIGINT UNSIGNED NULL,
            manager_at DATETIME NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            decision_note TEXT NULL,
            applied_ids VARCHAR(64) NULL,
            payroll_note VARCHAR(190) NULL,
            PRIMARY KEY  (id),
            KEY employee_date (employee_id,work_date),
            KEY status (status),
            KEY work_date (work_date)
        ) $c;");
        // ews_time_logs: where an event came from, and which correction added it / which event it replaces.
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->time_logs))===$this->time_logs){
            $have=(array)$wpdb->get_col("SHOW COLUMNS FROM {$this->time_logs}",0);
            foreach(['source'=>'VARCHAR(20) NULL','corrects_id'=>'BIGINT UNSIGNED NULL','correction_id'=>'BIGINT UNSIGNED NULL'] as $col=>$def){
                if(!in_array($col,$have,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN {$col} {$def}");
            }
            if(!$wpdb->get_var("SHOW INDEX FROM {$this->time_logs} WHERE Key_name='corrects_id'"))$wpdb->query("ALTER TABLE {$this->time_logs} ADD KEY corrects_id (corrects_id)");
        }
    }

    /* ------------------------------------------------------------------ the day and the facts */

    /** The day's live events: first Sign In and last Sign Out rows (or null). @return array{in:?object,out:?object} */
    private function cx_day($employee_id,$date){
        global $wpdb;
        $in=null;$out=null;
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out')".$this->tl_live()." ORDER BY event_at ASC,id ASC",(int)$employee_id,$date)) as $r){
            if($r->event_type==='sign_out')$out=$r;
            elseif(!$in)$in=$r;
        }
        return ['in'=>$in,'out'=>$out];
    }

    /** Requests this employee made for days of this month that count towards the limit. */
    private function cx_month_count($employee_id,$date){
        global $wpdb;
        $month=substr((string)$date,0,7);
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->cx_table()} WHERE employee_id=%d AND source='employee' AND status IN ('pending','pending_hr','approved') AND work_date BETWEEN %s AND %s",(int)$employee_id,$month.'-01',$month.'-31'));
    }

    private function cx_pending_for_day($employee_id,$date){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->cx_table()} WHERE employee_id=%d AND work_date=%s AND status IN ('pending','pending_hr') ORDER BY id DESC LIMIT 1",(int)$employee_id,$date));
    }

    /**
     * Facts for CorrectionRules from a request.
     * @param array<string,mixed> $in date, type, target, time_in, time_out, reason
     */
    private function cx_facts($emp,array $in,$source,$has_photo=false){
        $date=(string)($in['date']??'');
        $valid=CorrectionRules::isDate($date);
        $day=$valid?$this->cx_day((int)$emp->id,$date):['in'=>null,'out'=>null];
        $h=$this->working_hours((int)$emp->id);
        return [
            'type'=>(string)($in['type']??''),'target'=>(string)($in['target']??''),'date'=>$date,'today'=>current_time('Y-m-d'),'now'=>current_time('H:i'),
            'time_in'=>(string)($in['time_in']??''),'time_out'=>(string)($in['time_out']??''),
            'in'=>$day['in']?(string)$day['in']->event_at:null,'out'=>$day['out']?(string)$day['out']->event_at:null,
            'overnight'=>!empty($h['overnight']) && $h['start']>$h['end'],'source'=>$source,'settings'=>$this->correction_settings(),
            'pending'=>$valid && (bool)$this->cx_pending_for_day((int)$emp->id,$date),'month_count'=>$valid?$this->cx_month_count((int)$emp->id,$date):0,
            'reason'=>(string)($in['reason']??''),'has_photo'=>(bool)$has_photo,
        ];
    }

    /** The request fields from a form or the API. @param array<string,mixed> $src */
    private function cx_input(array $src){
        $g=function($k)use($src){return sanitize_text_field(wp_unslash((string)($src[$k]??'')));};
        return ['date'=>$g('date'),'type'=>sanitize_key((string)($src['type']??'')),'target'=>sanitize_key((string)($src['target']??'')),
            'time_in'=>$g('time_in'),'time_out'=>$g('time_out'),'reason'=>trim(sanitize_textarea_field(wp_unslash((string)($src['reason']??''))))];
    }

    /* ------------------------------------------------------------------ files */

    private function cx_file_dir(){
        $up=wp_upload_dir();$dir=trailingslashit($up['basedir']).'workforce-one-corrections';
        if(!is_dir($dir)){
            wp_mkdir_p($dir);
            // Not reachable by URL: every photo goes through ews_correction_photo (access checked).
            @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/index.php',"<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/web.config','<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $dir;
    }

    /** @param mixed $f an entry of $_FILES @return bool */
    private function cx_has_upload($f){
        return is_array($f) && (int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
    }

    /** Saves the request's photo. @param mixed $f @return array{0:?string,1:?string,2:string} [key, name, error] */
    private function cx_save_photo($f){
        if(!$this->cx_has_upload($f))return [null,null,''];
        if((int)$f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']))return [null,null,'photo_file'];
        if((int)$f['size']>8*1048576)return [null,null,'photo_size'];
        $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        $name=sanitize_file_name(wp_basename((string)$f['name']));
        $check=wp_check_filetype_and_ext((string)$f['tmp_name'],$name,$mimes);
        if(empty($check['ext']) || !isset($mimes[strtolower((string)$check['ext'])]))return [null,null,'photo_file'];
        $key=wp_generate_password(32,false).'.'.strtolower((string)$check['ext']);
        if(!@move_uploaded_file((string)$f['tmp_name'],$this->cx_file_dir().'/'.$key))return [null,null,'photo_file']; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.ForbiddenFunctions.Found
        return [$key,mb_substr($name,0,190),''];
    }

    /** A request's photo, for the employee, the people who decide it and HR. */
    public function correction_photo(){
        if(!is_user_logged_in())auth_redirect();
        $id=absint($_GET['id']??0);check_admin_referer('ews_correction_photo_'.$id);
        $c=$this->cx_get($id);
        if(!$c || !$c->photo_key || !$this->cx_can_view($c))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        if(!preg_match('/^[A-Za-z0-9]{32}\.[a-z]{3,4}$/',(string)$c->photo_key))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $path=$this->cx_file_dir().'/'.$c->photo_key;
        if(!is_readable($path))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $ext=strtolower((string)pathinfo((string)$c->photo_key,PATHINFO_EXTENSION));
        $all=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        Download::send((string)file_get_contents($path),$all[$ext]??'application/octet-stream',(string)$c->photo_name); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    private function cx_photo_url($c){
        return $c->photo_key?wp_nonce_url(add_query_arg(['action'=>'ews_correction_photo','id'=>(int)$c->id],admin_url('admin-post.php')),'ews_correction_photo_'.(int)$c->id):'';
    }

    /* ------------------------------------------------------------------ who may do what */

    private function cx_get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$this->cx_table()} c LEFT JOIN {$this->employees} e ON e.id=c.employee_id WHERE c.id=%d",(int)$id));
    }

    private function cx_workflow_mode(){
        $w=$this->approval_workflow('attendance_correction');
        return ($w && (int)$w->active===1)?strtoupper((string)$w->approval_mode):'';
    }

    /** May the current user decide this employee's requests without a workflow? Managers (Manage Time) of their department; with no department, all. */
    private function cx_can_manage($employee_id){
        if(current_user_can('manage_options'))return true;
        if(!$this->can('ews_manage_time'))return false;
        return !$this->current_department_id() || $this->department_scope_allows_employee((int)$employee_id);
    }

    private function cx_is_hr(){ return $this->can('ews_manage_corrections'); }

    /** May the current user decide this request now? */
    private function cx_can_decide($c){
        if(!$c || !in_array($c->status,['pending','pending_hr'],true))return false;
        if((int)$c->employee_user_id && (int)$c->employee_user_id===get_current_user_id())return false;   // never one's own
        if($c->status==='pending_hr')return $this->cx_is_hr();
        if(in_array($this->cx_workflow_mode(),['LEVEL_1','LEVEL_2'],true)){
            $ar=$this->approval_find_request('attendance_correction','attendance_correction',(int)$c->id);
            if($ar && Hub::isOpenApproval((string)$ar->status))return $this->approval_can_act((int)$ar->id);
        }
        return $this->cx_can_manage((int)$c->employee_id);
    }

    private function cx_can_view($c){
        if(!$c)return false;
        if((int)$c->employee_user_id===get_current_user_id())return true;
        return $this->cx_is_hr() || $this->cx_can_manage((int)$c->employee_id) || $this->cx_can_decide($c);
    }

    /** Users who receive the second-level requests: Manage Attendance Corrections, else administrators. @return int[] */
    private function cx_hr_users(){
        $ids=array_map('intval',wp_list_pluck(get_users(['capability'=>'ews_manage_corrections','fields'=>['ID']]),'ID'));
        if(!$ids)$ids=array_map('intval',wp_list_pluck(get_users(['role'=>'administrator','fields'=>['ID']]),'ID'));
        return $ids;
    }

    /* ------------------------------------------------------------------ create */

    /**
     * Creates a request (employee) or a direct correction (HR, applied at once).
     * @param array<string,mixed> $in cx_input()
     * @param mixed $photo an entry of $_FILES
     * @return array{id?:int,status?:string,error?:string}
     */
    private function cx_create($emp,array $in,$source='employee',$photo=null){
        if(!$this->corrections_enabled())return ['error'=>'disabled'];
        if(!$emp)return ['error'=>'employee'];
        $this->ensure_corrections_schema();
        $facts=$this->cx_facts($emp,$in,$source,$this->cx_has_upload($photo));
        $error=CorrectionRules::check($facts);
        if($error!=='')return ['error'=>$error];
        if($source==='employee' && ($this->correction_settings()['photo'][$facts['type']]??'off')==='off')$photo=null;
        [$key,$name,$perr]=$this->cx_save_photo($photo);
        if($perr!=='')return ['error'=>$perr];
        $plan=CorrectionRules::plan($facts);
        $times=CorrectionRules::times($facts);
        $old=$facts['type']==='time'?($facts['target']==='sign_in'?$facts['in']:$facts['out']):null;
        global $wpdb;$now=current_time('mysql');
        $row=['employee_id'=>(int)$emp->id,'work_date'=>$facts['date'],'type'=>$facts['type'],'target'=>$facts['type']==='time'?$facts['target']:null,
            'sign_in_at'=>in_array('sign_in',array_column($plan['events'],'type'),true)?$times['in']:null,
            'sign_out_at'=>in_array('sign_out',array_column($plan['events'],'type'),true)?$times['out']:null,'old_at'=>$old,
            'reason'=>mb_substr($facts['reason'],0,1000),'photo_key'=>$key,'photo_name'=>$name,'status'=>$source==='hr'?'approved':'pending','source'=>$source,
            'needs_hr'=>$plan['needs_hr']?1:0,'above_limit'=>$plan['above_limit']?1:0,'earlier'=>$plan['earlier']?1:0,
            'requested_by'=>get_current_user_id(),'requested_at'=>$now];
        if($source==='hr'){
            $row['status']='pending';   // claimed by cx_finalize below
            if($wpdb->insert($this->cx_table(),$row)===false)return ['error'=>'save'];
            $id=(int)$wpdb->insert_id;
            $res=$this->cx_finalize($id,true,(string)$facts['reason'],true);
            if($res!=='approved'){$wpdb->delete($this->cx_table(),['id'=>$id],['%d']);return ['error'=>$res];}
            $this->audit('correction_direct','employee',(int)$emp->id,$emp->name.' '.$facts['date'].': '.$this->cx_change_text($this->cx_get($id),'en').' — '.$facts['reason']);
            return ['id'=>$id,'status'=>'approved'];
        }
        if($wpdb->insert($this->cx_table(),$row)===false)return ['error'=>'save'];
        $id=(int)$wpdb->insert_id;
        $this->audit('correction_request','employee',(int)$emp->id,$emp->name.' '.$facts['date'].': '.$this->cx_change_text($this->cx_get($id),'en'));
        $mode=$this->cx_workflow_mode();
        if(in_array($mode,['NONE','LEVEL_1','LEVEL_2'],true)){
            $started=$this->approval_start('attendance_correction','attendance_correction',$id,(int)$emp->id);
            if(is_wp_error($started)){$wpdb->delete($this->cx_table(),['id'=>$id],['%d']);return ['error'=>'approval'];}
            $ar=$this->approval_find_request('attendance_correction','attendance_correction',$id);
            if($ar && $ar->status==='APPROVED'){$r=$this->cx_after_manager($id);return ['id'=>$id,'status'=>$r];}
            $step=$ar?$this->approval_current_step((int)$ar->id):null;
            if($step && $step->approver_wp_user_id)$this->cx_notify_decider((int)$step->approver_wp_user_id,$this->cx_get($id));
            return ['id'=>$id,'status'=>'pending'];
        }
        $c=$this->cx_get($id);
        foreach(get_users(['capability'=>'ews_manage_time','fields'=>['ID']]) as $m){
            if((int)$m->ID===get_current_user_id())continue;
            $this->cx_notify_decider((int)$m->ID,$c);
        }
        return ['id'=>$id,'status'=>'pending'];
    }

    /** After the manager level: to HR when the request needs it, otherwise approved. @return string outcome */
    private function cx_after_manager($id){
        global $wpdb;
        $c=$this->cx_get($id);
        if(!$c)return 'not_found';
        $wpdb->update($this->cx_table(),['manager_by'=>get_current_user_id(),'manager_at'=>current_time('mysql')],['id'=>(int)$id],['%d','%s'],['%d']);
        if((int)$c->needs_hr){
            $ok=$wpdb->update($this->cx_table(),['status'=>'pending_hr'],['id'=>(int)$id,'status'=>'pending'],['%s'],['%d','%s']);
            if($ok!==1)return 'done';
            $c=$this->cx_get($id);
            foreach($this->cx_hr_users() as $u)if($u!==(int)$c->employee_user_id)$this->cx_notify_decider($u,$c);
            return 'pending_hr';
        }
        return $this->cx_finalize($id,true,'');
    }

    private function cx_notify_decider($user_id,$c){
        if(!$c)return;
        $this->notify((int)$user_id,'correction',__('Attendance correction to review','workforce-one'),
            /* translators: 1: employee, 2: day, 3: the change */
            sprintf(__('%1$s asks to correct %2$s: %3$s.','workforce-one'),$c->employee_name,$this->cx_day_label((string)$c->work_date),$this->cx_change_text($c)),
            ['entity_id'=>(int)$c->id,'url'=>add_query_arg('ews_view','corrections',$this->app_home_url())]);
    }

    /* ------------------------------------------------------------------ decide */

    /**
     * A decision on a request. $admin: an administrator or HR deciding in wp-admin (any level).
     * @return string approved | rejected | advanced | pending_hr, or an error code
     */
    private function cx_decide($id,$decision,$note,$admin=false){
        if(!in_array($decision,['approve','reject'],true))return 'invalid';
        $note=trim((string)$note);
        $c=$this->cx_get($id);
        if(!$c)return 'not_found';
        if(!in_array($c->status,['pending','pending_hr'],true))return 'done';
        if($decision==='reject' && $note==='')return 'note';
        if((int)$c->employee_user_id && (int)$c->employee_user_id===get_current_user_id())return 'own';
        if($admin && !$this->cx_is_hr())return 'access';
        if(!$admin && !$this->cx_can_decide($c))return 'access';
        if($c->status==='pending' && in_array($this->cx_workflow_mode(),['LEVEL_1','LEVEL_2'],true)){
            $ar=$this->approval_find_request('attendance_correction','attendance_correction',(int)$c->id);
            if($ar && Hub::isOpenApproval((string)$ar->status)){
                if(is_wp_error($this->approval_act((int)$ar->id,$decision,$note,true,$admin)))return 'approval';
                $fresh=$this->approval_find_request('attendance_correction','attendance_correction',(int)$c->id);
                $st=$fresh?strtoupper((string)$fresh->status):'';
                if($st==='REJECTED')return $this->cx_finalize((int)$c->id,false,$note);
                if($st==='APPROVED')return $this->cx_after_manager((int)$c->id);
                $next=$fresh?$this->approval_current_step((int)$fresh->id):null;
                if($next && $next->approver_wp_user_id)$this->cx_notify_decider((int)$next->approver_wp_user_id,$c);
                return 'advanced';
            }
        }
        if($decision==='reject')return $this->cx_finalize((int)$c->id,false,$note);
        if($c->status==='pending' && !$admin)return $this->cx_after_manager((int)$c->id);
        if($c->status==='pending' && $admin && (int)$c->needs_hr){
            // HR deciding a request that needs HR anyway: both levels at once.
            global $wpdb;$wpdb->update($this->cx_table(),['manager_by'=>get_current_user_id(),'manager_at'=>current_time('mysql')],['id'=>(int)$c->id],['%d','%s'],['%d']);
        }
        return $this->cx_finalize((int)$c->id,true,$note);
    }

    /**
     * Approves (adds the events) or rejects a request. The day is checked again first: if it changed
     * since the request (the employee signed out after all), the request stays open.
     * @return string approved | rejected, or an error code
     */
    private function cx_finalize($id,$approve,$note,$direct=false){
        global $wpdb;
        $c=$this->cx_get($id);
        if(!$c)return 'not_found';
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",(int)$c->employee_id));
        if(!$emp)return 'employee';
        $now=current_time('mysql');
        if(!$approve){
            $ok=$wpdb->update($this->cx_table(),['status'=>'rejected','decided_by'=>get_current_user_id(),'decided_at'=>$now,'decision_note'=>mb_substr($note,0,1000)],['id'=>(int)$id,'status'=>$c->status],['%s','%d','%s','%s'],['%d','%s']);
            if($ok!==1)return 'done';
            $this->audit('correction_rejected','employee',(int)$c->employee_id,$c->employee_name.' '.$c->work_date.': '.$this->cx_change_text($c,'en').' — '.$note);
            if((int)$c->employee_user_id)$this->notify((int)$c->employee_user_id,'correction',__('Correction rejected','workforce-one'),
                /* translators: 1: day, 2: the manager's note */
                sprintf(__('Your correction for %1$s was rejected: %2$s','workforce-one'),$this->cx_day_label((string)$c->work_date),$note),['entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','corrections',$this->app_home_url())]);
            return 'rejected';
        }
        // The day as it is now must still accept the correction.
        $in=['date'=>(string)$c->work_date,'type'=>(string)$c->type,'target'=>(string)$c->target,'reason'=>(string)$c->reason?:'-',
            'time_in'=>$c->sign_in_at?substr((string)$c->sign_in_at,11,5):'','time_out'=>$c->sign_out_at?substr((string)$c->sign_out_at,11,5):''];
        $facts=$this->cx_facts($emp,$in,'hr',true);
        $facts['now']='23:59';   // a request made earlier today stays valid
        $error=CorrectionRules::check($facts);
        if($error!=='')return 'stale';
        $plan=CorrectionRules::plan($facts);
        $day=$this->cx_day((int)$emp->id,(string)$c->work_date);
        $month=substr((string)$c->work_date,0,7);
        $before=$this->cx_payroll_net((int)$emp->id,$month);
        if($wpdb->query('START TRANSACTION')===false)return 'save';
        $ok=$wpdb->update($this->cx_table(),['status'=>'approved','decided_by'=>get_current_user_id(),'decided_at'=>$now,'decision_note'=>$note!==''?mb_substr($note,0,1000):null],['id'=>(int)$id,'status'=>$c->status],['%s','%d','%s','%s'],['%d','%s']);
        if($ok!==1){$wpdb->query('ROLLBACK');return 'done';}
        $sch=$this->schedule_for_employee_date((int)$emp->id,(string)$c->work_date);
        $ids=[];
        foreach($plan['events'] as $ev){
            $orig=$plan['supersede']===$ev['type']?($ev['type']==='sign_in'?$day['in']:$day['out']):null;
            $row=['employee_id'=>(int)$emp->id,'user_id'=>(int)$emp->wp_user_id?:get_current_user_id(),'work_date'=>(string)$c->work_date,'event_type'=>$ev['type'],'event_at'=>$ev['at'],
                'scheduled_status'=>$orig?(string)$orig->scheduled_status:($sch?(string)$sch->status:'Not Set'),'location_status'=>'correction','created_at'=>$now,
                'source'=>'correction','correction_id'=>(int)$id,'corrects_id'=>$orig?(int)$orig->id:null];
            if($orig && isset($orig->location_id))$row+=['location_id'=>$orig->location_id,'branch_flag'=>$orig->branch_flag];
            if($wpdb->insert($this->time_logs,$row)===false){$wpdb->query('ROLLBACK');return 'save';}
            $ids[]=(int)$wpdb->insert_id;
        }
        $wpdb->update($this->cx_table(),['applied_ids'=>implode(',',$ids)],['id'=>(int)$id],['%s'],['%d']);
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
        $carried=$this->cx_payroll_carry($this->cx_get($id),$before);
        if(!$direct)$this->audit('correction_approved','employee',(int)$c->employee_id,$c->employee_name.' '.$c->work_date.': '.$this->cx_change_text($c,'en').($carried!==''?' — payroll: '.$carried:''));
        if((int)$c->employee_user_id)$this->notify((int)$c->employee_user_id,'correction',__('Correction approved','workforce-one'),
            /* translators: 1: day, 2: the change */
            sprintf(__('Your correction for %1$s was approved: %2$s.','workforce-one'),$this->cx_day_label((string)$c->work_date),$this->cx_change_text($c)),['entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','corrections',$this->app_home_url())]);
        return 'approved';
    }

    /* ------------------------------------------------------------------ payroll */

    /** The employee's net pay for a closed month, worked out as it would be now; null when the month is not closed. */
    private function cx_payroll_net($employee_id,$month){
        if(!method_exists($this,'payroll_run'))return null;
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$wpdb->prefix.'ews_payroll_runs'))!==$wpdb->prefix.'ews_payroll_runs')return null;
        if(!$this->payroll_run($month))return null;
        foreach($this->payroll_month($month,(int)$employee_id) as $x){
            if((int)$x['employee']->id===(int)$employee_id && $x['pay']!==null)return (float)$x['pay']['net'];
        }
        return null;
    }

    /**
     * A closed month is never reopened by a correction: the difference it makes goes to the next open
     * month as an adjustment, with the reason, and shows on that payslip.
     * @return string what was carried ('' = nothing)
     */
    private function cx_payroll_carry($c,$before){
        if($before===null || !$c)return '';
        $month=substr((string)$c->work_date,0,7);
        $after=$this->cx_payroll_net((int)$c->employee_id,$month);
        if($after===null)return '';
        $diff=round($after-$before,2);
        if(abs($diff)<0.01)return '';
        $to=$month;
        for($i=0;$i<36;$i++){$to=date('Y-m',strtotime($to.'-01 +1 month'));if(!$this->payroll_run($to))break;}
        global $wpdb;
        $reason=sprintf('Attendance correction #%d for %s (closed month %s)',(int)$c->id,$c->work_date,$month);
        $wpdb->insert($this->payroll_tables()['adjustments'],['employee_id'=>(int)$c->employee_id,'month'=>$to,'kind'=>$diff>0?'bonus':'deduction','amount'=>abs($diff),'reason'=>$reason,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')],['%d','%s','%s','%f','%s','%d','%s']);
        $note=$to.' '.($diff>0?'+':'−').number_format(abs($diff),2);
        $wpdb->update($this->cx_table(),['payroll_note'=>$note],['id'=>(int)$c->id],['%s'],['%d']);
        $this->audit('pay_adjustment_added','employee',(int)$c->employee_id,$c->employee_name.' '.$to.': '.($diff>0?'bonus':'deduction').' — '.$reason);
        return $note;
    }

    /* ------------------------------------------------------------------ text */

    private function cx_day_label($date){ return date_i18n('D j M',strtotime((string)$date)); }

    private function cx_type_label($type){
        $l=['out'=>__('Forgot Sign Out','workforce-one'),'in'=>__('Forgot Sign In','workforce-one'),'time'=>__('Wrong time','workforce-one'),'day'=>__('Whole day missing','workforce-one')];
        return $l[$type]??$type;
    }

    private function cx_status_label($status){
        $l=['pending'=>__('Waiting','workforce-one'),'pending_hr'=>__('Waiting for HR','workforce-one'),'approved'=>__('Approved','workforce-one'),'rejected'=>__('Rejected','workforce-one')];
        return $l[$status]??$status;
    }

    /** "Sign Out → 17:30", "Sign In 09:31 → 08:55", "09:00 – 17:00". $lang 'en' for the Audit Log. */
    private function cx_change_text($c,$lang=''){
        if(!$c)return '';
        $t=function($s)use($lang){return $lang==='en'?$s:__($s,'workforce-one');}; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        $hm=function($v){return $v?substr((string)$v,11,5):'';};
        switch($c->type){
            case 'out': return $t('Sign Out').' → '.$hm($c->sign_out_at);
            case 'in': return $t('Sign In').' → '.$hm($c->sign_in_at);
            case 'day': return $hm($c->sign_in_at).' – '.$hm($c->sign_out_at);
            default: return ($c->target==='sign_in'?$t('Sign In'):$t('Sign Out')).' '.$hm($c->old_at).' → '.$hm($c->target==='sign_in'?$c->sign_in_at:$c->sign_out_at);
        }
    }

    private function cx_error_message($code){
        $s=$this->correction_settings();
        $m=[
            'disabled'=>__('Attendance corrections are switched off.','workforce-one'),
            'employee'=>__('Your account is not linked to an employee.','workforce-one'),
            'type'=>__('This kind of correction is not available.','workforce-one'),
            'date'=>__('Choose a valid day.','workforce-one'),
            'future'=>__('You cannot correct a day that has not come yet.','workforce-one'),
            /* translators: %d: number of days */
            'deadline'=>sprintf(_n('This day is more than %d day ago, so it cannot be corrected from the app. Ask HR to correct it.','This day is more than %d days ago, so it cannot be corrected from the app. Ask HR to correct it.',(int)$s['deadline_days'],'workforce-one'),(int)$s['deadline_days']),
            'pending'=>__('You already asked to correct this day. Wait for the decision.','workforce-one'),
            'reason'=>__('Write what happened.','workforce-one'),
            'photo'=>__('Attach a photo for this kind of correction.','workforce-one'),
            'photo_file'=>__('The photo could not be saved. Use a JPG, PNG or WebP image.','workforce-one'),
            'photo_size'=>__('The photo is larger than 8 MB.','workforce-one'),
            'time'=>__('Enter the correct time.','workforce-one'),
            'future_time'=>__('That time has not come yet today.','workforce-one'),
            'has_in'=>__('A Sign In is already recorded for this day: choose "Wrong time" to change it.','workforce-one'),
            'no_in'=>__('There is no Sign In on this day: choose "Forgot Sign In" or "Whole day missing".','workforce-one'),
            'has_out'=>__('A Sign Out is already recorded for this day: choose "Wrong time" to change it.','workforce-one'),
            'has_events'=>__('This day already has a Sign In or Sign Out: choose another kind of correction.','workforce-one'),
            'no_event'=>__('That time is not recorded on this day.','workforce-one'),
            'order'=>__('Sign Out must be after Sign In.','workforce-one'),
            'same'=>__('That is the time already recorded.','workforce-one'),
            'limit'=>__('You have used all your corrections for this month.','workforce-one'),
            'note'=>__('Write why you reject it: the employee will see it.','workforce-one'),
            'own'=>__('You cannot decide your own request.','workforce-one'),
            'access'=>__('You cannot decide this request.','workforce-one'),
            'done'=>__('This request was already decided.','workforce-one'),
            'stale'=>__('The day changed since this request was made, so it cannot be applied. Reject it, or ask HR to correct the day.','workforce-one'),
            'approval'=>__('The approval could not be recorded. Please try again.','workforce-one'),
        ];
        return $m[$code]??__('Something went wrong. Please try again.','workforce-one');
    }

    /* ------------------------------------------------------------------ web handlers */

    private function cx_back($args){
        $url=wp_get_referer();
        if(!$url||strpos($url,'admin-post.php')!==false)$url=add_query_arg('ews_view','corrections',$this->app_home_url());
        $url=remove_query_arg(['cx_sent','cx_error','cx_done','cx','cx_date','cx_retry'],$url);
        wp_safe_redirect(add_query_arg($args,$url));exit;
    }

    public function correction_request(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        check_admin_referer('ews_correction_request');
        $r=$this->cx_create($this->current_employee(),$this->cx_input($_POST),'employee',$_FILES['photo']??null); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked in cx_save_photo()
        if(isset($r['error']))$this->cx_back(['cx_error'=>$r['error'],'cx_retry'=>1,'cx'=>sanitize_key($_POST['type']??''),'cx_date'=>sanitize_text_field(wp_unslash($_POST['date']??''))]);
        $this->cx_back(['cx_sent'=>$r['status']]);
    }

    public function correction_decide(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        $id=absint($_POST['correction_id']??0);
        check_admin_referer('ews_correction_decide_'.$id);
        $admin=!empty($_POST['in_admin']) && is_admin();
        $r=$this->cx_decide($id,sanitize_key($_POST['decision']??''),sanitize_textarea_field(wp_unslash($_POST['note']??'')),$admin);
        $ok=in_array($r,['approved','rejected','advanced','pending_hr'],true);
        $this->cx_back($ok?['cx_done'=>$r]:['cx_error'=>$r]);
    }

    /** The pop-up after a request or a decision (?cx_sent / cx_done / cx_error). */
    private function cx_notice(){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        if(isset($_GET['cx_error'])){
            $code=sanitize_key($_GET['cx_error']);
            // A refused request opens the form again with the message in it.
            if(in_array($code,['note'],true)||!isset($_GET['cx_retry']))return $this->ux_modal(__('Not saved','workforce-one'),$this->cx_error_message($code),'error');
            return '';
        }
        if(isset($_GET['cx_sent'])){
            $st=sanitize_key($_GET['cx_sent']);
            if($st==='approved')return $this->ux_modal(__('Correction approved','workforce-one'),__('No approval was needed: your day is corrected.','workforce-one'),'success');
            if($st==='pending_hr')return $this->ux_modal(__('Request sent','workforce-one'),__('Your request went to HR for approval. You will get a notification when it is decided.','workforce-one'),'success');
            return $this->ux_modal(__('Request sent','workforce-one'),__('Your manager will review it. You will get a notification when it is decided.','workforce-one'),'success');
        }
        $done=sanitize_key($_GET['cx_done']);
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $m=['approved'=>[__('Approved','workforce-one'),__('The day is corrected. The original record is kept, and the correction is in the Audit Log under your name.','workforce-one')],
            'rejected'=>[__('Rejected','workforce-one'),__('The employee will see your note.','workforce-one')],
            'advanced'=>[__('Approved','workforce-one'),__('The request moved to the next approval level.','workforce-one')],
            'pending_hr'=>[__('Approved','workforce-one'),__('The request now goes to HR for the second approval.','workforce-one')]];
        if(!isset($m[$done]))return '';
        return $this->ux_modal($m[$done][0],$m[$done][1],'success');
    }

    /* ------------------------------------------------------------------ employee app */

    /**
     * The employee's recent days for the Sign In / Out page, newest first, within the deadline.
     * @return array<int,array<string,mixed>>
     */
    private function cx_recent_days($emp){
        if(!$emp)return [];
        $s=$this->correction_settings();
        $today=current_time('Y-m-d');
        $from=date('Y-m-d',strtotime($today.' -'.(int)$s['deadline_days'].' days'));
        global $wpdb;
        $reqs=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->cx_table()} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY id ASC",(int)$emp->id,$from,$today)) as $r)$reqs[$r->work_date]=$r;
        $out=[];
        foreach(array_reverse($this->report_days([$emp],$from,$today,true)) as $d){
            $date=(string)$d['date'];
            $req=$reqs[$date]??null;
            $problem='';
            if($date===$today){ if($d['sign_in']!=='' && $d['sign_out']==='')$problem='today'; }
            elseif($d['sign_in']!=='' && $d['sign_out']==='')$problem='no_out';
            elseif($d['sign_in']==='' && ($d['result']??'')==='Absent')$problem='absent';
            $open=$req && in_array($req->status,['pending','pending_hr'],true);
            $kinds=CorrectionRules::kinds((string)$d['sign_in']!=='',(string)$d['sign_out']!=='',$s['types']);   // only what fits the day
            $out[]=['date'=>$date,'in'=>(string)$d['sign_in'],'out'=>(string)$d['sign_out'],'kinds'=>$kinds,'result'=>(string)($d['result']??''),'planned'=>(string)($d['planned']??''),
                'late'=>(int)($d['late_minutes']??0),'holiday'=>(string)($d['holiday']??''),'problem'=>$problem,'request'=>$req,'can'=>!$open,'today'=>$date===$today];
        }
        return $out;
    }

    /** The request sheet's data (the Sign In / Out page and the Corrections page). */
    private function cx_sheet_data($emp,array $days){
        $s=$this->correction_settings();
        $types=[];
        foreach(CorrectionSettings::TYPES as $t)if(!empty($s['types'][$t]))$types[$t]=['label'=>$this->cx_type_label($t),'photo'=>$s['photo'][$t]];
        $used=$emp?$this->cx_month_count((int)$emp->id,current_time('Y-m-d')):0;
        return ['types'=>$types,'days'=>$days,'settings'=>$s,'used'=>$used,'post_url'=>admin_url('admin-post.php'),
            'manager'=>$this->cx_manager_name($emp),'open'=>sanitize_key($_GET['cx']??''),'open_date'=>sanitize_text_field(wp_unslash($_GET['cx_date']??'')), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only: the end-of-day push opens the sheet
            'error'=>isset($_GET['cx_error'])?$this->cx_error_message(sanitize_key($_GET['cx_error'])):''];   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
    }

    private function cx_manager_name($emp){
        if(!$emp)return '';
        $sup=$this->approval_related_employee((int)$emp->id,'supervisor');
        return $sup?(string)$sup->name:'';
    }

    /** The "My recent days" panel and the sheet, under Today's Record on Sign In / Out. */
    private function cx_time_panel($emp){
        if(!$this->corrections_enabled() || !$emp)return '';
        $this->ensure_corrections_schema_once();
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_style('workforce-one-corrections');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-corrections');
        $days=$this->cx_recent_days($emp);
        return $this->render_template('app/correction-days',['days'=>$days,'corrections_url'=>$this->app_view_url('corrections'),'deadline'=>$this->correction_settings()['deadline_days'],'used'=>$this->cx_month_count((int)$emp->id,current_time('Y-m-d')),'limit'=>$this->correction_settings()['monthly_limit']])
            .$this->render_template('app/correction-sheet',$this->cx_sheet_data($emp,$days));
    }

    private function ensure_corrections_schema_once(){
        static $done=false;
        if($done)return;
        $done=true;
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->cx_table()))!==$this->cx_table())$this->ensure_corrections_schema();
    }

    /** Requests the current user may decide, with their evidence. @return object[] */
    private function cx_to_decide(){
        global $wpdb;
        $t=$this->cx_table();$me=get_current_user_id();
        $rows=[];
        $mode=$this->cx_workflow_mode();
        if(in_array($mode,['LEVEL_1','LEVEL_2'],true)){
            $at=$this->approval_tables();
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id,ars.step_order FROM {$t} c INNER JOIN {$this->employees} e ON e.id=c.employee_id
                INNER JOIN {$at['requests']} ar ON ar.entity_type='attendance_correction' AND ar.entity_id=c.id INNER JOIN {$at['instances']} ars ON ars.approval_request_id=ar.id AND ars.status='PENDING'
                WHERE c.status='pending' AND ars.approver_wp_user_id=%d ORDER BY c.requested_at ASC LIMIT 100",$me));
        }elseif($this->can('ews_manage_time')){
            foreach((array)$wpdb->get_results("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$t} c INNER JOIN {$this->employees} e ON e.id=c.employee_id WHERE c.status='pending' ORDER BY c.requested_at ASC LIMIT 200") as $r){
                if($this->cx_can_manage((int)$r->employee_id))$rows[]=$r;
            }
        }
        if($this->cx_is_hr()){
            foreach((array)$wpdb->get_results("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id FROM {$t} c INNER JOIN {$this->employees} e ON e.id=c.employee_id WHERE c.status='pending_hr' ORDER BY c.requested_at ASC LIMIT 200") as $r)$rows[]=$r;
        }
        $rows=array_values(array_filter($rows,function($r)use($me){return (int)$r->employee_user_id!==$me;}));
        foreach($rows as $r)$r->evidence=$this->cx_evidence($r);
        return $rows;
    }

    /**
     * What the system knows about the request's day, read-only, for the person deciding.
     * @return array<string,mixed>
     */
    private function cx_evidence($c){
        global $wpdb;
        $eid=(int)$c->employee_id;$date=(string)$c->work_date;
        $locs=method_exists($this,'branch_locations')?$this->branch_locations():[];
        $events=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT l.*,(SELECT COUNT(*) FROM {$this->time_logs} s WHERE s.corrects_id=l.id) superseded FROM {$this->time_logs} l WHERE l.employee_id=%d AND l.work_date=%s ORDER BY l.event_at ASC,l.id ASC",$eid,$date)) as $r){
            $events[]=['time'=>substr((string)$r->event_at,11,5),'type'=>(string)$r->event_type,'source'=>(string)($r->source??''),'location_status'=>(string)($r->location_status??''),
                'distance'=>$r->distance_meters!==null?(int)round((float)$r->distance_meters):null,'integrity'=>(string)($r->integrity_status??''),'superseded'=>(int)$r->superseded>0,
                'place'=>isset($r->location_id,$locs[(int)$r->location_id])?(string)$locs[(int)$r->location_id]->name:''];
        }
        $last=$wpdb->get_row($wpdb->prepare("SELECT event_at,location_status,distance_meters FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND latitude IS NOT NULL ORDER BY event_at DESC LIMIT 1",$eid,$date));
        $presence=[];
        if(method_exists($this,'presence_tables')){
            [,$v]=$this->presence_tables();
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$v))===$v){
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT status,created_at,verified_at,kiosk_id FROM {$v} WHERE employee_id=%d AND created_at BETWEEN %s AND %s ORDER BY created_at ASC",$eid,$date.' 00:00:00',$date.' 23:59:59')) as $p){
                    $presence[]=['time'=>substr((string)$p->created_at,11,5),'status'=>(string)$p->status,'verified'=>$p->verified_at?substr((string)$p->verified_at,11,5):'','kiosk'=>(bool)$p->kiosk_id];
                }
            }
        }
        $sch=$this->schedule_for_employee_date($eid,$date);
        $h=$this->working_hours($eid);
        $s=$this->correction_settings();
        $live=array_filter($events,function($e){return !$e['superseded'];});
        $types=array_column($live,'type');
        return ['events'=>$events,'has_in'=>(bool)array_intersect($types,['sign_in','late_sign_in']),'has_out'=>in_array('sign_out',$types,true),'last'=>$last?['time'=>substr((string)$last->event_at,11,5),'status'=>(string)$last->location_status,'distance'=>$last->distance_meters!==null?(int)round((float)$last->distance_meters):null]:null,
            'presence'=>$presence,'schedule'=>$sch?(string)$sch->status:'','shift'=>$h['start'].' – '.$h['end'],'month_count'=>$this->cx_month_count($eid,$date),'limit'=>(int)$s['monthly_limit'],
            'photo_url'=>$this->cx_photo_url($c)];
    }

    private function corrections_content(){
        if(!$this->corrections_enabled())return '<div class="ews-page"><div class="ews-login-error">'.esc_html__('Attendance corrections are switched off.','workforce-one').'</div></div>';
        $this->ensure_corrections_schema_once();
        $emp=$this->current_employee();
        global $wpdb;
        $mine=[];$days=[];
        if($emp){
            $mine=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->cx_table()} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT 30",(int)$emp->id));
            $days=$this->cx_recent_days($emp);
        }
        $decide=$this->cx_to_decide();
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_style('workforce-one-corrections');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-corrections');
        $s=$this->correction_settings();
        return $this->render_template('app/corrections',[
            'emp'=>$emp,'mine'=>$mine,'decide'=>$decide,'settings'=>$s,'used'=>$emp?$this->cx_month_count((int)$emp->id,current_time('Y-m-d')):0,
            'month'=>date_i18n('F',current_time('timestamp')),'resets'=>date_i18n('j F',strtotime(date('Y-m-01',current_time('timestamp')).' +1 month')),
            'post_url'=>admin_url('admin-post.php'),'change'=>function($c){return $this->cx_change_text($c);},'type_label'=>function($t){return $this->cx_type_label($t);},
            'status_label'=>function($s){return $this->cx_status_label($s);},'day_label'=>function($d){return $this->cx_day_label($d);},
            'notice'=>isset($_GET['cx_done'])?sanitize_key($_GET['cx_done']):'','sent'=>isset($_GET['cx_sent'])?sanitize_key($_GET['cx_sent']):'', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
            'error'=>isset($_GET['cx_error'])?$this->cx_error_message(sanitize_key($_GET['cx_error'])):'', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
            'sheet'=>$emp?$this->render_template('app/correction-sheet',$this->cx_sheet_data($emp,$days)):'',
        ]);
    }

    /* ------------------------------------------------------------------ end-of-day reminder */

    /** WP-Cron every 5 minutes: once a day at the set time, a reminder to everyone still signed in. */
    public function corrections_tick(){
        if(!$this->corrections_enabled())return;
        $s=$this->correction_settings();
        if(empty($s['reminder']) || current_time('H:i')<$s['reminder_time'])return;
        $today=current_time('Y-m-d');
        if(get_option('ews_corrections_reminded')===$today)return;
        update_option('ews_corrections_reminded',$today,false);
        $this->ensure_corrections_schema_once();
        $this->corrections_remind($today);
    }

    /** @return int people reminded */
    private function corrections_remind($date){
        global $wpdb;
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.wp_user_id,MIN(l.event_at) signed_in FROM {$this->time_logs} l INNER JOIN {$this->employees} e ON e.id=l.employee_id
            WHERE l.work_date=%s AND l.event_type IN ('sign_in','late_sign_in') AND e.active=1 AND e.wp_user_id IS NOT NULL".$this->tl_live('l.id')."
            AND NOT EXISTS (SELECT 1 FROM {$this->time_logs} o WHERE o.employee_id=l.employee_id AND o.work_date=l.work_date AND o.event_type='sign_out')
            GROUP BY e.id,e.wp_user_id",$date));
        $n=0;
        foreach($rows as $r){
            if(!(int)$r->wp_user_id)continue;
            $h=$this->working_hours((int)$r->id);
            if(!empty($h['overnight']) && $h['start']>$h['end'])continue;   // still at work
            $url=add_query_arg(['ews_view'=>'time','cx'=>'out','cx_date'=>$date],$this->app_home_url());
            $this->notify((int)$r->wp_user_id,'correction',__('You did not sign out today','workforce-one'),
                /* translators: %s: time */
                sprintf(__('You signed in at %s. If you have left, request a correction with the time you left.','workforce-one'),substr((string)$r->signed_in,11,5)),['url'=>$url]);
            $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------ wp-admin */

    private function cx_admin_url($args=[]){ return add_query_arg($args,admin_url('admin.php?page=ews31-corrections')); }

    public function admin_corrections(){
        if(!$this->cx_is_hr())wp_die('Access denied');
        $this->ensure_corrections_schema_once();
        $tab=sanitize_key($_GET['tab']??'list');
        if(!in_array($tab,['list','report','settings','direct'],true))$tab='list';
        global $wpdb;$t=$this->cx_table();
        $data=['tab'=>$tab,'enabled'=>$this->corrections_enabled(),'features_url'=>admin_url('admin.php?page=ews31-features'),'post_url'=>admin_url('admin-post.php'),
            'url'=>function($a=[]){return $this->cx_admin_url($a);},'type_label'=>function($x){return $this->cx_type_label($x);},'status_label'=>function($x){return $this->cx_status_label($x);},
            'change'=>function($c){return $this->cx_change_text($c);},'notice'=>sanitize_key($_GET['cx_done']??''),'error'=>isset($_GET['cx_error'])?$this->cx_error_message(sanitize_key($_GET['cx_error'])):'',
            'departments'=>(array)$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_departments WHERE active=1 ORDER BY name ASC")];
        $month=sanitize_text_field(wp_unslash($_GET['month']??current_time('Y-m')));
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=current_time('Y-m');
        $dept=absint($_GET['department']??0);
        $data+=['month'=>$month,'department'=>$dept];
        if($tab==='list'){
            $status=sanitize_key($_GET['status']??'');$type=sanitize_key($_GET['type']??'');$q=sanitize_text_field(wp_unslash($_GET['s']??''));
            $where=$wpdb->prepare(" WHERE c.work_date BETWEEN %s AND %s",$month.'-01',$month.'-31');
            if(in_array($type,CorrectionSettings::TYPES,true))$where.=$wpdb->prepare(' AND c.type=%s',$type);
            if($dept)$where.=$wpdb->prepare(' AND e.department_id=%d',$dept);
            if($q!=='')$where.=$wpdb->prepare(' AND (e.name LIKE %s OR e.domain_name LIKE %s)','%'.$wpdb->esc_like($q).'%','%'.$wpdb->esc_like($q).'%');
            $counts=['all'=>0,'pending'=>0,'pending_hr'=>0,'approved'=>0,'rejected'=>0];
            foreach((array)$wpdb->get_results("SELECT c.status,COUNT(*) n FROM {$t} c LEFT JOIN {$this->employees} e ON e.id=c.employee_id{$where} GROUP BY c.status") as $r){$counts[$r->status]=(int)$r->n;$counts['all']+=(int)$r->n;}
            if(isset($counts[$status]) && $status!=='all')$where.=$wpdb->prepare(' AND c.status=%s',$status);
            $page=max(1,absint($_GET['paged']??1));$per=50;
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} c LEFT JOIN {$this->employees} e ON e.id=c.employee_id{$where}");
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT c.*,e.name employee_name,e.wp_user_id employee_user_id,e.department_id,d.name department_name,u.display_name decided_name,m.display_name manager_name FROM {$t} c
                LEFT JOIN {$this->employees} e ON e.id=c.employee_id LEFT JOIN {$wpdb->prefix}ews_departments d ON d.id=e.department_id LEFT JOIN {$wpdb->users} u ON u.ID=c.decided_by LEFT JOIN {$wpdb->users} m ON m.ID=c.manager_by
                {$where} ORDER BY c.requested_at DESC LIMIT %d OFFSET %d",$per,($page-1)*$per));
            foreach($rows as $r)$r->photo_url=$this->cx_photo_url($r);
            $data+=['rows'=>$rows,'counts'=>$counts,'status'=>$status,'type'=>$type,'q'=>$q,'paged'=>$page,'pages'=>max(1,(int)ceil($total/$per)),'total'=>$total];
        }elseif($tab==='report'){
            $data+=$this->cx_report($month,$dept);
        }elseif($tab==='settings'){
            $data+=['s'=>$this->correction_settings(),'types'=>array_combine(CorrectionSettings::TYPES,array_map(function($x){return $this->cx_type_label($x);},CorrectionSettings::TYPES)),
                'workflows_url'=>admin_url('admin.php?page=ews31-approvals')];
        }else{
            $data+=['employees'=>(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC LIMIT 5000"),'types'=>array_combine(CorrectionSettings::TYPES,array_map(function($x){return $this->cx_type_label($x);},CorrectionSettings::TYPES)),
                'old'=>isset($_GET['cx_error'])?(array)get_transient('ews_cx_direct_'.get_current_user_id()):[]];
        }
        echo $this->render_template('admin/corrections',$data); // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes
    }

    /** Corrections per employee and department in a month, by type and outcome. */
    private function cx_report($month,$dept=0){
        global $wpdb;$t=$this->cx_table();
        $where=$wpdb->prepare(" WHERE c.work_date BETWEEN %s AND %s",$month.'-01',$month.'-31').($dept?$wpdb->prepare(' AND e.department_id=%d',$dept):'');
        $people=[];$depts=[];$tot=['all'=>0,'approved'=>0,'rejected'=>0,'open'=>0];
        foreach((array)$wpdb->get_results("SELECT c.employee_id,c.type,c.status,e.name,COALESCE(d.name,'') dept FROM {$t} c LEFT JOIN {$this->employees} e ON e.id=c.employee_id LEFT JOIN {$wpdb->prefix}ews_departments d ON d.id=e.department_id{$where}") as $r){
            $k=(int)$r->employee_id;
            if(!isset($people[$k]))$people[$k]=['name'=>(string)$r->name,'dept'=>(string)$r->dept,'out'=>0,'in'=>0,'time'=>0,'day'=>0,'approved'=>0,'rejected'=>0,'all'=>0];
            $people[$k][$r->type]=($people[$k][$r->type]??0)+1;$people[$k]['all']++;
            if($r->status==='approved')$people[$k]['approved']++;
            if($r->status==='rejected')$people[$k]['rejected']++;
            $dk=(string)$r->dept;
            if(!isset($depts[$dk]))$depts[$dk]=['all'=>0,'approved'=>0,'rejected'=>0];
            $depts[$dk]['all']++;
            if(in_array($r->status,['approved','rejected'],true))$depts[$dk][$r->status]++;
            $tot['all']++;
            if(in_array($r->status,['approved','rejected'],true))$tot[$r->status]++;else $tot['open']++;
        }
        uasort($people,function($a,$b){return $b['all']<=>$a['all'] ?: strcmp($a['name'],$b['name']);});
        ksort($depts);
        return ['people'=>$people,'depts'=>$depts,'totals'=>$tot,'frequent'=>3,'export_url'=>wp_nonce_url(add_query_arg(['action'=>'ews_corrections_export','month'=>$month,'department'=>$dept],admin_url('admin-post.php')),'ews_corrections_export')];
    }

    public function corrections_export(){
        if(!$this->cx_is_hr())wp_die('Access denied');
        check_admin_referer('ews_corrections_export');
        $month=sanitize_text_field(wp_unslash($_GET['month']??''));
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=current_time('Y-m');
        global $wpdb;$t=$this->cx_table();$dept=absint($_GET['department']??0);
        $where=$wpdb->prepare(" WHERE c.work_date BETWEEN %s AND %s",$month.'-01',$month.'-31').($dept?$wpdb->prepare(' AND e.department_id=%d',$dept):'');
        $rows=[['Employee','Department','Day','Type','Change','Reason','Status','Source','Requested','Decided by','Decided at','Note','Payroll']];
        foreach((array)$wpdb->get_results("SELECT c.*,e.name employee_name,COALESCE(d.name,'') dept,u.display_name decided_name FROM {$t} c LEFT JOIN {$this->employees} e ON e.id=c.employee_id LEFT JOIN {$wpdb->prefix}ews_departments d ON d.id=e.department_id LEFT JOIN {$wpdb->users} u ON u.ID=c.decided_by{$where} ORDER BY c.work_date ASC,e.name ASC") as $c){
            $rows[]=[(string)$c->employee_name,(string)$c->dept,(string)$c->work_date,$this->cx_type_label($c->type),$this->cx_change_text($c),(string)$c->reason,$this->cx_status_label($c->status),(string)$c->source,(string)$c->requested_at,(string)$c->decided_name,(string)$c->decided_at,(string)$c->decision_note,(string)$c->payroll_note];
        }
        $this->audit('report_export','report',0,'Attendance corrections CSV '.$month);
        $f=fopen('php://temp','w+');
        fwrite($f,"\xEF\xBB\xBF");   // UTF-8 BOM: Excel shows Arabic names correctly
        foreach($rows as $r)fputcsv($f,\WorkforceOne\Support\Csv::row($r));
        rewind($f);$csv=(string)stream_get_contents($f);fclose($f);
        Download::send($csv,'text/csv; charset=utf-8','attendance-corrections-'.$month.'.csv');
    }

    public function corrections_settings_save(){
        if(!$this->cx_is_hr())wp_die('Access denied');
        check_admin_referer('ews_corrections_settings_save');
        [$s,$err]=CorrectionSettings::fromPost(wp_unslash($_POST));
        if($err!==''){wp_safe_redirect($this->cx_admin_url(['tab'=>'settings','cx_settings_error'=>$err]));exit;}
        $before=$this->correction_settings();
        update_option('ews_correction_settings',$s,false);
        if($s!==$before)$this->audit('correction_settings_update','settings',0,'deadline='.$s['deadline_days'].'; limit='.$s['monthly_limit'].'; above='.$s['above_limit'].'; earlier_hr='.$s['earlier_needs_hr'].'; reminder='.($s['reminder']?$s['reminder_time']:'off').'; types='.implode(',',array_keys(array_filter($s['types']))));
        wp_safe_redirect($this->cx_admin_url(['tab'=>'settings','cx_saved'=>1]));exit;
    }

    public function correction_direct(){
        if(!$this->cx_is_hr())wp_die('Access denied');
        check_admin_referer('ews_correction_direct');
        $in=$this->cx_input($_POST);
        $ref=sanitize_text_field(wp_unslash($_POST['employee_ref']??''));
        $eid=\WorkforceOne\Support\Picker::parse($ref);
        global $wpdb;
        $emp=$eid>0?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",$eid)):null;
        set_transient('ews_cx_direct_'.get_current_user_id(),$in+['employee_ref'=>$ref],300);
        if(!$emp){wp_safe_redirect($this->cx_admin_url(['tab'=>'direct','cx_error'=>'employee']));exit;}
        $r=$this->cx_create($emp,$in,'hr');
        if(isset($r['error'])){wp_safe_redirect($this->cx_admin_url(['tab'=>'direct','cx_error'=>$r['error']]));exit;}
        delete_transient('ews_cx_direct_'.get_current_user_id());
        wp_safe_redirect($this->cx_admin_url(['tab'=>'list','cx_done'=>'direct','month'=>substr($in['date'],0,7)]));exit;
    }

    /** wp-admin → Requests Hub rows. @return array<int,object> */
    private function cx_hub_rows(){
        if(!$this->corrections_enabled())return [];
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->cx_table()))!==$this->cx_table())return [];
        return (array)$wpdb->get_results("SELECT c.* FROM {$this->cx_table()} c WHERE c.status IN ('pending','pending_hr') ORDER BY c.requested_at ASC");
    }

    /** Requests Hub decision (administrator). */
    private function cx_hub_decide($id,$decision){
        $r=$this->cx_decide($id,$decision,$decision==='reject'?'Rejected on the Requests page.':'',true);
        if($r==='approved')return Hub::APPROVED;
        if($r==='rejected')return Hub::REJECTED;
        if(in_array($r,['advanced','pending_hr'],true))return Hub::ADVANCED;
        return Hub::ERROR;
    }

    /* ------------------------------------------------------------------ API (native, token) */

    private function cx_api_guard(){
        if(!$this->corrections_enabled())return $this->api_fail(\WorkforceOne\Api\ErrorMap::FORBIDDEN,['reason'=>'disabled']);
        $emp=self::$api_session['employee']??null;
        if(!$emp)return $this->api_fail(\WorkforceOne\Api\ErrorMap::ACCOUNT_NOT_LINKED);
        $this->ensure_corrections_schema_once();
        return null;
    }

    private function cx_api_view($c){
        return ['id'=>(int)$c->id,'date'=>(string)$c->work_date,'type'=>(string)$c->type,'target'=>$c->target!==null?(string)$c->target:null,
            'sign_in'=>$c->sign_in_at?substr((string)$c->sign_in_at,11,5):null,'sign_out'=>$c->sign_out_at?substr((string)$c->sign_out_at,11,5):null,'previous'=>$c->old_at?substr((string)$c->old_at,11,5):null,
            'reason'=>(string)$c->reason,'status'=>(string)$c->status,'note'=>$c->decision_note!==null?(string)$c->decision_note:null,'requested_at'=>(string)$c->requested_at,'decided_at'=>$c->decided_at?(string)$c->decided_at:null,
            'change'=>$this->cx_change_text($c),'has_photo'=>(bool)$c->photo_key];
    }

    public function api_corrections_days($request){
        if($e=$this->cx_api_guard())return $e;
        $emp=self::$api_session['employee'];$s=$this->correction_settings();
        $days=array_map(function($d){return ['date'=>$d['date'],'sign_in'=>$d['in']?:null,'sign_out'=>$d['out']?:null,'result'=>$d['result'],'problem'=>$d['problem']?:null,
            'can_request'=>(bool)$d['can'] && $d['kinds'],'kinds'=>$d['kinds'],'request'=>$d['request']?$this->cx_api_view($d['request']):null];},$this->cx_recent_days($emp));
        $types=[];foreach(CorrectionSettings::TYPES as $t)if(!empty($s['types'][$t]))$types[]=['type'=>$t,'label'=>$this->cx_type_label($t),'photo'=>$s['photo'][$t]];
        return $this->api_ok(['days'=>$days,'types'=>$types,'deadline_days'=>(int)$s['deadline_days'],'monthly_limit'=>(int)$s['monthly_limit'],'used_this_month'=>$this->cx_month_count((int)$emp->id,current_time('Y-m-d'))]);
    }

    public function api_corrections_mine($request){
        if($e=$this->cx_api_guard())return $e;
        global $wpdb;$emp=self::$api_session['employee'];
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->cx_table()} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT 50",(int)$emp->id));
        return $this->api_ok(['requests'=>array_map(function($c){return $this->cx_api_view($c);},$rows)]);
    }

    public function api_corrections_create($request){
        if($e=$this->cx_api_guard())return $e;
        $p=$request->get_params();
        $files=$request->get_file_params();
        $r=$this->cx_create(self::$api_session['employee'],$this->cx_input(is_array($p)?$p:[]),'employee',$files['photo']??null);
        if(isset($r['error']))return $this->api_fail(\WorkforceOne\Api\ErrorMap::VALIDATION_FAILED,['reason'=>$r['error'],'message'=>$this->cx_error_message($r['error'])]);
        return $this->api_ok(['request'=>$this->cx_api_view($this->cx_get((int)$r['id']))],201);
    }

    public function api_corrections_pending($request){
        if($e=$this->cx_api_guard())return $e;
        return $this->api_ok(['requests'=>array_map(function($c){return $this->cx_api_view($c)+['employee'=>(string)$c->employee_name,'evidence'=>array_diff_key($c->evidence,['photo_url'=>1])];},$this->cx_to_decide())]);
    }

    public function api_corrections_decide($request){
        if($e=$this->cx_api_guard())return $e;
        $id=absint($request['id']);
        $r=$this->cx_decide($id,sanitize_key((string)$request->get_param('decision')),sanitize_textarea_field((string)$request->get_param('note')),false);
        if(in_array($r,['approved','rejected','advanced','pending_hr'],true))return $this->api_ok(['outcome'=>$r,'request'=>$this->cx_api_view($this->cx_get($id))]);
        if($r==='not_found')return $this->api_fail(\WorkforceOne\Api\ErrorMap::NOT_FOUND);
        if(in_array($r,['access','own'],true))return $this->api_fail(\WorkforceOne\Api\ErrorMap::FORBIDDEN,['reason'=>$r]);
        return $this->api_fail(\WorkforceOne\Api\ErrorMap::VALIDATION_FAILED,['reason'=>$r,'message'=>$this->cx_error_message($r)]);
    }
}
