<?php
if (!defined('ABSPATH')) exit;

/**
 * People: the employees table. Its functions for the other modules are first (modular monolith,
 * docs/architecture/modules.md): the other modules ask here instead of querying the table, so a rule
 * about employees (who is active, who is in a department) lives in one place.
 */
trait EWS_Employees_Trait {

    /* ------------------------------------------------------------------ People's functions for the other modules */

    /** An employee's row (all columns), or null. $active_only: null for an archived one. */
    private function people_employee($employee_id,$active_only=false){
        global $wpdb;
        $id=(int)$employee_id;
        if($id<=0)return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d".($active_only?' AND active=1':'')." LIMIT 1",$id));
    }

    /** The active employee linked to a WordPress user, or null. */
    private function people_employee_by_user($user_id){
        global $wpdb;
        $uid=(int)$user_id;
        if($uid<=0)return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d AND active=1 ORDER BY id ASC LIMIT 1",$uid));
    }

    /**
     * The names of the active employees linked to WordPress users, for many users at once (3.31.112): user id => name. A user
     * linked to two active employees gets the lowest id, as people_employee_by_user().
     * @param array<int,int> $user_ids
     * @return array<int,string>
     */
    private function people_names_by_user(array $user_ids){
        global $wpdb;$out=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$user_ids))));
        foreach(array_chunk($ids,500) as $chunk){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT wp_user_id,name FROM {$this->employees} WHERE active=1 AND wp_user_id IN (".implode(',',array_fill(0,count($chunk),'%d')).') ORDER BY id ASC',$chunk)) as $e){
                if(!isset($out[(int)$e->wp_user_id]))$out[(int)$e->wp_user_id]=(string)$e->name;
            }
        }
        return $out;
    }

    /**
     * id, name and WordPress user of many employees at once (3.31.112), active or not.
     * @param array<int,int> $employee_ids
     * @return array<int,object>
     */
    /** Whole employee rows for many ids with one query per 500 (3.31.124), only the active ones when asked. @return array<int,object> */
    private function people_by_ids(array $employee_ids,$active_only=false){
        global $wpdb;$out=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$employee_ids))));
        foreach(array_chunk($ids,500) as $chunk){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id IN (".implode(',',array_fill(0,count($chunk),'%d')).')'.($active_only?' AND active=1':''),$chunk)) as $e)$out[(int)$e->id]=$e;
        }
        return $out;
    }

    private function people_brief_by_ids(array $employee_ids){
        global $wpdb;$out=[];
        $ids=array_values(array_unique(array_filter(array_map('intval',$employee_ids))));
        foreach(array_chunk($ids,500) as $chunk){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,name,wp_user_id FROM {$this->employees} WHERE id IN (".implode(',',array_fill(0,count($chunk),'%d')).')',$chunk)) as $e)$out[(int)$e->id]=$e;
        }
        return $out;
    }

    /** The id if the employee exists and is active (as a string, like a query result), else null. */
    private function people_active_id($employee_id){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",(int)$employee_id));
    }

    /** An employee's name, or null. */
    private function people_name($employee_id){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->employees} WHERE id=%d",(int)$employee_id));
    }

    /** The WordPress user of an employee (as a string, like a query result), or null. $active_only: null for an archived one. */
    private function people_user_id($employee_id,$active_only=false){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d".($active_only?' AND active=1':'')." LIMIT 1",(int)$employee_id));
    }

    /** Is the employee active and in this department? */
    /** The active employees of a department (3.31.103). @return list<int> */
    private function people_department_member_ids($department_id){
        global $wpdb;
        return array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->employees} WHERE department_id=%d AND active=1",(int)$department_id)));
    }

    private function people_in_department($employee_id,$department_id){
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",(int)$employee_id,(int)$department_id));
    }

    /** The active employees (id, name, domain_name), by name; at most $limit when given. @return object[] */
    /** The shift an employee is on now (employees.default_shift_id; 0 = company hours). */
    private function people_shift_id($employee_id){
        global $wpdb;
        $id=(int)$employee_id;
        if(isset(self::$ews_people_shift_memo[$id]))return self::$ews_people_shift_memo[$id];
        return self::$ews_people_shift_memo[$id]=(int)$wpdb->get_var($wpdb->prepare("SELECT default_shift_id FROM {$this->employees} WHERE id=%d",$id));
    }

    /**
     * The shift many employees are on now, in one query (3.31.126), kept for the request as people_shift_id() keeps it.
     * An employee loaded with default_shift_id is not read again. @param array<int,object|int> $people @return array<int,int> by id
     */
    private function people_shift_ids(array $people){
        global $wpdb;
        $ids=[];$missing=[];
        foreach($people as $p){
            $id=is_object($p)?(int)($p->id??0):(int)$p;
            if($id<=0)continue;
            $ids[$id]=$id;
            if(isset(self::$ews_people_shift_memo[$id]))continue;
            if(is_object($p)&&property_exists($p,'default_shift_id'))self::$ews_people_shift_memo[$id]=(int)$p->default_shift_id;
            else $missing[$id]=$id;
        }
        foreach(array_chunk(array_values($missing),500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,default_shift_id FROM {$this->employees} WHERE id IN ($ph)",$chunk)) as $r)self::$ews_people_shift_memo[(int)$r->id]=(int)$r->default_shift_id;
            foreach($chunk as $id)if(!isset(self::$ews_people_shift_memo[$id]))self::$ews_people_shift_memo[$id]=0;   // as people_shift_id() for an unknown id
        }
        $out=[];
        foreach($ids as $id)$out[$id]=self::$ews_people_shift_memo[$id];
        return $out;
    }

    /** Set the shift an employee is on now (the shift history keeps it in step, 3.31.108). @return bool false on a database error (3.31.119) */
    private function people_set_shift_id($employee_id,$shift_id){
        global $wpdb;
        unset(self::$ews_people_shift_memo[(int)$employee_id]);
        if($shift_id)return $wpdb->query($wpdb->prepare("UPDATE {$this->employees} SET default_shift_id=%d WHERE id=%d AND COALESCE(default_shift_id,0)<>%d",(int)$shift_id,(int)$employee_id,(int)$shift_id))!==false;
        return $wpdb->query($wpdb->prepare("UPDATE {$this->employees} SET default_shift_id=NULL WHERE id=%d AND default_shift_id IS NOT NULL",(int)$employee_id))!==false;
    }

    /** Every employee (archived too) with the shift they are on now. @return array<int,int> id => shift id */
    private function people_all_shift_ids(){
        global $wpdb;$out=[];
        foreach((array)$wpdb->get_results("SELECT id,default_shift_id FROM {$this->employees}") as $r)$out[(int)$r->id]=(int)$r->default_shift_id;
        return $out;
    }

    private function people_active_list($limit=0){
        global $wpdb;
        return (array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC".($limit>0?' LIMIT '.(int)$limit:''));
    }

    /** The active employees with an app account (id, name, wp_user_id, department_id, active), by name. @return object[] */
    private function people_active_users(){
        global $wpdb;
        return (array)$wpdb->get_results("SELECT id,name,wp_user_id,department_id,active FROM {$this->employees} WHERE active=1 AND wp_user_id>0 ORDER BY name");
    }

    /** The ids of the active employees (only those whose attendance is tracked, with $attendance_only). @return string[] */
    private function people_active_ids($attendance_only=false){
        global $wpdb;
        return (array)$wpdb->get_col("SELECT id FROM {$this->employees} WHERE active=1".($attendance_only?' AND (attendance_enabled IS NULL OR attendance_enabled=1)':''));
    }

    /** The WordPress users of the active employees (of one department when given). @return string[] */
    private function people_active_user_ids($department_id=0){
        global $wpdb;
        $sql="SELECT wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL AND wp_user_id>0";
        return (array)($department_id?$wpdb->get_col($wpdb->prepare($sql.' AND department_id=%d',(int)$department_id)):$wpdb->get_col($sql));
    }

    /** The employee's photo (People, 3.31.97: moved from the app layout) or chosen avatar ('' = show initials). @param object|null $emp */
    private function employee_picture_url($emp){
        if($emp && ($emp->profile_image_type??'')==='photo' && !empty($emp->profile_image_url))return (string)$emp->profile_image_url;
        if($emp && ($emp->profile_image_type??'')==='avatar' && !empty($emp->avatar_key))return (string)$this->profile_avatar_url($emp->avatar_key);
        return '';
    }

    /** How many active employees (of one department when given; only those with an app account, with $with_user). */
    private function people_active_count($department_id=0,$with_user=false){
        global $wpdb;
        $sql="SELECT COUNT(*) FROM {$this->employees} WHERE active=1".($with_user?' AND wp_user_id>0':'');
        return (int)($department_id?$wpdb->get_var($wpdb->prepare($sql.' AND department_id=%d',(int)$department_id)):$wpdb->get_var($sql));
    }

    /** Employee app → Employees (managers): the active employees and Add employee (templates/app/employees.php). */
    private function employees_content(){
        global $wpdb;
        $rows=(array)$wpdb->get_results("SELECT id,name,domain_name,email FROM {$this->employees} WHERE active=1 ORDER BY name");
        $team_ids=$this->team_ids_by_employee(array_map(static function($r){return (int)$r->id;},$rows));
        $names=[];$all=array_unique(array_merge([],...array_values($team_ids?:[[]])));
        if($all){$t=$this->team_tables();$ph=implode(',',array_fill(0,count($all),'%d'));foreach((array)$wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$t['teams']} WHERE id IN ($ph)",array_values($all))) as $tm)$names[(int)$tm->id]=(string)$tm->name;}
        foreach($rows as $r){$r->teams=array_values(array_filter(array_map(static function($id)use($names){return $names[$id]??'';},$team_ids[(int)$r->id]??[])));$r->url=add_query_arg(['ews_view'=>'employee','employee_id'=>(int)$r->id],$this->app_view_url('people'));}
        wp_enqueue_style('workforce-one-employees-page');
        wp_enqueue_script('workforce-one-employees');
        return $this->render_template('app/employees',['rows'=>$rows,'post_url'=>admin_url('admin-post.php'),'admin_url'=>current_user_can('manage_options')||$this->can('ews_manage_employees')?admin_url('admin.php?page=ews31-employees'):'']);
    }

    private function ensure_employee_email_column(){
        if($this->ews_schema_is_current())return;
            global $wpdb;
            $table=$this->employees;
            $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
            if($exists!==$table) return;
            $has=$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'email'");
            if(!$has){
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN email VARCHAR(190) NULL AFTER domain_name");
                $wpdb->query("ALTER TABLE {$table} ADD KEY email (email)");
            }
        }

    public function email_save(){
            if(!$this->can('ews_manage_settings')) wp_die('Access denied');
            check_admin_referer('ews31_email_save');
            $raw=sanitize_textarea_field(wp_unslash($_POST['emails']??''));
            $parts=preg_split('/[\\,;\\r\\n]+/',$raw); $valid=[];
            foreach($parts as $email){$email=trim($email);if($email&&is_email($email))$valid[]=$email;}
            update_option('ews_attendance_emails',implode(',',$valid));
            $this->audit('email_settings_update','email_settings',0,implode(',',$valid));
            wp_safe_redirect(admin_url('admin.php?page=ews31-email&saved=1'));exit;
        }

    public function leave_save(){if(!$this->can('ews_manage_settings'))wp_die('Access denied');}
}
