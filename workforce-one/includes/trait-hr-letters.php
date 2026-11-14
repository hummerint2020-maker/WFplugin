<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\HrLetters\LetterSettings;

/**
 * HR letters (HL3, docs/tasks/hr-letter-hl3.md; owner decisions in docs/tasks/hr-letter.md; mockup docs/mockups/hr-letter/). Off by default
 * (wp-admin → Features). An employee asks HR for a letter from My requests; it goes through the "HR Letter" approval workflow (HR at one
 * level by default); each type is delivered as a PDF made from its template, a file HR uploads, or the request only (ready to collect).
 * Controllers only: the rules are in src/HrLetters/, the SQL in its stores. Behaviour: tests/e2e_hr_letters.py.
 */
trait EWS_Hr_Letters_Trait {

    /** The switch only (a primed option): every HR letter path checks it first. */
    public function hr_letters_enabled(){ return (bool)$this->option('ews_feature_hr_letters'); }

    /** Employees can ask: letters on, with My approvals and My requests (their only entry point). */
    public function hr_letters_requestable(){
        return $this->hr_letters_enabled() && $this->my_approvals_enabled();
    }

    /** @return array<string,mixed> */
    public function hr_letter_settings(){ return LetterSettings::settings($this->option('ews_hr_letters')); }

    /** @return list<array<string,mixed>> */
    public function hr_letter_types(){ return LetterSettings::types($this->option('ews_hr_letter_types')); }

    /** @return array<string,string> */
    public function hr_letter_letterhead(){ return LetterSettings::letterhead($this->option('ews_hr_letter_letterhead')); }

    /** The letters table (an upgrade step; created while the feature is off too, so switching it on needs nothing more). */
    public function ensure_hr_letters_schema(){
        if($this->ews_schema_is_current())return;
        $this->services()->letterSchema();
    }

    /* ------------------------------------------------------------------ what the services reach (the ports' adapters in trait-services.php) */

    /** The first request sets the workflow to one level, HR; HR's own letter goes to the administrators (Q9); rejecting needs a reason. */
    private function hr_letter_workflow_ready(){
        $w=$this->approval_workflow('hr_letter');
        if($w && (int)$w->active)return true;
        $self=get_option('ews_approval_self_rule',[]);
        if(!is_array($self))$self=[];
        if(!isset($self['hr_letter'])){$self['hr_letter']='admins';update_option('ews_approval_self_rule',$self,false);}
        $note=get_option('ews_approval_reject_note',[]);
        if(!is_array($note))$note=[];
        if(!array_key_exists('hr_letter',$note)){$note['hr_letter']=1;update_option('ews_approval_reject_note',$note,false);}
        return $this->approval_configure_workflow('hr_letter','LEVEL_1',[1=>['resolver_type'=>'HR']],\WorkforceOne\Approvals\Workflows::IN_USE['hr_letter'][1])===true;
    }

    /** The letter's name for the person reading it (their language is the site's): the type, or "Other: …". */
    private function hr_letter_title($l){
        return (string)$l->type_key==='other'?__('Other','workforce-one').': '.(string)$l->other_text:(string)$l->type_label;
    }

    private function hr_letter_notify_decider($l){
        $ar=$this->approval_find_request('hr_letter','hr_letter',(int)$l->id);
        $step=$ar?$this->approval_current_step((int)$ar->id):null;
        if(!$step || !(int)$step->approver_wp_user_id)return;
        $name=$this->people_name((int)$l->employee_id);
        $this->notify((int)$step->approver_wp_user_id,'system',__('An HR letter request waits for you','workforce-one'),
            /* translators: 1: employee name, 2: letter type, 3: language */
            sprintf(__('%1$s asked for "%2$s" (%3$s). Open My approvals.','workforce-one'),$name,$this->hr_letter_title($l),(string)$l->language==='ar'?__('Arabic','workforce-one'):__('English','workforce-one')),
            ['entity'=>'approval','entity_id'=>(int)$ar->id,'push'=>true]);
    }

