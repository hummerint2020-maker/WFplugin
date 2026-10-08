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

        $this->approval_seed_workflows();
        return true;
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
        if($resolver_type==='SPECIFIC_EMPLOYEE'){
            $id=absint($resolver_value);
            return $id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$id)):null;
        }
        if($resolver_type==='SPECIFIC_USER'){
            $uid=absint($resolver_value);
            if(!$uid)return null;
            $user=get_user_by('id',$uid);
            if(!$user)return null;
            $employee=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d AND active=1 LIMIT 1",$uid));
            if($employee)return $employee;
            return (object)['id'=>null,'wp_user_id'=>$uid];
        }
        if($resolver_type==='TARGET_EMPLOYEE'){
            $id=absint(isset($entity_context['target_employee_id'])?$entity_context['target_employee_id']:0);
            return $id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$id)):null;
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
            $ok=$wpdb->insert($t['steps'],['workflow_id'=>(int)$workflow->id,'step_order'=>$step['step_order'],'step_type'=>$step['step_type'],'resolver_type'=>$step['resolver_type'],'resolver_value'=>$step['resolver_value'],'required'=>1,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%d','%d','%s','%s','%s','%d','%d','%s','%s']);
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

        if($manage_transaction && $wpdb->query('START TRANSACTION')===false)return new WP_Error('approval_db_error','Could not start approval transaction.');
        $ok=$wpdb->insert($t['requests'],[
            'workflow_id'=>(int)$workflow->id,
            'entity_type'=>sanitize_key($entity_type),
            'entity_id'=>(int)$entity_id,
            'requester_employee_id'=>(int)$requester_employee_id,
            'status'=>\WorkforceOne\Approvals\StateMachine::initialStatus($family),
            'current_step'=>1,
        ],['%d','%s','%d','%d','%s','%d']);
        if($ok===false){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        $approval_id=(int)$wpdb->insert_id;

        foreach($steps as $step){
            $approver=$this->approval_resolve_approver($requester_employee_id,$step->resolver_type,$step->resolver_value,$entity_context);
            if(!$approver && (int)$step->required){
                if($manage_transaction)$wpdb->query('ROLLBACK');
                return new WP_Error('approval_approver_missing','Approval step '.$step->step_order.' could not resolve an approver.');
            }
            if($approver && !(int)$approver->wp_user_id){
                if($manage_transaction)$wpdb->query('ROLLBACK');
                return new WP_Error('approval_approver_unlinked','The configured approver is not linked to a WordPress user.');
            }
            $status=\WorkforceOne\Approvals\StateMachine::stepStatus((int)$step->step_order,(bool)$approver);
            $ok=$wpdb->insert($t['instances'],[
                'approval_request_id'=>$approval_id,
                'step_order'=>(int)$step->step_order,
                'approver_employee_id'=>$approver?(int)$approver->id:null,
                'approver_wp_user_id'=>$approver?(int)$approver->wp_user_id:null,
                'resolver_type'=>$step->resolver_type,
                'resolver_value'=>$step->resolver_value,
                'status'=>$status,
            ],['%d','%d','%d','%d','%s','%s','%s']);
            if($ok===false){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        }
        if($manage_transaction && $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error','Could not commit approval request.');}
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
        return $step && (int)$step->approver_wp_user_id===(int)get_current_user_id();
    }

    /** Apply a decision to the current pending step and advance the state machine. */
    private function approval_act($approval_request_id,$decision,$comment='',$manage_transaction=true,$allow_admin_override=false){
        global $wpdb;
        $t=$this->approval_tables();
        $approval_request_id=absint($approval_request_id);
        $decision=sanitize_key($decision);
        $comment=sanitize_textarea_field($comment);
        if(!$approval_request_id||!in_array($decision,['approve','reject'],true))return new WP_Error('invalid_decision','Invalid approval decision.');
        $request=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['requests']} WHERE id=%d LIMIT 1",$approval_request_id));
        if(!$request)return new WP_Error('approval_not_found','Approval request not found.');
        $step=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='PENDING' ORDER BY step_order ASC LIMIT 1",$approval_request_id));
        if(!$step)return new WP_Error('approval_not_pending','No pending approval step.');
        if(!$allow_admin_override && (!is_user_logged_in() || (int)$step->approver_wp_user_id!==(int)get_current_user_id()))return new WP_Error('approval_not_authorized','You are not the assigned approver.');

        $now=current_time('mysql');
        $next=$decision==='approve'?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='WAITING' ORDER BY step_order ASC LIMIT 1",$approval_request_id)):null;
        $to=\WorkforceOne\Approvals\StateMachine::afterDecision($decision,$next?(int)$next->step_order:null);
        if($manage_transaction && $wpdb->query('START TRANSACTION')===false)return new WP_Error('approval_db_error','Could not start approval transaction.');
        $ok=$wpdb->update($t['instances'],[
            'status'=>$to['step'],
            'decision'=>$decision,
            'comment'=>$comment,
            'acted_at'=>$now,
        ],['id'=>(int)$step->id,'status'=>'PENDING'],['%s','%s','%s','%s'],['%d','%s']);
        if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_stale','Approval step was already handled.');}
        if($next){
            $ok=$wpdb->update($t['instances'],['status'=>'PENDING'],['id'=>(int)$next->id,'status'=>'WAITING'],['%s'],['%d','%s']);
            if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'current_step'=>$to['current_step']],['id'=>$approval_request_id],['%s','%d'],['%d']);
        }elseif($decision==='reject'){
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'completed_at'=>$now],['id'=>$approval_request_id],['%s','%s'],['%d']);
        }else{
            $ok=$wpdb->update($t['requests'],['status'=>$to['request'],'current_step'=>null,'completed_at'=>$now],['id'=>$approval_request_id],['%s','%d','%s'],['%d']);
        }
        if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        if($manage_transaction && $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error','Could not commit approval decision.');}
        return true;
    }
    /** wp-admin → Approval Workflows: the mode and approvers of each workflow in use, and supervisors. */
    public function admin_approval_workflows(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        global $wpdb;$t=$this->approval_tables();$this->ensure_approval_schema();
        $employees=(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
        // Supervisor Relationships: 50 employees per page with a search (3.31.71).
        $sup_search=sanitize_text_field(wp_unslash($_GET['s']??''));$per=50;
        $listed=$employees;
        if($sup_search!==''){$n=function_exists('mb_strtolower')?'mb_strtolower':'strtolower';$q=$n($sup_search);$listed=array_values(array_filter($employees,function($e)use($q,$n){return strpos($n($e->name.' '.$e->domain_name),$q)!==false;}));}
        $sup_total=count($listed);$sup_pages=max(1,(int)ceil($sup_total/$per));$sup_paged=min(max(1,absint($_GET['paged']??1)),$sup_pages);
        $listed=array_slice($listed,($sup_paged-1)*$per,$per);
        $users=get_users(['fields'=>['ID','display_name','user_email'],'orderby'=>'display_name','order'=>'ASC']);
        $cards=[];
        foreach(\WorkforceOne\Approvals\Workflows::IN_USE as $key=>[$label,$supported]){
            $workflow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['workflows']} WHERE workflow_key=%s LIMIT 1",$key));
            $levels=[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>''],2=>['resolver_type'=>'SUPERVISOR','resolver_value'=>'']];
            if($workflow)foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['steps']} WHERE workflow_id=%d ORDER BY step_order",(int)$workflow->id)) as $st)$levels[(int)$st->step_order]=['resolver_type'=>$st->resolver_type,'resolver_value'=>$st->resolver_value];
            $mode=strtoupper((string)($workflow->approval_mode??'NONE'));
            $active=$workflow&&(int)$workflow->active===1;
            $cards[$key]=['label'=>$label,'modes'=>$supported,'none_means'=>\WorkforceOne\Approvals\Workflows::NONE_MEANS[$key],'mode'=>in_array($mode,$supported,true)?$mode:'NONE','levels'=>$levels,
                'unsupported'=>$active&&!in_array($mode,$supported,true)?$mode:''];
        }
        $supervisors=$this->approval_supervisor_ids(array_map(function($e){return (int)$e->id;},$listed));
        $error='';
        if(isset($_GET['approval_error'])){$error='Could not save approval configuration. Please verify the selected approvers.';$detail=sanitize_text_field(wp_unslash($_GET['approval_error_message']??''));if($detail!=='')$error.=' '.$detail;}
        echo $this->render_template('admin/approvals',['cards'=>$cards,'not_in_use'=>\WorkforceOne\Approvals\Workflows::NOT_IN_USE,'mode_labels'=>\WorkforceOne\Approvals\Workflows::MODES,
            'employees'=>$employees,'listed'=>$listed,'search'=>$sup_search,'paged'=>$sup_paged,'pages'=>$sup_pages,'total'=>$sup_total,'page_url'=>admin_url('admin.php?page=ews31-approvals'),'users'=>$users,'supervisors'=>$supervisors,'saved'=>isset($_GET['approval_saved']),'error'=>$error]); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
    }

    public function approval_workflow_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_workflow_save');
        $workflow_key=sanitize_key($_POST['workflow_key']??'');
        $in_use=\WorkforceOne\Approvals\Workflows::IN_USE;
        if(!isset($in_use[$workflow_key]))wp_die('Invalid workflow.');
        $mode=strtoupper(sanitize_key($_POST['approval_mode']??'NONE'));$configs=[];
        if($mode==='PEER'){$configs[1]=['resolver_type'=>'TARGET_EMPLOYEE','resolver_value'=>0];}else{for($i=1;$i<=2;$i++){$type=strtoupper(sanitize_key($_POST['level_'.$i.'_type']??''));$value=0;if($type==='SPECIFIC_EMPLOYEE')$value=$this->approval_picked('level_'.$i.'_employee');elseif($type==='SPECIFIC_USER')$value=$this->approval_picked('level_'.$i.'_user');$configs[$i]=['resolver_type'=>$type,'resolver_value'=>$value];}}
        $ok=$this->approval_configure_workflow($workflow_key,$mode,$configs,$in_use[$workflow_key][1]);
        if(is_wp_error($ok))wp_safe_redirect(add_query_arg(['approval_error'=>1,'approval_error_code'=>rawurlencode($ok->get_error_code()),'approval_error_message'=>rawurlencode($ok->get_error_message())],admin_url('admin.php?page=ews31-approvals')));else{$this->audit('approval_workflow_update','approval_workflow',0,$workflow_key.'='.$mode);wp_safe_redirect(add_query_arg('approval_saved',1,admin_url('admin.php?page=ews31-approvals')));}exit;
    }

    public function approval_relationship_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_relationship_save');
        $employee_id=absint($_POST['employee_id']??0);$supervisor_id=isset($_POST['supervisor_employee_ref'])?\WorkforceOne\Support\Picker::parse((string)wp_unslash($_POST['supervisor_employee_ref'])):absint($_POST['supervisor_employee_id']??0);
        $this->ensure_approval_schema();global $wpdb;$t=$this->approval_tables();
        // Back to the same page and search of the Supervisor Relationships list.
        $back=['page'=>'ews31-approvals'];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$q);foreach(['paged','s'] as $k)if(!empty($q[$k]))$back[$k]=$k==='paged'?absint($q[$k]):sanitize_text_field((string)$q[$k]);
        $ok=$supervisor_id===0||($supervisor_id>0&&$supervisor_id!==$employee_id&&(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$supervisor_id)));
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
