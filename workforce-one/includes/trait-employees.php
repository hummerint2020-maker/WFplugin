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
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d AND active=1 LIMIT 1",$uid));
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
        return (int)$wpdb->get_var($wpdb->prepare("SELECT default_shift_id FROM {$this->employees} WHERE id=%d",(int)$employee_id));
    }

    /** Set the shift an employee is on now (the shift history keeps it in step, 3.31.108). */
    private function people_set_shift_id($employee_id,$shift_id){
        global $wpdb;
        if($shift_id)$wpdb->query($wpdb->prepare("UPDATE {$this->employees} SET default_shift_id=%d WHERE id=%d AND COALESCE(default_shift_id,0)<>%d",(int)$shift_id,(int)$employee_id,(int)$shift_id));
        else $wpdb->query($wpdb->prepare("UPDATE {$this->employees} SET default_shift_id=NULL WHERE id=%d AND default_shift_id IS NOT NULL",(int)$employee_id));
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
