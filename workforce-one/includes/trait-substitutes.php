<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Approvals\Substitutes;

/**
 * A substitute while a manager is away (3.31.107, approvals stage 4; owner decisions 2026-10-17,
 * docs/tasks/approvals.md, mockup docs/mockups/substitutes/). Off by default (wp-admin → Features).
 *
 * A substitute is set by the manager with a leave request (it starts when the leave is approved and
 * stops if it is cancelled), by the manager from My approvals ("I'll be away", any start and end
 * time), or by HR on wp-admin → Substitutes. While it is active, every approval level that would
 * wait for the manager waits for the substitute instead, marked "for" the manager
 * (ews_approval_steps.for_employee_id, substitute_id): new requests, the next level of a request,
 * and the ones already waiting when it starts. The manager can still decide them (a setting). When it
 * ends, what still waits goes back to the manager (or stays, a setting) and the manager is told what
 * was decided. A substitute's own request stays with the manager. Rules: src/Approvals/Substitutes.php.
 * Behaviour: tests/e2e_substitutes.py.
 */
trait EWS_Substitutes_Trait {

    /** The leave form's pick, checked before the leave is saved (leave_request_create). */
    private $substitute_leave_pick=0;
    private $substitutes_shutdown_sync=false;

    private function substitutes_enabled(){ return (bool)$this->option('ews_feature_substitutes'); }

    /** @return array{manager_decides:int,on_return:string,who:string,ask_on_leave:string} */
    private function substitute_settings(){ return Substitutes::settings($this->option('ews_substitute_settings')); }

    private function substitutes_table(){
        global $wpdb;
        return $wpdb->prefix.'ews_approval_substitutes';
    }

