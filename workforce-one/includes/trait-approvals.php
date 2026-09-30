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
        $mode=strtoupper((string)$mode);
        if($mode==='LEVEL_1'||$mode==='LEVEL_2'||$mode==='SEQUENTIAL')return 'SEQUENTIAL';
        if($mode==='PEER')return 'PEER';
        return 'NONE';
    }

    private function approval_sequential_level_count($mode,$step_configs=[]){
        $mode=strtoupper((string)$mode);
        if($mode==='LEVEL_1')return 1;
        if($mode==='LEVEL_2')return 2;
        if($mode==='SEQUENTIAL'){
            $count=0;
            foreach([1,2] as $i){
                if(isset($step_configs[$i])&&is_array($step_configs[$i])&&!empty($step_configs[$i]['resolver_type']))$count=$i;
            }
            return $count;
        }
        return 0;
    }

    private function approval_configure_workflow($workflow_key,$mode,$step_configs=[]){
        global $wpdb;
        $t=$this->approval_tables();
        $workflow=$this->approval_workflow($workflow_key);
        if(!$workflow)return new WP_Error('workflow_not_found','Workflow not found.');
        $mode=strtoupper(sanitize_key($mode));
        if(!in_array($mode,['NONE','LEVEL_1','LEVEL_2','SEQUENTIAL','PEER'],true))return new WP_Error('invalid_mode','Invalid approval mode: '.$mode);
        $family=$this->approval_mode_family($mode);
        $count=$this->approval_sequential_level_count($mode,$step_configs);
        if($mode==='PEER')$count=1;
        for($i=1;$i<=$count;$i++){
            $cfg=isset($step_configs[$i])&&is_array($step_configs[$i])?$step_configs[$i]:[];
            $resolver=strtoupper(sanitize_key($cfg['resolver_type']??''));
            $value=absint($cfg['resolver_value']??0);
            $allowed=['SUPERVISOR','TEAM_MANAGER','SPECIFIC_EMPLOYEE','SPECIFIC_USER'];
            if($family==='PEER')$allowed=['TARGET_EMPLOYEE'];
            if(!in_array($resolver,$allowed,true))return new WP_Error('invalid_resolver','Invalid approver resolver for step '.$i.': '.$resolver);
            if(in_array($resolver,['SPECIFIC_EMPLOYEE','SPECIFIC_USER'],true)&&!$value)return new WP_Error('missing_resolver_value','A specific approver is required for step '.$i.'.');
        }
        $now=current_time('mysql');
        if($wpdb->query('START TRANSACTION')===false)return new WP_Error('db_error','Could not start configuration transaction.');
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$t['workflows']} SET approval_mode=%s,active=1,updated_at=%s WHERE id=%d",$mode,$now,(int)$workflow->id));
        if($ok===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error',$wpdb->last_error);}
        if($wpdb->query($wpdb->prepare("DELETE FROM {$t['steps']} WHERE workflow_id=%d",(int)$workflow->id))===false){$wpdb->query('ROLLBACK');return new WP_Error('db_error',$wpdb->last_error);}
        for($i=1;$i<=$count;$i++){
            $cfg=$step_configs[$i]??[];$resolver=strtoupper(sanitize_key($cfg['resolver_type']??($family==='PEER'?'TARGET_EMPLOYEE':'')));$value=$cfg['resolver_value']??null;if(in_array($resolver,['SPECIFIC_EMPLOYEE','SPECIFIC_USER'],true))$value=(string)absint($value);elseif($resolver==='TARGET_EMPLOYEE')$value=null;else $value=null;
            $step_type=$family==='PEER'?'PEER':'APPROVAL';
            $ok=$wpdb->insert($t['steps'],['workflow_id'=>(int)$workflow->id,'step_order'=>$i,'step_type'=>$step_type,'resolver_type'=>$resolver,'resolver_value'=>$value,'required'=>1,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%d','%d','%s','%s','%s','%d','%d','%s','%s']);
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
            'status'=>$family==='PEER'?'WAITING_FOR_PEER':'WAITING_FOR_LEVEL_1',
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
            $status=$approver?(((int)$step->step_order===1)?'PENDING':'WAITING'):'SKIPPED';
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
        if($manage_transaction && $wpdb->query('START TRANSACTION')===false)return new WP_Error('approval_db_error','Could not start approval transaction.');
        $new_status=$decision==='approve'?'APPROVED':'REJECTED';
        $ok=$wpdb->update($t['instances'],[
            'status'=>$new_status,
            'decision'=>$decision,
            'comment'=>$comment,
            'acted_at'=>$now,
        ],['id'=>(int)$step->id,'status'=>'PENDING'],['%s','%s','%s','%s'],['%d','%s']);
        if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_stale','Approval step was already handled.');}

        if($decision==='reject'){
            $ok=$wpdb->update($t['requests'],['status'=>'REJECTED','completed_at'=>$now],['id'=>$approval_request_id],['%s','%s'],['%d']);
            if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        }else{
            $next=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d AND status='WAITING' ORDER BY step_order ASC LIMIT 1",$approval_request_id));
            if($next){
                $ok=$wpdb->update($t['instances'],['status'=>'PENDING'],['id'=>(int)$next->id,'status'=>'WAITING'],['%s'],['%d','%s']);
                if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
                $ok=$wpdb->update($t['requests'],['status'=>'WAITING_FOR_LEVEL_'.(int)$next->step_order,'current_step'=>(int)$next->step_order],['id'=>$approval_request_id],['%s','%d'],['%d']);
            }else{
                $ok=$wpdb->update($t['requests'],['status'=>'APPROVED','current_step'=>null,'completed_at'=>$now],['id'=>$approval_request_id],['%s','%d','%s'],['%d']);
            }
            if($ok!==1){if($manage_transaction)$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error',$wpdb->last_error);}
        }
        if($manage_transaction && $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return new WP_Error('approval_db_error','Could not commit approval decision.');}
        return true;
    }
    private function get_active_teams_for_approval(){
        global $wpdb;
        $t=$this->team_tables();
        $this->ensure_teams_schema();
        return $wpdb->get_results("SELECT tm.id,tm.name,e.name AS manager_name FROM {$t['teams']} tm LEFT JOIN {$this->employees} e ON e.id=tm.manager_employee_id AND e.active=1 WHERE tm.active=1 ORDER BY tm.name ASC");
    }

    public function admin_approval_workflows(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        global $wpdb;$t=$this->approval_tables();$this->ensure_approval_schema();
        $employees=$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
        $users=get_users(['fields'=>['ID','display_name','user_email'],'orderby'=>'display_name','order'=>'ASC']);
        $workflows=['vacation'=>'Vacation','overtime'=>'Overtime','early_leave'=>'Early Leave','face_reset'=>'Face Reset','shift_swap'=>'Shift Swap'];
        echo '<div class="wrap"><h1>Approval Workflows</h1>';
        if(isset($_GET['approval_saved']))echo '<div class="notice notice-success is-dismissible"><p>Approval configuration saved.</p></div>';
        if(isset($_GET['approval_error'])){$msg='Could not save approval configuration. Please verify the selected approvers.';if(isset($_GET['approval_error_message'])&&$_GET['approval_error_message']!=='')$msg.=' <strong>'.esc_html(sanitize_text_field(wp_unslash($_GET['approval_error_message']))).'</strong>';echo '<div class="notice notice-error is-dismissible"><p>'.$msg.'</p></div>';}
        echo '<p style="max-width:1000px">Configure approval authority independently from WordPress roles. Choose the approval mode for each workflow and then configure only the fields required by that mode.</p>';
        foreach($workflows as $key=>$label){
            $workflow=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['workflows']} WHERE workflow_key=%s LIMIT 1",$key));
            $steps=$workflow?$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['steps']} WHERE workflow_id=%d ORDER BY step_order",(int)$workflow->id)):[];
            $cfg=[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>''],2=>['resolver_type'=>'SUPERVISOR','resolver_value'=>'']];
            foreach($steps as $st)$cfg[(int)$st->step_order]=['resolver_type'=>$st->resolver_type,'resolver_value'=>$st->resolver_value];
            $current_mode=strtoupper((string)($workflow->approval_mode??'NONE'));
            $card_id='ews-approval-workflow-'.sanitize_key($key);
            echo '<div class="ews-approval-card" data-approval-card="'.esc_attr($card_id).'" style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px;margin:0 0 18px">';
            echo '<h2 style="margin-top:0">'.esc_html($label).' Approval</h2>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_approval_workflow_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_approval_workflow_save"><input type="hidden" name="workflow_key" value="'.esc_attr($key).'">';
            echo '<table class="form-table"><tr><th>Approval Mode</th><td><select class="ews-approval-mode" name="approval_mode" aria-label="Approval Mode">';
            echo '<option value="NONE"'.selected($current_mode,'NONE',false).'>No approval</option>';
            echo '<option value="LEVEL_1"'.selected($current_mode,'LEVEL_1',false).'>Level 1 approval</option>';
            echo '<option value="LEVEL_2"'.selected($current_mode,'LEVEL_2',false).'>Level 1 + Level 2 approval</option>';
            echo '<option value="SEQUENTIAL"'.selected($current_mode,'SEQUENTIAL',false).'>Sequential approval</option>';
            echo '<option value="PEER"'.selected($current_mode,'PEER',false).'>Peer approval</option>';
            echo '</select><div class="ews-approval-help" style="margin-top:8px;line-height:1.6">';
            echo '<div data-mode="NONE"><strong>No approval:</strong> request proceeds without approval.</div>';
            echo '<div data-mode="LEVEL_1"><strong>Level 1:</strong> exactly one approval level.</div>';
            echo '<div data-mode="LEVEL_2"><strong>Level 1 + Level 2:</strong> exactly two sequential approval levels.</div>';
            echo '<div data-mode="SEQUENTIAL"><strong>Sequential:</strong> approval levels run in order using the configured active levels.</div>';
            echo '<div data-mode="PEER"><strong>Peer:</strong> the request target/peer is the approver.</div>';
            echo '</div></td></tr></table>';
            for($i=1;$i<=2;$i++){
                $c=$cfg[$i];
                $visible_modes=($i===1)?'LEVEL_1 LEVEL_2 SEQUENTIAL':'LEVEL_2 SEQUENTIAL';
                echo '<div class="ews-approval-level" data-visible-modes="'.esc_attr($visible_modes).'" style="border:1px solid #dcdcde;padding:16px;margin:12px 0">';
                echo '<h3 style="margin-top:0">Level '.(int)$i.'</h3>';
                echo '<p><label>Approver source <select class="ews-approval-source" name="level_'.(int)$i.'_type">';
                echo '<option value="SUPERVISOR"'.selected($c['resolver_type'],'SUPERVISOR',false).'>Employee Supervisor</option>';
                echo '<option value="TEAM_MANAGER"'.selected($c['resolver_type'],'TEAM_MANAGER',false).'>Team Manager</option>';
                echo '<option value="SPECIFIC_EMPLOYEE"'.selected($c['resolver_type'],'SPECIFIC_EMPLOYEE',false).'>Specific Workforce Employee</option>';
                echo '<option value="SPECIFIC_USER"'.selected($c['resolver_type'],'SPECIFIC_USER',false).'>Specific WordPress User</option>';
                echo '</select></label></p>';
                echo '<p class="ews-specific-employee"><label>Specific Employee <select name="level_'.(int)$i.'_employee"><option value="0">— Select —</option>';
                foreach($employees as $e)echo '<option value="'.(int)$e->id.'"'.selected((int)$c['resolver_value'],(int)$e->id,false).'>'.esc_html($e->name).' · '.esc_html($e->domain_name).'</option>';
                echo '</select></label></p>';
                echo '<p class="ews-specific-user"><label>Specific User <select name="level_'.(int)$i.'_user"><option value="0">— Select —</option>';
                foreach($users as $u)echo '<option value="'.(int)$u->ID.'"'.selected((int)$c['resolver_value'],(int)$u->ID,false).'>'.esc_html($u->display_name).' · '.esc_html($u->user_email).'</option>';
                echo '</select></label></p>';
                echo '</div>';
            }
            echo '<div class="ews-peer-settings" style="border:1px solid #dcdcde;padding:16px;margin:12px 0"><h3 style="margin-top:0">Peer Approval</h3><p class="description">The peer is supplied by the request itself (target employee). No supervisor/team-manager selection is required.</p></div>';
            echo '<p><button type="submit" class="button button-primary">Save '.esc_html($label).' Approval</button></p>';
            echo '</form>';
            echo '<script>(function(){function syncCard(card){var mode=card.querySelector(".ews-approval-mode");if(!mode)return;var value=(mode.value||"NONE").toUpperCase();card.querySelectorAll(".ews-approval-help [data-mode]").forEach(function(el){el.style.display=el.getAttribute("data-mode")===value?"block":"none";});card.querySelectorAll(".ews-approval-level").forEach(function(level){var modes=(level.getAttribute("data-visible-modes")||"").split(/\\s+/);level.style.display=modes.indexOf(value)>=0?"block":"none";});var peer=card.querySelector(".ews-peer-settings");if(peer)peer.style.display=value==="PEER"?"block":"none";card.querySelectorAll(".ews-approval-source").forEach(function(source){var row=source.closest(".ews-approval-level"),emp=row?row.querySelector(".ews-specific-employee"):null,user=row?row.querySelector(".ews-specific-user"):null,type=(source.value||"").toUpperCase();if(emp)emp.style.display=type==="SPECIFIC_EMPLOYEE"?"block":"none";if(user)user.style.display=type==="SPECIFIC_USER"?"block":"none";});}document.querySelectorAll(".ews-approval-card").forEach(function(card){var mode=card.querySelector(".ews-approval-mode");if(mode)mode.addEventListener("change",function(){syncCard(card);});card.querySelectorAll(".ews-approval-source").forEach(function(source){source.addEventListener("change",function(){syncCard(card);});});syncCard(card);});})();</script>';
            echo '</div>';
        }
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:22px;max-width:1100px"><h2 style="margin-top:0">Supervisor Relationships</h2><p>Assign the direct supervisor used by the <strong>Employee Supervisor</strong> resolver.</p>';
        foreach($employees as $e){$sup=$this->approval_related_employee((int)$e->id,'supervisor');echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid #eee">'.wp_nonce_field('ews_approval_relationship_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_approval_relationship_save"><input type="hidden" name="employee_id" value="'.(int)$e->id.'"><strong style="min-width:220px">'.esc_html($e->name).'</strong><select name="supervisor_employee_id" style="min-width:280px"><option value="0">— No supervisor —</option>';foreach($employees as $x){if((int)$x->id===(int)$e->id)continue;echo '<option value="'.(int)$x->id.'"'.selected($sup?(int)$sup->id:0,(int)$x->id,false).'>'.esc_html($x->name).'</option>';}echo '</select><button class="button" type="submit">Save</button></form>';}
        echo '</div></div>';
    }

    public function approval_workflow_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_workflow_save');
        $workflow_key=sanitize_key($_POST['workflow_key']??'');if(!in_array($workflow_key,['vacation','overtime','early_leave','face_reset','shift_swap'],true))wp_die('Invalid workflow.');
        $mode=strtoupper(sanitize_key($_POST['approval_mode']??'NONE'));$configs=[];
        if($mode==='PEER'){$configs[1]=['resolver_type'=>'TARGET_EMPLOYEE','resolver_value'=>0];}else{for($i=1;$i<=2;$i++){$type=strtoupper(sanitize_key($_POST['level_'.$i.'_type']??''));$value=0;if($type==='SPECIFIC_EMPLOYEE')$value=absint($_POST['level_'.$i.'_employee']??0);elseif($type==='SPECIFIC_USER')$value=absint($_POST['level_'.$i.'_user']??0);$configs[$i]=['resolver_type'=>$type,'resolver_value'=>$value];}}
        $ok=$this->approval_configure_workflow($workflow_key,$mode,$configs);
        if(is_wp_error($ok))wp_safe_redirect(add_query_arg(['approval_error'=>1,'approval_error_code'=>rawurlencode($ok->get_error_code()),'approval_error_message'=>rawurlencode($ok->get_error_message())],admin_url('admin.php?page=ews31-approvals')));else{$this->audit('approval_workflow_update','approval_workflow',0,$workflow_key.'='.$mode);wp_safe_redirect(add_query_arg('approval_saved',1,admin_url('admin.php?page=ews31-approvals')));}exit;
    }

    public function approval_relationship_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_relationship_save');
        $employee_id=absint($_POST['employee_id']??0);$supervisor_id=absint($_POST['supervisor_employee_id']??0);
        $this->ensure_approval_schema();global $wpdb;$t=$this->approval_tables();
        if($employee_id){$wpdb->query($wpdb->prepare("UPDATE {$t['relationships']} SET active=0,updated_at=%s WHERE employee_id=%d AND relationship_type='supervisor' AND active=1",current_time('mysql'),$employee_id));if($supervisor_id)$this->approval_set_relationship($employee_id,'supervisor',$supervisor_id,0);}
        $this->audit('approval_relationship_update','employee',$employee_id,'supervisor='.$supervisor_id);wp_safe_redirect(admin_url('admin.php?page=ews31-approvals&approval_saved=1'));exit;
    }

}
