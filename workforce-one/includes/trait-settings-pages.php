<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\NotificationSettings;
use WorkforceOne\Settings\RolePermissions;

/**
 * wp-admin "Roles & Permissions" and "Notification Settings".
 * Rules: src/Settings/RolePermissions.php, src/Settings/NotificationSettings.php.
 * Behaviour: tests/e2e_settings_pages.py.
 */
trait EWS_Settings_Pages_Trait {

    /* ------------------------------------------------------------------ roles */

    /** @return array<string, array<string, bool>> the matrix as the roles hold it now */
    private function roles_matrix(){
        $saved=$this->option('ews_role_permissions');
        $matrix=[];
        foreach($this->role_defs() as $slug=>$def){
            $role=get_role($slug);
            $current=$role?(array)$role->capabilities:[];
            foreach(array_keys($this->permission_defs()) as $cap){
                $matrix[$slug][$cap]=RolePermissions::desired(is_array($saved[$slug]??null)?$saved[$slug]:null,$current,$cap,in_array($cap,$def['caps'],true));
            }
        }
        return $matrix;
    }

    /** Applies the matrix: on = granted, off = removed (never stored as false), and remembers it. */
    private function roles_apply(array $matrix){
        foreach($matrix as $slug=>$caps){
            $role=get_role($slug);
            if(!$role)continue;
            foreach($caps as $cap=>$on){
                if($on)$role->add_cap($cap,true);
                elseif(array_key_exists($cap,(array)$role->capabilities))$role->remove_cap($cap);
            }
        }
        update_option('ews_role_permissions',$matrix,false);
    }

    /** Runs on activation / schema upgrades: creates the roles and migrates stored "false" caps. */
    private function ensure_roles_permissions(){
        foreach($this->role_defs() as $slug=>$def){
            if(!get_role($slug))add_role($slug,$def['name'],['read'=>true]);
        }
        $this->roles_apply($this->roles_matrix());
        // WordPress admin menus evaluate the capability directly; make sure
        // the native Administrator role can see all EWS admin pages.
        $admin=get_role('administrator');
        if($admin){
            foreach($this->permission_defs() as $cap=>$label)$admin->add_cap($cap,true);
        }
    }

    public function admin_roles(){
        if(!$this->can('ews_manage_roles'))wp_die('Access denied');
        echo $this->render_template('admin/roles',[
            'roles'=>$this->role_defs(),'permissions'=>$this->permission_defs(),'matrix'=>$this->roles_matrix(),
            'saved'=>($_GET['roles_notice']??'')==='saved',
            'error'=>($_GET['roles_error']??'')==='lockout'?'You cannot remove "Manage Roles & Permissions" from every role you hold: you would lose access to this page. Ask a WordPress administrator to make that change.':null,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function admin_roles_save(){
        if(!$this->can('ews_manage_roles'))wp_die('Access denied');
        check_admin_referer('ews31_roles_save');
        $matrix=RolePermissions::fromPost($_POST['roles']??[],array_keys($this->role_defs()),array_keys($this->permission_defs()));
        $page=admin_url('admin.php?page=ews31-roles');
        if(RolePermissions::locksOut($matrix,(array)wp_get_current_user()->roles,current_user_can('manage_options'))){
            wp_safe_redirect(add_query_arg('roles_error','lockout',$page));exit;
        }
        $this->roles_apply($matrix);
        $this->audit('roles_permissions_update','role',0,'Updated EWS role permissions');
        wp_safe_redirect(add_query_arg('roles_notice','saved',$page));exit;
    }

    /* ------------------------------------------------------------------ notifications */

    private function notifications_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-notifications')));
        exit;
    }

    public function admin_notification_settings(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        global $wpdb;
        $push_table=$this->push_table();
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->employees} WHERE active=1 AND wp_user_id>0");
        $configured=(bool)($this->option('ews_vapid_public_key') && $this->option('ews_vapid_private_key'));
        $ready=$configured?(int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$push_table} WHERE user_id IN (SELECT wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id>0)"):0;
        $notices=['policy'=>'Notification policy updated successfully.','retention'=>'Notification retention saved.'];
        $notice=$notices[sanitize_key($_GET['notifications_notice']??'')]??null;
        if(isset($_GET['push_sent']))$notice=sprintf('Push test sent to %d device(s). Expired: %d. Errors: %d.',(int)$_GET['push_sent'],(int)($_GET['push_expired']??0),(int)($_GET['push_error']??0));
        echo $this->render_template('admin/notifications',[
            'health'=>['ready'=>$ready,'missing'=>$configured?max(0,$total-$ready):0,'not_configured'=>$configured?0:$total,'configured'=>$configured],
            'categories'=>$this->notification_policy_categories(),'policy'=>$this->notification_policy(),
            'retention'=>NotificationSettings::retention($this->option('ews_notification_retention_days')),
            'retention_choices'=>NotificationSettings::RETENTION_DAYS,
            'vapid_subject'=>(string)get_option('ews_vapid_subject','mailto:'.get_option('admin_email','admin@example.com')),
            'notice'=>$notice,'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function admin_notification_policy_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_notification_policy_save');
        update_option('ews_notification_policy',NotificationSettings::policy(wp_unslash($_POST['policy']??[]),array_keys($this->notification_policy_categories())),false);
        update_option('ews_notification_policy_updated_at',current_time('mysql'),false);
        update_option('ews_notification_policy_updated_by',get_current_user_id(),false);
        $this->audit('notification_policy_update','settings',0,'Notification policy updated');
        $this->notifications_redirect(['notifications_notice'=>'policy']);
    }

    public function admin_notification_settings_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_notification_settings_save');
        update_option('ews_notification_retention_days',NotificationSettings::retention($_POST['retention_days']??NotificationSettings::DEFAULT_RETENTION));
        $this->notifications_redirect(['notifications_notice'=>'retention']);
    }

    /** The VAPID subject field sits in the test-push form: save it before sending. */
    private function notifications_save_vapid_subject(){
        $subject=NotificationSettings::vapidSubject(sanitize_text_field(wp_unslash($_POST['vapid_subject']??'')));
        if($subject!==null)update_option('ews_vapid_subject',$subject,false);
    }
}
