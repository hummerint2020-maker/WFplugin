<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\Hub;
use WorkforceOne\Schedule\ScheduleChangeSettings;

/**
 * Schedule change requests (3.31.165, week lock release 2; design docs/tasks/schedule-change-request.md, mockups
 * docs/mockups/schedule-change/). Off by default (Feature Configuration → Week lock → Change requests to HR), and only while the
 * week lock and My approvals are on. A manager changes days of a final week on the Attendance grid and sends them to HR with a
 * reason; HR approves or rejects the whole request (My approvals, the Requests page); the days still as they were asked are
 * applied and each employee is told once. Controllers and words only: the rules are in src/Schedule/ScheduleChange*.php, the
 * SQL in its store. Behaviour: tests/e2e_schedule_change.py.
 */
trait EWS_Schedule_Change_Trait {

    /** The note of a rejection on the Requests page: stored in English (the Audit Log's language), shown translated. @var string */
    private static $sc_hub_note='Rejected on the Requests page.';

    /** A decision note as people read it. */
    private function sc_note_text($note){
        $note=(string)$note;
        return $note===self::$sc_hub_note?__('Rejected on the Requests page.','workforce-one'):$note;
    }

    /** The switch, with the week lock and My approvals (option reads only: no query while off). */
    public function sc_enabled(){
        return (bool)$this->option('ews_feature_schedule_change') && $this->week_lock_enabled() && $this->my_approvals_enabled();
    }

    /** @return array<string,mixed> */
    public function sc_settings(){ return ScheduleChangeSettings::config($this->option('ews_schedule_change'),$this->statuses()); }

    /** The two tables (an upgrade step; created while the feature is off too). */
    public function ensure_schedule_change_schema(){
        if($this->ews_schema_is_current())return;
        $this->services()->scheduleChangeSchema();
    }

    /** The first request sets the workflow to one level, HR; HR's own request goes to the administrators; rejecting needs a reason. */
    private function sc_workflow_ready(){
        $w=$this->approval_workflow('schedule_change');
        if($w && (int)$w->active)return true;
        $self=get_option('ews_approval_self_rule',[]);
        if(!is_array($self))$self=[];
        if(!isset($self['schedule_change'])){$self['schedule_change']='admins';update_option('ews_approval_self_rule',$self,false);}
        $note=get_option('ews_approval_reject_note',[]);
        if(!is_array($note))$note=[];
        if(!array_key_exists('schedule_change',$note)){$note['schedule_change']=1;update_option('ews_approval_reject_note',$note,false);}
        return $this->approval_configure_workflow('schedule_change','LEVEL_1',[1=>['resolver_type'=>'HR']],\WorkforceOne\Approvals\Workflows::IN_USE['schedule_change'][1])===true;
    }

    /* ------------------------------------------------------------------ words */

    /** "Sun 11 Oct" */
    private function sc_day($date){ return date_i18n('D j M',strtotime((string)$date)); }

    /** The words of an error code (asking, deciding, withdrawing). */
    public function sc_error_text($code){
        $cfg=$this->sc_settings();
        $m=[
            'access'=>__('Change requests to HR are off, or you cannot do that.','workforce-one'),
            'employee'=>__('Your account is not linked to an active employee.','workforce-one'),
            /* translators: %d: characters */
            'reason'=>sprintf(__('Write why the days should change (at least %d characters).','workforce-one'),(int)$cfg['reason_min']),
            'approval'=>__('The request could not be sent for approval. Try again.','workforce-one'),
            /* translators: %d: days */
            'too_many'=>sprintf(__('At most %d days can be asked for in one request.','workforce-one'),(int)$cfg['max_days']),
            'none'=>__('Nothing was sent: none of these days can be asked for.','workforce-one'),
            'pending'=>__('A change of one of these days already waits for HR.','workforce-one'),
            'save'=>__('It could not be saved. Try again.','workforce-one'),
            'note'=>__('Write why you reject it, then reject it again.','workforce-one'),
            'moved'=>__('This request moved to another approval level since you opened the page, so nothing was changed. Open it again to see where it is.','workforce-one'),
            'done'=>__('This request is no longer waiting: it was already decided, withdrawn or closed.','workforce-one'),
            'changed'=>__('Nothing was changed: a day of this request changed since it was asked. You can reject it.','workforce-one'),
        ];
        return $m[$code]??$m['save'];
    }

