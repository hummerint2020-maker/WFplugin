<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\Advances;
use WorkforceOne\Approvals\Workflows;

/**
 * Salary advances (3.31.109, approvals stage 5; owner decisions 2026-10-17, docs/tasks/approvals.md,
 * mockup docs/mockups/new-types/). Off by default (wp-admin → Features).
 *
 * The employee asks from My requests → New request: an amount and the months to pay it back over,
 * within the limits (settings: months of salary, a fixed most, instalments, one open at a time,
 * months with the company). It runs on the approval engine (workflow salary_advance; by default the
 * direct manager, then Finance). Once approved, Finance records it as paid (wp-admin → Advances,
 * with the date and how) and the instalments come off the pay from the month after, one a month,
 * as a deduction line in Payroll and on the payslip. Finance can move one month's instalment to the
 * end. An employee who leaves: a warning when they are made inactive, and the rest comes off the pay
 * of their last month. An instalment is taken once its month is closed in Payroll.
 * Amounts are not written to the Audit Log (as for salaries). Rules: src/Payroll/Advances.php.
 * Behaviour: tests/e2e_advances.py.
 */
trait EWS_Advances_Trait {

    private function advances_enabled(){ return (bool)$this->option('ews_feature_advances'); }

    /** @return array{months_salary:float,max_amount:float,max_instalments:int,one_open:int,min_service_months:int} */
    private function advance_settings(){ return Advances::settings($this->option('ews_advance_settings')); }

    /** @return array{advances:string,instalments:string} */
    private function advances_tables(){
        global $wpdb;
        return ['advances'=>$wpdb->prefix.'ews_salary_advances','instalments'=>$wpdb->prefix.'ews_advance_instalments'];
    }

