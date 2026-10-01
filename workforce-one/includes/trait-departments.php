<?php
if (!defined('ABSPATH')) exit;

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

    public function admin_departments(){
        if(!current_user_can('manage_options')) wp_die('Access denied');
        $this->ensure_departments_schema(); global $wpdb; $t=$this->department_tables();
        $edit_id=absint($_GET['department_id']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['departments']} WHERE id=%d LIMIT 1",$edit_id)):null;
        $departments=$wpdb->get_results("SELECT d.*,e.name manager_name,(SELECT COUNT(*) FROM {$this->employees} x WHERE x.department_id=d.id AND x.active=1) employee_count FROM {$t['departments']} d LEFT JOIN {$this->employees} e ON e.id=d.manager_employee_id WHERE d.active=1 ORDER BY d.name ASC");
        $employees=$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
        echo '<div class="wrap"><h1>Departments</h1>';
        if(isset($_GET['department_saved']))echo '<div class="notice notice-success is-dismissible"><p>Department saved successfully.</p></div>';
        if(isset($_GET['department_deleted']))echo '<div class="notice notice-success is-dismissible"><p>Department archived successfully.</p></div>';
        if(isset($_GET['department_error']))echo '<div class="notice notice-error is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['department_error']))).'</p></div>';
        echo '<div style="display:grid;grid-template-columns:minmax(320px,520px) 1fr;gap:20px;align-items:start">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">'.($edit?'Edit Department':'Create Department').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_department_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_department_save"><input type="hidden" name="department_id" value="'.(int)($edit?$edit->id:0).'">';
        echo '<p><input name="name" required placeholder="Department Name" value="'.esc_attr($edit->name??'').'" style="width:100%"></p>';
        echo '<p><input name="code" required placeholder="Department Code" value="'.esc_attr($edit->code??'').'" style="width:100%"></p>';
        echo '<p><textarea name="description" placeholder="Description" style="width:100%;min-height:90px">'.esc_textarea($edit->description??'').'</textarea></p>';
        echo '<p><select name="manager_employee_id" style="width:100%"><option value="0">-- No Department Manager --</option>';
        foreach($employees as $e) echo '<option value="'.(int)$e->id.'" '.selected((int)($edit->manager_employee_id??0),(int)$e->id,false).'>'.esc_html($e->name.' ('.$e->domain_name.')').'</option>';
        echo '</select></p><p><button class="button button-primary">'.($edit?'Save Department':'Create Department').'</button> '.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-departments')).'">Cancel</a>':'').'</p></form></div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">Active Departments</h2>';
        if(!$departments) echo '<p>No departments created yet.</p>';
        else { echo '<table class="widefat striped"><thead><tr><th>Department</th><th>Code</th><th>Manager</th><th>Employees</th><th>Actions</th></tr></thead><tbody>'; foreach($departments as $d){echo '<tr><td><strong>'.esc_html($d->name).'</strong>'.($d->description?'<br><span style="color:#646970">'.esc_html($d->description).'</span>':'').'</td><td>'.esc_html($d->code).'</td><td>'.esc_html($d->manager_name?:'—').'</td><td>'.(int)$d->employee_count.'</td><td><a class="button button-small" href="'.esc_url(add_query_arg(['page'=>'ews31-departments','department_id'=>(int)$d->id],admin_url('admin.php'))).'">Edit</a> <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('ews_department_delete_'.$d->id,'_wpnonce',true,false).'<input type="hidden" name="action" value="ews_department_delete"><input type="hidden" name="department_id" value="'.(int)$d->id.'"><button class="button button-small" type="submit" onclick="return confirm(\'Archive this department?\')">Archive</button></form></td></tr>';} echo '</tbody></table>'; }
        echo '</div></div></div>';
    }

    public function department_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); check_admin_referer('ews_department_save'); $this->ensure_departments_schema();
        global $wpdb; $t=$this->department_tables();
        $id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $code=sanitize_key($_POST['code']??''); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        if(!$name||!$code)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Name+and+code+are+required')) or exit;
        $dup=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['departments']} WHERE (name=%s OR code=%s) AND id<>%d LIMIT 1",$name,$code,$id));
        if($dup)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Department+name+or+code+already+exists')) or exit;
        if($manager){$ok=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$manager,$id)); if(!$ok && $id)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Manager+must+belong+to+this+department')) or exit; if(!$ok && !$id)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Assign+the+manager+after+the+department+is+created')) or exit;}
        $now=current_time('mysql');
        if($id)$ok=$wpdb->update($t['departments'],['name'=>$name,'code'=>$code,'description'=>$description,'manager_employee_id'=>$manager?:null,'updated_at'=>$now],['id'=>$id],['%s','%s','%s','%d','%s'],['%d']);
        else {$ok=$wpdb->insert($t['departments'],['name'=>$name,'code'=>$code,'description'=>$description,'manager_employee_id'=>$manager?:null,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%s','%s','%s','%d','%d','%s','%s']);$id=(int)$wpdb->insert_id;}
        if($ok===false)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Could+not+save+department')) or exit;
        $this->audit($id?'department_update':'department_create','department',$id,$name.' / '.$code);
        wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_saved=1'));exit;
    }

    public function department_delete(){
        if(!current_user_can('manage_options'))wp_die('Access denied'); $id=absint($_POST['department_id']??0); check_admin_referer('ews_department_delete_'.$id); $this->ensure_departments_schema(); global $wpdb; $t=$this->department_tables();
        $employee_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE department_id=%d AND active=1",$id));
        if($employee_count>0)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Department+still+has+active+employees')) or exit;
        $team_table=$wpdb->prefix.'ews_teams';
        $team_count=0;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$team_table))===$team_table)$team_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$team_table} WHERE department_id=%d AND active=1",$id));
        if($team_count>0)wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_error=Department+still+has+active+teams')) or exit;
        $ok=$wpdb->update($t['departments'],['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']); if($ok===false)wp_die('Could not archive department.');
        $this->audit('department_archive','department',$id); wp_safe_redirect(admin_url('admin.php?page=ews31-departments&department_deleted=1'));exit;
    }
}
