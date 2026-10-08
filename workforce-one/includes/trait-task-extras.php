<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\TaskSettings;
use WorkforceOne\Support\Download;

/**
 * Tasks, more (3.31.70): the task details (checklist, comments with a file, who did what), team tasks
 * (one copy each, or the first one takes it), repeating tasks, a due time with a reminder, the
 * managers' Workload view, "My tasks today" on Home, and wp-admin → Tasks where each part is switched
 * on and tuned (src/Settings/TaskSettings.php, option ews_tasks_settings).
 * Files are kept outside the public uploads URL space (wp-content/uploads/workforce-one-tasks, with
 * deny rules) and sent through ews_task_file after checking that the user may see the task.
 */
trait EWS_Task_Extras_Trait {
    private static $ews_task_settings_cache = null;

    private function task_settings(){
        if(self::$ews_task_settings_cache===null)self::$ews_task_settings_cache=TaskSettings::config($this->option('ews_tasks_settings'));
        return self::$ews_task_settings_cache;
    }
    private function task_table(){ global $wpdb; return $wpdb->prefix.'ews_tasks'; }

    private function task_get($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT t.*,a.name assigned_name,a.wp_user_id assigned_wp_user_id,c.display_name creator_name FROM {$this->task_table()} t LEFT JOIN {$this->employees} a ON a.id=t.assigned_to LEFT JOIN {$wpdb->users} c ON c.ID=t.created_by WHERE t.id=%d LIMIT 1",(int)$id));
    }

    /** Team ids of the signed-in employee (for team tasks waiting to be taken). @return int[] */
    private function task_my_team_ids(){
        $emp=$this->current_employee();
        if(!$emp)return [];
        $ids=$this->team_ids_by_employee([(int)$emp->id]);
        return array_map('intval',$ids[(int)$emp->id]??[]);
    }

    private function task_is_assignee($t){
        return !empty($t->assigned_to) && (int)($t->assigned_wp_user_id??0)===get_current_user_id();
    }
    private function task_is_pool($t){ return empty($t->assigned_to) && !empty($t->team_id) && ($t->team_mode??'')==='first'; }

    /** May the signed-in user see this task (and comment on it)? */
    private function task_can_view($t){
        if(!$t || !empty($t->is_template))return false;
        if($this->task_can_manage_all())return true;
        $uid=get_current_user_id();
        if((int)$t->created_by===$uid || $this->task_is_assignee($t))return true;
        return $this->task_is_pool($t) && in_array((int)$t->team_id,$this->task_my_team_ids(),true);
    }
    /** May the signed-in user move it on (status, checklist ticks)? A team task must be taken first. */
    private function task_can_work($t){
        if(!$t || $this->task_is_pool($t))return false;
        return $this->task_can_manage_all() || $this->task_is_assignee($t) || (int)$t->created_by===get_current_user_id();
    }

