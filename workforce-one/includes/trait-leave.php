<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\Balance;
use WorkforceOne\Leave\CancellationRules;
use WorkforceOne\Leave\RequestRules;
use WorkforceOne\Leave\WorkingDays;

/**
 * Leave requests: submit, approve/reject, cancellation.
 *
 * Two modes share the same operations:
 * - normal: any user with ews_manage_settings decides;
 * - approval workflow ('vacation' workflow active, LEVEL_1/LEVEL_2): the approval engine
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

    /** The balance row for this year, created on first use (entitlement carried from the previous year). */
    private function ensure_leave_balance($employee_id,$type_id,$year=null,$entitlement=null){
            global $wpdb; $this->ensure_leave_schema(); $year=$year?:$this->leave_year();
            $bt=$wpdb->prefix.'ews_leave_balances'; $tt=$wpdb->prefix.'ews_leave_types';
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",$employee_id,$type_id,$year));
            if($r)return $r;
            if($entitlement===null){
                $entitlement=(float)$wpdb->get_var($wpdb->prepare("SELECT annual_entitlement FROM {$tt} WHERE id=%d",(int)$type_id));
                $prev=$wpdb->get_var($wpdb->prepare("SELECT entitlement FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year<%d ORDER BY leave_year DESC LIMIT 1",$employee_id,$type_id,$year));
                if($prev!==null)$entitlement=(float)$prev;
            }
            $wpdb->insert($bt,['employee_id'=>$employee_id,'leave_type_id'=>$type_id,'leave_year'=>$year,'entitlement'=>$entitlement,'used'=>0,'pending'=>0],['%d','%d','%d','%f','%f','%f']);
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE id=%d",(int)$wpdb->insert_id));
        }

    private function leave_overlap($employee_id,$start,$end){
            global $wpdb;
            return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_leave_requests WHERE employee_id=%d AND status IN ('Pending','Approved') AND start_date<=%s AND end_date>=%s LIMIT 1",$employee_id,$end,$start));
        }

    private function vacation_working_days_count($start,$end){
            return WorkingDays::count((string)$start,(string)$end,$this->working_days());
        }

    private function vacation_date_is_future($date){
            return RequestRules::isFutureDate((string)$date,current_time('Y-m-d'));
        }

    private function leave_redirect($args=[]){
            $url=wp_get_referer(); if(!$url||strpos($url,'admin-post.php')!==false)$url=home_url('/');
            $url=remove_query_arg(['leave_sent','leave_error','leave_done','leave_cancel_sent','early_error','break_success','break_error','overtime_sent','overtime_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','vacation',$url); if($args)$url=add_query_arg($args,$url); wp_safe_redirect($url); exit;
        }

    private function leave_approval_active(){
            $w=$this->approval_workflow('vacation');
            if(!$w || (int)$w->active!==1)return false;
            return in_array(strtoupper((string)$w->approval_mode),['LEVEL_1','LEVEL_2'],true);
        }

    /** Leave request joined with its type (deduct_balance, type_name). */
    private function leave_request_row($id,$extra_where=''){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT r.*,lt.name type_name,lt.deduct_balance FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id=%d {$extra_where} LIMIT 1",$id));
        }

    private function leave_active_employee($employee_id){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1",(int)$employee_id));
        }

    /** In-app + push notification. */
    private function leave_notify($user_id,$title,$message,$type,$entity_id){
            $this->notify_user((int)$user_id,$title,$message,$type,$type,$entity_id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$user_id,$title,$message,$type,$entity_id);
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

    /** Insert a Pending request and reserve the days. @return int|false request id */
    private function leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal){
            global $wpdb;
            $ok=$wpdb->insert($wpdb->prefix.'ews_leave_requests',['employee_id'=>$emp->id,'leave_type_id'=>(int)$type->id,'start_date'=>$start,'end_date'=>$end,'requested_days'=>$days,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id()],['%d','%d','%s','%s','%f','%s','%s','%d']);
            if($ok===false)return false;
            $id=(int)$wpdb->insert_id;
            if((int)$type->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET pending=pending+%f,updated_at=%s WHERE id=%d",$days,current_time('mysql'),$bal->id));
                if($q===false)return false;
            }
            return $id;
        }

    /** Approve: Pending -> Approved, pending -> used, schedule snapshot + Vacation on each working day. */
    private function leave_apply_final_approval($r,$emp,$bal,$reviewer_id,$now){
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
                if((int)$step->approver_wp_user_id===(int)get_current_user_id())continue;
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
            $start=sanitize_text_field($_POST['start_date']??'');
            $end=sanitize_text_field($_POST['end_date']??'');
            $reason=trim(sanitize_textarea_field($_POST['reason']??''));
            $type=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE id=%d AND active=1",$type_id));
            $days=$this->vacation_working_days_count($start,$end);

            // Same order as before: dates, working days, overlap, then balance.
            $facts=['type_active'=>(bool)$type,'start'=>$start,'end'=>$end,'today'=>current_time('Y-m-d'),'working_days'=>$days,'overlaps'=>false,'deducts'=>false,'has_balance'=>true,'remaining'=>0.0];
            $error=RequestRules::check($facts);
            if(!$error){
                $facts['overlaps']=$this->leave_overlap((int)$emp->id,$start,$end);
                $error=RequestRules::check($facts);
            }
            $bal=null;
            if(!$error){
                $bal=$this->ensure_leave_balance((int)$emp->id,$type_id);
                $facts['has_balance']=(bool)$bal;
                $facts['deducts']=(bool)(int)$type->deduct_balance;
                $facts['remaining']=$bal?Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending):0.0;
                $error=RequestRules::check($facts);
            }
            if($error)$this->leave_redirect(['leave_error'=>$error]);

            $request_msg=$emp->name.' requested '.$days.' day(s) of '.$type->name.' from '.$start.' to '.$end.'. Approval is required.';
            if($this->leave_approval_active()){
                $id=0; $approval=0; $ar=null;
                $this->leave_transaction(function()use(&$id,&$approval,&$ar,$emp,$type,$start,$end,$days,$reason,$bal){
                    $id=$this->leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal);
                    if(!$id)return 'save';
                    $approval=$this->approval_start('vacation','leave',$id,(int)$emp->id,[],false);
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
                return $id?true:'save';
            });
            $this->audit('leave_request','leave_request',$id,$emp->name.' requested '.$days.' day(s) of '.$type->name);
            foreach(get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]) as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $this->notify_user($m->ID,'Leave Request',$request_msg,'leave','leave',$id);
                if(method_exists($this,'push_custom_notification'))$this->push_custom_notification($m->ID,'Leave Request',$request_msg,'leave',$id);
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
                if(!$ar||!in_array($ar->status,['WAITING_FOR_LEVEL_1','WAITING_FOR_LEVEL_2'],true))$this->leave_redirect(['leave_done'=>1]);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

            $r=$this->leave_request_row($id);
            if(!$r||$r->status!=='Pending')$this->leave_redirect(['leave_done'=>1]);
            $emp=$this->leave_active_employee($r->employee_id);
            if(!$emp)$this->leave_redirect(['leave_error'=>'employee']);
            $bal=$this->ensure_leave_balance($emp->id,$r->leave_type_id);
            if(!$bal)$this->leave_redirect(['leave_error'=>'balance']);
            $now=current_time('mysql');

            // Outcome: 'APPROVED', 'REJECTED' or (multi-level) still waiting for the next level.
            $outcome=$decision==='approve'?'APPROVED':'REJECTED';
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now){
                if($ar){
                    if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',false)))return 'approval';
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
                    if(!$ar||!in_array($ar->status,['WAITING_FOR_LEVEL_1','WAITING_FOR_LEVEL_2'],true))return 'approval';
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
                if(!$ar||!in_array($ar->status,['WAITING_FOR_LEVEL_1','WAITING_FOR_LEVEL_2'],true))$this->leave_redirect(['leave_done'=>1]);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

            $r=$this->leave_request_row($id,"AND r.status='Approved' AND r.cancellation_status='Pending'");
            if(!$r||!in_array($decision,['approve','reject'],true))wp_die('Invalid cancellation.');
            $emp=$this->leave_active_employee($r->employee_id);
            if(!$emp)wp_die('Employee not found.');
            $bal=$this->ensure_leave_balance($emp->id,$r->leave_type_id);
            if(!$bal)wp_die('Leave balance unavailable.');
            $now=current_time('mysql');

            $outcome=$decision==='approve'?'APPROVED':'REJECTED';
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now){
                if($ar){
                    if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',false)))return 'approval';
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
}
