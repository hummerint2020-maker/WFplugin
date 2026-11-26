<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\AdminRecordRules;
use WorkforceOne\Leave\Balance;

/**
 * wp-admin "Leaves" page: leave types, recording an approved leave for an employee, and annual
 * balances. Recording reuses the Leave module operations (includes/trait-leave.php), so the days
 * are reserved on the leave year's balance and then approved exactly like an employee request.
 * Rules: src/Leave/AdminRecordRules.php. Behaviour: tests/e2e_leave_admin.py.
 */
trait EWS_Leave_Admin_Trait {

    private function leave_admin_redirect($args=[]){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-leaves')));
        exit;
    }

    public function admin_leaves(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $this->ensure_leave_schema();
        global $wpdb;
        // Leave return (3.31.161): two more tabs, only with the switch on.
        $tab=sanitize_key($_GET['tab']??'');
        if(($tab==='return'||$tab==='changed') && $this->leave_return_on()){echo $this->render_template('admin/leave-return',$this->leave_admin_return_view($tab));return;}
        // Company holidays are not leave days (3.31.162): its tab, only with the switch on.
        if($tab==='holidays' && $this->leave_holidays_on()){echo $this->render_template('admin/leave-holidays',$this->leave_admin_holidays_view());return;}
        $year=$this->leave_year();
        $years=AdminRecordRules::balanceYears($year);
        $shown_year=absint($_GET['balance_year']??$year);
        if(!in_array($shown_year,$years,true))$shown_year=$year;
        $balances=$wpdb->get_results($wpdb->prepare(
            "SELECT b.*,e.name employee_name,t.name type_name FROM {$wpdb->prefix}ews_leave_balances b
             JOIN {$this->employees} e ON e.id=b.employee_id AND e.active=1
             JOIN {$wpdb->prefix}ews_leave_types t ON t.id=b.leave_type_id
             WHERE b.leave_year=%d ORDER BY e.name ASC,t.name ASC",$shown_year));
        $balance_rows=[];
        foreach((array)$balances as $b){
            $balance_rows[]=['employee'=>$b->employee_name,'type'=>$b->type_name,'entitlement'=>(float)$b->entitlement,'used'=>(float)$b->used,'pending'=>(float)$b->pending,
                'remaining'=>Balance::remaining((float)$b->entitlement,(float)$b->used,(float)$b->pending)];
        }
        echo $this->render_template('admin/leaves',[
            'types'=>(array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types ORDER BY id ASC"),
            'employees'=>$this->people_active_list(),
            'year'=>$year,'years'=>$years,'shown_year'=>$shown_year,'balances'=>$balance_rows,
            'saved'=>isset($_GET['leave_admin_saved']),'recorded'=>isset($_GET['leave_recorded']),
            'record_error'=>isset($_GET['leave_record_error'])?sanitize_text_field(wp_unslash($_GET['leave_record_error'])):'',
            'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-leaves'),
            'tabs'=>$this->leave_return_on(),'holiday_tab'=>$this->leave_holidays_on(),
        ]);
    }

    /**
     * Records an approved leave directly. The date can be in the past. The days are reserved on
     * the balance of the leave's year and then approved, like an employee request, so other
     * pending reservations, the charged year and later cancellation all stay consistent.
     */
    public function admin_admin_leave_record(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_admin_leave_record');
        global $wpdb;
        $this->ensure_leave_schema();
        $employee_id=absint($_POST['employee_id']??0);
        $leave_type_id=absint($_POST['leave_type_id']??0);
        $start=sanitize_text_field(wp_unslash($_POST['start_date']??''));
        $end=sanitize_text_field(wp_unslash($_POST['end_date']??''));
        $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
        $fail=function($code,$remaining=0){$this->leave_admin_redirect(['leave_record_error'=>rawurlencode(AdminRecordRules::message($code,$remaining))]);};

        $facts=['start_valid'=>$employee_id&&$this->valid_date($start),'end_valid'=>$this->valid_date($end),'start'=>$start,'end'=>$end];
        if($error=AdminRecordRules::check($facts))$fail($error);
        $emp=$this->leave_active_employee($employee_id);
        $type=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE id=%d AND active=1 LIMIT 1",$leave_type_id));
        $facts+=['employee_found'=>(bool)$emp,'type_found'=>(bool)$type];
        if($error=AdminRecordRules::check($facts))$fail($error);
        $days=$this->vacation_working_days_count($start,$end);
        $bal=$this->ensure_leave_balance($employee_id,$leave_type_id,\WorkforceOne\Leave\RequestRules::year($start));
        if(!$bal)$this->leave_admin_redirect(['leave_record_error'=>rawurlencode('Could not initialize the employee leave balance.')]);
        $remaining=Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending);
        $facts+=['working_days'=>$days,'overlaps'=>$this->leave_overlap($employee_id,$start,$end),'deducts'=>(bool)(int)$type->deduct_balance,'remaining'=>$remaining];
        if($error=AdminRecordRules::check($facts))$fail($error,max(0,$remaining));

        $now=current_time('mysql');
        // Under the employee's leave lock, as an employee's own request (3.31.91).
        [$error,$id]=$this->ews_with_lock('leave_emp_'.$employee_id,function()use($wpdb,$emp,$type,$start,$end,$days,$reason,$bal,$now,$remaining){return $this->leave_admin_record_locked($wpdb,$emp,$type,$start,$end,$days,$reason,$bal,$now,$remaining);},['Another leave of this employee is being saved. Try again in a moment.',0]);
        if($error)$this->leave_admin_redirect(['leave_record_error'=>rawurlencode($error)]);
        $this->audit('admin_leave_record','leave_request',$id,$emp->name.' / '.$type->name.' / '.$start.' to '.$end.' / '.$days.' working day(s) / direct Admin approval');
        $this->leave_admin_redirect(['leave_recorded'=>1]);
    }

    /** @return array{0:string,1:int} [error message ('' = saved), request id] */
    private function leave_admin_record_locked($wpdb,$emp,$type,$start,$end,$days,$reason,$bal,$now,$remaining){
        $error='';$id=0;
        if($wpdb->query('START TRANSACTION')===false)$error='Could not start the database transaction.';
        if(!$error){
            $id=$this->leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal);
            if(!is_int($id)){$error=$id==='overlap'||$id==='balance'?AdminRecordRules::message($id,max(0,$remaining)):'Could not create the leave record.';$id=0;}
        }
        if(!$error){
            // Direct Admin recording is an immediate approval through the normal final-approval path.
            $final=$this->leave_apply_final_approval($this->leave_request_row($id),$emp,$bal,get_current_user_id(),$now);
            if(is_wp_error($final))$error=$final->get_error_message();
        }
        if(!$error && $wpdb->query('COMMIT')===false)$error='Could not commit the leave record.';
        if($error)$wpdb->query('ROLLBACK');
        return [$error,(int)$id];
    }