    /** Why a day was not sent. */
    private function sc_refused_text($code){
        $cfg=$this->sc_settings();
        $m=[
            'scope'=>__('you cannot manage this person','workforce-one'),
            'not_locked'=>__('this week is not final: save it on the grid as usual','workforce-one'),
            'status'=>__('this status cannot be asked for','workforce-one'),
            'leave_status'=>__('a leave goes through a leave request, so the balance is counted','workforce-one'),
            'same'=>__('the day is already so','workforce-one'),
            'holiday'=>__('it is a company holiday','workforce-one'),
            /* translators: %d: days */
            'past'=>sprintf(__('it is more than %d days ago','workforce-one'),(int)$cfg['past_days']),
            'closed_month'=>__('its month is closed in Payroll','workforce-one'),
            'leave_day'=>__('this day is a leave; change it through the leave','workforce-one'),
            'attendance'=>__('attendance is already recorded that day','workforce-one'),
            'pending'=>__('a change of this day already waits for HR','workforce-one'),
            'branch'=>__('this branch is not one of theirs','workforce-one'),
        ];
        return $m[$code]??$m['status'];
    }

    /** Why an approved day was not applied. */
    private function sc_skipped_text($result,$now){
        $m=[
            'inactive'=>__('Not applied: the employee is no longer active.','workforce-one'),
            'gone'=>__('Not applied: the status is no longer in use.','workforce-one'),
            'leave'=>__('Not applied: the day became a leave before the decision.','workforce-one'),
            'branch'=>__('Not applied: the branch is no longer one of theirs.','workforce-one'),
            'closed_month'=>__('Not applied: its month was closed in Payroll.','workforce-one'),
        ];
        if(isset($m[$result]))return $m[$result];
        /* translators: %s: the day's status now */
        return $now!==null&&$now!==''?sprintf(__('Not applied: the day changed before the decision (now %s).','workforce-one'),$now):__('Not applied: the day changed before the decision.','workforce-one');
    }

    /** "N days not sent: Adel · Mon 12 Oct — why; …" ('' when none). @param list<array{employee:int,date:string,code:string}> $refused */
    private function sc_refused_line(array $refused){
        if(!$refused)return '';
        $names=$this->people_names_by_ids(array_column($refused,'employee'));
        $parts=[];
        foreach(array_slice($refused,0,8) as $r)$parts[]=($names[(int)$r['employee']]??'#'.(int)$r['employee']).' · '.$this->sc_day($r['date']).' — '.$this->sc_refused_text($r['code']);
        $n=count($refused);
        /* translators: %d: days */
        $line=sprintf(_n('%d day not sent:','%d days not sent:',$n,'workforce-one'),$n).' '.implode('; ',$parts);
        if($n>8)$line.=' …';
        return $line.'.';
    }

    /* ------------------------------------------------------------------ Feature Configuration → Week lock → Change requests to HR */

    /** What the card shows. @return array<string,mixed> */
    public function sc_admin_view(){
        return ['s'=>$this->sc_settings(),'open'=>$this->sc_enabled()?$this->services()->scheduleChanges()->openCount():0,   // no query while off
            'statuses'=>$this->sc_offered_statuses(),'workflow_url'=>admin_url('admin.php?page=ews31-approvals')];
    }

    /** The statuses the card offers: every type that is not a leave. @return string[] */
    private function sc_offered_statuses(){
        return array_values(array_filter($this->statuses(),function($st){return $this->schedule_type_attendance_rule($st)!=='leave';}));
    }

