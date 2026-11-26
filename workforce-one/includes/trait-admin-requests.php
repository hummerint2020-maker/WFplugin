<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Requests\Hub;

/**
 * wp-admin "Requests Hub": every pending request in one table, decided by an administrator.
 * Each decision calls the owning module's operation (leave, overtime, early leave, swap, face
 * reset), so it behaves exactly like a decision taken elsewhere.
 * Presentation rules: src/Requests/Hub.php. Behaviour: tests/e2e_admin_requests.py and the
 * "admin Requests page" checks of the module tests.
 */
trait EWS_Admin_Requests_Trait {

    /** @return array<int,array<string,mixed>> Pending requests, oldest first, ready for the template. */
    private function admin_request_rows(){
        global $wpdb;
        $rows=[];
        $names=[];
        $deferred=[];
        // $ar is the request's key [workflow, entity, id]: the approval requests are found together after the last row (3.31.112).
        // $stage: a correction's own stage (pending | pending_hr), posted with the decision like the level (3.31.122).
        $add=function($type,$id,$employee_id,$title,$requested_at,$detail,$ar=null,$stage='')use(&$rows,&$deferred){
            $key=$type.':'.(int)$id;
            if(isset($rows[$key]))return;
            $rows[$key]=true;
            $deferred[$key]=[$type,$id,$employee_id,$title,$requested_at,$detail,$ar,$stage];
        };

        $lt=$wpdb->prefix.'ews_leave_requests';$types=$wpdb->prefix.'ews_leave_types';
        foreach((array)$wpdb->get_results("SELECT r.*,lt.name type_name FROM {$lt} r LEFT JOIN {$types} lt ON lt.id=r.leave_type_id WHERE r.status='Pending' ORDER BY r.requested_at ASC") as $r){
            $add('leave',$r->id,$r->employee_id,'Vacation / '.$r->type_name,$r->requested_at,Hub::details($r->start_date.' → '.$r->end_date,(float)$r->requested_days.' day(s)',(string)$r->reason),['vacation','leave',(int)$r->id]);
        }
        foreach((array)$wpdb->get_results("SELECT r.*,lt.name type_name FROM {$lt} r LEFT JOIN {$types} lt ON lt.id=r.leave_type_id WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC") as $r){
            $add('leave_cancellation',$r->id,$r->employee_id,'Vacation Cancellation / '.$r->type_name,$r->requested_at,$r->start_date.' → '.$r->end_date,['vacation','leave_cancellation',(int)$r->id]);
        }
        $ot=$wpdb->prefix.'ews_overtime_requests';
        if($this->admin_requests_table_exists($ot)){
            foreach((array)$wpdb->get_results("SELECT * FROM {$ot} WHERE status='Pending' ORDER BY requested_at ASC") as $r){
                $add('overtime',$r->id,$r->employee_id,'Overtime',$r->requested_at,Hub::details($r->overtime_date,substr($r->start_time,0,5).' → '.substr($r->end_time,0,5),Hub::duration((int)$r->requested_minutes),(string)$r->reason),['overtime','overtime_request',(int)$r->id]);
            }
        }
        foreach((array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_early_leave_requests WHERE status='Pending' ORDER BY requested_at ASC") as $r){
            $add('early_leave',$r->id,$r->employee_id,'Early Leave',$r->requested_at,Hub::details($r->work_date,Hub::duration((int)$r->leave_minutes),(string)$r->reason));
        }
        foreach($this->swap_pending_rows() as $r){
            // Waiting for the approvers: the column shows the current level, the one an administrator's decision takes (3.31.120).
            $add('shift_swap',$r->id,$r->requester_employee_id,'Shift Swap',$r->created_at,Hub::details($r->work_date,$r->requester_status.' ↔ '.$r->target_status),$r->status==='Awaiting'?['shift_swap','schedule_swap',(int)$r->id]:null);
        }
        $legacy=$wpdb->prefix.'ews_vacation_requests';
        if($this->admin_requests_table_exists($legacy)){
            foreach((array)$wpdb->get_results("SELECT * FROM {$legacy} WHERE status='Pending' ORDER BY requested_at ASC") as $r){
                $add('legacy_vacation',$r->id,$r->employee_id,'Legacy Vacation',$r->requested_at??'',Hub::details($r->start_date.' → '.$r->end_date,(float)$r->requested_days.' day(s)',(string)($r->reason??'')));
            }
        }
        foreach($this->cx_hub_rows() as $r){
            $add('correction',$r->id,$r->employee_id,'Attendance Correction',$r->requested_at,Hub::details($r->work_date,$this->cx_type_label((string)$r->type),$this->cx_change_text($r,'en'),(string)$r->reason,$r->status==='pending_hr'?'Second level (HR)':''),['attendance_correction','attendance_correction',(int)$r->id],(string)$r->status);
        }
        foreach($this->hr_letter_hub_rows() as $r){   // HR letters (HL3, owner decision 1): waiting for a decision; no salary amount
            $add('hr_letter',$r->id,$r->employee_id,'HR Letter',$r->requested_at,Hub::details((string)$r->type_label.((string)$r->type_key==='other'?': '.(string)$r->other_text:'').' · '.((string)$r->language==='ar'?'Arabic':'English'),'to '.(string)$r->addressee,(int)$r->with_salary?'shows the salary':''),['hr_letter','hr_letter',(int)$r->id]);
        }
        foreach($this->sc_hub_rows() as $row){   // schedule change requests (3.31.165): waiting for a decision
            $r=$row['request'];
            $add('schedule_change',$r->id,$r->requester_employee_id,'Schedule Change',$r->requested_at,$this->sc_hub_details($row),['schedule_change','schedule_change',(int)$r->id]);
        }
        foreach($this->face_reset_requests() as $employee_id=>$r){
            if(!is_array($r)||($r['status']??'')!=='pending')continue;
            $add('face_reset',$employee_id,$employee_id,'Face Reset',$r['requested_at']??'',$r['current_note']??'Reset requested',['face_reset','face_reset_request',(int)$employee_id]);
        }
        // The requests, their current levels and the names for the whole list at once (3.31.112).
        $found=$this->approval_find_requests(array_values(array_filter(array_column($deferred,6))));
        $current=$this->approval_current_steps(array_map(function($ar){return (int)$ar->id;},array_values($found)));
        $briefs=$this->people_brief_by_ids(array_column($deferred,2));
        $rows=[];
        foreach($deferred as $key=>[$type,$id,$employee_id,$title,$requested_at,$detail,$ref,$stage]){
            $employee_id=(int)$employee_id;
            if(!array_key_exists($employee_id,$names)){
                $name=$employee_id&&isset($briefs[$employee_id])?$briefs[$employee_id]->name:null;
                $names[$employee_id]=$name!==null?(string)$name:'Employee #'.$employee_id;
            }
            $ar=$ref?($found[$this->approval_request_key($ref[0],$ref[1],$ref[2])]??null):null;
            $step=$ar?($current[(int)$ar->id]??null):null;
            $rows[$key]=[
                'type'=>$type,'id'=>(int)$id,'title'=>$title,'employee'=>$names[$employee_id],'detail'=>$detail,
                'approval'=>Hub::approvalLabel($type,$ar?(int)$ar->id:0,$step?(int)$step->step_order:null,$step&&$step->approver_wp_user_id,$step&&!empty($step->rerouted),$step?\WorkforceOne\Approvals\AdminReason::of($step):''),
                'requested_at'=>(string)$requested_at,
                // The level this row shows (0: none open), so a decision never lands on a later one (3.31.122).
                'step'=>$step?(int)$step->id:0,'stage'=>(string)$stage,
                'round'=>$type==='leave_cancellation'?$this->leave_round_token((int)$id):0,   // Leave return (3.31.161): the change this row shows
                'nonce'=>wp_create_nonce('ews_admin_request_decision_'.$type.'_'.(int)$id),
            ];
        }
        return Hub::sortOldestFirst(array_values($rows));
    }

    private function admin_requests_table_exists($table){
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))===$table;
    }

    public function admin_requests(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $notice_key=sanitize_key($_GET['request_notice']??'');
        if(!$this->hub_reports_enabled()){
            echo $this->render_template('admin/requests',[
                'rows'=>$this->admin_request_rows(),
                'notice'=>Hub::hubNotice($notice_key),'notice_is_error'=>Hub::isErrorNotice($notice_key),
                'post_url'=>admin_url('admin-post.php'),
            ]);
            return;
        }
        // Requests Hub reports (3.31.159): two tabs, the first is today's page.
        $rows=$this->admin_request_rows();
        $tab=sanitize_key(wp_unslash($_GET['tab']??''))==='reports'?'reports':'pending'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $tabs=$this->admin_requests_tabs($tab,count($rows));
        if($tab==='reports'){
            echo $this->render_template('admin/requests-reports',$this->admin_requests_report_data()+['tabs'=>$tabs]);
            return;
        }
        echo $this->render_template('admin/requests',[
            'rows'=>$rows,
            'notice'=>Hub::hubNotice($notice_key),'notice_is_error'=>Hub::isErrorNotice($notice_key),
            'post_url'=>admin_url('admin-post.php'),'tabs'=>$tabs,
        ]);
    }

    /** Requests Hub reports (3.31.159, docs/tasks/hub-reports.md): off by default. */
    public function hub_reports_enabled(){
        return (bool)$this->option('ews_feature_hub_reports');
    }

    /** @return list<array{key:string,label:string,url:string,count:int,current:bool}> */
    private function admin_requests_tabs($current,$waiting){
        $base=admin_url('admin.php?page=ews31-requests');
        return [
            ['key'=>'pending','label'=>__('Waiting for a decision','workforce-one'),'url'=>$base,'count'=>(int)$waiting,'current'=>$current==='pending'],
            ['key'=>'reports','label'=>__('Reports','workforce-one'),'url'=>add_query_arg('tab','reports',$base),'count'=>0,'current'=>$current==='reports'],
        ];
    }

    /** The Reports tab's filter from the address. */
    private function admin_requests_report_filter(){
        $get=[];
        foreach(['from','to','type','status','who','paged'] as $k)if(isset($_GET[$k]))$get[$k]=sanitize_text_field(wp_unslash((string)$_GET[$k])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only filter
        return \WorkforceOne\Requests\HubReport::filter($get,current_time('Y-m-d'));
    }

    /** The face resets in the filter (they are kept in an option, one per employee). */
    private function admin_requests_report_faces(array $f){
        if(!in_array('face_reset',$f['types'],true))return [];
        $entries=$this->face_reset_requests();
        $only=null;
        if($f['department']){
            $only=[];
            foreach($this->people_by_ids(array_keys($entries)) as $id=>$e)if((int)$e->department_id===(int)$f['department'])$only[(int)$id]=true;
        }
        return \WorkforceOne\Requests\HubReport::faces($entries,$f,current_time('Y-m-d'),$only);
    }

    /** Everything the Reports tab shows. */
    private function admin_requests_report_data(){
        $R=\WorkforceOne\Requests\HubReport::class;
        $f=$this->admin_requests_report_filter();
        $today=current_time('Y-m-d');
        $q=$this->services()->hubReportQuery();
        $faces=$this->admin_requests_report_faces($f);
        $counts=$q->counts($f,$today);
        $tiles=$R::tiles($counts,$faces);
        $statusKey=$f['status']?$R::STATUSES[$f['status']]:'all';
        $facesShown=$R::facesOfStatus($faces,$f['status']);
        $total=$tiles[$statusKey];
        $per=20;$pages=max(1,(int)ceil($total/$per));$page=min($f['page'],$pages);$offset=($page-1)*$per;
        // With face resets in the list the read model gives every key up to this page, merged with them; else just the page.
        $keys=$facesShown?$R::merge($q->keys($f,$today,$offset+$per,0),$facesShown,$offset,$per):$q->keys($f,$today,$per,$offset);
        $base=['page'=>'ews31-requests','tab'=>'reports','from'=>$f['from'],'to'=>$f['to'],'type'=>$f['type'],'status'=>$f['status']?$statusKey:'','who'=>$f['who']];
        $base=array_filter($base,function($v){return $v!=='';});
        $export=[];
        foreach(['csv','xlsx'] as $fmt)$export[$fmt]=wp_nonce_url(add_query_arg(array_merge($base,['action'=>'ews31_requests_export','format'=>$fmt]),admin_url('admin-post.php')),'ews31_requests_export');
        return [
            'filter'=>$f,'status_key'=>$statusKey,'tiles'=>$tiles,'rows'=>$this->admin_requests_report_rows($keys,false),
            'total'=>$total,'page'=>$page,'pages'=>$pages,
            'page_url'=>function($n)use($base){return add_query_arg(array_merge($base,['paged'=>(int)$n]),admin_url('admin.php'));},
            'tile_url'=>function($key)use($base){$a=$base;unset($a['status']);if($key!=='all')$a['status']=$key;return add_query_arg($a,admin_url('admin.php'));},
            'export'=>$export,'types'=>$this->admin_requests_report_types(),'statuses'=>$this->admin_requests_report_statuses(),
            'departments'=>$this->people_department_names(),'people'=>$this->services()->hubReportQuery()->people(),
            'form_url'=>admin_url('admin.php'),
        ];
    }

    /** @return array<string,string> */
    private function admin_requests_report_types(){
        return ['leave'=>__('Leave','workforce-one'),'leave_cancellation'=>__('Leave cancellation','workforce-one'),'overtime'=>__('Overtime','workforce-one'),
            'early_leave'=>__('Early leave','workforce-one'),'shift_swap'=>__('Shift swap','workforce-one'),'correction'=>__('Attendance correction','workforce-one'),
            'hr_letter'=>__('HR letter','workforce-one'),'face_reset'=>__('Face reset','workforce-one')];
    }

    /** @return array<string,string> */
    private function admin_requests_report_statuses(){
        return ['waiting'=>__('Waiting','workforce-one'),'approved'=>__('Approved','workforce-one'),'rejected'=>__('Rejected','workforce-one'),
            'withdrawn'=>__('Withdrawn','workforce-one'),'cancelled'=>__('Cancelled','workforce-one')];
    }

    /**
     * The rows of these keys, in their order: title, employee, details, status, requested, decided (who · when), note.
     * Each type's rows, the approval requests and their steps, and the names are read once for all the keys.
     * @param list<array{t:int,id:int,at:string}> $keys
     * @return list<array<string,mixed>>
     */
    private function admin_requests_report_rows(array $keys,$export){
        $R=\WorkforceOne\Requests\HubReport::class;
        $today=current_time('Y-m-d');
        $q=$this->services()->hubReportQuery();
        $ids=[];
        foreach($keys as $k)$ids[$R::typeName($k['t'])][]=(int)$k['id'];
        $rows=[];
        foreach($ids as $type=>$list){
            if($type==='face_reset'){$faces=$this->face_reset_requests();foreach($list as $id)$rows[$type][$id]=(object)((array)($faces[$id]??[])+['id'=>$id,'employee_id'=>$id]);continue;}
            $rows[$type]=$q->rows($type,$list);
        }
        // The approval request of each row (the engine's workflow and entity), then their steps.
        $refs=['leave'=>['vacation','leave'],'leave_cancellation'=>['vacation','leave_cancellation'],'overtime'=>['overtime','overtime_request'],'shift_swap'=>['shift_swap','schedule_swap'],
            'correction'=>['attendance_correction','attendance_correction'],'hr_letter'=>['hr_letter','hr_letter'],'face_reset'=>['face_reset','face_reset_request']];
        $want=[];
        foreach($rows as $type=>$byId)if(isset($refs[$type]))foreach($byId as $id=>$r)$want[]=[$refs[$type][0],$refs[$type][1],(int)$id];
        $found=$want?$this->approval_find_requests($want):[];
        $steps=$found?$this->services()->approvalReads()->stepsOf(array_map(function($ar){return (int)$ar->id;},array_values($found))):[];
        // Names: the employees (archived ones too) and the people who decided.
        $emp_ids=[];$user_ids=[];
        foreach($rows as $type=>$byId)foreach($byId as $r){
            $emp_ids[]=(int)($r->employee_id??$r->requester_employee_id??0);
            if($type==='shift_swap')$emp_ids[]=(int)$r->target_employee_id;
            foreach(['reviewed_by','cancelled_by','decided_by','responded_by','handled_by','manager_by','issued_by'] as $c)if(!empty($r->$c))$user_ids[]=(int)$r->$c;
        }
        foreach($steps as $list)foreach($list as $st){if(!empty($st->acted_by_wp_user_id))$user_ids[]=(int)$st->acted_by_wp_user_id;if(!empty($st->approver_wp_user_id))$user_ids[]=(int)$st->approver_wp_user_id;}
        $people=$emp_ids?$this->people_by_ids($emp_ids):[];
        $user_ids=array_values(array_unique(array_filter($user_ids)));
        $by_user=$user_ids?$this->people_names_by_user($user_ids):[];
        $missing=array_values(array_diff($user_ids,array_keys($by_user)));
        if($missing){cache_users($missing);foreach($missing as $u){$wu=get_userdata($u);$by_user[$u]=$wu?(string)$wu->display_name:'';}}
        $depts=$export?$this->people_department_names():[];
        $emp_name=function($id)use($people){
            $id=(int)$id;if(!$id||!isset($people[$id]))return $id?sprintf(__('Employee #%d','workforce-one'),$id):'—';
            $n=(string)$people[$id]->name;
            return (int)$people[$id]->active?$n:sprintf(__('%s (archived)','workforce-one'),$n);
        };
        $user_name=function($u)use($by_user){$u=(int)$u;return $u&&($by_user[$u]??'')!==''?$by_user[$u]:'';};
        $time=function($at){$at=(string)$at;return $at===''||strpos($at,'0000')===0?'':substr($at,0,16);};
        $out=[];
        foreach($keys as $k){
            $type=$R::typeName($k['t']);$r=$rows[$type][(int)$k['id']]??null;
            if(!$r)continue;
            $ar=isset($refs[$type])?($found[$this->approval_request_key($refs[$type][0],$refs[$type][1],(int)$k['id'])]??null):null;
            $o=$R::outcome($ar?($steps[(int)$ar->id]??[]):[]);
            $row=$this->admin_requests_report_row($type,$r,$ar,$o,$R::status($type,(array)$r,$today),$emp_name,$user_name,$time);
            $row['requested']=$time($k['at']);
            $eid=(int)($r->employee_id??$r->requester_employee_id??0);
            $row['dept']=$export&&isset($people[$eid])?(string)($depts[(int)$people[$eid]->department_id]??''):'';
            $out[]=$row;
        }
        return $out;
    }

    /**
     * One row's texts. $o is the approval request's outcome (HubReport::outcome); deciders fall back on each table's own columns.
     * @return array{type:string,title:string,employee:string,detail:string,status:int,by:string,at:string,note:string}
     */
    private function admin_requests_report_row($type,$r,$ar,array $o,$status,callable $emp_name,callable $user_name,callable $time){
        $R=\WorkforceOne\Requests\HubReport::class;
        $by='';$at='';$note='';
        // The engine's decision when there is one; "Automatically" when the request was closed with no level decided by hand.
        $engine=function()use($o,$ar,$user_name,$time,&$by,&$at,&$note){
            $final=$ar?strtoupper((string)$ar->status):'';
            if($o['decision']!==''&&(!$ar||$final===$o['decision'])){
                $by=$user_name($o['by']);
                if($by!==''&&$o['for']&&$user_name($o['for'])!=='')$by=sprintf(__('%1$s for %2$s','workforce-one'),$by,$user_name($o['for']));
                if($by!==''&&$o['levels']>1)$by.=' '.sprintf(__('(level %d)','workforce-one'),$o['level']);
                $at=$time($o['at']);
                if($o['decision']==='REJECTED'&&$o['comment']!=='')$note=$o['comment'];
                return true;
            }
            if($ar&&in_array($final,['APPROVED','REJECTED'],true)){$by=__('Automatically','workforce-one');$at=$time($ar->completed_at??'');return true;}
            return false;
        };
        $level=function()use($o,$ar){
            if(!$ar||!$o['levels'])return '';
            return $o['current']?sprintf(__('Level %1$d of %2$d','workforce-one'),$o['current'],$o['levels']):'';
        };
        $direct=function($u,$when)use($user_name,$time,&$by,&$at){$by=$user_name($u);$at=$time($when);};
        $employee=$emp_name($r->employee_id??$r->requester_employee_id??0);
        $title='';$detail='';
        switch($type){
            case 'leave':
            case 'legacy_vacation':
                $title=$type==='leave'?sprintf(__('Leave / %s','workforce-one'),(string)($r->type_name??'')):__('Leave (old)','workforce-one');
                $detail=Hub::details($r->start_date.' → '.$r->end_date,sprintf(__('%s day(s)','workforce-one'),(float)$r->requested_days));
                if($status===$R::WITHDRAWN){$direct($r->cancelled_by??0,$r->cancelled_at??'');$note=__('Withdrawn by the employee','workforce-one');break;}
                if($status!==$R::WAITING&&!$engine())$direct($r->reviewed_by??0,$r->reviewed_at??'');
                if($status===$R::WAITING)$note=$level();
                if($status===$R::CANCELLED&&$type==='leave')$note=__('Cancelled at the employee\'s request','workforce-one');
                if($status===$R::APPROVED&&$type==='leave'){
                    $cs=(string)($r->cancellation_status??'');
                    if($cs==='Approved'&&!empty($r->cancel_from))$note=sprintf(__('Shortened: now ends on %s','workforce-one'),$r->end_date);
                    elseif($cs==='Pending')$note=__('A cancellation is waiting','workforce-one');
                }
                break;
            case 'leave_cancellation':
                $title=sprintf(__('Leave cancellation / %s','workforce-one'),(string)($r->type_name??''));
                $detail=Hub::details(!empty($r->cancel_from)&&(string)$r->cancel_from>(string)$r->start_date?sprintf(__('From %s','workforce-one'),$r->cancel_from):__('All of it','workforce-one'),$r->start_date.' → '.$r->end_date);
                $cs=(string)$r->cancellation_status;
                if($status===$R::WAITING)$note=$level();
                elseif($status===$R::WITHDRAWN){$by=$employee;$at=$time($ar->completed_at??'');$note=__('Withdrawn by the employee','workforce-one');}
                elseif(!$engine()){if($status===$R::APPROVED)$direct($r->cancelled_by??0,$r->cancelled_at??'');}
                if($cs==='Ended')$note=__('Decided after the leave had ended: nothing changed','workforce-one');
                if(!$ar||empty($ar->created_at))$note=trim($note.($note!==''?' · ':'').__('Date not recorded','workforce-one'));
                break;
            case 'overtime':
            case 'early_leave':
                $title=$type==='overtime'?__('Overtime','workforce-one'):__('Early leave','workforce-one');
                $detail=$type==='overtime'
                    ?Hub::details((string)$r->overtime_date,substr((string)$r->start_time,0,5).' → '.substr((string)$r->end_time,0,5),Hub::duration((int)$r->requested_minutes))
                    :Hub::details((string)$r->work_date,Hub::duration((int)$r->leave_minutes));
                if($status===$R::WAITING)$note=$level();
                elseif($status===$R::WITHDRAWN){$direct($r->reviewed_by??0,$r->reviewed_at??'');if($by==='')$by=$employee;$note=__('Withdrawn by the employee','workforce-one');}
                elseif(!$engine())$direct($r->reviewed_by??0,$r->reviewed_at??'');
                break;
            case 'shift_swap':
                $title=__('Shift swap','workforce-one');
                $detail=Hub::details((string)$r->work_date,$r->requester_status.' ↔ '.$r->target_status,sprintf(__('with %s','workforce-one'),$emp_name($r->target_employee_id)));
                $st=(string)$r->status;
                if($status===$R::WAITING)$note=$st==='Pending'?__('Waiting for the colleague','workforce-one'):$level();
                elseif($status===$R::WITHDRAWN){$direct($r->responded_by??0,$r->responded_at??'');if($by==='')$by=$employee;$note=__('Withdrawn by the employee','workforce-one');}
                elseif($status===$R::CANCELLED)$note=__('The day passed','workforce-one');
                elseif($status===$R::REJECTED&&empty($r->decided_by)&&!$o['decision']){$direct($r->responded_by??0,$r->responded_at??'');$note=__('Declined by the colleague','workforce-one');}
                elseif(!$engine()){if(!empty($r->decided_by))$direct($r->decided_by,$r->decided_at??'');else $direct($r->responded_by??0,$r->responded_at??'');}
                break;
            case 'correction':
                $title=__('Attendance correction','workforce-one');
                $detail=Hub::details((string)$r->work_date,$this->cx_type_label((string)$r->type),$this->cx_change_text($r));
                if($status===$R::WAITING)$note=(string)$r->status==='pending_hr'?__('Second level (HR)','workforce-one'):$level();
                elseif($status===$R::WITHDRAWN){$by=$employee;$at=$time($r->decided_at??'');$note=__('Withdrawn by the employee','workforce-one');}
                else{
                    if(!empty($r->decided_by)){$direct($r->decided_by,$r->decided_at??'');if($by!==''&&!empty($r->manager_by)&&((int)$r->manager_by!==(int)$r->decided_by||(int)($r->needs_hr??0)))$by.=' '.__('(HR)','workforce-one');}
                    elseif(!$engine()){$by=__('Automatically','workforce-one');$at=$time($r->decided_at??'');}
                    $note=trim((string)($r->decision_note??''));
                }
                break;
            case 'hr_letter':
                $title=__('HR letter','workforce-one');
                $detail=Hub::details((string)$r->type_label.((string)$r->type_key==='other'?': '.(string)$r->other_text:''),(string)$r->language==='ar'?__('Arabic','workforce-one'):__('English','workforce-one'),sprintf(__('to %s','workforce-one'),(string)$r->addressee));
                $st=(string)$r->status;
                if($status===$R::WAITING){$note=$level();break;}
                if($status===$R::WITHDRAWN){$by=$employee;$at=$time($r->decided_at??'');$note=__('Withdrawn by the employee','workforce-one');break;}
                if(!$engine())$at=$time($r->decided_at??'');
                if($st==='approved')$note=__('Approved, not issued yet','workforce-one');
                elseif($st==='issued')$note=sprintf(__('Issued %s','workforce-one'),$time($r->issued_at??''));
                elseif($st==='ready')$note=__('Ready to collect','workforce-one');
                elseif($st==='cancelled')$note=trim((string)$r->decision_note)!==''?sprintf(__('Cancelled by HR: %s','workforce-one'),trim((string)$r->decision_note)):__('Cancelled by HR','workforce-one');
                elseif($st==='rejected'&&trim((string)$r->decision_note)!=='')$note=trim((string)$r->decision_note);
                break;
            default: // face_reset
                $title=__('Face reset','workforce-one');
                $detail=(string)($r->current_note??'')!==''?(string)$r->current_note:__('Reset requested','workforce-one');
                if($status===$R::WAITING)$note=$level();
                elseif($ar&&$o['decision']===''&&in_array(strtoupper((string)$ar->status),['APPROVED','REJECTED'],true))$engine();   // closed by the workflow itself
                else{
                    $direct($r->handled_by??0,$r->handled_at??'');
                    if($by==='')$engine();
                    // The approval is this request's when its deciding level was taken by the same person.
                    if($status===$R::REJECTED&&$o['decision']==='REJECTED'&&$o['comment']!==''&&(int)$o['by']===(int)($r->handled_by??0))$note=$o['comment'];
                }
        }
        if($by===''&&$status!==$R::WAITING&&$at!=='')$by='—';
        return ['type'=>$type,'title'=>$title,'employee'=>$employee,'detail'=>$detail,'status'=>(int)$status,'by'=>$by,'at'=>$at,'note'=>$note];
    }

    /** CSV or Excel of the whole filtered list (every page), newest first, read in batches (3.31.159). */
    public function admin_requests_export(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        check_admin_referer('ews31_requests_export');
        if(!$this->hub_reports_enabled())wp_die(esc_html__('Requests Hub reports are switched off.','workforce-one'));
        $R=\WorkforceOne\Requests\HubReport::class;
        $f=$this->admin_requests_report_filter();
        $today=current_time('Y-m-d');
        $fmt=sanitize_key(wp_unslash($_GET['format']??''))==='xlsx'?'xlsx':'csv';
        $q=$this->services()->hubReportQuery();
        $faces=$this->admin_requests_report_faces($f);
        $tiles=$R::tiles($q->counts($f,$today),$faces);
        $facesShown=$R::facesOfStatus($faces,$f['status']);
        $statuses=$this->admin_requests_report_statuses();
        $headers=[__('Request','workforce-one'),__('Employee','workforce-one'),__('Department','workforce-one'),__('Details','workforce-one'),__('Status','workforce-one'),__('Requested','workforce-one'),__('Decided by','workforce-one'),__('Decided at','workforce-one'),__('Note','workforce-one')];
        $spool=new \WorkforceOne\Support\RowSpool();
        $size=max(1,(int)apply_filters('ews_hub_export_batch',2000));
        $after=null;$fi=0;
        try{
            do{
                $batch=$q->keys($f,$today,$size,0,$after);
                if($batch){$last=end($batch);$after=[$last['at'],$last['t'],$last['id']];}
                // The face resets that come before the end of this batch go in with it, in order.
                $take=[];$final=count($batch)<$size;
                while($fi<count($facesShown)&&($final||$R::newerFirst($facesShown[$fi]['at'],$R::TYPE_CODES['face_reset'],$facesShown[$fi]['id'],$after[0],$after[1],$after[2])<0)){$take[]=$facesShown[$fi];$fi++;}
                $keys=$take?$R::merge($batch,$take,0,count($batch)+count($take)):$batch;
                if(!$keys)break;
                foreach($this->admin_requests_report_rows($keys,true) as $row){
                    $spool->add([$row['title'],$row['employee'],$row['dept']??'',$row['detail'],$statuses[$R::STATUSES[$row['status']]]??'',$row['requested'],$row['by'],$row['at'],$row['note']]);
                }
                \WorkforceOne\Support\ExportTime::extend();
            }while(count($batch)===$size);
        }catch(\RuntimeException $x){wp_die(esc_html__('The export could not be completed. Please try again.','workforce-one'));}
        $type_label=$f['type']!==''?($this->admin_requests_report_types()[$f['type']]??$f['type']):__('All types','workforce-one');
        $status_label=$f['status']?$statuses[$R::STATUSES[$f['status']]]:__('All statuses','workforce-one');
        $audit=function()use($fmt,$f,$type_label,$status_label,$spool){$this->audit('requests_report_export','report',0,sprintf('%s · %s to %s · type: %s · status: %s · who: %s · rows: %d',$fmt==='xlsx'?'Excel':'CSV',$f['from'],$f['to'],$type_label,$status_label,$f['who']!==''?$f['who']:'all',$spool->count()));};
        $name='requests_'.$f['from'].'_to_'.$f['to'];
        if($fmt==='xlsx'){
            $kpis=[__('Requests','workforce-one')=>$tiles['all']];
            foreach(['approved','rejected','withdrawn','cancelled','waiting'] as $k)$kpis[$statuses[$k]]=$tiles[$k];   // the screen's order
            $xlsx=$this->report_xlsx_from_table(['headers'=>$headers,'rows'=>$spool,'kpis'=>$kpis,'title'=>__('Requests','workforce-one'),
                'subtitle'=>$type_label.' · '.$status_label,'widths'=>[26,22,18,40,12,17,24,17,40],'definitions'=>false],'requests',$f['from'],$f['to']);
            if(is_wp_error($xlsx))wp_die(esc_html($xlsx->get_error_message()));
            $audit();
            \WorkforceOne\Support\Download::send($xlsx,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',$name.'.xlsx');exit;
        }
        $out=fopen('php://temp/maxmemory:2097152','w+');
        if($out===false||!fputcsv($out,\WorkforceOne\Support\Csv::row($headers)))wp_die(esc_html__('The export could not be completed. Please try again.','workforce-one'));
        foreach($spool as $r)if(!fputcsv($out,\WorkforceOne\Support\Csv::row($r)))wp_die(esc_html__('The export could not be completed. Please try again.','workforce-one'));
        rewind($out);
        $audit();
        nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename='.$name.'.csv');echo "\xEF\xBB\xBF";fpassthru($out);fclose($out);exit;
    }

    public function admin_requests_decision(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $type=sanitize_key($_POST['request_type']??'');$id=absint($_POST['request_id']??0);$decision=sanitize_key($_POST['decision']??'');
        if(!$id||!Hub::isDecision($decision)||!in_array($type,Hub::TYPES,true))wp_die('Invalid request.');
        check_admin_referer('ews_admin_request_decision_'.$type.'_'.$id);
        switch($type){
            case 'leave':              $outcome=$this->leave_admin_decide($id,false,$decision);break;
            case 'leave_cancellation': $outcome=$this->leave_admin_decide($id,true,$decision);break;
            case 'overtime':           $outcome=$this->overtime_admin_decide($id,$decision);break;
            case 'early_leave':        $outcome=$this->early_leave_admin_decide($id,$decision);break;
            case 'shift_swap':         $outcome=$this->swap_admin_decide($id,$decision);break;
            case 'legacy_vacation':    $outcome=$this->legacy_vacation_admin_decide($id,$decision);break;
            case 'correction':         $outcome=$this->cx_hub_decide($id,$decision);break;
            case 'hr_letter':          $outcome=$this->hr_letter_hub_decide($id,$decision);break;
            case 'schedule_change':    $outcome=$this->sc_hub_decide($id,$decision);break;
            default:                   $outcome=$this->face_reset_decide($id,$decision,true);
        }
        wp_safe_redirect(add_query_arg('request_notice',$outcome,admin_url('admin.php?page=ews31-requests')));
        exit;
    }

    /**
     * Requests from the pre-3.x vacation table (ews_vacation_requests), still decidable here.
     * Approval marks each working day as Vacation; all writes succeed or none do.
     */
    private function legacy_vacation_admin_decide($id,$decision){
        global $wpdb;
        $table=$wpdb->prefix.'ews_vacation_requests';
        $r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND status='Pending' LIMIT 1",$id));
        if(!$r)return Hub::ERROR;
        $emp=$this->people_employee((int)$r->employee_id);
        $approve=$decision==='approve';
        $now=current_time('mysql');$uid=get_current_user_id();
        $written=[];
        if($wpdb->query('START TRANSACTION')===false)return Hub::ERROR;
        $ok=$wpdb->update($table,['status'=>$approve?'Approved':'Rejected','reviewed_by'=>$uid,'reviewed_at'=>$now],['id'=>$id,'status'=>'Pending'],['%s','%d','%s'],['%d','%s'])===1;
        if($ok && $approve && $emp){
            $note='Approved legacy vacation request #'.$id;
            for($ts=strtotime($r->start_date);$ok && $ts<=strtotime($r->end_date);$ts=strtotime('+1 day',$ts)){
                $date=date('Y-m-d',$ts);
                if(!$this->is_working_day($date))continue;
                $existing=$this->schedule_for_employee_date((int)$emp->id,$date);$written[]=$date;
                $ok=($existing
                    ?$wpdb->update($this->schedule,['status'=>'Vacation','note'=>$note,'updated_by'=>$uid,'updated_at'=>$now],['id'=>$existing->id])
                    :$wpdb->insert($this->schedule,['employee_id'=>(int)$emp->id,'work_date'=>$date,'status'=>'Vacation','note'=>$note,'updated_by'=>$uid,'updated_at'=>$now],['%d','%s','%s','%s','%d','%s']))!==false;
            }
        }
        if($ok && $approve && $emp)$this->week_lock_record_request((int)$emp->id,$written,'leave','legacy vacation #'.(int)$id);   // week lock (3.31.121)
        if(!$ok || $wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return Hub::ERROR;}
        if($emp && $emp->wp_user_id)$this->notify((int)$emp->wp_user_id,'vacation',$approve?'Vacation Approved':'Vacation Rejected',$approve?'Your vacation request has been approved.':'Your vacation request has been rejected.',['entity_id'=>$id,'push'=>false]);
        return Hub::outcome($decision);
    }
}
