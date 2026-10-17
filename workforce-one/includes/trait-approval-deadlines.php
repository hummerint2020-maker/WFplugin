<?php
if (!defined('ABSPATH')) exit;

/**
 * Approval deadlines (3.31.100, owner decisions 2026-10-14, docs/tasks/approvals.md): when nobody
 * decides a level, its approver is reminded after so many working hours, and the request moves up
 * after more: to the approver's own manager, or to the administrators when they have none. The first
 * approver is told; the step keeps its whole history (escalations, escalated_from_wp_user_id, the
 * Audit Log). Each workflow sets its own hours (wp-admin → Approval Workflows); 0 = never, the
 * default, so nothing changes until a company sets them. Runs hourly (ews_approvals_tick).
 */
trait EWS_Approval_Deadlines_Trait {

    /** Remind and move up the steps past their time. @return array{reminded:int,moved:int} */
    public function approval_deadlines_tick($now=null){
        $done=['reminded'=>0,'moved'=>0];
        $keys=[];
        foreach(array_keys(\WorkforceOne\Approvals\Workflows::IN_USE) as $key){$d=$this->approval_deadlines($key);if($d['remind']||$d['escalate'])$keys[$key]=$d;}
        if(!$keys)return $done;
        $this->ensure_approval_schema();
        global $wpdb;$t=$this->approval_tables();
        $now=is_string($now)&&$now!==''?$now:current_time('mysql');
        // Steps waiting since before 3.31.100 have no start: their wait counts from now.
        $wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET pending_since=%s WHERE status='PENDING' AND pending_since IS NULL",$now));
        $s=$this->approval_settings();$days=$this->working_days();
        foreach($keys as $key=>$d){
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT i.*,r.requester_employee_id,r.entity_type,r.entity_id FROM {$t['instances']} i JOIN {$t['requests']} r ON r.id=i.approval_request_id JOIN {$t['workflows']} w ON w.id=r.workflow_id
                WHERE w.workflow_key=%s AND i.status='PENDING' AND r.status NOT IN ('APPROVED','REJECTED','CANCELLED') ORDER BY i.pending_since ASC LIMIT 500",$key));
            foreach($rows as $step){
                $hours=\WorkforceOne\Approvals\WorkingHours::between((string)$step->pending_since,$now,$days,$s['day_start'],$s['day_end']);
                $with_admins=!(int)$step->approver_wp_user_id;
                if($d['escalate'] && $hours>=$d['escalate'] && !$with_admins){
                    if($this->approval_escalate($step,$key,$now))$done['moved']++;
                }elseif($d['remind'] && $hours>=$d['remind'] && !$step->reminded_at && !$with_admins){
                    $ok=$wpdb->update($t['instances'],['reminded_at'=>$now],['id'=>(int)$step->id,'status'=>'PENDING'],['%s'],['%d','%s']);
                    if($ok!==1)continue;
                    $this->notify((int)$step->approver_wp_user_id,'system',__('A request waits for you','workforce-one'),
                        sprintf(/* translators: 1: request kind, 2: employee name, 3: working hours */__('%1$s from %2$s has waited %3$s working hours for your decision.','workforce-one'),$this->approval_kind_label((string)$step->entity_type),$this->people_name((int)$step->requester_employee_id),number_format_i18n((float)$d['remind'])),
                        ['entity'=>'approval','entity_id'=>(int)$step->approval_request_id,'push'=>true]);
                    $done['reminded']++;
                }
            }
        }
        return $done;
    }

    /**
     * Move one waiting step up: to its approver's manager (not the requester), or to the administrators.
     * @return bool whether it moved
     */
    private function approval_escalate($step,$workflow_key,$now){
        global $wpdb;$t=$this->approval_tables();
        $from_uid=(int)$step->approver_wp_user_id;
        // A level with a substitute moves up from the manager it waits for (3.31.107), not from the substitute.
        $base=(int)($step->for_employee_id??0)?:(int)$step->approver_employee_id;
        $up=$base?$this->approval_related_employee($base,'supervisor'):null;
        $requester_uid=(int)$this->people_user_id((int)$step->requester_employee_id);
        if($up && ((int)$up->id===(int)$step->requester_employee_id || !(int)$up->wp_user_id || (int)$up->wp_user_id===$requester_uid || (int)$up->wp_user_id===$from_uid))$up=null;
        $set=($up?$wpdb->prepare('approver_employee_id=%d,approver_wp_user_id=%d,rerouted=0',(int)$up->id,(int)$up->wp_user_id):'approver_employee_id=NULL,approver_wp_user_id=NULL,rerouted=1').',for_employee_id=NULL,substitute_id=NULL';
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET {$set},pending_since=%s,reminded_at=NULL,escalations=escalations+1,escalated_from_wp_user_id=%d WHERE id=%d AND status='PENDING' AND approver_wp_user_id=%d",
            $now,$from_uid,(int)$step->id,$from_uid));
        if($ok!==1)return false;
        $kind=$this->approval_kind_label((string)$step->entity_type);$who=$this->people_name((int)$step->requester_employee_id);
        $to=$up?$up->name:__('the administrators','workforce-one');
        $this->audit('approval_escalated','approval_request',(int)$step->approval_request_id,$workflow_key.' · level '.(int)$step->step_order.' · user #'.$from_uid.' → '.($up?'employee #'.(int)$up->id:'administrators'));
        $this->notify($from_uid,'system',__('A request moved up','workforce-one'),
            sprintf(/* translators: 1: request kind, 2: employee name, 3: who decides it now */__('%1$s from %2$s was not decided in time and now waits for %3$s.','workforce-one'),$kind,$who,$to),
            ['entity'=>'approval','entity_id'=>(int)$step->approval_request_id]);
        if($up){
            $this->notify((int)$up->wp_user_id,'system',__('A request waits for you','workforce-one'),
                sprintf(/* translators: 1: request kind, 2: employee name */__('%1$s from %2$s was not decided in time by its approver and now waits for you.','workforce-one'),$kind,$who),
                ['entity'=>'approval','entity_id'=>(int)$step->approval_request_id,'push'=>true]);
        }else{
            $this->approval_notify_administrators((int)$step->approval_request_id,(int)$step->step_order);
        }
        return true;
    }

    /** What a request is, for messages. */
    private function approval_kind_label($entity_type){
        $labels=['leave'=>__('A leave request','workforce-one'),'leave_cancellation'=>__('A leave cancellation','workforce-one'),'overtime_request'=>__('An overtime request','workforce-one'),
            'attendance_correction'=>__('An attendance correction','workforce-one'),'schedule_swap'=>__('A shift swap','workforce-one'),'face_reset_request'=>__('A face reset request','workforce-one')];
        return $labels[$entity_type]??__('A request','workforce-one');
    }
}