    /** The card's fields, checked. @param array<string,mixed> $post unslashed @return array{0:array<string,mixed>,1:string} */
    public function sc_settings_from_post(array $post){
        [$sc,$error]=ScheduleChangeSettings::fromPost($post,$this->statuses(),$this->sc_offered_statuses());
        return [$error===''?ScheduleChangeSettings::config($sc,$this->statuses()):$sc,$error];   // in sc_settings() form, to compare
    }

    /* ------------------------------------------------------------------ the Attendance grid */

    /**
     * The grid's request mode: null when off, or when the week is not final for this person (open, or they may edit a final week).
     * @param string[] $dates @param int[] $employee_ids
     * @return array<string,mixed>|null
     */
    public function sc_grid_view(array $dates,array $employee_ids){
        if(!$dates || !$this->sc_enabled())return null;
        $wl=$this->week_lock_view($dates[0]);
        if(!$wl || !$wl['locked'] || $wl['override'])return null;
        $open=$employee_ids?$this->services()->scheduleChanges()->openOn($employee_ids,$dates[0],$dates[count($dates)-1]):[];
        $who=[];
        foreach($open as $days)foreach($days as $o)$who[]=(int)$o->requester_employee_id;
        $cfg=$this->sc_settings();
        return ['open'=>$open,'names'=>$who?$this->people_names_by_ids($who):[],'reason_min'=>(int)$cfg['reason_min'],'max_days'=>(int)$cfg['max_days']];
    }

    /** admin-post ews_schedule_change_request: the grid's changes of a final week, sent to HR with a reason. */
    public function schedule_change_request(){
        if(!$this->can('ews_manage_attendance'))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        check_admin_referer('ews_schedule_change_request');
        $week=sanitize_text_field(wp_unslash($_POST['week']??''));
        [$dates]=$this->week_dates_configured($week);
        $back=add_query_arg('week',$week,$this->app_return_url('attendance'));
        if(!$dates){wp_safe_redirect(add_query_arg('sc_error','none',$back));exit;}
        $raw=json_decode((string)wp_unslash($_POST['att_changes_json']??''),true);
        $branch_on=$this->branch_settings()['mode']==='schedule';
        $changes=[];
        foreach(is_array($raw)?$raw:[] as $c){
            if(!is_array($c))continue;
            $i=isset($c['day'])?absint($c['day']):9999;
            if(!isset($dates[$i]))continue;
            $status=sanitize_text_field((string)($c['status']??''));
            $row=['employee'=>absint($c['employee']??0),'date'=>$dates[$i],'status'=>$status];
            // "By the schedule": the day's branch (0: their main one); a status without a branch clears it, as the grid does.
            if($branch_on && array_key_exists('branch',$c))$row['branch']=$this->schedule_type_requires_location($status)?absint($c['branch']):0;
            $changes[]=$row;
        }
        $reason=sanitize_textarea_field(wp_unslash($_POST['sc_reason']??''));
        $r=$this->services()->scheduleChanges()->create($this->current_employee(),(int)get_current_user_id(),$changes,$reason,'grid');
        if($r['refused'])set_transient('ews_sc_refused_'.get_current_user_id(),array_slice($r['refused'],0,50),5*MINUTE_IN_SECONDS);
        else delete_transient('ews_sc_refused_'.get_current_user_id());
        wp_safe_redirect(add_query_arg($r['error']===''?['sc_sent'=>(int)$r['sent']]:['sc_error'=>$r['error']],$back));exit;
    }

    /** The pop-up after sending (the app layout's ux_notice_from_query() shows it). @return array{title:string,text:string,type:string} */
    public function sc_grid_notice(){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        $key='ews_sc_refused_'.get_current_user_id();
        $refused=get_transient($key);
        delete_transient($key);
        $line=$this->sc_refused_line(is_array($refused)?$refused:[]);
        if(isset($_GET['sc_sent'])){
            $n=absint($_GET['sc_sent']);
            /* translators: %d: days */
            $title=sprintf(_n('Sent to HR: %d day','Sent to HR: %d days',$n,'workforce-one'),$n);
            return ['title'=>$title,'text'=>trim(__('HR will review it and you will be told when it is decided.','workforce-one').' '.$line),'type'=>'success'];
        }
        $code=sanitize_key($_GET['sc_error']??'');
        // phpcs:enable
        return ['title'=>__('Not sent to HR','workforce-one'),'text'=>trim($this->sc_error_text($code).' '.$line),'type'=>'error'];
    }

