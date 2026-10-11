<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Payroll\PayCalculator;
use WorkforceOne\Payroll\PayRules;

/**
 * wp-admin → Payroll (permission "Manage Payroll"): salaries with the date they take effect, the
 * payroll rules, and each month's pay worked out from attendance (src/Payroll/PayCalculator.php on
 * the report engine's days), bonuses and deductions added by hand, and closing a month: its
 * payslips are kept as they were and no longer follow attendance until it is reopened.
 * Employees see their own pay in the app (My Pay) once an administrator switches it on.
 * Amounts are never written to the Audit Log, only what was done.
 * Output: templates/admin/payroll.php. Behaviour: tests/e2e_payroll.py.
 */
trait EWS_Payroll_Trait {

    private function payroll_table(){ global $wpdb; return $wpdb->prefix.'ews_pay_rates'; }
    /** @return array{adjustments:string,runs:string,payslips:string} */
    private function payroll_tables(){ global $wpdb; return ['adjustments'=>$wpdb->prefix.'ews_pay_adjustments','runs'=>$wpdb->prefix.'ews_payroll_runs','payslips'=>$wpdb->prefix.'ews_payslips']; }

    private function ensure_payroll_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$this->payroll_table()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            effective_from DATE NOT NULL,
            basic DECIMAL(14,2) NOT NULL DEFAULT 0,
            allowances LONGTEXT NULL,
            note VARCHAR(190) NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY employee_from(employee_id,effective_from)
        ) {$wpdb->get_charset_collate()};");
        $t=$this->payroll_tables();$c=$wpdb->get_charset_collate();
        // Bonuses and deductions added by hand for one employee and month.
        dbDelta("CREATE TABLE {$t['adjustments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            month CHAR(7) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            reason VARCHAR(190) NOT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY employee_month(employee_id,month)
        ) {$c};");
        // A closed month and the payslips it kept (figures as they were when closed).
        dbDelta("CREATE TABLE {$t['runs']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            month CHAR(7) NOT NULL,
            employees INT UNSIGNED NOT NULL DEFAULT 0,
            total_net DECIMAL(16,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT '',
            closed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY month(month)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['payslips']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            net DECIMAL(14,2) NOT NULL DEFAULT 0,
            data LONGTEXT NOT NULL,
            PRIMARY KEY(id),
            UNIQUE KEY run_employee(run_id,employee_id),
            KEY employee(employee_id)
        ) {$c};");
    }

    /** The payroll rules (wp-admin → Payroll → Rules). @return array<string,mixed> */
    private function payroll_rules(){
        return ['currency'=>(string)$this->option('ews_payroll_currency'),'day_divisor'=>(int)$this->option('ews_payroll_day_divisor'),
            'day_base'=>(string)$this->option('ews_payroll_day_base'),'absence_days'=>(float)$this->option('ews_payroll_absence_days'),
            'overtime_rate'=>(float)$this->option('ews_payroll_overtime_rate'),'overtime_rate_off'=>(float)$this->option('ews_payroll_overtime_rate_off'),
            'max_deduction_days'=>(float)$this->option('ews_payroll_max_deduction_days'),'employee_view'=>(bool)$this->option('ews_payroll_employee_view'),
            'late_mode'=>(string)$this->option('ews_payroll_late_mode')==='tiers'?'tiers':'minute','late_tiers'=>array_values(array_filter((array)$this->option('ews_payroll_late_tiers'),'is_array'))];
    }

    /** @return array{basic:float,allowances:array<int,array{name:string,amount:float}>,effective_from:string,note:string,id:int}|null */
    private function payroll_rate_row($r){
        if(!$r)return null;
        $allowances=json_decode((string)$r->allowances,true);
        return ['id'=>(int)$r->id,'basic'=>(float)$r->basic,'allowances'=>is_array($allowances)?$allowances:[],'effective_from'=>(string)$r->effective_from,'note'=>(string)$r->note];
    }

    /** The salary in effect on $date for each employee (the latest that started on or before it). @return array<int,array<string,mixed>> */
    private function payroll_rates_on($date){
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT r.* FROM {$this->payroll_table()} r JOIN (SELECT employee_id,MAX(effective_from) f FROM {$this->payroll_table()} WHERE effective_from<=%s GROUP BY employee_id) x ON x.employee_id=r.employee_id AND x.f=r.effective_from ORDER BY r.id ASC",$date));
        $out=[];
        foreach((array)$rows as $r)$out[(int)$r->employee_id]=$this->payroll_rate_row($r);
        return $out;
    }

    /** Minutes of an employee's working day: shift length less the allowed break (as the reports count it). */
    private function payroll_day_minutes($employee_id){
        $h=$this->working_hours($employee_id);
        $s=strtotime('1970-01-01 '.$h['start'].':00');$e=strtotime('1970-01-01 '.$h['end'].':00');
        if($s===false||$e===false)return 480;
        if($e<=$s)$e+=86400;
        return max(1,intdiv($e-$s,60)-($this->break_enabled()?(int)$this->break_duration_minutes():0));
    }

    /**
     * Pay of every active employee for a month: [employee, pay (null = no salary)] ordered by name.
     * @return array<int,array{employee:object,pay:?array<string,mixed>}>
     */
    private function payroll_month($month,$only_employee=0){
        global $wpdb;
        $this->ensure_payroll_schema();
        $first=$month.'-01';$last=date('Y-m-t',strtotime($first));
        $where=$only_employee?$wpdb->prepare(' AND id=%d',$only_employee):'';
        $emps=(array)$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1{$where} ORDER BY name ASC");
        $rates=$this->payroll_rates_on($last);
        // The salary in effect on the month's last day covers the whole month; only an employee's
        // first salary is a start date (days before it are not paid).
        foreach((array)$wpdb->get_results("SELECT employee_id,MIN(effective_from) f FROM {$this->payroll_table()} GROUP BY employee_id") as $r){
            if(isset($rates[(int)$r->employee_id]))$rates[(int)$r->employee_id]['effective_from']=(string)$r->f;
        }
        $tracked=array_values(array_filter($emps,function($e)use($rates){return isset($rates[(int)$e->id]) && (!isset($e->attendance_enabled)||(int)$e->attendance_enabled===1);}));
        $days=[];
        foreach($this->report_days($tracked,$first,$last,true) as $d)$days[(int)$d['employee_id']][]=$d;
        $ids=array_map(function($e){return (int)$e->id;},$tracked);
        $early=[];$leave=[];
        if($ids){
            $ph=implode(',',array_fill(0,count($ids),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,SUM(leave_minutes) m FROM {$wpdb->prefix}ews_early_leave_requests WHERE status='Approved' AND employee_id IN ($ph) AND work_date BETWEEN %s AND %s GROUP BY employee_id,work_date",array_merge($ids,[$first,$last]))) as $r)$early[(int)$r->employee_id][$r->work_date]=(int)$r->m;
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT s.employee_id,s.work_date,t.name,t.paid_percent FROM {$wpdb->prefix}ews_leave_schedule_snapshots s JOIN {$wpdb->prefix}ews_leave_requests r ON r.id=s.leave_request_id JOIN {$wpdb->prefix}ews_leave_types t ON t.id=r.leave_type_id WHERE r.status='Approved' AND s.employee_id IN ($ph) AND s.work_date BETWEEN %s AND %s",array_merge($ids,[$first,$last]))) as $r)$leave[(int)$r->employee_id][$r->work_date]=['type'=>(string)$r->name,'paid'=>(int)$r->paid_percent];
        }
        $adjust=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,employee_id,kind,amount,reason FROM {$this->payroll_tables()['adjustments']} WHERE month=%s ORDER BY id ASC",$month)) as $r)$adjust[(int)$r->employee_id][]=['id'=>(int)$r->id,'kind'=>(string)$r->kind,'amount'=>(float)$r->amount,'reason'=>(string)$r->reason];
        $rules=$this->payroll_rules();
        $out=[];
        foreach($emps as $e){
            $eid=(int)$e->id;
            if(!isset($rates[$eid])){$out[]=['employee'=>$e,'pay'=>null];continue;}
            $rows=[];
            foreach($days[$eid]??[] as $d){
                $d['approved_early']=$early[$eid][$d['date']]??0;
                if(isset($leave[$eid][$d['date']])){$d['leave_type']=$leave[$eid][$d['date']]['type'];$d['leave_paid']=$leave[$eid][$d['date']]['paid'];}
                $rows[]=$d;
            }
            $out[]=['employee'=>$e,'pay'=>PayCalculator::month($rates[$eid],$rules,$rows,$this->payroll_day_minutes($eid),$month,$adjust[$eid]??[])];
        }
        return $out;
    }

    private function payroll_month_param(){
        $m=sanitize_text_field(wp_unslash($_GET['month']??''));
        if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$m))$m=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
        return $m;
    }

    private function payroll_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-payroll')));
        exit;
    }

    public function admin_payroll(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        $this->ensure_payroll_schema();
        $tab=sanitize_key($_GET['tab']??'month');
        if(!in_array($tab,['month','salaries','rules'],true))$tab='month';
        $month=$this->payroll_month_param();
        $vars=['tab'=>$tab,'month'=>$month,'rules'=>$this->payroll_rules(),'page_url'=>admin_url('admin.php?page=ews31-payroll'),
            'grace'=>(int)$this->global_grace_period(),'notice'=>sanitize_key($_GET['payroll_saved']??''),'error'=>PayRules::message(sanitize_key($_GET['payroll_error']??'')),'detail'=>null,'people'=>[],'salaries'=>[]];
        if($tab==='month'){
            $employee=absint($_GET['employee']??0);
            $run=$this->payroll_run($month);
            $vars['run']=$run?['closed_at'=>(string)$run->closed_at,'closed_by'=>(string)(get_userdata((int)$run->closed_by)->display_name??'')]:null;
            $vars['can_close']=!$run&&$month<current_time('Y-m');
            $people=$run?$this->payroll_payslips((int)$run->id,$employee):$this->payroll_month($month,$employee);
            if($employee&&$people)$vars['detail']=$people[0];
            else $vars['people']=$people;
            $vars['export_url']=wp_nonce_url(add_query_arg(['action'=>'ews_payroll_export','month'=>$month],admin_url('admin-post.php')),'ews_payroll_export');
            $vars['pdf_url']=$run&&$employee?wp_nonce_url(add_query_arg(['action'=>'ews_payroll_pdf','month'=>$month,'employee'=>$employee],admin_url('admin-post.php')),'ews_payroll_pdf'):'';
        }elseif($tab==='salaries'){
            global $wpdb;
            $emps=(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
            $history=[];
            foreach((array)$wpdb->get_results("SELECT * FROM {$this->payroll_table()} ORDER BY effective_from DESC,id DESC") as $r)$history[(int)$r->employee_id][]=$this->payroll_rate_row($r);
            $today=current_time('Y-m-d');
            foreach($emps as $e){
                $list=$history[(int)$e->id]??[];
                $current=null;foreach($list as $r){if($r['effective_from']<=$today){$current=$r;break;}}
                $vars['salaries'][]=['employee'=>$e,'current'=>$current,'upcoming'=>array_values(array_filter($list,function($r)use($today){return $r['effective_from']>$today;})),'history'=>$list];
            }
            $vars['default_from']=current_time('Y-m').'-01';
            $vars['selected']=absint($_GET['employee']??0);
        }
        echo $this->render_template('admin/payroll',$vars+['run'=>null,'can_close'=>false,'pdf_url'=>'']);
    }

    /** The closed run of a month, if any. */
    private function payroll_run($month){
        global $wpdb;$this->ensure_payroll_schema();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->payroll_tables()['runs']} WHERE month=%s",$month));
    }

    /** A closed month's payslips, as payroll_month() rows (employee name and id as they were). @return array<int,array{employee:object,pay:array<string,mixed>}> */
    private function payroll_payslips($run_id,$only_employee=0){
        global $wpdb;$t=$this->payroll_tables();
        $where=$only_employee?$wpdb->prepare(' AND employee_id=%d',$only_employee):'';
        $out=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,data FROM {$t['payslips']} WHERE run_id=%d{$where}",$run_id)) as $r){
            $d=json_decode((string)$r->data,true);
            if(!is_array($d)||!isset($d['pay']))continue;
            $out[]=['employee'=>(object)['id'=>(int)$r->employee_id,'name'=>(string)($d['employee']['name']??'')],'pay'=>$d['pay']];
        }
        usort($out,function($a,$b){return strcasecmp($a['employee']->name,$b['employee']->name);});
        return $out;
    }

    private function payroll_month_post(){
        $m=sanitize_text_field(wp_unslash($_POST['month']??''));
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$m)?$m:'';
    }

    public function payroll_adjust_save(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_adjust_save');
        global $wpdb;$this->ensure_payroll_schema();
        $month=$this->payroll_month_post();$eid=absint($_POST['employee_id']??0);
        $back=['month'=>$month,'employee'=>$eid];
        $emp=$eid?$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->employees} WHERE id=%d",$eid)):null;
        if(!$month||!$emp)$this->payroll_redirect(['payroll_error'=>'employee']);
        if($this->payroll_run($month))$this->payroll_redirect($back+['payroll_error'=>'closed']);
        [$adj,$error]=PayRules::adjustment(['kind'=>(string)($_POST['kind']??''),'amount'=>(string)($_POST['amount']??''),'reason'=>sanitize_text_field(wp_unslash((string)($_POST['reason']??'')))]);
        if($error)$this->payroll_redirect($back+['payroll_error'=>$error]);
        $wpdb->insert($this->payroll_tables()['adjustments'],['employee_id'=>$eid,'month'=>$month,'kind'=>$adj['kind'],'amount'=>$adj['amount'],'reason'=>$adj['reason'],'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')],['%d','%s','%s','%f','%s','%d','%s']);
        $this->audit('pay_adjustment_added','employee',$eid,$emp->name.' '.$month.': '.($adj['kind']==='bonus'?'bonus':'deduction').' — '.$adj['reason']);
        $this->payroll_redirect($back+['payroll_saved'=>'adjustment']);
    }

    public function payroll_adjust_delete(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_adjust_delete');
        global $wpdb;$this->ensure_payroll_schema();$t=$this->payroll_tables();
        $r=$wpdb->get_row($wpdb->prepare("SELECT a.*,e.name FROM {$t['adjustments']} a LEFT JOIN {$this->employees} e ON e.id=a.employee_id WHERE a.id=%d",absint($_POST['adjustment_id']??0)));
        if(!$r)$this->payroll_redirect(['payroll_error'=>'not_found']);
        $back=['month'=>(string)$r->month,'employee'=>(int)$r->employee_id];
        if($this->payroll_run((string)$r->month))$this->payroll_redirect($back+['payroll_error'=>'closed']);
        $wpdb->delete($t['adjustments'],['id'=>(int)$r->id],['%d']);
        $this->audit('pay_adjustment_removed','employee',(int)$r->employee_id,(string)$r->name.' '.$r->month.': '.$r->kind.' — '.$r->reason);
        $this->payroll_redirect($back+['payroll_saved'=>'adjustment_removed']);
    }

    public function payroll_close(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_close');
        global $wpdb;$this->ensure_payroll_schema();$t=$this->payroll_tables();
        $month=$this->payroll_month_post();
        if(!$month)$this->payroll_redirect(['payroll_error'=>'month']);
        $back=['month'=>$month];
        if($month>=current_time('Y-m'))$this->payroll_redirect($back+['payroll_error'=>'not_ended']);
        if($this->payroll_run($month))$this->payroll_redirect($back+['payroll_error'=>'already_closed']);
        $people=array_values(array_filter($this->payroll_month($month),function($x){return $x['pay']!==null;}));
        if(!$people)$this->payroll_redirect($back+['payroll_error'=>'nothing']);
        foreach($people as $x)if($x['pay']['review'])$this->payroll_redirect($back+['payroll_error'=>'review']);
        $rules=$this->payroll_rules();
        $total=round(array_sum(array_map(function($x){return (float)$x['pay']['net'];},$people)),2);
        $ok=$wpdb->insert($t['runs'],['month'=>$month,'employees'=>count($people),'total_net'=>$total,'currency'=>$rules['currency'],'closed_by'=>get_current_user_id(),'closed_at'=>current_time('mysql')],['%s','%d','%f','%s','%d','%s']);
        if(!$ok)$this->payroll_redirect($back+['payroll_error'=>'already_closed']);
        $run_id=(int)$wpdb->insert_id;
        foreach($people as $x){
            $wpdb->insert($t['payslips'],['run_id'=>$run_id,'employee_id'=>(int)$x['employee']->id,'net'=>$x['pay']['net'],
                'data'=>wp_json_encode(['employee'=>['id'=>(int)$x['employee']->id,'name'=>(string)$x['employee']->name],'currency'=>$rules['currency'],'rules'=>$rules,'pay'=>$x['pay']])],['%d','%d','%f','%s']);
        }
        $this->audit('payroll_closed','payroll',$run_id,$month.': '.count($people).' payslips');
        if($rules['employee_view']){
            $label=date('F Y',strtotime($month.'-01'));
            foreach($people as $x){
                if(!empty($x['employee']->wp_user_id))$this->notify((int)$x['employee']->wp_user_id,'payroll','Payslip ready','Your payslip for '.$label.' is ready in My Pay.',['type'=>'info','entity_id'=>$run_id,'url'=>add_query_arg('ews_view','pay',$this->app_home_url())]);
            }
        }
        $this->payroll_redirect($back+['payroll_saved'=>'closed']);
    }

    public function payroll_reopen(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_reopen');
        global $wpdb;$this->ensure_payroll_schema();$t=$this->payroll_tables();
        $month=$this->payroll_month_post();
        $run=$month?$this->payroll_run($month):null;
        if(!$run)$this->payroll_redirect(['month'=>$month,'payroll_error'=>'not_closed']);
        $wpdb->delete($t['payslips'],['run_id'=>(int)$run->id],['%d']);
        $wpdb->delete($t['runs'],['id'=>(int)$run->id],['%d']);
        $this->audit('payroll_reopened','payroll',(int)$run->id,$month);
        $this->payroll_redirect(['month'=>$month,'payroll_saved'=>'reopened']);
    }

    /** My Pay: switched on by an administrator, for an employee with a salary or a payslip. */
    private function payroll_view_available(){
        if(!$this->option('ews_payroll_employee_view'))return false;
        $emp=$this->current_employee();
        if(!$emp)return false;
        global $wpdb;$this->ensure_payroll_schema();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$this->payroll_table()} WHERE employee_id=%d LIMIT 1",(int)$emp->id))
            || (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$this->payroll_tables()['payslips']} WHERE employee_id=%d LIMIT 1",(int)$emp->id));
    }

    /** Employee app → My Pay: a closed month's payslip, or the current month as an estimate. Only the employee's own. */
    private function pay_content(){
        $emp=$this->current_employee();
        if(!$emp)return $this->ews_empty_state('Employee profile required','Your account is not linked to an active employee.');
        global $wpdb;$t=$this->payroll_tables();
        $closed=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT r.month,r.closed_at,s.data FROM {$t['payslips']} s JOIN {$t['runs']} r ON r.id=s.run_id WHERE s.employee_id=%d ORDER BY r.month DESC",(int)$emp->id)) as $r){
            $d=json_decode((string)$r->data,true);
            if(is_array($d)&&isset($d['pay']))$closed[(string)$r->month]=['pay'=>$d['pay'],'currency'=>(string)($d['currency']??''),'rules'=>(array)($d['rules']??[]),'closed_at'=>(string)$r->closed_at];
        }
        $current=current_time('Y-m');
        $months=array_keys($closed);if(!in_array($current,$months,true))$months[]=$current;
        rsort($months);
        $req=sanitize_text_field(wp_unslash($_GET['month']??''));
        $month=preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$req)?$req:($closed?array_key_first($closed):$current);
        $rules=$this->payroll_rules();
        $slip=null;$final=false;$closed_at='';$currency=$rules['currency'];$used_rules=$rules;
        if(isset($closed[$month])){
            $slip=$closed[$month]['pay'];$final=true;$closed_at=$closed[$month]['closed_at'];
            if($closed[$month]['currency']!=='')$currency=$closed[$month]['currency'];
            if($closed[$month]['rules'])$used_rules=$closed[$month]['rules']+$rules;
        }elseif($month===$current){
            $rows=$this->payroll_month($month,(int)$emp->id);
            $slip=$rows[0]['pay']??null;
        }
        $i=array_search($month,$months,true);
        $previous=date('Y-m',strtotime('first day of last month',current_time('timestamp')));
        wp_enqueue_style('workforce-one-pay');
        return $this->render_template('app/pay',[
            'month'=>$month,'month_label'=>date('F Y',strtotime($month.'-01')),'slip'=>$slip,'final'=>$final,'closed_at'=>$closed_at,'currency'=>$currency,'rules'=>$used_rules,
            'older_url'=>$i!==false&&isset($months[$i+1])?add_query_arg(['ews_view'=>'pay','month'=>$months[$i+1]],$this->app_home_url()):'',
            'newer_url'=>$i!==false&&$i>0?add_query_arg(['ews_view'=>'pay','month'=>$months[$i-1]],$this->app_home_url()):'',
            'preparing'=>$month===$current&&!isset($closed[$previous])?date('F Y',strtotime($previous.'-01')):'',
            'pdf_url'=>isset($closed[$month])?wp_nonce_url(add_query_arg(['action'=>'ews_payslip_pdf','month'=>$month],admin_url('admin-post.php')),'ews_payslip_pdf'):'',
        ]);
    }

    /** A payslip row of a closed month: [data, closed_at] or null. */
    private function payroll_payslip($month,$employee_id){
        global $wpdb;$t=$this->payroll_tables();$this->ensure_payroll_schema();
        $r=$wpdb->get_row($wpdb->prepare("SELECT s.data,r.closed_at FROM {$t['payslips']} s JOIN {$t['runs']} r ON r.id=s.run_id WHERE r.month=%s AND s.employee_id=%d",$month,$employee_id));
        $d=$r?json_decode((string)$r->data,true):null;
        return is_array($d)&&isset($d['pay'])?[$d,(string)$r->closed_at]:null;
    }

    /** Send a closed month's payslip as a PDF download. */
    private function payroll_send_pdf($month,array $slip){
        [$d,$closed_at]=$slip;
        $font=\WorkforceOne\Pdf\TrueTypeFont::fromFile(dirname(__DIR__).'/assets/vendor/dejavu/DejaVuSans.ttf');
        $pdf=\WorkforceOne\Payroll\PayslipPdf::render($font,[
            'company'=>wp_specialchars_decode((string)get_bloginfo('name'),ENT_QUOTES),'employee'=>(string)($d['employee']['name']??''),'month_label'=>date('F Y',strtotime($month.'-01')),
            'closed_at'=>$closed_at,'currency'=>(string)($d['currency']??$this->payroll_rules()['currency']),'rules'=>(array)($d['rules']??[])+$this->payroll_rules(),'pay'=>$d['pay'],'generated'=>current_time('j M Y H:i'),
        ]);
        $name=sanitize_file_name('payslip-'.$month.'-'.remove_accents((string)($d['employee']['name']??'employee')));
        \WorkforceOne\Support\Download::send($pdf,'application/pdf',($name!==''?$name:'payslip-'.$month).'.pdf');
        exit;
    }

    /** wp-admin: any employee's payslip of a closed month. */
    public function payroll_pdf(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_pdf');
        $month=$this->payroll_month_param();$eid=absint($_GET['employee']??0);
        $slip=$this->payroll_payslip($month,$eid);
        if(!$slip)$this->payroll_redirect(['month'=>$month,'employee'=>$eid,'payroll_error'=>'not_closed']);
        $this->audit('payslip_downloaded','employee',$eid,$month);
        $this->payroll_send_pdf($month,$slip);
    }

    /** My Pay: the signed-in employee's own payslip of a closed month. */
    public function payslip_pdf(){
        if(!is_user_logged_in())wp_die('Access denied',403);
        check_admin_referer('ews_payslip_pdf');
        $emp=$this->current_employee();
        if(!$emp||!$this->option('ews_payroll_employee_view'))wp_die('Access denied',403);
        $month=$this->payroll_month_param();
        $slip=$this->payroll_payslip($month,(int)$emp->id);
        if(!$slip)wp_die('No payslip for this month.',404);
        $this->payroll_send_pdf($month,$slip);
    }

    public function payroll_rate_save(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_rate_save');
        global $wpdb;$this->ensure_payroll_schema();
        $eid=absint($_POST['employee_id']??0);
        $emp=$eid?$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->employees} WHERE id=%d",$eid)):null;
        if(!$emp)$this->payroll_redirect(['tab'=>'salaries','payroll_error'=>'employee']);
        $post=wp_unslash($_POST);
        $post['note']=sanitize_text_field((string)($post['note']??''));
        $post['allowance_name']=array_map('sanitize_text_field',array_map('strval',(array)($post['allowance_name']??[])));
        [$rate,$error]=PayRules::rate($post);
        if($error)$this->payroll_redirect(['tab'=>'salaries','employee'=>$eid,'payroll_error'=>$error]);
        $wpdb->insert($this->payroll_table(),['employee_id'=>$eid,'effective_from'=>$rate['effective_from'],'basic'=>$rate['basic'],'allowances'=>wp_json_encode($rate['allowances']),'note'=>$rate['note'],'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')],['%d','%s','%f','%s','%s','%d','%s']);
        // Never the amounts: the Audit Log is open to more people than payroll.
        $this->audit('pay_rate_saved','employee',$eid,$emp->name.': salary from '.$rate['effective_from']);
        $this->payroll_redirect(['tab'=>'salaries','payroll_saved'=>'rate']);
    }

    public function payroll_rate_delete(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_rate_delete');
        global $wpdb;$this->ensure_payroll_schema();
        $id=absint($_POST['rate_id']??0);
        $r=$id?$wpdb->get_row($wpdb->prepare("SELECT r.employee_id,r.effective_from,e.name FROM {$this->payroll_table()} r LEFT JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.id=%d",$id)):null;
        if(!$r)$this->payroll_redirect(['tab'=>'salaries','payroll_error'=>'not_found']);
        $wpdb->delete($this->payroll_table(),['id'=>$id],['%d']);
        $this->audit('pay_rate_deleted','employee',(int)$r->employee_id,(string)$r->name.': salary from '.$r->effective_from.' removed');
        $this->payroll_redirect(['tab'=>'salaries','payroll_saved'=>'deleted']);
    }

    public function payroll_rules_save(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_rules_save');
        [$rules,$error]=PayRules::rules(wp_unslash($_POST));
        if($error)$this->payroll_redirect(['tab'=>'rules','payroll_error'=>$error]);
        update_option('ews_payroll_currency',$rules['currency'],false);
        update_option('ews_payroll_day_divisor',$rules['day_divisor'],false);
        update_option('ews_payroll_day_base',$rules['day_base'],false);
        update_option('ews_payroll_absence_days',$rules['absence_days'],false);
        update_option('ews_payroll_overtime_rate',$rules['overtime_rate'],false);
        update_option('ews_payroll_overtime_rate_off',$rules['overtime_rate_off'],false);
        update_option('ews_payroll_max_deduction_days',$rules['max_deduction_days'],false);
        update_option('ews_payroll_employee_view',$rules['employee_view']?1:0,false);
        update_option('ews_payroll_late_mode',$rules['late_mode'],false);
        update_option('ews_payroll_late_tiers',$rules['late_tiers'],false);
        $this->audit('payroll_rules_saved','settings',0,'Payroll rules');
        $this->payroll_redirect(['tab'=>'rules','payroll_saved'=>'rules']);
    }

    public function payroll_export(){
        if(!$this->can('ews_manage_payroll'))wp_die('Access denied');
        check_admin_referer('ews_payroll_export');
        if(!class_exists('ZipArchive'))wp_die('The PHP ZipArchive extension is required for Excel export.');
        $month=$this->payroll_month_param();
        $run=$this->payroll_run($month);
        $people=$run?$this->payroll_payslips((int)$run->id):array_values(array_filter($this->payroll_month($month),function($x){return $x['pay']!==null;}));
        $headers=['Employee','Basic','Allowances','Monthly pay','Paid for','Overtime (h:mm)','Overtime pay','Absent days','Absence','Late (min)','Late','Early leave (min)','Early leave','Unpaid leave days','Unpaid leave','Attendance deductions','Bonuses','Other deductions','Net pay','Days to review'];
        $rows=[];$days=[];
        foreach($people as $x){
            $p=$x['pay'];$name=(string)$x['employee']->name;
            $rows[]=[$name,$p['basic'],$p['allowances_total'],$p['monthly'],$p['prorated']?'From '.$p['prorated']['from']:'Full month',PayCalculator::hm((int)$p['overtime']['minutes']),$p['overtime']['amount'],
                count($p['absence']['days']),-$p['absence']['amount'],(int)$p['late']['minutes'],-$p['late']['amount'],(int)$p['early']['minutes'],-$p['early']['amount'],
                count($p['leave']['days']),-$p['leave']['amount'],-$p['deductions'],$p['bonuses']['amount'],-$p['manual']['amount'],$p['net'],count($p['review'])];
            foreach($p['absence']['days'] as $d)$days[]=[$name,$d['date'],'Absent','',-$d['amount']];
            foreach($p['late']['days'] as $d)$days[]=[$name,$d['date'],'Late arrival (in '.$d['sign_in'].')',$d['minutes'],-$d['amount']];
            foreach($p['early']['days'] as $d)$days[]=[$name,$d['date'],'Early leave without approval (out '.$d['sign_out'].')',$d['minutes'],-$d['amount']];
            foreach($p['leave']['days'] as $d)$days[]=[$name,$d['date'],$d['type'].' ('.$d['paid'].'% paid)','',-$d['amount']];
            foreach($p['overtime']['days'] as $d)$days[]=[$name,$d['date'],'Overtime × '.$d['rate'].($d['off']?' (day off)':''),$d['minutes'],$d['amount']];
            foreach($p['review'] as $d)$days[]=[$name,$d['date'],'To review: '.$d['reason'],'',''];
            foreach($p['bonuses']['items'] as $a)$days[]=[$name,$month,'Bonus: '.$a['reason'],'',$a['amount']];
            foreach($p['manual']['items'] as $a)$days[]=[$name,$month,'Deduction: '.$a['reason'],'',-$a['amount']];
        }
        $label=date('F Y',strtotime($month.'-01'));
        $xlsx=$this->payroll_xlsx([
            ['Payroll '.$label,$headers,$rows,[26,12,12,12,16,14,12,12,12,10,12,14,12,14,12,14,12,14,14,12]],
            ['Days',['Employee','Date','Item','Minutes','Amount'],$days,[26,12,44,10,12]],
        ]);
        $this->audit('payroll_exported','payroll',0,$month.' ('.count($people).' employees'.($run?', closed':'').')');
        \WorkforceOne\Support\Download::send($xlsx,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','payroll-'.$month.'.xlsx');
        exit;
    }

    /**
     * A plain workbook: per sheet [name, headers, rows, column widths]. Numbers stay numbers.
     * @param array<int,array{0:string,1:array<int,string>,2:array<int,array<int,mixed>>,3:array<int,int>}> $sheets
     */
    private function payroll_xlsx(array $sheets){
        $tmp=wp_tempnam('workforce-one-payroll.xlsx');$zip=new ZipArchive();
        if($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)wp_die('Could not create Excel workbook.');
        $entries='';$rels='';$overrides='';
        foreach($sheets as $i=>[$name,$headers,$rows,$widths]){
            $n=$i+1;
            $xml='<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
            foreach($headers as $c=>$h)$xml.='<col min="'.($c+1).'" max="'.($c+1).'" width="'.(int)($widths[$c]??14).'" customWidth="1"/>';
            $xml.='</cols><sheetData>';
            foreach(array_merge([$headers],$rows) as $r=>$row){
                $xml.='<row r="'.($r+1).'">';
                foreach(array_values($row) as $c=>$v){
                    $ref=$this->report_xlsx_col($c+1).($r+1);
                    if($r>0&&(is_int($v)||is_float($v)))$xml.='<c r="'.$ref.'" s="2"><v>'.(is_float($v)?round($v,2):$v).'</v></c>';
                    else $xml.='<c r="'.$ref.'" s="'.($r===0?1:0).'" t="inlineStr"><is><t xml:space="preserve">'.$this->report_xlsx_escape($v).'</t></is></c>';
                }
                $xml.='</row>';
            }
            $xml.='</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml);
            $entries.='<sheet name="'.$this->report_xlsx_escape(mb_substr($name,0,31)).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels.='<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $overrides.='<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $rels.='<Relationship Id="rId'.(count($sheets)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$entries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
        $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
        $data=(string)file_get_contents($tmp);@unlink($tmp);
        return $data;
    }
}
