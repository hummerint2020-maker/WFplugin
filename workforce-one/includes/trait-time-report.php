<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\ManualRecordRules;
use WorkforceOne\Support\Csv;

/**
 * wp-admin "Sign In / Out Report": the attendance log, manual records (add / edit), Reset Day and
 * the CSV export. Rules: src/Attendance/ManualRecordRules.php. Behaviour: tests/e2e_time_report.py.
 */
trait EWS_Time_Report_Trait {

    private function time_report_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-time-report')));
        exit;
    }

    /** The employee's Work Location (or the default one), as used by Sign In. @return array{0:mixed,1:mixed,2:float} */
    private function time_report_site($employee_id){
        $loc=$this->ews_v321_employee_location((int)$employee_id);
        if($loc)return [$loc->latitude,$loc->longitude,(float)($loc->radius?:200)];
        return [$this->option('ews_location_latitude'),$this->option('ews_location_longitude'),(float)$this->option('ews_location_radius')];
    }

    /**
     * Department scope (3.31.90): a manager sees and changes the records of their own department only,
     * archived employees included (their history); administrators see everyone. As the Attendance page.
     * The SQL to add to a query joined on employees as "e".
     */
    private function time_report_scope_sql(){
        if(current_user_can('manage_options'))return '';
        $dept=$this->current_department_id();
        global $wpdb;
        return $dept?$wpdb->prepare(' AND e.department_id=%d',$dept):' AND 1=0';
    }

    /** May the current user see / change this employee's records here (archived ones included)? */
    private function time_report_may_manage($employee_id){
        if(!$employee_id)return false;
        if(current_user_can('manage_options'))return true;
        $dept=$this->current_department_id();
        if(!$dept)return false;
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND department_id=%d",(int)$employee_id,$dept));
    }

    public function admin_time_report(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        global $wpdb;
        $start=sanitize_text_field(wp_unslash($_GET['start']??date('Y-m-01',current_time('timestamp'))));
        $end=sanitize_text_field(wp_unslash($_GET['end']??current_time('Y-m-d')));
        if(!$this->valid_date($start)||!$this->valid_date($end)){$start=date('Y-m-01',current_time('timestamp'));$end=current_time('Y-m-d');}
        // 100 records a page, with a search by employee (3.31.71: a month for 3,000 people was 30,000 rows in one page).
        $search=sanitize_text_field(wp_unslash($_GET['s']??''));$per=100;
        $where=$wpdb->prepare(" WHERE l.work_date BETWEEN %s AND %s",$start,$end).$this->time_report_scope_sql();
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=$wpdb->prepare(" AND (e.name LIKE %s OR e.domain_name LIKE %s)",$like,$like);}
        // With refused attempts kept (3.31.92): recorded, refused, or both in time order.
        $attempts=$this->attempts_enabled() && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->attempts_table()))===$this->attempts_table();
        $show=$attempts?sanitize_key($_GET['show']??'both'):'recorded';
        if(!in_array($show,['recorded','refused','both'],true))$show='both';
        $aw='';
        if($attempts){
            $aw=$wpdb->prepare(" WHERE a.work_date BETWEEN %s AND %s AND a.result<>'face_ok'",$start,$end).$this->time_report_scope_sql();
            if($search!=='')$aw.=$wpdb->prepare(" AND (e.name LIKE %s OR e.domain_name LIKE %s)",$like,$like);
        }
        $parts=[];
        if($show!=='refused')$parts[]="SELECT 'log' kind,l.id id,l.work_date d,l.event_at t FROM {$this->time_logs} l LEFT JOIN {$this->employees} e ON e.id=l.employee_id{$where}";
        if($show!=='recorded')$parts[]="SELECT 'attempt' kind,a.id id,a.work_date d,a.attempted_at t FROM {$this->attempts_table()} a LEFT JOIN {$this->employees} e ON e.id=a.employee_id{$aw}";
        $union=implode(' UNION ALL ',$parts);
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM ({$union}) x");
        $pages=max(1,(int)ceil($total/$per));$paged=min(max(1,absint($_GET['paged']??1)),$pages);
        $page_ids=(array)$wpdb->get_results($wpdb->prepare("SELECT kind,id FROM ({$union}) x ORDER BY d DESC,t DESC,id DESC LIMIT %d OFFSET %d",$per,($paged-1)*$per));
        $ids=['log'=>[],'attempt'=>[]];
        foreach($page_ids as $x)$ids[(string)$x->kind][]=(int)$x->id;
        $rows=[];$attempt_rows=[];
        if($ids['log']){
            $in=implode(',',array_map('intval',$ids['log']));
            foreach((array)$wpdb->get_results("SELECT l.*,e.name,e.domain_name,(SELECT MIN(c.correction_id) FROM {$this->time_logs} c WHERE c.corrects_id=l.id) replaced_by FROM {$this->time_logs} l LEFT JOIN {$this->employees} e ON e.id=l.employee_id WHERE l.id IN ($in)") as $r)$rows[(int)$r->id]=$r;
        }
        if($ids['attempt']){
            $in=implode(',',array_map('intval',$ids['attempt']));
            foreach((array)$wpdb->get_results("SELECT a.*,e.name,e.domain_name FROM {$this->attempts_table()} a LEFT JOIN {$this->employees} e ON e.id=a.employee_id WHERE a.id IN ($in)") as $r)$attempt_rows[(int)$r->id]=$r;
        }
        $edit_id=absint($_GET['edit_time_id']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE id=%d",$edit_id)):null;
        if($edit && !$this->time_report_may_manage((int)$edit->employee_id))$edit=null;
        $integrity_labels=['verified'=>'Verified','unreliable'=>'Unreliable','suspicious'=>'Suspicious','not_evaluated'=>'Not evaluated'];
        $radius_by_employee=[];
        $records=[];
        $range_url=add_query_arg(['start'=>$start,'end'=>$end],admin_url('admin.php?page=ews31-time-report'));
        $page_eids=array_map(function($r){return (int)$r->employee_id;},array_values($rows));
        $this->shift_prime_people($page_eids);   // the shift history too (3.31.126)
        $this->ews_v321_employee_locations_prime($page_eids);   // each record's Work Location radius (3.31.126)
        foreach($page_ids as $x){
            if($x->kind==='attempt'){
                $a=$attempt_rows[(int)$x->id]??null;
                if(!$a)continue;
                $records[]=$this->evidence_row($a)+['record_url'=>in_array($a->result,['refused','not_reached'],true)&&$a->event_type!=='face'?$this->evidence_record_url($a,$range_url):''];
                continue;
            }
            $r=$rows[(int)$x->id]??null;
            if(!$r)continue;
            $eid=(int)$r->employee_id;
            if(!isset($radius_by_employee[$eid]))$radius_by_employee[$eid]=(int)$this->time_report_site($eid)[2];
            $integrity=$r->integrity_status?:'not_evaluated';
            $records[]=[
                'kind'=>'log',
                'id'=>(int)$r->id,'employee_id'=>$eid,'work_date'=>$r->work_date,'name'=>(string)$r->name,'domain'=>(string)$r->domain_name,
                'scheduled'=>(string)$r->scheduled_status,'event'=>$this->time_event_label($r->event_type),
                'status'=>$r->event_type==='sign_in'?$this->sign_in_classification($r->event_at,(int)$r->employee_id):($r->event_type==='late_sign_in'?'Late Arrival':'—'),
                'time'=>date_i18n('Y-m-d h:i A',strtotime($r->event_at)),
                'location'=>($r->latitude!==null&&$r->longitude!==null)?$r->latitude.', '.$r->longitude:'Not available',
                'distance'=>$r->distance_meters!==null?$this->format_distance((float)$r->distance_meters):'Not available',
                'radius'=>$radius_by_employee[$eid].' m',
                'result'=>$r->location_status?ucfirst($r->location_status):'Not available',
                'accuracy'=>$r->accuracy!==null?round((float)$r->accuracy,1).' m':'—',
                'integrity'=>$integrity_labels[$integrity]??ucfirst(str_replace('_',' ',$integrity)),'integrity_key'=>$integrity,
                'integrity_reason'=>$r->integrity_reason?ucwords(str_replace('_',' ',$r->integrity_reason)):'',
                'ip'=>(string)($r->ip_address??'')!==''?(string)$r->ip_address:'—',   // shown since 3.31.92 (it was saved, never shown)
                // 3.31.74: where the event came from; a correction shows next to the original, which stays.
                'source'=>$this->time_source_label((string)($r->source??'')),'correction_id'=>(int)($r->correction_id??0),'replaced_by'=>(int)($r->replaced_by??0),
                'photo'=>!empty($r->photo_key)&&$this->face_photo_may_view($eid)?$this->face_photo_url('log',(int)$r->id):'',
                'photo_full'=>!empty($r->photo_key)&&$this->face_photo_may_view($eid)?$this->face_photo_url('log',(int)$r->id,true):'',
            ];
        }
        // How many were refused in the period, by employee (the summary above the table).
        $refused_summary=null;
        if($attempts){
            $by=(array)$wpdb->get_results("SELECT a.employee_id,e.name,COUNT(*) n FROM {$this->attempts_table()} a LEFT JOIN {$this->employees} e ON e.id=a.employee_id{$aw} AND a.result IN ('refused','face_failed','not_reached') GROUP BY a.employee_id,e.name ORDER BY n DESC,e.name ASC LIMIT 6");
            $refused_summary=['total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->attempts_table()} a LEFT JOIN {$this->employees} e ON e.id=a.employee_id{$aw} AND a.result IN ('refused','face_failed','not_reached')"),'by'=>$by];
        }
        $timeline=isset($_GET['timeline'])&&$attempts?$this->evidence_timeline(absint($_GET['timeline']),sanitize_text_field(wp_unslash($_GET['tdate']??''))):null;
        $error=sanitize_key($_GET['time_error']??'');
        $form=null;
        if(isset($_GET['add_time'])||$edit){
            // "Record it manually" on a refused attempt fills in its employee, event and time (3.31.92).
            $pre_emp=absint($_GET['prefill_employee']??0);
            $pre_type=in_array($_GET['prefill_type']??'',['sign_in','sign_out'],true)?sanitize_key($_GET['prefill_type']):'sign_in';
            $pre_date=$this->valid_date(sanitize_text_field(wp_unslash($_GET['prefill_date']??'')))?sanitize_text_field(wp_unslash($_GET['prefill_date'])):$start;
            $pre_at=preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/',(string)($_GET['prefill_at']??''))?str_replace('T',' ',(string)$_GET['prefill_at']):$pre_date.' 09:00';
            if($pre_emp && !$this->time_report_may_manage($pre_emp))$pre_emp=0;
            $form=[
                'id'=>$edit?(int)$edit->id:0,'employee_id'=>$edit?(int)$edit->employee_id:$pre_emp,
                'work_date'=>$edit?$edit->work_date:$pre_date,'event_type'=>$edit?$edit->event_type:$pre_type,
                'event_at'=>str_replace(' ','T',$edit?substr($edit->event_at,0,16):$pre_at),
                'latitude'=>$edit&&$edit->latitude!==null?$edit->latitude:'','longitude'=>$edit&&$edit->longitude!==null?$edit->longitude:'',
                'accuracy'=>$edit&&$edit->accuracy!==null?$edit->accuracy:'',
            ];
        }
        echo $this->render_template('admin/time-report',[
            'start'=>$start,'end'=>$end,'records'=>$records,'form'=>$form,'search'=>$search,'paged'=>$paged,'pages'=>$pages,'total'=>$total,
            'employees'=>(array)$wpdb->get_results("SELECT e.id,e.name,e.domain_name FROM {$this->employees} e WHERE (e.active=1".($form&&$form['employee_id']?$wpdb->prepare(" OR e.id=%d",$form['employee_id']):'').")".$this->time_report_scope_sql()." ORDER BY e.name ASC"),
            'event_types'=>ManualRecordRules::EVENT_TYPES,
            'saved'=>($_GET['time_notice']??'')==='saved',
            'reset'=>isset($_GET['time_reset'])?[absint($_GET['time_reset']),absint($_GET['break_reset']??0)]:null,
            'error'=>$error!==''?ManualRecordRules::errorMessage($error):null,
            'page_url'=>admin_url('admin.php?page=ews31-time-report'),
            'post_url'=>admin_url('admin-post.php'),
            'csv_url'=>wp_nonce_url(add_query_arg(['action'=>'ews31_time_csv','start'=>$start,'end'=>$end],admin_url('admin-post.php')),'ews31_time_csv'),
            'attempts'=>$attempts,'show'=>$show,'refused_summary'=>$refused_summary,'timeline'=>$timeline,'photos'=>$this->face_photo_enabled(),
        ]);
    }

    public function admin_time_save(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        check_admin_referer('ews31_time_save');
        $id=absint($_POST['time_id']??0);
        $employee_id=absint($_POST['employee_id']??0);
        $work_date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
        $type=sanitize_key($_POST['event_type']??'');
        $event_at=ManualRecordRules::eventAt(sanitize_text_field(wp_unslash($_POST['event_at']??'')));
        $date_valid=$this->valid_date($work_date);
        $back=['start'=>$date_valid?$work_date:current_time('Y-m-d')];$back['end']=$back['start'];
        $num=function($key){$v=sanitize_text_field(wp_unslash($_POST[$key]??''));return ($v!==''&&is_numeric($v))?(float)$v:null;};
        $lat=$num('latitude');$lng=$num('longitude');$acc=$num('accuracy');
        // The checks, the geofence and the write: ManualRecordService, in the order they always ran.
        $r=$this->services()->manualRecords()->save($id,$employee_id,$work_date,$type,$event_at,$date_valid,$lat,$lng,$acc,$this->time_event_label($type),get_current_user_id());
        if($r['error'])$this->time_report_redirect($back+['time_error'=>$r['error']]);
        $this->time_report_redirect($back+['time_notice'=>'saved']);
    }

    public function admin_time_reset(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        check_admin_referer('ews31_time_reset');
        $employee_id=absint($_POST['employee_id']??0);
        $date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
        if(!$employee_id||!$this->valid_date($date))$this->time_report_redirect(['time_error'=>'invalid']);
        if(!$this->time_report_may_manage($employee_id))$this->time_report_redirect(['start'=>$date,'end'=>$date,'time_error'=>'scope']);
        [$deleted,$break_deleted]=$this->services()->manualRecords()->reset($employee_id,$date);
        $this->time_report_redirect(['start'=>$date,'end'=>$date,'time_reset'=>$deleted,'break_reset'=>$break_deleted]);
    }

    public function admin_time_csv(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        check_admin_referer('ews31_time_csv');
        $start=sanitize_text_field(wp_unslash($_GET['start']??''));
        $end=sanitize_text_field(wp_unslash($_GET['end']??''));
        if(!$this->valid_date($start)||!$this->valid_date($end)||$end<$start)wp_die('Invalid date range.');
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT l.*,e.name,e.domain_name,(SELECT MIN(c.correction_id) FROM {$this->time_logs} c WHERE c.corrects_id=l.id) replaced_by FROM {$this->time_logs} l LEFT JOIN {$this->employees} e ON e.id=l.employee_id WHERE l.work_date BETWEEN %s AND %s".$this->time_report_scope_sql()." ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC",
            $start,$end
        ));
        nocache_headers();
        header('Content-Type:text/csv; charset=utf-8');
        header('Content-Disposition:attachment; filename=sign_in_out_'.$start.'_to_'.$end.'.csv');
        $f=fopen('php://output','w');
        fprintf($f,"\xEF\xBB\xBF");
        fputcsv($f,['Date','Weekday','Employee','Domain','Scheduled Status','Event','Attendance Status','Time','Latitude','Longitude','Distance','Radius','Location Result','GPS Accuracy','Integrity','Integrity Reason','IP address','Source','Correction','Replaced by correction']);
        $radius=[];
        $this->shift_prime_people(array_map(function($r){return (int)$r->employee_id;},(array)$rows));   // the shift history too (3.31.126)
        $this->ews_v321_employee_locations_prime(array_map(function($r){return (int)$r->employee_id;},(array)$rows));   // 3.31.126
        foreach((array)$rows as $r){
            $eid=(int)$r->employee_id;
            if(!isset($radius[$eid]))$radius[$eid]=(int)$this->time_report_site($eid)[2].' m';
            fputcsv($f,Csv::row([
                $r->work_date,date_i18n('l',strtotime($r->work_date)),$r->name,$r->domain_name,$r->scheduled_status,
                $this->time_event_label($r->event_type),
                $r->event_type==='sign_in'?$this->sign_in_classification($r->event_at,(int)$r->employee_id):($r->event_type==='late_sign_in'?'Late Arrival':''),
                date_i18n('Y-m-d h:i A',strtotime($r->event_at)),
                $r->latitude!==null?$r->latitude:'',$r->longitude!==null?$r->longitude:'',
                $r->distance_meters!==null?$this->format_distance((float)$r->distance_meters):'',
                $radius[$eid],$r->location_status?:'Not available',
                $r->accuracy!==null?round((float)$r->accuracy,1).' m':'',
                $r->integrity_status?:'not_evaluated',$r->integrity_reason?:'',(string)($r->ip_address??''),
                $this->time_source_label((string)($r->source??'')),(int)($r->correction_id??0)?'#'.(int)$r->correction_id:'',(int)($r->replaced_by??0)?'#'.(int)$r->replaced_by:'',
            ]));
        }
        fclose($f);
        exit;
    }

    /** "App", "QR", "Correction"… for the Source column (3.31.74; older rows have none and came from the app). */
    private function time_source_label($source){
        $l=['app'=>'App','qr'=>'QR','kiosk'=>'Kiosk','auto'=>'Automatic','import'=>'Import','correction'=>'Correction','admin'=>'Admin'];
        return $l[$source]??($source===''?'App':ucfirst($source));
    }
}