    private function ensure_substitutes_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=$this->substitutes_table();$c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            manager_employee_id BIGINT UNSIGNED NOT NULL,
            substitute_employee_id BIGINT UNSIGNED NOT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            source VARCHAR(20) NOT NULL DEFAULT 'hr',
            leave_request_id BIGINT UNSIGNED NULL,
            reason VARCHAR(255) NOT NULL DEFAULT '',
            decided INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY manager_status (manager_employee_id,status),
            KEY substitute_status (substitute_employee_id,status),
            KEY status_starts (status,starts_at),
            KEY leave_request (leave_request_id)
        ) {$c};");
    }

    /** Who may set substitutes for anyone (wp-admin → Substitutes): administrators, settings managers, the HR approver. */
    private function substitutes_can_manage(){
        if(!is_user_logged_in())return false;
        $uid=(int)get_current_user_id();
        return user_can($uid,'manage_options') || $this->can('ews_manage_settings') || $uid===(int)$this->approval_settings()['hr_user'];
    }

    /** May this employee set their own substitute ("I'll be away")? Managers: someone reports to them. */
    private function substitute_can_set_own($emp){
        return $this->substitutes_enabled() && $emp && $this->people_has_reports((int)$emp->id);
    }

    private function substitutes_sync_at_shutdown(){
        if(!$this->substitutes_enabled() || $this->substitutes_shutdown_sync)return;
        $this->substitutes_shutdown_sync=true;
        add_action('shutdown',function(){$this->substitutes_sync();});
    }

    /** The hourly job (ews_approvals_tick). */
    public function substitutes_tick(){ $this->substitutes_sync(); }

    /** At most once a minute on page loads (the hourly job is too coarse for a start at 14:30). */
    public function substitutes_maybe_sync(){
        if(!$this->substitutes_enabled())return;
        $next=(int)get_option('ews_substitutes_next_sync',0);
        if($next>time())return;
        update_option('ews_substitutes_next_sync',time()+60,true);
        $this->substitutes_sync();
    }

    /**
     * Bring every substitute up to date at $now: one linked to a leave follows it (approved: scheduled;
     * rejected or cancelled: cancelled); a scheduled one starts at its time, an active one ends at its
     * time; levels now waiting for a manager who has an active substitute go to the substitute.
     * @return array{started:int,ended:int,cancelled:int,moved:int}
     */
    public function substitutes_sync($now=null){
        return $this->services()->substitutes()->sync(is_string($now)?$now:null);
    }

    /** End (its time is over, or ended now) or cancel a substitute; what still waits goes back to the manager unless the setting keeps it. */
    private function substitute_finish($row,$status,$now){
        return $this->services()->substitutes()->finish($row,(string)$status,(string)$now);
    }

    /** The substitute is told the cover started ($moved levels went to them now). */
    private function substitute_tell_started($row,$moved,$manager){
        $this->notify((int)$this->people_user_id((int)$row->substitute_employee_id),'system',sprintf(/* translators: %s: manager name */__('You decide for %s','workforce-one'),$manager),
            sprintf(/* translators: 1: manager name, 2: date and time, 3: number of requests */_n('Until %2$s, requests that would wait for %1$s wait for you. %3$d is waiting now.','Until %2$s, requests that would wait for %1$s wait for you. %3$d are waiting now.',$moved,'workforce-one'),$manager,$this->substitute_when((string)$row->ends_at),$moved),
            ['entity'=>'approval','push'=>true]);
    }

    /** The manager is told the cover ended: what the substitute decided, what went back, what still waits for the substitute. */
    private function substitute_tell_ended($muid,$row,$decided,$back,$still,$sub){
        $msg=sprintf(/* translators: 1: substitute name, 2: number of requests */_n('%1$s decided %2$d request for you while you were away.','%1$s decided %2$d requests for you while you were away.',$decided,'workforce-one'),$sub,$decided);
        if($back)$msg.=' '.sprintf(/* translators: %d: number of requests */_n('%d still waits for you.','%d still wait for you.',$back,'workforce-one'),$back);
        if($still)$msg.=' '.sprintf(/* translators: 1: number of requests, 2: substitute name */_n('%1$d still waits for %2$s.','%1$d still wait for %2$s.',$still,'workforce-one'),$still,$sub);
        $this->notify($muid,'system',__('Your substitute has ended','workforce-one'),$msg,['entity'=>'approval','push'=>true]);
    }

    /** The substitute is told they were picked (when it is scheduled). */
    private function substitute_tell_picked($row){
        $this->notify((int)$this->people_user_id((int)$row->substitute_employee_id),'system',__('You will decide for a manager','workforce-one'),
            sprintf(/* translators: 1: manager name, 2: start, 3: end */__('From %2$s to %3$s, requests that would wait for %1$s will wait for you.','workforce-one'),
                $this->people_name((int)$row->manager_employee_id),$this->substitute_when((string)$row->starts_at),$this->substitute_when((string)$row->ends_at)),['entity'=>'approval']);
    }

    private function substitute_when($dt){ return date_i18n('D j M, H:i',strtotime((string)$dt)); }

    /** Whether $employee may be a substitute for $manager under the setting. */
    private function substitute_eligible($manager,$candidate){
        $who=$this->substitute_settings()['who'];
        return Substitutes::eligible($who,['active'=>$candidate && (int)$candidate->active===1 && (int)$candidate->wp_user_id>0,
            'has_reports'=>$candidate && $who==='managers' && $this->people_has_reports((int)$candidate->id),
            'same_department'=>$candidate && $manager && (int)$manager->department_id>0 && (int)$candidate->department_id===(int)$manager->department_id]);
    }

    /**
     * Why this substitute cannot be saved, or null.
     * @return string|null same, dates, past, who, away, overlap, manager, substitute
     */
    private function substitute_check($manager_id,$substitute_id,$starts,$ends,$ignore_id=0){
        return $this->services()->substitutes()->check((int)$manager_id,(int)$substitute_id,(string)$starts,(string)$ends,(int)$ignore_id);
    }

    /** A message for a refusal code. */
    private function substitute_error_text($code){
        $m=['same'=>__('A manager cannot be their own substitute.','workforce-one'),'dates'=>__('The end must be after the start.','workforce-one'),
            'past'=>__('These days are already over.','workforce-one'),'who'=>__('That person cannot be a substitute (see the substitute settings).','workforce-one'),
            'away'=>__('That person is away themselves on some of these days. Pick someone else or change the days.','workforce-one'),
            'overlap'=>__('There is already a substitute for some of these days.','workforce-one'),'manager'=>__('Pick the manager.','workforce-one'),
            'substitute'=>__('Pick the substitute.','workforce-one'),'access'=>__('You cannot do that.','workforce-one')];
        return $m[$code]??$m['access'];
    }

    /** Save a substitute row. @return int|string the id, or a refusal code */
    private function substitute_create($manager_id,$substitute_id,$starts,$ends,$source,$reason='',$leave_id=0,$status='scheduled'){
        return $this->services()->substitutes()->create((int)$manager_id,(int)$substitute_id,(string)$starts,(string)$ends,(string)$source,(string)$reason,(int)$leave_id,(string)$status,(int)get_current_user_id());
    }

    /**
     * The people offered as substitutes: the manager's own manager and the managers in the department
     * first, then everyone else the setting allows. @return list<array{id:int,name:string,sub:string,suggested:bool}>
     */
    private function substitute_choices($manager){
        if(!$manager)return [];
        $boss=$this->approval_related_employee((int)$manager->id,'supervisor');
        $out=[];
        foreach($this->people_active_users() as $r){
            if((int)$r->id===(int)$manager->id || !$this->substitute_eligible($manager,$r))continue;
            $is_boss=$boss && (int)$boss->id===(int)$r->id;
            $peer=!$is_boss && (int)$manager->department_id>0 && (int)$r->department_id===(int)$manager->department_id && $this->people_has_reports((int)$r->id);
            $out[]=['id'=>(int)$r->id,'name'=>(string)$r->name,'sub'=>$is_boss?__('Your manager','workforce-one'):($peer?__('A manager in your department','workforce-one'):''),'suggested'=>$is_boss||$peer,'rank'=>$is_boss?0:($peer?1:2)];
        }
        usort($out,function($a,$b){return [$a['rank'],$a['name']]<=>[$b['rank'],$b['name']];});
        return array_map(function($x){unset($x['rank']);return $x;},$out);
    }

    /* ------------------------------------------------------------------ leave */

    /** The leave form's question, for a manager: null when not asked. @return array{required:bool,people:list<array<string,mixed>>}|null */
    private function substitute_leave_question($emp){
        if(!$this->substitute_can_set_own($emp))return null;
        $ask=$this->substitute_settings()['ask_on_leave'];
        if($ask==='no')return null;
        return ['required'=>$ask==='required','people'=>$this->substitute_choices($emp)];
    }

    /** Before a leave is saved: the substitute picked with it, checked. @return string|null a leave_error code */
    private function substitute_leave_check($emp,$start,$end){
        $this->substitute_leave_pick=0;
        $q=$this->substitute_leave_question($emp);
        if(!$q)return null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- leave_request_create() checked its nonce
        $pick=absint($_POST['substitute_employee_id']??0);
        if(!$pick)return $q['required']?'substitute':null;
        [$from,$to]=Substitutes::leavePeriod($start,$end);
        if($this->substitute_check((int)$emp->id,$pick,$from,$to)!==null)return 'substitute_bad';
        $this->substitute_leave_pick=$pick;
        return null;
    }

    /** After the leave is saved: its substitute, waiting for the leave's approval. */
    private function substitute_leave_saved($emp,$leave_id,$start,$end){
        if(!$this->substitute_leave_pick || !$leave_id)return;
        [$from,$to]=Substitutes::leavePeriod($start,$end);
        $this->substitute_create((int)$emp->id,$this->substitute_leave_pick,$from,$to,'leave','',(int)$leave_id,'pending');
        $this->substitute_leave_pick=0;
    }

    /* ------------------------------------------------------------------ the app */

    /** For My approvals: who I decide for now, and my own substitutes. @return array<string,mixed> */
    private function substitute_app_data($emp){
        if(!$this->substitutes_enabled() || !$emp)return ['on'=>false];
        $now=current_time('mysql');
        $subs=$this->services()->substitutes();
        $for=$subs->decidingFor((int)$emp->id,function($r,$n){return ['name'=>$this->people_name((int)$r->manager_employee_id),'until'=>$this->substitute_when((string)$r->ends_at),'waiting'=>$n];});
        $own=[];
        if($this->substitute_can_set_own($emp)){
            foreach($subs->ownOpen((int)$emp->id,$now) as $r)
                $own[]=['id'=>(int)$r->id,'name'=>$this->people_name((int)$r->substitute_employee_id),'from'=>$this->substitute_when((string)$r->starts_at),'to'=>$this->substitute_when((string)$r->ends_at),'status'=>(string)$r->status,'leave'=>(int)$r->leave_request_id>0];
        }
        return ['on'=>true,'for'=>$for,'own'=>$own,'can_set'=>$this->substitute_can_set_own($emp),'people'=>$this->substitute_can_set_own($emp)?$this->substitute_choices($emp):[],
            'post_url'=>admin_url('admin-post.php'),'now'=>substr(str_replace(' ','T',$now),0,16)];
    }

    /** Where a substitute form goes back to: the page it came from (My approvals or wp-admin → Substitutes). */
    private function substitute_back($args){
        $back=wp_get_referer();
        if(!$back || strpos($back,'admin-post.php')!==false)$back=$this->substitutes_can_manage()?admin_url('admin.php?page=ews31-substitutes'):$this->app_home_url();
        return add_query_arg($args,remove_query_arg(['sub_done','sub_error'],$back));
    }

    /** admin-post ews_substitute_save: the manager's own ("I'll be away"), or HR's for any manager. */
    public function substitute_save(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_substitute_save');
        if(!$this->substitutes_enabled()){wp_safe_redirect($this->substitute_back(['sub_error'=>'access']));exit;}
        $emp=$this->current_employee();
        // HR picks the manager (wp-admin → Substitutes); a manager's own "I'll be away" sends none.
        $manager_id=absint($_POST['manager_employee_id']??0);
        if($manager_id){
            if(!$this->substitutes_can_manage()){wp_safe_redirect($this->substitute_back(['sub_error'=>'access']));exit;}
            $source=$emp && $manager_id===(int)$emp->id?'self':'hr';
        }else{
            if(!$this->substitute_can_set_own($emp)){wp_safe_redirect($this->substitute_back(['sub_error'=>'access']));exit;}
            $manager_id=(int)$emp->id;$source='self';
        }
        $starts=Substitutes::dateTime(wp_unslash($_POST['starts_at']??''));
        $ends=Substitutes::dateTime(wp_unslash($_POST['ends_at']??''));
        $r=$this->substitute_create($manager_id,absint($_POST['substitute_employee_id']??0),$starts,$ends,$source,sanitize_text_field(wp_unslash($_POST['reason']??'')));
        wp_safe_redirect($this->substitute_back(is_int($r)?['sub_done'=>'saved']:['sub_error'=>$r]));exit;
    }

    /** admin-post ews_substitute_end: end an active substitute now, or cancel one not started (the manager, or HR). */
    public function substitute_end(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        $id=absint($_POST['id']??0);
        check_admin_referer('ews_substitute_end_'.$id);
        $row=$this->substitutes_enabled()?$this->services()->substitutes()->find($id):null;
        $emp=$this->current_employee();
        $mine=$row && $emp && (int)$row->manager_employee_id===(int)$emp->id;
        if(!$row || (!$mine && !$this->substitutes_can_manage()) || !in_array($row->status,['pending','scheduled','active'],true)){wp_safe_redirect($this->substitute_back(['sub_error'=>'access']));exit;}
        $ok=$this->substitute_finish($row,$row->status==='active'?'ended':'cancelled',current_time('mysql'));
        wp_safe_redirect($this->substitute_back($ok?['sub_done'=>$row->status==='active'?'ended':'cancelled']:['sub_error'=>'access']));exit;
    }

    /** A notice for ?sub_done / ?sub_error. @return array{type:string,text:string}|null */
    private function substitute_notice(){
        $done=sanitize_key($_GET['sub_done']??'');$error=sanitize_key($_GET['sub_error']??'');
        if($done!==''){
            $m=['saved'=>__('Substitute saved.','workforce-one'),'ended'=>__('Substitute ended. What still waits goes back to the manager.','workforce-one'),'cancelled'=>__('Substitute cancelled.','workforce-one')];
            return ['type'=>'ok','text'=>$m[$done]??$m['saved']];
        }
        return $error!==''?['type'=>'bad','text'=>$this->substitute_error_text($error)]:null;
    }

    /* ------------------------------------------------------------------ wp-admin */

    /** wp-admin → Approvals → Substitutes. */
    public function admin_substitutes(){
        if(!$this->substitutes_can_manage())wp_die('Access denied');
        $this->substitutes_maybe_sync();
        $rows=$this->substitutes_enabled()?$this->services()->substitutes()->adminRows():[];
        foreach($rows as $r){$r->manager=$this->people_name((int)$r->manager_employee_id);$r->substitute=$this->people_name((int)$r->substitute_employee_id);$r->from=$this->substitute_when((string)$r->starts_at);$r->to=$this->substitute_when((string)$r->ends_at);}
        $people=$this->people_active_users();
        echo $this->render_template('admin/substitutes',['on'=>$this->substitutes_enabled(),'rows'=>$rows,'people'=>$people,'notice'=>$this->substitute_notice(),
            'post_url'=>admin_url('admin-post.php'),'features_url'=>admin_url('admin.php?page=ews31-features#ews-substitutes'),'settings'=>$this->substitute_settings()]);
    }
}
