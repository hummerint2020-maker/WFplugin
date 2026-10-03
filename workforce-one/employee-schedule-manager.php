<?php
/**
 * Plugin Name: Workforce One
 * Description: Workforce management platform for schedules, attendance, reporting and employee operations.
 * Version: 3.31.33
 * Author: Internal
 * Text Domain: workforce-one
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: Proprietary
 */
if (!defined('ABSPATH')) exit;
if (!defined('EWS_VERSION')) define('EWS_VERSION', '3.31.33');

if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Workforce One:</strong> PHP 7.4 or newer is required.</p></div>';
    });
    return;
}

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/includes/trait-core.php';
require_once __DIR__ . '/includes/trait-schema.php';
require_once __DIR__ . '/includes/trait-work-time.php';
require_once __DIR__ . '/includes/trait-schedule-types.php';
require_once __DIR__ . '/includes/trait-breaks.php';
require_once __DIR__ . '/includes/trait-face.php';
require_once __DIR__ . '/includes/trait-permissions.php';
require_once __DIR__ . '/includes/trait-profile-account.php';
require_once __DIR__ . '/includes/trait-attendance.php';
require_once __DIR__ . '/includes/trait-leave.php';
require_once __DIR__ . '/includes/trait-overtime.php';
require_once __DIR__ . '/includes/trait-swap.php';
require_once __DIR__ . '/includes/trait-face-reset.php';
require_once __DIR__ . '/includes/trait-admin-requests.php';
require_once __DIR__ . '/includes/trait-leave-admin.php';
require_once __DIR__ . '/includes/trait-schedule-config.php';
require_once __DIR__ . '/includes/trait-features.php';
require_once __DIR__ . '/includes/trait-employee-admin.php';
require_once __DIR__ . '/includes/trait-time-report.php';
require_once __DIR__ . '/includes/trait-settings-pages.php';
require_once __DIR__ . '/includes/trait-achievements-admin.php';
require_once __DIR__ . '/includes/trait-engagement-admin.php';
require_once __DIR__ . '/includes/trait-employee-profile.php';
require_once __DIR__ . '/includes/trait-admin-dashboard.php';
require_once __DIR__ . '/includes/trait-attendance-insights.php';
require_once __DIR__ . '/includes/trait-schedule-view.php';
require_once __DIR__ . '/includes/trait-attendance-grid.php';
require_once __DIR__ . '/includes/trait-leave-view.php';
require_once __DIR__ . '/includes/trait-dashboard-view.php';
require_once __DIR__ . '/includes/trait-my-profile.php';
require_once __DIR__ . '/includes/trait-people-view.php';
require_once __DIR__ . '/includes/trait-app-layout.php';
require_once __DIR__ . '/includes/trait-frontend.php';
require_once __DIR__ . '/includes/trait-pwa.php';
require_once __DIR__ . '/includes/trait-reports.php';
require_once __DIR__ . '/includes/trait-employees.php';
require_once __DIR__ . '/includes/trait-locations.php';
require_once __DIR__ . '/includes/trait-admin.php';
require_once __DIR__ . '/includes/trait-notifications.php';
require_once __DIR__ . '/includes/trait-tasks.php';
require_once __DIR__ . '/includes/trait-approvals.php';
require_once __DIR__ . '/includes/trait-teams.php';
require_once __DIR__ . '/includes/trait-auto-attendance.php';
require_once __DIR__ . '/includes/trait-achievements.php';
require_once __DIR__ . '/includes/trait-polls.php';
require_once __DIR__ . '/includes/trait-recognition.php';
require_once __DIR__ . '/includes/trait-departments.php';
require_once __DIR__ . '/includes/trait-presence.php';
require_once __DIR__ . '/includes/trait-privacy.php';

