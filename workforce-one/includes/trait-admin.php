<?php
if (!defined('ABSPATH')) exit;

trait EWS_Admin_Trait {

    public function enqueue_admin_ui_foundation($hook_suffix){
        if(strpos((string)$hook_suffix, "page_ews31") === false && (string)$hook_suffix !== "toplevel_page_ews31") return;
        wp_enqueue_style('workforce-one-ui', plugin_dir_url(__FILE__) . '../assets/css/workforce-one-ui.css', [], EWS_VERSION);
        wp_style_add_data('workforce-one-ui', 'rtl', 'replace');
        if(substr((string)$hook_suffix,-strlen('ews31-schedule-config'))==='ews31-schedule-config'){
            wp_enqueue_style('workforce-one-admin-schedule-config', $this->plugin_url('assets/css/admin-schedule-config.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-schedule-config', 'rtl', 'replace');
        }
        if(substr((string)$hook_suffix,-strlen('ews31-features'))==='ews31-features'){
            wp_enqueue_style('workforce-one-admin-features', $this->plugin_url('assets/css/admin-features.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-features', 'rtl', 'replace');
        }
        if(substr((string)$hook_suffix,-strlen('ews31-notifications'))==='ews31-notifications'){
            wp_enqueue_style('workforce-one-admin-notifications', $this->plugin_url('assets/css/admin-notifications.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-notifications', 'rtl', 'replace');
        }
        if(substr((string)$hook_suffix,-strlen('ews31-achievements'))==='ews31-achievements'){
            wp_enqueue_style('workforce-one-admin-achievements', $this->plugin_url('assets/css/admin-achievements.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-achievements', 'rtl', 'replace');
            wp_enqueue_script('workforce-one-admin-achievements', $this->plugin_url('assets/js/admin-achievements.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-smart-nudges'))==='ews31-smart-nudges'){
            wp_enqueue_style('workforce-one-admin-smart-nudges', $this->plugin_url('assets/css/admin-smart-nudges.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-smart-nudges', 'rtl', 'replace');
        }
        if(substr((string)$hook_suffix,-strlen('ews31-employee-profile'))==='ews31-employee-profile'){
            wp_enqueue_style('workforce-one-admin-employee-profile', $this->plugin_url('assets/css/admin-employee-profile.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-employee-profile', 'rtl', 'replace');
        }
    }

    public function admin_menu(){
            add_action('admin_footer',[$this,'ews_admin_confirmation_guard']);
            if(!$this->has_ews_admin_access()) return;
            // The parent menu is visible to EWS users with at least one management capability.
            // Each submenu keeps its own capability, so permissions remain independent.
            add_menu_page('Employee Schedule','Employee Schedule','read','ews31',[$this,'admin_home_router'],'dashicons-groups',26);
            // Keep the Workforce One admin menu flat and workflow-oriented.
            // No custom section headers or deep nesting: daily operational screens first,
            // configuration and administration later. Capabilities and page handlers remain unchanged.
            add_submenu_page('ews31','Employees','Employees','ews_manage_employees','ews31-employees',[$this,'admin_employees']);
            add_submenu_page('ews31','Employee Profile','Employee Profile','ews_manage_employees','ews31-employee-profile',[$this,'admin_employee_profile']);
            add_submenu_page('ews31','Attendance Insights','Attendance Insights','ews_view_reports','ews31-attendance-insights',[$this,'admin_attendance_insights']);
            add_submenu_page('ews31','Sign In / Out Report','Sign In / Out Report','ews_manage_time','ews31-time-report',[$this,'admin_time_report']);
            add_submenu_page('ews31','Requests Hub','Requests Hub','manage_options','ews31-requests',[$this,'admin_requests']);
            add_submenu_page('ews31','Leaves','Leaves','ews_manage_settings','ews31-leaves',[$this,'admin_leaves']);
            add_submenu_page('ews31','Face Reset Requests','Face Reset Requests','ews_manage_employees','ews31-face-reset-requests',[$this,'admin_face_reset_requests']);

            add_submenu_page('ews31','Departments','Departments','manage_options','ews31-departments',[$this,'admin_departments']);
            add_submenu_page('ews31','Teams','Teams','manage_options','ews31-teams',[$this,'admin_teams']);
            add_submenu_page('ews31','Work Locations','Work Locations','ews_manage_locations','ews31-multi-locations',[$this,'admin_multi_locations_v321']);
            add_submenu_page('ews31','Presence Kiosks','Presence Kiosks','ews_manage_locations','ews31-presence-kiosks',[$this,'admin_presence_kiosks']);
            add_submenu_page('ews31','Schedule Configuration','Schedule Configuration','ews_manage_settings','ews31-schedule-config',[$this,'admin_schedule_config']);
            add_submenu_page('ews31','Auto Attendance','Auto Attendance','ews_manage_auto_attendance','ews31-auto-attendance',[$this,'admin_auto_attendance']);

            add_submenu_page('ews31','Employee Moments','Employee Moments','ews_manage_settings','ews31-moments',[$this,'admin_employee_moments']);
            add_submenu_page('ews31','Smart Nudges','Smart Nudges','ews_manage_settings','ews31-smart-nudges',[$this,'admin_smart_nudges']);
            add_submenu_page('ews31','Achievements','Achievements','ews_manage_settings','ews31-achievements',[$this,'admin_achievements']);
            add_submenu_page('ews31','Employee Polls','Employee Polls','ews_manage_settings','ews31-polls',[$this,'admin_polls']);
            add_submenu_page('ews31','Recognition','Recognition','ews_manage_settings','ews31-recognition',[$this,'admin_recognition']);

            add_submenu_page('ews31','Roles & Permissions','Roles & Permissions','ews_manage_roles','ews31-roles',[$this,'admin_roles']);
            add_submenu_page('ews31','Notification Settings','Notification Settings','ews_manage_settings','ews31-notifications',[$this,'admin_notification_settings']);
            add_submenu_page('ews31','Email Settings','Email Settings','ews_manage_settings','ews31-email',[$this,'admin_email']);
            add_submenu_page('ews31','Feature Configuration','Feature Configuration','ews_manage_settings','ews31-features',[$this,'admin_features']);
            add_submenu_page('ews31','Audit Log','Audit Log','ews_view_audit_log','ews31-audit',[$this,'admin_audit']);
            add_submenu_page('ews31','Employee Profile','Employee Profile','ews_manage_settings','ews31-employee-profile-settings',[$this,'admin_employee_profile_settings']);
            add_submenu_page('ews31','View Navigation','View Navigation','ews_manage_settings','ews31-navigation',[$this,'admin_navigation']);
            add_submenu_page('ews31','Approval Workflows','Approval Workflows','manage_options','ews31-approvals',[$this,'admin_approval_workflows']);
            // Keep Employee Profile registered so direct URLs remain authorized (removing the
            // submenu entry would make WordPress refuse the page), but hide its menu link on every
            // screen: profiles are opened from Employees.
            add_action('admin_head', function(){
                echo '<style>#toplevel_page_ews31 .wp-submenu a[href="admin.php?page=ews31-employee-profile"]{display:none!important}</style>';
            });
        }


        public function ews_admin_confirmation_guard(){
            if(!$this->can('ews_manage_settings') && !$this->can('ews_manage_time')) return;
            $global=(int)get_option('ews_confirm_global',1);
            $cfg=\WorkforceOne\Settings\FeatureSettings::confirmState(get_option('ews_confirmation_actions',[]));
            $payload=['global'=>(bool)$global,'actions'=>$cfg];
            echo '<script>window.ewsAdminConfirmationConfig='.wp_json_encode($payload).';(function(){function init(){document.querySelectorAll("[data-ews-confirm-key]").forEach(function(el){if(el.dataset.ewsConfirmBound==="1")return;el.dataset.ewsConfirmBound="1";el.addEventListener("click",function(e){var c=window.ewsAdminConfirmationConfig||{};var k=el.getAttribute("data-ews-confirm-key");if(c.global!==false&&c.actions&&c.actions[k]){var msg=k==="attendance_reset"?"Reset ALL attendance and break records for this employee on this date?":"Remove this General Leave?";if(!window.confirm(msg))e.preventDefault();}});});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init();})();</script>';
        }




    public function admin_attendance_insights(){
            if(!$this->can('ews_view_reports')) wp_die('Access denied');
            global $wpdb;

            [$dates,$sun]=$this->week_dates_configured(sanitize_text_field(wp_unslash($_GET['week']??current_time('Y-m-d'))));
            $week_start=$dates[0]??current_time('Y-m-d');
            $week_end=$dates[count($dates)-1]??$week_start;
            $today=current_time('Y-m-d');
            $focus_date=sanitize_text_field(wp_unslash($_GET['focus_date']??$today));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$focus_date) || strtotime($focus_date)===false)$focus_date=$today;
            $selected_team=sanitize_text_field(wp_unslash($_GET['team']??'all'));

            $all_emps=(array)$this->emps();
            $team_options=[];
            $team_names_by_emp=[];
            $team_map=[];
            if(method_exists($this,'team_tables')){
                $this->ensure_teams_schema();
                $tt=$this->team_tables();
                $team_rows=$wpdb->get_results("SELECT id,name FROM {$tt['teams']} WHERE active=1 ORDER BY name ASC");
                foreach((array)$team_rows as $tm){$team_map[(int)$tm->id]=(string)$tm->name;$team_options[(string)$tm->name]=(string)$tm->name;}
                $member_rows=$wpdb->get_results("SELECT team_id,employee_id FROM {$tt['members']} WHERE active=1 ORDER BY team_id ASC,employee_id ASC");
                foreach((array)$member_rows as $mr){$eid=(int)$mr->employee_id;$tid=(int)$mr->team_id;if(isset($team_map[$tid]))$team_names_by_emp[$eid][]=$team_map[$tid];}
                foreach($team_names_by_emp as $eid=>$names)$team_names_by_emp[$eid]=array_values(array_unique($names));
            }
            natcasesort($team_options);

            $emps=[];
            foreach($all_emps as $e){
                $names=$team_names_by_emp[(int)$e->id]??[];
                if($selected_team!=='all' && !in_array($selected_team,$names,true)) continue;
                $e->_insight_teams=$names;
                $emps[]=$e;
            }

            $ids=$emps?implode(',',array_map('intval',wp_list_pluck($emps,'id'))):'0';
            $schedule=[];$events=[];
            if($emps){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s",$week_start,$week_end));
                foreach((array)$rows as $r)$schedule[(int)$r->employee_id][$r->work_date]=$r;
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at,latitude,longitude,accuracy,location_status,distance_meters FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s ORDER BY event_at ASC",$week_start,$week_end));
                foreach((array)$rows as $r)$events[(int)$r->employee_id][$r->work_date][$r->event_type]=$r;
            }
            $company_leave=$this->company_leave_dates($week_start,$week_end);
            $company_leave_today=$this->company_leave_dates($today,$today);
            if(!empty($company_leave_today))$company_leave=array_merge($company_leave,$company_leave_today);

            $status_for=function($planned,$ev,$date,$employee_id)use($today,$company_leave){
                if(isset($company_leave[$date]))return ['Leave',$company_leave[$date]];
                $rule=$this->schedule_type_attendance_rule($planned);
                if($rule==='leave')return ['Leave',''];
                if($rule==='business_trip')return [$planned,''];
                if(!$this->schedule_type_requires_sign_in($planned))return ['Not Scheduled',''];
                if(!empty($ev['sign_in'])){
                    $classification=$this->sign_in_classification($ev['sign_in']->event_at,$employee_id);
                    return [$classification==='Late Arrival'?'Late':'Present',date_i18n('H:i',strtotime($ev['sign_in']->event_at))];
                }
                if(!empty($ev['late_sign_in']))return ['Late',date_i18n('H:i',strtotime($ev['late_sign_in']->event_at))];
                if($date<$today)return ['Absent',''];
                if($date===$today){
                    $hours=$this->working_hours($employee_id);$now_ts=current_time('timestamp');
                    $cutoff_ts=strtotime($date.' '.$hours['normal_until'].':00');
                    if(!empty($hours['overnight']) && $hours['start']>$hours['end'] && $hours['normal_until']<$hours['start'])$cutoff_ts=strtotime(date('Y-m-d',strtotime($date.' +1 day')).' '.$hours['normal_until'].':00');
                    if($cutoff_ts!==false && $now_ts>=$cutoff_ts)return ['Absent',''];
                }
                return ['Pending',''];
            };

            $planned_labels=['Office','WFH','Leave','Business Trip','Absent','Not Set'];
            $day_plan=[];$day_actual=[];$employee_metrics=[];$overall=['Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0,'Pending'=>0];
            foreach($dates as $d){
                $day_plan[$d]=array_fill_keys($planned_labels,0);
                $day_actual[$d]=['Present'=>0,'Late'=>0,'Absent'=>0,'Pending'=>0,'Leave'=>0];
            }
            foreach($emps as $e){
                $m=['Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0,'Pending'=>0,'working'=>0,'on_time'=>0,'missing_sign_out'=>0];
                foreach($dates as $d){
                    $planned=trim((string)($schedule[(int)$e->id][$d]->status??''));
                    $plan_key=$planned!==''?$planned:'Not Set';
                    if(!isset($day_plan[$d][$plan_key]))$day_plan[$d]['Not Set']++;
                    else $day_plan[$d][$plan_key]++;
                    [$actual,$tm]=$status_for($planned,$events[(int)$e->id][$d]??[],$d,(int)$e->id);
                    if(isset($day_actual[$d][$actual]))$day_actual[$d][$actual]++;
                    if(isset($m[$actual]))$m[$actual]++;
                    if(isset($overall[$actual]))$overall[$actual]++;
                    if(in_array($actual,['Present','Late','Absent'],true))$m['working']++;
                    if($actual==='Present')$m['on_time']++;
                    $ev=$events[(int)$e->id][$d]??[];
                    if(!empty($ev['sign_in']) && empty($ev['sign_out']) && $d<=$today)$m['missing_sign_out']++;
                }
                $m['rate']=$m['working']?round((($m['Present']+$m['Late'])/$m['working'])*100):0;
                $m['on_time_rate']=$m['working']?round(($m['on_time']/$m['working'])*100):0;
                $employee_metrics[]=['employee'=>$e,'metrics'=>$m];
            }

            // Focus day is manager-selectable. The current calendar date remains $today for attendance classification rules.
            $focus_schedule=[];$focus_events=[];
            if($emps){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date=%s",$focus_date));
                foreach((array)$rows as $r)$focus_schedule[(int)$r->employee_id]=$r;
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at,latitude,longitude,accuracy,location_status,distance_meters FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date=%s ORDER BY event_at ASC",$focus_date));
                foreach((array)$rows as $r)$focus_events[(int)$r->employee_id][$r->event_type]=$r;
            }
            $scheduled_focus=0;$focus_plan=['Office'=>0,'WFH'=>0,'Leave'=>0,'Business Trip'=>0,'Absent'=>0,'Not Set'=>0];
            $focus_actual=['Present'=>0,'Late'=>0,'Absent'=>0,'Pending'=>0,'Leave'=>0];$focus_missing_sign_out=[];
            foreach($emps as $e){
                $p=trim((string)($focus_schedule[(int)$e->id]->status??''));if($p==='Vacation')$p='Leave';$pk=$p!==''?$p:'Not Set';if(isset($focus_plan[$pk]))$focus_plan[$pk]++;
                $ev=$focus_events[(int)$e->id]??[];
                [$a]=$status_for($p,$ev,$focus_date,(int)$e->id);if(isset($focus_actual[$a]))$focus_actual[$a]++;if(in_array($p,['Office','WFH'],true))$scheduled_focus++;
                if(!empty($ev['sign_in']) && empty($ev['sign_out']))$focus_missing_sign_out[]=(string)$e->name;
            }

            $prev=add_query_arg(['page'=>'ews31-attendance-insights','week'=>date('Y-m-d',strtotime('-7 days',$sun)),'team'=>$selected_team,'focus_date'=>$focus_date],admin_url('admin.php'));
            $next=add_query_arg(['page'=>'ews31-attendance-insights','week'=>date('Y-m-d',strtotime('+7 days',$sun)),'team'=>$selected_team,'focus_date'=>$focus_date],admin_url('admin.php'));
            $focus_prev=add_query_arg(['page'=>'ews31-attendance-insights','week'=>$week_start,'team'=>$selected_team,'focus_date'=>date('Y-m-d',strtotime('-1 day',strtotime($focus_date)))],admin_url('admin.php'));
            $focus_next=add_query_arg(['page'=>'ews31-attendance-insights','week'=>$week_start,'team'=>$selected_team,'focus_date'=>date('Y-m-d',strtotime('+1 day',strtotime($focus_date)))],admin_url('admin.php'));
            $focus_label_date=date_i18n('l, d M Y',strtotime($focus_date));
            $week_label=date('d',strtotime($week_start)).' – '.date('d M Y',strtotime($week_end));

            $focus_drill=['plan'=>[],'actual'=>[]];
            foreach($planned_labels as $label)$focus_drill['plan'][$label]=[];
            foreach(['Present','Late','Absent','Pending','Leave'] as $label)$focus_drill['actual'][$label]=[];
            foreach($emps as $e){
                $p=trim((string)($focus_schedule[(int)$e->id]->status??''));$p=$p!==''?$p:'Not Set';$key=isset($focus_drill['plan'][$p])?$p:'Not Set';$focus_drill['plan'][$key][]=(string)$e->name;
                [$a]=$status_for($p,$focus_events[(int)$e->id]??[],$focus_date,(int)$e->id);if(isset($focus_drill['actual'][$a]))$focus_drill['actual'][$a][]=(string)$e->name;
            }
            $focus_drill['actual']['Missing Sign-out']=$focus_missing_sign_out;

            $drill=[];
            foreach($dates as $d){
                foreach($planned_labels as $label)$drill[$d]['plan'][$label]=[];
                foreach($emps as $e){$p=trim((string)($schedule[(int)$e->id][$d]->status??''));if($p==='Vacation')$p='Leave';$p=$p!==''?$p:'Not Set';$key=isset($drill[$d]['plan'][$p])?$p:'Not Set';$drill[$d]['plan'][$key][] = (string)$e->name;}
                foreach(['Present','Late','Absent','Pending','Leave'] as $label)$drill[$d]['actual'][$label]=[];
                foreach($emps as $e){[$a]=$status_for(trim((string)($schedule[(int)$e->id][$d]->status??'')),$events[(int)$e->id][$d]??[],$d,(int)$e->id);if(isset($drill[$d]['actual'][$a]))$drill[$d]['actual'][$a][]=(string)$e->name;}
            }

            echo '<div class="wrap ews-ai-admin"><style>
            .ews-ai-admin{max-width:1480px;margin-right:24px}.ews-ai-admin *{box-sizing:border-box}.ews-ai-hero{margin:24px 0 18px;padding:28px 30px;border-radius:18px;background:linear-gradient(135deg,#101827 0%,#1d2740 58%,#3b1f69 100%);color:#fff;box-shadow:0 10px 30px rgba(16,24,39,.12)}
            .ews-ai-hero h1{margin:0 0 7px;color:#fff;font-size:30px;line-height:1.2}.ews-ai-hero p{margin:0;color:#cbd5e1;font-size:14px}.ews-ai-controls{margin-top:20px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}.ews-ai-controls a,.ews-ai-controls select{min-height:40px;border-radius:9px;border:1px solid #d0d5dd;background:#fff;color:#172033;padding:8px 12px;text-decoration:none}.ews-ai-controls .nav{width:40px;text-align:center;font-size:22px;padding:5px}.ews-ai-controls form{display:flex;gap:10px;align-items:center}.ews-ai-controls input[type=date]{position:static;opacity:1;width:auto;height:40px;min-height:40px;border:1px solid #d0d5dd;border-radius:9px;background:#fff;color:#172033;padding:8px 10px}.ews-ai-focus-date{display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:0 2px;color:#fff;font-size:11px;font-weight:700}.ews-ai-focus-date span{opacity:.82}.ews-ai-focus-date strong{font-size:12px;color:#172033;background:#fff;border-radius:7px;padding:9px 10px}.ews-ai-focus-nav{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 11px;border:1px solid rgba(255,255,255,.18);border-radius:9px;background:rgba(255,255,255,.08)!important;color:#fff!important;font-weight:700!important}.ews-ai-focus-nav:hover{background:rgba(255,255,255,.14)!important}.ews-ai-range{display:inline-flex!important;align-items:center;gap:9px;font-weight:700;cursor:pointer}.ews-ai-range span{font-size:16px}.ews-ai-stats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:18px}.ews-ai-stat{background:#fff;border:1px solid #e4e7ec;border-radius:14px;padding:15px 16px;box-shadow:0 3px 12px rgba(16,24,40,.04)}.ews-ai-stat b{display:block;font-size:25px;color:#172033;line-height:1.1}.ews-ai-stat small{display:block;margin-top:5px;color:#667085;font-weight:600}.ews-ai-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(360px,.75fr);gap:18px;align-items:start}.ews-ai-card{background:#fff;border:1px solid #e4e7ec;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(16,24,40,.045);margin-bottom:18px}.ews-ai-card-head{padding:19px 21px 14px;border-bottom:1px solid #edf0f4}.ews-ai-card-head h2{margin:0 0 4px;font-size:18px;color:#172033}.ews-ai-card-head p{margin:0;color:#667085;font-size:12px}.ews-ai-card-body{padding:18px 20px}.ews-ai-week{overflow:auto}.ews-ai-week table{width:100%;border-collapse:separate;border-spacing:0;min-width:720px}.ews-ai-week th{padding:12px 10px;background:#f8fafc;border-bottom:1px solid #e4e7ec;text-align:left;font-size:11px;color:#475467}.ews-ai-week td{padding:9px 10px;border-bottom:1px solid #edf0f4;vertical-align:middle}.ews-ai-week tr:last-child td{border-bottom:0}.ews-ai-week th:first-child,.ews-ai-week td:first-child{position:sticky;left:0;background:#fff;z-index:1}.ews-ai-week th:first-child{background:#f8fafc}.ews-ai-day-head{text-align:center!important}.ews-ai-day-head strong{display:block;color:#172033}.ews-ai-day-head small{display:block;color:#98a2b3;font-weight:500}.ews-ai-day-head em{display:inline-block;margin-top:4px;padding:2px 6px;border-radius:999px;background:#f3e8ff;color:#6b21a8;font-size:9px;font-style:normal}.ews-ai-rowlabel{font-weight:700;color:#344054;white-space:nowrap}.ews-ai-rowlabel span{display:block;color:#98a2b3;font-size:10px;font-weight:500;margin-top:2px}.ews-ai-count{display:flex;align-items:center;justify-content:center;width:100%;min-height:46px;border:1px solid #e4e7ec;border-radius:10px;background:#fff;cursor:pointer;font-size:18px;font-weight:800;color:#344054}.ews-ai-count small{font-size:10px;margin-left:4px;font-weight:600;color:#98a2b3}.ews-ai-count:hover{border-color:#a78bfa;box-shadow:0 3px 10px rgba(91,33,182,.09);transform:translateY(-1px)}.ews-ai-office{background:#eff6ff;border-color:#bfdbfe;color:#2563eb}.ews-ai-wfh{background:#f5f3ff;border-color:#ddd6fe;color:#7c3aed}.ews-ai-leave{background:#fffbeb;border-color:#fde68a;color:#b45309}.ews-ai-trip{background:#ecfeff;border-color:#a5f3fc;color:#0e7490}.ews-ai-absent-plan{background:#fff7ed;border-color:#fed7aa;color:#c2410c}.ews-ai-notset{background:#f8fafc;color:#667085}.ews-ai-actual-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ews-ai-actual{border:1px solid #e4e7ec;border-radius:12px;padding:13px 14px;background:#fff;cursor:pointer;text-align:left}.ews-ai-actual:hover{border-color:#c4b5fd;box-shadow:0 3px 10px rgba(91,33,182,.08)}.ews-ai-actual b{font-size:22px;color:#172033;display:block}.ews-ai-actual span{font-size:11px;color:#667085;font-weight:700}.ews-ai-actual.present{background:#f0fdf4;border-color:#bbf7d0}.ews-ai-actual.late{background:#fff7ed;border-color:#fed7aa}.ews-ai-actual.absent{background:#fef2f2;border-color:#fecaca}.ews-ai-actual.pending{background:#f8fafc}.ews-ai-attention{display:flex;flex-direction:column;gap:8px}.ews-ai-attn{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid #edf0f4;border-radius:10px}.ews-ai-attn strong{display:block;color:#344054;font-size:12px}.ews-ai-attn small{display:block;color:#667085;font-size:11px;margin-top:2px}.ews-ai-attn button{border:0;background:#f5f3ff;color:#6d28d9;border-radius:8px;padding:6px 9px;font-size:11px;font-weight:700;cursor:pointer}.ews-ai-emp-table{overflow:auto}.ews-ai-emp-table table{width:100%;border-collapse:collapse;min-width:680px}.ews-ai-emp-table th{font-size:10px;text-transform:uppercase;letter-spacing:.03em;color:#667085;background:#f8fafc;padding:10px;text-align:left}.ews-ai-emp-table td{padding:10px;border-top:1px solid #edf0f4;font-size:12px;color:#344054}.ews-ai-emp-name{font-weight:700;color:#172033}.ews-ai-rate{font-weight:800}.ews-ai-link{color:#6d28d9;text-decoration:none;font-weight:700}.ews-ai-empty{padding:18px;color:#667085;text-align:center}.ews-ai-drawer{position:fixed;inset:0;z-index:100000;display:none;background:rgba(16,24,40,.35)}.ews-ai-drawer.open{display:block}.ews-ai-panel{position:absolute;right:0;top:0;height:100%;width:min(430px,94vw);background:#fff;box-shadow:-12px 0 35px rgba(16,24,40,.18);padding:24px;overflow:auto}.ews-ai-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:18px}.ews-ai-panel-head h2{margin:0;color:#172033;font-size:20px}.ews-ai-panel-head p{margin:4px 0 0;color:#667085;font-size:12px}.ews-ai-close{border:0;background:#f2f4f7;border-radius:8px;width:34px;height:34px;font-size:20px;cursor:pointer}.ews-ai-names{display:flex;flex-direction:column;gap:7px}.ews-ai-name{padding:10px 12px;border:1px solid #edf0f4;border-radius:9px;background:#f8fafc;font-weight:600;color:#344054}.ews-ai-subtle{color:#667085;font-size:11px}.ews-ai-mini-note{margin-top:8px;color:#667085;font-size:11px}.ews-ai-mobile{display:none}
            .ews-ai-today-card{margin-bottom:18px}.ews-ai-today-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.ews-ai-today-tile{display:flex;align-items:center;gap:10px;text-align:left;border:1px solid #e4e7ec;border-radius:12px;padding:13px 12px;background:#fff;cursor:pointer;transition:.16s ease}.ews-ai-today-tile:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,24,40,.07)}.ews-ai-today-icon{font-size:20px}.ews-ai-today-tile b{display:block;font-size:22px;line-height:1;color:#172033}.ews-ai-today-tile small{display:block;margin-top:4px;font-size:10px;font-weight:700;color:#667085}.ews-ai-today-divider{height:1px;background:#edf0f4;margin:17px 0}.ews-ai-today-attendance{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px}.ews-ai-today-attendance .ews-ai-actual{min-height:68px}.ews-ai-actual.missing{background:#fff7ed;border-color:#fed7aa}.ews-ai-secondary-today{display:none}
            @media(max-width:1000px){.ews-ai-today-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.ews-ai-today-attendance{grid-template-columns:repeat(3,minmax(0,1fr))}.ews-ai-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.ews-ai-grid{grid-template-columns:1fr}}
            @media(max-width:700px){.ews-ai-today-grid,.ews-ai-today-attendance{grid-template-columns:repeat(2,minmax(0,1fr))}.ews-ai-admin{margin-right:10px}.ews-ai-hero{padding:22px}.ews-ai-hero h1{font-size:24px}.ews-ai-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ews-ai-actual-list{grid-template-columns:1fr}.ews-ai-controls form{width:100%}.ews-ai-focus-form{width:auto!important}.ews-ai-controls select{flex:1}.ews-ai-controls .ews-ai-range{flex:1;justify-content:center}}
            </style>';

            echo '<div class="ews-ai-hero"><h1>Attendance Insights</h1><p>Manager view for daily workforce distribution, attendance performance and employee drill-down.</p><div class="ews-ai-controls">';
            echo '<a class="nav" href="'.esc_url($prev).'" aria-label="Previous week">‹</a>';
            echo '<form method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="team" value="'.esc_attr($selected_team).'">';
            echo '<label class="ews-ai-range"><span>📅</span><strong>'.$week_label.'</strong><input type="date" name="week" value="'.esc_attr($week_start).'" onchange="this.form.submit()" aria-label="Choose week"></label></form>';
            echo '<a class="nav" href="'.esc_url($next).'" aria-label="Next week">›</a>';
            echo '<a class="ews-ai-focus-nav" href="'.esc_url($focus_prev).'" aria-label="Previous focus day">‹ Day</a>';
            echo '<form class="ews-ai-focus-form" method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="week" value="'.esc_attr($week_start).'"><input type="hidden" name="team" value="'.esc_attr($selected_team).'"><label class="ews-ai-focus-date"><span>Focus day</span><input type="date" name="focus_date" value="'.esc_attr($focus_date).'" onchange="this.form.submit()" aria-label="Choose focus day"><strong>'.esc_html($focus_label_date).'</strong></label></form>';
            echo '<a class="ews-ai-focus-nav" href="'.esc_url($focus_next).'" aria-label="Next focus day">Day ›</a>';
            echo '<form method="get"><input type="hidden" name="page" value="ews31-attendance-insights"><input type="hidden" name="week" value="'.esc_attr($week_start).'">';
            echo '<select name="team" onchange="this.form.submit()"><option value="all">All Teams</option>';
            foreach($team_options as $team_name)echo '<option value="'.esc_attr($team_name).'" '.selected($selected_team,$team_name,false).'>'.esc_html($team_name).'</option>';
            echo '</select></form></div></div>';

            $week_rate=($overall['Present']+$overall['Late']+$overall['Absent'])?round((($overall['Present']+$overall['Late'])/($overall['Present']+$overall['Late']+$overall['Absent']))*100):0;
            $focus_rate=($focus_actual['Present']+$focus_actual['Late']+$focus_actual['Absent'])?round((($focus_actual['Present']+$focus_actual['Late'])/($focus_actual['Present']+$focus_actual['Late']+$focus_actual['Absent']))*100):0;
            echo '<div class="ews-ai-stats"><div class="ews-ai-stat"><b>'.count($emps).'</b><small>Employees</small></div><div class="ews-ai-stat"><b>'.(int)$focus_plan['Office'].'</b><small>Office Today</small></div><div class="ews-ai-stat"><b>'.(int)$focus_plan['WFH'].'</b><small>WFH Today</small></div><div class="ews-ai-stat"><b>'.(int)$focus_plan['Leave'].'</b><small>Leave Today</small></div><div class="ews-ai-stat"><b>'.$focus_rate.'%</b><small>Today Attendance</small></div></div>';

            echo '<div class="ews-ai-card ews-ai-today-card"><div class="ews-ai-card-head"><h2>Attendance Overview · '.esc_html(date_i18n('l, d M Y',strtotime($focus_date))).'</h2><p>Click any workforce or attendance count to see the employees behind it.</p></div><div class="ews-ai-card-body"><div class="ews-ai-today-grid">';
            $today_dist=[['Office','🏢','ews-ai-office'],['WFH','🏠','ews-ai-wfh'],['Leave','🌴','ews-ai-leave'],['Business Trip','✈️','ews-ai-trip'],['Absent','⚠️','ews-ai-absent-plan'],['Not Set','○','ews-ai-notset']];
            foreach($today_dist as $row){$label=$row[0];$names=$focus_drill['plan'][$label]??[];echo '<button type="button" class="ews-ai-today-tile '.$row[2].'" data-kind="planned" data-label="'.esc_attr($label).'" data-date="'.esc_attr(date('D, d M Y',strtotime($focus_date))).'" data-names="'.esc_attr(wp_json_encode($names)).'"><span class="ews-ai-today-icon">'.$row[1].'</span><span><b>'.count($names).'</b><small>'.esc_html($label).'</small></span></button>';}
            echo '</div><div class="ews-ai-today-divider"></div><div class="ews-ai-today-attendance">';
            $today_actual_labels=[['Present','present'],['Late','late'],['Absent','absent'],['Pending','pending'],['Leave','leave']];
            foreach($today_actual_labels as $row){$label=$row[0];$names=$focus_drill['actual'][$label]??[];echo '<button type="button" class="ews-ai-actual '.$row[1].'" data-kind="actual" data-label="'.esc_attr($label).'" data-date="'.esc_attr(date('D, d M Y',strtotime($focus_date))).'" data-names="'.esc_attr(wp_json_encode($names)).'"><b>'.count($names).'</b><span>'.esc_html($label).'</span></button>';}
            echo '<button type="button" class="ews-ai-actual missing" data-kind="actual" data-label="Missing Sign-out" data-date="'.esc_attr(date('D, d M Y',strtotime($focus_date))).'" data-names="'.esc_attr(wp_json_encode($focus_missing_sign_out)).'"><b>'.count($focus_missing_sign_out).'</b><span>Missing Sign-out</span></button>';
            echo '</div></div></div>';

            echo '<div class="ews-ai-grid"><div>';
            echo '<div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Daily Workforce Overview</h2><p>Planned distribution. Click any number to see the employees behind it.</p></div><div class="ews-ai-week"><table><thead><tr><th>Planned</th>';
            foreach($dates as $d)echo '<th class="ews-ai-day-head"><strong>'.esc_html(date('D',strtotime($d))).'</strong><small>'.esc_html(date('d M',strtotime($d))).'</small>'.($d===$today?'<em>Today</em>':'').'</th>';
            echo '</tr></thead><tbody>';
            $plan_rows=[['Office','ews-ai-office'],['WFH','ews-ai-wfh'],['Leave','ews-ai-leave'],['Business Trip','ews-ai-trip'],['Absent','ews-ai-absent-plan'],['Not Set','ews-ai-notset']];
            foreach($plan_rows as $pr){echo '<tr><td class="ews-ai-rowlabel">'.esc_html($pr[0]).'<span>Planned</span></td>';foreach($dates as $d){$names=$drill[$d]['plan'][$pr[0]]??[];$count=count($names);echo '<td><button type="button" class="ews-ai-count '.$pr[1].'" data-kind="planned" data-label="'.esc_attr($pr[0]).'" data-date="'.esc_attr(date('D, d M Y',strtotime($d))).'" data-names="'.esc_attr(wp_json_encode($names)).'">'.$count.'</button></td>';}echo '</tr>';}
            echo '</tbody></table></div></div>';

            $focus_is_today=($focus_date===$today);
            $focus_label=$focus_is_today?'Today':date_i18n('D, d M Y',strtotime($focus_date));
            echo '<div class="ews-ai-card ews-ai-secondary-today"><div class="ews-ai-card-head"><h2>'.esc_html($focus_is_today?'Today’s Attendance':'Selected Day Attendance').'</h2><p>'.esc_html($scheduled_focus).' employees are scheduled Office/WFH on '.esc_html($focus_label).'.</p></div><div class="ews-ai-card-body"><div class="ews-ai-actual-list">';
            foreach(['Present','Late','Absent','Pending','Leave'] as $label){$names=$focus_drill['actual'][$label]??[];$cls=strtolower($label);echo '<button type="button" class="ews-ai-actual '.$cls.'" data-kind="actual" data-label="'.esc_attr($label).'" data-date="'.esc_attr(date('D, d M Y',strtotime($focus_date))).'" data-names="'.esc_attr(wp_json_encode($names)).'"><b>'.count($names).'</b><span>'.esc_html($label).'</span></button>';}
            echo '</div></div></div>';
            echo '</div><div>';

            echo '<div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Workforce Distribution</h2><p>Today’s planned location/status mix.</p></div><div class="ews-ai-card-body">';
            $dist=[['Office','🏢'],['WFH','🏠'],['Leave','🌴'],['Business Trip','✈️']];foreach($dist as $drow){$label=$drow[0];$count=(int)($focus_plan[$label]??0);$names=$focus_drill['plan'][$label]??[];echo '<button type="button" class="ews-ai-attn" style="width:100%;border:0;background:#fff;cursor:pointer;text-align:left" data-kind="planned" data-label="'.esc_attr($label).'" data-date="'.esc_attr(date('D, d M Y',strtotime($focus_date))).'" data-names="'.esc_attr(wp_json_encode($names)).'"><span><strong>'.$drow[1].' '.esc_html($label).'</strong><small>Planned employees</small></span><b style="font-size:20px;color:#172033">'.$count.'</b></button>';}
            echo '</div></div>';

            echo '<div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Requires Attention</h2><p>Employees with absence, lateness or incomplete sign-out in the selected week.</p></div><div class="ews-ai-card-body"><div class="ews-ai-attention">';
            $attention=0;foreach($employee_metrics as $row){$e=$row['employee'];$m=$row['metrics'];$issue='';if($m['Absent']>0)$issue=$m['Absent'].' absent day(s)';elseif($m['Late']>0)$issue=$m['Late'].' late day(s)';elseif($m['missing_sign_out']>0)$issue=$m['missing_sign_out'].' missing sign-out(s)';if($issue){$attention++;echo '<div class="ews-ai-attn"><span><strong>'.esc_html($e->name).'</strong><small>'.esc_html($issue).'</small></span><button type="button" data-open-employee="'.(int)$e->id.'">Review</button></div>';if($attention>=5)break;}}
            if(!$attention)echo '<div class="ews-ai-empty">No attention items for this period.</div>';
            echo '</div></div></div>';
            echo '</div></div>';

            echo '<div class="ews-ai-card"><div class="ews-ai-card-head"><h2>Employee Attendance Metrics</h2><p>Selected period: '.esc_html($week_label).'</p></div><div class="ews-ai-emp-table"><table><thead><tr><th>Employee</th><th>Rate</th><th>On-Time</th><th>Late</th><th>Absent</th><th>Missing Sign-out</th></tr></thead><tbody>';
            if(!$employee_metrics)echo '<tr><td colspan="6" class="ews-ai-empty">No employees match the selected filter.</td></tr>';
            foreach($employee_metrics as $row){$e=$row['employee'];$m=$row['metrics'];echo '<tr><td><span class="ews-ai-emp-name">'.esc_html($e->name).'</span><br><span class="ews-ai-subtle">'.esc_html($e->domain_name).'</span></td><td><span class="ews-ai-rate">'.(int)$m['rate'].'%</span></td><td>'.(int)$m['on_time_rate'].'%</td><td>'.(int)$m['Late'].'</td><td>'.(int)$m['Absent'].'</td><td>'.(int)$m['missing_sign_out'].'</td></tr>';}
            echo '</tbody></table></div></div>';

            echo '<div id="ews-ai-drawer" class="ews-ai-drawer" aria-hidden="true"><div class="ews-ai-panel"><div class="ews-ai-panel-head"><div><h2 id="ews-ai-title">Employees</h2><p id="ews-ai-subtitle"></p></div><button type="button" class="ews-ai-close" aria-label="Close">×</button></div><div id="ews-ai-names" class="ews-ai-names"></div></div></div>';
            echo '<script>(function(){function openDrawer(btn){var d=document.getElementById("ews-ai-drawer"),n=document.getElementById("ews-ai-names"),t=document.getElementById("ews-ai-title"),s=document.getElementById("ews-ai-subtitle");if(!d||!n)return;var names=[];try{names=JSON.parse(btn.getAttribute("data-names")||"[]")}catch(e){}var label=btn.getAttribute("data-label")||"Employees",date=btn.getAttribute("data-date")||"";t.textContent=label+(names.length?" · "+names.length:"");s.textContent=date;n.innerHTML="";if(!names.length){n.innerHTML="<div class=\"ews-ai-empty\">No employees in this group.</div>"}else{names.forEach(function(name){var x=document.createElement("div");x.className="ews-ai-name";x.textContent=name;n.appendChild(x)})}d.classList.add("open");d.setAttribute("aria-hidden","false")}function closeDrawer(){var d=document.getElementById("ews-ai-drawer");if(d){d.classList.remove("open");d.setAttribute("aria-hidden","true")}}document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".ews-ai-count,.ews-ai-actual");if(b){openDrawer(b);return}if(e.target.closest&&e.target.closest(".ews-ai-close")){closeDrawer();return}if(e.target.id==="ews-ai-drawer"){closeDrawer()}});document.addEventListener("keydown",function(e){if(e.key==="Escape")closeDrawer()})})();</script>';
            echo '</div>';
        }

    public function admin_attendance(){
            if(!$this->can('ews_manage_attendance')) wp_die('Access denied');
            echo '<div class="wrap"><h1>Attendance</h1><p>Create a WordPress page containing <code>[employee_app]</code> and use <strong>Attendance</strong> there for the full friendly interface.</p></div>';
        }

    public function admin_reports(){
            if(!$this->can('ews_view_reports')) wp_die('Access denied');
            echo '<div class="wrap"><h1>Reports</h1><p>Use <code>[employee_app]</code> → Reports for the frontend report interface.</p></div>';
        }

    public function admin_email(){
            if(!$this->can('ews_manage_settings')) wp_die('Access denied');
            echo '<div class="wrap"><h1>Attendance Email Settings</h1><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">
            <input type="hidden" name="action" value="ews31_email_save">'.wp_nonce_field('ews31_email_save','_wpnonce',true,false).'
            <p>Enter recipient email addresses separated by comma, semicolon or new line.</p>
            <textarea name="emails" style="width:600px;max-width:100%;min-height:120px">'.esc_textarea(get_option('ews_attendance_emails','')).'</textarea>
            <p><button class="button button-primary">Save Email Settings</button></p></form></div>';
        }

    public function auto_attendance_redirect($args=[]){
        $url=admin_url('admin.php?page=ews31-auto-attendance');
        if($args)$url=add_query_arg($args,$url);
        wp_safe_redirect($url); exit;
    }
}
