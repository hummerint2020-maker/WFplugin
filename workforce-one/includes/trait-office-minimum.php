<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\ManagerMinimum;
use WorkforceOne\Schedule\OfficeMinimum;
use WorkforceOne\Settings\OfficeMinimumSettings;

/**
 * Office minimum (3.31.77, Feature Configuration → Office Minimum, off by default).
 *
 * A fixed number of people planned in the office every working day, for the whole company. Whoever
 * sets the schedule is warned, never blocked: the Attendance page shows "in the office / minimum"
 * under each day, a card listing the days below it, and for each such day the teams under their
 * share (proportional to team size, src/Schedule/OfficeMinimum.php) with who could come in. A
 * manager approving leave sees what it does to the day. Schedule managers get a weekly reminder of
 * next week's short days. Company holidays and days off are never checked.
 *
 * Settings: src/Settings/OfficeMinimumSettings.php (option ews_office_minimum).
 * Behaviour: tests/e2e_office_minimum.py.
 */
trait EWS_Office_Minimum_Trait {

    private function om_enabled(){ return (bool)$this->option('ews_feature_office_minimum'); }

    private function om_settings(){ return OfficeMinimumSettings::config(get_option('ews_office_minimum',[])); }

    /** Manager office minimum (3.31.153): a minimum of team managers in the office too; only with the Office minimum on. */
    private function om_mgr_on(){ return $this->om_enabled() && !empty($this->om_settings()['mgr']); }

    /** Does a planned status count as in the office? */
    private function om_counts($status){ return in_array((string)$status,$this->om_settings()['statuses'],true); }

    /** Could someone with this status come in instead (working from home, not on leave or away)? */
    private function om_could_come($status){
        if($status===''||$this->om_counts($status))return false;
        return \WorkforceOne\Ui\Icons::forStatus((string)$status)[1]==='wfh';
    }

    /**
     * Everyone the minimum counts (active, attendance on) with their primary team, as the Attendance
     * page groups them, and the team managers among them (a manager of two teams once).
     * @return array{people:array<int,array{name:string,team:int}>,teams:array<int,string>,sizes:array<int,int>,managers:array<int,list<string>>}
     */
    private function om_people(){
        static $cache=null;
        if($cache!==null)return $cache;
        global $wpdb;
        $emps=(array)$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 AND (attendance_enabled=1 OR attendance_enabled IS NULL) ORDER BY name ASC");
        $emps=$this->schedule_employees_ordered($emps,0);
        $t=$this->team_tables();
        $ids=[];
        foreach((array)$wpdb->get_results("SELECT id,name FROM {$t['teams']} WHERE active=1") as $r)$ids[(string)$r->name]=(int)$r->id;
        $people=[];$teams=[0=>__('No team','workforce-one')];$sizes=[];$managed=[];
        foreach($emps as $e){
            if(!empty($e->_schedule_manager_teams))$managed[(int)$e->id]=(array)$e->_schedule_manager_teams;
            $name=(string)($e->_schedule_primary_team??'');
            $tid=$name!==''&&isset($ids[$name])?$ids[$name]:0;
            if($tid)$teams[$tid]=$name;
            $people[(int)$e->id]=['name'=>(string)$e->name,'team'=>$tid];
            $sizes[$tid]=($sizes[$tid]??0)+1;
        }
        // Teams in name order, "No team" last.
        $named=array_diff_key($teams,[0=>1]);natcasesort($named);
        $teams=$named+(isset($sizes[0])?[0=>$teams[0]]:[]);
        $sorted=[];foreach($teams as $k=>$n)if(isset($sizes[$k]))$sorted[$k]=$sizes[$k];
        return $cache=['people'=>$people,'teams'=>$teams,'sizes'=>$sorted,'managers'=>ManagerMinimum::managers($managed)];
    }