class EWS_Manager_V31_1 {
    use EWS_Core_Trait, EWS_Schema_Trait, EWS_Work_Time_Trait, EWS_Schedule_Types_Trait, EWS_Breaks_Trait, EWS_Face_Trait, EWS_Permissions_Trait, EWS_Profile_Account_Trait, EWS_Attendance_Trait, EWS_Leave_Trait, EWS_Leave_Admin_Trait, EWS_Overtime_Trait, EWS_Swap_Trait, EWS_Schedule_Config_Trait, EWS_Features_Trait, EWS_Employee_Admin_Trait, EWS_Time_Report_Trait, EWS_Settings_Pages_Trait, EWS_Achievements_Admin_Trait, EWS_Engagement_Admin_Trait, EWS_Employee_Profile_Trait, EWS_Admin_Dashboard_Trait, EWS_Attendance_Insights_Trait, EWS_Schedule_View_Trait, EWS_Attendance_Grid_Trait, EWS_Leave_View_Trait, EWS_Dashboard_View_Trait, EWS_My_Profile_Trait, EWS_People_View_Trait, EWS_App_Layout_Trait, EWS_Face_Reset_Trait, EWS_Admin_Requests_Trait, EWS_Frontend_Trait, EWS_PWA_Trait, EWS_Reports_Trait, EWS_Employees_Trait, EWS_Locations_Trait, EWS_Admin_Trait, EWS_Notifications_Trait, EWS_Tasks_Trait, EWS_Approvals_Trait, EWS_Teams_Trait, EWS_Auto_Attendance_Trait, EWS_Achievements_Trait, EWS_Polls_Trait, EWS_Recognition_Trait, EWS_Departments_Trait, EWS_Presence_Trait, EWS_Privacy_Trait;

    private $employees,$schedule,$leaves,$audit,$time_logs,$locations,$company_calendar;


