<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\Hub;

/**
 * Face Reset requests: an employee with an enrolled face asks for it to be removed so they can
 * enroll again. Decided from the wp-admin "Face Reset Requests" page (assigned approvers and
 * employee managers) or the Requests Hub (administrators), directly or through the 'face_reset'
 * approval workflow. Requests live in the ews_face_reset_requests option, keyed by employee id.
 * Behaviour is pinned by tests/e2e_admin_requests.py.
 */
trait EWS_Face_Reset_Trait {

    /* ------------------------------------------------------------------ operations */

    private function face_reset_requests(){
        $requests=get_option('ews_face_reset_requests',[]);
        return is_array($requests)?$requests:[];
    }

    private function face_reset_requested($employee_id){
        $employee_id=absint($employee_id);
        if(!$employee_id)return false;
        $requests=$this->face_reset_requests();
        return !empty($requests[$employee_id]) && ($requests[$employee_id]['status']??'')==='pending';
    }

    private function face_reset_workflow_active(){
        $workflow=$this->approval_workflow('face_reset');
        return $workflow && (int)$workflow->active===1;
    }

    private function face_reset_employee($employee_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d",absint($employee_id)));
    }

    private function face_reset_notify($user_id,$title,$message,$type,$employee_id,$link){
        if(!$user_id)return;
        $this->notify_user((int)$user_id,$title,$message,$type,'face_reset',$employee_id);
        if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$user_id,$title,$message,'face_reset',$employee_id,$link);
    }

    private function face_reset_admin_link(){
        return add_query_arg(['page'=>'ews31-face-reset-requests'],admin_url('admin.php'));
    }

    private function face_request_reset($employee_id,$user_id=0){
        $employee_id=absint($employee_id);
        $user_id=absint($user_id);
        if(!$employee_id || !$this->face_template_for_employee($employee_id)) return false;
        $requests=$this->face_reset_requests();
        if(!empty($requests[$employee_id]) && ($requests[$employee_id]['status']??'')==='pending') return true;
        $requests[$employee_id]=['status'=>'pending','requested_by'=>$user_id,'requested_at'=>current_time('mysql')];
        if(!update_option('ews_face_reset_requests',$requests,false))return false;
        global $wpdb;
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
        if(!$emp)return true;
        $msg=$emp->name.' requested a Face Reset. Approval is required.';
        if($this->face_reset_workflow_active()){
            $this->face_reset_clear_finished_approval($employee_id);
            $approval=$this->approval_start('face_reset','face_reset_request',$employee_id,$employee_id,[],true);
            if(is_wp_error($approval))return false;
            $ar=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
            // A workflow without steps approves immediately.
            if($ar && strtoupper((string)$ar->status)==='APPROVED')return $this->face_reset_finish($employee_id,true,'by approval workflow');
            $step=$ar?$this->approval_current_step((int)$ar->id):null;
            if($step && !empty($step->approver_wp_user_id))$this->face_reset_notify((int)$step->approver_wp_user_id,'Face Reset Request',$msg,'face_reset',$employee_id,$this->face_reset_admin_link());
            return true;
        }
        foreach(get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]) as $m){
            if((int)$m->ID===$user_id)continue;
            $this->face_reset_notify((int)$m->ID,'Face Reset Request',$msg,'face_reset',$employee_id,$this->face_reset_admin_link());
        }
        return true;
    }

    /**
     * Approvals are unique per (entity, workflow) and a reset is keyed by employee, so the
     * finished approval of an earlier reset is removed before a new one starts. Its outcome stays
     * in the audit log and in the request history.
     */
    private function face_reset_clear_finished_approval($employee_id){
        $ar=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
        if(!$ar || Hub::isOpenApproval((string)$ar->status))return;
        global $wpdb;$t=$this->approval_tables();
        $wpdb->delete($t['instances'],['approval_request_id'=>(int)$ar->id],['%d']);
        $wpdb->delete($t['requests'],['id'=>(int)$ar->id],['%d']);
    }

    /**
     * Completes a reset request: removes the face (when approved), closes the request, audits it
     * and tells the employee. Returns false only when the face could not be removed.
     */
    private function face_reset_finish($employee_id,$approved,$how){
        if($approved && !$this->face_delete_template($employee_id))return false;
        $requests=$this->face_reset_requests();
        $requests[$employee_id]=array_merge((array)($requests[$employee_id]??[]),['status'=>$approved?'approved':'rejected','handled_by'=>get_current_user_id(),'handled_at'=>current_time('mysql')]);
        update_option('ews_face_reset_requests',$requests,false);
        $this->audit($approved?'face_reset_approved':'face_reset_rejected','employee',$employee_id,'Face reset '.($approved?'approved':'rejected').' '.$how.'.');
        $emp=$this->face_reset_employee($employee_id);
        if($emp && !empty($emp->wp_user_id)){
            $this->face_reset_notify((int)$emp->wp_user_id,
                $approved?'Face Reset Approved':'Face Reset Rejected',
                $approved?'Your Face Reset request has been approved. You can now enroll your new face.':'Your Face Reset request has been rejected. Your current face enrollment remains active.',
                $approved?'success':'warning',$employee_id,add_query_arg('ews_view','time',home_url('/')));
        }
        return true;
    }

    /**
     * One decision on a pending reset. $as_admin: the Requests Hub (administrator override of the
     * current approval level, or a direct decision when no approval is open). Otherwise the Face
     * Reset page: with the workflow on, only the assigned approver of the current level may act.
     * Returns a Hub outcome: approved, rejected, advanced or error.
     */
    private function face_reset_decide($employee_id,$decision,$as_admin){
        $employee_id=absint($employee_id);
        if(!$employee_id || !Hub::isDecision($decision) || !$this->face_reset_requested($employee_id))return Hub::ERROR;
        $ar=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
        if($ar && Hub::isOpenApproval((string)$ar->status)){
            if(!$as_admin && !$this->approval_can_act((int)$ar->id))return Hub::ERROR;
            if(is_wp_error($this->approval_act((int)$ar->id,$decision,'',true,$as_admin)))return Hub::ERROR;
            $fresh=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
            $outcome=Hub::workflowOutcome($fresh?(string)$fresh->status:null);
            if($outcome===Hub::ADVANCED){
                $next=$fresh?$this->approval_current_step((int)$fresh->id):null;
                if($next && !empty($next->approver_wp_user_id)){
                    $emp=$this->face_reset_employee($employee_id);
                    $msg=$emp?$emp->name.' Face Reset request is waiting for your approval.':'Face Reset request is waiting for your approval.';
                    $this->face_reset_notify((int)$next->approver_wp_user_id,'Face Reset Approval Required',$msg,'face_reset',$employee_id,$this->face_reset_admin_link());
                }
                return Hub::ADVANCED;
            }
            return $this->face_reset_finish($employee_id,$outcome===Hub::APPROVED,'by approval workflow')?$outcome:Hub::ERROR;
        }
        if(!$as_admin && $this->face_reset_workflow_active())return Hub::ERROR;
        return $this->face_reset_finish($employee_id,$decision==='approve',$as_admin?'from the Requests Hub':'by manager')?Hub::outcome($decision):Hub::ERROR;
    }

    /* ------------------------------------------------------------------ wp-admin page */

    public function admin_face_reset_requests(){
        if(!$this->can('ews_manage_employees')) wp_die('Access denied');
        $workflow_active=$this->face_reset_workflow_active();
        $pending=[];$history=[];
        foreach($this->face_reset_requests() as $employee_id=>$r){
            if(!is_array($r))continue;
            $employee_id=absint($employee_id);
            $emp=$employee_id?$this->face_reset_employee($employee_id):null;
            $name=$emp?$emp->name:('Employee #'.$employee_id);
            if(($r['status']??'')==='pending'){
                if($workflow_active){
                    // Approvers only see what is waiting for them.
                    $ar=$this->approval_find_request('face_reset','face_reset_request',$employee_id);
                    $step=$ar?$this->approval_current_step((int)$ar->id):null;
                    if(!$step || (int)$step->approver_wp_user_id!==(int)get_current_user_id())continue;
                }
                $by_id=absint($r['requested_by']??0);$by=$by_id?get_userdata($by_id):false;
                $pending[]=[
                    'employee_id'=>$employee_id,'name'=>$name,'domain'=>$emp?$emp->domain_name:'Employee not found',
                    'requested_by'=>$by?$by->display_name:($by_id?'User #'.$by_id:'Employee'),
                    'requested_at'=>(string)($r['requested_at']??''),
                    'enrolled'=>$this->face_template_for_employee($employee_id)!==false,
                ];
            }else{
                $uid=absint($r['handled_by']??0);$u=$uid?get_userdata($uid):false;
                $history[]=[
                    'name'=>$name,'status'=>ucfirst(sanitize_key($r['status']??'')),
                    'requested_at'=>(string)($r['requested_at']??''),'handled_at'=>(string)($r['handled_at']??''),
                    'handled_by'=>$u?$u->display_name:($uid?'User #'.$uid:'—'),
                ];
            }
        }
        usort($pending,function($a,$b){return strcmp($b['requested_at'],$a['requested_at']);});
        usort($history,function($a,$b){return strcmp($b['handled_at']?:$b['requested_at'],$a['handled_at']?:$a['requested_at']);});
        $notice_key=sanitize_key($_GET['face_reset_notice']??'');
        echo $this->render_template('admin/face-reset-requests',[
            'pending'=>$pending,'history'=>array_slice($history,0,50),
            'notice'=>Hub::faceResetNotice($notice_key),'notice_is_error'=>$notice_key===Hub::ERROR,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function face_reset_admin_approve(){ $this->face_reset_admin_handle('approve'); }

    public function face_reset_admin_reject(){ $this->face_reset_admin_handle('reject'); }

    private function face_reset_admin_handle($decision){
        if(!$this->can('ews_manage_employees')) wp_die('Access denied');
        $employee_id=absint($_POST['employee_id']??0);
        check_admin_referer('ews_face_reset_'.$decision.'_'.$employee_id);
        $outcome=$this->face_reset_decide($employee_id,$decision,false);
        wp_safe_redirect(add_query_arg('face_reset_notice',$outcome,$this->face_reset_admin_link()));
        exit;
    }
}
