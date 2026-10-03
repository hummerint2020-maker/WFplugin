<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\ProfileSummary;

/**
 * The employee app's frame: the [employee_app] entry points, which view is shown (and whether the
 * user may open it), the navigation from wp-admin → View Navigation, the profile menu, and the
 * login page with its failed-login and logged-out messages.
 * Views: templates/app/layout.php, templates/app/login.php; script: assets/js/workforce-one.js.
 * Behaviour: tests/e2e_layout.py.
 */
trait EWS_App_Layout_Trait {

    public function app(){
        if(!is_user_logged_in())return $this->login_page();
        return $this->render_app_view(sanitize_key($_GET['ews_view']??'dashboard'));
    }

    public function app_forced_view($view){
        if(!is_user_logged_in())return $this->login_page();
        return $this->render_app_view(sanitize_key($view));
    }

    /** May the current user open this view? Views they may not open show the Dashboard instead. */
    private function app_view_allowed($view){
        $profiles=$this->employee_profile_settings();
        switch($view){
            case 'dashboard': case 'schedule': case 'time': case 'vacation': case 'overtime': case 'tasks': case 'notifications': case 'profile': case 'presence': return true;
            case 'attendance': return $this->can('ews_manage_attendance');
            case 'employees': return $this->can('ews_manage_employees');
            case 'reports': case 'attendance-insights': return $this->can('ews_view_reports');
            case 'people': case 'employee': return $this->can('ews_view_people') && !empty($profiles['enabled']);
            case 'polls': return $this->polls_available();
            case 'pay': return $this->payroll_view_available();
        }
        return false;
    }

    private function render_app_view($view){
        if(!$this->app_view_allowed($view))$view='dashboard';
        switch($view){
            case 'schedule': $content=$this->schedule_content(); break;
            case 'attendance': $content=$this->attendance_content(); break;
            case 'time': $content=$this->time_content(); break;
            case 'employees': $content=$this->employees_content(); break;
            case 'reports': $content=$this->reports_content(); break;
            case 'attendance-insights': $content=$this->attendance_insights_content(); break;
            case 'vacation': $content=$this->vacation_content(); break;
            case 'overtime': $content=$this->overtime_content(); break;
            case 'tasks': $content=$this->tasks_content(); break;
            case 'notifications': $content=$this->notifications_content(); break;
            case 'profile': $content=$this->my_profile_content(); break;
            case 'people': $content=$this->people_content(); break;
            case 'employee': $content=$this->employee_profile_content(absint($_GET['employee_id']??0)); break;
            case 'presence': $content=$this->presence_content(); break;
            case 'polls': $content=$this->polls_content(); break;
            case 'pay': $content=$this->pay_content(); break;
            default: $content=$this->dashboard_content();
        }
        $titles=['pay'=>__('My Pay','workforce-one'),'dashboard'=>__('Dashboard','workforce-one'),'schedule'=>__('Schedule','workforce-one'),'time'=>__('Time','workforce-one'),'attendance'=>__('Attendance','workforce-one'),'employees'=>__('Employees','workforce-one'),'reports'=>__('Reports','workforce-one'),'attendance-insights'=>__('Attendance Insights','workforce-one'),'vacation'=>__('Leave','workforce-one'),'overtime'=>__('Overtime','workforce-one'),'tasks'=>__('Tasks','workforce-one'),'notifications'=>__('Notifications','workforce-one'),'profile'=>__('My Profile','workforce-one'),'people'=>__('People','workforce-one'),'employee'=>__('Employee Profile','workforce-one'),'presence'=>__('Presence Verification','workforce-one'),'polls'=>'Polls'];
        // A colleague's profile belongs to People in the menu.
        return $this->layout($titles[$view]??ucwords(str_replace('-',' ',$view)),$content,$view==='employee'?'people':$view);
    }

