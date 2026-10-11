<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\EarlyLeave\RequestRules as EarlyLeaveRules;
use WorkforceOne\Overtime\RequestRules as OvertimeRules;
use WorkforceOne\Requests\Hub;

/**
 * Overtime requests and Early Leave requests.
 *
 * Overtime has two modes like Leave: normal (ews_manage_time decides) and the approval
 * workflow ('overtime' workflow active). Early Leave is always decided by ews_manage_time.
 * Validation rules live in src/Overtime and src/EarlyLeave (pure, unit tested).
 * Behaviour is pinned by tests/e2e_overtime.py.
 */
trait EWS_Overtime_Trait {

    /* ------------------------------------------------------------------ overtime helpers */

    private function overtime_enabled(){
            return (bool)$this->option('ews_feature_overtime');
        }

    private function overtime_request_minutes($start,$end){
            return OvertimeRules::minutes((string)$start,(string)$end);
        }

    private function overtime_date_is_future($date){
            return OvertimeRules::isAllowedDate((string)$date,current_time('Y-m-d'));
        }

    private function overtime_overlap($employee_id,$date,$start,$end){
            global $wpdb;
            return (bool)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ews_overtime_requests WHERE employee_id=%d AND overtime_date=%s AND status IN ('Pending','Approved') AND start_time<%s AND end_time>%s LIMIT 1",
                $employee_id,$date,OvertimeRules::withSeconds((string)$end),OvertimeRules::withSeconds((string)$start)
            ));
        }

    private function overtime_redirect($args=[]){
            $url=wp_get_referer();if(!$url||strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            $url=remove_query_arg(['overtime_sent','overtime_error','leave_sent','leave_error','early_error','break_success','break_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','overtime',$url);if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);exit;
        }

    private function overtime_workflow_mode(){
            $w=$this->approval_workflow('overtime');
            return ($w && (int)$w->active===1)?strtoupper((string)$w->approval_mode):'';
        }

    /** Pending -> Approved/Rejected. @return true|string overtime_error code */
    private function overtime_set_status($id,$status){
            global $wpdb;
            $ok=$wpdb->update($wpdb->prefix.'ews_overtime_requests',['status'=>$status,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql')],['id'=>(int)$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
            if($ok!==1)return $ok===false?'save':'done';
            return true;
        }

    private function overtime_notify($user_id,$title,$msg,$id){
            $this->notify((int)$user_id,'overtime',$title,$msg,['entity_id'=>$id]);
        }

    /** Tell the employee the final outcome and write the audit entry. */
    private function overtime_finalized($req,$emp,$approved){
            $id=(int)$req->id;
            $msg=$approved
                ?'Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.', '.OvertimeRules::durationText((int)$req->requested_minutes).') has been approved.'
                :'Your overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.') has been rejected.';
            $this->audit($approved?'overtime_approved':'overtime_rejected','overtime_request',$id,($approved?'Approved':'Rejected').' by '.wp_get_current_user()->display_name);
            if($emp->wp_user_id)$this->overtime_notify($emp->wp_user_id,$approved?'Overtime Approved':'Overtime Rejected',$msg,$id);
        }

    /* ------------------------------------------------------------------ overtime handlers */

    public function overtime_request_create(){
            if(!$this->overtime_enabled())$this->overtime_redirect(['overtime_error'=>'disabled']);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_overtime_request_create');
            $emp=$this->current_employee();if(!$emp)$this->overtime_redirect(['overtime_error'=>'employee']);
            $date=sanitize_text_field(wp_unslash($_POST['overtime_date']??''));
            $start=sanitize_text_field(wp_unslash($_POST['start_time']??''));
            $end=sanitize_text_field(wp_unslash($_POST['end_time']??''));
            $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
            $facts=['date'=>$date,'today'=>current_time('Y-m-d'),'start'=>$start,'end'=>$end,'reason'=>$reason];
            $error=OvertimeRules::check($facts);
            if(!$error){
                $this->ensure_overtime_schema();
                $facts['overlaps']=$this->overtime_overlap((int)$emp->id,$date,$start,$end);
                $error=OvertimeRules::check($facts);
            }
            if($error)$this->overtime_redirect(['overtime_error'=>$error]);

            global $wpdb;$table=$wpdb->prefix.'ews_overtime_requests';$now=current_time('mysql');
            $minutes=OvertimeRules::minutes($start,$end);$hours=OvertimeRules::hours($minutes);
            $row=['employee_id'=>(int)$emp->id,'overtime_date'=>$date,'start_time'=>$start,'end_time'=>$end,'requested_minutes'=>$minutes,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'requested_at'=>$now];
            $formats=['%d','%s','%s','%s','%d','%s','%s','%d','%s'];

            // An active workflow in mode NONE auto-approves through the approval engine.
            if(in_array($this->overtime_workflow_mode(),['NONE','LEVEL_1','LEVEL_2'],true)){
                $fail=function($code)use($wpdb){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>$code]);};
                if($wpdb->query('START TRANSACTION')===false)$this->overtime_redirect(['overtime_error'=>'save']);
                if($wpdb->insert($table,$row,$formats)===false)$fail('save');
                $id=(int)$wpdb->insert_id;
                if(is_wp_error($this->approval_start('overtime','overtime_request',$id,(int)$emp->id,[],false)))$fail('approval');
                $ar=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$ar)$fail('approval');
                if($ar->status==='APPROVED'){$ok=$this->overtime_set_status($id,'Approved');if($ok!==true)$fail($ok);}
                if($wpdb->query('COMMIT')===false)$fail('save');
                $this->audit('overtime_request','overtime_request',$id,$emp->name.' requested '.$hours.' overtime hour(s) on '.$date);
                if($ar->status==='APPROVED'){
                    if($emp->wp_user_id)$this->overtime_notify($emp->wp_user_id,'Overtime Approved','Your overtime request for '.$date.' ('.$start.'–'.$end.') has been approved.',$id);
                }else{
                    $step=$this->approval_current_step((int)$ar->id);
                    if($step&&$step->approver_wp_user_id)$this->overtime_notify($step->approver_wp_user_id,'Overtime Approval Required',$emp->name.' requested '.$hours.' hour(s) of overtime on '.$date.' ('.$start.'–'.$end.'). It is awaiting your Level '.(int)$step->step_order.' approval.',$id);
                }
                $this->overtime_redirect(['overtime_sent'=>$hours]);
            }

            if($wpdb->insert($table,$row,$formats)===false)wp_die('Could not submit overtime request. '.esc_html($wpdb->last_error));
            $id=(int)$wpdb->insert_id;
            $this->audit('overtime_request','overtime_request',$id,$emp->name.' requested '.$hours.' overtime hour(s) on '.$date);
            $msg=$emp->name.' requested '.$hours.' hour(s) of overtime on '.$date.' ('.$start.'–'.$end.'). Approval is required.';
            foreach(get_users(['capability'=>'ews_manage_time','fields'=>['ID']]) as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $this->overtime_notify($m->ID,'Overtime Request',$msg,$id);
            }
            $this->overtime_redirect(['overtime_sent'=>$hours]);
        }

    public function overtime_request_respond(){
            if(!$this->overtime_enabled())$this->overtime_redirect(['overtime_error'=>'disabled']);
            check_admin_referer('ews_overtime_respond');
            $id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
            if(!$id||!in_array($decision,['approve','reject'],true))$this->overtime_redirect(['overtime_error'=>'invalid']);
            $this->ensure_overtime_schema();global $wpdb;
            $req=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_overtime_requests WHERE id=%d LIMIT 1",$id));
            if(!$req)$this->overtime_redirect(['overtime_error'=>'not_found']);
            if($req->status!=='Pending')$this->overtime_redirect(['overtime_done'=>1]);
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",(int)$req->employee_id));
            if(!$emp)$this->overtime_redirect(['overtime_error'=>'employee']);

            if(in_array($this->overtime_workflow_mode(),['LEVEL_1','LEVEL_2'],true)){
                $ar=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$ar)$this->overtime_redirect(['overtime_error'=>'approval']);
                if(!$this->approval_can_act((int)$ar->id))$this->overtime_redirect(['overtime_error'=>'access']);
                $fail=function($code)use($wpdb){$wpdb->query('ROLLBACK');$this->overtime_redirect(['overtime_error'=>$code]);};
                if($wpdb->query('START TRANSACTION')===false)$this->overtime_redirect(['overtime_error'=>'save']);
                if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',false)))$fail('approval');
                $fresh=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$fresh)$fail('approval');
                if($fresh->status==='APPROVED'||$fresh->status==='REJECTED'){
                    $ok=$this->overtime_set_status($id,$fresh->status==='APPROVED'?'Approved':'Rejected');
                    if($ok!==true)$fail($ok);
                }
                if($wpdb->query('COMMIT')===false)$fail('save');
                if($fresh->status==='APPROVED'||$fresh->status==='REJECTED'){
                    $this->overtime_finalized($req,$emp,$fresh->status==='APPROVED');
                    $this->overtime_redirect($fresh->status==='APPROVED'?['overtime_done'=>1]:['overtime_rejected'=>1]);
                }
                $next=$this->approval_current_step((int)$fresh->id);
                if($next&&$next->approver_wp_user_id)$this->overtime_notify($next->approver_wp_user_id,'Overtime Approval Required',$emp->name.' overtime request for '.$req->overtime_date.' ('.$req->start_time.'–'.$req->end_time.') is awaiting your Level '.(int)$next->step_order.' approval.',$id);
                $this->overtime_redirect(['overtime_done'=>1]);
            }

            if(!$this->can('ews_manage_time'))$this->overtime_redirect(['overtime_error'=>'access']);
            $approved=$decision==='approve';
            $ok=$this->overtime_set_status($id,$approved?'Approved':'Rejected');
            if($ok!==true)$this->overtime_redirect(['overtime_error'=>$ok]);
            $this->overtime_finalized($req,$emp,$approved);
            $this->overtime_redirect($approved?['overtime_done'=>1]:['overtime_rejected'=>1]);
        }

    /* ------------------------------------------------------------------ early leave */

    private function early_leave_month_usage($employee_id,$month){
            global $wpdb;$this->ensure_leave_schema();
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(leave_minutes),0) FROM {$wpdb->prefix}ews_early_leave_requests WHERE employee_id=%d AND status IN ('Pending','Approved') AND DATE_FORMAT(work_date,'%%Y-%%m')=%s",$employee_id,$month));
        }

    /** Approval re-checks the schedule: with "Office only" the day must still be Office. */
    private function early_leave_can_approve($r){
            $sch=$this->schedule_for_employee_date((int)$r->employee_id,$r->work_date);
            return EarlyLeaveRules::scheduleAllows((bool)(int)$this->option('ews_early_leave_office_only'),$sch?$sch->status:null);
        }

    /** Pending -> Approved/Rejected. @return int|false rows changed */
    private function early_leave_set_status($id,$status){
            global $wpdb;
            return $wpdb->update($wpdb->prefix.'ews_early_leave_requests',['status'=>$status,'reviewed_by'=>get_current_user_id(),'reviewed_at'=>current_time('mysql')],['id'=>(int)$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
        }

    /** In-app notification to the employee (+ push unless $push is false, as on the admin Requests page). */
    private function early_leave_notify_employee($r,$decision,$push=true){
            global $wpdb;
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",(int)$r->employee_id));
            if(!$emp||!$emp->wp_user_id)return;
            $title='Early Leave '.ucfirst($decision);
            $msg='Your Early Leave request for '.$r->work_date.' has been '.($decision==='approve'?'approved':'rejected').'.';
            $this->notify((int)$emp->wp_user_id,'early_leave',$title,$msg,['entity_id'=>(int)$r->id,'push'=>$push]);
        }

    public function early_leave_create(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_early_leave_create');
            $emp=$this->current_employee();if(!$emp)$this->leave_redirect(['leave_error'=>'employee']);
            global $wpdb;$this->ensure_leave_schema();
            $date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
            $minutes=max(1,absint($_POST['leave_minutes']??0));
            $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
            $scheduled=$this->schedule_for_employee_date((int)$emp->id,$date);
            $facts=[
                'date'=>$date,'today'=>current_time('Y-m-d'),'working_day'=>$this->is_working_day($date),
                'office_only'=>(bool)(int)$this->option('ews_early_leave_office_only'),'schedule_status'=>$scheduled?$scheduled->status:null,
                'minutes'=>$minutes,'max_minutes'=>(int)$this->option('ews_early_leave_max_minutes'),'monthly_minutes'=>(int)$this->option('ews_early_leave_monthly_minutes'),
            ];
            $error=EarlyLeaveRules::check($facts);
            if(!$error){
                $facts['used_this_month']=$this->early_leave_month_usage($emp->id,substr($date,0,7));
                $error=EarlyLeaveRules::check($facts);
            }
            if($error)$this->leave_redirect(['early_error'=>$error]);
            $ok=$wpdb->insert($wpdb->prefix.'ews_early_leave_requests',['employee_id'=>$emp->id,'work_date'=>$date,'leave_minutes'=>$minutes,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id()],['%d','%s','%d','%s','%s','%d']);
            if($ok===false)$this->leave_redirect(['early_error'=>'save']);
            $id=$wpdb->insert_id;
            $msg=$emp->name.' requested Early Leave of '.floor($minutes/60).'h '.($minutes%60).'m on '.$date.'.';
            foreach(get_users(['capability'=>'ews_manage_time','fields'=>['ID']]) as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $this->notify((int)$m->ID,'early_leave','Early Leave Request',$msg,['entity_id'=>$id]);
            }
            $this->leave_redirect(['leave_sent'=>1]);
        }

    public function early_leave_respond(){
            if(!$this->can('ews_manage_time'))wp_die('Access denied');
            check_admin_referer('ews_early_leave_respond');
            global $wpdb;$this->ensure_leave_schema();
            $id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
            if(!in_array($decision,['approve','reject'],true))wp_die('Invalid decision.');
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_early_leave_requests WHERE id=%d AND status='Pending'",$id));
            if(!$r)wp_die('Early Leave request not found.');
            // Validate the schedule before changing state, so a conflict never leaves it Approved.
            // The schedule itself stays Office: Early Leave is an attendance exception, not a schedule type.
            if($decision==='approve'&&!$this->early_leave_can_approve($r))$this->leave_redirect(['early_error'=>'approval_conflict']);
            $wpdb->query('START TRANSACTION');
            $updated=$this->early_leave_set_status($id,$decision==='approve'?'Approved':'Rejected');
            if($updated!==1){$wpdb->query('ROLLBACK');$this->leave_redirect(['early_error'=>$updated===false?'save':'approval_conflict']);}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->leave_redirect(['early_error'=>'save']);}
            $this->early_leave_notify_employee($r,$decision);
            $this->leave_redirect(['leave_done'=>1]);
        }

    /* ------------------------------------------------------------------ wp-admin Requests Hub */

    /** Administrator decision on an overtime request. Returns a Hub outcome. */
    private function overtime_admin_decide($id,$decision){
            global $wpdb;
            $req=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_overtime_requests WHERE id=%d AND status='Pending' LIMIT 1",$id));
            if(!$req)return Hub::ERROR;
            $ar=$this->approval_find_request('overtime','overtime_request',$id);
            if($ar && Hub::isOpenApproval((string)$ar->status)){
                if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',true,true)))return Hub::ERROR;
                $fresh=$this->approval_find_request('overtime','overtime_request',$id);
                if(!$fresh)return Hub::ERROR;
                $outcome=Hub::workflowOutcome((string)$fresh->status);
                if($outcome!==Hub::ADVANCED)return $this->overtime_set_status($id,$outcome===Hub::APPROVED?'Approved':'Rejected')===true?$outcome:Hub::ERROR;
                $next=$this->approval_current_step((int)$fresh->id);
                if($next&&$next->approver_wp_user_id){
                    $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",(int)$req->employee_id));
                    $this->notify((int)$next->approver_wp_user_id,'overtime','Overtime Approval Required',($emp?$emp->name:'An employee').' overtime request is waiting for your approval.',['entity_id'=>$id]);
                }
                return Hub::ADVANCED;
            }
            return $this->overtime_set_status($id,$decision==='approve'?'Approved':'Rejected')===true?Hub::outcome($decision):Hub::ERROR;
        }

    /** Administrator decision on an early leave request. Returns a Hub outcome. */
    private function early_leave_admin_decide($id,$decision){
            global $wpdb;
            $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_early_leave_requests WHERE id=%d AND status='Pending' LIMIT 1",$id));
            if(!$r)return Hub::ERROR;
            if($decision==='approve'&&!$this->early_leave_can_approve($r))return Hub::ERROR;
            if($this->early_leave_set_status($id,$decision==='approve'?'Approved':'Rejected')!==1)return Hub::ERROR;
            $this->early_leave_notify_employee($r,$decision,false);
            return Hub::outcome($decision);
        }
}
