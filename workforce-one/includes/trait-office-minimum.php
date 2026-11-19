<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\ManagerMinimum;
use WorkforceOne\Schedule\OfficeMinimum;
use WorkforceOne\Schedule\OfficeMinimumScope;
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
    private function om_mgr_on($dept=0){ return $this->om_enabled() && !empty($this->om_cfg($dept)['mgr']); }

    /** Does a planned status count as in the office? ($cfg: a department's settings, 3.31.154; else the company's) */
    private function om_counts($status,$cfg=null){ return in_array((string)$status,($cfg??$this->om_settings())['statuses'],true); }

    /** Could someone with this status come in instead (working from home, not on leave or away)? */
    private function om_could_come($status,$cfg=null){
        if($status===''||$this->om_counts($status,$cfg))return false;
        return \WorkforceOne\Ui\Icons::forStatus((string)$status)[1]==='wfh';
    }

    /* ------------------------------------------------------------------ by department (3.31.154) */

    /** The departments' own settings (docs/tasks/office-minimum-departments.md): [] when none, so nothing else is read. @return array<int,array<string,mixed>> */
    private function om_dept_settings(){
        static $cache=null;
        return $cache??($cache=OfficeMinimumSettings::departments(get_option('ews_office_minimum',[])));
    }

    /** The active departments with their own settings: id => name (one read, only when some are stored). @return array<int,string> */
    private function om_own_depts(){
        static $cache=null;
        if($cache!==null)return $cache;
        if(!$this->om_dept_settings())return $cache=[];
        return $cache=OfficeMinimumScope::own($this->om_dept_settings(),$this->people_department_names());
    }

    /** The settings for a department with its own (0 = the company's). @return array<string,mixed> */
    private function om_cfg($dept=0){
        $dept=(int)$dept;
        return $dept&&isset($this->om_dept_settings()[$dept])?$this->om_dept_settings()[$dept]:$this->om_settings();
    }

    /** Who a department's minimum counts (0 = everyone, as before). @return array<string,mixed> */
    private function om_people_for($dept=0){
        static $cache=[];
        $dept=(int)$dept;
        if(!$dept)return $this->om_people();
        return $cache[$dept]??($cache[$dept]=OfficeMinimumScope::people($this->om_people(),$dept));
    }

    /** The department whose numbers the viewer sees (0 = the company): an administrator may pick one. */
    private function om_viewer_dept($picked=0){
        if(!$this->om_dept_settings())return 0;
        return OfficeMinimumScope::forViewer(current_user_can('manage_options'),(int)$this->current_department_id(),(int)$picked,array_keys($this->om_own_depts()));
    }

    /**
     * Everyone the minimum counts (active, attendance on) with their primary team, as the Attendance
     * page groups them, and the team managers among them (a manager of two teams once).
     * @return array{people:array<int,array{name:string,team:int,dept:int}>,teams:array<int,string>,sizes:array<int,int>,managers:array<int,list<string>>}
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
            $people[(int)$e->id]=['name'=>(string)$e->name,'team'=>$tid,'dept'=>(int)($e->department_id??0)];
            $sizes[$tid]=($sizes[$tid]??0)+1;
        }
        // Teams in name order, "No team" last.
        $named=array_diff_key($teams,[0=>1]);natcasesort($named);
        $teams=$named+(isset($sizes[0])?[0=>$teams[0]]:[]);
        $sorted=[];foreach($teams as $k=>$n)if(isset($sizes[$k]))$sorted[$k]=$sizes[$k];
        return $cache=['people'=>$people,'teams'=>$teams,'sizes'=>$sorted,'managers'=>ManagerMinimum::managers($managed)];
    }

    /** Everyone's planned status on some dates: employee => date => status (one read). @param string[] $dates @return array<int,array<string,string>> */
    private function om_schedule_map(array $dates){
        global $wpdb;
        $map=[];
        $ph=implode(',',array_fill(0,count($dates),'%s'));
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE work_date IN ($ph)",...$dates)) as $r)$map[(int)$r->employee_id][(string)$r->work_date]=(string)$r->status;
        return $map;
    }

    /**
     * The check for some dates: null for a company holiday or a day that is not a working day.
     * @param string[] $dates
     * @param array<int,array<string,string>> $override employee => date => status, instead of the saved one
     * @param int $dept a department with its own settings (3.31.154); 0 = the company, as before
     * @param array<int,array<string,string>>|null $map the schedule already read (the reminder reads it once for everyone)
     * @return array<string,array<string,mixed>|null>
     */
    private function om_days(array $dates,array $override=[],$dept=0,$map=null){
        $out=[];
        if(!$dates)return $out;
        global $wpdb;
        $cfg=$this->om_cfg($dept);
        $p=$this->om_people_for($dept);
        $mgr_on=!empty($cfg['mgr']);
        $holidays=$this->company_leave_dates(min($dates),max($dates));
        if($map===null)$map=$this->om_schedule_map($dates);
        foreach($override as $eid=>$days)foreach($days as $d=>$st)$map[(int)$eid][$d]=$st;
        foreach($dates as $d){
            if(isset($holidays[$d])||!$this->is_working_day($d)){$out[$d]=null;continue;}
            $min=OfficeMinimum::minimumFor($cfg,$d);
            if($min<=0){$out[$d]=null;continue;}
            $office=array_fill_keys(array_keys($p['sizes']),0);$can=[];$plans=[];
            foreach($p['people'] as $eid=>$who){
                $st=$map[$eid][$d]??'';
                $in=$this->om_counts($st,$cfg);
                if($in)$office[$who['team']]++;
                elseif($this->om_could_come($st,$cfg))$can[$who['team']][]=$who['name'];
                if($mgr_on&&isset($p['managers'][$eid]))$plans[$eid]=['kind'=>$in?'office':($this->om_could_come($st,$cfg)?'wfh':'other'),'status'=>$st];
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
        // By department (3.31.154): the viewer's department when it has its own settings, or the one an administrator picked; else the company.
        $dept=$this->om_viewer_dept(absint(wp_unslash($_GET['om_dept']??0)));
        $cfg=$this->om_cfg($dept);$p=$this->om_people_for($dept);
        $days=$this->om_days($dates,[],$dept);
        $short=array_keys(array_filter($days,function($d){return $d && $d['short']>0;}));
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-office-minimum');
        $out=['days'=>$days,'short'=>$short,'statuses'=>$cfg['statuses'],'total'=>count($p['people'])];
        if($this->om_mgr_on($dept)){
            $visible=$this->om_mgr_visible();
            if($visible!==null)foreach($days as $d=>$day)if($day&&!empty($day['mgr']))$days[$d]['mgr']=$this->om_mgr_for_viewer($day['mgr'],$visible);
            $out['days']=$days;
            $mshort=array_keys(array_filter($days,function($d){return $d && !empty($d['mgr']) && $d['mgr']['short']>0;}));
            $out['mgr']=['ids'=>array_keys($p['managers']),'teams'=>$p['managers'],'short'=>$mshort,
                'any'=>array_values(array_filter(array_keys($days),function($d)use($short,$mshort){return in_array($d,$short,true)||in_array($d,$mshort,true);}))];
        }
        // Only when some department has its own settings: the bar that says whose minimum this is (and, for an administrator, the choice).
        $own=$this->om_own_depts();
        if($own){
            $admin=current_user_can('manage_options');
            if($admin||$dept){
                $out['scope']=['dept'=>$dept,'name'=>$dept?(string)$own[$dept]:'','min'=>(int)$cfg['min'],'mgr_min'=>!empty($cfg['mgr'])?(int)$cfg['mgr_min']:null,
                    'choices'=>$admin?[0=>__('The whole company','workforce-one')]+$own:null,'keep'=>$admin?$this->list_keep_args('attendance'):[]];
            }
            if($dept)$out['people']=array_keys($p['people']);
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
        // An employee of a department with its own settings: that department's check only (owner, 3.31.154).
        $dept=(int)$p['people'][(int)$employee_id]['dept'];
        if(!isset($this->om_own_depts()[$dept]))$dept=0;
        if($dept)$p=$this->om_people_for($dept);
        $dates=[];
        for($t=strtotime($start);$t&&$t<=strtotime($end)&&count($dates)<62;$t+=86400)$dates[]=date('Y-m-d',$t);
        if(!$dates)return null;
        $before=$this->om_days($dates,[],$dept);
        $after=$this->om_days($dates,[(int)$employee_id=>array_fill_keys($dates,'Vacation')],$dept);
        $worst=null;
        foreach($dates as $d){
            if(!$before[$d]||!$after[$d])continue;
            if($after[$d]['office']>=$before[$d]['office'])continue;    // not in the office that day anyway
            if($after[$d]['short']<=0)continue;
            if($worst===null||$after[$d]['short']>$after[$worst]['short'])$worst=$d;
        }
        // A manager's leave (3.31.153): the day it leaves furthest below the managers' minimum, from the same two reads.
        $mgr=null;
        if(!empty($this->om_cfg($dept)['mgr'])&&isset($p['managers'][(int)$employee_id])){
            $m=function($days){return array_map(function($x){return $x['mgr']??null;},$days);};
            $md=ManagerMinimum::worstDrop($m($before),$m($after),$dates);
            if($md!==null){
                $a=$this->om_mgr_for_viewer($after[$md]['mgr'],$this->om_mgr_visible());   // who could come in: only those the approver may see
                $can=[];foreach($a['can'] as $id)$can[]=(string)($p['people'][$id]['name']??'');
                $mgr=['date'=>$md,'office'=>$a['office'],'min'=>$a['min'],'can'=>$can];
            }
        }
        if($worst===null&&$mgr===null)return null;
        $label=$dept?['dept'=>(string)$this->om_own_depts()[$dept]]:[];
        if($worst===null)return ['mgr'=>$mgr]+$label;
        $team=(int)$p['people'][(int)$employee_id]['team'];
        $row=null;foreach($after[$worst]['teams'] as $r)if($r['team']===$team)$row=$r;
        $out=['date'=>$worst,'office'=>$after[$worst]['office'],'min'=>$after[$worst]['min'],'team'=>$row?$row['name']:'','team_office'=>$row?$row['office']:0,'share'=>$row?$row['share']:0];
        if($mgr!==null)$out['mgr']=$mgr;
        return $out+$label;
    }

    /** The teams for the settings table: size and automatic share. @return array<int,array{name:string,size:int,share:int}> */
    private function om_admin_teams($dept=0){
        $p=$this->om_people_for($dept);
        if(count($p['sizes'])<2 && isset($p['sizes'][0]))return [];
        $cfg=$this->om_cfg($dept);
        $auto=\WorkforceOne\Schedule\OfficeMinimum::shares((int)$cfg['min'],$p['sizes']);
        $out=[];
        foreach($p['sizes'] as $tid=>$n)$out[$tid]=['name'=>$p['teams'][$tid]??'','size'=>$n,'share'=>$auto[$tid]??0];
        return $out;
    }

    /**
     * The managers whose plan the viewer may see (owner, 2026-11-18): those in the viewer's department scope; null = all
     * (an administrator). The others show only "in the office" or "not in the office", never why.
     * @return array<int,true>|null
     */
    private function om_mgr_visible(){
        if(current_user_can('manage_options'))return null;
        return array_fill_keys($this->department_employee_ids(),true);
    }

    /**
     * A day's managers as the viewer may see them: outside their scope, the plan is only in / not in the office (no status, not
     * "could come in"), in the same order as if they were away. @param array<string,mixed> $m ManagerMinimum::day() @return array<string,mixed>
     */
    private function om_mgr_for_viewer(array $m,$visible){
        if($visible===null)return $m;
        foreach($m['rows'] as &$r){
            if(isset($visible[$r['id']]))continue;
            $r['masked']=true;$r['status']='';
            if($r['kind']!=='office')$r['kind']='other';
        }
        unset($r);
        $order=['office'=>0,'wfh'=>1];
        usort($m['rows'],function($a,$b)use($order){return [$order[$a['kind']]??2,$a['name'],$a['id']]<=>[$order[$b['kind']]??2,$b['name'],$b['id']];});
        $m['can']=array_values(array_filter($m['can'],function($id)use($visible){return isset($visible[$id]);}));
        return $m;
    }

    /** The team managers the managers' minimum counts, for Feature Configuration (3.31.153). @return list<string> names */
    private function om_admin_managers($dept=0){
        $p=$this->om_people_for($dept);$out=[];
        foreach($p['managers'] as $eid=>$teams)$out[]=(string)($p['people'][$eid]['name']??'');
        natcasesort($out);
        return array_values($out);
    }

    /**
     * Feature Configuration's department tabs (3.31.154): each active department, whether it has its own settings and how many
     * people the minimum counts in it. @return array<int,array{name:string,own:bool,people:int}>
     */
    private function om_admin_departments(){
        $own=$this->om_dept_settings();$count=[];
        foreach($this->om_people()['people'] as $w)$count[(int)$w['dept']]=($count[(int)$w['dept']]??0)+1;
        $out=[];
        foreach($this->people_department_names() as $id=>$name)$out[$id]=['name'=>$name,'own'=>isset($own[$id]),'people'=>$count[$id]??0];
        return $out;
    }

    /** "Thursday 15 October" */
    private function om_day_label($date){ return date_i18n('l j F',strtotime($date)); }

    /* ------------------------------------------------------------------ weekly reminder */

    public function om_tick(){
        if(!$this->om_enabled())return;
        $now=current_time('timestamp');
        $week=date('o-W',$now);
        $due=function($cfg)use($now){return $cfg['reminder'] && (int)date('w',$now)===(int)$cfg['reminder_day'] && date('H:i',$now)>=$cfg['reminder_time'];};
        $next=date('Y-m-d',strtotime('+7 days',$now));
        // By department (3.31.154): with departments of their own, each person gets one reminder, their department's or the
        // company's. Who gets which, and next week's schedule, are read only once something is due (review 5).
        $own=$this->om_own_depts();
        $groups=null;$map=null;
        $ready=function()use(&$groups,&$map,$own,$next){
            if($groups!==null)return;
            $groups=$this->om_reminder_groups(array_keys($own));
            [$dates]=$this->week_dates_configured($next);
            $map=$this->om_schedule_map($dates);
        };
        $cfg=$this->om_settings();
        if($due($cfg) && (string)get_option('ews_office_minimum_reminded','')!==$week){
            update_option('ews_office_minimum_reminded',$week,false);
            if(!$own)$this->om_remind($next);
            else{ $ready(); if(!empty($groups[0]))$this->om_remind($next,0,$groups[0],$map); }   // nobody in the group: nothing sent, nothing audited (review 6)
        }
        if(!$own)return;
        $done=(array)get_option('ews_office_minimum_reminded_depts',[]);
        foreach($own as $d=>$name){
            if(!$due($this->om_cfg($d)) || (string)($done[$d]??'')===$week)continue;
            $done[$d]=$week;
            update_option('ews_office_minimum_reminded_depts',$done,false);
            $ready();
            if(!empty($groups[$d]))$this->om_remind($next,$d,$groups[$d],$map);
        }
    }

    /**
     * The reminder's recipients by department: the schedule managers, each in one group (OfficeMinimumScope::reminderGroups()).
     * @param int[] $own @return array<int,list<int>>
     */
    private function om_reminder_groups(array $own){
        $users=array_map(function($u){return (int)$u->ID;},get_users(['capability'=>'ews_manage_attendance','fields'=>['ID']]));
        if(!$users)$users=array_map(function($u){return (int)$u->ID;},get_users(['role'=>'administrator','fields'=>['ID']]));
        $admins=array_map(function($u){return (int)$u->ID;},get_users(['capability'=>'manage_options','fields'=>['ID']]));
        $byUser=[];
        foreach($this->emps() as $e)if((int)($e->wp_user_id??0))$byUser[(int)$e->wp_user_id]=(int)($e->department_id??0);
        $userDept=[];
        foreach($users as $u)$userDept[$u]=$byUser[$u]??0;
        return OfficeMinimumScope::reminderGroups($userDept,$admins,$own);
    }

    /** Sends next week's short days to the schedule managers. @return int people notified */
    private function om_remind($any_day_next_week,$dept=0,$user_ids=null,$map=null){
        [$dates]=$this->week_dates_configured($any_day_next_week);
        $short=array_filter($this->om_days($dates,[],$dept,$map),function($d){return $d && ($d['short']>0 || (!empty($d['mgr']) && $d['mgr']['short']>0));});
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
        if($user_ids===null){
            $users=get_users(['capability'=>'ews_manage_attendance','fields'=>['ID']]);
            if(!$users)$users=get_users(['role'=>'administrator','fields'=>['ID']]);
        }else $users=array_map(function($id){return (object)['ID'=>$id];},$user_ids);   // by department (3.31.154): this group only
        $url=add_query_arg(['ews_view'=>'attendance','week'=>$dates[0]],$this->app_home_url());
        /* translators: %d: number of days */
        $title=sprintf(_n('%d day next week is below the office minimum','%d days next week are below the office minimum',count($short),'workforce-one'),count($short));
        /* translators: 1: department, 2: the reminder's title */
        if($dept)$title=sprintf(__('%1$s: %2$s','workforce-one'),(string)($this->om_own_depts()[$dept]??''),$title);
        foreach($users as $u){
            $this->notify((int)$u->ID,'office_minimum',$title,implode(' · ',$parts),['url'=>$url]);
        }
        if($dept)$this->audit('office_minimum_reminder','department',(int)$dept,count($short).' short day(s), '.count($users).' people');
        else $this->audit('office_minimum_reminder','settings',0,count($short).' short day(s), '.count($users).' people');
        return count($users);
    }
}