    /** Menu items from View Navigation, limited to the views this user may open. */
    private function app_navigation(){
        $cfg=$this->option('ews_frontend_navigation');
        if(!is_array($cfg))$cfg=[];
        // Labels still equal to the built-in English defaults are translated; custom labels are shown as entered.
        $i18n=['Dashboard'=>__('Dashboard','workforce-one'),'Schedule'=>__('Schedule','workforce-one'),'Sign In / Out'=>__('Sign In / Out','workforce-one'),'Leave'=>__('Leave','workforce-one'),'Overtime'=>__('Overtime','workforce-one'),'Tasks'=>__('Tasks','workforce-one'),'Attendance'=>__('Attendance','workforce-one'),'Reports'=>__('Reports','workforce-one'),'Attendance Insights'=>__('Attendance Insights','workforce-one'),'People'=>__('People','workforce-one')];
        $items=[];
        foreach(\WorkforceOne\Settings\Navigation::DEFAULTS as $key=>$def){
            if(!$this->app_view_allowed($key))continue;
            if($key==='overtime' && !$this->overtime_enabled())continue;
            if($key==='tasks' && !$this->tasks_enabled())continue;
            $item=array_merge($def,(isset($cfg[$key])&&is_array($cfg[$key]))?$cfg[$key]:[]);
            $desktop=sanitize_text_field((string)($item['label']??''))?:$def['label'];
            $mobile=sanitize_text_field((string)($item['mobile_label']??''))?:$def['mobile_label'];
            $items[]=['key'=>$key,'icon'=>$def['icon'],'url'=>$this->app_view_url($key),
                'desktop_label'=>$i18n[$desktop]??$desktop,'mobile_label'=>$i18n[$mobile]??$mobile,
                'desktop_visible'=>!empty($item['desktop_visible']),'mobile_visible'=>!empty($item['mobile_visible']),
                'desktop_order'=>(int)($item['desktop_order']??$def['desktop_order']),'mobile_order'=>(int)($item['mobile_order']??$def['mobile_order'])];
        }
        $desktop=array_values(array_filter($items,function($x){return $x['desktop_visible'];}));
        $mobile=array_values(array_filter($items,function($x){return $x['mobile_visible'];}));
        usort($desktop,function($a,$b){return $a['desktop_order']<=>$b['desktop_order'];});
        usort($mobile,function($a,$b){return $a['mobile_order']<=>$b['mobile_order'];});
        return [$desktop,$mobile];
    }

    /** Log out back to this page, which then confirms it ("You have been logged out"). */
    private function app_logout_url(){
        return wp_logout_url(add_query_arg('loggedout','1',get_permalink()?:home_url('/')));
    }

    private function layout($title,$body,$active='dashboard'){
        [$desktop,$mobile]=$this->app_navigation();
        $emp=$this->current_employee();
        $picture='';
        if($emp && ($emp->profile_image_type??'')==='photo' && !empty($emp->profile_image_url))$picture=(string)$emp->profile_image_url;
        elseif($emp && ($emp->profile_image_type??'')==='avatar' && !empty($emp->avatar_key))$picture=$this->profile_avatar_url($emp->avatar_key);
        return $this->render_template('app/layout',[
            'title'=>$title,'body'=>$body,'active'=>$active,'desktop_items'=>$desktop,'mobile_items'=>$mobile,
            'notice_html'=>$this->ux_notice_from_query(),'bell_html'=>$this->notification_bell(),
            'picture'=>$picture,'initials'=>($emp?ProfileSummary::initials((string)$emp->name):'')?:'ME',
            'home_url'=>$this->app_view_url('dashboard'),'profile_url'=>$this->app_view_url('profile'),'logout_url'=>$this->app_logout_url(),
        ]);
    }

    public function login_page(){
        if(is_user_logged_in()){
            $url=remove_query_arg(['login','loggedout','login_error'])?:home_url('/');
            return '<div class="ews-login-wrap"><div class="ews-login-card"><div class="ews-login-brand">Workforce One</div><h2>You are already signed in</h2><p><a class="ews-login-btn" href="'.esc_url($url).'">Open Workforce One</a> <a class="ews-login-link" href="'.esc_url(wp_logout_url($url)).'">Log out</a></p></div></div>';
        }
        $redirect=esc_url_raw(remove_query_arg(['login_error','loggedout'],wp_unslash($_GET['redirect_to']??(get_permalink()?:home_url('/')))));
        wp_enqueue_style('workforce-one'); // already enqueued on the app page; needed for [employee_login] elsewhere
        // Mark this form so a failed login comes back here (app_login_failed) instead of wp-login.php.
        $marker=function($html){return $html.'<input type="hidden" name="ews_app_login" value="1">';};
        add_filter('login_form_bottom',$marker);
        $form=wp_login_form([
            'echo'=>false,'redirect'=>$redirect,'remember'=>true,
            'label_username'=>'Username or Email','label_password'=>'Password','label_remember'=>'Remember me','label_log_in'=>'Sign In',
            'id_username'=>'ews_login_username','id_password'=>'ews_login_password','id_remember'=>'ews_login_remember','id_submit'=>'ews_login_submit',
            'value_username'=>'','value_remember'=>false,
        ]);
        remove_filter('login_form_bottom',$marker);
        return $this->render_template('app/login',[
            'form'=>$form,'lost_password_url'=>wp_lostpassword_url($redirect),
            'error'=>isset($_GET['login_error'])?'The username or password is incorrect. Please try again.':'',
            'message'=>isset($_GET['loggedout'])?'You have been logged out successfully.':'',
        ]);
    }

    /** wp_login_failed: a failed login from the app's form goes back to the app with an error. */
    public function app_login_failed($username=''){
        if(empty($_POST['ews_app_login']))return;
        $back=wp_validate_redirect(esc_url_raw(wp_unslash($_POST['redirect_to']??'')),home_url('/'));
        wp_safe_redirect(add_query_arg('login_error','1',remove_query_arg(['login_error','loggedout'],$back)));
        exit;
    }

    public function after_logout(){
        // WordPress ends the session; the app page shows the login screen on the next request.
    }
}