    /* ------------------------------------------------------------------ My approvals and the Requests page */

    /** admin-post ews_schedule_change_decide: a level's decision (My approvals). */
    public function schedule_change_decide(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_schedule_change_decide');
        $back=$this->app_return_url($this->approvals_return_view('approvals'));
        $id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
        $r=$this->services()->scheduleChanges()->decide($id,$decision,$this->approval_posted_note(),$this->approval_posted_step(),false);
        $ok=in_array($r['result'],['advanced','approved','partial','rejected'],true);
        wp_safe_redirect(add_query_arg($ok?['scd_done'=>$r['result']]:['scd_error'=>$r['result']],$back));exit;
    }

    /** The notice on My approvals after a decision taken there, or null. @return array{type:string,text:string}|null */
    public function sc_approvals_notice(){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        if(isset($_GET['scd_done'])){
            $m=['approved'=>__('Approved: the days were applied and each employee was told.','workforce-one'),
                'partial'=>__('Approved, but not every day was applied: some changed since the request. My requests and the Audit Log list them.','workforce-one'),
                'rejected'=>__('Rejected, and the manager was told.','workforce-one'),
                'advanced'=>__('Approved at your level. It moves to the next one.','workforce-one')];
            $k=sanitize_key($_GET['scd_done']);
            return ['type'=>$k==='partial'?'warning':'success','text'=>$m[$k]??$m['approved']];
        }
        if(isset($_GET['scd_error']))return ['type'=>'error','text'=>$this->sc_error_text(sanitize_key($_GET['scd_error']))];
        // phpcs:enable
        return null;
    }

    /**
     * The day list of a card: who, which day, old → new, and a warning line. @param list<array<string,mixed>> $days
     * @return list<array{name:string,day:string,old:string,new:string,warn:string}>
     */
    private function sc_day_rows(array $days){
        $names=$this->people_names_by_ids(array_map(function($d){return (int)$d['employee'];},$days));
        $not_set=__('Not Set','workforce-one');
        $out=[];
        foreach($days as $d){
            $old=$d['old']!==null&&(string)$d['old']!==''?(string)$d['old']:$not_set;
            $out[]=['name'=>$names[(int)$d['employee']]??'#'.(int)$d['employee'],'day'=>$this->sc_day($d['date']),'old'=>$old,'new'=>(string)$d['new'],'warn'=>(string)($d['warn']??'')];
        }
        return $out;
    }

