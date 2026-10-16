<?php
if (!defined('ABSPATH')) exit;

/**
 * My approvals and My requests (3.31.103, owner decisions 2026-10-16, docs/tasks/approvals.md,
 * mockup docs/mockups/my-approvals/). Off by default (wp-admin → Features).
 *
 * - My approvals (app ?ews_view=approvals): every request waiting for the signed-in person, of every
 *   type, with its level, how long it has waited in working hours, overdue ones first, whether it
 *   moved up from someone else, and its path. Administrators also see the levels waiting for them
 *   (nobody else to decide them). Each request is decided with the same form as on its own page
 *   (the module's own handler, rules, notifications and Audit Log), which brings the person back
 *   here (ews_return=approvals). Several can be approved at once (assets/js/approvals.js, up to 20).
 * - My requests (app ?ews_view=requests): the person's own leave, overtime, corrections and swaps,
 *   each with its path, and withdrawing one still waiting (admin-post ews_request_withdraw).
 * The approval lists inside each page stay, with a link here.
 * Templates: templates/app/approvals.php, templates/app/requests.php; styles assets/css/app-approvals.css.
 * Behaviour: tests/e2e_my_approvals.py.
 */
trait EWS_My_Approvals_Trait {

    /** @var array<int,mixed>|null the inbox of this page load */
    private $my_approvals_memo=null;

    private function my_approvals_enabled(){ return (bool)$this->option('ews_feature_my_approvals'); }

    /** The view a module's decision returns to: My approvals when the decision was taken there. */
    private function approvals_return_view($view){
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the module checked its own nonce
        // My requests' New request sheet sends ews_return=requests (3.31.105).
        $back=sanitize_key($_POST['ews_return']??'');
        return $this->my_approvals_enabled() && in_array($back,['approvals','requests'],true)?$back:$view;
    }

