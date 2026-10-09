<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\GridRules;
use WorkforceOne\Attendance\Insights;

/**
 * The manager Attendance grid in the employee app (?ews_view=attendance): plan the week's schedule
 * and see each day's result. Day results use the same rules as Attendance Insights (insights_day()).
 * Saving and the CSV import live in trait-attendance.php (att_grid_save, att_preview, att_import).
 * View: templates/app/attendance.php; script: assets/js/attendance-grid.js. Behaviour: tests/e2e_attendance_grid.py.
 */
trait EWS_Attendance_Grid_Trait {

    private function attendance_content(){
        if(!$this->can('ews_manage_attendance'))return $this->ews_empty_state('Attendance unavailable','You do not have permission to manage attendance.');
        $current_emp_id=(int)($this->current_employee()->id??0);
        $emps=$this->schedule_employees_ordered(array_values(array_filter($this->department_scoped_employees(),function($e){return !isset($e->attendance_enabled) || (int)$e->attendance_enabled===1;})),$current_emp_id);
        [$dates,$sun]=$this->week_dates();
        $today=current_time('Y-m-d');
        if(!$dates)$dates=[$today];
        $week_end=$dates[count($dates)-1];
        $holidays=$this->company_leave_dates($dates[0],$week_end);
        // A long list is searched, filtered by team and paged on the server (Ui\ListPage, 3.31.71): the
        // cards and day counts count everyone the search and filter keep; the grid shows this page.
        $all_emps=$emps;
        // Branch filter (3.31.72): people whose main or other branches include it.
        $branch_filter=0;$branch_filter_names=[];
        if(count($this->branch_locations())>1&&$this->branch_settings()['mode']!=='single'){
            $branch_filter_names=array_map(function($l){return (string)$l->name;},$this->branch_choices());
            $branch_filter=absint($_GET['branch']??0);
            if($branch_filter&&isset($branch_filter_names[$branch_filter])){
                $ids=array_map(function($e){return (int)$e->id;},$emps);$others=$this->branch_other_ids_bulk($ids);
                $emps=array_values(array_filter($emps,function($e)use($branch_filter,$others){return $this->branch_main_id((int)$e->id)===$branch_filter||in_array($branch_filter,$others[(int)$e->id]??[],true);}));
            }else $branch_filter=0;
        }
        $list=\WorkforceOne\Ui\ListPage::apply($emps,wp_unslash($_GET));
        $on_page=array_flip(array_map(function($e){return (int)$e->id;},$list['rows']));
        $emps=$list['matched'];
        [$schedule,$events]=$this->insights_load(array_map(function($e){return (int)$e->id;},$emps),$dates[0],$week_end);

        // One result per employee and day, plus the summary cards and per-day counts.
        $summary=array_fill_keys(GridRules::SUMMARY,0);
        $day_summary=[];$days=[];
        foreach($dates as $d)$day_summary[$d]=['recorded'=>0,'absent'=>0];
        foreach($emps as $e){
            $eid=(int)$e->id;
            foreach($dates as $d){
                $planned=$schedule[$eid][$d]??'';
                $day=$this->insights_day($eid,$planned,$events[$eid][$d]??[],$d,$today,$holidays);
                if(isset($summary[$day['actual']]))$summary[$day['actual']]++;
                if(in_array($day['actual'],['Present','Late'],true))$day_summary[$d]['recorded']++;
                if($day['actual']==='Absent')$day_summary[$d]['absent']++;
                if(isset($on_page[$eid]))$days[$eid][$d]=['planned'=>$planned,'actual'=>$day['actual'],'badge'=>GridRules::badge($day['actual'],isset($holidays[$d])?(string)$holidays[$d]:$day['time'])];
            }
        }
        $emps=$list['rows'];
        $team_options=[];
        foreach($all_emps as $e){
            foreach(array_merge((array)($e->_schedule_team_names??[]),[(string)($e->_schedule_primary_team??'')]) as $team_name){
                $team_name=trim((string)$team_name);
                if($team_name!=='')$team_options[$team_name]=$team_name;
            }
        }
        natcasesort($team_options);

        $preview_token=sanitize_text_field(wp_unslash($_GET['preview']??''));
        $preview_rows=$preview_token!==''?get_transient('ews31_preview_'.$preview_token.'_'.get_current_user_id()):false;
        wp_enqueue_script('workforce-one-attendance-grid');
        wp_enqueue_style('workforce-one-attendance-page');
        $people=[];
        foreach($emps as $e)$people[(int)$e->id]=['picture'=>$this->employee_picture_url($e),'initials'=>\WorkforceOne\Employees\ProfileSummary::initials((string)$e->name)?:'·'];
        // Branches "By the schedule" (3.31.72): each Office day carries a branch, one of the employee's.
        $branch=null;
        if($this->branch_settings()['mode']==='schedule'&&count($this->branch_locations())>1){
            $page_ids=array_map(function($e){return (int)$e->id;},$emps);
            $choices=$this->branch_choices();$others=$this->branch_other_ids_bulk($page_ids);$mine=[];$main=[];
            foreach($page_ids as $eid){$main[$eid]=$this->branch_main_id($eid);$mine[$eid]=array_values(array_filter(array_unique(array_merge([$main[$eid]],$others[$eid]??[])),function($id)use($choices){return isset($choices[$id]);}));}
            $branch=['names'=>array_map(function($l){return (string)$l->name;},$choices),'planned'=>$this->branch_planned_bulk($page_ids,$dates),'mine'=>$mine,'main'=>$main,
                'statuses'=>array_values(array_filter($this->schedule_type_names(true),function($st){return $this->schedule_type_requires_location($st);}))];
        }
        $office_min=$this->om_attendance($dates);
        return $this->render_template('app/attendance',[
            'office_min'=>$office_min,'office_min_html'=>$office_min?$this->render_template('app/office-minimum',['om'=>$office_min]):'',
            'people'=>$people,
            'emps'=>$emps,'list'=>$list,'branch'=>$branch,'branch_filter'=>$branch_filter,'branch_filter_names'=>$branch_filter_names,'list_keep'=>$this->list_keep_args('attendance'),'dates'=>$dates,'today'=>$today,'current_emp_id'=>$current_emp_id,'days'=>$days,
            'summary'=>$summary,'day_summary'=>$day_summary,'team_options'=>array_values($team_options),
            'rate'=>Insights::rate($summary['Present'],$summary['Late'],$summary['Absent']),
            'statuses'=>$this->schedule_type_names(true),
            'message'=>GridRules::message(wp_unslash($_GET)),
            'week'=>date('Y-m-d',$sun),'range'=>date('d',strtotime($dates[0])).' – '.date('d M Y',strtotime($week_end)),
            'prev_url'=>add_query_arg(['ews_view'=>'attendance','week'=>date('Y-m-d',strtotime('-7 days',$sun))]),
            'next_url'=>add_query_arg(['ews_view'=>'attendance','week'=>date('Y-m-d',strtotime('+7 days',$sun))]),
            'preview_token'=>$preview_token,'preview_rows'=>is_array($preview_rows)?$preview_rows:null,
            'preview_cancel_url'=>remove_query_arg('preview'),
            'sample_url'=>wp_nonce_url(admin_url('admin-post.php?action=ews31_att_sample'),'ews31_att_sample'),
            'post_url'=>admin_url('admin-post.php'),
        ]);
    }
}
