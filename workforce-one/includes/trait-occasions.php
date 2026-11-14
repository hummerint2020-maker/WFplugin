<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\Occasions;
use WorkforceOne\Settings\OccasionArt;

/**
 * Occasions (3.31.145, docs/tasks/occasions.md; mockups docs/mockups/occasions/): decorations on the employee app, the login page and
 * kiosks for the company occasion the administrator switched on, each employee's own birthday and the picked people's birthdays, a
 * "moment" once a day, and the words the administrator wrote. Off by default; while off, occasion_now() returns null before reading
 * anything else, so nothing is printed, enqueued or sent. The rules are in src/Settings/Occasions.php, the art in OccasionArt.php.
 */
trait EWS_Occasions_Trait {

    public function occasions_enabled(){
        return (bool)(int)$this->option('ews_feature_occasions');
    }

    /** @return array<string,mixed> Occasions::config() */
    private function occasion_settings(){
        return Occasions::config($this->option('ews_occasions'));
    }

    /**
     * What shows here for the viewer (where: app | login | kiosk), or null. Memoised per request.
     * @return array{key:string,instance:string,mode:string,name:string,moment:string}|null
     */
    public function occasion_now($where='app'){
        static $memo=[];
        if(array_key_exists($where,$memo))return $memo[$where];
        $preview='';
        // An administrator's preview (Features → Occasions → Preview): works while off and saves nothing.
        if($where==='app'&&isset($_GET['wfo_occasion_preview'])&&is_user_logged_in()&&$this->can('ews_manage_settings'))$preview=sanitize_key(wp_unslash($_GET['wfo_occasion_preview']));
        if(!in_array($preview,Occasions::TEXT_KEYS,true))$preview='';   // an unknown key is no preview
        if($preview===''&&!$this->occasions_enabled())return $memo[$where]=null;
        $c=$this->occasion_settings();
        $today=current_time('Y-m-d');
        $ctx=['where'=>$where,'preview'=>$preview];
        $uid=(int)get_current_user_id();
        if($where==='app'&&($c['birthdays']||$c['people'])&&$preview===''){
            $emp=$this->current_employee();
            $saved=$this->option('ews_employee_moments');
            $saved=is_array($saved)?$saved:[];
            $ctx['self_id']=$emp?(int)$emp->id:0;
            $ctx['birthday']=$emp?(string)($saved[(int)$emp->id]['birthday']??''):'';
            // The picked people: their names are read only when one of them has a birthday today.
            $due=[];
            foreach($c['people'] as $pid)if(Occasions::isBirthday((string)($saved[$pid]['birthday']??''),$today))$due[]=(int)$pid;
            if($due){
                foreach((array)$this->people_by_ids($due,true) as $r)
                    $ctx['picked'][]=['id'=>(int)$r->id,'name'=>(string)$r->name,'birthday'=>(string)($saved[(int)$r->id]['birthday']??'')];
            }
        }
        // What the viewer hid (the app only: a kiosk or the login page opened by someone signed in is never affected).
        if($where==='app'&&$uid&&$preview===''){$ctx['hidden']=(string)get_user_meta($uid,$this->site_user_key('ews_occasion_hidden'),true);$ctx['hidden_birthday']=(string)get_user_meta($uid,$this->site_user_key('ews_occasion_hidden_birthday'),true);}
        return $memo[$where]=Occasions::resolve($c,$this->occasions_enabled(),$today,$ctx);
    }

    /** The stylesheet and the one occasion's palette (only when something shows). */
    private function occasion_assets($o){
        static $done=false;
        if($done)return;
        $done=true;
        $root=plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php');
        wp_register_style('workforce-one-occasions',$root.'assets/css/occasions.css',[],defined('EWS_VERSION')?EWS_VERSION:'1');
        wp_style_add_data('workforce-one-occasions','rtl','replace');
        wp_enqueue_style('workforce-one-occasions');
        $vars=OccasionArt::VARS[$o['key']]??'';
        if($vars!=='')wp_add_inline_style('workforce-one-occasions','.wfo-occ-'.$o['key'].'{'.$vars.'}');
    }

