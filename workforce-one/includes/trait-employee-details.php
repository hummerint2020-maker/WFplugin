<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\DetailsImportPlan;
use WorkforceOne\Employees\EmployeeDetailsRules;
use WorkforceOne\Support\Xlsx;

/**
 * An employee's details for letters (HR letters HL2, docs/tasks/employee-details.md): the name in Arabic, gender and the hiring date. HR saves
 * them on the employee's profile, or for many employees from a file (download it, fill it, upload it, see the changes, apply). Controllers only:
 * the checks are in src/Employees/EmployeeDetailsRules.php and DetailsImportPlan.php, the saving in src/People/EmployeeDetailsService.php.
 * Who: Manage Employees, like every other employee edit (owner decision Q1). Part of People. Behaviour: tests/e2e_employee_details.py.
 */
trait EWS_Employee_Details_Trait {

    private function employee_details_key(){
        return 'ews_emp_details_import_'.get_current_user_id();
    }

    private function employee_details_back($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;
    }

    /** The profile's form (admin-post ews31_employee_details_save). */
    public function employee_details_save(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        $id=absint($_POST['employee_id']??0);
        check_admin_referer('ews31_employee_details_'.$id);
        $raw=[];
        foreach(EmployeeDetailsRules::FIELDS as $f)$raw[$f]=sanitize_text_field(wp_unslash($_POST[$f]??''));
        $error=$this->services()->employeeDetails()->save($id,$raw);
        $back=['page'=>'ews31-employee-profile','employee_id'=>$id];
        if($error!==null)$this->employee_details_back($back+['details_error'=>$error,'edit_details'=>1]);
        $this->employee_details_back($back+['details_notice'=>'saved']);
    }

    /** The file of every employee with their details (admin-post ews31_employee_details_export). */
    public function employee_details_export(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews31_employee_details_export');
        $headers=['Employee ID','Domain name','Name','Status','name_ar','gender','hire_date'];
        $data=[];
        foreach($this->services()->employeeDetails()->people() as $r){
            $data[]=[(int)$r->id,(string)$r->domain_name,(string)$r->name,(int)$r->active===1?'Active':'Former',(string)($r->name_ar??''),(string)($r->gender??''),(string)($r->hire_date??'')];
        }
        $help=[
            ['Fill name_ar (the name in Arabic), gender (male or female) and hire_date (YYYY-MM-DD, for example 2021-03-01).'],
            ['An empty cell leaves the saved value as it is. To remove a value, edit the employee\'s profile.'],
            ['Do not change Employee ID. Name and Status are only there to read. Rows can be removed: only the rows in the file are looked at.'],
            ['Then upload the file on Employees → Update details from a file. You will see every change, and every row that cannot be used, before anything is saved.'],
        ];
        $this->audit('employee_details_exported','settings',0,count($data).' employees');
        $date=current_time('Y-m-d');
        if(!Xlsx::available()){
            $csv=fopen('php://temp','w+');
            fwrite($csv,"\xEF\xBB\xBF");
            fputcsv($csv,$headers,',','"','\\');
            foreach($data as $r)fputcsv($csv,\WorkforceOne\Support\Csv::row($r),',','"','\\');
            rewind($csv);
            \WorkforceOne\Support\Download::send((string)stream_get_contents($csv),'text/csv; charset=utf-8','employee-details-'.$date.'.csv');
            exit;
        }
        \WorkforceOne\Support\Download::send(Xlsx::write([['Employees',$headers,$data,[12,22,26,10,30,10,14]],['How to import',['How to import'],$help,[120]]]),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','employee-details-'.$date.'.xlsx');
        exit;
    }

    /** Reads the uploaded file and keeps the preview for 30 minutes (admin-post ews31_employee_details_import). */
    public function employee_details_import(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews31_employee_details_import');
        $back=['page'=>'ews31-employees','view'=>'details-import'];
        $f=$_FILES['details_file']??null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, read only
        if(!is_array($f) || (int)($f['error']??1)!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']) || (int)$f['size']>5*MB_IN_BYTES)$this->employee_details_back($back+['details_error'=>'file']);
        $name=sanitize_file_name((string)$f['name']);
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,['xlsx','csv'],true))$this->employee_details_back($back+['details_error'=>'file']);
        if($ext==='xlsx' && !Xlsx::available())$this->employee_details_back($back+['details_error'=>'zip']);
        $rows=Xlsx::read((string)$f['tmp_name'],$name);
        if($rows===null)$this->employee_details_back($back+['details_error'=>'file']);
        $plan=DetailsImportPlan::build($rows,$this->services()->employeeDetails()->people(),current_time('Y-m-d'));
        if($plan['error'])$this->employee_details_back($back+['details_error'=>$plan['error']]);
        // The token ties Apply to this preview: a file uploaded later in another tab replaces the preview, and the older page's Apply is refused.
        $kept=['plan'=>$plan,'file'=>$name,'token'=>wp_generate_password(20,false)];
        if(!set_transient($this->employee_details_key(),$kept,30*MINUTE_IN_SECONDS) || !is_array(get_transient($this->employee_details_key())))$this->employee_details_back($back+['details_error'=>'preview']);
        $this->employee_details_back($back);
    }

