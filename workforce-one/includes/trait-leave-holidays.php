<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\HolidayRefund;

/**
 * Company holidays are not leave days (3.31.162, docs/tasks/leave-holidays.md, mockup docs/mockups/leave-holidays/).
 * One switch, off by default; off, every leave counts exactly as before and the report is not shown.
 * - On: a new leave is counted without the company holidays inside it (vacation_working_days_count()) and its approval puts no
 *   leave day on them (the leave keeps them in holidays_skipped, so its leave days always match what it charges).
 * - The report (wp-admin → Leaves → Company holidays): the approved leaves of this leave year, charged to the balance, with a
 *   leave day on an active company holiday (charged before the switch, or a holiday added after the leave was asked). HR gives those days back: the day leaves the leave (its schedule goes
 *   back as it was before the leave, unless a manager changed it), the leave charges one day less, the balance gets it back, the
 *   employee is told, and the Audit Log keeps it. A day is never given back twice (ews_leave_holiday_refunds' unique key, and the
 *   leave day is gone). Pending leaves and leaves with a change waiting are decided first (owner, 2026-11-24).
 */
trait EWS_Leave_Holidays_Trait {

    /** The active company holidays between two days: Y-m-d => title (one per day). @return array<string,string> */
    private function leave_company_holidays($start,$end){
            $out=[];
            foreach($this->services()->scheduleDays()->companyHolidays((string)$start,(string)$end) as $e)if(!isset($out[(string)$e->event_date]))$out[(string)$e->event_date]=(string)$e->title;
            return $out;
        }

    /** The company holidays between two days (Y-m-d, ascending); none with the switch off, so nothing changes then. @return string[] */
    private function leave_holiday_dates($start,$end){
            if(!$this->leave_holidays_on() || !$this->valid_date((string)$start) || !$this->valid_date((string)$end) || (string)$end<(string)$start)return [];
            $days=array_map('strval',array_keys($this->leave_company_holidays((string)$start,(string)$end)));
            sort($days);
            return $days;
        }

    /**
     * The holidays a new leave is counted without (sorted Y-m-d), from one reading of the switch: those of its working days that are
     * company holidays, when $days was counted without them; [] when $days counts every working day (the switch was off, or turned
     * on meanwhile); null when $days matches neither (the calendar changed meanwhile: not saved). @return string[]|null
     */
    private function leave_holidays_skipped($start,$end,$days){
            $all=\WorkforceOne\Leave\WorkingDays::dates((string)$start,(string)$end,$this->working_days());
            $skip=array_values(array_intersect($all,$this->leave_holiday_dates($start,$end)));
            if(abs(count($all)-count($skip)-(float)$days)<0.001)return $skip;
            return abs(count($all)-(float)$days)<0.001?[]:null;
        }

