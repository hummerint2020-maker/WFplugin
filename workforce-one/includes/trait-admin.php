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
            // Keep Employee Profile registered so direct URLs remain authorized.
            // Hide it from the submenu because profiles are opened from Employees.
            add_action('admin_head', function(){
                $screen=get_current_screen();
                if($screen && $screen->id==='toplevel_page_ews31'){
                    echo '<style>#toplevel_page_ews31 .wp-submenu a[href="admin.php?page=ews31-employee-profile"]{display:none!important}</style>';
                }
            });
        }


        public function ews_admin_confirmation_guard(){
            if(!$this->can('ews_manage_settings') && !$this->can('ews_manage_time')) return;
            $global=(int)get_option('ews_confirm_global',1);
            $cfg=\WorkforceOne\Settings\FeatureSettings::confirmState(get_option('ews_confirmation_actions',[]));
            $payload=['global'=>(bool)$global,'actions'=>$cfg];
            echo '<script>window.ewsAdminConfirmationConfig='.wp_json_encode($payload).';(function(){function init(){document.querySelectorAll("[data-ews-confirm-key]").forEach(function(el){if(el.dataset.ewsConfirmBound==="1")return;el.dataset.ewsConfirmBound="1";el.addEventListener("click",function(e){var c=window.ewsAdminConfirmationConfig||{};var k=el.getAttribute("data-ews-confirm-key");if(c.global!==false&&c.actions&&c.actions[k]){var msg=k==="attendance_reset"?"Reset ALL attendance and break records for this employee on this date?":"Remove this General Leave?";if(!window.confirm(msg))e.preventDefault();}});});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init();})();</script>';
        }




    public function admin_achievements(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        global $wpdb;
        $this->ensure_achievements_schema();
        $enabled=$this->achievements_enabled();
        $search=sanitize_text_field(wp_unslash($_GET['achievement_search']??''));
        $progress_type=sanitize_key($_GET['achievement_type']??'all');
        $employee_sql="SELECT id,name,domain_name FROM {$this->employees} WHERE active=1";
        $employee_args=[];
        if($search!==''){
            $like='%'.$wpdb->esc_like($search).'%';
            $employee_sql.=" AND (name LIKE %s OR domain_name LIKE %s)";
            $employee_args=[$like,$like];
        }
        $employee_sql.=" ORDER BY name ASC";
        $employees=$employee_args?$wpdb->get_results($wpdb->prepare($employee_sql,$employee_args)):$wpdb->get_results($employee_sql);
        $defs=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_achievements WHERE active=1 ORDER BY sort_order ASC,id ASC");
        $progress=$enabled?$this->achievement_admin_progress_data($employees,$defs):[];
        $attendance_rows=0;$swap_rows=0;$near_count=0;$earned_count=0;
        foreach($progress as $row){
            if($progress_type==='all' || $progress_type==='attendance')$attendance_rows++;
            if($progress_type==='all' || $progress_type==='swap')$swap_rows++;
            $earned_count+=count($row['attendance']['earned']);
            if(isset($row['attendance']['achievement']) && $row['attendance']['target']>0 && $row['attendance']['current']>0 && ($row['attendance']['current']/$row['attendance']['target'])>=.8 && $row['attendance']['current']<$row['attendance']['target'])$near_count++;
            if(isset($row['swap']['achievement']) && $row['swap']['target']>0 && ($row['swap']['current']/$row['swap']['target'])>=.8 && $row['swap']['current']<$row['swap']['target'])$near_count++;
        }
        echo '<div class="wrap"><h1>Achievements</h1>';
        if(isset($_GET['achievement_saved']))echo '<div class="notice notice-success is-dismissible"><p>Achievement settings saved.</p></div>';
        if(isset($_GET['achievement_granted']))echo '<div class="notice notice-success is-dismissible"><p>Achievement granted successfully. The employee received the normal achievement notification.</p></div>';
        if(isset($_GET['achievement_deleted']))echo '<div class="notice notice-success is-dismissible"><p>Achievement removed from the employee profile.</p></div>';
        if(isset($_GET['achievement_error']))echo '<div class="notice notice-error is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['achievement_error']))).'</p></div>';
        echo '<style>
        .wfo-ach-shell{max-width:1180px}.wfo-ach-card{background:#fff;border:1px solid #dcdcde;border-radius:14px;margin:16px 0;padding:22px;box-shadow:0 2px 8px rgba(16,24,40,.04)}.wfo-ach-head{display:flex;justify-content:space-between;gap:20px;align-items:center}.wfo-ach-muted{color:#667085;font-size:13px;line-height:1.55}.wfo-ach-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wfo-ach-form-grid .full{grid-column:1/-1}.wfo-ach-form-grid label{display:block;font-weight:600;color:#344054;font-size:13px}.wfo-ach-form-grid input,.wfo-ach-form-grid select,.wfo-ach-form-grid textarea{width:100%;margin-top:6px;box-sizing:border-box}.wfo-ach-icons{display:flex;gap:8px;flex-wrap:wrap;margin-top:7px}.wfo-ach-icon{width:42px;height:42px;border:1px solid #d0d5dd;background:#fff;border-radius:10px;font-size:21px;cursor:pointer}.wfo-ach-icon.selected{border-color:#3158c8;box-shadow:0 0 0 2px #dbe4ff;background:#f7f8ff}.wfo-ach-preview{display:flex;align-items:center;gap:12px;margin-top:12px}.wfo-ach-preview-badge{width:58px;height:58px;display:flex;align-items:center;justify-content:center;font-size:28px;background:#fff7e6;border:1px solid #f3d9a2}.wfo-ach-preview-badge.circle{border-radius:50%}.wfo-ach-preview-badge.shield{border-radius:18px 18px 24px 24px;clip-path:polygon(50% 0,90% 15%,90% 58%,50% 100%,10% 58%,10% 15%)}.wfo-ach-preview-badge.star{border-radius:18px;clip-path:polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 95%,50% 72%,21% 95%,32% 57%,2% 35%,39% 35%)}.wfo-ach-preview-badge.ribbon{border-radius:10px 10px 18px 18px}.wfo-ach-list{width:100%;border-collapse:collapse}.wfo-ach-list th,.wfo-ach-list td{padding:10px 8px;border-bottom:1px solid #eef0f3;text-align:left;font-size:12px}.wfo-ach-badge{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;background:#fff7e6;border:1px solid #f3d9a2;font-size:18px;vertical-align:middle}.wfo-ach-badge.circle{border-radius:50%}.wfo-ach-badge.shield{border-radius:10px 10px 14px 14px}.wfo-ach-badge.star{border-radius:10px}.wfo-ach-badge.ribbon{border-radius:8px 8px 12px 12px}
        .wfo-ach-progress-intro{display:flex;justify-content:space-between;gap:18px;align-items:flex-end}.wfo-ach-stat-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:18px 0}.wfo-ach-stat{border:1px solid #eaecf0;border-radius:12px;padding:14px 16px;background:#fcfcfd}.wfo-ach-stat .k{font-size:12px;color:#667085}.wfo-ach-stat .v{font-size:24px;font-weight:700;color:#101828;margin-top:4px}.wfo-ach-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:18px 0}.wfo-ach-toolbar input[type=text],.wfo-ach-toolbar select{min-height:38px}.wfo-ach-toolbar input[type=text]{min-width:260px}.wfo-ach-progress-table{width:100%;border-collapse:separate;border-spacing:0}.wfo-ach-progress-table th{background:#f8fafc;color:#667085;font-size:12px;font-weight:600;text-align:left;padding:11px 12px;border-bottom:1px solid #eaecf0}.wfo-ach-progress-table td{padding:14px 12px;border-bottom:1px solid #f0f2f5;vertical-align:top}.wfo-ach-progress-table tr:last-child td{border-bottom:0}.wfo-ach-employee{display:flex;gap:10px;align-items:center;min-width:190px}.wfo-ach-avatar{width:36px;height:36px;border-radius:10px;background:#eff4ff;color:#3158c8;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex:0 0 auto}.wfo-ach-employee strong{display:block;color:#101828;font-size:13px}.wfo-ach-employee span{display:block;color:#98a2b3;font-size:11px;margin-top:2px}.wfo-ach-progress-cell{min-width:220px}.wfo-ach-progress-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:7px}.wfo-ach-progress-title{font-weight:600;color:#344054;font-size:12px}.wfo-ach-progress-value{font-weight:700;color:#101828;font-size:12px}.wfo-ach-progress-track{height:8px;border-radius:999px;background:#eaecf0;overflow:hidden}.wfo-ach-progress-fill{height:100%;border-radius:999px;background:#3158c8}.wfo-ach-progress-sub{font-size:11px;color:#667085;margin-top:6px}.wfo-ach-progress-earned{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.wfo-ach-earned-pill{display:inline-flex;align-items:center;gap:4px;padding:3px 7px;border-radius:999px;background:#ecfdf3;color:#067647;font-size:10px;border:1px solid #abefc6}.wfo-ach-complete{font-size:12px;color:#067647;font-weight:600}.wfo-ach-empty{padding:24px 8px;color:#667085;text-align:center}.wfo-ach-legend{display:flex;gap:14px;flex-wrap:wrap;color:#667085;font-size:11px}.wfo-ach-near{color:#b54708;font-weight:600}.wfo-ach-near-dot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#f79009;margin-right:5px}
        @media(max-width:1100px){.wfo-ach-progress-table{min-width:920px}.wfo-ach-progress-scroll{overflow-x:auto}.wfo-ach-progress-cell{min-width:260px}}@media(max-width:782px){.wfo-ach-form-grid{grid-template-columns:1fr}.wfo-ach-form-grid .full{grid-column:auto}.wfo-ach-head,.wfo-ach-progress-intro{display:block}.wfo-ach-stat-grid{grid-template-columns:1fr}.wfo-ach-toolbar input[type=text]{min-width:0;width:100%}.wfo-ach-toolbar{align-items:stretch}.wfo-ach-toolbar select,.wfo-ach-toolbar .button{width:100%}}
        </style>';
        echo '<div class="wfo-ach-shell">';
        echo '<div class="wfo-ach-card"><div class="wfo-ach-head"><div><h2 style="margin:0 0 5px">Achievements Feature</h2><div class="wfo-ach-muted">Controls automatic achievements, profile display, and recognition notifications. Existing earned records are preserved if the feature is disabled.</div></div><strong style="color:'.($enabled?'#067647':'#b42318').';font-size:14px">'.($enabled?'Enabled':'Disabled').'</strong></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:18px">'.wp_nonce_field('ews_achievements_settings_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_achievements_settings_save"><label><input type="checkbox" name="achievements_enabled" value="1" '.checked($enabled,true,false).'> Enable Achievements</label><p><button class="button button-primary">Save Achievement Settings</button></p></form></div>';

        if($enabled){
            echo '<div class="wfo-ach-card"><div class="wfo-ach-progress-intro"><div><h2 style="margin:0 0 5px">Employee Achievement Progress</h2><div class="wfo-ach-muted">Admin-only view of each employee\'s current progress toward the next Attendance and Swap achievement. This is progress visibility, not a ranking.</div></div><div class="wfo-ach-legend"><span>🔥 Attendance streak</span><span>🤝 Successful swaps</span></div></div>';
            echo '<div class="wfo-ach-stat-grid"><div class="wfo-ach-stat"><div class="k">Employees tracked</div><div class="v">'.(int)count($progress).'</div></div><div class="wfo-ach-stat"><div class="k">Near an achievement</div><div class="v">'.(int)$near_count.'</div></div><div class="wfo-ach-stat"><div class="k">Earned achievements</div><div class="v">'.(int)$earned_count.'</div></div></div>';
            echo '<form method="get" class="wfo-ach-toolbar"><input type="hidden" name="page" value="ews31-achievements"><input type="text" name="achievement_search" value="'.esc_attr($search).'" placeholder="Search employee name or domain"><select name="achievement_type"><option value="all" '.selected($progress_type,'all',false).'>Attendance + Swap</option><option value="attendance" '.selected($progress_type,'attendance',false).'>Attendance</option><option value="swap" '.selected($progress_type,'swap',false).'>Swap</option></select><button class="button">Filter</button>'; if($search!==''||$progress_type!=='all')echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-achievements')).'">Reset</a>'; echo '</form>';
            echo '<div class="wfo-ach-progress-scroll"><table class="wfo-ach-progress-table"><thead><tr><th>Employee</th><th>Attendance Streak</th><th>Successful Swaps</th></tr></thead><tbody>';
            $shown=0;
            foreach($progress as $row){
                $show_att=$progress_type==='all'||$progress_type==='attendance';$show_swap=$progress_type==='all'||$progress_type==='swap';
                if(!$show_att && !$show_swap)continue;
                $shown++;$e=$row['employee'];$initials='';foreach(preg_split('/\s+/',trim((string)$e->name)) as $part){if($part!=='')$initials.=mb_strtoupper(mb_substr($part,0,1));} $initials=mb_substr($initials,0,2);
                echo '<tr><td><div class="wfo-ach-employee"><span class="wfo-ach-avatar">'.esc_html($initials?:'?').'</span><span><strong>'.esc_html($e->name).'</strong><span>'.esc_html($e->domain_name?:'Employee').'</span></span></div></td>';
                if($show_att){
                    $a=$row['attendance'];$target=(int)$a['target'];$current=(int)$a['current'];$pct=$target>0?min(100,round(($current/$target)*100)):100;
                    if($a['achievement']){
                        $near=$current>0&&$target>$current&&$pct>=80;
                        echo '<td><div class="wfo-ach-progress-cell"><div class="wfo-ach-progress-head"><span class="wfo-ach-progress-title">'.esc_html($a['achievement']->icon.' '.$a['achievement']->name).'</span><span class="wfo-ach-progress-value">'.(int)$current.' / '.(int)$target.'</span></div><div class="wfo-ach-progress-track"><div class="wfo-ach-progress-fill" style="width:'.(int)$pct.'%"></div></div><div class="wfo-ach-progress-sub">'.($near?'<span class="wfo-ach-near"><span class="wfo-ach-near-dot"></span>':'').esc_html((int)$a['remaining'].' more consecutive days').($near?'</span>':'').' </div>';
                        if(!empty($a['earned'])){echo '<div class="wfo-ach-progress-earned">';foreach($defs as $d){if($d->category==='attendance'&&isset($a['earned'][(int)$d->id]))echo '<span class="wfo-ach-earned-pill">'.esc_html($d->icon.' '.$d->name).'</span>';}echo '</div>';}
                        echo '</div></td>';
                    } else echo '<td><span class="wfo-ach-complete">🏆 All Attendance achievements earned</span></td>';
                } else echo '<td>—</td>';
                if($show_swap){
                    $sw=$row['swap'];$target=(int)$sw['target'];$current=(int)$sw['current'];$pct=$target>0?min(100,round(($current/$target)*100)):100;
                    if($sw['achievement']){
                        $near=$target>$current&&$pct>=80;
                        echo '<td><div class="wfo-ach-progress-cell"><div class="wfo-ach-progress-head"><span class="wfo-ach-progress-title">'.esc_html($sw['achievement']->icon.' '.$sw['achievement']->name).'</span><span class="wfo-ach-progress-value">'.(int)$current.' / '.(int)$target.'</span></div><div class="wfo-ach-progress-track"><div class="wfo-ach-progress-fill" style="width:'.(int)$pct.'%"></div></div><div class="wfo-ach-progress-sub">'.($near?'<span class="wfo-ach-near"><span class="wfo-ach-near-dot"></span>':'').esc_html((int)$sw['remaining'].' more successful swaps').($near?'</span>':'').' </div>'; if(!empty($sw['earned'])){echo '<div class="wfo-ach-progress-earned">';foreach($defs as $d){if($d->category==='collaboration'&&isset($sw['earned'][(int)$d->id]))echo '<span class="wfo-ach-earned-pill">'.esc_html($d->icon.' '.$d->name).'</span>';}echo '</div>';} echo '</div></td>';
                    } else echo '<td><span class="wfo-ach-complete">🏆 All Swap achievements earned</span></td>';
                } else echo '<td>—</td>';
                echo '</tr>';
            }
            if(!$shown)echo '<tr><td colspan="3" class="wfo-ach-empty">No active employees match the current filter.</td></tr>';
            echo '</tbody></table></div></div>';

            echo '<div class="wfo-ach-card"><h2 style="margin-top:0">Grant Achievement to Employee</h2><p class="wfo-ach-muted">Manual achievements are stored as real earned achievements. The employee receives the same in-app and push congratulations flow as an automatic achievement.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_achievement_manual_grant','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_achievement_manual_grant"><div class="wfo-ach-form-grid"><label>Employee<select name="employee_id" required><option value="">Select employee</option>'; foreach((array)$employees as $e){echo '<option value="'.(int)$e->id.'">'.esc_html($e->name.($e->domain_name?' · '.$e->domain_name:'')).'</option>';} echo '</select></label><label>Achievement Name<input type="text" name="achievement_name" maxlength="120" required placeholder="e.g. Team Player"></label><label class="full">Description<textarea name="achievement_description" rows="3" maxlength="500" placeholder="e.g. Helped a teammate when they needed a shift swap."></textarea></label><label class="full">Badge Shape<input type="hidden" id="wfo-ach-icon" name="achievement_icon" value="🏅"><div class="wfo-ach-icons">'; foreach(['🏅','🤝','⭐','🔥','🏆','💎','👑','🚀','💡','🎯','🌟','❤️'] as $ic){echo '<button type="button" class="wfo-ach-icon'.($ic==='🏅'?' selected':'').'" data-icon="'.esc_attr($ic).'">'.esc_html($ic).'</button>';} echo '</div><span class="wfo-ach-muted">Choose the badge symbol shown on the employee profile.</span></label><label>Badge Shape<select name="badge_style" id="wfo-ach-style"><option value="circle">Circle</option><option value="shield">Shield</option><option value="star">Star</option><option value="ribbon">Ribbon</option></select><div class="wfo-ach-preview"><span class="wfo-ach-preview-badge circle" id="wfo-ach-preview">🏅</span><span class="wfo-ach-muted">Preview</span></div></label></div><p><button class="button button-primary">Grant Achievement & Notify Employee</button></p></form><script>(function(){var icon=document.getElementById("wfo-ach-icon"),preview=document.getElementById("wfo-ach-preview"),style=document.getElementById("wfo-ach-style");document.querySelectorAll(".wfo-ach-icon").forEach(function(b){b.addEventListener("click",function(){document.querySelectorAll(".wfo-ach-icon").forEach(function(x){x.classList.remove("selected")});b.classList.add("selected");icon.value=b.dataset.icon;preview.textContent=b.dataset.icon})});style.addEventListener("change",function(){preview.className="wfo-ach-preview-badge "+style.value})})();</script></div>';
        }

        echo '<div class="wfo-ach-card"><h2 style="margin-top:0">Automatic Achievements</h2><p class="wfo-ach-muted">These are the built-in achievements evaluated by Workforce One.</p><table class="wfo-ach-list"><thead><tr><th>Badge</th><th>Name</th><th>Category</th><th>Rule</th><th>Threshold</th></tr></thead><tbody>'; foreach((array)$defs as $d){echo '<tr><td><span class="wfo-ach-badge '.esc_attr($d->badge_style).'">'.esc_html($d->icon).'</span></td><td><strong>'.esc_html($d->name).'</strong><br><span class="wfo-ach-muted">'.esc_html($d->description).'</span></td><td>'.esc_html(ucwords(str_replace('_',' ',$d->category))).'</td><td>'.esc_html(ucwords(str_replace('_',' ',$d->rule_type))).'</td><td>'.(int)$d->threshold.'</td></tr>';} echo '</tbody></table></div></div></div>';
    }

    public function achievement_award_delete(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $award_id=absint($_GET['award_id']??0);
        $employee_id=absint($_GET['employee_id']??0);
        check_admin_referer('ews_achievement_award_delete_'.(int)$award_id);
        if(!$award_id || !$employee_id)wp_die('Achievement award not found.');
        global $wpdb;
        $awards=$wpdb->prefix.'ews_employee_achievements';
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.id,a.employee_id,a.achievement_id,a.earned_at,d.name,d.slug FROM {$awards} a INNER JOIN {$wpdb->prefix}ews_achievements d ON d.id=a.achievement_id WHERE a.id=%d AND a.employee_id=%d LIMIT 1",$award_id,$employee_id));
        if(!$row)wp_die('Achievement award not found.');
        $emp=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
        $deleted=$wpdb->delete($awards,['id'=>$award_id,'employee_id'=>$employee_id],['%d','%d']);
        if($deleted===false)wp_die('Could not delete the achievement award.');
        $employee_name=$emp?$emp->name:('Employee #'.$employee_id);
        $this->audit('achievement_award_deleted','employee_achievement',(int)$award_id,sprintf('%s achievement award removed: %s (%s)', $employee_name, $row->name, $row->slug));
        $url=add_query_arg(['page'=>'ews31-employee-profile','employee_id'=>$employee_id,'achievement_deleted'=>1],admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function achievements_settings_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_achievements_settings_save');
        $enabled=!empty($_POST['achievements_enabled']);
        update_option('ews_feature_achievements',$enabled,false);
        $this->audit('achievement_feature_update','settings',0,$enabled?'enabled':'disabled');
        $this->redirect(['page'=>'ews31-achievements','achievement_saved'=>1]);
    }

    public function achievement_manual_grant(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_achievement_manual_grant');
        if(!$this->achievements_enabled())wp_die('Enable Achievements before granting an achievement.');
        global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        $name=sanitize_text_field(wp_unslash($_POST['achievement_name']??''));
        $description=sanitize_textarea_field(wp_unslash($_POST['achievement_description']??''));
        $icon=sanitize_text_field(wp_unslash($_POST['achievement_icon']??'🏅'));
        $style=sanitize_key($_POST['badge_style']??'circle');
        $allowed=['circle','shield','star','ribbon']; if(!in_array($style,$allowed,true))$style='circle';
        if(!$employee_id||$name==='')wp_die('Employee and achievement name are required.');
        $emp=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
        if(!$emp)wp_die('Employee not found.');
        if($description==='')$description='A special recognition from Workforce One.';
        if($this->create_manual_achievement($employee_id,$name,$description,$icon,$style)){
            $this->redirect(['page'=>'ews31-achievements','achievement_granted'=>1]);
        }
        $this->redirect(['page'=>'ews31-achievements','achievement_error'=>'Could not grant the achievement.']);
    }

    public function admin_smart_nudges(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $cfg=$this->smart_nudge_settings();
        echo '<div class="wrap"><h1>Smart Nudges</h1>';
        if(isset($_GET['smart_nudges_saved']))echo '<div class="notice notice-success is-dismissible"><p>Smart Nudge settings saved.</p></div>';
        if(isset($_GET['smart_nudge_test'])){ $ok=$_GET['smart_nudge_test']==='test_sent'; echo '<div class="notice '.($ok?'notice-success':'notice-warning').' is-dismissible"><p>'.esc_html($ok?'Test push was accepted for delivery.':'Test push was not sent. Check the Push subscription and Notification Policy for this admin user.').'</p></div>'; }
        echo '<style>.wfo-nudge-shell{max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(16,24,40,.04)}.wfo-nudge-intro{padding:24px 28px;background:linear-gradient(180deg,#fafaff,#fff);border-bottom:1px solid #e8e8ec}.wfo-nudge-section{padding:22px 28px;border-bottom:1px solid #ececf0}.wfo-nudge-row{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}.wfo-nudge-title{font-size:16px;font-weight:700;color:#172b24}.wfo-nudge-desc{margin-top:4px;color:#667085;font-size:13px;line-height:1.5;max-width:650px}.wfo-nudge-subgrid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:14px;margin-top:16px;padding:16px;background:#fafafa;border:1px solid #ececf0;border-radius:10px}.wfo-nudge-subgrid label{display:flex;align-items:center;gap:8px;color:#475467;font-size:13px}.wfo-nudge-subgrid label span{font-weight:600}.wfo-nudge-subgrid input[type=number]{width:82px}.wfo-nudge-save{padding:20px 28px;background:#fafafa;text-align:right}.wfo-nudge-save .button-primary{min-height:40px;padding:0 18px;border-radius:8px}@media(max-width:782px){.wfo-nudge-subgrid{grid-template-columns:1fr}.wfo-nudge-row{display:block}.wfo-nudge-row label{display:block;margin-top:12px}.wfo-nudge-section,.wfo-nudge-intro,.wfo-nudge-save{padding:20px}}</style>';
        echo '<div class="wfo-nudge-shell" style="margin-top:18px">';
        echo '<div class="wfo-nudge-intro"><p style="margin:0;color:#667085;font-size:14px;line-height:1.6">Smart Nudges provide lightweight employee reminders. Sign In Reminder is an independent attendance reminder: it can send a push notification once after the configured delay by default, with optional repeat reminders. No database tables are required.</p></div>';
        // Keep diagnostic test form separate; nested HTML forms make the settings Save button unreliable in browsers.
        echo '<form id="wfo-nudge-test-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_smart_nudge_test_push','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_smart_nudge_test_push"></form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo wp_nonce_field('ews_smart_nudges_save','_wpnonce',true,false);
        echo '<input type="hidden" name="action" value="ews_smart_nudges_save">';
        echo '<div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title">Enable Smart Nudges</div><div class="wfo-nudge-desc">Enable the employee-facing Smart Nudge layer for Tasks, Leave and Schedule. Sign In Reminder is independent.</div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="enabled" value="1" '.checked(!empty($cfg['enabled']),true,false).'> Enabled</label></div></div>';
        echo '<div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title">Sign In Reminder</div><div class="wfo-nudge-desc">Send a push notification when the employee has not signed in after their own scheduled start time.</div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="items[attendance]" value="1" '.checked(!empty($cfg['items']['attendance']),true,false).'> Enabled</label></div>';
        echo '<div class="wfo-nudge-subgrid">';
        echo '<label><span>Remind after</span><input type="number" name="attendance_after" min="1" max="240" value="'.esc_attr((int)$cfg['attendance_after']).'"> <em>minutes</em></label>';
        echo '<label><span>Repeat reminder</span><input type="checkbox" name="attendance_repeat" value="1" '.checked(!empty($cfg['attendance_repeat']),true,false).'> Enabled</label>';
        echo '<label><span>Repeat every</span><input type="number" name="attendance_repeat_interval" min="5" max="240" value="'.esc_attr((int)$cfg['attendance_repeat_interval']).'"> <em>minutes</em></label>';
        echo '<label><span>Maximum reminders</span><input type="number" name="attendance_max_reminders" min="1" max="10" value="'.esc_attr((int)$cfg['attendance_max_reminders']).'"><em> total, including the first</em></label>';
        echo '</div></div>';
        $items=[
            'tasks'=>'Task reminder — tasks due today or overdue.',
            'leave'=>'Leave reminder — pending leave requests awaiting a decision.',
            'schedule'=>'Schedule reminder — tomorrow\'s planned schedule.',
        ];
        foreach($items as $key=>$desc){
            echo '<div class="wfo-nudge-section"><div class="wfo-nudge-row"><div><div class="wfo-nudge-title">'.esc_html(ucfirst($key)).'</div><div class="wfo-nudge-desc">'.esc_html($desc).'</div></div><label style="font-weight:700;white-space:nowrap"><input type="checkbox" name="items['.esc_attr($key).']" value="1" '.checked(!empty($cfg['items'][$key]),true,false).'> Enabled</label></div></div>';
        }
        $last_run=(int)get_option('ews_smart_nudges_last_run',0);
        $last_result=get_option('ews_smart_nudges_last_result',[]);
        if(!is_array($last_result))$last_result=[];
        echo '<div class="wfo-nudge-section" style="background:#fbfcfe"><div class="wfo-nudge-title">Diagnostics</div><div class="wfo-nudge-desc">Use this to separate Push delivery problems from reminder-condition problems. Execute the scheduled event, then refresh this page.</div>';
        echo '<div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
        echo '<button type="submit" form="wfo-nudge-test-form" class="button">🔔 Send Test Push</button>';
        if($last_run){echo '<span style="color:#475467;font-size:13px">Last scheduler run: <strong>'.esc_html(wp_date('Y-m-d H:i:s',$last_run)).'</strong></span>';}else{echo '<span style="color:#98a2b3;font-size:13px">No scheduler run recorded yet.</span>';}
        echo '</div>';
        if($last_result){$parts=[];foreach(['employees'=>'Employees checked','nudges'=>'Active nudges','sent'=>'Push sent','skipped'=>'Skipped'] as $k=>$label){if(isset($last_result[$k]))$parts[]=$label.': '.(int)$last_result[$k];} if(isset($last_result['reason'])&&!empty($last_result['reason']))$parts[]='Status: '.sanitize_text_field($last_result['reason']); echo '<div style="margin-top:10px;color:#667085;font-size:13px">'.esc_html(implode(' · ',$parts)).'</div>';}
        echo '</div>';
        echo '<div class="wfo-nudge-save"><span style="float:left;color:#667085;font-size:13px;line-height:40px">Sign In Reminder works independently from the Smart Nudges master switch. It is one-shot by default; if repeat is enabled, the maximum includes the first reminder. Employees must enable push notifications on their device.</span><button class="button button-primary">Save Smart Nudges</button></div></form></div></div>';
    }

    public function smart_nudge_test_push(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_smart_nudge_test_push');
        $uid=get_current_user_id();
        $sent=method_exists($this,'push_custom_notification')?$this->push_custom_notification($uid,'Workforce One · Smart Nudge Test','This is a test push from Smart Nudges.','smart_nudge',0,$this->app_view_url('time')):0;
        $status=$sent>0?'test_sent':'test_not_sent';
        wp_safe_redirect(add_query_arg(['page'=>'ews31-smart-nudges','smart_nudges_saved'=>1,'smart_nudge_test'=>$status],admin_url('admin.php')));exit;
    }

    public function smart_nudges_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_smart_nudges_save');
        $items=$_POST['items']??[];
        if(!is_array($items))$items=[];
        $allowed=['attendance','tasks','leave','schedule'];
        $clean=[];foreach($allowed as $k)$clean[$k]=!empty($items[$k])?1:0;
        $after=max(1,min(240,absint($_POST['attendance_after']??15)));
        $repeat=!empty($_POST['attendance_repeat'])?1:0;
        $repeat_interval=max(5,min(240,absint($_POST['attendance_repeat_interval']??15)));
        $max_reminders=max(1,min(10,absint($_POST['attendance_max_reminders']??3)));
        update_option('ews_smart_nudges',['enabled'=>!empty($_POST['enabled'])?1:0,'items'=>$clean,'attendance_after'=>$after,'attendance_repeat'=>$repeat,'attendance_repeat_interval'=>$repeat_interval,'attendance_max_reminders'=>$max_reminders],false);
        if(method_exists($this,'audit'))$this->audit('smart_nudges_update','settings',0,'Updated Smart Nudge configuration.');
        wp_safe_redirect(add_query_arg(['page'=>'ews31-smart-nudges','smart_nudges_saved'=>1],admin_url('admin.php')));exit;
    }

    public function admin_employee_moments(){
        if(!$this->can('ews_manage_settings')) wp_die('Access denied');
        global $wpdb;
        $employees=$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
        $saved=get_option('ews_employee_moments',[]);
        if(!is_array($saved)) $saved=[];
        $enabled=(int)get_option('ews_employee_moments_enabled',1);
        $saved_count=0;
        foreach($employees as $e){ if(!empty($saved[(int)$e->id]['birthday']) || !empty($saved[(int)$e->id]['join_date'])) $saved_count++; }
        echo '<div class="wrap"><h1>Employee Moments</h1><p>Give employees a small celebration for birthdays, work anniversaries, and recent joiners. Dates are stored as Workforce One employee settings and are not tied to the WordPress user profile.</p>';
        if(isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Employee Moments settings saved.</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('ews31_employee_moments_save');
        echo '<input type="hidden" name="action" value="ews31_employee_moments_save">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:18px;margin:18px 0;max-width:1100px"><label style="display:flex;gap:10px;align-items:center"><input type="checkbox" name="enabled" value="1" '.checked($enabled,1,false).'> <strong>Enable Employee Moments</strong></label><p style="margin:8px 0 0;color:#646970">When enabled, Workforce One shows a small Moments card when an employee birthday, work anniversary, or recent joiner celebration applies today.</p></div>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Employee</th><th>Birthday</th><th>Joining Date</th></tr></thead><tbody>';
        if($employees){ foreach($employees as $e){ $id=(int)$e->id; $row=(isset($saved[$id])&&is_array($saved[$id]))?$saved[$id]:[]; echo '<tr><td><strong>'.esc_html($e->name).'</strong><br><span style="color:#646970">'.esc_html($e->domain_name).'</span></td><td><input type="date" name="moments['.$id.'][birthday]" value="'.esc_attr($row['birthday']??'').'" style="max-width:180px"><br><small>Month/day are used each year.</small></td><td><input type="date" name="moments['.$id.'][join_date]" value="'.esc_attr($row['join_date']??'').'" style="max-width:180px"><br><small>Used for work anniversaries and recent-joiner greetings.</small></td></tr>'; } }
        else echo '<tr><td colspan="3">No active employees found.</td></tr>';
        echo '</tbody></table><p style="margin-top:16px"><button type="submit" class="button button-primary">Save Moments</button> <span style="margin-left:10px;color:#646970">'.absint($saved_count).' employee(s) have moment dates configured.</span></p></form></div>';
    }

    public function employee_moments_save(){
        if(!$this->can('ews_manage_settings')) wp_die('Access denied');
        check_admin_referer('ews31_employee_moments_save');
        global $wpdb;
        $raw=isset($_POST['moments'])&&is_array($_POST['moments'])?$_POST['moments']:[];
        $clean=[];
        $valid_ids=$wpdb->get_col("SELECT id FROM {$this->employees} WHERE active=1");
        foreach($raw as $id=>$row){
            $id=absint($id); if(!$id||!in_array($id,array_map('intval',$valid_ids),true)||!is_array($row)) continue;
            $birthday=sanitize_text_field($row['birthday']??''); $join=sanitize_text_field($row['join_date']??'');
            if($birthday && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$birthday)) $birthday='';
            if($join && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$join)) $join='';
            if($birthday||$join) $clean[$id]=['birthday'=>$birthday,'join_date'=>$join];
        }
        update_option('ews_employee_moments_enabled',!empty($_POST['enabled'])?1:0,false);
        update_option('ews_employee_moments',$clean,false);
        $this->audit('employee_moments_update','employee_moments',0,'Updated Employee Moments configuration for '.count($clean).' employees.');
        wp_safe_redirect(admin_url('admin.php?page=ews31-moments&saved=1')); exit;
    }

    public function admin_employee_profile_settings(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $defaults=[
            'enabled'=>1,
            'show_photo'=>1,
            'show_name'=>1,
            'show_team'=>1,
            'show_email'=>0,
            'show_supervisor'=>0,
            'show_achievements'=>1,
            'show_recognition'=>0,
        ];
        $cfg=$this->employee_profile_settings();
        if(isset($_POST['ews_employee_profile_save'])){
            check_admin_referer('ews_employee_profile_settings_save','ews_employee_profile_settings_nonce');
            $cfg=[];
            foreach($defaults as $key=>$default)$cfg[$key]=!empty($_POST[$key])?1:0;
            update_option('ews_employee_profile_settings',$cfg,false);
            $this->audit('employee_profile_settings_update','settings',0,'Employee Profile visibility settings updated');
            echo '<div class="notice notice-success is-dismissible"><p>Employee Profile settings saved.</p></div>';
        }
        echo '<div class="wrap"><h1>Employee Profile</h1>';
        echo '<p>Control which work information employees can see when viewing another employee profile. Permissions still apply: disabling this feature does not grant access to anyone.</p>';
        echo '<form method="post">'.wp_nonce_field('ews_employee_profile_settings_save','ews_employee_profile_settings_nonce',true,false);
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">Employee Profiles</th><td><label><input type="checkbox" name="enabled" value="1" '.checked(!empty($cfg['enabled']),true,false).'> Allow employees with the View People permission to open other employee profiles.</label></td></tr>';
        echo '<tr><th scope="row">Visible Information</th><td>';
        $labels=['show_photo'=>'Profile photo / avatar','show_name'=>'Full name','show_team'=>'Team membership','show_email'=>'Work email','show_supervisor'=>'Supervisor','show_achievements'=>'Achievements','show_recognition'=>'Recognition'];
        foreach($labels as $key=>$label)echo '<label style="display:block;margin:0 0 9px"><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked(!empty($cfg[$key]),true,false).'> '.esc_html($label).'</label>';
        echo '<p class="description">Sensitive operational data such as attendance, leave balance, salary, login information and employee settings is never exposed by the employee profile view.</p></td></tr>';
        echo '</tbody></table>';
        echo '<p><button type="submit" name="ews_employee_profile_save" value="1" class="button button-primary">Save Employee Profile Settings</button></p></form></div>';
    }


    public function admin_recognition(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        global $wpdb;$this->ensure_recognition_schema();
        if(isset($_GET['delete_kudos'])){check_admin_referer('ews_delete_kudos_'.absint($_GET['delete_kudos']));$this->recognition_delete(absint($_GET['delete_kudos']));echo '<div class="notice notice-success is-dismissible"><p>Kudos removed.</p></div>';}
        $rows=$wpdb->get_results("SELECT k.*,s.name sender_name,r.name recipient_name FROM {$wpdb->prefix}ews_kudos k LEFT JOIN {$this->employees} s ON s.id=k.sender_employee_id LEFT JOIN {$this->employees} r ON r.id=k.recipient_employee_id ORDER BY k.created_at DESC LIMIT 200");
        echo '<div class="wrap"><h1>Recognition</h1><p>Review and remove employee Kudos. Recognition is appreciation only and is not a performance score.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>From</th><th>To</th><th>Category</th><th>Message</th><th>Status</th><th></th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="7">No Kudos yet.</td></tr>';
        foreach((array)$rows as $r){$del=add_query_arg(['page'=>'ews31-recognition','delete_kudos'=>(int)$r->id],admin_url('admin.php'));echo '<tr><td>'.esc_html(date_i18n(get_option('date_format').' '.get_option('time_format'),strtotime($r->created_at))).'</td><td>'.esc_html($r->sender_name?:'—').'</td><td>'.esc_html($r->recipient_name?:'—').'</td><td>'.esc_html($this->recognition_category_label($r->category)).'</td><td>'.esc_html($r->message?:'—').'</td><td>'.esc_html($r->status).'</td><td>'.($r->status==='active'?'<a href="'.esc_url(wp_nonce_url($del,'ews_delete_kudos_'.$r->id)).'" onclick="return confirm(\'Remove this Kudos?\')">Delete</a>':'—').'</td></tr>';}
        echo '</tbody></table></div>';
    }

    public function admin_navigation(){
        if(!$this->can('ews_manage_settings')) wp_die('Access denied');

        $defaults=[
            'dashboard'=>['label'=>'Dashboard','mobile_label'=>'Dashboard','icon'=>'🏠','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>10,'mobile_order'=>10],
            'schedule'=>['label'=>'Schedule','mobile_label'=>'Schedule','icon'=>'📅','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>20,'mobile_order'=>20],
            'time'=>['label'=>'Sign In / Out','mobile_label'=>'Sign In / Out','icon'=>'🕘','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>30,'mobile_order'=>30],
            'vacation'=>['label'=>'Leave','mobile_label'=>'Leave','icon'=>'📝','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>40,'mobile_order'=>40],
            'overtime'=>['label'=>'Overtime','mobile_label'=>'Overtime','icon'=>'⏱️','desktop_visible'=>1,'mobile_visible'=>0,'desktop_order'=>50,'mobile_order'=>50],
            'tasks'=>['label'=>'Tasks','mobile_label'=>'Tasks','icon'=>'✅','desktop_visible'=>1,'mobile_visible'=>0,'desktop_order'=>60,'mobile_order'=>60],
            'attendance'=>['label'=>'Attendance','mobile_label'=>'Attendance','icon'=>'📝','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>70,'mobile_order'=>50],
            'reports'=>['label'=>'Reports','mobile_label'=>'Reports','icon'=>'📊','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>80,'mobile_order'=>60],
            'attendance-insights'=>['label'=>'Attendance Insights','mobile_label'=>'Attendance Insights','icon'=>'📈','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>90,'mobile_order'=>70],
        ];
        $saved=get_option('ews_frontend_navigation',[]);
        if(!is_array($saved)) $saved=[];
        $cfg=[];
        foreach($defaults as $key=>$def){
            $item=(isset($saved[$key])&&is_array($saved[$key]))?array_merge($def,$saved[$key]):$def;
            $item['label']=isset($item['label'])?sanitize_text_field($item['label']):$def['label'];
            $item['mobile_label']=isset($item['mobile_label'])?sanitize_text_field($item['mobile_label']):$item['label'];
            if($item['label']==='')$item['label']=$def['label'];
            if($item['mobile_label']==='')$item['mobile_label']=$def['mobile_label'];
            $item['desktop_visible']=!empty($item['desktop_visible'])?1:0;
            $item['mobile_visible']=!empty($item['mobile_visible'])?1:0;
            $item['desktop_order']=max(1,(int)($item['desktop_order']??$def['desktop_order']));
            $item['mobile_order']=max(1,(int)($item['mobile_order']??$def['mobile_order']));
            $item['icon']=$def['icon'];
            $cfg[$key]=$item;
        }

        if(isset($_POST['ews_navigation_save'])){
            check_admin_referer('ews_navigation_save','ews_navigation_nonce');
            $posted=isset($_POST['nav']) && is_array($_POST['nav'])?$_POST['nav']:[];
            foreach($defaults as $key=>$def){
                $row=isset($posted[$key])&&is_array($posted[$key])?$posted[$key]:[];
                $label=isset($row['label'])?sanitize_text_field(wp_unslash($row['label'])):$def['label'];
                $mobile_label=isset($row['mobile_label'])?sanitize_text_field(wp_unslash($row['mobile_label'])):$def['mobile_label'];
                $cfg[$key]=[
                    'label'=>$label!==''?$label:$def['label'],
                    'mobile_label'=>$mobile_label!==''?$mobile_label:$def['mobile_label'],
                    'icon'=>$def['icon'],
                    'desktop_visible'=>!empty($row['desktop_visible'])?1:0,
                    'mobile_visible'=>!empty($row['mobile_visible'])?1:0,
                    'desktop_order'=>max(1,(int)($row['desktop_order']??$def['desktop_order'])),
                    'mobile_order'=>max(1,(int)($row['mobile_order']??$def['mobile_order'])),
                ];
            }
            update_option('ews_frontend_navigation',$cfg,false);
            echo '<div class="notice notice-success is-dismissible"><p>Frontend navigation settings saved.</p></div>';
        }

        if(isset($_POST['ews_navigation_reset'])){
            check_admin_referer('ews_navigation_reset','ews_navigation_reset_nonce');
            update_option('ews_frontend_navigation',$defaults,false);
            $cfg=$defaults;
            echo '<div class="notice notice-success is-dismissible"><p>Frontend navigation restored to defaults.</p></div>';
        }

        echo '<div class="wrap"><h1>View Navigation</h1>';
        echo '<p>Configure frontend navigation separately for Desktop and Mobile. You can control visibility, display names, and order. Permissions still apply: hidden navigation does not grant or remove access, and missing permission always prevents access.</p>';
        echo '<form method="post">'.wp_nonce_field('ews_navigation_save','ews_navigation_nonce',true,false);
        echo '<table class="widefat striped" style="max-width:1200px;margin-top:18px"><thead><tr><th style="width:18%">Page</th><th>Desktop Label</th><th style="width:10%">Desktop</th><th style="width:9%">Order</th><th>Mobile Label</th><th style="width:10%">Mobile</th><th style="width:9%">Order</th><th>Key</th></tr></thead><tbody>';
        foreach($cfg as $key=>$item){
            echo '<tr><td><strong>'.esc_html($item['icon'].' '.$defaults[$key]['label']).'</strong></td>';
            echo '<td><input type="text" class="regular-text" name="nav['.esc_attr($key).'][label]" value="'.esc_attr($item['label']).'" maxlength="60"></td>';
            echo '<td><label><input type="checkbox" name="nav['.esc_attr($key).'][desktop_visible]" value="1" '.checked(!empty($item['desktop_visible']),true,false).'> Show</label></td>';
            echo '<td><input type="number" min="1" step="1" style="width:70px" name="nav['.esc_attr($key).'][desktop_order]" value="'.esc_attr($item['desktop_order']).'"></td>';
            echo '<td><input type="text" class="regular-text" name="nav['.esc_attr($key).'][mobile_label]" value="'.esc_attr($item['mobile_label']).'" maxlength="40"></td>';
            echo '<td><label><input type="checkbox" name="nav['.esc_attr($key).'][mobile_visible]" value="1" '.checked(!empty($item['mobile_visible']),true,false).'> Show</label></td>';
            echo '<td><input type="number" min="1" step="1" style="width:70px" name="nav['.esc_attr($key).'][mobile_order]" value="'.esc_attr($item['mobile_order']).'"></td>';
            echo '<td><code>'.esc_html($key).'</code></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p style="margin-top:18px"><button type="submit" name="ews_navigation_save" value="1" class="button button-primary">Save Navigation</button></p></form>';
        echo '<form method="post" style="margin-top:8px">'.wp_nonce_field('ews_navigation_reset','ews_navigation_reset_nonce',true,false).'<button type="submit" name="ews_navigation_reset" value="1" class="button">Restore Defaults</button></form>';
        echo '<p style="margin-top:18px;color:#667085">Notifications and My Profile remain top-right actions. Employee management remains in WordPress Admin. Navigation visibility is presentation only; permissions remain the access-control layer.</p>';
        echo '</div>';
    }

        public function admin_notification_settings(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');
            $days=(int)get_option('ews_notification_retention_days',90);
            $allowed=[7,30,90,180,365,0];
            if(!in_array($days,$allowed,true))$days=90;
            $policy=$this->notification_policy();
            $categories=$this->notification_policy_categories();
            global $wpdb;
            $push_table=$this->push_table();
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->employees} WHERE active=1 AND wp_user_id>0");
            $ready=(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$push_table} WHERE user_id IN (SELECT wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id>0)");
            $missing=max(0,$total-$ready);
            $configured=(bool)(get_option('ews_vapid_public_key','') && get_option('ews_vapid_private_key',''));
            $not_configured=$configured?0:$total;
            if(!$configured){$ready=0;$missing=0;}
            echo '<style>
            .wfo-notification-policy{max-width:1180px}.wfo-policy-intro{font-size:14px;margin:4px 0 22px}.wfo-policy-health{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) 1.7fr;gap:12px;margin:0 0 18px}.wfo-health-card,.wfo-health-meta{background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:17px 18px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 2px rgba(0,0,0,.03)}.wfo-health-card strong{display:block;font-size:22px;line-height:1.1}.wfo-health-card span:not(.wfo-health-icon){display:block;color:#646970;font-size:12px;margin-top:4px}.wfo-health-icon{font-size:18px;line-height:1}.wfo-health-meta{display:block}.wfo-health-meta strong,.wfo-health-meta span{display:block}.wfo-health-meta span{color:#646970;font-size:12px;margin-top:5px}.wfo-policy-panel{background:#fff;border:1px solid #e2e4e7;border-radius:14px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.03)}.wfo-policy-panel-head{display:flex;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid #eee}.wfo-policy-panel-head h2{margin:0 0 5px}.wfo-policy-panel-head p{margin:0;color:#646970}.wfo-policy-admin{align-self:center;border:1px solid #dcdcde;border-radius:999px;padding:6px 11px;font-size:12px;color:#50575e;white-space:nowrap}.wfo-policy-table-head,.wfo-policy-row{display:grid;grid-template-columns:minmax(260px,1fr) 110px 110px 150px;align-items:center}.wfo-policy-table-head{background:#f6f7f7;padding:11px 24px;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.04em;font-weight:600}.wfo-policy-table-head span:not(:first-child),.wfo-policy-row>.wfo-switch{text-align:center}.wfo-policy-row{padding:16px 24px;border-top:1px solid #f0f0f1}.wfo-policy-category strong{display:block;font-size:14px}.wfo-policy-category span{display:block;color:#646970;font-size:12px;margin-top:3px}.wfo-switch{display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer}.wfo-switch input{position:absolute;opacity:0;pointer-events:none}.wfo-switch>span{width:38px;height:22px;border-radius:999px;background:#c3c4c7;position:relative;transition:.15s}.wfo-switch>span:after{content:"";position:absolute;width:18px;height:18px;left:2px;top:2px;background:#fff;border-radius:50%;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:.15s}.wfo-switch input:checked+span{background:#2271b1}.wfo-switch input:checked+span:after{transform:translateX(16px)}.wfo-switch em{font-style:normal;font-size:11px;color:#646970;min-width:22px}.wfo-policy-note{margin:14px 0;background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:15px 18px;display:flex;gap:8px;align-items:flex-start}.wfo-policy-note strong{white-space:nowrap}.wfo-policy-note span{color:#646970;font-size:12px}.wfo-policy-actions{padding:18px 0}.wfo-policy-secondary{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:8px}.wfo-policy-secondary>div{background:#fff;border:1px solid #e2e4e7;border-radius:12px;padding:20px}.wfo-policy-secondary h2{margin-top:0}.wfo-policy-secondary p{color:#646970}.wfo-policy-secondary form{margin:0}@media(max-width:900px){.wfo-policy-health{grid-template-columns:1fr 1fr}.wfo-health-meta{grid-column:1/-1}.wfo-policy-table-head,.wfo-policy-row{grid-template-columns:minmax(180px,1fr) 80px 80px 110px;padding-left:14px;padding-right:14px}.wfo-policy-secondary{grid-template-columns:1fr}}
            </style>';
            echo '<div class="wrap wfo-notification-policy"><h1>Notification Policy Center</h1>';
            echo '<p class="description wfo-policy-intro">Control how Workforce One delivers notifications across the organization.</p>';
            if(isset($_GET['policy_saved'])) echo '<div class="notice notice-success is-dismissible"><p><strong>Notification policy updated successfully.</strong></p></div>';
            if(isset($_GET['push_sent'])) echo '<div class="notice notice-success is-dismissible"><p>Push test sent to '.(int)$_GET['push_sent'].' device(s). Expired: '.(int)($_GET['push_expired']??0).'. Errors: '.(int)($_GET['push_error']??0).'.</p></div>';
            echo '<div class="wfo-policy-health">';
            echo '<div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong>'.(int)$ready.'</strong><span>Push Ready</span></div></div>';
            echo '<div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong>'.(int)$missing.'</strong><span>No Active Device</span></div></div>';
            echo '<div class="wfo-health-card"><span class="wfo-health-icon">●</span><div><strong>'.(int)$not_configured.'</strong><span>Not Configured</span></div></div>';
            echo '<div class="wfo-health-meta"><strong>Push Delivery Health</strong><span>'.($configured?'Push infrastructure is configured.':'Push infrastructure is not configured yet.').'</span></div>';
            echo '</div>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews_notification_policy_save');
            echo '<input type="hidden" name="action" value="ews_notification_policy_save">';
            echo '<div class="wfo-policy-panel"><div class="wfo-policy-panel-head"><div><h2>Notification Policy</h2><p>In-App notifications are retained as part of the notification history. Push controls device delivery.</p></div><span class="wfo-policy-admin">Admin controlled</span></div>';
            echo '<div class="wfo-policy-table-head"><span>Category</span><span>In-App</span><span>Push</span><span>Mandatory Push</span></div>';
            foreach($categories as $key=>$cat){
                $row=$policy[$key];
                echo '<div class="wfo-policy-row">';
                echo '<div class="wfo-policy-category"><strong>'.esc_html($cat['label']).'</strong><span>'.esc_html($cat['description']).'</span></div>';
                echo '<label class="wfo-switch"><input type="checkbox" name="policy['.esc_attr($key).'][in_app]" value="1" '.checked($row['in_app'],1,false).'><span></span><em>'.($row['in_app']?'ON':'OFF').'</em></label>';
                echo '<label class="wfo-switch"><input type="checkbox" name="policy['.esc_attr($key).'][push]" value="1" '.checked($row['push'],1,false).'><span></span><em>'.($row['push']?'ON':'OFF').'</em></label>';
                echo '<label class="wfo-switch"><input type="checkbox" name="policy['.esc_attr($key).'][mandatory]" value="1" '.checked($row['mandatory'],1,false).'><span></span><em>'.($row['mandatory']?'ON':'OFF').'</em></label>';
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="wfo-policy-note"><strong>About Mandatory Push</strong><span>Mandatory Push means Workforce One will attempt Push delivery for this category even when optional Push is turned off. It cannot bypass browser or operating-system permissions.</span></div>';
            echo '<div class="wfo-policy-actions"><button class="button button-primary button-large" type="submit">Save Notification Policy</button></div>';
            echo '</form>';
            echo '<div class="wfo-policy-secondary"><div><h2>Push Notifications</h2><p>Send a test push to every subscribed device. This infrastructure test is not affected by the Notification Policy.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews_push_send_test');
            echo '<input type="hidden" name="action" value="ews_push_send_test">';
            echo '<p><label><strong>VAPID Subject</strong><br><input type="text" name="vapid_subject" value="'.esc_attr(get_option('ews_vapid_subject','mailto:'.get_option('admin_email','admin@example.com'))).'" class="regular-text" placeholder="mailto:admin@example.com"></label></p><p class="description">Use a mailto: address or your HTTPS site URL.</p><p><button class="button" type="submit">Send Test Push</button></p></form></div>';
            echo '<div><h2>Notification Retention</h2><p>Choose how long notifications remain in the system.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews_notification_settings_save');
            echo '<input type="hidden" name="action" value="ews_notification_settings_save"><select name="retention_days">';
            foreach($allowed as $v){$label=$v===0?'Forever':$v.' days';echo '<option value="'.(int)$v.'" '.selected($days,$v,false).'>'.esc_html($label).'</option>';}
            echo '</select><p><button class="button" type="submit">Save Retention</button></p></form></div></div></div>';
        }

        public function admin_notification_policy_save(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');
            check_admin_referer('ews_notification_policy_save');
            $categories=$this->notification_policy_categories();
            $posted=isset($_POST['policy'])&&is_array($_POST['policy'])?wp_unslash($_POST['policy']):[];
            $policy=[];
            foreach($categories as $key=>$cat){
                $row=isset($posted[$key])&&is_array($posted[$key])?$posted[$key]:[];
                $mandatory=!empty($row['mandatory'])?1:0;
                $policy[$key]=[
                    'in_app'=>!empty($row['in_app'])?1:0,
                    'push'=>($mandatory||!empty($row['push']))?1:0,
                    'mandatory'=>$mandatory,
                ];
            }
            update_option('ews_notification_policy',$policy,false);
            update_option('ews_notification_policy_updated_at',current_time('mysql'),false);
            update_option('ews_notification_policy_updated_by',get_current_user_id(),false);
            $this->redirect(['page'=>'ews31-notifications','policy_saved'=>1]);
        }

        public function admin_notification_settings_save(){
            if(!$this->can('ews_manage_settings'))wp_die('Access denied');
            check_admin_referer('ews_notification_settings_save');
            $allowed=[7,30,90,180,365,0];
            $days=isset($_POST['retention_days'])?(int)$_POST['retention_days']:90;
            if(!in_array($days,$allowed,true))$days=90;
            update_option('ews_notification_retention_days',$days);
            $subject=sanitize_text_field(wp_unslash($_POST['vapid_subject']??''));
            if($subject && (strpos($subject,'mailto:')===0 || filter_var($subject,FILTER_VALIDATE_URL))) update_option('ews_vapid_subject',$subject,false);
            $this->redirect(['page'=>'ews31-notifications','saved'=>1]);
        }

        public function admin_roles_save(){
            if(!$this->can('ews_manage_roles'))wp_die('Access denied');
            check_admin_referer('ews31_roles_save');
            $defs=$this->role_defs();$perms=$this->permission_defs();
            foreach($defs as $slug=>$def){
                $role=get_role($slug);if(!$role)continue;
                foreach($perms as $cap=>$label){
                    $enabled=!empty($_POST['roles'][$slug][$cap]);
                    $role->add_cap($cap,$enabled);
                }
            }
            $this->audit('roles_permissions_update','role',0,'Updated EWS role permissions');
            $this->redirect(['page'=>'ews31-roles','roles_saved'=>1]);
        }

    public function admin_roles(){
            if(!$this->can('ews_manage_roles'))wp_die('Access denied');
            $defs=$this->role_defs();$perms=$this->permission_defs();
            echo '<div class="wrap"><h1>Roles & Permissions</h1>';
            if(isset($_GET['roles_saved']))echo '<div class="notice notice-success is-dismissible"><p>Roles and permissions saved successfully.</p></div>';
            echo '<p>Control what each Employee Schedule role can access. WordPress Administrators always retain full access.</p>';
            echo '<div style="background:#f6f7f7;border-left:4px solid #2271b1;padding:12px 14px;margin:14px 0;max-width:1100px"><strong>Permission model:</strong> <em>View Dashboard</em> only controls whether the user gets the management dashboard or the personal user dashboard. All other permissions are independent. If a user does not have a module permission, that module is hidden from the Workforce One frontend and protected server-side as well.</div>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews31_roles_save');
            echo '<input type="hidden" name="action" value="ews31_roles_save"><div style="overflow:auto"><table class="widefat striped"><thead><tr><th>Permission</th>';
            foreach($defs as $slug=>$def)echo '<th style="text-align:center">'.esc_html($def['name']).'</th>';
            echo '</tr></thead><tbody>';
            foreach($perms as $cap=>$label){
                echo '<tr><td><strong>'.esc_html($label).'</strong><br><code>'.esc_html($cap).'</code></td>';
                foreach($defs as $slug=>$def){
                    $role=get_role($slug);$checked=$role&&$role->has_cap($cap);
                    echo '<td style="text-align:center"><input type="checkbox" name="roles['.esc_attr($slug).']['.esc_attr($cap).']" value="1" '.checked($checked,true,false).'></td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div><p><button class="button button-primary">Save Permissions</button></p></form>';
            echo '<div style="background:#fff;border:1px solid #ddd;padding:15px;margin-top:20px"><h2>Role Assignment</h2><p>Assign the EWS roles to WordPress users from <strong>Users → All Users</strong>. Employees should normally use <strong>EWS Employee</strong>; managers/supervisors can be assigned the corresponding EWS role.</p></div></div>';
        }

    public function admin_home_router(){
            if($this->can('ews_view_dashboard')){ $this->admin_home(); return; }
            $routes=[
                'ews_manage_employees'=>['admin_employees'],
                'ews_manage_attendance'=>['admin_attendance'],
                'ews_view_reports'=>['admin_reports'],
                'ews_manage_locations'=>['admin_multi_locations_v321'],
                'ews_manage_settings'=>['admin_schedule_config'],
                'ews_manage_time'=>['admin_time_report'],
                'ews_manage_auto_attendance'=>['admin_auto_attendance'],
                'ews_view_audit_log'=>['admin_audit'],
                'ews_manage_roles'=>['admin_roles'],
            ];
            foreach($routes as $cap=>$method){
                if($this->can($cap) && method_exists($this,$method[0])){ $this->{$method[0]}(); return; }
            }
            wp_die('Access denied');
        }

    public function admin_home(){
            if(!$this->can('ews_view_dashboard')) wp_die('Access denied');
            global $wpdb;
            $today=current_time('Y-m-d');
            $today_label=date_i18n('l, d M Y',strtotime($today));
    
            // Read today's schedule first, then calculate attendance from actual time-log records.
            $scheduled=$wpdb->get_results($wpdb->prepare(
                "SELECT e.id,e.name,e.domain_name,s.status
                 FROM {$this->employees} e
                 INNER JOIN {$this->schedule} s
                    ON s.employee_id=e.id AND s.work_date=%s
                 WHERE e.active=1
                   AND LOWER(TRIM(s.status)) IN ('office','wfh')
                 ORDER BY e.name ASC",
                $today
            ));
    
            $scheduled_ids=[];
            foreach($scheduled as $s)$scheduled_ids[(int)$s->id]=$s;
            $sign_in_count=0;$late_count=0;$no_show_count=0;
            $status_by_employee=[];
    
            if($scheduled_ids){
                $ids=array_keys($scheduled_ids);
                $placeholders=implode(',',array_fill(0,count($ids),'%d'));
                $sql=$wpdb->prepare(
                    "SELECT employee_id,event_type,event_at
                     FROM {$this->time_logs}
                     WHERE work_date=%s
                       AND employee_id IN ($placeholders)
                       AND event_type IN ('sign_in','late_sign_in')
                     ORDER BY event_at ASC,id ASC",
                    array_merge([$today],$ids)
                );
                $events=$wpdb->get_results($sql);
    
                foreach($ids as $eid)$status_by_employee[$eid]='no_show';
                foreach($events as $ev){
                    $eid=(int)$ev->employee_id;
                    if(!isset($status_by_employee[$eid]))continue;
                    if($ev->event_type==='sign_in'){
                        // A late arrival is still a Sign In event. Its attendance
                        // status is derived from the actual time + grace period.
                        $status_by_employee[$eid]=$this->sign_in_classification($ev->event_at)==='Late Arrival'?'late_arrival':'sign_in';
                    }elseif($ev->event_type==='late_sign_in' && $status_by_employee[$eid]==='no_show'){
                        // Preserve compatibility with historical records.
                        $status_by_employee[$eid]='late_arrival';
                    }
                }
                foreach($status_by_employee as $st){
                    if($st==='sign_in')$sign_in_count++;
                    elseif($st==='late_arrival')$late_count++;
                    else $no_show_count++;
                }
            }
    
            $total=count($scheduled_ids);
            $card=function($title,$number,$subtitle,$class,$url=''){
                $inner='<div style="font-size:32px;font-weight:700;line-height:1.1">'.number_format_i18n($number).'</div><div style="font-size:15px;font-weight:600;margin-top:6px">'.esc_html($title).'</div><div style="font-size:12px;color:#667085;margin-top:4px">'.esc_html($subtitle).'</div>';
                return '<div style="background:#fff;border:1px solid #dcdcde;border-left:5px solid '.$class.';border-radius:6px;padding:18px;min-width:210px;flex:1;box-sizing:border-box">'.$inner.($url?'<p style="margin:12px 0 0"><a href="'.esc_url($url).'">View details</a></p>':'').'</div>';
            };
    
            $report_url=admin_url('admin.php?page=ews31-time-report&start='.$today.'&end='.$today);
            echo '<div class="wrap"><h1>Employee Schedule Dashboard</h1>';
            echo '<p><strong>Today:</strong> '.esc_html($today_label).' &nbsp; <strong>Scheduled Office / WFH:</strong> '.number_format_i18n($total).'</p>';
            echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">';
            echo $card('Sign In',$sign_in_count,'Normal sign-in recorded','#2271b1',$report_url);
            echo $card('Late Arrival',$late_count,'Arrived after grace period','#dba617',$report_url);
            echo $card('No Show',$no_show_count,'Office / WFH but no sign-in yet','#d63638',$report_url);
            echo $card('Scheduled Today',$total,'Office + WFH employees','#50575e',$report_url);
            echo '</div>';
    
            // Useful breakdown so an admin can immediately verify the KPI numbers.
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin-top:18px"><h2 style="margin-top:0">Today Attendance Status</h2>';
            if(!$scheduled){
                echo '<p><strong>No Office/WFH schedule records found for today.</strong> If employees are scheduled today, check that their schedule date is '.esc_html($today).' and the status is Office or WFH.</p>';
            }else{
                echo '<table class="widefat striped"><thead><tr><th>Employee</th><th>Domain</th><th>Schedule</th><th>Attendance</th></tr></thead><tbody>';
                foreach($scheduled as $s){
                    $st=$status_by_employee[(int)$s->id]??'no_show';
                    $label=$st==='sign_in'?'On Time':($st==='late_arrival'?'Late Arrival':'No Show');
                    echo '<tr><td>'.esc_html($s->name).'</td><td>'.esc_html($s->domain_name).'</td><td>'.esc_html(strtoupper($s->status)).'</td><td><strong>'.esc_html($label).'</strong></td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            echo '<p style="margin-top:18px"><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=ews31-employees')).'">Employees</a>
            <a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-attendance')).'">Attendance</a>
            <a class="button" href="'.esc_url($report_url).'">Today Sign In / Out Report</a>
            <a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-reports')).'">Reports</a></p></div>';
        }

    public function admin_employee_profile(){
        if(!$this->can('ews_manage_employees')) wp_die('Access denied');
        global $wpdb;
        $employee_id=absint($_GET['employee_id']??0);
        if(!$employee_id) wp_die('Employee not found.');
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
        if(!$emp) wp_die('Employee not found.');

        $this->ensure_teams_schema();
        $tt=$this->team_tables();
        $teams=$wpdb->get_results($wpdb->prepare("SELECT t.id,t.name,t.manager_employee_id FROM {$tt['teams']} t INNER JOIN {$tt['members']} m ON m.team_id=t.id AND m.employee_id=%d AND m.active=1 WHERE t.active=1 ORDER BY t.name ASC",$employee_id));
        $supervisor=$this->approval_related_employee($employee_id,'supervisor');
        $manager_teams=[];
        foreach((array)$teams as $tm){ if((int)$tm->manager_employee_id===$employee_id) $manager_teams[]=$tm->name; }

        $today=current_time('Y-m-d');
        $schedule=$wpdb->get_row($wpdb->prepare("SELECT status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",$employee_id,$today));
        $today_events=$wpdb->get_results($wpdb->prepare("SELECT event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ('sign_in','late_sign_in','sign_out') ORDER BY event_at ASC",$employee_id,$today));
        $today_in='';$today_out='';$today_in_type='';
        foreach((array)$today_events as $ev){
            if(($ev->event_type==='sign_in'||$ev->event_type==='late_sign_in')&&!$today_in){$today_in=$ev->event_at;$today_in_type=$ev->event_type;}
            if($ev->event_type==='sign_out'&&!$today_out)$today_out=$ev->event_at;
        }

        $period_start=date('Y-m-d',strtotime('-29 days',current_time('timestamp')));
        [$att_rows]=$this->report_attendance_data($period_start,$today,'all',$employee_id,'all',$emp->active?'active':'all');
        $counted=0;$attended=0;$late=0;$absent=0;
        foreach((array)$att_rows as $ar){
            if(in_array($ar['result'],['Present','Late','Absent'],true)){
                $counted++;
                if($ar['result']==='Present'||$ar['result']==='Late')$attended++;
                if($ar['result']==='Late')$late++;
                if($ar['result']==='Absent')$absent++;
            }
        }
        $attendance_rate=$counted>0?round(($attended/$counted)*100):0;
        $recent=[];
        foreach(array_reverse((array)$att_rows) as $ar){if(count($recent)>=10)break;$recent[]=$ar;}

        $this->ensure_leave_schema();
        $leave_types=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY name ASC");
        $leave_balances=[];$year=(int)date('Y',current_time('timestamp'));$bal_table=$wpdb->prefix.'ews_leave_balances';
        foreach((array)$leave_types as $lt){
            $b=$wpdb->get_row($wpdb->prepare("SELECT entitlement,used,pending FROM {$bal_table} WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",$employee_id,(int)$lt->id,$year));
            if($b)$leave_balances[]=['name'=>$lt->name,'entitlement'=>(float)$b->entitlement,'used'=>(float)$b->used,'pending'=>(float)$b->pending,'available'=>max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending)];
        }

        $presence_locations=[]; $presence_latest=null;
        if($this->presence_verification_enabled() && $this->can('ews_manage_attendance')){
            global $wpdb; $presence_locations=$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC");
            list(,$presence_vt)=$this->presence_tables();
            $presence_latest=$wpdb->get_results($wpdb->prepare("SELECT v.*,l.name location_name FROM $presence_vt v LEFT JOIN {$this->locations} l ON l.id=v.location_id WHERE v.employee_id=%d ORDER BY v.id DESC LIMIT 5",$employee_id));
        }
        $shift=$this->shift_for_employee($employee_id);$hours=$this->working_hours($employee_id);
        $initials='';$parts=preg_split('/\s+/',trim((string)$emp->name));
        if($parts){$initials=strtoupper(substr($parts[0],0,1).(count($parts)>1?substr($parts[count($parts)-1],0,1):''));}
        $profile_image_type=!empty($emp->profile_image_type)?$emp->profile_image_type:'initials';
        $profile_image_url=!empty($emp->profile_image_url)?$emp->profile_image_url:'';
        $status_label=(int)$emp->active?'Active':'Inactive';$status_class=(int)$emp->active?'is-active':'is-inactive';
        $today_planned=$schedule?$schedule->status:'Not Set';
        $today_result='Pending';
        if($today_planned==='Vacation'||$today_planned==='Leave')$today_result='Leave';
        elseif(in_array($today_planned,['Business Trip','Training Course'],true))$today_result=$today_planned;
        elseif($today_in)$today_result=$today_in_type==='late_sign_in'?'Late':'Present';
        elseif($today_planned!=='Not Set' && $today<current_time('Y-m-d'))$today_result='Absent';

        echo '<div class="wrap ews-profile-wrap">';
        echo '<style>
        .ews-profile-wrap{max-width:1280px}.ews-profile-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin:18px 0}.ews-profile-person{display:flex;gap:16px;align-items:center}.ews-profile-avatar{width:64px;height:64px;border-radius:16px;background:#f0f4ff;border:1px solid #dbe4ff;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;color:#3158c8;overflow:hidden}.ews-profile-avatar img{width:100%;height:100%;object-fit:cover}.ews-profile-title h1{margin:0 0 5px;font-size:28px}.ews-profile-title p{margin:0;color:#667085}.ews-profile-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.ews-profile-pill{display:inline-flex;align-items:center;border:1px solid #e4e7ec;border-radius:999px;padding:4px 9px;font-size:12px;background:#fff;color:#475467}.ews-profile-pill.is-active{color:#067647;background:#ecfdf3;border-color:#abefc6}.ews-profile-pill.is-inactive{color:#b42318;background:#fef3f2;border-color:#fecdca}.ews-profile-actions{display:flex;gap:8px}.ews-profile-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.ews-profile-card{background:#fff;border:1px solid #e4e7ec;border-radius:12px;padding:16px}.ews-profile-card .label{font-size:12px;color:#667085}.ews-profile-card .value{font-size:23px;font-weight:700;color:#101828;margin-top:5px}.ews-profile-card .sub{font-size:12px;color:#667085;margin-top:4px}.ews-profile-layout{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.65fr);gap:16px}.ews-profile-panel{background:#fff;border:1px solid #e4e7ec;border-radius:12px;margin-bottom:16px;overflow:hidden}.ews-profile-panel h2{font-size:16px;margin:0;padding:15px 18px;border-bottom:1px solid #eef0f3}.ews-profile-body{padding:16px 18px}.ews-profile-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ews-profile-field .k{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#98a2b3}.ews-profile-field .v{font-size:14px;color:#344054;margin-top:4px;word-break:break-word}.ews-profile-list{margin:0;padding:0;list-style:none}.ews-profile-list li{display:flex;justify-content:space-between;gap:14px;padding:10px 0;border-bottom:1px solid #f2f4f7;font-size:13px}.ews-profile-list li:last-child{border-bottom:0}.ews-profile-list .muted{color:#667085}.ews-profile-table{width:100%;border-collapse:collapse}.ews-profile-table th,.ews-profile-table td{padding:10px 12px;border-bottom:1px solid #f0f2f5;text-align:left;font-size:12px}.ews-profile-table th{color:#667085;font-weight:600;background:#fafbfc}.ews-profile-empty{color:#667085;padding:4px 0}.wfo-achievement-admin-badge{width:42px;height:42px;display:flex;align-items:center;justify-content:center;font-size:22px;background:#fff7e6;border:1px solid #f3d9a2;flex:0 0 auto}.wfo-achievement-admin-badge.circle{border-radius:50%}.wfo-achievement-admin-badge.shield{border-radius:12px 12px 18px 18px;clip-path:polygon(50% 0,90% 15%,90% 58%,50% 100%,10% 58%,10% 15%)}.wfo-achievement-admin-badge.star{border-radius:12px;clip-path:polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 95%,50% 72%,21% 95%,32% 57%,2% 35%,39% 35%)}.wfo-achievement-admin-badge.ribbon{border-radius:10px 10px 16px 16px}.ews-profile-badge{display:inline-flex;padding:3px 7px;border-radius:999px;font-size:11px;background:#f2f4f7;color:#475467}.ews-profile-badge.present{background:#ecfdf3;color:#067647}.ews-profile-badge.late{background:#fffaeb;color:#b54708}.ews-profile-badge.absent{background:#fef3f2;color:#b42318}.ews-profile-badge.leave{background:#eff8ff;color:#175cd3}@media(max-width:980px){.ews-profile-layout{grid-template-columns:1fr}.ews-profile-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.ews-profile-head{flex-direction:column}.ews-profile-grid,.ews-profile-fields{grid-template-columns:1fr}.ews-profile-actions{width:100%}.ews-profile-actions .button{flex:1;text-align:center}}
        </style>';
        echo '<div class="ews-profile-head"><div class="ews-profile-person"><div class="ews-profile-avatar">'.(($profile_image_type==='photo'&&$profile_image_url)?'<img src="'.esc_url($profile_image_url).'" alt="'.esc_attr($emp->name).'">':esc_html($initials)).'</div><div class="ews-profile-title"><h1>'.esc_html($emp->name).'</h1><p>'.esc_html($emp->domain_name).' · '.esc_html($emp->email?:'No email').'</p><div class="ews-profile-meta"><span class="ews-profile-pill '.$status_class.'">'.esc_html($status_label).'</span>';
        foreach((array)$teams as $tm)echo '<span class="ews-profile-pill">'.esc_html($tm->name.((int)$tm->manager_employee_id===$employee_id?' · Manager':'')).'</span>';
        echo '</div></div></div><div class="ews-profile-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-employees')).'">← Employees</a></div></div>';

        if($presence_locations){
            echo '<section class="ews-profile-panel" style="margin-bottom:16px"><h2>Presence Verification</h2><div class="ews-profile-body"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">'.wp_nonce_field('ews_presence_request','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_presence_request"><input type="hidden" name="employee_id" value="'.(int)$employee_id.'"><label style="font-size:12px;font-weight:600;color:#344054">Work Location<br><select name="location_id" required style="min-width:220px;margin-top:5px"><option value="">Select location</option>';
            foreach($presence_locations as $pl)echo '<option value="'.(int)$pl->id.'">'.esc_html($pl->name).'</option>';
            echo '</select></label><button class="button button-primary" type="submit">Request Presence Verification</button></form>';
            if($presence_latest){echo '<div style="overflow:auto;margin-top:16px"><table class="widefat striped"><thead><tr><th>Status</th><th>Location</th><th>Requested</th><th>Expires</th><th>Verified</th></tr></thead><tbody>';foreach($presence_latest as $pr){echo '<tr><td><strong>'.esc_html(ucfirst($pr->status)).'</strong></td><td>'.esc_html($pr->location_name?:'—').'</td><td>'.esc_html($pr->created_at).'</td><td>'.esc_html($pr->expires_at).'</td><td>'.esc_html($pr->verified_at?:'—').'</td></tr>';}echo '</tbody></table></div>';}
            echo '</div></section>';
        }

        echo '<div class="ews-profile-grid">';
        echo '<div class="ews-profile-card"><div class="label">Today · Planned</div><div class="value" style="font-size:18px">'.esc_html($today_planned).'</div><div class="sub">'.esc_html($schedule&&$schedule->note?$schedule->note:'Schedule status').'</div></div>';
        echo '<div class="ews-profile-card"><div class="label">Today · Attendance</div><div class="value" style="font-size:18px">'.esc_html($today_result).'</div><div class="sub">'.esc_html($today_in?'In '.date_i18n('g:i A',strtotime($today_in)):'No sign in').' '.($today_out?'· Out '.date_i18n('g:i A',strtotime($today_out)):'').'</div></div>';
        echo '<div class="ews-profile-card"><div class="label">30-day Attendance Rate</div><div class="value">'.(int)$attendance_rate.'%</div><div class="sub">'.(int)$attended.' attended · '.(int)$absent.' absent</div></div>';
        echo '<div class="ews-profile-card"><div class="label">Late Arrivals · 30 days</div><div class="value">'.(int)$late.'</div><div class="sub">Based on actual Sign In events</div></div></div>';

        if($this->achievements_enabled()){
            $achievements=$this->employee_achievements((int)$employee_id);
            echo '<section class="ews-profile-panel"><h2>🏆 Achievements <span style="font-size:12px;color:#667085;font-weight:400">'.(int)count($achievements).' earned</span></h2><div class="ews-profile-body">';
            if(!$achievements){
                echo '<div class="ews-profile-empty">No achievements earned yet.</div>';
            } else {
                echo '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px">';
                foreach($achievements as $a){
                    $shape=esc_attr($a->badge_style?:'circle');
                    $delete_url=wp_nonce_url(admin_url('admin-post.php?action=ews_achievement_award_delete&award_id='.(int)$a->id.'&employee_id='.(int)$employee_id),'ews_achievement_award_delete_'.(int)$a->id);
                    echo '<div style="border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd;display:flex;gap:10px;align-items:flex-start;justify-content:space-between"><div style="display:flex;gap:10px;align-items:flex-start;min-width:0"><div class="wfo-achievement-admin-badge '.esc_attr($shape).'">'.esc_html($a->icon).'</div><div><strong style="color:#101828">'.esc_html($a->name).'</strong><div style="font-size:12px;color:#667085;margin-top:3px">'.esc_html($a->description).'</div><div style="font-size:11px;color:#98a2b3;margin-top:6px">Earned '.esc_html(date_i18n(get_option('date_format'),strtotime($a->earned_at))).'</div></div></div><a class="button button-small" style="color:#b42318;border-color:#fecdca;flex:0 0 auto" href="'.esc_url($delete_url).'" onclick="return confirm(\'Delete this achievement award? The badge will be removed from the employee profile. If the employee still qualifies, an automatic achievement may be awarded again later.\');">Delete</a></div>';
                }
                echo '</div>';
            }
            echo '</div></section>';
        }

        echo '<div class="ews-profile-layout"><div>';
        echo '<section class="ews-profile-panel"><h2>Employee Information</h2><div class="ews-profile-body"><div class="ews-profile-fields">';
        $linked_user=$emp->wp_user_id?get_userdata((int)$emp->wp_user_id):null;
        echo '<div class="ews-profile-field"><div class="k">Employee Name</div><div class="v">'.esc_html($emp->name).'</div></div><div class="ews-profile-field"><div class="k">Domain Name</div><div class="v">'.esc_html($emp->domain_name).'</div></div><div class="ews-profile-field"><div class="k">Email</div><div class="v">'.esc_html($emp->email?:'—').'</div></div><div class="ews-profile-field"><div class="k">WordPress User</div><div class="v">'.($linked_user?esc_html($linked_user->user_login):'Not linked').'</div></div><div class="ews-profile-field"><div class="k">Status</div><div class="v">'.esc_html($status_label).'</div></div><div class="ews-profile-field"><div class="k">Employee Since</div><div class="v">'.esc_html($emp->created_at?date_i18n(get_option('date_format'),strtotime($emp->created_at)):'—').'</div></div>';
        echo '</div></div></section>';

        echo '<section class="ews-profile-panel"><h2>Organization & Work Setup</h2><div class="ews-profile-body"><div class="ews-profile-fields">';
        echo '<div class="ews-profile-field"><div class="k">Direct Supervisor</div><div class="v">'.($supervisor?esc_html($supervisor->name.' ('.$supervisor->domain_name.')'):'—').'</div></div><div class="ews-profile-field"><div class="k">Teams</div><div class="v">'.($teams?esc_html(implode(', ',array_map(function($x){return $x->name;},(array)$teams))):'—').'</div></div><div class="ews-profile-field"><div class="k">Default Shift</div><div class="v">'.esc_html($shift?$shift['name']:'Company Default').'</div></div><div class="ews-profile-field"><div class="k">Working Hours</div><div class="v">'.esc_html($this->format_time_label($hours['start']).' – '.$this->format_time_label($hours['end'])).'</div></div></div></div></section>';

        echo '<section class="ews-profile-panel"><h2>Recent Attendance</h2><div class="ews-profile-body" style="padding:0"><table class="ews-profile-table"><thead><tr><th>Date</th><th>Planned</th><th>Sign In</th><th>Sign Out</th><th>Result</th></tr></thead><tbody>';
        if(!$recent)echo '<tr><td colspan="5" class="ews-profile-empty">No attendance records in the selected period.</td></tr>';else foreach($recent as $ar){$cls=strtolower(str_replace(' ','-',$ar['result']));echo '<tr><td>'.esc_html(date_i18n(get_option('date_format'),strtotime($ar['date']))).'</td><td>'.esc_html($ar['planned']).'</td><td>'.esc_html($ar['sign_in']?:'—').'</td><td>'.esc_html($ar['sign_out']?:'—').'</td><td><span class="ews-profile-badge '.esc_attr($cls).'">'.esc_html($ar['result']).'</span></td></tr>';}
        echo '</tbody></table></div></section></div><div>';

        echo '<section class="ews-profile-panel"><h2>Leave Balance · '.$year.'</h2><div class="ews-profile-body">';
        if(!$leave_balances)echo '<div class="ews-profile-empty">No leave balances found for this employee.</div>';else{echo '<ul class="ews-profile-list">';foreach($leave_balances as $b){echo '<li><span><strong>'.esc_html($b['name']).'</strong><br><span class="muted">Used '.esc_html(number_format($b['used'],2)).' · Pending '.esc_html(number_format($b['pending'],2)).'</span></span><strong>'.esc_html(number_format($b['available'],2)).' available</strong></li>';}echo '</ul>';}
        echo '</div></section>';
        echo '<section class="ews-profile-panel"><h2>Today</h2><div class="ews-profile-body"><ul class="ews-profile-list"><li><span class="muted">Planned</span><strong>'.esc_html($today_planned).'</strong></li><li><span class="muted">Sign In</span><strong>'.esc_html($today_in?date_i18n('g:i A',strtotime($today_in)):'—').'</strong></li><li><span class="muted">Sign Out</span><strong>'.esc_html($today_out?date_i18n('g:i A',strtotime($today_out)):'—').'</strong></li><li><span class="muted">Result</span><strong>'.esc_html($today_result).'</strong></li></ul></div></section>';
        if($manager_teams)echo '<section class="ews-profile-panel"><h2>Team Management</h2><div class="ews-profile-body"><div class="ews-profile-empty">Employee is a manager of: <strong>'.esc_html(implode(', ',$manager_teams)).'</strong></div></div></section>';
        echo '</div></div></div>';
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

    private function audit_target_labels($rows){
            global $wpdb;
            $targets=[];
            $groups=[];
            foreach($rows as $r){
                $entity=sanitize_key((string)$r->entity); $id=absint($r->entity_id);
                if(!$entity||!$id)continue;
                $groups[$entity][$id]=true;
            }
            $load=function($entity,$sql,$ids)use(&$targets,$wpdb){
                if(empty($ids))return;
                $ids=array_map('absint',array_keys($ids));
                $placeholders=implode(',',array_fill(0,count($ids),'%d'));
                $sql=str_replace('{IDS}',$placeholders,$sql);
                $query=$wpdb->prepare($sql,$ids);
                $rows=$wpdb->get_results($query);
                foreach((array)$rows as $row){$targets[$entity.':'.(int)$row->id]=(string)$row->label;}
            };
            if(isset($groups['employee']))$load('employee',"SELECT id,name AS label FROM {$this->employees} WHERE id IN ({IDS})",$groups['employee']);
            if(isset($groups['schedule']))$load('schedule',"SELECT s.id,CONCAT(COALESCE(e.name,'Employee #'), ' — ', s.work_date) AS label FROM {$this->schedule} s LEFT JOIN {$this->employees} e ON e.id=s.employee_id WHERE s.id IN ({IDS})",$groups['schedule']);
            if(isset($groups['time_log']))$load('time_log',"SELECT t.id,CONCAT(COALESCE(e.name,'Employee #'), ' — ', t.work_date, ' ', t.event_type) AS label FROM {$this->time_logs} t LEFT JOIN {$this->employees} e ON e.id=t.employee_id WHERE t.id IN ({IDS})",$groups['time_log']);
            if(isset($groups['leave_request']))$load('leave_request',"SELECT r.id,COALESCE(e.name,CONCAT('Leave Request #',r.id)) AS label FROM {$wpdb->prefix}ews_leave_requests r LEFT JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.id IN ({IDS})",$groups['leave_request']);
            if(isset($groups['vacation_request']))$load('vacation_request',"SELECT r.id,COALESCE(e.name,CONCAT('Vacation Request #',r.id)) AS label FROM {$wpdb->prefix}ews_vacation_requests r LEFT JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.id IN ({IDS})",$groups['vacation_request']);
            if(isset($groups['leave_balance']))$load('leave_balance',"SELECT b.id,COALESCE(e.name,CONCAT('Leave Balance #',b.id)) AS label FROM {$wpdb->prefix}ews_leave_balances b LEFT JOIN {$this->employees} e ON e.id=b.employee_id WHERE b.id IN ({IDS})",$groups['leave_balance']);
            if(isset($groups['employee_achievement']))$load('employee_achievement',"SELECT a.id,COALESCE(e.name,CONCAT('Employee Achievement #',a.id)) AS label FROM {$wpdb->prefix}ews_employee_achievements a LEFT JOIN {$this->employees} e ON e.id=a.employee_id WHERE a.id IN ({IDS})",$groups['employee_achievement']);
            if(isset($groups['team']))$load('team',"SELECT id,name AS label FROM {$wpdb->prefix}ews_teams WHERE id IN ({IDS})",$groups['team']);
            if(isset($groups['department']))$load('department',"SELECT id,name AS label FROM {$wpdb->prefix}ews_departments WHERE id IN ({IDS})",$groups['department']);
            return $targets;
        }

    public function admin_audit(){
            if(!$this->can('ews_view_audit_log')) wp_die('Access denied');
            global $wpdb;
            $from=sanitize_text_field(wp_unslash($_GET['audit_from']??''));
            $to=sanitize_text_field(wp_unslash($_GET['audit_to']??''));
            $user_id=absint($_GET['audit_user']??0);
            $where=[];$args=[];
            if($from && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$from)){$where[]='a.created_at >= %s';$args[]=$from.' 00:00:00';}
            if($to && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$to)){$where[]='a.created_at <= %s';$args[]=$to.' 23:59:59';}
            if($user_id){$where[]='a.user_id = %d';$args[]=$user_id;}
            $per_page=50;
            $page=max(1,absint($_GET['audit_page']??1));
            $offset=($page-1)*$per_page;
            $sql="SELECT a.*,u.user_login FROM {$this->audit} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id";
            $count_sql="SELECT COUNT(*) FROM {$this->audit} a";
            if($where){$condition=' WHERE '.implode(' AND ',$where);$sql.=$condition;$count_sql.=$condition;}
            $total=(int)($args?$wpdb->get_var($wpdb->prepare($count_sql,$args)):$wpdb->get_var($count_sql));
            $total_pages=max(1,(int)ceil($total/$per_page));
            if($page>$total_pages)$page=$total_pages;$offset=($page-1)*$per_page;
            $sql.=$wpdb->prepare(' ORDER BY a.id DESC LIMIT %d OFFSET %d',$per_page,$offset);
            $rows=$args?$wpdb->get_results($wpdb->prepare($sql,$args)):$wpdb->get_results($sql);
            $target_labels=$this->audit_target_labels($rows);
            $users=$wpdb->get_results("SELECT DISTINCT u.ID,u.user_login FROM {$this->audit} a INNER JOIN {$wpdb->users} u ON u.ID=a.user_id ORDER BY u.user_login ASC");
            echo '<div class="wrap"><h1>Audit Log</h1>';
            echo '<p>Use the filters below to find logs by date or by a specific user.</p>';
            echo '<form method="get" style="background:#fff;border:1px solid #dcdcde;padding:14px;margin:15px 0">';
            echo '<input type="hidden" name="page" value="ews31-audit">';
            echo '<label style="margin-right:12px"><strong>From:</strong> <input type="date" name="audit_from" value="'.esc_attr($from).'"></label>';
            echo '<label style="margin-right:12px"><strong>To:</strong> <input type="date" name="audit_to" value="'.esc_attr($to).'"></label>';
            echo '<label style="margin-right:12px"><strong>User:</strong> <select name="audit_user"><option value="0">All Users</option>';
            foreach($users as $u)echo '<option value="'.(int)$u->ID.'" '.selected($user_id,(int)$u->ID,false).'>'.esc_html($u->user_login).'</option>';
            echo '</select></label>';
            echo '<button class="button button-primary">Search Logs</button> ';
            echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-audit')).'">Reset Filters</a>';
            echo '</form>';
            echo '<p><strong>'.number_format_i18n($total).'</strong> log(s) found. Showing '.($total?((($page-1)*$per_page)+1):0).'–'.min($page*$per_page,$total).' on page '.number_format_i18n($page).' of '.number_format_i18n($total_pages).'.</p>';
            if($total_pages>1){
                $base_args=['page'=>'ews31-audit'];if($from)$base_args['audit_from']=$from;if($to)$base_args['audit_to']=$to;if($user_id)$base_args['audit_user']=$user_id;
                echo '<div class="tablenav top"><div class="tablenav-pages">';
                if($page>1){$base_args['audit_page']=$page-1;echo '<a class="button" href="'.esc_url(add_query_arg($base_args,admin_url('admin.php'))).'">&laquo; Previous</a> ';}
                $start=max(1,$page-2);$end=min($total_pages,$page+2);
                for($i=$start;$i<=$end;$i++){ $base_args['audit_page']=$i; $cls=$i===$page?' button button-primary':'button'; echo '<a class="'.esc_attr($cls).'" style="margin-left:4px" href="'.esc_url(add_query_arg($base_args,admin_url('admin.php'))).'">'.(int)$i.'</a>'; }
                if($page<$total_pages){$base_args['audit_page']=$page+1;echo ' <a class="button" href="'.esc_url(add_query_arg($base_args,admin_url('admin.php'))).'">Next &raquo;</a>';}
                echo '</div></div>';
            }
            echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Actor</th><th>Action</th><th>Entity</th><th>ID</th><th>Target</th><th>Details</th></tr></thead><tbody>';
            if(!$rows)echo '<tr><td colspan="7">No audit logs found for the selected filters.</td></tr>';
            foreach($rows as $r){
                $target=$target_labels[(string)$r->entity.':'.(int)$r->entity_id]??'—';
                echo '<tr><td>'.esc_html($r->created_at).'</td><td>'.esc_html($r->user_login?:'System').'</td><td>'.esc_html($r->action).'</td><td>'.esc_html($r->entity).'</td><td>'.(int)$r->entity_id.'</td><td>'.esc_html($target).'</td><td>'.esc_html($r->details).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }
}
