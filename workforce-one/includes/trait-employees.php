<?php
if (!defined('ABSPATH')) exit;

trait EWS_Employees_Trait {

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