    function __construct(){
            global $wpdb;
            $this->employees=$wpdb->prefix.'ews_employees';
            $this->schedule=$wpdb->prefix.'ews_schedule';
            $this->leaves=$wpdb->prefix.'ews_leave_requests';
            $this->audit=$wpdb->prefix.'ews_audit_log';
            $this->time_logs=$wpdb->prefix.'ews_time_logs';
            $this->locations=$wpdb->prefix.'ews_locations';
            $this->company_calendar=$wpdb->prefix.'ews_company_calendar';
            /* Schema maintenance is version-gated. The old build ran multiple
               SHOW COLUMNS/dbDelta checks on every front-end page request, which
               made navigation unnecessarily slow. */
            $this->maybe_upgrade_schema();
            add_action('init',[$this,'load_textdomain'],1);
            add_shortcode('employee_app',[$this,'app']);
            add_action('rest_api_init',[$this,'face_rest_routes']);
            add_shortcode('employee_login',[$this,'login_page']);
            add_action('wp_logout',[$this,'after_logout']);
            add_action('wp_login_failed',[$this,'app_login_failed']);
            add_action('init',[$this,'prevent_dynamic_page_cache'],0);
            add_action('template_redirect',[$this,'prevent_dynamic_page_cache'],0);
            add_action('send_headers',[$this,'send_dynamic_no_cache_headers'],0);
            add_shortcode('employee_schedule',function(){ return $this->app_forced_view('schedule'); });
            add_shortcode('employee_attendance',function(){ return $this->app_forced_view('time'); });
            add_shortcode('employee_reports',function(){ return $this->app_forced_view('reports'); });
            add_action('wp_head',[$this,'pwa_head']);
            add_action('wp_enqueue_scripts',[$this,'enqueue_frontend_assets']);
            add_action('wp_footer',[$this,'pwa_footer']);
            add_action('template_redirect',[$this,'pwa_manifest_route']);
            add_action('template_redirect',[$this,'pwa_sw_route']);
            add_action('admin_menu',[$this,'admin_menu']);
            add_action('admin_post_ews_presence_kiosk_save',[$this,'presence_kiosk_save']);
            add_action('admin_post_ews_presence_kiosk_revoke',[$this,'presence_kiosk_revoke']);
            add_action('admin_post_ews_presence_request',[$this,'presence_request']);
            add_action('admin_post_ews_presence_verify',[$this,'presence_verify']);
            add_action('admin_post_ews_presence_qr_signin',[$this,'presence_qr_signin']);
            add_action('template_redirect',[$this,'presence_kiosk_payload_route'],1);
            add_action('template_redirect',[$this,'presence_kiosk_route'],2);
            add_action('admin_enqueue_scripts',[$this,'enqueue_admin_ui_foundation']);
            add_action('admin_post_ews31_employee_save',[$this,'employee_save']);
            add_action('admin_post_ews_kudos_submit',[$this,'recognition_submit']);
            add_action('admin_post_ews_department_save',[$this,'department_save']);
            add_action('admin_post_ews_department_delete',[$this,'department_delete']);
            add_action('admin_post_ews31_employee_update',[$this,'employee_update']);
            add_action('admin_post_ews31_employee_archive',[$this,'employee_archive']);
            add_action('admin_post_ews31_att_single',[$this,'att_single']);
            add_action('admin_post_ews31_att_grid_save',[$this,'att_grid_save']);
            add_action('admin_post_ews31_att_preview',[$this,'att_preview']);
            add_action('admin_post_ews31_att_import',[$this,'att_import']);
            add_action('admin_post_ews31_att_sample',[$this,'att_sample']);
            add_action('admin_post_ews31_report_download',[$this,'report_download']);
            add_action('admin_post_ews31_report_xlsx',[$this,'report_xlsx']);
            add_action('admin_post_ews_report_view_save',[$this,'report_view_save']);
            add_action('admin_post_ews_report_view_delete',[$this,'report_view_delete']);
            add_action('admin_post_ews31_report_email',[$this,'report_email']);
            add_action('admin_post_ews31_email_save',[$this,'email_save']);
            add_action('admin_post_ews_smart_nudge_dismiss',[$this,'smart_nudge_dismiss']);
            add_action('admin_post_ews_achievements_settings_save',[$this,'achievements_settings_save']);
            add_action('admin_post_ews_achievement_manual_grant',[$this,'achievement_manual_grant']);
            add_action('admin_post_ews_achievement_award_delete',[$this,'achievement_award_delete']);
            add_action('admin_post_ews_poll_save',[$this,'poll_save']);
            add_action('admin_post_ews_poll_toggle',[$this,'poll_toggle']);
            add_action('admin_post_ews_poll_homepage',[$this,'poll_homepage']);
            add_action('admin_post_ews_poll_archive',[$this,'poll_archive']);
            add_action('admin_post_ews_poll_vote',[$this,'poll_vote']);
            add_action('ews_smart_nudges_tick',[$this,'smart_nudges_cron']);
            add_action('admin_post_ews31_leave_save',[$this,'leave_save']);
            \WorkforceOne\Attendance\Hooks::register($this);
            add_action('admin_post_ews31_time_reset',[$this,'admin_time_reset']);
            add_action('admin_post_ews31_time_save',[$this,'admin_time_save']);
            add_action('admin_post_ews31_time_csv',[$this,'admin_time_csv']);
            add_action('admin_post_ews31_roles_save',[$this,'admin_roles_save']);
            add_action('admin_post_ews_notification_read',[$this,'notification_read']);
            add_action('admin_post_ews_notification_open',[$this,'notification_open']);
            add_action('admin_post_ews_notification_read_all',[$this,'notification_read_all']);
            add_action('admin_post_ews_push_subscribe',[$this,'push_subscribe']);
            add_action('admin_post_ews_push_unsubscribe',[$this,'push_unsubscribe']);
            add_action('admin_post_ews_push_test',[$this,'push_test']);
            add_action('admin_post_ews_push_send_test',[$this,'push_send_test']);
            \WorkforceOne\Leave\Hooks::register($this);
            \WorkforceOne\Overtime\Hooks::register($this);
            \WorkforceOne\Schedule\Hooks::register($this);
            \WorkforceOne\Schedule\ConfigHooks::register($this);
            add_action('admin_post_ews31_features_save',[$this,'features_save_handler']);
            \WorkforceOne\Requests\Hooks::register($this);
            \WorkforceOne\Approvals\Hooks::register($this);
            \WorkforceOne\Locations\Hooks::register($this);
            \WorkforceOne\Settings\EngagementHooks::register($this);
            add_action('admin_post_ews_task_save',[$this,'task_save']);
            add_action('admin_post_ews_task_status_update',[$this,'task_status_update']);
            add_action('admin_post_ews_task_delete',[$this,'task_delete']);


            add_action('admin_post_ews_notification_settings_save',[$this,'admin_notification_settings_save']);
            add_action('admin_post_ews_notification_policy_save',[$this,'admin_notification_policy_save']);
            add_action('admin_post_ews_team_save',[$this,'team_save']);
            add_action('admin_post_ews_team_delete',[$this,'team_delete']);
            add_action('admin_post_ews_auto_attendance_save',[$this,'auto_attendance_save']);
            add_action('admin_post_ews_auto_attendance_toggle',[$this,'auto_attendance_toggle']);
            add_action('admin_post_ews_auto_attendance_delete',[$this,'auto_attendance_delete']);
            add_action('admin_post_ews_auto_attendance_bulk_sign_out',[$this,'auto_attendance_bulk_sign_out']);
            add_action('admin_post_ews_profile_photo_save',[$this,'profile_photo_save']);
            add_action('admin_post_ews_profile_password_change',[$this,'profile_password_change']);
            add_action('ews_notifications_cleanup',[$this,'cleanup_notifications']);
            add_action('ews_privacy_cleanup',[$this,'privacy_cleanup_cron']);
            add_action('ews_auto_attendance_tick',[$this,'auto_attendance_cron']);
            add_filter('cron_schedules',function($s){if(!isset($s['ews_auto_five_minutes']))$s['ews_auto_five_minutes']=['interval'=>300,'display'=>'Every 5 minutes'];return $s;});
            // Keep the scheduler present after upgrades/reloads; wp_next_scheduled prevents duplicates.
            if(wp_next_scheduled('ews_auto_attendance_tick')===false) $this->auto_attendance_schedule();
            $this->privacy_schedule();
            if(wp_next_scheduled('ews_smart_nudges_tick')===false) wp_schedule_event(time()+120,'ews_auto_five_minutes','ews_smart_nudges_tick');
        }

