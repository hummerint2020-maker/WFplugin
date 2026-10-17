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

    /** The substitute deciding for this manager now, or null (scheduled ones whose time came count before the sync marks them). */
    private function substitute_active_for($manager_employee_id,$now=null){
        if(!$this->substitutes_enabled() || !(int)$manager_employee_id)return null;
        global $wpdb;$t=$this->substitutes_table();$now=$now?:current_time('mysql');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE manager_employee_id=%d AND status IN ('scheduled','active') AND starts_at<=%s AND ends_at>%s ORDER BY id DESC LIMIT 1",(int)$manager_employee_id,$now,$now));
        return $row?:null;
    }

    /**
     * For a level about to wait for $approver_employee_id: who decides it instead. Not the substitute's
     * own request (it stays with the manager). @return array{employee:object,row:object}|null
     */
    private function substitute_route($approver_employee_id,$requester_employee_id){
        $row=$this->substitute_active_for((int)$approver_employee_id);
        if(!$row || (int)$row->substitute_employee_id===(int)$requester_employee_id)return null;
        $sub=$this->people_employee((int)$row->substitute_employee_id,true);
        if(!$sub || !(int)$sub->wp_user_id)return null;
        return ['employee'=>$sub,'row'=>$row];
    }

    /** Whether $uid is the manager a step waits "for" and may still decide it (setting). */
    private function substitute_manager_may_decide($step,$uid){
        if(!$uid || empty($step->for_employee_id) || !$this->substitute_settings()['manager_decides'])return false;
        return (int)$this->people_user_id((int)$step->for_employee_id)===(int)$uid;
    }

    /** After a decision: count it for the substitute who took it, and bring substitutes up to date at the end of the request. */
    private function substitute_after_decision($step,$uid){
        if(!empty($step->substitute_id) && (int)$step->approver_wp_user_id===(int)$uid){
            global $wpdb;$t=$this->substitutes_table();
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET decided=decided+1 WHERE id=%d",(int)$step->substitute_id));
        }
        $this->substitutes_sync_at_shutdown();
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
        $done=['started'=>0,'ended'=>0,'cancelled'=>0,'moved'=>0];
        if(!$this->substitutes_enabled())return $done;
        global $wpdb;$t=$this->substitutes_table();
        $now=is_string($now)&&$now!==''?$now:current_time('mysql');
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE leave_request_id IS NOT NULL AND status IN ('pending','scheduled','active')") as $row){
            $do=Substitutes::forLeave((string)$row->status,(string)$this->leave_status_of((int)$row->leave_request_id));
            if($do==='schedule' && $wpdb->update($t,['status'=>'scheduled'],['id'=>(int)$row->id,'status'=>'pending'])===1){
                $row->status='scheduled';$this->substitute_tell_picked($row);
            }elseif($do==='cancel' && $this->substitute_finish($row,'cancelled',$now))$done['cancelled']++;
        }
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE status IN ('scheduled','active') ORDER BY starts_at ASC") as $row){
            $do=Substitutes::transition((string)$row->status,(string)$row->starts_at,(string)$row->ends_at,$now);
            if($do==='start'){if($this->substitute_begin($row,$now))$done['started']++;}
            elseif($do==='end'){if($this->substitute_finish($row,'ended',$now))$done['ended']++;}
            elseif($do==='expire'){$wpdb->update($t,['status'=>'ended','ended_at'=>$now],['id'=>(int)$row->id,'status'=>'scheduled']);}
        }
        foreach((array)$wpdb->get_results("SELECT * FROM {$t} WHERE status='active'") as $row)$done['moved']+=$this->substitute_take_waiting($row);
        return $done;
    }

    /** The levels waiting for the manager (not yet "for" anyone) go to the substitute. @return int how many */
    private function substitute_take_waiting($row){
        $sub=$this->people_employee((int)$row->substitute_employee_id,true);
        if(!$sub || !(int)$sub->wp_user_id)return 0;
        global $wpdb;$t=$this->approval_tables();
        $n=$wpdb->query($wpdb->prepare("UPDATE {$t['instances']} SET approver_employee_id=%d,approver_wp_user_id=%d,for_employee_id=%d,substitute_id=%d,reminded_at=NULL
            WHERE status='PENDING' AND approver_employee_id=%d AND for_employee_id IS NULL
            AND approval_request_id IN (SELECT id FROM {$t['requests']} WHERE status LIKE 'WAITING%%' AND requester_employee_id<>%d)",
            (int)$sub->id,(int)$sub->wp_user_id,(int)$row->manager_employee_id,(int)$row->id,(int)$row->manager_employee_id,(int)$sub->id));
        return (int)$n;
    }

    private function substitute_begin($row,$now){
        global $wpdb;$t=$this->substitutes_table();
        if($wpdb->update($t,['status'=>'active','started_at'=>$now],['id'=>(int)$row->id,'status'=>'scheduled'])!==1)return false;
        $moved=$this->substitute_take_waiting($row);
        $manager=$this->people_name((int)$row->manager_employee_id);
        $this->audit('substitute_started','approval_substitute',(int)$row->id,'employee #'.(int)$row->substitute_employee_id.' for #'.(int)$row->manager_employee_id.' · '.$moved.' waiting moved');
        $this->notify((int)$this->people_user_id((int)$row->substitute_employee_id),'system',sprintf(/* translators: %s: manager name */__('You decide for %s','workforce-one'),$manager),
            sprintf(/* translators: 1: manager name, 2: date and time, 3: number of requests */_n('Until %2$s, requests that would wait for %1$s wait for you. %3$d is waiting now.','Until %2$s, requests that would wait for %1$s wait for you. %3$d are waiting now.',$moved,'workforce-one'),$manager,$this->substitute_when((string)$row->ends_at),$moved),
            ['entity'=>'approval','push'=>true]);
        return true;
    }

    /** End (its time is over, or ended now) or cancel a substitute; what still waits goes back to the manager unless the setting keeps it. */
    private function substitute_finish($row,$status,$now){
        global $wpdb;$t=$this->substitutes_table();$was=(string)$row->status;
        if($wpdb->update($t,['status'=>$status,'ended_at'=>$now],['id'=>(int)$row->id,'status'=>$was])!==1)return false;
        $this->audit('substitute_'.$status,'approval_substitute',(int)$row->id,'employee #'.(int)$row->substitute_employee_id.' for #'.(int)$row->manager_employee_id);
        if($was!=='active')return true;
        $back=0;$still=0;
        $a=$this->approval_tables();
        $muid=(int)$this->people_user_id((int)$row->manager_employee_id);
        if($this->substitute_settings()['on_return']==='back' && $muid){
            $back=(int)$wpdb->query($wpdb->prepare("UPDATE {$a['instances']} SET approver_employee_id=%d,approver_wp_user_id=%d,for_employee_id=NULL,substitute_id=NULL,reminded_at=NULL WHERE substitute_id=%d AND status='PENDING'",
                (int)$row->manager_employee_id,$muid,(int)$row->id));
        }else{
            $still=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$a['instances']} WHERE substitute_id=%d AND status='PENDING'",(int)$row->id));
        }
        $decided=(int)$wpdb->get_var($wpdb->prepare("SELECT decided FROM {$t} WHERE id=%d",(int)$row->id));
        $sub=$this->people_name((int)$row->substitute_employee_id);
        $msg=sprintf(/* translators: 1: substitute name, 2: number of requests */_n('%1$s decided %2$d request for you while you were away.','%1$s decided %2$d requests for you while you were away.',$decided,'workforce-one'),$sub,$decided);
        if($back)$msg.=' '.sprintf(/* translators: %d: number of requests */_n('%d still waits for you.','%d still wait for you.',$back,'workforce-one'),$back);
        if($still)$msg.=' '.sprintf(/* translators: 1: number of requests, 2: substitute name */_n('%1$d still waits for %2$s.','%1$d still wait for %2$s.',$still,'workforce-one'),$still,$sub);
        $this->notify($muid,'system',__('Your substitute has ended','workforce-one'),$msg,['entity'=>'approval','push'=>true]);
        return true;
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
        $manager=$this->people_employee((int)$manager_id,true);$cand=$this->people_employee((int)$substitute_id,true);
        global $wpdb;$t=$this->substitutes_table();
        $overlap=false;$away=false;
        if($manager && $cand && $starts!=='' && $ends!=='' && $ends>$starts){
            $overlap=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND starts_at<%s AND ends_at>%s AND id<>%d LIMIT 1",(int)$manager->id,$ends,$starts,(int)$ignore_id));
            // Away themselves: on leave on one of those days, or with a substitute of their own then.
            $away=$this->leave_away_between((int)$cand->id,substr($starts,0,10),substr(gmdate('Y-m-d H:i:s',strtotime($ends)-1),0,10))
                || (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND starts_at<%s AND ends_at>%s LIMIT 1",(int)$cand->id,$ends,$starts));
        }
        return Substitutes::check(['manager'=>$manager?(int)$manager->id:0,'substitute'=>$cand?(int)$cand->id:0,'starts'=>$starts,'ends'=>$ends,'now'=>current_time('mysql'),
            'eligible'=>$this->substitute_eligible($manager,$cand),'away'=>$away,'overlap'=>$overlap]);
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
        $error=$this->substitute_check($manager_id,$substitute_id,$starts,$ends);
        if($error!==null)return $error;
        global $wpdb;$t=$this->substitutes_table();
        $row=['manager_employee_id'=>(int)$manager_id,'substitute_employee_id'=>(int)$substitute_id,'starts_at'=>$starts,'ends_at'=>$ends,'status'=>$status,'source'=>$source,
            'reason'=>mb_substr((string)$reason,0,255),'created_by'=>(int)get_current_user_id(),'created_at'=>current_time('mysql')];
        if($leave_id)$row['leave_request_id']=(int)$leave_id;
        if($wpdb->insert($t,$row)===false)return 'save';
        $id=(int)$wpdb->insert_id;
        $this->audit('substitute_set','approval_substitute',$id,'employee #'.(int)$substitute_id.' for #'.(int)$manager_id.' · '.$starts.' → '.$ends.' · '.$source);
        if($status==='scheduled')$this->substitute_tell_picked($wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id)));
        $this->substitutes_sync();
        return $id;
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
        global $wpdb;$t=$this->substitutes_table();$a=$this->approval_tables();$now=current_time('mysql');
        $for=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE substitute_employee_id=%d AND status='active' ORDER BY ends_at ASC",(int)$emp->id)) as $r){
            $n=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$a['instances']} WHERE substitute_id=%d AND status='PENDING'",(int)$r->id));
            $for[]=['name'=>$this->people_name((int)$r->manager_employee_id),'until'=>$this->substitute_when((string)$r->ends_at),'waiting'=>$n];
        }
        $own=[];
        if($this->substitute_can_set_own($emp)){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE manager_employee_id=%d AND status IN ('pending','scheduled','active') AND ends_at>%s ORDER BY starts_at ASC",(int)$emp->id,$now)) as $r)
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
        global $wpdb;$t=$this->substitutes_table();
        $row=$this->substitutes_enabled()?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id)):null;
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
        global $wpdb;$t=$this->substitutes_table();
        $rows=$this->substitutes_enabled()?(array)$wpdb->get_results("SELECT * FROM {$t} ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'scheduled' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END,starts_at DESC LIMIT 200"):[];
        foreach($rows as $r){$r->manager=$this->people_name((int)$r->manager_employee_id);$r->substitute=$this->people_name((int)$r->substitute_employee_id);$r->from=$this->substitute_when((string)$r->starts_at);$r->to=$this->substitute_when((string)$r->ends_at);}
        $people=$this->people_active_users();
        echo $this->render_template('admin/substitutes',['on'=>$this->substitutes_enabled(),'rows'=>$rows,'people'=>$people,'notice'=>$this->substitute_notice(),
            'post_url'=>admin_url('admin-post.php'),'features_url'=>admin_url('admin.php?page=ews31-features#ews-substitutes'),'settings'=>$this->substitute_settings()]);
    }
}