    private function occasion_lang(){
        return strpos(determine_locale(),'ar')===0?'ar':'en';
    }

    /**
     * The words of an occasion for the viewer's language: the administrator's, else the built-in ones.
     * @return array{card_title:string,card_line:string,moment_title:string,moment_line:string}
     */
    private function occasion_words($o){
        $c=$this->occasion_settings();
        $app=(string)$this->appearance()['app_name'];
        $name=$o['name']!==''?$o['name']:'';
        if($name===''&&$o['key']==='birthday'){$emp=$this->current_employee();$name=$emp?(string)$emp->name:wp_get_current_user()->display_name;}
        $first=trim((string)strtok($name,' '));
        $years=Occasions::years($c['founded'],current_time('Y-m-d'));
        /* translators: %s: the app's name (Workforce One) */
        $team=sprintf(__('From the %s team','workforce-one'),$app);
        $d=[
            'valentine'=>[__('Happy Valentine\'s Day','workforce-one'),$team,'HAPPY VALENTINE\'S DAY',$team],
            'ramadan'=>[__('Ramadan Kareem','workforce-one'),$team,'رمضان كريم',$team],
            'eid_fitr'=>[__('Eid Mubarak','workforce-one'),$team,'عيد فطر مبارك',$team],
            'eid_adha'=>[__('Eid al-Adha Mubarak','workforce-one'),$team,'عيد أضحى مبارك',$team],
            'new_year'=>[__('Happy New Year','workforce-one'),$team,'HAPPY NEW YEAR',$team],
            'halloween'=>[__('Happy Halloween','workforce-one'),$team,'HAPPY HALLOWEEN',$team],
            'national'=>[__('Happy National Day','workforce-one'),$team,__('Happy National Day','workforce-one'),$team],
            /* translators: %d: years since the company was founded */
            'company_birthday'=>[$years?sprintf(__('%d years together','workforce-one'),$years):__('Happy birthday to us','workforce-one'),$app,'',''],
            /* translators: %s: the employee's first name */
            'birthday'=>[$first!==''?sprintf(__('Happy birthday, %s!','workforce-one'),$first):__('Happy birthday!','workforce-one'),$o['name']!==''?__('Wish them a great day','workforce-one'):__('Have a great day, from all the team','workforce-one'),'HAPPY BIRTHDAY',
                /* translators: %s: the employee's first name */
                $first!==''?sprintf(__('Happy birthday, %s!','workforce-one'),$first):__('Happy birthday!','workforce-one')],
        ][$o['key']]??['','','',''];
        $lang=$this->occasion_lang();
        $out=[];
        foreach(['card_title','card_line','moment_title','moment_line'] as $i=>$f){
            $mine=Occasions::text($c,$o['key'],$f,$lang);
            $out[$f]=Occasions::fill($mine!==''?$mine:$d[$i],$first,$years);
        }
        return $out;
    }

    private function occasion_layer($mode){
        return '<div class="wfo-occ is-'.esc_attr($mode).'" aria-hidden="true">'.str_repeat('<i></i>',14).'</div>';
    }

    /** The greeting card at the top of the Dashboard. */
    private function occasion_card($o,$with_hide){
        $w=$this->occasion_words($o);
        $hide=$with_hide?'<a class="wfo-occ-greet-hide" href="'.esc_url($this->occasion_hide_url()).'">'.esc_html__('Hide decorations','workforce-one').'</a>':'';
        return '<div class="wfo-occ-greet" role="status">'.$hide.'<div class="wfo-occ-greet-text"><b>'.esc_html($w['card_title']).'</b>'.($w['card_line']!==''?'<small>'.esc_html($w['card_line']).'</small>':'').'</div><span class="wfo-occ-greet-art" aria-hidden="true"></span></div>';
    }

