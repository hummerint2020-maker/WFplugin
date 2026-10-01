<?php
if (!defined('ABSPATH')) exit;

trait EWS_Core_Trait {

    /* Request-level performance caches. These are intentionally scoped to the
       current PHP request so writes remain immediately visible on the next request. */
    private static $ews_current_employee_cache = [];
    private static $ews_shifts_cache = null;
    private static $ews_shift_for_employee_cache = [];
    private static $ews_working_hours_cache = [];
    private static $ews_schedule_types_cache = null;
    private static $ews_schedule_type_by_name_cache = [];
    private static $ews_company_leave_cache = [];
    private static $ews_emps_cache = null;

    private function invalidate_employee_runtime_cache($employee_id=0,$wp_user_id=0){
            $employee_id=absint($employee_id); $wp_user_id=absint($wp_user_id);
            if($employee_id){unset(self::$ews_shift_for_employee_cache[$employee_id],self::$ews_working_hours_cache[$employee_id]);}
            if($wp_user_id)unset(self::$ews_current_employee_cache[$wp_user_id]);
            self::$ews_emps_cache=null;
        }

    private function invalidate_schedule_runtime_cache(){
            self::$ews_shifts_cache=null;
            self::$ews_shift_for_employee_cache=[];
            self::$ews_working_hours_cache=[];
            self::$ews_schedule_types_cache=null;
            self::$ews_schedule_type_by_name_cache=[];
        }

    private function invalidate_working_hours_runtime_cache(){
            self::$ews_working_hours_cache=[];
        }

    private function invalidate_company_leave_runtime_cache(){
            self::$ews_company_leave_cache=[];
        }


    private function employee_attendance_enabled($employee_id){
            global $wpdb;
            $employee_id=absint($employee_id);
            if(!$employee_id)return false;
            $value=$wpdb->get_var($wpdb->prepare("SELECT attendance_enabled FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
            return $value===null ? true : ((int)$value===1);
        }

    private function ensure_employee_attendance_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb;
            $employees=$this->employees;
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$employees))!==$employees)return;
            if(!$wpdb->get_var("SHOW COLUMNS FROM {$employees} LIKE 'attendance_enabled'")){
                $wpdb->query("ALTER TABLE {$employees} ADD COLUMN attendance_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER default_shift_id");
                $wpdb->query("UPDATE {$employees} SET attendance_enabled=1 WHERE attendance_enabled IS NULL");
            }
        }

    private function ensure_employee_profile_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb;
            $employees=$this->employees;
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$employees))!==$employees)return;
            $cols=$wpdb->get_col("SHOW COLUMNS FROM {$employees}",0);
            $defs=[
                'profile_image_type'=>"VARCHAR(20) NOT NULL DEFAULT 'initials'",
                'profile_image_url'=>"TEXT NULL",
                'avatar_key'=>"VARCHAR(80) NULL"
            ];
            foreach($defs as $col=>$def){
                if(!in_array($col,$cols,true))$wpdb->query("ALTER TABLE {$employees} ADD COLUMN `{$col}` {$def}");
            }
        }

    private function ensure_time_feature_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb;
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->time_logs))===$this->time_logs){
                $cols=$wpdb->get_col("SHOW COLUMNS FROM {$this->time_logs}",0);
                if(!in_array('latitude',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN latitude DECIMAL(10,7) NULL AFTER ip_address");
                if(!in_array('longitude',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude");
                if(!in_array('accuracy',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN accuracy DECIMAL(10,2) NULL AFTER longitude");
                if(!in_array('location_status',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN location_status VARCHAR(30) NULL AFTER accuracy");
                if(!in_array('location_timestamp',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN location_timestamp BIGINT NULL AFTER location_status");
                if(!in_array('integrity_status',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN integrity_status VARCHAR(30) NULL AFTER location_timestamp");
                if(!in_array('integrity_reason',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN integrity_reason VARCHAR(190) NULL AFTER integrity_status");
                if(!in_array('distance_meters',$cols,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN distance_meters DECIMAL(12,2) NULL AFTER location_status");
                $index_names=$wpdb->get_col("SHOW INDEX FROM {$this->time_logs}",2);
                if(!in_array('employee_date_type_at',$index_names,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD KEY employee_date_type_at (employee_id,work_date,event_type,event_at)");
            }
            $employees=$this->employees;
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$employees))===$employees){
                if(!$wpdb->get_var("SHOW COLUMNS FROM {$employees} LIKE 'wp_user_id'")){
                    $wpdb->query("ALTER TABLE {$employees} ADD COLUMN wp_user_id BIGINT UNSIGNED NULL AFTER email");
                    $wpdb->query("ALTER TABLE {$employees} ADD KEY wp_user_id (wp_user_id)");
                }
                if(!$wpdb->get_var("SHOW COLUMNS FROM {$employees} LIKE 'default_shift_id'")){
                    $wpdb->query("ALTER TABLE {$employees} ADD COLUMN default_shift_id BIGINT UNSIGNED NULL AFTER wp_user_id");
                    $wpdb->query("ALTER TABLE {$employees} ADD KEY default_shift_id (default_shift_id)");
                }
            }
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->time_logs))!==$this->time_logs){
                require_once ABSPATH.'wp-admin/includes/upgrade.php';
                $c=$wpdb->get_charset_collate();
                dbDelta("CREATE TABLE {$this->time_logs} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,work_date DATE NOT NULL,event_type VARCHAR(30) NOT NULL,event_at DATETIME NOT NULL,scheduled_status VARCHAR(40) NOT NULL,ip_address VARCHAR(64) NULL,latitude DECIMAL(10,7) NULL,longitude DECIMAL(10,7) NULL,accuracy DECIMAL(10,2) NULL,location_status VARCHAR(30) NULL,distance_meters DECIMAL(12,2) NULL,location_timestamp BIGINT NULL,integrity_status VARCHAR(30) NULL,integrity_reason VARCHAR(190) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY employee_date(employee_id,work_date),KEY event_type(event_type),KEY event_at(event_at)) $c;");
            }
        }

    private function ensure_swap_schema(){
            global $wpdb;
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $c=$wpdb->get_charset_collate();
            $table=$wpdb->prefix.'ews_schedule_swaps';
            if($this->ews_schema_is_current())return $table;
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                requester_employee_id BIGINT UNSIGNED NOT NULL,
                target_employee_id BIGINT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                requester_status VARCHAR(40) NOT NULL,
                target_status VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                requested_by BIGINT UNSIGNED NOT NULL,
                responded_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at DATETIME NULL,
                PRIMARY KEY(id),
                KEY requester_status(requester_employee_id,status),
                KEY target_status(target_employee_id,status),
                KEY work_date(work_date),
                KEY status(status)
            ) $c;");
            return $table;
        }

    

        private function ensure_leave_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
            $types=$wpdb->prefix.'ews_leave_types'; $bal=$wpdb->prefix.'ews_leave_balances';
            $req=$wpdb->prefix.'ews_leave_requests'; $snap=$wpdb->prefix.'ews_leave_schedule_snapshots';
            $early=$wpdb->prefix.'ews_early_leave_requests';
            dbDelta("CREATE TABLE {$types} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1, deduct_balance TINYINT(1) NOT NULL DEFAULT 1,
                annual_entitlement DECIMAL(8,2) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id), KEY active(active)) {$c};");
            dbDelta("CREATE TABLE {$bal} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL,
                leave_type_id BIGINT UNSIGNED NOT NULL, leave_year INT NOT NULL, entitlement DECIMAL(8,2) NOT NULL DEFAULT 0,
                used DECIMAL(8,2) NOT NULL DEFAULT 0, pending DECIMAL(8,2) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id), UNIQUE KEY employee_type_year(employee_id,leave_type_id,leave_year), KEY employee_year(employee_id,leave_year)) {$c};");
            dbDelta("CREATE TABLE {$req} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, leave_type_id BIGINT UNSIGNED NOT NULL,
                start_date DATE NOT NULL, end_date DATE NOT NULL, requested_days DECIMAL(8,2) NOT NULL DEFAULT 0,
                reason TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Pending', cancellation_status VARCHAR(30) NULL,
                requested_by BIGINT UNSIGNED NOT NULL, reviewed_by BIGINT UNSIGNED NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL, cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL,
                PRIMARY KEY(id), KEY employee_status(employee_id,status), KEY dates(start_date,end_date), KEY type_year(leave_type_id,start_date)) {$c};");
            dbDelta("CREATE TABLE {$snap} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, leave_request_id BIGINT UNSIGNED NOT NULL, employee_id BIGINT UNSIGNED NOT NULL,
                work_date DATE NOT NULL, had_schedule TINYINT(1) NOT NULL DEFAULT 0, schedule_id BIGINT UNSIGNED NULL,
                status VARCHAR(80) NULL, note TEXT NULL, schedule_data LONGTEXT NULL,
                PRIMARY KEY(id), KEY request_date(leave_request_id,work_date)) {$c};");
            dbDelta("CREATE TABLE {$early} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, employee_id BIGINT UNSIGNED NOT NULL, work_date DATE NOT NULL,
                leave_minutes INT UNSIGNED NOT NULL, reason TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Pending',
                requested_by BIGINT UNSIGNED NOT NULL, reviewed_by BIGINT UNSIGNED NULL, requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL, PRIMARY KEY(id), KEY employee_date(employee_id,work_date), KEY employee_status(employee_id,status)) {$c};");
            // Upgrade-safe migration for installations that already had the legacy Vacation table.
            $req_cols=$wpdb->get_col("SHOW COLUMNS FROM {$req}",0);
            $req_defs=[
                'employee_id'=>"BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'leave_type_id'=>"BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'start_date'=>"DATE NOT NULL DEFAULT '1970-01-01'",
                'end_date'=>"DATE NOT NULL DEFAULT '1970-01-01'",
                'requested_days'=>"DECIMAL(8,2) NOT NULL DEFAULT 0",
                'reason'=>"TEXT NULL",
                'status'=>"VARCHAR(30) NOT NULL DEFAULT 'Pending'",
                'cancellation_status'=>"VARCHAR(30) NULL",
                'requested_by'=>"BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'reviewed_by'=>"BIGINT UNSIGNED NULL",
                'requested_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
                'reviewed_at'=>"DATETIME NULL",
                'cancelled_by'=>"BIGINT UNSIGNED NULL",
                'cancelled_at'=>"DATETIME NULL"
            ];
            foreach($req_defs as $col=>$def){
                if(!in_array($col,$req_cols,true))$wpdb->query("ALTER TABLE {$req} ADD COLUMN `{$col}` {$def}");
            }
            $snap_cols=$wpdb->get_col("SHOW COLUMNS FROM {$snap}",0);
            $snap_defs=[
                'leave_request_id'=>"BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'employee_id'=>"BIGINT UNSIGNED NOT NULL DEFAULT 0",
                'work_date'=>"DATE NOT NULL DEFAULT '1970-01-01'",
                'had_schedule'=>"TINYINT(1) NOT NULL DEFAULT 0",
                'schedule_id'=>"BIGINT UNSIGNED NULL",
                'status'=>"VARCHAR(80) NULL",
                'note'=>"TEXT NULL",
                'schedule_data'=>"LONGTEXT NULL"
            ];
            foreach($snap_defs as $col=>$def){
                if(!in_array($col,$snap_cols,true))$wpdb->query("ALTER TABLE {$snap} ADD COLUMN `{$col}` {$def}");
            }
            // Legacy compatibility: General Leave is informational/non-deducting by default.
            // Admin can still change this through Leave Type configuration.
            $general_id=$wpdb->get_var("SELECT id FROM {$types} WHERE LOWER(name)='general leave' LIMIT 1");
            if($general_id){
                $wpdb->update($types,['deduct_balance'=>0],['id'=>(int)$general_id],['%d'],['%d']);
            }
            $default=$wpdb->get_var("SELECT id FROM {$types} WHERE name='Annual Leave' LIMIT 1");
            if(!$default)$wpdb->insert($types,['name'=>'Annual Leave','active'=>1,'deduct_balance'=>1,'annual_entitlement'=>21],['%s','%d','%d','%f']);
        }
        private function early_leave_month_usage($employee_id,$month){
            global $wpdb;$this->ensure_leave_schema();$t=$wpdb->prefix.'ews_early_leave_requests';
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(leave_minutes),0) FROM {$t} WHERE employee_id=%d AND status IN ('Pending','Approved') AND DATE_FORMAT(work_date,'%%Y-%%m')=%s",$employee_id,$month));
        }
        public function early_leave_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');check_admin_referer('ews_early_leave_create');$emp=$this->current_employee();if(!$emp)$this->leave_redirect(['leave_error'=>'employee']);
            global $wpdb;$this->ensure_leave_schema();$date=sanitize_text_field($_POST['work_date']??'');$minutes=max(1,absint($_POST['leave_minutes']??0));$reason=trim(sanitize_textarea_field($_POST['reason']??''));
            $max=(int)get_option('ews_early_leave_max_minutes',120);$monthly=(int)get_option('ews_early_leave_monthly_minutes',240);$office_only=(int)get_option('ews_early_leave_office_only',1);
            if(!$this->vacation_date_is_future($date))$this->leave_redirect(['early_error'=>'future_date']);
            if(!$this->is_working_day($date))$this->leave_redirect(['early_error'=>'working_day']);
            $scheduled_day=$this->schedule_for_employee_date((int)$emp->id,$date);
            if($office_only && (!$scheduled_day || $scheduled_day->status!=='Office')){
                $this->leave_redirect(['early_error'=>'office_only']);
            }
            if($minutes>$max)$this->leave_redirect(['early_error'=>'max_duration']);
            $month=substr($date,0,7);$used=$this->early_leave_month_usage($emp->id,$month);if($used+$minutes>$monthly)$this->leave_redirect(['early_error'=>'monthly_limit']);
            $ok=$wpdb->insert($wpdb->prefix.'ews_early_leave_requests',['employee_id'=>$emp->id,'work_date'=>$date,'leave_minutes'=>$minutes,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id()],['%d','%s','%d','%s','%s','%d']);
            if($ok===false)$this->leave_redirect(['early_error'=>'save']);$id=$wpdb->insert_id;
            $managers=get_users(['capability'=>'ews_manage_time','fields'=>['ID']]);$msg=$emp->name.' requested Early Leave of '.floor($minutes/60).'h '.($minutes%60).'m on '.$date.'.';
            foreach($managers as $m){if((int)$m->ID===(int)get_current_user_id())continue;$this->notify_user($m->ID,'Early Leave Request',$msg,'early_leave','early_leave',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification($m->ID,'Early Leave Request',$msg,'early_leave',$id);}
            $this->leave_redirect(['leave_sent'=>1]);
        }
        public function early_leave_respond(){
            if(!$this->can('ews_manage_time'))wp_die('Access denied');check_admin_referer('ews_early_leave_respond');global $wpdb;$this->ensure_leave_schema();$id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
            if(!in_array($decision,['approve','reject'],true))wp_die('Invalid decision.');$t=$wpdb->prefix.'ews_early_leave_requests';$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d AND status='Pending'",$id));if(!$r)wp_die('Early Leave request not found.');
            $status=$decision==='approve'?'Approved':'Rejected';
            // Approval must validate the current schedule before changing request state.
            // Otherwise an approval conflict could leave the request marked Approved.
            if($decision==='approve'){
                $office_only=(int)get_option('ews_early_leave_office_only',1);
                $sch=$this->schedule_for_employee_date((int)$r->employee_id,$r->work_date);
                if($office_only && (!$sch || $sch->status!=='Office')){
                    $this->leave_redirect(['early_error'=>'approval_conflict']);
                }
                // Keep the schedule status as Office. Early Leave is an attendance exception
                // and is stored in ews_early_leave_requests, not as a replacement schedule type.
            }
            $wpdb->query('START TRANSACTION');
            $updated=$wpdb->update($t,['status'=>$status,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql')],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($updated!==1){
                $wpdb->query('ROLLBACK');
                $this->leave_redirect(['early_error'=>$updated===false?'save':'approval_conflict']);
            }
            if($wpdb->query('COMMIT')===false){
                $wpdb->query('ROLLBACK');
                $this->leave_redirect(['early_error'=>'save']);
            }
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",(int)$r->employee_id));if($emp&&$emp->wp_user_id){$msg='Your Early Leave request for '.$r->work_date.' has been '.($decision==='approve'?'approved':'rejected').'.';$this->notify_user($emp->wp_user_id,'Early Leave '.ucfirst($decision),$msg,'early_leave','early_leave',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification($emp->wp_user_id,'Early Leave '.ucfirst($decision),$msg,'early_leave',$id);}
            $this->leave_redirect(['leave_done'=>1]);
        }
        private function ensure_vacation_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $c=$wpdb->get_charset_collate(); $table=$wpdb->prefix.'ews_vacation_requests';
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                employee_id BIGINT UNSIGNED NOT NULL,
                start_date DATE NOT NULL, end_date DATE NOT NULL,
                requested_days INT UNSIGNED NOT NULL DEFAULT 0,
                reason TEXT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                requested_by BIGINT UNSIGNED NOT NULL, reviewed_by BIGINT UNSIGNED NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_at DATETIME NULL,
                PRIMARY KEY(id), KEY employee_status(employee_id,status), KEY dates(start_date,end_date), KEY status(status)
            ) {$c};");
        }

        private function vacation_overlap($employee_id,$start,$end){
            global $wpdb;$table=$wpdb->prefix.'ews_vacation_requests';
            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE employee_id=%d AND status IN ('Pending','Approved') AND start_date<=%s AND end_date>=%s LIMIT 1",
                $employee_id,$end,$start
            ));
        }

        private function vacation_redirect($args=[]){
            $url=wp_get_referer();if(!$url||strpos($url,'admin-post.php')!==false)$url=home_url('/');
            $url=remove_query_arg(['vacation_sent','vacation_error','vacation_done','vacation_rejected','leave_sent','leave_error','early_error','break_success','break_error','overtime_sent','overtime_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','vacation',$url);if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);exit;
        }

        public function vacation_request_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_vacation_request_create');
            $emp=$this->current_employee();if(!$emp)$this->vacation_redirect(['vacation_error'=>'employee']);
            $start=sanitize_text_field($_POST['start_date']??'');$end=sanitize_text_field($_POST['end_date']??'');
            $reason=trim(sanitize_textarea_field($_POST['reason']??''));
            if(!$this->vacation_date_is_future($start)||!$this->vacation_date_is_future($end)||$end<$start)$this->vacation_redirect(['vacation_error'=>'date']);
            $days=$this->vacation_working_days_count($start,$end);
            if($days<1)$this->vacation_redirect(['vacation_error'=>'no_working_days']);
            $this->ensure_vacation_schema();
            if($this->vacation_overlap((int)$emp->id,$start,$end))$this->vacation_redirect(['vacation_error'=>'overlap']);
            global $wpdb;$table=$wpdb->prefix.'ews_vacation_requests';
            $ok=$wpdb->insert($table,['employee_id'=>(int)$emp->id,'start_date'=>$start,'end_date'=>$end,'requested_days'=>$days,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'requested_at'=>current_time('mysql')],['%d','%s','%s','%d','%s','%s','%d','%s']);
            if($ok===false)$this->vacation_redirect(['vacation_error'=>'save']);
            $id=(int)$wpdb->insert_id;
            $this->audit('vacation_request','vacation_request',$id,$emp->name.' requested '.$days.' working day(s) from '.$start.' to '.$end);
            $managers=get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]);
            foreach($managers as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $msg=$emp->name.' requested '.$days.' working day(s) from '.$start.' to '.$end.'. Approval is required.';
                $this->notify_user((int)$m->ID,'Vacation Request',$msg,'vacation','vacation',$id);
                if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$m->ID,'Vacation Request',$msg,'vacation',$id);
            }
            $this->vacation_redirect(['vacation_sent'=>$days]);
        }

        public function vacation_request_respond(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');check_admin_referer('ews_vacation_respond');$id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');if(!$id||!in_array($decision,['approve','reject'],true))wp_die('Invalid vacation decision.');$this->ensure_vacation_schema();global $wpdb;$table=$wpdb->prefix.'ews_vacation_requests';$req=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$id));if(!$req)wp_die('Vacation request not found.');if($req->status!=='Pending')$this->vacation_redirect(['vacation_done'=>1]);$emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",(int)$req->employee_id));if(!$emp)$this->vacation_redirect(['vacation_error'=>'employee']);$now=current_time('mysql');if($wpdb->query('START TRANSACTION')===false)$this->vacation_redirect(['vacation_error'=>'save']);$ok=$wpdb->update($table,['status'=>$decision==='approve'?'Approved':'Rejected','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);if($ok!==1){$wpdb->query('ROLLBACK');$this->vacation_redirect(['vacation_error'=>$ok===false?'save':'done']);}if($decision==='approve'){for($ts=strtotime($req->start_date);$ts<=strtotime($req->end_date);$ts=strtotime('+1 day',$ts)){$date=date('Y-m-d',$ts);if(!$this->is_working_day($date))continue;$existing=$this->schedule_for_employee_date((int)$emp->id,$date);if($existing)$q=$wpdb->update($this->schedule,['status'=>'Vacation','note'=>'Approved vacation request #'.$id,'updated_by'=>get_current_user_id(),'updated_at'=>$now],['id'=>(int)$existing->id],['%s','%s','%d','%s'],['%d']);else$q=$wpdb->insert($this->schedule,['employee_id'=>(int)$emp->id,'work_date'=>$date,'status'=>'Vacation','note'=>'Approved vacation request #'.$id,'updated_by'=>get_current_user_id(),'updated_at'=>$now],['%d','%s','%s','%s','%d','%s']);if($q===false){$wpdb->query('ROLLBACK');$this->vacation_redirect(['vacation_error'=>'save']);}}}if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->vacation_redirect(['vacation_error'=>'save']);}$msg=$decision==='approve'?'Your vacation request from '.$req->start_date.' to '.$req->end_date.' ('.$req->requested_days.' working day(s)) has been approved.':'Your vacation request from '.$req->start_date.' to '.$req->end_date.' has been rejected.';$this->audit($decision==='approve'?'vacation_approved':'vacation_rejected','vacation_request',$id,ucfirst($decision).' by '.wp_get_current_user()->display_name);if($emp->wp_user_id){$title=$decision==='approve'?'Vacation Approved':'Vacation Rejected';$this->notify_user((int)$emp->wp_user_id,$title,$msg,'vacation','vacation',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,$title,$msg,'vacation',$id);}$this->vacation_redirect($decision==='approve'?['vacation_done'=>1]:['vacation_rejected'=>1]);
        }