    /** The card in My approvals. @return array<string,mixed>|null */
    public function sc_approval_card($id,$light=false){
        if(!$this->sc_enabled())return null;
        if($light){   // (3.31.148) only what the counts read
            $r=$this->services()->scheduleChangeStore()->find((int)$id);
            return $r && (string)$r->status==='pending'?['employee_id'=>(int)$r->requester_employee_id,'tone'=>'schedule','since'=>(string)$r->requested_at]:null;
        }
        $c=$this->services()->scheduleChanges()->card((int)$id);
        if(!$c)return null;
        $r=$c['request'];
        $refuse=$this->sc_settings()['changed_day']==='refuse';
        $days=[];$changed=0;$people=[];
        foreach($c['days'] as $d){
            $people[(int)$d['employee']]=true;
            $warn='';
            if($d['changed']){
                $changed++;
                /* translators: %s: the day's status now */
                $now=$d['leave']?__('Changed since the request: now a leave.','workforce-one'):sprintf(__('Changed since the request: now %s.','workforce-one'),$d['now']!==null?$d['now']:__('Not Set','workforce-one'));
                $warn=$now.' '.($refuse?__('While it stays so, approving changes nothing.','workforce-one'):__('This day will be skipped.','workforce-one'));
            }
            $days[]=$d+['warn'=>$warn];
        }
        $n=count($days);
        $range=(string)$r->first_date===(string)$r->last_date?$this->sc_day($r->first_date):$this->sc_day($r->first_date).' – '.$this->sc_day($r->last_date);
        /* translators: 1: days, 2: dates, 3: people */
        $what=sprintf(_n('%1$d day · %2$s','%1$d days · %2$s',$n,'workforce-one'),$n,$range).' · '.sprintf(_n('%d person','%d people',count($people),'workforce-one'),count($people));
        $facts=[[__('Days','workforce-one'),$range],[__('Reason','workforce-one'),(string)$r->reason]];
        if($c['closed'])$facts[]=[__('Pay','workforce-one'),sprintf(/* translators: %s: months */__('Payroll is closed for %s: this request closes automatically.','workforce-one'),implode(', ',$c['closed']))];
        elseif((string)$r->first_date<current_time('Y-m-d'))$facts[]=[__('Pay','workforce-one'),__('The month is still open in Payroll. Past days change absence and lateness.','workforce-one')];
        /* translators: %d: days */
        $pills=$changed?[[sprintf(_n('%d day changed since','%d days changed since',$changed,'workforce-one'),$changed),'is-warn']]:[];
        return ['employee_id'=>(int)$r->requester_employee_id,'tone'=>'schedule','kind'=>__('Schedule change','workforce-one'),'what'=>$what,'facts'=>$facts,'pills'=>$pills,
            'sc_days'=>$this->sc_day_rows($days),'since'=>(string)$r->requested_at,'action'=>'ews_schedule_change_decide','nonce'=>'ews_schedule_change_decide',
            'fields'=>['request_id'=>(int)$r->id],'bulk'=>false,'decisions'=>['yes'=>['approve',__('Approve','workforce-one')],'no'=>['reject',__('Reject','workforce-one')]]];
    }

    /** The waiting requests on the Requests page (none while off: no query). @return list<array{request:object,days:object[]}> */
    public function sc_hub_rows(){
        return $this->sc_enabled()?$this->services()->scheduleChanges()->waiting(200):[];
    }

    /** "3 day(s) · 2026-10-11 → 2026-10-13 · Adel 2026-10-11 WFH → Office; … · "reason"" (the Requests page is in English). */
    public function sc_hub_details($row){
        $r=$row['request'];
        $names=$this->people_names_by_ids(array_map(function($d){return (int)$d->employee_id;},$row['days']));
        $parts=[];
        foreach(array_slice($row['days'],0,6) as $d)$parts[]=($names[(int)$d->employee_id]??'#'.(int)$d->employee_id).' '.(string)$d->work_date.' '.((string)$d->old_status!==''?(string)$d->old_status:'Not Set').' → '.(string)$d->new_status;
        if(count($row['days'])>6)$parts[]='…';
        return Hub::details((int)$r->days.' day(s)',(string)$r->first_date.' → '.(string)$r->last_date,implode('; ',$parts),'"'.mb_substr((string)$r->reason,0,200).'"');
    }

    /** An administrator's decision on the Requests page (the override of a level). @return string a Hub outcome */
    public function sc_hub_decide($id,$decision){
        $r=$this->services()->scheduleChanges()->decide((int)$id,$decision,$decision==='reject'?self::$sc_hub_note:'',$this->approval_posted_step(),true);
        $map=['advanced'=>Hub::ADVANCED,'approved'=>Hub::APPROVED,'partial'=>Hub::SC_PARTIAL,'rejected'=>Hub::REJECTED,'moved'=>Hub::MOVED,'changed'=>Hub::SC_CHANGED];
        return $map[$r['result']]??Hub::ERROR;
    }

