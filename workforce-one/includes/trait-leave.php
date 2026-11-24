<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\Balance;
use WorkforceOne\Leave\TypeRules;
use WorkforceOne\Leave\CancellationRules;
use WorkforceOne\Leave\RequestRules;
use WorkforceOne\Leave\ReturnRules;
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

    /**
     * The type of each day of approved leave of these employees between $from and $to (3.31.152, the Attendance page's
     * Leave type): employee id => Y-m-d => the type's name ('' when the type is gone). One read; [] when it fails.
     * @param int[] $employee_ids @return array<int,array<string,string>>
     */
    private function leave_types_by_day(array $employee_ids,$from,$to){
            return $this->services()->leaveDays()->typesByDay($employee_ids,(string)$from,(string)$to);
        }

    /**
     * The days held by an approved leave (Leave return, 3.31.161, owner: a manager's schedule change never returns leave): employee
     * id => Y-m-d => leave id. One read; [] with Leave return off (no query).
     * @param int[] $employee_ids @return array<int,array<string,int>>
     */
    private function leave_held_days(array $employee_ids,$from,$to){
            if(!$this->leave_return_on()||!$employee_ids)return [];
            return $this->services()->leaveDays()->heldDays($employee_ids,(string)$from,(string)$to);
        }

    /** One Audit Log row for the schedule changes refused on approved leave days (up to 20 "employee date" pairs). */
    private function leave_held_audit($source,array $pairs){
            if(!$pairs)return;
            $this->audit('schedule_leave_refused','schedule',0,$source.': '.count($pairs).' refused | '.implode(', ',array_slice($pairs,0,20)));
        }

    /**
     * The pending leave requests of these employees, newest first (the Smart Nudges cron, 3.31.148): one read for all of them, in the order the
     * one-person reminder reads them (requested_at DESC). @param int[] $employee_ids @return object[] id, employee_id, start_date, end_date, requested_days
     */
    private function leave_pending_newest_first(array $employee_ids){
            global $wpdb;$out=[];
            foreach(array_chunk(array_values(array_unique(array_filter(array_map('intval',$employee_ids)))),500) as $chunk){
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,employee_id,start_date,end_date,requested_days FROM {$this->leaves} WHERE employee_id IN (".implode(',',array_fill(0,count($chunk),'%d')).") AND status='Pending' ORDER BY requested_at DESC",$chunk)) as $r)$out[]=$r;
            }
            return $out;
        }

    /** A leave request's status ('' when there is none), for substitutes linked to a leave (3.31.107). */
    private function leave_status_of($leave_id){
            global $wpdb;
            return (string)$wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}ews_leave_requests WHERE id=%d",(int)$leave_id));
        }

    /** Working days of a leave; with "Company holidays are not leave days" on (3.31.162), the company holidays are not counted. */
    private function vacation_working_days_count($start,$end){
            return WorkingDays::count((string)$start,(string)$end,$this->working_days(),$this->leave_holiday_dates($start,$end));
        }

    private function leave_redirect($args=[]){
            $url=wp_get_referer(); if(!$url||strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            if($this->my_requests_leave_on())$url=remove_query_arg(['leave_cancel_error','req_done','req_error'],$url);   // 3.31.158: an old message is not shown again
            $url=remove_query_arg(['leave_sent','leave_error','leave_done','leave_cancel_sent','early_error','break_success','break_error','overtime_sent','overtime_error','time_success','time_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the handler checked its own nonce
            $decision=in_array(sanitize_key($_POST['action']??''),['ews_vacation_request_respond','ews_leave_cancel_respond','ews_early_leave_respond'],true);
            $url=add_query_arg('ews_view',$this->approvals_return_view($this->leave_page_view($decision)),$url); if($args)$url=add_query_arg($args,$url); wp_safe_redirect($url); exit;
        }

    /**
     * A leave or cancellation asked while leave approvals were off has no approval levels; after they are turned on it stays with
     * the administrators, decided as before (3.31.158, with Leave in My requests on; until then it could not be decided).
     */
    private function leave_without_levels($type,$id){
            // Leave return (3.31.161): a leave changed more than once has its levels per round, not per leave.
            if($type==='leave_cancellation' && $this->leave_return_on()){
                $c=$this->leave_change_current((int)$id);
                if($c && (string)$c->source==='employee')return $this->my_requests_leave_on() && !(int)$c->approval_request_id;
            }
            return $this->my_requests_leave_on() && !$this->approval_find_request('vacation',(string)$type,(int)$id);
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
    /** @param array<string,string>|null $codes From My requests (3.31.158): leave_cancel_error with these codes (others: save) instead of leave_error. */
    private function leave_transaction(callable $fn,$codes=null){
            global $wpdb;
            $back=function($code)use($codes){return $codes===null?['leave_error'=>$code]:['leave_cancel_error'=>$codes[$code]??'save'];};
            if($wpdb->query('START TRANSACTION')===false)$this->leave_redirect($back('save'));
            $result=$fn();
            if($result!==true){$wpdb->query('ROLLBACK');$this->leave_redirect($back(is_string($result)?$result:'save'));}
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$this->leave_redirect($back('save'));}
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
            $row=['employee_id'=>$emp->id,'leave_type_id'=>(int)$type->id,'start_date'=>$start,'end_date'=>$end,'requested_days'=>$days,'reason'=>$reason,'status'=>'Pending','requested_by'=>get_current_user_id(),'balance_id'=>$bal?(int)$bal->id:null];
            $fmt=['%d','%d','%s','%s','%f','%s','%s','%d','%d'];
            // Counted without the company holidays (3.31.162): the leave says so, so its approval skips them too and it is never in the report.
            if($this->leave_holidays_on()){$row['holidays_excluded']=1;$fmt[]='%d';}
            $ok=$wpdb->insert($wpdb->prefix.'ews_leave_requests',$row,$fmt);
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
            // A leave counted without the company holidays (3.31.162) gets no leave day on them; an older one as before (its days are charged).
            $leave_dates=WorkingDays::dates($r->start_date,$r->end_date,$this->working_days(),!empty($r->holidays_excluded)?array_keys($this->leave_company_holidays($r->start_date,$r->end_date)):[]);
            foreach($leave_dates as $date){
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
            $this->week_lock_record_request((int)$emp->id,$leave_dates,'leave','leave #'.(int)$r->id);   // applied as usual, recorded (3.31.121)
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

    /**
     * The first day a cancellation cancels when it is not the whole leave (3.31.158), or null for the whole leave:
     * the day the employee said they are back at work (owner: even when approved later), else, decided once the leave has
     * started, today (the days taken stay leave). A day asked is kept even if the switch was turned off meanwhile.
     */
    /** What the last cancellation applied did (3.31.158): '' (all of it), 'from:Y-m-d' or 'ended'; for the employee's message. */
    private $leave_cancel_outcome='';

    private function leave_cancel_from_day($r){
            $asked=(string)($r->cancel_from??'');
            if($asked!=='')return $asked>(string)$r->start_date?$asked:null;
            if(!$this->my_requests_leave_on())return null;
            $today=current_time('Y-m-d');
            return $today>(string)$r->start_date?$today:null;
        }

    /** Cancellation approved: return used days, restore the snapshotted schedule, mark Cancelled. @return true|string leave_error */
    private function leave_apply_cancellation($r,$emp,$bal,$now){
            // Leave return (3.31.161): under the leave's row lock, from the days actually removed, closed payroll months kept.
            if($this->leave_return_on())return $this->leave_return_apply((int)$r->id,$emp,$now,$this->leave_return_asked_day($r),'employee','Pending');
            $this->substitutes_sync_at_shutdown();   // a manager's substitute follows the leave (3.31.107)
            global $wpdb;
            // With Leave in My requests on (3.31.158, owner): a cancellation decided once the leave has started cancels the days from
            // today on only; the days taken stay leave. Once it has ended, nothing is left to cancel.
            $from=$this->leave_cancel_from_day($r);
            if($from!==null)return $this->leave_apply_cancellation_from($r,$emp,$bal,$now,$from);
            if((int)$r->deduct_balance){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d",$r->requested_days,$now,$bal->id));
                if($q===false)return 'save';
            }
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_schedule_snapshots WHERE leave_request_id=%d ORDER BY work_date ASC",(int)$r->id));
            if($rows===null)return 'save';
            if(!$this->leave_restore_days($rows,$emp,$now))return 'save';
            $q=$wpdb->update($wpdb->prefix.'ews_leave_requests',['status'=>'Cancelled','cancellation_status'=>'Approved','cancelled_by'=>get_current_user_id(),'cancelled_at'=>$now],['id'=>(int)$r->id,'status'=>'Approved','cancellation_status'=>'Pending'],['%s','%s','%d','%s'],['%d','%s','%s']);
            if($q!==1)return $q===false?'save':'done';
            $this->week_lock_record_request((int)$emp->id,array_map(function($x){return (string)$x->work_date;},$rows),'leave_cancel','leave #'.(int)$r->id);
            return true;
        }

    /** The schedule of these snapshot days back as before the leave (and, $drop, the snapshots gone: 3.31.158). */
    private function leave_restore_days($rows,$emp,$now,$drop=false){
            global $wpdb;
            foreach($rows as $x){
                if((int)$x->had_schedule&&$x->schedule_id)$q=$wpdb->update($this->schedule,['status'=>$x->status,'note'=>$x->note,'updated_by'=>get_current_user_id(),'updated_at'=>$now],['id'=>$x->schedule_id]);
                else $q=$wpdb->delete($this->schedule,['employee_id'=>$emp->id,'work_date'=>$x->work_date]);
                if($q===false)return false;
                if($drop && $wpdb->delete($wpdb->prefix.'ews_leave_schedule_snapshots',['id'=>(int)$x->id])===false)return false;
            }
            return true;
        }

    /**
     * A started leave's cancellation (3.31.158): the days from $from on go back to the balance and to the schedule as before the
     * leave; the leave now ends the day before. Past its end, the cancellation is closed as Ended with nothing changed.
     * @return true|string leave_error
     */
    private function leave_apply_cancellation_from($r,$emp,$bal,$now,$from){
            global $wpdb;
            $t=$wpdb->prefix.'ews_leave_requests';$cond=['id'=>(int)$r->id,'status'=>'Approved','cancellation_status'=>'Pending'];
            if($from>(string)$r->end_date){
                $q=$wpdb->update($t,['cancellation_status'=>'Ended','cancelled_by'=>get_current_user_id(),'cancelled_at'=>$now],$cond,['%s','%d','%s'],['%d','%s','%s']);
                $this->leave_cancel_outcome='ended';
                return $q===1?true:($q===false?'save':'done');
            }
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_schedule_snapshots WHERE leave_request_id=%d AND work_date>=%s ORDER BY work_date ASC",(int)$r->id,$from));
            if($rows===null)return 'save';
            // The days given back: the leave's days from then on (its snapshots); a leave kept without snapshots counts its working days.
            $any=$rows?1:(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_leave_schedule_snapshots WHERE leave_request_id=%d",(int)$r->id));
            $back=min((float)$r->requested_days,(float)($any?count($rows):$this->vacation_working_days_count($from,(string)$r->end_date)));
            if(!$this->leave_restore_days($rows,$emp,$now,true))return 'save';
            if((int)$r->deduct_balance && $back>0){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d",$back,$now,$bal->id));
                if($q===false)return 'save';
            }
            $end=gmdate('Y-m-d',strtotime($from.' -1 day'));
            $q=$wpdb->update($t,['end_date'=>$end,'requested_days'=>max(0.0,(float)$r->requested_days-$back),'cancellation_status'=>'Approved','cancelled_by'=>get_current_user_id(),'cancelled_at'=>$now],$cond,['%s','%f','%s','%d','%s'],['%d','%s','%s']);
            if($q!==1)return $q===false?'save':'done';
            // A manager's substitute ends when the leave now ends (review 2).
            if(!$this->services()->substitutes()->followLeaveEnd((int)$r->id,(string)$r->start_date,$end))return 'save';
            $this->leave_cancel_outcome='from:'.$from;
            $this->week_lock_record_request((int)$emp->id,array_map(function($x){return (string)$x->work_date;},$rows),'leave_cancel','leave #'.(int)$r->id.' from '.$from);
            $this->audit('leave_cancelled_from','leave_request',(int)$r->id,$r->type_name.' '.$r->start_date.' – '.$r->end_date.': cancelled from '.$from.', '.$back.' day(s) back');
            return true;
        }

    /* ------------------------------------------------------------------ Leave return (3.31.161, docs/tasks/leave-return.md) */

    /** The leave row, locked until the transaction ends (not on SQLite, which has no row locks), with its type. */
    private function leave_lock_row($id){
            global $wpdb;
            $lock=(defined('DB_ENGINE') && DB_ENGINE==='sqlite')?'':' FOR UPDATE';
            return $wpdb->get_row($wpdb->prepare("SELECT r.*,lt.name type_name,lt.deduct_balance,lt.kind,lt.carry_mode,lt.carry_max FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id=%d LIMIT 1{$lock}",(int)$id));
        }

    /** The leave's change rounds, newest first (Leave return). @return object[] */
    private function leave_changes($leave_id,$limit=10){
            global $wpdb;
            return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_changes WHERE leave_request_id=%d ORDER BY id DESC LIMIT %d",(int)$leave_id,(int)$limit));
        }

    /** The current (newest) change round of a leave, or null. */
    private function leave_change_current($leave_id){
            $c=$this->leave_changes($leave_id,1);
            return $c?$c[0]:null;
        }

    /**
     * The round a decision form showed is still the current one (Leave return): the form posts the round's id; a form without it
     * (an older page, a round asked before 3.31.161) is not checked.
     */
    private function leave_round_matches($leave_id){
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the handler checked its own nonce
            $c=$this->leave_change_current($leave_id);
            // A form that shows no round (an older page) may decide only a change asked before 3.31.161 (no round row waiting).
            if(!isset($_POST['round']))return !$c || (string)$c->status!=='Pending';
            return (int)($c->id??0)===absint($_POST['round']);
        }

    /** The change a decision form shows (Leave return on): its round row's id; 0 when off or when there is none. */
    private function leave_round_token($leave_id){
            if(!$this->leave_return_on())return 0;
            $c=$this->leave_change_current((int)$leave_id);
            return $c?(int)$c->id:0;
        }

    /**
     * Whether the leave's current change has an approval to show (Leave return on): a round asked or recorded without levels
     * has none, so an earlier round's path is not shown for it (review). Off, or a leave without rounds: true, as before.
     */
    private function leave_round_has_approval($leave_id){
            if(!$this->leave_return_on())return true;
            $c=$this->leave_change_current((int)$leave_id);
            return !$c || (int)$c->approval_request_id>0;
        }

    /** The leave's days from a day on: its snapshot days, or for a leave kept without any (very old data) its working days. */
    private function leave_return_days_from($r,$from){
            if($this->leave_days_from((int)$r->id,'0000-00-00')>0)return $this->leave_days_from((int)$r->id,$from);
            return $from<=(string)$r->end_date?(int)$this->vacation_working_days_count(max($from,(string)$r->start_date),(string)$r->end_date):0;
        }

    /** A new change round. @return int its id, 0 on a database error */
    private function leave_change_insert($r,$source,$asked,$reason,$approval_id){
            global $wpdb;
            $round=1+(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_leave_changes WHERE leave_request_id=%d",(int)$r->id));
            $row=['leave_request_id'=>(int)$r->id,'employee_id'=>(int)$r->employee_id,'round'=>$round,'source'=>(string)$source,'asked_from'=>(string)$asked,
                'status'=>$source==='hr'?'Recorded':'Pending','end_before'=>(string)$r->end_date,'reason'=>$reason!==''?(string)$reason:null,
                'requested_by'=>get_current_user_id(),'requested_at'=>current_time('mysql')];
            if($approval_id)$row['approval_request_id']=(int)$approval_id;
            return $wpdb->insert($wpdb->prefix.'ews_leave_changes',$row)===false?0:(int)$wpdb->insert_id;
        }

    /** The current round ends (Rejected, Withdrawn, Closed, Ended…). Nothing when the leave has no round (asked before 3.31.161). */
    private function leave_change_close($leave_id,$status,$extra=[]){
            global $wpdb;
            $c=$this->leave_change_current($leave_id);
            if(!$c || !in_array((string)$c->status,['Pending'],true))return true;
            return $wpdb->update($wpdb->prefix.'ews_leave_changes',['status'=>(string)$status,'decided_by'=>get_current_user_id(),'decided_at'=>current_time('mysql')]+$extra,['id'=>(int)$c->id,'status'=>'Pending'])!==false;
        }

    /** Snapshot days of a leave on or after a day. */
    private function leave_days_from($leave_id,$from){
            global $wpdb;
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_leave_schedule_snapshots WHERE leave_request_id=%d AND work_date>=%s",(int)$leave_id,(string)$from));
        }

    /**
     * The day the current change asks to cancel from: the day back at work, or (asked before Leave return) as before.
     * The whole leave = its first day.
     */
    private function leave_return_asked_day($r){
            $from=$this->leave_cancel_from_day($r);
            return $from!==null?$from:(string)$r->start_date;
        }

    /**
     * One snapshot day back as it was before the leave, unless a manager changed it meanwhile (owner, 2026-11-24: their status is
     * kept, and the Audit Log says so). @return bool false on a database error
     */
    private function leave_return_restore_day($x,$emp,$now,$leave_id){
            $cur=$this->schedule_for_employee_date((int)$emp->id,(string)$x->work_date);
            if(!$cur || (string)$cur->status!=='Vacation'){
                $this->audit('leave_restore_kept','leave_request',(int)$leave_id,(string)$x->work_date.' kept as '.($cur?(string)$cur->status:'no schedule').' (changed while on leave)');
                return true;
            }
            return $this->leave_restore_days([$x],$emp,$now);
        }

    /**
     * Next year's carried days follow a late return (owner, 2026-11-24): when days go back to a year whose next year's balance
     * already exists, it grows by what the carry rule now allows, never more than the days returned. @return float|false the days added
     */
    private function leave_carry_top_up($r,$bal,$back,$now){
            global $wpdb;
            if($back<=0 || !$bal)return 0.0;
            $bt=$wpdb->prefix.'ews_leave_balances';
            $next=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",(int)$bal->employee_id,(int)$bal->leave_type_id,(int)$bal->leave_year+1));
            if(!$next)return 0.0;   // it does not exist yet: it carries the corrected figures when it is made
            $year=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$bt} WHERE id=%d",(int)$bal->id));
            if(!$year)return false;
            $add=ReturnRules::carryTopUp(TypeRules::config($r),(float)$year->entitlement,(float)$year->used,(float)$year->pending,(float)($next->carried??0),(float)$back);
            if($add<=0)return 0.0;
            if($wpdb->query($wpdb->prepare("UPDATE {$bt} SET carried=carried+%f,entitlement=entitlement+%f,updated_at=%s WHERE id=%d",$add,$add,$now,(int)$next->id))===false)return false;
            $this->audit('leave_carry_topped_up','leave_balance',(int)$next->id,'leave #'.(int)$r->id.': '.(int)$bal->leave_year.' → '.((int)$bal->leave_year+1).' +'.$add.' carried');
            return $add;
        }

    /**
     * The return itself (Leave return on), for an approved change and for a return HR records; the caller owns the transaction.
     * Under the leave's row lock: the days from $asked on (days in a closed payroll month stay leave) go back to the schedule and
     * the balance, counted from the leave days actually removed, so a day is never returned twice.
     * $expect: the cancellation state the caller read ('Pending' for a decision; HR: whatever it found).
     * @return true|string leave_error
     */
    private function leave_return_apply($id,$emp,$now,$asked,$source,$expect){
            global $wpdb;
            $this->substitutes_sync_at_shutdown();
            $r=$this->leave_lock_row($id);
            if(!$r || (string)$r->status!=='Approved' || (string)($r->cancellation_status??'')!==(string)$expect)return 'done';
            if($source==='employee'){
                $asked=$this->leave_return_asked_day($r);   // the round as locked, not as read before (review)
                // A level left open (approvals switched off meanwhile) would block every later round: closed now (review).
                if($this->approval_close_open('vacation','leave_cancellation',(int)$r->id,true)===false)return 'save';
            }
            $t=$wpdb->prefix.'ews_leave_requests';$snap=$wpdb->prefix.'ews_leave_schedule_snapshots';
            $end=(string)$r->end_date;
            $closed=$this->payroll_closed_months($asked,$end);
            $eff=ReturnRules::effectiveFrom((string)$asked,$end,$closed);
            if($eff===null){
                if($source==='hr')return 'closed_payroll';
                $q=$wpdb->query($wpdb->prepare("UPDATE {$t} SET cancellation_status='Ended',cancelled_by=%d,cancelled_at=%s WHERE id=%d AND status='Approved' AND cancellation_status='Pending'",get_current_user_id(),$now,(int)$r->id));
                if($q!==1)return $q===false?'save':'done';
                if(!$this->leave_change_close((int)$r->id,'Ended'))return 'save';
                $this->leave_cancel_outcome='ended';
                $this->leave_return_applied=['closed_all'=>(bool)$closed];
                if($closed)$this->audit('leave_return_kept_closed','leave_request',(int)$r->id,'nothing returned: payroll closed for '.implode(', ',$closed));
                return true;
            }
            $lock=(defined('DB_ENGINE') && DB_ENGINE==='sqlite')?'':' FOR UPDATE';
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$snap} WHERE leave_request_id=%d AND work_date>=%s ORDER BY work_date ASC{$lock}",(int)$r->id,$eff));
            if($rows===null)return 'save';
            $deleted=0;$dates=[];
            foreach($rows as $x){
                if(!$this->leave_return_restore_day($x,$emp,$now,(int)$r->id))return 'save';
                $n=$wpdb->delete($snap,['id'=>(int)$x->id]);
                if($n===false)return 'save';
                if($n===1){$deleted++;$dates[]=(string)$x->work_date;}
            }
            $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$snap} WHERE leave_request_id=%d",(int)$r->id));
            $has=($deleted+$remaining)>0;
            $back=ReturnRules::daysBack($deleted,$remaining,(float)$r->requested_days,$has,$has?0:$this->vacation_working_days_count($eff,$end));
            $bal=$this->leave_request_balance($r);
            $top=0.0;
            if((int)$r->deduct_balance && $back>0){
                if(!$bal)return 'save';
                if($wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d",$back,$now,(int)$bal->id))===false)return 'save';
                $top=$this->leave_carry_top_up($r,$bal,$back,$now);
                if($top===false)return 'save';
            }
            $whole=$has?$remaining===0:$eff<=(string)$r->start_date;
            $new_end=$whole?$end:gmdate('Y-m-d',strtotime($eff.' -1 day'));
            $set=$whole?['status'=>'Cancelled','cancellation_status'=>'Approved']:['end_date'=>$new_end,'requested_days'=>max(0,(float)$r->requested_days-$back),'cancellation_status'=>'Approved'];
            $set+=['cancelled_by'=>(int)get_current_user_id(),'cancelled_at'=>$now,'cancel_from'=>(string)$asked];
            $was=$r->cancellation_status;   // as locked: the update changes the leave only if it is still so
            $where="id=%d AND status='Approved' AND ".($was===null?'cancellation_status IS NULL':'cancellation_status=%s');
            $parts=[];$vals=[];
            foreach($set as $k=>$v){$parts[]="`{$k}`=".(is_int($v)?'%d':(is_float($v)?'%f':'%s'));$vals[]=$v;}
            $vals[]=(int)$r->id;if($was!==null)$vals[]=(string)$was;
            $q=$wpdb->query($wpdb->prepare("UPDATE {$t} SET ".implode(',',$parts)." WHERE {$where}",$vals));
            if($q!==1)return $q===false?'save':'done';
            if(!$whole && !$this->services()->substitutes()->followLeaveEnd((int)$r->id,(string)$r->start_date,$new_end))return 'save';
            // The round's history row: what was asked, what was applied (3.31.161).
            $c=$this->leave_change_current((int)$r->id);
            if($c && in_array((string)$c->status,['Pending','Recorded'],true) && $wpdb->update($wpdb->prefix.'ews_leave_changes',['status'=>$source==='hr'?'Recorded':'Approved','applied_from'=>$eff,'days_back'=>$back,'carry_top_up'=>(float)$top,'end_after'=>$whole?null:$new_end,'decided_by'=>get_current_user_id(),'decided_at'=>$now],['id'=>(int)$c->id])===false)return 'save';
            $this->leave_cancel_outcome=$whole?'':'from:'.$eff;
            $this->leave_return_applied=['from'=>$eff,'asked'=>(string)$asked,'back'=>$back,'whole'=>$whole,'closed'=>$closed,'top'=>(float)$top];
            $this->week_lock_record_request((int)$emp->id,$dates,'leave_cancel','leave #'.(int)$r->id.($whole?'':' from '.$eff));
            $this->audit($source==='hr'?'leave_return_recorded':'leave_cancelled_from','leave_request',(int)$r->id,$r->type_name.' '.$r->start_date.' – '.$end.': '.($whole?'cancelled':'cancelled from '.$eff).', '.$back.' day(s) back'.($eff>(string)$asked?' (asked from '.$asked.'; payroll closed for '.implode(', ',$closed).')':'').($top>0?', next year +'.$top.' carried':'').($source==='hr'&&$c&&(string)$c->reason!==''?' | HR: '.wp_get_current_user()->display_name.' | reason: '.$c->reason:''));
            return true;
        }

    /** A Leave return refusal, translated (ReturnRules codes). */
    private function leave_return_message($code){
            $m=[ReturnRules::NOT_APPROVED=>__('Only an approved leave can be changed.','workforce-one'),ReturnRules::ALREADY_PENDING=>__('A change of this leave is already waiting.','workforce-one'),
                ReturnRules::DAY=>__('Pick the day you are back at work: from the first day of the leave (or today) up to its last day.','workforce-one'),ReturnRules::NO_DAYS=>__('No leave day is left from that day on.','workforce-one'),
                ReturnRules::CLOSED_PAYROLL=>__('The payroll of those days is closed, so they stay leave.','workforce-one'),ReturnRules::REASON=>__('Write the reason.','workforce-one'),
                ReturnRules::SELF=>__('You cannot record a return on your own leave. Another HR person must.','workforce-one'),ReturnRules::ENDED=>__('No leave day is left to return.','workforce-one')];
            return $m[$code]??__('The leave could not be changed.','workforce-one');
        }

    /** What the last return did (Leave return), for the messages. @var array<string,mixed> */
    private $leave_return_applied=[];

    /** @return true|string leave_error */
    private function leave_reject_cancellation($id){
            global $wpdb;
            $q=$wpdb->update($wpdb->prefix.'ews_leave_requests',['cancellation_status'=>'Rejected'],['id'=>(int)$id,'status'=>'Approved','cancellation_status'=>'Pending'],['%s'],['%d','%s','%s']);
            if($q!==1)return $q===false?'save':'done';
            if($this->leave_return_on() && !$this->leave_change_close((int)$id,'Rejected'))return 'save';   // the round's history (3.31.161)
            if($this->leave_return_on() && $this->approval_close_open('vacation','leave_cancellation',(int)$id,true)===false)return 'save';
            return true;
        }

    /** Mark an approved leave's cancellation as requested. @return int|false rows changed */
    private function leave_mark_cancellation_pending($id,$from=null){
            global $wpdb;
            // "I'm back early" (3.31.158): also the first day it cancels.
            if($from!==null)return $wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_requests SET cancellation_status='Pending',cancel_from=%s WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status='Not Requested')",(string)$from,(int)$id));
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

            $ar=null;$posted=$this->approval_posted_step();   // the level the page showed (3.31.122)
            if($this->leave_approval_active() && !$this->leave_without_levels('leave',$id)){
                $ar=$this->approval_find_request('vacation','leave',$id);
                if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))$this->leave_redirect(['leave_done'=>1]);
                if($this->approval_level_moved((int)$ar->id,$posted))$this->leave_redirect(['leave_error'=>'moved']);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif($this->approval_level_moved_for('vacation','leave',$id,$posted))$this->leave_redirect(['leave_error'=>'moved']);
            elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

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
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now,$posted){
                if($ar){
                    // The reason typed with the decision (3.31.103), needed to reject when the workflow asks for one.
                    $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),false,false,$posted);
                    if(is_wp_error($acted))return $this->approval_error_code($acted);
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
                CancellationRules::CLOSED=>'A cancellation was already asked for this leave and closed, so it cannot be asked again.',
                CancellationRules::DAY=>'Pick the day you are back at work: after the first day of the leave, from today on, up to its last day.',
            ];
            $today=current_time('Y-m-d');
            if($this->leave_return_ask_on())$this->leave_return_ask($r,$emp,$id,$today);   // Leave return (3.31.161): always redirects
            // "Change the leave" (3.31.158, Leave in My requests): the day back at work, before or during the leave; the days from it on.
            $from=null;
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
            $back=$this->my_requests_leave_on() && sanitize_key($_POST['lr_mode']??'back')!=='all'?sanitize_text_field(wp_unslash($_POST['back_on']??'')):'';
            if($back!==''){
                $from=preg_match('/^\d{4}-\d{2}-\d{2}$/',$back)?$back:'0000-00-00';
                $rule=CancellationRules::checkChange((string)$r->status,$r->cancellation_status,$r->start_date,$r->end_date,$today,$from);
            }else $rule=CancellationRules::check((string)$r->status,$r->cancellation_status,$r->start_date,$today);
            // From My requests (3.31.158) a refusal comes back there with its message; the Leave page keeps its page as before.
            $from_requests=$this->my_requests_leave_on() && $this->approvals_return_view('vacation')==='requests';
            if($rule && $from_requests)$this->leave_redirect(['leave_cancel_error'=>$rule]);
            if($rule)wp_die($messages[$rule]);

            if($this->leave_approval_active()){
                $approval=0;
                $this->leave_transaction(function()use(&$approval,$id,$emp,$from){
                    $ok=$this->leave_mark_cancellation_pending($id,$from);
                    if($ok!==1)return $ok===false?'save':'done';
                    $approval=$this->approval_start('vacation','leave_cancellation',$id,(int)$emp->id,['leave_request_id'=>$id],false);
                    if(is_wp_error($approval))return 'approval';
                    $ar=$this->approval_find_request('vacation','leave_cancellation',$id);
                    if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))return 'approval';
                    return true;
                },$from_requests?['done'=>CancellationRules::ALREADY_PENDING]:null);
                $this->leave_notify_pending_approvers($approval,'Vacation Cancellation Request',$from!==null?$emp->name.' is back at work on '.$from.' and asks to cancel '.$from.' to '.$r->end_date.'. Approval is required.':$emp->name.' requested cancellation of '.$r->start_date.' to '.$r->end_date.'. Approval is required.',$id);
                $this->leave_redirect(['leave_cancel_sent'=>1]);
            }
            $ok=$this->leave_mark_cancellation_pending($id,$from);
            if($ok!==1 && $from_requests)$this->leave_redirect(['leave_cancel_error'=>$ok===false?'save':CancellationRules::ALREADY_PENDING]);
            if($ok!==1)wp_die($ok===false?'Could not submit cancellation request. Database error.':'Cancellation request is no longer pending.');
            // With leave approvals off, the people who decide are told, as for a leave request (3.31.158, with Leave in My requests on).
            if($this->my_requests_leave_on()){
                foreach(get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]) as $m){
                    if((int)$m->ID===(int)get_current_user_id())continue;
                    $this->notify((int)$m->ID,'leave','Leave Cancellation Request',$emp->name.($from!==null?' is back at work on '.$from.' and asks to cancel '.$from:' asked to cancel '.$r->start_date).' to '.$r->end_date.'. Approval is required.',['entity_id'=>$id]);
                }
            }
            $this->leave_redirect(['leave_cancel_sent'=>1]);
        }

    /**
     * Leave return (3.31.161, owner): the employee is back at work on any day of an approved leave from today on, the first day
     * (all of it) or a single remaining day too, and may change a leave again after an approved change, a refusal or a
     * withdrawal. Each change is approved as a cancellation is. Always redirects.
     */
    private function leave_return_ask($r,$emp,$id,$today){
            global $wpdb;
            // phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by leave_cancel_request()
            $mode=sanitize_key($_POST['lr_mode']??'back');
            $posted=sanitize_text_field(wp_unslash($_POST['back_on']??''));
            // phpcs:enable WordPress.Security.NonceVerification.Missing
            $back=$mode==='all'||$posted===''?(string)$r->start_date:(preg_match('/^\d{4}-\d{2}-\d{2}$/',$posted)?$posted:'0000-00-00');
            $rule=ReturnRules::checkBack((string)$r->status,$r->cancellation_status,(string)$r->start_date,(string)$r->end_date,$today,$back,$this->leave_return_days_from($r,$back));
            $from_requests=$this->approvals_return_view('vacation')==='requests';
            if($rule){
                if($from_requests)$this->leave_redirect(['leave_cancel_error'=>$rule]);
                wp_die(esc_html($this->leave_return_message($rule)));
            }
            $levels=$this->leave_approval_active();
            $approval=0;
            $this->leave_transaction(function()use(&$approval,$id,$emp,$back,$levels){
                global $wpdb;
                $locked=$this->leave_lock_row($id);
                if(!$locked)return 'done';
                // Checked again as locked: HR may have shortened it meanwhile (review).
                if(ReturnRules::checkBack((string)$locked->status,$locked->cancellation_status,(string)$locked->start_date,(string)$locked->end_date,current_time('Y-m-d'),$back,$this->leave_return_days_from($locked,$back)))return 'done';
                $ok=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_requests SET cancellation_status='Pending',cancel_from=%s WHERE id=%d AND status='Approved' AND (cancellation_status IS NULL OR cancellation_status IN ('Not Requested','Approved','Rejected','Withdrawn','Ended'))",$back,(int)$id));
                if($ok!==1)return $ok===false?'save':'done';
                if($levels){
                    $approval=$this->approval_start('vacation','leave_cancellation',$id,(int)$emp->id,['leave_request_id'=>$id],false);
                    if(is_wp_error($approval))return 'approval';
                    $ar=$this->approval_find_request('vacation','leave_cancellation',$id);
                    if(!$ar||(int)$ar->id!==(int)$approval||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))return 'approval';
                }
                return $this->leave_change_insert($locked,'employee',$back,'',$levels?(int)$approval:0)?true:'save';
            },$from_requests?['done'=>ReturnRules::ALREADY_PENDING]:null);
            $whole=$back===(string)$r->start_date;
            $msg=$whole?$emp->name.' asked to cancel '.$r->start_date.' to '.$r->end_date.' (all of it). Approval is required.':$emp->name.' is back at work on '.$back.' and asks to cancel '.$back.' to '.$r->end_date.'. Approval is required.';
            if($levels)$this->leave_notify_pending_approvers($approval,'Vacation Cancellation Request',$msg,$id);
            else foreach(get_users(['capability'=>'ews_manage_settings','fields'=>['ID']]) as $m){
                if((int)$m->ID===(int)get_current_user_id())continue;
                $this->notify((int)$m->ID,'leave','Leave Cancellation Request',$msg,['entity_id'=>$id]);
            }
            $this->audit('leave_change_asked','leave_request',(int)$id,'back at work on '.$back.($whole?' (all of it)':''));
            $this->leave_redirect(['leave_cancel_sent'=>1]);
        }

    public function leave_cancel_respond(){
            check_admin_referer('ews_leave_cancel_respond');
            $this->ensure_leave_schema();
            $id=absint($_POST['request_id']??0);
            $decision=sanitize_key($_POST['decision']??'');

            $ar=null;$posted=$this->approval_posted_step();   // the level the page showed (3.31.122)
            if($this->leave_approval_active() && !$this->leave_without_levels('leave_cancellation',$id)){
                $ar=$this->approval_find_request('vacation','leave_cancellation',$id);
                if(!$ar||!\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status))$this->leave_redirect(['leave_done'=>1]);
                if($this->approval_level_moved((int)$ar->id,$posted))$this->leave_redirect(['leave_error'=>'moved']);
                if(!$this->approval_can_act((int)$ar->id))wp_die('Access denied');
            }elseif($this->approval_level_moved_for('vacation','leave_cancellation',$id,$posted))$this->leave_redirect(['leave_error'=>'moved']);
            elseif(!$this->can('ews_manage_settings'))wp_die('Access denied');

            if(!in_array($decision,['approve','reject'],true))wp_die('Invalid cancellation.');
            $r=$this->leave_request_row($id,"AND r.status='Approved' AND r.cancellation_status='Pending'");
            if(!$r)$this->leave_redirect(['leave_error'=>'decided']);   // decided meanwhile (QA 6, Q9): back with "already decided", not a bare page
            $emp=$this->leave_active_employee($r->employee_id);
            if(!$emp)wp_die('Employee not found.');
            if(!$ar && !$this->approval_self_gate((int)($emp->wp_user_id??0),$this->approval_self_rule('vacation')==='allow','vacation cancellation #'.(int)$id))wp_die(esc_html__('You cannot decide your own request. Another approver must.','workforce-one'),'',['response'=>403]);
            $bal=$this->leave_request_balance($r);
            if(!$bal)wp_die('Leave balance unavailable.');
            $now=current_time('mysql');

            $outcome=$decision==='approve'?'APPROVED':'REJECTED';$this->leave_cancel_outcome='';
            $this->leave_transaction(function()use(&$outcome,$ar,$decision,$id,$r,$emp,$bal,$now,$posted){
                // Leave return (3.31.161): the leave first, then its levels (the engine's lock order); a round changed meanwhile is "moved".
                if($this->leave_return_on()){
                    $locked=$this->leave_lock_row($id);
                    if(!$locked || (string)$locked->status!=='Approved' || (string)$locked->cancellation_status!=='Pending')return 'decided';
                    if(!$this->leave_round_matches($id))return 'moved';
                }
                if($ar){
                    $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),false,false,$posted);
                    if(is_wp_error($acted))return $this->approval_error_code($acted);
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
                // Shortened, or decided once it had ended (3.31.158): say what happened.
                if($outcome==='APPROVED' && strpos($this->leave_cancel_outcome,'from:')===0)$msg='Your '.$r->type_name.' leave now ends on '.gmdate('Y-m-d',strtotime(substr($this->leave_cancel_outcome,5).' -1 day')).'; the days from '.substr($this->leave_cancel_outcome,5).' on went back to your balance and schedule.';
                elseif($outcome==='APPROVED' && $this->leave_cancel_outcome==='ended')$msg='Your '.$r->type_name.' leave had ended before the cancellation was decided, so nothing changed.';
                if($outcome==='APPROVED' && !empty($this->leave_return_applied['closed_all']))$msg='The days of your '.$r->type_name.' leave you asked to cancel are in a closed payroll month, so they stay leave.';
                if($outcome==='APPROVED' && isset($this->leave_return_applied['from']) && $this->leave_return_applied['from']>$this->leave_return_applied['asked'])$msg.=' Days in a closed payroll month stay leave.';   // 3.31.161
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
            $lock=function()use($cancel,$id){
                if(!$cancel || !$this->leave_return_on())return true;   // Leave return (3.31.161): the leave first, then its levels
                $locked=$this->leave_lock_row($id);
                return $locked && (string)$locked->status==='Approved' && (string)$locked->cancellation_status==='Pending' && $this->leave_round_matches($id);
            };
            $commit=function($outcome)use($apply,$wpdb,$lock){
                if($wpdb->query('START TRANSACTION')===false)return false;
                if(!$lock()){$wpdb->query('ROLLBACK');return false;}
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
                if(!$lock()){$wpdb->query('ROLLBACK');return Hub::MOVED;}
                $acted=$this->approval_act((int)$ar->id,$decision,'',false,true,$this->approval_posted_step());
                if(is_wp_error($acted)){$wpdb->query('ROLLBACK');return Hub::fromEngineError($acted->get_error_code());}
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
            if($this->approval_level_moved_for('vacation',$entity,$id,$this->approval_posted_step()))return Hub::MOVED;   // the page showed another level (3.31.122)
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
    private function leave_approval_card($entity_type,$id,$row=null,$light=false){
            global $wpdb;
            $cancel=$entity_type==='leave_cancellation';
            $r=$row&&(int)$row->id===(int)$id?$row:$this->leave_request_row((int)$id,$cancel?"AND r.status='Approved' AND r.cancellation_status='Pending'":"AND r.status='Pending'");
            if(!$r)return null;
            if($light)return ['employee_id'=>(int)$r->employee_id,'tone'=>'leave','since'=>(string)$r->requested_at];   // the counts need no balance, no one away (3.31.148)
            $days=(float)$r->requested_days;
            // A change (3.31.158): the days from the day back at work, or, decided once the leave started, from today; not the whole leave.
            $back_from=$cancel?(string)$this->leave_cancel_from_day($r):'';
            $back_days=$back_from!==''?($back_from>(string)$r->end_date?0.0:min($days,(float)$this->vacation_working_days_count($back_from,(string)$r->end_date))):null;
            // Leave return (3.31.161): the days actually returned, from the leave's days, after the closed payroll months.
            $kept_until='';$round=null;
            if($cancel && $this->leave_return_on()){
                $asked=$this->leave_return_asked_day($r);
                $eff=ReturnRules::effectiveFrom($asked,(string)$r->end_date,$this->payroll_closed_months($asked,(string)$r->end_date));
                $all=$this->leave_days_from((int)$r->id,'0000-00-00');$n=$eff===null?0:$this->leave_days_from((int)$r->id,$eff);
                $back_from=$eff===null?$asked:$eff;
                $back_days=$eff===null?0.0:($all>0&&$n>=$all?$days:min($days,(float)($all>0?$n:$this->vacation_working_days_count($eff,(string)$r->end_date))));
                if($eff===null||$eff>$asked)$kept_until=$eff===null?(string)$r->end_date:gmdate('Y-m-d',strtotime($eff.' -1 day'));
                $c=$this->leave_change_current((int)$r->id);
                if($c)$round=['id'=>(int)$c->id,'n'=>(int)$c->round];
            }
            $bal=$this->leave_request_balance($r);
            $left=$bal?Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending)+($cancel?($back_days??$days):0):null;
            $who=$this->people_employee((int)$r->employee_id);$dept=$who?(int)($who->department_id??0):0;
            $away=null;
            if($dept){
                $ids=array_values(array_diff($this->people_department_member_ids($dept),[(int)$r->employee_id]));
                $away=$ids?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT employee_id) FROM {$wpdb->prefix}ews_leave_requests WHERE employee_id IN (".implode(',',array_fill(0,count($ids),'%d')).") AND status='Approved' AND start_date<=%s AND end_date>=%s",array_merge($ids,[$r->end_date,$r->start_date]))):0;
            }
            /* translators: %s: number of days */
            $days_txt=sprintf(_n('%s day','%s days',(int)ceil($days),'workforce-one'),number_format_i18n($days,floor($days)==$days?0:1));
            $facts=[[__('Days','workforce-one'),$days_txt],[__('Dates','workforce-one'),$this->leave_day_range($r->start_date,$r->end_date)]];
            if($back_days!==null)$facts[]=[!empty($r->cancel_from)?__('Back at work on','workforce-one'):__('Cancelled from','workforce-one'),date_i18n('D j M',strtotime($back_from)).' · '.sprintf(/* translators: %s: number of days */_n('%s day back','%s days back',(int)ceil($back_days),'workforce-one'),number_format_i18n($back_days))];
            if($kept_until!=='')$facts[]=[__('Payroll closed','workforce-one'),sprintf(/* translators: %s: a day */__('The days up to %s stay leave: their payroll month is closed.','workforce-one'),date_i18n('D j M',strtotime($kept_until)))];
            if($round && $round['n']>1)$facts[]=[__('Change','workforce-one'),sprintf(/* translators: %d: how many times the leave was changed */__('Change %d of this leave','workforce-one'),$round['n'])];
            if($left!==null && (int)$r->deduct_balance)$facts[]=[$cancel?__('Balance after cancelling','workforce-one'):__('Balance after','workforce-one'),number_format_i18n($left,floor($left)==$left?0:1)];
            if($away!==null)$facts[]=[__('Others in the department away','workforce-one'),number_format_i18n($away)];
            if(trim((string)$r->reason)!=='')$facts[]=[__('Reason','workforce-one'),(string)$r->reason];
            return ['employee_id'=>(int)$r->employee_id,'tone'=>'leave','kind'=>$cancel?__('Leave cancellation','workforce-one'):__('Leave','workforce-one'),
                'what'=>$r->type_name.' · '.$days_txt.' · '.$this->leave_day_range($r->start_date,$r->end_date),'facts'=>$facts,'since'=>(string)$r->requested_at,
                'action'=>$cancel?'ews_leave_cancel_respond':'ews_vacation_request_respond','nonce'=>$cancel?'ews_leave_cancel_respond':'ews_vacation_respond','fields'=>['request_id'=>(int)$id]+($round?['round'=>$round['id']]:[]),
                // The office minimum warning, as on the Leave page (3.31.158, with Leave in My requests on).
                'om'=>!$cancel && $this->my_requests_leave_on()?$this->om_leave_impact((int)$r->employee_id,(string)$r->start_date,(string)$r->end_date):null];
        }

    /**
     * Leave requests and cancellations waiting while leave approvals are off, for My approvals (QA 6,
     * docs/tasks/legacy-approval-cards.md): Manage Settings decides them today, through the same handlers. A cancellation has
     * no "asked on" time, so its card shows no waiting time. @return list<array<string,mixed>>
     */
    private function leave_legacy_approvals(){
            if(!$this->can('ews_manage_settings'))return [];
            $active=$this->leave_approval_active();
            // With approvals on, only what was asked while they were off (no levels: 3.31.158, with Leave in My requests on).
            if($active && !$this->my_requests_leave_on())return [];
            global $wpdb;$this->ensure_leave_schema();
            $allow=$this->approval_self_rule('vacation')==='allow';
            $out=[];
            $rows=(array)$wpdb->get_results("SELECT r.*,lt.name type_name,lt.deduct_balance FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.status='Pending' OR (r.status='Approved' AND r.cancellation_status='Pending') ORDER BY r.requested_at ASC,r.id ASC LIMIT 200");
            // With approvals on: which of them have their levels, in one read (3.31.158).
            $levels=$active && $rows?$this->approval_find_requests(array_map(function($x){return ['vacation',$x->status!=='Pending'?'leave_cancellation':'leave',(int)$x->id];},$rows)):[];
            foreach($rows as $r){
                $cancel=$r->status!=='Pending';$type=$cancel?'leave_cancellation':'leave';
                if($active && isset($levels[$this->approval_request_key('vacation',$type,(int)$r->id)]) && !($cancel && $this->leave_return_on() && $this->leave_without_levels($type,(int)$r->id)))continue;   // it has its levels (per round with Leave return, 3.31.161)
                // The card (balance, others away) is built only where it is shown; the counts need the row alone.
                $out[]=['key'=>['vacation',$type,(int)$r->id],'employee_id'=>(int)$r->employee_id,'tone'=>'leave','since'=>(string)$r->requested_at,'wait'=>!$cancel,
                    'make'=>function()use($type,$r,$cancel){$c=$this->leave_approval_card($type,(int)$r->id,$r);if($c && $cancel)$c['wait']=false;return $c;},
                    'allow'=>$allow,'active'=>true,'who'=>__('Anyone who can manage settings','workforce-one'),
                    'why'=>$active?__('Asked while leave approvals were off, so it has no levels: the administrators decide it','workforce-one'):($cancel?__('Leave approvals are off, so the cancellation is decided as before','workforce-one'):__('Leave approvals are off, so it is decided as before','workforce-one'))];
            }
            return $out;
        }

    /**
     * An employee's leave requests for My requests, newest first. @return list<array<string,mixed>>
     */
    private function leave_my_requests($employee_id,$limit=20,$offset=0){
            global $wpdb;$this->ensure_leave_schema();
            $rows=(array)$wpdb->get_results($offset?$wpdb->prepare("SELECT r.*,lt.name type_name FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC,r.id DESC LIMIT %d OFFSET %d",(int)$employee_id,(int)$limit,(int)$offset)
                :$wpdb->prepare("SELECT r.*,lt.name type_name FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC,r.id DESC LIMIT %d",(int)$employee_id,(int)$limit));
            $out=[];
            // Leave in My requests (3.31.158): asking to cancel an approved leave, and what became of it.
            $more=$this->my_requests_leave_on();$today=current_time('Y-m-d');
            foreach($rows as $r){
                if($more){$out[]=$this->leave_my_request_item($r,$today);continue;}
                $cancel=$r->status==='Approved' && $r->cancellation_status==='Pending';
                // Cancelled before it was decided = withdrawn by the employee (3.31.103); after approval = cancelled.
                $state=$cancel?'waiting':(['Pending'=>'waiting','Approved'=>'approved','Rejected'=>'rejected'][$r->status]??($r->status==='Cancelled' && $r->cancellation_status!=='Approved'?'withdrawn':'cancelled'));
                $out[]=['key'=>'leave-'.(int)$r->id,'tone'=>'leave','title'=>$r->type_name.' · '.$this->leave_day_range($r->start_date,$r->end_date),
                    'sub'=>$cancel?__('Cancellation asked','workforce-one'):'','state'=>$state,'when'=>(string)$r->requested_at,
                    'approval'=>['vacation',$cancel?'leave_cancellation':'leave',(int)$r->id],'withdraw'=>$r->status==='Pending'?'leave':'','id'=>(int)$r->id];
            }
            return $out;
        }

    /** One leave request in My requests with Leave in My requests on (3.31.158). @return array<string,mixed> */
    private function leave_my_request_item($r,$today){
            $s=\WorkforceOne\Leave\MyRequestsLeave::itemState((string)$r->status,$r->cancellation_status??null,$r->start_date??null,$r->end_date??null,$today);
            $cancel_path=$s['cancel_asked'] || $s['cancel_decided']!=='';
            $partly=$s['cancel_decided']==='approved' && (string)$r->status==='Approved';   // cancelled from a day on: it ends earlier
            $sub=$s['cancel_asked']?__('Cancellation asked','workforce-one'):($partly?__('Shortened at your request','workforce-one'):($s['cancel_decided']==='approved'?__('Cancelled at your request','workforce-one'):''));
            $note='';
            $cf=(string)($r->cancel_from??'');
            if($s['cancel_asked'] && $cf!=='')$note=sprintf(/* translators: %s: a day */__('Back at work on %s: the days from then on are cancelled once it is approved; until then the leave stands.','workforce-one'),date_i18n('D j M',strtotime($cf)));
            elseif($s['cancel_asked'])$note=__('The leave stands until the cancellation is approved.','workforce-one');
            elseif($s['cancel_decided']==='rejected')$note=__('Cancelling it was refused, so it cannot be asked again.','workforce-one');
            elseif($s['cancel_decided']==='withdrawn')$note=__('You withdrew the cancellation, so the leave stands.','workforce-one');
            elseif($s['cancel_decided']==='ended')$note=__('It was decided after the leave had ended, so nothing was cancelled.','workforce-one');
            elseif($partly)$note=__('The cancellation was approved after the leave started: the days from that day on were cancelled and went back to your balance.','workforce-one');
            elseif($s['started'] && \WorkforceOne\Leave\CancellationRules::firstBackDay($r->start_date??null,$r->end_date??null,$today)===null && $s['cancel_decided']==='' && (string)$r->end_date>=$today)$note=__('It has started, so it can no longer be cancelled.','workforce-one');
            elseif($s['cancel_decided']==='approved')$note=sprintf(/* translators: %s: number of days */_n('%s day went back to your balance.','%s days went back to your balance.',(int)ceil((float)$r->requested_days),'workforce-one'),\WorkforceOne\Support\Format::number((float)$r->requested_days));
            // Leave return (3.31.161): a leave can be changed again; a return HR recorded says so, with its reason.
            if($this->leave_return_ask_on() && !$s['cancel_asked']){
                $c=$this->leave_change_current((int)$r->id);
                $open=(string)$r->status==='Approved' && ReturnRules::backRange((string)$r->start_date,(string)$r->end_date,$today)!==null;
                if($c && (string)$c->source==='hr' && (string)$c->status==='Recorded'){
                    $sub=__('Back at work, recorded by HR','workforce-one');
                    $note=sprintf(/* translators: 1: a day, 2: number of days, 3: the reason HR wrote */__('HR recorded that you were back at work on %1$s: %2$s day(s) went back to your balance. Reason: %3$s','workforce-one'),date_i18n('D j M',strtotime((string)$c->applied_from)),\WorkforceOne\Support\Format::number((float)$c->days_back),(string)$c->reason);
                }elseif($s['cancel_decided']==='rejected')$note=$open?__('Cancelling it was refused. You can ask again.','workforce-one'):__('Cancelling it was refused.','workforce-one');
                elseif($s['cancel_decided']==='withdrawn' && $open)$note=__('You withdrew the change, so the leave stands. You can ask again.','workforce-one');
                elseif($partly && $open)$note=__('Shortened at your request: the days from then on went back to your balance. You can change it again.','workforce-one');
                if($c && $c->status==='Approved' && (float)$c->days_back>0 && $c->applied_from && $c->applied_from>$c->asked_from)$note.=' '.__('Days in a closed payroll month stayed leave.','workforce-one');
            }
            return ['key'=>'leave-'.(int)$r->id,'tone'=>'leave','title'=>$r->type_name.' · '.$this->leave_day_range($r->start_date,$r->end_date),
                'sub'=>$sub,'state'=>$s['state'],'state_label'=>$s['cancel_asked']?__('Cancellation waiting','workforce-one'):'','note'=>$note,'when'=>(string)$r->requested_at,
                'approval'=>['vacation',$cancel_path&&!$this->leave_round_has_approval((int)$r->id)?'leave_round':($cancel_path?'leave_cancellation':'leave'),(int)$r->id],'withdraw'=>$r->status==='Pending'?'leave':($s['cancel_asked']?'leave_cancel':''),'cancel'=>$s['cancellable'],'id'=>(int)$r->id,
                // "Change the leave" (3.31.158, owner): cancel all of it before it starts, or the day back at work (today or later).
                'change'=>$this->leave_change_offer($r,$s,$today),'again'=>$this->leave_return_ask_on()];
        }

    /**
     * What "Change the leave" offers on an approved leave (3.31.158), or null: whole = it has not started (cancel all of it);
     * min / max = the days the employee may say they are back at work (after the first day, from today, up to the last).
     * @param array<string,mixed> $s MyRequestsLeave::itemState()
     * @return array<string,mixed>|null
     */
    private function leave_change_offer($r,$s,$today){
            // Leave return (3.31.161, owner): any day from the first (or today) to the last, again after a change, a refusal or a withdrawal.
            if($this->leave_return_ask_on()){
                $range=ReturnRules::backRange((string)($r->start_date??''),(string)($r->end_date??''),$today);
                if(!$range || (string)$r->status!=='Approved' || !in_array(strtolower(trim((string)($r->cancellation_status??''))),ReturnRules::ASK_AGAIN,true))return null;
                if($this->leave_days_from((int)$r->id,$range['min'])<1 && $this->leave_days_from((int)$r->id,'0000-00-00')>0)return null;   // no leave day left
                // "All of it" = back on the first day; the other option starts the day after it (or today once it has started).
                $next=gmdate('Y-m-d',strtotime((string)$r->start_date.' +1 day'));
                $min=$range['whole']?($next<=$range['max']?($next>$today?$next:$today):''):$range['min'];
                return ['whole'=>$range['whole'],'min'=>$min,'max'=>$range['max'],'start'=>(string)$r->start_date,'days'=>(float)$r->requested_days,'again'=>true];
            }
            $first=CancellationRules::firstBackDay($r->start_date??null,$r->end_date??null,$today);
            $back=$first!==null && CancellationRules::checkChange((string)$r->status,$r->cancellation_status??null,$r->start_date??null,$r->end_date??null,$today,$first)===null;
            if(!$s['cancellable'] && !$back)return null;
            return ['whole'=>(bool)$s['cancellable'],'min'=>$back?$first:'','max'=>(string)$r->end_date,'start'=>(string)$r->start_date,'days'=>(float)$r->requested_days];
        }

    /** The employee withdraws a leave still waiting (My requests): its days are no longer reserved. @return true|string */
    /**
     * The employee withdraws a cancellation still waiting (My requests, 3.31.158): the leave stays as it was. A leave has one
     * cancellation (its approval exists once), so it cannot be asked again. @return true|string
     */
    private function leave_cancel_withdraw($id,$employee_id){
            global $wpdb;
            if($wpdb->query('START TRANSACTION')===false)return 'save';
            $ok=$wpdb->update($wpdb->prefix.'ews_leave_requests',['cancellation_status'=>'Withdrawn'],['id'=>(int)$id,'employee_id'=>(int)$employee_id,'status'=>'Approved','cancellation_status'=>'Pending'],['%s'],['%d','%d','%s','%s']);
            if($ok!==1){$wpdb->query('ROLLBACK');return $ok===false?'save':'done';}
            $closed=$this->approval_close('vacation','leave_cancellation',(int)$id);
            if($closed!==true){
                $wpdb->query('ROLLBACK');
                if($closed!==false)return 'done';   // 'moved': decided meanwhile
                $ar=$this->approval_find_request('vacation','leave_cancellation',(int)$id);
                return $ar && !\WorkforceOne\Approvals\StateMachine::isWaitingForLevel((string)$ar->status)?'done':'save';
            }
            if($this->leave_return_on() && !$this->leave_change_close((int)$id,'Withdrawn')){$wpdb->query('ROLLBACK');return 'save';}   // 3.31.161
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
            $this->audit('leave_cancellation_withdrawn','leave_request',(int)$id,'cancellation withdrawn');
            return true;
        }

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
