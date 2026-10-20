<?php
/**
 * approval_path() exactly as it was in 3.31.111 (ebc679d), one request at a time, kept as the reference for
 * tests/e2e_query_batching.py: the batched approval_paths() (3.31.112) must give the same result. Bound to the plugin
 * object with Closure::bind, so $this is the manager. Test-only; never loaded by the plugin.
 */
return function($workflow_key,$entity_type,$entity_id,$now){
        $ar=$this->approval_find_request($workflow_key,$entity_type,$entity_id);
        if(!$ar)return null;
        global $wpdb;$t=$this->approval_tables();
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['instances']} WHERE approval_request_id=%d ORDER BY step_order ASC",(int)$ar->id));
        $s=$this->approval_settings();$days=$this->working_days();
        $levels=count(array_filter($rows,function($r){return $r->status!=='SKIPPED';}));
        $pos=0;$level=0;$hours=0.0;$moved='';$note='';$steps=[];
        foreach($rows as $r){
            $who=(int)$r->approver_wp_user_id?$this->approval_user_name((int)$r->approver_wp_user_id):(!empty($r->rerouted)?__('Administrators','workforce-one'):'');
            $by=$this->approval_user_name((int)($r->acted_by_wp_user_id??0));
            // Decided by a substitute while the approver is away (3.31.107): "Sami Ali for Mona Adel".
            $for=(int)($r->for_employee_id??0)?$this->people_name((int)$r->for_employee_id):'';
            if($for!==''){
                if($who!=='')$who=sprintf(/* translators: 1: substitute, 2: the manager away */__('%1$s for %2$s','workforce-one'),$who,$for);
                if($by!=='' && (int)$r->acted_by_wp_user_id!==(int)$this->people_user_id((int)$r->for_employee_id))$by=sprintf(/* translators: 1: substitute, 2: the manager away */__('%1$s for %2$s','workforce-one'),$by,$for);
            }
            $when=$r->acted_at?date_i18n('D j M, H:i',strtotime((string)$r->acted_at)):'';
            switch($r->status){
                case 'APPROVED': $state='done';$sub=sprintf(/* translators: 1: person, 2: date and time */__('%1$s approved · %2$s','workforce-one'),$by?:$who,$when);break;
                case 'REJECTED': $state='rejected';$sub=sprintf(/* translators: 1: person, 2: date and time */__('%1$s rejected · %2$s','workforce-one'),$by?:$who,$when);if((string)$r->comment!=='')$note=(string)$r->comment;break;
                case 'PENDING':
                    $state='now';$hours=$r->pending_since?\WorkforceOne\Approvals\WorkingHours::between((string)$r->pending_since,$now,$days,$s['day_start'],$s['day_end']):0.0;
                    $sub=sprintf(/* translators: %s: working hours */__('Deciding now · waiting %s working hours','workforce-one'),number_format_i18n($hours,$hours<10&&floor($hours)!=$hours?1:0));
                    if((int)($r->escalations??0) && (int)($r->escalated_from_wp_user_id??0))$moved=$this->approval_user_name((int)$r->escalated_from_wp_user_id);
                    break;
                case 'WAITING': $state='next';$sub=__('Next','workforce-one');break;
                case 'CANCELLED': $state='cancelled';$sub=__('Withdrawn by the employee','workforce-one');break;
                default:
                    $state='skipped';
                    $sub=($r->skip_reason??'')==='condition'?__('Not needed for this request','workforce-one'):(($r->skip_reason??'')==='self'?__('Skipped: the approver made the request','workforce-one'):__('Skipped: nobody to decide it','workforce-one'));
            }
            if($r->status!=='SKIPPED'){$pos++;if($r->status==='PENDING')$level=$pos;}
            $steps[]=['order'=>(int)$r->step_order,'state'=>$state,'label'=>$this->approval_resolver_label((string)$r->resolver_type),'who'=>$who,'sub'=>$sub];
        }
        $key=(string)($ar->workflow_key??$workflow_key);
        return ['id'=>(int)$ar->id,'workflow'=>$key,'status'=>(string)$ar->status,'level'=>$level,'levels'=>$levels,'hours'=>$hours,
            'overdue'=>$level>0 && $hours>=$this->approval_overdue_hours($key),'moved_from'=>$moved,'note'=>$note,'steps'=>$steps];
};
