<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\AttendanceCommand;
use WorkforceOne\Attendance\AttendanceContext;
use WorkforceOne\Attendance\AttendanceResult;
use WorkforceOne\Attendance\AttendanceService;
use WorkforceOne\Attendance\LocationAssessment;
use WorkforceOne\Attendance\SignInRules;

trait EWS_Attendance_Trait {

    /**
     * The device location a form posted (hidden fields filled by the browser's geolocation):
     * [latitude, longitude, accuracy in metres, timestamp in ms], null where missing.
     * @return array{0:?float,1:?float,2:?float,3:?int}
     */
    private function posted_device_location(){
            $num=function($k){return isset($_POST[$k])&&is_numeric($_POST[$k])?$_POST[$k]:null;}; // phpcs:ignore WordPress.Security.NonceVerification -- the callers check the nonce
            $lat=$num('latitude');$lng=$num('longitude');$acc=$num('accuracy');$ts=$num('location_timestamp');
            return [$lat!==null?(float)$lat:null,$lng!==null?(float)$lng:null,$acc!==null?(float)$acc:null,$ts!==null?(int)$ts:null];
        }

    /** The employee's last Sign In / Out that had coordinates (for the impossible-movement check). */
    private function last_device_location($employee_id){
            global $wpdb;
            return $wpdb->get_row($wpdb->prepare("SELECT latitude,longitude,location_timestamp FROM {$this->time_logs} WHERE employee_id=%d AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY event_at DESC LIMIT 1",(int)$employee_id),ARRAY_A);
        }

    public function time_event(){
            if(!is_user_logged_in())wp_die('Please log in.');
            $type=sanitize_key($_POST['event_type']??'');
            if(!in_array($type,['sign_in','sign_out'],true))wp_die('Invalid event.');
            check_admin_referer('ews_time_event_'.$type);
            $this->record_time_event($type,['face_ok'=>$this->face_consume_token(wp_unslash($_POST['face_token']??''))]);
        }