    private function ensure_advances_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=$this->advances_tables();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t['advances']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            instalments TINYINT UNSIGNED NOT NULL DEFAULT 1,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'Pending',
            requested_at DATETIME NOT NULL,
            decided_at DATETIME NULL,
            paid_on DATE NULL,
            paid_method VARCHAR(20) NOT NULL DEFAULT '',
            paid_note VARCHAR(190) NOT NULL DEFAULT '',
            paid_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY employee_status (employee_id,status),
            KEY status (status)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['instalments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            advance_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            month CHAR(7) NOT NULL,
            seq TINYINT UNSIGNED NOT NULL DEFAULT 1,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            moved TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY employee_month (employee_id,month),
            KEY advance (advance_id)
        ) {$c};");
    }

    private function advance_currency(){ return (string)$this->option('ews_payroll_currency')?:'EGP'; }

    private function advance_money($amount){ return number_format_i18n((float)$amount,(float)$amount==floor((float)$amount)?0:2).' '.$this->advance_currency(); }

    /** The monthly salary (basic + allowances) in effect today, or null when none is on file. */
    private function advance_salary($employee_id){
        $this->ensure_payroll_schema();
        $rates=$this->payroll_rates_on(current_time('Y-m-d'));
        $r=$rates[(int)$employee_id]??null;
        if(!$r)return null;
        $sum=(float)$r['basic'];
        foreach($r['allowances'] as $a)$sum+=(float)($a['amount']??0);
        return round($sum,2);
    }

    /** An employee's advances still open: waiting, approved and not paid, or paid and not paid back. */
    private function advance_has_open($employee_id){
        foreach($this->advance_rows((int)$employee_id) as $a)if(in_array($a['state'],['waiting','approved','paying'],true))return true;
        return false;
    }

    /**
     * An employee's advances with where each one is, newest first.
     * @return list<array<string,mixed>> each: row fields + state (waiting|approved|paying|done|rejected|withdrawn), left, taken, count, due (instalments not taken)
     */
    private function advance_rows($employee_id,$limit=20){
        if(!$this->advances_enabled())return [];
        global $wpdb;$t=$this->advances_tables();
        $out=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['advances']} WHERE employee_id=%d ORDER BY id DESC LIMIT %d",(int)$employee_id,(int)$limit)) as $a){
            $ins=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['instalments']} WHERE advance_id=%d ORDER BY month,seq",(int)$a->id));
            $taken=0;$left=0.0;$due=[];
            foreach($ins as $i){if($this->payroll_run((string)$i->month)){$taken++;}else{$left+=(float)$i->amount;$due[]=$i;}}
            $state=['Pending'=>'waiting','Approved'=>'approved','Rejected'=>'rejected','Cancelled'=>'withdrawn'][$a->status]??($a->status==='Paid'?($due?'paying':'done'):'withdrawn');
            $out[]=['id'=>(int)$a->id,'amount'=>(float)$a->amount,'instalments'=>(int)$a->instalments,'reason'=>(string)$a->reason,'status'=>(string)$a->status,'requested_at'=>(string)$a->requested_at,
                'paid_on'=>(string)$a->paid_on,'state'=>$state,'left'=>round($left,2),'taken'=>$taken,'count'=>count($ins),'schedule'=>$ins,'due'=>$due];
        }
        return $out;
    }

    /** For My requests → New request: the limits for this person, or why they cannot ask. @return array<string,mixed>|null null = the type is off */
    private function advance_form($emp){
        if(!$this->advances_enabled() || !$emp)return null;
        $s=$this->advance_settings();
        $salary=$this->advance_salary((int)$emp->id);
        $limit=Advances::limit($salary,$s);
        $service=Advances::serviceMonths((string)($emp->hire_date??$emp->created_at??''),current_time('Y-m-d'));
        $why='';
        if($limit===null)$why='no_salary';
        elseif($s['one_open'] && $this->advance_has_open((int)$emp->id))$why='open';
        elseif($service<$s['min_service_months'])$why='service';
        return ['limit'=>$limit,'limit_text'=>$limit===null?'':(is_infinite($limit)?__('No limit','workforce-one'):$this->advance_money($limit)),'max'=>(int)$s['max_instalments'],
            'why'=>$why,'why_text'=>$why!==''?$this->advance_error_text($why):'','currency'=>$this->advance_currency(),'one_open'=>(bool)$s['one_open']];
    }

    private function advance_error_text($code){
        $s=$this->advance_settings();
        $m=['amount'=>__('Enter the amount.','workforce-one'),'instalments'=>sprintf(/* translators: %d: most instalments */__('Pay it back over 1 to %d months.','workforce-one'),$s['max_instalments']),
            'no_salary'=>__('Your salary is not on file yet, so the limit is not known. Ask HR.','workforce-one'),
            'limit'=>__('That is more than you can ask for.','workforce-one'),'open'=>__('You already have an advance open. One at a time.','workforce-one'),
            'service'=>sprintf(/* translators: %d: months */_n('You can ask after %d month with the company.','You can ask after %d months with the company.',$s['min_service_months'],'workforce-one'),$s['min_service_months']),
            'access'=>__('You cannot do that.','workforce-one'),'save'=>__('It could not be saved. Try again.','workforce-one'),'approval'=>__('It could not be decided. Try again.','workforce-one'),
            'note'=>__('Write why you reject it, then reject it again.','workforce-one'),'busy'=>__('Another request of yours is being saved. Try again in a moment.','workforce-one')];
        return $m[$code]??$m['save'];
    }

    /** Where the employee goes back to (My requests). */
    private function advance_back($args){
        return add_query_arg($args,$this->app_view_url('requests'));
    }

    /** The default path when the workflow was never saved: the direct manager, then Finance (owner decision). */
    private function advance_workflow_ready(){
        $w=$this->approval_workflow('salary_advance');
        if($w && (int)$w->active)return true;
        return $this->approval_configure_workflow('salary_advance','LEVEL_2',[1=>['resolver_type'=>'SUPERVISOR'],2=>['resolver_type'=>'FINANCE']],Workflows::IN_USE['salary_advance'][1])===true;
    }

    /** admin-post ews_advance_request_create (My requests → New request → Salary advance). */
    public function advance_request_create(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_advance_request_create');
        $emp=$this->current_employee();
        if(!$this->advances_enabled() || !$emp){wp_safe_redirect($this->advance_back(['adv_error'=>'access']));exit;}
        $amount=round((float)str_replace(',','',sanitize_text_field(wp_unslash($_POST['amount']??'0'))),2);
        $n=absint($_POST['instalments']??0);
        $reason=trim(sanitize_textarea_field(wp_unslash($_POST['reason']??'')));
        $s=$this->advance_settings();
        $form=$this->advance_form($emp);
        $error=$form['why']!==''?$form['why']:Advances::check(['amount'=>$amount,'instalments'=>$n,'limit'=>$form['limit'],'open'=>false,'service_months'=>PHP_INT_MAX],$s);
        if($error){wp_safe_redirect($this->advance_back(['adv_error'=>$error]));exit;}
        $this->ews_with_lock('advance_emp_'.(int)$emp->id,function()use($emp,$amount,$n,$reason,$s){
            if($s['one_open'] && $this->advance_has_open((int)$emp->id)){wp_safe_redirect($this->advance_back(['adv_error'=>'open']));exit;}
            global $wpdb;$t=$this->advances_tables();
            if(!$this->advance_workflow_ready()){wp_safe_redirect($this->advance_back(['adv_error'=>'approval']));exit;}
            if($wpdb->query('START TRANSACTION')===false){wp_safe_redirect($this->advance_back(['adv_error'=>'save']));exit;}
            $ok=$wpdb->insert($t['advances'],['employee_id'=>(int)$emp->id,'amount'=>$amount,'instalments'=>$n,'reason'=>mb_substr($reason,0,255),'status'=>'Pending','requested_at'=>current_time('mysql')],['%d','%f','%d','%s','%s','%s']);
            $id=(int)$wpdb->insert_id;
            $ar=$ok?$this->approval_start('salary_advance','salary_advance',$id,(int)$emp->id,['amount'=>$amount],false):false;
            if(!$ok || is_wp_error($ar)){$wpdb->query('ROLLBACK');wp_safe_redirect($this->advance_back(['adv_error'=>'save']));exit;}
            $req=$this->approval_find_request('salary_advance','salary_advance',$id);
            if($req && $req->status==='APPROVED')$wpdb->update($t['advances'],['status'=>'Approved','decided_at'=>current_time('mysql')],['id'=>$id]);
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');wp_safe_redirect($this->advance_back(['adv_error'=>'save']));exit;}
            $this->audit('advance_request','salary_advance',$id,$emp->name.' asked for a salary advance over '.$n.' month(s)');
            if($req && $req->status==='APPROVED')$this->advance_tell_finance($id,$emp);
            else $this->advance_notify_approver($id,$emp);
            wp_safe_redirect($this->advance_back(['adv_sent'=>1]));exit;
        },'busy');
        wp_safe_redirect($this->advance_back(['adv_error'=>'busy']));exit;
    }

    private function advance_notify_approver($id,$emp){
        $ar=$this->approval_find_request('salary_advance','salary_advance',(int)$id);
        $step=$ar?$this->approval_current_step((int)$ar->id):null;
        if($step && (int)$step->approver_wp_user_id)$this->notify((int)$step->approver_wp_user_id,'system',__('A salary advance waits for you','workforce-one'),
            sprintf(/* translators: %s: employee name */__('%s asked for a salary advance.','workforce-one'),$emp->name),['entity'=>'approval','entity_id'=>(int)$ar->id,'push'=>true]);
    }

    /** Approved: Finance is told it waits to be paid. */
    private function advance_tell_finance($id,$emp){
        $uid=(int)$this->approval_settings()['finance_user'];
        $msg=sprintf(/* translators: %s: employee name */__('The salary advance of %s was approved and waits to be paid (wp-admin → Advances).','workforce-one'),$emp->name);
        if($uid)$this->notify($uid,'system',__('An advance waits to be paid','workforce-one'),$msg,['entity'=>'approval']);
        else foreach(get_users(['capability'=>'ews_manage_payroll','fields'=>['ID']]) as $u)$this->notify((int)$u->ID,'system',__('An advance waits to be paid','workforce-one'),$msg,['entity'=>'approval']);
    }

    /** admin-post ews_advance_respond: a level's decision (My approvals). */
    public function advance_respond(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_advance_respond');
        $id=absint($_POST['advance_id']??0);$decision=sanitize_key($_POST['decision']??'');
        $back=add_query_arg([],$this->app_view_url($this->approvals_return_view('approvals')));
        $go=function($args)use($back){wp_safe_redirect(add_query_arg($args,$back));exit;};
        if(!$this->advances_enabled() || !$id || !in_array($decision,['approve','reject'],true))$go(['adv_error'=>'access']);
        global $wpdb;$t=$this->advances_tables();
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['advances']} WHERE id=%d",$id));
        if(!$a || $a->status!=='Pending')$go(['adv_done'=>1]);
        $ar=$this->approval_find_request('salary_advance','salary_advance',$id);
        if(!$ar || !$this->approval_can_act((int)$ar->id))$go(['adv_error'=>'access']);
        if($wpdb->query('START TRANSACTION')===false)$go(['adv_error'=>'save']);
        $acted=$this->approval_act((int)$ar->id,$decision,$this->approval_posted_note(),false);
        if(is_wp_error($acted)){$wpdb->query('ROLLBACK');$go(['adv_error'=>$acted->get_error_code()==='approval_note_required'?'note':'approval']);}
        $fresh=$this->approval_find_request('salary_advance','salary_advance',$id);
        $final=$fresh && in_array($fresh->status,['APPROVED','REJECTED'],true);
        if($final)$wpdb->update($t['advances'],['status'=>$fresh->status==='APPROVED'?'Approved':'Rejected','decided_at'=>current_time('mysql')],['id'=>$id,'status'=>'Pending']);
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');$go(['adv_error'=>'save']);}
        $emp=$this->people_employee((int)$a->employee_id);
        $this->audit('advance_'.$decision,'salary_advance',$id,'level decision');
        if($final && $emp){
            $uid=(int)$this->people_user_id((int)$emp->id);
            if($fresh->status==='APPROVED'){
                if($uid)$this->notify($uid,'system',__('Your salary advance was approved','workforce-one'),__('It waits to be paid. Instalments start the month after it is paid.','workforce-one'),['entity'=>'approval','push'=>true]);
                $this->advance_tell_finance($id,$emp);
            }elseif($uid)$this->notify($uid,'system',__('Your salary advance was rejected','workforce-one'),$fresh?(string)$this->approval_path('salary_advance','salary_advance',$id)['note']:'',['entity'=>'approval','push'=>true]);
        }elseif($emp)$this->advance_notify_approver($id,$emp);
        $go(['adv_done'=>1]);
    }

    /** The employee withdraws an advance still waiting (My requests). @return true|string */
    private function advance_withdraw($id,$employee_id){
        global $wpdb;$t=$this->advances_tables();
        if($wpdb->query('START TRANSACTION')===false)return 'save';
        $ok=$wpdb->update($t['advances'],['status'=>'Cancelled','decided_at'=>current_time('mysql')],['id'=>(int)$id,'employee_id'=>(int)$employee_id,'status'=>'Pending']);
        if($ok!==1){$wpdb->query('ROLLBACK');return 'done';}
        if(!$this->approval_close('salary_advance','salary_advance',(int)$id)){$wpdb->query('ROLLBACK');return 'save';}
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
        $this->audit('advance_withdrawn','salary_advance',(int)$id,'Withdrawn by the employee');
        return true;
    }

    /** The card in My approvals. @return array<string,mixed>|null */
    private function advance_approval_card($id){
        if(!$this->advances_enabled())return null;
        global $wpdb;$t=$this->advances_tables();
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['advances']} WHERE id=%d AND status='Pending'",(int)$id));
        if(!$a)return null;
        $salary=$this->advance_salary((int)$a->employee_id);$limit=Advances::limit($salary,$this->advance_settings());
        $each=Advances::split((float)$a->amount,(int)$a->instalments)[0];
        $last=null;
        foreach($this->advance_rows((int)$a->employee_id,10) as $r){
            if((int)$r['id']!==(int)$a->id && in_array($r['state'],['done','paying'],true)){$last=$r;break;}
        }
        $emp=$this->people_employee((int)$a->employee_id);
        $facts=[[__('Salary','workforce-one'),$salary!==null?$this->advance_money($salary):'—'],
            [__('Of the limit','workforce-one'),$limit!==null&&!is_infinite($limit)&&$limit>0?round((float)$a->amount/$limit*100).'% · '.$this->advance_money($limit):'—'],
            [__('Open advances','workforce-one'),$last&&$last['state']==='paying'?sprintf(/* translators: %s: amount */__('%s left','workforce-one'),$this->advance_money($last['left'])):__('None','workforce-one')],
            [__('Last advance','workforce-one'),$last?$this->advance_money($last['amount']).($last['state']==='done'?' · '.__('paid back','workforce-one'):''):'—'],
            [__('With the company','workforce-one'),$emp?sprintf(/* translators: %d: months */_n('%d month','%d months',Advances::serviceMonths((string)($emp->hire_date??$emp->created_at??''),current_time('Y-m-d')),'workforce-one'),Advances::serviceMonths((string)($emp->hire_date??$emp->created_at??''),current_time('Y-m-d'))):'—']];
        if(trim((string)$a->reason)!=='')$facts[]=[__('Reason','workforce-one'),(string)$a->reason];
        return ['employee_id'=>(int)$a->employee_id,'tone'=>'advance','kind'=>__('Salary advance','workforce-one'),
            'what'=>sprintf(/* translators: 1: amount, 2: months, 3: each instalment */_n('%1$s over %2$d month (%3$s a month)','%1$s over %2$d months (%3$s a month)',(int)$a->instalments,'workforce-one'),$this->advance_money($a->amount),(int)$a->instalments,$this->advance_money($each)),
            'facts'=>$facts,'since'=>(string)$a->requested_at,'action'=>'ews_advance_respond','nonce'=>'ews_advance_respond','fields'=>['advance_id'=>(int)$a->id]];
    }

    /** For My requests. @return list<array<string,mixed>> */
    private function advance_my_requests($employee_id){
        $out=[];
        foreach($this->advance_rows((int)$employee_id) as $r){
            $state=['waiting'=>'waiting','approved'=>'approved','paying'=>'approved','done'=>'approved','rejected'=>'rejected','withdrawn'=>'withdrawn'][$r['state']];
            $sub=$r['state']==='approved'?__('Approved · waits to be paid','workforce-one'):($r['state']==='paying'?sprintf(/* translators: 1: date, 2: amount left, 3: instalments taken, 4: instalments */__('Paid on %1$s · %2$s left · %3$d of %4$d instalments','workforce-one'),date_i18n('j M',strtotime($r['paid_on'])),$this->advance_money($r['left']),$r['taken'],$r['count'])
                :($r['state']==='done'?__('Paid back','workforce-one'):sprintf(/* translators: %d: months */_n('Over %d month','Over %d months',$r['instalments'],'workforce-one'),$r['instalments'])));
            $out[]=['key'=>'advance-'.$r['id'],'tone'=>'advance','title'=>__('Salary advance','workforce-one').' · '.$this->advance_money($r['amount']),'sub'=>$sub,'state'=>$state,'when'=>$r['requested_at'],
                'approval'=>['salary_advance','salary_advance',$r['id']],'withdraw'=>$r['state']==='waiting'?'advance':'','id'=>$r['id'],
                'schedule'=>in_array($r['state'],['paying','done'],true)?array_map(function($i){return ['month'=>(string)$i->month,'taken'=>(bool)$this->payroll_run((string)$i->month)];},$r['schedule']):[]];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ Payroll */

    /**
     * The instalments that come off a month's pay, per employee, as Payroll adjustments (deductions).
     * An employee who left with their last working day in this month: the rest too.
     * @param object[] $emps @return array<int,list<array<string,mixed>>>
     */
    private function advance_payroll_lines($month,array $emps){
        if(!$this->advances_enabled() || !$emps)return [];
        global $wpdb;$t=$this->advances_tables();
        $ids=array_map(function($e){return (int)$e->id;},$emps);$ph=implode(',',array_fill(0,count($ids),'%d'));
        $leaving=[];foreach($emps as $e)if(!(int)$e->active && !empty($e->last_working_day) && substr((string)$e->last_working_day,0,7)===$month)$leaving[(int)$e->id]=true;
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT i.*,a.instalments total FROM {$t['instalments']} i JOIN {$t['advances']} a ON a.id=i.advance_id WHERE a.status='Paid' AND i.employee_id IN ($ph) AND (i.month=%s OR (i.month>%s)) ORDER BY i.month,i.seq",array_merge($ids,[$month,$month])));
        $out=[];$rest=[];
        foreach($rows as $r){
            $eid=(int)$r->employee_id;
            if((string)$r->month===$month)$out[$eid][]=['id'=>0,'kind'=>'deduction','amount'=>(float)$r->amount,'instalment_id'=>(int)$r->id,
                'reason'=>sprintf(/* translators: 1: instalment, 2: instalments */__('Advance instalment (%1$d of %2$d)','workforce-one'),(int)$r->seq,(int)$r->total)];
            elseif(isset($leaving[$eid]))$rest[$eid]=($rest[$eid]??0)+(float)$r->amount;
        }
        foreach($rest as $eid=>$sum)$out[$eid][]=['id'=>0,'kind'=>'deduction','amount'=>round($sum,2),'reason'=>__('Advance: the rest (last month)','workforce-one')];
        return $out;
    }

    /** What an employee still owes, for the warning when they are made inactive. */
    private function advance_left_for($employee_id){
        $left=0.0;foreach($this->advance_rows((int)$employee_id) as $r)if($r['state']==='paying')$left+=$r['left'];
        return round($left,2);
    }

    /* ------------------------------------------------------------------ wp-admin → Advances */

    private function advances_can_pay(){
        return $this->can('ews_manage_payroll') || (int)get_current_user_id()===(int)$this->approval_settings()['finance_user'];
    }

    public function admin_advances(){
        if(!$this->advances_can_pay())wp_die('Access denied');
        global $wpdb;$t=$this->advances_tables();
        $rows=$this->advances_enabled()?(array)$wpdb->get_results("SELECT * FROM {$t['advances']} WHERE status IN ('Approved','Paid','Pending') ORDER BY CASE status WHEN 'Approved' THEN 0 WHEN 'Paid' THEN 1 ELSE 2 END,id DESC LIMIT 300"):[];
        $list=[];
        foreach($rows as $a){
            $st=null;foreach($this->advance_rows((int)$a->employee_id,50) as $r)if($r['id']===(int)$a->id)$st=$r;
            if(!$st)continue;
            $st['name']=$this->people_name((int)$a->employee_id);$st['method']=(string)$a->paid_method;$st['note']=(string)$a->paid_note;
            $list[]=$st;
        }
        $notice=null;
        $done=sanitize_key($_GET['adv_done']??'');$err=sanitize_key($_GET['adv_error']??'');
        if($done!=='')$notice=['type'=>'success','text'=>['paid'=>__('Recorded as paid. The instalments start next month.','workforce-one'),'moved'=>__('The instalment moved to after the last one.','workforce-one')][$done]??__('Saved.','workforce-one')];
        elseif($err!=='')$notice=['type'=>'error','text'=>['date'=>__('Pick the day it was paid.','workforce-one'),'closed'=>__('That month is closed in Payroll.','workforce-one')][$err]??$this->advance_error_text($err)];
        echo $this->render_template('admin/advances',['on'=>$this->advances_enabled(),'rows'=>$list,'notice'=>$notice,'post_url'=>admin_url('admin-post.php'),'today'=>current_time('Y-m-d'),
            'money'=>function($v){return $this->advance_money($v);},'features_url'=>admin_url('admin.php?page=ews31-features#ews-advances'),'settings'=>$this->advance_settings()]);
    }

    /** admin-post ews_advance_paid: Finance records an approved advance as paid; its instalments are planned. */
    public function advance_paid(){
        $id=absint($_POST['advance_id']??0);
        check_admin_referer('ews_advance_paid_'.$id);
        $back=admin_url('admin.php?page=ews31-advances');
        if(!$this->advances_can_pay() || !$this->advances_enabled()){wp_safe_redirect(add_query_arg('adv_error','access',$back));exit;}
        $on=sanitize_text_field(wp_unslash($_POST['paid_on']??''));
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$on);
        if(!$d || $d->format('Y-m-d')!==$on || $on>current_time('Y-m-d')){wp_safe_redirect(add_query_arg('adv_error','date',$back));exit;}
        global $wpdb;$t=$this->advances_tables();
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['advances']} WHERE id=%d AND status='Approved'",$id));
        if(!$a){wp_safe_redirect(add_query_arg('adv_error','access',$back));exit;}
        $method=in_array($_POST['paid_method']??'',['cash','transfer'],true)?(string)$_POST['paid_method']:'cash';
        if($wpdb->query('START TRANSACTION')===false){wp_safe_redirect(add_query_arg('adv_error','save',$back));exit;}
        $ok=$wpdb->update($t['advances'],['status'=>'Paid','paid_on'=>$on,'paid_method'=>$method,'paid_note'=>mb_substr(sanitize_text_field(wp_unslash($_POST['paid_note']??'')),0,190),'paid_by'=>(int)get_current_user_id()],['id'=>$id,'status'=>'Approved'])===1;
        $parts=Advances::split((float)$a->amount,(int)$a->instalments);$months=Advances::months($on,(int)$a->instalments);
        foreach($parts as $i=>$amount)$ok=$ok && $wpdb->insert($t['instalments'],['advance_id'=>$id,'employee_id'=>(int)$a->employee_id,'month'=>$months[$i],'seq'=>$i+1,'amount'=>$amount])!==false;
        if(!$ok || $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');wp_safe_redirect(add_query_arg('adv_error','save',$back));exit;}
        $this->audit('advance_paid','salary_advance',$id,'Paid ('.$method.') on '.$on.'; instalments from '.$months[0]);
        $uid=(int)$this->people_user_id((int)$a->employee_id);
        if($uid)$this->notify($uid,'system',__('Your salary advance was paid','workforce-one'),
            sprintf(/* translators: 1: month, 2: instalments */_n('The instalment comes off your pay from %1$s, for %2$d month.','The instalments come off your pay from %1$s, for %2$d months.',(int)$a->instalments,'workforce-one'),date_i18n('F Y',strtotime($months[0].'-01')),(int)$a->instalments),['entity'=>'approval','push'=>true]);
        wp_safe_redirect(add_query_arg('adv_done','paid',$back));exit;
    }

    /** admin-post ews_advance_move: one month's instalment moves to after the last one (a month not closed). */
    public function advance_move(){
        $iid=absint($_POST['instalment_id']??0);
        check_admin_referer('ews_advance_move_'.$iid);
        $back=wp_get_referer()?:admin_url('admin.php?page=ews31-advances');
        if(!$this->advances_can_pay()){wp_safe_redirect(add_query_arg('adv_error','access',$back));exit;}
        global $wpdb;$t=$this->advances_tables();
        $i=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['instalments']} WHERE id=%d",$iid));
        if(!$i){wp_safe_redirect(add_query_arg('adv_error','access',$back));exit;}
        if($this->payroll_run((string)$i->month)){wp_safe_redirect(add_query_arg('adv_error','closed',$back));exit;}
        $months=array_map('strval',(array)$wpdb->get_col($wpdb->prepare("SELECT month FROM {$t['instalments']} WHERE advance_id=%d",(int)$i->advance_id)));
        $to=Advances::after($months);
        $wpdb->update($t['instalments'],['month'=>$to,'moved'=>1],['id'=>$iid]);
        $this->audit('advance_instalment_moved','salary_advance',(int)$i->advance_id,'instalment '.(int)$i->seq.': '.$i->month.' → '.$to);
        wp_safe_redirect(add_query_arg('adv_done','moved',remove_query_arg(['adv_done','adv_error'],$back)));exit;
    }
}
