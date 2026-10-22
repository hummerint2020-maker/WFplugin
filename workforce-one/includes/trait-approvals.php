<?php
if (!defined('ABSPATH')) exit;

/**
 * Workforce One Approval Engine foundation.
 *
 * V1 provides the normalized approval domain and persistence layer. Existing
 * business workflows are migrated onto this engine one at a time; until a
 * workflow is migrated its legacy behavior remains unchanged.
 */
trait EWS_Approvals_Trait {

    private function approval_tables(){
        global $wpdb;
        return [
            'relationships' => $wpdb->prefix.'ews_employee_relationships',
            'workflows'     => $wpdb->prefix.'ews_approval_workflows',
            'steps'         => $wpdb->prefix.'ews_approval_workflow_steps',
            'requests'      => $wpdb->prefix.'ews_approval_requests',
            'instances'     => $wpdb->prefix.'ews_approval_steps',
        ];
    }

    private function ensure_approval_schema(){
        if($this->ews_schema_is_current())return true;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $t=$this->approval_tables();

        dbDelta("CREATE TABLE {$t['relationships']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            relationship_type VARCHAR(50) NOT NULL,
            related_employee_id BIGINT UNSIGNED NULL,
            related_wp_user_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY employee_relationship(employee_id,relationship_type,related_employee_id,related_wp_user_id),
            KEY employee_type(employee_id,relationship_type,active),
            KEY related_employee(related_employee_id),
            KEY related_user(related_wp_user_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$t['workflows']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_key VARCHAR(80) NOT NULL,
            name VARCHAR(190) NOT NULL,
            approval_mode VARCHAR(30) NOT NULL DEFAULT 'NONE',
            active TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY workflow_key(workflow_key),
            KEY active(active)
        ) {$c};");

        dbDelta("CREATE TABLE {$t['steps']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id BIGINT UNSIGNED NOT NULL,
            step_order SMALLINT UNSIGNED NOT NULL,
            step_type VARCHAR(30) NOT NULL DEFAULT 'APPROVAL',
            resolver_type VARCHAR(50) NOT NULL,
            resolver_value VARCHAR(190) NULL,
            required TINYINT(1) NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY workflow_order(workflow_id,step_order),
            KEY workflow_active(workflow_id,active),
            KEY resolver_type(resolver_type)
        ) {$c};");

        dbDelta("CREATE TABLE {$t['requests']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            workflow_id BIGINT UNSIGNED NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            requester_employee_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
            current_step SMALLINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            PRIMARY KEY(id),
            UNIQUE KEY entity_workflow(entity_type,entity_id,workflow_id),
            KEY requester_status(requester_employee_id,status),
            KEY workflow_status(workflow_id,status),
            KEY entity(entity_type,entity_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$t['instances']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            approval_request_id BIGINT UNSIGNED NOT NULL,
            step_order SMALLINT UNSIGNED NOT NULL,
            approver_employee_id BIGINT UNSIGNED NULL,
            approver_wp_user_id BIGINT UNSIGNED NULL,
            resolver_type VARCHAR(50) NOT NULL,
            resolver_value VARCHAR(190) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'WAITING',
            decision VARCHAR(30) NULL,
            comment TEXT NULL,
            acted_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY request_step(approval_request_id,step_order),
            KEY approver_employee(approver_employee_id,status),
            KEY approver_user(approver_wp_user_id,status),
            KEY request_status(approval_request_id,status)
        ) {$c};");
        // 3.31.75: who actually decided a step (an administrator may decide for the approver), whether
        // that person was the requester, and steps sent to administrators because the approver was the
        // requester and the workflow does not allow self-approval.
        foreach(['acted_by_wp_user_id'=>"BIGINT UNSIGNED NULL AFTER acted_at",'self_decision'=>"TINYINT(1) NOT NULL DEFAULT 0 AFTER acted_by_wp_user_id",'rerouted'=>"TINYINT(1) NOT NULL DEFAULT 0 AFTER self_decision"] as $col=>$def){
            if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t['instances']} LIKE %s",$col)))$wpdb->query("ALTER TABLE {$t['instances']} ADD COLUMN {$col} {$def}");
        }
        // 3.31.100: a level's condition (only when the request's amount is above it); on each step why it
        // was skipped, since when it waits, when its approver was reminded, how often it moved up and from whom.
        if(!$wpdb->get_var("SHOW COLUMNS FROM {$t['steps']} LIKE 'min_amount'"))$wpdb->query("ALTER TABLE {$t['steps']} ADD COLUMN min_amount DECIMAL(12,2) NULL AFTER resolver_value");
        foreach(['skip_reason'=>"VARCHAR(20) NULL AFTER rerouted",'pending_since'=>"DATETIME NULL AFTER skip_reason",'reminded_at'=>"DATETIME NULL AFTER pending_since",'escalations'=>"TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER reminded_at",'escalated_from_wp_user_id'=>"BIGINT UNSIGNED NULL AFTER escalations"] as $col=>$def){
            if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t['instances']} LIKE %s",$col)))$wpdb->query("ALTER TABLE {$t['instances']} ADD COLUMN {$col} {$def}");
        }

        // 3.31.107: a level decided by a substitute while its approver is away: for whom (the manager), and which substitute row.
        foreach(['for_employee_id'=>"BIGINT UNSIGNED NULL AFTER escalated_from_wp_user_id",'substitute_id'=>"BIGINT UNSIGNED NULL AFTER for_employee_id"] as $col=>$def){
            if(!$wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$t['instances']} LIKE %s",$col)))$wpdb->query("ALTER TABLE {$t['instances']} ADD COLUMN {$col} {$def}");
        }
        $this->approval_seed_workflows();
        return true;
    }

    /** Whether a workflow lets the requester approve their own request (the default: yes). */
    private function approval_self_allowed($workflow_key){
        return $this->approval_self_rule($workflow_key)==='allow';
    }

    /**
     * A decision outside a workflow (3.31.111): whoever decides their own request needs the feature's own setting to allow it
     * ($setting_allows); administrators always may. A self-decision that goes through is marked in the Audit Log.
     * @param int $requester_user_id the requester's WordPress user (0 when they have none)
     * @return bool false when the decision must be refused
     */
    private function approval_self_gate($requester_user_id,$setting_allows,$what){
        $uid=get_current_user_id();
        if(!$uid || (int)$requester_user_id!==$uid)return true;
        if(!$setting_allows && !current_user_can('manage_options'))return false;
        $this->audit('approval_self_decision','approval_workflow',0,$what.' · no workflow');   // $what names the request (type #id) so the row can be found
        return true;
    }

    /**
     * When a level's approver is the requester (3.31.100): 'allow' (they decide it), 'next' (the next
     * level decides; administrators when there is none) or 'admins'. Before 3.31.100 a workflow was
     * allowed or not (ews_approval_allow_self); "not allowed" sent the level to administrators.
     */
    private function approval_self_rule($workflow_key){
        $key=sanitize_key((string)$workflow_key);
        $rules=get_option('ews_approval_self_rule',[]);
        if(is_array($rules) && isset($rules[$key]))return \WorkforceOne\Approvals\Workflows::selfRule((string)$rules[$key]);
        $all=get_option('ews_approval_allow_self',[]);
        return (!is_array($all) || !array_key_exists($key,$all) || !empty($all[$key]))?'allow':'admins';
    }

    /** Whether rejecting needs a reason in this workflow (3.31.103; by default only Attendance Correction, as before). */
    private function approval_reject_note_required($workflow_key){
        $key=sanitize_key((string)$workflow_key);$all=get_option('ews_approval_reject_note',[]);
        if(is_array($all) && array_key_exists($key,$all))return !empty($all[$key]);
        return $key==='attendance_correction';
    }

    /** After how many working hours a waiting request is overdue (3.31.103): the setting, else the reminder time, else 24. */
    private function approval_overdue_hours($workflow_key){
        $key=sanitize_key((string)$workflow_key);$all=get_option('ews_approval_overdue',[]);
        return \WorkforceOne\Approvals\Workflows::overdueHours(is_array($all)?($all[$key]??0):0,$this->approval_deadlines($key)['remind']);
    }