    public function load_textdomain(){
            load_plugin_textdomain('workforce-one',false,dirname(plugin_basename(__FILE__)).'/languages');
        }

    static function activate(){
            ob_start();
            global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $c=$wpdb->get_charset_collate();
            $e=$wpdb->prefix.'ews_employees';$s=$wpdb->prefix.'ews_schedule';$l=$wpdb->prefix.'ews_leave_requests';$a=$wpdb->prefix.'ews_audit_log';
            dbDelta("CREATE TABLE $e (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,name VARCHAR(190) NOT NULL,domain_name VARCHAR(190) NOT NULL,email VARCHAR(190) NULL,wp_user_id BIGINT UNSIGNED NULL,default_shift_id BIGINT UNSIGNED NULL,attendance_enabled TINYINT(1) NOT NULL DEFAULT 1,active TINYINT(1) NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY domain_name(domain_name),KEY active(active),KEY email(email),KEY wp_user_id(wp_user_id)) $c;");
            dbDelta("CREATE TABLE $s (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,work_date DATE NOT NULL,status VARCHAR(40) NOT NULL DEFAULT 'Office',note TEXT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY employee_date(employee_id,work_date),KEY work_date(work_date),KEY employee_id(employee_id)) $c;");
            dbDelta("CREATE TABLE {$wpdb->prefix}ews_time_logs (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,work_date DATE NOT NULL,event_type VARCHAR(30) NOT NULL,event_at DATETIME NOT NULL,scheduled_status VARCHAR(40) NOT NULL,ip_address VARCHAR(64) NULL,latitude DECIMAL(10,7) NULL,longitude DECIMAL(10,7) NULL,accuracy DECIMAL(10,2) NULL,location_status VARCHAR(30) NULL,distance_meters DECIMAL(12,2) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY employee_date(employee_id,work_date),KEY event_type(event_type),KEY event_at(event_at)) $c;");
            dbDelta("CREATE TABLE $l (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,leave_type VARCHAR(40) NOT NULL DEFAULT 'Vacation',start_date DATE NOT NULL,end_date DATE NOT NULL,reason TEXT NULL,status VARCHAR(20) NOT NULL DEFAULT 'Pending',requested_by BIGINT UNSIGNED NULL,approved_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY employee_id(employee_id),KEY dates(start_date,end_date),KEY status(status)) $c;");
            dbDelta("CREATE TABLE $a (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NULL,action VARCHAR(80) NOT NULL,entity VARCHAR(80) NOT NULL,entity_id BIGINT UNSIGNED NULL,details TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY user_id(user_id),KEY entity(entity,entity_id),KEY created_at(created_at)) $c;");
            dbDelta("CREATE TABLE {$wpdb->prefix}ews_tasks (
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
            $n=$wpdb->prefix.'ews_notifications';
            dbDelta("CREATE TABLE $n (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,title VARCHAR(190) NOT NULL,message TEXT NOT NULL,type VARCHAR(30) NOT NULL DEFAULT 'info',entity VARCHAR(80) NULL,entity_id BIGINT UNSIGNED NULL,is_read TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,read_at DATETIME NULL,PRIMARY KEY(id),KEY user_unread(user_id,is_read),KEY created_at(created_at),KEY entity(entity,entity_id)) $c;");
            $p=$wpdb->prefix.'ews_push_subscriptions';
            dbDelta("CREATE TABLE $p (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id BIGINT UNSIGNED NOT NULL,endpoint TEXT NOT NULL,endpoint_hash CHAR(64) NOT NULL,p256dh TEXT NOT NULL,auth TEXT NOT NULL,content_encoding VARCHAR(30) NULL,user_agent TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY endpoint_hash(endpoint_hash),KEY user_id(user_id)) $c;");
            self::activate_vapid_keys();
            add_option('ews_vapid_subject','mailto:'.get_option('admin_email','admin@example.com'));

            add_option('ews_notification_retention_days',90);
            if(!wp_next_scheduled('ews_notifications_cleanup')) wp_schedule_event(time()+3600,'daily','ews_notifications_cleanup');
            add_option('ews_attendance_emails','');
            add_option('ews_location_enforcement',0);
            add_option('ews_location_name','Office HQ');
            add_option('ews_location_latitude','');
            add_option('ews_location_longitude','');
            add_option('ews_location_radius',200);
            add_option('ews_feature_tasks',false,false);
            ob_end_clean();
        }

    static function activate_vapid_keys(){
            if(get_option('ews_vapid_public_key','') && get_option('ews_vapid_private_key','')) return true;
            if(!function_exists('openssl_pkey_new')) return false;
            $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
            if(!$key)return false;
            if(!openssl_pkey_export($key,$private_pem))return false;
            $details=openssl_pkey_get_details($key);
            if(empty($details['ec']['x'])||empty($details['ec']['y']))return false;
            $public="\x04".$details['ec']['x'].$details['ec']['y'];
            $b64=function($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');};
            update_option('ews_vapid_public_key',$b64($public),false);
            update_option('ews_vapid_private_key',$private_pem,false);
            return true;
        }

    static function deactivate(){ wp_clear_scheduled_hook('ews_notifications_cleanup'); wp_clear_scheduled_hook('ews_auto_attendance_tick'); wp_clear_scheduled_hook('ews_smart_nudges_tick'); wp_clear_scheduled_hook('ews_privacy_cleanup'); }

}

register_activation_hook(__FILE__, ['EWS_Manager_V31_1','activate']);
register_deactivation_hook(__FILE__, ['EWS_Manager_V31_1','deactivate']);

/*
 * Do not construct the plugin object during the WordPress plugin-activation
 * request. The activation callback is static and does not need the object.
 */
$ews_activation_request=(is_admin() && isset($_REQUEST['action']) && in_array($_REQUEST['action'],['activate','activate-plugin'],true));
if(!$ews_activation_request){
    new EWS_Manager_V31_1();
}