    /**
     * The check for some dates: null for a company holiday or a day that is not a working day.
     * @param string[] $dates
     * @param array<int,array<string,string>> $override employee => date => status, instead of the saved one
     * @return array<string,array<string,mixed>|null>
     */
    private function om_days(array $dates,array $override=[]){
        $out=[];
        if(!$dates)return $out;
        global $wpdb;
        $cfg=$this->om_settings();
        $p=$this->om_people();
        $mgr_on=!empty($cfg['mgr']);
        $holidays=$this->company_leave_dates(min($dates),max($dates));
        $map=[];
        $ph=implode(',',array_fill(0,count($dates),'%s'));
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE work_date IN ($ph)",...$dates)) as $r)$map[(int)$r->employee_id][(string)$r->work_date]=(string)$r->status;
        foreach($override as $eid=>$days)foreach($days as $d=>$st)$map[(int)$eid][$d]=$st;
        foreach($dates as $d){
            if(isset($holidays[$d])||!$this->is_working_day($d)){$out[$d]=null;continue;}
            $min=OfficeMinimum::minimumFor($cfg,$d);
            if($min<=0){$out[$d]=null;continue;}
            $office=array_fill_keys(array_keys($p['sizes']),0);$can=[];$plans=[];
            foreach($p['people'] as $eid=>$who){
                $st=$map[$eid][$d]??'';
                $in=$this->om_counts($st);
                if($in)$office[$who['team']]++;
                elseif($this->om_could_come($st))$can[$who['team']][]=$who['name'];
                if($mgr_on&&isset($p['managers'][$eid]))$plans[$eid]=['kind'=>$in?'office':($this->om_could_come($st)?'wfh':'other'),'status'=>$st];
            }
            $day=OfficeMinimum::day($min,OfficeMinimum::shares($min,$p['sizes'],array_intersect_key($cfg['teams'],$p['sizes'])),$office);
            foreach($day['teams'] as &$row){$row['name']=$p['teams'][$row['team']]??'';$row['size']=$p['sizes'][$row['team']]??0;$row['can']=$row['gap']<0?($can[$row['team']]??[]):[];}
            unset($row);
            // The managers (3.31.153): only with them on, so the day is as before otherwise.
            if($mgr_on){
                $mm=ManagerMinimum::minimumFor($cfg,$d);
                $day['mgr']=$mm>0?ManagerMinimum::day($mm,$plans,array_map(function($w){return $w['name'];},array_intersect_key($p['people'],$p['managers']))):null;
            }
            $out[$d]=$day;
        }
        return $out;
    }

    /** The Attendance page's part (null when off). @param string[] $dates */
    private function om_attendance($dates){
        if(!$this->om_enabled())return null;
        $days=$this->om_days($dates);
        $short=array_keys(array_filter($days,function($d){return $d && $d['short']>0;}));
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-office-minimum');
        $out=['days'=>$days,'short'=>$short,'statuses'=>$this->om_settings()['statuses'],'total'=>count($this->om_people()['people'])];
        if($this->om_mgr_on()){
            $mshort=array_keys(array_filter($days,function($d){return $d && !empty($d['mgr']) && $d['mgr']['short']>0;}));
            $out['mgr']=['ids'=>array_keys($this->om_people()['managers']),'teams'=>$this->om_people()['managers'],'short'=>$mshort,
                'any'=>array_values(array_filter(array_keys($days),function($d)use($short,$mshort){return in_array($d,$short,true)||in_array($d,$mshort,true);}))];
        }
        return $out;
    }

    /**
     * What approving a leave request does to the office minimum: the worst working day it would leave
     * below the minimum, or null. @return array<string,mixed>|null
     */
    private function om_leave_impact($employee_id,$start,$end){
        if(!$this->om_enabled())return null;
        $p=$this->om_people();
        if(!isset($p['people'][(int)$employee_id]))return null;
        $dates=[];
        for($t=strtotime($start);$t&&$t<=strtotime($end)&&count($dates)<62;$t+=86400)$dates[]=date('Y-m-d',$t);
        if(!$dates)return null;
        $before=$this->om_days($dates);
        $after=$this->om_days($dates,[(int)$employee_id=>array_fill_keys($dates,'Vacation')]);
        $worst=null;
        foreach($dates as $d){
            if(!$before[$d]||!$after[$d])continue;
            if($after[$d]['office']>=$before[$d]['office'])continue;    // not in the office that day anyway
            if($after[$d]['short']<=0)continue;
            if($worst===null||$after[$d]['short']>$after[$worst]['short'])$worst=$d;
        }
        // A manager's leave (3.31.153): the day it leaves furthest below the managers' minimum, from the same two reads.
        $mgr=null;
        if(!empty($this->om_settings()['mgr'])&&isset($p['managers'][(int)$employee_id])){
            $m=function($days){return array_map(function($x){return $x['mgr']??null;},$days);};
            $md=ManagerMinimum::worstDrop($m($before),$m($after),$dates);
            if($md!==null){
                $a=$after[$md]['mgr'];
                $can=[];foreach($a['can'] as $id)$can[]=(string)($p['people'][$id]['name']??'');
                $mgr=['date'=>$md,'office'=>$a['office'],'min'=>$a['min'],'can'=>$can];
            }
        }
        if($worst===null&&$mgr===null)return null;
        if($worst===null)return ['mgr'=>$mgr];
        $team=(int)$p['people'][(int)$employee_id]['team'];
        $row=null;foreach($after[$worst]['teams'] as $r)if($r['team']===$team)$row=$r;
        $out=['date'=>$worst,'office'=>$after[$worst]['office'],'min'=>$after[$worst]['min'],'team'=>$row?$row['name']:'','team_office'=>$row?$row['office']:0,'share'=>$row?$row['share']:0];
        if($mgr!==null)$out['mgr']=$mgr;
        return $out;
    }

