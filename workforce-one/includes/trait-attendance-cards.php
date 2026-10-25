<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\CardLists;
use WorkforceOne\Attendance\Insights;
use WorkforceOne\Attendance\Lateness;

/**
 * Attendance cards you can open (3.31.125, docs/tasks/attendance-cards.md): the cards at the top of the manager Attendance page
 * (Employees, Present days, Late days, Absent days, Attendance rate) open a sheet with who and when, loaded when a card is
 * opened (admin-post.php?action=ews_att_cards, GET, JSON). The rows come from the page's own scope and loop
 * (attendance_scope(), attendance_walk()), so a list always counts what its card counts. Off by default
 * (ews_feature_attendance_cards); off, the page is as before and the endpoint answers nothing.
 * View: templates/app/attendance.php; script: assets/js/attendance-cards.js. Behaviour: tests/e2e_attendance_cards.py.
 */
trait EWS_Attendance_Cards_Trait {

    private function attendance_cards_enabled(){ return (bool)$this->option('ews_feature_attendance_cards'); }

    /**
     * What the page's sheet needs: where to load from, the filters the server applied (the list follows them), the chips,
     * and the links rows and "Open in Reports" go to (built here, on the page, as app_view_url() reads the current request).
     * @param array<string,mixed> $sc attendance_scope()
     * @return array<string,mixed>
     */
    private function attendance_cards_view(array $sc){
        $list=$sc['list'];$params=['week'=>date('Y-m-d',$sc['sun'])];
        // A short list is searched in the browser only (Ui\ListPage): the cards count everyone, and so does the list.
        if($list['paged']){if($list['q']!=='')$params['q']=$list['q'];if($list['team']!=='')$params['team']=$list['team'];}
        if($sc['branch_filter'])$params['branch']=$sc['branch_filter'];
        $chips=[date_i18n('j M',strtotime($sc['dates'][0])).' – '.date_i18n('j M Y',strtotime($sc['week_end']))];
        $chips[]=isset($params['team'])?(string)$params['team']:__('All teams','workforce-one');
        if(isset($params['branch']))$chips[]=(string)($sc['branch_filter_names'][$sc['branch_filter']]??'');
        if(isset($params['q']))$chips[]='“'.$params['q'].'”';
        // "Open in Reports" (owner decision 3): only with the Reports permission; the Reports have no branch or search filter.
        $reports='';
        if($this->can('ews_view_reports'))$reports=add_query_arg(['ews_view'=>'reports','report_type'=>'attendance','start'=>$sc['dates'][0],'end'=>$sc['week_end'],
            'team'=>$params['team']??'all','report_page'=>1],$this->app_view_url('reports'));
        $days=[];
        foreach($sc['dates'] as $d)$days[$d]=date_i18n('l j M',strtotime($d));
        return ['url'=>admin_url('admin-post.php'),'nonce'=>wp_create_nonce('ews_att_cards'),'params'=>$params,'chips'=>array_values(array_filter($chips,'strlen')),
            'reports_url'=>$reports,'reports_partial'=>$reports!=='' && (isset($params['branch'])||isset($params['q'])),
            // A row opens the person's profile (owner decision 2), for viewers who may open profiles.
            'profile_url'=>$this->app_view_allowed('employee')?add_query_arg('ews_view','employee',$this->app_view_url('people')):'',
            'days'=>$days];
    }

    /** A late Sign In: its minutes after the shift start and the shift it was measured against (sign_in_classification()'s shift). @return array{min:int|null,start:string,grace:int} */
    private function att_cards_late($employee_id,$in){
        $cfg=$this->working_hours((int)$employee_id,substr((string)$in->event_at,0,10));
        // An old "late_sign_in" event is Late whatever its time: shown without minutes (owner decision 9).
        $min=$in->event_type==='late_sign_in'?null:Lateness::minutesLate((int)strtotime((string)$in->event_at),(string)$cfg['start'],(string)$cfg['end'],!empty($cfg['overnight']));
        return ['min'=>$min,'start'=>(string)$cfg['start'],'grace'=>(int)$cfg['grace']];
    }

    /** The person in a row: name, team, avatar. @return array<string,mixed> */
    private function att_cards_person($e){
        return ['employee_id'=>(int)$e->id,'name'=>(string)$e->name,'sub'=>trim((string)($e->_schedule_primary_team??''))!==''?(string)$e->_schedule_primary_team:(string)($e->domain_name??''),
            'initials'=>\WorkforceOne\Employees\ProfileSummary::initials((string)$e->name)?:'·','tone'=>(int)$e->id%5,'picture'=>esc_url_raw((string)$this->employee_picture_url($e))];
    }