    /** The reason typed with a decision (the form's "note"). */
    private function approval_posted_note(){
        return sanitize_textarea_field(wp_unslash($_POST['note']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked its nonce
    }

    /**
     * The approval level the form showed (3.31.122): its step id, 0 when it showed no open level, null when the form did not say
     * (a page opened before 3.31.122: the current level, as before).
     */
    private function approval_posted_step(){
        return \WorkforceOne\Approvals\LevelGuard::posted(isset($_POST['approval_step'])?wp_unslash($_POST['approval_step']):null); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- the caller checked its nonce; LevelGuard::posted() keeps digits only
    }

    /**
     * The level the form showed is no longer the open one (3.31.122): checked before a module's own checks, so the person is told it
     * moved. $approval_request_id 0: the request has no approval request (the workflow is off), so no level is open.
     */
    private function approval_level_moved($approval_request_id,$posted){
        if($posted===null)return false;
        $step=(int)$approval_request_id?$this->approval_current_step((int)$approval_request_id):null;
        return \WorkforceOne\Approvals\LevelGuard::check((int)$posted,$step?(int)$step->id:0)!==\WorkforceOne\Approvals\LevelGuard::OK;
    }

    private function approval_moved_error(){
        return new WP_Error('approval_moved','This request moved to another level since the page was opened; nothing was changed.');
    }

    /** What a module tells the person when the engine refused a decision: note | moved | approval. */
    private function approval_error_code($error){
        $code=is_wp_error($error)?$error->get_error_code():'';
        if($code==='approval_note_required')return 'note';
        if($code==='approval_moved')return 'moved';
        return 'approval';
    }

    /**
     * The open level of each request, for the forms of a list (3.31.122): approval_request_key() => step id (0 = none open).
     * @param array<int,array{0:string,1:string,2:int}> $keys [workflow_key, entity_type, entity_id]
     * @return array<string,int>
     */
    private function approval_shown_steps(array $keys){
        $found=$this->approval_find_requests($keys);
        $current=$this->approval_current_steps(array_map(function($ar){return (int)$ar->id;},array_values($found)));
        $out=[];
        foreach($keys as $k){
            $key=$this->approval_request_key((string)$k[0],(string)$k[1],(int)$k[2]);
            $ar=$found[$key]??null;
            $out[$key]=$ar && isset($current[(int)$ar->id])?(int)$current[(int)$ar->id]->id:0;
        }
        return $out;
    }

    /** Reminder and move-up times of a workflow, in working hours (0 = never; 3.31.100). @return array{remind:int,escalate:int} */
    private function approval_deadlines($workflow_key){
        $all=get_option('ews_approval_deadlines',[]);
        $d=is_array($all)?($all[sanitize_key((string)$workflow_key)]??[]):[];
        return \WorkforceOne\Approvals\Workflows::deadlines($d['remind']??0,$d['escalate']??0);
    }

    /**
     * Who HR and Finance are (WordPress users) and the working day the deadlines count in (3.31.100).
     * @return array{hr_user:int,finance_user:int,day_start:string,day_end:string}
     */
    private function approval_settings(){
        $s=get_option('ews_approval_settings',[]);$s=is_array($s)?$s:[];
        $start=\WorkforceOne\Approvals\WorkingHours::minutes((string)($s['day_start']??''))!==null?(string)$s['day_start']:'09:00';
        $end=\WorkforceOne\Approvals\WorkingHours::minutes((string)($s['day_end']??''))!==null?(string)$s['day_end']:'17:00';
        if($end<=$start){$start='09:00';$end='17:00';}
        return ['hr_user'=>absint($s['hr_user']??0),'finance_user'=>absint($s['finance_user']??0),'day_start'=>$start,'day_end'=>$end];
    }

    /** The WordPress user of the employee who made an approval request (0 = none). */
    private function approval_requester_user_id($request){
        global $wpdb;
        if(!$request)return 0;
        return (int)$this->people_user_id((int)$request->requester_employee_id);
    }

    private function approval_workflow_key_by_id($workflow_id){
        global $wpdb;$t=$this->approval_tables();
        return (string)$wpdb->get_var($wpdb->prepare("SELECT workflow_key FROM {$t['workflows']} WHERE id=%d",(int)$workflow_id));
    }

    /** Tells the site's administrators that a step waits for them (its approver was the requester). */
    private function approval_notify_administrators($approval_request_id,$step_order){
        $request_id=(int)$approval_request_id;
        foreach(get_users(['capability'=>'manage_options','fields'=>'ID']) as $uid){
            $this->notify((int)$uid,'system',__('Approval needed','workforce-one'),
                sprintf(/* translators: 1: approval request number, 2: approval level */__('Approval #%1$d, level %2$d, waits for an administrator: its approver is the person who made the request. Decide it in wp-admin → Requests Hub.','workforce-one'),$request_id,(int)$step_order),
                ['entity'=>'approval','entity_id'=>$request_id,'push'=>true,'url'=>admin_url('admin.php?page=ews31-requests')]);
        }
    }

    /**
     * Tell administrators once the request is saved (3.31.102). A caller that owns the transaction
     * ($manage_transaction false) may still roll it back: the message is sent at the end of the page
     * load, and only when that level still waits for administrators.
     */
    private function approval_notify_administrators_after($approval_request_id,$step_order){
        $request_id=(int)$approval_request_id;$order=(int)$step_order;
        add_action('shutdown',function()use($request_id,$order){
            global $wpdb;$t=$this->approval_tables();
            $still=$wpdb->get_var($wpdb->prepare("SELECT i.id FROM {$t['instances']} i JOIN {$t['requests']} r ON r.id=i.approval_request_id
                WHERE i.approval_request_id=%d AND i.step_order=%d AND i.status='PENDING' AND i.rerouted=1 AND i.approver_wp_user_id IS NULL",$request_id,$order));
            if($still)$this->approval_notify_administrators($request_id,$order);
        },5);
    }

    private function approval_seed_workflows(){
        global $wpdb;
        $t=$this->approval_tables();
        $defaults=[
            'vacation'=>['Vacation','NONE'],
            'overtime'=>['Overtime','NONE'],
            'early_leave'=>['Early Leave','NONE'],
            'face_reset'=>['Face Reset','NONE'],
            'shift_swap'=>['Shift Swap','PEER'],
            'attendance_correction'=>['Attendance Correction','NONE'],
            'salary_advance'=>['Salary Advance','NONE'],
        ];
        foreach($defaults as $key=>$def){
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['workflows']} WHERE workflow_key=%s LIMIT 1",$key));
            if($exists)continue;
            $wpdb->insert($t['workflows'],[
                'workflow_key'=>$key,
                'name'=>$def[0],
                'approval_mode'=>$def[1],
                'active'=>0,
                'created_at'=>current_time('mysql'),
                'updated_at'=>current_time('mysql'),
            ],['%s','%s','%s','%d','%s','%s']);
        }
    }

    private function approval_related_employee($employee_id,$relationship_type){
        global $wpdb;
        $t=$this->approval_tables();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT e.* FROM {$t['relationships']} r
             JOIN {$this->employees} e ON e.id=r.related_employee_id
             WHERE r.employee_id=%d AND r.relationship_type=%s AND r.active=1 AND e.active=1
             ORDER BY r.id DESC LIMIT 1",
            (int)$employee_id,sanitize_key($relationship_type)
        ));
    }

    /**
     * Each employee's active supervisor id (0 = none) for many employees in one query; the same
     * answer as approval_related_employee($id,'supervisor') per employee (the newest relationship).
     * @param list<int> $employee_ids @return array<int,int>
     */
    private function approval_supervisor_ids(array $employee_ids){
        global $wpdb;
        $t=$this->approval_tables();
        $ids=array_values(array_unique(array_filter(array_map('intval',$employee_ids))));
        $out=array_fill_keys($ids,0);
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'%d'));
            $rows=$wpdb->get_results($wpdb->prepare("SELECT r.employee_id,r.related_employee_id FROM {$t['relationships']} r JOIN {$this->employees} e ON e.id=r.related_employee_id
                WHERE r.employee_id IN ($ph) AND r.relationship_type='supervisor' AND r.active=1 AND e.active=1 ORDER BY r.id DESC",$chunk));
            foreach((array)$rows as $r){ if(empty($out[(int)$r->employee_id]))$out[(int)$r->employee_id]=(int)$r->related_employee_id; }
        }
        return $out;
    }

