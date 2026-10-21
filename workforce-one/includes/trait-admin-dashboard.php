<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\TodayStatus;
use WorkforceOne\Audit\AuditFilters;

/**
 * wp-admin home (Employee Schedule dashboard: today's attendance) and the Audit Log.
 * Rules: src/Attendance/TodayStatus.php, src/Audit/AuditFilters.php.
 * Behaviour: tests/e2e_dashboard_audit.py.
 */
trait EWS_Admin_Dashboard_Trait {

    /** The top-level menu opens the first page this user may use. */
    public function admin_home_router(){
        if($this->can('ews_view_dashboard')){$this->admin_home();return;}
        $routes=['ews_manage_employees'=>'admin_employees','ews_manage_attendance'=>'admin_attendance','ews_view_reports'=>'admin_reports',
            'ews_manage_locations'=>'admin_multi_locations_v321','ews_manage_settings'=>'admin_schedule_config','ews_manage_time'=>'admin_time_report',
            'ews_manage_auto_attendance'=>'admin_auto_attendance','ews_view_audit_log'=>'admin_audit','ews_manage_roles'=>'admin_roles','ews_manage_payroll'=>'admin_payroll'];
        foreach($routes as $cap=>$method){
            if($this->can($cap) && method_exists($this,$method)){$this->{$method}();return;}
        }
        wp_die('Access denied');
    }

    /**
     * Today's attendance. Expected: active employees with attendance tracking whose schedule type
     * requires Sign In; nobody is expected on a company holiday. Lateness follows each shift.
     */
    public function admin_home(){
        if(!$this->can('ews_view_dashboard'))wp_die('Access denied');
        global $wpdb;
        $today=current_time('Y-m-d');
        $holidays=array_map(function($e){return $e->title;},(array)$this->company_calendar_events($today,$today));
        $rows=[];
        if(!$holidays){
            $scheduled=(array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.name,e.domain_name,s.status FROM {$this->employees} e INNER JOIN {$this->schedule} s ON s.employee_id=e.id AND s.work_date=%s WHERE e.active=1 AND e.attendance_enabled=1 ORDER BY e.name ASC",$today));
            $expected=array_values(array_filter($scheduled,function($s){return $this->schedule_type_requires_sign_in($s->status);}));
            $first=[];
            if($expected){
                $ids=array_map(function($s){return (int)$s->id;},$expected);
                $ph=implode(',',array_fill(0,count($ids),'%d'));
                foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,event_type,event_at FROM {$this->time_logs} WHERE work_date=%s AND employee_id IN ($ph) AND event_type IN ('sign_in','late_sign_in')".$this->tl_live()." ORDER BY event_at ASC,id ASC",array_merge([$today],$ids))) as $ev){
                    if(!isset($first[(int)$ev->employee_id]))$first[(int)$ev->employee_id]=$ev;
                }
            }
            $this->prime_shift_cache(array_map(function($s){return (int)$s->id;},$expected));
            foreach($expected as $s){
                $ev=$first[(int)$s->id]??null;
                $status=TodayStatus::status($ev?$ev->event_type:null,$ev?$this->sign_in_classification($ev->event_at,(int)$s->id):null);
                $rows[]=['name'=>$s->name,'domain'=>$s->domain_name,'schedule'=>$s->status,'status'=>$status,'label'=>TodayStatus::label($status)];
            }
        }
        echo $this->render_template('admin/dashboard',[
            'today_label'=>date_i18n('l, d M Y',strtotime($today)),'holidays'=>$holidays,'rows'=>$rows,
            'counts'=>TodayStatus::counts(array_column($rows,'status')),
            'report_url'=>admin_url('admin.php?page=ews31-time-report&start='.$today.'&end='.$today),
            'links'=>['Employees'=>admin_url('admin.php?page=ews31-employees'),'Attendance'=>admin_url('admin.php?page=ews31-attendance'),'Reports'=>admin_url('admin.php?page=ews31-reports')],
        ]);
    }

