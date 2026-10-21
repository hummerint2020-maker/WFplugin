<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\ShiftPeriods;

/**
 * Shift history (3.31.108; owner report: changing an employee's shift changed old reports; owner
 * decisions 2026-10-17, mockup docs/mockups/shift-history/).
 *
 * - ews_employee_shift_periods: each employee's shift from a day to a day (the last one open). A new
 *   period closes the one before it, so they never overlap and leave no gap. A past day is for HR
 *   (ews_manage_settings) only, to fix a mistake; a future day waits until then.
 * - ews_shift_versions: a shift's times from a day (shift 0 = the company working hours). Editing a
 *   shift's times asks from when (today by default); the days before keep the old times.
 * - working_hours($employee_id, $date) counts every day with the shift and the times of that day:
 *   Sign In, lateness, overtime, early leave, payroll, the reports.
 * Today's shift is the employee's shift as saved (employees.default_shift_id, kept in step when a new
 * period starts), and the latest period and the latest times follow what is saved now: a change made
 * without these pages (an import, a script) still counts as before; only the days before a recorded
 * change keep the older shift and times. On update every employee gets one period from 2000-01-01 with
 * their shift then, and every shift its times then: changes made before 3.31.108 were not kept.
 * Rules: src/Schedule/ShiftPeriods.php.
 * Behaviour: tests/e2e_shift_history.py.
 */
trait EWS_Shift_History_Trait {

    /** @return array{periods:string,versions:string} */
    private function shift_history_tables(){
        global $wpdb;
        return ['periods'=>$wpdb->prefix.'ews_employee_shift_periods','versions'=>$wpdb->prefix.'ews_shift_versions'];
    }