    /** For My requests. @return list<array<string,mixed>> */
    public function sc_my_requests($employee_id){
        if(!$this->sc_enabled())return [];
        $cfg=$this->sc_settings();
        $state=['pending'=>'waiting','approved'=>'approved','partial'=>'approved','rejected'=>'rejected','withdrawn'=>'withdrawn','expired'=>'expired','closed'=>'expired'];
        $out=[];
        foreach($this->services()->scheduleChanges()->mine((int)$employee_id,40) as $row){
            $r=$row['request'];$st=(string)$r->status;
            $n=(int)$r->days;
            $range=(string)$r->first_date===(string)$r->last_date?$this->sc_day($r->first_date):$this->sc_day($r->first_date).' – '.$this->sc_day($r->last_date);
            $sub=[$range];
            $label='';$note='';$skipped=[];
            if($st==='partial'){
                $label=(int)$r->applied>0?__('Partly applied','workforce-one'):__('Not applied','workforce-one');
                /* translators: 1: days applied, 2: days not applied */
                $sub[]=(int)$r->applied>0?sprintf(__('%1$d applied, %2$d not','workforce-one'),(int)$r->applied,$n-(int)$r->applied):__('none applied','workforce-one');
                foreach($row['days'] as $d)if(!in_array((string)$d->result,['applied','already',''],true))
                    $skipped[]=['employee'=>(int)$d->employee_id,'date'=>(string)$d->work_date,'old'=>$d->old_status,'new'=>(string)$d->new_status,'warn'=>$this->sc_skipped_text((string)$d->result,$d->now_status!==null?(string)$d->now_status:null)];
            }elseif($st==='expired'||$st==='closed'){
                $label=__('Closed automatically','workforce-one');
                $why=(string)$r->decision_note;
                /* translators: %d: days */
                $note=$why==='expired'?sprintf(__('Closed automatically: %d days without a decision.','workforce-one'),(int)$cfg['expire_days'])
                    :($why==='closed_month'?__('Closed automatically: its month was closed in Payroll.','workforce-one'):__('Closed automatically: change requests to HR were switched off.','workforce-one'));
            }elseif($st==='rejected' && trim((string)$r->decision_note)!==''){
                /* translators: %s: reason */
                $note=sprintf(__('Reason: %s','workforce-one'),$this->sc_note_text($r->decision_note));
            }
            $out[]=['key'=>'schedule-'.(int)$r->id,'tone'=>'schedule','title'=>sprintf(/* translators: %d: days */_n('Schedule change · %d day','Schedule change · %d days',$n,'workforce-one'),$n),
                'sub'=>implode(' · ',$sub),'state'=>$state[$st]??'waiting','state_label'=>$label,'when'=>(string)$r->requested_at,'note'=>$note,
                'sc_days'=>$skipped?$this->sc_day_rows($skipped):[],'approval'=>['schedule_change','schedule_change',(int)$r->id],
                'withdraw'=>$st==='pending'?'schedule':'','id'=>(int)$r->id];
        }
        return $out;
    }

    /** My requests → Withdraw. @return true|string */
    public function sc_withdraw($id,$employee_id){
        if(!$this->sc_enabled())return 'access';
        $r=$this->services()->scheduleChanges()->withdraw((int)$id,(int)$employee_id);
        return $r===''?true:$r;
    }

    /* ------------------------------------------------------------------ notifications (after COMMIT; the ports call them) */

    private function sc_notify_approver($r){
        $ar=$this->approval_find_request('schedule_change','schedule_change',(int)$r->id);
        $step=$ar?$this->approval_current_step((int)$ar->id):null;
        if(!$step || !(int)$step->approver_wp_user_id)return;
        $this->notify((int)$step->approver_wp_user_id,'system',__('A schedule change waits for you','workforce-one'),
            /* translators: 1: manager name, 2: days */
            sprintf(_n('%1$s asked to change %2$d day of a final week. Open My approvals.','%1$s asked to change %2$d days of a final week. Open My approvals.',(int)$r->days,'workforce-one'),$this->people_name((int)$r->requester_employee_id),(int)$r->days),
            ['entity'=>'approval','entity_id'=>(int)$ar->id,'push'=>true]);
    }

