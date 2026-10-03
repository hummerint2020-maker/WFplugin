<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\Moments;
use WorkforceOne\Settings\Navigation;
use WorkforceOne\Settings\SmartNudges;

/**
 * Engagement settings pages in wp-admin: Smart Nudges, Employee Moments, Employee Profile
 * visibility, View Navigation and Recognition (Kudos moderation). Every change is a POST to
 * admin-post.php followed by a redirect, so refreshing a page never repeats it.
 * Rules: src/Settings/{SmartNudges,Moments,Navigation}.php. Behaviour: tests/e2e_engagement.py.
 */
trait EWS_Engagement_Admin_Trait {

    private function engagement_redirect($page,$args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page='.$page)));
        exit;
    }

    private function engagement_guard($nonce){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer($nonce);
    }

    /* ------------------------------------------------------------------ smart nudges */

    public function admin_smart_nudges(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $last=$this->option('ews_smart_nudges_last_result');
        $parts=[];
        if(is_array($last)){
            foreach(['employees'=>'Employees checked','nudges'=>'Active nudges','sent'=>'Push sent','skipped'=>'Skipped'] as $k=>$label)if(isset($last[$k]))$parts[]=$label.': '.(int)$last[$k];
            if(!empty($last['reason']))$parts[]='Status: '.sanitize_text_field($last['reason']);
        }
        $test=sanitize_key($_GET['smart_nudge_test']??'');
        echo $this->render_template('admin/smart-nudges',[
            'cfg'=>$this->smart_nudge_settings(),
            'items'=>['tasks'=>'Task reminder — tasks due today or overdue.','leave'=>'Leave reminder — pending leave requests awaiting a decision.','schedule'=>'Schedule reminder — tomorrow\'s planned schedule.'],
            'last_run'=>(int)$this->option('ews_smart_nudges_last_run'),'last_result'=>$parts,
            'notice'=>($_GET['nudges_notice']??'')==='saved'?'Smart Nudge settings saved.':null,
            'test_ok'=>$test===''?null:$test==='test_sent',
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function smart_nudges_save(){
        $this->engagement_guard('ews_smart_nudges_save');
        update_option('ews_smart_nudges',SmartNudges::fromPost(wp_unslash($_POST)),false);
        $this->audit('smart_nudges_update','settings',0,'Updated Smart Nudge configuration.');
        $this->engagement_redirect('ews31-smart-nudges',['nudges_notice'=>'saved']);
    }

    public function smart_nudge_test_push(){
        $this->engagement_guard('ews_smart_nudge_test_push');
        $sent=$this->push_custom_notification(get_current_user_id(),'Workforce One · Smart Nudge Test','This is a test push from Smart Nudges.','smart_nudge',0,$this->app_view_url('time'));
        $this->engagement_redirect('ews31-smart-nudges',['smart_nudge_test'=>$sent>0?'test_sent':'test_not_sent']);
    }

    /* ------------------------------------------------------------------ employee moments */

    public function admin_employee_moments(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        global $wpdb;
        $employees=(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
        $saved=$this->option('ews_employee_moments');
        $saved=is_array($saved)?$saved:[];
        $configured=0;
        foreach($employees as $e)if(!empty($saved[(int)$e->id]['birthday'])||!empty($saved[(int)$e->id]['join_date']))$configured++;
        echo $this->render_template('admin/moments',[
            'employees'=>$employees,'saved'=>$saved,'enabled'=>(bool)(int)$this->option('ews_employee_moments_enabled'),'configured'=>$configured,
            'notice'=>($_GET['moments_notice']??'')==='saved'?'Employee Moments settings saved.':null,
            'error'=>($_GET['moments_error']??'')==='date'?'Nothing was saved: a date is not valid, or a birthday is in the future.':null,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function employee_moments_save(){
        $this->engagement_guard('ews31_employee_moments_save');
        global $wpdb;
        $stored=$this->option('ews_employee_moments');
        $form_ids=array_map('intval',(array)$wpdb->get_col("SELECT id FROM {$this->employees} WHERE active=1"));
        [$clean,$error]=Moments::merge(wp_unslash($_POST['moments']??[]),is_array($stored)?$stored:[],$form_ids,current_time('Y-m-d'));
        if($error)$this->engagement_redirect('ews31-moments',['moments_error'=>$error]);
        update_option('ews_employee_moments_enabled',!empty($_POST['enabled'])?1:0,false);
        update_option('ews_employee_moments',$clean,false);
        $this->audit('employee_moments_update','employee_moments',0,'Updated Employee Moments configuration for '.count($clean).' employees.');
        $this->engagement_redirect('ews31-moments',['moments_notice'=>'saved']);
    }

    /* ------------------------------------------------------------------ employee profile visibility */

    private function profile_settings_labels(){
        return ['show_photo'=>'Profile photo / avatar','show_name'=>'Full name','show_team'=>'Team membership','show_email'=>'Work email','show_supervisor'=>'Supervisor','show_achievements'=>'Achievements','show_recognition'=>'Recognition'];
    }

    public function admin_employee_profile_settings(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        echo $this->render_template('admin/profile-settings',[
            'cfg'=>$this->employee_profile_settings(),'labels'=>$this->profile_settings_labels(),
            'saved'=>($_GET['profile_notice']??'')==='saved','post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function profile_settings_save(){
        $this->engagement_guard('ews31_profile_settings_save');
        $cfg=['enabled'=>!empty($_POST['enabled'])?1:0];
        foreach(array_keys($this->profile_settings_labels()) as $key)$cfg[$key]=!empty($_POST[$key])?1:0;
        update_option('ews_employee_profile_settings',$cfg,false);
        $this->audit('employee_profile_settings_update','settings',0,'Employee Profile visibility settings updated');
        $this->engagement_redirect('ews31-employee-profile-settings',['profile_notice'=>'saved']);
    }

    /* ------------------------------------------------------------------ view navigation */

    public function admin_navigation(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $notices=['saved'=>'Frontend navigation settings saved.','reset'=>'Frontend navigation restored to defaults.'];
        echo $this->render_template('admin/navigation',[
            'cfg'=>Navigation::config($this->option('ews_frontend_navigation')),'defaults'=>Navigation::DEFAULTS,
            'notice'=>$notices[sanitize_key($_GET['navigation_notice']??'')]??null,'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function navigation_save(){
        $this->engagement_guard('ews31_navigation_save');
        $posted=wp_unslash($_POST['nav']??[]);
        if(is_array($posted))foreach($posted as $k=>$row)if(is_array($row))foreach(['label','mobile_label'] as $f)if(isset($row[$f]))$posted[$k][$f]=sanitize_text_field($row[$f]);
        update_option('ews_frontend_navigation',Navigation::fromPost($posted),false);
        $this->audit('navigation_update','settings',0,'Frontend navigation updated');
        $this->engagement_redirect('ews31-navigation',['navigation_notice'=>'saved']);
    }

    public function navigation_reset(){
        $this->engagement_guard('ews31_navigation_reset');
        update_option('ews_frontend_navigation',Navigation::config([]),false);
        $this->audit('navigation_update','settings',0,'Frontend navigation restored to defaults');
        $this->engagement_redirect('ews31-navigation',['navigation_notice'=>'reset']);
    }

    /* ------------------------------------------------------------------ recognition */

    public function admin_recognition(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        global $wpdb;$this->ensure_recognition_schema();
        $rows=(array)$wpdb->get_results("SELECT k.*,s.name sender_name,r.name recipient_name FROM {$wpdb->prefix}ews_kudos k LEFT JOIN {$this->employees} s ON s.id=k.sender_employee_id LEFT JOIN {$this->employees} r ON r.id=k.recipient_employee_id ORDER BY k.created_at DESC LIMIT 200");
        $kudos=[];
        foreach($rows as $r){
            $kudos[]=['id'=>(int)$r->id,'date'=>date_i18n(get_option('date_format').' '.get_option('time_format'),strtotime($r->created_at)),
                'from'=>$r->sender_name?:'—','to'=>$r->recipient_name?:'—','category'=>$this->recognition_category_label($r->category),
                'message'=>$r->message?:'—','status'=>(string)$r->status,
                'delete_url'=>$r->status==='active'?wp_nonce_url(add_query_arg(['action'=>'ews31_kudos_delete','kudos_id'=>(int)$r->id],admin_url('admin-post.php')),'ews_delete_kudos_'.(int)$r->id):''];
        }
        echo $this->render_template('admin/recognition',['kudos'=>$kudos,'deleted'=>isset($_GET['kudos_deleted'])]);
    }

    public function kudos_delete(){
        $id=absint($_GET['kudos_id']??0);
        $this->engagement_guard('ews_delete_kudos_'.$id);
        $this->recognition_delete($id);
        $this->engagement_redirect('ews31-recognition',['kudos_deleted'=>1]);
    }
}
