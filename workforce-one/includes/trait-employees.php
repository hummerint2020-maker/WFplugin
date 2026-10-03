<?php
if (!defined('ABSPATH')) exit;

trait EWS_Employees_Trait {

    private function employees_content(){global $wpdb;$rows=$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 ORDER BY name");ob_start();?><div class="ews-card ews-form"><h3>Add Employee</h3><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="ews31_employee_save"><?php wp_nonce_field('ews31_employee_save');?><div class="ews-grid2"><label>Name<input name="name" required></label><label>Domain Name<input name="domain_name" required></label></div><label>Email Address<input type="email" name="email" placeholder="employee@company.com" required></label><button class="ews-btn">Add Employee</button></form></div><div class="ews-card ews-week"><table class="ews-table"><thead><tr><th>Name</th><th>Domain</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?php echo esc_html($r->name);?></td><td><?php echo esc_html($r->domain_name);?></td></tr><?php endforeach;?></tbody></table></div><?php return ob_get_clean();}

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