    /**
     * The report's leaves: approved, charged to this leave year's balance (or next year's, for a leave already asked for it; never a
     * past year, owner 2026-11-24), with a leave day on a company holiday. @return array<int,array<string,mixed>> one row per leave, by employee name
     */
    private function leave_holiday_report($year){
            global $wpdb;
            $hol=$this->leave_company_holidays(sprintf('%04d-01-01',$year-1),sprintf('%04d-12-31',$year+2));
            if(!$hol)return [];
            $out=[];$req=$wpdb->prefix.'ews_leave_requests';$snap=$wpdb->prefix.'ews_leave_schedule_snapshots';
            foreach(array_chunk(array_keys($hol),300) as $chunk){
                $in=implode(',',array_fill(0,count($chunk),'%s'));
                $rows=(array)$wpdb->get_results($wpdb->prepare(
                    "SELECT DISTINCT s.leave_request_id FROM {$snap} s JOIN {$req} r ON r.id=s.leave_request_id
                     JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id
                     LEFT JOIN {$wpdb->prefix}ews_leave_balances b ON b.id=r.balance_id
                     WHERE s.work_date IN ({$in}) AND r.status='Approved' AND lt.deduct_balance=1
                       AND (b.leave_year>=%d OR (b.id IS NULL AND YEAR(r.requested_at)>=%d))",array_merge($chunk,[(int)$year,(int)$year])));
                foreach($rows as $x)$out[(int)$x->leave_request_id]=true;
            }
            if(!$out)return [];
            $ids=array_keys($out);$list=[];
            foreach(array_chunk($ids,300) as $chunk){
                $in=implode(',',array_fill(0,count($chunk),'%d'));
                $leaves=(array)$wpdb->get_results($wpdb->prepare("SELECT r.id,r.employee_id,r.start_date,r.end_date,r.requested_days,r.status,r.cancellation_status,lt.name type_name,lt.deduct_balance
                    FROM {$req} r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.id IN ({$in})",$chunk));
                $days=[];
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT leave_request_id,work_date FROM {$snap} WHERE leave_request_id IN ({$in}) ORDER BY work_date ASC",$chunk)) as $s)$days[(int)$s->leave_request_id][]=(string)$s->work_date;
                foreach($leaves as $l){
                    $d=$days[(int)$l->id]??[];
                    $back=HolidayRefund::candidates($d,array_keys($hol),[],(float)$l->requested_days);
                    if(!$back)continue;
                    $emp=$this->people_employee((int)$l->employee_id);
                    $list[]=['leave_id'=>(int)$l->id,'employee_id'=>(int)$l->employee_id,'employee'=>$emp?(string)$emp->name:'#'.(int)$l->employee_id,
                        'type'=>(string)$l->type_name,'start'=>(string)$l->start_date,'end'=>(string)$l->end_date,
                        'days'=>array_map(function($x)use($hol){return ['day'=>$x,'holiday'=>isset($hol[$x])?(string)$hol[$x]:null];},$d),
                        'charged'=>(float)$l->requested_days,'back'=>count($back),'should'=>max(0,(float)$l->requested_days-count($back)),
                        'partly'=>(string)$l->cancellation_status==='Approved',
                        'restorable'=>HolidayRefund::restorable((string)$l->status,(bool)(int)$l->deduct_balance,$l->cancellation_status!==null?(string)$l->cancellation_status:null)];
                }
            }
            usort($list,function($a,$b){return [$a['employee'],$a['start']]<=>[$b['employee'],$b['start']];});
            return $list;
        }

    /** The "Company holidays" tab of wp-admin → Leaves. */
    private function leave_admin_holidays_view(){
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only messages after a redirect
            $year=$this->leave_year();
            $error=sanitize_key($_GET['lh_error']??'');
            return ['tab'=>'holidays','return_tabs'=>$this->leave_return_on(),'year'=>$year,'rows'=>$this->leave_holiday_report($year),
                'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-leaves'),
                'done_days'=>isset($_GET['lh_days'])?absint($_GET['lh_days']):null,'done_leaves'=>absint($_GET['lh_leaves']??0),
                'error'=>$error!==''?$this->leave_holiday_error($error):''];
            // phpcs:enable
        }

    private function leave_holiday_error($code){
            $m=['none'=>__('Choose at least one leave.','workforce-one'),'busy'=>__('Another change of this employee\'s leave is being saved. Try again in a moment.','workforce-one'),
                'some'=>__('Some leaves were not changed: they changed meanwhile, or a change is waiting for a decision. The list shows them as they are now.','workforce-one')];
            return $m[$code]??__('Could not save. Nothing was changed.','workforce-one');
        }

    /** HR gives back the company holidays charged on the chosen leaves (one or several). */
    public function admin_leave_holiday_restore(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');
            check_admin_referer('ews_leave_holiday_restore');
            $this->ensure_leave_schema();
            if(!$this->leave_holidays_on())wp_die(esc_html__('Company holidays are not leave days is off.','workforce-one'));
            $back=['tab'=>'holidays'];
            // A row's own button gives back that leave only, whatever else is ticked.
            $one=absint($_POST['one']??0);
            $ids=$one?[$one]:array_slice(array_values(array_unique(array_filter(array_map('absint',(array)($_POST['leave_ids']??[]))))),0,200);
            if(!$ids)$this->leave_admin_redirect($back+['lh_error'=>'none']);
            $days=0;$leaves=0;$failed=0;$busy=0;
            foreach($ids as $id){
                $r=$this->leave_request_row($id);
                if(!$r){$failed++;continue;}
                $emp=$this->people_employee((int)$r->employee_id);
                if(!$emp){$failed++;continue;}
                $done=[];
                $res=$this->ews_with_lock('leave_emp_'.(int)$r->employee_id,function()use($id,$emp,&$done){
                    global $wpdb;
                    if($wpdb->query('START TRANSACTION')===false)return 'save';
                    $res=$this->leave_holiday_restore_locked($id,$emp,current_time('mysql'),$done);
                    if($res!==true){$wpdb->query('ROLLBACK');return $res;}
                    if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return 'save';}
                    return true;
                },'busy');
                if($res==='busy')$busy++;
                if($res!==true || !$done){$failed++;continue;}
                $days+=count($done['days']);$leaves++;
                $this->audit('leave_holiday_refund','leave_request',(int)$id,$done['type'].' '.$done['start'].' – '.$done['end'].': '.count($done['days']).' company holiday(s) given back ('.implode(', ',$done['days']).'), '.$done['before'].' → '.$done['after'].' day(s)'.($done['top']>0?', next year +'.$done['top'].' carried':''));
                if(!empty($emp->wp_user_id)){
                    // Stored in English, as the other leave notifications.
                    $msg=sprintf('%1$d day(s) went back to your balance: your %2$s leave (%3$s – %4$s) was charged for company holidays (%5$s). It now counts %6$s day(s).',
                        count($done['days']),$done['type'],$done['start'],$done['end'],implode(', ',$done['days']),$done['after']);
                    $this->notify((int)$emp->wp_user_id,'leave','Leave days given back',$msg,['entity_id'=>(int)$id]);
                }
            }
            $this->leave_admin_redirect($back+['lh_days'=>$days,'lh_leaves'=>$leaves]+($failed?['lh_error'=>$busy?'busy':'some']:[]));
        }

    /**
     * One leave's company holidays given back, under the employee's lock and in the caller's transaction, the leave row locked.
     * @param array<string,mixed> $done filled with what was given back
     * @return true|string an error code ('done' = nothing to give back any more)
     */
    private function leave_holiday_restore_locked($id,$emp,$now,&$done){
            global $wpdb;
            $r=$this->leave_lock_row($id);
            if(!$r || !HolidayRefund::restorable((string)$r->status,(bool)(int)$r->deduct_balance,$r->cancellation_status!==null?(string)$r->cancellation_status:null))return 'done';
            $bal=$this->leave_request_balance($r);
            if(!$bal || (int)$bal->leave_year<$this->leave_year())return 'done';   // owner: not a past leave year (this one, or a leave already charged to next year)
            $snap=$wpdb->prefix.'ews_leave_schedule_snapshots';$ref=$wpdb->prefix.'ews_leave_holiday_refunds';
            $lock=(defined('DB_ENGINE') && DB_ENGINE==='sqlite')?'':' FOR UPDATE';
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$snap} WHERE leave_request_id=%d ORDER BY work_date ASC{$lock}",(int)$r->id));
            $hol=$this->leave_company_holidays((string)$r->start_date,(string)$r->end_date);
            $given=array_map('strval',(array)$wpdb->get_col($wpdb->prepare("SELECT work_date FROM {$ref} WHERE leave_request_id=%d",(int)$r->id)));
            $days=HolidayRefund::candidates(array_map(function($x){return (string)$x->work_date;},$rows),array_keys($hol),$given,(float)$r->requested_days);
            if(!$days)return 'done';
            $by=[];foreach($rows as $x)$by[(string)$x->work_date]=$x;
            foreach($days as $day){
                $q=$wpdb->insert($ref,['leave_request_id'=>(int)$r->id,'employee_id'=>(int)$r->employee_id,'work_date'=>$day,'days'=>1,'balance_id'=>(int)$bal->id,
                    'holiday'=>mb_substr((string)$hol[$day],0,190),'created_by'=>(int)get_current_user_id(),'created_at'=>$now],['%d','%d','%s','%f','%d','%s','%d','%s']);
                if($q!==1)return 'done';   // given back meanwhile (the unique key)
                if(!$this->leave_return_restore_day($by[$day],$emp,$now,(int)$r->id))return 'save';
                if($wpdb->delete($snap,['id'=>(int)$by[$day]->id])!==1)return 'save';
            }
            $n=(float)count($days);$before=(float)$r->requested_days;$after=max(0.0,$before-$n);
            $q=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_requests SET requested_days=%f WHERE id=%d AND status='Approved' AND requested_days=%f",$after,(int)$r->id,$before));
            if($q!==1)return $q===false?'save':'done';
            if($wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}ews_leave_balances SET used=GREATEST(0,used-%f),updated_at=%s WHERE id=%d",$n,$now,(int)$bal->id))===false)return 'save';
            $top=$this->leave_carry_top_up($r,$bal,$n,$now);
            if($top===false)return 'save';
            $this->week_lock_record_request((int)$emp->id,$days,'leave_holiday_refund','leave #'.(int)$r->id);
            $num=function($x){return rtrim(rtrim(number_format((float)$x,2,'.',''),'0'),'.');};
            $done=['days'=>$days,'type'=>(string)$r->type_name,'start'=>(string)$r->start_date,'end'=>(string)$r->end_date,'before'=>$num($before),'after'=>$num($after),'top'=>(float)$top];
            return true;
        }
}
