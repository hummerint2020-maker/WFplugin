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

    /** @var array{0:string,1:string}|null A view's greeting for the header (Dashboard): [greeting, date line]. */
    private $app_header_greeting=null;

    public function app(){
        // A daily worker's own page (mobile + PIN, no WordPress account): open without logging in.
        if(sanitize_key($_GET['ews_view']??'')==='worker')return $this->dw_worker_page();
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
            case 'corrections': return $this->corrections_enabled();
            case 'sites': case 'payout': return $this->dw_view_allowed($view);
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
            case 'corrections': $content=$this->corrections_content(); break;
            case 'sites': $content=$this->dw_sites_content(); break;
            case 'payout': $content=$this->dw_payout_content(); break;
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
        $titles=['pay'=>__('My Pay','workforce-one'),'dashboard'=>__('Dashboard','workforce-one'),'schedule'=>__('Schedule','workforce-one'),'time'=>__('Time','workforce-one'),'attendance'=>__('Attendance','workforce-one'),'employees'=>__('Employees','workforce-one'),'reports'=>__('Reports','workforce-one'),'attendance-insights'=>__('Attendance Insights','workforce-one'),'vacation'=>__('Leave','workforce-one'),'overtime'=>__('Overtime','workforce-one'),'corrections'=>__('Corrections','workforce-one'),'sites'=>__('My sites','workforce-one'),'payout'=>__('Payout','workforce-one'),'tasks'=>__('Tasks','workforce-one'),'notifications'=>__('Notifications','workforce-one'),'profile'=>__('My Profile','workforce-one'),'people'=>__('People','workforce-one'),'employee'=>__('Employee Profile','workforce-one'),'presence'=>__('Presence Verification','workforce-one'),'polls'=>__('Polls','workforce-one')];
        // A colleague's profile belongs to People in the menu.
        return $this->layout($titles[$view]??ucwords(str_replace('-',' ',$view)),$content,$view==='employee'?'people':$view);
    }

    /** Menu items from View Navigation, limited to the views this user may open. */
    private function app_navigation(){
        $cfg=$this->option('ews_frontend_navigation');
        if(!is_array($cfg))$cfg=[];
        // Labels still equal to the built-in English defaults are translated; custom labels are shown as entered.
        $i18n=['Dashboard'=>__('Dashboard','workforce-one'),'Schedule'=>__('Schedule','workforce-one'),'Sign In / Out'=>__('Sign In / Out','workforce-one'),'Leave'=>__('Leave','workforce-one'),'Overtime'=>__('Overtime','workforce-one'),'Corrections'=>__('Corrections','workforce-one'),'My sites'=>__('My sites','workforce-one'),'Payout'=>__('Payout','workforce-one'),'Tasks'=>__('Tasks','workforce-one'),'Attendance'=>__('Attendance','workforce-one'),'Reports'=>__('Reports','workforce-one'),'Attendance Insights'=>__('Attendance Insights','workforce-one'),'People'=>__('People','workforce-one'),'Polls'=>__('Polls','workforce-one'),'My Pay'=>__('My Pay','workforce-one')];
        $items=[];
        foreach(\WorkforceOne\Settings\Navigation::DEFAULTS as $key=>$def){
            if(!$this->app_view_allowed($key))continue;
            if($key==='overtime' && !$this->overtime_enabled())continue;
            if($key==='tasks' && !$this->tasks_enabled())continue;
            if($key==='corrections' && !$this->corrections_enabled())continue;
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

    /** The employee's photo or chosen avatar ('' = show initials). @param object|null $emp */
    private function employee_picture_url($emp){
        if($emp && ($emp->profile_image_type??'')==='photo' && !empty($emp->profile_image_url))return (string)$emp->profile_image_url;
        if($emp && ($emp->profile_image_type??'')==='avatar' && !empty($emp->avatar_key))return (string)$this->profile_avatar_url($emp->avatar_key);
        return '';
    }

    /** Log out back to this page, which then confirms it ("You have been logged out"). */
    private function app_logout_url(){
        return wp_logout_url(add_query_arg('loggedout','1',get_permalink()?:home_url('/')));
    }

    private function layout($title,$body,$active='dashboard'){
        [$desktop,$mobile]=$this->app_navigation();
        $emp=$this->current_employee();
        $picture=$this->employee_picture_url($emp);
        $look=$this->appearance();
        $bar=\WorkforceOne\Ui\MobileBar::split($mobile);
        $clock=$bar['center']&&$emp?\WorkforceOne\Ui\MobileBar::clockState($this->today_events((int)$emp->id),true):'';
        return $this->render_template('app/layout',[
            'title'=>$title,'body'=>$body,'active'=>$active,'greeting'=>$this->app_header_greeting,'desktop_items'=>$desktop,'mobile_items'=>$mobile,'bar'=>$bar,'clock_state'=>$clock,
            // The built-in tagline is translated; one typed in Appearance is shown as entered.
            'company_name'=>$look['company_name'],'app_name'=>$look['app_name'],'logo_url'=>$look['logo_url'],
            'tagline'=>$look['tagline']===\WorkforceOne\Settings\Appearance::DEFAULTS['tagline']?__('One Platform. One Team. One Goal.','workforce-one'):$look['tagline'],
            'user_name'=>$emp?(string)$emp->name:wp_get_current_user()->display_name,
            'notice_html'=>$this->ux_notice_from_query(),'bell_html'=>$this->notification_bell(),
            'picture'=>$picture,'initials'=>($emp?ProfileSummary::initials((string)$emp->name):'')?:'ME',
            'home_url'=>$this->app_view_url('dashboard'),'profile_url'=>$this->app_view_url('profile'),'logout_url'=>$this->app_logout_url(),
        ]);
    }

    /** The login's stylesheet and theme (once per page), and the show-password script. */
    private function enqueue_login_assets(){
        static $done=false;
        wp_enqueue_style('workforce-one-login');
        wp_enqueue_script('workforce-one-login');
        if($done)return;
        $done=true;
        $cfg=$this->appearance();
        $font=$cfg['font'];
        $faces=\WorkforceOne\Settings\Appearance::fontFaces($font,function($file)use($font){return $this->plugin_url('assets/fonts/'.$font.'/'.$file);});
        $css=$faces.\WorkforceOne\Settings\Appearance::cssVars($cfg,'.wfo-login');
        if($cfg['login_bg']==='color')$css.='.wfo-login{--wfo-login-bg:'.$cfg['login_bg_color'].'}';
        if($cfg['login_bg']==='picture')$css.='.wfo-login{--wfo-login-bg:linear-gradient(rgba(10,16,40,.55),rgba(10,16,40,.75)),url("'.esc_url_raw($cfg['login_bg_image']).'") center/cover no-repeat '.$cfg['login_bg_color'].'}';
        wp_add_inline_style('workforce-one-login',$css);
    }

    public function login_page(){
        wp_enqueue_style('workforce-one'); // already enqueued on the app page; needed for [employee_login] elsewhere
        $this->enqueue_login_assets();
        $cfg=$this->appearance();
        $brand=['app_name'=>$cfg['app_name'],'company_name'=>$cfg['company_name'],'tagline'=>$cfg['tagline'],'logo_url'=>$cfg['logo_url'],'show'=>$cfg['login_brand']==='1'];
        if(is_user_logged_in()){
            $url=remove_query_arg(['login','loggedout','login_error'])?:home_url('/');
            return $this->render_template('app/login',['signed_in'=>true,'brand'=>$brand,'cfg'=>$cfg,'open_url'=>$url,'logout_url'=>wp_logout_url($url),'form'=>'','lost_password_url'=>'','error'=>'','message'=>'','worker_url'=>'']);
        }
        $redirect=esc_url_raw(remove_query_arg(['login_error','loggedout'],wp_unslash($_GET['redirect_to']??(get_permalink()?:home_url('/')))));
        // Mark this form so a failed login comes back here (app_login_failed) instead of wp-login.php.
        $marker=function($html){return $html.'<input type="hidden" name="ews_app_login" value="1">';};
        add_filter('login_form_bottom',$marker);
        // "Forgot password?" sits in the form, on the Remember me row (app-login.css orders them).
        $lost=$cfg['login_forgot']==='1'?wp_lostpassword_url($redirect):'';
        $forgot=function($html)use($lost){return $lost===''?$html:$html.'<p class="ews-login-forgot"><a href="'.esc_url($lost).'">'.esc_html__('Forgot password?','workforce-one').'</a></p>';};
        add_filter('login_form_middle',$forgot);
        $form=wp_login_form([
            'echo'=>false,'redirect'=>$redirect,'remember'=>$cfg['login_remember']==='1',
            'label_username'=>__('Username or Email','workforce-one'),'label_password'=>__('Password','workforce-one'),'label_remember'=>__('Remember me','workforce-one'),'label_log_in'=>_x('Sign In','log in to the app','workforce-one'),
            'id_username'=>'ews_login_username','id_password'=>'ews_login_password','id_remember'=>'ews_login_remember','id_submit'=>'ews_login_submit',
            'value_username'=>'','value_remember'=>false,
        ]);
        remove_filter('login_form_bottom',$marker);
        remove_filter('login_form_middle',$forgot);
        // Placeholders (the labels stay for screen readers) and the fields' own hints for phones.
        $form=str_replace('id="ews_login_username"','id="ews_login_username" placeholder="'.esc_attr__('Username or Email','workforce-one').'" autocomplete="username" autocapitalize="none" spellcheck="false"',$form);
        $form=str_replace('id="ews_login_password"','id="ews_login_password" placeholder="'.esc_attr__('Password','workforce-one').'" autocomplete="current-password"',$form);
        return $this->render_template('app/login',[
            'signed_in'=>false,'brand'=>$brand,'cfg'=>$cfg,'open_url'=>'','logout_url'=>'',
            'form'=>$form,'lost_password_url'=>$lost,'worker_url'=>$this->dw_self_anywhere()?$this->dw_worker_url():'',
            'error'=>isset($_GET['login_error'])?__('The username or password is incorrect. Please try again.','workforce-one'):'',
            'message'=>isset($_GET['loggedout'])?__('You have been logged out successfully.','workforce-one'):'',
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
