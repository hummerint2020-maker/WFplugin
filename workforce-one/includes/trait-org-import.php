<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Organization\ImportPlan;
use WorkforceOne\Organization\MoveRules;
use WorkforceOne\Support\Xlsx;

/**
 * The Org chart's managers in Excel (3.31.98, stage 3): export every active employee with their job
 * title, department and manager; change the file and import it back. Nothing is saved before the
 * preview: what will change, and every row that cannot be used and why (src/Organization/ImportPlan).
 * The changes are moves (from a date, with a reason; the waiting requests go to the new manager), and
 * job titles. Part of People. Behaviour: tests/e2e_org_moves.py.
 */
trait EWS_Org_Import_Trait {

    private function org_import_key(){
        return 'ews_org_import_'.get_current_user_id();
    }

    /** Every employee for the import, by id. @return array<int,array{id:int,name:string,domain:string,title:string,manager:int,active:bool}> */
    private function org_import_people(){
        global $wpdb;
        $managers=$this->people_manager_ids();
        $out=[];
        foreach((array)$wpdb->get_results("SELECT id,name,domain_name,job_title,active FROM {$this->employees}") as $r){
            $id=(int)$r->id;
            $out[$id]=['id'=>$id,'name'=>(string)$r->name,'domain'=>(string)$r->domain_name,'title'=>(string)($r->job_title??''),'manager'=>(int)($managers[$id]??0),'active'=>(int)$r->active===1];
        }
        return $out;
    }

