<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Messages\Hooks as MessageHooks;
use WorkforceOne\Messages\MessageFilter;
use WorkforceOne\Messages\MessageRules;

/**
 * System messages (3.31.165, docs/tasks/system-messages.md; mockup /mnt/project-files/system-messages/mockup.html):
 * an administrator changes the words of any text the plugin shows (notifications, notices, errors, buttons), in Arabic
 * and in English, from wp-admin → Settings → System messages. One switch in Features, off by default; off, nothing is
 * registered, the page is hidden and every text is the shipped one (saved edits are kept for when it is turned on again).
 * The rules, the catalog and saving are src/Messages; this trait is their controller.
 */
trait EWS_System_Messages_Trait {

    public function system_messages_enabled(){ return (bool)$this->option('ews_feature_system_messages'); }

    /** From the constructor: shows the edited texts. Does nothing while off or while nothing is edited. */
    public function system_messages_boot(){
        if(!$this->system_messages_enabled() || !$this->services()->messageStore()->cached())return;
        $filter=new MessageFilter(
            'get_current_blog_id',
            // Each site's own switch and edits (read while that site is the current one).
            function($site){ return $this->system_messages_enabled()?($this->services()->messageStore()->cached()?:null):null; },
            function(){ return (string)determine_locale(); },
            function(){ return $this->services()->messageFiles()->scriptKeys(); }
        );
        MessageHooks::filters($filter);
    }

    /** The language the page edits first: the one the admin reads the site in. */
    private function system_messages_lang($asked){
        return in_array($asked,['ar','en'],true)?$asked:MessageRules::bucket((string)determine_locale());
    }

    /** Module names for the filter (docs/architecture/modules.json names). */
    private function system_messages_modules(){
        return ['leave'=>__('Leave','workforce-one'),'attendance'=>__('Attendance','workforce-one'),'schedule'=>__('Schedule & swaps','workforce-one'),
            'overtime'=>__('Overtime & early leave','workforce-one'),'approvals'=>__('Requests & approvals','workforce-one'),'notifications'=>__('Notifications','workforce-one'),
            'tasks'=>__('Tasks','workforce-one'),'payroll'=>__('Payroll & advances','workforce-one'),'hr_letters'=>__('HR letters','workforce-one'),
            'daily_workers'=>__('Daily workers','workforce-one'),'engagement'=>__('Engagement','workforce-one'),'reports'=>__('Reports','workforce-one'),
            'people'=>__('People','workforce-one'),'locations'=>__('Locations & presence','workforce-one'),'health'=>__('System health','workforce-one'),
            'platform'=>__('Settings','workforce-one'),'shell'=>__('Navigation & dashboard','workforce-one'),'app'=>__('App & sign in','workforce-one'),
            'messages'=>__('System messages','workforce-one'),'general'=>__('General','workforce-one')];
    }

    private function system_messages_error($code){
        $m=['empty'=>__('Write the text, or use "Back to default".','workforce-one'),
            'placeholders'=>__('Keep every value of the original (the coloured chips), each at least once, and add no other.','workforce-one'),
            'percent'=>__('A single % sign cannot be used in this text.','workforce-one'),
            'html'=>__('Keep the formatting of the original (such as bold) and add no other < or > signs.','workforce-one'),
            'chars'=>__('A backslash (\\) cannot be used in this text.','workforce-one'),
            'entity'=>__('An & joined to a word or to # cannot be used in this text. Put a space after it.','workforce-one'),
            'too_long'=>__('The text is too long.','workforce-one'),
            'stale'=>__('Someone changed this text while you were editing it. Here it is as it is now.','workforce-one'),
            'plural'=>__('Texts with a number in them (one day, 2 days…) cannot be edited yet.','workforce-one'),
            'unknown'=>__('This text is no longer in the plugin.','workforce-one')];
        return $m[$code]??__('Could not save. Nothing was changed.','workforce-one');
    }

    /** The page's address with its filters (lang, kind, module, q, edited, paged). @param array<string,mixed> $args */
    private function system_messages_url(array $args){
        $args=array_filter($args,function($v){ return $v!=='' && $v!==null && $v!==0 && $v!==false; });
        return add_query_arg(array_map('rawurlencode',array_map('strval',$args)),admin_url('admin.php?page=ews31-system-messages'));
    }