    /** The moment (a full-screen scene of a few seconds; never takes a tap). */
    private function occasion_moment($o){
        $art_key=$o['moment']==='cake'?'birthday':$o['key'];
        if(!isset(OccasionArt::MOMENTS[$art_key]))return '';
        [$cls,$style,$art]=OccasionArt::MOMENTS[$art_key];
        $w=$this->occasion_words($o);
        $title=$w['moment_title'];
        if(Occasions::isArabic($title)){
            $title_html='<p class="wfo-mo-ar">'.esc_html($title).'</p>';
        }else{
            $words=[];$i=0;
            foreach(preg_split('/\s+/u',trim($title))?:[] as $word){
                $chars=preg_split('//u',$word,-1,PREG_SPLIT_NO_EMPTY)?:[];
                $spans='';
                foreach($chars as $k=>$ch)$spans.='<span style="animation-delay:'.number_format(2.75+($i+$k)*.07,2,'.','').'s">'.esc_html($ch).'</span>';
                $words[]='<b class="w">'.$spans.'</b>';
                $i+=count($chars)+1;
            }
            $title_html='<p class="wfo-mo-en">'.implode('<span class="sp"></span>',$words).'</p>';
        }
        $art=str_replace('{{year}}',(string)Occasions::newYear(current_time('Y-m-d')),$art);
        return '<div class="'.esc_attr($cls).'" role="status" aria-label="'.esc_attr($title).'"'.($style!==''?' style="'.esc_attr($style).'"':'').'>'.$art.$title_html
            .($w['moment_line']!==''?'<p class="wfo-mo-sub">'.esc_html($w['moment_line']).'</p>':'').'</div>';
    }

    /** Once a day for each employee (and every time in a preview). */
    private function occasion_moment_due($o){
        if($o['moment']==='')return false;
        if(strpos($o['instance'],'preview|')===0)return true;
        $uid=(int)get_current_user_id();
        if(!$uid)return false;
        $mark=current_time('Y-m-d').'|'.$o['instance'];
        if((string)get_user_meta($uid,$this->site_user_key('ews_occasion_moment'),true)===$mark)return false;
        update_user_meta($uid,$this->site_user_key('ews_occasion_moment'),$mark);
        return true;
    }

    /**
     * The app frame's pieces (all '' when nothing shows): the class on .ews-app, the layer in the top bar, the page's layer, what
     * goes over the screen (the falling layer, the moment) and the greeting card (Dashboard only).
     * @return array{class:string,head:string,page:string,over:string,card:string,hide:string}
     */
    private function occasion_frame($active){
        $none=['class'=>'','head'=>'','page'=>'','over'=>'','card'=>'','hide'=>''];
        if(is_admin()&&!wp_doing_ajax())return $none;   // never in wp-admin
        $o=$this->occasion_now('app');
        if(!$o)return $none;
        $this->occasion_assets($o);
        $over=$o['mode']==='full'?$this->occasion_layer('full'):'';
        if($this->occasion_moment_due($o))$over.=$this->occasion_moment($o);
        return [
            'class'=>' wfo-occ-on wfo-occ-'.$o['key'],
            'head'=>$o['mode']==='bg'?$this->occasion_layer('bg'):'',
            'page'=>$o['mode']==='bg'?$this->occasion_layer('page'):'',
            'over'=>$over,
            'card'=>$active==='dashboard'?$this->occasion_card($o,strpos($o['instance'],'preview|')!==0):'',
            'hide'=>strpos($o['instance'],'preview|')!==0?$this->occasion_hide_url():'',
        ];
    }

    /** The login page's pieces (the company occasion only, never a name). @return array{class:string,layer:string,card:string} */
    private function occasion_login(){
        $o=is_admin()&&!wp_doing_ajax()?null:$this->occasion_now('login');
        if(!$o)return ['class'=>'','layer'=>'','card'=>''];
        $this->occasion_assets($o);
        return ['class'=>' wfo-occ-on wfo-occ-'.$o['key'],'layer'=>$this->occasion_layer('bg'),'card'=>$this->occasion_card($o,false)];
    }