    private function task_log($task_id,$action,$detail=''){
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'ews_task_activity',['task_id'=>(int)$task_id,'user_id'=>get_current_user_id(),'action'=>substr((string)$action,0,30),'detail'=>$detail!==''?mb_substr((string)$detail,0,255):null,'created_at'=>current_time('mysql')]);
    }

    /** WordPress user ids to tell about a task (creator and assignee, or a team's members), without $except. @return int[] */
    private function task_people($t,$except=0){
        global $wpdb;$out=[];
        if((int)$t->created_by)$out[]=(int)$t->created_by;
        if(!empty($t->assigned_to)){
            $u=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d",(int)$t->assigned_to));
            if($u)$out[]=$u;
        }elseif(!empty($t->team_id)){
            foreach($this->task_team_members((int)$t->team_id) as $m)$out[]=(int)$m->wp_user_id;
        }
        return array_values(array_diff(array_unique(array_filter($out)),[(int)$except]));
    }

    /** Active members of a team who can sign in. @return object[] id, name, wp_user_id */
    private function task_team_members($team_id){
        global $wpdb;$t=$this->team_tables();
        return (array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.name,e.wp_user_id FROM {$t['members']} m INNER JOIN {$this->employees} e ON e.id=m.employee_id WHERE m.team_id=%d AND m.active=1 AND e.active=1 AND e.wp_user_id>0 ORDER BY e.name",(int)$team_id));
    }
    /** @return object[] id, name, members */
    private function task_teams(){
        global $wpdb;$t=$this->team_tables();$this->ensure_teams_schema();
        return (array)$wpdb->get_results("SELECT tm.id,tm.name,(SELECT COUNT(*) FROM {$t['members']} m INNER JOIN {$this->employees} e ON e.id=m.employee_id WHERE m.team_id=tm.id AND m.active=1 AND e.active=1 AND e.wp_user_id>0) members FROM {$t['teams']} tm WHERE tm.active=1 ORDER BY tm.name");
    }

    private function task_back($task_id,$args=[]){
        $url=wp_get_referer();
        if(!$url || strpos($url,'admin-post.php')!==false)$url=add_query_arg('task',(int)$task_id,$this->app_view_url('tasks'));
        $url=remove_query_arg(['task_saved','task_updated','task_deleted','task_completed','task_error','task_note'],$url);
        wp_safe_redirect($args?add_query_arg($args,$url):$url);exit;
    }
    private function task_guard($nonce){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        if(!$this->tasks_enabled())wp_die(esc_html__('Tasks are currently disabled by your administrator.','workforce-one'));
        check_admin_referer($nonce);
        $this->ensure_tasks_schema();
    }

    /* ------------------------------------------------------------------ checklist */

    public function task_item_add(){
        $id=absint($_POST['task_id']??0);$this->task_guard('ews_task_item_'.$id);
        $t=$this->task_get($id);
        if(!$this->task_settings()['checklist'] || !$this->task_can_work($t))wp_die(esc_html__('Access denied','workforce-one'));
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
        if($title==='')$this->task_back($id);
        global $wpdb;$it=$wpdb->prefix.'ews_task_items';
        $pos=(int)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(position),0) FROM $it WHERE task_id=%d",$id));
        $wpdb->insert($it,['task_id'=>$id,'title'=>mb_substr($title,0,255),'done'=>0,'position'=>$pos+1]);
        $this->task_log($id,'item_add',$title);
        $this->task_back($id);
    }

    public function task_item_toggle(){
        $item_id=absint($_POST['item_id']??0);$this->task_guard('ews_task_item_toggle_'.$item_id);
        global $wpdb;$it=$wpdb->prefix.'ews_task_items';
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM $it WHERE id=%d",$item_id));
        $t=$item?$this->task_get((int)$item->task_id):null;
        if(!$item || !$this->task_settings()['checklist'] || !$this->task_can_work($t))wp_die(esc_html__('Access denied','workforce-one'));
        $done=$item->done?0:1;
        $wpdb->update($it,['done'=>$done,'done_by'=>$done?get_current_user_id():null,'done_at'=>$done?current_time('mysql'):null],['id'=>$item_id]);
        $this->task_log((int)$item->task_id,$done?'item_done':'item_undone',(string)$item->title);
        $this->task_back((int)$item->task_id);
    }

    public function task_item_delete(){
        $item_id=absint($_POST['item_id']??0);$this->task_guard('ews_task_item_delete_'.$item_id);
        global $wpdb;$it=$wpdb->prefix.'ews_task_items';
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM $it WHERE id=%d",$item_id));
        $t=$item?$this->task_get((int)$item->task_id):null;
        if(!$item || !$t || !($this->task_user_can_edit($t) || (int)$t->created_by===get_current_user_id()))wp_die(esc_html__('Access denied','workforce-one'));
        $wpdb->delete($it,['id'=>$item_id]);
        $this->task_log((int)$item->task_id,'item_delete',(string)$item->title);
        $this->task_back((int)$item->task_id);
    }

    /* ------------------------------------------------------------------ comments and files */

    private function task_file_dir(){
        $up=wp_upload_dir();$dir=trailingslashit($up['basedir']).'workforce-one-tasks';
        if(!is_dir($dir)){
            wp_mkdir_p($dir);
            // Not reachable by URL: every download goes through ews_task_file (access checked).
            @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/index.php',"<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/web.config','<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $dir;
    }

    /** MIME types for the allowed file types. @return array<string,string> ext => mime */
    private function task_file_mimes(){
        $all=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','doc'=>'application/msword',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','xls'=>'application/vnd.ms-excel',
            'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv','txt'=>'text/plain'];
        return array_intersect_key($all,array_flip($this->task_settings()['attach_types']));
    }

    public function task_comment(){
        $id=absint($_POST['task_id']??0);$this->task_guard('ews_task_comment_'.$id);
        $s=$this->task_settings();$t=$this->task_get($id);
        if(!$s['comments'] || !$this->task_can_view($t))wp_die(esc_html__('Access denied','workforce-one'));
        $body=trim(sanitize_textarea_field(wp_unslash($_POST['body']??'')));
        $file=['name'=>null,'key'=>null,'size'=>null];
        $f=$_FILES['file']??null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below
        if($s['attachments'] && is_array($f) && (int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            if((int)$f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']))$this->task_back($id,['task_error'=>'file']);
            if((int)$f['size']>$s['attach_max_mb']*1048576)$this->task_back($id,['task_error'=>'file_size']);
            $name=sanitize_file_name(wp_basename((string)$f['name']));
            $mimes=$this->task_file_mimes();
            $check=wp_check_filetype_and_ext((string)$f['tmp_name'],$name,$mimes);
            if(empty($check['ext']) || !isset($mimes[strtolower((string)$check['ext'])]))$this->task_back($id,['task_error'=>'file_type']);
            $key=wp_generate_password(32,false).'.'.strtolower((string)$check['ext']);
            if(!@move_uploaded_file((string)$f['tmp_name'],$this->task_file_dir().'/'.$key))$this->task_back($id,['task_error'=>'file']); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.ForbiddenFunctions.Found
            $file=['name'=>mb_substr($name,0,255),'key'=>$key,'size'=>(int)$f['size']];
        }
        if($body==='' && !$file['key'])$this->task_back($id);
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'ews_task_comments',['task_id'=>$id,'user_id'=>get_current_user_id(),'body'=>$body!==''?$body:null,'file_name'=>$file['name'],'file_key'=>$file['key'],'file_size'=>$file['size'],'created_at'=>current_time('mysql')]);
        $this->task_log($id,'comment',$file['name']?(string)$file['name']:'');
        if($s['notify_comments']){
            $who=wp_get_current_user()->display_name;
            foreach($this->task_people($t,get_current_user_id()) as $u)$this->task_notify_user($u,__('New comment on a task','workforce-one'),sprintf(/* translators: 1: person, 2: task title */__('%1$s commented on %2$s.','workforce-one'),esc_html($who),'<strong>'.esc_html($t->title).'</strong>'),$id);
        }
        $this->task_back($id);
    }

    /** A comment's file, for people who may see the task. */
    public function task_file(){
        if(!is_user_logged_in())auth_redirect();
        $cid=absint($_GET['comment']??0);check_admin_referer('ews_task_file_'.$cid);
        global $wpdb;$c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_task_comments WHERE id=%d",$cid));
        if(!$c || !$c->file_key || !$this->task_can_view($this->task_get((int)$c->task_id)))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        if(!preg_match('/^[A-Za-z0-9]{32}\.[a-z]{2,5}$/',(string)$c->file_key))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $path=$this->task_file_dir().'/'.$c->file_key;
        if(!is_readable($path))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $ext=strtolower((string)pathinfo((string)$c->file_key,PATHINFO_EXTENSION));
        $all=['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        Download::send((string)file_get_contents($path),$all[$ext]??'application/octet-stream',(string)$c->file_name); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /* ------------------------------------------------------------------ team tasks: take one */

    public function task_take(){
        $id=absint($_POST['task_id']??0);$this->task_guard('ews_task_take_'.$id);
        $t=$this->task_get($id);$emp=$this->current_employee();
        if(!$t || !$emp || !$this->task_is_pool($t) || !in_array((int)$t->team_id,$this->task_my_team_ids(),true))$this->task_back($id,['task_error'=>'taken']);
        global $wpdb;
        $ok=$wpdb->query($wpdb->prepare("UPDATE {$this->task_table()} SET assigned_to=%d,updated_at=%s WHERE id=%d AND assigned_to IS NULL",(int)$emp->id,current_time('mysql'),$id));
        if($ok!==1)$this->task_back($id,['task_error'=>'taken']);
        $this->task_log($id,'take',(string)$emp->name);
        if((int)$t->created_by && (int)$t->created_by!==get_current_user_id())$this->task_notify_user((int)$t->created_by,__('Task taken','workforce-one'),sprintf(/* translators: 1: person, 2: task title */__('%1$s took %2$s.','workforce-one'),esc_html($emp->name),'<strong>'.esc_html($t->title).'</strong>'),$id);
        $this->task_back($id,['task_updated'=>1]);
    }

    /* ------------------------------------------------------------------ repeating tasks */

    public function task_repeat_stop(){
        $id=absint($_POST['template_id']??0);$this->task_guard('ews_task_repeat_stop_'.$id);
        global $wpdb;$tpl=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->task_table()} WHERE id=%d AND is_template=1",$id));
        if(!$tpl || !($this->task_can_manage_all() || (int)$tpl->created_by===get_current_user_id()))wp_die(esc_html__('Access denied','workforce-one'));
        $wpdb->delete($this->task_table(),['id'=>$id]);
        $back=absint($_POST['task_id']??0);
        if($back)$this->task_log($back,'repeat_stop',(string)$tpl->repeat_rule);
        $this->task_back($back,['task_updated'=>1]);
    }

    /** Create the task(s) of one date from a template: one for a person, one copy per team member, or one for the team to take. @return int[] new ids */
    private function task_from_template($tpl,$date){
        global $wpdb;$now=current_time('mysql');$ids=[];
        $row=['title'=>$tpl->title,'description'=>$tpl->description,'status'=>'todo','priority'=>$tpl->priority,'due_date'=>$date,'due_time'=>$tpl->due_time,
            'remind_minutes'=>$tpl->remind_minutes,'created_by'=>(int)$tpl->created_by,'created_at'=>$now,'updated_at'=>$now,'repeat_of'=>!empty($tpl->id)?(int)$tpl->id:null,'is_template'=>0,
            'team_id'=>$tpl->team_id,'team_mode'=>$tpl->team_mode];
        if(!empty($tpl->team_id) && $tpl->team_mode==='each'){
            $batch=wp_generate_password(16,false);
            foreach($this->task_team_members((int)$tpl->team_id) as $m){ if($wpdb->insert($this->task_table(),$row+['assigned_to'=>(int)$m->id,'batch'=>$batch]))$ids[]=(int)$wpdb->insert_id; }
        }else{
            if($wpdb->insert($this->task_table(),$row+['assigned_to'=>!empty($tpl->team_id)?null:($tpl->assigned_to?:null)]))$ids[]=(int)$wpdb->insert_id;
        }
        return $ids;
    }

    /** Due repeating tasks: one set for the latest date that has come (missed days are not made up). @return int tasks created */
    private function task_generate_repeats(){
        global $wpdb;$today=current_time('Y-m-d');$made=0;
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->task_table()} WHERE is_template=1 AND repeat_next IS NOT NULL AND repeat_next<=%s ORDER BY id LIMIT 50",$today));
        foreach($rows as $tpl){
            $date=(string)$tpl->repeat_next;
            while(($n=TaskSettings::nextDate((string)$tpl->repeat_rule,$date))!==null && $n<=$today)$date=$n;
            $next=TaskSettings::nextDate((string)$tpl->repeat_rule,$date);
            // Claim this date first, so two runs at once never make it twice.
            $claimed=$wpdb->query($wpdb->prepare("UPDATE {$this->task_table()} SET repeat_next=%s WHERE id=%d AND repeat_next=%s",$next,(int)$tpl->id,(string)$tpl->repeat_next));
            if($claimed!==1)continue;
            foreach($this->task_from_template($tpl,$date) as $new){$made++;$this->task_new_notify($this->task_get($new));}
        }
        return $made;
    }

    /** Tell the assignee (or the team) about a new task. */
    private function task_new_notify($t){
        if(!$t)return;
        $users=[];
        if(!empty($t->assigned_wp_user_id))$users[]=(int)$t->assigned_wp_user_id;
        elseif($this->task_is_pool($t))foreach($this->task_team_members((int)$t->team_id) as $m)$users[]=(int)$m->wp_user_id;
        $msg=$this->task_is_pool($t)
            ?sprintf(/* translators: %s: task title */__('A task for your team is waiting to be taken: %s.','workforce-one'),'<strong>'.esc_html($t->title).'</strong>')
            :sprintf(/* translators: %s: task title */__('You have been assigned the task: %s.','workforce-one'),'<strong>'.esc_html($t->title).'</strong>');
        foreach(array_unique($users) as $u)$this->task_notify_user($u,__('New Task','workforce-one'),$msg,(int)$t->id);
    }

    /* ------------------------------------------------------------------ reminders and the 5-minute run */

    /** Reminders before the due time. @return int reminders sent */
    private function task_send_reminders(){
        global $wpdb;$sent=0;$now=current_time('timestamp');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT id,due_date,due_time,remind_minutes FROM {$this->task_table()} WHERE is_template=0 AND status<>'completed' AND due_date IS NOT NULL AND due_time IS NOT NULL AND remind_minutes IS NOT NULL AND reminded_at IS NULL AND due_date<=%s LIMIT 200",gmdate('Y-m-d',$now+86400*2)));
        foreach($rows as $r){
            $due=strtotime($r->due_date.' '.$r->due_time.' UTC');
            if($due===false || $due-((int)$r->remind_minutes*60)>$now)continue;
            if($wpdb->query($wpdb->prepare("UPDATE {$this->task_table()} SET reminded_at=%s WHERE id=%d AND reminded_at IS NULL",current_time('mysql'),(int)$r->id))!==1)continue;
            $t=$this->task_get((int)$r->id);if(!$t)continue;
            $when=date_i18n('D d M · H:i',$due);
            $users=!empty($t->assigned_wp_user_id)?[(int)$t->assigned_wp_user_id]:($this->task_is_pool($t)?array_map(static function($m){return (int)$m->wp_user_id;},$this->task_team_members((int)$t->team_id)):[(int)$t->created_by]);
            foreach(array_unique(array_filter($users)) as $u){
                $this->notify($u,'task',__('Task reminder','workforce-one'),sprintf(/* translators: 1: task title, 2: due date and time */__('%1$s is due %2$s.','workforce-one'),'<strong>'.esc_html($t->title).'</strong>',esc_html($when)),['type'=>'warning','entity_id'=>(int)$t->id,'url'=>add_query_arg(['ews_view'=>'tasks','task'=>(int)$t->id],$this->app_home_url())]);
                $sent++;
            }
        }
        return $sent;
    }

    /** The scheduled run (every 5 minutes), also started by a page view at most once a minute. */
    public function tasks_tick(){
        if(!$this->tasks_enabled())return;
        $this->ensure_tasks_schema();
        $s=$this->task_settings();
        if($s['repeat'])$this->task_generate_repeats();
        if($s['reminders'])$this->task_send_reminders();
    }
    private function tasks_tick_maybe(){
        if(get_transient('ews_tasks_tick_lock'))return;
        set_transient('ews_tasks_tick_lock',1,60);
        $this->tasks_tick();
    }

    /* ------------------------------------------------------------------ the details sheet */

    /** Everything the task details sheet shows. @return array<string,mixed>|null */
    private function task_details($id){
        $t=$this->task_get($id);
        if(!$this->task_can_view($t))return null;
        global $wpdb;$s=$this->task_settings();
        $items=$s['checklist']?(array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_task_items WHERE task_id=%d ORDER BY position,id",$id)):[];
        $comments=$s['comments']?(array)$wpdb->get_results($wpdb->prepare("SELECT c.*,u.display_name FROM {$wpdb->prefix}ews_task_comments c LEFT JOIN {$wpdb->users} u ON u.ID=c.user_id WHERE c.task_id=%d ORDER BY c.id",$id)):[];
        foreach($comments as $c)if($c->file_key)$c->file_url=wp_nonce_url(add_query_arg(['action'=>'ews_task_file','comment'=>(int)$c->id],admin_url('admin-post.php')),'ews_task_file_'.(int)$c->id);
        $activity=$s['activity']?(array)$wpdb->get_results($wpdb->prepare("SELECT a.*,u.display_name FROM {$wpdb->prefix}ews_task_activity a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.task_id=%d ORDER BY a.id DESC LIMIT 20",$id)):[];
        $tpl=!empty($t->repeat_of)?$wpdb->get_row($wpdb->prepare("SELECT id,repeat_rule,created_by FROM {$this->task_table()} WHERE id=%d AND is_template=1",(int)$t->repeat_of)):null;
        $team=!empty($t->team_id)?(string)$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->team_tables()['teams']} WHERE id=%d",(int)$t->team_id)):'';
        return ['task'=>$t,'items'=>$items,'comments'=>$comments,'activity'=>$activity,'template'=>$tpl,'team'=>$team,
            'can_work'=>$this->task_can_work($t),'can_edit'=>$this->task_user_can_edit($t),'can_take'=>$this->task_is_pool($t) && in_array((int)$t->team_id,$this->task_my_team_ids(),true),
            'can_stop'=>$tpl && ($this->task_can_manage_all() || (int)$tpl->created_by===get_current_user_id()),'is_pool'=>$this->task_is_pool($t)];
    }

    /* ------------------------------------------------------------------ Workload (managers) */

    private function task_workload_allowed(){
        $s=$this->task_settings();
        if(!$s['workload'])return false;
        return $s['workload_who']==='admins'?current_user_can('manage_options'):$this->task_can_manage_all();
    }

    /** Per person: open, in progress, overdue, done this month, on time %. @return array<string,mixed> */
    private function task_workload(){
        global $wpdb;$today=current_time('Y-m-d');$month=current_time('Y-m').'-01';
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.name,
            COALESCE(SUM(t.status='todo'),0) todo,COALESCE(SUM(t.status='in_progress'),0) progress,
            COALESCE(SUM(t.status<>'completed' AND t.due_date<%s),0) late,
            COALESCE(SUM(t.status='completed' AND t.completed_at>=%s),0) done,
            COALESCE(SUM(t.status='completed' AND t.completed_at>=%s AND t.due_date IS NOT NULL),0) due_done,
            COALESCE(SUM(t.status='completed' AND t.completed_at>=%s AND t.due_date IS NOT NULL AND DATE(t.completed_at)<=t.due_date),0) on_time
            FROM {$this->employees} e LEFT JOIN {$this->task_table()} t ON t.assigned_to=e.id AND t.is_template=0
            WHERE e.active=1 AND e.wp_user_id>0 GROUP BY e.id,e.name ORDER BY e.name",$today,$month,$month,$month));
        $tot=['open'=>0,'late'=>0,'done'=>0,'due_done'=>0,'on_time'=>0,'max'=>1];
        foreach($rows as $r){
            foreach(['todo','progress','late','done','due_done','on_time'] as $k)$r->$k=(int)$r->$k;
            $r->open=$r->todo+$r->progress;
            $tot['open']+=$r->open;$tot['late']+=$r->late;$tot['done']+=$r->done;$tot['due_done']+=$r->due_done;$tot['on_time']+=$r->on_time;$tot['max']=max($tot['max'],$r->open);
        }
        $pool=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->task_table()} WHERE is_template=0 AND assigned_to IS NULL AND team_id IS NOT NULL AND status<>'completed'");
        return ['rows'=>$rows,'total'=>$tot,'on_time'=>$tot['due_done']?(int)round(100*$tot['on_time']/$tot['due_done']):null,'pool'=>$pool];
    }

    /* ------------------------------------------------------------------ Home: My tasks today */

    /** Open tasks for Home: overdue, due today and in progress, first. @return object[] */
    private function task_home_list(){
        if(!$this->tasks_enabled())return [];
        $s=$this->task_settings();
        if(!$s['home_card'])return [];
        $this->ensure_tasks_schema();$this->tasks_tick_maybe();
        global $wpdb;$uid=get_current_user_id();$today=current_time('Y-m-d');
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT t.*,a.wp_user_id assigned_wp_user_id FROM {$this->task_table()} t LEFT JOIN {$this->employees} a ON a.id=t.assigned_to
            WHERE t.is_template=0 AND t.status<>'completed' AND (a.wp_user_id=%d OR (t.assigned_to IS NULL AND t.team_id IS NULL AND t.created_by=%d))
              AND (t.status='in_progress' OR (t.due_date IS NOT NULL AND t.due_date<=%s))
            ORDER BY (t.due_date IS NULL), t.due_date, t.due_time IS NULL, t.due_time, CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END LIMIT %d",$uid,$uid,$today,(int)$s['home_count']));
        foreach($rows as $r){$r->is_overdue=$r->due_date && $r->due_date<$today;$r->url=add_query_arg('task',(int)$r->id,$this->app_view_url('tasks'));}
        return $rows;
    }

    /* ------------------------------------------------------------------ wp-admin → Tasks */

    public function admin_tasks_settings(){
        if(!$this->can('ews_manage_settings'))wp_die(esc_html__('Access denied','workforce-one'));
        echo $this->render_template('admin/tasks',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'cfg'=>$this->task_settings(),'enabled'=>$this->tasks_enabled(),'saved'=>isset($_GET['tasks_saved']),
            'features_url'=>admin_url('admin.php?page=ews31-features'),'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function tasks_settings_save(){
        if(!$this->can('ews_manage_settings'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_tasks_settings_save');
        update_option('ews_tasks_settings',TaskSettings::fromPost(wp_unslash($_POST)),false);
        self::$ews_task_settings_cache=null;
        $this->audit('tasks_settings_update','settings',0,'Updated Tasks settings.');
        wp_safe_redirect(admin_url('admin.php?page=ews31-tasks&tasks_saved=1'));exit;
    }
}