    /** @param array<string,mixed> $detail */
    private function sc_notify_requester($event,$r,array $detail){
        $uid=(int)$this->people_user_id((int)$r->requester_employee_id,true);
        if(!$uid)return;
        $id=(int)$r->id;$n=(int)$r->days;
        $skipped=count((array)($detail['skipped']??[]));
        $why=(string)($detail['why']??'');
        $m=[
            /* translators: 1: request number, 2: days */
            'approved'=>[__('Your schedule change was approved','workforce-one'),sprintf(_n('Request #%1$d: the %2$d day was applied.','Request #%1$d: the %2$d days were applied.',$n,'workforce-one'),$id,$n)],
            /* translators: 1: request number, 2: days applied, 3: days not applied */
            'partial'=>$n-$skipped>0?[__('Your schedule change was partly applied','workforce-one'),sprintf(__('Request #%1$d: %2$d day(s) applied; %3$d not, because they changed before the decision. See My requests.','workforce-one'),$id,$n-$skipped,$skipped)]
                /* translators: %d: request number */
                :[__('Your schedule change was approved but not applied','workforce-one'),sprintf(__('Request #%d: none of the days was applied, because they all changed before the decision. See My requests.','workforce-one'),$id)],
            'rejected'=>[__('Your schedule change was rejected','workforce-one'),trim((string)($detail['note']??''))!==''
                /* translators: 1: request number, 2: reason */
                ?sprintf(__('Request #%1$d was rejected. Reason: %2$s','workforce-one'),$id,$this->sc_note_text($detail['note']))
                /* translators: %d: request number */
                :sprintf(__('Request #%d was rejected.','workforce-one'),$id)],
            'closed'=>[__('Your schedule change was closed','workforce-one'),$why==='expired'
                /* translators: 1: request number, 2: days */
                ?sprintf(__('Request #%1$d closed automatically: %2$d days without a decision.','workforce-one'),$id,(int)$this->sc_settings()['expire_days'])
                :($why==='closed_month'
                    /* translators: %d: request number */
                    ?sprintf(__('Request #%d closed automatically: its month was closed in Payroll.','workforce-one'),$id)
                    /* translators: %d: request number */
                    :sprintf(__('Request #%d closed automatically: change requests to HR were switched off.','workforce-one'),$id))],
        ];
        if(!isset($m[$event]))return;
        $this->notify($uid,'system',$m[$event][0],$m[$event][1],['url'=>add_query_arg('ews_view','requests',$this->app_home_url()),'push'=>true]);
    }

    /** One message per employee: "Sun 11 Oct is now Office · …". @param array<string,string> $days Y-m-d => status */
    private function sc_notify_employee($kind,$employee_id,array $days,$request_id){
        $uid=(int)$this->people_user_id((int)$employee_id,true);
        if(!$uid||!$days)return;
        ksort($days);
        $parts=[];
        /* translators: 1: day, 2: status */
        foreach($days as $d=>$st)$parts[]=sprintf($kind==='applied'?__('%1$s is now %2$s','workforce-one'):__('%1$s to %2$s','workforce-one'),$this->sc_day($d),$st);
        $url=add_query_arg(['ews_view'=>'schedule','week'=>(string)array_key_first($days)],$this->app_home_url());
        if($kind==='applied')$this->notify($uid,'schedule',__('Your schedule changed','workforce-one'),implode(' · ',$parts),['url'=>$url,'entity_id'=>(int)$request_id,'push'=>true]);
        else $this->notify($uid,'schedule',__('A change of your schedule was asked','workforce-one'),__('Your manager asked HR to change:','workforce-one').' '.implode(' · ',$parts),['url'=>$url,'entity_id'=>(int)$request_id]);
    }

    /* ------------------------------------------------------------------ jobs */

    /** The five-minute tick (week_lock_tick): waiting requests too old, or whose payroll month closed. */
    public function sc_expire_tick(){
        return $this->services()->scheduleChanges()->expireDue();
    }

    /** Every waiting request closed (the switch, the week lock or My approvals went off). @return int closed */
    public function sc_close_all($why='off'){
        return $this->services()->scheduleChanges()->closeAll((string)$why);
    }
}
