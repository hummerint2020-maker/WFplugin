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
                'my_approvals'=>$this->my_approvals_enabled(),
                'substitutes'=>$this->substitutes_enabled(),
                'advances'=>$this->advances_enabled(),
                'health'=>$this->health_enabled(),
                'daily_workers'=>$this->dw_enabled(),
                'office_minimum'=>$this->om_enabled(),
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
            'evidence'=>$this->evidence_settings(),
            'face_fields'=>FeatureSettings::FACE_FIELDS,
            'detector_sizes'=>FeatureSettings::DETECTOR_SIZES,
            'splash'=>$this->pwa_splash_settings(),
            'offline'=>$this->offline_settings(),'offline_texts'=>\WorkforceOne\Settings\OfflineScreen::TEXTS,'offline_sections'=>\WorkforceOne\Settings\OfflineScreen::SECTIONS,
            'offline_preview'=>add_query_arg('ews_pwa','offline',home_url('/')),
            'org'=>$this->org_settings(),'org_url'=>admin_url('admin.php?page=ews31-org-chart'),
            'advance'=>$this->advance_settings(),'advances_url'=>admin_url('admin.php?page=ews31-advances'),
            'health_rate'=>\WorkforceOne\Health\Settings::rate($this->option('ews_health_sample_rate')),'health_retention'=>\WorkforceOne\Health\Settings::retention($this->option('ews_health_retention_days')),'health_url'=>admin_url('admin.php?page=ews31-system-health'),
            'substitute'=>$this->substitute_settings(),'substitutes_url'=>admin_url('admin.php?page=ews31-substitutes'),
            'recognition'=>FeatureSettings::recognition($this->option('ews_recognition_weekly_limit_mode'),$this->option('ews_recognition_weekly_limit')),
            'confirm_labels'=>FeatureSettings::CONFIRM_ACTIONS,
            'confirm'=>FeatureSettings::confirmState($this->option('ews_confirmation_actions')),
            'privacy_html'=>$this->privacy_settings_section(),
            'office_minimum'=>$this->om_settings(),'om_teams'=>$this->om_admin_teams(),'om_statuses'=>$this->schedule_type_names(true),
            'weekday_names'=>$this->working_day_names(),'working_days'=>$this->working_days(),
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
        $om=null;
        if(isset($post['office_minimum_present'])){
            [$om,$om_error]=\WorkforceOne\Settings\OfficeMinimumSettings::fromPost($post,$this->schedule_type_names(true));
            if($om_error!=='')$this->features_redirect(['features_error'=>'om_'.$om_error]);
        }
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
        // My approvals and My requests (3.31.103).
        if(isset($post['my_approvals_present']))update_option('ews_feature_my_approvals',$flag('my_approvals_enabled')?1:0,false);
        // Salary advances (3.31.109).
        if(isset($post['advances_present'])){
            update_option('ews_feature_advances',$flag('advances_enabled')?1:0,false);
            update_option('ews_advance_settings',\WorkforceOne\Payroll\Advances::settings(['months_salary'=>$post['advance_months_salary']??null,'max_amount'=>$post['advance_max_amount']??null,
                'max_instalments'=>$post['advance_max_instalments']??null,'one_open'=>$flag('advance_one_open')?1:0,'min_service_months'=>$post['advance_min_service']??null,'max_moves'=>$post['advance_max_moves']??null]),false);
        }
        // System health (3.31.110): the switch, how many requests give query detail, how long the daily history is kept.
        if(isset($post['health_present'])){
            $was=$this->health_enabled();$now=$flag('health_enabled')?1:0;
            // Nothing is written for a site that keeps it off; on a network only a super admin switches it.
            if(!is_multisite() || is_super_admin()){
                if($now||$was){
                    update_option('ews_health_sample_rate',\WorkforceOne\Health\Settings::rate($post['health_sample_rate']??null),false);
                    update_option('ews_health_retention_days',\WorkforceOne\Health\Settings::retention($post['health_retention_days']??null),false);
                }
                if((bool)$was!==(bool)$now){
                    update_option('ews_feature_health',$now,false);
                    $this->health_switch_changed((bool)$now);
                }
            }
        }
        // A substitute while a manager is away (3.31.107).
        if(isset($post['substitutes_present'])){
            update_option('ews_feature_substitutes',$flag('substitutes_enabled')?1:0,false);
            update_option('ews_substitute_settings',\WorkforceOne\Approvals\Substitutes::settings(['manager_decides'=>$flag('substitute_manager_decides')?1:0,
                'on_return'=>sanitize_key($post['substitute_on_return']??''),'who'=>sanitize_key($post['substitute_who']??''),'ask_on_leave'=>sanitize_key($post['substitute_ask']??'')]),false);
        }
        // Sign In evidence (3.31.92): refused attempts and the photo with Face Sign In.
        if(isset($post['evidence_present'])){
            $ev_before=$this->evidence_settings();$ev=\WorkforceOne\Settings\SignInEvidence::fromPost($post);
            update_option('ews_signin_evidence',$ev,false);
            if($ev['attempts']||$ev['photo'])$this->ensure_evidence_schema();
            if($ev!==$ev_before)$this->audit('signin_evidence_update','settings',0,'attempts='.($ev['attempts']?'on':'off').' '.$ev['attempt_days'].'d alert='.$ev['alert'].'; photo='.($ev['photo']?'on':'off').' '.$ev['photo_days'].'d viewers='.$ev['photo_viewers']);
        }
        // Offline screen of the installed app (3.31.93).
        if(isset($post['offline_present'])){
            $off_before=\WorkforceOne\Settings\OfflineScreen::summary(is_array($this->option('ews_offline_screen'))?$this->option('ews_offline_screen'):[]);
            $off=\WorkforceOne\Settings\OfflineScreen::fromPost($post);
            update_option('ews_offline_screen',$off,false);
            if(\WorkforceOne\Settings\OfflineScreen::summary($off)!==$off_before)$this->audit('offline_screen_update','settings',0,\WorkforceOne\Settings\OfflineScreen::summary($off));
        }
        // Organization chart (3.31.97).
        if(isset($post['org_present'])){
            $org_before=$this->org_settings();$org=\WorkforceOne\Settings\OrgChartSettings::fromPost($post);
            update_option('ews_org_chart',$org,false);
            if($org!==$org_before)$this->audit('org_chart_update','settings',0,'enabled='.($org['enabled']?'on':'off').' audience='.$org['audience'].' employee_view='.$org['employee_view'].' wide_span='.$org['wide_span'].' title_required='.($org['title_required']?'yes':'no'));
        }
        if(isset($post['daily_workers_present'])){
            update_option('ews_feature_daily_workers',$flag('daily_workers_enabled')?1:0,false);
            if($this->dw_enabled())$this->ensure_dw_schema();
        }
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
        if($om!==null){
            $om_before=$this->om_settings();$om_was=$this->om_enabled();
            update_option('ews_office_minimum',$om,false);
            update_option('ews_feature_office_minimum',$flag('office_minimum_enabled')?1:0,false);
            if($om_before!==\WorkforceOne\Settings\OfficeMinimumSettings::config($om)||$om_was!==$this->om_enabled())$this->audit('office_minimum_update','settings',0,($this->om_enabled()?'enabled':'disabled').'; min='.$om['min'].'; statuses='.implode(',',$om['statuses']).'; reminder='.($om['reminder']?'on':'off'));
        }
        update_option('ews_confirmation_actions',FeatureSettings::confirmActions($post['confirm_actions']??[]),false);

        $state=function($on){return $on?'enabled':'disabled';};
        $this->audit('feature_update','settings',0,implode(';',[
            'tasks='.$state($flag('tasks_enabled')),'corrections='.$state($this->corrections_enabled()),'daily_workers='.$state($this->dw_enabled()),'overtime_requests='.$state($flag('overtime_enabled')),
            'face_signin='.$state($flag('face_signin_enabled')),'breaks='.$state($flag('break_enabled')),
            'breaks_per_day='.$breaks['per_day'],'break_duration='.$breaks['duration'],'break_escalation='.$breaks['escalation'],
            'early_leave='.$early['max'].'/'.$early['monthly'],'presence_qr='.$state($flag('presence_qr_signin')),
            'recognition='.$state($flag('recognition_enabled')),'kudos='.($flag('recognition_allow_kudos')?'allowed':'blocked'),
            'kudos_weekly='.($recognition['mode']==='unlimited'?'unlimited':$recognition['limit']),
        ]));
        $this->features_redirect(['features_saved'=>1]);
    }
}
