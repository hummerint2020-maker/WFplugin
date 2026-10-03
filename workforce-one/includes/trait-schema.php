<?php
if (!defined('ABSPATH')) exit;

/**
 * Database schema: the plugin's tables and columns, the schema version (ews_schema_target()) and the
 * one-time upgrade that runs when it changes (maybe_upgrade_schema()). A schema change = a new
 * ensure_*_schema() step + a bump of ews_schema_target().
 */
trait EWS_Schema_Trait {


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
                annual_entitlement DECIMAL(8,2) NOT NULL DEFAULT 0, paid_percent TINYINT UNSIGNED NOT NULL DEFAULT 100,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
                reviewed_at DATETIME NULL, cancelled_by BIGINT UNSIGNED NULL, cancelled_at DATETIME NULL, balance_id BIGINT UNSIGNED NULL,
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
            // Payroll (3.31.41): share of a day's pay kept on each leave type; existing types stay fully paid.
            if(!in_array('paid_percent',(array)$wpdb->get_col("SHOW COLUMNS FROM {$types}",0),true))$wpdb->query("ALTER TABLE {$types} ADD COLUMN `paid_percent` TINYINT UNSIGNED NOT NULL DEFAULT 100");
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
                'cancelled_at'=>"DATETIME NULL",
                // Balance row the request was charged to (NULL for requests created before 3.31.1).
                'balance_id'=>"BIGINT UNSIGNED NULL"
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

        private function ews_schema_target(){ return '3.31.14'; }

        /*
         * True once maybe_upgrade_schema() has completed for the current schema
         * target. Every ensure_*_schema() routine returns early when this is true,
         * so request paths never run SHOW TABLES / SHOW COLUMNS / dbDelta.
         * To ship a schema change: update the ensure_* routine and bump
         * ews_schema_target().
         */
        private function ews_schema_is_current(){
            return $this->option('ews_schema_version')===$this->ews_schema_target();
        }

        private function maybe_upgrade_schema(){
            $target=$this->ews_schema_target();
            $done=$this->option('ews_schema_version');
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
            $this->ensure_payroll_schema();
            $this->ews_v321_ensure_locations_table();
            $this->ews_v321_ensure_employee_map();
            if(get_option('ews_feature_tasks',null)===null)update_option('ews_feature_tasks',false,false);
            if(get_option('ews_presence_qr_signin',null)===null)update_option('ews_presence_qr_signin',false,false);
            if(get_option('ews_presence_verification',null)===null)update_option('ews_presence_verification',false,false);
            if(method_exists($this,'ensure_presence_schema'))$this->ensure_presence_schema();
            $this->repair_stale_location_integrity();
            update_option('ews_schema_version',$target,false);
        }

        /**
         * One-time repair (3.31.28): sites not on UTC compared the phone's UTC location time with local
         * time, so fresh locations were saved as "unreliable / stale_timestamp" (3 hours off in Cairo).
         * Re-evaluate those rows against the event's own time in UTC; rows that really were stale stay so.
         */
        private function repair_stale_location_integrity(){
            if($this->option('ews_integrity_repair_done'))return;
            global $wpdb;
            $last=0;
            do{
                $rows=$wpdb->get_results($wpdb->prepare("SELECT id,event_at,latitude,longitude,accuracy,location_timestamp FROM {$this->time_logs} WHERE integrity_reason='stale_timestamp' AND location_timestamp IS NOT NULL AND id>%d ORDER BY id ASC LIMIT 500",$last));
                foreach((array)$rows as $r){
                    $last=(int)$r->id;
                    $utc=strtotime(get_gmt_from_date($r->event_at).' UTC');
                    if(!$utc)continue;
                    [$st,$why]=\WorkforceOne\Attendance\LocationAssessment::integrity($r->latitude!==null?(float)$r->latitude:null,$r->longitude!==null?(float)$r->longitude:null,$r->accuracy!==null?(float)$r->accuracy:null,(int)$r->location_timestamp,$utc,null);
                    if($why!=='stale_timestamp')$wpdb->update($this->time_logs,['integrity_status'=>$st,'integrity_reason'=>$why],['id'=>(int)$r->id]);
                }
            }while($rows && count($rows)===500);
            update_option('ews_integrity_repair_done',1,false);
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
            $version=$this->option('ews_schedule_config_schema');
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
                $types=$this->option('ews_schedule_types_config');
                if(!is_array($types)||empty($types))update_option('ews_schedule_types_config',$this->default_schedule_types_config(),false);
                $days=get_option('ews_working_days',null); if(!is_array($days))update_option('ews_working_days',$this->default_working_days(),false);
            }
            if($version!=='3.6.0')update_option('ews_schedule_config_schema','3.6.0',false);
        }
}