    /*
     * Shared Sign In / Sign Out recorder for the Web forms: an adapter over AttendanceService
     * (src/Attendance/AttendanceService.php), which decides and records. Callers are responsible for
     * their own nonce check. $ctx:
     *   face_ok     bool  A server-issued face token was consumed for this request.
     *   qr_kiosk    obj   Kiosk row validated from a dynamic QR (QR Sign-In only).
     *   qr_location obj   Work location of that kiosk; the employee's own GPS must be inside it.
     * Ends with a redirect carrying the same messages as before 3.31.46.
     */
    private function record_time_event($type,$ctx=[]){
            $cmd=new AttendanceCommand($type==='sign_out'?AttendanceCommand::SIGN_OUT:AttendanceCommand::SIGN_IN);
            [$cmd->latitude,$cmd->longitude,$cmd->accuracy,$cmd->locationTimestampMs]=$this->posted_device_location();
            $cmd->faceOk=!empty($ctx['face_ok']);
            if(!empty($ctx['qr_kiosk']))$cmd->qrKiosk=['id'=>(int)$ctx['qr_kiosk']->id];
            if(!empty($ctx['qr_location'])){$l=$ctx['qr_location'];$cmd->qrLocation=['id'=>(int)$l->id,'name'=>(string)$l->name,'latitude'=>$l->latitude,'longitude'=>$l->longitude,'radius'=>$l->radius];}
            $r=$this->attendance_service()->record($cmd);
            if(!$r->ok){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode($this->attendance_error_message($r,$cmd->type))]);return;}
            $type=$cmd->type;$classification=(string)($r->details['classification']??'');
            $success=$type==='sign_in'&&$classification==='Late Arrival'?__('Late Arrival recorded successfully.','workforce-one'):($type==='sign_out'?__('Sign Out recorded successfully.','workforce-one'):__('Sign In recorded successfully.','workforce-one'));
            // At another of their branches than the one planned for today (3.31.72): say so; the manager sees it too.
            if(($r->details['branch_flag']??'')===\WorkforceOne\Attendance\BranchRules::OTHER){$l=$this->branch_locations()[(int)($r->details['branch_id']??0)]??null;if($l)$success.=' '.sprintf(/* translators: %s: branch */__('You were at %s, not the branch planned for today; your manager will see this.','workforce-one'),(string)$l->name);}
            $this->redirect(['ews_view'=>'time','time_success'=>rawurlencode($success)]);
        }

    /** The Web message for an AttendanceService failure on Sign In / Sign Out (the texts of 3.31.45). */
    private function attendance_error_message(AttendanceResult $r,$type){
            $d=$r->details;
            switch($r->code){
                case AttendanceResult::ATTENDANCE_DISABLED: return __('Attendance tracking is disabled for your employee profile.','workforce-one');
                case LocationAssessment::QR_KIOSK_NO_COORDS: return __('This Kiosk location has no coordinates configured. Please contact your administrator.','workforce-one');
                case LocationAssessment::QR_LOCATION_REQUIRED: return __('Location access is required for QR Sign In. Please allow Location Services and try again.','workforce-one');
                case LocationAssessment::QR_OUTSIDE: return sprintf(/* translators: 1: work location name, 2: distance */__('You must be at %1$s to use QR Sign In. Distance: %2$s','workforce-one'),(string)($d['location_name']??''),$this->format_distance($d['distance']??null));
                case AttendanceResult::OUTSIDE_LOCATION: return sprintf(/* translators: %s: distance */__('You are outside your assigned work location. Distance: %s','workforce-one'),$this->format_distance($d['distance']??null));
                case AttendanceResult::SAVE_FAILED: return $type==='sign_out'?__('Unable to record Sign Out. Please contact the administrator.','workforce-one'):__('Unable to record Sign In. Please contact the administrator.','workforce-one');
            }
            $emp=!empty($d['employee_id'])?(object)['id'=>(int)$d['employee_id']]:null;
            return $this->sign_in_rule_message($r->code,$emp,$d['bounds']??null);
        }

    /** Attendance decisions for this request (Sign In / Out, breaks); see AttendanceContext for what it may touch. */
    private function attendance_service(){
            return new AttendanceService($this->attendance_context());
        }

    private function attendance_context(){
            global $wpdb;
            $breaks=$wpdb->prefix.'ews_break_sessions';
            return new AttendanceContext([
                'employee'=>function(){return $this->current_employee();},
                'attendanceEnabled'=>function($eid){return $this->employee_attendance_enabled($eid);},
                'schedule'=>function($eid){return $this->today_schedule_for_employee($eid);},
                'events'=>function($eid){return $this->today_events($eid);},
                'attendanceDay'=>function($eid){return $this->attendance_day($eid);},
                'generalLeave'=>function($day){return (bool)$this->company_leave_dates($day,$day);},
                'requiresSignIn'=>function($status){return $this->schedule_type_requires_sign_in($status);},
                'requiresLocation'=>function($status){return $this->schedule_type_requires_location($status);},
                'faceRequired'=>function(){return $this->face_signin_enabled();},
                'windowOpen'=>function($eid){return $this->sign_in_window_open($eid);},
                'windowBounds'=>function($eid){return $this->sign_in_window_bounds($eid);},
                'classify'=>function($at,$eid){return $this->sign_in_classification($at,$eid);},
                'assignedLocation'=>function($eid){return $this->ews_v321_employee_location($eid);},
                'branches'=>function($eid){return $this->branches_for_sign_in($eid);},
                'defaultSite'=>function(){return [$this->option('ews_location_latitude'),$this->option('ews_location_longitude'),(float)$this->option('ews_location_radius')];},
                'lastDeviceLocation'=>function($eid){return $this->last_device_location($eid);},
                'localTime'=>function(){return current_time('timestamp');},
                'localMysql'=>function(){return current_time('mysql');},
                'unixTime'=>function(){return time();},
                'insertTimeLog'=>function($row)use($wpdb){return $wpdb->insert($this->time_logs,$row)===false?0:(int)$wpdb->insert_id;},
                'insertError'=>function()use($wpdb){return (string)$wpdb->last_error;},
                'lastInsertId'=>function()use($wpdb){return (int)$wpdb->insert_id;},
                'firstEventId'=>function($eid,$day,$types)use($wpdb){
                    $ph=implode(',',array_fill(0,count($types),'%s'));
                    return (int)$wpdb->get_var($wpdb->prepare("SELECT MIN(id) FROM {$this->time_logs} WHERE employee_id=%d AND work_date=%s AND event_type IN ($ph)",array_merge([(int)$eid,$day],$types)));
                },
                'deleteTimeLog'=>function($id)use($wpdb){$wpdb->delete($this->time_logs,['id'=>(int)$id],['%d']);},
                'evaluateAchievements'=>function($eid,$day){$this->achievement_evaluate_attendance($eid,$day);},
                'audit'=>function($action,$entity,$id,$details){$this->audit($action,$entity,$id,$details);},
                'currentUserId'=>function(){return get_current_user_id();},
                'clientIp'=>function(){return isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'';},
                'lock'=>function($eid){return $this->attendance_lock($eid);},
                'unlock'=>function($eid){$this->attendance_unlock($eid);},
                'idemFind'=>function($uid,$scope,$hash){return $this->attendance_idempotency_find($uid,$scope,$hash);},
                'idemClaim'=>function($uid,$scope,$hash,$request){return $this->attendance_idempotency_claim($uid,$scope,$hash,$request);},
                'idemSave'=>function($uid,$scope,$hash,$response)use($wpdb){$wpdb->update($wpdb->prefix.'ews_api_idempotency',['response'=>wp_json_encode($response)],['user_id'=>(int)$uid,'scope'=>$scope,'key_hash'=>$hash],['%s'],['%d','%s','%s']);},
                'idemRelease'=>function($uid,$scope,$hash)use($wpdb){$wpdb->delete($wpdb->prefix.'ews_api_idempotency',['user_id'=>(int)$uid,'scope'=>$scope,'key_hash'=>$hash],['%d','%s','%s']);},
                'breakEnabled'=>function(){return $this->break_enabled();},
                'openBreak'=>function($eid){return $this->break_open_session($eid);},
                'breakRemaining'=>function($eid){return $this->break_remaining($eid);},
                'insertBreak'=>function($row)use($wpdb,$breaks){return $wpdb->insert($breaks,$row,['%d','%d','%s','%s','%s','%s'])?(int)$wpdb->insert_id:0;},
                'firstOpenBreakId'=>function($eid,$day)use($wpdb,$breaks){return (int)$wpdb->get_var($wpdb->prepare("SELECT MIN(id) FROM {$breaks} WHERE employee_id=%d AND work_date=%s AND status='Open'",(int)$eid,$day));},
                'deleteBreak'=>function($id)use($wpdb,$breaks){$wpdb->delete($breaks,['id'=>(int)$id],['%d']);},
                'scheduleBreakEvents'=>function($id){
                    $now_utc=current_time('timestamp',true);
                    wp_schedule_single_event($now_utc+($this->break_duration_minutes()*60),'ews_break_duration_reminder',[$id]);
                    wp_schedule_single_event($now_utc+($this->break_escalation_minutes()*60),'ews_break_manager_escalation',[$id]);
                },
                'closeBreak'=>function($id,$end,$minutes)use($wpdb,$breaks){return $wpdb->update($breaks,['end_at'=>$end,'actual_minutes'=>$minutes,'status'=>'Completed'],['id'=>(int)$id,'status'=>'Open'],['%s','%d','%s'],['%d','%s'])===1;},
                'breakEnded'=>function($emp,$id,$minutes){$this->notify((int)$emp->wp_user_id,'break','Break Ended',$this->break_ended_message($minutes),['entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','time',$this->app_home_url())]);},
            ]);
        }

    /**
     * Serialises attendance writes of one employee across requests: MySQL / MariaDB GET_LOCK (named
     * per site and employee, released at the end of the request or when the connection closes; no
     * table or privilege needed, available on shared hosting). Waits up to 5 seconds. Where it is not
     * available (SQLite, some clusters) the request goes on unlocked and AttendanceService's
     * check after the insert keeps the first row only.
     */
    private function attendance_lock($employee_id){
            global $wpdb;
            if(defined('DB_ENGINE') && DB_ENGINE==='sqlite')return false;
            $prev=$wpdb->suppress_errors(true);
            $got=$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$this->attendance_lock_name($employee_id),5));
            $wpdb->suppress_errors($prev);
            return (string)$got==='1';
        }

    private function attendance_unlock($employee_id){
            global $wpdb;
            $prev=$wpdb->suppress_errors(true);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$this->attendance_lock_name($employee_id)));
            $wpdb->suppress_errors($prev);
        }

    /** Lock names are server-wide: include the site (database + table prefix). At most 64 characters. */
    private function attendance_lock_name($employee_id){
            global $wpdb;
            return 'wfo_att_'.substr(md5((defined('DB_NAME')?DB_NAME:'').'|'.$wpdb->prefix),0,12).'_'.(int)$employee_id;
        }

    /** @return array{request:string,response:array<string,mixed>|null}|null */
    private function attendance_idempotency_find($user_id,$scope,$key_hash){
            global $wpdb;
            $this->ensure_api_idempotency_schema();
            $row=$wpdb->get_row($wpdb->prepare("SELECT request_hash,response FROM {$wpdb->prefix}ews_api_idempotency WHERE user_id=%d AND scope=%s AND key_hash=%s LIMIT 1",(int)$user_id,$scope,$key_hash));
            if(!$row)return null;
            $response=$row->response!==null&&$row->response!==''?json_decode((string)$row->response,true):null;
            return ['request'=>(string)$row->request_hash,'response'=>is_array($response)?$response:null];
        }

    /** Inserts the key as "in progress"; the unique key makes a concurrent second claim fail. */
    private function attendance_idempotency_claim($user_id,$scope,$key_hash,$request_hash){
            global $wpdb;
            $this->ensure_api_idempotency_schema();
            $prev=$wpdb->suppress_errors(true);
            $ok=$wpdb->insert($wpdb->prefix.'ews_api_idempotency',['user_id'=>(int)$user_id,'scope'=>$scope,'key_hash'=>$key_hash,'request_hash'=>$request_hash,'created_at'=>current_time('mysql',true)],['%d','%s','%s','%s','%s']);
            $wpdb->suppress_errors($prev);
            return (bool)$ok;
        }

    /** Idempotency keys are kept for 24 hours (daily cleanup). */
    private function attendance_idempotency_cleanup(){
            global $wpdb;
            $this->ensure_api_idempotency_schema();
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ews_api_idempotency WHERE created_at < %s",gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS)));
        }

    /** Employee-facing text for a SignInRules result. */
    private function sign_in_rule_message($rule,$emp,$bounds){
            switch($rule){
                case SignInRules::NO_EMPLOYEE: return __('Your WordPress account is not linked to an active employee.','workforce-one');
                case SignInRules::FACE_REQUIRED: return __('Face verification is required before Sign In. Please complete Face Verification and try again.','workforce-one');
                case SignInRules::GENERAL_LEAVE: return __('Today is a General Leave day. Sign In is not required.','workforce-one');
                case SignInRules::NOT_WORKING_DAY: return __('Today is not a working day for you.','workforce-one');
                case SignInRules::TOO_EARLY: return sprintf(/* translators: %s: start time */__('Sign In is not available yet. Your working hours start at %s.','workforce-one'),date_i18n('g:i A',$bounds['start']));
                case SignInRules::TOO_LATE:
                    $h=$this->working_hours($emp->id);
                    return sprintf(/* translators: %s: cutoff time */__('Sign In is no longer available. The Sign In cutoff was %s.','workforce-one'),$bounds['cutoff']?date_i18n('g:i A',$bounds['cutoff']):$this->format_time_label($h['end']));
                case SignInRules::ALREADY_SIGNED_IN: return __('You have already signed in today.','workforce-one');
                case SignInRules::ON_BREAK: return __('Please Resume Work before signing out.','workforce-one');
                case SignInRules::NOT_SIGNED_IN: return __('You cannot sign out before signing in.','workforce-one');
                case SignInRules::ALREADY_SIGNED_OUT: return __('You have already signed out today.','workforce-one');
            }
            return __('Unable to record this action.','workforce-one');
        }

    public function att_grid_save(){
            if(!$this->can('ews_manage_attendance')) wp_die('Access denied');
            check_admin_referer('ews31_att_grid_save');
            global $wpdb;
            $week=sanitize_text_field(wp_unslash($_POST['week']??current_time('Y-m-d')));
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
                    // "By the schedule" (3.31.72): the day's branch, one of the employee's that this manager may plan.
                    if(array_key_exists('branch',$change))$this->branch_plan_day($eid,$date,$status,absint($change['branch']));

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
                    if(!$exists || !$this->employee_attendance_enabled($eid) || !$this->department_scope_allows_employee($eid)){$invalid++;continue;}
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

    public function att_single(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_single');$eid=absint($_POST['employee_id']);$d=sanitize_text_field(wp_unslash($_POST['work_date']));$s=sanitize_text_field(wp_unslash($_POST['status']));$n=sanitize_textarea_field(wp_unslash($_POST['note']??''));if(!$eid||!$this->valid_date($d)||!in_array($s,$this->statuses(),true))wp_die('Invalid attendance.');if(!$this->employee_attendance_enabled($eid))wp_die('Attendance tracking is disabled for this employee.');if(!$this->department_scope_allows_employee($eid))wp_die('You cannot manage attendance outside your Department.');$id=$this->save_att($eid,$d,$s,$n);$this->audit('attendance_single','schedule',$id,$d.' => '.$s);$this->redirect(['ews_view'=>'attendance','saved'=>1]);}

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

    public function att_import(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_import');$t=sanitize_text_field(wp_unslash($_POST['token']));$rows=get_transient('ews31_preview_'.$t.'_'.get_current_user_id());if(!is_array($rows))wp_die('Preview expired.');$ok=0;$bad=0;foreach($rows as $r){if(!$r['valid']){$bad++;continue;}if(!$this->employee_attendance_enabled((int)$r['eid'])){$bad++;continue;}if(!$this->department_scope_allows_employee((int)$r['eid'])){$bad++;continue;}$this->save_att($r['eid'],$r['normalized_date'],$r['status'],$r['note']);$ok++;}delete_transient('ews31_preview_'.$t.'_'.get_current_user_id());$this->audit('attendance_bulk_import','schedule',0,'Imported '.$ok.'; Rejected '.$bad);$this->redirect(['ews_view'=>'attendance','imported'=>$ok,'rejected'=>$bad]);}

    public function att_sample(){if(!$this->can('ews_manage_attendance'))wp_die('Access denied');check_admin_referer('ews31_att_sample');nocache_headers();header('Content-Type:text/csv; charset=utf-8');header('Content-Disposition:attachment; filename=attendance_import_sample.csv');echo "\xEF\xBB\xBF";$o=fopen('php://output','w');fputcsv($o,['domain_name','work_date','status','note']);fputcsv($o,['employee.domain','2026-08-09','Office','Normal attendance']);fputcsv($o,['employee.domain','2026-08-10','WFH','Working from home']);fputcsv($o,['employee02','2026-08-11','Vacation','Annual leave']);fclose($o);exit;}

}
