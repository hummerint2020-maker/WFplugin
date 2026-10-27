<?php
if (!defined('ABSPATH')) exit;

/**
 * Approval deadlines (3.31.100, owner decisions 2026-10-14, docs/tasks/approvals.md): when nobody
 * decides a level, its approver is reminded after so many working hours, and the request moves up
 * after more: to the approver's own manager, or to the administrators when they have none. The first
 * approver is told; the step keeps its whole history (escalations, escalated_from_wp_user_id, the
 * Audit Log). Each workflow sets its own hours (wp-admin → Approval Workflows); 0 = never, the
 * default, so nothing changes until a company sets them. Runs hourly (ews_approvals_tick).
 * Since 3.31.128 the work is in WorkforceOne\Approvals\DeadlineService; this trait keeps the cron's entry point and the words.
 */
trait EWS_Approval_Deadlines_Trait {

    /** Remind and move up the steps past their time. @return array{reminded:int,moved:int} */
    public function approval_deadlines_tick($now=null){
        $done=['reminded'=>0,'moved'=>0];
        $keys=[];
        foreach(array_keys(\WorkforceOne\Approvals\Workflows::IN_USE) as $key){$d=$this->approval_deadlines($key);if($d['remind']||$d['escalate'])$keys[$key]=$d;}
        if(!$keys)return $done;
        $this->ensure_approval_schema();
        return $this->services()->approvalDeadlines()->tick($keys,is_string($now)?$now:null);
    }

    /** The approver is reminded that a request has waited (the deadline's "remind" hours). */
    private function approval_tell_reminded($step,$remind_hours){
        $this->notify((int)$step->approver_wp_user_id,'system',__('A request waits for you','workforce-one'),
            sprintf(/* translators: 1: request kind, 2: employee name, 3: working hours */__('%1$s from %2$s has waited %3$s working hours for your decision.','workforce-one'),$this->approval_kind_label((string)$step->entity_type),$this->people_name((int)$step->requester_employee_id),number_format_i18n((float)$remind_hours)),
            ['entity'=>'approval','entity_id'=>(int)$step->approval_request_id,'push'=>true]);
    }

    /** A level moved up: the first approver is told, then the new one, or the administrators. */
    private function approval_tell_moved_up($from_uid,$step,$up,$who){
        $kind=$this->approval_kind_label((string)$step->entity_type);
        $to=$up?$up->name:__('the administrators','workforce-one');
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
    }

    /** What a request is, for messages. */
    private function approval_kind_label($entity_type){
        $labels=['leave'=>__('A leave request','workforce-one'),'leave_cancellation'=>__('A leave cancellation','workforce-one'),'overtime_request'=>__('An overtime request','workforce-one'),
            'attendance_correction'=>__('An attendance correction','workforce-one'),'schedule_swap'=>__('A shift swap','workforce-one'),'face_reset_request'=>__('A face reset request','workforce-one'),'salary_advance'=>__('A salary advance','workforce-one')];
        return $labels[$entity_type]??__('A request','workforce-one');
    }
}
