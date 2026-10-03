<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Polls\PollRules;

/**
 * Employee polls: wp-admin → Employee Polls (create, audience, results visibility, vote changes,
 * anonymous or named voting, notifications, participation, export, archive) and the employee app
 * (the Dashboard card and the Polls page). Rules: src/Polls/PollRules.php; pages:
 * templates/admin/polls.php, templates/app/polls.php, templates/app/poll-card.php.
 */
trait EWS_Polls_Trait {

    private function ensure_polls_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $t=$this->polls_table_names();
        dbDelta("CREATE TABLE {$t['polls']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            show_homepage TINYINT(1) NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            audience_department_id BIGINT UNSIGNED NULL,
            results_visibility VARCHAR(20) NOT NULL DEFAULT 'after_vote',
            allow_change TINYINT(1) NOT NULL DEFAULT 0,
            anonymous TINYINT(1) NOT NULL DEFAULT 1,
            notified_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY(id),
            KEY status(status),
            KEY homepage(show_homepage,status),
            KEY dates(starts_at,ends_at)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['options']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            KEY poll_order(poll_id,sort_order),
            KEY poll_id(poll_id)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['votes']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY employee_poll(poll_id,employee_id),
            KEY poll_option(poll_id,option_id),
            KEY employee_id(employee_id)
        ) {$c};");
    }

    private function polls_table_names(){
        global $wpdb;
        return ['polls'=>$wpdb->prefix.'ews_polls','options'=>$wpdb->prefix.'ews_poll_options','votes'=>$wpdb->prefix.'ews_poll_votes'];
    }

    private function polls_now(){
        return current_time('mysql');
    }

    private function poll_state($poll){
        return PollRules::state((string)$poll->status,$poll->starts_at?:null,$poll->ends_at?:null,$this->polls_now());
    }

    /** Active employees the poll is for (everyone, or its department). */
    private function poll_audience_count($poll){
        global $wpdb;
        return $poll->audience_department_id
            ?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE active=1 AND department_id=%d",(int)$poll->audience_department_id))
            :(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->employees} WHERE active=1");
    }

    /** @return array{rows:array<int,object>,total:int} choices with votes and percentage (adding up to 100) */
    private function poll_results($poll_id){
        global $wpdb; $t=$this->polls_table_names();
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT o.id,o.option_text,o.sort_order,COUNT(v.id) votes FROM {$t['options']} o LEFT JOIN {$t['votes']} v ON v.option_id=o.id AND v.poll_id=o.poll_id WHERE o.poll_id=%d GROUP BY o.id,o.option_text,o.sort_order ORDER BY o.sort_order ASC,o.id ASC",$poll_id));
        $votes=[];foreach($rows as $r)$votes[(int)$r->id]=(int)$r->votes;
        $pct=PollRules::percentages($votes);
        foreach($rows as $r)$r->percentage=$pct[(int)$r->id]??0;
        return ['rows'=>$rows,'total'=>array_sum($votes)];
    }

    /** The current employee's vote (option id) in a poll, 0 when none. */
    private function poll_my_choice($poll_id,$employee_id){
        global $wpdb; $t=$this->polls_table_names();
        return (int)$wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$t['votes']} WHERE poll_id=%d AND employee_id=%d LIMIT 1",$poll_id,$employee_id));
    }

    /** What templates/app/poll-card.php shows for one poll and employee. */
    private function poll_card($poll,$emp,$compact=false){
        global $wpdb; $t=$this->polls_table_names();
        $state=$this->poll_state($poll);
        $mine=$this->poll_my_choice((int)$poll->id,(int)$emp->id);
        $in=PollRules::inAudience($poll->audience_department_id?(int)$poll->audience_department_id:null,(int)$emp->department_id);
        $can_vote=PollRules::voteError($state,$in,$mine>0,(bool)(int)$poll->allow_change)==='';
        $show=PollRules::resultsVisible((string)$poll->results_visibility,$state,$mine>0);
        $note='';
        if(!$show&&$mine){
            $note=$poll->results_visibility==='admins'?'Thanks! The results are shared with the admins only.':'Results will be shown when the poll closes'.($poll->ends_at?' ('.date_i18n('d M, H:i',strtotime($poll->ends_at)).').':'.');
        }
        return [
            'id'=>(int)$poll->id,'question'=>(string)$poll->question,'description'=>(string)$poll->description,'state'=>$state,
            'choices'=>(array)$wpdb->get_results($wpdb->prepare("SELECT id,option_text FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order ASC,id ASC",(int)$poll->id)),
            'mine'=>$mine,'can_vote'=>$can_vote,'changing'=>$can_vote&&$mine>0,'results'=>$show?$this->poll_results((int)$poll->id):null,'note'=>$note,
            'closes'=>$state==='open'?PollRules::closesLabel($poll->ends_at?:null,$this->polls_now()):'',
            'anonymous'=>(bool)(int)$poll->anonymous,'nonce'=>wp_create_nonce('ews_poll_vote_'.$poll->id),'compact'=>$compact,
        ];
    }

    /**
     * Polls the employee can see: open ones (unanswered first, newest first), then past ones
     * (closed or archived). Hidden and scheduled polls are not shown.
     * @return array{0:object[],1:object[]} [open, past]
     */
    private function employee_polls($emp){
        global $wpdb; $t=$this->polls_table_names();
        $this->ensure_polls_schema();
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT p.*,(SELECT COUNT(*) FROM {$t['votes']} v WHERE v.poll_id=p.id AND v.employee_id=%d) answered FROM {$t['polls']} p WHERE p.status IN ('active','archived') AND (p.audience_department_id IS NULL OR p.audience_department_id=%d) ORDER BY p.id DESC LIMIT 60",(int)$emp->id,(int)$emp->department_id));
        $open=[];$past=[];
        foreach($rows as $p){$s=$this->poll_state($p);if($s==='open')$open[]=$p;elseif($s==='closed'||$s==='archived')$past[]=$p;}
        usort($open,function($a,$b){return [(int)$a->answered,-(int)$a->id]<=>[(int)$b->answered,-(int)$b->id];});
        return [$open,$past];
    }

    /** Whether the app's Polls page has anything for the current user. */
    private function polls_available(){
        $emp=$this->current_employee();
        if(!$emp)return false;
        [$open,$past]=$this->employee_polls($emp);
        return (bool)($open||$past);
    }

    /** Dashboard card: the newest poll the employee has not answered (else the newest open one). */
    private function employee_poll_markup(){
        $emp=$this->current_employee();
        if(!$emp)return '';
        [$open]=$this->employee_polls($emp);
        $open=array_values(array_filter($open,function($p){return !empty($p->show_homepage);}));
        if(!$open)return '';
        return $this->render_template('app/poll-card',['poll'=>$this->poll_card($open[0],$emp,true),'more'=>count($open)-1,'polls_url'=>$this->app_view_url('polls'),
            'flash'=>$this->poll_flash((int)$open[0]->id)]);
    }

    /** The thank-you or error after a vote, for the poll it is about. @return array{type:string,text:string}|null */
    private function poll_flash($poll_id){
        if(absint($_GET['poll_id']??($_GET['poll_voted']??0))!==(int)$poll_id)return null;
        if(isset($_GET['poll_voted']))return ['type'=>'success','text'=>'Thanks for voting!'];
        $code=sanitize_key($_GET['poll_error']??'');
        return isset(PollRules::ERRORS[$code])?['type'=>'error','text'=>PollRules::ERRORS[$code]]:null;
    }

    /** Employee app → Polls: open polls to answer and past polls with their results. */
    private function polls_content(){
        $emp=$this->current_employee();
        if(!$emp)return $this->ews_empty_state('Employee profile required','Your account is not linked to an active employee.');
        [$open,$past]=$this->employee_polls($emp);
        $card=function($p)use($emp){return $this->render_template('app/poll-card',['poll'=>$this->poll_card($p,$emp),'more'=>0,'polls_url'=>'','flash'=>$this->poll_flash((int)$p->id)]);};
        // A refused vote for a poll that is not listed (switched off, not for this employee…) is told at the top.
        $about=absint($_GET['poll_id']??0);$listed=array_map(function($p){return (int)$p->id;},array_merge($open,$past));
        $error=$about&&!in_array($about,$listed,true)?($this->poll_flash($about)['text']??''):'';
        return $this->render_template('app/polls',['open'=>array_map($card,$open),'past'=>array_map($card,$past),'error'=>$error]);
    }

    /** Tell the poll's audience that it is open (once). */
    private function poll_notify($poll){
        global $wpdb; $t=$this->polls_table_names();
        if(!empty($poll->notified_at)||$this->poll_state($poll)!=='open')return 0;
        $users=$poll->audience_department_id
            ?(array)$wpdb->get_col($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL AND wp_user_id>0 AND department_id=%d",(int)$poll->audience_department_id))
            :(array)$wpdb->get_col("SELECT wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL AND wp_user_id>0");
        $url=add_query_arg('ews_view','polls',$this->app_home_url());
        foreach($users as $uid){
            $this->notify_user((int)$uid,'New poll',(string)$poll->question,'poll','poll',(int)$poll->id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$uid,'New poll',(string)$poll->question,'poll',(int)$poll->id,$url);
        }
        $wpdb->update($t['polls'],['notified_at'=>$this->polls_now()],['id'=>(int)$poll->id],['%s'],['%d']);
        return count($users);
    }

    private function polls_admin_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-polls')));exit;
    }

    /** wp-admin → Employee Polls: create / edit a poll, and the list with results and participation. */
    public function admin_polls(){
        if(!$this->can('ews_manage_settings')) wp_die('Access denied.');
        global $wpdb; $this->ensure_polls_schema(); $this->ensure_departments_schema(); $t=$this->polls_table_names();
        $departments=(array)$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_departments WHERE active=1 ORDER BY name ASC");
        $dep_names=[];foreach($departments as $d)$dep_names[(int)$d->id]=$d->name;
        $polls=(array)$wpdb->get_results("SELECT * FROM {$t['polls']} ORDER BY id DESC");
        foreach($polls as $p){
            $p->state=$this->poll_state($p);$p->results=$this->poll_results((int)$p->id);
            $p->audience=(int)$p->audience_department_id?($dep_names[(int)$p->audience_department_id]??'Archived department'):'Everyone';
            $p->audience_count=$this->poll_audience_count($p);$p->participation=PollRules::participation((int)$p->results['total'],$p->audience_count);
            $p->links=[];
            foreach(['toggle','homepage','archive','export'] as $a)$p->links[$a]=wp_nonce_url(admin_url('admin-post.php?action=ews_poll_'.$a.'&poll_id='.(int)$p->id),'ews_poll_'.$a.'_'.$p->id,'ews_poll_nonce');
        }
        $edit_id=absint($_GET['poll_id']??0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$edit_id)):null;
        $choices=$edit?(array)$wpdb->get_col($wpdb->prepare("SELECT option_text FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order,id",$edit_id)):[];
        $code=sanitize_key($_GET['poll_error']??'');
        echo $this->render_template('admin/polls',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'polls'=>$polls,'edit'=>$edit,'choices'=>$choices?:['',''],'locked'=>$edit&&(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['votes']} WHERE poll_id=%d",$edit_id))>0,
            'departments'=>$departments,'visibility'=>PollRules::VISIBILITY,'states'=>PollRules::STATES,
            'notice'=>isset($_GET['poll_saved'])?'Poll saved successfully.'.(isset($_GET['notified'])?' '.absint($_GET['notified']).' employees were told it is open.':''):(isset($_GET['poll_status'])?'Poll visibility/status updated.':(isset($_GET['poll_deleted'])?'Poll archived. Its votes are kept and employees see it under past polls.':'')),
            'error'=>PollRules::ERRORS[$code]??'',
        ]);
    }

    public function poll_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_poll_save','ews_poll_nonce');
        global $wpdb; $this->ensure_polls_schema(); $t=$this->polls_table_names();
        $id=absint($_POST['poll_id']??0); $question=sanitize_text_field(wp_unslash($_POST['question']??'')); $description=sanitize_textarea_field(wp_unslash($_POST['description']??''));
        $choices=[];foreach((isset($_POST['options'])&&is_array($_POST['options'])?wp_unslash($_POST['options']):[]) as $v){$v=sanitize_text_field($v);if($v!=='')$choices[]=$v;}
        $date=function($k){$v=sanitize_text_field(wp_unslash($_POST[$k]??''));return $v!==''&&strtotime($v)?date('Y-m-d H:i:s',strtotime($v)):null;};
        $starts=$date('starts_at');$ends=$date('ends_at');
        $votes=$id?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['votes']} WHERE poll_id=%d",$id)):0;
        $locked=$votes>0?(array)$wpdb->get_col($wpdb->prepare("SELECT option_text FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order ASC,id ASC",$id)):null;
        $error=PollRules::saveError($question,$choices,$starts,$ends,$locked);
        if($error!=='')$this->polls_admin_redirect(['poll_error'=>$error]+($id?['poll_id'=>$id]:[]));
        $visibility=sanitize_key($_POST['results_visibility']??'after_vote');
        $department=absint($_POST['audience_department_id']??0);
        $data=['question'=>$question,'description'=>$description,'status'=>!empty($_POST['active'])?'active':'inactive','show_homepage'=>!empty($_POST['show_homepage'])?1:0,'starts_at'=>$starts,'ends_at'=>$ends,
            'audience_department_id'=>$department?:null,'results_visibility'=>isset(PollRules::VISIBILITY[$visibility])?$visibility:'after_vote',
            'allow_change'=>!empty($_POST['allow_change'])?1:0,'anonymous'=>!empty($_POST['anonymous'])?1:0,'updated_at'=>$this->polls_now()];
        $is_new=!$id;
        if($id){
            $current=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$t['polls']} WHERE id=%d",$id));
            if($current&&$current->status==='archived')$data['status']='archived'; // an archived poll stays archived
            $wpdb->update($t['polls'],$data,['id'=>$id]);
            if($votes===0){$wpdb->delete($t['options'],['poll_id'=>$id],['%d']);foreach($choices as $i=>$text)$wpdb->insert($t['options'],['poll_id'=>$id,'option_text'=>$text,'sort_order'=>$i],['%d','%s','%d']);}
        }else{
            $data['created_by']=get_current_user_id();$data['created_at']=$this->polls_now();$wpdb->insert($t['polls'],$data);$id=(int)$wpdb->insert_id;
            foreach($choices as $i=>$text)$wpdb->insert($t['options'],['poll_id'=>$id,'option_text'=>$text,'sort_order'=>$i],['%d','%s','%d']);
        }
        $this->audit('poll_saved','poll',$id,($is_new?'Created':'Updated').' employee poll: '.$question);
        $args=['poll_saved'=>1];
        if(!empty($_POST['notify'])){$notified=$this->poll_notify($wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$id)));if($notified)$args['notified']=$notified;}
        $this->polls_admin_redirect($args);
    }

    public function poll_toggle(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_toggle_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();
        $wpdb->query($wpdb->prepare("UPDATE {$t['polls']} SET status=CASE WHEN status='active' THEN 'inactive' ELSE 'active' END, updated_at=%s WHERE id=%d",$this->polls_now(),$id));$this->polls_admin_redirect(['poll_status'=>1]);
    }
    public function poll_homepage(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_homepage_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();
        $row=$wpdb->get_row($wpdb->prepare("SELECT show_homepage FROM {$t['polls']} WHERE id=%d",$id));$wpdb->update($t['polls'],['show_homepage'=>$row&&!empty($row->show_homepage)?0:1,'updated_at'=>$this->polls_now()],['id'=>$id],['%d','%s'],['%d']);$this->polls_admin_redirect(['poll_status'=>1]);
    }
    /** Archive: voting ends, votes are kept, and the poll's audience sees it under past polls. */
    public function poll_archive(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_archive_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();
        $wpdb->update($t['polls'],['status'=>'archived','show_homepage'=>0,'updated_at'=>$this->polls_now()],['id'=>$id],['%s','%d','%s'],['%d']);$this->audit('poll_archived','poll',$id);$this->polls_admin_redirect(['poll_deleted'=>1]);
    }

    /** CSV: votes per choice; for a named (not anonymous) poll also who voted for what. */
    public function poll_export(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_export_'.$id,'ews_poll_nonce');
        global $wpdb;$t=$this->polls_table_names();$poll=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$id));if(!$poll)wp_die('Poll not found.');
        $f=fopen('php://temp','w+');
        fputcsv($f,\WorkforceOne\Support\Csv::row([(string)$poll->question]));
        fputcsv($f,['Choice','Votes','Percentage']);
        $res=$this->poll_results($id);foreach($res['rows'] as $r)fputcsv($f,\WorkforceOne\Support\Csv::row([$r->option_text,(int)$r->votes,$r->percentage.'%']));
        if(!(int)$poll->anonymous){
            fputcsv($f,[]);fputcsv($f,['Employee','Employee ID','Choice','Voted at']);
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT e.name,e.domain_name,o.option_text,v.voted_at FROM {$t['votes']} v JOIN {$this->employees} e ON e.id=v.employee_id JOIN {$t['options']} o ON o.id=v.option_id WHERE v.poll_id=%d ORDER BY e.name ASC",$id)) as $v)fputcsv($f,\WorkforceOne\Support\Csv::row([$v->name,$v->domain_name,$v->option_text,$v->voted_at]));
        }
        rewind($f);$csv=stream_get_contents($f);
        $this->audit('poll_export','poll',$id,(int)$poll->anonymous?'counts':'counts and voters');
        nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename=poll_'.$id.'.csv');echo "\xEF\xBB\xBF".$csv;exit;
    }

    /** Back to the page the vote came from (Dashboard or Polls), with the outcome. */
    private function poll_vote_redirect($args){
        $ref=wp_get_referer();
        $path=$ref?(string)wp_parse_url($ref,PHP_URL_PATH):'';
        $target=($ref&&strpos($path,'/wp-admin/')===false&&basename($path)!=='admin-post.php')?remove_query_arg(['poll_voted','poll_error','poll_id'],$ref):add_query_arg('ews_view','polls',$this->app_home_url());
        wp_safe_redirect(add_query_arg($args,$target));exit;
    }

    public function poll_vote(){
        if(!is_user_logged_in())wp_die('You must be logged in to vote.');
        $id=absint($_POST['poll_id']??0);
        check_admin_referer('ews_poll_vote_'.$id,'ews_poll_nonce');
        $this->ensure_polls_schema();
        global $wpdb;$t=$this->polls_table_names();
        $emp=$this->current_employee();
        $poll=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$id));
        if(!$emp||!$poll)$this->poll_vote_redirect(['poll_error'=>'closed','poll_id'=>$id]);
        $mine=$this->poll_my_choice($id,(int)$emp->id);
        $error=PollRules::voteError($this->poll_state($poll),PollRules::inAudience($poll->audience_department_id?(int)$poll->audience_department_id:null,(int)$emp->department_id),$mine>0,(bool)(int)$poll->allow_change);
        $option=absint($_POST['option_id']??0);
        if($error===''&&!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['options']} WHERE id=%d AND poll_id=%d",$option,$id)))$error='choice';
        if($error!=='')$this->poll_vote_redirect(['poll_error'=>$error,'poll_id'=>$id]);
        if($mine){
            if($mine!==$option){$wpdb->update($t['votes'],['option_id'=>$option,'voted_at'=>$this->polls_now()],['poll_id'=>$id,'employee_id'=>(int)$emp->id],['%d','%s'],['%d','%d']);$this->audit('poll_vote_changed','poll',$id,$emp->name.' changed their vote in employee poll #'.$id);}
        }else{
            $ok=$wpdb->insert($t['votes'],['poll_id'=>$id,'option_id'=>$option,'employee_id'=>(int)$emp->id,'user_id'=>get_current_user_id(),'voted_at'=>$this->polls_now()],['%d','%d','%d','%d','%s']);
            if($ok!==false)$this->audit('poll_vote','poll',$id,$emp->name.' voted in employee poll #'.$id);
        }
        $this->poll_vote_redirect(['poll_voted'=>$id]);
    }
}
