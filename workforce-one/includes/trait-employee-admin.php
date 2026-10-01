<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\EmployeeRules;

/**
 * wp-admin "Employees" page: add, update and archive employees. Every check runs before anything
 * is written. Rules: src/Employees/EmployeeRules.php. Behaviour: tests/e2e_employees.py.
 */
trait EWS_Employee_Admin_Trait {

    /** Back to the page the form came from: wp-admin Employees, or the app's Employees view. */
    private function employee_admin_redirect($args){
        $referer=(string)wp_get_referer();
        if($referer!=='' && strpos($referer,'/wp-admin/')===false){
            if(isset($args['employee_error']))wp_die(esc_html(EmployeeRules::errorMessage($args['employee_error'])));
            $this->redirect(['ews_view'=>'employees']);
        }
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-employees')));
        exit;
    }

    /** Reads and validates the posted employee. @return array{0: array<string,mixed>, 1: ?string} */
    private function employee_admin_input($id,$old_department=null){
        global $wpdb;
        $in=[
            'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),
            'domain_name'=>sanitize_text_field(wp_unslash($_POST['domain_name']??'')),
            'email'=>sanitize_email(wp_unslash($_POST['email']??'')),
            'wp_user_id'=>absint($_POST['wp_user_id']??0),
            'default_shift_id'=>absint($_POST['default_shift_id']??0),
            'supervisor_id'=>absint($_POST['supervisor_employee_id']??0),
            'department_id'=>absint($_POST['department_id']??0),
            'attendance_enabled'=>isset($_POST['attendance_enabled'])?1:0,
            'team_ids'=>isset($_POST['team_ids'])&&is_array($_POST['team_ids'])?array_values(array_unique(array_filter(array_map('absint',$_POST['team_ids'])))):[],
            'active'=>isset($_POST['active'])&&in_array((string)$_POST['active'],['0','1'],true)?(int)$_POST['active']:1,
        ];
        $this->ensure_departments_schema();$this->ensure_teams_schema();$this->ensure_approval_schema();
        $teams=$this->team_tables()['teams'];
        $facts=[
            'id'=>$id,'name'=>$in['name'],'domain'=>$in['domain_name'],'email_valid'=>$in['email']!==''&&is_email($in['email']),
            'department_ok'=>!$in['department_id']||(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_departments WHERE id=%d AND active=1",$in['department_id'])),
            'domain_taken'=>(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE domain_name=%s AND id<>%d LIMIT 1",$in['domain_name'],$id)),
            'user_taken'=>$in['wp_user_id']&&(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE wp_user_id=%d AND id<>%d LIMIT 1",$in['wp_user_id'],$id)),
            'supervisor_id'=>$in['supervisor_id'],
        ];
        if($in['supervisor_id']){
            $facts['supervisor_ok']=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$in['supervisor_id']));
            $above=$this->approval_related_employee($in['supervisor_id'],'supervisor');
            $facts['supervisors_supervisor']=$above?(int)$above->id:0;
        }
        if($in['team_ids']){
            $ph=implode(',',array_fill(0,count($in['team_ids']),'%d'));
            $matching=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$teams} WHERE active=1 AND department_id=%d AND id IN ($ph)",array_merge([$in['department_id']],$in['team_ids'])));
            $facts['teams_in_department']=$matching===count($in['team_ids']);
        }
        // Only a department change can strand the teams this employee manages.
        if($id && $old_department!==null && $old_department!==$in['department_id'])$facts['manages_team_elsewhere']=(bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$teams} WHERE manager_employee_id=%d AND active=1 AND department_id<>%d",$id,$in['department_id']));
        return [$in,EmployeeRules::check($facts)];
    }

    private function employee_admin_set_supervisor($id,$supervisor_id){
        if($supervisor_id)return !is_wp_error($this->approval_set_relationship($id,'supervisor',$supervisor_id,0));
        global $wpdb;
        $rt=$this->approval_tables()['relationships'];
        return $wpdb->query($wpdb->prepare("UPDATE {$rt} SET active=0,updated_at=%s WHERE employee_id=%d AND relationship_type='supervisor' AND active=1",current_time('mysql'),$id))!==false;
    }

    public function employee_save(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_save');
        global $wpdb;
        [$in,$error]=$this->employee_admin_input(0);
        if($error)$this->employee_admin_redirect(['employee_error'=>$error]);
        $ok=$wpdb->insert($this->employees,['name'=>$in['name'],'domain_name'=>$in['domain_name'],'email'=>$in['email'],'wp_user_id'=>$in['wp_user_id'],'default_shift_id'=>$in['default_shift_id'],'attendance_enabled'=>$in['attendance_enabled'],'department_id'=>$in['department_id']]);
        if($ok===false)$this->employee_admin_redirect(['employee_error'=>'save']);
        $id=(int)$wpdb->insert_id;
        $this->invalidate_employee_runtime_cache($id,$in['wp_user_id']);
        if(!$this->employee_admin_set_supervisor($id,$in['supervisor_id']))$this->employee_admin_redirect(['employee_error'=>'save']);
        $this->audit('employee_create','employee',$id,$in['name'].' / '.$in['domain_name']);
        $this->employee_admin_redirect(['employee_notice'=>'added']);
    }

