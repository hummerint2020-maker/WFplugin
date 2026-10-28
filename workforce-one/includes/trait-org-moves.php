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
        return $this->services()->org()->managerOf((int)$employee_id);
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
        return $this->services()->orgMoves()->check((int)$employee_id,(int)$to,(string)$date,(string)$reason,(bool)$reason_required,(int)$ignore_scheduled_id,(bool)$check_loop);
    }

    /**
     * Does a move now: the new manager, the waiting requests, the history row and the Audit Log. All or nothing (3.31.119).
     * $claim: the claim time of a scheduled move ($scheduled_id) being done by the daily run.
     * @return array{ok:bool,requests:int,id:int}
     */
    private function org_move_apply($employee_id,$to,$date,$reason,$source,$scheduled_id=0,$claim=null){
        return $this->services()->orgMoves()->apply((int)$employee_id,(int)$to,(string)$date,(string)$reason,(string)$source,(int)$scheduled_id,$claim===null?null:(string)$claim);
    }

    /**
     * The supervisor, the waiting approval steps and the history row, in one transaction (3.31.119); the new manager is told after it.
     * @param array<string,mixed> $row @param array{id:int,at:string}|null $claim @return array{ok:bool,requests:int,id:int}
     */
    private function org_supervisor_change($employee_id,$from,$to,array $row,$claim=null){
        return $this->services()->orgMoves()->supervisorChange((int)$employee_id,(int)$from,(int)$to,$row,$claim);
    }

    /** The supervisor alone (the chart off, or unchanged), both of its writes together (3.31.119). @return bool */
    private function org_set_supervisor_only($employee_id,$supervisor_id){
        return $this->services()->orgMoves()->setSupervisorOnly((int)$employee_id,(int)$supervisor_id);
    }

    /** A move dated after today: kept and done on its day. @return int */
    private function org_move_schedule($employee_id,$to,$date,$reason,$source){
        return $this->services()->orgMoves()->schedule((int)$employee_id,(int)$to,(string)$date,(string)$reason,(string)$source);
    }

    private function org_move_summary($employee_id,$from,$to,$date,$reason){
        return $this->services()->orgMoves()->summary((int)$employee_id,(int)$from,(int)$to,(string)$date,(string)$reason);
    }

    /**
     * The moves whose day has come (the daily job, and the first visit each hour). A move that can no
     * longer be done (the employee or the manager archived, or it would now make a loop) is marked
     * failed with the reason, and shown under "Needs attention".
     */
    public function org_moves_apply_due(){
        return $this->services()->orgMoves()->applyDue();
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
        $employee=$this->services()->orgMoves()->cancel($id);
        $this->org_redirect(['page'=>'ews31-org-chart','focus'=>$employee,'org_notice'=>'cancelled']);
    }

    private function org_redirect(array $args){
        wp_safe_redirect(add_query_arg(array_filter($args,static function($v){return $v!==0 && $v!=='' && $v!==null;}),admin_url('admin.php')));
        exit;
    }

    /**
     * The moves for the chart: the last ones per person (newest first), the scheduled ones and the
     * failed ones. @return array{history:array<int,list<array<string,mixed>>>,scheduled:list<array<string,mixed>>,failed:list<array<string,mixed>>}
     */
    private function org_moves_for_chart(){
        return $this->services()->orgMoves()->forChart(static function($id){return wp_create_nonce('ews_org_move_cancel_'.(int)$id);});
    }
}