    /** The managers file (admin-post ews_org_export). */
    public function org_export(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_org_export');
        $rows=$this->people_org_rows();$tree=$this->org_tree();
        $data=[];
        foreach($rows as $id=>$r){
            $m=$tree->managerOf($id);$mr=$m?($rows[$m]??null):null;
            $data[]=[$id,(string)$r->name,(string)($r->domain_name??''),(string)($r->job_title??''),(string)($r->department_name??''),$m?:'',$mr?(string)$mr->name:'',$mr?(string)($mr->domain_name??''):''];
        }
        $headers=['Employee ID','Name','Domain name','Job title','Department','Manager ID','Manager name','Manager domain name'];
        $help=[
            ['Change the manager of anyone: write the new manager\'s Manager ID (or Manager domain name). Leave both empty for no manager.'],
            ['Manager name is only there to read; it is not used.'],
            ['Change a job title in the Job title column. An empty Job title keeps the saved one.'],
            ['Do not change Employee ID. Rows can be removed: only the rows in the file are looked at.'],
            ['Then import the file on the Org chart page. You will see every change, and every row that cannot be used, before anything is saved.'],
        ];
        $this->audit('org_exported','settings',0,count($data).' employees');
        $date=current_time('Y-m-d');
        if(!Xlsx::available()){
            $csv=fopen('php://temp','w+');
            fwrite($csv,"\xEF\xBB\xBF");
            fputcsv($csv,$headers,',','"','\\');
            foreach($data as $r)fputcsv($csv,\WorkforceOne\Support\Csv::row($r),',','"','\\');
            rewind($csv);
            \WorkforceOne\Support\Download::send((string)stream_get_contents($csv),'text/csv; charset=utf-8','org-chart-managers-'.$date.'.csv');
            exit;
        }
        \WorkforceOne\Support\Download::send(Xlsx::write([['Managers',$headers,$data,[12,26,22,26,18,12,26,22]],['How to import',['How to import'],$help,[120]]]),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','org-chart-managers-'.$date.'.xlsx');
        exit;
    }

    /** Reads the uploaded file and keeps the preview for 30 minutes (admin-post ews_org_import). */
    public function org_import_preview(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_org_import');
        $back=['page'=>'ews31-org-chart'];
        if(!$this->org_enabled())$this->org_redirect($back+['org_error'=>'off']);
        $f=$_FILES['managers_file']??null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, read only
        if(!is_array($f) || (int)($f['error']??1)!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) || (int)$f['size']>5*MB_IN_BYTES)$this->org_redirect($back+['org_error'=>'file']);
        $name=sanitize_file_name((string)$f['name']);
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['xlsx','csv'],true))$this->org_redirect($back+['org_error'=>'file']);
        if($ext==='xlsx' && !Xlsx::available())$this->org_redirect($back+['org_error'=>'zip']);
        $rows=Xlsx::read((string)$f['tmp_name'],$name);
        if($rows===null)$this->org_redirect($back+['org_error'=>'file']);
        $plan=ImportPlan::build($rows,$this->org_import_people());
        if($plan['error'])$this->org_redirect($back+['org_error'=>$plan['error']]);
        $date=sanitize_text_field(wp_unslash($_POST['effective_date']??''));
        if(!MoveRules::validDate($date))$date=current_time('Y-m-d');
        $reason=MoveRules::reason(wp_unslash($_POST['reason']??''));
        if($reason==='')$reason='Imported from Excel';
        set_transient($this->org_import_key(),['plan'=>$plan,'date'=>$date,'reason'=>$reason,'file'=>$name],30*MINUTE_IN_SECONDS);
        $this->org_redirect($back+['import'=>'preview']);
    }

    /** Saves the previewed changes (admin-post ews_org_import_apply). */
    public function org_import_apply(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_org_import_apply');
        $back=['page'=>'ews31-org-chart'];
        $saved=get_transient($this->org_import_key());
        delete_transient($this->org_import_key());
        if(!empty($_POST['cancel']))$this->org_redirect($back);
        if(!is_array($saved) || empty($saved['plan']))$this->org_redirect($back+['org_error'=>'expired']);
        if(!$this->org_enabled())$this->org_redirect($back+['org_error'=>'off']);
        global $wpdb;
        $plan=$saved['plan'];$date=(string)$saved['date'];$reason=(string)$saved['reason'];
        $scheduled=MoveRules::isScheduled($date,current_time('Y-m-d'));
        $moved=0;$planned=0;$skipped=0;$titles=0;
        // Checked again (the people may have changed since the preview), in passes: a row that would make a
        // loop now may be fine once another row of the file is done (B no longer under A, then A under B).
        $pending=$plan['moves'];
        while($pending){
            $later=[];
            foreach($pending as $m){
                $code=$this->org_move_check((int)$m['employee'],(int)$m['to'],$date,$reason,false,0,!$scheduled);
                if($code==='loop'){$later[]=$m;continue;}
                if($code){$skipped++;continue;}
                if($scheduled){$this->org_move_schedule((int)$m['employee'],(int)$m['to'],$date,$reason,'import');$planned++;continue;}
                $r=$this->org_move_apply((int)$m['employee'],(int)$m['to'],$date,$reason,'import');
                if($r['ok'])$moved++;else $skipped++;
            }
            if(count($later)===count($pending)){$skipped+=count($later);break;}
            $pending=$later;
        }
        foreach($plan['titles'] as $t){
            if($wpdb->update($this->employees,['job_title'=>$t['to'],'updated_at'=>current_time('mysql')],['id'=>(int)$t['employee'],'active'=>1])){
                $titles++;
                $this->audit('employee_job_title','employee',(int)$t['employee'],'"'.$t['from'].'" → "'.$t['to'].'" · import');
            }
        }
        $this->audit('org_imported','settings',0,$saved['file'].': '.$moved.' moved, '.$planned.' scheduled for '.$date.', '.$titles.' job titles, '.$skipped.' skipped, '.count($plan['problems']).' rows not used');
        $this->org_redirect($back+['org_notice'=>'imported','moved'=>$moved,'planned'=>$planned,'titles'=>$titles,'skipped'=>$skipped]);
    }

    /** The preview shown on the page, with names. @return array<string,mixed>|null */
    private function org_import_preview_data(){
        $saved=get_transient($this->org_import_key());
        if(!is_array($saved) || empty($saved['plan']))return null;
        $people=$this->org_import_people();
        $name=function($id)use($people){return $id?($people[(int)$id]['name']??'#'.(int)$id):'';};
        $codes=[
            'employee_unknown'=>__('No employee with this ID or domain name','workforce-one'),
            'employee_archived'=>__('The employee is archived','workforce-one'),
            'duplicate'=>__('The employee is in the file twice; the first row is used','workforce-one'),
            'manager_unknown'=>__('No employee with this manager ID or domain name','workforce-one'),
            'manager_archived'=>__('The manager is archived','workforce-one'),
            'self'=>__('Nobody can be their own manager','workforce-one'),
            'loop'=>__('Would put them under someone who reports to them','workforce-one'),
        ];
        $p=$saved['plan'];
        return [
            'file'=>(string)$saved['file'],'date'=>(string)$saved['date'],'reason'=>(string)$saved['reason'],
            'scheduled'=>MoveRules::isScheduled((string)$saved['date'],current_time('Y-m-d')),
            'rows'=>(int)$p['rows'],'unchanged'=>(int)$p['unchanged'],
            'moves'=>array_map(function($m)use($name){return ['row'=>$m['row'],'name'=>$name($m['employee']),'from'=>$name($m['from']),'to'=>$name($m['to'])];},$p['moves']),
            'titles'=>array_map(function($t)use($name){return ['row'=>$t['row'],'name'=>$name($t['employee']),'from'=>$t['from'],'to'=>$t['to']];},$p['titles']),
            'problems'=>array_map(function($x)use($codes){return ['row'=>$x['row'],'why'=>$codes[$x['code']]??$x['code'],'value'=>$x['value']];},$p['problems']),
        ];
    }
}