    public function employee_update(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_update');
        global $wpdb;
        $id=absint($_POST['id']??0);
        $old=$id?$wpdb->get_row($wpdb->prepare("SELECT active,department_id FROM {$this->employees} WHERE id=%d LIMIT 1",$id)):null;
        if(!$old)$this->employee_admin_redirect(['employee_error'=>'not_found']);
        [$in,$error]=$this->employee_admin_input($id,(int)$old->department_id);
        if($error)$this->employee_admin_redirect(['employee_error'=>$error]);
        $updated=$wpdb->update($this->employees,['name'=>$in['name'],'domain_name'=>$in['domain_name'],'email'=>$in['email'],'wp_user_id'=>$in['wp_user_id'],'default_shift_id'=>$in['default_shift_id'],'attendance_enabled'=>$in['attendance_enabled'],'department_id'=>$in['department_id'],'active'=>$in['active'],'updated_at'=>current_time('mysql')],['id'=>$id]);
        if($updated===false)$this->employee_admin_redirect(['employee_error'=>'save']);
        $this->invalidate_employee_runtime_cache($id,$in['wp_user_id']);
        if(!$this->employee_admin_set_supervisor($id,$in['supervisor_id']))$this->employee_admin_redirect(['employee_error'=>'save']);
        if(is_wp_error($this->sync_employee_teams($id,$in['team_ids'])))$this->employee_admin_redirect(['employee_error'=>'teams']);
        $this->audit('employee_update','employee',$id,$in['name'].' / '.$in['domain_name'].' / '.$in['email'].' / teams='.count($in['team_ids']));
        if((int)$old->active!==$in['active'])$this->audit('employee_status_change','employee',$id,'Status: '.((int)$old->active?'Active':'Inactive').' -> '.($in['active']?'Active':'Inactive'));
        $this->employee_admin_redirect(['employee_notice'=>'updated']);
    }

    public function employee_archive(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_archive');
        global $wpdb;
        $id=absint($_POST['id']??0);
        $old=$id?$wpdb->get_var($wpdb->prepare("SELECT active FROM {$this->employees} WHERE id=%d LIMIT 1",$id)):null;
        if($old===null)$this->employee_admin_redirect(['employee_error'=>'not_found']);
        if($wpdb->update($this->employees,['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id])===false)$this->employee_admin_redirect(['employee_error'=>'save']);
        $this->invalidate_employee_runtime_cache($id);
        if((int)$old!==0)$this->audit('employee_status_change','employee',$id,'Status: Active -> Inactive');
        $this->employee_admin_redirect(['employee_notice'=>'archived']);
    }

    public function admin_employees(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        global $wpdb;
        $this->ensure_departments_schema();$this->ensure_teams_schema();
        $rows=(array)$wpdb->get_results("SELECT * FROM {$this->employees} ORDER BY active DESC,name ASC");
        $teams=(array)$wpdb->get_results("SELECT id,name,manager_employee_id,department_id FROM {$wpdb->prefix}ews_teams WHERE active=1 ORDER BY name ASC");
        $users=[];
        foreach(get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_login']]) as $u)$users[(int)$u->ID]=$u->display_name.' ('.$u->user_login.')';
        $shifts=[];$all_shifts=[];
        foreach($this->shifts() as $shift){
            $label=$shift['name'].' — '.$this->format_time_label($shift['start']).' – '.$this->format_time_label($shift['end']);
            $all_shifts[(int)$shift['id']]=$label.(empty($shift['active'])?' (inactive)':'');
            if(!empty($shift['active']))$shifts[(int)$shift['id']]=$label;
        }
        $employees=[];
        foreach($rows as $r){
            $supervisor=$this->approval_related_employee((int)$r->id,'supervisor');
            $employees[]=['row'=>$r,'supervisor_id'=>$supervisor?(int)$supervisor->id:0,'team_ids'=>$this->team_ids_for_employee((int)$r->id)];
        }
        $error=sanitize_key($_GET['employee_error']??'');
        echo $this->render_template('admin/employees',[
            'employees'=>$employees,'teams'=>$teams,'users'=>$users,'shifts'=>$shifts,'all_shifts'=>$all_shifts,
            'departments'=>(array)$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_departments WHERE active=1 ORDER BY name ASC"),
            'notice'=>EmployeeRules::noticeMessage(sanitize_key($_GET['employee_notice']??'')),
            'error'=>$error!==''?EmployeeRules::errorMessage($error):null,
            'post_url'=>admin_url('admin-post.php'),
            'profile_url'=>admin_url('admin.php?page=ews31-employee-profile'),
        ]);
    }
}
