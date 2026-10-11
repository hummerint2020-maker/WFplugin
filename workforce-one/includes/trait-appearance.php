<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\Appearance;

/**
 * How the employee app looks: the wp-admin Appearance page (brand, theme colours, header style,
 * font, corners) and the CSS it produces for the app. Rules: src/Settings/Appearance.php;
 * view: templates/admin/appearance.php; behaviour: tests/e2e_appearance.py.
 */
trait EWS_Appearance_Trait {

    /** The saved look, merged over the defaults. */
    private function appearance(){
        return Appearance::config($this->option('ews_appearance'));
    }

    /**
     * template_include: on the app's page (a page holding one of the app shortcodes) show only the app,
     * without the site theme's header, footer and page padding, when Appearance → Full-screen app page
     * is on. templates/app/page-fullscreen.php still runs wp_head() / wp_footer(), so the theme's and
     * other plugins' head and footer hooks (styles, the PWA tags, analytics) keep working.
     */
    /**
     * show_admin_bar: employees do not get the WordPress toolbar on the app page. It covered the app's
     * own header and cost two stylesheets (about 80 KB) and a few queries on every page. Site
     * administrators keep it.
     */
    public function app_admin_bar($show){
        if(!$show||is_admin()||current_user_can('manage_options'))return $show;
        return $this->pwa_is_employee_app_page()?false:$show;
    }

    public function app_fullscreen_template($template){
        if(is_admin()||!is_singular()||($this->appearance()['fullscreen']??'1')!=='1')return $template;
        $post=get_queried_object();
        if(!$post instanceof \WP_Post||post_password_required($post))return $template;
        foreach(['employee_app','employee_login','employee_schedule','employee_attendance','employee_reports'] as $code){
            if(has_shortcode((string)$post->post_content,$code)){
                $file=dirname(__DIR__).'/templates/app/page-fullscreen.php';
                return is_file($file)?$file:$template;
            }
        }
        return $template;
    }

    /** The app's theme: the chosen font's @font-face rules and the colour / shape tokens. */
    private function appearance_css(){
        $cfg=$this->appearance();
        $font=$cfg['font'];
        $faces=Appearance::fontFaces($font,function($file)use($font){return $this->plugin_url('assets/fonts/'.$font.'/'.$file);});
        return $faces.Appearance::cssVars($cfg,'.ews-app.wfo-app');
    }

    public function admin_appearance(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $stored=get_option('ews_appearance',null);
        $errors=get_transient('ews_appearance_errors_'.get_current_user_id());
        if($errors)delete_transient('ews_appearance_errors_'.get_current_user_id());
        $notices=['saved'=>'Appearance saved. The app uses it from the next page load.','reset'=>'Appearance restored to the built-in look.'];
        echo $this->render_template('admin/appearance',[
            'cfg'=>$this->appearance(),'presets'=>Appearance::PRESETS,'fonts'=>Appearance::FONTS,'corners'=>Appearance::CORNERS,
            'header_styles'=>Appearance::HEADER_STYLES,'is_default'=>$stored===null,
            'notice'=>$notices[sanitize_key($_GET['appearance_notice']??'')]??null,'errors'=>is_array($errors)?$errors:[],
            'post_url'=>admin_url('admin-post.php'),'preview_css'=>Appearance::cssVars($this->appearance(),'.wfo-appearance-preview'),
            'font_css'=>implode('',array_map(function($key){return Appearance::fontFaces($key,function($file)use($key){return $this->plugin_url('assets/fonts/'.$key.'/'.$file);});},array_keys(Appearance::FONTS))),
            'min_contrast'=>Appearance::MIN_CONTRAST,
        ]);
    }

    public function appearance_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews31_appearance_save');
        $post=wp_unslash($_POST);
        $in=[];
        foreach(['preset','preset_applied','header_style','font','corners'] as $f)$in[$f]=sanitize_key((string)($post[$f]??''));
        foreach(Appearance::COLOR_FIELDS as $f)$in[$f]=sanitize_text_field((string)($post[$f]??''));
        foreach(['company_name','app_name','tagline'] as $f)$in[$f]=sanitize_text_field((string)($post[$f]??''));
        $in['logo_url']=esc_url_raw(trim((string)($post['logo_url']??'')),['http','https']);
        if(isset($post['fullscreen']))$in['fullscreen']=(string)$post['fullscreen']==='1'?'1':'0';
        if(isset($post['nozoom']))$in['nozoom']=(string)$post['nozoom']==='1'?'1':'0';
        $in['login_bg']=sanitize_key((string)($post['login_bg']??''));
        $in['login_bg_color']=sanitize_text_field((string)($post['login_bg_color']??''));
        $in['login_bg_image']=esc_url_raw(trim((string)($post['login_bg_image']??'')),['http','https']);
        foreach(['login_title','login_subtitle','login_help'] as $f)$in[$f]=sanitize_text_field((string)($post[$f]??''));
        foreach(['login_remember','login_forgot','login_brand','login_eye'] as $f)if(isset($post[$f]))$in[$f]=(string)$post[$f]==='1'?'1':'0';
        $result=Appearance::fromPost($in,$this->option('ews_appearance'));
        update_option('ews_appearance',$result['config'],false);
        if($result['errors'])set_transient('ews_appearance_errors_'.get_current_user_id(),$result['errors'],10*MINUTE_IN_SECONDS);
        $this->audit('appearance_update','settings',0,'Appearance updated (theme '.$result['config']['preset'].', font '.$result['config']['font'].')');
        wp_safe_redirect(add_query_arg(['appearance_notice'=>'saved'],admin_url('admin.php?page=ews31-appearance')));
        exit;
    }

    public function appearance_reset(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews31_appearance_reset');
        delete_option('ews_appearance');
        $this->audit('appearance_update','settings',0,'Appearance restored to defaults');
        wp_safe_redirect(add_query_arg(['appearance_notice'=>'reset'],admin_url('admin.php?page=ews31-appearance')));
        exit;
    }
}