    /** wp-admin → Settings → System messages. */
    public function admin_system_messages(){
        if(!$this->can('ews_manage_messages'))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        if(!$this->system_messages_enabled())wp_die(esc_html__('System messages is off. Turn it on in Features.','workforce-one'),'',['response'=>403]);
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters and messages after a redirect
        $lang=$this->system_messages_lang(sanitize_key($_GET['lang']??''));
        $kind=sanitize_key($_GET['kind']??'');
        if(!in_array($kind,MessageRules::KINDS,true) || $kind==='notification_title')$kind='';
        $module=sanitize_key($_GET['module']??'');
        $q=sanitize_text_field(wp_unslash($_GET['q']??''));
        $edited=!empty($_GET['edited']);
        $page=max(1,absint($_GET['paged']??1));
        $edit_hash=preg_replace('/[^a-f0-9]/','',(string)($_GET['edit']??''));
        $done=sanitize_key($_GET['sm_done']??'');
        $error=sanitize_key($_GET['sm_error']??'');
        // What was typed, shown again only inside the textarea (esc_textarea): kept as typed, so %1$d or a tag stay visible to fix.
        $draft=isset($_GET['sm_draft'])?substr((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',wp_check_invalid_utf8((string)wp_unslash($_GET['sm_draft']))),0,2000):null;
        $count=absint($_GET['sm_count']??0);
        // phpcs:enable
        $catalog=$this->services()->messageCatalog();
        $all=$this->services()->messages()->all();
        $edits=$all[$lang]??[];
        $filters=['lang'=>$lang,'kind'=>$kind,'module'=>$module,'q'=>$q,'edited'=>$edited?1:0];
        $list=$catalog->listing(['lang'=>$lang,'kind'=>$kind,'module'=>$module,'q'=>$q,'edited'=>$edited],$edits,$page);
        $editing=null;
        if($edit_hash!==''){
            $key=$catalog->byHash($edit_hash);
            if($key!==null)$editing=$catalog->row($lang,$key,$edits);
        }
        $modules=array_intersect_key($this->system_messages_modules(),array_flip($catalog->modules()));
        $edited_count=0;
        foreach($all as $l=>$e)foreach($e as $k=>$_)if($catalog->has((string)$k))$edited_count++;
        $notice='';
        if($done==='saved')$notice=__('Saved. New notifications and pages use the new text.','workforce-one');
        elseif($done==='reset')$notice=__('Back to the default text.','workforce-one');
        elseif($done==='reset_many')/* translators: %d: number of texts */$notice=sprintf(__('%d text(s) went back to the default.','workforce-one'),$count);
        elseif($done==='none')$notice=__('Nothing was edited there, so nothing changed.','workforce-one');
        wp_enqueue_script('ews-system-messages',plugins_url('assets/js/admin-system-messages.js',dirname(__DIR__).'/employee-schedule-manager.php'),[],EWS_VERSION,true);
        echo $this->render_template('admin/system-messages',[
            'lang'=>$lang,'filters'=>$filters,'list'=>$list,'editing'=>$editing,'draft'=>$draft,'modules'=>$modules,
            'review'=>$catalog->needsReview($all),'total'=>$catalog->count(),'edited_count'=>$edited_count,
            'notice'=>$notice,'error'=>$error!==''?$this->system_messages_error($error):'',
            'locale_warning'=>$lang==='ar' && (string)determine_locale()!=='ar' && strpos((string)determine_locale(),'ar')===0,
            'post_url'=>admin_url('admin-post.php'),'base_url'=>admin_url('admin.php'),
            'url'=>function(array $args)use($filters){ return $this->system_messages_url($args+$filters); },
            'links'=>['offline'=>admin_url('admin.php?page=ews31-features#ews-offline-screen'),'login'=>admin_url('admin.php?page=ews31-appearance'),'occasions'=>admin_url('admin.php?page=ews31-features#ews-occasions')],
        ]);
    }

    /** The page's filters as posted back with a form (to return to the same list). @return array<string,mixed> */
    private function system_messages_posted_filters(){
        $kind=sanitize_key($_POST['f_kind']??'');   // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller
        return ['lang'=>$this->system_messages_lang(sanitize_key($_POST['lang']??'')),'kind'=>in_array($kind,MessageRules::KINDS,true)?$kind:'',   // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'module'=>sanitize_key($_POST['f_module']??''),'q'=>sanitize_text_field(wp_unslash($_POST['f_q']??'')),'edited'=>empty($_POST['f_edited'])?0:1,'paged'=>absint($_POST['f_paged']??0)];   // phpcs:ignore WordPress.Security.NonceVerification.Missing
    }

    private function system_messages_guard($nonce){
        if(!$this->can('ews_manage_messages'))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        check_admin_referer($nonce);
        if(!$this->system_messages_enabled())wp_die(esc_html__('System messages is off. Turn it on in Features.','workforce-one'),'',['response'=>403]);
    }

    /** Saves one text (the edit panel's form). */
    public function system_messages_save(){
        $this->system_messages_guard('ews_messages_save');
        $back=$this->system_messages_posted_filters();
        $hash=preg_replace('/[^a-f0-9]/','',(string)($_POST['hash']??''));
        $catalog=$this->services()->messageCatalog();
        $key=$catalog->byHash($hash);
        if($key===null){wp_safe_redirect($this->system_messages_url($back+['sm_error'=>'unknown']));exit;}
        // The text the form showed, to refuse a save over someone else's: kept exactly (it may hold tags), compared only.
        $expected=str_replace("\r\n","\n",(string)wp_unslash($_POST['expected']??''));
        if(!empty($_POST['reset'])){
            $n=$this->services()->messages()->reset($back['lang'],'one',$key,$catalog);
            wp_safe_redirect($this->system_messages_url($back+['sm_done'=>$n?'reset':'none']));exit;
        }
        // Taken as typed: MessageRules::check() refuses any tag the original does not have, any < or >, and makes quotes typographic
        // (sanitize_textarea_field() would drop the original's own tags and %-sequences such as %1$s… in some texts).
        $text=(string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',wp_check_invalid_utf8(str_replace("\r\n","\n",(string)wp_unslash($_POST['text']??''))));
        $r=$this->services()->messages()->save($back['lang'],$key,$text,$expected,$catalog);
        if($r['code']==='' || $r['code']==='reset'){
            wp_safe_redirect($this->system_messages_url($back+['sm_done'=>$r['code']===''?'saved':'reset']));exit;
        }
        // Refused: back to the panel with what was typed, and why.
        wp_safe_redirect($this->system_messages_url($back+['edit'=>$hash,'sm_error'=>$r['code'],'sm_draft'=>$r['code']==='stale'?null:$text]));exit;
    }

    /** Back to the default: a module's texts, every text of the language, or the saved texts that no longer apply. */
    public function system_messages_reset(){
        $this->system_messages_guard('ews_messages_reset');
        $back=$this->system_messages_posted_filters();
        $scope=sanitize_key($_POST['scope']??'');
        if(!in_array($scope,['module','all','review','one_review'],true)){wp_safe_redirect($this->system_messages_url($back));exit;}
        $catalog=$this->services()->messageCatalog();
        if($scope==='one_review'){
            // One saved text that no longer applies (its language is the one it was saved in).
            $hash=preg_replace('/[^a-f0-9]/','',(string)($_POST['hash']??''));
            $lang=$this->system_messages_lang(sanitize_key($_POST['review_lang']??''));
            $n=0;
            foreach($catalog->needsReview($this->services()->messages()->all()) as $r){
                if($r['hash']===$hash && $r['lang']===$lang)$n=$this->services()->messages()->reset($lang,'one',$r['key'],$catalog);
            }
        }elseif($scope==='review'){
            $n=0;
            foreach(['ar','en'] as $l)$n+=$this->services()->messages()->reset($l,'review','',$catalog);
        }else{
            $n=$this->services()->messages()->reset($back['lang'],$scope,$scope==='module'?$back['module']:'',$catalog);
        }
        wp_safe_redirect($this->system_messages_url($back+['sm_done'=>$n?'reset_many':'none','sm_count'=>$n]));exit;
    }
}
