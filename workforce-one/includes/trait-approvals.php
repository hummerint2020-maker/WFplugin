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
        return $this->services()->approvalReads()->levelMoved((int)$approval_request_id,$posted===null?null:(int)$posted);
    }

    /**
     * approval_level_moved() for a request by its key, whatever its workflow is set to now: a request whose approval is still open
     * after its workflow was switched off is shown with its open level, and a fresh page passes (3.31.122 review).
     */
    private function approval_level_moved_for($workflow_key,$entity_type,$entity_id,$posted){
        return $this->services()->approvalReads()->levelMovedFor(sanitize_key((string)$workflow_key),sanitize_key((string)$entity_type),(int)$entity_id,$posted===null?null:(int)$posted);
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
        return $this->services()->approvalReads()->shownSteps($keys);
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

    private function approval_workflow_key_by_id($workflow_id){
        return $this->services()->workflows()->keyById((int)$workflow_id);
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

    private function approval_seed_workflows(){
        $this->services()->workflows()->seedDefaults();
    }

    /**
     * The steps waiting for an employee's direct manager (resolver SUPERVISOR, now or at a later level)
     * on requests still open, per requester and approver, for the Org chart's Move (3.31.98).
     * @return array<int,array<int,int>> requester employee id => [approver employee id => steps]
     */
    private function approval_waiting_on_supervisors(){
        return $this->services()->approvalReads()->waitingOnSupervisors();
    }

    /**
     * An employee was moved under another manager (3.31.98): the steps of their open requests waiting
     * for the old manager now wait for the new one, who is told. Steps already decided keep who
     * decided them. The new manager is told by approval_reassign_notify(), after the caller's commit (3.31.119).
     * @param int $pending set to the moved steps waiting now @return int|false steps moved, false on a database error
     */
    private function approval_reassign_supervisor($employee_id,$from_employee_id,$to_employee_id,&$pending=0){
        $pending=0;
        $moved=0;
        $result=$this->services()->approvals()->reassignSupervisor((int)$employee_id,(int)$from_employee_id,(int)$to_employee_id,$moved);
        $pending=$moved;
        return $result;
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
        return $this->services()->workflows()->workflow(sanitize_key($workflow_key));
    }

    /**
     * Save a workflow's mode and steps (one transaction). $supported limits the modes (the modes the
     * workflow's module handles); the checks are WorkforceOne\Approvals\Workflows::plan().
     * @return true|WP_Error
     */
    private function approval_configure_workflow($workflow_key,$mode,$step_configs=[],$supported=null){
        $r=$this->services()->workflows()->configure(sanitize_key((string)$workflow_key),strtoupper(sanitize_key($mode)),is_array($step_configs)?$step_configs:[],$supported);
        return $r->ok?true:new WP_Error($r->code,$r->message);
    }

    private function approval_find_request($workflow_key,$entity_type,$entity_id){
        return $this->services()->approvalReads()->findRequest(sanitize_key($workflow_key),sanitize_key($entity_type),(int)$entity_id);
    }

    private function approval_current_step($approval_request_id){
        return $this->services()->approvalReads()->currentStep((int)$approval_request_id);
    }

    /**
     * approval_current_step() for many requests with one query (3.31.112, the wp-admin Requests hub): request id => the first
     * PENDING level (a request with none is missing from the map). Only for showing lists.
     * @param array<int,int> $approval_request_ids
     * @return array<int,object>
     */
    private function approval_current_steps(array $approval_request_ids){
        return $this->services()->approvalReads()->currentSteps($approval_request_ids);
    }

    /** Start an approval instance and snapshot all resolved approvers. */
    private function approval_start($workflow_key,$entity_type,$entity_id,$requester_employee_id,$entity_context=[],$manage_transaction=true){
        $r=$this->services()->approvals()->start(sanitize_key($workflow_key),sanitize_key($entity_type),(int)$entity_id,(int)$requester_employee_id,is_array($entity_context)?$entity_context:[],(bool)$manage_transaction);
        return $r->ok?$r->value:new WP_Error($r->code,$r->message);
    }

    private function approval_can_act($approval_request_id,$step_order=null){
        if(!is_user_logged_in())return false;
        return $this->services()->approvals()->canAct((int)$approval_request_id,$step_order===null?null:(int)$step_order,(int)get_current_user_id());
    }

    /**
     * Whether $uid may decide this pending step: its approver, or (a step sent to administrators, or
     * $admin_override) an administrator. A requester deciding their own request needs the workflow to
     * allow self-approval, except an administrator using the override on a level that waits for administrators (3.31.129). @return true|WP_Error
     */
    private function approval_may_decide($step,$uid,$admin_override){
        $r=$this->services()->approvals()->mayDecide($step,(int)$uid,(bool)$admin_override);
        return $r->ok?true:new WP_Error($r->code,$r->message);
    }

    /**
     * Apply a decision to the current pending step and advance the state machine. $expected_step: the level the form showed
     * (approval_posted_step(), 3.31.122); when the open level is another one the decision is refused (approval_moved), and the
     * update touches only that step. null = the form did not say: the current level, as before.
     */
    private function approval_act($approval_request_id,$decision,$comment='',$manage_transaction=true,$allow_admin_override=false,$expected_step=null){
        $r=$this->services()->approvals()->act(absint($approval_request_id),sanitize_key($decision),sanitize_textarea_field($comment),(bool)$manage_transaction,(bool)$allow_admin_override,$expected_step===null?null:(int)$expected_step,(int)get_current_user_id(),is_user_logged_in());
        return $r->ok?true:new WP_Error($r->code,$r->message);
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
        return \WorkforceOne\Approvals\ApprovalReads::requestKey((string)$workflow_key,(string)$entity_type,(int)$entity_id);   // the same function the Service builds its map with
    }

    /**
     * approval_find_request() for many requests with one query (3.31.112): [[workflow_key, entity_type, entity_id], ...]
     * to a map "workflow|entity|id" => the row (a request with none is missing from the map). The newest request of an entity
     * wins, as with LIMIT 1. Only for showing lists: the decision handlers keep re-reading one request, inside their transaction.
     * @param array<int,array{0:string,1:string,2:int}> $keys
     * @return array<string,object>
     */
    private function approval_find_requests(array $keys){
        return $this->services()->approvalReads()->findRequests($keys);
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
        $found=$this->approval_find_requests($keys);
        $out=[];
        foreach($keys as $k)$out[$this->approval_request_key($k[0],$k[1],$k[2])]=null;
        if(!$found)return $out;
        $by_request=$this->services()->approvalReads()->stepsOf(array_map(function($r){return (int)$r->id;},array_values($found)));
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
     * closed and the levels still to decide are marked withdrawn. The caller owns the transaction, and on false must roll it back:
     * the levels may already be marked (they are locked first, 3.31.129).
     * @return bool
     */
    private function approval_close($workflow_key,$entity_type,$entity_id){
        return $this->services()->approvals()->closeWithdrawn(sanitize_key($workflow_key),sanitize_key($entity_type),(int)$entity_id);
    }

    /**
     * Cancels an entity's approval request if it is still open (3.31.117, attendance corrections). Unlike approval_close(), a request
     * already decided when read (APPROVED by the manager levels of a request now waiting for HR, REJECTED, CANCELLED) is left as it
     * is and counts as done. The caller owns the transaction.
     * $closed: closed by the system, not withdrawn; the levels still to decide say "Closed automatically" (skip_reason 'closed').
     * @return true|string|false true: cancelled or nothing open; 'moved': it was decided while being closed; false: a database error
     */
    private function approval_close_open($workflow_key,$entity_type,$entity_id,$closed=false){
        return $this->services()->approvals()->closeOpen(sanitize_key($workflow_key),sanitize_key($entity_type),(int)$entity_id,(bool)$closed);
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
