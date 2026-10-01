<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Leave\AdminRecordRules;
use WorkforceOne\Leave\Balance;

/**
 * wp-admin "Leaves" page: leave types, recording an approved leave for an employee, and annual
 * balances. Recording reuses the Leave module operations (includes/trait-leave.php), so the days
 * are reserved on the leave year's balance and then approved exactly like an employee request.
 * Rules: src/Leave/AdminRecordRules.php. Behaviour: tests/e2e_leave_admin.py.
 */
trait EWS_Leave_Admin_Trait {

    private function leave_admin_redirect($args=[]){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-leaves')));
        exit;
    }

    public function admin_leaves(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $this->ensure_leave_schema();
        global $wpdb;
        $year=$this->leave_year();
        $years=AdminRecordRules::balanceYears($year);
        $shown_year=absint($_GET['balance_year']??$year);
        if(!in_array($shown_year,$years,true))$shown_year=$year;
        $balances=$wpdb->get_results($wpdb->prepare(
            "SELECT b.*,e.name employee_name,t.name type_name FROM {$wpdb->prefix}ews_leave_balances b
             JOIN {$this->employees} e ON e.id=b.employee_id AND e.active=1
             JOIN {$wpdb->prefix}ews_leave_types t ON t.id=b.leave_type_id
             WHERE b.leave_year=%d ORDER BY e.name ASC,t.name ASC",$shown_year));
        $balance_rows=[];
        foreach((array)$balances as $b){
            $balance_rows[]=['employee'=>$b->employee_name,'type'=>$b->type_name,'entitlement'=>(float)$b->entitlement,'used'=>(float)$b->used,'pending'=>(float)$b->pending,
                'remaining'=>Balance::remaining((float)$b->entitlement,(float)$b->used,(float)$b->pending)];
        }
        echo $this->render_template('admin/leaves',[
            'types'=>(array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types ORDER BY id ASC"),
            'employees'=>(array)$wpdb->get_results("SELECT id,name FROM {$this->employees} WHERE active=1 ORDER BY name ASC"),
            'year'=>$year,'years'=>$years,'shown_year'=>$shown_year,'balances'=>$balance_rows,
            'saved'=>isset($_GET['leave_admin_saved']),'recorded'=>isset($_GET['leave_recorded']),
            'record_error'=>isset($_GET['leave_record_error'])?sanitize_text_field(wp_unslash($_GET['leave_record_error'])):'',
            'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-leaves'),
        ]);
    }

    /**
     * Records an approved leave directly. The date can be in the past. The days are reserved on
     * the balance of the leave's year and then approved, like an employee request, so other
     * pending reservations, the charged year and later cancellation all stay consistent.
     */
    public function admin_admin_leave_record(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_admin_leave_record');
        global $wpdb;
        $this->ensure_leave_schema();
        $employee_id=absint($_POST['employee_id']??0);
        $leave_type_id=absint($_POST['leave_type_id']??0);
        $start=sanitize_text_field($_POST['start_date']??'');
        $end=sanitize_text_field($_POST['end_date']??'');
        $reason=trim(sanitize_textarea_field($_POST['reason']??''));
        $fail=function($code,$remaining=0){$this->leave_admin_redirect(['leave_record_error'=>rawurlencode(AdminRecordRules::message($code,$remaining))]);};

        $facts=['start_valid'=>$employee_id&&$this->valid_date($start),'end_valid'=>$this->valid_date($end),'start'=>$start,'end'=>$end];
        if($error=AdminRecordRules::check($facts))$fail($error);
        $emp=$this->leave_active_employee($employee_id);
        $type=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE id=%d AND active=1 LIMIT 1",$leave_type_id));
        $facts+=['employee_found'=>(bool)$emp,'type_found'=>(bool)$type];
        if($error=AdminRecordRules::check($facts))$fail($error);
        $days=$this->vacation_working_days_count($start,$end);
        $bal=$this->ensure_leave_balance($employee_id,$leave_type_id,\WorkforceOne\Leave\RequestRules::year($start));
        if(!$bal)$this->leave_admin_redirect(['leave_record_error'=>rawurlencode('Could not initialize the employee leave balance.')]);
        $remaining=Balance::remaining((float)$bal->entitlement,(float)$bal->used,(float)$bal->pending);
        $facts+=['working_days'=>$days,'overlaps'=>$this->leave_overlap($employee_id,$start,$end),'deducts'=>(bool)(int)$type->deduct_balance,'remaining'=>$remaining];
        if($error=AdminRecordRules::check($facts))$fail($error,max(0,$remaining));

        $now=current_time('mysql');
        $error='';$id=0;
        if($wpdb->query('START TRANSACTION')===false)$error='Could not start the database transaction.';
        if(!$error){
            $id=$this->leave_insert_request($emp,$type,$start,$end,$days,$reason,$bal);
            if(!$id)$error='Could not create the leave record.';
        }
        if(!$error){
            // Direct Admin recording is an immediate approval through the normal final-approval path.
            $final=$this->leave_apply_final_approval($this->leave_request_row($id),$emp,$bal,get_current_user_id(),$now);
            if(is_wp_error($final))$error=$final->get_error_message();
        }
        if(!$error && $wpdb->query('COMMIT')===false)$error='Could not commit the leave record.';
        if($error){$wpdb->query('ROLLBACK');$this->leave_admin_redirect(['leave_record_error'=>rawurlencode($error)]);}
        $this->audit('admin_leave_record','leave_request',$id,$emp->name.' / '.$type->name.' / '.$start.' to '.$end.' / '.$days.' working day(s) / direct Admin approval');
        $this->leave_admin_redirect(['leave_recorded'=>1]);
    }

    public function admin_leave_type_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_type_save');
        global $wpdb;$this->ensure_leave_schema();
        $table=$wpdb->prefix.'ews_leave_types';
        $id=absint($_POST['id']??0);$name=trim(sanitize_text_field($_POST['name']??''));
        $ent=max(0,(float)($_POST['annual_entitlement']??0));$ded=!empty($_POST['deduct_balance'])?1:0;$active=!empty($_POST['active'])?1:0;
        if($name==='')wp_die('Leave Type name is required.');
        if($id){
            if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id=%d",$id)))wp_die('Leave Type not found.');
            $wpdb->update($table,['name'=>$name,'annual_entitlement'=>$ent,'deduct_balance'=>$ded,'active'=>$active],['id'=>$id],['%s','%f','%d','%d'],['%d']);
        }else{
            $wpdb->insert($table,['name'=>$name,'annual_entitlement'=>$ent,'deduct_balance'=>$ded,'active'=>1],['%s','%f','%d','%d']);
            $id=(int)$wpdb->insert_id;
        }
        $this->audit('leave_type_update','leave_type',$id,$name);
        $this->leave_admin_redirect(['leave_admin_saved'=>1]);
    }

    public function admin_leave_balance_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_leave_balance_save');
        global $wpdb;$this->ensure_leave_schema();
        $eid=absint($_POST['employee_id']??0);$tid=absint($_POST['leave_type_id']??0);$ent=max(0,(float)($_POST['entitlement']??0));
        $y=absint($_POST['leave_year']??$this->leave_year());
        if(!in_array($y,AdminRecordRules::balanceYears($this->leave_year()),true))wp_die('Invalid leave year.');
        if(!$this->leave_active_employee($eid))wp_die('Employee not found.');
        if(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_leave_types WHERE id=%d",$tid)))wp_die('Leave Type not found.');
        $table=$wpdb->prefix.'ews_leave_balances';
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d",$eid,$tid,$y));
        if($r)$wpdb->update($table,['entitlement'=>$ent,'updated_at'=>current_time('mysql')],['id'=>$r->id],['%f','%s'],['%d']);
        else $wpdb->insert($table,['employee_id'=>$eid,'leave_type_id'=>$tid,'leave_year'=>$y,'entitlement'=>$ent],['%d','%d','%d','%f']);
        $this->audit('leave_balance_update','leave_balance',$r?$r->id:$wpdb->insert_id,'Annual entitlement set to '.$ent.' for '.$y);
        $this->leave_admin_redirect(['leave_admin_saved'=>1,'balance_year'=>$y]);
    }
}
