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
        return $this->services()->people()->employee((int)$employee_id,(bool)$active_only);
    }

    /** The active employee linked to a WordPress user, or null. */
    private function people_employee_by_user($user_id){
        return $this->services()->people()->employeeByUser((int)$user_id);
    }

    /**
     * The names of the active employees linked to WordPress users, for many users at once (3.31.112): user id => name. A user
     * linked to two active employees gets the lowest id, as people_employee_by_user().
     * @param array<int,int> $user_ids
     * @return array<int,string>
     */
    private function people_names_by_user(array $user_ids){
        return $this->services()->people()->namesByUser($user_ids);
    }

    /**
     * id, name and WordPress user of many employees at once (3.31.112), active or not.
     * @param array<int,int> $employee_ids
     * @return array<int,object>
     */
    /** Whole employee rows for many ids with one query per 500 (3.31.124), only the active ones when asked. @return array<int,object> */
    private function people_by_ids(array $employee_ids,$active_only=false){
        return $this->services()->people()->byIds($employee_ids,(bool)$active_only);
    }

    private function people_brief_by_ids(array $employee_ids){
        return $this->services()->people()->briefsByIds($employee_ids);
    }

    /**
     * The names of many employees at once, id => name, archived ones too (3.31.133): the shared read for "a name next to a row",
     * one query per 500 ids. @param array<int,int> $employee_ids @return array<int,string>
     */
    private function people_names_by_ids(array $employee_ids){
        return $this->services()->people()->namesByIds($employee_ids);
    }

    /** The active employee related to this one by a relationship type (the newest line), or null (3.31.133: moved here from the approvals trait with the reporting lines). */
    private function approval_related_employee($employee_id,$relationship_type){
        return $this->services()->org()->relatedEmployee((int)$employee_id,sanitize_key($relationship_type));
    }

    /** Create/update one active relationship; V1 keeps one current target per type (3.31.134: moved here from the approvals trait with the reporting lines). */
    private function approval_set_relationship($employee_id,$relationship_type,$related_employee_id=0,$related_wp_user_id=0){
        $r=$this->services()->reporting()->setRelationship(absint($employee_id),sanitize_key($relationship_type),absint($related_employee_id),absint($related_wp_user_id));
        return is_array($r)?new WP_Error($r['code'],$r['message']):$r;
    }

    public function approval_relationship_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');check_admin_referer('ews_approval_relationship_save');
        $employee_id=absint($_POST['employee_id']??0);$supervisor_id=isset($_POST['supervisor_employee_ref'])?\WorkforceOne\Support\Picker::parse((string)wp_unslash($_POST['supervisor_employee_ref'])):absint($_POST['supervisor_employee_id']??0);
        $this->ensure_approval_schema();
        // Back to the same page and search of the Supervisor Relationships list.
        $back=['page'=>'ews31-approvals'];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$q);foreach(['paged','s'] as $k)if(!empty($q[$k]))$back[$k]=$k==='paged'?absint($q[$k]):sanitize_text_field((string)$q[$k]);
        $ok=$supervisor_id===0||($supervisor_id>0&&$supervisor_id!==$employee_id&&(bool)$this->people_active_id($supervisor_id));
        if(!$ok){wp_safe_redirect(add_query_arg($back+['approval_error'=>1,'approval_error_message'=>rawurlencode('Pick the supervisor from the list.')],admin_url('admin.php')).'#ews-supervisors');exit;}
        $this->services()->reporting()->savePicked($employee_id,$supervisor_id);
        $this->audit('approval_relationship_update','employee',$employee_id,'supervisor='.$supervisor_id);wp_safe_redirect(add_query_arg($back+['approval_saved'=>1],admin_url('admin.php')).'#ews-supervisors');exit;
    }

    /**
     * Each employee's active supervisor id (0 = none) for many employees in one query; the same answer as
     * approval_related_employee($id,'supervisor') per employee (the newest relationship).
     * @param list<int> $employee_ids @return array<int,int>
     */
    private function approval_supervisor_ids(array $employee_ids){
        return $this->services()->org()->supervisorIds($employee_ids);
    }

    /** The id if the employee exists and is active (as a string, like a query result), else null. */
    private function people_active_id($employee_id){
        return $this->services()->people()->activeId((int)$employee_id);
    }

    /** An employee's name, or null. */
    private function people_name($employee_id){
        return $this->services()->people()->name((int)$employee_id);
    }

    /** The WordPress user of an employee (as a string, like a query result), or null. $active_only: null for an archived one. */
    private function people_user_id($employee_id,$active_only=false){
        return $this->services()->people()->userId((int)$employee_id,(bool)$active_only);
    }

    /** Is the employee active and in this department? */
    /** The active employees of a department (3.31.103). @return list<int> */
    private function people_department_member_ids($department_id){
        return $this->services()->people()->departmentMemberIds((int)$department_id);
    }

    private function people_in_department($employee_id,$department_id){
        return $this->services()->people()->inDepartment((int)$employee_id,(int)$department_id);
    }

    /** The active employees (id, name, domain_name), by name; at most $limit when given. @return object[] */
    /** An active employee's name and WordPress user, or null (for the Schedule services, 3.31.127). */
    private function people_active_brief($employee_id){
        return $this->services()->people()->activeBrief((int)$employee_id);
    }

    /** The shift an employee is on now (employees.default_shift_id; 0 = company hours). */
    private function people_shift_id($employee_id){
        return $this->services()->people()->shiftId((int)$employee_id);
    }

    /**
     * The shift many employees are on now, in one query (3.31.126), kept for the request as people_shift_id() keeps it.
     * An employee loaded with default_shift_id is not read again. @param array<int,object|int> $people @return array<int,int> by id
     */
    private function people_shift_ids(array $people){
        return $this->services()->people()->shiftIds($people);
    }

    /** Set the shift an employee is on now (the shift history keeps it in step, 3.31.108). @return bool false on a database error (3.31.119) */
    private function people_set_shift_id($employee_id,$shift_id){
        return $this->services()->people()->setShiftId((int)$employee_id,(int)$shift_id);
    }

    /** Every employee (archived too) with the shift they are on now. @return array<int,int> id => shift id */
    private function people_all_shift_ids(){
        return $this->services()->people()->allShiftIds();
    }

    private function people_active_list($limit=0){
        return $this->services()->people()->activeList((int)$limit);
    }

    /** The active employees with an app account (id, name, wp_user_id, department_id, active), by name. @return object[] */
    private function people_active_users(){
        return $this->services()->people()->activeUsers();
    }

    /** The ids of the active employees (only those whose attendance is tracked, with $attendance_only). @return string[] */
    private function people_active_ids($attendance_only=false){
        return $this->services()->people()->activeIds((bool)$attendance_only);
    }

    /** The WordPress users of the active employees (of one department when given). @return string[] */
    private function people_active_user_ids($department_id=0){
        return $this->services()->people()->activeUserIds((int)$department_id);
    }

    /** The employee's photo (People, 3.31.97: moved from the app layout) or chosen avatar ('' = show initials). @param object|null $emp */
    private function employee_picture_url($emp){
        if($emp && ($emp->profile_image_type??'')==='photo' && !empty($emp->profile_image_url))return (string)$emp->profile_image_url;
        if($emp && ($emp->profile_image_type??'')==='avatar' && !empty($emp->avatar_key))return (string)$this->profile_avatar_url($emp->avatar_key);
        return '';
    }

    /** How many active employees (of one department when given; only those with an app account, with $with_user). */
    private function people_active_count($department_id=0,$with_user=false){
        return $this->services()->people()->activeCount((int)$department_id,(bool)$with_user);
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
