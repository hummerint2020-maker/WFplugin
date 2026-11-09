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
        if(!\WorkforceOne\HrLetters\LetterRules::isPdf($head))return ['error'=>'type']+$out;
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
}
