<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\Hub;

/**
 * wp-admin "Requests Hub": every pending request in one table, decided by an administrator.
 * Each decision calls the owning module's operation (leave, overtime, early leave, swap, face
 * reset), so it behaves exactly like a decision taken elsewhere.
 * Presentation rules: src/Requests/Hub.php. Behaviour: tests/e2e_admin_requests.py and the
 * "admin Requests page" checks of the module tests.
 */
trait EWS_Admin_Requests_Trait {

    /** @return array<int,array<string,mixed>> Pending requests, oldest first, ready for the template. */
    private function admin_request_rows(){
        global $wpdb;
        $rows=[];
        $names=[];
        $deferred=[];
        // $ar is the request's key [workflow, entity, id]: the approval requests are found together after the last row (3.31.112).
        // $stage: a correction's own stage (pending | pending_hr), posted with the decision like the level (3.31.122).
        $add=function($type,$id,$employee_id,$title,$requested_at,$detail,$ar=null,$stage='')use(&$rows,&$deferred){
            $key=$type.':'.(int)$id;
            if(isset($rows[$key]))return;
            $rows[$key]=true;
            $deferred[$key]=[$type,$id,$employee_id,$title,$requested_at,$detail,$ar,$stage];
        };

        $lt=$wpdb->prefix.'ews_leave_requests';$types=$wpdb->prefix.'ews_leave_types';
        foreach((array)$wpdb->get_results("SELECT r.*,lt.name type_name FROM {$lt} r LEFT JOIN {$types} lt ON lt.id=r.leave_type_id WHERE r.status='Pending' ORDER BY r.requested_at ASC") as $r){
            $add('leave',$r->id,$r->employee_id,'Vacation / '.$r->type_name,$r->requested_at,Hub::details($r->start_date.' → '.$r->end_date,(float)$r->requested_days.' day(s)',(string)$r->reason),['vacation','leave',(int)$r->id]);
        }
        foreach((array)$wpdb->get_results("SELECT r.*,lt.name type_name FROM {$lt} r LEFT JOIN {$types} lt ON lt.id=r.leave_type_id WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC") as $r){
            $add('leave_cancellation',$r->id,$r->employee_id,'Vacation Cancellation / '.$r->type_name,$r->requested_at,$r->start_date.' → '.$r->end_date,['vacation','leave_cancellation',(int)$r->id]);
        }
        $ot=$wpdb->prefix.'ews_overtime_requests';
        if($this->admin_requests_table_exists($ot)){
            foreach((array)$wpdb->get_results("SELECT * FROM {$ot} WHERE status='Pending' ORDER BY requested_at ASC") as $r){
                $add('overtime',$r->id,$r->employee_id,'Overtime',$r->requested_at,Hub::details($r->overtime_date,substr($r->start_time,0,5).' → '.substr($r->end_time,0,5),Hub::duration((int)$r->requested_minutes),(string)$r->reason),['overtime','overtime_request',(int)$r->id]);
            }
        }
        foreach((array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_early_leave_requests WHERE status='Pending' ORDER BY requested_at ASC") as $r){
            $add('early_leave',$r->id,$r->employee_id,'Early Leave',$r->requested_at,Hub::details($r->work_date,Hub::duration((int)$r->leave_minutes),(string)$r->reason));
        }
        foreach($this->swap_pending_rows() as $r){
            // Waiting for the approvers: the column shows the current level, the one an administrator's decision takes (3.31.120).
            $add('shift_swap',$r->id,$r->requester_employee_id,'Shift Swap',$r->created_at,Hub::details($r->work_date,$r->requester_status.' ↔ '.$r->target_status),$r->status==='Awaiting'?['shift_swap','schedule_swap',(int)$r->id]:null);
        }
        $legacy=$wpdb->prefix.'ews_vacation_requests';
        if($this->admin_requests_table_exists($legacy)){
            foreach((array)$wpdb->get_results("SELECT * FROM {$legacy} WHERE status='Pending' ORDER BY requested_at ASC") as $r){
                $add('legacy_vacation',$r->id,$r->employee_id,'Legacy Vacation',$r->requested_at??'',Hub::details($r->start_date.' → '.$r->end_date,(float)$r->requested_days.' day(s)',(string)($r->reason??'')));
            }
        }
        foreach($this->cx_hub_rows() as $r){
            $add('correction',$r->id,$r->employee_id,'Attendance Correction',$r->requested_at,Hub::details($r->work_date,$this->cx_type_label((string)$r->type),$this->cx_change_text($r,'en'),(string)$r->reason,$r->status==='pending_hr'?'Second level (HR)':''),['attendance_correction','attendance_correction',(int)$r->id],(string)$r->status);
        }
        foreach($this->face_reset_requests() as $employee_id=>$r){
            if(!is_array($r)||($r['status']??'')!=='pending')continue;
            $add('face_reset',$employee_id,$employee_id,'Face Reset',$r['requested_at']??'',$r['current_note']??'Reset requested',['face_reset','face_reset_request',(int)$employee_id]);
        }
        // The requests, their current levels and the names for the whole list at once (3.31.112).
        $found=$this->approval_find_requests(array_values(array_filter(array_column($deferred,6))));
        $current=$this->approval_current_steps(array_map(function($ar){return (int)$ar->id;},array_values($found)));
        $briefs=$this->people_brief_by_ids(array_column($deferred,2));
        $rows=[];
        foreach($deferred as $key=>[$type,$id,$employee_id,$title,$requested_at,$detail,$ref,$stage]){
            $employee_id=(int)$employee_id;
            if(!array_key_exists($employee_id,$names)){
                $name=$employee_id&&isset($briefs[$employee_id])?$briefs[$employee_id]->name:null;
                $names[$employee_id]=$name!==null?(string)$name:'Employee #'.$employee_id;
            }
            $ar=$ref?($found[$this->approval_request_key($ref[0],$ref[1],$ref[2])]??null):null;
            $step=$ar?($current[(int)$ar->id]??null):null;
            $rows[$key]=[
                'type'=>$type,'id'=>(int)$id,'title'=>$title,'employee'=>$names[$employee_id],'detail'=>$detail,
                'approval'=>Hub::approvalLabel($type,$ar?(int)$ar->id:0,$step?(int)$step->step_order:null,$step&&$step->approver_wp_user_id,$step&&!empty($step->rerouted)),
                'requested_at'=>(string)$requested_at,
                // The level this row shows (0: none open), so a decision never lands on a later one (3.31.122).
                'step'=>$step?(int)$step->id:0,'stage'=>(string)$stage,
                'nonce'=>wp_create_nonce('ews_admin_request_decision_'.$type.'_'.(int)$id),
            ];
        }
        return Hub::sortOldestFirst(array_values($rows));
    }

    private function admin_requests_table_exists($table){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))===$table;
    }

    public function admin_requests(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $notice_key=sanitize_key($_GET['request_notice']??'');
        echo $this->render_template('admin/requests',[
            'rows'=>$this->admin_request_rows(),
            'notice'=>Hub::hubNotice($notice_key),'notice_is_error'=>Hub::isErrorNotice($notice_key),
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function admin_requests_decision(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $type=sanitize_key($_POST['request_type']??'');$id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
        if(!$id||!Hub::isDecision($decision)||!in_array($type,Hub::TYPES,true))wp_die('Invalid request.');
        check_admin_referer('ews_admin_request_decision_'.$type.'_'.$id);
        switch($type){
            case 'leave':              $outcome=$this->leave_admin_decide($id,false,$decision);break;
            case 'leave_cancellation': $outcome=$this->leave_admin_decide($id,true,$decision);break;
            case 'overtime':           $outcome=$this->overtime_admin_decide($id,$decision);break;
            case 'early_leave':        $outcome=$this->early_leave_admin_decide($id,$decision);break;
            case 'shift_swap':         $outcome=$this->swap_admin_decide($id,$decision);break;
            case 'legacy_vacation':    $outcome=$this->legacy_vacation_admin_decide($id,$decision);break;
            case 'correction':         $outcome=$this->cx_hub_decide($id,$decision);break;
            default:                   $outcome=$this->face_reset_decide($id,$decision,true);
        }
        wp_safe_redirect(add_query_arg('request_notice',$outcome,admin_url('admin.php?page=ews31-requests')));
        exit;
    }

    /**
     * Requests from the pre-3.x vacation table (ews_vacation_requests), still decidable here.
     * Approval marks each working day as Vacation; all writes succeed or none do.
     */
    private function legacy_vacation_admin_decide($id,$decision){
        global $wpdb;
        $table=$wpdb->prefix.'ews_vacation_requests';
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Pending' LIMIT 1",$id));
        if(!$r)return Hub::ERROR;
        $emp=$this->people_employee((int)$r->employee_id);
        $approve=$decision==='approve';
        $now=current_time('mysql');$uid=get_current_user_id();
        $written=[];
        if($wpdb->query('START TRANSACTION')===false)return Hub::ERROR;
        $ok=$wpdb->update($table,['status'=>$approve?'Approved':'Rejected','reviewed_by'=>$uid,'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s'])===1;
        if($ok && $approve && $emp){
            $note='Approved legacy vacation request #'.$id;
            for($ts=strtotime($r->start_date);$ok && $ts<=strtotime($r->end_date);$ts=strtotime('+1 day',$ts)){
                $date=date('Y-m-d',$ts);
                if(!$this->is_working_day($date))continue;
                $existing=$this->schedule_for_employee_date((int)$emp->id,$date);$written[]=$date;
                $ok=($existing
                    ?$wpdb->update($this->schedule,['status'=>'Vacation','note'=>$note,'updated_by'=>$uid,'updated_at'=>$now],['id'=>$existing->id])
                    :$wpdb->insert($this->schedule,['employee_id'=>(int)$emp->id,'work_date'=>$date,'status'=>'Vacation','note'=>$note,'updated_by'=>$uid,'updated_at'=>$now],['%d','%s','%s','%s','%d','%s']))!==false;
            }
        }
        if($ok && $approve && $emp)$this->week_lock_record_request((int)$emp->id,$written,'leave','legacy vacation #'.(int)$id);   // week lock (3.31.121)
        if(!$ok || $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return Hub::ERROR;}
        if($emp && $emp->wp_user_id)$this->notify((int)$emp->wp_user_id,'vacation',$approve?'Vacation Approved':'Vacation Rejected',$approve?'Your vacation request has been approved.':'Your vacation request has been rejected.',['entity_id'=>$id,'push'=>false]);
        return Hub::outcome($decision);
    }
}
