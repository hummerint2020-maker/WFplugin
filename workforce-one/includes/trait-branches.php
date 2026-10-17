<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\BranchRules;
use WorkforceOne\Settings\BranchSettings;
use WorkforceOne\Support\Picker;

/**
 * Branches (3.31.72): an employee's main branch (the existing assigned Work Location,
 * ews_employee_locations_v321) plus other branches (ews_employee_branches), the branch planned for a
 * day (ews_schedule.location_id) and the branch a Sign In happened at (ews_time_logs.location_id,
 * branch_flag). The mode is set in Feature Configuration (src/Settings/BranchSettings.php); the rules
 * are src/Attendance/BranchRules.php. Who may change an employee's branches: the
 * ews_manage_employee_branches permission (administrators always; a manager with it, only their
 * department's people).
 */
trait EWS_Branches_Trait {

    /** @var array<int,object>|null active locations by id, for this request */
    private static $ews_branch_locations=null;

    private function branch_settings(){
        return BranchSettings::config(get_option('ews_branch_settings',[]));
    }

    private function ensure_branches_schema(){
        global $wpdb;$p=$wpdb->prefix;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$p}ews_employee_branches (
            employee_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (employee_id,location_id),
            KEY location_id (location_id)
        ) $c;");
        foreach([$this->schedule=>['location_id'=>'BIGINT UNSIGNED NULL'],$this->time_logs=>['location_id'=>'BIGINT UNSIGNED NULL','branch_flag'=>'VARCHAR(20) NULL']] as $table=>$cols){
            if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table)continue;
            $have=$wpdb->get_col("SHOW COLUMNS FROM {$table}",0);
            foreach($cols as $col=>$def)if(!in_array($col,$have,true))$wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    }

    /** @return array<int,object> active work locations by id */
    private function branch_locations(){
        if(self::$ews_branch_locations===null){
            global $wpdb;self::$ews_branch_locations=[];
            foreach((array)$wpdb->get_results("SELECT * FROM {$this->locations} WHERE active=1 ORDER BY name ASC") as $l)self::$ews_branch_locations[(int)$l->id]=$l;
        }
        return self::$ews_branch_locations;
    }

    /** The main branch id: the assigned location, else the default location (0 when there is none). */
    private function branch_main_id($employee_id){
        $l=$this->ews_v321_employee_location((int)$employee_id);
        return $l?(int)$l->id:0;
    }

    /** @return int[] other branch ids (active ones only) */
    private function branch_other_ids($employee_id){
        global $wpdb;
        $active=$this->branch_locations();
        $ids=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT location_id FROM {$wpdb->prefix}ews_employee_branches WHERE employee_id=%d ORDER BY location_id",(int)$employee_id)));
        return array_values(array_filter($ids,function($id)use($active){return isset($active[$id]);}));
    }

    /** @param int[] $employee_ids @return array<int,int[]> other branch ids per employee (one query) */
    private function branch_other_ids_bulk(array $employee_ids){
        global $wpdb;$out=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$employee_ids))));
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,location_id FROM {$wpdb->prefix}ews_employee_branches WHERE employee_id IN ($ph)",$chunk)) as $r)$out[(int)$r->employee_id][]=(int)$r->location_id;
        }
        return $out;
    }

    /** The branch planned for an employee on a day ("By the schedule"), 0 when none. */
    private function branch_planned_id($employee_id,$day){
        if($this->branch_settings()['mode']!=='schedule')return 0;
        global $wpdb;
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT location_id FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",(int)$employee_id,$day));
        return isset($this->branch_locations()[$id])?$id:0;
    }

    /**
     * Today's branches for Sign In (AttendanceContext 'branches').
     * @return array{expected:?object, allowed:array<int,object>, flag_other:bool, kiosk_any:bool}
     */
    private function branches_for_sign_in($employee_id){
        $cfg=$this->branch_settings();$locs=$this->branch_locations();
        $main=$this->branch_main_id($employee_id);
        $planned=$this->branch_planned_id($employee_id,$this->attendance_day($employee_id));
        $c=BranchRules::candidates($cfg['mode'],$main,$this->branch_other_ids($employee_id),$planned,!empty($cfg['allow_others']));
        $allowed=[];
        foreach($c['allowed'] as $id)if(isset($locs[$id]))$allowed[$id]=$locs[$id];
        $expected=$locs[$c['expected']]??$this->ews_v321_employee_location($employee_id);
        if($expected&&!isset($allowed[(int)$expected->id]))$allowed=[(int)$expected->id=>$expected]+$allowed;
        return ['expected'=>$expected?:null,'allowed'=>$allowed,'flag_other'=>$c['flag_other'],'kiosk_any'=>!empty($cfg['kiosk_any'])];
    }

    /** Today's branch for the employee app (Home, Sign In), or null when it is not shown. */
    private function branch_today($employee_id){
        $cfg=$this->branch_settings();
        if(empty($cfg['show_branch']))return null;
        $locs=$this->branch_locations();
        if(count($locs)<2)return null;   // one place: nothing to say
        $main=$this->branch_main_id($employee_id);
        $planned=$this->branch_planned_id($employee_id,$this->attendance_day($employee_id));
        $id=$planned?:$main;
        if(!isset($locs[$id]))return null;
        return ['id'=>$id,'name'=>(string)$locs[$id]->name,'planned'=>$planned>0,'main_name'=>isset($locs[$main])?(string)$locs[$main]->name:''];
    }

    /** @param int[] $employee_ids @param string[] $days @return array<int,array<string,int>> planned branch per employee and day */
    private function branch_planned_bulk(array $employee_ids,array $days){
        global $wpdb;$out=[];
        if(!$employee_ids||!$days||$this->branch_settings()['mode']!=='schedule')return $out;
        $dph=implode(',',array_fill(0,count($days),'%s'));
        foreach(array_chunk(array_values(array_unique(array_map('intval',$employee_ids))),500) as $chunk){
            $eph=implode(',',array_fill(0,count($chunk),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,location_id FROM {$this->schedule} WHERE location_id IS NOT NULL AND employee_id IN ($eph) AND work_date IN ($dph)",array_merge($chunk,$days))) as $r)$out[(int)$r->employee_id][$r->work_date]=(int)$r->location_id;
        }
        return $out;
    }

    /** Plans the branch of a day on the Attendance grid (0 = their main branch). Ignored outside "By the schedule", for a status without a place, or a branch that is not theirs. */
    private function branch_plan_day($employee_id,$date,$status,$branch_id){
        if($this->branch_settings()['mode']!=='schedule')return;
        global $wpdb;
        $id=0;
        if($branch_id&&$this->schedule_type_requires_location($status)){
            $mine=array_merge([$this->branch_main_id($employee_id)],$this->branch_other_ids($employee_id));
            if(!in_array($branch_id,$mine,true)||!isset($this->branch_choices()[$branch_id]))return;
            $id=$branch_id;
        }
        $wpdb->query($wpdb->prepare("UPDATE {$this->schedule} SET location_id=".($id?'%d':'NULL')." WHERE employee_id=%d AND work_date=%s",...($id?[$id,(int)$employee_id,$date]:[(int)$employee_id,$date])));
    }

    /** May the current user change this employee's branches? Administrators always; with the permission, a manager for their department's people. */
    private function branch_can_edit($employee_id){
        if(current_user_can('manage_options')||current_user_can('ews_manage_locations'))return true;
        if(!current_user_can('ews_manage_employee_branches'))return false;
        return $this->department_scope_allows_employee((int)$employee_id);
    }

    /** The branches a manager may hand out: all, or with "Managers see their department's branches only", those their people use. @return array<int,object> */
    private function branch_choices(){
        $locs=$this->branch_locations();
        if(current_user_can('manage_options')||current_user_can('ews_manage_locations')||empty($this->branch_settings()['manager_scope']))return $locs;
        $ids=$this->department_employee_ids();
        if(!$ids)return [];
        $used=[];
        foreach($ids as $eid)$used[$this->branch_main_id($eid)]=true;
        foreach($this->branch_other_ids_bulk($ids) as $list)foreach($list as $lid)$used[$lid]=true;
        return array_intersect_key($locs,$used);
    }

    /** Saves an employee's other branches (the main branch stays on Work Locations' map). Audited. @param int[] $ids */
    private function branch_set_others($employee_id,array $ids){
        global $wpdb;$t=$wpdb->prefix.'ews_employee_branches';
        $main=$this->branch_main_id($employee_id);$locs=$this->branch_locations();
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),function($id)use($main,$locs){return $id>0&&$id!==$main&&isset($locs[$id]);})));
        $before=$this->branch_other_ids($employee_id);
        sort($before);sort($ids);
        if($before===$ids)return;
        $wpdb->delete($t,['employee_id'=>(int)$employee_id],['%d']);
        foreach($ids as $id)$wpdb->insert($t,['employee_id'=>(int)$employee_id,'location_id'=>$id,'created_at'=>current_time('mysql')],['%d','%d','%s']);
        $name=function($list)use($locs){return implode(', ',array_map(function($id)use($locs){return isset($locs[$id])?$locs[$id]->name:'#'.$id;},$list))?:'none';};
        $this->audit('employee_branches_update','employee',(int)$employee_id,'Other branches: '.$name($before).' -> '.$name($ids));
    }
}
