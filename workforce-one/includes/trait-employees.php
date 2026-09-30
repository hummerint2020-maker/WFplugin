<?php
if (!defined('ABSPATH')) exit;

trait EWS_Employees_Trait {

    private function employees_content(){global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 ORDER BY name");ob_start();?><div class="ews-card ews-form"><h3>Add Employee</h3><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ews31_employee_save"><?php wp_nonce_field('ews31_employee_save');?><div class="ews-grid2"><label>Name<input name="name" required></label><label>Domain Name<input name="domain_name" required></label></div><label>Email Address<input type="email" name="email" placeholder="employee@company.com" required></label><button class="ews-btn">Add Employee</button></form></div><div class="ews-card ews-week"><table class="ews-table"><thead><tr><th>Name</th><th>Domain</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?php echo esc_html($r->name);?></td><td><?php echo esc_html($r->domain_name);?></td></tr><?php endforeach;?></tbody></table></div><?php return ob_get_clean();}

    public function employee_save(){if(!$this->can('ews_manage_employees'))wp_die('Access denied');check_admin_referer('ews31_employee_save');global $wpdb;$n=sanitize_text_field($_POST['name']);$d=sanitize_text_field($_POST['domain_name']);$email=sanitize_email($_POST['email']??'');$wp_user_id=absint($_POST['wp_user_id']??0);$default_shift_id=absint($_POST['default_shift_id']??0);$supervisor_id=absint($_POST['supervisor_employee_id']??0);$department_id=absint($_POST['department_id']??0);$attendance_enabled=isset($_POST['attendance_enabled'])?1:0;$this->ensure_departments_schema();if($department_id&&!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_departments WHERE id=%d AND active=1",$department_id)))wp_die('Invalid Department.');if(!$n||!$d||!$email||!is_email($email))wp_die('Name, Domain Name and a valid Email Address are required.');$dup=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE domain_name=%s",$d));if($dup)wp_die('Domain Name already exists.');$ok=$wpdb->insert($this->employees,['name'=>$n,'domain_name'=>$d,'email'=>$email,'wp_user_id'=>$wp_user_id,'default_shift_id'=>$default_shift_id,'attendance_enabled'=>$attendance_enabled,'department_id'=>$department_id]);if($ok===false)wp_die('Could not create employee. Database error: '.esc_html($wpdb->last_error));$new_employee_id=(int)$wpdb->insert_id;
        $this->invalidate_employee_runtime_cache($new_employee_id,$wp_user_id);if($supervisor_id&&$supervisor_id===$new_employee_id)wp_die('An employee cannot be their own supervisor.');$this->ensure_approval_schema();if($supervisor_id){$rel=$this->approval_set_relationship($new_employee_id,'supervisor',$supervisor_id,0);if(is_wp_error($rel))wp_die('Employee saved, but supervisor could not be saved. '.esc_html($rel->get_error_message()));}$this->audit('employee_create','employee',$new_employee_id,$n.' / '.$d);$this->redirect(['ews_view'=>'employees']);}

    private function ensure_employee_email_column(){
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

    public function employee_update(){
            if(!$this->can('ews_manage_employees')) wp_die('Access denied');
            check_admin_referer('ews31_employee_update');
            global $wpdb;
            $id=absint($_POST['id']??0);
            $name=sanitize_text_field($_POST['name']??'');
            $domain=sanitize_text_field($_POST['domain_name']??'');
            $email=sanitize_email($_POST['email']??'');$wp_user_id=absint($_POST['wp_user_id']??0);$default_shift_id=absint($_POST['default_shift_id']??0);$supervisor_id=absint($_POST['supervisor_employee_id']??0);$department_id=absint($_POST['department_id']??0);$attendance_enabled=isset($_POST['attendance_enabled'])?1:0;$team_ids=isset($_POST['team_ids'])&&is_array($_POST['team_ids'])?array_values(array_unique(array_filter(array_map('absint',$_POST['team_ids'])))):[];
            $this->ensure_departments_schema();if($department_id&&!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_departments WHERE id=%d AND active=1",$department_id)))wp_die('Invalid Department.');$this->ensure_teams_schema();$invalid_team=false;foreach($team_ids as $team_id){$team_department=$wpdb->get_var($wpdb->prepare("SELECT department_id FROM {$wpdb->prefix}ews_teams WHERE id=%d AND active=1 LIMIT 1",$team_id));if($team_department===null || (int)$team_department!==$department_id){$invalid_team=true;break;}}if($invalid_team)wp_die('Selected team must belong to the selected Department.');$active=isset($_POST['active'])&&in_array((string)$_POST['active'],['0','1'],true)?(int)$_POST['active']:1;
            if(!$id||!$name||!$domain||!$email||!is_email($email))wp_die('Name, Domain Name and valid Email are required.');
            $dup=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE domain_name=%s AND id<>%d",$domain,$id));
            if($dup)wp_die('Domain Name already exists.');
            $old_employee=$wpdb->get_row($wpdb->prepare("SELECT active FROM {$this->employees} WHERE id=%d LIMIT 1",$id));
            if(!$old_employee)wp_die('Employee not found.');
            $old_active=(int)$old_employee->active; $old_department=(int)($old_employee->department_id??0);
            if($old_department!==$department_id){
                $managed_team_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_teams WHERE manager_employee_id=%d AND active=1 AND department_id<>%d",$id,$department_id));
                if($managed_team_count>0)wp_die('Employee manages an active team in another Department. Reassign that Team Manager first.');
            }
            $updated=$wpdb->update($this->employees,['name'=>$name,'domain_name'=>$domain,'email'=>$email,'wp_user_id'=>$wp_user_id,'default_shift_id'=>$default_shift_id,'attendance_enabled'=>$attendance_enabled,'department_id'=>$department_id,'active'=>$active,'updated_at'=>current_time('mysql')],['id'=>$id]);
            if($updated===false) wp_die('Could not save employee. Database error: '.esc_html($wpdb->last_error));
            $this->invalidate_employee_runtime_cache($id,$wp_user_id);
            if($supervisor_id===$id)wp_die('An employee cannot be their own supervisor.');
            $this->ensure_approval_schema();$rt=$this->approval_tables()['relationships'];
            if($supervisor_id){$rel=$this->approval_set_relationship($id,'supervisor',$supervisor_id,0);if(is_wp_error($rel))wp_die('Employee saved, but supervisor could not be saved. '.esc_html($rel->get_error_message()));}
            else {$wpdb->query($wpdb->prepare("UPDATE {$rt} SET active=0,updated_at=%s WHERE employee_id=%d AND relationship_type='supervisor' AND active=1",current_time('mysql'),$id));}
            $this->ensure_teams_schema();$team_result=$this->sync_employee_teams($id,$team_ids);if(is_wp_error($team_result)){wp_safe_redirect(admin_url('admin.php?page=ews31-employees&team_update_error=1'));exit;}
            $this->audit('employee_update','employee',$id,$name.' / '.$domain.' / '.$email.' / teams='.count($team_ids));
            if($old_active!==$active){$this->audit('employee_status_change','employee',$id,'Status: '.($old_active?'Active':'Inactive').' -> '.($active?'Active':'Inactive'));}
            wp_safe_redirect(admin_url('admin.php?page=ews31-employees&updated=1'));exit;
        }

    public function employee_archive(){
            if(!$this->can('ews_manage_employees')) wp_die('Access denied');
            check_admin_referer('ews31_employee_archive');
            global $wpdb; $id=absint($_POST['id']??0);
            if($id){$old=(int)$wpdb->get_var($wpdb->prepare("SELECT active FROM {$this->employees} WHERE id=%d LIMIT 1",$id));$ok=$wpdb->update($this->employees,['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id]);if($ok===false)wp_die('Could not archive employee. Database error: '.esc_html($wpdb->last_error));
        $this->invalidate_employee_runtime_cache($id);if($old!==0)$this->audit('employee_status_change','employee',$id,'Status: Active -> Inactive');}
            wp_safe_redirect(admin_url('admin.php?page=ews31-employees'));exit;
        }

    public function email_save(){
            if(!$this->can('ews_manage_settings')) wp_die('Access denied');
            check_admin_referer('ews31_email_save');
            $raw=sanitize_textarea_field($_POST['emails']??'');
            $parts=preg_split('/[\\,;\\r\\n]+/',$raw); $valid=[];
            foreach($parts as $email){$email=trim($email);if($email&&is_email($email))$valid[]=$email;}
            update_option('ews_attendance_emails',implode(',',$valid));
            $this->audit('email_settings_update','email_settings',0,implode(',',$valid));
            wp_safe_redirect(admin_url('admin.php?page=ews31-email&saved=1'));exit;
        }

    public function leave_save(){if(!$this->can('ews_manage_settings'))wp_die('Access denied');}
}
