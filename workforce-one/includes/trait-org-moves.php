<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Organization\MoveRules;

/**
 * Moving people in the organization chart (3.31.98, owner-approved mockup docs/mockups/org-chart/,
 * docs/tasks/org-chart.md, stage 3). Part of People.
 *
 * A move puts an employee under another manager (or none) from a date, with a reason kept in the Audit
 * Log. The people under them move with them (their manager does not change). The steps of their open
 * requests waiting for the old manager now wait for the new one (owner, 2026-10-14), who is told.
 * A date after today is kept as a scheduled move and done on that day (owner, 2026-10-14): by the daily
 * job, and at the latest within an hour of the first visit that day. Every move is kept in
 * ews_org_moves, the history shown on the chart. While the chart is on, a supervisor changed on the
 * Employees page is a move too (from today, no reason).
 * Rules: src/Organization/MoveRules.php. Behaviour: tests/e2e_org_moves.py.
 */
trait EWS_Org_Moves_Trait {

    private function org_moves_table(){
        global $wpdb;
        return $wpdb->prefix.'ews_org_moves';
    }

    private function ensure_org_moves_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=$this->org_moves_table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            from_manager_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            to_manager_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            effective_date DATE NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'done',
            source VARCHAR(20) NOT NULL DEFAULT 'chart',
            requests_moved INT UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            applied_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status_date (status,effective_date),
            KEY employee (employee_id,id)
        ) {$c};");
    }

    /** The manager an employee has now (0 = none), archived or not. */
    private function people_manager_of($employee_id){
        global $wpdb;
        $rt=$wpdb->prefix.'ews_employee_relationships';
        return (int)$wpdb->get_var($wpdb->prepare("SELECT related_employee_id FROM {$rt} WHERE employee_id=%d AND relationship_type='supervisor' AND active=1 AND related_employee_id IS NOT NULL ORDER BY id DESC LIMIT 1",(int)$employee_id));
    }

    /** @return array<string,string> error code => message */
    private function org_error_messages(){
        return [
            'employee'=>__('Pick an active employee to move.','workforce-one'),
            'manager'=>__('The new manager is not an active employee. Pick them from the list.','workforce-one'),
            'self'=>__('Nobody can be their own manager.','workforce-one'),
            'same'=>__('They already report to this manager.','workforce-one'),
            'loop'=>__('Nobody can be moved under someone who reports to them.','workforce-one'),
            'date'=>__('Pick a date within a year from today.','workforce-one'),
            'reason'=>__('Write the reason for the move; it is kept in the Audit Log.','workforce-one'),
            'scheduled'=>__('A move is already scheduled for this employee. Cancel it first.','workforce-one'),
            'apply'=>__('The move could not be saved. Please try again.','workforce-one'),
            'file'=>__('The file could not be read. Upload the Excel file exported from this page (.xlsx) or a .csv file.','workforce-one'),
            'columns'=>__('The file needs an "Employee ID" or "Domain name" column, and a "Manager ID", "Manager domain name" or "Job title" column.','workforce-one'),
            'too_many'=>__('The file has more than 5,000 rows.','workforce-one'),
            'empty'=>__('The file is empty.','workforce-one'),
            'expired'=>__('The preview has expired. Upload the file again.','workforce-one'),
            'zip'=>__('Excel files need the PHP ZipArchive extension on the server. Ask your host to turn it on, or upload a .csv file.','workforce-one'),
            'off'=>__('The organization chart is switched off.','workforce-one'),
        ];
    }

    /**
     * Checks a move. @param bool $reason_required a move from the chart needs a reason
     * @return string|null error code
     */
    private function org_move_check($employee_id,$to,$date,$reason,$reason_required,$ignore_scheduled_id=0,$check_loop=true){
        global $wpdb;
        $employee_id=(int)$employee_id;$to=(int)$to;
        $current=$this->people_manager_of($employee_id);
        $facts=['employee_ok'=>$employee_id>0 && (bool)$this->people_active_id($employee_id),'employee_id'=>$employee_id,'manager_id'=>$to,
            'current_manager'=>$current && $this->people_active_id($current)?$current:0,'date'=>(string)$date,'today'=>current_time('Y-m-d'),
            'reason'=>(string)$reason,'reason_required'=>(bool)$reason_required];
        if($to)$facts['manager_ok']=(bool)$this->people_active_id($to);
        $facts['scheduled_exists']=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->org_moves_table()} WHERE employee_id=%d AND status='scheduled' AND id<>%d LIMIT 1",$employee_id,(int)$ignore_scheduled_id));
        if($to && $check_loop)$facts['would_loop']=$this->org_tree()->wouldLoop($employee_id,$to);
        return MoveRules::check($facts);
    }

    /**
     * Does a move now: the new manager, the waiting requests, the history row and the Audit Log.
     * @return array{ok:bool,requests:int,id:int}
     */
    private function org_move_apply($employee_id,$to,$date,$reason,$source,$scheduled_id=0){
        global $wpdb;
        $employee_id=(int)$employee_id;$to=(int)$to;
        $from=$this->people_manager_of($employee_id);
        if(!$this->employee_admin_set_supervisor($employee_id,$to))return ['ok'=>false,'requests'=>0,'id'=>0];
        $moved=$this->approval_reassign_supervisor($employee_id,$from,$to);
        $row=['employee_id'=>$employee_id,'from_manager_id'=>$from,'to_manager_id'=>$to,'effective_date'=>$date,'reason'=>(string)$reason,'status'=>'done',
            'source'=>$source,'requests_moved'=>$moved,'applied_at'=>current_time('mysql')];
        if($scheduled_id){
            $wpdb->update($this->org_moves_table(),$row,['id'=>(int)$scheduled_id]);
            $id=(int)$scheduled_id;
        }else{
            $wpdb->insert($this->org_moves_table(),$row+['created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
            $id=(int)$wpdb->insert_id;
        }
        $this->org_reset_runtime_caches($employee_id);
        $this->audit('org_move','employee',$employee_id,$this->org_move_summary($employee_id,$from,$to,$date,$reason).($moved?' · '.$moved.' waiting approval step(s) moved':'').' · '.$source);
        return ['ok'=>true,'requests'=>$moved,'id'=>$id];
    }

    /** A move dated after today: kept and done on its day. @return int */
    private function org_move_schedule($employee_id,$to,$date,$reason,$source){
        global $wpdb;
        $from=$this->people_manager_of((int)$employee_id);
        $wpdb->insert($this->org_moves_table(),['employee_id'=>(int)$employee_id,'from_manager_id'=>$from,'to_manager_id'=>(int)$to,'effective_date'=>$date,
            'reason'=>(string)$reason,'status'=>'scheduled','source'=>$source,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
        $id=(int)$wpdb->insert_id;
        $this->audit('org_move_scheduled','employee',(int)$employee_id,$this->org_move_summary((int)$employee_id,$from,(int)$to,$date,$reason).' · '.$source);
        return $id;
    }

    private function org_move_summary($employee_id,$from,$to,$date,$reason){
        $n=function($id){return $id?(string)$this->people_name((int)$id):'no manager';};
        return $n($employee_id).': '.$n($from).' → '.$n($to).' from '.$date.($reason!==''?' · "'.$reason.'"':'');
    }

    private function org_reset_runtime_caches($employee_id){
        $uid=(int)$this->people_user_id((int)$employee_id,false);
        if(method_exists($this,'invalidate_employee_runtime_cache'))$this->invalidate_employee_runtime_cache((int)$employee_id,$uid);
    }

    /**
     * The moves whose day has come (the daily job, and the first visit each hour). A move that can no
     * longer be done (the employee or the manager archived, or it would now make a loop) is marked
     * failed with the reason, and shown under "Needs attention".
     */
    public function org_moves_apply_due(){
        global $wpdb;
        $t=$this->org_moves_table();
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t))!==$t)return 0;
        $today=current_time('Y-m-d');$done=0;
        $due=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status='scheduled' AND effective_date<=%s ORDER BY effective_date ASC,id ASC LIMIT 200",$today)) as $m){
            // Claimed first, so two visits at the same time do not both do it.
            if($wpdb->update($t,['status'=>'applying'],['id'=>(int)$m->id,'status'=>'scheduled'])===1)$due[]=$m;
        }
        // In passes: a move that would make a loop now may be fine once another move of the same day is done.
        while($due){
            $later=[];
            foreach($due as $m){
                $code=$this->org_move_check((int)$m->employee_id,(int)$m->to_manager_id,(string)$m->effective_date,(string)$m->reason,false,(int)$m->id);
                if($code==='loop'){$later[]=$m;continue;}
                if($code==='same'){$wpdb->update($t,['status'=>'done','note'=>'already in place','applied_at'=>current_time('mysql')],['id'=>(int)$m->id]);continue;}
                if($code!==null && $code!=='date'){$this->org_move_failed($m,$code);continue;}
                $r=$this->org_move_apply((int)$m->employee_id,(int)$m->to_manager_id,(string)$m->effective_date,(string)$m->reason,(string)$m->source,(int)$m->id);
                if(!$r['ok'])$wpdb->update($t,['status'=>'scheduled'],['id'=>(int)$m->id]);
                else $done++;
            }
            if(count($later)===count($due)){foreach($later as $m)$this->org_move_failed($m,'loop');break;}
            $due=$later;
        }
        return $done;
    }

    private function org_move_failed($m,$code){
        global $wpdb;
        $wpdb->update($this->org_moves_table(),['status'=>'failed','note'=>$code,'applied_at'=>current_time('mysql')],['id'=>(int)$m->id]);
        $this->audit('org_move_failed','employee',(int)$m->employee_id,'Scheduled move #'.(int)$m->id.' not done: '.$code);
    }

    /** At most once an hour, while the chart is on: the scheduled moves whose day has come. */
    public function org_moves_maybe_due(){
        if(!$this->org_enabled() || get_transient('ews_org_moves_checked'))return;
        set_transient('ews_org_moves_checked',1,HOUR_IN_SECONDS);
        $this->org_moves_apply_due();
    }

    /** wp-admin → Org chart → Move (admin-post ews_org_move). */
    public function org_move_submit(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_org_move');
        $employee_id=absint($_POST['employee_id']??0);
        $back=['page'=>'ews31-org-chart','focus'=>$employee_id];
        if(!$this->org_enabled())$this->org_redirect($back+['org_error'=>'off']);
        $to=empty($_POST['no_manager'])?\WorkforceOne\Support\Picker::parse((string)wp_unslash($_POST['manager_ref']??'')):0;
        if(empty($_POST['no_manager']) && !$to)$this->org_redirect($back+['org_error'=>'manager']);
        $date=sanitize_text_field(wp_unslash($_POST['effective_date']??''));
        $reason=MoveRules::reason(wp_unslash($_POST['reason']??''));
        $code=$this->org_move_check($employee_id,$to,$date,$reason,true);
        if($code)$this->org_redirect($back+['org_error'=>$code]);
        if(MoveRules::isScheduled($date,current_time('Y-m-d'))){
            $this->org_move_schedule($employee_id,$to,$date,$reason,'chart');
            $this->org_redirect($back+['org_notice'=>'scheduled']);
        }
        $r=$this->org_move_apply($employee_id,$to,$date,$reason,'chart');
        if(!$r['ok'])$this->org_redirect($back+['org_error'=>'apply']);
        $this->org_redirect($back+['org_notice'=>'moved','requests'=>$r['requests']]);
    }

    /** Cancels a scheduled move (admin-post ews_org_move_cancel). */
    public function org_move_cancel(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        $id=absint($_POST['move_id']??0);
        check_admin_referer('ews_org_move_cancel_'.$id);
        global $wpdb;
        $m=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->org_moves_table()} WHERE id=%d",$id));
        if($m && in_array($m->status,['scheduled','failed'],true)){
            $wpdb->update($this->org_moves_table(),['status'=>'cancelled','applied_at'=>current_time('mysql')],['id'=>$id,'status'=>$m->status]);
            $this->audit('org_move_cancelled','employee',(int)$m->employee_id,($m->status==='failed'?'Failed':'Scheduled').' move #'.$id.' cancelled: '.$this->org_move_summary((int)$m->employee_id,(int)$m->from_manager_id,(int)$m->to_manager_id,(string)$m->effective_date,(string)$m->reason));
        }
        $this->org_redirect(['page'=>'ews31-org-chart','focus'=>$m?(int)$m->employee_id:0,'org_notice'=>'cancelled']);
    }

    private function org_redirect(array $args){
        wp_safe_redirect(add_query_arg(array_filter($args,static function($v){return $v!==0 && $v!=='' && $v!==null;}),admin_url('admin.php')));
        exit;
    }

    /**
     * While the chart is on, a supervisor changed on the Employees page is kept as a move (from today,
     * no reason) and their waiting requests go to the new supervisor too.
     */
    private function org_record_employees_page_change($employee_id,$from,$to){
        if(!$this->org_enabled() || (int)$from===(int)$to)return;
        global $wpdb;
        $moved=$this->approval_reassign_supervisor((int)$employee_id,(int)$from,(int)$to);
        $wpdb->insert($this->org_moves_table(),['employee_id'=>(int)$employee_id,'from_manager_id'=>(int)$from,'to_manager_id'=>(int)$to,'effective_date'=>current_time('Y-m-d'),
            'reason'=>'','status'=>'done','source'=>'employees','requests_moved'=>$moved,'created_by'=>get_current_user_id(),'created_at'=>current_time('mysql'),'applied_at'=>current_time('mysql')]);
        $this->audit('org_move','employee',(int)$employee_id,$this->org_move_summary((int)$employee_id,(int)$from,(int)$to,current_time('Y-m-d'),'').($moved?' · '.$moved.' waiting approval step(s) moved':'').' · employees');
    }

    /**
     * The moves for the chart: the last ones per person (newest first), the scheduled ones and the
     * failed ones. @return array{history:array<int,list<array<string,mixed>>>,scheduled:list<array<string,mixed>>,failed:list<array<string,mixed>>}
     */
    private function org_moves_for_chart(){
        global $wpdb;
        $t=$this->org_moves_table();
        $out=['history'=>[],'scheduled'=>[],'failed'=>[]];
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t))!==$t)return $out;
        $row=function($m){return ['id'=>(int)$m->id,'e'=>(int)$m->employee_id,'f'=>(int)$m->from_manager_id,'t'=>(int)$m->to_manager_id,'d'=>(string)$m->effective_date,
            'r'=>(string)$m->reason,'s'=>(string)$m->source,'q'=>(int)$m->requests_moved,'n'=>(string)$m->note,'nonce'=>in_array($m->status,['scheduled','failed'],true)?wp_create_nonce('ews_org_move_cancel_'.(int)$m->id):''];};
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE status='done' ORDER BY id DESC LIMIT 2000") as $m){
            $e=(int)$m->employee_id;
            if(count($out['history'][$e]??[])<5)$out['history'][$e][]=$row($m);
        }
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('scheduled','applying') ORDER BY effective_date ASC,id ASC LIMIT 500") as $m)$out['scheduled'][]=$row($m);
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE status='failed' ORDER BY id DESC LIMIT 100") as $m)$out['failed'][]=$row($m);
        return $out;
    }
}
