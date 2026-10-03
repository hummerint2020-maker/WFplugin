<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\SwapRules;

/**
 * Schedule Swap requests: an employee offers to exchange an Office/WFH day with a colleague
 * in the same department; the colleague accepts or rejects, the requester may cancel, and an
 * administrator can decide from the wp-admin Requests page.
 * Validation rules live in src/Schedule/SwapRules.php (pure, unit tested).
 * Behaviour is pinned by tests/e2e_swap.py.
 */
trait EWS_Swap_Trait {

    /* ------------------------------------------------------------------ helpers */

    private function schedule_for_employee_date($eid,$date){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$eid,$date));
        }

    private function swap_employee_user_id($employee_id){
            global $wpdb;
            return (int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",(int)$employee_id));
        }

    private function swap_redirect($args=[]){
            $url=wp_get_referer();
            if(!$url || strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            $url=remove_query_arg(['action','swap_error','swap_sent','swap_done','leave_error','leave_sent','leave_done','leave_cancel_sent','early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','vacation_sent','vacation_error','vacation_done','vacation_rejected','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','schedule',$url);
            if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url);
            exit;
        }

    /** A Pending swap by id, optionally narrowed (e.g. "AND target_employee_id=%d"). */
    private function swap_pending_row($id,$extra_where='',...$extra_args){
            global $wpdb;$table=$this->ensure_swap_schema();
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Pending' {$extra_where} LIMIT 1",$id,...$extra_args));
        }

    /** Moves a Pending swap to $status. Returns 1 on success, 0 if it was no longer Pending, false on a DB error. */
    private function swap_close($id,$status){
            global $wpdb;$table=$this->ensure_swap_schema();
            return $wpdb->update($table,['status'=>$status,'responded_by'=>get_current_user_id(),'responded_at'=>current_time('mysql')],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s']);
        }

    /**
     * Exchanges the two schedule days and marks the swap Accepted, atomically.
     * Returns true, or a ?swap_error= code: 'changed' (a schedule was edited since the request),
     * 'expired' (no longer Pending) or 'save'.
     */
    private function swap_apply($r){
            global $wpdb;$table=$this->ensure_swap_schema();
            $a=$this->schedule_for_employee_date($r->requester_employee_id,$r->work_date);
            $b=$this->schedule_for_employee_date($r->target_employee_id,$r->work_date);
            $error=SwapRules::checkStillCurrent((string)$r->requester_status,(string)$r->target_status,$a?$a->status:null,$b?$b->status:null);
            if($error)return $error;
            $now=current_time('mysql');$uid=get_current_user_id();
            if($wpdb->query('START TRANSACTION')===false)return 'save';
            $ok1=$wpdb->update($this->schedule,['status'=>$r->target_status,'updated_by'=>$uid,'updated_at'=>$now],['id'=>$a->id],['%s','%d','%s'],['%d']);
            $ok2=$wpdb->update($this->schedule,['status'=>$r->requester_status,'updated_by'=>$uid,'updated_at'=>$now],['id'=>$b->id],['%s','%d','%s'],['%d']);
            if($ok1===false||$ok2===false){$wpdb->query('ROLLBACK');return 'save';}
            $updated=$this->swap_close((int)$r->id,'Accepted');
            if($updated!==1){$wpdb->query('ROLLBACK');return $updated===false?'save':'expired';}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
            return true;
        }

    /** In-app notification plus push (when enabled) for one swap participant. */
    private function swap_notify($user_id,$title,$message,$type,$swap_id,$push=true){
            if(!$user_id)return;
            $this->notify($user_id,'swap',$title,$message,['type'=>$type,'entity_id'=>$swap_id,'push'=>$push]);
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
            $target_emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$target));
            if(!$target_emp || !$this->department_scope_allows_employee($target))$this->swap_redirect(['swap_error'=>'employee']);
            $a=$this->schedule_for_employee_date($requester->id,$date);
            $b=$this->schedule_for_employee_date($target,$date);
            $error=SwapRules::checkSwappable($a?$a->status:null,$b?$b->status:null);
            if($error)$this->swap_redirect(['swap_error'=>$error]);
            $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE work_date=%s AND status='Pending' AND ((requester_employee_id=%d AND target_employee_id=%d) OR (requester_employee_id=%d AND target_employee_id=%d))",$date,$requester->id,$target,$target,$requester->id));
            if($exists)$this->swap_redirect(['swap_error'=>'pending']);
            $ok=$wpdb->insert($table,[
                'requester_employee_id'=>(int)$requester->id,'target_employee_id'=>$target,'work_date'=>$date,
                'requester_status'=>$a->status,'target_status'=>$b->status,'status'=>'Pending',
                'requested_by'=>get_current_user_id(),'created_at'=>current_time('mysql')
            ],['%d','%d','%s','%s','%s','%s','%d','%s']);
            if(!$ok)$this->swap_redirect(['swap_error'=>'save']);
            $id=(int)$wpdb->insert_id;
            $this->audit('SCHEDULE_SWAP_REQUESTED','schedule_swap',$id,sprintf('%s requested a swap with %s for %s: %s -> %s; %s -> %s',$requester->name,$target_emp->name,date_i18n('l, d M Y',strtotime($date)),$requester->name,$b->status,$target_emp->name,$a->status));
            $msg=sprintf('%s requested to swap %s: %s ↔ %s.', $requester->name,date_i18n('l, d M',strtotime($date)),$a->status,$b->status);
            $this->swap_notify($this->swap_employee_user_id($target),'Schedule Swap Request',$msg,'info',$id);
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
                $this->swap_notify($req_uid,'Schedule Swap Rejected',sprintf('%s rejected your schedule swap for %s.', $emp->name,date_i18n('l, d M',strtotime($r->work_date))),'warning',$id);
                $this->swap_redirect(['swap_done'=>'rejected']);
            }
            $past=SwapRules::checkNotPast((string)$r->work_date,current_time('Y-m-d'));
            if($past)$this->swap_redirect(['swap_error'=>$past]);
            $applied=$this->swap_apply($r);
            if($applied!==true)$this->swap_redirect(['swap_error'=>$applied]);
            $this->achievement_evaluate_swap_acceptance((int)$r->target_employee_id,(int)$r->requester_employee_id,(int)$id);
            $this->audit('SCHEDULE_SWAP_ACCEPTED','schedule_swap',$id,sprintf('Swap accepted by %s for %s: requester %s %s -> %s; target %s %s -> %s', $emp->name,date_i18n('l, d M Y',strtotime($r->work_date)),$r->requester_employee_id,$r->requester_status,$r->target_status,$r->target_employee_id,$r->target_status,$r->requester_status));
            $this->swap_notify($req_uid,'Schedule Swap Completed',sprintf('Your schedule swap for %s with %s is complete: %s ↔ %s.',date_i18n('l, d M',strtotime($r->work_date)),$emp->name,$r->requester_status,$r->target_status),'success',$id);
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
     * Decision taken from the wp-admin Requests page. Returns true or an error code; the caller
     * redirects. Unlike a peer acceptance it does not count towards collaboration achievements.
     */
    private function swap_admin_decide($id,$decision){
            $r=$this->swap_pending_row($id);
            if(!$r)return 'not_found';
            $approve=$decision==='approve';
            if($approve){
                $applied=$this->swap_apply($r);
                if($applied!==true)return $applied;
            }elseif($this->swap_close($id,'Rejected')!==1)return 'save';
            $actor=wp_get_current_user()->display_name;
            $this->swap_audit($approve?'SCHEDULE_SWAP_ACCEPTED':'SCHEDULE_SWAP_REJECTED',$r,($approve?'Swap approved by administrator ':'Swap rejected by administrator ').$actor);
            $title=$approve?'Schedule Swap Completed':'Schedule Swap Rejected';
            $type=$approve?'success':'warning';
            $this->swap_notify($this->swap_employee_user_id($r->requester_employee_id),$title,$approve?'Your schedule swap was completed by an administrator.':'Your schedule swap request was rejected by an administrator.',$type,$id,false);
            $this->swap_notify($this->swap_employee_user_id($r->target_employee_id),$title,$approve?'Your schedule has been updated after an administrator approved a swap.':'A schedule swap offered to you was rejected by an administrator.',$type,$id,false);
            return true;
        }

    /** Pending swaps for the wp-admin Requests page. */
    private function swap_pending_rows(){
            global $wpdb;$table=$this->ensure_swap_schema();
            return (array)$wpdb->get_results("SELECT * FROM {$table} WHERE status='Pending' ORDER BY created_at ASC");
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
}