    /** admin-post.php?action=ews_att_cards (GET): one page of a card's list as JSON. Reads only. */
    public function att_cards_data(){
        nocache_headers();
        if(!$this->attendance_cards_enabled())wp_send_json_error(['reason'=>'off'],404);
        if(!is_user_logged_in()||!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce']??'')),'ews_att_cards'))wp_send_json_error(['reason'=>'nonce'],403);
        if(!$this->can('ews_manage_attendance'))wp_send_json_error(['reason'=>'access'],403);
        $kind=sanitize_key($_GET['kind']??'');
        if(!in_array($kind,CardLists::KINDS,true))wp_send_json_error(['reason'=>'kind'],400);
        $order=sanitize_key($_GET['order']??'day')==='employee'?'employee':'day';
        $offset=max(0,(int)($_GET['offset']??0));
        $sc=$this->attendance_scope(wp_unslash($_GET));
        $emps=$sc['matched'];$dates=$sc['dates'];$today=$sc['today'];$holidays=$sc['holidays'];
        // Shift times by day for everyone at once (late minutes, the end of today's shift).
        $this->prime_shift_cache($emps);
        $this->shift_prime_periods(array_map(function($e){return (int)$e->id;},$emps));
        $keys=[];$evs=[];$weeks=[];$day_counts=[];$card=['Present'=>0,'Late'=>0,'Absent'=>0];
        foreach($dates as $d)$day_counts[$d]=['present'=>0,'late'=>0,'absent'=>0];
        $this->attendance_walk($emps,$dates,$today,$holidays,function($pos,$e,$d,$planned,$day,$ev)use($kind,&$keys,&$evs,&$weeks,&$day_counts,&$card,$holidays){
            $a=$day['actual'];$eid=(int)$e->id;
            if(isset($card[$a])){$card[$a]++;$day_counts[$d][strtolower($a)]++;}
            if($kind==='people'||$kind==='rate'){$weeks[$eid]=CardLists::count($weeks[$eid]??CardLists::emptyWeek()+['pos'=>$pos],$a,isset($holidays[$d]));return;}
            if(!CardLists::accepts($kind,$a))return;
            $in=$ev['sign_in']??null;
            $sort=0;
            if($kind==='late'&&$in){$l=$this->att_cards_late($eid,$in);$sort=-(int)($l['min']??0);}
            elseif($kind==='present'&&$in)$sort=(int)strtotime((string)$in->event_at);
            $keys[]=['pos'=>$pos,'date'=>$d,'sort'=>$sort];
            $evs[$pos][$d]=['ev'=>$ev,'planned'=>$planned];
        });
        $out=['kind'=>$kind,'order'=>$order];
        if($kind==='rate'){
            $people=[];
            foreach($weeks as $eid=>$w)$people[$eid]=['pos'=>$w['pos'],'present'=>$w['present'],'late'=>$w['late'],'absent'=>$w['absent']];
            $lowest=[];
            foreach(CardLists::lowest($people) as $r)$lowest[]=$this->att_cards_person($emps[$r['pos']])+['rate'=>$r['rate'],'attended'=>$r['attended'],'required'=>$r['required']];
            wp_send_json_success($out+['card'=>Insights::rate($card['Present'],$card['Late'],$card['Absent']),'days'=>CardLists::rateByDay($day_counts),'lowest'=>$lowest]);
        }
        if($kind==='people'){
            $rows=[];foreach($emps as $pos=>$e)$rows[]=$pos;
            $page=CardLists::page($rows,$offset);
            $out['rows']=[];
            foreach($page['rows'] as $pos)$out['rows'][]=$this->att_cards_person($emps[$pos])+array_diff_key($weeks[(int)$emps[$pos]->id]??CardLists::emptyWeek(),['pos'=>0]);
            wp_send_json_success($out+['card'=>count($emps),'total'=>$page['total'],'offset'=>$page['offset'],'next'=>$page['next']]);
        }
        $page=CardLists::page(CardLists::order($keys,$order),$offset);
        $branch=null;
        if($kind==='absent'&&$this->branch_settings()['mode']==='schedule'&&count($this->branch_locations())>1){
            $ids=array_map(function($k)use($emps){return (int)$emps[$k['pos']]->id;},$page['rows']);
            $branch=['planned'=>$this->branch_planned_bulk($ids,array_values(array_unique(array_column($page['rows'],'date')))),'names'=>array_map(function($l){return (string)$l->name;},$this->branch_choices())];
        }
        $now=(int)current_time('timestamp');$rows=[];$shifts=[];
        foreach($page['rows'] as $k){
            $e=$emps[$k['pos']];$eid=(int)$e->id;$d=$k['date'];$ev=$evs[$k['pos']][$d]['ev'];$in=$ev['sign_in']??null;$outv=$ev['sign_out']??null;
            $row=$this->att_cards_person($e)+['date'=>$d];
            if($kind==='absent'){
                $planned=(string)$evs[$k['pos']][$d]['planned'];
                $row+=['plan'=>$planned,'plan_class'=>sanitize_title($planned!==''?$planned:'not-set')];
                if($branch&&$this->schedule_type_requires_location($planned)){$bid=($branch['planned'][$eid][$d]??0)?:$this->branch_main_id($eid);$row['branch']=(string)($branch['names'][$bid]??'');}
            }else{
                $end=null;
                if($d===$sc['today']){$h=$this->working_hours($eid,$d);$end=(int)strtotime($d.' '.$h['end'].':00');if(!empty($h['overnight'])&&$h['start']>$h['end'])$end+=DAY_IN_SECONDS;}
                $row+=['in'=>$in?date('H:i',strtotime((string)$in->event_at)):'','next_day'=>$in&&substr((string)$in->event_at,0,10)!==$d,
                    'out'=>$outv?date('H:i',strtotime((string)$outv->event_at)):null,
                    'missing_out'=>CardLists::missingOut((bool)$in,(bool)$outv,$d,$sc['today'],$now,$end)];
                if($kind==='late'&&$in){$l=$this->att_cards_late($eid,$in);$row['late_min']=$l['min'];$shifts[]=['start'=>$l['start'],'grace'=>$l['grace']];}
            }
            $rows[]=$row;
        }
        wp_send_json_success($out+['card'=>$card[ucfirst($kind)],'total'=>$page['total'],'offset'=>$page['offset'],'next'=>$page['next'],'rows'=>$rows,
            'note'=>$kind==='late'?CardLists::lateNote($shifts):null]);
    }
}
