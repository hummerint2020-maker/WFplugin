<?php
if (!defined('ABSPATH')) exit;

trait EWS_Tasks_Trait {
    private static $ews_tasks_schema_ready = false;
    private function tasks_enabled(){ return (bool)get_option('ews_feature_tasks',false); }

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
            PRIMARY KEY  (id),
            KEY assigned_to (assigned_to),
            KEY created_by (created_by),
            KEY status (status),
            KEY priority (priority),
            KEY due_date (due_date)
        ) $c;");
        self::$ews_tasks_schema_ready=true;
    }

    private function tasks_redirect($args=[]){
        $url=wp_get_referer();
        if(!$url || strpos($url,'admin-post.php')!==false)$url=home_url('/');
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
        $this->notify_user($user_id,$title,$message,'info','task',$task_id);
        if(method_exists($this,'push_custom_notification')){
            $this->push_custom_notification($user_id,$title,$message,'task',$task_id,add_query_arg('ews_view','tasks',home_url('/')));
        }
    }

    public function task_save(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        if(!$this->tasks_enabled())wp_die('Tasks are disabled.');
        check_admin_referer('ews_task_save');
        $id=absint($_POST['task_id']??0);
        $title=trim(sanitize_text_field(wp_unslash($_POST['title']??'')));
        $description=trim(sanitize_textarea_field(wp_unslash($_POST['description']??'')));
        $status=sanitize_key($_POST['status']??'todo');
        $priority=sanitize_key($_POST['priority']??'normal');
        $due=trim(sanitize_text_field(wp_unslash($_POST['due_date']??'')));
        $assigned=absint($_POST['assigned_to']??0);
        if($title==='')$this->tasks_redirect(['task_error'=>'title']);
        if(!in_array($status,$this->task_allowed_statuses(),true))$status='todo';
        if(!in_array($priority,$this->task_allowed_priorities(),true))$priority='normal';
        if($due!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))$due='';
        if(!$this->task_can_manage_all())$assigned=0;
        if($assigned){
            global $wpdb;
            $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->employees} WHERE id=%d AND active=1 AND wp_user_id>0",$assigned));
            if(!$valid)$assigned=0;
        }
        global $wpdb;$table=$wpdb->prefix.'ews_tasks';$now=current_time('mysql');
        if($id){
            $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$id));
            if(!$task || !$this->task_user_can_edit($task))wp_die('Access denied');
            $old_assigned=(int)($task->assigned_to??0);
            // The edit form does not change workflow status. Preserve it for every role.
            $status=in_array($task->status,$this->task_allowed_statuses(),true)?$task->status:'todo';
            $completed=($status==='completed')?($task->completed_at?:$now):null;
            $ok=$wpdb->update($table,['title'=>$title,'description'=>$description,'status'=>$status,'priority'=>$priority,'due_date'=>$due?:null,'assigned_to'=>$assigned?:null,'updated_at'=>$now,'completed_at'=>$completed],['id'=>$id],['%s','%s','%s','%s','%s','%d','%s','%s'],['%d']);
            if($ok===false)$this->tasks_redirect(['task_error'=>'save']);
            $this->audit('task_update','task',$id,$title);

            // Notify the new assignee when an existing task is assigned/reassigned.
            if($assigned && $assigned!==$old_assigned){
                $assigned_user=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d LIMIT 1",$assigned));
                if($assigned_user){
                    $this->task_notify_user($assigned_user,'Task Assigned','You have been assigned the task: <strong>'.esc_html($title).'</strong>.',$id);
                }
            }
            $this->tasks_redirect(['task_updated'=>1]);
        }
        if($status==='completed')$completed=$now; else $completed=null;
        $ok=$wpdb->insert($table,['title'=>$title,'description'=>$description,'status'=>$status,'priority'=>$priority,'due_date'=>$due?:null,'assigned_to'=>$assigned?:null,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now,'completed_at'=>$completed],['%s','%s','%s','%s','%s','%d','%d','%s','%s','%s']);
        if($ok===false)$this->tasks_redirect(['task_error'=>'save']);
        $new_id=(int)$wpdb->insert_id;
        $this->audit('task_create','task',$new_id,$title);
        if($assigned){
            $user_id=(int)$wpdb->get_var($wpdb->prepare("SELECT wp_user_id FROM {$this->employees} WHERE id=%d LIMIT 1",$assigned));
            if($user_id){
                $this->task_notify_user($user_id,'New Task','You have been assigned the task: <strong>'.esc_html($title).'</strong>.',$new_id);
            }
        }
        $this->tasks_redirect(['task_saved'=>1]);
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
        $status=sanitize_key($_POST['status']??'todo');
        if(!in_array($status,$this->task_allowed_statuses(),true))wp_die('Invalid status.');
        $allowed_next=['todo'=>['in_progress'],'in_progress'=>['todo','completed'],'completed'=>['in_progress']];
        if($status!==$task->status && !in_array($status,$allowed_next[$task->status]??[],true)){$this->tasks_redirect(['task_error'=>'transition']);}
        $now=current_time('mysql');
        $ok=$wpdb->update($table,['status'=>$status,'updated_at'=>$now,'completed_at'=>$status==='completed'?($task->completed_at?:$now):null],['id'=>$id],['%s','%s','%s'],['%d']);
        if($ok===false)$this->tasks_redirect(['task_error'=>'status']);
        $this->audit('task_status','task',$id,$status);

        // Keep the task owner/creator informed when someone else changes workflow status.
        $creator=(int)$task->created_by;
        if($creator && $creator!==$uid){
            $this->task_notify_user($creator,'Task Status Updated','<strong>'.esc_html($task->title).'</strong> is now <strong>'.esc_html($this->task_display_status($status)).'</strong>.',$id);
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
        $this->tasks_redirect(['task_deleted'=>1]);
    }

    private function task_display_status($status){ return ['todo'=>'To Do','in_progress'=>'In Progress','completed'=>'Completed'][$status]??ucwords(str_replace('_',' ',$status)); }
    private function task_display_priority($priority){ return ucfirst($priority); }

    public function tasks_content(){
        if(!$this->tasks_enabled()) return '<div class="ews-card"><h3>Tasks</h3><p>Tasks are currently disabled by your administrator.</p></div>';

        global $wpdb;
        $table=$wpdb->prefix.'ews_tasks';
        $uid=get_current_user_id();
        $manage=$this->task_can_manage_all();
        $tab=sanitize_key($_GET['task_tab']??'my');
        if(!$manage) $tab='my';

        $status_filter=sanitize_key($_GET['task_status']??'all');
        if($status_filter!=='all' && !in_array($status_filter,$this->task_allowed_statuses(),true)) $status_filter='all';
        $priority_filter=sanitize_key($_GET['task_priority']??'all');
        if($priority_filter!=='all' && !in_array($priority_filter,$this->task_allowed_priorities(),true)) $priority_filter='all';

        if($manage && $tab==='team'){
            $where=" AND t.assigned_to IN (SELECT id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL)";
            $args=[];
        } else {
            $where=" AND (t.created_by=%d OR a.wp_user_id=%d)";
            $args=[$uid,$uid];
        }
        if($status_filter!=='all'){ $where.=' AND t.status=%s'; $args[]=$status_filter; }
        if($priority_filter!=='all'){ $where.=' AND t.priority=%s'; $args[]=$priority_filter; }

        $sql="SELECT t.*,a.name assigned_name,a.wp_user_id assigned_wp_user_id,c.display_name creator_name
              FROM $table t
              LEFT JOIN {$this->employees} a ON a.id=t.assigned_to
              LEFT JOIN {$wpdb->users} c ON c.ID=t.created_by
              WHERE 1=1 $where
              ORDER BY CASE t.status WHEN 'in_progress' THEN 1 WHEN 'todo' THEN 2 ELSE 3 END,
                       CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END,
                       (t.due_date IS NULL), t.due_date ASC, t.created_at DESC";
        $rows=$args ? $wpdb->get_results($wpdb->prepare($sql,...$args)) : $wpdb->get_results($sql);

        // Dashboard counters use the same visibility rules as the list.
        $counter_where=($manage&&$tab==='team')
            ? " AND t.assigned_to IN (SELECT id FROM {$this->employees} WHERE active=1 AND wp_user_id IS NOT NULL)"
            : " AND (t.created_by=%d OR a.wp_user_id=%d)";
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
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d LIMIT 1",$edit_id)):null;
        if($edit && !$this->task_user_can_edit($edit)) $edit=null;

        $base=$this->app_view_url('tasks');
        $filter_url=function($status,$priority) use ($base,$tab){
            // app_view_url() intentionally preserves unknown query args, so strip
            // Task filters first. Otherwise clicking "All" while already filtered
            // keeps the previous task_status/task_priority in the URL.
            $url=remove_query_arg(['task_tab','task_status','task_priority','edit_task'],$base);
            $args=['task_tab'=>$tab];
            if($status!=='all') $args['task_status']=$status;
            if($priority!=='all') $args['task_priority']=$priority;
            return add_query_arg($args,$url);
        };
        $tab_url=function($target_tab) use ($base){
            $url=remove_query_arg(['task_tab','task_status','task_priority','edit_task'],$base);
            return add_query_arg('task_tab',$target_tab,$url);
        };

        ob_start();
        ?>
        <style>
        .wfo-tasks{display:grid;gap:18px}.wfo-task-hero{background:linear-gradient(135deg,#172b4d,#315a85);color:#fff;border-radius:18px;padding:22px}.wfo-task-hero h2{margin:0 0 5px;font-size:26px}.wfo-task-hero p{margin:0;color:#dbeafe}.wfo-task-tabs{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap}.wfo-task-tabs a{padding:8px 12px;border-radius:999px;background:#ffffff20;color:#fff;text-decoration:none;font-weight:700}.wfo-task-tabs a.active{background:#fff;color:#172b4d}.wfo-task-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:18px}.wfo-task-kpi{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:13px}.wfo-task-kpi strong{display:block;font-size:22px;color:#101828}.wfo-task-kpi span{font-size:11px;color:#667085;font-weight:700}.wfo-task-kpi.overdue strong{color:#b42318}.wfo-task-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.wfo-task-filters{display:flex;gap:7px;flex-wrap:wrap}.wfo-task-filters a{padding:7px 10px;border:1px solid #d0d5dd;border-radius:999px;text-decoration:none;color:#344054;background:#fff;font-size:12px;font-weight:700}.wfo-task-filters a.active{background:#172b4d;color:#fff;border-color:#172b4d}.wfo-task-grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px}.wfo-task-list{display:grid;gap:10px}.wfo-task-card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:16px}.wfo-task-card.overdue{border-color:#fecdca}.wfo-task-head{display:flex;justify-content:space-between;gap:14px}.wfo-task-title{font-weight:800;color:#101828}.wfo-task-desc{color:#667085;font-size:13px;margin-top:6px;white-space:pre-wrap}.wfo-task-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:12px}.wfo-task-pill{font-size:11px;font-weight:800;padding:5px 8px;border-radius:999px;background:#f2f4f7;color:#344054}.wfo-task-pill.urgent{background:#fef3f2;color:#b42318}.wfo-task-pill.high{background:#fff7ed;color:#c2410c}.wfo-task-pill.completed{background:#ecfdf3;color:#027a48}.wfo-task-pill.overdue{background:#fef3f2;color:#b42318}.wfo-task-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.wfo-task-actions form{margin:0}.wfo-task-form{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:18px}.wfo-task-form h3{margin-top:0}.wfo-task-form label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:12px}.wfo-task-form input,.wfo-task-form textarea,.wfo-task-form select{width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #d0d5dd;border-radius:9px;padding:9px 10px;background:#fff}.wfo-task-form textarea{min-height:100px;resize:vertical}.wfo-task-form .row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.wfo-task-empty{text-align:center;padding:35px;color:#667085;background:#fff;border:1px dashed #d0d5dd;border-radius:14px}.wfo-task-new{width:100%}
        @media(max-width:800px){.wfo-task-kpis{grid-template-columns:repeat(2,1fr)}.wfo-task-grid{grid-template-columns:1fr}.wfo-task-form{order:-1}.wfo-task-form .row{grid-template-columns:1fr}}
        </style>
        <div class="wfo-tasks">
            <div class="wfo-task-hero">
                <h2>Tasks</h2><p>Keep workforce work organized, assigned and visible.</p>
                <?php if($manage): ?><div class="wfo-task-tabs"><a class="<?php echo $tab==='my'?'active':''; ?>" href="<?php echo esc_url($tab_url('my')); ?>">My Tasks</a><a class="<?php echo $tab==='team'?'active':''; ?>" href="<?php echo esc_url($tab_url('team')); ?>">Team Tasks</a></div><?php endif; ?>
                <div class="wfo-task-kpis">
                    <div class="wfo-task-kpi"><strong><?php echo (int)($counter->todo_count??0); ?></strong><span>To Do</span></div>
                    <div class="wfo-task-kpi"><strong><?php echo (int)($counter->progress_count??0); ?></strong><span>In Progress</span></div>
                    <div class="wfo-task-kpi"><strong><?php echo (int)($counter->completed_count??0); ?></strong><span>Completed</span></div>
                    <div class="wfo-task-kpi overdue"><strong><?php echo (int)($counter->overdue_count??0); ?></strong><span>Overdue</span></div>
                </div>
            </div>

            <?php $task_error=sanitize_key($_GET['task_error']??''); if($task_error): ?>
                <div class="wfo-task-notice error" role="alert">
                    <?php if($task_error==='transition'): ?>That task can’t move to that status yet. Start Progress first, then you can complete it.
                    <?php elseif($task_error==='status'): ?>We couldn’t update the task status. No changes were applied.
                    <?php elseif($task_error==='save'): ?>We couldn’t save the task. No partial changes were applied.
                    <?php elseif($task_error==='delete'): ?>We couldn’t delete the task. No changes were applied.
                    <?php elseif($task_error==='title'): ?>Please enter a task title.
                    <?php else: ?>Something went wrong. Please try again.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="wfo-task-toolbar">
                <div class="wfo-task-filters">
                    <?php foreach(['all'=>'All','todo'=>'To Do','in_progress'=>'In Progress','completed'=>'Completed'] as $k=>$label): ?>
                        <a class="<?php echo $status_filter===$k?'active':''; ?>" href="<?php echo esc_url($filter_url($k,$priority_filter)); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="wfo-task-filters">
                    <?php foreach(['all'=>'All Priority','urgent'=>'Urgent','high'=>'High','normal'=>'Normal','low'=>'Low'] as $k=>$label): ?>
                        <a class="<?php echo $priority_filter===$k?'active':''; ?>" href="<?php echo esc_url($filter_url($status_filter,$k)); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wfo-task-grid">
                <div class="wfo-task-list">
                <?php if(!$rows): ?><div class="wfo-task-empty"><strong>No tasks found.</strong><br>Try changing the filters or create a new task.</div><?php endif; ?>
                <?php foreach($rows as $t):
                    $is_overdue=!empty($t->due_date) && $t->status!=='completed' && $t->due_date < current_time('Y-m-d');
                    $is_assignee=!empty($t->assigned_wp_user_id) && (int)$t->assigned_wp_user_id===$uid;
                ?>
                    <div class="wfo-task-card <?php echo $is_overdue?'overdue':''; ?>">
                        <div class="wfo-task-head"><div><div class="wfo-task-title"><?php echo esc_html($t->title); ?></div><?php if($t->description): ?><div class="wfo-task-desc"><?php echo esc_html($t->description); ?></div><?php endif; ?></div><span class="wfo-task-pill <?php echo esc_attr($t->status); ?>"><?php echo esc_html($this->task_display_status($t->status)); ?></span></div>
                        <div class="wfo-task-meta"><span class="wfo-task-pill <?php echo esc_attr($t->priority); ?>">Priority: <?php echo esc_html($this->task_display_priority($t->priority)); ?></span><?php if($t->due_date): ?><span class="wfo-task-pill <?php echo $is_overdue?'overdue':''; ?>"><?php echo $is_overdue?'⚠ Overdue: ':'Due: '; ?><?php echo esc_html($t->due_date); ?></span><?php endif; ?><?php if($t->assigned_name): ?><span class="wfo-task-pill">Assigned: <?php echo esc_html($t->assigned_name); ?></span><?php endif; ?></div>
                        <div class="wfo-task-actions">
                            <?php if($manage||$is_assignee||(int)$t->created_by===$uid): ?>
                                <?php if($t->status==='todo'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_task_status_'.(int)$t->id); ?><input type="hidden" name="action" value="ews_task_status_update"><input type="hidden" name="task_id" value="<?php echo (int)$t->id; ?>"><input type="hidden" name="status" value="in_progress"><button class="ews-btn" type="submit">▶ Start Progress</button></form><?php endif; ?>
                                <?php if($t->status==='in_progress'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_task_status_'.(int)$t->id); ?><input type="hidden" name="action" value="ews_task_status_update"><input type="hidden" name="task_id" value="<?php echo (int)$t->id; ?>"><input type="hidden" name="status" value="todo"><button class="ews-btn secondary" type="submit">↩ To Do</button></form><?php endif; ?>
                                <?php if($t->status==='in_progress'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_task_status_'.(int)$t->id); ?><input type="hidden" name="action" value="ews_task_status_update"><input type="hidden" name="task_id" value="<?php echo (int)$t->id; ?>"><input type="hidden" name="status" value="completed"><button class="ews-btn" type="submit">✓ Complete</button></form><?php endif; ?>
                                <?php if($t->status==='completed'): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_task_status_'.(int)$t->id); ?><input type="hidden" name="action" value="ews_task_status_update"><input type="hidden" name="task_id" value="<?php echo (int)$t->id; ?>"><input type="hidden" name="status" value="in_progress"><button class="ews-btn secondary" type="submit">↻ Reopen</button></form><?php endif; ?>
                            <?php endif; ?>
                            <?php if($this->task_user_can_edit($t)): ?><a class="ews-btn secondary" href="<?php echo esc_url(add_query_arg(['edit_task'=>(int)$t->id,'task_tab'=>$tab],$base)); ?>">Edit</a><?php endif; ?>
                            <?php if($this->task_user_can_delete($t)): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this task?');"><?php wp_nonce_field('ews_task_delete_'.(int)$t->id); ?><input type="hidden" name="action" value="ews_task_delete"><input type="hidden" name="task_id" value="<?php echo (int)$t->id; ?>"><button class="ews-btn secondary" type="submit">Delete</button></form><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
                <div class="wfo-task-form">
                    <h3><?php echo $edit?'Edit Task':'New Task'; ?></h3>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('ews_task_save'); ?><input type="hidden" name="action" value="ews_task_save"><input type="hidden" name="task_id" value="<?php echo $edit?(int)$edit->id:0; ?>">
                        <label>Title<input name="title" value="<?php echo esc_attr($edit->title??''); ?>" required maxlength="255" placeholder="What needs to be done?"></label>
                        <label>Description<textarea name="description" placeholder="Add useful context, instructions or notes..."><?php echo esc_textarea($edit->description??''); ?></textarea></label>
                        <div class="row"><label>Priority<select name="priority"><?php foreach($this->task_allowed_priorities() as $p): ?><option value="<?php echo esc_attr($p); ?>" <?php selected($edit->priority??'normal',$p); ?>><?php echo esc_html(ucfirst($p)); ?></option><?php endforeach; ?></select></label><label>Due Date<input type="date" name="due_date" value="<?php echo esc_attr($edit->due_date??''); ?>"></label></div>
                        <?php if($manage): ?><label>Assign To<select name="assigned_to"><option value="0">Myself / Personal Task</option><?php foreach($employees as $e): ?><option value="<?php echo (int)$e->id; ?>" <?php selected((int)($edit->assigned_to??0),(int)$e->id); ?>><?php echo esc_html($e->name); ?></option><?php endforeach; ?></select></label><?php endif; ?>
                        <button class="ews-btn wfo-task-new" type="submit"><?php echo $edit?'Save Changes':'＋ Create Task'; ?></button><?php if($edit): ?> <a class="ews-btn secondary" href="<?php echo esc_url($base); ?>">Cancel</a><?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

}