    public function admin_leave_type_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_type_save');
        global $wpdb;$this->ensure_leave_schema();
        $table=$wpdb->prefix.'ews_leave_types';
        $id=absint($_POST['id']??0);$name=trim(sanitize_text_field(wp_unslash($_POST['name']??'')));
        $ent=max(0,(float)($_POST['annual_entitlement']??0));$ded=!empty($_POST['deduct_balance'])?1:0;$active=!empty($_POST['active'])?1:0;
        // Share of a day's pay kept on this leave (Payroll); a form without the field keeps the saved value.
        $paid=isset($_POST['paid_percent'])?max(0,min(100,(int)$_POST['paid_percent'])):null;
        // Planned / unplanned and the year-end carry-over (3.31.99); a form without them keeps the saved values.
        $rules=isset($_POST['kind'])?\WorkforceOne\Leave\TypeRules::fromPost(wp_unslash($_POST)):null;
        if($name==='')wp_die('Leave Type name is required.');
        if($id){
            if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",$id)))wp_die('Leave Type not found.');
            $wpdb->update($table,['name'=>$name,'annual_entitlement'=>$ent,'deduct_balance'=>$ded,'active'=>$active]+($paid===null?[]:['paid_percent'=>$paid])+($rules??[]),['id'=>$id]);
        }else{
            $wpdb->insert($table,['name'=>$name,'annual_entitlement'=>$ent,'deduct_balance'=>$ded,'active'=>1,'paid_percent'=>$paid??100]+($rules??[]));
            $id=(int)$wpdb->insert_id;
        }
        $this->audit('leave_type_update','leave_type',$id,$name.($rules?' · '.$rules['kind'].($rules['kind']==='unplanned'?' up to '.$rules['after_days'].' day(s) after':'').' · carry '.$rules['carry_mode'].($rules['carry_mode']==='max'?' '.$rules['carry_max']:''):''));
        $this->leave_admin_redirect(['leave_admin_saved'=>1]);
    }

    public function admin_leave_balance_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_balance_save');
        global $wpdb;$this->ensure_leave_schema();
        $eid=absint($_POST['employee_id']??0);$tid=absint($_POST['leave_type_id']??0);$ent=max(0,(float)($_POST['entitlement']??0));
        $y=absint($_POST['leave_year']??$this->leave_year());
        if(!in_array($y,AdminRecordRules::balanceYears($this->leave_year()),true))wp_die('Invalid leave year.');
        if(!$this->leave_active_employee($eid))wp_die('Employee not found.');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_leave_types WHERE id=%d",$tid)))wp_die('Leave Type not found.');
        $table=$wpdb->prefix.'ews_leave_balances';
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d",$eid,$tid,$y));
        if($r)$wpdb->update($table,['entitlement'=>$ent,'updated_at'=>current_time('mysql')],['id'=>$r->id],['%f','%s'],['%d']);
        else $wpdb->insert($table,['employee_id'=>$eid,'leave_type_id'=>$tid,'leave_year'=>$y,'entitlement'=>$ent],['%d','%d','%d','%f']);
        $this->audit('leave_balance_update','leave_balance',$r?$r->id:$wpdb->insert_id,'Annual entitlement set to '.$ent.' for '.$y);
        $this->leave_admin_redirect(['leave_admin_saved'=>1,'balance_year'=>$y]);
    }

    /* ------------------------------------------------------------------ Leave return (3.31.161, docs/tasks/leave-return.md §4.4, §4.6) */

    /** The error messages of the HR pages (ReturnRules codes and the handlers' own). */
    private function leave_admin_return_error($code){
        $m=['changed'=>__('The leave changed since the preview. Check it again.','workforce-one'),'moved'=>__('The employee\'s change was decided meanwhile. Check the leave again.','workforce-one'),
            'busy'=>__('Another leave of this employee is being saved. Try again in a moment.','workforce-one'),'save'=>__('Could not save. Nothing was changed.','workforce-one'),
            'done'=>__('The leave is no longer approved.','workforce-one'),'snapshot'=>__('This day is no longer a leave day.','workforce-one'),'kept'=>__('This day is already leave in the schedule.','workforce-one')];
        return $m[$code]??$this->leave_return_message($code);
    }


    /** The data of the tabs "Back at work" and "Changed leave days". @return array<string,mixed> */
    private function leave_admin_return_view($tab){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters and a preview
        $base=['tab'=>$tab,'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-leaves'),'employees'=>$this->people_active_list(),
            'error'=>isset($_GET['lr_error'])?$this->leave_admin_return_error(sanitize_key($_GET['lr_error'])):'','done'=>isset($_GET['lr_done'])?sanitize_key($_GET['lr_done']):'','holiday_tab'=>$this->leave_holidays_on()];
        $today=current_time('Y-m-d');
        if($tab==='changed'){
            $from=sanitize_text_field(wp_unslash($_GET['from']??''));$to=sanitize_text_field(wp_unslash($_GET['to']??''));
            if(!$this->valid_date($from))$from=gmdate('Y-m-d',strtotime($today.' -3 months'));
            if(!$this->valid_date($to))$to=$today;
            $emp=absint($_GET['employee']??0);$pg=max(1,absint($_GET['paged']??1));
            $res=$this->services()->leaveChangedDays()->page($from,$to,$today,$emp,50,($pg-1)*50);
            $ids=array_values(array_unique(array_map(function($x){return (int)$x->employee_id;},$res['rows'])));
            $events=[];
            if($res['rows']){$ds=array_map(function($x){return (string)$x->work_date;},$res['rows']);[,$events]=$this->insights_load($ids,min($ds),max($ds));}
            $closed=$res['rows']?$this->payroll_closed_months(min(array_map(function($x){return (string)$x->work_date;},$res['rows'])),$today):[];
            $rows=[];
            foreach($res['rows'] as $x){
                $ev=$events[(int)$x->employee_id][(string)$x->work_date]??[];
                $by=(int)$x->updated_by?get_userdata((int)$x->updated_by):null;
                $rows[]=['snap_id'=>(int)$x->snap_id,'employee_id'=>(int)$x->employee_id,'employee'=>(string)$x->employee_name,'leave_id'=>(int)$x->leave_id,'type'=>(string)$x->type_name,
                    'start'=>(string)$x->start_date,'end'=>(string)$x->end_date,'day'=>(string)$x->work_date,'status'=>$x->status!==null?(string)$x->status:'',
                    'by'=>$by?(string)$by->display_name:'','at'=>(string)($x->updated_at??''),
                    'in'=>isset($ev['sign_in'])?substr((string)$ev['sign_in']->event_at,11,5):'','out'=>isset($ev['sign_out'])?substr((string)$ev['sign_out']->event_at,11,5):'',
                    'closed'=>in_array(substr((string)$x->work_date,0,7),$closed,true)];
            }
            // phpcs:enable
            return $base+['rows'=>$rows,'total'=>$res['total'],'paged'=>$pg,'pages'=>max(1,(int)ceil($res['total']/50)),'from'=>$from,'to'=>$to,'employee'=>$emp];
        }
        $emp=absint($_GET['lr_emp']??0);$lid=absint($_GET['lr_leave']??0);
        $back=sanitize_text_field(wp_unslash($_GET['lr_back']??''));$reason=trim(sanitize_textarea_field(wp_unslash($_GET['lr_reason']??'')));
        // phpcs:enable
        $leaves=[];
        if($emp)$leaves=$this->services()->leaveReturns()->approvedLeaves($emp);
        $preview=null;$check=null;$r=null;
        if($lid){
            $r=$this->leave_request_row($lid,'AND r.employee_id='.(int)$emp);
            if($r && $this->valid_date($back)){
                $preview=$this->services()->leaveReturns()->preview($r,$back);
                $check=\WorkforceOne\Leave\ReturnRules::hrCheck(['reason'=>$reason,'self'=>$this->leave_admin_is_self($r),'status'=>(string)$r->status,'start'=>(string)$r->start_date,'end'=>(string)$r->end_date,'back'=>$back,'effective'=>$preview['effective']]);
                if(!$check && $preview['days']<1)$check=\WorkforceOne\Leave\ReturnRules::ENDED;
            }
        }
        return $base+['emp'=>$emp,'leaves'=>$leaves,'leave'=>$r,'back'=>$back,'reason'=>$reason,'preview'=>$preview,'check'=>$check?$this->leave_admin_return_error($check):''];
    }

    /** HR's own leave (owner: another HR person records it). */
    private function leave_admin_is_self($r){
        return (int)$this->people_user_id((int)$r->employee_id)===(int)get_current_user_id();
    }

    /**
     * HR records that the employee was back at work on a day (owner, 2026-11-24: a reason is required, no further approval, not on
     * HR's own leave; days in a closed payroll month stay leave). The days from then on go back; a change still waiting is closed.
     */
    public function admin_leave_return_record(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_return_record');
        $this->ensure_leave_schema();
        if(!$this->leave_return_on())wp_die(esc_html__('Leave return is off.','workforce-one'));
        $id=absint($_POST['leave_id']??0);
        $back=sanitize_text_field(wp_unslash($_POST['back_on']??''));
        $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
        $exp_from=sanitize_text_field(wp_unslash($_POST['expect_from']??''));$exp_days=absint($_POST['expect_days']??0);
        $r=$this->leave_request_row($id);
        if(!$r)wp_die('Leave request not found.');
        $again=['tab'=>'return','lr_emp'=>(int)$r->employee_id,'lr_leave'=>$id,'lr_back'=>$back,'lr_reason'=>rawurlencode($reason)];
        $preview=$this->services()->leaveReturns()->preview($r,$this->valid_date($back)?$back:'0000-00-00');
        $code=\WorkforceOne\Leave\ReturnRules::hrCheck(['reason'=>$reason,'self'=>$this->leave_admin_is_self($r),'status'=>(string)$r->status,'start'=>(string)$r->start_date,'end'=>(string)$r->end_date,'back'=>$back,'effective'=>$preview['effective']]);
        if(!$code && $preview['days']<1)$code=\WorkforceOne\Leave\ReturnRules::ENDED;
        if($code)$this->leave_admin_redirect($again+['lr_error'=>$code]);
        $emp=$this->people_employee((int)$r->employee_id);
        if(!$emp)wp_die('Employee not found.');
        // The return, under the employee's lock and in its own transaction (LeaveReturnService, 3.31.164).
        $o=$this->services()->leaveReturns()->recordReturn($id,$emp,$back,$reason,current_time('mysql'),$exp_from,$exp_days);
        if($o['outcome']!==null)$this->leave_cancel_outcome=$o['outcome'];   // kept as before, also when it failed
        if($o['applied']!==null)$this->leave_return_applied=$o['applied'];
        if($o['result']!==true)$this->leave_admin_redirect($again+['lr_error'=>(string)$o['result']]);
        $closed_round=$o['closed_round'];
        $a=(array)$this->leave_return_applied;
        $days=rtrim(rtrim(number_format((float)($a['back']??0),2,'.',''),'0'),'.');
        /* translators: 1: the day back at work, 2: what happened to the leave (a sentence), 3: HR's reason */
        $msg=sprintf(__('HR recorded that you were back at work on %1$s. %2$s Reason: %3$s','workforce-one'),$back,
            !empty($a['whole'])
                /* translators: 1: leave type, 2: number of days */
                ?sprintf(__('Your %1$s leave was cancelled and %2$s day(s) went back to your balance.','workforce-one'),$r->type_name,$days)
                /* translators: 1: leave type, 2: new last day, 3: number of days, 4: first day given back */
                :sprintf(__('Your %1$s leave now ends on %2$s; %3$s day(s) from %4$s on went back to your balance.','workforce-one'),$r->type_name,gmdate('Y-m-d',strtotime((string)$a['from'].' -1 day')),$days,$a['from']),$reason);
        if($closed_round)$msg.=' '.__('Your change request was closed because HR recorded your return.','workforce-one');
        if($emp->wp_user_id)$this->notify((int)$emp->wp_user_id,'leave',__('Back at work recorded','workforce-one'),$msg,['entity_id'=>$id]);
        $mgr=(int)$this->people_manager_of((int)$emp->id);
        $mgr_user=$mgr?(int)$this->people_user_id($mgr,true):0;
        if($mgr_user && $mgr_user!==(int)get_current_user_id())$this->notify($mgr_user,'leave',__('Back at work recorded','workforce-one'),
            /* translators: 1: employee name, 2: the day back at work, 3: number of days, 4: leave type */
            sprintf(__('HR recorded that %1$s was back at work on %2$s: %3$s day(s) of %4$s went back to the balance.','workforce-one'),$emp->name,$back,$days,$r->type_name),['entity_id'=>$id]);
        $this->leave_admin_redirect(['tab'=>'return','lr_emp'=>(int)$emp->id,'lr_done'=>'recorded']);
    }


    /**
     * "Put the day back to leave" (the report, owner 2026-11-24): a past day of an approved leave that a manager changed is leave
     * again in the schedule. Open payroll months only; the leave and the balance do not change (the day is still charged).
     */
    public function admin_leave_day_restore(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_day_restore');
        $this->ensure_leave_schema();
        if(!$this->leave_return_on())wp_die(esc_html__('Leave return is off.','workforce-one'));
        $sid=absint($_POST['snap_id']??0);
        $keep=['tab'=>'changed'];
        foreach(['from','to','employee','paged'] as $k)if(isset($_POST[$k]))$keep[$k]=sanitize_text_field(wp_unslash($_POST[$k]));
        $result=$this->services()->leaveReturns()->putBackDay($sid);   // LeaveReturnService (3.31.164)
        $this->leave_admin_redirect($keep+($result===true?['lr_done'=>'restored']:['lr_error'=>(string)$result]));
    }
}
