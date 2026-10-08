<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Schedule\SwapRules;
use WorkforceOne\Schedule\TeamOrder;

/**
 * The employee app's shared Team Schedule (?ews_view=schedule) with the Schedule Swap panel.
 * View: templates/app/schedule.php; script: assets/js/schedule.js; ordering: src/Schedule/TeamOrder.php.
 * Swap actions live in trait-swap.php. Behaviour: tests/e2e_schedule_view.py.
 */
trait EWS_Schedule_View_Trait {

    /** The shared Team Schedule order (rules: src/Schedule/TeamOrder.php). */
    private function schedule_employees_ordered($emps,$current_emp_id=0){
        global $wpdb;
        $emps=array_values((array)$emps);
        if(!$emps) return $emps;
        $t=$this->team_tables();
        // Team tables are created by maybe_upgrade_schema(); only probe on a not-yet-upgraded install.
        if(!$this->ews_schema_is_current()){
            foreach(['teams','members'] as $k)if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t[$k]))!==$t[$k])return $emps;
        }
        $teams=[];$members=[];
        foreach((array)$wpdb->get_results("SELECT id,name,manager_employee_id FROM {$t['teams']} WHERE active=1") as $r)$teams[(int)$r->id]=['name'=>(string)$r->name,'manager'=>(int)$r->manager_employee_id];
        if(!$teams) return $emps;
        foreach((array)$wpdb->get_results("SELECT team_id,employee_id FROM {$t['members']} WHERE active=1") as $r)$members[(int)$r->employee_id][]=(int)$r->team_id;
        return \WorkforceOne\Schedule\TeamOrder::arrange($emps,$teams,$members,(int)$current_emp_id);
    }

    /**
     * The week the Team Schedule shows (?week=…): the employees in team order, the working days and
     * each employee's status per day (General Leave on a company holiday, Not Set when empty).
     * @return array{current_emp_id:int,emps:object[],dates:string[],sun:int,today:string,week_end:string,map:array<int,array<string,string>>,company_leave:array<string,string>,cells:array<int,array<string,string>>}
     */
    private function schedule_week_grid(){
        global $wpdb;
        $current_emp=$this->current_employee();
        $current_emp_id=$current_emp?(int)$current_emp->id:0;
        $emps=$this->schedule_employees_ordered($this->department_scoped_employees(),$current_emp_id);
        $dept_emps=$emps;   // the swap form offers the whole department, whichever list is shown
        $scope=$this->schedule_scope($emps,$current_emp_id);
        $emps=$scope['emps'];
        [$dates,$sun]=$this->week_dates();
        $today=current_time('Y-m-d');
        if(!$dates)$dates=[$today];
        $week_end=$dates[count($dates)-1];
        $ph=implode(',',array_fill(0,count($dates),'%s'));
        $map=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status FROM {$this->schedule} WHERE work_date IN ($ph)",...$dates)) as $r)$map[(int)$r->employee_id][$r->work_date]=$r->status;
        $company_leave=$this->company_leave_dates($dates[0],$week_end);
        $cells=[];
        foreach($emps as $e)foreach($dates as $d)$cells[(int)$e->id][$d]=isset($company_leave[$d])?'General Leave':($map[(int)$e->id][$d]??'Not Set');
        $scope=$scope['scope'];
        return compact('current_emp_id','emps','dates','sun','today','week_end','map','company_leave','cells','scope','dept_emps');
    }

    /**
     * Whose schedule an employee sees (3.31.71): their team(s) first, and their whole department when
     * they choose (?scope=department). Administrators, and employees without a team, see the list as
     * before. @param object[] $emps the department list, in display order
     * @return array{emps:object[],scope:string} scope '' when there is no choice to make
     */
    private function schedule_scope($emps,$current_emp_id){
        if(!$current_emp_id||current_user_can('manage_options'))return ['emps'=>$emps,'scope'=>''];
        global $wpdb;$t=$this->team_tables();
        $mates=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT m.employee_id FROM {$t['members']} m INNER JOIN {$t['teams']} tm ON tm.id=m.team_id AND tm.active=1 INNER JOIN {$t['members']} me ON me.team_id=m.team_id AND me.active=1 AND me.employee_id=%d WHERE m.active=1",$current_emp_id)));
        if(!$mates)return ['emps'=>$emps,'scope'=>''];
        if(sanitize_key($_GET['scope']??'')==='department')return ['emps'=>$emps,'scope'=>'department'];
        $mates=array_flip($mates);$mates[$current_emp_id]=true;
        return ['emps'=>array_values(array_filter($emps,function($e)use($mates){return isset($mates[(int)$e->id]);})),'scope'=>'team'];
    }

    /** Team Schedule → PDF / WhatsApp: the shown week as a PDF file (src/Schedule/SchedulePdf.php). */
    public function schedule_pdf(){
        if(!is_user_logged_in())wp_die(esc_html__('Please sign in.','workforce-one'),403);
        check_admin_referer('ews_schedule_pdf');
        $g=$this->schedule_week_grid();
        $known=['Not Set'=>__('Not Set','workforce-one'),'General Leave'=>__('General Leave','workforce-one'),'Absent'=>__('Absent','workforce-one')];
        $rows=[];
        foreach($g['emps'] as $e){
            $cells=[];
            foreach($g['dates'] as $d){$st=(string)$g['cells'][(int)$e->id][$d];[,$tone]=\WorkforceOne\Ui\Icons::forStatus($st);$cells[]=['label'=>$known[$st]??$st,'tone'=>$tone];}
            $rows[]=['name'=>(string)$e->name,'team'=>implode(' · ',(array)($e->_schedule_team_names??[])),'cells'=>$cells];
        }
        $days=[];
        foreach($g['dates'] as $d)$days[]=['name'=>date_i18n('D',strtotime($d)),'date'=>date_i18n('d M',strtotime($d))];
        $range=date_i18n('d M',strtotime($g['dates'][0])).' – '.date_i18n('d M Y',strtotime($g['week_end']));
        $font=\WorkforceOne\Pdf\TrueTypeFont::fromFile(dirname(__DIR__).'/assets/vendor/dejavu/DejaVuSans.ttf');
        $pdf=\WorkforceOne\Schedule\SchedulePdf::render($font,['company'=>wp_specialchars_decode((string)get_bloginfo('name'),ENT_QUOTES),'title'=>__('Team Schedule','workforce-one').' '.$range,'heading'=>__('Team Schedule','workforce-one'),'employee'=>__('Employee','workforce-one'),'empty'=>__('No employees to show.','workforce-one'),'range'=>$range,'days'=>$days,'rows'=>$rows,'generated'=>date_i18n('j M Y H:i')]);
        \WorkforceOne\Support\Download::send($pdf,'application/pdf','team-schedule-'.$g['dates'][0].'.pdf');
        exit;
    }

    private function schedule_content(){
        $g=$this->schedule_week_grid();
        // A long list is searched and paged on the server (Ui\ListPage, 3.31.71); the swap form still offers everyone.
        $swap_people=$g['dept_emps'];
        $list=\WorkforceOne\Ui\ListPage::apply($g['emps'],wp_unslash($_GET));
        $g['emps']=$list['rows'];
        ['current_emp_id'=>$current_emp_id,'emps'=>$emps,'dates'=>$dates,'sun'=>$sun,'today'=>$today,'week_end'=>$week_end,'map'=>$map,'company_leave'=>$company_leave,'cells'=>$cells]=$g;
        $people=[];
        foreach($emps as $e)$people[(int)$e->id]=['picture'=>$this->employee_picture_url($e),'initials'=>\WorkforceOne\Employees\ProfileSummary::initials((string)$e->name)?:'·'];
        $legend=[];
        foreach($this->schedule_types_config(true) as $stype)$legend[]=(string)$stype['name'];
        foreach(['General Leave','Absent','Not Set'] as $extra)$legend[]=$extra;
        $legend=array_values(array_unique($legend));

        // Days I can offer: my Office/WFH days from today on that are not a company holiday.
        $swap_days=[];
        foreach($dates as $d){
            $mine=$map[$current_emp_id][$d]??'';
            if($current_emp_id && in_array($mine,SwapRules::SWAPPABLE,true) && !isset($company_leave[$d]) && !SwapRules::checkNotPast($d,$today))$swap_days[$d]=$mine;
        }
        [,$this_week]=$this->week_dates_configured($today);
        $range=date('d M',strtotime($dates[0])).' – '.date('d M Y',strtotime($week_end));
        $email=null;
        if(isset($_GET['email_sent'])||isset($_GET['email_skipped'])||isset($_GET['email_failed']))$email=['sent'=>absint($_GET['email_sent']??0),'skipped'=>absint($_GET['email_skipped']??0),'failed'=>absint($_GET['email_failed']??0)];
        wp_enqueue_script('workforce-one-schedule');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_style('workforce-one-schedule-page');
        return $this->render_template('app/schedule',[
            'emps'=>$emps,'list'=>$list,'list_keep'=>$this->list_keep_args('schedule'),'swap_people'=>$swap_people,'dates'=>$dates,'today'=>$today,'current_emp_id'=>$current_emp_id,'cells'=>$cells,'people'=>$people,'legend'=>$legend,
            'range'=>$range,'week_start'=>date('Y-m-d',$sun),
            'week_label'=>TeamOrder::weekLabel(date('Y-m-d',$sun),date('Y-m-d',$this_week))??$range,
            'prev_url'=>add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('-7 days',$sun))]),
            'next_url'=>add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('+7 days',$sun))]),
            'pdf_url'=>wp_nonce_url(add_query_arg(array_filter(['action'=>'ews_schedule_pdf','week'=>date('Y-m-d',$sun),'scope'=>$g['scope']==='department'?'department':null]),admin_url('admin-post.php')),'ews_schedule_pdf'),
            'scope'=>$g['scope'],'scope_urls'=>$g['scope']===''?[]:['team'=>remove_query_arg(['scope','pg','q']),'department'=>add_query_arg('scope','department',remove_query_arg(['pg','q']))],
            'pdf_name'=>'team-schedule-'.$dates[0].'.pdf',
            'email_url'=>$this->can('ews_view_reports')?wp_nonce_url(add_query_arg(['action'=>'ews31_report_email','start'=>$dates[0],'end'=>$week_end],admin_url('admin-post.php')),'ews31_report'):'',
            'email'=>$email,'swap_days'=>$swap_days,
            'swap_requests'=>$current_emp_id?$this->swap_requests_for_user($current_emp_id,$dates[0],$week_end):[],
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }
}
