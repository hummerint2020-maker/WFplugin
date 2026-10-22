<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\WeekLock;
use WorkforceOne\Settings\WeekLockSettings;

/**
 * Week lock (3.31.121, Feature Configuration → Week lock, off by default; docs/tasks/week-lock.md).
 *
 * From a weekly cutoff (site time) the coming week's schedule is final and stays final: the Attendance grid, the single-day
 * handler and the CSV import no longer change a final week, except for people with "Edit a locked week" (every such
 * change is in the Audit Log). Leave, leave cancellations and swaps are still requested, approved and applied as usual
 * (owner decision): their writes into a final week are only recorded. Attendance itself is not locked. Employees see
 * "Final" on the week and are told at the cutoff. When the switch is off nothing here runs a query or changes a page.
 *
 * Rules: src/Schedule/WeekLock.php; settings: src/Settings/WeekLockSettings.php. Behaviour: tests/e2e_week_lock.py.
 */
trait EWS_Week_Lock_Trait {

    private function week_lock_enabled(){ return (bool)$this->option('ews_feature_week_lock'); }

    private function week_lock_settings(){ return WeekLockSettings::config(get_option('ews_week_lock',[])); }

    /** One instant per request, so one save is judged against one time. */
    private function week_lock_now(){
        static $now=null;
        return $now??($now=current_time('mysql'));
    }

    /** Is the day in a final week? Always false when the switch is off. */
    private function week_lock_is_locked($date){
        if(!$this->week_lock_enabled())return false;
        $start=WeekLock::weekStart((string)$date,$this->working_days());
        $cfg=$this->week_lock_settings();
        return WeekLock::isLocked($start,$this->week_lock_now(),(int)$cfg['cutoff_day'],(string)$cfg['cutoff_time']);
    }

    private function week_lock_can_override(){ return $this->can('ews_week_lock_override'); }

    /**
     * A person's edit of a schedule day: '' (the week is open, or the switch is off), 'override' (final, and they may
     * still change it: audit it) or 'locked' (refused).
     */
    private function week_lock_refusal($date){
        if(!$this->week_lock_is_locked($date))return '';
        return $this->week_lock_can_override()?'override':'locked';
    }

    /** An edit of a final week by someone allowed to: one Audit Log row. */
    private function week_lock_audit_edit($employee_id,$date,$source,$old,$new){
        $this->audit('schedule_locked_edit','employee',(int)$employee_id,sprintf('%s | %s | %s → %s (final week)',$date,$source,$old!==null&&$old!==''?$old:'Not Set',$new));
    }

    /** Refused edits of a final week: one row per request, not per day. */
    private function week_lock_audit_refused($count,$source){
        if($count>0)$this->audit('schedule_locked_refused','schedule',0,$count.' day(s) not saved ('.$source.'): the week is final');
    }

    /**
     * An approved request wrote these days (leave, its cancellation, a swap): applied as usual, and the days that are in a
     * final week recorded once. @param string[] $dates
     */
    private function week_lock_record_request($employee_id,array $dates,$source,$ref){
        if(!$this->week_lock_enabled()||!$dates)return;
        $locked=array_values(array_filter(array_unique($dates),function($d){return $this->week_lock_is_locked($d);}));
        if($locked)$this->audit('schedule_locked_by_'.$source,'employee',(int)$employee_id,$ref.' | '.implode(', ',$locked).' (final week)');
    }

    /**
     * What a page shows for the week starting $week_start, or null when the switch is off.
     * @return array{locked:bool,since:string,override:bool,soon:string}|null since: when it became final; soon: today's
     *         cutoff that will make it final (empty otherwise)
     */
    private function week_lock_view($week_start){
        if(!$this->week_lock_enabled())return null;
        $cfg=$this->week_lock_settings();
        $start=WeekLock::weekStart((string)$week_start,$this->working_days());
        $from=WeekLock::lockedFrom($start,(int)$cfg['cutoff_day'],(string)$cfg['cutoff_time']);
        $now=$this->week_lock_now();
        $locked=$from!==''&&strcmp($now,$from)>=0;
        $soon=!$locked&&$from!==''&&substr($from,0,10)===substr($now,0,10)?$from:'';
        return ['locked'=>$locked,'since'=>$locked?$from:'','override'=>$locked&&$this->week_lock_can_override(),'soon'=>$soon];
    }

    /** "Thu 8 Oct, 5:00 PM" */
    private function week_lock_when($at){ return date_i18n(__('D j M, g:i A','workforce-one'),strtotime((string)$at)); }

    /** The next cutoff, for the settings page. @return array{week:string,at:string} */
    private function week_lock_next(){
        $cfg=$this->week_lock_settings();
        return WeekLock::next($this->week_lock_now(),$this->working_days(),(int)$cfg['cutoff_day'],(string)$cfg['cutoff_time']);
    }

    /* ------------------------------------------------------------------ the cutoff notification */

    /** Every five minutes (WP-Cron): once per cutoff, tell everyone with a schedule that week. */
    public function week_lock_tick(){
        if(!$this->week_lock_enabled())return;
        $cfg=$this->week_lock_settings();
        if(!$cfg['notify'])return;
        $now=$this->week_lock_now();
        $last=WeekLock::latest($now,$this->working_days(),(int)$cfg['cutoff_day'],(string)$cfg['cutoff_time']);
        // Only a cutoff of the last day: switching it on, or a site that was down for a week, does not send an old one.
        if($last['week']===''||strtotime($now)-strtotime($last['at'])>DAY_IN_SECONDS)return;
        if((string)get_option('ews_week_lock_notified','')===$last['week'])return;
        // Claimed with one conditional UPDATE: of two overlapping cron runs only one tells everyone.
        global $wpdb;
        add_option('ews_week_lock_notified','','','no');
        $claimed=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name='ews_week_lock_notified' AND option_value<>%s",$last['week'],$last['week']));
        wp_cache_delete('ews_week_lock_notified','options');
        if($claimed!==1)return;
        $this->week_lock_notify($last['week']);
    }

    /** "Next week's schedule is final", with each person's days. @return int people told */
    private function week_lock_notify($week_start){
        global $wpdb;
        [$dates]=$this->week_dates_configured($week_start);
        if(!$dates)return 0;
        $ph=implode(',',array_fill(0,count($dates),'%s'));
        $users=[];
        foreach($this->people_active_users() as $e)$users[(int)$e->id]=(int)$e->wp_user_id;
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE work_date IN ($ph) ORDER BY work_date ASC",...$dates));
        $by=[];
        foreach($rows as $r)if(!empty($users[(int)$r->employee_id]))$by[$users[(int)$r->employee_id]][(string)$r->work_date]=(string)$r->status;
        $url=add_query_arg(['ews_view'=>'schedule','week'=>$dates[0]],$this->app_home_url());
        foreach($by as $uid=>$days){
            $parts=[];
            foreach($days as $d=>$st)$parts[]=date_i18n('D',strtotime($d)).' '.$st;
            $this->notify($uid,'schedule',__('Next week\'s schedule is final','workforce-one'),implode(' · ',$parts),['url'=>$url,'entity'=>'week_lock']);
        }
        $this->audit('week_lock_notified','schedule',0,$dates[0].': '.count($by).' people');
        return count($by);
    }
}
