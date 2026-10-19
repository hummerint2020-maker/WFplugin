<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\Balance;
use WorkforceOne\Leave\TypeRules;
use WorkforceOne\Leave\CancellationRules;
use WorkforceOne\Leave\RequestRules;
use WorkforceOne\Leave\WorkingDays;
use WorkforceOne\Requests\Hub;

/**
 * Leave requests: submit, approve/reject, cancellation.
 *
 * Two modes share the same operations:
 * - normal: any user with ews_manage_settings decides;
 * - approval workflow ('vacation' workflow active, LEVEL_1 … LEVEL_4): the approval engine
 *   decides who may act and when the request is final.
 * Validation rules live in src/Leave (pure, unit tested); this trait does the WordPress side.
 * Behaviour is pinned by tests/e2e_leave.py.
 */
trait EWS_Leave_Trait {

    /* ------------------------------------------------------------------ helpers */

    private function leave_year(){ return (int)current_time('Y'); }

    private function leave_available($employee_id,$type_id){
            global $wpdb; $this->ensure_leave_schema();
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",$employee_id,$type_id,$this->leave_year()));
            return $r?Balance::available((float)$r->entitlement,(float)$r->used,(float)$r->pending):0;
        }

    /**
     * The balance row for this year, created on first use: the year's own days (the previous year's,
     * without what had been carried into it, or the type's annual entitlement), plus what the type
     * carries over from what was left last year (3.31.99; by default nothing, as before).
     */
    private function ensure_leave_balance($employee_id,$type_id,$year=null,$entitlement=null){
            global $wpdb; $this->ensure_leave_schema(); $year=$year?:$this->leave_year();
            $bt=$wpdb->prefix.'ews_leave_balances'; $tt=$wpdb->prefix.'ews_leave_types';
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",$employee_id,$type_id,$year));
            if($r)return $r;
            $carried=0.0;
            if($entitlement===null){
                $type=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$tt} WHERE id=%d",(int)$type_id));
                $entitlement=(float)($type->annual_entitlement??0);
                $prev=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year<%d ORDER BY leave_year DESC LIMIT 1",$employee_id,$type_id,$year));
                if($prev){
                    $entitlement=max(0.0,(float)$prev->entitlement-(float)($prev->carried??0));
                    // Only what was left of the year just before is carried.
                    if((int)$prev->leave_year===(int)$year-1)$carried=TypeRules::carried(TypeRules::config($type),(float)$prev->entitlement,(float)$prev->used,(float)$prev->pending);
                }
            }
            $wpdb->insert($bt,['employee_id'=>$employee_id,'leave_type_id'=>$type_id,'leave_year'=>$year,'entitlement'=>$entitlement+$carried,'used'=>0,'pending'=>0,'carried'=>$carried],['%d','%d','%d','%f','%f','%f','%f']);
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE id=%d",(int)$wpdb->insert_id));
        }

    private function leave_overlap($employee_id,$start,$end,$except_id=0){
            global $wpdb;
            return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_leave_requests WHERE employee_id=%d AND status IN ('Pending','Approved') AND start_date<=%s AND end_date>=%s AND id<>%d LIMIT 1",$employee_id,$end,$start,(int)$except_id));
        }

    /** Whether an employee is on leave (asked or approved) on any day from $from to $to (substitutes, 3.31.107). */
    private function leave_away_between($employee_id,$from,$to){
            return $this->leave_overlap((int)$employee_id,(string)$from,(string)$to);
        }

    /** A leave request's status ('' when there is none), for substitutes linked to a leave (3.31.107). */
    private function leave_status_of($leave_id){
            global $wpdb;
            return (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}ews_leave_requests WHERE id=%d",(int)$leave_id));
        }

    private function vacation_working_days_count($start,$end){
            return WorkingDays::count((string)$start,(string)$end,$this->working_days());
        }

    private function leave_redirect($args=[]){
            $url=wp_get_referer(); if(!$url||strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            $url=remove_query_arg(['leave_sent','leave_error','leave_done','leave_cancel_sent','early_error','break_success','break_error','overtime_sent','overtime_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view',$this->approvals_return_view('vacation'),$url); if($args)$url=add_query_arg($args,$url); wp_safe_redirect($url); exit;
        }

    private function leave_approval_active(){
            $w=$this->approval_workflow('vacation');
            if(!$w || (int)$w->active!==1)return false;
            return \WorkforceOne\Approvals\Workflows::isLevels((string)$w->approval_mode);
        }

    /** Leave request joined with its type (deduct_balance, type_name). */
    private function leave_request_row($id,$extra_where=''){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT r.*,lt.name type_name,lt.deduct_balance FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id=%d {$extra_where} LIMIT 1",$id));
        }

    /**
     * The balance row a request was charged to. Requests created before 3.31.1 have no balance_id;
     * they were charged to the year they were submitted in, so settle them there.
     */
    private function leave_request_balance($r){
            global $wpdb;
            if(!empty($r->balance_id)){
                $bal=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_balances WHERE id=%d",(int)$r->balance_id));
                if($bal)return $bal;
            }
            $year=!empty($r->requested_at)?(int)substr((string)$r->requested_at,0,4):$this->leave_year();
            return $this->ensure_leave_balance((int)$r->employee_id,(int)$r->leave_type_id,$year?:$this->leave_year());
        }

    private function leave_active_employee($employee_id){
            global $wpdb;
            return $this->people_employee((int)$employee_id,true);
        }

    /** In-app + push notification. */
    private function leave_notify($user_id,$title,$message,$type,$entity_id){
            $this->notify((int)$user_id,$type,$title,$message,['entity_id'=>$entity_id]);
        }

    /** Run $fn inside a transaction; any non-true result rolls back and redirects with that leave_error code. */
    private function leave_transaction(callable $fn){
            global $wpdb;
            if($wpdb->query('START TRANSACTION')===false)$this->leave_redirect(['leave_error'=>'save']);
            $result=$fn();
            if($result!==true){$wpdb->query('ROLLBACK');$this->leave_redirect(['leave_error'=>is_string($result)?$result:'save']);}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->leave_redirect(['leave_error'=>'save']);}
        }

    /* ------------------------------------------------------------------ state changes (caller owns the transaction) */

    /**
     * Insert a Pending request and reserve the days (caller owns the transaction and holds the
     * employee's leave lock). Checked again here, after the insert (3.31.91): no other pending or
     * approved leave of this employee overlaps it, and the reservation never takes the balance below
     * zero (a conditional update, so two requests sent together cannot both use the last days).
     * @return int|string request id, or an error code: save | overlap | balance
     */
    private function leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal){
            global $wpdb;
            $ok=$wpdb->insert($wpdb->prefix.'ews_leave_requests',['employee_id'=>$emp->id,'leave_type_id'=>(int)$type->id,'start_date'=>$start,'end_date'=>$end,'requested_days'=>$days,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'balance_id'=>$bal?(int)$bal->id:null],['%d','%d','%s','%s','%f','%s','%s','%d','%d']);
            if($ok===false)return 'save';
            $id=(int)$wpdb->insert_id;
            if($this->leave_overlap((int)$emp->id,$start,$end,$id))return 'overlap';
            if((int)$type->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET pending=pending+%f,updated_at=%s WHERE id=%d AND entitlement-used-pending>=%f",$days,current_time('mysql'),$bal->id,(float)$days-0.0001));
                if($q===false)return 'save';
                if($q!==1)return 'balance';
            }
            return $id;
        }

    /** Approve: Pending -> Approved, pending -> used, schedule snapshot + Vacation on each working day. */
    private function leave_apply_final_approval($r,$emp,$bal,$reviewer_id,$now){
            $this->substitutes_sync_at_shutdown();   // a manager's substitute follows the leave (3.31.107)
            global $wpdb;
            $t=$wpdb->prefix.'ews_leave_requests';
            if((string)$r->status==='Pending'){
                $ok=$wpdb->update($t,['status'=>'Approved','reviewed_by'=>(int)$reviewer_id,'reviewed_at'=>$now],['id'=>(int)$r->id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
                if($ok!==1)return new WP_Error('leave_state','Leave request was already handled.');
            }elseif((string)$r->status!=='Approved')return new WP_Error('leave_state','Leave request is not pending.');
            if((int)$r->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET pending=GREATEST(0,pending-%f),used=used+%f,updated_at=%s WHERE id=%d",$r->requested_days,$r->requested_days,$now,$bal->id));
                if($q===false)return new WP_Error('leave_balance','Could not update leave balance.');
            }
            $snap=$wpdb->prefix.'ews_leave_schedule_snapshots';
            foreach(WorkingDays::dates($r->start_date,$r->end_date,$this->working_days()) as $date){
                $existing=$this->schedule_for_employee_date((int)$emp->id,$date);
                if($existing){
                    $q=$wpdb->insert($snap,['leave_request_id'=>$r->id,'employee_id'=>$emp->id,'work_date'=>$date,'had_schedule'=>1,'schedule_id'=>$existing->id,'status'=>$existing->status,'note'=>$existing->note,'schedule_data'=>wp_json_encode($existing)],['%d','%d','%s','%d','%d','%s','%s','%s']);
                    if($q===false)return new WP_Error('leave_schedule','Could not snapshot schedule.');
                    $q=$wpdb->update($this->schedule,['status'=>'Vacation','note'=>'Approved leave #'.$r->id,'updated_by'=>(int)$reviewer_id,'updated_at'=>$now],['id'=>$existing->id]);
                }else{
                    $q=$wpdb->insert($snap,['leave_request_id'=>$r->id,'employee_id'=>$emp->id,'work_date'=>$date,'had_schedule'=>0],['%d','%d','%s','%d']);
                    if($q===false)return new WP_Error('leave_schedule','Could not snapshot schedule.');
                    $q=$wpdb->insert($this->schedule,['employee_id'=>$emp->id,'work_date'=>$date,'status'=>'Vacation','note'=>'Approved leave #'.$r->id,'updated_by'=>(int)$reviewer_id,'updated_at'=>$now],['%d','%s','%s','%s','%d','%s']);
                }
                if($q===false)return new WP_Error('leave_schedule','Could not apply vacation schedule.');
            }
            return true;
        }

    /** Reject: Pending -> Rejected and release the reserved days. */
    private function leave_reject_approval($r,$bal,$reviewer_id,$now){
            $this->substitutes_sync_at_shutdown();   // a manager's substitute follows the leave (3.31.107)
            global $wpdb;
            $ok=$wpdb->update($wpdb->prefix.'ews_leave_requests',['status'=>'Rejected','reviewed_by'=>(int)$reviewer_id,'reviewed_at'=>$now],['id'=>(int)$r->id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($ok!==1)return new WP_Error('leave_state','Leave request was already handled.');
            if((int)$r->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET pending=GREATEST(0,pending-%f),updated_at=%s WHERE id=%d",$r->requested_days,$now,$bal->id));
                if($q===false)return new WP_Error('leave_balance','Could not update leave balance.');
            }
            return true;
        }

    /** Cancellation approved: return used days, restore the snapshotted schedule, mark Cancelled. @return true|string leave_error */
    private function leave_apply_cancellation($r,$emp,$bal,$now){
            $this->substitutes_sync_at_shutdown();   // a manager's substitute follows the leave (3.31.107)
            global $wpdb;
            if((int)$r->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d",$r->requested_days,$now,$bal->id));
                if($q===false)return 'save';
            }
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date ASC",(int)$r->id));
            if($rows===null)return 'save';
            foreach($rows as $x){
                if((int)$x->had_schedule&&$x->schedule_id)$q=$wpdb->update($this->schedule,['status'=>$x->status,'note'=>$x->note,'updated_by'=>get_current_user_id(),'updated_at'=>$now],['id'=>$x->schedule_id]);
                else $q=$wpdb->delete($this->schedule,['employee_id'=>$emp->id,'work_date'=>$x->work_date]);
                if($q===false)return 'save';
            }
            $q=$wpdb->update($wpdb->prefix.'ews_leave_requests',['status'=>'Cancelled','cancellation_status'=>'Approved','cancelled_by'=>get_current_user_id(),'cancelled_at'=>$now],['id'=>(int)$r->id,'status'=>'Approved','cancellation_status'=>'Pending'],['%s','%s','%d','%s'],['%d','%s','%s']);
            if($q!==1)return $q===false?'save':'done';
            return true;
        }

    /** @return true|string leave_error */
    private function leave_reject_cancellation($id){
            global $wpdb;
            $q=$wpdb->update($wpdb->prefix.'ews_leave_requests',['cancellation_status'=>'Rejected'],['id'=>(int)$id,'status'=>'Approved','cancellation_status'=>'Pending'],['%s'],['%d','%s','%s']);
            if($q!==1)return $q===false?'save':'done';
            return true;
        }

    /** Mark an approved leave's cancellation as requested. @return int|false rows changed */
    private function leave_mark_cancellation_pending($id){
            global $wpdb;
            return $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_requests SET cancellation_status='Pending' WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status='Not Requested')",(int)$id));
        }

    /** Notify the approvers of the pending step(s) of an approval request. */
    private function leave_notify_pending_approvers($approval_request_id,$title,$msg,$entity_id){
            global $wpdb;
            $steps=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->approval_tables()['instances']} WHERE approval_request_id=%d AND status='PENDING'",(int)$approval_request_id));
            foreach($steps as $step){
                // A level waiting for administrators has no approver: they are told by the approval engine.
                if(!(int)$step->approver_wp_user_id || (int)$step->approver_wp_user_id===(int)get_current_user_id())continue;
                $this->leave_notify($step->approver_wp_user_id,$title,$msg,'vacation',$entity_id);
            }
        }

    /* ------------------------------------------------------------------ request handlers */

    public function leave_request_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_vacation_request_create');
            $emp=$this->current_employee();
            if(!$emp)$this->leave_redirect(['leave_error'=>'employee']);
            global $wpdb; $this->ensure_leave_schema();
            $type_id=absint($_POST['leave_type_id']??0);
            $start=sanitize_text_field(wp_unslash($_POST['start_date']??''));
            $end=sanitize_text_field(wp_unslash($_POST['end_date']??''));
            $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
            $type=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE id=%d AND active=1",$type_id));
            $days=$this->vacation_working_days_count($start,$end);

            // Same order as before: dates, working days, overlap, then balance.
            $facts=['type_active'=>(bool)$type,'start'=>$start,'end'=>$end,'today'=>current_time('Y-m-d'),'working_days'=>$days,'overlaps'=>false,'deducts'=>false,'has_balance'=>true,'remaining'=>0.0];
            // Planned: from tomorrow; unplanned: on the day or up to the type's days after it (3.31.99).
            if($type){$tc=TypeRules::config($type);$facts['earliest']=TypeRules::earliestStart($tc,$facts['today']);$facts['unplanned']=$tc['kind']==='unplanned';}
            $error=RequestRules::check($facts);
            if(!$error){
                $facts['overlaps']=$this->leave_overlap((int)$emp->id,$start,$end);
                $error=RequestRules::check($facts);
            }
            $bal=null;
            if(!$error){
                // Charged to the balance of the year the leave falls in (not the year it is requested in).
                $bal=$this->ensure_leave_balance((int)$emp->id,$type_id,RequestRules::year($start));
                $facts['has_balance']=(bool)$bal;
                $facts['deducts']=(bool)(int)$type->deduct_balance;
                $facts['remaining']=$bal?Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending):0.0;
                $error=RequestRules::check($facts);
            }
            // A manager's substitute while away (3.31.107), picked in the same form.
            if(!$error)$error=$this->substitute_leave_check($emp,$start,$end);
            if($error)$this->leave_redirect(['leave_error'=>$error]);
            // One request at a time per employee (3.31.91): two requests sent together could both pass the
            // overlap and balance checks. leave_insert_request() checks both again inside the transaction.
            // The rest always ends in a redirect; the lock goes with the database connection.
            $this->ews_with_lock('leave_emp_'.(int)$emp->id,function()use($emp,$type,$start,$end,$days,$reason,$bal){$this->leave_request_create_locked($emp,$type,$start,$end,$days,$reason,$bal);});
            $this->leave_redirect(['leave_error'=>'busy']);
        }

    /** The second half of leave_request_create(), under the employee's leave lock. Always redirects. */
    private function leave_request_create_locked($emp,$type,$start,$end,$days,$reason,$bal){
            $request_msg=$emp->name.' requested '.$days.' day(s) of '.$type->name.' from '.$start.' to '.$end.'. Approval is required.';
            if($this->leave_approval_active()){
                $id=0; $approval=0; $ar=null;
                $this->leave_transaction(function()use(&$id,&$approval,&$ar,$emp,$type,$start,$end,$days,$reason,$bal){
                    $id=$this->leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal);
                    if(!is_int($id))return $id;
                    $approval=$this->approval_start('vacation','leave',$id,(int)$emp->id,['amount'=>(float)$days],false);
                    if(is_wp_error($approval))return 'approval';
                    $ar=$this->approval_find_request('vacation','leave',$id);
                    if($ar&&$ar->status==='APPROVED'){
                        // The workflow resolved immediately (e.g. no approver needed): apply now.
                        $final=$this->leave_apply_final_approval($this->leave_request_row($id),$emp,$bal,get_current_user_id(),current_time('mysql'));
                        if(is_wp_error($final))return 'save';
                    }
                    return true;
                });
                $this->audit('leave_request','leave_request',$id,$emp->name.' requested '.$days.' day(s) of '.$type->name);
                $this->substitute_leave_saved($emp,$id,$start,$end);
                if($ar&&$ar->status==='APPROVED'){
                    if($emp->wp_user_id)$this->leave_notify($emp->wp_user_id,'Vacation Approved','Your '.$type->name.' request from '.$start.' to '.$end.' has been approved.','vacation',$id);
                }else{
                    $this->leave_notify_pending_approvers($approval,'Vacation Request',$request_msg,$id);
                }
                $this->leave_redirect(['leave_sent'=>$days]);
            }

            $id=0;
            $this->leave_transaction(function()use(&$id,$emp,$type,$start,$end,$days,$reason,$bal){
                $id=$this->leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal);
                return is_int($id)?true:$id;
            });
            $this->audit('leave_request','leave_request',$id,$emp->name.' requested '.$days.' day(s) of '.$type->name);
            $this->substitute_leave_saved($emp,$id,$start,$end);
            foreach(get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]) as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $this->notify((int)$m->ID,'leave','Leave Request',$request_msg,['entity_id'=>$id]);
            }
            $this->leave_redirect(['leave_sent'=>$days]);
        }

    public function leave_request_respond(){
            check_admin_referer('ews_vacation_respond');
            $id=absint($_POST['request_id']??0);
            $decision=sanitize_key($_POST['decision']??'');
            if(!$id||!in_array($decision,['approve','reject'],true))wp_die('Invalid decision.');
            $this->ensure_leave_schema();

            $ar=null;
            if($this->leave_approval_active()){
                $ar=$this->approval_find_request('vacation','leave',$id);
                if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))$this->leave_redirect(['leave_done'=>1]);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

            $r=$this->leave_request_row($id);
            if(!$r||$r->status!=='Pending')$this->leave_redirect(['leave_done'=>1]);
            $emp=$this->leave_active_employee($r->employee_id);
            if(!$emp)$this->leave_redirect(['leave_error'=>'employee']);
            if(!$ar && !$this->approval_self_gate((int)($emp->wp_user_id??0),$this->approval_self_rule('vacation')==='allow','vacation #'.(int)$id))$this->leave_redirect(['leave_error'=>'self']);
            $bal=$this->leave_request_balance($r);
            if(!$bal)$this->leave_redirect(['leave_error'=>'balance']);
            $now=current_time('mysql');

            // Outcome: 'APPROVED', 'REJECTED' or (multi-level) still waiting for the next level.
            $outcome=$decision==='approve'?'APPROVED':'REJECTED';
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now){
                if($ar){
                    // The reason typed with the decision (3.31.103), needed to reject when the workflow asks for one.
                    $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),false);
                    if(is_wp_error($acted))return $acted->get_error_code()==='approval_note_required'?'note':'approval';
                    $fresh=$this->approval_find_request('vacation','leave',$id);
                    if(!$fresh)return 'approval';
                    $outcome=$fresh->status;
                }
                if($outcome==='REJECTED'&&is_wp_error($this->leave_reject_approval($r,$bal,get_current_user_id(),$now)))return 'save';
                if($outcome==='APPROVED'&&is_wp_error($this->leave_apply_final_approval($r,$emp,$bal,get_current_user_id(),$now)))return 'save';
                return true;
            });

            $workflow=(bool)$ar;
            if($outcome==='APPROVED'||$outcome==='REJECTED'){
                $msg='Your '.$r->type_name.' request from '.$r->start_date.' to '.$r->end_date.' has been '.($outcome==='APPROVED'?'approved':'rejected').'.';
                if($emp->wp_user_id){
                    $title=$outcome==='APPROVED'?'Leave Approved':'Leave Rejected';
                    $this->leave_notify($emp->wp_user_id,$title,$msg,$workflow?'leave':'vacation',$id);
                }
            }else{
                $pending=$this->approval_current_step((int)$ar->id);
                if($pending&&$pending->approver_wp_user_id)$this->leave_notify($pending->approver_wp_user_id,'Vacation Request',$emp->name.' '.$r->type_name.' request from '.$r->start_date.' to '.$r->end_date.' is waiting for your approval.','vacation',$id);
            }
            $this->audit($decision==='approve'?'leave_approved':'leave_rejected','leave_request',$id,ucfirst($decision).' by '.wp_get_current_user()->display_name);
            $this->leave_redirect($workflow&&$outcome==='REJECTED'?['leave_rejected'=>1]:['leave_done'=>1]);
        }

    public function leave_cancellation_allowed($r){
            if(!$r)return false;
            return CancellationRules::check((string)$r->status,$r->cancellation_status??null,$r->start_date??null,current_time('Y-m-d'))===null;
        }

    public function leave_cancel_request(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_leave_cancel');
            global $wpdb; $this->ensure_leave_schema();
            $emp=$this->current_employee();
            $id=absint($_POST['request_id']??0);
            if(!$emp)wp_die('Employee not found.');
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_requests WHERE id=%d AND employee_id=%d LIMIT 1",$id,$emp->id));
            if(!$r)wp_die('Leave request not found.');
            $messages=[
                CancellationRules::NOT_APPROVED=>'Only an approved leave can be cancelled.',
                CancellationRules::ALREADY_PENDING=>'Cancellation request is already pending.',
                CancellationRules::ALREADY_REJECTED=>'Cancellation request was already rejected and cannot be submitted again.',
                CancellationRules::ALREADY_CANCELLED=>'This leave has already been cancelled.',
                CancellationRules::STARTED=>'Cancellation is no longer available because this leave has already started or is starting today.',
            ];
            $rule=CancellationRules::check((string)$r->status,$r->cancellation_status,$r->start_date,current_time('Y-m-d'));
            if($rule)wp_die($messages[$rule]);

            if($this->leave_approval_active()){
                $approval=0;
                $this->leave_transaction(function()use(&$approval,$id,$emp){
                    $ok=$this->leave_mark_cancellation_pending($id);
                    if($ok!==1)return $ok===false?'save':'done';
                    $approval=$this->approval_start('vacation','leave_cancellation',$id,(int)$emp->id,['leave_request_id'=>$id],false);
                    if(is_wp_error($approval))return 'approval';
                    $ar=$this->approval_find_request('vacation','leave_cancellation',$id);
                    if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))return 'approval';
                    return true;
                });
                $this->leave_notify_pending_approvers($approval,'Vacation Cancellation Request',$emp->name.' requested cancellation of '.$r->start_date.' to '.$r->end_date.'. Approval is required.',$id);
                $this->leave_redirect(['leave_cancel_sent'=>1]);
            }
            $ok=$this->leave_mark_cancellation_pending($id);
            if($ok!==1)wp_die($ok===false?'Could not submit cancellation request. Database error.':'Cancellation request is no longer pending.');
            $this->leave_redirect(['leave_cancel_sent'=>1]);
        }

    public function leave_cancel_respond(){
            check_admin_referer('ews_leave_cancel_respond');
            $this->ensure_leave_schema();
            $id=absint($_POST['request_id']??0);
            $decision=sanitize_key($_POST['decision']??'');

            $ar=null;
            if($this->leave_approval_active()){
                $ar=$this->approval_find_request('vacation','leave_cancellation',$id);
                if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))$this->leave_redirect(['leave_done'=>1]);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

            $r=$this->leave_request_row($id,"AND r.status='Approved' AND r.cancellation_status='Pending'");
            if(!$r||!in_array($decision,['approve','reject'],true))wp_die('Invalid cancellation.');
            $emp=$this->leave_active_employee($r->employee_id);
            if(!$emp)wp_die('Employee not found.');
            if(!$ar && !$this->approval_self_gate((int)($emp->wp_user_id??0),$this->approval_self_rule('vacation')==='allow','vacation cancellation #'.(int)$id))wp_die(esc_html__('You cannot decide your own request. Another approver must.','workforce-one'),'',['response'=>403]);
            $bal=$this->leave_request_balance($r);
            if(!$bal)wp_die('Leave balance unavailable.');
            $now=current_time('mysql');

            $outcome=$decision==='approve'?'APPROVED':'REJECTED';
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now){
                if($ar){
                    $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),false);
                    if(is_wp_error($acted))return $acted->get_error_code()==='approval_note_required'?'note':'approval';
                    $fresh=$this->approval_find_request('vacation','leave_cancellation',$id);
                    if(!$fresh)return 'approval';
                    $outcome=$fresh->status;
                }
                if($outcome==='APPROVED')return $this->leave_apply_cancellation($r,$emp,$bal,$now);
                if($outcome==='REJECTED')return $this->leave_reject_cancellation($id);
                return true;
            });

            if($outcome==='APPROVED'||$outcome==='REJECTED'){
                $msg=$outcome==='APPROVED'?'Your '.$r->type_name.' leave was cancelled and the previous schedule was restored.':'Your '.$r->type_name.' leave cancellation request was rejected.';
                if($emp->wp_user_id)$this->leave_notify($emp->wp_user_id,$outcome==='APPROVED'?'Leave Cancelled':'Leave Cancellation Rejected',$msg,'vacation',$id);
            }else{
                $pending=$this->approval_current_step((int)$ar->id);
                if($pending&&$pending->approver_wp_user_id)$this->leave_notify($pending->approver_wp_user_id,'Vacation Cancellation Request','Your '.$r->type_name.' leave cancellation is waiting for your approval.','vacation',$id);
            }
            if($ar)$this->audit($decision==='approve'?'leave_cancel_approved':'leave_cancel_rejected','leave_request',$id,ucfirst($decision).' cancellation approval by '.wp_get_current_user()->display_name);
            else $this->audit($decision==='approve'?'leave_cancelled':'leave_cancel_rejected','leave_request',$id,ucfirst($decision).' cancellation by '.wp_get_current_user()->display_name);
            $this->leave_redirect(['leave_done'=>1]);
        }

    /**
     * Decision from the wp-admin Requests Hub (administrator override). Uses the same operations
     * as the employee app, so balances, schedule snapshots and the charged year match.
     * Returns a Hub outcome: approved, rejected, advanced or error.
     */
    private function leave_admin_decide($id,$cancel,$decision){
            global $wpdb;
            $this->ensure_leave_schema();
            $r=$this->leave_request_row($id,$cancel?"AND r.status='Approved' AND r.cancellation_status='Pending'":"AND r.status='Pending'");
            if(!$r)return Hub::ERROR;
            $emp=$this->leave_active_employee($r->employee_id);
            $bal=$this->leave_request_balance($r);
            if(!$emp||!$bal)return Hub::ERROR;
            $now=current_time('mysql');
            $apply=function($outcome)use($cancel,$r,$emp,$bal,$id,$now){
                if($cancel)return $outcome==='APPROVED'?$this->leave_apply_cancellation($r,$emp,$bal,$now):$this->leave_reject_cancellation($id);
                $ok=$outcome==='APPROVED'?$this->leave_apply_final_approval($r,$emp,$bal,get_current_user_id(),$now):$this->leave_reject_approval($r,$bal,get_current_user_id(),$now);
                return is_wp_error($ok)?'save':true;
            };
            $commit=function($outcome)use($apply,$wpdb){
                if($wpdb->query('START TRANSACTION')===false)return false;
                if($apply($outcome)!==true){$wpdb->query('ROLLBACK');return false;}
                if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return false;}
                return true;
            };
            $entity=$cancel?'leave_cancellation':'leave';
            $ar=$this->approval_find_request('vacation',$entity,$id);
            if($ar && Hub::isOpenApproval((string)$ar->status)){
                // The decision and, when it is the last one, the leave itself in one transaction (3.31.102):
                // before, the balance could change while the leave stayed Pending, or the other way round.
                if($wpdb->query('START TRANSACTION')===false)return Hub::ERROR;
                if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',false,true))){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                $fresh=$this->approval_find_request('vacation',$entity,$id);
                if(!$fresh){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                $outcome=Hub::workflowOutcome((string)$fresh->status);
                if($outcome!==Hub::ADVANCED && $apply($outcome===Hub::APPROVED?'APPROVED':'REJECTED')!==true){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return Hub::ERROR;}
                if($outcome!==Hub::ADVANCED){
                    if(!$cancel)$this->notify((int)$emp->wp_user_id,'leave','Leave '.ucfirst($outcome),'Your '.$r->type_name.' request from '.$r->start_date.' to '.$r->end_date.' has been '.$outcome.'.',['entity_id'=>$id]);
                    return $outcome;
                }
                $next=$this->approval_current_step((int)$fresh->id);
                if($next&&$next->approver_wp_user_id){
                    if($cancel)$this->notify((int)$next->approver_wp_user_id,'vacation','Vacation Cancellation Request',$emp->name.' vacation cancellation is waiting for your approval.',['entity_id'=>$id]);
                    else $this->notify((int)$next->approver_wp_user_id,'vacation','Vacation Request',$emp->name.' '.$r->type_name.' request is waiting for your approval.',['entity_id'=>$id]);
                }
                return Hub::ADVANCED;
            }
            if(!$cancel)$this->approval_self_gate((int)($emp->wp_user_id??0),true,'vacation #'.(int)$id);   // administrators may; the Audit Log marks it
            return $commit($decision==='approve'?'APPROVED':'REJECTED')?Hub::outcome($decision):Hub::ERROR;
        }

    /* ------------------------------------------------------------------ My approvals / My requests (3.31.103) */

    private function leave_day_range($start,$end){
            $a=date_i18n('j M',strtotime((string)$start));
            return $start===$end?$a:$a.' – '.date_i18n('j M',strtotime((string)$end));
        }

    /**
     * A leave (or a leave cancellation) waiting for a decision, for the My approvals inbox: what it is,
     * the facts the approver needs, and the form that decides it (the same as on the Leave page).
     * @return array<string,mixed>|null null when it no longer waits
     */
    private function leave_approval_card($entity_type,$id){
            global $wpdb;
            $cancel=$entity_type==='leave_cancellation';
            $r=$this->leave_request_row((int)$id,$cancel?"AND r.status='Approved' AND r.cancellation_status='Pending'":"AND r.status='Pending'");
            if(!$r)return null;
            $days=(float)$r->requested_days;
            $bal=$this->leave_request_balance($r);
            $left=$bal?Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending)+($cancel?$days:0):null;
            $who=$this->people_employee((int)$r->employee_id);$dept=$who?(int)($who->department_id??0):0;
            $away=null;
            if($dept){
                $ids=array_values(array_diff($this->people_department_member_ids($dept),[(int)$r->employee_id]));
                $away=$ids?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT employee_id) FROM {$wpdb->prefix}ews_leave_requests WHERE employee_id IN (".implode(',',array_fill(0,count($ids),'%d')).") AND status='Approved' AND start_date<=%s AND end_date>=%s",array_merge($ids,[$r->end_date,$r->start_date]))):0;
            }
            /* translators: %s: number of days */
            $days_txt=sprintf(_n('%s day','%s days',(int)ceil($days),'workforce-one'),number_format_i18n($days,floor($days)==$days?0:1));
            $facts=[[__('Days','workforce-one'),$days_txt],[__('Dates','workforce-one'),$this->leave_day_range($r->start_date,$r->end_date)]];
            if($left!==null && (int)$r->deduct_balance)$facts[]=[$cancel?__('Balance after cancelling','workforce-one'):__('Balance after','workforce-one'),number_format_i18n($left,floor($left)==$left?0:1)];
            if($away!==null)$facts[]=[__('Others in the department away','workforce-one'),number_format_i18n($away)];
            if(trim((string)$r->reason)!=='')$facts[]=[__('Reason','workforce-one'),(string)$r->reason];
            return ['employee_id'=>(int)$r->employee_id,'tone'=>'leave','kind'=>$cancel?__('Leave cancellation','workforce-one'):__('Leave','workforce-one'),
                'what'=>$r->type_name.' · '.$days_txt.' · '.$this->leave_day_range($r->start_date,$r->end_date),'facts'=>$facts,'since'=>(string)$r->requested_at,
                'action'=>$cancel?'ews_leave_cancel_respond':'ews_vacation_request_respond','nonce'=>$cancel?'ews_leave_cancel_respond':'ews_vacation_respond','fields'=>['request_id'=>(int)$id]];
        }

    /**
     * An employee's leave requests for My requests, newest first. @return list<array<string,mixed>>
     */
    private function leave_my_requests($employee_id,$limit=20){
            global $wpdb;$this->ensure_leave_schema();
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT r.*,lt.name type_name FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC,r.id DESC LIMIT %d",(int)$employee_id,(int)$limit));
            $out=[];
            foreach($rows as $r){
                $cancel=$r->status==='Approved' && $r->cancellation_status==='Pending';
                // Cancelled before it was decided = withdrawn by the employee (3.31.103); after approval = cancelled.
                $state=$cancel?'waiting':(['Pending'=>'waiting','Approved'=>'approved','Rejected'=>'rejected'][$r->status]??($r->status==='Cancelled' && $r->cancellation_status!=='Approved'?'withdrawn':'cancelled'));
                $out[]=['key'=>'leave-'.(int)$r->id,'tone'=>'leave','title'=>$r->type_name.' · '.$this->leave_day_range($r->start_date,$r->end_date),
                    'sub'=>$cancel?__('Cancellation asked','workforce-one'):'','state'=>$state,'when'=>(string)$r->requested_at,
                    'approval'=>['vacation',$cancel?'leave_cancellation':'leave',(int)$r->id],'withdraw'=>$r->status==='Pending'?'leave':'','id'=>(int)$r->id];
            }
            return $out;
        }

    /** The employee withdraws a leave still waiting (My requests): its days are no longer reserved. @return true|string */
    private function leave_withdraw($id,$employee_id){
            global $wpdb;
            $r=$this->leave_request_row((int)$id,$wpdb->prepare("AND r.status='Pending' AND r.employee_id=%d",(int)$employee_id));
            if(!$r)return 'done';
            $bal=$this->leave_request_balance($r);$now=current_time('mysql');
            if($wpdb->query('START TRANSACTION')===false)return 'save';
            $ok=$wpdb->update($wpdb->prefix.'ews_leave_requests',['status'=>'Cancelled','cancelled_by'=>get_current_user_id(),'cancelled_at'=>$now],['id'=>(int)$r->id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($ok!==1){$wpdb->query('ROLLBACK');return 'done';}
            if((int)$r->deduct_balance && $bal && $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET pending=GREATEST(0,pending-%f),updated_at=%s WHERE id=%d",$r->requested_days,$now,$bal->id))===false){$wpdb->query('ROLLBACK');return 'save';}
            if(!$this->approval_close('vacation','leave',(int)$r->id)){$wpdb->query('ROLLBACK');return 'save';}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
            $this->audit('leave_withdrawn','leave_request',(int)$r->id,$r->type_name.' '.$r->start_date.' – '.$r->end_date);
            return true;
        }
}