private function ensure_break_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $c=$wpdb->get_charset_collate(); $table=$wpdb->prefix.'ews_break_sessions';
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                employee_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NULL,
                actual_minutes INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Open',
                duration_notified TINYINT(1) NOT NULL DEFAULT 0,
                escalation_notified TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY employee_date(employee_id,work_date),
                KEY status(status),
                KEY start_at(start_at)
            ) {$c};");
        }

        private function break_enabled(){
            return (bool)get_option('ews_feature_breaks',false);
        }

        private function break_per_day(){
            $n=(int)get_option('ews_breaks_per_day',3);
            return max(1,min(20,$n));
        }

        private function break_duration_minutes(){
            $n=(int)get_option('ews_break_duration_minutes',30);
            return max(1,min(480,$n));
        }

        private function break_escalation_minutes(){
            $n=(int)get_option('ews_break_manager_alert_minutes',45);
            return max($this->break_duration_minutes()+1,min(1440,$n));
        }

        private function break_sessions_today($employee_id){
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND work_date=%s ORDER BY id ASC",(int)$employee_id,current_time('Y-m-d')));
        }

        private function break_open_session($employee_id){
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND work_date=%s AND status='Open' ORDER BY id DESC LIMIT 1",(int)$employee_id,current_time('Y-m-d')));
        }

        private function break_remaining($employee_id){
            return max(0,$this->break_per_day()-count($this->break_sessions_today($employee_id)));
        }

        private function break_redirect($args=[]){
            $url=wp_get_referer(); if(!$url||strpos($url,'admin-post.php')!==false)$url=home_url('/');
            $url=remove_query_arg(['break_success','break_error','leave_sent','leave_error','early_error','time_success','time_error','overtime_sent','overtime_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','time',$url);
            if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url); exit;
        }

        private function break_manager_user_ids(){
            $ids=[];
            $users=get_users(['role__in'=>['ews_manager','ews_supervisor','ews_administrator'],'fields'=>['ID']]);
            foreach($users as $u)$ids[]=(int)$u->ID;
            // Native administrators can manage Workforce One too.
            $admins=get_users(['role__in'=>['administrator'],'fields'=>['ID']]);
            foreach($admins as $u)$ids[]=(int)$u->ID;
            return array_values(array_unique(array_filter($ids)));
        }

        public function break_start(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_start');
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id))$this->break_redirect(['break_error'=>rawurlencode('Attendance tracking is disabled for this employee.')]);
            if(!$emp)$this->break_redirect(['break_error'=>rawurlencode('Employee account is not linked.')]);
            $sch=$this->today_schedule_for_employee($emp->id); $ev=$this->today_events($emp->id);
            if(!$sch || !$this->schedule_type_requires_sign_in($sch->status))$this->break_redirect(['break_error'=>rawurlencode('Breaks are available only on a working day.')]);
            if(!isset($ev['sign_in'])&&!isset($ev['late_sign_in']))$this->break_redirect(['break_error'=>rawurlencode('You must Sign In before starting a break.')]);
            if(isset($ev['sign_out']))$this->break_redirect(['break_error'=>rawurlencode('You have already signed out today.')]);
            if($this->break_open_session($emp->id))$this->break_redirect(['break_error'=>rawurlencode('You are already on a break.')]);
            if($this->break_remaining($emp->id)<=0)$this->break_redirect(['break_error'=>rawurlencode('No break sessions remain today.')]);
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions'; $now=current_time('mysql');
            $ok=$wpdb->insert($table,[
                'employee_id'=>(int)$emp->id,'user_id'=>(int)$emp->wp_user_id,'work_date'=>current_time('Y-m-d'),
                'start_at'=>$now,'status'=>'Open','created_at'=>$now
            ],['%d','%d','%s','%s','%s','%s']);
            if(!$ok)$this->break_redirect(['break_error'=>rawurlencode('Unable to start your break. Please try again.')]);
            $id=(int)$wpdb->insert_id;
            $now_utc=current_time('timestamp',true);
            wp_schedule_single_event($now_utc+($this->break_duration_minutes()*60),'ews_break_duration_reminder',[$id]);
            wp_schedule_single_event($now_utc+($this->break_escalation_minutes()*60),'ews_break_manager_escalation',[$id]);
            $this->audit('break_start','break',$id,$emp->name.' / '.$now);
            $this->break_redirect(['break_success'=>rawurlencode('Break started successfully.')]);
        }

        public function break_resume(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_resume');
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id))$this->break_redirect(['break_error'=>rawurlencode('Attendance tracking is disabled for this employee.')]);
            if(!$emp)$this->break_redirect(['break_error'=>rawurlencode('Employee account is not linked.')]);
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $session=$this->break_open_session($emp->id);
            if(!$session)$this->break_redirect(['break_error'=>rawurlencode('No open break was found.')]);
            $now=current_time('mysql');
            $start=strtotime($session->start_at); $end=strtotime($now);
            $minutes=max(0,(int)floor(($end-$start)/60));
            $wpdb->update($table,['end_at'=>$now,'actual_minutes'=>$minutes,'status'=>'Completed'],['id'=>(int)$session->id],['%s','%d','%s'],['%d']);
            $msg='Your break ended. Duration: '.$minutes.' minute'.($minutes===1?'':'s').'.';
            $this->notify_user((int)$emp->wp_user_id,'Break Ended',$msg,'break','break',(int)$session->id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Break Ended',$msg,'break',(int)$session->id,add_query_arg('ews_view','time',home_url('/')));
            $this->audit('break_resume','break',(int)$session->id,$emp->name.' / '.$now.' / '.$minutes.'m');
            $this->break_redirect(['break_success'=>rawurlencode($msg)]);
        }

        public function break_duration_reminder($session_id){
            if(!$this->break_enabled())return;
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",(int)$session_id));
            if(!$s||$s->status!=='Open'||(int)$s->duration_notified===1)return;
            $wpdb->update($table,['duration_notified'=>1],['id'=>(int)$s->id],['%d'],['%d']);
            $msg='Your '.$this->break_duration_minutes().'-minute break has ended. Please resume work.';
            $this->notify_user((int)$s->user_id,'Break Time Ended',$msg,'break','break',(int)$s->id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$s->user_id,'Break Time Ended',$msg,'break',(int)$s->id,add_query_arg('ews_view','time',home_url('/')));
        }

        public function break_manager_escalation($session_id){
            if(!$this->break_enabled())return;
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",(int)$session_id));
            if(!$s||$s->status!=='Open'||(int)$s->escalation_notified===1)return;
            if(!$this->employee_attendance_enabled((int)$s->employee_id))return;
            $wpdb->update($table,['escalation_notified'=>1],['id'=>(int)$s->id],['%d'],['%d']);
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d LIMIT 1",(int)$s->employee_id));
            if(!$emp)return;
            $elapsed=max(0,(int)floor((current_time('timestamp',true)-strtotime(get_gmt_from_date($s->start_at)))/60));
            $msg=$emp->name.' has been on break for '.$elapsed.' minutes and has not resumed work.';
            foreach($this->break_manager_user_ids() as $uid){
                $this->notify_user($uid,'Extended Break Alert',$msg,'break','break',(int)$s->id);
                if(method_exists($this,'push_custom_notification'))$this->push_custom_notification($uid,'Extended Break Alert',$msg,'break',(int)$s->id,add_query_arg('ews_view','time',home_url('/')));
            }
        }

        private function break_ui_data($employee_id){
            $sessions=$this->break_sessions_today($employee_id);
            $open=null; foreach($sessions as $s){if($s->status==='Open'){$open=$s;break;}}
            return ['sessions'=>$sessions,'open'=>$open,'remaining'=>max(0,$this->break_per_day()-count($sessions)),
                'allowed'=>$this->break_per_day(),'duration'=>$this->break_duration_minutes(),'escalation'=>$this->break_escalation_minutes()];
        }

        private function ensure_overtime_schema(){
        if($this->ews_schema_is_current())return;
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $c=$wpdb->get_charset_collate(); $table=$wpdb->prefix.'ews_overtime_requests';
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                employee_id BIGINT UNSIGNED NOT NULL,
                overtime_date DATE NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                requested_minutes INT UNSIGNED NOT NULL DEFAULT 0,
                reason TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'Pending',
                requested_by BIGINT UNSIGNED NOT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                PRIMARY KEY(id),
                KEY employee_status(employee_id,status),
                KEY overtime_date(overtime_date),
                KEY status(status)
            ) {$c};");
        }

        private function overtime_request_minutes($start,$end){
            $a=strtotime('1970-01-01 '.$start);$b=strtotime('1970-01-01 '.$end);
            if(!$a||!$b||$b<=$a)return 0;
            return (int)round(($b-$a)/60);
        }

        private function overtime_date_is_future($date){
            return preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) && $date>=current_time('Y-m-d');
        }

        private function overtime_overlap($employee_id,$date,$start,$end){
            global $wpdb;$table=$wpdb->prefix.'ews_overtime_requests';
            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE employee_id=%d AND overtime_date=%s AND status IN ('Pending','Approved') AND start_time<%s AND end_time>%s LIMIT 1",
                $employee_id,$date,$end,$start
            ));
        }

        private function overtime_redirect($args=[]){
            $url=wp_get_referer();if(!$url||strpos($url,'admin-post.php')!==false)$url=home_url('/');
            $url=remove_query_arg(['overtime_sent','overtime_error','leave_sent','leave_error','early_error','break_success','break_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','overtime',$url);if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);exit;
        }

        public function overtime_request_respond(){
            if(!$this->overtime_enabled())$this->overtime_redirect(['overtime_error'=>'disabled']);
            check_admin_referer('ews_overtime_respond');
            $id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
            if(!$id||!in_array($decision,['approve','reject'],true))$this->overtime_redirect(['overtime_error'=>'invalid']);
            $this->ensure_overtime_schema();global $wpdb;$table=$wpdb->prefix.'ews_overtime_requests';
            $req=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",$id));
            if(!$req)$this->overtime_redirect(['overtime_error'=>'not_found']);
            if($req->status!=='Pending')$this->overtime_redirect(['overtime_done'=>1]);
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",(int)$req->employee_id));
            if(!$emp)$this->overtime_redirect(['overtime_error'=>'employee']);

            $workflow=$this->approval_workflow('overtime');
            $approval_active=$workflow && (int)$workflow->active===1 && in_array(strtoupper((string)$workflow->approval_mode),['LEVEL_1','LEVEL_2'],true);
            if($approval_active){
                $ar=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$ar)$this->overtime_redirect(['overtime_error'=>'approval']);
                if(!$this->approval_can_act((int)$ar->id))$this->overtime_redirect(['overtime_error'=>'access']);
                global $wpdb;
                if($wpdb->query('START TRANSACTION')===false)$this->overtime_redirect(['overtime_error'=>'save']);
                $acted=$this->approval_act((int)$ar->id,$decision,'',false);
                if(is_wp_error($acted)){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'approval']);}
                $fresh=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$fresh){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'approval']);}
                $now=current_time('mysql');
                if($fresh->status==='REJECTED'){
                    $ok=$wpdb->update($table,['status'=>'Rejected','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
                    if($ok!==1){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>$ok===false?'save':'done']);}
                }elseif($fresh->status==='APPROVED'){
                    $ok=$wpdb->update($table,['status'=>'Approved','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
                    if($ok!==1){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>$ok===false?'save':'done']);}
                }
                if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'save']);}

                if($fresh->status==='APPROVED' || $fresh->status==='REJECTED'){
                    $approved=$fresh->status==='APPROVED';
                    $hours=floor((int)$req->requested_minutes/60);$mins=(int)$req->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');
                    $msg=$approved?'Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.', '.$duration.') has been approved.':'Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.') has been rejected.';
                    $this->audit($approved?'overtime_approved':'overtime_rejected','overtime_request',$id,($approved?'Approved':'Rejected').' by '.wp_get_current_user()->display_name);
                    if($emp->wp_user_id){$title=$approved?'Overtime Approved':'Overtime Rejected';$this->notify_user((int)$emp->wp_user_id,$title,$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,$title,$msg,'overtime',$id);}
                    $this->overtime_redirect($approved?['overtime_done'=>1]:['overtime_rejected'=>1]);
                }

                $next=$this->approval_current_step((int)$fresh->id);
                if($next && $next->approver_wp_user_id){
                    $msg=$emp->name.' overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.') is awaiting your Level '.(int)$next->step_order.' approval.';
                    $this->notify_user((int)$next->approver_wp_user_id,'Overtime Approval Required',$msg,'overtime','overtime',$id);
                    if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$next->approver_wp_user_id,'Overtime Approval Required',$msg,'overtime',$id);
                }
                $this->overtime_redirect(['overtime_done'=>1]);
            }

            if(!$this->can('ews_manage_time'))$this->overtime_redirect(['overtime_error'=>'access']);
            $now=current_time('mysql');
            if($decision==='approve'){
                $ok=$wpdb->update($table,['status'=>'Approved','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
                if($ok!==1)$this->overtime_redirect(['overtime_error'=>$ok===false?'save':'done']);
                $hours=floor((int)$req->requested_minutes/60);$mins=(int)$req->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$msg='Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.', '.$duration.') has been approved.';
                $this->audit('overtime_approved','overtime_request',$id,'Approved by '.wp_get_current_user()->display_name);
                if($emp->wp_user_id){$this->notify_user((int)$emp->wp_user_id,'Overtime Approved',$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Overtime Approved',$msg,'overtime',$id);}
                $this->overtime_redirect(['overtime_done'=>1]);
            }
            $ok=$wpdb->update($table,['status'=>'Rejected','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($ok!==1)$this->overtime_redirect(['overtime_error'=>$ok===false?'save':'done']);
            $msg='Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.') has been rejected.';$this->audit('overtime_rejected','overtime_request',$id,'Rejected by '.wp_get_current_user()->display_name);
            if($emp->wp_user_id){$this->notify_user((int)$emp->wp_user_id,'Overtime Rejected',$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Overtime Rejected',$msg,'overtime','overtime',$id);}
            $this->overtime_redirect(['overtime_rejected'=>1]);
        }

        public function overtime_request_create(){
            if(!$this->overtime_enabled())$this->overtime_redirect(['overtime_error'=>'disabled']);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_overtime_request_create');
            $emp=$this->current_employee();if(!$emp)$this->overtime_redirect(['overtime_error'=>'employee']);
            $date=sanitize_text_field($_POST['overtime_date']??'');$start=sanitize_text_field($_POST['start_time']??'');$end=sanitize_text_field($_POST['end_time']??'');$reason=trim(sanitize_textarea_field($_POST['reason']??''));
            if(!$this->overtime_date_is_future($date))$this->overtime_redirect(['overtime_error'=>'date']);
            if(!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end))$this->overtime_redirect(['overtime_error'=>'time']);
            $minutes=$this->overtime_request_minutes($start,$end);if($minutes<1)$this->overtime_redirect(['overtime_error'=>'time']);if(!$reason)$this->overtime_redirect(['overtime_error'=>'reason']);
            $this->ensure_overtime_schema();if($this->overtime_overlap((int)$emp->id,$date,$start,$end))$this->overtime_redirect(['overtime_error'=>'overlap']);
            global $wpdb;$table=$wpdb->prefix.'ews_overtime_requests';$now=current_time('mysql');
            $workflow=$this->approval_workflow('overtime');
            $approval_active=$workflow && (int)$workflow->active===1 && in_array(strtoupper((string)$workflow->approval_mode),['NONE','LEVEL_1','LEVEL_2'],true);
            if($approval_active){
                if($wpdb->query('START TRANSACTION')===false)$this->overtime_redirect(['overtime_error'=>'save']);
                $ok=$wpdb->insert($table,['employee_id'=>(int)$emp->id,'overtime_date'=>$date,'start_time'=>$start,'end_time'=>$end,'requested_minutes'=>$minutes,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'requested_at'=>$now],['%d','%s','%s','%s','%d','%s','%s','%d','%s']);
                if($ok===false){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'save']);}
                $id=(int)$wpdb->insert_id;
                $approval=$this->approval_start('overtime','overtime_request',$id,(int)$emp->id,[],false);
                if(is_wp_error($approval)){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'approval']);}
                $ar=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$ar){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'approval']);}
                if($ar->status==='APPROVED'){
                    $ok=$wpdb->update($table,['status'=>'Approved','reviewed_by'=>get_current_user_id(),'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
                    if($ok!==1){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>$ok===false?'save':'done']);}
                }
                if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>'save']);}
                $this->audit('overtime_request','overtime_request',$id,$emp->name.' requested '.round($minutes/60,2).' overtime hour(s) on '.$date);
                if($ar->status==='APPROVED'){
                    $msg='Your overtime request for '.$date.' ('.$start.'–'.$end.') has been approved.';if($emp->wp_user_id){$this->notify_user((int)$emp->wp_user_id,'Overtime Approved',$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Overtime Approved',$msg,'overtime',$id);}
                }else{
                    $step=$this->approval_current_step((int)$ar->id);if($step&&$step->approver_wp_user_id){$msg=$emp->name.' requested '.round($minutes/60,2).' hour(s) of overtime on '.$date.' ('.$start.'–'.$end.'). It is awaiting your Level '.(int)$step->step_order.' approval.';$this->notify_user((int)$step->approver_wp_user_id,'Overtime Approval Required',$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$step->approver_wp_user_id,'Overtime Approval Required',$msg,'overtime',$id);}}
                $this->overtime_redirect(['overtime_sent'=>round($minutes/60,2)]);
            }
            $ok=$wpdb->insert($table,['employee_id'=>(int)$emp->id,'overtime_date'=>$date,'start_time'=>$start,'end_time'=>$end,'requested_minutes'=>$minutes,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'requested_at'=>$now],['%d','%s','%s','%s','%d','%s','%s','%d','%s']);
            if($ok===false)wp_die('Could not submit overtime request. '.esc_html($wpdb->last_error));
            $id=(int)$wpdb->insert_id;$this->audit('overtime_request','overtime_request',$id,$emp->name.' requested '.round($minutes/60,2).' overtime hour(s) on '.$date);
            $managers=get_users(['capability'=>'ews_manage_time','fields'=>['ID']]);foreach($managers as $m){if((int)$m->ID===(int)get_current_user_id())continue;$msg=$emp->name.' requested '.round($minutes/60,2).' hour(s) of overtime on '.$date.' ('.$start.'–'.$end.'). Approval is required.';$this->notify_user((int)$m->ID,'Overtime Request',$msg,'overtime','overtime',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$m->ID,'Overtime Request',$msg,'overtime','overtime',$id);}
            $this->overtime_redirect(['overtime_sent'=>round($minutes/60,2)]);
        }

        private function overtime_enabled(){
            return (bool)get_option('ews_feature_overtime',false);
        }

        private function ews_schema_target(){ return '3.28.2'; }

        /*
         * True once maybe_upgrade_schema() has completed for the current schema
         * target. Every ensure_*_schema() routine returns early when this is true,
         * so request paths never run SHOW TABLES / SHOW COLUMNS / dbDelta.
         * To ship a schema change: update the ensure_* routine and bump
         * ews_schema_target().
         */
        private function ews_schema_is_current(){
            return get_option('ews_schema_version','')===$this->ews_schema_target();
        }

        private function maybe_upgrade_schema(){
            $target=$this->ews_schema_target();
            $done=get_option('ews_schema_version','');
            if($done===$target)return;
            // The base tables come from activate(). If this runs before activation has
            // created them (e.g. WP-CLI loads the plugin before the activation hook), create
            // them now; otherwise the column upgrades below would be skipped yet marked done.
            global $wpdb;
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->employees))!==$this->employees)self::activate();

            /* Existing schema routines are retained; they now run only once
               when the plugin schema version changes. */
            $this->ensure_time_feature_schema();
            $this->ensure_schedule_config_schema();
            $this->ensure_employee_email_column();
            $this->ensure_employee_profile_schema();
            $this->ensure_employee_attendance_schema();
            $this->ensure_roles_permissions();
            $this->ensure_vacation_schema();
            $this->ensure_overtime_schema();
            $this->ensure_break_schema();
            $this->ensure_tasks_schema();
            $this->ensure_notifications_schema(true);
            $this->ensure_approval_schema();
            $this->ensure_teams_schema();
            $this->ensure_departments_schema();
            $this->ensure_auto_attendance_schema();
            $this->ensure_achievements_schema();
            $this->ensure_polls_schema();
            $this->ensure_swap_schema();
            $this->ensure_leave_schema();
            $this->ensure_push_schema();
            $this->ensure_recognition_schema();
            $this->ews_v321_ensure_locations_table();
            $this->ews_v321_ensure_employee_map();
            if(get_option('ews_feature_tasks',null)===null)update_option('ews_feature_tasks',false,false);
            if(get_option('ews_presence_qr_signin',null)===null)update_option('ews_presence_qr_signin',false,false);
            if(get_option('ews_presence_verification',null)===null)update_option('ews_presence_verification',false,false);
            if(method_exists($this,'ensure_presence_schema'))$this->ensure_presence_schema();
            update_option('ews_schema_version',$target,false);
        }