    /** The teams for the settings table: size and automatic share. @return array<int,array{name:string,size:int,share:int}> */
    private function om_admin_teams(){
        $p=$this->om_people();
        if(count($p['sizes'])<2 && isset($p['sizes'][0]))return [];
        $cfg=$this->om_settings();
        $auto=\WorkforceOne\Schedule\OfficeMinimum::shares((int)$cfg['min'],$p['sizes']);
        $out=[];
        foreach($p['sizes'] as $tid=>$n)$out[$tid]=['name'=>$p['teams'][$tid]??'','size'=>$n,'share'=>$auto[$tid]??0];
        return $out;
    }

    /** The team managers the managers' minimum counts, for Feature Configuration (3.31.153). @return list<string> names */
    private function om_admin_managers(){
        $p=$this->om_people();$out=[];
        foreach($p['managers'] as $eid=>$teams)$out[]=(string)($p['people'][$eid]['name']??'');
        natcasesort($out);
        return array_values($out);
    }

    /** "Thursday 15 October" */
    private function om_day_label($date){ return date_i18n('l j F',strtotime($date)); }

    /* ------------------------------------------------------------------ weekly reminder */

    public function om_tick(){
        if(!$this->om_enabled())return;
        $cfg=$this->om_settings();
        if(!$cfg['reminder'])return;
        $now=current_time('timestamp');
        if((int)date('w',$now)!==(int)$cfg['reminder_day'] || date('H:i',$now)<$cfg['reminder_time'])return;
        $week=date('o-W',$now);
        if((string)get_option('ews_office_minimum_reminded','')===$week)return;
        update_option('ews_office_minimum_reminded',$week,false);
        $this->om_remind(date('Y-m-d',strtotime('+7 days',$now)));
    }

    /** Sends next week's short days to the schedule managers. @return int people notified */
    private function om_remind($any_day_next_week){
        [$dates]=$this->week_dates_configured($any_day_next_week);
        $short=array_filter($this->om_days($dates),function($d){return $d && ($d['short']>0 || (!empty($d['mgr']) && $d['mgr']['short']>0));});
        if(!$short)return 0;
        $parts=[];
        foreach($short as $d=>$day){
            $mg=!empty($day['mgr'])&&$day['mgr']['short']>0?$day['mgr']:null;   // managers short (3.31.153); never set with them off
            if($day['short']>0){
                /* translators: 1: day, 2: in the office, 3: minimum */
                $part=sprintf(__('%1$s %2$d of %3$d','workforce-one'),date_i18n('D j M',strtotime($d)),$day['office'],$day['min']);
                /* translators: 1: managers in the office, 2: managers' minimum */
                if($mg)$part.=sprintf(__(', %1$d of %2$d managers','workforce-one'),$mg['office'],$mg['min']);
            }else{
                /* translators: 1: day, 2: managers in the office, 3: managers' minimum */
                $part=sprintf(__('%1$s %2$d of %3$d managers','workforce-one'),date_i18n('D j M',strtotime($d)),$mg['office'],$mg['min']);
            }
            $parts[]=$part;
        }
        $users=get_users(['capability'=>'ews_manage_attendance','fields'=>['ID']]);
        if(!$users)$users=get_users(['role'=>'administrator','fields'=>['ID']]);
        $url=add_query_arg(['ews_view'=>'attendance','week'=>$dates[0]],$this->app_home_url());
        foreach($users as $u){
            $this->notify((int)$u->ID,'office_minimum',
                /* translators: %d: number of days */
                sprintf(_n('%d day next week is below the office minimum','%d days next week are below the office minimum',count($short),'workforce-one'),count($short)),
                implode(' · ',$parts),['url'=>$url]);
        }
        $this->audit('office_minimum_reminder','settings',0,count($short).' short day(s), '.count($users).' people');
        return count($users);
    }
}
