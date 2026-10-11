<?php
if (!defined('ABSPATH')) exit;

trait EWS_Tasks_Trait {
    private static $ews_tasks_schema_ready = false;
    private function tasks_enabled(){ return (bool)$this->option('ews_feature_tasks'); }

    private function ensure_tasks_schema(){
        if($this->ews_schema_is_current())return;
        if(self::$ews_tasks_schema_ready)return;
        global $wpdb;
        $table=$wpdb->prefix.'ews_tasks';
        $c=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'todo',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            due_date DATE NULL,
            assigned_to BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            due_time TIME NULL,
            remind_minutes SMALLINT UNSIGNED NULL,
            reminded_at DATETIME NULL,
            team_id BIGINT UNSIGNED NULL,
            team_mode VARCHAR(10) NULL,
            batch VARCHAR(32) NULL,
            is_template TINYINT(1) NOT NULL DEFAULT 0,
            repeat_rule VARCHAR(40) NULL,
            repeat_next DATE NULL,
            repeat_of BIGINT UNSIGNED NULL,
            PRIMARY KEY  (id),
            KEY assigned_to (assigned_to),
            KEY created_by (created_by),
            KEY status (status),
            KEY priority (priority),
            KEY due_date (due_date),
            KEY team_id (team_id),
            KEY is_template (is_template,repeat_next),
            KEY repeat_of (repeat_of)
        ) $c;");
        // 3.31.70: steps inside a task, comments (with a file) and who did what.
        dbDelta("CREATE TABLE {$wpdb->prefix}ews_task_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(255) NOT NULL,
            done TINYINT(1) NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            done_by BIGINT UNSIGNED NULL,
            done_at DATETIME NULL,
            PRIMARY KEY  (id),
            KEY task_id (task_id)
        ) $c;");
        dbDelta("CREATE TABLE {$wpdb->prefix}ews_task_comments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            body TEXT NULL,
            file_name VARCHAR(255) NULL,
            file_key VARCHAR(80) NULL,
            file_size INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY task_id (task_id)
        ) $c;");
        dbDelta("CREATE TABLE {$wpdb->prefix}ews_task_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            task_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(30) NOT NULL,
            detail VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY task_id (task_id)
        ) $c;");
        self::$ews_tasks_schema_ready=true;
    }

    private function tasks_redirect($args=[]){
        $url=wp_get_referer();
        if(!$url || strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
        $url=remove_query_arg(['task_saved','task_updated','task_deleted','task_completed','task_error'],$url);
        if($args)$url=add_query_arg($args,$url);
        wp_safe_redirect($url); exit;
    }

    private function task_can_manage_all(){ return $this->can('ews_manage_tasks'); }

    private function task_user_can_edit($task){
        $uid=get_current_user_id();
        if($this->task_can_manage_all())return true;
        return (int)$task->created_by===$uid && empty($task->assigned_to);
    }

    private function task_user_can_delete($task){ return $this->task_can_manage_all() || ((int)$task->created_by===get_current_user_id() && empty($task->assigned_to)); }

    private function task_allowed_statuses(){ return ['todo','in_progress','completed']; }
    private function task_allowed_priorities(){ return ['low','normal','high','urgent']; }

    private function tasks_employees(){
        global $wpdb;
        return $wpdb->get_results("SELECT id,name,wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id>0 ORDER BY name");
    }

    private function task_notify_user($user_id,$title,$message,$task_id){
        $user_id=absint($user_id);
        if(!$user_id || $user_id===get_current_user_id()) return;
        $this->notify($user_id,'task',$title,$message,['type'=>'info','entity_id'=>$task_id,'url'=>add_query_arg('ews_view','tasks',$this->app_home_url())]);
    }

    public function task_save(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        if(!$this->tasks_enabled())wp_die(esc_html__('Tasks are currently disabled by your administrator.','workforce-one'));
        check_admin_referer('ews_task_save');
        $this->ensure_tasks_schema();
        $cfg=$this->task_settings();$manage=$this->task_can_manage_all();
        $id=absint($_POST['task_id']??0);
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
        $description=trim(sanitize_textarea_field(wp_unslash($_POST['description']??'')));
        $priority=sanitize_key($_POST['priority']??'normal');
        $due=trim(sanitize_text_field(wp_unslash($_POST['due_date']??'')));
        $assigned=absint($_POST['assigned_to']??0);
        $time=trim(sanitize_text_field(wp_unslash($_POST['due_time']??'')));
        $remind=isset($_POST['remind_minutes'])&&$_POST['remind_minutes']!==''?(int)$_POST['remind_minutes']:-1;
        $kind=sanitize_key($_POST['assign_kind']??'');
        $team_id=absint($_POST['team_id']??0);
        $team_mode=in_array($_POST['team_mode']??'',\WorkforceOne\Settings\TaskSettings::TEAM_MODES,true)?(string)$_POST['team_mode']:$cfg['team_mode'];
        if($title==='')$this->tasks_redirect(['task_error'=>'title']);
        if(!$id && !$manage && !$cfg['personal'])$this->tasks_redirect(['task_error'=>'personal']);
        if(!in_array($priority,$this->task_allowed_priorities(),true))$priority='normal';
        if($due!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))$due='';
        $time=($cfg['due_time'] && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$time))?$time.':00':null;
        $remind=($cfg['reminders'] && $time && in_array($remind,\WorkforceOne\Settings\TaskSettings::REMIND_CHOICES,true) && $remind>0)?$remind:null;
        if(!$manage){$assigned=0;$kind='';}
        if($kind==='team' && (!$cfg['teams'] || !$team_id))$kind='';
        if($kind!=='person')$assigned=$kind===''?$assigned:0;
        if($assigned){
            global $wpdb;
            $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE id=%d AND active=1 AND wp_user_id>0",$assigned));
            if(!$valid)$assigned=0;
        }
        global $wpdb;$table=$wpdb->prefix.'ews_tasks';$now=current_time('mysql');
        if($id){
            $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND is_template=0 LIMIT 1",$id));
            if(!$task || !$this->task_user_can_edit($task))wp_die(esc_html__('Access denied','workforce-one'));
            $old_assigned=(int)($task->assigned_to??0);
            // The edit form does not change workflow status or the team. Preserve them for every role.
            $status=in_array($task->status,$this->task_allowed_statuses(),true)?$task->status:'todo';
            $completed=($status==='completed')?($task->completed_at?:$now):null;
            $row=['title'=>$title,'description'=>$description,'status'=>$status,'priority'=>$priority,'due_date'=>$due?:null,'assigned_to'=>$assigned?:null,'updated_at'=>$now,'completed_at'=>$completed,'due_time'=>$time,'remind_minutes'=>$remind];
            // A new due time or reminder: remind again.
            if((string)$task->due_date!==(string)($due?:'') || (string)$task->due_time!==(string)$time || (string)$task->remind_minutes!==(string)$remind)$row['reminded_at']=null;
            if(empty($task->assigned_to) && !empty($task->team_id) && !$assigned)unset($row['assigned_to']);
            $ok=$wpdb->update($table,$row,['id'=>$id]);
            if($ok===false)$this->tasks_redirect(['task_error'=>'save']);
            $this->audit('task_update','task',$id,$title);
            $changes=[];
            if((string)$task->title!==$title)$changes[]='title';
            if((string)$task->priority!==$priority)$changes[]='priority';
            if((string)$task->due_date!==(string)($due?:'') || (string)$task->due_time!==(string)$time)$changes[]='due';
            if($old_assigned!==$assigned && $assigned)$changes[]='assignee';
            if((string)$task->description!==$description)$changes[]='description';
            $this->task_log($id,'edit',implode(',',$changes));
            if($assigned && $assigned!==$old_assigned)$this->task_new_notify($this->task_get($id));
            $this->tasks_redirect(['task_updated'=>1]);
        }
        $base=['title'=>$title,'description'=>$description,'status'=>'todo','priority'=>$priority,'due_date'=>$due?:null,'due_time'=>$time,'remind_minutes'=>$remind,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'is_template'=>0];
        $rule=$cfg['repeat']?\WorkforceOne\Settings\TaskSettings::ruleFromPost(wp_unslash($_POST)):'';
        if($rule!=='' && !$due)$base['due_date']=$due=current_time('Y-m-d');
        $tpl=null;
        if($rule!==''){
            // The repeat lives on a hidden template; each date gets its own task (task_generate_repeats()).
            $wpdb->insert($table,array_merge($base,['is_template'=>1,'repeat_rule'=>$rule,'repeat_next'=>\WorkforceOne\Settings\TaskSettings::nextDate($rule,$due),'assigned_to'=>$kind==='team'?null:($assigned?:null),'team_id'=>$kind==='team'?$team_id:null,'team_mode'=>$kind==='team'?$team_mode:null]));
            $tpl=(int)$wpdb->insert_id;
        }
        $ids=[];
        if($kind==='team'){
            $tobj=(object)($base+['id'=>$tpl,'team_id'=>$team_id,'team_mode'=>$team_mode,'assigned_to'=>null]);
            foreach($this->task_from_template($tobj,$base['due_date']) as $new)$ids[]=$new;
        }else{
            $ok=$wpdb->insert($table,$base+['assigned_to'=>$assigned?:null,'repeat_of'=>$tpl]);
            if($ok===false)$this->tasks_redirect(['task_error'=>'save']);
            $ids[]=(int)$wpdb->insert_id;
        }
        if(!$ids)$this->tasks_redirect(['task_error'=>'team_empty']);
        foreach($ids as $new){
            $this->audit('task_create','task',$new,$title);
            $this->task_log($new,'create',$rule);
            $this->task_new_notify($this->task_get($new));
        }
        $this->tasks_redirect(['task_saved'=>count($ids)]);
    }

    public function task_status_update(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        if(!$this->tasks_enabled())wp_die('Tasks are disabled.');
        $id=absint($_POST['task_id']??0);check_admin_referer('ews_task_status_'.$id);
        global $wpdb;$table=$wpdb->prefix.'ews_tasks';
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id));
        if(!$task)wp_die('Task not found.');
        $uid=get_current_user_id();
        $is_assignee=false;
        if(!empty($task->assigned_to))$is_assignee=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d LIMIT 1",(int)$task->assigned_to))===$uid;
        if(!$this->task_can_manage_all() && !$is_assignee && (int)$task->created_by!==$uid)wp_die('Access denied');
        if(empty($task->assigned_to) && !empty($task->team_id) && ($task->team_mode??'')==='first')$this->tasks_redirect(['task_error'=>'take']);
        $status=sanitize_key($_POST['status']??'todo');
        if(!in_array($status,$this->task_allowed_statuses(),true))wp_die('Invalid status.');
        $allowed_next=['todo'=>['in_progress'],'in_progress'=>['todo','completed'],'completed'=>['in_progress']];
        if($status!==$task->status && !in_array($status,$allowed_next[$task->status]??[],true)){$this->tasks_redirect(['task_error'=>'transition']);}
        $now=current_time('mysql');
        $ok=$wpdb->update($table,['status'=>$status,'updated_at'=>$now,'completed_at'=>$status==='completed'?($task->completed_at?:$now):null],['id'=>$id],['%s','%s','%s'],['%d']);
        if($ok===false)$this->tasks_redirect(['task_error'=>'status']);
        $this->audit('task_status','task',$id,$status);
        $this->task_log($id,'status',$status);

        // Keep the task owner/creator informed when someone else changes workflow status.
        $creator=(int)$task->created_by;
        if($creator && $creator!==$uid && $this->task_settings()['notify_status']){
            $this->task_notify_user($creator,__('Task Status Updated','workforce-one'),sprintf(/* translators: 1: task title, 2: status */__('%1$s is now %2$s.','workforce-one'),'<strong>'.esc_html($task->title).'</strong>','<strong>'.esc_html($this->task_display_status($status)).'</strong>'),$id);
        }
        $this->tasks_redirect(['task_completed'=>$status==='completed'?1:0,'task_updated'=>$status!=='completed'?1:0]);
    }

    public function task_delete(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        if(!$this->tasks_enabled())wp_die('Tasks are disabled.');
        $id=absint($_POST['task_id']??0);check_admin_referer('ews_task_delete_'.$id);
        global $wpdb;$table=$wpdb->prefix.'ews_tasks';
        $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id));
        if(!$task || !$this->task_user_can_delete($task))wp_die('Access denied');
        $ok=$wpdb->delete($table,['id'=>$id],['%d']);
        if($ok===false)$this->tasks_redirect(['task_error'=>'delete']);
        $this->audit('task_delete','task',$id,$task->title);
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT file_key FROM {$wpdb->prefix}ews_task_comments WHERE task_id=%d AND file_key IS NOT NULL",$id)) as $key){
            if(preg_match('/^[A-Za-z0-9]{32}\.[a-z]{2,5}$/',(string)$key))wp_delete_file($this->task_file_dir().'/'.$key);
        }
        foreach(['ews_task_items','ews_task_comments','ews_task_activity'] as $x)$wpdb->delete($wpdb->prefix.$x,['task_id'=>$id],['%d']);
        $this->tasks_redirect(['task_deleted'=>1]);
    }

    private function task_display_status($status){ return ['todo'=>__('To Do','workforce-one'),'in_progress'=>__('In Progress','workforce-one'),'completed'=>__('Completed','workforce-one')][$status]??ucwords(str_replace('_',' ',$status)); }
    private function task_display_priority($priority){ return ['urgent'=>__('Urgent','workforce-one'),'high'=>__('High','workforce-one'),'normal'=>__('Normal','workforce-one'),'low'=>__('Low','workforce-one')][$priority]??ucfirst($priority); }

    public function tasks_content(){
        if(!$this->tasks_enabled()) return '<div class="ews-card"><h3>'.esc_html__('Tasks','workforce-one').'</h3><p>'.esc_html__('Tasks are currently disabled by your administrator.','workforce-one').'</p></div>';
        $this->ensure_tasks_schema();
        $this->tasks_tick_maybe();
        $cfg=$this->task_settings();

        global $wpdb;
        $table=$wpdb->prefix.'ews_tasks';
        $uid=get_current_user_id();
        $manage=$this->task_can_manage_all();
        $tab=sanitize_key($_GET['task_tab']??'my');
        if($tab==='workload' && !$this->task_workload_allowed())$tab='my';
        if(!$manage && $tab!=='workload') $tab='my';
        $my_teams=$this->task_my_team_ids();
        $team_in=$my_teams?implode(',',array_map('intval',$my_teams)):'0';

        $status_filter=sanitize_key($_GET['task_status']??'all');
        if($status_filter!=='all' && !in_array($status_filter,$this->task_allowed_statuses(),true)) $status_filter='all';
        $priority_filter=sanitize_key($_GET['task_priority']??'all');
        if($priority_filter!=='all' && !in_array($priority_filter,$this->task_allowed_priorities(),true)) $priority_filter='all';

        // My tasks: mine, assigned to me, and my teams' tasks waiting to be taken. Team: everyone's.
        if($manage && $tab==='team'){
            $where=" AND t.is_template=0 AND (t.assigned_to IN (SELECT id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL) OR (t.assigned_to IS NULL AND t.team_id IS NOT NULL))";
            $args=[];
        } else {
            $where=" AND t.is_template=0 AND (t.created_by=%d OR a.wp_user_id=%d OR (t.assigned_to IS NULL AND t.team_mode='first' AND t.team_id IN ($team_in)))";
            $args=[$uid,$uid];
        }
        if($status_filter!=='all'){ $where.=' AND t.status=%s'; $args[]=$status_filter; }
        if($priority_filter!=='all'){ $where.=' AND t.priority=%s'; $args[]=$priority_filter; }

        $sql="SELECT t.*,a.name assigned_name,a.wp_user_id assigned_wp_user_id,c.display_name creator_name,tm.name team_name
              FROM $table t
              LEFT JOIN {$this->employees} a ON a.id=t.assigned_to
              LEFT JOIN {$wpdb->users} c ON c.ID=t.created_by
              LEFT JOIN {$wpdb->prefix}ews_teams tm ON tm.id=t.team_id
              WHERE 1=1 $where
              ORDER BY CASE t.status WHEN 'in_progress' THEN 1 WHEN 'todo' THEN 2 ELSE 3 END,
                       CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END,
                       (t.due_date IS NULL), t.due_date ASC, t.created_at DESC";
        $rows=$args ? $wpdb->get_results($wpdb->prepare($sql,...$args)) : $wpdb->get_results($sql);

        // Dashboard counters use the same visibility rules as the list.
        $counter_where=($manage&&$tab==='team')
            ? " AND t.is_template=0 AND (t.assigned_to IN (SELECT id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL) OR (t.assigned_to IS NULL AND t.team_id IS NOT NULL))"
            : " AND t.is_template=0 AND (t.created_by=%d OR a.wp_user_id=%d OR (t.assigned_to IS NULL AND t.team_mode='first' AND t.team_id IN ($team_in)))";
        $counter_args=($manage&&$tab==='team')?[]:[$uid,$uid];
        $counter_sql="SELECT
            COALESCE(SUM(CASE WHEN t.status='todo' THEN 1 ELSE 0 END),0) todo_count,
            COALESCE(SUM(CASE WHEN t.status='in_progress' THEN 1 ELSE 0 END),0) progress_count,
            COALESCE(SUM(CASE WHEN t.status='completed' THEN 1 ELSE 0 END),0) completed_count,
            COALESCE(SUM(CASE WHEN t.status<>'completed' AND t.due_date IS NOT NULL AND t.due_date < %s THEN 1 ELSE 0 END),0) overdue_count
            FROM $table t LEFT JOIN {$this->employees} a ON a.id=t.assigned_to WHERE 1=1 $counter_where";
        // The due-date placeholder appears in SELECT before the visibility placeholders in WHERE.
        // Keep arguments in the exact placeholder order; otherwise MySQL compares user IDs to dates.
        $counter_args = array_merge([current_time('Y-m-d')], $counter_args);
        $counter=$wpdb->get_row($wpdb->prepare($counter_sql,...$counter_args));

        $employees=$manage?$this->tasks_employees():[];
        $edit_id=absint($_GET['edit_task']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d AND is_template=0 LIMIT 1",$edit_id)):null;
        if($edit && !$this->task_user_can_edit($edit)) $edit=null;

        $base=$this->app_view_url('tasks');
        $filter_url=function($status,$priority) use ($base,$tab){
            // app_view_url() intentionally preserves unknown query args, so strip
            // Task filters first. Otherwise clicking "All" while already filtered
            // keeps the previous task_status/task_priority in the URL.
            $url=remove_query_arg(['task_tab','task_status','task_priority','edit_task','task'],$base);
            $args=['task_tab'=>$tab];
            if($status!=='all') $args['task_status']=$status;
            if($priority!=='all') $args['task_priority']=$priority;
            return add_query_arg($args,$url);
        };
        $tab_url=function($target_tab) use ($base){
            $url=remove_query_arg(['task_tab','task_status','task_priority','edit_task','task'],$base);
            return add_query_arg('task_tab',$target_tab,$url);
        };

        $uid=(int)$uid;$today=current_time('Y-m-d');
        $cards=['todo'=>[],'in_progress'=>[],'completed'=>[]];
        foreach((array)$rows as $t){
            $t->is_overdue=!empty($t->due_date) && $t->status!=='completed' && $t->due_date<$today;
            $is_assignee=!empty($t->assigned_wp_user_id) && (int)$t->assigned_wp_user_id===$uid;
            $t->is_pool=$this->task_is_pool($t);
            $t->can_take=$t->is_pool && in_array((int)$t->team_id,$my_teams,true);
            $t->can_status=!$t->is_pool && ($manage||$is_assignee||(int)$t->created_by===$uid);
            $t->status_nonce=$t->can_status?wp_create_nonce('ews_task_status_'.(int)$t->id):'';
            $t->view_url=add_query_arg(['task'=>(int)$t->id,'task_tab'=>$tab],remove_query_arg(['edit_task','task'],$base));
            $t->can_edit=$this->task_user_can_edit($t);
            $t->can_delete=$this->task_user_can_delete($t);
            $t->edit_url=add_query_arg(['edit_task'=>(int)$t->id,'task_tab'=>$tab],$base);
            $cards[isset($cards[$t->status])?$t->status:'todo'][]=$t;
        }
        // Done: the most recently finished first; a long list is cut (Show all).
        usort($cards['completed'],static function($a,$b){return strcmp((string)$b->completed_at,(string)$a->completed_at);});
        $done_all=isset($_GET['task_done']);
        $done_total=count($cards['completed']);
        if(!$done_all)$cards['completed']=array_slice($cards['completed'],0,(int)$cfg['done_limit']);
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-tasks');
        wp_enqueue_style('workforce-one-requests');
        $detail_id=absint($_GET['task']??0);
        $detail=$detail_id?$this->task_details($detail_id):null;
        // Counts per item for the cards (checklist progress, comments).
        $ids=[];foreach($cards as $list)foreach($list as $t)$ids[]=(int)$t->id;
        $item_counts=$comment_counts=[];
        if($ids){
            $in=implode(',',$ids);
            if($cfg['checklist'])foreach((array)$wpdb->get_results("SELECT task_id,COUNT(*) n,SUM(done) d FROM {$wpdb->prefix}ews_task_items WHERE task_id IN ($in) GROUP BY task_id") as $r)$item_counts[(int)$r->task_id]=[(int)$r->d,(int)$r->n];
            if($cfg['comments'])foreach((array)$wpdb->get_results("SELECT task_id,COUNT(*) n FROM {$wpdb->prefix}ews_task_comments WHERE task_id IN ($in) GROUP BY task_id") as $r)$comment_counts[(int)$r->task_id]=(int)$r->n;
        }
        return $this->render_template('app/tasks',[
            'cfg'=>$cfg,'detail'=>$detail,'item_counts'=>$item_counts,'comment_counts'=>$comment_counts,
            'teams'=>($manage&&$cfg['teams'])?$this->task_teams():[],'workload'=>$tab==='workload'?$this->task_workload():null,
            'can_create'=>$manage||$cfg['personal'],'can_workload'=>$this->task_workload_allowed(),
            'manage'=>$manage,'tab'=>$tab,'priority_filter'=>$priority_filter,'counter'=>$counter,'cards'=>$cards,
            'done_total'=>$done_total,'done_all'=>$done_all,'done_url'=>add_query_arg('task_done','all',$filter_url('all',$priority_filter)),
            'employees'=>$employees,'edit'=>$edit,'base'=>$base,'tab_url'=>$tab_url,'filter_url'=>$filter_url,
            'error'=>sanitize_key($_GET['task_error']??''),'post_url'=>admin_url('admin-post.php'),'today'=>$today,
        ]);
    }

}
