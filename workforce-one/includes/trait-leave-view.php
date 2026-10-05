<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Support\Format;

/**
 * The employee app's Leave page (?ews_view=vacation, with Early Leave) and Overtime page: the
 * employee's forms and history, and the manager's pending lists. Requests, approvals and their
 * rules live in trait-leave.php and trait-overtime.php; results are shown by the layout's pop-up
 * (ux_notice_from_query()). Views: templates/app/leave.php, templates/app/overtime.php; scripts:
 * assets/js/leave.js, assets/js/overtime.js. Behaviour: tests/e2e_leave_view.py.
 */
trait EWS_Leave_View_Trait {

    /** "1 h 30 min" / "2 h" (the same wording as the Overtime form's live counter). */
    private function duration_label($minutes){
        [$h,$m]=Format::split((int)$minutes);
        /* translators: 1: hours, 2: minutes */
        if($m)return sprintf(__('%1$d h %2$d min','workforce-one'),$h,$m);
        /* translators: %d: hours */
        return sprintf(__('%d h','workforce-one'),$h);
    }

    /** Leave rows a manager decides, with the employee and type names. */
    private function leave_view_manager_rows($entity,$where){
        global $wpdb;
        $rt=$wpdb->prefix.'ews_leave_requests';$lt=$wpdb->prefix.'ews_leave_types';
        $select="SELECT r.*,e.name employee_name,lt.name type_name";
        $from="FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$lt} lt ON lt.id=r.leave_type_id";
        $workflow=$this->approval_workflow('vacation');
        if($workflow && (int)$workflow->active===1){
            // Only the requests whose current approval step is assigned to this user.
            $at=$this->approval_tables();
            return ['mine'=>true,'rows'=>(array)$wpdb->get_results($wpdb->prepare("{$select},ai.step_order {$from} JOIN {$at['requests']} ar ON ar.entity_type=%s AND ar.entity_id=r.id JOIN {$at['instances']} ai ON ai.approval_request_id=ar.id AND ai.status='PENDING' AND ai.approver_wp_user_id=%d WHERE {$where} ORDER BY r.requested_at ASC",$entity,get_current_user_id()))];
        }
        if(!$this->can('ews_manage_settings'))return null;
        return ['mine'=>false,'rows'=>(array)$wpdb->get_results("{$select} {$from} WHERE {$where} ORDER BY r.requested_at ASC")];
    }

    private function vacation_content(){
        if(!is_user_logged_in())return $this->login_page();
        $this->ensure_leave_schema();
        global $wpdb;
        $emp=$this->current_employee();$today=current_time('Y-m-d');
        $types=[];
        foreach((array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY id ASC") as $lt){
            $available=null;
            if($emp){$b=$this->ensure_leave_balance($emp->id,$lt->id);$available=max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending);}
            $types[]=['id'=>(int)$lt->id,'name'=>(string)$lt->name,'deducts'=>(bool)$lt->deduct_balance,'available'=>$available===null?'':Format::number($available)];
        }

        $mine=[];$has_more=false;$show_all=sanitize_key($_GET['leave_history']??'')==='all';
        if($emp){
            $sql="SELECT r.*,lt.name type_name FROM {$wpdb->prefix}ews_leave_requests r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC";
            $rows=(array)$wpdb->get_results($wpdb->prepare($sql.($show_all?'':' LIMIT 7'),$emp->id));
            // One extra row tells whether "View all" has anything more to show.
            if(!$show_all && count($rows)>6){$has_more=true;$rows=array_slice($rows,0,6);}
            foreach($rows as $r){
                $r->can_cancel=$this->leave_cancellation_allowed($r);
                $r->started=$r->status==='Approved' && !empty($r->start_date) && $today>=(string)$r->start_date;
                $r->status_text=$this->status_label($r->status);
                $mine[]=$r;
            }
        }

        $early=null;
        if($emp){
            $monthly=(int)$this->option('ews_early_leave_monthly_minutes');$max=(int)$this->option('ews_early_leave_max_minutes');
            $remaining=max(0,$monthly-$this->early_leave_month_usage($emp->id,substr($today,0,7)));
            $early=['max'=>$max,'remaining'=>$remaining,
                /* translators: 1: max hours per request, 2: monthly allowance in hours, 3: remaining hours */
                'summary'=>sprintf(__('Available only on Office workdays · Maximum per request: %1$s hours · Monthly allowance: %2$s hours · Remaining this month: %3$s hours.','workforce-one'),Format::hours($max),Format::hours($monthly),Format::hours($remaining))];
        }
        $pending_early=null;
        if($this->can('ews_manage_time')){
            $pending_early=(array)$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$wpdb->prefix}ews_early_leave_requests r JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
            foreach($pending_early as $r)$r->duration=$this->duration_label($r->leave_minutes);
        }