function current_employee(){
            if(!is_user_logged_in())return null;
            global $wpdb;
            $uid=get_current_user_id();
            if(!$uid)return null;
            if(array_key_exists($uid,self::$ews_current_employee_cache))return self::$ews_current_employee_cache[$uid];
            self::$ews_current_employee_cache[$uid]=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d AND active=1 LIMIT 1",$uid));
            return self::$ews_current_employee_cache[$uid];
        }

    private function profile_avatar_catalog(){
        return [
            'man-beard'=>['label'=>'Professional Beard','category'=>'Men','style'=>'Professional'],
            'man-short'=>['label'=>'Short Hair','category'=>'Men','style'=>'Casual'],
            'man-glasses'=>['label'=>'Glasses','category'=>'Men','style'=>'Professional'],
            'man-curly'=>['label'=>'Curly Hair','category'=>'Men','style'=>'Casual'],
            'man-hoodie'=>['label'=>'Hoodie','category'=>'Men','style'=>'Casual'],
            'man-cap'=>['label'=>'Cap','category'=>'Men','style'=>'Casual'],
            'man-tie'=>['label'=>'Business','category'=>'Men','style'=>'Professional'],
            'man-headphones'=>['label'=>'Headphones','category'=>'Men','style'=>'Fun'],
            'woman-long'=>['label'=>'Long Hair','category'=>'Women','style'=>'Professional'],
            'woman-glasses'=>['label'=>'Glasses','category'=>'Women','style'=>'Professional'],
            'woman-hijab'=>['label'=>'Hijab','category'=>'Women','style'=>'Professional'],
            'woman-curly'=>['label'=>'Curly Hair','category'=>'Women','style'=>'Casual'],
            'woman-bob'=>['label'=>'Bob Hair','category'=>'Women','style'=>'Casual'],
            'woman-cap'=>['label'=>'Cap','category'=>'Women','style'=>'Casual'],
            'woman-smile'=>['label'=>'Smile','category'=>'Women','style'=>'Casual'],
            'woman-headphones'=>['label'=>'Headphones','category'=>'Women','style'=>'Fun'],
            'cat'=>['label'=>'Cat','category'=>'Fun','style'=>'Fun'],
            'dog'=>['label'=>'Dog','category'=>'Fun','style'=>'Fun'],
            'panda'=>['label'=>'Panda','category'=>'Fun','style'=>'Fun'],
            'robot'=>['label'=>'Robot','category'=>'Fun','style'=>'Fun'],
            'ninja'=>['label'=>'Ninja','category'=>'Fun','style'=>'Fun'],
            'astronaut'=>['label'=>'Astronaut','category'=>'Fun','style'=>'Fun'],
            'gamer'=>['label'=>'Gamer','category'=>'Fun','style'=>'Casual'],
            'traveler'=>['label'=>'Traveler','category'=>'Fun','style'=>'Casual'],
        ];
    }
    private function profile_avatar_url($key){
        $key=sanitize_key($key); $catalog=$this->profile_avatar_catalog();
        if(!isset($catalog[$key]))return '';
        return plugins_url('assets/avatars/'.$key.'.svg',dirname(__DIR__).'/employee-schedule-manager.php');
    }

    public function profile_password_change(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'ews_profile_password_change'))wp_die('Security check failed.');
            $emp=$this->current_employee();
            if(!$emp)wp_die('Employee account not found.');
            $user_id=get_current_user_id();
            $user=get_user_by('id',$user_id);
            if(!$user)wp_die('User account not found.');
            $current=(string)wp_unslash($_POST['current_password']??'');
            $new=(string)wp_unslash($_POST['new_password']??'');
            $confirm=(string)wp_unslash($_POST['confirm_password']??'');
            $redirect=remove_query_arg(['password_updated','password_error'],wp_get_referer()?:home_url('/'));
            $redirect=add_query_arg('ews_view','profile',$redirect);
            if(!wp_check_password($current,$user->user_pass,$user_id)){
                wp_safe_redirect(add_query_arg('password_error','current',$redirect));exit;
            }
            if($new!==$confirm){
                wp_safe_redirect(add_query_arg('password_error','mismatch',$redirect));exit;
            }
            $strong=(strlen($new)>=8 && preg_match('/[A-Z]/',$new) && preg_match('/[a-z]/',$new) && preg_match('/\d/',$new) && preg_match('/[^A-Za-z0-9]/',$new));
            if(!$strong){
                wp_safe_redirect(add_query_arg('password_error','weak',$redirect));exit;
            }
            if(wp_check_password($new,$user->user_pass,$user_id)){
                wp_safe_redirect(add_query_arg('password_error','same',$redirect));exit;
            }
            $updated=wp_update_user(['ID'=>$user_id,'user_pass'=>$new]);
            if(is_wp_error($updated)){
                wp_safe_redirect(add_query_arg('password_error','update',$redirect));exit;
            }
            $this->audit('profile_password_change','employee',(int)$emp->id,$emp->name.' changed account password');
            wp_safe_redirect(add_query_arg('password_updated','1',$redirect));exit;
        }

    public function profile_photo_save(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'ews_profile_photo_save'))wp_die('Security check failed.');
            $emp=$this->current_employee();
            if(!$emp)wp_die('Employee account not found.');
            global $wpdb;
            $table=$this->employees;
            $action=sanitize_key($_POST['profile_photo_action']??'upload');
            $old_url=(string)($emp->profile_image_url??'');
            if($action==='avatar') {
                $key=sanitize_key($_POST['avatar_key']??''); $catalog=$this->profile_avatar_catalog();
                if(!isset($catalog[$key])) wp_die('Invalid avatar selection.');
                $url=$this->profile_avatar_url($key);
                $ok=$wpdb->update($table,['profile_image_type'=>'avatar','profile_image_url'=>$url,'avatar_key'=>$key],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
                if($ok===false) wp_die('Could not save the avatar.');
                $this->delete_employee_profile_image_file($old_url);
                $this->audit('profile_avatar_update','employee',(int)$emp->id,$emp->name.' selected avatar '.$key);
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'avatar'],$redirect)); exit;
            }
            if($action==='reset'){
                $ok=$wpdb->update($table,['profile_image_type'=>'initials','profile_image_url'=>'','avatar_key'=>null],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
                if($ok===false)wp_die('Could not reset the profile picture.');
                $this->delete_employee_profile_image_file($old_url);
                $this->audit('profile_photo_reset','employee',(int)$emp->id,$emp->name.' reset profile picture');
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'reset'],$redirect));exit;
            }
            if(empty($_FILES['profile_photo'])||!empty($_FILES['profile_photo']['error'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
            }
            $file=$_FILES['profile_photo'];
            if((int)$file['size']>2*1024*1024){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'size'],$redirect));exit;
            }
            $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);
            if(empty($check['type'])||empty($check['ext'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'type'],$redirect));exit;
            }
            $img=@getimagesize($file['tmp_name']);
            if(!$img||empty($img['mime'])||!in_array($img['mime'],['image/jpeg','image/png','image/webp'],true)){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'type'],$redirect));exit;
            }
            require_once ABSPATH.'wp-admin/includes/file.php';
            $overrides=['test_form'=>false,'mimes'=>['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']];
            $uploaded=wp_handle_upload($file,$overrides);
            if(isset($uploaded['error'])||empty($uploaded['url'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
            }
            $editor=wp_get_image_editor($uploaded['file']);
            if(!is_wp_error($editor)){
                $size=$editor->get_size();
                if(!empty($size['width'])&&!empty($size['height'])&&($size['width']>512||$size['height']>512)){
                    $editor->resize(512,512,false);
                    $saved=$editor->save($uploaded['file']);
                    if(is_wp_error($saved)){
                        @unlink($uploaded['file']);
                        $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
                    }
                }
            }
            $url=esc_url_raw($uploaded['url']);
            $ok=$wpdb->update($table,['profile_image_type'=>'photo','profile_image_url'=>$url,'avatar_key'=>null],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
            if($ok===false){
                $this->delete_employee_profile_image_file($url);
                wp_die('Could not save the profile picture.');
            }
            $this->delete_employee_profile_image_file($old_url);
            $this->audit('profile_photo_update','employee',(int)$emp->id,$emp->name.' updated profile picture');
            $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
            wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'photo'],$redirect));exit;
        }

        private function delete_employee_profile_image_file($url){
            $url=trim((string)$url); if($url==='')return;
            $uploads=wp_upload_dir();
            if(empty($uploads['baseurl'])||empty($uploads['basedir']))return;
            $base=rtrim((string)$uploads['baseurl'],'/');
            if(strpos($url,$base.'/')!==0)return;
            $relative=ltrim(substr($url,strlen($base)),'/');
            if($relative==='')return;
            $path=wp_normalize_path(trailingslashit($uploads['basedir']).$relative);
            $root=wp_normalize_path(trailingslashit($uploads['basedir']));
            if(strpos($path,$root)!==0)return;
            if(is_file($path))@unlink($path);
        }

    private function schedule_for_employee_date($eid,$date){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$eid,$date));
        }

    private function swap_employee_user_id($employee_id){
            global $wpdb;
            return (int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",(int)$employee_id));
        }

        private function swap_redirect($args=[]){
            $url=wp_get_referer();
            if(!$url || strpos($url,'admin-post.php')!==false)$url=home_url('/');
            $url=remove_query_arg(['action','swap_error','swap_sent','swap_done','leave_error','leave_sent','leave_done','leave_cancel_sent','early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','vacation_sent','vacation_error','vacation_done','vacation_rejected','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','schedule',$url);
            if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);
            exit;
        }

        public function swap_request_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_swap_create');
            $requester=$this->current_employee();
            if(!$requester)$this->swap_redirect(['swap_error'=>'employee']);
            $target=absint($_POST['target_employee_id']??0);
            $date=sanitize_text_field($_POST['work_date']??'');
            if(!$target||$target===(int)$requester->id||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$this->swap_redirect(['swap_error'=>'invalid_request']);
            global $wpdb;$table=$this->ensure_swap_schema();
            $target_emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$target));
            if(!$target_emp || !$this->department_scope_allows_employee($target))$this->swap_redirect(['swap_error'=>'employee']);
            if(!$target_emp)$this->swap_redirect(['swap_error'=>'employee']);
            $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$requester->id,$date));
            $b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$target,$date));
            if(!$a||!$b||!in_array($a->status,['Office','WFH'],true)||!in_array($b->status,['Office','WFH'],true)||$a->status===$b->status)$this->swap_redirect(['swap_error'=>'not_swappable']);
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE work_date=%s AND status='Pending' AND ((requester_employee_id=%d AND target_employee_id=%d) OR (requester_employee_id=%d AND target_employee_id=%d))",$date,$requester->id,$target,$target,$requester->id));
            if($exists)$this->swap_redirect(['swap_error'=>'pending']);
            $ok=$wpdb->insert($table,[
                'requester_employee_id'=>(int)$requester->id,'target_employee_id'=>$target,'work_date'=>$date,
                'requester_status'=>$a->status,'target_status'=>$b->status,'status'=>'Pending',
                'requested_by'=>get_current_user_id(),'created_at'=>current_time('mysql')
            ],['%d','%d','%s','%s','%s','%s','%d','%s']);
            if(!$ok)$this->swap_redirect(['swap_error'=>'save']);
            $id=(int)$wpdb->insert_id;
            $this->audit('SCHEDULE_SWAP_REQUESTED','schedule_swap',$id,sprintf('%s requested a swap with %s for %s: %s -> %s; %s -> %s',$requester->name,$target_emp->name,date_i18n('l, d M Y',strtotime($date)),$requester->name,$b->status,$target_emp->name,$a->status));
            $msg=sprintf('%s requested to swap %s: %s ↔ %s.', $requester->name,date_i18n('l, d M',strtotime($date)),$a->status,$b->status);
            $note=$this->notify_user($this->swap_employee_user_id($target),'Schedule Swap Request',$msg,'info','swap',$id);
            $this->push_custom_notification($this->swap_employee_user_id($target),'Schedule Swap Request',$msg,'swap',$id);
            $this->swap_redirect(['swap_sent'=>1]);
        }

        public function swap_request_respond(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            $id=absint($_POST['swap_id']??0);$decision=sanitize_key($_POST['decision']??'');
            check_admin_referer('ews_swap_respond_'.$id);
            $emp=$this->current_employee();if(!$emp||!in_array($decision,['accept','reject'],true))wp_die('Invalid swap request.');
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND target_employee_id=%d AND status='Pending' LIMIT 1",$id,$emp->id));
            if(!$r)$this->swap_redirect(['swap_error'=>'not_found']);
            if($decision==='reject'){
                $ok=$wpdb->update($table,['status'=>'Rejected','responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>$id],['%s','%d','%s'],['%d']);
                if($ok!==1)$this->swap_redirect(['swap_error'=>$ok===false?'save':'expired']);
                $this->audit('SCHEDULE_SWAP_REJECTED','schedule_swap',$id,sprintf('Swap rejected by %s for %s: %s ↔ %s', $emp->name,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_status,$r->target_status));
                $req_uid=$this->swap_employee_user_id($r->requester_employee_id);
                $msg=sprintf('%s rejected your schedule swap for %s.', $emp->name,date_i18n('l, d M',strtotime($r->work_date)));
                $this->notify_user($req_uid,'Schedule Swap Rejected',$msg,'warning','swap',$id);
                $this->push_custom_notification($req_uid,'Schedule Swap Rejected',$msg,'swap',$id);
                $this->swap_redirect(['swap_done'=>'rejected']);
            }
            $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$r->requester_employee_id,$r->work_date));
            $b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$r->target_employee_id,$r->work_date));
            if(!$a||!$b||$a->status!==$r->requester_status||$b->status!==$r->target_status)$this->swap_redirect(['swap_error'=>'changed']);
            if($wpdb->query('START TRANSACTION')===false)$this->swap_redirect(['swap_error'=>'save']);
            $ok1=$wpdb->update($this->schedule,['status'=>$r->target_status,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')],['id'=>$a->id],['%s','%d','%s'],['%d']);
            $ok2=$wpdb->update($this->schedule,['status'=>$r->requester_status,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')],['id'=>$b->id],['%s','%d','%s'],['%d']);
            if($ok1===false||$ok2===false){$wpdb->query('ROLLBACK');$this->swap_redirect(['swap_error'=>'save']);}
            $updated=$wpdb->update($table,['status'=>'Accepted','responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($updated!==1){$wpdb->query('ROLLBACK');$this->swap_redirect(['swap_error'=>'expired']);}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->swap_redirect(['swap_error'=>'save']);}
            $this->achievement_evaluate_swap_acceptance((int)$r->target_employee_id,(int)$r->requester_employee_id,(int)$id);
            $this->audit('SCHEDULE_SWAP_ACCEPTED','schedule_swap',$id,sprintf('Swap accepted by %s for %s: requester %s %s -> %s; target %s %s -> %s', $emp->name,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_employee_id,$r->requester_status,$r->target_status,$r->target_employee_id,$r->target_status,$r->requester_status));
            $req_uid=$this->swap_employee_user_id($r->requester_employee_id);
            $msg=sprintf('Your schedule swap for %s with %s is complete: %s ↔ %s.',date_i18n('l, d M',strtotime($r->work_date)),$emp->name,$r->requester_status,$r->target_status);
            $this->notify_user($req_uid,'Schedule Swap Completed',$msg,'success','swap',$id);
            $this->notify_user(get_current_user_id(),'Schedule Swap Completed','Your schedule has been updated after the swap.','success','swap',$id);
            $this->push_custom_notification($req_uid,'Schedule Swap Completed',$msg,'swap',$id);
            $this->push_custom_notification(get_current_user_id(),'Schedule Swap Completed','Your schedule has been updated after the swap.','swap',$id);
            $this->swap_redirect(['swap_done'=>'accepted']);
        }

        public function swap_request_cancel(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            $id=absint($_POST['swap_id']??0);check_admin_referer('ews_swap_cancel_'.$id);
            $emp=$this->current_employee();if(!$emp)$this->break_redirect(['break_error'=>rawurlencode('Employee account is not linked.')]);
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND requester_employee_id=%d AND status='Pending' LIMIT 1",$id,$emp->id));
            if($r){
                $ok=$wpdb->update($table,['status'=>'Cancelled','responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>$id],['%s','%d','%s'],['%d']);
                if($ok!==1)$this->swap_redirect(['swap_error'=>$ok===false?'save':'expired']);
                $this->audit('SCHEDULE_SWAP_CANCELLED','schedule_swap',$id,sprintf('Swap cancelled by %s for %s: %s ↔ %s', $emp->name,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_status,$r->target_status));
            }
            $this->swap_redirect(['swap_done'=>'cancelled']);
        }

        private function swap_requests_for_user($emp_id,$week_start=null,$week_end=null){
            global $wpdb;$table=$this->ensure_swap_schema();
            $where="(s.requester_employee_id=%d OR s.target_employee_id=%d)";
            $args=[$emp_id,$emp_id];
            if($week_start && $week_end){
                $where.=" AND s.work_date BETWEEN %s AND %s";
                $args[]=$week_start;$args[]=$week_end;
            }
            $sql="SELECT s.*,a.name requester_name,b.name target_name
                  FROM {$table} s
                  LEFT JOIN {$this->employees} a ON a.id=s.requester_employee_id
                  LEFT JOIN {$this->employees} b ON b.id=s.target_employee_id
                  WHERE {$where}
                  ORDER BY s.work_date DESC,s.id DESC
                  LIMIT 50";
            return $wpdb->get_results($wpdb->prepare($sql,...$args));
        }

    private function overtime_attendance_summary($eid,$date){
            if(!$this->overtime_enabled())return ['approved_minutes'=>0,'actual_approved_minutes'=>0,'unapproved_extra_minutes'=>0,'approved_requests'=>[]];
            global $wpdb;
            $table=$wpdb->prefix.'ews_overtime_requests';
            $requests=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND overtime_date=%s AND status='Approved' ORDER BY start_time ASC",$eid,$date));
            $events=$wpdb->get_results($wpdb->prepare("SELECT event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY event_at ASC",$eid,$date));
            $sign_in=null;$sign_out=null;
            foreach($events as $ev){
                if(($ev->event_type==='sign_in'||$ev->event_type==='late_sign_in') && !$sign_in)$sign_in=$ev->event_at;
                if($ev->event_type==='sign_out' && !$sign_out)$sign_out=$ev->event_at;
            }
            $cfg=$this->working_hours();
            $scheduled_start=strtotime($date.' '.$cfg['start']);
            $scheduled_end=strtotime($date.' '.$cfg['end']);
            $in_ts=$sign_in?strtotime($sign_in):null;
            $out_ts=$sign_out?strtotime($sign_out):null;
            $approved=0;$actual=0;$details=[];
            foreach($requests as $r){
                $rs=strtotime($date.' '.$r->start_time);
                $re=strtotime($date.' '.$r->end_time);
                if($re<=$rs)continue;

                // Approved duration is the approved request itself. It is not limited
                // by scheduled working hours; pre-shift and post-shift OT are both valid.
                $mins=(int)floor(($re-$rs)/60);
                $approved+=$mins;

                // Actual OT must be backed by attendance:
                // pre-shift OT -> actual Sign In; post-shift OT -> actual Sign Out.
                $real=0;
                if($rs < $scheduled_start){
                    if($in_ts){
                        $actual_start=max($rs,$in_ts);
                        $actual_end=min($re,$scheduled_start);
                        if($actual_end>$actual_start)$real=(int)floor(($actual_end-$actual_start)/60);
                    }
                    // If the same approved request crosses the scheduled start,
                    // count the post-shift part from Sign Out as well.
                    if($re>$scheduled_end && $out_ts){
                        $actual_start=max($scheduled_end,$rs);
                        $actual_end=min($re,$out_ts);
                        if($actual_end>$actual_start)$real+=(int)floor(($actual_end-$actual_start)/60);
                    }
                } elseif($rs >= $scheduled_end){
                    if($out_ts){
                        $actual_start=max($rs,$scheduled_end);
                        $actual_end=min($re,$out_ts);
                        if($actual_end>$actual_start)$real=(int)floor(($actual_end-$actual_start)/60);
                    }
                } else {
                    // Request overlaps regular hours and extends after shift.
                    if($out_ts && $re>$scheduled_end){
                        $actual_start=$scheduled_end;
                        $actual_end=min($re,$out_ts);
                        if($actual_end>$actual_start)$real=(int)floor(($actual_end-$actual_start)/60);
                    }
                }
                $actual+=$real;
                $details[]=['id'=>(int)$r->id,'date'=>$date,'start'=>$r->start_time,'end'=>$r->end_time,'approved_minutes'=>$mins,'actual_minutes'=>$real];
            }

            // Unapproved extra presence is only time after the scheduled end that
            // is not covered by an approved OT window. Pre-shift attendance is
            // intentionally not converted into OT here.
            $unapproved=0;
            if($out_ts && $out_ts>$scheduled_end){
                $presence=(int)floor(($out_ts-$scheduled_end)/60);
                $approved_post=0;
                foreach($details as $d){
                    $rs=strtotime($date.' '.$d['start']);$re=strtotime($date.' '.$d['end']);
                    $post_start=max($rs,$scheduled_end);
                    if($re>$post_start)$approved_post+=(int)floor(($re-$post_start)/60);
                }
                $unapproved=max(0,$presence-min($presence,$approved_post));
            }
            return ['approved_minutes'=>$approved,'actual_approved_minutes'=>$actual,'unapproved_extra_minutes'=>$unapproved,'approved_requests'=>$details,'sign_in'=>$sign_in,'sign_out'=>$sign_out,'scheduled_start'=>$cfg['start'],'scheduled_end'=>$cfg['end']];
        }

        private function format_duration_minutes($minutes){
            $minutes=max(0,(int)$minutes);$h=floor($minutes/60);$m=$minutes%60;
            if(!$h)return $m.' min';
            return $h.($h===1?' hour':' hours').($m?' '.$m.' min':'');
        }

    private function today_schedule_for_employee($eid){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$eid,current_time('Y-m-d')));
        }

    private function today_events($eid){
            global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s ORDER BY event_at ASC",$eid,current_time('Y-m-d')));
            $o=[];foreach($rows as $r)$o[$r->event_type]=$r;return $o;
        }

    private function time_event_label($type){return $type==='sign_out'?'Sign Out':'Sign In';}

    /** URL of a file inside the plugin, e.g. plugin_url('assets/js/time.js'). Safe to call from templates. */
    private function plugin_url($path=''){
            return plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').ltrim($path,'/');
        }

    /**
     * Render templates/<name>.php with $vars as local variables and return the HTML.
     * Templates run inside this object, so they can call its helpers via $this.
     */
    private function render_template($name,array $vars=[]){
            $file=dirname(__DIR__).'/templates/'.$name.'.php';
            if(!is_file($file))return '';
            extract($vars,EXTR_SKIP);
            ob_start();
            include $file;
            return ob_get_clean();
        }

    private function location_distance_meters($lat1,$lon1,$lat2,$lon2){
            return \WorkforceOne\Support\Geo::distanceMeters($lat1,$lon1,$lat2,$lon2);
        }

    private function format_distance($m){
            if($m===null)return 'Not available';
            return $m<1000?round($m).' m':number_format($m/1000,2).' km';
        }

    private function default_shifts(){
            return [
                ['id'=>1,'name'=>'Standard','start'=>'08:00','end'=>'17:00','grace'=>10,'sign_in_cutoff_minutes'=>240,'overnight'=>0,'active'=>1],
            ];
        }

    private function shifts(){
            if(self::$ews_shifts_cache!==null)return self::$ews_shifts_cache;
            $raw=get_option('ews_shifts',null);
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

    private function default_working_hours(){
            return ['start'=>'08:00','normal_until'=>'10:00','end'=>'17:00'];
        }

    private function working_hours($employee_id=0){
            $employee_id=absint($employee_id);
            if(!$employee_id){$emp=$this->current_employee();$employee_id=$emp?(int)$emp->id:0;}
            if(array_key_exists($employee_id,self::$ews_working_hours_cache))return self::$ews_working_hours_cache[$employee_id];
            $shift=$this->shift_for_employee($employee_id);
            if($shift){
                // Shifts historically had no separate no-show cutoff. Preserve the
                // legacy two-hour no-show window used by Company Working Hours,
                // while anchoring it to each employee's actual shift start.
                $start_ts=strtotime('1970-01-01 '.$shift['start'].':00');
                $cutoff_ts=$start_ts!==false?$start_ts+(2*HOUR_IN_SECONDS):false;
                $end_ts=strtotime('1970-01-01 '.$shift['end'].':00');
                if($cutoff_ts!==false && $end_ts!==false && empty($shift['overnight']) && $cutoff_ts>$end_ts)$cutoff_ts=$end_ts;
                $normal_until=$cutoff_ts!==false?date('H:i',$cutoff_ts):$shift['end'];
                return self::$ews_working_hours_cache[$employee_id]=['start'=>$shift['start'],'normal_until'=>$normal_until,'end'=>$shift['end'],'grace'=>$shift['grace'],'sign_in_cutoff_minutes'=>$shift['sign_in_cutoff_minutes'],'overnight'=>$shift['overnight'],'shift_id'=>$shift['id'],'shift_name'=>$shift['name']];
            }
            $cfg=get_option('ews_working_hours',[]); $defaults=$this->default_working_hours(); if(!is_array($cfg))$cfg=[];
            $start=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['start']??'')?$cfg['start']:$defaults['start'];
            $end=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['end']??'')?$cfg['end']:$defaults['end'];
            $normal_until=preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$cfg['normal_until']??'')?$cfg['normal_until']:$end;
            return self::$ews_working_hours_cache[$employee_id]=['start'=>$start,'normal_until'=>$normal_until,'end'=>$end,'grace'=>$this->global_grace_period(),'sign_in_cutoff_minutes'=>240,'overnight'=>(int)get_option('ews_allow_overnight_shift',0),'shift_id'=>0,'shift_name'=>'Company Default'];
        }

    private function global_grace_period(){ return max(0,min(180,(int)get_option('ews_grace_period',10))); }

    private function attendance_grace_period($employee_id=0){
            $h=$this->working_hours($employee_id); return isset($h['grace'])?(int)$h['grace']:$this->global_grace_period();
        }

    private function format_time_label($time){
            $time=is_string($time)?trim($time):'';
            if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))return $time;
            $ts=strtotime('1970-01-01 '.$time.':00');
            return $ts?date_i18n('g:i A',$ts):$time;
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
            $ts=$event_at?strtotime($event_at):current_time('timestamp'); $date=date('Y-m-d',$ts); $cfg=$this->working_hours($employee_id);
            $start=strtotime($date.' '.$cfg['start']);
            if(!empty($cfg['overnight']) && $cfg['start']>$cfg['end'] && date('H:i',$ts)<=$cfg['end'])$start=strtotime(date('Y-m-d',strtotime($date.' -1 day')).' '.$cfg['start']);
            $grace_end=$start+($this->attendance_grace_period($employee_id)*60);
            return $ts<=$grace_end?'On Time':'Late Arrival';
        }

    private function working_hours_save($start,$normal_until,$end){
            $valid=function($v){return is_string($v)&&preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/',$v);};
            if(!$valid($start)||!$valid($end))return new WP_Error('invalid_hours','Invalid working hours.');
            if($start===$end)return new WP_Error('invalid_hours','Work start and work end cannot be the same.');
            update_option('ews_working_hours',['start'=>$start,'normal_until'=>$end,'end'=>$end],false);
            return true;
        }

    private function employee_profile_settings(){
            $defaults=[
                'enabled'=>1,
                'show_photo'=>1,
                'show_name'=>1,
                'show_team'=>1,
                'show_email'=>0,
                'show_supervisor'=>0,
                'show_achievements'=>1,
                'show_recognition'=>0,
            ];
            $cfg=get_option('ews_employee_profile_settings',[]);
            if(!is_array($cfg))$cfg=[];
            foreach($defaults as $key=>$value){
                if(!array_key_exists($key,$cfg))$cfg[$key]=$value;
                $cfg[$key]=!empty($cfg[$key])?1:0;
            }
            return array_merge($defaults,$cfg);
        }

    private function permission_defs(){
            return [
                'ews_view_dashboard'=>'View Dashboard',
                'ews_view_people'=>'View People',
                'ews_manage_employees'=>'Manage Employees',
                'ews_manage_schedule'=>'Manage Weekly Schedule',
                'ews_manage_attendance'=>'Manage Attendance',
                'ews_view_reports'=>'View Reports',
                'ews_manage_time'=>'Manage Sign In / Out',
                'ews_manage_auto_attendance'=>'Manage Auto Attendance',
                'ews_view_audit_log'=>'View Audit Log',
                'ews_manage_locations'=>'Manage Locations',
                'ews_manage_settings'=>'Manage Settings',
                'ews_manage_tasks'=>'Manage Tasks',
                'ews_manage_roles'=>'Manage Roles & Permissions',
            ];
        }

    private function role_defs(){
            return [
                'ews_administrator'=>['name'=>'EWS Administrator','caps'=>array_keys($this->permission_defs())],
                'ews_manager'=>['name'=>'EWS Manager','caps'=>['ews_view_dashboard','ews_view_people','ews_manage_employees','ews_manage_schedule','ews_manage_attendance','ews_view_reports','ews_manage_time','ews_manage_auto_attendance','ews_view_audit_log','ews_manage_locations','ews_manage_settings','ews_manage_tasks']],
                'ews_supervisor'=>['name'=>'EWS Supervisor','caps'=>['ews_view_dashboard','ews_view_people','ews_manage_schedule','ews_manage_attendance','ews_view_reports','ews_manage_time']],
                'ews_employee'=>['name'=>'EWS Employee','caps'=>['ews_view_people']],
            ];
        }

    private function ensure_roles_permissions(){
            foreach($this->role_defs() as $slug=>$def){
                if(!get_role($slug))add_role($slug,$def['name'],['read'=>true]);
            }
            foreach($this->role_defs() as $slug=>$def){
                $role=get_role($slug);if(!$role)continue;
                foreach($this->permission_defs() as $cap=>$label){
                    if(!isset($role->capabilities[$cap]))$role->add_cap($cap,in_array($cap,$def['caps'],true));
                }
            }
            // WordPress admin menus evaluate the capability directly; make sure
            // the native Administrator role can see all EWS admin pages.
            $admin=get_role('administrator');
            if($admin){
                foreach($this->permission_defs() as $cap=>$label)$admin->add_cap($cap,true);
            }
        }

    private function can($cap){
            return current_user_can('manage_options') || current_user_can($cap);
        }

    private function has_ews_admin_access(){
            if(!is_user_logged_in())return false;
            if(current_user_can('manage_options'))return true;
            foreach(array_keys($this->permission_defs()) as $cap){
                if(current_user_can($cap))return true;
            }
            return false;
        }

    public function prevent_dynamic_page_cache(){
            // Workforce One frontend pages are user-specific and contain live state
            // (authentication, attendance, notifications, requests, schedules, etc.).
            // Never allow a page cache to serve one user's rendered page to another user.
            $dynamic=false;
            if(isset($_GET['ews_view']))$dynamic=true;
            if(is_user_logged_in() && function_exists('is_singular') && is_singular()){
                global $post;
                if($post && !empty($post->post_content) && has_shortcode($post->post_content,'employee_app'))$dynamic=true;
            }
            if(!$dynamic)return;
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            if(!defined('DONOTCACHEDB'))define('DONOTCACHEDB',true);
            if(!defined('DONOTMINIFY'))define('DONOTMINIFY',true);
            if(function_exists('nocache_headers'))nocache_headers();
            $GLOBALS['ews31_dynamic_no_cache']=true;
        }

        public function send_dynamic_no_cache_headers(){
            if(empty($GLOBALS['ews31_dynamic_no_cache']))return;
            if(!headers_sent()){
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            }
        }


    private function default_working_days(){
            // PHP date('w'): Sunday=0 ... Saturday=6.
            return [0,1,2,3,4]; // Preserve current behavior: Sunday–Thursday.
        }

    private function working_days(){
            $days=get_option('ews_working_days',null);
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

    private function default_schedule_types_config(){
            return [
                ['name'=>'Office','requires_sign_in'=>1,'requires_location'=>1,'attendance_rule'=>'attendance','active'=>1,'icon'=>'🏢','bg_color'=>'#dcfce7','text_color'=>'#166534','border_color'=>'#bbf7d0'],
                ['name'=>'WFH','requires_sign_in'=>1,'requires_location'=>0,'attendance_rule'=>'attendance','active'=>1,'icon'=>'🏠','bg_color'=>'#dbeafe','text_color'=>'#1d4ed8','border_color'=>'#bfdbfe'],
                ['name'=>'Vacation','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'leave','active'=>1,'icon'=>'🌴','bg_color'=>'#fef3c7','text_color'=>'#92400e','border_color'=>'#fde68a'],
                ['name'=>'Business Trip','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1,'icon'=>'🚗','bg_color'=>'#ede9fe','text_color'=>'#6d28d9','border_color'=>'#ddd6fe'],
                ['name'=>'Training Course','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1,'icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd'],
            ];
        }

    private function schedule_types_config($active_only=true){
            if(self::$ews_schedule_types_cache===null){
                $types=get_option('ews_schedule_types_config',null);
            if(!is_array($types)||empty($types)){
                $types=$this->default_schedule_types_config();
                update_option('ews_schedule_types_config',$types,false);
            }
            $out=[];
            foreach($types as $type){
                if(!is_array($type))continue;
                $name=trim(sanitize_text_field($type['name']??''));
                if($name==='')continue;
                if($active_only && empty($type['active']))continue;
                $rule=sanitize_key($type['attendance_rule']??'attendance');
                if(!in_array($rule,['attendance','leave','business_trip'],true))$rule='attendance';
                $defaults=['icon'=>'•','bg_color'=>'#f2f4f7','text_color'=>'#667085','border_color'=>'#e5e7eb'];
                $style_defaults=[
                    'Office'=>['icon'=>'🏢','bg_color'=>'#dcfce7','text_color'=>'#166534','border_color'=>'#bbf7d0'],
                    'WFH'=>['icon'=>'🏠','bg_color'=>'#dbeafe','text_color'=>'#1d4ed8','border_color'=>'#bfdbfe'],
                    'Vacation'=>['icon'=>'🌴','bg_color'=>'#fef3c7','text_color'=>'#92400e','border_color'=>'#fde68a'],
                    'Business Trip'=>['icon'=>'🚗','bg_color'=>'#ede9fe','text_color'=>'#6d28d9','border_color'=>'#ddd6fe'],
                    'Training Course'=>['icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd']
                ];
                $sd=$style_defaults[$name]??$defaults;
                $hex=function($v,$fallback){$v=sanitize_text_field((string)$v);return preg_match('/^#[0-9a-fA-F]{6}$/',$v)?strtolower($v):$fallback;};
                $out[]=[
                    'name'=>$name,
                    'requires_sign_in'=>!empty($type['requires_sign_in'])?1:0,
                    'requires_location'=>!empty($type['requires_location'])?1:0,
                    'attendance_rule'=>$rule,
                    'active'=>!empty($type['active'])?1:0,
                    'icon'=>trim(sanitize_text_field($type['icon']??$sd['icon']))?:$sd['icon'],
                    'bg_color'=>$hex($type['bg_color']??$sd['bg_color'],$sd['bg_color']),
                    'text_color'=>$hex($type['text_color']??$sd['text_color'],$sd['text_color']),
                    'border_color'=>$hex($type['border_color']??$sd['border_color'],$sd['border_color'])
                ];
            }
            $has_training=false;
            foreach($out as $x){if(strtolower($x['name'])==='training course'){$has_training=true;break;}}
            if(!$has_training)$out[]=array_merge(['name'=>'Training Course','requires_sign_in'=>0,'requires_location'=>0,'attendance_rule'=>'business_trip','active'=>1],['icon'=>'🎓','bg_color'=>'#e0f2fe','text_color'=>'#0369a1','border_color'=>'#bae6fd']);
                self::$ews_schedule_types_cache=$out;
            }
            if(!$active_only)return self::$ews_schedule_types_cache;
            return array_values(array_filter(self::$ews_schedule_types_cache,function($type){return !empty($type['active']);}));
        }

    private function schedule_type_config($name){
            $name=(string)$name;
            if(array_key_exists($name,self::$ews_schedule_type_by_name_cache))return self::$ews_schedule_type_by_name_cache[$name];
            foreach($this->schedule_types_config(false) as $type){
                if($type['name']===$name)return self::$ews_schedule_type_by_name_cache[$name]=$type;
            }
            return self::$ews_schedule_type_by_name_cache[$name]=null;
        }

    private function schedule_type_requires_sign_in($name){
            $type=$this->schedule_type_config($name);
            return $type?(bool)$type['requires_sign_in']:false;
        }private function pwa_splash_settings(){
            $defaults=['enabled'=>1,'duration_ms'=>650,'title'=>'Workforce One','subtitle'=>'Workforce Management Platform','background'=>'#f7f7fb','accent'=>'#6125c9','logo'=>''];
            $saved=get_option('ews_pwa_splash_settings',[]);
            $s=wp_parse_args(is_array($saved)?$saved:[],$defaults);
            $s['enabled']=!empty($s['enabled'])?1:0;
            $s['duration_ms']=max(0,min(3000,(int)$s['duration_ms']));
            $s['title']=sanitize_text_field($s['title']);
            $s['subtitle']=sanitize_text_field($s['subtitle']);
            $s['background']=sanitize_hex_color($s['background'])?:$defaults['background'];
            $s['accent']=sanitize_hex_color($s['accent'])?:$defaults['accent'];
            $s['logo']=esc_url($s['logo']);
            return $s;
        }

private function face_signin_enabled(){ return (bool)get_option('ews_feature_face_signin',false); }

    private function face_signin_settings(){
        $defaults=array(
            'baseline_samples'=>15,'sample_interval_ms'=>100,'eye_drop_threshold'=>0.90,
            'blink_min_drop'=>0.94,'blink_min_ms'=>60,'challenge_timeout_sec'=>12,
            'head_move_px'=>8,'head_move_ratio'=>0.018,'face_match_threshold'=>0.60,
            'enrollment_samples'=>5,'enrollment_interval_ms'=>650,
            'detector_score_threshold'=>0.35,'detector_input_size'=>320
        );
        $saved=get_option('ews_face_signin_settings',array());
        return wp_parse_args(is_array($saved)?$saved:array(),$defaults);
    }

    private function face_signin_setting($key,$fallback=null){
        $s=$this->face_signin_settings();
        return array_key_exists($key,$s)?$s[$key]:$fallback;
    }


    private function schedule_type_requires_location($name){
            $type=$this->schedule_type_config($name);
            return $type?(bool)$type['requires_location']:false;
        }

    private function schedule_type_attendance_rule($name){
            $type=$this->schedule_type_config($name);
            return $type?$type['attendance_rule']:'attendance';
        }

    private function schedule_type_names($active_only=true){
            return array_map(function($x){return $x['name'];},$this->schedule_types_config($active_only));
        }

    private function face_biometric_key(){
        $salt = defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : wp_salt('auth');
        return hash('sha256', $salt.'|workforce-one|face-biometric-v1', true);
    }

    private function face_encrypt_template($template){
        if(!is_array($template) || !$template || !function_exists('openssl_encrypt')) return false;
        $json=wp_json_encode(array_values(array_map('floatval',$template)));
        if($json===false) return false;
        try{$iv=random_bytes(16);}catch(Throwable $e){return false;}
        $cipher=openssl_encrypt($json,'AES-256-CBC',$this->face_biometric_key(),OPENSSL_RAW_DATA,$iv);
        return $cipher===false?false:base64_encode($iv.$cipher);
    }

    private function face_decrypt_template($payload){
        if(!$payload || !function_exists('openssl_decrypt')) return false;
        $raw=base64_decode($payload,true);
        if($raw===false || strlen($raw)<17) return false;
        $json=openssl_decrypt(substr($raw,16),'AES-256-CBC',$this->face_biometric_key(),OPENSSL_RAW_DATA,substr($raw,0,16));
        if($json===false) return false;
        $data=json_decode($json,true);
        return is_array($data)?array_values(array_map('floatval',$data)):false;
    }

    /* Face matching happens in /face/verify; a successful match issues a short-lived,
       single-use token bound to the current user. Sign In trusts that token only,
       never a client-supplied "face_verified" flag. */
    private function face_issue_token(){
        $uid=get_current_user_id();
        if(!$uid)return '';
        $token=wp_generate_password(40,false,false);
        set_transient('ews_face_token_'.$uid,hash('sha256',$token),120);
        return $token;
    }

    private function face_consume_token($token){
        $uid=get_current_user_id();
        $token=is_string($token)?trim($token):'';
        if(!$uid||$token==='')return false;
        $stored=get_transient('ews_face_token_'.$uid);
        if(!is_string($stored)||$stored==='')return false;
        if(!hash_equals($stored,hash('sha256',$token)))return false;
        delete_transient('ews_face_token_'.$uid);
        return true;
    }

    private function face_template_for_employee($employee_id){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id) return false;
        $table=$wpdb->prefix.'ews_face_profiles';
        $payload=$wpdb->get_var($wpdb->prepare(
            "SELECT face_template FROM {$table} WHERE employee_id=%d AND status='active' LIMIT 1",
            $employee_id
        ));
        return $payload?$this->face_decrypt_template($payload):false;
    }

    private function face_save_template($employee_id,$template,$model_version='face-api-0.22.2'){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id || !is_array($template) || count($template)<64) return false;
        $encrypted=$this->face_encrypt_template($template);
        if($encrypted===false) return false;
        $table=$wpdb->prefix.'ews_face_profiles';
        $now=current_time('mysql');
        $data=array(
            'employee_id'=>$employee_id,
            'face_template'=>$encrypted,
            'model_version'=>sanitize_text_field($model_version),
            'status'=>'active',
            'updated_at'=>$now
        );
        $existing=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE employee_id=%d LIMIT 1",$employee_id
        ));
        if($existing){
            $ok=$wpdb->update($table,$data,array('employee_id'=>$employee_id));
        }else{
            $data['enrolled_at']=$now;
            $ok=$wpdb->insert($table,$data);
        }
        return $ok!==false;
    }

    private function face_reset_requested($employee_id){
        $employee_id=absint($employee_id);
        if(!$employee_id)return false;
        $requests=get_option('ews_face_reset_requests',array());
        return is_array($requests) && !empty($requests[$employee_id]) && ($requests[$employee_id]['status']??'')==='pending';
    }

    private function face_request_reset($employee_id,$user_id=0){
        $employee_id=absint($employee_id);
        $user_id=absint($user_id);
        if(!$employee_id || !$this->face_template_for_employee($employee_id)) return false;
        $requests=get_option('ews_face_reset_requests',array());
        if(!is_array($requests))$requests=array();
        if(!empty($requests[$employee_id]) && ($requests[$employee_id]['status']??'')==='pending') return true;
        $requests[$employee_id]=array(
            'status'=>'pending',
            'requested_by'=>$user_id,
            'requested_at'=>current_time('mysql')
        );
        $saved=update_option('ews_face_reset_requests',$requests,false);
        if(!$saved)return false;
        global $wpdb;
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
        if(!$emp)return true;
        $workflow=$this->approval_workflow('face_reset');
        $approval_active=$workflow && (int)$workflow->active===1;
        if($approval_active){
            $approval=$this->approval_start('face_reset','face_reset_request',$employee_id,$employee_id,[],true);
            if(is_wp_error($approval))return false;
            $ar=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
            if($ar && strtoupper((string)$ar->status)==='APPROVED'){
                if(!$this->face_delete_template($employee_id))return false;
                $requests=get_option('ews_face_reset_requests',[]);
                if(is_array($requests)&&isset($requests[$employee_id])){
                    $requests[$employee_id]['status']='approved';
                    $requests[$employee_id]['handled_by']=$user_id;
                    $requests[$employee_id]['handled_at']=current_time('mysql');
                    update_option('ews_face_reset_requests',$requests,false);
                }
                $msg='Your Face Reset request has been approved. You can now enroll your new face.';
                if(!empty($emp->wp_user_id)){$this->notify_user((int)$emp->wp_user_id,'Face Reset Approved',$msg,'success','face_reset',$employee_id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Face Reset Approved',$msg,'face_reset',$employee_id,add_query_arg('ews_view','time',home_url('/')));}
                return true;
            }
            $step=$ar?$this->approval_current_step((int)$ar->id):null;
            if($step && !empty($step->approver_wp_user_id)){
                $msg=$emp->name.' requested a Face Reset. Approval is required.';
                $this->notify_user((int)$step->approver_wp_user_id,'Face Reset Request',$msg,'face_reset','face_reset',$employee_id);
                if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$step->approver_wp_user_id,'Face Reset Request',$msg,'face_reset',$employee_id,add_query_arg(['page'=>'ews31-face-reset-requests'],admin_url('admin.php')));
            }
            return true;
        }
        $managers=get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]);
        $msg=$emp->name.' requested a Face Reset. Approval is required.';
        foreach($managers as $m){
            if((int)$m->ID===$user_id)continue;
            $this->notify_user((int)$m->ID,'Face Reset Request',$msg,'face_reset','face_reset',$employee_id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$m->ID,'Face Reset Request',$msg,'face_reset',$employee_id,add_query_arg(['page'=>'ews31-face-reset-requests'],admin_url('admin.php')));
        }
        return true;
    }

    private function face_delete_template($employee_id){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id) return false;
        return $wpdb->delete(
            $wpdb->prefix.'ews_face_profiles',
            array('employee_id'=>$employee_id),
            array('%d')
        )!==false;
    }

    private function ensure_schedule_config_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        $table=$wpdb->prefix.'ews_face_profiles';
        $charset=$wpdb->get_charset_collate();
        $sql="CREATE TABLE IF NOT EXISTS {$table} (
          id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          employee_id bigint(20) unsigned NOT NULL,
          face_template longtext NOT NULL,
          model_version varchar(100) NOT NULL DEFAULT '',
          status varchar(20) NOT NULL DEFAULT 'active',
          enrolled_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY employee_id (employee_id), KEY status (status)
        ) {$charset};";
        require_once ABSPATH.'wp-admin/includes/upgrade.php'; dbDelta($sql);


            if(get_option('ews_feature_overtime',null)===null)update_option('ews_feature_overtime',false,false);
            if(get_option('ews_feature_face_signin',null)===null)update_option('ews_feature_face_signin',false,false);
            if(get_option('ews_face_signin_settings',null)===null)update_option('ews_face_signin_settings',array(),false);
            
            global $wpdb;
            $version=get_option('ews_schedule_config_schema','');
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $charset=$wpdb->get_charset_collate();
            $calendar=$wpdb->prefix.'ews_company_calendar';
            dbDelta("CREATE TABLE $calendar (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_date DATE NOT NULL,
                title VARCHAR(190) NOT NULL,
                event_type VARCHAR(40) NOT NULL DEFAULT 'general_leave',
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY event_date(event_date),
                KEY active(active)
            ) $charset;");
            if(!$version){
                update_option('ews_schedule_types_config',$this->default_schedule_types_config(),false);
                update_option('ews_working_days',$this->default_working_days(),false);
            }else{
                $types=get_option('ews_schedule_types_config',[]);
                if(!is_array($types)||empty($types))update_option('ews_schedule_types_config',$this->default_schedule_types_config(),false);
                $days=get_option('ews_working_days',null); if(!is_array($days))update_option('ews_working_days',$this->default_working_days(),false);
            }
            if($version!=='3.6.0')update_option('ews_schedule_config_schema','3.6.0',false);
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

    private function statuses(){
            return array_merge($this->schedule_type_names(true),['Absent']);
        }

    private function normalize_date($value){
            $value=trim((string)$value);
            if($value==='') return '';
            if(preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/',$value,$m)){
                $y=(int)$m[1];$mo=(int)$m[2];$d=(int)$m[3];
                return checkdate($mo,$d,$y)?sprintf('%04d-%02d-%02d',$y,$mo,$d):'';
            }
            if(preg_match('/^(\\d{1,2})[\\/.-](\\d{1,2})[\\/.-](\\d{4})$/',$value,$m)){
                $mo=(int)$m[1];$d=(int)$m[2];$y=(int)$m[3];
                if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
            }
            if(preg_match('/^(\\d{1,2})[\\/.-](\\d{1,2})[\\/.-](\\d{2})$/',$value,$m)){
                $mo=(int)$m[1];$d=(int)$m[2];$y=2000+(int)$m[3];
                if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
            }
            if(ctype_digit($value)){
                $serial=(int)$value;
                if($serial>=1 && $serial<=60000){
                    $base=new DateTime('1899-12-30');
                    $base->modify('+'.$serial.' days');
                    return $base->format('Y-m-d');
                }
            }
            return '';
        }

    private function valid_date($d){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))return false;[$y,$m,$day]=array_map('intval',explode('-',$d));return checkdate($m,$day,$y);}

    private function audit($a,$e,$id=0,$d=''){global $wpdb;$wpdb->insert($this->audit,['user_id'=>get_current_user_id(),'action'=>$a,'entity'=>$e,'entity_id'=>$id,'details'=>$d,'created_at'=>current_time('mysql')]);}

    private function emps(){
            if(self::$ews_emps_cache!==null)return self::$ews_emps_cache;
            global $wpdb;
            self::$ews_emps_cache=$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
            return self::$ews_emps_cache;
        }

    private function emp_domain($d){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE domain_name=%s AND active=1 LIMIT 1",trim($d)));}


        private function save_att_if_unchanged($eid,$date,$status,$expected_status){
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                $eid,$date
            ));

            /*
             * Concurrency guard for the Attendance grid:
             * - expected_status === null means the cell was empty when the page loaded.
             * - otherwise the manager loaded this exact schedule status.
             *
             * The actual UPDATE is conditional on the expected status, so a
             * second manager cannot silently overwrite a newer value.
             */
            if($expected_status===null){
                if($row){
                    return ['ok'=>false,'conflict'=>true,'id'=>(int)$row->id];
                }

                $data=[
                    'employee_id'=>$eid,
                    'work_date'=>$date,
                    'status'=>$status,
                    'note'=>'',
                    'updated_by'=>get_current_user_id(),
                    'updated_at'=>current_time('mysql')
                ];
                $inserted=$wpdb->insert($this->schedule,$data);
                if($inserted===false){
                    /*
                     * The UNIQUE employee_date key protects the insert if two
                     * managers create the same previously-empty cell at once.
                     * If another insert won the race, report a conflict rather
                     * than treating it as a generic database failure.
                     */
                    $winner=$wpdb->get_row($wpdb->prepare(
                        "SELECT id,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                        $eid,$date
                    ));
                    if($winner){
                        return ['ok'=>false,'conflict'=>true,'id'=>(int)$winner->id];
                    }
                    $this->audit('schedule_insert_failed','schedule',0,$wpdb->last_error?:'Database insert failed.');
                    return ['ok'=>false,'conflict'=>false,'id'=>0];
                }
                $id=(int)$wpdb->insert_id;
                $old_status=null;
                $old_note='';
                $row=$wpdb->get_row($wpdb->prepare(
                    "SELECT id,status,note FROM {$this->schedule} WHERE id=%d",
                    $id
                ));
            }else{
                if(!$row || (string)$row->status!==(string)$expected_status){
                    return ['ok'=>false,'conflict'=>true,'id'=>$row?(int)$row->id:0];
                }

                /*
                 * Keep the current note. The Attendance grid edits schedule
                 * status only and has no note input, so it must never erase a
                 * note that another workflow/user has stored.
                 */
                $old_status=(string)$row->status;
                $old_note=(string)$row->note;
                $data=[
                    'status'=>$status,
                    'note'=>$old_note,
                    'updated_by'=>get_current_user_id(),
                    'updated_at'=>current_time('mysql')
                ];

                /*
                 * Atomic compare-and-update. The status in the WHERE clause
                 * is the value the manager saw when the grid was loaded.
                 */
                $updated=$wpdb->query($wpdb->prepare(
                    "UPDATE {$this->schedule}
                     SET status=%s,note=%s,updated_by=%d,updated_at=%s
                     WHERE id=%d AND status=%s",
                    $status,$old_note,get_current_user_id(),current_time('mysql'),
                    (int)$row->id,$expected_status
                ));

                if($updated===false){
                    $this->audit('schedule_update_failed','schedule',(int)$row->id,$wpdb->last_error?:'Database update failed.');
                    return ['ok'=>false,'conflict'=>false,'id'=>(int)$row->id];
                }

                /*
                 * 0 rows can mean a concurrent manager changed the value
                 * between the pre-check and the UPDATE. Re-read and classify
                 * it as a conflict only if the current value is different.
                 */
                if((int)$updated===0){
                    $latest=$wpdb->get_row($wpdb->prepare(
                        "SELECT status FROM {$this->schedule} WHERE id=%d",
                        (int)$row->id
                    ));
                    if(!$latest || (string)$latest->status!==(string)$expected_status){
                        return ['ok'=>false,'conflict'=>true,'id'=>(int)$row->id];
                    }
                    /*
                     * The requested value is already the same value. Treat
                     * this as a harmless no-op rather than a false conflict.
                     */
                    return ['ok'=>true,'conflict'=>false,'id'=>(int)$row->id,'changed'=>false,'old_status'=>(string)$row->status];
                }

                $id=(int)$row->id;
            }

            $emp=$wpdb->get_row($wpdb->prepare(
                "SELECT name,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1",
                $eid
            ));
            $changed=($old_status!==$status);
            if($emp && $emp->wp_user_id && $changed){
                $label=date_i18n('l, d M Y',strtotime($date));
                $this->notify_user(
                    (int)$emp->wp_user_id,
                    'Schedule Updated',
                    'Your schedule for <strong>'.esc_html($label).'</strong> is now <strong>'.esc_html($status).'</strong>.',
                    'info',
                    'schedule',
                    (int)$id
                );
                $this->push_schedule_update((int)$emp->wp_user_id);
            }

            return ['ok'=>true,'conflict'=>false,'id'=>$id,'changed'=>$changed,'old_status'=>$old_status];
        }

    private function save_att($eid,$date,$status,$note=''){global $wpdb;$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s",$eid,$date));$old_status=$id?$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->schedule} WHERE id=%d",$id)):null;$old_note=$id?$wpdb->get_var($wpdb->prepare("SELECT note FROM {$this->schedule} WHERE id=%d",$id)):null;$data=['status'=>$status,'note'=>$note,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')];if($id){$updated=$wpdb->update($this->schedule,$data,['id'=>$id]);if($updated===false){$this->audit('schedule_update_failed','schedule',(int)$id,$wpdb->last_error?:'Database update failed.');return 0;}}else{$data['employee_id']=$eid;$data['work_date']=$date;$inserted=$wpdb->insert($this->schedule,$data);if($inserted===false){$this->audit('schedule_insert_failed','schedule',0,$wpdb->last_error?:'Database insert failed.');return 0;}$id=$wpdb->insert_id;}$emp=$wpdb->get_row($wpdb->prepare("SELECT name,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1",$eid));$changed=($old_status!==$status)||((string)$old_note!==(string)$note);if($emp&&$emp->wp_user_id&&$changed){$label=date_i18n('l, d M Y',strtotime($date));$this->notify_user((int)$emp->wp_user_id,'Schedule Updated','Your schedule for <strong>'.esc_html($label).'</strong> is now <strong>'.esc_html($status).'</strong>.','info','schedule',(int)$id);$this->push_schedule_update((int)$emp->wp_user_id);}return $id;}
}