    /** Saves the previewed changes (admin-post ews31_employee_details_import_apply). */
    public function employee_details_import_apply(){
        if(!$this->can('ews_manage_employees'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews31_employee_details_import_apply');
        $back=['page'=>'ews31-employees','view'=>'details-import'];
        $saved=get_transient($this->employee_details_key());
        $token=sanitize_text_field(wp_unslash($_POST['preview']??''));
        // Only the preview this page showed: another one (a later upload in another tab) is neither applied nor removed.
        if(!is_array($saved) || empty($saved['plan']) || !hash_equals((string)($saved['token']??''),$token)){
            if(!empty($_POST['cancel']))$this->employee_details_back($back);
            $this->employee_details_back($back+['details_error'=>'expired']);
        }
        delete_transient($this->employee_details_key());
        if(!empty($_POST['cancel']))$this->employee_details_back($back);
        $r=$this->services()->employeeDetails()->apply($saved['plan'],(string)$saved['file']);
        $this->employee_details_back($back+['details_notice'=>'imported','changed'=>$r['changed'],'employees'=>$r['employees'],'skipped'=>$r['skipped']]);
    }

    /** Employees → Update details from a file: the upload, or the preview of the file just uploaded. */
    private function admin_employee_details_import(){
        $saved=get_transient($this->employee_details_key());
        $preview=null;
        if(is_array($saved) && !empty($saved['plan'])){
            $people=$this->services()->employeeDetails()->people();
            $p=$saved['plan'];
            $label=['name_ar'=>__('Name in Arabic','workforce-one'),'gender'=>__('Gender','workforce-one'),'hire_date'=>__('Hiring date','workforce-one')];
            $value=function($field,$v){return $v===null||$v===''?'—':($field==='gender'?$this->employee_gender_label($v):$v);};
            $who=function($id)use($people){return isset($people[$id])?[(string)$people[$id]->domain_name,(string)$people[$id]->name.((int)$people[$id]->active===1?'':' · '.__('Former','workforce-one'))]:['',''];};
            $employees=[];foreach($p['changes'] as $c)$employees[(int)$c['employee']]=true;
            $preview=[
                'file'=>(string)$saved['file'],'token'=>(string)($saved['token']??''),'unchanged'=>(int)$p['unchanged'],'employees'=>count($employees),
                'changes'=>array_map(function($c)use($label,$value,$who){[$d,$n]=$who((int)$c['employee']);return ['domain'=>$d,'name'=>$n,'field'=>$label[$c['field']],'from'=>$value($c['field'],$c['from']),'to'=>$value($c['field'],$c['to'])];},$p['changes']),
                'problems'=>array_map(function($x)use($who){[$d,$n]=$who((int)$x['employee']);return ['row'=>(int)$x['row'],'domain'=>$d!==''?$d:(string)$x['value'],'name'=>$n,'value'=>(string)$x['value'],'why'=>$this->employee_details_message($x['code'])];},$p['problems']),
            ];
        }
        $error=sanitize_key($_GET['details_error']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $notice=sanitize_key($_GET['details_notice']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        echo $this->render_template('admin/employee-details-import',[ // phpcs:ignore WordPress.Security.EscapeOutput -- the template escapes
            'preview'=>$preview,'post_url'=>admin_url('admin-post.php'),'employees_url'=>admin_url('admin.php?page=ews31-employees'),
            'export_label'=>Xlsx::available()?__('Download the employees file (Excel)','workforce-one'):__('Download the employees file (CSV)','workforce-one'),
            'export_url'=>wp_nonce_url(admin_url('admin-post.php?action=ews31_employee_details_export'),'ews31_employee_details_export'),
            'error'=>$error!==''?$this->employee_details_message($error):'',
            'notice'=>$notice==='imported'?sprintf(/* translators: 1: changes, 2: employees, 3: skipped */__('%1$d change(s) saved for %2$d employee(s). %3$d skipped because the employee changed since the preview.','workforce-one'),absint($_GET['changed']??0),absint($_GET['employees']??0),absint($_GET['skipped']??0)):'', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        ]);
    }

    /** Male / Female / Not set, translated. */
    private function employee_gender_label($g){
        if($g==='male')return __('Male','workforce-one');
        if($g==='female')return __('Female','workforce-one');
        return __('Not set','workforce-one');
    }

    /** The words of an error or a refused row, translated. */
    private function employee_details_message($code){
        $m=[
            'employee_unknown'=>__('No employee with this ID or domain name','workforce-one'),
            'duplicate'=>__('The employee is already in an earlier row; only the first row is looked at','workforce-one'),
            'too_long'=>__('The name in Arabic is too long (190 characters at most)','workforce-one'),
            'bad_gender'=>__('Gender must be male or female','workforce-one'),
            'bad_date'=>__('The hiring date must be written as YYYY-MM-DD','workforce-one'),
            'future_date'=>__('The hiring date is in the future','workforce-one'),
            'empty'=>__('The file is empty.','workforce-one'),
            'columns'=>__('The file needs an "Employee ID" or "Domain name" column, and at least one of name_ar, gender, hire_date.','workforce-one'),
            'too_many'=>__('The file has more than 5,000 rows.','workforce-one'),
            'file'=>__('Upload an Excel (.xlsx) or CSV file of 5 MB at most.','workforce-one'),
            'zip'=>__('This server cannot read Excel files. Save the file as CSV and upload it again.','workforce-one'),
            'expired'=>__('The preview expired. Upload the file again.','workforce-one'),
            'preview'=>__('The preview could not be kept on this site. Upload a smaller file (fewer rows).','workforce-one'),
            'not_found'=>__('Employee not found.','workforce-one'),
            'save'=>__('The details could not be saved. Please try again.','workforce-one'),
        ];
        return $m[$code]??$m['save'];
    }
}