        wp_enqueue_script('workforce-one-leave');
        wp_enqueue_style('workforce-one-leave-page');
        return $this->render_template('app/leave',[
            'emp'=>$emp,'today'=>$today,'types'=>$types,'mine'=>$mine,'has_more'=>$has_more,'show_all'=>$show_all,
            'all_url'=>add_query_arg('leave_history','all',remove_query_arg('leave_history',$this->app_view_url('vacation'))),
            'recent_url'=>remove_query_arg('leave_history',$this->app_view_url('vacation')),
            'request_url'=>$this->app_view_url('vacation'),
            'approvals'=>$this->leave_view_manager_rows('leave',"r.status='Pending'"),
            'cancellations'=>$this->leave_view_manager_rows('leave_cancellation',"r.status='Approved' AND r.cancellation_status='Pending'"),
            'early'=>$early,'pending_early'=>$pending_early,
            'working_days'=>array_values($this->working_days()),
            'post_url'=>admin_url('admin-post.php'),'empty'=>$this->leave_view_empty_state(),
        ]);
    }

    /** ews_empty_state() for the templates (which cannot call $this). */
    private function leave_view_empty_state(){
        return function($title,$text='',$url='',$label=''){return $this->ews_empty_state($title,$text,$url,$label);};
    }

    private function overtime_content(){
        if(!$this->overtime_enabled())return '<div class="ews-page"><div class="ews-login-error">'.esc_html__('Overtime Requests are currently disabled.','workforce-one').'</div></div>';
        if(!is_user_logged_in())return $this->login_page();
        $this->ensure_overtime_schema();
        global $wpdb;
        $table=$wpdb->prefix.'ews_overtime_requests';
        $emp=$this->current_employee();
        $mine=[];
        if($emp){
            $mine=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT 20",(int)$emp->id));
            foreach($mine as $r){$r->duration=$this->duration_label($r->requested_minutes);$r->status_text=$this->status_label($r->status);}
        }
        $approvals=null;
        $workflow=$this->approval_workflow('overtime');
        if($workflow && (int)$workflow->active===1 && in_array(strtoupper((string)$workflow->approval_mode),['LEVEL_1','LEVEL_2'],true)){
            $at=$this->approval_tables();
            $approvals=['mine'=>true,'rows'=>(array)$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,ars.step_order FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id INNER JOIN {$at['requests']} ar ON ar.entity_type='overtime_request' AND ar.entity_id=r.id INNER JOIN {$at['instances']} ars ON ars.approval_request_id=ar.id AND ars.status='PENDING' AND ars.approver_wp_user_id=%d WHERE r.status='Pending' ORDER BY r.requested_at ASC",get_current_user_id()))];
        }elseif($this->can('ews_manage_settings')){
            $approvals=['mine'=>false,'rows'=>(array)$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC")];
        }
        if($approvals)foreach($approvals['rows'] as $r)$r->duration=$this->duration_label($r->requested_minutes);
        wp_enqueue_script('workforce-one-overtime');
        return $this->render_template('app/overtime',[
            'emp'=>$emp,'today'=>current_time('Y-m-d'),'mine'=>$mine,'approvals'=>$approvals,
            'request_url'=>$this->app_view_url('overtime'),'post_url'=>admin_url('admin-post.php'),'empty'=>$this->leave_view_empty_state(),
        ]);
    }
}