    private function hr_letter_notify_employee($event,$l,$reason=''){
        $uid=(int)$this->people_user_id((int)$l->employee_id);
        if(!$uid)return;
        $t=$this->hr_letter_title($l);
        $where=trim((string)$l->decision_note);
        $m=[
            /* translators: %s: letter type */
            'ready'=>[__('Your HR letter is ready','workforce-one'),sprintf(__('Your "%s" letter is ready. Open My requests to download it.','workforce-one'),$t)],
            /* translators: %s: letter type */
            'preparing'=>[__('Your HR letter request was approved','workforce-one'),sprintf(__('Your "%s" request was approved. HR is preparing it; you will be told when it is ready.','workforce-one'),$t)],
            /* translators: 1: letter type, 2: where to collect it */
            'collect'=>[__('Your HR letter is ready to collect','workforce-one'),$where!==''?sprintf(__('Your "%1$s" letter is ready to collect from HR: %2$s.','workforce-one'),$t,$where):sprintf(__('Your "%s" letter is ready to collect from HR.','workforce-one'),$t)],
            /* translators: 1: letter type, 2: reason */
            'rejected'=>[__('Your HR letter request was rejected','workforce-one'),$reason!==''?sprintf(__('Your "%1$s" request was rejected. Reason: %2$s','workforce-one'),$t,$reason):sprintf(__('Your "%s" request was rejected.','workforce-one'),$t)],
            /* translators: 1: letter type, 2: reason */
            'cancelled'=>[__('HR cancelled your letter','workforce-one'),sprintf(__('HR cancelled your "%1$s" letter. Reason: %2$s','workforce-one'),$t,$reason)],
            /* translators: %s: letter type */
            'updated'=>[__('Your HR letter was updated','workforce-one'),sprintf(__('HR updated your "%s" letter. Download the new copy from My requests.','workforce-one'),$t)],
        ];
        if(!isset($m[$event]))return;
        $this->notify($uid,'system',$m[$event][0],$m[$event][1],['url'=>add_query_arg('ews_view','requests',$this->app_home_url()),'push'=>true]);
    }

    /** HR: the HR user of Approval Workflows, else whoever has Manage HR letters. @return int[] */
    private function hr_letter_hr_users(){
        $uid=(int)($this->approval_settings()['hr_user']??0);
        if($uid)return [$uid];
        return array_map(function($u){return (int)$u->ID;},(array)get_users(['capability'=>'ews_manage_hr_letters','fields'=>['ID']]));
    }

    private function hr_letter_notify_hr($event,$l){
        $name=$this->people_name((int)$l->employee_id);$t=$this->hr_letter_title($l);
        $why=['template_missing'=>__('the type has no template in this language','workforce-one'),'no_salary'=>__('no pay rate is on file','workforce-one'),
            'render'=>__('the PDF could not be made','workforce-one'),'write'=>__('the file could not be saved','workforce-one')][(string)($l->issue_error??'')]??__('the PDF could not be made','workforce-one');
        $m=[
            /* translators: 1: letter type, 2: employee name */
            'waiting_file'=>[__('An HR letter waits for its file','workforce-one'),sprintf(__('"%1$s" for %2$s was approved. Upload it from HR letters.','workforce-one'),$t,$name)],
            /* translators: 1: letter type, 2: employee name */
            'request_only'=>[__('An HR letter to prepare','workforce-one'),sprintf(__('"%1$s" for %2$s was approved. Prepare it and mark it ready to collect.','workforce-one'),$t,$name)],
            /* translators: 1: letter type, 2: employee name, 3: why */
            'issue_failed'=>[__('An HR letter could not be made','workforce-one'),sprintf(__('Could not make "%1$s" for %2$s: %3$s. Regenerate it or upload a file.','workforce-one'),$t,$name,$why)],
        ];
        if(!isset($m[$event]))return;
        foreach($this->hr_letter_hr_users() as $uid)$this->notify($uid,'system',$m[$event][0],$m[$event][1],['url'=>admin_url('admin.php?page=ews31-hr-letters'),'push'=>true]);
    }