    /** Whether the signed-in person sees My approvals: anyone with something to decide, managers, approvers and administrators. */
    private function my_approvals_allowed(){
        if(!$this->my_approvals_enabled() || !is_user_logged_in())return false;
        $uid=(int)get_current_user_id();
        if(user_can($uid,'manage_options') || $this->my_approvals_items())return true;
        $s=$this->approval_settings();
        if(in_array($uid,[$s['hr_user'],$s['finance_user']],true))return true;
        $emp=$this->current_employee();
        if($emp && $this->people_has_reports((int)$emp->id))return true;
        global $wpdb;$t=$this->approval_tables();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['instances']} WHERE approver_wp_user_id=%d OR acted_by_wp_user_id=%d LIMIT 1",$uid,$uid));
    }

    private function my_requests_allowed(){
        return $this->my_approvals_enabled() && is_user_logged_in() && (bool)$this->current_employee();
    }

    /** What decides a request of this kind, and how it reads (each module's own card). */
    private function my_approvals_card($entity_type,$entity_id){
        switch($entity_type){
            case 'leave': case 'leave_cancellation': return $this->leave_approval_card($entity_type,(int)$entity_id);
            case 'overtime_request': return $this->overtime_enabled()?$this->overtime_approval_card((int)$entity_id):null;
            case 'attendance_correction': return $this->corrections_enabled()?$this->cx_approval_card((int)$entity_id):null;
            case 'schedule_swap': return $this->swap_approval_card((int)$entity_id);
            case 'face_reset_request': return $this->face_reset_approval_card((int)$entity_id);
        }
        return null;
    }

    /**
     * Everything waiting for the signed-in person, overdue first, then the longest waiting.
     * @return list<array<string,mixed>>
     */
    private function my_approvals_items(){
        if($this->my_approvals_memo!==null)return $this->my_approvals_memo;
        $this->my_approvals_memo=[];
        if(!$this->my_approvals_enabled() || !is_user_logged_in())return [];
        $this->ensure_approval_schema();
        global $wpdb;$t=$this->approval_tables();$uid=(int)get_current_user_id();
        $admin=user_can($uid,'manage_options');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT i.id step_id,i.approval_request_id,i.pending_since,r.entity_type,r.entity_id,r.requester_employee_id,w.workflow_key FROM {$t['instances']} i
            JOIN {$t['requests']} r ON r.id=i.approval_request_id JOIN {$t['workflows']} w ON w.id=r.workflow_id
            WHERE i.status='PENDING' AND r.status LIKE 'WAITING%%' AND (i.approver_wp_user_id=%d OR (%d=1 AND i.rerouted=1 AND i.approver_wp_user_id IS NULL))
            ORDER BY i.pending_since ASC,i.id ASC LIMIT 200",$uid,$admin?1:0));
        $out=[];
        foreach($rows as $row){
            $card=$this->my_approvals_card((string)$row->entity_type,(int)$row->entity_id);
            if(!$card)continue;
            $path=$this->approval_path((string)$row->workflow_key,(string)$row->entity_type,(int)$row->entity_id);
            if(!$path)continue;
            $emp=$this->people_employee((int)$card['employee_id']);
            $mine=false;
            foreach($path['steps'] as $st)if($st['state']==='now' && $st['who']===__('Administrators','workforce-one'))$mine=true;
            $out[]=$card+['id'=>(int)$row->approval_request_id,'workflow'=>(string)$row->workflow_key,'entity_type'=>(string)$row->entity_type,'entity_id'=>(int)$row->entity_id,
                'name'=>$emp?(string)$emp->name:__('Employee','workforce-one'),'title'=>$emp?trim((string)($emp->job_title??'')):'','employee_id'=>(int)$card['employee_id'],
                'picture'=>$emp?$this->employee_picture_url($emp):'','path'=>$path,'admins'=>$mine,'note_required'=>$this->approval_reject_note_required((string)$row->workflow_key)];
        }
        usort($out,function($a,$b){
            if($a['path']['overdue']!==$b['path']['overdue'])return $a['path']['overdue']?-1:1;
            return $b['path']['hours']<=>$a['path']['hours'];
        });
        return $this->my_approvals_memo=$out;
    }

    /** How many requests wait for the signed-in person (the menu and the home card). */
    private function my_approvals_count(){
        return $this->my_approvals_enabled() && is_user_logged_in()?count($this->my_approvals_items()):0;
    }

    /** The home card: how many wait, how many are overdue. @return array{waiting:int,overdue:int,url:string} */
    private function my_approvals_home(){
        $items=$this->my_approvals_enabled() && is_user_logged_in()?$this->my_approvals_items():[];
        return ['waiting'=>count($items),'overdue'=>count(array_filter($items,function($it){return $it['path']['overdue'];})),'url'=>$this->app_view_url('approvals'),
            // The New request shortcut (3.31.105): My requests with its sheet open.
            'new_url'=>$this->my_requests_allowed()?add_query_arg('new','1',$this->my_approvals_clean_url('requests')):''];
    }

    /** The link above a page's own approval list: all of them in one place. '' while My approvals is off. */
    private function my_approvals_link(){
        if(!$this->my_approvals_enabled())return '';
        $n=$this->my_approvals_count();
        return '<a class="wfo-apv-cta" href="'.esc_url($this->app_view_url('approvals')).'" data-apv-link><span>'.esc_html($n?sprintf(/* translators: %d: number of requests */_n('All your approvals in one place · %d waiting','All your approvals in one place · %d waiting',$n,'workforce-one'),$n):__('All your approvals in one place','workforce-one')).'</span><span aria-hidden="true">›</span></a>';
    }

    /** The kinds the inbox can be narrowed to. */
    private function my_approvals_kinds(){
        return ['leave'=>__('Leave','workforce-one'),'overtime'=>__('Overtime','workforce-one'),'correction'=>__('Corrections','workforce-one'),'swap'=>__('Swaps','workforce-one'),'face'=>__('Face reset','workforce-one')];
    }

    /** App → My approvals. */
    private function approvals_content(){
        $items=$this->my_approvals_items();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a filter
        $kind=sanitize_key($_GET['apv_kind']??'');
        $kinds=$this->my_approvals_kinds();
        $present=[];foreach($items as $it)$present[$it['tone']]=true;
        $shown=array_values(array_filter($items,function($it)use($kind){return $kind===''||($kind==='late'?$it['path']['overdue']:$it['tone']===$kind);}));
        global $wpdb;$t=$this->approval_tables();
        $week=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['instances']} WHERE acted_by_wp_user_id=%d AND acted_at>=%s",(int)get_current_user_id(),gmdate('Y-m-d H:i:s',current_time('timestamp')-7*DAY_IN_SECONDS)));
        wp_enqueue_script('workforce-one-approvals');
        return $this->render_template('app/approvals',[
            'items'=>$shown,'all'=>count($items),'overdue'=>count(array_filter($items,function($it){return $it['path']['overdue'];})),'week'=>$week,
            'kind'=>$kind,'kinds'=>array_intersect_key($kinds,$present),'base_url'=>$this->my_approvals_clean_url('approvals'),'post_url'=>admin_url('admin-post.php'),
            'notice'=>$this->my_approvals_notice(),'bulk_max'=>20,
        ]);
    }

    /** The message after a decision taken here (the ones the app does not already show everywhere). */
    private function my_approvals_notice(){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        if(isset($_GET['apv_bulk'])){
            $n=absint($_GET['apv_bulk']);$asked=absint($_GET['apv_asked']??$n);
            /* translators: %d: number of requests */
            $msg=sprintf(_n('%d request approved.','%d requests approved.',$n,'workforce-one'),$n);
            if($asked>$n)$msg.=' '.sprintf(/* translators: %d: number of requests */_n('%d could not be approved: open it to see why.','%d could not be approved: open them to see why.',$asked-$n,'workforce-one'),$asked-$n);
            return ['type'=>$asked>$n?'warning':'success','text'=>$msg];
        }
        if(isset($_GET['leave_rejected']))return ['type'=>'success','text'=>__('The leave request was rejected, and the employee was told.','workforce-one')];
        if(isset($_GET['swap_done']))return ['type'=>'success','text'=>__('The shift swap was updated.','workforce-one')];
        if(isset($_GET['swap_error']))return ['type'=>'error','text'=>sanitize_key($_GET['swap_error'])==='note'?__('Write why you reject it, then reject it again.','workforce-one'):__('The shift swap could not be decided. Open it on the Schedule page.','workforce-one')];
        // phpcs:enable
        return null;
    }

    /** A view's address without this page's own filters and results, for "All" and the filter chips. */
    private function my_approvals_clean_url($view){
        return remove_query_arg(['apv_kind','apv_bulk','apv_asked','req_state','req_done','req_error','leave_rejected','swap_done','swap_error'],$this->app_view_url($view));
    }

    /** App → My requests. */
    private function requests_content(){
        $emp=$this->current_employee();
        $items=$emp?array_merge($this->leave_my_requests((int)$emp->id),$this->overtime_my_requests((int)$emp->id),$this->cx_my_requests((int)$emp->id),$this->swap_my_requests((int)$emp->id)):[];
        usort($items,function($a,$b){return strcmp((string)$b['when'],(string)$a['when']);});
        $items=array_slice($items,0,40);
        foreach($items as &$it){
            [$wk,$et,$eid]=$it['approval'];
            $it['path']=$this->approval_path($wk,$et,$eid);
            if(($it['note']??'')==='' && $it['path'] && $it['path']['note']!=='')$it['note']=$it['path']['note'];
        }
        unset($it);
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        $notice=null;
        if(isset($_GET['req_done']))$notice=['type'=>'success','text'=>sanitize_key($_GET['req_done'])==='face'?__('Face reset asked. Your current face is used until it is approved.','workforce-one'):__('Withdrawn. Nobody needs to decide it any more.','workforce-one')];
        elseif(isset($_GET['req_error']))$notice=['type'=>'error','text'=>sanitize_key($_GET['req_error'])==='face'?__('The face reset could not be asked. Register your face first on the Sign In page.','workforce-one'):(sanitize_key($_GET['req_error'])==='done'?__('It was already decided, so it can no longer be withdrawn.','workforce-one'):__('It could not be withdrawn. Try again.','workforce-one'))];
        // phpcs:enable
        return $this->render_template('app/requests',['items'=>$items,'post_url'=>admin_url('admin-post.php'),'notice'=>$notice,'filter'=>sanitize_key($_GET['req_state']??''),'base_url'=>$this->my_approvals_clean_url('requests'),
            'new'=>$this->my_requests_new($emp),'open_new'=>!empty($_GET['new'])]); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * The New request sheet (3.31.105, owner decisions 2026-10-16, mockup docs/mockups/new-request/):
     * the request types this person can ask for, each with a useful line, and what each form needs.
     * Each form posts to its own handler (the same rules and messages as its page) and comes back here.
     * @return array<string,mixed>
     */
    private function my_requests_new($emp){
        if(!$emp)return ['kinds'=>[]];
        wp_enqueue_style('workforce-one-requests');wp_enqueue_script('workforce-one-sheet');wp_enqueue_script('workforce-one-leave');wp_enqueue_script('workforce-one-my-requests');
        $kinds=[];$data=['working_days'=>array_values($this->working_days()),'today'=>current_time('Y-m-d'),'tomorrow'=>gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +1 day'))];
        $types=$this->leave_form_types($emp);
        if($types){
            $line=implode(' · ',array_filter(array_map(function($t){return $t['deducts']&&$t['available']!==''?sprintf(/* translators: 1: days left, 2: leave type */__('%1$s %2$s left','workforce-one'),$t['available'],$t['name']):'';},array_slice($types,0,2))));
            $kinds['leave']=['group'=>'time','label'=>__('Leave','workforce-one'),'line'=>$line,'sheet'=>'wfo-new-leave'];$data['types']=$types;
        }
        $early=$this->leave_early_form($emp);
        if($early && $early['max']>0){
            $kinds['early']=['group'=>'time','label'=>__('Early leave','workforce-one'),'line'=>sprintf(/* translators: %s: hours */__('%s h left this month','workforce-one'),\WorkforceOne\Support\Format::hours($early['remaining'])),'sheet'=>'wfo-new-early'];$data['early']=$early;
        }
        if($this->overtime_enabled()){$kinds['overtime']=['group'=>'time','label'=>__('Overtime','workforce-one'),'line'=>__('Before or after work','workforce-one'),'sheet'=>'wfo-ot-sheet'];wp_enqueue_script('workforce-one-overtime');}
        $cx=$this->cx_new_request_sheet($emp);
        if($cx['html']!==''){
            $kinds['correction']=['group'=>'attendance','label'=>__('Attendance correction','workforce-one'),'line'=>$cx['limit']>0?sprintf(/* translators: %d: requests left */_n('%d left this month','%d left this month',max(0,$cx['limit']-$cx['used']),'workforce-one'),max(0,$cx['limit']-$cx['used'])):__('A missing or wrong Sign In or Out','workforce-one'),'sheet'=>'wfo-cx-sheet'];
            $data['cx_html']=$cx['html'];
        }
        $swap=$this->swap_new_choices($emp);
        $kinds['swap']=['group'=>'attendance','label'=>__('Swap with a colleague','workforce-one'),'line'=>$swap['reason']===''?__('An office day for a home day','workforce-one'):($swap['reason']==='department'?__('You are not in a department yet','workforce-one'):($swap['reason']==='days'?__('No office or home days in the next two weeks','workforce-one'):__('Nobody else in your department','workforce-one'))),'sheet'=>'wfo-new-swap','off'=>$swap['reason']!==''];
        $data['swap']=$swap;
        if($this->face_reset_can_ask((int)$emp->id))$kinds['face']=['group'=>'attendance','label'=>__('Face reset','workforce-one'),'line'=>__('If your face is not recognised','workforce-one'),'sheet'=>'wfo-new-face'];
        elseif($this->face_reset_requested((int)$emp->id))$kinds['face']=['group'=>'attendance','label'=>__('Face reset','workforce-one'),'line'=>__('Already asked, waiting','workforce-one'),'sheet'=>'','off'=>true];
        return ['kinds'=>$kinds]+$data;
    }

    /** My requests → Withdraw (admin-post ews_request_withdraw): a waiting leave, overtime or correction. */
    public function request_withdraw(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        $kind=sanitize_key($_POST['kind']??'');$id=absint($_POST['id']??0);
        check_admin_referer('ews_request_withdraw_'.$kind.'_'.$id);
        // Back to the page the person came from (app_view_url() would build on admin-post.php here).
        $back=wp_get_referer();
        if(!$back || strpos($back,'admin-post.php')!==false)$back=$this->app_home_url();
        $back=add_query_arg('ews_view','requests',remove_query_arg(['req_done','req_error','req_state'],$back));
        $emp=$this->current_employee();
        if(!$this->my_requests_allowed() || !$emp){wp_safe_redirect(add_query_arg('req_error','access',$back));exit;}
        switch($kind){
            case 'leave': $r=$this->leave_withdraw($id,(int)$emp->id);break;
            case 'overtime': $r=$this->overtime_withdraw($id,(int)$emp->id);break;
            case 'correction': $r=$this->corrections_enabled()?$this->cx_withdraw($id,(int)$emp->id):'access';break;
            default: $r='access';
        }
        wp_safe_redirect(add_query_arg($r===true?['req_done'=>$kind]:['req_error'=>$r],$back));exit;
    }
}
