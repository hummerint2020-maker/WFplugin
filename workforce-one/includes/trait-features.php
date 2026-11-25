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

    /**
     * The Office Minimum section's tab (3.31.154): the company's (0), or a department's own settings; a department without
     * its own starts from a copy of the company's, without the teams' fixed numbers (owner answer 9).
     * @return array{dept:int,own:bool,settings:array<string,mixed>,departments:array<int,array{name:string,own:bool,people:int}>}
     */
    private function features_om_tab(){
        $departments=$this->om_admin_departments();
        $dept=absint(wp_unslash($_GET['om_dept']??0));
        if(!isset($departments[$dept]))$dept=0;
        $own=$dept&&!empty($departments[$dept]['own']);
        $settings=$own?$this->om_cfg($dept):$this->om_settings();
        if($dept&&!$own)$settings['teams']=[];
        return ['dept'=>$dept,'own'=>$own,'settings'=>$settings,'departments'=>$departments];
    }

    public function admin_features(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        $om_tab=$this->features_om_tab();
        $error=sanitize_key($_GET['features_error']??'');
        echo $this->render_template('admin/features',[
            'branch'=>$this->branch_settings(),'branch_count'=>count($this->branch_locations()),'locations_url'=>admin_url('admin.php?page=ews31-multi-locations'),
            'presence_minutes'=>\WorkforceOne\Settings\FeatureSettings::presenceMinutes($this->option('ews_presence_request_minutes')),
            'on'=>[
                'tasks'=>$this->tasks_enabled(),
                'corrections'=>$this->corrections_enabled(),
                'my_approvals'=>$this->my_approvals_enabled(),
                'hub_reports'=>$this->hub_reports_enabled(),'my_requests_leave'=>(bool)$this->option('ews_feature_my_requests_leave'),'leave_return'=>(bool)$this->option('ews_feature_leave_return'),'leave_holidays'=>(bool)$this->option('ews_feature_leave_holidays'),'my_requests_cx_balance'=>(bool)$this->option('ews_feature_my_requests_cx_balance'),'leave_page'=>(bool)$this->option('ews_feature_leave_page'),
                'app_api'=>$this->app_api_enabled(),
                'api_refresh_safe'=>$this->api_refresh_safe_enabled(),
                'substitutes'=>$this->substitutes_enabled(),
                'advances'=>$this->advances_enabled(),
                'hr_letters'=>$this->hr_letters_enabled(),
                'occasions'=>$this->occasions_enabled(),
                'health'=>$this->health_enabled(),
                'daily_workers'=>$this->dw_enabled(),
                'office_minimum'=>$this->om_enabled(),
                'week_lock'=>$this->week_lock_enabled(),
                'attendance_cards'=>$this->attendance_cards_enabled(),
                'leave_type'=>$this->leave_type_enabled(),
                'presence_qr'=>(bool)(int)$this->option('ews_presence_qr_signin'),
                'presence_verification'=>(bool)(int)$this->option('ews_presence_verification'),
                'breaks'=>$this->break_enabled(),
                'face'=>$this->face_signin_enabled(),
                'recognition'=>(bool)(int)$this->option('ews_feature_recognition'),
                'kudos'=>(bool)(int)$this->option('ews_recognition_allow_kudos'),
                'overtime'=>$this->overtime_enabled(),
                'early_leave_office_only'=>(bool)(int)$this->option('ews_early_leave_office_only'),
                'early_leave_self'=>(bool)(int)$this->option('ews_early_leave_self'),
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
            'occasions'=>$this->occasion_admin_data(),
            'att_page_cards'=>$this->attendance_page_cards(),
            'hr_letters'=>['s'=>$this->hr_letter_settings(),'hr_set'=>(int)($this->approval_settings()['hr_user']??0)>0,'page_url'=>admin_url('admin.php?page=ews31-hr-letters&tab=types'),'workflow_url'=>admin_url('admin.php?page=ews31-approvals')],
            'advance'=>$this->advance_settings(),'advances_url'=>admin_url('admin.php?page=ews31-advances'),
            'health_rate'=>\WorkforceOne\Health\Settings::rate($this->option('ews_health_sample_rate')),'health_retention'=>\WorkforceOne\Health\Settings::retention($this->option('ews_health_retention_days')),'health_url'=>admin_url('admin.php?page=ews31-system-health'),
            'substitute'=>$this->substitute_settings(),'substitutes_url'=>admin_url('admin.php?page=ews31-substitutes'),
            'recognition'=>FeatureSettings::recognition($this->option('ews_recognition_weekly_limit_mode'),$this->option('ews_recognition_weekly_limit')),
            'confirm_labels'=>FeatureSettings::CONFIRM_ACTIONS,
            'confirm'=>FeatureSettings::confirmState($this->option('ews_confirmation_actions')),
            'privacy_html'=>$this->privacy_settings_section(),
            'office_minimum'=>$om_tab['settings'],'om_teams'=>$this->om_admin_teams($om_tab['dept']),'om_managers'=>$this->om_admin_managers($om_tab['dept']),'om_statuses'=>$this->schedule_type_names(true),
            'om_departments'=>$om_tab['departments'],'om_dept'=>$om_tab['dept'],'om_dept_own'=>$om_tab['own'],
            'week_lock'=>$this->week_lock_settings(),'week_lock_next'=>$this->week_lock_next(),'timezone'=>wp_timezone_string(),'week_lock_can'=>$this->week_lock_can_override(),
            'roles_url'=>admin_url('admin.php?page=ews31-roles'),
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
        $om_dept=0;
        if(isset($post['office_minimum_present'])){
            // A department's tab (3.31.154) saves that department's settings only; the company's stay as they are.
            $om_dept=isset($post['om_dept_present'])?absint($post['om_dept']??0):0;
            // A department tab whose department is gone (archived meanwhile): never saved as the company's (review 4).
            if(isset($post['om_dept_present'])&&!isset($this->people_department_names()[$om_dept]))$this->features_redirect(['features_error'=>'om_dept']);
            [$om,$om_error]=$om_dept
                ?\WorkforceOne\Settings\OfficeMinimumSettings::departmentFromPost($post,$this->schedule_type_names(true),get_option('ews_office_minimum',[]),$om_dept)
                :\WorkforceOne\Settings\OfficeMinimumSettings::fromPost($post,$this->schedule_type_names(true),get_option('ews_office_minimum',[]));
            if($om_error!=='')$this->features_redirect(['features_error'=>'om_'.$om_error]+($om_dept?['om_dept'=>$om_dept]:[]));
        }
        $wl=null;
        // Only someone who may edit a final week may move the cutoff or switch the lock off, or a week could be reopened unaudited.
        if(isset($post['week_lock_present'])&&$this->week_lock_can_override()){
            [$wl,$wl_error]=\WorkforceOne\Settings\WeekLockSettings::fromPost($post);
            if($wl_error!=='')$this->features_redirect(['features_error'=>'wl_'.$wl_error]);
        }
        $occ=$this->occasions_validate($post);   // Occasions (3.31.145)
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
        // Leave in My requests (3.31.158): the Leave page can be hidden only while leave is in My requests, so asking to cancel never disappears.
        if(isset($post['my_requests_leave_present'])){
            $mrl=$flag('my_requests_leave_enabled');$page=$mrl?$flag('leave_page_enabled'):true;
            if((bool)$this->option('ews_feature_my_requests_leave')!==$mrl){update_option('ews_feature_my_requests_leave',$mrl?1:0,false);$this->audit('my_requests_leave_feature_update','settings',0,$mrl?'enabled':'disabled');}
            if((bool)$this->option('ews_feature_leave_page')!==$page){update_option('ews_feature_leave_page',$page?1:0,false);$this->audit('leave_page_feature_update','settings',0,$page?'shown':'hidden');}
        }
        // Leave return (3.31.161, docs/tasks/leave-return.md): written and audited only when it changes.
        if(isset($post['leave_return_present'])&&(bool)$this->option('ews_feature_leave_return')!==$flag('leave_return_enabled')){
            update_option('ews_feature_leave_return',$flag('leave_return_enabled')?1:0,false);
            $this->audit('leave_return_feature_update','settings',0,$flag('leave_return_enabled')?'enabled':'disabled');
        }
        // Company holidays are not leave days (3.31.162, docs/tasks/leave-holidays.md): written and audited only when it changes.
        if(isset($post['leave_holidays_present'])&&(bool)$this->option('ews_feature_leave_holidays')!==$flag('leave_holidays_enabled')){
            update_option('ews_feature_leave_holidays',$flag('leave_holidays_enabled')?1:0,false);
            $this->audit('leave_holidays_feature_update','settings',0,$flag('leave_holidays_enabled')?'enabled':'disabled');
        }
        // Corrections balance in My requests (3.31.163): written and audited only when it changes.
        if(isset($post['my_requests_cx_balance_present'])&&(bool)$this->option('ews_feature_my_requests_cx_balance')!==$flag('my_requests_cx_balance_enabled')){
            update_option('ews_feature_my_requests_cx_balance',$flag('my_requests_cx_balance_enabled')?1:0,false);
            $this->audit('my_requests_cx_balance_feature_update','settings',0,$flag('my_requests_cx_balance_enabled')?'enabled':'disabled');
        }
        // Requests Hub reports (3.31.159): written and audited only when it changes.
        if(isset($post['hub_reports_present'])&&$this->hub_reports_enabled()!==$flag('hub_reports_enabled')){
            update_option('ews_feature_hub_reports',$flag('hub_reports_enabled')?1:0,false);
            $this->audit('hub_reports_feature_update','settings',0,$flag('hub_reports_enabled')?'enabled':'disabled');
        }
        // The Attendance page's cards open who and when (3.31.125).
        if(isset($post['attendance_cards_present']))update_option('ews_feature_attendance_cards',$flag('attendance_cards_enabled')?1:0,false);
        // Leave type and the Attendance page's cards (3.31.152): written and audited only when they change, so a save that leaves them stores nothing.
        if(isset($post['leave_type_present'])&&$this->leave_type_enabled()!==$flag('leave_type_enabled')){
            update_option('ews_feature_leave_type',$flag('leave_type_enabled')?1:0,false);
            $this->audit('leave_type_feature_update','settings',0,$flag('leave_type_enabled')?'enabled':'disabled');
        }
        if(isset($post['att_page_cards_present'])){
            $apc=\WorkforceOne\Settings\AttendancePageCards::fromPost((array)($post['att_page_cards']??[]));
            if($apc!==$this->attendance_page_cards()){
                if($apc['hidden'])update_option('ews_attendance_page_cards',$apc,false);else delete_option('ews_attendance_page_cards');
                $this->audit('attendance_page_cards_update','settings',0,'hidden='.($apc['hidden']?implode(',',$apc['hidden']):'none'));
            }
        }
        if(isset($post['app_api_present']) && (bool)(int)$this->option('ews_feature_app_api')!==$flag('app_api_enabled'))update_option('ews_feature_app_api',$flag('app_api_enabled')?1:0,false);   // nothing is written while it stays off
        if(isset($post['app_api_present']) && $this->api_refresh_safe_enabled()!==$flag('api_refresh_safe'))update_option('ews_feature_api_refresh_safe',$flag('api_refresh_safe')?1:0,false);   // the refresh fix (3.31.114), same rule
        // Salary advances (3.31.109).
        if(isset($post['advances_present'])){
            update_option('ews_feature_advances',$flag('advances_enabled')?1:0,false);
            update_option('ews_advance_settings',\WorkforceOne\Payroll\Advances::settings(['months_salary'=>$post['advance_months_salary']??null,'max_amount'=>$post['advance_max_amount']??null,
                'max_instalments'=>$post['advance_max_instalments']??null,'one_open'=>$flag('advance_one_open')?1:0,'min_service_months'=>$post['advance_min_service']??null,'max_moves'=>$post['advance_max_moves']??null]),false);
        }
        // HR letters (HL3): written only when something changed, so a site that keeps them off stores nothing new.
        if(isset($post['hr_letters_present'])){
            if($this->hr_letters_enabled()!==$flag('hr_letters_enabled'))update_option('ews_feature_hr_letters',$flag('hr_letters_enabled')?1:0,false);
            $hl=\WorkforceOne\HrLetters\LetterSettings::fromForm(['languages'=>(array)($post['hl_languages']??[]),'addressee_ar'=>$post['hl_addressee_ar']??'','addressee_en'=>$post['hl_addressee_en']??'',
                'other'=>$flag('hl_other'),'one_open'=>$flag('hl_one_open'),'new_type_delivery'=>$this->hr_letter_settings()['new_type_delivery'],'currency_ar'=>$post['hl_currency_ar']??'','currency_en'=>$post['hl_currency_en']??'']);
            if($hl!==$this->hr_letter_settings())update_option('ews_hr_letters',$hl,false);
        }
        $this->occasions_store($occ);
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
            $this->substitutes_due_now();   // the next page load syncs, as it did within a minute before (3.31.151)
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
        if(isset($post['early_leave_self_shown']))update_option('ews_early_leave_self',$flag('early_leave_self')?1:0,false);   // an older form without the field keeps the saved value
        update_option('ews_confirm_global',$flag('confirm_global')?1:0,false);
        // A form without the field (an older page) keeps the saved branch settings.
        if(isset($post['branch_mode'])){
            $branch_before=$this->branch_settings();$branch=\WorkforceOne\Settings\BranchSettings::fromPost($post);
            update_option('ews_branch_settings',$branch,false);
            if($branch!==$branch_before)$this->audit('branch_settings_update','settings',0,'mode='.$branch['mode'].'; allow_others='.$branch['allow_others'].'; kiosk_any='.$branch['kiosk_any'].'; show_branch='.$branch['show_branch'].'; manager_scope='.$branch['manager_scope']);
        }
        if($om!==null){
            $om_before=$this->om_settings();$om_was=$this->om_enabled();
            $om_dept_before=$om_dept?(\WorkforceOne\Settings\OfficeMinimumSettings::departments(get_option('ews_office_minimum',[]))[$om_dept]??null):null;
            update_option('ews_office_minimum',$om,false);
            update_option('ews_feature_office_minimum',$flag('office_minimum_enabled')?1:0,false);
            $om_after=\WorkforceOne\Settings\OfficeMinimumSettings::config($om);
            // The managers' part (3.31.153) is added to the line only when it is used or was used, so the line is as before otherwise.
            $mk=['mgr'=>1,'mgr_min'=>1,'mgr_days'=>1];
            $om_mgr=array_intersect_key($om_before,$mk)!==array_intersect_key($om_after,$mk)||!empty($om_after['mgr'])
                ?'; managers='.(!empty($om_after['mgr'])?'on':'off').' min='.(int)$om_after['mgr_min'].($om_after['mgr_days']?' days='.implode(',',array_map(function($w,$n){return $w.':'.$n;},array_keys($om_after['mgr_days']),$om_after['mgr_days'])):''):'';
            if($om_before!==$om_after||$om_was!==$this->om_enabled())$this->audit('office_minimum_update','settings',0,($this->om_enabled()?'enabled':'disabled').'; min='.$om_after['min'].'; statuses='.implode(',',$om_after['statuses']).'; reminder='.($om_after['reminder']?'on':'off').$om_mgr);
            if($om_dept){   // a department's tab (3.31.154): its own audit line, only when it changed
                $om_dept_after=\WorkforceOne\Settings\OfficeMinimumSettings::departments($om)[$om_dept]??null;
                $pairs=function($a){return implode(',',array_map(function($k,$v){return $k.':'.$v;},array_keys($a),$a));};
                if($om_dept_before!==$om_dept_after)$this->audit('office_minimum_department_update','department',$om_dept,$om_dept_after===null?'own=off':
                    'own=on; min='.$om_dept_after['min'].($om_dept_after['days']?' days='.$pairs($om_dept_after['days']):'').'; statuses='.implode(',',$om_dept_after['statuses'])
                    .($om_dept_after['teams']?'; teams='.$pairs($om_dept_after['teams']):'').'; reminder='.($om_dept_after['reminder']?'on':'off')
                    .'; managers='.(!empty($om_dept_after['mgr'])?'on':'off').' min='.(int)$om_dept_after['mgr_min'].($om_dept_after['mgr_days']?' days='.$pairs($om_dept_after['mgr_days']):''));
            }
        }
        if($wl!==null){
            $wl_before=$this->week_lock_settings();$wl_was=$this->week_lock_enabled();
            update_option('ews_week_lock',$wl,false);
            update_option('ews_feature_week_lock',$flag('week_lock_enabled')?1:0,false);
            $wl_on=$this->week_lock_enabled();
            // Switched on: the cutoff that has passed already does not send a notification now (only the next one does).
            if($wl_on&&!$wl_was){$c=\WorkforceOne\Settings\WeekLockSettings::config($wl);update_option('ews_week_lock_notified',\WorkforceOne\Schedule\WeekLock::latest(current_time('mysql'),$this->working_days(),(int)$c['cutoff_day'],(string)$c['cutoff_time'])['week'],false);}
            if($wl_before!==\WorkforceOne\Settings\WeekLockSettings::config($wl)||$wl_was!==$wl_on)$this->audit('week_lock_update','settings',0,($wl_on?'enabled':'disabled').'; cutoff='.$wl['cutoff_day'].' '.$wl['cutoff_time'].'; notify='.($wl['notify']?'on':'off'));
        }
        update_option('ews_confirmation_actions',FeatureSettings::confirmActions($post['confirm_actions']??[]),false);

        $state=function($on){return $on?'enabled':'disabled';};
        $this->audit('feature_update','settings',0,implode(';',[
            'tasks='.$state($flag('tasks_enabled')),'corrections='.$state($this->corrections_enabled()),'attendance_cards='.$state($this->attendance_cards_enabled()),'daily_workers='.$state($this->dw_enabled()),'overtime_requests='.$state($flag('overtime_enabled')),
            'face_signin='.$state($flag('face_signin_enabled')),'breaks='.$state($flag('break_enabled')),
            'breaks_per_day='.$breaks['per_day'],'break_duration='.$breaks['duration'],'break_escalation='.$breaks['escalation'],
            'early_leave='.$early['max'].'/'.$early['monthly'],'presence_qr='.$state($flag('presence_qr_signin')),
            'recognition='.$state($flag('recognition_enabled')),'kudos='.($flag('recognition_allow_kudos')?'allowed':'blocked'),
            'kudos_weekly='.($recognition['mode']==='unlimited'?'unlimited':$recognition['limit']),
        ]));
        $this->features_redirect(['features_saved'=>1]+($om_dept?['om_dept'=>$om_dept]:[]));
    }
}