    /**
     * The kiosk's pieces (the company occasion only, when kiosks are on; never a name): its instance is sent with each code, and the
     * kiosk reloads when it changes, so switching an occasion on or off reaches every kiosk within one code.
     * @return array{instance:string,class:string,layer:string,css_url:string,css:string}
     */
    public function occasion_kiosk(){
        $o=$this->occasion_now('kiosk');
        if(!$o)return ['instance'=>'','class'=>'','layer'=>'','css_url'=>'','css'=>''];
        return ['instance'=>$o['instance'],'class'=>'wfo-occ-on wfo-occ-'.$o['key'].' k-occ','layer'=>$this->occasion_layer('bg'),
            'css_url'=>$this->plugin_url('assets/css/occasions.css'),'css'=>'.wfo-occ-'.$o['key'].'{'.(OccasionArt::VARS[$o['key']]??'').'}'];
    }

    private function occasion_hide_url(){
        return wp_nonce_url(admin_url('admin-post.php?action=ews31_occasion_hide'),'ews_occasion_hide');
    }

    /** admin-post ews31_occasion_hide: the viewer hides the decorations until the next occasion (a birthday: until next year). */
    public function occasion_hide(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_occasion_hide');
        $o=$this->occasion_now('app');
        if($o&&strpos($o['instance'],'preview|')!==0)update_user_meta((int)get_current_user_id(),$this->site_user_key($o['key']==='birthday'?'ews_occasion_hidden_birthday':'ews_occasion_hidden'),$o['instance']);
        $back=wp_get_referer();
        if(!$back||strpos($back,'admin-post.php')!==false)$back=$this->app_home_url();
        wp_safe_redirect($back);exit;
    }

    /** Feature Configuration → Occasions: what the card shows. @return array<string,mixed> */
    private function occasion_admin_data(){
        $c=$this->occasion_settings();
        $saved=$this->option('ews_employee_moments');$saved=is_array($saved)?$saved:[];
        $people=(array)$this->people_active_list();
        $with=0;foreach($people as $p)if(\WorkforceOne\Settings\Moments::validDate((string)($saved[(int)$p->id]['birthday']??'')))$with++;
        return ['s'=>$c,'ended'=>Occasions::ended($c,current_time('Y-m-d')),'people'=>$people,'with_birthday'=>$with,'total'=>count($people),
            'moments_url'=>admin_url('admin.php?page=ews31-moments'),'preview_url'=>add_query_arg('wfo_occasion_preview','__key__',$this->app_home_url())];
    }

    /**
     * Checks the Occasions card before anything is saved (an error refuses the whole save).
     * @return array{0:array<string,mixed>,1:bool}|null [settings, on], or null when the card was not on the page
     */
    private function occasions_validate($post){
        if(!isset($post['occasions_present']))return null;
        [$c,$err]=Occasions::fromPost($post,$this->occasion_settings(),current_time('Y-m-d'),current_time('mysql'),$this->occasions_enabled());
        if($err!=='')$this->features_redirect(['features_error'=>'occ_'.$err]);
        return [$c,!empty($post['occasions_enabled'])];
    }

    /** Saves it: only what changed is written, so a site that keeps it off stores nothing new. */
    private function occasions_store($checked){
        if($checked===null)return;
        [$c,$on]=$checked;
        $before=$this->occasion_settings();$was=$this->occasions_enabled();
        if($was!==$on)update_option('ews_feature_occasions',$on?1:0,false);
        if($c!==$before)update_option('ews_occasions',$c,false);
        if($was!==$on||$c!==$before)$this->audit('occasions_update','settings',0,Occasions::summary($c,$on));
    }
}
