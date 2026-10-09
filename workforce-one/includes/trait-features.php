<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\FeatureSettings;

/**
 * wp-admin "Feature Configuration": optional features and their settings. Option names are
 * unchanged. Rules: src/Settings/FeatureSettings.php. Behaviour: tests/e2e_features.py.
 */
trait EWS_Features_Trait {

    private function features_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-features')));
        exit;
    }

    public function admin_features(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $error=sanitize_key($_GET['features_error']??'');
        echo $this->render_template('admin/features',[
            'branch'=>$this->branch_settings(),'branch_count'=>count($this->branch_locations()),'locations_url'=>admin_url('admin.php?page=ews31-multi-locations'),
            'presence_minutes'=>\WorkforceOne\Settings\FeatureSettings::presenceMinutes($this->option('ews_presence_request_minutes')),
            'on'=>[
                'tasks'=>$this->tasks_enabled(),
                'corrections'=>$this->corrections_enabled(),
                'presence_qr'=>(bool)(int)$this->option('ews_presence_qr_signin'),
                'presence_verification'=>(bool)(int)$this->option('ews_presence_verification'),
                'breaks'=>$this->break_enabled(),
                'face'=>$this->face_signin_enabled(),
                'recognition'=>(bool)(int)$this->option('ews_feature_recognition'),
                'kudos'=>(bool)(int)$this->option('ews_recognition_allow_kudos'),
                'overtime'=>$this->overtime_enabled(),
                'early_leave_office_only'=>(bool)(int)$this->option('ews_early_leave_office_only'),
                'confirm_global'=>(bool)(int)$this->option('ews_confirm_global'),
            ],
            'breaks'=>['per_day'=>$this->break_per_day(),'duration'=>$this->break_duration_minutes(),'escalation'=>$this->break_escalation_minutes()],
            'early_leave'=>['max'=>(int)$this->option('ews_early_leave_max_minutes'),'monthly'=>(int)$this->option('ews_early_leave_monthly_minutes')],
            'face'=>$this->face_signin_settings(),
            'face_fields'=>FeatureSettings::FACE_FIELDS,
            'detector_sizes'=>FeatureSettings::DETECTOR_SIZES,
            'splash'=>$this->pwa_splash_settings(),
            'recognition'=>FeatureSettings::recognition($this->option('ews_recognition_weekly_limit_mode'),$this->option('ews_recognition_weekly_limit')),
            'confirm_labels'=>FeatureSettings::CONFIRM_ACTIONS,
            'confirm'=>FeatureSettings::confirmState($this->option('ews_confirmation_actions')),
            'privacy_html'=>$this->privacy_settings_section(),
            'saved'=>isset($_GET['features_saved']),
            'error'=>$error!==''?FeatureSettings::errorMessage($error):null,
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function features_save_handler(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_features_save');
        $post=wp_unslash($_POST);
        $flag=function($key)use($post){return !empty($post[$key]);};

        // Validate everything before saving anything.
        [$early,$error]=FeatureSettings::earlyLeave($post['early_leave_max']??120,$post['early_leave_monthly']??240);
        if($error)$this->features_redirect(['features_error'=>$error]);

        $splash_post=is_array($post['pwa_splash']??null)?$post['pwa_splash']:[];
        foreach(['title','subtitle'] as $k)$splash_post[$k]=sanitize_text_field($splash_post[$k]??'');
        $splash=FeatureSettings::splash($splash_post);
        $splash['logo']=esc_url_raw($splash['logo']);
        $breaks=FeatureSettings::breaks($post['breaks_per_day']??3,$post['break_duration']??30,$post['break_escalation']??45);
        $recognition=FeatureSettings::recognition(sanitize_key($post['recognition_weekly_limit_mode']??'limited'),$post['recognition_weekly_limit']??5);

        update_option('ews_pwa_splash_settings',$splash,false);
        update_option('ews_presence_qr_signin',$flag('presence_qr_signin')?1:0,false);
        update_option('ews_presence_verification',$flag('presence_verification')?1:0,false);
        // A form without the field (an older page) keeps the saved time.
        if(isset($post['presence_request_minutes']))update_option('ews_presence_request_minutes',FeatureSettings::presenceMinutes($post['presence_request_minutes']),false);
        $this->privacy_settings_save();
        update_option('ews_feature_recognition',$flag('recognition_enabled'),false);
        update_option('ews_recognition_allow_kudos',$flag('recognition_allow_kudos'),false);
        update_option('ews_recognition_weekly_limit_mode',$recognition['mode'],false);
        update_option('ews_recognition_weekly_limit',$recognition['limit'],false);
        update_option('ews_feature_tasks',$flag('tasks_enabled'),false);
        // A form without the field (an older page) keeps the saved state.
        if(isset($post['corrections_present']))update_option('ews_feature_corrections',$flag('corrections_enabled')?1:0,false);
        update_option('ews_feature_overtime',$flag('overtime_enabled'),false);
        update_option('ews_feature_face_signin',$flag('face_signin_enabled'),false);
        update_option('ews_face_signin_settings',FeatureSettings::face($post['face_signin_settings']??[]),false);
        update_option('ews_feature_breaks',$flag('break_enabled'),false);
        update_option('ews_breaks_per_day',$breaks['per_day'],false);
        update_option('ews_break_duration_minutes',$breaks['duration'],false);
        update_option('ews_break_manager_alert_minutes',$breaks['escalation'],false);
        update_option('ews_early_leave_max_minutes',$early['max'],false);
        update_option('ews_early_leave_monthly_minutes',$early['monthly'],false);
        update_option('ews_early_leave_office_only',$flag('early_leave_office_only')?1:0,false);
        update_option('ews_confirm_global',$flag('confirm_global')?1:0,false);
        // A form without the field (an older page) keeps the saved branch settings.
        if(isset($post['branch_mode'])){
            $branch_before=$this->branch_settings();$branch=\WorkforceOne\Settings\BranchSettings::fromPost($post);
            update_option('ews_branch_settings',$branch,false);
            if($branch!==$branch_before)$this->audit('branch_settings_update','settings',0,'mode='.$branch['mode'].'; allow_others='.$branch['allow_others'].'; kiosk_any='.$branch['kiosk_any'].'; show_branch='.$branch['show_branch'].'; manager_scope='.$branch['manager_scope']);
        }
        update_option('ews_confirmation_actions',FeatureSettings::confirmActions($post['confirm_actions']??[]),false);

        $state=function($on){return $on?'enabled':'disabled';};
        $this->audit('feature_update','settings',0,implode(';',[
            'tasks='.$state($flag('tasks_enabled')),'corrections='.$state($this->corrections_enabled()),'overtime_requests='.$state($flag('overtime_enabled')),
            'face_signin='.$state($flag('face_signin_enabled')),'breaks='.$state($flag('break_enabled')),
            'breaks_per_day='.$breaks['per_day'],'break_duration='.$breaks['duration'],'break_escalation='.$breaks['escalation'],
            'early_leave='.$early['max'].'/'.$early['monthly'],'presence_qr='.$state($flag('presence_qr_signin')),
            'recognition='.$state($flag('recognition_enabled')),'kudos='.($flag('recognition_allow_kudos')?'allowed':'blocked'),
            'kudos_weekly='.($recognition['mode']==='unlimited'?'unlimited':$recognition['limit']),
        ]));
        $this->features_redirect(['features_saved'=>1]);
    }
}
