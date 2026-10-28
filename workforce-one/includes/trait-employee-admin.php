<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\EmployeeRules;
use WorkforceOne\Support\Picker;

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
        // Back to the same page of the list and the same search.
        parse_str((string)wp_parse_url($referer,PHP_URL_QUERY),$q);
        foreach(['paged','s'] as $k)if(!empty($q[$k]))$args[$k]=$k==='paged'?absint($q[$k]):sanitize_text_field((string)$q[$k]);
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-employees')));
        exit;
    }

    /**
     * The last working day posted with an archive (3.31.90): payroll pays an archived employee up to it,
     * in the months not closed yet. Today when none (or an invalid date) is sent.
     */
    private function employee_last_working_day_input($fallback=''){
        $d=sanitize_text_field(wp_unslash($_POST['last_working_day']??''));
        if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$d) && strtotime($d)!==false && date('Y-m-d',strtotime($d))===$d)return $d;
        return $fallback!==''?$fallback:current_time('Y-m-d');
    }

    /** Reads and validates the posted employee. @return array{0: array<string,mixed>, 1: ?string} */
    private function employee_admin_input($id,$old_department=null){
        $in=[
            'name'=>sanitize_text_field(wp_unslash($_POST['name']??'')),
            'domain_name'=>sanitize_text_field(wp_unslash($_POST['domain_name']??'')),
            'email'=>sanitize_email(wp_unslash($_POST['email']??'')),
            // The page sends "Name · #id" from a pick list (3.31.71); other callers may still send the id.
            'wp_user_id'=>isset($_POST['wp_user_ref'])?Picker::parse((string)wp_unslash($_POST['wp_user_ref'])):absint($_POST['wp_user_id']??0),
            'default_shift_id'=>absint($_POST['default_shift_id']??0),
            'supervisor_id'=>isset($_POST['supervisor_employee_ref'])?Picker::parse((string)wp_unslash($_POST['supervisor_employee_ref'])):absint($_POST['supervisor_employee_id']??0),
            'department_id'=>absint($_POST['department_id']??0),
            'attendance_enabled'=>isset($_POST['attendance_enabled'])?1:0,
            'team_ids'=>isset($_POST['team_ids'])&&is_array($_POST['team_ids'])?array_values(array_unique(array_filter(array_map('absint',$_POST['team_ids'])))):[],
            'active'=>isset($_POST['active'])&&in_array((string)$_POST['active'],['0','1'],true)?(int)$_POST['active']:1,
            // Job title (3.31.97, organization chart); a form without the field keeps the saved one (null).
            'job_title'=>isset($_POST['job_title'])?\WorkforceOne\Settings\OrgChartSettings::title(wp_unslash($_POST['job_title'])):null,
        ];
        $this->ensure_departments_schema();$this->ensure_teams_schema();$this->ensure_approval_schema();
        return [$in,$this->services()->employees()->check($in,$in['email']!==''&&is_email($in['email']),$id,$old_department)];
    }

    private function employee_admin_set_supervisor($id,$supervisor_id){
        return $this->services()->reporting()->setSupervisor((int)$id,absint($supervisor_id));
    }

    public function employee_save(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_save');
        [$in,$error]=$this->employee_admin_input(0);
        if($error)$this->employee_admin_redirect(['employee_error'=>$error]);
        $error=$this->services()->employees()->create($in);
        if($error)$this->employee_admin_redirect(['employee_error'=>$error]);
        $this->employee_admin_redirect(['employee_notice'=>'added']);
    }

    public function employee_update(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_update');
        $id=absint($_POST['id']??0);
        $old=$id?$this->services()->employees()->former($id):null;
        if(!$old)$this->employee_admin_redirect(['employee_error'=>'not_found']);
        [$in,$error]=$this->employee_admin_input($id,(int)$old->department_id);
        if($error)$this->employee_admin_redirect(['employee_error'=>$error]);
        // Inactive: paid up to the last working day (kept from before when not sent); active again: none.
        $last_day=$in['active']?null:$this->employee_last_working_day_input((string)($old->last_working_day??''));
        $r=$this->services()->employees()->update($id,$old,$in,$last_day);
        if($r['error'])$this->employee_admin_redirect(['employee_error'=>$r['error']]);
        $this->employee_admin_redirect($r['advance_left']>0?['employee_notice'=>'updated','advance_left'=>$r['advance_left']]:['employee_notice'=>'updated']);
    }

    public function employee_archive(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        check_admin_referer('ews31_employee_archive');
        $id=absint($_POST['id']??0);
        $row=$id?$this->services()->employees()->status($id):null;
        if(!$row)$this->employee_admin_redirect(['employee_error'=>'not_found']);
        $last_day=$this->employee_last_working_day_input();
        $r=$this->services()->employees()->archive($id,$row,$last_day);
        if($r['error'])$this->employee_admin_redirect(['employee_error'=>$r['error']]);
        $this->employee_admin_redirect($r['advance_left']>0?['employee_notice'=>'archived','advance_left'=>$r['advance_left']]:['employee_notice'=>'archived']);
    }

    public function admin_employees(){
        if(!$this->can('ews_manage_employees'))wp_die('Access denied');
        $this->ensure_departments_schema();$this->ensure_teams_schema();
        $svc=$this->services()->employees();
        // One page of the list at a time, with a search (3.31.71: the whole list did not fit in memory at 3,000).
        $per=50;$search=sanitize_text_field(wp_unslash($_GET['s']??''));$paged=max(1,absint($_GET['paged']??1));
        $total=$svc->adminCount($search);
        $pages=max(1,(int)ceil($total/$per));$paged=min($paged,$pages);
        $rows=$svc->adminPage($search,$per,($paged-1)*$per);
        // Every employee once, for the supervisor pick list and the names shown in it.
        $people=[];foreach($svc->pickList() as $p)$people[(int)$p->id]=$p;
        $teams=$svc->pageTeams();
        $users=[];
        foreach(get_users(['orderby'=>'display_name','order'=>'ASC','fields'=>['ID','display_name','user_login']]) as $u)$users[(int)$u->ID]=$u->display_name.' ('.$u->user_login.')';
        $shifts=[];$all_shifts=[];
        foreach($this->shifts() as $shift){
            $label=$shift['name'].' — '.$this->format_time_label($shift['start']).' – '.$this->format_time_label($shift['end']);
            $all_shifts[(int)$shift['id']]=$label.(empty($shift['active'])?' (inactive)':'');
            if(!empty($shift['active']))$shifts[(int)$shift['id']]=$label;
        }
        $employees=[];
        $row_ids=array_map(function($r){return (int)$r->id;},(array)$rows);
        $sup_ids=$this->approval_supervisor_ids($row_ids);   // one query, not one per employee
        $team_ids=$this->team_ids_by_employee($row_ids);     // idem
        foreach($rows as $r){
            $employees[]=['row'=>$r,'supervisor_id'=>$sup_ids[(int)$r->id]??0,'team_ids'=>$team_ids[(int)$r->id]??[]];
        }
        $error=sanitize_key($_GET['employee_error']??'');
        echo $this->render_template('admin/employees',[
            'employees'=>$employees,'people'=>$people,'teams'=>$teams,'users'=>$users,'search'=>$search,'paged'=>$paged,'pages'=>$pages,'total'=>$total,'page_url'=>admin_url('admin.php?page=ews31-employees'),'shifts'=>$shifts,'all_shifts'=>$all_shifts,
            'departments'=>$svc->pageDepartments(),
            'notice'=>EmployeeRules::noticeMessage(sanitize_key($_GET['employee_notice']??'')),
            'error'=>$error!==''?EmployeeRules::errorMessage($error):null,
            // Made inactive with a salary advance still open (3.31.109, owner decision 3): the rest comes off the last pay.
            'advance_left'=>isset($_GET['advance_left'])&&(float)$_GET['advance_left']>0?sprintf(/* translators: %s: amount */__('This employee still owes %s of a salary advance. The rest is taken off their last pay.','workforce-one'),$this->advance_money((float)$_GET['advance_left'])):'',
            'post_url'=>admin_url('admin-post.php'),
            // Job titles (3.31.97) while the organization chart is on: the field, whether it is required, the titles in use.
            'org'=>$this->org_enabled(),'title_required'=>$this->org_title_required(),
            'titles'=>$this->org_enabled()?$svc->activeTitles():[],
            'profile_url'=>admin_url('admin.php?page=ews31-employee-profile'),'today'=>current_time('Y-m-d'),
        ]);
    }
}
