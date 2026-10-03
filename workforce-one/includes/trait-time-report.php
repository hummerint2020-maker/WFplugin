<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\LocationAssessment;
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
        return [get_option('ews_location_latitude',''),get_option('ews_location_longitude',''),(float)get_option('ews_location_radius',200)];
    }

    public function admin_time_report(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        global $wpdb;
        $start=sanitize_text_field(wp_unslash($_GET['start']??date('Y-m-01',current_time('timestamp'))));
        $end=sanitize_text_field(wp_unslash($_GET['end']??current_time('Y-m-d')));
        if(!$this->valid_date($start)||!$this->valid_date($end)){$start=date('Y-m-01',current_time('timestamp'));$end=current_time('Y-m-d');}
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT l.*,e.name,e.domain_name FROM {$this->time_logs} l LEFT JOIN {$this->employees} e ON e.id=l.employee_id WHERE l.work_date BETWEEN %s AND %s ORDER BY l.work_date DESC,l.event_at DESC",$start,$end));
        $edit_id=absint($_GET['edit_time_id']??0);
        $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->time_logs} WHERE id=%d",$edit_id)):null;
        $integrity_labels=['verified'=>'Verified','unreliable'=>'Unreliable','suspicious'=>'Suspicious','not_evaluated'=>'Not evaluated'];
        $radius_by_employee=[];
        $records=[];
        foreach($rows as $r){
            $eid=(int)$r->employee_id;
            if(!isset($radius_by_employee[$eid]))$radius_by_employee[$eid]=(int)$this->time_report_site($eid)[2];
            $integrity=$r->integrity_status?:'not_evaluated';
            $records[]=[
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
            ];
        }
        $error=sanitize_key($_GET['time_error']??'');
        $form=null;
        if(isset($_GET['add_time'])||$edit){
            $form=[
                'id'=>$edit?(int)$edit->id:0,'employee_id'=>$edit?(int)$edit->employee_id:0,
                'work_date'=>$edit?$edit->work_date:$start,'event_type'=>$edit?$edit->event_type:'sign_in',
                'event_at'=>str_replace(' ','T',$edit?substr($edit->event_at,0,16):$start.' 09:00'),
                'latitude'=>$edit&&$edit->latitude!==null?$edit->latitude:'','longitude'=>$edit&&$edit->longitude!==null?$edit->longitude:'',
                'accuracy'=>$edit&&$edit->accuracy!==null?$edit->accuracy:'',
            ];
        }
        echo $this->render_template('admin/time-report',[
            'start'=>$start,'end'=>$end,'records'=>$records,'form'=>$form,
            'employees'=>(array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1".($form&&$form['employee_id']?$wpdb->prepare(" OR id=%d",$form['employee_id']):'')." ORDER BY name ASC"),
            'event_types'=>ManualRecordRules::EVENT_TYPES,
            'saved'=>($_GET['time_notice']??'')==='saved',
            'reset'=>isset($_GET['time_reset'])?[absint($_GET['time_reset']),absint($_GET['break_reset']??0)]:null,
            'error'=>$error!==''?ManualRecordRules::errorMessage($error):null,
            'page_url'=>admin_url('admin.php?page=ews31-time-report'),
            'post_url'=>admin_url('admin-post.php'),
            'csv_url'=>wp_nonce_url(add_query_arg(['action'=>'ews31_time_csv','start'=>$start,'end'=>$end],admin_url('admin-post.php')),'ews31_time_csv'),
        ]);
    }

    public function admin_time_save(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        check_admin_referer('ews31_time_save');
        global $wpdb;
        $id=absint($_POST['time_id']??0);
        $employee_id=absint($_POST['employee_id']??0);
        $work_date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
        $type=sanitize_key($_POST['event_type']??'');
        $event_at=ManualRecordRules::eventAt(sanitize_text_field(wp_unslash($_POST['event_at']??'')));
        $back=['start'=>$this->valid_date($work_date)?$work_date:current_time('Y-m-d')];$back['end']=$back['start'];
        if($id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->time_logs} WHERE id=%d",$id)))$this->time_report_redirect($back+['time_error'=>'not_found']);
        // A new record needs an active employee; an existing record may belong to an archived one.
        $emp=$employee_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d".($id?'':' AND active=1'),$employee_id)):null;
        $facts=['type'=>$type,'date_valid'=>$this->valid_date($work_date),'work_date'=>$work_date,'event_at'=>$event_at,'employee_found'=>(bool)$emp];
        if(!ManualRecordRules::check($facts)){
            $others=$wpdb->get_results($wpdb->prepare("SELECT event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND id<>%d AND event_type IN ('sign_in','late_sign_in','sign_out')",$employee_id,$work_date,$id));
            $facts['others']=array_map(function($o){return ['type'=>$o->event_type,'at'=>$o->event_at];},(array)$others);
        }
        if($error=ManualRecordRules::check($facts))$this->time_report_redirect($back+['time_error'=>$error]);

        $num=function($key){$v=sanitize_text_field(wp_unslash($_POST[$key]??''));return ($v!==''&&is_numeric($v))?(float)$v:null;};
        $lat=$num('latitude');$lng=$num('longitude');$acc=$num('accuracy');
        [$site_lat,$site_lng,$radius]=$this->time_report_site($employee_id);
        [$location_status,$distance]=LocationAssessment::geofence($lat,$lng,$site_lat,$site_lng,$radius);
        $sch=$this->schedule_for_employee_date($employee_id,$work_date);
        $data=['employee_id'=>$employee_id,'work_date'=>$work_date,'event_type'=>$type,'event_at'=>$event_at,'scheduled_status'=>$sch?$sch->status:'Not Scheduled','latitude'=>$lat,'longitude'=>$lng,'accuracy'=>$acc,'location_status'=>$location_status,'distance_meters'=>$distance];
        $label=$this->time_event_label($type);
        if($id){
            $ok=$wpdb->update($this->time_logs,$data,['id'=>$id]);
            $action='time_manual_edit';$detail='Admin edited '.$label.' for '.$emp->name.' on '.$work_date;
        }else{
            $data['user_id']=$emp->wp_user_id?(int)$emp->wp_user_id:get_current_user_id();
            $ok=$wpdb->insert($this->time_logs,$data);$id=(int)$wpdb->insert_id;
            $action='time_manual_add';$detail='Admin added '.$label.' for '.$emp->name.' on '.$work_date;
        }
        if($ok===false){
            $this->audit($action.'_failed','time_log',$id,$wpdb->last_error?:'Database error');
            $this->time_report_redirect($back+['time_error'=>'save']);
        }
        $this->audit($action,'time_log',$id,$detail);
        $this->time_report_redirect($back+['time_notice'=>'saved']);
    }

    public function admin_time_reset(){
        if(!$this->can('ews_manage_time'))wp_die('Access denied');
        check_admin_referer('ews31_time_reset');
        global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        $date=sanitize_text_field(wp_unslash($_POST['work_date']??''));
        if(!$employee_id||!$this->valid_date($date))$this->time_report_redirect(['time_error'=>'invalid']);
        $deleted=(int)$wpdb->query($wpdb->prepare("DELETE FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s",$employee_id,$date));
        // Breaks belong to the attendance day too: clear them (and their pending reminders).
        $this->ensure_break_schema();
        $break_table=$wpdb->prefix.'ews_break_sessions';
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$break_table} WHERE employee_id=%d AND work_date=%s",$employee_id,$date)) as $break_id){
            wp_clear_scheduled_hook('ews_break_duration_reminder',[(int)$break_id]);
            wp_clear_scheduled_hook('ews_break_manager_escalation',[(int)$break_id]);
        }
        $break_deleted=(int)$wpdb->query($wpdb->prepare("DELETE FROM {$break_table} WHERE employee_id=%d AND work_date=%s",$employee_id,$date));
        $this->audit('time_reset','time_log',$employee_id,'Reset attendance and break records for '.$date.' (time_logs='.$deleted.', breaks='.$break_deleted.')');
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
            "SELECT l.*,e.name,e.domain_name FROM {$this->time_logs} l LEFT JOIN {$this->employees} e ON e.id=l.employee_id WHERE l.work_date BETWEEN %s AND %s ORDER BY l.work_date ASC,e.name ASC,l.event_at ASC",
            $start,$end
        ));
        nocache_headers();
        header('Content-Type:text/csv; charset=utf-8');
        header('Content-Disposition:attachment; filename=sign_in_out_'.$start.'_to_'.$end.'.csv');
        $f=fopen('php://output','w');
        fprintf($f,"\xEF\xBB\xBF");
        fputcsv($f,['Date','Weekday','Employee','Domain','Scheduled Status','Event','Attendance Status','Time','Latitude','Longitude','Distance','Radius','Location Result','GPS Accuracy','Integrity','Integrity Reason']);
        $radius=[];
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
                $r->integrity_status?:'not_evaluated',$r->integrity_reason?:'',
            ]));
        }
        fclose($f);
        exit;
    }
}