    /**
     * The steps waiting for an employee's direct manager (resolver SUPERVISOR, now or at a later level)
     * on requests still open, per requester and approver, for the Org chart's Move (3.31.98).
     * @return array<int,array<int,int>> requester employee id => [approver employee id => steps]
     */
    private function approval_waiting_on_supervisors(){
        global $wpdb;
        $t=$this->approval_tables();
        $out=[];
        foreach((array)$wpdb->get_results("SELECT r.requester_employee_id req,i.approver_employee_id appr,COUNT(*) n FROM {$t['instances']} i JOIN {$t['requests']} r ON r.id=i.approval_request_id
            WHERE i.resolver_type='SUPERVISOR' AND i.status IN ('PENDING','WAITING') AND i.approver_employee_id IS NOT NULL AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED')
            GROUP BY r.requester_employee_id,i.approver_employee_id") as $x)$out[(int)$x->req][(int)$x->appr]=(int)$x->n;
        return $out;
    }

    /**
     * An employee was moved under another manager (3.31.98): the steps of their open requests waiting
     * for the old manager now wait for the new one, who is told. Steps already decided keep who
     * decided them. The new manager is told by approval_reassign_notify(), after the caller's commit (3.31.119).
     * @param int $pending set to the moved steps waiting now @return int|false steps moved, false on a database error
     */
    private function approval_reassign_supervisor($employee_id,$from_employee_id,$to_employee_id,&$pending=0){
        $pending=0;
        global $wpdb;
        $t=$this->approval_tables();
        $from=(int)$from_employee_id;$to=$to_employee_id?$this->people_employee((int)$to_employee_id,true):null;
        if(!$from || !$to || (int)$to->id===$from)return 0;
        $ids=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT i.id FROM {$t['instances']} i JOIN {$t['requests']} r ON r.id=i.approval_request_id
            WHERE r.requester_employee_id=%d AND i.resolver_type='SUPERVISOR' AND i.status IN ('PENDING','WAITING') AND i.approver_employee_id=%d AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED')",(int)$employee_id,$from)));
        if(!$ids)return 0;
        $ph=implode(',',array_fill(0,count($ids),'%d'));
        if($wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET approver_employee_id=%d,approver_wp_user_id=%d,rerouted=0 WHERE id IN ($ph) AND status IN ('PENDING','WAITING')",array_merge([(int)$to->id,(int)$to->wp_user_id],$ids)))===false)return false;
        $pending=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['instances']} WHERE id IN ($ph) AND status='PENDING'",$ids));
        return count($ids);
    }

    /** The new manager is told about the moved requests waiting for them now (3.31.119: after the move is saved). */
    private function approval_reassign_notify($employee_id,$to_employee_id,$pending){
        $to=$to_employee_id?$this->people_employee((int)$to_employee_id,true):null;
        if($pending && $to && (int)$to->wp_user_id){
            $who=$this->people_name((int)$employee_id);
            $this->notify((int)$to->wp_user_id,'system',__('Requests now waiting for you','workforce-one'),
                sprintf(/* translators: %s: employee name */__('%s reports to you now: their requests waiting for approval now wait for you.','workforce-one'),$who),
                ['entity'=>'approval','push'=>true]);
        }
    }

    /** Resolve the actual approver from the configured resolver. */
    private function approval_resolve_approver($requester_employee_id,$resolver_type,$resolver_value=null,$entity_context=[]){
        global $wpdb;
        $resolver_type=strtoupper(sanitize_key($resolver_type));
        $entity_context=is_array($entity_context)?$entity_context:[];

        if($resolver_type==='SUPERVISOR'){
            return $this->approval_related_employee($requester_employee_id,'supervisor');
        }
        if($resolver_type==='TEAM_MANAGER'){
            return $this->team_manager_for_employee($requester_employee_id);
        }
        if($resolver_type==='MANAGERS_MANAGER'){
            $manager=$this->approval_related_employee($requester_employee_id,'supervisor');
            return $manager?$this->approval_related_employee((int)$manager->id,'supervisor'):null;
        }
        if($resolver_type==='DEPARTMENT_MANAGER'){
            return $this->people_department_manager((int)$requester_employee_id);
        }
        if($resolver_type==='HR' || $resolver_type==='FINANCE'){
            $uid=$this->approval_settings()[$resolver_type==='HR'?'hr_user':'finance_user'];
            if(!$uid || !get_user_by('id',$uid))return null;
            return $this->people_employee_by_user($uid)?:(object)['id'=>null,'wp_user_id'=>$uid];
        }
        if($resolver_type==='SPECIFIC_EMPLOYEE'){
            $id=absint($resolver_value);
            return $id?$this->people_employee($id,true):null;
        }
        if($resolver_type==='SPECIFIC_USER'){
            $uid=absint($resolver_value);
            if(!$uid)return null;
            $user=get_user_by('id',$uid);
            if(!$user)return null;
            $employee=$this->people_employee_by_user($uid);
            if($employee)return $employee;
            return (object)['id'=>null,'wp_user_id'=>$uid];
        }
        if($resolver_type==='TARGET_EMPLOYEE'){
            $id=absint(isset($entity_context['target_employee_id'])?$entity_context['target_employee_id']:0);
            return $id?$this->people_employee($id,true):null;
        }
        return null;
    }

    /** Create/update one active relationship; V1 keeps one current target per type. */
    private function approval_set_relationship($employee_id,$relationship_type,$related_employee_id=0,$related_wp_user_id=0){
        global $wpdb;
        $t=$this->approval_tables();
        $employee_id=absint($employee_id);
        $relationship_type=sanitize_key($relationship_type);
        $related_employee_id=absint($related_employee_id);
        $related_wp_user_id=absint($related_wp_user_id);
        if(!$employee_id||!$relationship_type)return new WP_Error('invalid_relationship','Invalid employee relationship.');
        if(!$related_employee_id&&!$related_wp_user_id)return new WP_Error('invalid_target','A related employee or WordPress user is required.');
        $ok=$wpdb->query($wpdb->prepare(
            "UPDATE {$t['relationships']} SET active=0,updated_at=%s WHERE employee_id=%d AND relationship_type=%s AND active=1",
            current_time('mysql'),$employee_id,$relationship_type
        ));
        if($ok===false)return new WP_Error('db_error',$wpdb->last_error);
        $ok=$wpdb->insert($t['relationships'],[
            'employee_id'=>$employee_id,
            'relationship_type'=>$relationship_type,
            'related_employee_id'=>$related_employee_id?:null,
            'related_wp_user_id'=>$related_wp_user_id?:null,
            'active'=>1,
            'created_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
        ],['%d','%s','%d','%d','%d','%s','%s']);
        if($ok===false)return new WP_Error('db_error',$wpdb->last_error);
        return (int)$wpdb->insert_id;
    }

    private function approval_workflow($workflow_key){
        global $wpdb;
        $t=$this->approval_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['workflows']} WHERE workflow_key=%s LIMIT 1",sanitize_key($workflow_key)));
    }

    private function approval_mode_family($mode){
        return \WorkforceOne\Approvals\Workflows::modeFamily((string)$mode);
    }

    /**
     * Save a workflow's mode and steps (one transaction). $supported limits the modes (the modes the
     * workflow's module handles); the checks are WorkforceOne\Approvals\Workflows::plan().
     * @return true|WP_Error
     */
    private function approval_configure_workflow($workflow_key,$mode,$step_configs=[],$supported=null){
        global $wpdb;
        $t=$this->approval_tables();
        $workflow=$this->approval_workflow($workflow_key);
        if(!$workflow)return new WP_Error('workflow_not_found','Workflow not found.');
        $mode=strtoupper(sanitize_key($mode));
        $plan=\WorkforceOne\Approvals\Workflows::plan($mode,is_array($step_configs)?$step_configs:[],$supported);
        if(!$plan['ok'])return new WP_Error($plan['code'],$plan['message']);
        $now=current_time('mysql');
        if($wpdb->query('START TRANSACTION')===false)return new WP_Error('db_error','Could not start configuration transaction.');
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$t['workflows']} SET approval_mode=%s,active=1,updated_at=%s WHERE id=%d",$mode,$now,(int)$workflow->id));
        if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error',$wpdb->last_error);}
        if($wpdb->query($wpdb->prepare("DELETE FROM {$t['steps']} WHERE workflow_id=%d",(int)$workflow->id))===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error',$wpdb->last_error);}
        foreach($plan['steps'] as $step){
            $row=['workflow_id'=>(int)$workflow->id,'step_order'=>$step['step_order'],'step_type'=>$step['step_type'],'resolver_type'=>$step['resolver_type'],'resolver_value'=>$step['resolver_value'],'required'=>1,'active'=>1,'created_at'=>$now,'updated_at'=>$now];
            $fmt=['%d','%d','%s','%s','%s','%d','%d','%s','%s'];
            if($step['min_amount']!==null){$row['min_amount']=$step['min_amount'];$fmt[]='%f';}
            $ok=$wpdb->insert($t['steps'],$row,$fmt);
            if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error',$wpdb->last_error);}
        }
        $commit=$wpdb->query('COMMIT');
        if($commit===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error','Could not commit workflow configuration: '.($wpdb->last_error?:'database commit failed').'.');}
        return true;
    }

    private function approval_find_request($workflow_key,$entity_type,$entity_id){
        global $wpdb;
        $t=$this->approval_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT ar.*,w.workflow_key,w.approval_mode FROM {$t['requests']} ar JOIN {$t['workflows']} w ON w.id=ar.workflow_id WHERE w.workflow_key=%s AND ar.entity_type=%s AND ar.entity_id=%d ORDER BY ar.id DESC LIMIT 1",sanitize_key($workflow_key),sanitize_key($entity_type),(int)$entity_id));
    }

    private function approval_current_step($approval_request_id){
        global $wpdb;
        $t=$this->approval_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='PENDING' ORDER BY step_order ASC LIMIT 1",(int)$approval_request_id));
    }

    /**
     * approval_current_step() for many requests with one query (3.31.112, the wp-admin Requests hub): request id => the first
     * PENDING level (a request with none is missing from the map). Only for showing lists.
     * @param array<int,int> $approval_request_ids
     * @return array<int,object>
     */
    private function approval_current_steps(array $approval_request_ids){
        global $wpdb;$t=$this->approval_tables();$out=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$approval_request_ids))));
        foreach(array_chunk($ids,500) as $chunk){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE status='PENDING' AND approval_request_id IN (".implode(',',array_fill(0,count($chunk),'%d')).') ORDER BY approval_request_id ASC,step_order ASC',$chunk)) as $r){
                if(!isset($out[(int)$r->approval_request_id]))$out[(int)$r->approval_request_id]=$r;
            }
        }
        return $out;
    }

    /** Start an approval instance and snapshot all resolved approvers. */
    private function approval_start($workflow_key,$entity_type,$entity_id,$requester_employee_id,$entity_context=[],$manage_transaction=true){
        global $wpdb;
        $t=$this->approval_tables();
        $workflow=$wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t['workflows']} WHERE workflow_key=%s AND active=1 LIMIT 1",
            sanitize_key($workflow_key)
        ));
        if(!$workflow)return new WP_Error('approval_workflow_not_found','Approval workflow is not configured.');

        $existing=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t['requests']} WHERE entity_type=%s AND entity_id=%d AND workflow_id=%d LIMIT 1",
            sanitize_key($entity_type),(int)$entity_id,(int)$workflow->id
        ));
        if($existing)return new WP_Error('approval_already_started','Approval workflow already exists for this request.');

        $steps=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t['steps']} WHERE workflow_id=%d AND active=1 ORDER BY step_order ASC",
            (int)$workflow->id
        ));

        $mode=strtoupper((string)$workflow->approval_mode);
        $family=$this->approval_mode_family($mode);
        if($family==='NONE')$steps=[];
        elseif(!in_array($family,['SEQUENTIAL','PEER'],true))return new WP_Error('approval_invalid_mode','Approval workflow has an invalid approval mode.');
        elseif(!$steps&&$family!=='PEER')return new WP_Error('approval_steps_missing','Approval workflow requires configured approval steps.');
        if($family==='PEER'&&!$steps){
            $target_id=absint($entity_context['target_employee_id']??0);
            if(!$target_id)return new WP_Error('peer_target_missing','Peer approval requires target_employee_id.');
            $steps=[(object)['step_order'=>1,'step_type'=>'PEER','resolver_type'=>'TARGET_EMPLOYEE','resolver_value'=>null,'required'=>1,'active'=>1]];
        }

        if(!$steps){
            $ok=$wpdb->insert($t['requests'],[
                'workflow_id'=>(int)$workflow->id,
                'entity_type'=>sanitize_key($entity_type),
                'entity_id'=>(int)$entity_id,
                'requester_employee_id'=>(int)$requester_employee_id,
                'status'=>'APPROVED',
                'current_step'=>null,
                'completed_at'=>current_time('mysql'),
            ],['%d','%s','%d','%d','%s',null,'%s']);
            if($ok===false)return new WP_Error('approval_db_error',$wpdb->last_error);
            return (int)$wpdb->insert_id;
        }

        // Who decides each level, fixed when the request starts. A level whose condition does not apply
        // is skipped; when its approver is the requester, the workflow's rule decides (3.31.100). A level
        // with nobody to decide it (no manager, HR not set, an approver without a WordPress user) goes to
        // the next level, or to administrators when there is none: the request is never refused for it
        // (3.31.101; before, a requester without a manager could not ask at all).
        $W=\WorkforceOne\Approvals\Workflows::class;
        $amount=isset($entity_context['amount']) && is_numeric($entity_context['amount'])?(float)$entity_context['amount']:null;
        $self_rule=$this->approval_self_rule($workflow_key);
        $requester_uid=(int)$this->people_user_id((int)$requester_employee_id);
        $plan=[];$facts=[];
        foreach($steps as $step){
            $order=(int)$step->step_order;
            $min=isset($step->min_amount)?$W::minAmount($step->min_amount):null;
            $approver=null;$rerouted=false;$skip='';
            if($family!=='PEER' && !$W::applies($min,$amount)){
                $skip='condition';
            }else{
                $approver=$this->approval_resolve_approver($requester_employee_id,$step->resolver_type,$step->resolver_value,$entity_context);
                if($approver && !(int)$approver->wp_user_id)$approver=null;
                if($family==='PEER'){
                    if(!$approver)return new WP_Error('approval_approver_missing','The colleague of this request could not be found.');
                }elseif($approver && $self_rule!=='allow'
                    && (((int)($approver->id??0) && (int)$approver->id===(int)$requester_employee_id) || ($requester_uid && (int)$approver->wp_user_id===$requester_uid))){
                    $approver=null;   // decided by the next level, or by an administrator (approval_can_act)
                    if($self_rule==='next')$skip='self';else $rerouted=true;
                }elseif(!$approver){
                    $skip='missing';  // nobody to decide it: the next level, or administrators
                }
            }
            $plan[$order]=['step'=>$step,'approver'=>$approver,'rerouted'=>$rerouted];
            $facts[$order]=['skip'=>$skip];
        }
        $start=\WorkforceOne\Approvals\StateMachine::start($facts,$family);
        $now=current_time('mysql');

        if($manage_transaction && $wpdb->query('START TRANSACTION')===false)return new WP_Error('approval_db_error','Could not start approval transaction.');
        $row=['workflow_id'=>(int)$workflow->id,'entity_type'=>sanitize_key($entity_type),'entity_id'=>(int)$entity_id,'requester_employee_id'=>(int)$requester_employee_id,'status'=>$start['request']];
        $fmt=['%d','%s','%d','%d','%s'];
        if($start['current_step']!==null){$row['current_step']=$start['current_step'];$fmt[]='%d';}else{$row['completed_at']=$now;$fmt[]='%s';}
        $ok=$wpdb->insert($t['requests'],$row,$fmt);
        if($ok===false){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        $approval_id=(int)$wpdb->insert_id;

        $admins_now=0;
        foreach($plan as $order=>$p){
            $st=$start['steps'][$order];$approver=$p['approver'];$rerouted=$p['rerouted']||$st['rerouted'];
            $row=['approval_request_id'=>$approval_id,'step_order'=>$order,'resolver_type'=>$p['step']->resolver_type,'resolver_value'=>$p['step']->resolver_value,'status'=>$st['status'],'rerouted'=>$rerouted?1:0];
            $fmt=['%d','%d','%s','%s','%s','%d'];
            // A manager away with a substitute (3.31.107): the level waits for the substitute, "for" the manager.
            $route=$approver && $st['status']==='PENDING' && (int)($approver->id??0)?$this->substitute_route((int)$approver->id,(int)$requester_employee_id):null;
            if($route){$row['for_employee_id']=(int)$approver->id;$row['substitute_id']=(int)$route['row']->id;$fmt[]='%d';$fmt[]='%d';$approver=$route['employee'];}
            if($approver){$row['approver_employee_id']=$approver->id?(int)$approver->id:null;$row['approver_wp_user_id']=(int)$approver->wp_user_id;$fmt[]='%d';$fmt[]='%d';}
            if($st['skip']!==''){$row['skip_reason']=$st['skip'];$fmt[]='%s';}
            if($st['status']==='PENDING'){$row['pending_since']=$now;$fmt[]='%s';if($rerouted && !$approver)$admins_now=$order;}
            $ok=$wpdb->insert($t['instances'],$row,$fmt);
            if($ok===false){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        }
        if($manage_transaction && $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error','Could not commit approval request.');}
        if($admins_now){if($manage_transaction)$this->approval_notify_administrators($approval_id,$admins_now);else $this->approval_notify_administrators_after($approval_id,$admins_now);}
        return $approval_id;
    }

    private function approval_can_act($approval_request_id,$step_order=null){
        if(!is_user_logged_in())return false;
        global $wpdb;
        $t=$this->approval_tables();
        $sql="SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='PENDING'";
        $args=[(int)$approval_request_id];
        if($step_order!==null){$sql.=' AND step_order=%d';$args[]=(int)$step_order;}
        $sql.=' LIMIT 1';
        $step=$wpdb->get_row($wpdb->prepare($sql,$args));
        if(!$step)return false;
        return $this->approval_may_decide($step,(int)get_current_user_id(),false)===true;
    }

    /**
     * Whether $uid may decide this pending step: its approver, or (a step sent to administrators, or
     * $admin_override) an administrator. A requester deciding their own request needs the workflow to
     * allow self-approval. @return true|WP_Error
     */
    private function approval_may_decide($step,$uid,$admin_override){
        global $wpdb;$t=$this->approval_tables();
        $request=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE id=%d",(int)$step->approval_request_id));
        if(!$request)return new WP_Error('approval_not_found','Approval request not found.');
        $is_self=$uid && $uid===$this->approval_requester_user_id($request);
        if($is_self && !$this->approval_self_allowed($this->approval_workflow_key_by_id((int)$request->workflow_id)))
            return new WP_Error('approval_self_not_allowed','This workflow does not allow approving your own request.');
        if($admin_override)return true;
        if($uid && (int)$step->approver_wp_user_id===$uid)return true;
        if($this->substitute_manager_may_decide($step,$uid))return true;   // the manager away, deciding anyway (3.31.107)
        if(!empty($step->rerouted) && !(int)$step->approver_wp_user_id && $uid && user_can($uid,'manage_options'))return true;
        return new WP_Error('approval_not_authorized','You are not the assigned approver.');
    }

    /**
     * Apply a decision to the current pending step and advance the state machine. $expected_step: the level the form showed
     * (approval_posted_step(), 3.31.122); when the open level is another one the decision is refused (approval_moved), and the
     * update touches only that step. null = the form did not say: the current level, as before.
     */
    private function approval_act($approval_request_id,$decision,$comment='',$manage_transaction=true,$allow_admin_override=false,$expected_step=null){
        global $wpdb;
        $t=$this->approval_tables();
        $approval_request_id=absint($approval_request_id);
        $decision=sanitize_key($decision);
        $comment=sanitize_textarea_field($comment);
        if(!$approval_request_id||!in_array($decision,['approve','reject'],true))return new WP_Error('invalid_decision','Invalid approval decision.');
        $request=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE id=%d LIMIT 1",$approval_request_id));
        if(!$request)return new WP_Error('approval_not_found','Approval request not found.');
        $step=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='PENDING' ORDER BY step_order ASC LIMIT 1",$approval_request_id));
        // Before the approver check, so the approver of a level already decided is told it moved, not that it is not theirs.
        if(\WorkforceOne\Approvals\LevelGuard::check($expected_step===null?null:(int)$expected_step,$step?(int)$step->id:0)!==\WorkforceOne\Approvals\LevelGuard::OK)return $this->approval_moved_error();
        if(!$step)return new WP_Error('approval_not_pending','No pending approval step.');
        $uid=(int)get_current_user_id();
        if(!$allow_admin_override && !is_user_logged_in())return new WP_Error('approval_not_authorized','You are not the assigned approver.');
        $may=$this->approval_may_decide($step,$uid,(bool)$allow_admin_override);
        if(is_wp_error($may))return $may;
        // A reason to reject, when the workflow asks for one (3.31.103); an administrator's override on the Requests Hub is not held to it.
        if($decision==='reject' && trim($comment)==='' && !$allow_admin_override && $this->approval_reject_note_required($this->approval_workflow_key_by_id((int)$request->workflow_id)))
            return new WP_Error('approval_note_required','Write why you reject it.');
        $is_self=$uid && $uid===$this->approval_requester_user_id($request);

        $now=current_time('mysql');
        $next=$decision==='approve'?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='WAITING' ORDER BY step_order ASC LIMIT 1",$approval_request_id)):null;
        $to=\WorkforceOne\Approvals\StateMachine::afterDecision($decision,$next?(int)$next->step_order:null);
        if($manage_transaction && $wpdb->query('START TRANSACTION')===false)return new WP_Error('approval_db_error','Could not start approval transaction.');
        $ok=$wpdb->update($t['instances'],[
            'status'=>$to['step'],
            'decision'=>$decision,
            'comment'=>$comment,
            'acted_at'=>$now,
            'acted_by_wp_user_id'=>$uid?:null,
            'self_decision'=>$is_self?1:0,
        ],['id'=>(int)$step->id,'status'=>'PENDING'],['%s','%s','%s','%s','%d','%d'],['%d','%s']);
        if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return $expected_step!==null?$this->approval_moved_error():new WP_Error('approval_stale','Approval step was already handled.');}
        if($next){
            $set=['status'=>'PENDING','pending_since'=>$now];
            $route=(int)$next->approver_employee_id?$this->substitute_route((int)$next->approver_employee_id,(int)$request->requester_employee_id):null;
            if($route){$set+=['approver_employee_id'=>(int)$route['employee']->id,'approver_wp_user_id'=>(int)$route['employee']->wp_user_id,'for_employee_id'=>(int)$next->approver_employee_id,'substitute_id'=>(int)$route['row']->id];}
            $ok=$wpdb->update($t['instances'],$set,['id'=>(int)$next->id,'status'=>'WAITING'],null,['%d','%s']);
            if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'current_step'=>$to['current_step']],['id'=>$approval_request_id],['%s','%d'],['%d']);
        }elseif($decision==='reject'){
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'completed_at'=>$now],['id'=>$approval_request_id],['%s','%s'],['%d']);
        }else{
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'current_step'=>null,'completed_at'=>$now],['id'=>$approval_request_id],['%s','%d','%s'],['%d']);
        }
        if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        if($manage_transaction && $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error','Could not commit approval decision.');}
        $this->substitute_after_decision($step,$uid);
        if($is_self)$this->audit('approval_self_decision','approval_request',$approval_request_id,
            $this->approval_workflow_key_by_id((int)$request->workflow_id).' · level '.(int)$step->step_order.' · '.$decision);
        if($next && !empty($next->rerouted) && !(int)$next->approver_wp_user_id){
            if($manage_transaction)$this->approval_notify_administrators($approval_request_id,(int)$next->step_order);else $this->approval_notify_administrators_after($approval_request_id,(int)$next->step_order);
        }
        return true;
    }
    /**
     * The correction requests that go to HR whatever the mode (Attendance Corrections → Settings), for
     * the Attendance Correction card. @return array{on:bool,cases:list<string>,url:string}
     */
    private function approval_correction_hr_cases(){
        $s=$this->correction_settings();$cases=[];
        if(!empty($s['earlier_needs_hr']))$cases[]='a Sign In moved earlier (it removes lateness)';
        if(($s['above_limit']??'')==='hr' && (int)$s['monthly_limit']>0)$cases[]='a request above the monthly limit of '.(int)$s['monthly_limit'];
        return ['on'=>$this->corrections_enabled(),'cases'=>$cases,'url'=>admin_url('admin.php?page=ews31-corrections&tab=settings')];
    }

    /** Who approves a level, for people (3.31.103). */
    private function approval_resolver_label($resolver_type){
        $l=['SUPERVISOR'=>__('Direct manager','workforce-one'),'MANAGERS_MANAGER'=>__("Manager's manager",'workforce-one'),'DEPARTMENT_MANAGER'=>__('Department manager','workforce-one'),
            'TEAM_MANAGER'=>__('Team manager','workforce-one'),'HR'=>__('HR','workforce-one'),'FINANCE'=>__('Finance','workforce-one'),
            'SPECIFIC_EMPLOYEE'=>__('Approver','workforce-one'),'SPECIFIC_USER'=>__('Approver','workforce-one'),'TARGET_EMPLOYEE'=>__('Colleague','workforce-one')];
        return $l[strtoupper((string)$resolver_type)]??__('Approver','workforce-one');
    }

    private function approval_user_name($uid){
        $uid=(int)$uid;if(!$uid)return '';
        $e=$this->people_employee_by_user($uid);if($e)return (string)$e->name;
        $u=get_userdata($uid);return $u?(string)$u->display_name:'';
    }

    /** The key a request is found under in the batch helpers. */
    private function approval_request_key($workflow_key,$entity_type,$entity_id){
        return sanitize_key((string)$workflow_key).'|'.sanitize_key((string)$entity_type).'|'.(int)$entity_id;
    }

    /**
     * approval_find_request() for many requests with one query (3.31.112): [[workflow_key, entity_type, entity_id], ...]
     * to a map "workflow|entity|id" => the row (a request with none is missing from the map). The newest request of an entity
     * wins, as with LIMIT 1. Only for showing lists: the decision handlers keep re-reading one request, inside their transaction.
     * @param array<int,array{0:string,1:string,2:int}> $keys
     * @return array<string,object>
     */
    private function approval_find_requests(array $keys){
        global $wpdb;
        $groups=[];
        foreach($keys as $k){
            if(!is_array($k)||count($k)<3)continue;
            $groups[sanitize_key((string)$k[0])."\0".sanitize_key((string)$k[1])][(int)$k[2]]=true;
        }
        if(!$groups)return [];
        $t=$this->approval_tables();$out=[];
        $where=[];
        foreach($groups as $g=>$ids){
            [$wk,$et]=explode("\0",$g);
            foreach(array_chunk(array_keys($ids),500) as $chunk)$where[]=$wpdb->prepare('(w.workflow_key=%s AND ar.entity_type=%s AND ar.entity_id IN ('.implode(',',array_fill(0,count($chunk),'%d')).'))',array_merge([$wk,$et],$chunk));
        }
        $rows=(array)$wpdb->get_results("SELECT ar.*,w.workflow_key,w.approval_mode FROM {$t['requests']} ar JOIN {$t['workflows']} w ON w.id=ar.workflow_id WHERE ".implode(' OR ',$where).' ORDER BY ar.id DESC');
        foreach($rows as $r){
            $key=$this->approval_request_key($r->workflow_key,$r->entity_type,$r->entity_id);
            if(!isset($out[$key]))$out[$key]=$r;
        }
        return $out;
    }

    /**
     * Where a request is, level by level, for My approvals and My requests (3.31.103): each level's
     * approver and state (done / now / next / skipped and why / rejected / cancelled), which level it
     * waits at, for how many working hours, from whom it moved up, and the reason of a rejection.
     * One request (My requests card, Advances), or null; the same code as approval_paths() (3.31.112).
     * @return array{id:int,workflow:string,status:string,level:int,levels:int,hours:float,overdue:bool,moved_from:string,note:string,steps:list<array{order:int,state:string,label:string,who:string,sub:string}>}|null
     */
    private function approval_path($workflow_key,$entity_type,$entity_id){
        $paths=$this->approval_paths([[$workflow_key,$entity_type,$entity_id]]);
        return $paths[$this->approval_request_key($workflow_key,$entity_type,$entity_id)]??null;
    }

    /**
     * The paths of many requests with a handful of queries (3.31.112) instead of several per request: the requests, their
     * levels, and the names of everyone on them. Same result as approval_path() for each; a request with no approval is null.
     * @param array<int,array{0:string,1:string,2:int}> $keys
     * @param string|null $now the time the waiting hours are counted to (default: now)
     * @return array<string,array{id:int,workflow:string,status:string,level:int,levels:int,hours:float,overdue:bool,moved_from:string,note:string,steps:list<array{order:int,state:string,label:string,who:string,sub:string}>}|null> by approval_request_key()
     */
    private function approval_paths(array $keys,$now=null){
        global $wpdb;$t=$this->approval_tables();
        $found=$this->approval_find_requests($keys);
        $out=[];
        foreach($keys as $k)$out[$this->approval_request_key($k[0],$k[1],$k[2])]=null;
        if(!$found)return $out;
        $by_request=[];
        $ids=array_map(function($r){return (int)$r->id;},array_values($found));
        foreach(array_chunk($ids,500) as $chunk){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id IN (".implode(',',array_fill(0,count($chunk),'%d')).') ORDER BY approval_request_id ASC,step_order ASC',$chunk)) as $r)$by_request[(int)$r->approval_request_id][]=$r;
        }
        // Everyone's name: the active employee linked to the user (lowest id first), else the WordPress display name.
        $uids=[];$for_ids=[];
        foreach($by_request as $rows)foreach($rows as $r){
            foreach(['approver_wp_user_id','acted_by_wp_user_id','escalated_from_wp_user_id'] as $f)if((int)($r->$f??0))$uids[(int)$r->$f]=true;
            if((int)($r->for_employee_id??0))$for_ids[(int)$r->for_employee_id]=true;
        }
        $emp_by_user=$this->people_names_by_user(array_keys($uids));
        $missing=array_values(array_diff(array_keys($uids),array_keys($emp_by_user)));
        if($missing)cache_users($missing);
        $for_by_id=$this->people_brief_by_ids(array_keys($for_ids));
        $name=function($uid)use($emp_by_user){
            $uid=(int)$uid;if(!$uid)return '';
            if(isset($emp_by_user[$uid]))return $emp_by_user[$uid];
            $u=get_userdata($uid);return $u?(string)$u->display_name:'';
        };
        $empname=function($id)use($for_by_id){return isset($for_by_id[(int)$id])?(string)$for_by_id[(int)$id]->name:'';};
        $empuser=function($id)use($for_by_id){return isset($for_by_id[(int)$id])?(int)$for_by_id[(int)$id]->wp_user_id:0;};
        $s=$this->approval_settings();$days=$this->working_days();$now=$now?:current_time('mysql');
        foreach($keys as $k){
            $key=$this->approval_request_key($k[0],$k[1],$k[2]);
            if(!isset($found[$key]))continue;
            $ar=$found[$key];
            $rows=$by_request[(int)$ar->id]??[];
            $workflow_key=(string)$k[0];
            $levels=count(array_filter($rows,function($r){return $r->status!=='SKIPPED';}));
            $pos=0;$level=0;$hours=0.0;$moved='';$note='';$steps=[];
            foreach($rows as $r){
                $who=(int)$r->approver_wp_user_id?$name((int)$r->approver_wp_user_id):(!empty($r->rerouted)?__('Administrators','workforce-one'):'');
                $by=$name((int)($r->acted_by_wp_user_id??0));
                // Decided by a substitute while the approver is away (3.31.107): "Sami Ali for Mona Adel".
                $for=(int)($r->for_employee_id??0)?$empname((int)$r->for_employee_id):'';
                if($for!==''){
                    if($who!=='')$who=sprintf(/* translators: 1: substitute, 2: the manager away */__('%1$s for %2$s','workforce-one'),$who,$for);
                    if($by!=='' && (int)$r->acted_by_wp_user_id!==(int)$empuser((int)$r->for_employee_id))$by=sprintf(/* translators: 1: substitute, 2: the manager away */__('%1$s for %2$s','workforce-one'),$by,$for);
                }
                $when=$r->acted_at?date_i18n('D j M, H:i',strtotime((string)$r->acted_at)):'';
                switch($r->status){
                    case 'APPROVED': $state='done';$sub=sprintf(/* translators: 1: person, 2: date and time */__('%1$s approved · %2$s','workforce-one'),$by?:$who,$when);break;
                    case 'REJECTED': $state='rejected';$sub=sprintf(/* translators: 1: person, 2: date and time */__('%1$s rejected · %2$s','workforce-one'),$by?:$who,$when);if((string)$r->comment!=='')$note=(string)$r->comment;break;
                    case 'PENDING':
                        $state='now';$hours=$r->pending_since?\WorkforceOne\Approvals\WorkingHours::between((string)$r->pending_since,$now,$days,$s['day_start'],$s['day_end']):0.0;
                        $sub=sprintf(/* translators: %s: working hours */__('Deciding now · waiting %s working hours','workforce-one'),number_format_i18n($hours,$hours<10&&floor($hours)!=$hours?1:0));
                        if((int)($r->escalations??0) && (int)($r->escalated_from_wp_user_id??0))$moved=$name((int)$r->escalated_from_wp_user_id);
                        break;
                    case 'WAITING': $state='next';$sub=__('Next','workforce-one');break;
                    case 'CANCELLED': $state='cancelled';$sub=($r->skip_reason??'')==='closed'?__('Closed automatically','workforce-one'):__('Withdrawn by the employee','workforce-one');break;
                    default:
                        $state='skipped';
                        $sub=($r->skip_reason??'')==='condition'?__('Not needed for this request','workforce-one'):(($r->skip_reason??'')==='self'?__('Skipped: the approver made the request','workforce-one'):__('Skipped: nobody to decide it','workforce-one'));
                }
                if($r->status!=='SKIPPED'){$pos++;if($r->status==='PENDING')$level=$pos;}
                $steps[]=['order'=>(int)$r->step_order,'state'=>$state,'label'=>$this->approval_resolver_label((string)$r->resolver_type),'who'=>$who,'sub'=>$sub];
            }
            $wkey=(string)($ar->workflow_key??$workflow_key);
            $out[$key]=['id'=>(int)$ar->id,'workflow'=>$wkey,'status'=>(string)$ar->status,'level'=>$level,'levels'=>$levels,'hours'=>$hours,
                'overdue'=>$level>0 && $hours>=$this->approval_overdue_hours($wkey),'moved_from'=>$moved,'note'=>$note,'steps'=>$steps];
        }
        return $out;
    }

    /**
     * The employee withdrew a request still waiting (3.31.103, My requests): its approval request is
     * closed and the levels still to decide are marked withdrawn. The caller owns the transaction.
     * @return bool
     */
    private function approval_close($workflow_key,$entity_type,$entity_id){
        $ar=$this->approval_find_request($workflow_key,$entity_type,$entity_id);
        $this->substitutes_sync_at_shutdown();   // a withdrawn leave cancels its substitute (3.31.107)
        if(!$ar)return true;
        if(in_array($ar->status,['APPROVED','REJECTED','CANCELLED'],true))return false;
        global $wpdb;$t=$this->approval_tables();$now=current_time('mysql');
        if($wpdb->update($t['requests'],['status'=>'CANCELLED','current_step'=>null,'completed_at'=>$now],['id'=>(int)$ar->id,'status'=>(string)$ar->status])!==1)return false;
        return $wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET status='CANCELLED' WHERE approval_request_id=%d AND status IN ('PENDING','WAITING')",(int)$ar->id))!==false;
    }

    /**
     * Cancels an entity's approval request if it is still open (3.31.117, attendance corrections). Unlike approval_close(), a request
     * already decided when read (APPROVED by the manager levels of a request now waiting for HR, REJECTED, CANCELLED) is left as it
     * is and counts as done. The caller owns the transaction.
     * $closed: closed by the system, not withdrawn; the levels still to decide say "Closed automatically" (skip_reason 'closed').
     * @return true|string|false true: cancelled or nothing open; 'moved': it was decided while being closed; false: a database error
     */
    private function approval_close_open($workflow_key,$entity_type,$entity_id,$closed=false){
        global $wpdb;
        $wpdb->last_error='';
        $ar=$this->approval_find_request($workflow_key,$entity_type,$entity_id);
        if(!$ar && $wpdb->last_error!=='')return false;   // not read: not "nothing open"
        $this->substitutes_sync_at_shutdown();
        if(!$ar || in_array($ar->status,['APPROVED','REJECTED','CANCELLED'],true))return true;
        $t=$this->approval_tables();
        // The levels first, then the request: the order approval_act() locks them in, so a decision at the same moment waits instead of
        // deadlocking. Both roll back with the caller's transaction when the request was decided meanwhile.
        if($wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET status='CANCELLED'".($closed?",skip_reason='closed'":'')." WHERE approval_request_id=%d AND status IN ('PENDING','WAITING')",(int)$ar->id))===false)return false;
        $n=$wpdb->update($t['requests'],['status'=>'CANCELLED','current_step'=>null,'completed_at'=>current_time('mysql')],['id'=>(int)$ar->id,'status'=>(string)$ar->status]);
        if($n===false)return false;
        return $n===1?true:'moved';
    }

    /** wp-admin → Approval Workflows: the mode and approvers of each workflow in use, and supervisors. */
    public function admin_approval_workflows(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        global $wpdb;$t=$this->approval_tables();$this->ensure_approval_schema();
        $employees=(array)$this->people_active_list();
        // Supervisor Relationships: 50 employees per page with a search (3.31.71).
        $sup_search=sanitize_text_field(wp_unslash($_GET['s']??''));$per=50;
        $listed=$employees;
        if($sup_search!==''){$n=function_exists('mb_strtolower')?'mb_strtolower':'strtolower';$q=$n($sup_search);$listed=array_values(array_filter($employees,function($e)use($q,$n){return strpos($n($e->name.' '.$e->domain_name),$q)!==false;}));}
        $sup_total=count($listed);$sup_pages=max(1,(int)ceil($sup_total/$per));$sup_paged=min(max(1,absint($_GET['paged']??1)),$sup_pages);
        $listed=array_slice($listed,($sup_paged-1)*$per,$per);
        $users=get_users(['fields'=>['ID','display_name','user_email'],'orderby'=>'display_name','order'=>'ASC']);
        $cards=[];$W=\WorkforceOne\Approvals\Workflows::class;
        foreach(\WorkforceOne\Approvals\Workflows::IN_USE as $key=>[$label,$supported]){
            $workflow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['workflows']} WHERE workflow_key=%s LIMIT 1",$key));
            $levels=[];
            for($i=1;$i<=\WorkforceOne\Approvals\Workflows::MAX_LEVELS;$i++)$levels[$i]=['resolver_type'=>'SUPERVISOR','resolver_value'=>'','min_amount'=>null];
            if($workflow)foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['steps']} WHERE workflow_id=%d ORDER BY step_order",(int)$workflow->id)) as $st)$levels[(int)$st->step_order]=['resolver_type'=>$st->resolver_type,'resolver_value'=>$st->resolver_value,'min_amount'=>\WorkforceOne\Approvals\Workflows::minAmount($st->min_amount??null)];
            $mode=strtoupper((string)($workflow->approval_mode??'NONE'));
            $active=$workflow&&(int)$workflow->active===1;
            $cards[$key]=['label'=>$label,'modes'=>$supported,'none_means'=>$W::NONE_MEANS[$key],'mode'=>in_array($mode,$supported,true)?$mode:$supported[0],'levels'=>$levels,
                'unsupported'=>$active&&!in_array($mode,$supported,true)?$mode:'','allow_self'=>$this->approval_self_allowed($key),
                'self_rule'=>$this->approval_self_rule($key),'deadlines'=>$this->approval_deadlines($key),'unit'=>$W::AMOUNT_UNITS[$key]??'',
                'overdue'=>$this->approval_overdue_hours($key),'overdue_setting'=>(int)(((array)get_option('ews_approval_overdue',[]))[$key]??0),'reject_note'=>$this->approval_reject_note_required($key),
                // Never saved: the module's own fallback applies, shown instead of a mode that is not in force (3.31.89).
                'active'=>$active,'not_set'=>$W::NOT_SET_MEANS[$key]??'',
                'mode_labels'=>($W::MODE_LABELS[$key]??[])+$W::MODES,'mode_help'=>$W::MODE_HELP[$key]??[],
                'hr_cases'=>$key==='attendance_correction'?$this->approval_correction_hr_cases():null];
        }
        $supervisors=$this->approval_supervisor_ids(array_map(function($e){return (int)$e->id;},$listed));
        $self_decisions=(array)$wpdb->get_results("SELECT s.approval_request_id,s.step_order,s.decision,s.acted_at,w.name workflow_name,r.entity_type,r.entity_id,e.name employee_name
            FROM {$t['instances']} s JOIN {$t['requests']} r ON r.id=s.approval_request_id JOIN {$t['workflows']} w ON w.id=r.workflow_id
            LEFT JOIN {$this->employees} e ON e.id=r.requester_employee_id WHERE s.self_decision=1 ORDER BY s.acted_at DESC,s.id DESC LIMIT 50");
        $error='';
        if(isset($_GET['approval_error'])){$error='Could not save approval configuration. Please verify the selected approvers.';$detail=sanitize_text_field(wp_unslash($_GET['approval_error_message']??''));if($detail!=='')$error.=' '.$detail;}
        echo $this->render_template('admin/approvals',['cards'=>$cards,'not_in_use'=>\WorkforceOne\Approvals\Workflows::NOT_IN_USE,'mode_labels'=>\WorkforceOne\Approvals\Workflows::MODES,
            'employees'=>$employees,'listed'=>$listed,'search'=>$sup_search,'paged'=>$sup_paged,'pages'=>$sup_pages,'total'=>$sup_total,'page_url'=>admin_url('admin.php?page=ews31-approvals'),'users'=>$users,'supervisors'=>$supervisors,'saved'=>isset($_GET['approval_saved']),'error'=>$error,'self_decisions'=>$self_decisions,
            'settings'=>$this->approval_settings(),'resolver_labels'=>$W::RESOLVER_LABELS,'self_rules'=>$W::SELF_RULES,'max_levels'=>$W::MAX_LEVELS]); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
    }

    public function approval_workflow_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_workflow_save');
        $workflow_key=sanitize_key($_POST['workflow_key']??'');
        $in_use=\WorkforceOne\Approvals\Workflows::IN_USE;
        if(!isset($in_use[$workflow_key]))wp_die('Invalid workflow.');
        $W=\WorkforceOne\Approvals\Workflows::class;
        $mode=strtoupper(sanitize_key($_POST['approval_mode']??'NONE'));$configs=[];$unit=$W::AMOUNT_UNITS[$workflow_key]??'';
        if($mode==='PEER'){$configs[1]=['resolver_type'=>'TARGET_EMPLOYEE','resolver_value'=>0];}else{for($i=1;$i<=$W::MAX_LEVELS;$i++){if(!isset($_POST['level_'.$i.'_type']))continue;$type=strtoupper(sanitize_key($_POST['level_'.$i.'_type']));$value=0;if($type==='SPECIFIC_EMPLOYEE')$value=$this->approval_picked('level_'.$i.'_employee');elseif($type==='SPECIFIC_USER')$value=$this->approval_picked('level_'.$i.'_user');
            // A level's condition (3.31.100): only for the workflows with an amount, and only when "If above" is chosen.
            $min=$unit!=='' && sanitize_key($_POST['level_'.$i.'_when']??'')==='above'?$W::minAmount(wp_unslash($_POST['level_'.$i.'_min']??'')):null;
            $configs[$i]=['resolver_type'=>$type,'resolver_value'=>$value,'min_amount'=>$min];}}
        $ok=$this->approval_configure_workflow($workflow_key,$mode,$configs,$in_use[$workflow_key][1]);
        if(!is_wp_error($ok)){
            // When the approver is the requester (3.31.100): a choice of three; a form from before sends the Own requests box.
            $rule=isset($_POST['self_rule'])?$W::selfRule(sanitize_key($_POST['self_rule'])):(!empty($_POST['allow_self'])?'allow':'admins');
            if($this->approval_self_rule($workflow_key)!==$rule)$this->audit('approval_self_setting','approval_workflow',0,$workflow_key.'='.($rule==='allow'?'allowed':($rule==='next'?'next level':'not allowed')));
            $rules=get_option('ews_approval_self_rule',[]);if(!is_array($rules))$rules=[];$rules[$workflow_key]=$rule;update_option('ews_approval_self_rule',$rules,false);
            $all=get_option('ews_approval_allow_self',[]);if(!is_array($all))$all=[];$all[$workflow_key]=$rule==='allow'?1:0;update_option('ews_approval_allow_self',$all,false);
            $d=$W::deadlines(absint($_POST['remind_hours']??0),absint($_POST['escalate_hours']??0));
            if($this->approval_deadlines($workflow_key)!==$d)$this->audit('approval_deadlines','approval_workflow',0,$workflow_key.' · remind '.$d['remind'].' h · move up '.$d['escalate'].' h');
            $dl=get_option('ews_approval_deadlines',[]);if(!is_array($dl))$dl=[];$dl[$workflow_key]=$d;update_option('ews_approval_deadlines',$dl,false);
            // My approvals (3.31.103): when a request is overdue, and whether rejecting needs a reason. A form without them keeps the saved values.
            if(isset($_POST['overdue_hours'])){
                $ov=get_option('ews_approval_overdue',[]);if(!is_array($ov))$ov=[];$h=max(0,min($W::MAX_DEADLINE_HOURS,absint($_POST['overdue_hours'])));
                if((int)($ov[$workflow_key]??0)!==$h)$this->audit('approval_overdue','approval_workflow',0,$workflow_key.' · overdue after '.($h?:'the reminder time / 24').' h');
                $ov[$workflow_key]=$h;update_option('ews_approval_overdue',$ov,false);
                $note=!empty($_POST['reject_note']);
                if($this->approval_reject_note_required($workflow_key)!==$note)$this->audit('approval_reject_note','approval_workflow',0,$workflow_key.'='.($note?'reason needed':'no reason needed'));
                $rn=get_option('ews_approval_reject_note',[]);if(!is_array($rn))$rn=[];$rn[$workflow_key]=$note?1:0;update_option('ews_approval_reject_note',$rn,false);
            }
        }
        if(is_wp_error($ok))wp_safe_redirect(add_query_arg(['approval_error'=>1,'approval_error_code'=>rawurlencode($ok->get_error_code()),'approval_error_message'=>rawurlencode($ok->get_error_message())],admin_url('admin.php?page=ews31-approvals')));else{$this->audit('approval_workflow_update','approval_workflow',0,$workflow_key.'='.$mode);wp_safe_redirect(add_query_arg('approval_saved',1,admin_url('admin.php?page=ews31-approvals')));}exit;
    }

    /** wp-admin → Approval Workflows → HR, Finance and the working day (admin-post ews_approval_settings_save; 3.31.100). */
    public function approval_settings_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_settings_save');
        $WH=\WorkforceOne\Approvals\WorkingHours::class;
        $start=sanitize_text_field(wp_unslash($_POST['day_start']??''));$end=sanitize_text_field(wp_unslash($_POST['day_end']??''));
        $back=admin_url('admin.php?page=ews31-approvals');
        if($WH::minutes($start)===null || $WH::minutes($end)===null || $WH::minutes($end)<=$WH::minutes($start)){wp_safe_redirect(add_query_arg(['approval_error'=>1,'approval_error_message'=>rawurlencode('The working day must end after it starts.')],$back).'#ews-approval-settings');exit;}
        $hr=$this->approval_picked('hr_user');$fin=$this->approval_picked('finance_user');
        foreach([$hr,$fin] as $uid)if($uid && !get_user_by('id',$uid)){wp_safe_redirect(add_query_arg(['approval_error'=>1,'approval_error_message'=>rawurlencode('Pick HR and Finance from the list.')],$back).'#ews-approval-settings');exit;}
        $new=['hr_user'=>$hr,'finance_user'=>$fin,'day_start'=>$start,'day_end'=>$end];
        update_option('ews_approval_settings',$new,false);
        $this->audit('approval_settings','approval_workflow',0,'HR user #'.$hr.' · Finance user #'.$fin.' · '.$start.'–'.$end);
        wp_safe_redirect(add_query_arg('approval_saved',1,$back).'#ews-approval-settings');exit;
    }

    public function approval_relationship_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_relationship_save');
        $employee_id=absint($_POST['employee_id']??0);$supervisor_id=isset($_POST['supervisor_employee_ref'])?\WorkforceOne\Support\Picker::parse((string)wp_unslash($_POST['supervisor_employee_ref'])):absint($_POST['supervisor_employee_id']??0);
        $this->ensure_approval_schema();global $wpdb;$t=$this->approval_tables();
        // Back to the same page and search of the Supervisor Relationships list.
        $back=['page'=>'ews31-approvals'];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$q);foreach(['paged','s'] as $k)if(!empty($q[$k]))$back[$k]=$k==='paged'?absint($q[$k]):sanitize_text_field((string)$q[$k]);
        $ok=$supervisor_id===0||($supervisor_id>0&&$supervisor_id!==$employee_id&&(bool)$this->people_active_id($supervisor_id));
        if(!$ok){wp_safe_redirect(add_query_arg($back+['approval_error'=>1,'approval_error_message'=>rawurlencode('Pick the supervisor from the list.')],admin_url('admin.php')).'#ews-supervisors');exit;}
        if($employee_id){$wpdb->query($wpdb->prepare("UPDATE {$t['relationships']} SET active=0,updated_at=%s WHERE employee_id=%d AND relationship_type='supervisor' AND active=1",current_time('mysql'),$employee_id));if($supervisor_id)$this->approval_set_relationship($employee_id,'supervisor',$supervisor_id,0);}
        $this->audit('approval_relationship_update','employee',$employee_id,'supervisor='.$supervisor_id);wp_safe_redirect(add_query_arg($back+['approval_saved'=>1],admin_url('admin.php')).'#ews-supervisors');exit;
    }

    /** A Specific Employee / User approver: "Name · #id" from the pick list (3.31.71), or the plain id. */
    private function approval_picked($field){
        if(isset($_POST[$field.'_ref']))return max(0,\WorkforceOne\Support\Picker::parse((string)wp_unslash($_POST[$field.'_ref'])));
        return absint($_POST[$field]??0);
    }

}
