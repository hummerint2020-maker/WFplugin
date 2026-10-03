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
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$employee_id,$dept));
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

    private function department_for_employee($employee_id){
        global $wpdb; $t=$this->department_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT d.* FROM {$t['departments']} d JOIN {$this->employees} e ON e.department_id=d.id WHERE e.id=%d AND d.active=1 LIMIT 1",absint($employee_id)));
    }

    private function department_same($employee_a,$employee_b){
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$this->employees} a JOIN {$this->employees} b ON b.id=%d WHERE a.id=%d AND a.active=1 AND b.active=1 AND a.department_id IS NOT NULL AND a.department_id=b.department_id LIMIT 1",absint($employee_b),absint($employee_a)));
    }

    private function department_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-departments')));exit;
    }

    /** wp-admin → Departments: create / edit a department and its manager; archive empty ones. */
    public function admin_departments(){
        if(!current_user_can('manage_options')) wp_die('Access denied');
        $this->ensure_departments_schema(); global $wpdb; $t=$this->department_tables();
        $edit_id=absint($_GET['department_id']??0);
        echo $this->render_template('admin/departments',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'edit'=>$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['departments']} WHERE id=%d LIMIT 1",$edit_id)):null,
            'departments'=>(array)$wpdb->get_results("SELECT d.*,e.name manager_name,(SELECT COUNT(*) FROM {$this->employees} x WHERE x.department_id=d.id AND x.active=1) employee_count FROM {$t['departments']} d LEFT JOIN {$this->employees} e ON e.id=d.manager_employee_id WHERE d.active=1 ORDER BY d.name ASC"),
            'employees'=>(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC"),
            'notice'=>isset($_GET['department_saved'])?'Department saved successfully.':(isset($_GET['department_deleted'])?'Department archived successfully.':''),
            'error'=>OrgRules::message('department',sanitize_key(wp_unslash($_GET['department_error']??''))),
        ]);
    }

    public function department_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); check_admin_referer('ews_department_save'); $this->ensure_departments_schema();
        global $wpdb; $t=$this->department_tables();
        $id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $code=sanitize_key($_POST['code']??''); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        $error=OrgRules::departmentError([
            'name'=>$name,'code'=>$code,'is_new'=>!$id,'manager'=>$manager,
            'manager_in_department'=>$manager&&$id&&$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$manager,$id)),
            'duplicate'=>OrgRules::duplicate((array)$wpdb->get_col($wpdb->prepare("SELECT active FROM {$t['departments']} WHERE (name=%s OR code=%s) AND id<>%d",$name,$code,$id))),
        ]);
        if($error!=='')$this->department_redirect(['department_error'=>$error]);
        $now=current_time('mysql');$is_new=!$id;
        if($id)$ok=$wpdb->update($t['departments'],['name'=>$name,'code'=>$code,'description'=>$description,'manager_employee_id'=>$manager?:null,'updated_at'=>$now],['id'=>$id],['%s','%s','%s','%d','%s'],['%d']);
        else {$ok=$wpdb->insert($t['departments'],['name'=>$name,'code'=>$code,'description'=>$description,'manager_employee_id'=>$manager?:null,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%s','%s','%s','%d','%d','%s','%s']);$id=(int)$wpdb->insert_id;}
        if($ok===false)$this->department_redirect(['department_error'=>'save']);
        $this->audit($is_new?'department_create':'department_update','department',$id,$name.' / '.$code);
        $this->department_redirect(['department_saved'=>1]);
    }

    public function department_delete(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); $id=absint($_POST['department_id']??0); check_admin_referer('ews_department_delete_'.$id); $this->ensure_departments_schema(); global $wpdb; $t=$this->department_tables();
        $team_table=$wpdb->prefix.'ews_teams';
        $teams=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$team_table))===$team_table?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$team_table} WHERE department_id=%d AND active=1",$id)):0;
        $error=OrgRules::departmentArchiveError((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE department_id=%d AND active=1",$id)),$teams);
        if($error!=='')$this->department_redirect(['department_error'=>$error]);
        $ok=$wpdb->update($t['departments'],['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']); if($ok===false)wp_die('Could not archive department.');
        $this->audit('department_archive','department',$id); $this->department_redirect(['department_deleted'=>1]);
    }
}
