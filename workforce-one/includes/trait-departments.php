<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Organization\OrgRules;

/**
 * Workforce One Department / operational scope foundation.
 * Department is an operational boundary for Schedule and Attendance.
 * People/Recognition remain company-wide.
 */
trait EWS_Departments_Trait {
    private function department_tables(){
        global $wpdb;
        return ['departments'=>$wpdb->prefix.'ews_departments'];
    }

    /** The active manager of an employee's department (null: no department, or no manager; 3.31.100, the Department manager approver). */
    private function people_department_manager($employee_id){
        $this->ensure_departments_schema();
        return $this->services()->org()->departmentManager((int)$employee_id);
    }

    private function ensure_departments_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate(); $t=$this->department_tables();
        dbDelta("CREATE TABLE {$t['departments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            code VARCHAR(80) NOT NULL,
            description TEXT NULL,
            manager_employee_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY department_name(name),
            UNIQUE KEY department_code(code),
            KEY manager_employee(manager_employee_id),
            KEY active(active)
        ) {$c};");
        $has=$wpdb->get_var("SHOW COLUMNS FROM {$this->employees} LIKE 'department_id'");
        if(!$has){
            $wpdb->query("ALTER TABLE {$this->employees} ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER default_shift_id");
            $wpdb->query("ALTER TABLE {$this->employees} ADD KEY department_id (department_id)");
        }
        $tt=$wpdb->prefix.'ews_teams';
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$tt))===$tt){
            $has=$wpdb->get_var("SHOW COLUMNS FROM {$tt} LIKE 'department_id'");
            if(!$has){
                $wpdb->query("ALTER TABLE {$tt} ADD COLUMN department_id BIGINT UNSIGNED NULL AFTER manager_employee_id");
                $wpdb->query("ALTER TABLE {$tt} ADD KEY department_id (department_id)");
            }
        }
    }

    private function current_department_id(){
        if(current_user_can('manage_options')) return 0;
        $emp=$this->current_employee();
        return $emp ? (int)$emp->department_id : 0;
    }

    private function department_scope_allows_employee($employee_id){
        $employee_id=absint($employee_id);
        if(!$employee_id) return false;
        if(current_user_can('manage_options')) return true;
        $dept=$this->current_department_id();
        if(!$dept) return false;
        global $wpdb;
        return (bool)$this->people_in_department($employee_id,$dept);
    }

    private function department_scoped_employees($employees=null){
        $employees=$employees===null?$this->emps():$employees;
        if(current_user_can('manage_options')) return (array)$employees;
        $dept=$this->current_department_id();
        if(!$dept) return [];
        return array_values(array_filter((array)$employees,function($e)use($dept){return (int)($e->department_id??0)===$dept;}));
    }

    private function department_employee_ids(){
        return array_map('intval',wp_list_pluck($this->department_scoped_employees(),'id'));
    }



    private function department_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-departments')));exit;
    }

    /** wp-admin → Departments: create / edit a department and its manager; archive empty ones. */
    public function admin_departments(){
        if(!current_user_can('manage_options')) wp_die('Access denied');
        $this->ensure_departments_schema();
        $edit_id=absint($_GET['department_id']??0);
        $departments=$this->services()->departments();
        echo $this->render_template('admin/departments',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'edit'=>$edit_id?$departments->byId($edit_id):null,
            'departments'=>$departments->adminList(),
            'employees'=>(array)$this->people_active_list(),
            'notice'=>isset($_GET['department_saved'])?'Department saved successfully.':(isset($_GET['department_deleted'])?'Department archived successfully.':''),
            'error'=>OrgRules::message('department',sanitize_key(wp_unslash($_GET['department_error']??''))),
        ]);
    }

    public function department_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); check_admin_referer('ews_department_save'); $this->ensure_departments_schema();
        $id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $code=sanitize_key($_POST['code']??''); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        $error=$this->services()->departments()->save($id,$name,$code,$description,$manager);
        if($error!=='')$this->department_redirect(['department_error'=>$error]);
        $this->department_redirect(['department_saved'=>1]);
    }

    public function department_delete(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); $id=absint($_POST['department_id']??0); check_admin_referer('ews_department_delete_'.$id); $this->ensure_departments_schema();
        $error=$this->services()->departments()->archive($id);
        if($error==='db')wp_die('Could not archive department.');
        if($error!=='')$this->department_redirect(['department_error'=>$error]);
        $this->department_redirect(['department_deleted'=>1]);
    }
}