    private function ensure_shift_history_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=$this->shift_history_tables();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t['periods']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            shift_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            from_date DATE NOT NULL,
            to_date DATE NULL,
            reason VARCHAR(190) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY employee_from (employee_id,from_date)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['versions']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shift_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            from_date DATE NOT NULL,
            start_time CHAR(5) NOT NULL,
            end_time CHAR(5) NOT NULL,
            grace SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            sign_in_cutoff_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 240,
            overnight TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY shift_from (shift_id,from_date)
        ) {$c};");
        $this->shift_history_seed();
    }

    /** The history as it is on update: one period per employee, one version per shift, from the beginning. */
    private function shift_history_seed(){
        global $wpdb;$t=$this->shift_history_tables();$now=current_time('mysql');$from=ShiftPeriods::BEGINNING;
        if(!(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t['periods']}")){
            foreach($this->people_all_shift_ids() as $eid=>$sid)$wpdb->insert($t['periods'],['employee_id'=>$eid,'shift_id'=>$sid,'from_date'=>$from,'to_date'=>null,'reason'=>'','created_by'=>0,'created_at'=>$now]);
        }
        $have=array_map('intval',(array)$wpdb->get_col("SELECT DISTINCT shift_id FROM {$t['versions']}"));
        $raw=get_option('ews_shifts');
        foreach(is_array($raw)?$raw:[] as $s){
            $id=absint($s['id']??0);
            if(!$id || in_array($id,$have,true) || !preg_match('/^\d{2}:\d{2}$/',(string)($s['start']??'')) || !preg_match('/^\d{2}:\d{2}$/',(string)($s['end']??'')))continue;
            $this->shift_version_insert($id,$from,$s);
        }
        if(!in_array(0,$have,true)){
            $h=$this->company_hours_now();
            $this->shift_version_insert(0,$from,$h);
        }
    }

    /** The company working hours as saved (no history), for the first version. @return array<string,mixed> */
    private function company_hours_now(){
        $cfg=$this->option('ews_working_hours');$d=$this->default_working_hours();if(!is_array($cfg))$cfg=[];
        $ok=function($v){return is_string($v)&&preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$v);};
        return ['start'=>$ok($cfg['start']??null)?$cfg['start']:$d['start'],'end'=>$ok($cfg['end']??null)?$cfg['end']:$d['end'],
            'grace'=>$this->global_grace_period(),'sign_in_cutoff_minutes'=>240,'overnight'=>(int)$this->option('ews_allow_overnight_shift')];
    }

    private function shift_version_insert($shift_id,$from,array $s){
        global $wpdb;$t=$this->shift_history_tables();
        $wpdb->delete($t['versions'],['shift_id'=>(int)$shift_id,'from_date'=>$from],['%d','%s']);
        $wpdb->insert($t['versions'],['shift_id'=>(int)$shift_id,'from_date'=>$from,'start_time'=>(string)$s['start'],'end_time'=>(string)$s['end'],
            'grace'=>max(0,min(180,(int)($s['grace']??10))),'sign_in_cutoff_minutes'=>max(0,min(1440,(int)($s['sign_in_cutoff_minutes']??240))),'overnight'=>!empty($s['overnight'])?1:0,
            'created_by'=>(int)get_current_user_id(),'created_at'=>current_time('mysql')]);
        self::$ews_shift_versions_cache=null;self::$ews_working_hours_cache=[];self::$ews_shift_for_employee_cache=[];self::$ews_shifts_cache=null;
    }

    /**
     * Before new times are saved from $from (3.31.116): record the times the shift counts with now ($live, the option) where the history
     * would otherwise lose them, so the days before $from keep exactly what they are counted with today (ShiftPeriods::settle).
     * @param array<string,mixed> $live @return string the settle action (beginning | rewrite | none | later)
     */
    private function shift_version_settle($shift_id,$from,array $live){
        $all=$this->shift_versions_all();
        $do=ShiftPeriods::settle($all[(int)$shift_id]??[],(string)$from,$live);
        if($do['action']==='beginning')$this->shift_version_insert($shift_id,ShiftPeriods::BEGINNING,$live);
        elseif($do['action']==='rewrite'){
            global $wpdb;$t=$this->shift_history_tables();
            // Only the times: the row keeps its id, its day and who made it.
            $wpdb->update($t['versions'],['start_time'=>(string)$live['start'],'end_time'=>(string)$live['end'],'grace'=>max(0,min(180,(int)($live['grace']??10))),
                'sign_in_cutoff_minutes'=>max(0,min(1440,(int)($live['sign_in_cutoff_minutes']??240))),'overnight'=>!empty($live['overnight'])?1:0],
                ['shift_id'=>(int)$shift_id,'from_date'=>(string)$do['from']]);
            self::$ews_shift_versions_cache=null;self::$ews_working_hours_cache=[];self::$ews_shift_for_employee_cache=[];self::$ews_shifts_cache=null;
        }
        return $do['action'];
    }

    /** A later dated change already saved for this shift (3.31.116: saving times from an earlier day is refused). @return string|null its day */
    private function shift_version_later($shift_id,$from){
        $all=$this->shift_versions_all();
        $do=ShiftPeriods::settle($all[(int)$shift_id]??[],(string)$from,[]);
        return $do['action']==='later'?(string)$do['from']:null;
    }

    /** Every shift's dated times. @return array<int,list<array<string,mixed>>> shift id => versions */
    private function shift_versions_all(){
        if(self::$ews_shift_versions_cache!==null)return self::$ews_shift_versions_cache;
        global $wpdb;$t=$this->shift_history_tables();$out=[];
        $rows=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['versions']))===$t['versions']?(array)$wpdb->get_results("SELECT * FROM {$t['versions']} ORDER BY shift_id,from_date"):[];
        foreach($rows as $r)$out[(int)$r->shift_id][]=['from'=>(string)$r->from_date,'start'=>(string)$r->start_time,'end'=>(string)$r->end_time,'grace'=>(int)$r->grace,'sign_in_cutoff_minutes'=>(int)$r->sign_in_cutoff_minutes,'overnight'=>(int)$r->overnight];
        return self::$ews_shift_versions_cache=$out;
    }

    /**
     * A shift's times on a day (shift 0 = company hours), or null when it has no history. The latest
     * version is the shift as saved now (the option), so a change made without the Schedule
     * Configuration page (an import, a script) still counts; only the days before a recorded change
     * keep older times.
     */
    private function shift_times_on($shift_id,$date){
        $all=$this->shift_versions_all();
        if(!isset($all[(int)$shift_id]))return null;
        $v=ShiftPeriods::version($all[(int)$shift_id],(string)$date);
        if(!$v)return null;
        if(!ShiftPeriods::isLatest($all[(int)$shift_id],$v))return $v;
        $now=(int)$shift_id===0?$this->company_hours_now():$this->shift_saved((int)$shift_id);
        return $now?['from'=>$v['from']]+$now:$v;
    }

    /** A shift as saved now (the option), without its history. @return array<string,mixed>|null */
    private function shift_saved($shift_id){
        $raw=get_option('ews_shifts');
        foreach(is_array($raw)?$raw:[] as $s){
            if(absint($s['id']??0)!==(int)$shift_id)continue;
            if(!preg_match('/^\d{2}:\d{2}$/',(string)($s['start']??'')) || !preg_match('/^\d{2}:\d{2}$/',(string)($s['end']??'')))return null;
            return ['start'=>(string)$s['start'],'end'=>(string)$s['end'],'grace'=>max(0,min(180,(int)($s['grace']??10))),'sign_in_cutoff_minutes'=>max(0,min(1440,(int)($s['sign_in_cutoff_minutes']??240))),'overnight'=>!empty($s['overnight'])?1:0];
        }
        return null;
    }

    /** An employee's shift periods, oldest first. @return list<array{id:int,from:string,to:string|null,shift_id:int,reason:string,created_by:int,created_at:string}> */
    private function shift_periods_of($employee_id){
        $employee_id=(int)$employee_id;
        if(isset(self::$ews_shift_periods_cache[$employee_id]))return self::$ews_shift_periods_cache[$employee_id];
        $this->shift_prime_periods([$employee_id]);
        return self::$ews_shift_periods_cache[$employee_id]??[];
    }

    /** Load many employees' periods in one query (reports, payroll). @param int[] $ids */
    private function shift_prime_periods(array $ids){
        $ids=array_values(array_filter(array_map('intval',$ids),function($i){return $i>0 && !isset(self::$ews_shift_periods_cache[$i]);}));
        if(!$ids)return;
        global $wpdb;$t=$this->shift_history_tables();
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['periods']))!==$t['periods']){foreach($ids as $id)self::$ews_shift_periods_cache[$id]=[];return;}
        foreach($ids as $id)self::$ews_shift_periods_cache[$id]=[];
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['periods']} WHERE employee_id IN ($ph) ORDER BY from_date",$chunk)) as $r)
                self::$ews_shift_periods_cache[(int)$r->employee_id][]=['id'=>(int)$r->id,'from'=>(string)$r->from_date,'to'=>$r->to_date!==null&&$r->to_date!==''?(string)$r->to_date:null,'shift_id'=>(int)$r->shift_id,'reason'=>(string)$r->reason,'created_by'=>(int)$r->created_by,'created_at'=>(string)$r->created_at];
        }
    }

    /** The shift id an employee was on on a day (0 = company hours); the employee's shift when there is no history. */
    private function shift_id_on($employee_id,$date){
        if((string)$date===current_time('Y-m-d'))return $this->people_shift_id((int)$employee_id);
        $periods=$this->shift_periods_of((int)$employee_id);
        $p=ShiftPeriods::on($periods,(string)$date);
        // The period running now is the employee's shift as saved (a change made elsewhere still counts).
        if($p && !(ShiftPeriods::isLatest($periods,$p) && $p['from']<=current_time('Y-m-d')))return (int)$p['shift_id'];
        return $this->people_shift_id((int)$employee_id);
    }

    /**
     * A new period: the employee is on $shift_id from $from. The period before ends the day before; one
     * on the same day is replaced. The employee's shift now (employees.default_shift_id) follows.
     * @return true|string a refusal code (date, shift, past)
     */
    private function shift_period_add($employee_id,$shift_id,$from,$reason='',$check_past=true){
        $employee_id=(int)$employee_id;$shift_id=(int)$shift_id;$today=current_time('Y-m-d');
        $shift_ok=$shift_id===0;
        foreach($this->shifts() as $s)if((int)$s['id']===$shift_id)$shift_ok=true;
        $error=ShiftPeriods::check((string)$from,$today,!$check_past || $this->can('ews_manage_settings'),$shift_ok);
        if($error!==null)return $error;
        global $wpdb;$t=$this->shift_history_tables();$now=current_time('mysql');
        $periods=$this->shift_periods_of($employee_id);
        if(!$periods){
            // No history yet (an employee added before the history, or by an import): what was before stays.
            $old=$this->people_shift_id($employee_id);
            $wpdb->insert($t['periods'],['employee_id'=>$employee_id,'shift_id'=>$old,'from_date'=>ShiftPeriods::BEGINNING,'to_date'=>null,'reason'=>'','created_by'=>0,'created_at'=>$now]);
            unset(self::$ews_shift_periods_cache[$employee_id]);$periods=$this->shift_periods_of($employee_id);
        }
        $plan=ShiftPeriods::insert($periods,(string)$from);
        if($plan['replace']){
            $wpdb->update($t['periods'],['shift_id'=>$shift_id,'reason'=>mb_substr((string)$reason,0,190),'created_by'=>(int)get_current_user_id(),'created_at'=>$now],['id'=>$plan['replace']]);
        }else{
            if($plan['close'])$wpdb->update($t['periods'],['to_date'=>$plan['close_to']],['id'=>$plan['close']]);
            $wpdb->insert($t['periods'],['employee_id'=>$employee_id,'shift_id'=>$shift_id,'from_date'=>(string)$from,'to_date'=>$plan['to'],'reason'=>mb_substr((string)$reason,0,190),'created_by'=>(int)get_current_user_id(),'created_at'=>$now]);
        }
        unset(self::$ews_shift_periods_cache[$employee_id]);
        $this->shift_sync_current($employee_id);
        $this->audit('shift_period_set','employee',$employee_id,'shift #'.$shift_id.' from '.$from.($from<$today?' (a past day: those days are counted again)':'').($reason!==''?' · '.$reason:''));
        return true;
    }

    /** employees.default_shift_id = the shift of today (a change on a future day reaches it when the day comes). */
    private function shift_sync_current($employee_id){
        $p=ShiftPeriods::on($this->shift_periods_of((int)$employee_id),current_time('Y-m-d'));
        if($p)$this->people_set_shift_id((int)$employee_id,(int)$p['shift_id']);
        unset(self::$ews_shift_for_employee_cache[(int)$employee_id]);self::$ews_working_hours_cache=[];
    }

    /** Once a day: the employees whose new period starts today get it as their shift now. */
    public function shift_history_daily(){
        $today=current_time('Y-m-d');
        if(get_option('ews_shift_history_synced')===$today)return;
        update_option('ews_shift_history_synced',$today,true);
        global $wpdb;$t=$this->shift_history_tables();
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['periods']))!==$t['periods'])return;
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT employee_id FROM {$t['periods']} WHERE from_date<=%s AND from_date>=%s",$today,gmdate('Y-m-d',strtotime($today.' -7 days')))) as $eid)$this->shift_sync_current((int)$eid);
    }

    /** Remove a period that has not started; the one before takes its days back. */
    private function shift_period_remove($employee_id,$period_id){
        global $wpdb;$t=$this->shift_history_tables();
        $periods=$this->shift_periods_of((int)$employee_id);$p=null;
        foreach($periods as $x)if((int)$x['id']===(int)$period_id)$p=$x;
        if(!$p || $p['from']<=current_time('Y-m-d'))return false;
        $plan=ShiftPeriods::afterRemove($periods,(int)$period_id);
        $wpdb->delete($t['periods'],['id'=>(int)$period_id]);
        if($plan['extend'])$wpdb->update($t['periods'],['to_date'=>$plan['to']],['id'=>$plan['extend']]);
        unset(self::$ews_shift_periods_cache[(int)$employee_id]);
        $this->audit('shift_period_removed','employee',(int)$employee_id,'shift #'.$p['shift_id'].' from '.$p['from']);
        return true;
    }

    /** wp-admin → Employee Profile → Change the shift (admin-post ews_shift_period_save / ews_shift_period_remove). */
    public function shift_period_save(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        $employee_id=absint($_POST['employee_id']??0);
        check_admin_referer('ews_shift_period_'.$employee_id);
        $back=admin_url('admin.php?page=ews31-employee-profile&employee_id='.$employee_id);
        if(!$this->people_employee($employee_id)){wp_safe_redirect(add_query_arg('shift_error','date',$back));exit;}
        if(isset($_POST['remove'])){
            $ok=$this->shift_period_remove($employee_id,absint($_POST['remove']));
            wp_safe_redirect(add_query_arg($ok?['shift_done'=>'removed']:['shift_error'=>'date'],$back).'#ews-shift-history');exit;
        }
        $r=$this->shift_period_add($employee_id,absint($_POST['shift_id']??0),sanitize_text_field(wp_unslash($_POST['from_date']??'')),sanitize_text_field(wp_unslash($_POST['reason']??'')));
        wp_safe_redirect(add_query_arg($r===true?['shift_done'=>'saved']:['shift_error'=>$r],$back).'#ews-shift-history');exit;
    }

    /** Record new versions for the shifts whose times changed, from $from (Schedule Configuration). @param array<int,array<string,mixed>> $before @param array<int,array<string,mixed>> $after */
    private function shift_versions_record(array $before,array $after,$from){
        $old=[];foreach($before as $s)$old[(int)$s['id']]=$s;
        $n=0;
        foreach($after as $s){
            $id=(int)$s['id'];
            if(!isset($old[$id])){$this->shift_version_insert($id,ShiftPeriods::BEGINNING,$s);continue;}   // a new shift: its times from the start
            if(!ShiftPeriods::timesChanged($old[$id],$s))continue;
            $this->shift_version_settle($id,$from,$old[$id]);   // the days before $from keep what they count with now (3.31.116)
            $this->shift_version_insert($id,$from,$s);$n++;
        }
        return $n;
    }

    /** A "from" date posted with new times: today when empty, else a valid day (a past one only for HR). @return string|null */
    private function shift_times_from(){
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller checked its nonce
        $from=sanitize_text_field(wp_unslash($_POST['times_from']??''));
        $today=current_time('Y-m-d');
        if($from==='')return $today;
        return ShiftPeriods::check($from,$today,$this->can('ews_manage_settings'),true)===null?$from:null;
    }

    /** For the Employee Profile: the periods, newest first, ready to show. @return array<string,mixed> */
    private function shift_history_view($employee_id){
        $names=[0=>__('Company working hours','workforce-one')];
        foreach($this->shifts() as $s)$names[(int)$s['id']]=$s['name'];
        $today=current_time('Y-m-d');$rows=[];
        foreach(array_reverse($this->shift_periods_of((int)$employee_id)) as $p){
            $h=$this->working_hours((int)$employee_id,$p['to']!==null&&$p['to']<$today?$p['to']:max($p['from'],$today));
            $rows[]=['id'=>$p['id'],'from'=>$p['from']===ShiftPeriods::BEGINNING?'':$p['from'],'to'=>(string)$p['to'],'shift'=>$names[$p['shift_id']]??('#'.$p['shift_id']),
                'times'=>$this->format_time_label($h['start']).' – '.$this->format_time_label($h['end']).' · '.sprintf(/* translators: %d: minutes */__('grace %d min','workforce-one'),(int)$h['grace']),
                'by'=>$p['created_by']&&($u=get_userdata($p['created_by']))?(string)$u->display_name:'','when'=>$p['created_by']?date_i18n(get_option('date_format'),strtotime($p['created_at'])):'',
                'reason'=>$p['reason'],'state'=>$p['from']>$today?'later':(($p['to']===null||$p['to']>=$today)?'now':'past'),'removable'=>$p['from']>$today];
        }
        $done=sanitize_key($_GET['shift_done']??'');$error=sanitize_key($_GET['shift_error']??'');
        $msg=['date'=>__('Pick a valid day.','workforce-one'),'shift'=>__('Pick a shift.','workforce-one'),'past'=>__('Only HR can change the shift from a past day.','workforce-one')];
        return ['rows'=>$rows,'shifts'=>$names,'today'=>$today,'can_past'=>$this->can('ews_manage_settings'),
            'notice'=>$done!==''?['type'=>'success','text'=>$done==='removed'?__('The upcoming change was removed.','workforce-one'):__('Shift saved.','workforce-one')]:($error!==''?['type'=>'error','text'=>$msg[$error]??$msg['date']]:null)];
    }

    /** For the employee's own profile: an upcoming shift change ("From 1 Nov: Evening"), or null. */
    private function shift_upcoming($employee_id){
        $today=current_time('Y-m-d');
        foreach($this->shift_periods_of((int)$employee_id) as $p){
            if($p['from']>$today){
                $name=__('Company working hours','workforce-one');
                foreach($this->shifts() as $s)if((int)$s['id']===$p['shift_id'])$name=$s['name'];
                return ['from'=>$p['from'],'name'=>$name];
            }
        }
        return null;
    }
}