    /** Readable names of audited records ("Target" column), loaded in one query per kind. */
    private function audit_target_labels($rows){
        global $wpdb;
        $groups=[];
        foreach($rows as $r){
            $entity=sanitize_key((string)$r->entity);$id=absint($r->entity_id);
            if($entity && $id)$groups[$entity][$id]=$id;
        }
        $p=$wpdb->prefix;
        $sql=[
            'employee'=>"SELECT id,name AS label FROM {$this->employees} WHERE id IN ({IDS})",
            'schedule'=>"SELECT s.id,CONCAT(COALESCE(e.name,'Employee'),' — ',s.work_date) AS label FROM {$this->schedule} s LEFT JOIN {$this->employees} e ON e.id=s.employee_id WHERE s.id IN ({IDS})",
            'time_log'=>"SELECT t.id,CONCAT(COALESCE(e.name,'Employee'),' — ',t.work_date,' ',t.event_type) AS label FROM {$this->time_logs} t LEFT JOIN {$this->employees} e ON e.id=t.employee_id WHERE t.id IN ({IDS})",
            'leave_request'=>"SELECT r.id,COALESCE(e.name,CONCAT('Leave Request #',r.id)) AS label FROM {$p}ews_leave_requests r LEFT JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.id IN ({IDS})",
            'vacation_request'=>"SELECT r.id,COALESCE(e.name,CONCAT('Vacation Request #',r.id)) AS label FROM {$p}ews_vacation_requests r LEFT JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.id IN ({IDS})",
            'leave_balance'=>"SELECT b.id,COALESCE(e.name,CONCAT('Leave Balance #',b.id)) AS label FROM {$p}ews_leave_balances b LEFT JOIN {$this->employees} e ON e.id=b.employee_id WHERE b.id IN ({IDS})",
            'leave_type'=>"SELECT id,name AS label FROM {$p}ews_leave_types WHERE id IN ({IDS})",
            'employee_achievement'=>"SELECT a.id,COALESCE(e.name,CONCAT('Employee Achievement #',a.id)) AS label FROM {$p}ews_employee_achievements a LEFT JOIN {$this->employees} e ON e.id=a.employee_id WHERE a.id IN ({IDS})",
            'schedule_swap'=>"SELECT w.id,CONCAT(COALESCE(a.name,'Employee'),' ↔ ',COALESCE(b.name,'Employee'),' — ',w.work_date) AS label FROM {$p}ews_schedule_swaps w LEFT JOIN {$this->employees} a ON a.id=w.requester_employee_id LEFT JOIN {$this->employees} b ON b.id=w.target_employee_id WHERE w.id IN ({IDS})",
            'company_calendar'=>"SELECT id,CONCAT(event_date,' — ',title) AS label FROM {$this->company_calendar} WHERE id IN ({IDS})",
            'kudos'=>"SELECT k.id,CONCAT(COALESCE(s.name,'Employee'),' → ',COALESCE(r.name,'Employee')) AS label FROM {$p}ews_kudos k LEFT JOIN {$this->employees} s ON s.id=k.sender_employee_id LEFT JOIN {$this->employees} r ON r.id=k.recipient_employee_id WHERE k.id IN ({IDS})",
            'team'=>"SELECT id,name AS label FROM {$p}ews_teams WHERE id IN ({IDS})",
            'department'=>"SELECT id,name AS label FROM {$p}ews_departments WHERE id IN ({IDS})",
        ];
        $targets=[];
        foreach($groups as $entity=>$ids){
            if(!isset($sql[$entity]))continue;
            $ids=array_values($ids);
            $query=str_replace('{IDS}',implode(',',array_fill(0,count($ids),'%d')),$sql[$entity]);
            $wpdb->suppress_errors(true); // a module's table may not exist on older installs
            $found=(array)$wpdb->get_results($wpdb->prepare($query,$ids));
            $wpdb->suppress_errors(false);
            foreach($found as $row)$targets[$entity.':'.(int)$row->id]=(string)$row->label;
        }
        return $targets;
    }

    public function admin_audit(){
        if(!$this->can('ews_view_audit_log'))wp_die('Access denied');
        global $wpdb;
        [$from,$to]=AuditFilters::range(sanitize_text_field(wp_unslash($_GET['audit_from']??'')),sanitize_text_field(wp_unslash($_GET['audit_to']??'')));
        $user_id=absint($_GET['audit_user']??0);
        $action=sanitize_text_field(wp_unslash($_GET['audit_action']??''));
        $where=[];$args=[];
        if($from!==''){$where[]='a.created_at >= %s';$args[]=$from.' 00:00:00';}
        if($to!==''){$where[]='a.created_at <= %s';$args[]=$to.' 23:59:59';}
        if($user_id){$where[]='a.user_id = %d';$args[]=$user_id;}
        if($action!==''){$where[]='a.action = %s';$args[]=$action;}
        $condition=$where?' WHERE '.implode(' AND ',$where):'';
        $count_sql="SELECT COUNT(*) FROM {$this->audit} a".$condition;
        $total=(int)$wpdb->get_var($args?$wpdb->prepare($count_sql,$args):$count_sql);
        $paging=AuditFilters::paging($total,absint($_GET['audit_page']??1));
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT a.*,u.user_login FROM {$this->audit} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id{$condition} ORDER BY a.id DESC LIMIT %d OFFSET %d",array_merge($args,[AuditFilters::PER_PAGE,$paging['offset']])));
        $labels=$this->audit_target_labels($rows);
        $filters=array_filter(['page'=>'ews31-audit','audit_from'=>$from,'audit_to'=>$to,'audit_user'=>$user_id,'audit_action'=>$action]);
        $page_links=[];
        if($paging['pages']>1){
            if($paging['page']>1)$page_links[]=['label'=>'« Previous','url'=>add_query_arg($filters+['audit_page'=>$paging['page']-1],admin_url('admin.php')),'current'=>false];
            for($i=max(1,$paging['page']-2);$i<=min($paging['pages'],$paging['page']+2);$i++)$page_links[]=['label'=>(string)$i,'url'=>add_query_arg($filters+['audit_page'=>$i],admin_url('admin.php')),'current'=>$i===$paging['page']];
            if($paging['page']<$paging['pages'])$page_links[]=['label'=>'Next »','url'=>add_query_arg($filters+['audit_page'=>$paging['page']+1],admin_url('admin.php')),'current'=>false];
        }
        echo $this->render_template('admin/audit',[
            'from'=>$from,'to'=>$to,'user_id'=>$user_id,'action'=>$action,'total'=>$total,'paging'=>$paging,'page_links'=>$page_links,
            'users'=>(array)$wpdb->get_results("SELECT DISTINCT u.ID,u.user_login FROM {$this->audit} a INNER JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY u.user_login ASC"),
            'actions'=>(array)$wpdb->get_col("SELECT DISTINCT action FROM {$this->audit} ORDER BY action ASC"),
            'rows'=>array_map(function($r)use($labels){return ['date'=>$r->created_at,'actor'=>$r->user_login?:'System','action'=>$r->action,'entity'=>$r->entity,'id'=>(int)$r->entity_id,'target'=>$labels[$r->entity.':'.(int)$r->entity_id]??'—','details'=>$r->details];},$rows),
            'reset_url'=>admin_url('admin.php?page=ews31-audit'),
        ]);
    }
}
