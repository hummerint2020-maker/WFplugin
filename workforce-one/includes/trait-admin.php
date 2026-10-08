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
        if(substr((string)$hook_suffix,-strlen('ews31-features'))==='ews31-features'||substr((string)$hook_suffix,-strlen('ews31-tasks'))==='ews31-tasks'){
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
        if(substr((string)$hook_suffix,-strlen('ews31-polls'))==='ews31-polls'){
            wp_enqueue_style('workforce-one-admin-polls', $this->plugin_url('assets/css/admin-polls.css'), [], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-polls', 'rtl', 'replace');
            wp_enqueue_script('workforce-one-admin-polls', $this->plugin_url('assets/js/admin-polls.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-polls'))==='ews31-polls'||substr((string)$hook_suffix,-strlen('ews31-departments'))==='ews31-departments'||substr((string)$hook_suffix,-strlen('ews31-teams'))==='ews31-teams'||substr((string)$hook_suffix,-strlen('ews31-auto-attendance'))==='ews31-auto-attendance'){
            wp_enqueue_script('workforce-one-admin-organization', $this->plugin_url('assets/js/admin-organization.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-multi-locations'))==='ews31-multi-locations'){
            wp_enqueue_script('workforce-one-admin-locations', $this->plugin_url('assets/js/admin-locations.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-approvals'))==='ews31-approvals'){
            wp_enqueue_script('workforce-one-admin-approvals', $this->plugin_url('assets/js/admin-approvals.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-payroll'))==='ews31-payroll'){
            wp_enqueue_style('workforce-one-admin-payroll', $this->plugin_url('assets/css/admin-payroll.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-payroll', 'rtl', 'replace');
            wp_enqueue_script('workforce-one-admin-organization', $this->plugin_url('assets/js/admin-organization.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-settings-overview'))==='ews31-settings-overview'){
            wp_enqueue_style('workforce-one-admin-settings-overview', $this->plugin_url('assets/css/admin-settings-overview.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-settings-overview', 'rtl', 'replace');
        }
        if(substr((string)$hook_suffix,-strlen('ews31-appearance'))==='ews31-appearance'){
            wp_enqueue_style('workforce-one-admin-appearance', $this->plugin_url('assets/css/admin-appearance.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-appearance', 'rtl', 'replace');
            wp_enqueue_script('workforce-one-admin-appearance', $this->plugin_url('assets/js/admin-appearance.js'), [], EWS_VERSION, true);
        }
        if(substr((string)$hook_suffix,-strlen('ews31-attendance-insights'))==='ews31-attendance-insights'){
            wp_enqueue_style('workforce-one-admin-attendance-insights', $this->plugin_url('assets/css/admin-attendance-insights.css'), ['workforce-one-ui'], EWS_VERSION);
            wp_style_add_data('workforce-one-admin-attendance-insights', 'rtl', 'replace');
            wp_enqueue_script('workforce-one-admin-attendance-insights', $this->plugin_url('assets/js/admin-attendance-insights.js'), [], EWS_VERSION, true);
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
            add_submenu_page('ews31','Payroll','Payroll','ews_manage_payroll','ews31-payroll',[$this,'admin_payroll']);
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
            add_submenu_page('ews31','Tasks','Tasks','ews_manage_settings','ews31-tasks',[$this,'admin_tasks_settings']);
            add_submenu_page('ews31','Audit Log','Audit Log','ews_view_audit_log','ews31-audit',[$this,'admin_audit']);
            add_submenu_page('ews31','Employee Profile','Employee Profile','ews_manage_settings','ews31-employee-profile-settings',[$this,'admin_employee_profile_settings']);
            add_submenu_page('ews31','View Navigation','View Navigation','ews_manage_settings','ews31-navigation',[$this,'admin_navigation']);
            add_submenu_page('ews31','Appearance','Appearance','ews_manage_settings','ews31-appearance',[$this,'admin_appearance']);
            add_submenu_page('ews31','Approval Workflows','Approval Workflows','manage_options','ews31-approvals',[$this,'admin_approval_workflows']);
            add_submenu_page('ews31','Settings Overview','Settings Overview','manage_options','ews31-settings-overview',[$this,'admin_settings_overview']);
            // Keep Employee Profile registered so direct URLs remain authorized (removing the
            // submenu entry would make WordPress refuse the page), but hide its menu link on every
            // screen: profiles are opened from Employees.
            add_action('admin_head', function(){
                echo '<style>#toplevel_page_ews31 .wp-submenu a[href="admin.php?page=ews31-employee-profile"]{display:none!important}</style>';
            });
        }


        public function ews_admin_confirmation_guard(){
            if(!$this->can('ews_manage_settings') && !$this->can('ews_manage_time')) return;
            $global=(int)$this->option('ews_confirm_global');
            $cfg=\WorkforceOne\Settings\FeatureSettings::confirmState($this->option('ews_confirmation_actions'));
            $payload=['global'=>(bool)$global,'actions'=>$cfg];
            echo '<script>window.ewsAdminConfirmationConfig='.wp_json_encode($payload).';(function(){function init(){document.querySelectorAll("[data-ews-confirm-key]").forEach(function(el){if(el.dataset.ewsConfirmBound==="1")return;el.dataset.ewsConfirmBound="1";el.addEventListener("click",function(e){var c=window.ewsAdminConfirmationConfig||{};var k=el.getAttribute("data-ews-confirm-key");if(c.global!==false&&c.actions&&c.actions[k]){var msg=k==="attendance_reset"?"Reset ALL attendance and break records for this employee on this date?":"Remove this General Leave?";if(!window.confirm(msg))e.preventDefault();}});});}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",init);else init();})();</script>';
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
            <textarea name="emails" style="width:600px;max-width:100%;min-height:120px">'.esc_textarea($this->option('ews_attendance_emails')).'</textarea>
            <p><button class="button button-primary">Save Email Settings</button></p></form></div>';
        }

    public function auto_attendance_redirect($args=[]){
        $url=admin_url('admin.php?page=ews31-auto-attendance');
        if($args)$url=add_query_arg($args,$url);
        wp_safe_redirect($url); exit;
    }
}