    private function hr_letter_store_file($bytes,$ext){
        $key=wp_generate_password(32,false).'.'.preg_replace('/[^a-z]/','',(string)$ext);
        return @file_put_contents($this->private_files_dir('workforce-one-letters').'/'.$key,(string)$bytes)!==false?$key:''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    /** @return array{key:string,name:string,size:int,error:string} */
    private function hr_letter_upload_pdf($f,$max){
        $out=['key'=>'','name'=>'','size'=>0,'error'=>'file'];
        if(!is_array($f) || (int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name']??'')))return $out;
        if((int)$f['size']>(int)$max)return ['error'=>'size']+$out;
        $head=(string)@file_get_contents((string)$f['tmp_name'],false,null,0,8); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if(!\WorkforceOne\HrLetters\LetterRules::isPdf($head))return ['error'=>'not_pdf']+$out;
        $key=wp_generate_password(32,false).'.pdf';
        if(!@move_uploaded_file((string)$f['tmp_name'],$this->private_files_dir('workforce-one-letters').'/'.$key))return ['error'=>'write']+$out; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.ForbiddenFunctions.Found
        return ['key'=>$key,'name'=>mb_substr(sanitize_file_name(wp_basename((string)$f['name'])),0,190),'size'=>(int)$f['size'],'error'=>''];
    }

    private function hr_letter_read_file($key){
        if(!preg_match('/^[A-Za-z0-9]{32}\.(pdf|png|jpg)$/',(string)$key))return null;
        $path=$this->private_files_path('workforce-one-letters',(string)$key);
        if(!is_readable($path))return null;
        return (string)file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    private function hr_letter_delete_file($key){
        if(preg_match('/^[A-Za-z0-9]{32}\.(pdf|png|jpg)$/',(string)$key))$this->private_files_delete('workforce-one-letters',(string)$key);
    }

    private function hr_letter_font(){
        return (string)file_get_contents(dirname(__DIR__).'/assets/vendor/amiri/Amiri-Regular.ttf'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /* ------------------------------------------------------------------ the employee: My requests, My approvals, the file */

    /** The words of an error code. */
    public function hr_letter_error_text($code){
        $m=[
            'access'=>__('You cannot do that.','workforce-one'),'type'=>__('Pick a letter type.','workforce-one'),'other_off'=>__('Only the letters in the list can be asked for.','workforce-one'),
            'other_text'=>__('Write which letter you need.','workforce-one'),'language'=>__('This letter is not available in that language.','workforce-one'),
            'note_long'=>__('The note is too long (500 characters at most).','workforce-one'),
            'open'=>__('You already asked for this letter in this language and it is not ready yet.','workforce-one'),
            'no_salary'=>__('Your salary is not on file yet, so this letter cannot be made. Ask HR.','workforce-one'),
            'approval'=>__('It could not be decided. Try again.','workforce-one'),'save'=>__('It could not be saved. Try again.','workforce-one'),
            'note'=>__('Write why you reject it, then reject it again.','workforce-one'),
            'moved'=>__('This request moved to another approval level since you opened the page, so nothing was changed. Open it again to see where it is.','workforce-one'),
            'done'=>__('This letter is no longer waiting for that: it was already decided or changed.','workforce-one'),
            'self'=>__('You cannot make a letter for yourself here: ask in the app and the administrators decide.','workforce-one'),
            'payroll'=>__('This letter shows the salary: it needs "Manage payroll" too.','workforce-one'),
            'template_missing'=>__('The type has no template in this language.','workforce-one'),'render'=>__('The PDF could not be made.','workforce-one'),
            'write'=>__('The file could not be saved.','workforce-one'),'file'=>__('Choose a PDF file.','workforce-one'),'size'=>__('The file is larger than 10 MB.','workforce-one'),
            'not_pdf'=>__('Only PDF files can be attached.','workforce-one'),'missing'=>__('The file is not there any more.','workforce-one'),
            'employee'=>__('Pick an employee.','workforce-one'),'image'=>__('Upload a JPEG or PNG image of 1 MB at most.','workforce-one'),
            'name'=>__('Give the type a name.','workforce-one'),'template_tokens'=>__('The template has a placeholder that is not in the list.','workforce-one'),
        ];
        return $m[$code]??$m['save'];
    }

    /**
     * For My requests → New request: the types this person can ask for (with their languages and what happens next), or null when letters are not offered.
     * @return array<string,mixed>|null
     */
    public function hr_letter_form($emp){
        if(!$emp || !$this->hr_letters_requestable())return null;
        $s=$this->hr_letter_settings();$types=[];
        foreach($this->hr_letter_types() as $t){
            if(!(int)$t['active'])continue;
            $langs=\WorkforceOne\HrLetters\LetterRules::languagesFor($t,$s);
            if(!$langs)continue;
            $types[]=['key'=>$t['key'],'name'=>\WorkforceOne\HrLetters\LetterSettings::name($t,$this->hr_letter_ui_lang()),'languages'=>$langs,'delivery'=>$t['delivery'],
                'salary'=>$t['delivery']==='pdf' && (\WorkforceOne\HrLetters\LetterTemplate::usesSalary((string)$t['tpl_ar']) || \WorkforceOne\HrLetters\LetterTemplate::usesSalary((string)$t['tpl_en']))];
        }
        if(!$types && !(int)$s['other'])return null;
        return ['types'=>$types,'languages'=>$s['languages'],'other'=>(int)$s['other'],'addressee_ar'=>$s['addressee_ar'],'addressee_en'=>$s['addressee_en'],
            'line'=>implode(', ',array_slice(array_map(function($t){return $t['name'];},$types),0,4))];
    }

    /** The language the person reads the app in: ar or en. */
    private function hr_letter_ui_lang(){
        return strpos((string)determine_locale(),'ar')===0?'ar':'en';
    }

    /** admin-post ews_hr_letter_request (My requests → New request → HR letter). */
    public function hr_letter_request(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_hr_letter_request');
        $back=$this->app_return_url('requests');
        $emp=$this->current_employee();
        if(!$emp){wp_safe_redirect(add_query_arg('hl_error','access',$back));exit;}
        $in=[];
        foreach(['type','other_text','addressee','language'] as $f)$in[$f]=sanitize_text_field(wp_unslash($_POST[$f]??''));
        $in['note']=sanitize_textarea_field(wp_unslash($_POST['note']??''));
        $r=$this->services()->letterRequests()->request($emp,$in,(int)get_current_user_id());
        wp_safe_redirect(add_query_arg($r['error']===''?['hl_sent'=>$r['status']]:['hl_error'=>$r['error']],$back));exit;
    }

    /** admin-post ews_hr_letter_respond: a level's decision (My approvals). */
    public function hr_letter_respond(){
        if(!is_user_logged_in())wp_die(esc_html__('You must be logged in.','workforce-one'));
        check_admin_referer('ews_hr_letter_respond');
        $back=$this->app_return_url($this->approvals_return_view('approvals'));
        $id=absint($_POST['letter_id']??0);$decision=sanitize_key($_POST['decision']??'');
        $r=$this->services()->letterDecisions()->decide($id,$decision,$this->approval_posted_note(),$this->approval_posted_step(),false);
        wp_safe_redirect(add_query_arg($r===''?['hl_done'=>1]:['hl_error'=>$r],$back));exit;
    }

    /** admin-post ews_hr_letter_file (a link): the letter's PDF, shown in the browser, never stored by it. */
    public function hr_letter_file(){
        if(!is_user_logged_in())auth_redirect();
        $id=absint($_GET['id']??0);
        check_admin_referer('ews_hr_letter_file_'.$id);
        $me=$this->current_employee();$u=wp_get_current_user();
        $r=$this->services()->letterIssuer()->download($id,$me?(int)$me->id:0,$this->can('ews_manage_hr_letters'),(string)($u->display_name??''));
        if($r['error']==='access')wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        if($r['error']!=='')wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        \WorkforceOne\Support\Download::image($r['bytes'],'application/pdf',$r['name']);
        exit;
    }

    private function hr_letter_file_url($id){
        return wp_nonce_url(add_query_arg(['action'=>'ews_hr_letter_file','id'=>(int)$id],admin_url('admin-post.php')),'ews_hr_letter_file_'.(int)$id);
    }

    /** My requests → Withdraw. @return true|string */
    public function hr_letter_withdraw($id,$employee_id){
        if(!$this->hr_letters_enabled())return 'access';
        $r=$this->services()->letterRequests()->withdraw((int)$id,(int)$employee_id);
        return $r===''?true:$r;
    }

    /** For My requests. @return list<array<string,mixed>> */
    public function hr_letter_my_requests($employee_id){
        if(!$this->hr_letters_enabled())return [];
        $out=[];
        $words=['waiting'=>'','ready'=>__('Ready','workforce-one'),'collect'=>__('Ready to collect','workforce-one'),'being_made'=>__('Approved · being made','workforce-one'),
            'preparing'=>__('Approved · HR is preparing it','workforce-one'),'preparing_collect'=>__('Approved · HR is preparing it','workforce-one'),'rejected'=>'','withdrawn'=>'','cancelled'=>__('Cancelled','workforce-one')];
        foreach($this->services()->letterRequests()->mine((int)$employee_id) as $l){
            if((string)$l->source==='hr' && !in_array((string)$l->status,['issued','ready'],true))continue;   // HR's own letters show once they are there
            $st=\WorkforceOne\HrLetters\LetterRules::stateFor($l);
            $ref=\WorkforceOne\HrLetters\LetterRules::reference((int)$l->id,(string)$l->requested_at);
            $sub=[sprintf(/* translators: %s: who the letter is addressed to */__('To %s','workforce-one'),(string)$l->addressee),$ref];
            if($st['label']==='preparing')$sub[]=__('you will be told when HR attaches it','workforce-one');
            $note='';
            if(in_array((string)$l->status,['rejected','cancelled'],true) && trim((string)$l->decision_note)!=='')$note=sprintf(/* translators: %s: reason */__('Reason: %s','workforce-one'),(string)$l->decision_note);
            if((string)$l->status==='ready')$note=trim((string)$l->decision_note)!==''?sprintf(/* translators: %s: where */__('Collect it from HR · %s','workforce-one'),(string)$l->decision_note):__('Collect it from HR','workforce-one');
            $out[]=['key'=>'letter-'.(int)$l->id,'tone'=>'letter','title'=>$this->hr_letter_title($l).' · '.((string)$l->language==='ar'?__('Arabic','workforce-one'):__('English','workforce-one')),
                'sub'=>implode(' · ',$sub),'state'=>$st['state'],'state_label'=>$words[$st['label']]??'','when'=>(string)$l->requested_at,'note'=>$note,
                'approval'=>['hr_letter','hr_letter',(int)$l->id],'withdraw'=>(string)$l->status==='pending'?'letter':'','id'=>(int)$l->id,
                'download'=>(string)$l->status==='issued'?$this->hr_letter_file_url((int)$l->id):''];
        }
        return $out;
    }

    /** The card in My approvals. @return array<string,mixed>|null */
    public function hr_letter_approval_card($id,$light=false){
        if(!$this->hr_letters_enabled())return null;
        $f=$this->services()->letterQuery()->facts((int)$id);
        if(!$f || (string)$f->status!=='pending')return null;
        if($light)return ['employee_id'=>(int)$f->employee_id,'tone'=>'letter','since'=>(string)$f->requested_at];   // (3.31.148)
        $hr=$this->can('ews_manage_hr_letters');
        $yes='✓';$no='✗';
        $data=[__('Arabic name','workforce-one').' '.(trim((string)$f->name_ar)!==''?$yes:$no),__('Gender','workforce-one').' '.((string)$f->gender!==''?$yes:$no),
            __('Hiring date','workforce-one').' '.((string)$f->hire_date!==''&&(string)$f->hire_date!=='0000-00-00'?($hr?date_i18n('j M Y',strtotime((string)$f->hire_date)):$yes):$no)];
        $delivery=['pdf'=>__('Automatic PDF once approved','workforce-one'),'upload'=>__('HR uploads the file','workforce-one'),'none'=>__('Request only (ready to collect)','workforce-one')][(string)$f->delivery]??'';
        $facts=[[__('Type','workforce-one'),$this->hr_letter_title($f)],[__('Language','workforce-one'),(string)$f->language==='ar'?__('Arabic','workforce-one'):__('English','workforce-one')],
            [__('Addressed to','workforce-one'),(string)$f->addressee],[__('Delivery','workforce-one'),$delivery],[__('Employee data','workforce-one'),implode(' · ',$data)]];
        if(trim((string)$f->note)!=='')$facts[]=[__('Note','workforce-one'),(string)$f->note];
        return ['employee_id'=>(int)$f->employee_id,'tone'=>'letter','kind'=>__('HR letter','workforce-one'),
            'what'=>$this->hr_letter_title($f).' · '.((string)$f->language==='ar'?__('Arabic','workforce-one'):__('English','workforce-one')).' · '.sprintf(/* translators: %s: addressee */__('to %s','workforce-one'),(string)$f->addressee),
            'facts'=>$facts,'since'=>(string)$f->requested_at,'action'=>'ews_hr_letter_respond','nonce'=>'ews_hr_letter_respond','fields'=>['letter_id'=>(int)$f->id],
            'pills'=>(int)$f->with_salary?[[__('Shows the salary','workforce-one'),'is-warn']]:[],
            'decisions'=>['yes'=>['approve',(string)$f->delivery==='pdf'?__('Approve and issue the letter','workforce-one'):__('Approve','workforce-one')],'no'=>['reject',__('Reject','workforce-one')]]];
    }

    /* ------------------------------------------------------------------ wp-admin → HR letters (Manage HR letters) */

    private function hr_letter_admin_back($args,$tab='list'){
        wp_safe_redirect(add_query_arg($args+['page'=>'ews31-hr-letters','tab'=>$tab],admin_url('admin.php')));exit;
    }

    private function hr_letter_admin_guard($nonce){
        if(!$this->can('ews_manage_hr_letters'))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        check_admin_referer($nonce);
    }

    /** The page: Letters, Types & templates, Letterhead, and Letter for an employee. */
    public function admin_hr_letters(){
        if(!$this->can('ews_manage_hr_letters'))wp_die(esc_html__('Access denied','workforce-one'));
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        $tab=sanitize_key($_GET['tab']??'list');if(!in_array($tab,['list','types','head','new'],true))$tab='list';
        $status=sanitize_key($_GET['status']??'');$search=sanitize_text_field(wp_unslash($_GET['s']??''));$paged=max(1,absint($_GET['paged']??1));
        $notice=null;
        if(isset($_GET['hl_done']))$notice=['type'=>'success','text'=>['uploaded'=>__('The file is attached and the employee was told.','workforce-one'),'regenerated'=>__('The letter was made again and the employee was told.','workforce-one'),
            'ready'=>__('Marked ready to collect; the employee was told.','workforce-one'),'cancelled'=>__('The letter was cancelled; the employee was told.','workforce-one'),'created'=>__('The letter was made for the employee.','workforce-one'),
            'saved'=>__('Saved.','workforce-one')][sanitize_key($_GET['hl_done'])]??__('Saved.','workforce-one')];
        elseif(isset($_GET['hl_error']))$notice=['type'=>'error','text'=>$this->hr_letter_error_text(sanitize_key($_GET['hl_error'])).(isset($_GET['hl_unknown'])?' '.sanitize_text_field(wp_unslash($_GET['hl_unknown'])):'')];
        $edit=sanitize_key($_GET['type']??'');
        // phpcs:enable
        $q=$this->services()->letterQuery();
        $list=$tab==='list'?$q->adminList($status,$search,$paged,30):['rows'=>[],'total'=>0];
        $rows=[];
        foreach($list['rows'] as $l){
            $rows[]=['l'=>$l,'ref'=>\WorkforceOne\HrLetters\LetterRules::reference((int)$l->id,(string)$l->requested_at),'title'=>$this->hr_letter_title($l),
                'file_url'=>(string)$l->status==='issued'?$this->hr_letter_file_url((int)$l->id):'',
                'can'=>['upload'=>\WorkforceOne\HrLetters\LetterRules::mayUpload($l) && (string)$l->delivery!=='none','regenerate'=>\WorkforceOne\HrLetters\LetterRules::mayRegenerate($l),
                    'ready'=>\WorkforceOne\HrLetters\LetterRules::mayMarkReady($l),'cancel'=>\WorkforceOne\HrLetters\LetterRules::mayCancel($l)]];
        }
        $types=$this->hr_letter_types();
        echo $this->render_template('admin/hr-letters',[ // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes
            'tab'=>$tab,'on'=>$this->hr_letters_enabled(),'notice'=>$notice,'rows'=>$rows,'total'=>(int)$list['total'],'paged'=>$paged,'status'=>$status,'search'=>$search,
            'counts'=>$tab==='list'?$q->counts():[],'types'=>$types,'edit'=>$edit,'settings'=>$this->hr_letter_settings(),'head'=>$this->hr_letter_letterhead(),
            'placeholders'=>\WorkforceOne\HrLetters\LetterTemplate::PLACEHOLDERS,'can_payroll'=>$this->can('ews_manage_payroll'),
            'employees'=>$tab==='new'||($tab==='types'&&$edit!=='')?$this->hr_letter_people():[],'me'=>$this->current_employee(),
            'post_url'=>admin_url('admin-post.php'),'page_url'=>admin_url('admin.php?page=ews31-hr-letters'),
            'image_url'=>function($which){return wp_nonce_url(add_query_arg(['action'=>'ews31_hr_letter_image','which'=>$which],admin_url('admin-post.php')),'ews31_hr_letter_image');},
            'features_url'=>admin_url('admin.php?page=ews31-features#ews-hr-letters'),'workflow_url'=>admin_url('admin.php?page=ews31-approvals'),
        ]);
    }

    /** Everyone HR may make a letter for, former employees too, by name. @return list<array{id:int,name:string,active:bool}> */
    private function hr_letter_people(){
        $out=[];
        foreach((array)$this->services()->employeeDetails()->people() as $p)$out[]=['id'=>(int)$p->id,'name'=>(string)$p->name,'active'=>(int)$p->active===1];
        return $out;
    }

    /** admin-post ews31_hr_letter_action: upload, regenerate, ready (with where), cancel (with the reason). */
    public function hr_letter_admin_action(){
        $id=absint($_POST['letter_id']??0);$do=sanitize_key($_POST['do']??'');
        $this->hr_letter_admin_guard('ews31_hr_letter_action_'.$id);
        // While letters are off nothing is delivered or decided: HR keeps the page and the downloads only (design §3).
        if(!$this->hr_letters_enabled())$this->hr_letter_admin_back(['hl_error'=>'access']);
        $uid=(int)get_current_user_id();
        // Making a salary letter again reads today's salary: Manage Payroll too, as for the preview and the direct letter (decision 6).
        $row=$this->services()->letterStore()->find($id);
        if($do==='regenerate' && $row && (int)$row->with_salary && !$this->can('ews_manage_payroll'))$this->hr_letter_admin_back(['hl_error'=>'payroll']);
        $issuer=$this->services()->letterIssuer();$dec=$this->services()->letterDecisions();
        switch($do){
            case 'upload': $r=$issuer->upload($id,$_FILES['letter_file']??null,$uid);$ok='uploaded';break; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked by the upload port
            case 'regenerate': $r=$issuer->regenerate($id,$uid);$ok='regenerated';break;
            case 'ready': $r=$dec->markReady($id,sanitize_text_field(wp_unslash($_POST['where']??'')),$uid);$ok='ready';break;
            case 'cancel': $r=$dec->cancel($id,sanitize_textarea_field(wp_unslash($_POST['reason']??'')),$uid);$ok='cancelled';break;
            default: $r='access';$ok='';
        }
        $this->hr_letter_admin_back($r===''?['hl_done'=>$ok]:['hl_error'=>$r]);
    }

    /** admin-post ews31_hr_letter_create: Letter for an employee (no request, no approval; former employees too). */
    public function hr_letter_admin_create(){
        $this->hr_letter_admin_guard('ews31_hr_letter_create');
        if(!empty($_POST['preview'])){$this->hr_letter_preview_out('new');return;}
        $emp=$this->services()->letterQuery()->employee(absint($_POST['employee_id']??0));
        if(!$emp)$this->hr_letter_admin_back(['hl_error'=>'employee'],'new');
        $in=[];foreach(['type','other_text','addressee','language','make'] as $f)$in[$f]=sanitize_text_field(wp_unslash($_POST[$f]??''));
        $me=$this->current_employee();
        $upload=!empty($_FILES['letter_file']['name'])?$_FILES['letter_file']:null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked by the upload port
        if($in['make']==='upload' && $upload===null)$this->hr_letter_admin_back(['hl_error'=>'file'],'new');
        $r=$this->services()->letterRequests()->createForEmployee($emp,$in,$upload,(int)get_current_user_id(),$me?(int)$me->id:0,$this->can('ews_manage_payroll'));
        if($r['id']===0)$this->hr_letter_admin_back(['hl_error'=>$r['error']],'new');
        $this->hr_letter_admin_back($r['error']===''?['hl_done'=>'created']:['hl_error'=>$r['error']]);
    }

    /** admin-post ews31_hr_letter_types_save: a type's names, delivery, active and templates. A salary template needs Manage Payroll too (Q7). */
    public function hr_letter_types_save(){
        $this->hr_letter_admin_guard('ews31_hr_letter_types_save');
        $key=\WorkforceOne\HrLetters\LetterSettings::key(sanitize_text_field(wp_unslash($_POST['key']??'')));
        $types=$this->hr_letter_types();
        $post=['name_ar'=>sanitize_text_field(wp_unslash($_POST['name_ar']??'')),'name_en'=>sanitize_text_field(wp_unslash($_POST['name_en']??'')),
            'delivery'=>sanitize_key($_POST['delivery']??''),'active'=>!empty($_POST['active']),
            'tpl_ar'=>sanitize_textarea_field(wp_unslash($_POST['tpl_ar']??'')),'tpl_en'=>sanitize_textarea_field(wp_unslash($_POST['tpl_en']??''))];
        $before=$key!==''?\WorkforceOne\HrLetters\LetterSettings::type($types,$key):null;
        foreach(['ar','en'] as $l){
            $changed=!$before || (string)$before['tpl_'.$l]!==\WorkforceOne\HrLetters\LetterSettings::template($post['tpl_'.$l]);
            if($changed && \WorkforceOne\HrLetters\LetterTemplate::usesSalary($post['tpl_'.$l]) && !$this->can('ews_manage_payroll'))$this->hr_letter_admin_back(['hl_error'=>'payroll','type'=>$key],'types');
        }
        $r=\WorkforceOne\HrLetters\LetterSettings::saveType($types,$key,$post,$this->hr_letter_settings()['new_type_delivery']);
        if($r['error']!=='')$this->hr_letter_admin_back(['hl_error'=>$r['error'],'type'=>$key,'hl_unknown'=>rawurlencode(implode(' ',$r['unknown']))],'types');
        update_option('ews_hr_letter_types',$r['types'],false);
        $this->audit('hr_letter_type_saved','settings',0,'HR letter type '.$r['key'].' saved');
        $this->hr_letter_admin_back(['hl_done'=>'saved','type'=>$r['key']],'types');
    }

    /** admin-post ews31_hr_letter_letterhead_save: the company, address, signatory, footer, and the three images (private, 1 MB, JPEG or PNG). */
    public function hr_letter_letterhead_save(){
        $this->hr_letter_admin_guard('ews31_hr_letter_letterhead_save');
        $head=$this->hr_letter_letterhead();
        foreach(['company','address','signatory_name','signatory_title','footer'] as $f)foreach(['ar','en'] as $l)$head[$f.'_'.$l]=sanitize_text_field(wp_unslash($_POST[$f.'_'.$l]??''));
        $old=[];
        foreach(['logo','signature','stamp'] as $img){
            if(!empty($_POST['remove_'.$img])){$old[]=$head[$img.'_key'];$head[$img.'_key']='';continue;}
            $f=$_FILES[$img]??null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below
            if(!is_array($f) || (int)($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;
            $bytes=(int)($f['error']??1)===UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name']) && (int)$f['size']<=\WorkforceOne\HrLetters\LetterRules::MAX_IMAGE?(string)file_get_contents((string)$f['tmp_name']):''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            try{$ok=$bytes!=='' && \WorkforceOne\Pdf\Image::fromString($bytes);}catch(\Throwable $e){$ok=false;}
            if(!$ok)$this->hr_letter_admin_back(['hl_error'=>'image'],'head');
            $key=$this->hr_letter_store_file($bytes,strncmp($bytes,"\x89PNG",4)===0?'png':'jpg');
            if($key==='')$this->hr_letter_admin_back(['hl_error'=>'write'],'head');
            $old[]=$head[$img.'_key'];$head[$img.'_key']=$key;
        }
        update_option('ews_hr_letter_letterhead',\WorkforceOne\HrLetters\LetterSettings::letterhead($head),false);
        foreach(array_filter($old) as $k)$this->hr_letter_delete_file($k);
        $this->audit('hr_letter_letterhead_saved','settings',0,'HR letters letterhead saved');
        $this->hr_letter_admin_back(['hl_done'=>'saved'],'head');
    }

    /** admin-post ews31_hr_letter_image: a letterhead image, to HR only (never a public link). */
    public function hr_letter_image(){
        $this->hr_letter_admin_guard('ews31_hr_letter_image');
        $which=sanitize_key($_GET['which']??'');
        $key=in_array($which,['logo','signature','stamp'],true)?(string)$this->hr_letter_letterhead()[$which.'_key']:'';
        $bytes=$key!==''?$this->hr_letter_read_file($key):null;
        if($bytes===null)wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        \WorkforceOne\Support\Download::image($bytes,substr($key,-4)==='.png'?'image/png':'image/jpeg',$which.substr($key,-4));
        exit;
    }

    /** admin-post ews31_hr_letter_preview: a letter for an employee as it would be made now, not saved. A salary letter needs Manage Payroll (decision 6). */
    public function hr_letter_preview(){
        $this->hr_letter_admin_guard('ews31_hr_letter_preview');
        $this->hr_letter_preview_out(isset($_POST['from_types'])?'types':'new');
    }

    /** The preview of the posted employee, type and language (the caller checked the capability and its nonce). */
    private function hr_letter_preview_out($back){
        $lang=sanitize_key($_POST['language']??'ar')==='en'?'en':'ar';
        $key=\WorkforceOne\HrLetters\LetterSettings::key(sanitize_text_field(wp_unslash($_POST['type']??'')));
        $type=\WorkforceOne\HrLetters\LetterSettings::type($this->hr_letter_types(),$key);
        $tpl=(string)($type['tpl_'.$lang]??'');   // the saved template
        if(\WorkforceOne\HrLetters\LetterTemplate::usesSalary($tpl) && !$this->can('ews_manage_payroll'))$this->hr_letter_admin_back(['hl_error'=>'payroll','type'=>$key],$back);
        $s=$this->hr_letter_settings();
        $addr=sanitize_text_field(wp_unslash($_POST['addressee']??''));
        $r=$this->services()->letterIssuer()->preview(absint($_POST['employee_id']??0),$type?\WorkforceOne\HrLetters\LetterSettings::name($type,$lang):($lang==='ar'?'خطاب':'Letter'),$lang,$tpl,$addr!==''?$addr:(string)$s['addressee_'.$lang]);
        if($r['error']!=='')$this->hr_letter_admin_back(['hl_error'=>$r['error'],'type'=>$key],$back);
        \WorkforceOne\Support\Download::image($r['pdf'],'application/pdf','preview.pdf');
        exit;
    }

    /* ------------------------------------------------------------------ the Requests Hub (owner decision 1) */

    /** The letters waiting for a decision (none while letters are off: no query). @return object[] */
    public function hr_letter_hub_rows(){
        return $this->hr_letters_enabled()?$this->services()->letterQuery()->waiting():[];
    }

    /** An administrator's decision on the Requests Hub (the override of a level). @return string a Hub outcome */
    public function hr_letter_hub_decide($id,$decision){
        $r=$this->services()->letterDecisions()->decide((int)$id,$decision,$decision==='reject'?'Rejected on the Requests page.':'',$this->approval_posted_step(),true);
        if($r==='moved')return \WorkforceOne\Requests\Hub::MOVED;
        if($r!=='')return \WorkforceOne\Requests\Hub::ERROR;
        $l=$this->services()->letterStore()->find((int)$id);
        $st=$l?(string)$l->status:'';
        if($st==='rejected')return \WorkforceOne\Requests\Hub::REJECTED;
        if($st==='pending')return \WorkforceOne\Requests\Hub::ADVANCED;
        return \WorkforceOne\Requests\Hub::APPROVED;
    }
}
