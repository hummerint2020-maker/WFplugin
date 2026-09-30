<?php
if (!defined('ABSPATH')) exit;

trait EWS_Attendance_Trait {

    public function time_event(){
            if(!is_user_logged_in())wp_die('Please log in.');
            $type=sanitize_key($_POST['event_type']??'');
            if(!in_array($type,['sign_in','sign_out'],true))wp_die('Invalid event.');
            check_admin_referer('ews_time_event_'.$type);
            $this->record_time_event($type,['face_ok'=>$this->face_consume_token(wp_unslash($_POST['face_token']??''))]);
        }

    /*
     * Shared Sign In / Sign Out recorder. Callers are responsible for their own
     * nonce check. $ctx:
     *   face_ok     bool  A server-issued face token was consumed for this request.
     *   qr_kiosk    obj   Kiosk row validated from a dynamic QR (QR Sign-In only).
     *   qr_location obj   Work location of that kiosk; the employee's own GPS must be inside it.
     */
    private function record_time_event($type,$ctx=[]){
            $face_ok=!empty($ctx['face_ok']);
            $qr_kiosk=$ctx['qr_kiosk']??null;
            $qr_location=$ctx['qr_location']??null;
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id)){ $this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(__('Attendance tracking is disabled for your employee profile.','workforce-one'))]);return; }
            $sch=$emp?$this->today_schedule_for_employee($emp->id):null;$ev=$emp?$this->today_events($emp->id):[];
            $assigned_location_v321=$emp?$this->ews_v321_employee_location($emp->id):null;
            $is_general_leave=$sch && $this->company_leave_dates(current_time('Y-m-d'),current_time('Y-m-d'));
            $requires_sign_in=$sch?$this->schedule_type_requires_sign_in($sch->status):false;
            $requires_location=$sch?$this->schedule_type_requires_location($sch->status):false;
            $msg='';
            if(!$msg && $this->face_signin_enabled() && $type==='sign_in' && !$face_ok)$msg=__('Face verification is required before Sign In. Please complete Face Verification and try again.','workforce-one');
            if(!$emp)$msg=__('Your WordPress account is not linked to an active employee.','workforce-one');
            elseif($is_general_leave)$msg=__('Today is a General Leave day. Sign In is not required.','workforce-one');
            elseif(!$sch||!$requires_sign_in)$msg=__('Today is not a working day for you.','workforce-one');
            elseif($type==='sign_in'&&!$this->sign_in_window_open($emp?$emp->id:0)){
                $h=$this->working_hours($emp?$emp->id:0); $bounds=$this->sign_in_window_bounds($emp?$emp->id:0); $now_ts=current_time('timestamp');
                if($bounds['start'] && $now_ts<$bounds['start'])$msg=sprintf(/* translators: %s: start time */__('Sign In is not available yet. Your working hours start at %s.','workforce-one'),date_i18n('g:i A',$bounds['start']));
                else $msg=sprintf(/* translators: %s: cutoff time */__('Sign In is no longer available. The Sign In cutoff was %s.','workforce-one'),$bounds['cutoff']?date_i18n('g:i A',$bounds['cutoff']):$this->format_time_label($h['end']));
            }
            elseif($type==='sign_in'&&(isset($ev['sign_in'])||isset($ev['late_sign_in'])))$msg=__('You have already signed in today.','workforce-one');
            elseif($type==='sign_out'&&$this->break_enabled()&&$this->break_open_session($emp->id))$msg=__('Please Resume Work before signing out.','workforce-one');
            elseif($type==='sign_out'&&!isset($ev['sign_in'])&&!isset($ev['late_sign_in']))$msg=__('You cannot sign out before signing in.','workforce-one');
            elseif($type==='sign_out'&&isset($ev['sign_out']))$msg=__('You have already signed out today.','workforce-one');
            if($msg){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode($msg)]);return;}
            global $wpdb;$now=current_time('mysql');
            $lat=isset($_POST['latitude'])&&is_numeric($_POST['latitude'])?(float)$_POST['latitude']:null;
            $lng=isset($_POST['longitude'])&&is_numeric($_POST['longitude'])?(float)$_POST['longitude']:null;
            $acc=isset($_POST['accuracy'])&&is_numeric($_POST['accuracy'])?(float)$_POST['accuracy']:null;
            $location_timestamp=isset($_POST['location_timestamp'])&&is_numeric($_POST['location_timestamp'])?(int)$_POST['location_timestamp']:null;
            $integrity_status='unreliable';$integrity_reason='location_not_available';
            if($lat!==null&&$lng!==null){
                $integrity_status='verified';$integrity_reason='';
                if($acc===null){$integrity_status='unreliable';$integrity_reason='missing_accuracy';}
                elseif($acc>100){$integrity_status='unreliable';$integrity_reason='low_accuracy';}
                if($location_timestamp===null){$integrity_status='unreliable';$integrity_reason=$integrity_reason?:'missing_timestamp';}
                elseif(abs((int)round($location_timestamp/1000)-current_time('timestamp'))>300){$integrity_status='unreliable';$integrity_reason='stale_timestamp';}
                $prev=$wpdb->get_row($wpdb->prepare("SELECT latitude,longitude,location_timestamp,event_at FROM {$this->time_logs} WHERE employee_id=%d AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY event_at DESC LIMIT 1",(int)$emp->id));
                if($prev && $location_timestamp!==null && $prev->location_timestamp){
                    $elapsed=max(1,abs($location_timestamp-(int)$prev->location_timestamp)/1000);
                    if($elapsed<=7200){
                        $move=$this->location_distance_meters((float)$prev->latitude,(float)$prev->longitude,$lat,$lng);
                        if($move!==null && ($move/$elapsed)*3.6>180){$integrity_status='suspicious';$integrity_reason='impossible_movement';}
                    }
                }
            }
            $location_status=($lat!==null&&$lng!==null)?'recorded':'not_available';
            $configured_lat=$assigned_location_v321?$assigned_location_v321->latitude:get_option('ews_location_latitude','');$configured_lng=$assigned_location_v321?$assigned_location_v321->longitude:get_option('ews_location_longitude','');
            $distance=$this->location_distance_meters($lat,$lng,$configured_lat,$configured_lng);
            $radius=$assigned_location_v321?(float)$assigned_location_v321->radius:(float)get_option('ews_location_radius',200);
            if($distance!==null&&$configured_lat!==''&&$configured_lng!=='')$location_status=$distance<=$radius?'inside':'outside';
            if($qr_location){
                // A QR can be photographed and forwarded, so QR Sign-In additionally requires the
                // employee's own device location to be inside the kiosk's work location.
                $qr_distance=$this->location_distance_meters($lat,$lng,$qr_location->latitude,$qr_location->longitude);
                $qr_radius=(float)($qr_location->radius?:200);
                if(!is_numeric($qr_location->latitude)||!is_numeric($qr_location->longitude)){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(__('This Kiosk location has no coordinates configured. Please contact your administrator.','workforce-one'))]);return;}
                if($qr_distance===null||$integrity_reason==='stale_timestamp'){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(__('Location access is required for QR Sign In. Please allow Location Services and try again.','workforce-one'))]);return;}
                if($integrity_status==='suspicious'||$qr_distance>$qr_radius){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(sprintf(/* translators: 1: work location name, 2: distance */__('You must be at %1$s to use QR Sign In. Distance: %2$s','workforce-one'),$qr_location->name,$this->format_distance($qr_distance)))]);return;}
            }
            if($type!=='sign_out'&&$requires_location&&$assigned_location_v321&&$assigned_location_v321->enforcement&&$location_status!=='inside'){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(sprintf(/* translators: %s: distance */__('You are outside your assigned work location. Distance: %s','workforce-one'),$this->format_distance($distance)))]);return;}
            $inserted=$wpdb->insert($this->time_logs,['employee_id'=>(int)$emp->id,'user_id'=>get_current_user_id(),'work_date'=>current_time('Y-m-d'),'event_type'=>$type,'event_at'=>$now,'scheduled_status'=>$sch->status,'ip_address'=>isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'','latitude'=>$lat,'longitude'=>$lng,'accuracy'=>$acc,'location_status'=>$location_status,'distance_meters'=>$distance,'location_timestamp'=>$location_timestamp,'integrity_status'=>$integrity_status,'integrity_reason'=>$integrity_reason,'created_at'=>$now]);
            if($inserted===false){$detail=$wpdb->last_error?$wpdb->last_error:'Database insert failed.';$this->audit('time_'.$type.'_failed','time_log',0,$emp->name.' / '.$detail);$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode($type==='sign_out'?__('Unable to record Sign Out. Please contact the administrator.','workforce-one'):__('Unable to record Sign In. Please contact the administrator.','workforce-one'))]);return;}
            $classification=$type==='sign_in'?$this->sign_in_classification($now,$emp?$emp->id:0):'';
            if($type==='sign_out' && $emp){ $this->achievement_evaluate_attendance((int)$emp->id,current_time('Y-m-d')); }
            $this->audit('time_'.$type,'time_log',$wpdb->insert_id,$emp->name.' / '.$sch->status.' / '.$now.' / '.$classification.' / face_verified='.($face_ok?'yes':'no').' / source='.($qr_kiosk?'qr_kiosk_'.(int)$qr_kiosk->id:'normal'));
            $success=$type==='sign_in'&&$classification==='Late Arrival'?__('Late Arrival recorded successfully.','workforce-one'):($type==='sign_out'?__('Sign Out recorded successfully.','workforce-one'):__('Sign In recorded successfully.','workforce-one'));
            $this->redirect(['ews_view'=>'time','time_success'=>rawurlencode($success)]);
        }

    public function att_grid_save(){
            if(!$this->can('ews_manage_attendance')) wp_die('Access denied');
            check_admin_referer('ews31_att_grid_save');
            global $wpdb;
            $week=sanitize_text_field($_POST['week']??current_time('Y-m-d'));
            $t=strtotime($week);
            if(!$t)wp_die('Invalid week.');
            [$dates,$sun]=$this->week_dates_configured($week);
            if(!$dates)wp_die('No working days are configured.');
            $saved=0;$skipped=0;$invalid=0;$conflicts=0;

            /*
             * The Attendance grid now submits only cells that the current
             * browser actually changed. Each change also carries the value
             * that was present when the grid was loaded. The save helper uses
             * that value as a concurrency guard so a stale browser cannot
             * silently overwrite another manager's newer change.
             *
             * Keep the legacy full-grid path below as a no-JavaScript fallback.
             */
            $changes_raw=isset($_POST['att_changes_json'])?wp_unslash($_POST['att_changes_json']):'';
            $changes=[];
            $use_concurrent_grid=($changes_raw!=='');
            if($use_concurrent_grid){
                $decoded=json_decode($changes_raw,true);
                if(!is_array($decoded)){
                    wp_die('Invalid attendance changes payload.');
                }
                $changes=$decoded;

                $statuses=$this->statuses();
                foreach($changes as $change){
                    if(!is_array($change)){
                        $invalid++;
                        continue;
                    }

                    $eid=absint($change['employee']??0);
                    $day_index=isset($change['day'])?absint($change['day']):9999;
                    $status=sanitize_text_field($change['status']??'');
                    $has_original=array_key_exists('original',$change);
                    $original=$has_original && $change['original']!==null
                        ?sanitize_text_field($change['original'])
                        :null;

                    if(!$eid || !isset($dates[$day_index]) || !$has_original){
                        $invalid++;
                        continue;
                    }

                    /*
                     * "Not Set" / empty means no schedule change. The grid has
                     * always treated empty cells as unchanged, so preserve that
                     * behavior rather than introducing a destructive clear.
                     */
                    if($status==='' || $status==='Not Set'){
                        $skipped++;
                        continue;
                    }

                    if(!in_array($status,$statuses,true)){
                        $invalid++;
                        continue;
                    }

                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$eid));
                    if($exists && !$this->employee_attendance_enabled($eid)){$invalid++;continue;}
                    if($exists && !$this->department_scope_allows_employee($eid)){$invalid++;continue;}
                    if(!$exists){
                        $invalid++;
                        continue;
                    }

                    $date=$dates[$day_index];
                    $result=$this->save_att_if_unchanged($eid,$date,$status,$original);

                    if(!empty($result['conflict'])){
                        $conflicts++;
                        continue;
                    }
                    if(empty($result['ok'])){
                        $invalid++;
                        continue;
                    }

                    $saved++;
                    if(!empty($result['id']) && !empty($result['changed'])){
                        $old_value=array_key_exists('old_status',$result) && $result['old_status']!==null
                            ?(string)$result['old_status']
                            :'Not Set';
                        $this->audit(
                            'attendance_grid',
                            'schedule',
                            (int)$result['id'],
                            $date.' | Old: '.$old_value.' | New: '.$status
                        );
                    }
                }
            }else{
                /*
                 * Legacy fallback for clients without the new JS payload.
                 * Modern Attendance pages always use the concurrent path.
                 */
                $grid=$_POST['att']??[];
                if(!is_array($grid))$grid=[];
                /*
                 * Desktop and mobile render the same schedule twice in one form.
                 * On desktop, the mobile copy is hidden and contains the OLD values;
                 * never let it overwrite desktop edits. On mobile, the JS syncs the
                 * visible mobile values into att[...] before submit.
                 */
                if(($_POST['attendance_client']??'desktop')==='mobile'){
                    $mobile_grid=$_POST['mobile_att']??[];
                    if(is_array($mobile_grid)){
                        foreach($mobile_grid as $eid=>$days){
                            if(!isset($grid[$eid])||!is_array($grid[$eid]))$grid[$eid]=[];
                            if(is_array($days)){
                                foreach($days as $i=>$value)$grid[$eid][$i]=$value;
                            }
                        }
                    }
                }
                $statuses=$this->statuses();
                foreach($grid as $eid=>$days){
                    $eid=absint($eid);
                    if(!$eid||!is_array($days))continue;
                    $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$eid));
                    if(!$exists || !$this->employee_attendance_enabled($eid)){$invalid++;continue;}
                    foreach($dates as $i=>$date){
                        $status=sanitize_text_field($days[$i]??'');
                        if($status===''||$status==='Not Set'){ $skipped++; continue; }
                        if(!in_array($status,$statuses,true)){ $invalid++; continue; }
                        $note=sanitize_textarea_field($days['note_'.$i]??'');
                        $old_status=$wpdb->get_var($wpdb->prepare(
                            "SELECT status FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                            $eid,$date
                        ));
                        $id=$this->save_att($eid,$date,$status,$note);
                        if($id && $old_status!==$status){
                            $old_value=$old_status!==null?(string)$old_status:'Not Set';
                            $saved++;
                            $this->audit('attendance_grid','schedule',$id,$date.' | Old: '.$old_value.' | New: '.$status);
                        }elseif($id){
                            $saved++;
                        }
                    }
                }
            }

            $args=[
                'ews_view'=>'attendance',
                'week'=>$week,
                'grid_saved'=>$saved,
                'grid_skipped'=>$skipped,
                'grid_invalid'=>$invalid
            ];
            if($conflicts>0)$args['grid_conflict']=$conflicts;
            $this->redirect($args);
        }

    public function att_single(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_single');$eid=absint($_POST['employee_id']);$d=sanitize_text_field($_POST['work_date']);$s=sanitize_text_field($_POST['status']);$n=sanitize_textarea_field($_POST['note']??'');if(!$eid||!$this->valid_date($d)||!in_array($s,$this->statuses(),true))wp_die('Invalid attendance.');if(!$this->employee_attendance_enabled($eid))wp_die('Attendance tracking is disabled for this employee.');if(!$this->department_scope_allows_employee($eid))wp_die('You cannot manage attendance outside your Department.');$id=$this->save_att($eid,$d,$s,$n);$this->audit('attendance_single','schedule',$id,$d.' => '.$s);$this->redirect(['ews_view'=>'attendance','saved'=>1]);}

    /*
     * Parse and validate an attendance CSV (columns: domain_name, work_date, status, note —
     * the same layout as att_sample()). Every row is returned with valid/error so the preview
     * can show exactly what will be imported; att_import() re-checks permissions per row.
     */
    private function parse_csv($path){
            $max_bytes=2*1024*1024; $max_rows=5000;
            if(!is_readable($path))return ['error'=>'The uploaded file could not be read.'];
            if(filesize($path)>$max_bytes)return ['error'=>'The CSV file is larger than 2 MB. Please split it into smaller files.'];
            $fh=fopen($path,'r'); if(!$fh)return ['error'=>'The uploaded file could not be read.'];
            $first=fgets($fh); if($first===false){fclose($fh);return ['error'=>'The CSV file is empty.'];}
            $first=preg_replace('/^\xEF\xBB\xBF/','',$first);
            // Excel in many locales (including Arabic) saves CSV with ";" as the separator.
            $delim=substr_count($first,';')>substr_count($first,',')?';':',';
            $header=array_map(function($h){return strtolower(trim((string)$h));},str_getcsv($first,$delim));
            $cols=['domain_name'=>array_search('domain_name',$header,true),'work_date'=>array_search('work_date',$header,true),'status'=>array_search('status',$header,true),'note'=>array_search('note',$header,true)];
            foreach(['domain_name','work_date','status'] as $req)if($cols[$req]===false){fclose($fh);return ['error'=>'Missing required column "'.$req.'". Download the sample CSV for the expected format.'];}
            global $wpdb;
            $employees=[];
            foreach((array)$wpdb->get_results("SELECT id,name,domain_name FROM {$this->employees} WHERE active=1") as $e)$employees[strtolower(trim($e->domain_name))]=$e;
            $statuses=$this->statuses(); $status_lc=[]; foreach($statuses as $s)$status_lc[strtolower($s)]=$s;
            $rows=[]; $line=1; $seen=[];
            while(($r=fgetcsv($fh,0,$delim))!==false){
                $line++;
                if($r===[null]||count(array_filter(array_map('trim',array_map('strval',$r)),'strlen'))===0)continue;
                if(count($rows)>=$max_rows){fclose($fh);return ['error'=>'The CSV file has more than '.$max_rows.' rows. Please split it into smaller files.'];}
                $get=function($k)use($r,$cols){return $cols[$k]===false?'':trim((string)($r[$cols[$k]]??''));};
                $domain=sanitize_text_field($get('domain_name')); $raw_date=sanitize_text_field($get('work_date'));
                $raw_status=sanitize_text_field($get('status')); $note=sanitize_textarea_field($get('note'));
                $emp=$employees[strtolower($domain)]??null; $date=$this->normalize_date($raw_date); $status=$status_lc[strtolower($raw_status)]??'';
                $error='';
                if(!$emp)$error='Unknown or inactive employee';
                elseif($date==='')$error='Invalid date (use YYYY-MM-DD)';
                elseif($status==='')$error='Unknown status';
                elseif(!$this->employee_attendance_enabled((int)$emp->id))$error='Attendance tracking is disabled for this employee';
                elseif(!$this->department_scope_allows_employee((int)$emp->id))$error='Employee is outside your Department';
                elseif(isset($seen[$emp->id.'|'.$date]))$error='Duplicate of line '.$seen[$emp->id.'|'.$date];
                if(!$error)$seen[$emp->id.'|'.$date]=$line;
                $rows[]=['line'=>$line,'domain'=>$domain,'employee'=>$emp?$emp->name:'','eid'=>$emp?(int)$emp->id:0,'date'=>$raw_date,'normalized_date'=>$date,'status'=>$status?:$raw_status,'note'=>$note,'valid'=>$error==='','error'=>$error];
            }
            fclose($fh);
            if(!$rows)return ['error'=>'The CSV file has no data rows.'];
            return ['rows'=>$rows];
        }

    public function att_preview(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_preview');if(empty($_FILES['attendance_csv']['tmp_name']))wp_die('CSV required.');$p=$this->parse_csv($_FILES['attendance_csv']['tmp_name']);if(isset($p['error']))wp_die(esc_html($p['error']));$t=wp_generate_uuid4();set_transient('ews31_preview_'.$t.'_'.get_current_user_id(),$p['rows'],15*MINUTE_IN_SECONDS);$this->redirect(['ews_view'=>'attendance','preview'=>$t]);}

    public function att_import(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_import');$t=sanitize_text_field($_POST['token']);$rows=get_transient('ews31_preview_'.$t.'_'.get_current_user_id());if(!is_array($rows))wp_die('Preview expired.');$ok=0;$bad=0;foreach($rows as $r){if(!$r['valid']){$bad++;continue;}if(!$this->employee_attendance_enabled((int)$r['eid'])){$bad++;continue;}if(!$this->department_scope_allows_employee((int)$r['eid'])){$bad++;continue;}$this->save_att($r['eid'],$r['normalized_date'],$r['status'],$r['note']);$ok++;}delete_transient('ews31_preview_'.$t.'_'.get_current_user_id());$this->audit('attendance_bulk_import','schedule',0,'Imported '.$ok.'; Rejected '.$bad);$this->redirect(['ews_view'=>'attendance','imported'=>$ok,'rejected'=>$bad]);}

    public function att_sample(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_sample');nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename=attendance_import_sample.csv');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');fputcsv($o,['domain_name','work_date','status','note']);fputcsv($o,['employee.domain','2026-08-09','Office','Normal attendance']);fputcsv($o,['employee.domain','2026-08-10','WFH','Working from home']);fputcsv($o,['employee02','2026-08-11','Vacation','Annual leave']);fclose($o);exit;}

}
