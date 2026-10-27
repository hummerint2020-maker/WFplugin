<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\Hub;
use WorkforceOne\Schedule\SwapRules;
use WorkforceOne\Schedule\SwapInbox;

/**
 * Schedule Swap requests: an employee offers to exchange an Office/WFH day with a colleague
 * in the same department; the colleague accepts or rejects, the requester may cancel, and an
 * administrator can decide from the wp-admin Requests page.
 * With the Shift Swap workflow on "Colleague, then Level 1 (+ Level 2)" (3.31.89), an accepted swap
 * waits for the approvers (status Awaiting) and the days are exchanged only when the last one approves.
 * Validation rules live in src/Schedule/SwapRules.php (pure, unit tested).
 * Behaviour is pinned by tests/e2e_swap.py.
 */
trait EWS_Swap_Trait {

    /* ------------------------------------------------------------------ helpers */

    private function schedule_for_employee_date($eid,$date){
            return $this->services()->scheduleDays()->day((int)$eid,(string)$date);
        }

    private function swap_employee_user_id($employee_id){
            global $wpdb;
            return (int)$this->people_user_id((int)$employee_id,true);
        }

    private function swap_redirect($args=[]){
            $url=wp_get_referer();
            if(!$url || strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            $url=remove_query_arg(['action','apv_swap','swap_error','swap_sent','swap_done','leave_error','leave_sent','leave_done','leave_cancel_sent','early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','vacation_sent','vacation_error','vacation_done','vacation_rejected','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view',$this->approvals_return_view('schedule'),$url);
            if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);
            exit;
        }

    /** A Pending swap by id, optionally narrowed (e.g. "AND target_employee_id=%d"). */
    private function swap_pending_row($id,$extra_where='',...$extra_args){
            global $wpdb;$table=$this->ensure_swap_schema();
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Pending' {$extra_where} LIMIT 1",$id,...$extra_args));
        }

    /** Moves a swap from $from (Pending, or Awaiting the approvers) to $status. Returns 1 on success, 0 if it had moved on, false on a DB error. */
    private function swap_close($id,$status,$from='Pending'){
            // The callers read the swap first (schema ensured); it also runs inside transactions, where a dbDelta would commit (3.31.120).
            global $wpdb;$table=$wpdb->prefix.'ews_schedule_swaps';
            return $wpdb->update($table,['status'=>$status,'responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>$id,'status'=>$from],['%s','%d','%s'],['%d','%s']);
        }

    /** The Shift Swap workflow's levels after the colleague: LEVEL_1 … LEVEL_4, or '' (the colleague alone decides). */
    private function swap_manager_mode(){
            $w=$this->approval_workflow('shift_swap');
            if(!$w || (int)$w->active!==1)return '';
            $m=strtoupper((string)$w->approval_mode);
            return \WorkforceOne\Approvals\Workflows::isLevels($m)?$m:'';
        }

    /**
     * Exchanges the two schedule days and marks the swap Accepted, atomically.
     * Returns true, or a ?swap_error= code: 'changed' (a schedule was edited since the request),
     * 'expired' (no longer Pending) or 'save'.
     */
    private function swap_apply($r){
            global $wpdb;$this->ensure_swap_schema();
            if($wpdb->query('START TRANSACTION')===false)return 'save';
            $out=$this->swap_apply_rows($r);
            if($out!==true){$wpdb->query('ROLLBACK');return $out;}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
            return true;
        }

    /**
     * swap_apply() inside a transaction the caller owns (3.31.120): never starts, commits or rolls back; on anything but true the
     * caller rolls back. Lets the administrator's last approval and the exchange be one transaction.
     */
    private function swap_apply_rows($r){
            global $wpdb;
            $a=$this->schedule_for_employee_date($r->requester_employee_id,$r->work_date);
            $b=$this->schedule_for_employee_date($r->target_employee_id,$r->work_date);
            $error=SwapRules::checkStillCurrent((string)$r->requester_status,(string)$r->target_status,$a?$a->status:null,$b?$b->status:null);
            if($error)return $error;
            $now=current_time('mysql');$uid=get_current_user_id();
            // Claimed first, then each day written only if it is still as read (3.31.117): a schedule saved at the same moment (a leave
            // approved, the grid, another swap) is never overwritten, and of two accepts at once the second is "expired".
            $updated=$this->swap_close((int)$r->id,'Accepted',(string)$r->status);
            if($updated!==1)return $updated===false?'save':'expired';
            $writes=[[$a,(int)$r->requester_employee_id,(string)$r->target_status],[$b,(int)$r->target_employee_id,(string)$r->requester_status]];
            usort($writes,function($x,$y){return (int)$x[0]->id<=>(int)$y[0]->id;});   // the same order in every swap: no deadlock
            foreach($writes as [$row,$employee_id,$status]){
                $n=$wpdb->update($this->schedule,['status'=>$status,'updated_by'=>$uid,'updated_at'=>$now],
                    ['id'=>(int)$row->id,'employee_id'=>$employee_id,'work_date'=>(string)$r->work_date,'status'=>(string)$row->status],['%s','%d','%s'],['%d','%d','%s','%s']);
                if($n===false)return 'save';
                if($n!==1)return 'changed';
            }
            // Week lock (3.31.121): an approved swap is applied as usual, also in a final week, and recorded.
            foreach([(int)$r->requester_employee_id,(int)$r->target_employee_id] as $eid)$this->week_lock_record_request($eid,[(string)$r->work_date],'swap','swap #'.(int)$r->id);
            return true;
        }

    /** In-app notification plus push (when enabled) for one swap participant. */
    /**
     * In-app notification plus push. $url null: with My approvals on (3.31.124) the requester's notes open My requests, where the
     * swap is followed; the others keep the Schedule page (the notification link's default).
     */
    private function swap_notify($user_id,$title,$message,$type,$swap_id,$push=true,$url=null){
            if(!$user_id)return;
            $opt=['type'=>$type,'entity_id'=>$swap_id,'push'=>$push];
            if($url)$opt['url']=$url;
            $this->notify($user_id,'swap',$title,$message,$opt);
        }

    /** Where the requester's notes about their swap open (3.31.124): My requests, or the default link with My approvals off. */
    private function swap_requester_url(){
            return $this->my_approvals_enabled()?add_query_arg('ews_view','requests',$this->app_home_url()):null;
        }

    /** Where a swap waiting for this person opens (3.31.124): My approvals on the swap, or the Schedule page with My approvals off. */
    private function swap_inbox_url($id){
            if(!$this->my_approvals_enabled())return add_query_arg('ews_view','schedule',$this->app_home_url());
            return add_query_arg(['ews_view'=>'approvals','apv_swap'=>(int)$id],$this->app_home_url()).'#apv-swap-'.(int)$id;
        }

    private function swap_audit($action,$r,$detail){
            $this->audit($action,'schedule_swap',(int)$r->id,sprintf('%s for %s: %s ↔ %s',$detail,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_status,$r->target_status));
        }

    /* ------------------------------------------------------------------ handlers */

    public function swap_request_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_swap_create');
            $requester=$this->current_employee();
            if(!$requester)$this->swap_redirect(['swap_error'=>'employee']);
            $target=absint($_POST['target_employee_id']??0);
            $date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
            $error=SwapRules::checkRequest((int)$requester->id,$target,$date)??SwapRules::checkNotPast($date,current_time('Y-m-d'));
            if($error)$this->swap_redirect(['swap_error'=>$error]);
            global $wpdb;$table=$this->ensure_swap_schema();
            $target_emp=$this->people_employee($target,true);
            if(!$target_emp || !$this->department_scope_allows_employee($target))$this->swap_redirect(['swap_error'=>'employee']);
            $a=$this->schedule_for_employee_date($requester->id,$date);
            $b=$this->schedule_for_employee_date($target,$date);
            $error=SwapRules::checkSwappable($a?$a->status:null,$b?$b->status:null);
            if($error)$this->swap_redirect(['swap_error'=>$error]);
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE work_date=%s AND status IN ('Pending','Awaiting') AND ((requester_employee_id=%d AND target_employee_id=%d) OR (requester_employee_id=%d AND target_employee_id=%d))",$date,$requester->id,$target,$target,$requester->id));
            if($exists)$this->swap_redirect(['swap_error'=>'pending']);
            $ok=$wpdb->insert($table,[
                'requester_employee_id'=>(int)$requester->id,'target_employee_id'=>$target,'work_date'=>$date,
                'requester_status'=>$a->status,'target_status'=>$b->status,'status'=>'Pending',
                'requested_by'=>get_current_user_id(),'created_at'=>current_time('mysql')
            ],['%d','%d','%s','%s','%s','%s','%d','%s']);
            if(!$ok)$this->swap_redirect(['swap_error'=>'save']);
            $id=(int)$wpdb->insert_id;
            $this->audit('SCHEDULE_SWAP_REQUESTED','schedule_swap',$id,sprintf('%s requested a swap with %s for %s: %s -> %s; %s -> %s',$requester->name,$target_emp->name,date_i18n('l, d M Y',strtotime($date)),$requester->name,$b->status,$target_emp->name,$a->status));
            if($this->my_approvals_enabled()){
                /* translators: 1: colleague, 2: day, 3: the colleague's day after the swap, 4: the requester's day after it */
                $msg=sprintf(__('%1$s asks to swap %2$s with you: you %3$s, them %4$s.','workforce-one'),$requester->name,date_i18n('l, d M',strtotime($date)),$this->swap_status_word((string)$a->status),$this->swap_status_word((string)$b->status));
                $this->swap_notify($this->swap_employee_user_id($target),__('Swap request','workforce-one'),$msg,'info',$id,true,$this->swap_inbox_url($id));
            }else{
                // My approvals off: as before 3.31.124.
                $msg=sprintf('%s requested to swap %s: %s ↔ %s.', $requester->name,date_i18n('l, d M',strtotime($date)),$a->status,$b->status);
                $this->swap_notify($this->swap_employee_user_id($target),'Schedule Swap Request',$msg,'info',$id);
            }
            $this->swap_redirect(['swap_sent'=>1]);
        }

    public function swap_request_respond(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            $id=absint($_POST['swap_id']??0);$decision=sanitize_key($_POST['decision']??'');
            check_admin_referer('ews_swap_respond_'.$id);
            $emp=$this->current_employee();if(!$emp||!in_array($decision,['accept','reject'],true))wp_die('Invalid swap request.');
            $r=$this->swap_pending_row($id,'AND target_employee_id=%d',(int)$emp->id);
            if(!$r)$this->swap_redirect(['swap_error'=>'not_found']);
            $req_uid=$this->swap_employee_user_id($r->requester_employee_id);
            if($decision==='reject'){
                $ok=$this->swap_close($id,'Rejected');
                if($ok!==1)$this->swap_redirect(['swap_error'=>$ok===false?'save':'expired']);
                $this->swap_audit('SCHEDULE_SWAP_REJECTED',$r,'Swap rejected by '.$emp->name);
                $this->swap_notify($req_uid,'Schedule Swap Rejected',sprintf('%s rejected your schedule swap for %s.', $emp->name,date_i18n('l, d M',strtotime($r->work_date))),'warning',$id,true,$this->swap_requester_url());
                $this->swap_redirect(['swap_done'=>'rejected']);
            }
            $past=SwapRules::checkNotPast((string)$r->work_date,current_time('Y-m-d'));
            if($past)$this->swap_redirect(['swap_error'=>$past]);
            if($this->swap_manager_mode()!==''){
                // A day changed since the request cannot go to the approvers either (as swap_apply refuses it without levels).
                $a=$this->schedule_for_employee_date($r->requester_employee_id,$r->work_date);$b=$this->schedule_for_employee_date($r->target_employee_id,$r->work_date);
                if(SwapRules::checkStillCurrent((string)$r->requester_status,(string)$r->target_status,$a?(string)$a->status:null,$b?(string)$b->status:null)!==null)$this->swap_redirect(['swap_error'=>'changed']);
                $out=$this->swap_to_approvers($r,$emp);
                if($out!==true)$this->swap_redirect(['swap_error'=>$out]);
                $this->swap_redirect(['swap_done'=>'awaiting']);
            }
            $applied=$this->swap_apply($r);
            if($applied!==true)$this->swap_redirect(['swap_error'=>$applied]);
            $this->achievement_evaluate_swap_acceptance((int)$r->target_employee_id,(int)$r->requester_employee_id,(int)$id);
            $this->audit('SCHEDULE_SWAP_ACCEPTED','schedule_swap',$id,sprintf('Swap accepted by %s for %s: requester %s %s -> %s; target %s %s -> %s', $emp->name,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_employee_id,$r->requester_status,$r->target_status,$r->target_employee_id,$r->target_status,$r->requester_status));
            $this->swap_notify($req_uid,'Schedule Swap Completed',sprintf('Your schedule swap for %s with %s is complete: %s ↔ %s.',date_i18n('l, d M',strtotime($r->work_date)),$emp->name,$r->requester_status,$r->target_status),'success',$id,true,$this->swap_requester_url());
            $this->swap_notify(get_current_user_id(),'Schedule Swap Completed','Your schedule has been updated after the swap.','success',$id);
            $this->swap_redirect(['swap_done'=>'accepted']);
        }

    public function swap_request_cancel(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            $id=absint($_POST['swap_id']??0);check_admin_referer('ews_swap_cancel_'.$id);
            $emp=$this->current_employee();if(!$emp)$this->swap_redirect(['swap_error'=>'employee']);
            $r=$this->swap_pending_row($id,'AND requester_employee_id=%d',(int)$emp->id);
            if($r){
                $ok=$this->swap_close($id,'Cancelled');
                if($ok!==1)$this->swap_redirect(['swap_error'=>$ok===false?'save':'expired']);
                $this->swap_audit('SCHEDULE_SWAP_CANCELLED',$r,'Swap cancelled by '.$emp->name);
            }
            $this->swap_redirect(['swap_done'=>'cancelled']);
        }

    /**
     * Decision taken from the wp-admin Requests page. Returns a Hub outcome; the caller redirects. With levels the administrator
     * decides the current level only, as for leave and overtime (3.31.120): the decision and, on the last level, the exchange are
     * one transaction, so a schedule changed since the request leaves the level open. Unlike a peer acceptance it does not count
     * towards collaboration achievements.
     */
    private function swap_admin_decide($id,$decision){
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status IN ('Pending','Awaiting') LIMIT 1",(int)$id));
            if(!$r)return Hub::ERROR;
            $approve=$decision==='approve';
            $ar=$r->status==='Awaiting'?$this->approval_find_request('shift_swap','schedule_swap',(int)$id):null;
            if($ar&&Hub::isOpenApproval((string)$ar->status)){
                if($wpdb->query('START TRANSACTION')===false)return Hub::ERROR;
                $acted=$this->approval_act((int)$ar->id,$approve?'approve':'reject','',false,true,$this->approval_posted_step());
                if(is_wp_error($acted)){$wpdb->query('ROLLBACK');return Hub::fromEngineError($acted->get_error_code());}
                $fresh=$this->approval_find_request('shift_swap','schedule_swap',(int)$id);
                if(!$fresh){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                $outcome=Hub::workflowOutcome((string)$fresh->status);
                $done=true;
                if($outcome===Hub::APPROVED)$done=$this->swap_apply_rows($r);
                elseif($outcome===Hub::REJECTED)$done=$this->swap_close((int)$id,'Rejected','Awaiting')===1?true:'save';
                if($done!==true){$wpdb->query('ROLLBACK');return $done==='changed'?Hub::CHANGED:Hub::ERROR;}
                if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                if($outcome===Hub::ADVANCED){$this->swap_notify_approver($fresh,$r);return Hub::ADVANCED;}
                $this->swap_admin_done($r,$outcome===Hub::APPROVED);
                return $outcome;
            }
            // Pending (the colleague has not answered), or the approval already decided: the administrator decides the swap itself.
            if((int)$this->approval_posted_step()>0)return Hub::MOVED;   // the page showed a level that is no longer open (3.31.122)
            if($approve){
                $applied=$this->swap_apply($r);
                if($applied!==true)return $applied==='changed'?Hub::CHANGED:Hub::ERROR;
            }elseif($this->swap_close($id,'Rejected',(string)$r->status)!==1)return Hub::ERROR;
            $this->swap_admin_done($r,$approve);
            return Hub::outcome($decision);
        }

    /** Audit row and the two participants' notes after an administrator's final decision. */
    private function swap_admin_done($r,$approve){
            $id=(int)$r->id;
            $actor=wp_get_current_user()->display_name;
            $this->swap_audit($approve?'SCHEDULE_SWAP_ACCEPTED':'SCHEDULE_SWAP_REJECTED',$r,($approve?'Swap approved by administrator ':'Swap rejected by administrator ').$actor);
            $title=$approve?'Schedule Swap Completed':'Schedule Swap Rejected';
            $type=$approve?'success':'warning';
            $this->swap_notify($this->swap_employee_user_id($r->requester_employee_id),$title,$approve?'Your schedule swap was completed by an administrator.':'Your schedule swap request was rejected by an administrator.',$type,$id,false,$this->swap_requester_url());
            $this->swap_notify($this->swap_employee_user_id($r->target_employee_id),$title,$approve?'Your schedule has been updated after an administrator approved a swap.':'A schedule swap offered to you was rejected by an administrator.',$type,$id,false);
        }

    /** Pending swaps for the wp-admin Requests page. */
    private function swap_pending_rows(){
            global $wpdb;$table=$this->ensure_swap_schema();
            return (array)$wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('Pending','Awaiting') ORDER BY created_at ASC");
        }

    /**
     * The colleague accepted and the workflow has levels: the swap waits for the approvers. Returns
     * true or an error code. An approval request that is approved at once (no approver) swaps now.
     */
    private function swap_to_approvers($r,$emp){
            global $wpdb;$table=$this->ensure_swap_schema();
            if($wpdb->query('START TRANSACTION')===false)return 'save';
            $ok=$wpdb->update($table,['status'=>'Awaiting','responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>(int)$r->id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($ok!==1){$wpdb->query('ROLLBACK');return $ok===false?'save':'expired';}
            if(is_wp_error($this->approval_start('shift_swap','schedule_swap',(int)$r->id,(int)$r->requester_employee_id,[],false))){$wpdb->query('ROLLBACK');return 'approval';}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
            $r->status='Awaiting';
            $this->swap_audit('SCHEDULE_SWAP_ACCEPTED',$r,'Swap accepted by '.$emp->name.', waiting for approval');
            $ar=$this->approval_find_request('shift_swap','schedule_swap',(int)$r->id);
            if($ar&&$ar->status==='APPROVED')return $this->swap_finish_approved($r);
            $this->swap_notify_approver($ar,$r);
            $this->swap_notify($this->swap_employee_user_id($r->requester_employee_id),__('Swap accepted, waiting for approval','workforce-one'),
                /* translators: 1: colleague, 2: day */
                sprintf(__('%1$s accepted your swap for %2$s. It is applied once it is approved.','workforce-one'),$emp->name,date_i18n('l, d M',strtotime($r->work_date))),'info',(int)$r->id,true,$this->swap_requester_url());
            return true;
        }

    private function swap_notify_approver($ar,$r){
            $step=$ar?$this->approval_current_step((int)$ar->id):null;
            if(!$step||!$step->approver_wp_user_id)return;
            global $wpdb;
            $a=$this->people_name((int)$r->requester_employee_id);
            $b=$this->people_name((int)$r->target_employee_id);
            $this->notify((int)$step->approver_wp_user_id,'swap',__('Shift swap to approve','workforce-one'),
                /* translators: 1: employee, 2: colleague, 3: day, 4: status, 5: status */
                sprintf(__('%1$s and %2$s agreed to swap %3$s: %4$s ↔ %5$s.','workforce-one'),(string)$a,(string)$b,date_i18n('l, d M',strtotime($r->work_date)),$r->requester_status,$r->target_status),
                ['type'=>'info','entity_id'=>(int)$r->id,'url'=>$this->swap_inbox_url((int)$r->id)]);
        }

    /** The last approver approved: the days are exchanged (if the schedules have not changed since). */
    private function swap_finish_approved($r){
            $applied=$this->swap_apply($r);
            if($applied!==true)return $applied;
            $this->swap_audit('SCHEDULE_SWAP_APPROVED',$r,'Swap approved');
            foreach([(int)$r->requester_employee_id,(int)$r->target_employee_id] as $eid)
                $this->swap_notify($this->swap_employee_user_id($eid),'Schedule Swap Completed',sprintf('Your schedule swap for %s was approved: %s ↔ %s.',date_i18n('l, d M',strtotime($r->work_date)),$r->requester_status,$r->target_status),'success',(int)$r->id,true,(int)$eid===(int)$r->requester_employee_id?$this->swap_requester_url():null);
            return true;
        }

    /** An approver decides a swap waiting for them (app → Schedule). */
    public function swap_manager_decide(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            $id=absint($_POST['swap_id']??0);$decision=sanitize_key($_POST['decision']??'');
            check_admin_referer('ews_swap_decide_'.$id);
            if(!in_array($decision,['approve','reject'],true))$this->swap_redirect(['swap_error'=>'invalid']);
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Awaiting' LIMIT 1",$id));
            if(!$r)$this->swap_redirect(['swap_error'=>'not_found']);
            $ar=$this->approval_find_request('shift_swap','schedule_swap',$id);
            $posted=$this->approval_posted_step();   // the level the page showed (3.31.122)
            if($ar && $this->approval_level_moved(Hub::isOpenApproval((string)$ar->status)?(int)$ar->id:0,$posted))$this->swap_redirect(['swap_error'=>'moved']);
            if(!$ar||!$this->approval_can_act((int)$ar->id))$this->swap_redirect(['swap_error'=>'access']);
            if($decision==='approve'){$past=SwapRules::checkNotPast((string)$r->work_date,current_time('Y-m-d'));if($past)$this->swap_redirect(['swap_error'=>$past]);}
            $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),true,false,$posted);
            if(is_wp_error($acted))$this->swap_redirect(['swap_error'=>$this->approval_error_code($acted)]);
            $fresh=$this->approval_find_request('shift_swap','schedule_swap',$id);
            if($fresh&&$fresh->status==='APPROVED'){
                $out=$this->swap_finish_approved($r);
                $this->swap_redirect($out===true?['swap_done'=>'approved']:['swap_error'=>$out]);
            }
            if($fresh&&$fresh->status==='REJECTED'){
                if($this->swap_close($id,'Rejected','Awaiting')!==1)$this->swap_redirect(['swap_error'=>'save']);
                $this->swap_audit('SCHEDULE_SWAP_REJECTED',$r,'Swap rejected by approver '.wp_get_current_user()->display_name);
                foreach([(int)$r->requester_employee_id,(int)$r->target_employee_id] as $eid)
                    $this->swap_notify($this->swap_employee_user_id($eid),'Schedule Swap Rejected',sprintf('The schedule swap for %s was not approved.',date_i18n('l, d M',strtotime($r->work_date))),'warning',$id,true,(int)$eid===(int)$r->requester_employee_id?$this->swap_requester_url():null);
                $this->swap_redirect(['swap_done'=>'rejected']);
            }
            $this->swap_notify_approver($fresh,$r);
            $this->swap_redirect(['swap_done'=>'advanced']);
        }

    /** Swaps waiting for the signed-in user's approval. @return object[] */
    private function swap_awaiting_my_approval(){
            if($this->swap_manager_mode()==='')return [];
            global $wpdb;$table=$this->ensure_swap_schema();$at=$this->approval_tables();
            return (array)$wpdb->get_results($wpdb->prepare("SELECT s.*,a.name requester_name,b.name target_name,st.id approval_step FROM {$table} s
                INNER JOIN {$at['requests']} ar ON ar.entity_type='schedule_swap' AND ar.entity_id=s.id AND ar.status LIKE 'WAITING%%'
                INNER JOIN {$at['instances']} st ON st.approval_request_id=ar.id AND st.status='PENDING' AND st.approver_wp_user_id=%d
                LEFT JOIN {$this->employees} a ON a.id=s.requester_employee_id LEFT JOIN {$this->employees} b ON b.id=s.target_employee_id
                WHERE s.status='Awaiting' ORDER BY s.work_date ASC LIMIT 50",get_current_user_id()));
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

    /* ------------------------------------------------------------------ My approvals / My requests (3.31.103) */

    /** A swap waiting for an approver (after the colleague accepted), for the My approvals inbox. @return array<string,mixed>|null */
    private function swap_approval_card($id){
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Awaiting' LIMIT 1",(int)$id));
            if(!$r)return null;
            $r->requester_name=$this->people_name((int)$r->requester_employee_id);$r->target_name=$this->people_name((int)$r->target_employee_id);
            $day=date_i18n('D j M',strtotime((string)$r->work_date));
            return ['employee_id'=>(int)$r->requester_employee_id,'tone'=>'swap','kind'=>__('Shift swap','workforce-one'),
                /* translators: 1: day, 2: colleague's name */
                'what'=>sprintf(__('%1$s with %2$s (they accepted)','workforce-one'),$day,(string)$r->target_name),
                'facts'=>[[__('Day','workforce-one'),$day],[(string)$r->requester_name,$r->requester_status.' → '.$r->target_status],[(string)$r->target_name,$r->target_status.' → '.$r->requester_status]],'since'=>(string)$r->created_at,
                'action'=>'ews_swap_decide','nonce'=>'ews_swap_decide_'.(int)$id,'fields'=>['swap_id'=>(int)$id],'anchor'=>'apv-swap-'.(int)$id,
                // The colleague's answer before the levels, as in the path the requester sees (3.31.124).
                'path_before'=>[['order'=>0,'mark'=>'↔','label'=>(string)$r->target_name,'who'=>'','state'=>'done','sub'=>__('Accepted','workforce-one')]]];
        }

    /** The swaps an employee asked for, for My requests (withdrawn on the Schedule page, as today). @return list<array<string,mixed>> */
    private function swap_my_requests($employee_id,$limit=10){
            global $wpdb;$table=$this->ensure_swap_schema();
            $out=[];$today=current_time('Y-m-d');$inbox=$this->my_approvals_enabled();
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE requester_employee_id=%d ORDER BY created_at DESC,id DESC LIMIT %d",(int)$employee_id,(int)$limit));
            $names=$this->people_brief_by_ids(array_map(function($r){return (int)$r->target_employee_id;},$rows));
            $levels=$this->swap_manager_mode()!=='';
            // Rejected swaps that reached the approvers (one query): the others were declined by the colleague.
            $engine=[];
            foreach($this->approval_find_requests(array_values(array_filter(array_map(function($r){return $r->status==='Rejected'?['shift_swap','schedule_swap',(int)$r->id]:null;},$rows)))) as $ar)$engine[(int)$ar->entity_id]=true;
            foreach($rows as $r){
                $name=isset($names[(int)$r->target_employee_id])?(string)$names[(int)$r->target_employee_id]->name:'';
                $state=SwapInbox::state((string)$r->status,(string)$r->work_date,$today);
                $peer=SwapInbox::waitsForPeer((string)$r->status,(string)$r->work_date,$today);
                // Where it stands (3.31.124): the colleague's answer first, then the approvers' levels (the engine's path, once it starts).
                if($peer)$sub=sprintf(/* translators: %s: colleague's name */__('With %s · waiting for their answer','workforce-one'),$name);
                elseif($r->status==='Pending')$sub=sprintf(/* translators: %s: colleague's name */__('With %s · the day passed before they answered','workforce-one'),$name);
                elseif($r->status==='Awaiting')$sub=sprintf(/* translators: %s: colleague's name */__('With %s · they accepted','workforce-one'),$name);
                elseif($state==='approved')$sub=sprintf(/* translators: %s: colleague's name */__('With %s · the days were exchanged','workforce-one'),$name);
                elseif($state==='withdrawn')$sub=sprintf(/* translators: %s: colleague's name */__('With %s · withdrawn','workforce-one'),$name);
                elseif($state==='rejected')$sub=!isset($engine[(int)$r->id])
                    ?sprintf(/* translators: %s: colleague's name */__('With %s · they declined','workforce-one'),$name):sprintf(/* translators: %s: colleague's name */__('With %s · not approved','workforce-one'),$name);
                else $sub=sprintf(/* translators: %s: colleague's name */__('With %s','workforce-one'),$name);
                $colleague=['order'=>0,'mark'=>'↔','label'=>$name!==''?$name:__('Colleague','workforce-one'),'who'=>'',
                    'state'=>$peer?'now':($r->status==='Pending'?'cancelled':'done'),'sub'=>$peer?__('Waiting for their answer','workforce-one'):($r->status==='Pending'?__('Did not answer','workforce-one'):__('Answered','workforce-one'))];
                $path=null;
                if($peer){
                    $steps=[$colleague];
                    if($levels)$steps[]=['order'=>1,'state'=>'next','label'=>__('Approver','workforce-one'),'who'=>'','sub'=>__('After they accept','workforce-one')];
                    $path=['id'=>0,'level'=>0,'levels'=>0,'hours'=>0.0,'overdue'=>false,'moved_from'=>'','note'=>'','steps'=>$steps];
                }
                $out[]=['key'=>'swap-'.(int)$r->id,'tone'=>'swap','title'=>__('Shift swap','workforce-one').' · '.date_i18n('j M',strtotime((string)$r->work_date)),'sub'=>$sub,
                    'state'=>$state,'when'=>(string)$r->created_at,'approval'=>['shift_swap','schedule_swap',(int)$r->id],'id'=>(int)$r->id,
                    'withdraw'=>$inbox && SwapInbox::canWithdraw((string)$r->status,(string)$r->work_date,$today)?'swap':'',
                    'path'=>$path,'path_before'=>in_array($r->status,['Awaiting','Accepted','Approved','Rejected'],true) && (int)$r->responded_by?[$colleague]:[],
                    'link'=>$inbox?'':add_query_arg('ews_view','schedule',$this->app_home_url())];
            }
            return $out;
        }

    /* ------------------------------------------------------------------ the colleague's answer in My approvals (3.31.124) */

    /** "Office" / "WFH" as the person reads it. */
    private function swap_status_word($status){
            $m=['Office'=>__('Office','workforce-one'),'WFH'=>__('WFH','workforce-one')];
            return $m[(string)$status]??(string)$status;
        }

    /**
     * Swaps asked of this employee and not answered yet, whatever week their day is in (owner decision 2026-10-24), with both
     * schedule days as they are now (a changed day can only be declined). One query.
     * @return object[]
     */
    private function swap_peer_waiting($employee_id){
            global $wpdb;$table=$this->ensure_swap_schema();
            return (array)$wpdb->get_results($wpdb->prepare("SELECT s.*,sa.status cur_a,sb.status cur_b FROM {$table} s
                LEFT JOIN {$this->schedule} sa ON sa.employee_id=s.requester_employee_id AND sa.work_date=s.work_date
                LEFT JOIN {$this->schedule} sb ON sb.employee_id=s.target_employee_id AND sb.work_date=s.work_date
                WHERE s.target_employee_id=%d AND s.status='Pending' AND s.work_date>=%s ORDER BY s.created_at ASC LIMIT 50",(int)$employee_id,current_time('Y-m-d')));
        }

    /**
     * swap_peer_waiting() as My approvals cards, with their requester (3.31.124): the requesters in one query, a deactivated one's
     * swap left out (design: edge cases), the Shift Swap type read once. @return list<array{0:object,1:object,2:array<string,mixed>}>
     */
    private function swap_peer_cards($employee_id){
            $rows=$this->swap_peer_waiting((int)$employee_id);
            if(!$rows)return [];
            $who=$this->people_by_ids(array_map(function($r){return (int)$r->requester_employee_id;},$rows),true);
            $levels=$this->swap_manager_mode()!=='';$out=[];
            foreach($rows as $r){
                $emp=$who[(int)$r->requester_employee_id]??null;
                if($emp)$out[]=[$r,$emp,$this->swap_peer_card($r,$emp,$levels)];
            }
            return $out;
        }

    /**
     * The card of a swap asked of the person (My approvals): accept or decline, with the form of the Schedule page (ews_swap_respond,
     * the same checks). No level: the colleague's answer is not an approval level. Never in "Approve the selected".
     * @param object $who the requester (an active employee row)
     * @param bool $levels whether the Shift Swap type has approval levels (swap_manager_mode(), read once by the caller)
     * @return array<string,mixed>
     */
    private function swap_peer_card($r,$who,$levels){
            $day=date_i18n('l j M',strtotime((string)$r->work_date));
            $first=trim((string)strtok((string)$who->name,' '));
            $stale=SwapRules::checkStillCurrent((string)$r->requester_status,(string)$r->target_status,$r->cur_a!==null?(string)$r->cur_a:null,$r->cur_b!==null?(string)$r->cur_b:null)!==null;
            $hint=$stale?__('Your or their day changed since; you can only decline it.','workforce-one'):($levels?__('After you accept, it goes to the approver.','workforce-one'):'');
            return ['employee_id'=>(int)$r->requester_employee_id,'tone'=>'swap','kind'=>__('Shift swap · asked of you','workforce-one'),'what'=>$day,'facts'=>[],'since'=>(string)$r->created_at,
                'action'=>'ews_swap_respond','nonce'=>'ews_swap_respond_'.(int)$r->id,'fields'=>['swap_id'=>(int)$r->id],
                'peer'=>true,'bulk'=>false,'item_key'=>'swap-'.(int)$r->id,'anchor'=>'apv-swap-'.(int)$r->id,'stale'=>$stale,'hint'=>$hint,
                'decisions'=>['yes'=>$stale?null:['accept',__('Accept','workforce-one')],'no'=>['reject',__('Decline','workforce-one')]],
                /* translators: %s: colleague's first name */
                'trade'=>[[__('You will work','workforce-one'),$this->swap_status_word((string)$r->requester_status)],[sprintf(__('%s will work','workforce-one'),$first),$this->swap_status_word((string)$r->target_status)]]];
        }

    /** Swaps this employee asked for that still wait (for the colleague or the approvers): the Schedule page's count. */
    private function swap_waiting_mine_count($employee_id){
            global $wpdb;$table=$this->ensure_swap_schema();
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE requester_employee_id=%d AND (status='Awaiting' OR (status='Pending' AND work_date>=%s))",(int)$employee_id,current_time('Y-m-d')));
        }

    /** A swap linked from a notification that no longer waits for this person: withdrawn | answered | past | other ('' when it still waits). */
    private function swap_state_for($id,$employee_id){
            global $wpdb;$table=$this->ensure_swap_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT status,work_date FROM {$table} WHERE id=%d AND (target_employee_id=%d OR requester_employee_id=%d)",(int)$id,(int)$employee_id,(int)$employee_id));
            if(!$r)return 'other';
            if($r->status==='Cancelled')return 'withdrawn';
            if($r->status==='Pending')return SwapInbox::waitsForPeer('Pending',(string)$r->work_date,current_time('Y-m-d'))?'':'past';
            return 'answered';
        }

    /** The requester withdraws a swap still waiting for the colleague (My requests), and the colleague is told (owner decision). @return true|string */
    private function swap_withdraw($id,$employee_id){
            $r=$this->swap_pending_row((int)$id,'AND requester_employee_id=%d',(int)$employee_id);
            if(!$r || !SwapInbox::canWithdraw('Pending',(string)$r->work_date,current_time('Y-m-d')))return 'done';
            $ok=$this->swap_close((int)$id,'Cancelled');
            if($ok!==1)return $ok===false?'save':'done';
            $name=$this->people_name((int)$employee_id);
            $this->swap_audit('SCHEDULE_SWAP_CANCELLED',$r,'Swap withdrawn by '.$name);
            $this->swap_notify($this->swap_employee_user_id((int)$r->target_employee_id),__('Swap request withdrawn','workforce-one'),
                /* translators: 1: colleague, 2: day */
                sprintf(__('%1$s withdrew the swap request for %2$s.','workforce-one'),$name,date_i18n('l, d M',strtotime((string)$r->work_date))),'info',(int)$id,true,$this->swap_inbox_url((int)$id));
            return true;
        }

    /**
     * The New request sheet's swap (3.31.105, owner decision 2026-10-16): the next two weeks' days this
     * employee is at the Office or at home, and the colleagues of the same department (their team
     * first; never another department, as the swap itself requires), each with their status that day
     * and whether a swap between the two is already waiting.
     * @return array{reason:string,days:array<string,string>,people:list<array{id:int,name:string,team:bool,days:array<string,array{status:string,ok:bool,busy:bool}>}>}
     */
    private function swap_new_choices($emp){
            global $wpdb;$table=$this->ensure_swap_schema();
            $out=['reason'=>'','days'=>[],'people'=>[]];
            $dept=$emp?(int)($emp->department_id??0):0;
            if(!$emp || !$dept){$out['reason']='department';return $out;}
            $today=current_time('Y-m-d');$to=gmdate('Y-m-d',strtotime($today.' +13 days'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT work_date,status FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY work_date",(int)$emp->id,$today,$to)) as $r)
                if(in_array((string)$r->status,SwapRules::SWAPPABLE,true))$out['days'][(string)$r->work_date]=(string)$r->status;
            if(!$out['days']){$out['reason']='days';return $out;}
            $ids=array_values(array_diff($this->people_department_member_ids($dept),[(int)$emp->id]));
            if(!$ids){$out['reason']='people';return $out;}
            $mates=[];
            foreach($this->team_ids_for_employee((int)$emp->id) as $tid)foreach($this->team_member_ids((int)$tid) as $m)$mates[(int)$m]=true;
            $ph=implode(',',array_fill(0,count($ids),'%d'));$dates=array_keys($out['days']);$dph=implode(',',array_fill(0,count($dates),'%s'));
            $st=[];
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE employee_id IN ($ph) AND work_date IN ($dph)",array_merge($ids,$dates))) as $r)$st[(int)$r->employee_id][(string)$r->work_date]=(string)$r->status;
            $busy=[];
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT requester_employee_id a,target_employee_id b,work_date FROM {$table} WHERE status IN ('Pending','Awaiting') AND work_date IN ($dph) AND (requester_employee_id=%d OR target_employee_id=%d)",array_merge($dates,[(int)$emp->id,(int)$emp->id]))) as $r)
                $busy[((int)$r->a===(int)$emp->id?(int)$r->b:(int)$r->a)][(string)$r->work_date]=true;
            $names=$this->people_brief_by_ids($ids);   // one query, not one per colleague (3.31.126)
            foreach($ids as $id){
                $days=[];
                foreach($out['days'] as $d=>$mine){$s=$st[$id][$d]??'';$days[$d]=['status'=>$s,'ok'=>SwapRules::checkSwappable($mine,$s?:null)===null,'busy'=>!empty($busy[$id][$d])];}
                $out['people'][]=['id'=>$id,'name'=>isset($names[$id])?$names[$id]->name:null,'team'=>!empty($mates[$id]),'days'=>$days];
            }
            usort($out['people'],function($a,$b){return [$b['team'],$a['name']]<=>[$a['team'],$b['name']];});
            return $out;
        }
}
