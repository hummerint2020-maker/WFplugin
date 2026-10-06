<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\FaceMatch;

trait EWS_Frontend_Trait {

    /* The Face REST routes are registered by src/Api/Routes.php (workforce-one/v1/face/*). */
    public function face_rest_enroll($request){
        $emp=$this->current_employee(); if(!$emp)return new WP_Error('no_employee',__('Employee account not found','workforce-one'),array('status'=>403));
        $p=$request->get_json_params(); $tpl=isset($p['template'])&&is_array($p['template'])?$p['template']:array();
        if(count($tpl)<64)return new WP_Error('invalid_template',__('Invalid face template','workforce-one'),array('status'=>400));
        // An existing enrollment can only be replaced through the admin-approved reset workflow.
        if($this->face_template_for_employee($emp->id))return new WP_Error('already_enrolled',__('A face is already enrolled. Request a reset to enroll again.','workforce-one'),array('status'=>409));
        return $this->face_save_template($emp->id,$tpl)?array('ok'=>true):new WP_Error('save_failed',__('Could not save face template','workforce-one'),array('status'=>500));
    }
    public function face_rest_verify($request){
        $emp=$this->current_employee(); if(!$emp)return new WP_Error('no_employee',__('Employee account not found','workforce-one'),array('status'=>403));
        $p=$request->get_json_params(); $tpl=isset($p['template'])&&is_array($p['template'])?$p['template']:array();
        $stored=$this->face_template_for_employee($emp->id); if(!$stored)return new WP_Error('not_enrolled',__('No face enrolled for this employee','workforce-one'),array('status'=>404));
        // Only the decision is returned (no distance or threshold: that was a similarity oracle), and
        // failed attempts are limited per user (src/Attendance/FaceMatch.php: 5 in 10 minutes).
        $uid=get_current_user_id();$state=get_user_meta($uid,'ews_face_verify_failures',true);$state=is_array($state)?$state:null;
        if(FaceMatch::lockedOut($state,time()))return $this->face_rate_limited(FaceMatch::retryAfter($state,time()));
        $ok=FaceMatch::matches($tpl,$stored,(float)$this->face_signin_setting('face_match_threshold',.60));
        if($ok!==true){
            $state=FaceMatch::afterFailure($state,time());update_user_meta($uid,'ews_face_verify_failures',$state);
            if($state['failures']===FaceMatch::MAX_FAILURES)$this->audit('face_verify_locked','employee',(int)$emp->id,$emp->name.' / '.FaceMatch::MAX_FAILURES.' failed Face verifications in '.(FaceMatch::WINDOW_SECONDS/60).' minutes');
            if($ok===null)return new WP_Error('invalid_template',__('Template size mismatch','workforce-one'),array('status'=>400));
            return array('ok'=>false);
        }
        delete_user_meta($uid,'ews_face_verify_failures');
        return array('ok'=>true,'face_token'=>$this->face_issue_token());
    }

    private function face_rate_limited($retry_after){
        $r=new WP_REST_Response(array('code'=>'rate_limited','message'=>__('Too many Face verification attempts. Please wait a few minutes and try again.','workforce-one'),'data'=>array('status'=>429,'retry_after'=>(int)$retry_after)),429);
        $r->header('Retry-After',(string)(int)$retry_after);
        return $r;
    }
    public function face_rest_reset_request($request){
        $emp=$this->current_employee();
        if(!$emp)return new WP_Error('no_employee',__('Employee account not found','workforce-one'),array('status'=>403));
        if(!$this->face_template_for_employee($emp->id))return new WP_Error('not_enrolled',__('No face is currently enrolled.','workforce-one'),array('status'=>404));
        if($this->face_reset_requested($emp->id))return array('ok'=>true,'already_requested'=>true);
        $ok=$this->face_request_reset($emp->id,get_current_user_id());
        if(!$ok)return new WP_Error('request_failed',__('Could not submit the face reset request.','workforce-one'),array('status'=>500));
        return array('ok'=>true,'requested'=>true);
    }

    public function face_rest_delete($request){
        $p=$request->get_json_params();$id=absint($p['employee_id']??0);$emp=$this->current_employee();
        // Employees must not be able to delete their own enrollment: that would bypass the reset approval workflow.
        if(!$id||!$this->can('ews_manage_employees'))return new WP_Error('forbidden',__('You cannot remove this face profile','workforce-one'),array('status'=>403));
        return array('ok'=>$this->face_delete_template($id));
    }


        private function css(){
        return '';
    }

    private function status_html($s){
            $cls=preg_replace('/[^A-Za-z0-9-]/','-',str_replace(' ','-',$s));
            $type=$this->schedule_type_config($s);
            if($type){
                $icon=$type['icon']??'•';
                $style='background:'.esc_attr($type['bg_color']).';color:'.esc_attr($type['text_color']).';border:1px solid '.esc_attr($type['border_color']).';';
            }else{
                $special=[
                    'Absent'=>['✕','#fee2e2','#991b1b','#fecaca'],
                    'General Leave'=>['▣','#fffbeb','#92400e','#fde68a'],
                    'Early Leave'=>['↩','#f3e8ff','#6d28d9','#ddd6fe'],
                    'Not Set'=>['—','#f2f4f7','#667085','#e5e7eb']
                ];
                $v=$special[$s]??['•','#f2f4f7','#667085','#e5e7eb'];
                $icon=$v[0];$style='background:'.$v[1].';color:'.$v[2].';border:1px solid '.$v[3].';';
            }
            return '<span class="ews-status '.$cls.'" style="'.$style.'">'.$icon.' '.esc_html($s).'</span>';
        }

            private function app_view_url($view){
            $url=remove_query_arg([
                'ews_view','leave_error','leave_sent','leave_done','leave_cancel_sent',
                'early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','saved','imported','grid_saved',
                'time_reset','time_saved','swap_error','swap_sent','swap_done','vacation_sent','vacation_error','vacation_done','vacation_rejected','profile_updated','profile_error','password_updated','password_error'
            ]);
            $view=sanitize_key($view);
            // Module-specific state must not leak into another module.
            // In particular, edit_task is an instruction to render a task editor;
            // keeping it while navigating to Leave/Attendance causes Tasks to
            // reopen the previously selected task when the user returns.
            if($view!=='tasks') {
                $url=remove_query_arg(['edit_task','task_tab','task_status','task_priority'],$url);
            }
            if($view!=='notifications') {
                $url=remove_query_arg(['notification_tab'],$url);
            }
            return add_query_arg('ews_view',$view,$url);
        }

        private function ux_modal($title,$message,$type='error',$confirm=false,$confirm_text='',$cancel_text=''){
            if($confirm_text==='')$confirm_text=__('Confirm','workforce-one');
            if($cancel_text==='')$cancel_text=__('Cancel','workforce-one');
            $type=in_array($type,['error','success','warning','info'],true)?$type:'info';
            $id='ews-ux-modal-'.wp_generate_uuid4();
            $icon=['error'=>'!','success'=>'✓','warning'=>'!','info'=>'i'][$type];
            $actions=$confirm?'<button type="button" class="ews-ux-cancel">'.esc_html($cancel_text).'</button><button type="button" class="ews-ux-confirm">'.esc_html($confirm_text).'</button>':'<button type="button" class="ews-ux-ok">'.esc_html__('OK','workforce-one').'</button>';
            return '<div id="'.esc_attr($id).'" class="ews-ux-modal" data-type="'.esc_attr($type).'" role="dialog" aria-modal="true"><div class="ews-ux-modal-card"><button type="button" class="ews-ux-close" aria-label="'.esc_attr__('Close','workforce-one').'">×</button><div class="ews-ux-icon">'.$icon.'</div><h3>'.esc_html($title).'</h3><p>'.esc_html($message).'</p><div class="ews-ux-actions">'.$actions.'</div></div></div>';
        }
        private function ux_notice_from_query(){
            if(isset($_GET['grid_conflict'])){
                $count=absint($_GET['grid_conflict']);
                $saved=absint($_GET['grid_saved']??0);
                /* translators: %d: number of schedule changes */
                $message=sprintf(_n('%d schedule change was not saved because another manager changed the same cell before you saved.','%d schedule changes were not saved because another manager changed the same cell before you saved.',$count,'workforce-one'),$count);
                /* translators: %d: number of schedule changes */
                if($saved>0)$message.=' '.sprintf(_n('%d other change was saved successfully.','%d other changes were saved successfully.',$saved,'workforce-one'),$saved);
                $message.=' '.__('Please refresh the Attendance page and review the latest schedule.','workforce-one');
                return $this->ux_modal(__('Schedule Conflict','workforce-one'),$message,'warning');
            }
            $map=[

                'saved'=>['success',__('Saved Successfully','workforce-one'),__('Your changes have been saved.','workforce-one')],
                'leave_sent'=>['success',__('Request Submitted','workforce-one'),__('Your leave request has been submitted.','workforce-one')],
                'leave_done'=>['success',__('Request Updated','workforce-one'),__('The leave request has been updated.','workforce-one')],
                'leave_cancel_sent'=>['success',__('Cancellation Submitted','workforce-one'),__('Your cancellation request has been submitted.','workforce-one')],
                'time_success'=>['success',__('Attendance Updated','workforce-one'),__('Your attendance action was completed successfully.','workforce-one')],
                'time_reset'=>['success',__('Day Reset','workforce-one'),__('The selected attendance day has been reset successfully.','workforce-one')],
                'grid_saved'=>['success',__('Attendance Saved','workforce-one'),__('The attendance schedule has been updated.','workforce-one')],
                'overtime_done'=>['success',__('Overtime Updated','workforce-one'),__('The overtime request has been updated.','workforce-one')],
                'overtime_rejected'=>['success',__('Overtime Rejected','workforce-one'),__('The overtime request has been rejected.','workforce-one')],
            ];
            foreach($map as $key=>$v){if(isset($_GET[$key]))return $this->ux_modal($v[1],$v[2],$v[0]);}
            if(isset($_GET['time_error']))return $this->ux_modal(__('Attendance Action Not Completed','workforce-one'),rawurldecode(sanitize_text_field(wp_unslash($_GET['time_error']))),'error');
            if(isset($_GET['break_error']))return $this->ux_modal(__('Break Action Not Completed','workforce-one'),rawurldecode(sanitize_text_field(wp_unslash($_GET['break_error']))),'error');
            if(isset($_GET['break_success']))return $this->ux_modal(__('Break Updated','workforce-one'),rawurldecode(sanitize_text_field(wp_unslash($_GET['break_success']))),'success');
            if(isset($_GET['overtime_error'])){
                $m=['disabled'=>__('Overtime Requests are currently disabled.','workforce-one'),'access'=>__('You do not have permission to perform this action.','workforce-one'),'invalid'=>__('Please review the overtime request and try again.','workforce-one'),'not_found'=>__('The overtime request could not be found.','workforce-one'),'save'=>__('The overtime request could not be updated. Please try again.','workforce-one'),'employee'=>__('Your account is not linked to an active employee.','workforce-one'),'date'=>__('Please select a valid future date.','workforce-one'),'time'=>__('Please enter a valid overtime time range.','workforce-one'),'reason'=>__('Please enter a reason for the overtime request.','workforce-one'),'overlap'=>__('The requested overtime overlaps another overtime request.','workforce-one')];
                return $this->ux_modal(__('Overtime Action Not Completed','workforce-one'),$m[sanitize_key($_GET['overtime_error'])]??__('Please review the overtime request and try again.','workforce-one'),'error');
            }
            if(isset($_GET['overtime_sent']))return $this->ux_modal(__('Overtime Request Submitted','workforce-one'),__('Your overtime request has been submitted.','workforce-one'),'success');
            if(isset($_GET['vacation_error'])){
                $m=['date'=>__('Please select a valid future date range.','workforce-one'),'no_working_days'=>__('The selected range contains no working days.','workforce-one'),'overlap'=>__('You already have a pending or approved vacation overlapping this period.','workforce-one'),'employee'=>__('Your account is not linked to an active employee.','workforce-one'),'save'=>__('We could not save the vacation request. Please try again.','workforce-one')];
                return $this->ux_modal(__('Vacation Request Not Submitted','workforce-one'),$m[sanitize_key($_GET['vacation_error'])]??__('Please review the vacation details and try again.','workforce-one'),'error');
            }
            if(isset($_GET['vacation_sent']))return $this->ux_modal(__('Vacation Request Submitted','workforce-one'),__('Your vacation request has been submitted.','workforce-one'),'success');
            if(isset($_GET['vacation_done']))return $this->ux_modal(__('Vacation Request Updated','workforce-one'),__('The vacation request has been updated.','workforce-one'),'success');
            $ews_view=sanitize_key($_GET['ews_view']??'');
            if($ews_view==='schedule' && isset($_GET['swap_error'])){
                $m=['invalid_request'=>__('Please select a valid employee and work date for the shift swap.','workforce-one'),'employee'=>__('The selected employee could not be found.','workforce-one'),'not_swappable'=>__('These schedules cannot be swapped. Both employees must have different Office/WFH schedules on this day.','workforce-one'),'pending'=>__('A pending swap request already exists for this day.','workforce-one'),'save'=>__('The shift swap could not be saved. Please try again.','workforce-one'),'not_found'=>__('The shift swap request could not be found.','workforce-one'),'changed'=>__('The schedule changed after the swap request was created, so it can no longer be accepted.','workforce-one'),'expired'=>__('The swap request has already been processed or expired.','workforce-one'),'past_date'=>__('This day has already passed, so it can no longer be swapped.','workforce-one')];
                return $this->ux_modal(__('Shift Swap Not Completed','workforce-one'),$m[sanitize_key($_GET['swap_error'])]??__('Please review the shift swap and try again.','workforce-one'),'error');
            }
            if($ews_view==='schedule' && isset($_GET['swap_sent']))return $this->ux_modal(__('Shift Swap Submitted','workforce-one'),__('Your shift swap request has been submitted.','workforce-one'),'success');
            if($ews_view==='schedule' && isset($_GET['swap_done'])){
                $m=['accepted'=>__('The shift swap was accepted and both schedules were updated.','workforce-one'),'rejected'=>__('The shift swap request was rejected.','workforce-one'),'cancelled'=>__('The shift swap request was cancelled.','workforce-one')];
                return $this->ux_modal(__('Shift Swap Updated','workforce-one'),$m[sanitize_key($_GET['swap_done'])]??__('The shift swap has been updated.','workforce-one'),'success');
            }
            if(isset($_GET['leave_error'])){
                $m=['date'=>__('Please select a valid future date range.','workforce-one'),'no_working_days'=>__('The selected range contains no working days.','workforce-one'),'overlap'=>__('You already have a pending or approved leave overlapping this period.','workforce-one'),'balance'=>__('Insufficient available balance for this Leave Type.','workforce-one'),'cross_year'=>__('A leave cannot span two calendar years. Please submit one request for each year.','workforce-one'),'employee'=>__('Your account is not linked to an active employee.','workforce-one')];
                return $this->ux_modal(__('Leave Request Not Submitted','workforce-one'),$m[sanitize_key($_GET['leave_error'])]??__('Please review the leave details and try again.','workforce-one'),'error');
            }
            if(isset($_GET['early_error'])){
                $m=['future_date'=>__('Please select a future date for Early Leave.','workforce-one'),'working_day'=>__('Early Leave is available only on a configured working day.','workforce-one'),'office_only'=>__('Early Leave is available only on an Office workday. It cannot be requested on Work From Home or Leave/Vacation days.','workforce-one'),'max_duration'=>__('The requested Early Leave exceeds the maximum duration allowed for one request.','workforce-one'),'monthly_limit'=>__('The requested Early Leave exceeds the remaining monthly allowance.','workforce-one'),'save'=>__('We could not submit the Early Leave request. Please try again.','workforce-one'),'approval_conflict'=>__('This request can no longer be approved because the employee is no longer scheduled for an eligible Office workday.','workforce-one')];
                return $this->ux_modal(__('Early Leave Not Available','workforce-one'),$m[sanitize_key($_GET['early_error'])]??__('Please review the Early Leave details and try again.','workforce-one'),'error');
            }
            return '';
        }
        private function ux_clean_url(){
            $url=remove_query_arg(['leave_error','leave_sent','leave_done','leave_cancel_sent','early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','saved','imported','grid_saved','time_reset','time_saved']);
            return $url;
        }
        private function frontend_confirmation_config(){
            return ['global'=>(bool)$this->option('ews_confirm_global'),'actions'=>\WorkforceOne\Settings\FeatureSettings::confirmState($this->option('ews_confirmation_actions'))];
        }
        private function ews_empty_state($title,$text='',$action_url='',$action_label=''){
            $html='<div class="ews-empty-state" role="status"><div class="ews-empty-icon" aria-hidden="true">○</div><div class="ews-empty-title">'.esc_html($title).'</div>';
            if($text!=='')$html.='<div class="ews-empty-text">'.esc_html($text).'</div>';
            if($action_url && $action_label)$html.='<a class="ews-btn ews-empty-action" href="'.esc_url($action_url).'">'.esc_html($action_label).'</a>';
            return $html.'</div>';
        }





    public function enqueue_frontend_assets(){
            $root=plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
            $ver=defined('EWS_VERSION') ? EWS_VERSION : '3.22.81';
            // Page-specific scripts: registered on every front-end request (a view can also be
            // rendered by its own shortcode) and enqueued by the view that needs them.
            wp_register_script('workforce-one-jsqr', $root.'assets/vendor/jsQR-1.4.0.js', [], '1.4.0', true);
            wp_register_script('workforce-one-time', $root.'assets/js/time.js', ['wp-i18n'], $ver, true);
            wp_set_script_translations('workforce-one-time', 'workforce-one', dirname(__DIR__).'/languages');
            wp_register_script('workforce-one-schedule', $root.'assets/js/schedule.js', [], $ver, true);
            wp_register_script('workforce-one-attendance-grid', $root.'assets/js/attendance-grid.js', [], $ver, true);
            wp_register_script('workforce-one-app-attendance-insights', $root.'assets/js/app-attendance-insights.js', [], $ver, true);
            wp_register_script('workforce-one-leave', $root.'assets/js/leave.js', [], $ver, true);
            wp_register_script('workforce-one-overtime', $root.'assets/js/overtime.js', [], $ver, true);
            wp_register_script('workforce-one-my-profile', $root.'assets/js/my-profile.js', [], $ver, true);
            wp_register_script('workforce-one-people', $root.'assets/js/people.js', [], $ver, true);
            wp_register_script('workforce-one-reports', $root.'assets/js/reports.js', [], $ver, true);
            wp_register_script('workforce-one-presence-scan', $root.'assets/js/presence-scan.js', ['workforce-one-jsqr'], $ver, true);
            wp_register_style('workforce-one', $root.'assets/css/workforce-one.css', [], $ver);
            wp_register_style('workforce-one-pay', $root.'assets/css/app-pay.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-pay', 'rtl', 'replace');
            wp_register_style('workforce-one-home', $root.'assets/css/app-home.css', ['workforce-one'], $ver);
            wp_register_style('workforce-one-time-page', $root.'assets/css/app-time.css', ['workforce-one'], $ver);
            wp_register_style('workforce-one-leave-page', $root.'assets/css/app-leave.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-leave-page', 'rtl', 'replace');
            wp_register_style('workforce-one-schedule-page', $root.'assets/css/app-schedule.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-schedule-page', 'rtl', 'replace');
            wp_register_style('workforce-one-polls-page', $root.'assets/css/app-polls.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-polls-page', 'rtl', 'replace');
            wp_register_style('workforce-one-profile-page', $root.'assets/css/app-profile.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-profile-page', 'rtl', 'replace');
            wp_register_style('workforce-one-people-page', $root.'assets/css/app-people.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-people-page', 'rtl', 'replace');
            wp_style_add_data('workforce-one-time-page', 'rtl', 'replace');
            wp_style_add_data('workforce-one-home', 'rtl', 'replace');
            wp_style_add_data('workforce-one', 'rtl', 'replace');
            if(!$this->pwa_is_employee_app_page()) return;
            wp_enqueue_style('workforce-one', $root.'assets/css/workforce-one.css', [], $ver);
            // assets/css/*-rtl.css are generated with rtlcss from the LTR files (see assets/css/README.md).
            wp_style_add_data('workforce-one', 'rtl', 'replace');
            // The app frame (templates/app/layout.php) and the theme from wp-admin → Appearance.
            wp_enqueue_style('workforce-one-shell', $root.'assets/css/app-shell.css', ['workforce-one'], $ver);
            wp_style_add_data('workforce-one-shell', 'rtl', 'replace');
            wp_add_inline_style('workforce-one-shell', $this->appearance_css());
            // In the <head> on the Home page, so it does not flash unstyled (the view enqueues it too, for its shortcode).
            $view=sanitize_key($_GET['ews_view']??'dashboard');
            if($view==='dashboard')wp_enqueue_style('workforce-one-home');
            if($view==='time')wp_enqueue_style('workforce-one-time-page');
            if($view==='vacation')wp_enqueue_style('workforce-one-leave-page');
            if($view==='schedule')wp_enqueue_style('workforce-one-schedule-page');
            if($view==='polls'||$view==='dashboard')wp_enqueue_style('workforce-one-polls-page');
            if($view==='profile'||$view==='employee')wp_enqueue_style('workforce-one-profile-page');
            if($view==='people'||$view==='employee')wp_enqueue_style('workforce-one-people-page');
            wp_enqueue_script('workforce-one', $root.'assets/js/workforce-one.js', ['wp-i18n'], $ver, true);
            wp_set_script_translations('workforce-one', 'workforce-one', dirname(__DIR__).'/languages');
            wp_add_inline_script('workforce-one','window.ewsConfirmationConfig='.wp_json_encode($this->frontend_confirmation_config()).';','before');
        }

    private function smart_nudge_settings(){
        return \WorkforceOne\Settings\SmartNudges::config($this->option('ews_smart_nudges'));
    }

    private function smart_nudge_dismissed($user_id=null){
        $user_id=$user_id===null?get_current_user_id():absint($user_id);
        if(!$user_id)return [];
        $raw=get_user_meta($user_id,'ews_smart_nudge_dismissed',true);
        if(!is_array($raw))return [];
        $now=time();$clean=[];
        foreach($raw as $key=>$ts){if($key && (int)$ts>$now-30*DAY_IN_SECONDS)$clean[sanitize_key($key)]=(int)$ts;}
        if($clean!==$raw)update_user_meta($user_id,'ews_smart_nudge_dismissed',$clean);
        return $clean;
    }

    private function smart_nudge_is_dismissed($id,$dismissed){return isset($dismissed[sanitize_key($id)]);}

    private function smart_nudge_minutes($time){
        if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',(string)$time))return null;
        [$h,$m]=array_map('intval',explode(':',$time));return ($h*60)+$m;
    }

    private function smart_nudge_for_employee($emp,$user_id=null){
        global $wpdb;
        if(!$emp)return [];
        if(!$this->employee_attendance_enabled((int)$emp->id))return [];
        $user_id=$user_id===null?get_current_user_id():absint($user_id);
        if(!$user_id)return [];
        $cfg=$this->smart_nudge_settings();
        $smart_enabled=!empty($cfg['enabled']);
        $attendance_enabled=!empty($cfg['items']['attendance']);
        if(!$smart_enabled && !$attendance_enabled)return [];
        $dismissed=$this->smart_nudge_dismissed($user_id);$today=current_time('Y-m-d');$nudges=[];
        $add=function($id,$kind,$icon,$title,$message,$url,$action)use(&$nudges,$dismissed){
            if($this->smart_nudge_is_dismissed($id,$dismissed))return;
            $nudges[]=['id'=>$id,'kind'=>$kind,'icon'=>$icon,'title'=>$title,'message'=>$message,'url'=>$url,'action'=>$action];
        };

        if(!empty($cfg['items']['attendance'])){
            $sch=$this->today_schedule_for_employee((int)$emp->id);
            if($sch && $this->schedule_type_requires_sign_in($sch->status) && empty($this->company_leave_dates($today,$today))){
                $events=$this->today_events((int)$emp->id);
                $signed=!empty($events['sign_in'])||!empty($events['late_sign_in']);
                if(!$signed){
                    $h=$this->working_hours((int)$emp->id);$start=$this->smart_nudge_minutes($h['start']??'');$now=$this->smart_nudge_minutes(current_time('H:i'));
                    if($start!==null && $now!==null && $now >= $start+(int)$cfg['attendance_after'] && $this->sign_in_window_open((int)$emp->id)){
                        // The identity includes the effective schedule inputs. If an admin changes the same day's schedule,
                        // the old dismissed/sent reminder must not suppress the reminder for the new schedule.
                        $schedule_fingerprint=substr(md5($today.'|'.(string)($sch->status??'').'|'.(string)($h['start']??'').'|'.(string)($h['normal_until']??'').'|'.(string)($h['sign_in_cutoff_minutes']??240)),0,12);
                        $add('attendance-'.$today.'-'.$schedule_fingerprint,'attendance','⏰','Quick reminder','Your workday started at '.date_i18n('g:i A',strtotime($today.' '.$h['start'])).'. You are not signed in yet.',$this->app_view_url('time'),'Sign In');
                    }
                }
            }
        }

        if($smart_enabled && !empty($cfg['items']['tasks']) && $this->tasks_enabled()){
            $this->ensure_tasks_schema();$table=$wpdb->prefix.'ews_tasks';$uid=$user_id;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT id,title,due_date,status,priority FROM $table WHERE status<>%s AND due_date IS NOT NULL AND due_date<=%s AND (assigned_to=%d OR (assigned_to IS NULL AND created_by=%d)) ORDER BY due_date ASC, id DESC LIMIT 5",'completed',$today,(int)$emp->id,$uid));
            foreach($rows as $r){
                $overdue=((string)$r->due_date<$today);$label=$overdue?'Task overdue':'Due today';
                $message=$overdue?'“'.wp_strip_all_tags((string)$r->title).'” was due on '.date_i18n('D, d M',strtotime($r->due_date)).'.':'“'.wp_strip_all_tags((string)$r->title).'” is due today.';
                $add('task-'.$r->id.'-'.$r->due_date,'task','📋',$label,$message,$this->app_view_url('tasks'),'Open Task');
            }
        }

        if($smart_enabled && !empty($cfg['items']['leave'])){
            $leave_table=$this->leaves;$pending=$wpdb->get_results($wpdb->prepare("SELECT id,start_date,end_date,requested_days FROM $leave_table WHERE employee_id=%d AND status='Pending' ORDER BY requested_at DESC LIMIT 3",(int)$emp->id));
            foreach($pending as $r){
                $range=$r->start_date===$r->end_date?date_i18n('D, d M',strtotime($r->start_date)):date_i18n('D, d M',strtotime($r->start_date)).' → '.date_i18n('D, d M',strtotime($r->end_date));
                $add('leave-'.$r->id,'leave','🏖','Leave request waiting','Your leave request for '.$range.' is still pending.',$this->app_view_url('vacation'),'View Request');
            }
        }

        if($smart_enabled && !empty($cfg['items']['schedule'])){
            $tomorrow=date('Y-m-d',strtotime($today.' +1 day'));$next=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",(int)$emp->id,$tomorrow));
            if($next){
                $status=(string)$next->status;$label=$status==='WFH'?'🏠 WFH':($status==='Office'?'🏢 Office':$status);
                $add('schedule-'.$tomorrow,'schedule','📅','Tomorrow’s schedule','You are scheduled for '.$label.' tomorrow.', $this->app_view_url('schedule'),'View Schedule');
            }
        }
        return array_slice($nudges,0,6);
    }

    public function smart_nudges_cron(){
        $started=microtime(true);$last_run=time();update_option('ews_smart_nudges_last_run',$last_run,false);
        $cfg=$this->smart_nudge_settings();
        $smart_enabled=!empty($cfg['enabled']);
        $attendance_enabled=!empty($cfg['items']['attendance']);
        $result=['employees'=>0,'nudges'=>0,'sent'=>0,'skipped'=>0,'reason'=>''];
        if((!$smart_enabled && !$attendance_enabled) || !method_exists($this,'push_custom_notification')){
            $result['reason']='Smart Nudges disabled or Push sender unavailable';update_option('ews_smart_nudges_last_result',$result,false);return;
        }
        global $wpdb;
        $employees=$wpdb->get_results("SELECT id,name,wp_user_id FROM {$this->employees} WHERE active=1 AND wp_user_id>0 AND attendance_enabled=1 ORDER BY id ASC");
        if(!$employees){$result['reason']='No active linked employees';update_option('ews_smart_nudges_last_result',$result,false);return;}
        $result['employees']=count($employees);
        foreach($employees as $emp){
            $uid=(int)$emp->wp_user_id;
            $nudges=$this->smart_nudge_for_employee($emp,$uid);
            if(!$nudges)continue;
            $pushed=get_user_meta($uid,'ews_smart_nudge_pushed',true);
            if(!is_array($pushed))$pushed=[];
            $now=time();
            foreach($pushed as $key=>$ts){
                // Attendance state is an array (count + last_sent); legacy states are timestamps.
                $expiry=is_array($ts)?(int)($ts['last_sent']??0):(int)$ts;
                if($expiry>0 && $expiry<$now-30*DAY_IN_SECONDS)unset($pushed[$key]);
            }
            foreach($nudges as $n){
                $result['nudges']++;
                $id=sanitize_key($n['id']);
                $is_attendance=(strpos($id,'attendance-')===0);
                $state=$pushed[$id]??null;
                if($is_attendance){
                    if(is_array($state)){
                        $count=max(0,(int)($state['count']??0));
                        $last=max(0,(int)($state['last_sent']??0));
                    }elseif($state){
                        // Backward compatibility with the one-shot timestamp format.
                        $count=1;$last=(int)$state;
                    }else{$count=0;$last=0;}
                    $cfg_now=$this->smart_nudge_settings();
                    $max=(int)$cfg_now['attendance_max_reminders'];
                    $repeat=!empty($cfg_now['attendance_repeat']);
                    $interval=max(5,(int)$cfg_now['attendance_repeat_interval'])*60;
                    $should_send=($count===0)||($repeat && $count<$max && $last>0 && $now >= $last+$interval);
                    if(!$should_send){$result['skipped']++;continue;}
                    $sent=$this->push_custom_notification($uid,'Workforce One · '.$n['title'],$n['message'],'smart_nudge',0,$n['url']);
                    if($sent>0){$pushed[$id]=['count'=>$count+1,'last_sent'=>$now];$result['sent']++;}else{$result['skipped']++;}
                    continue;
                }
                if(isset($pushed[$id])){$result['skipped']++;continue;}
                $sent=$this->push_custom_notification($uid,'Workforce One · '.$n['title'],$n['message'],'smart_nudge',0,$n['url']);
                if($sent>0){$pushed[$id]=$now;$result['sent']++;}else{$result['skipped']++;}
            }
            update_user_meta($uid,'ews_smart_nudge_pushed',$pushed);
        }
        $result['reason']='Completed in '.number_format(microtime(true)-$started,3).'s';
        update_option('ews_smart_nudges_last_result',$result,false);
    }

    public function smart_nudge_dismiss(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        $id=sanitize_key($_GET['nudge_id']??'');
        if(!$id)wp_die('Invalid nudge.');
        check_admin_referer('ews_smart_nudge_dismiss_'.$id);
        $raw=get_user_meta(get_current_user_id(),'ews_smart_nudge_dismissed',true);if(!is_array($raw))$raw=[];
        $raw[$id]=time();update_user_meta(get_current_user_id(),'ews_smart_nudge_dismissed',$raw);
        $ref=wp_get_referer();if(!$ref)$ref=$this->app_home_url();wp_safe_redirect($ref);exit;
    }

    private function employee_moments_for_today(){
        global $wpdb;
        if(!(int)$this->option('ews_employee_moments_enabled')) return [];
        $saved=$this->option('ews_employee_moments');
        if(!is_array($saved)||empty($saved)) return [];
        $ids=array_filter(array_map('absint',array_keys($saved)));
        if(!$ids) return [];
        $today=current_time('Y-m-d');
        $out=[];
        $rows=$wpdb->get_results("SELECT id,name FROM {$this->employees} WHERE active=1 AND id IN (".implode(',',$ids).") ORDER BY name ASC");
        foreach($rows as $e){
            $id=(int)$e->id; $row=is_array($saved[$id]??null)?$saved[$id]:[];
            foreach(\WorkforceOne\Settings\Moments::forDate((string)($row['birthday']??''),(string)($row['join_date']??''),$today) as $m){
                if($m['type']==='birthday')$out[]=['type'=>'birthday','employee_id'=>$id,'name'=>$e->name,'icon'=>'🎂','title'=>'Birthday','message'=>'Happy birthday, '.(string)$e->name.'!'];
                elseif($m['type']==='anniversary')$out[]=['type'=>'anniversary','employee_id'=>$id,'name'=>$e->name,'icon'=>'🎉','title'=>'Work Anniversary','message'=>$e->name.' is celebrating '.$m['years'].' '.($m['years']===1?'year':'years').' with the team!'];
                else $out[]=['type'=>'welcome','employee_id'=>$id,'name'=>$e->name,'icon'=>'👋','title'=>'Welcome','message'=>'Welcome to Workforce One, '.$e->name.'!'];
            }
        }
        return $out;
    }

    private function week_dates(){
            $raw=sanitize_text_field(wp_unslash($_GET['week']??current_time('Y-m-d')));
            return $this->week_dates_configured($raw);
        }

    /* Request statuses are stored in English (and compared in code); translate only for display. */
    private function status_label($status){
        $map=['pending'=>__('Pending','workforce-one'),'approved'=>__('Approved','workforce-one'),'rejected'=>__('Rejected','workforce-one'),'cancelled'=>__('Cancelled','workforce-one'),'accepted'=>__('Accepted','workforce-one'),'completed'=>__('Completed','workforce-one'),'expired'=>__('Expired','workforce-one'),'verified'=>__('Verified','workforce-one')];
        return $map[strtolower(trim((string)$status))]??$status;
    }

    /* sign_in_classification() values are stored and compared in English; translate only for display. */
    private function sign_in_classification_label($c){
        $map=['On Time'=>__('On Time','workforce-one'),'Late Arrival'=>__('Late Arrival','workforce-one')];
        return $map[$c]??$c;
    }

    private function time_content(){
            if(!is_user_logged_in())return $this->login_page();
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id)){wp_enqueue_style('workforce-one-time-page');return '<div class="ews-time-card wfo-clock"><div class="ews-time-block wfo-clock-empty"><div class="ews-time-icon">'.\WorkforceOne\Ui\Icons::svg('attendance',30).'</div><h2>'.esc_html__('Attendance tracking is disabled','workforce-one').'</h2><p>'.esc_html__('Attendance is not required for your employee profile.','workforce-one').'</p></div></div>';}
            $sch=$emp?$this->today_schedule_for_employee($emp->id):null;$ev=$emp?$this->today_events($emp->id):[];
            $working=$sch&&$this->schedule_type_requires_sign_in($sch->status);
            $today=$emp?$this->attendance_day($emp->id):current_time('Y-m-d'); // an overnight shift after midnight is still yesterday's
            if($this->company_leave_dates($today,$today))$working=false;$sign_in_open=$this->sign_in_window_open();
            $hours=$this->working_hours($emp?$emp->id:0);$grace_period=$this->attendance_grace_period($emp?$emp->id:0);$face_signin_enabled=$this->face_signin_enabled();$requires_location=$sch?$this->schedule_type_requires_location($sch->status):false;$break_data=($emp&&$this->break_enabled())?$this->break_ui_data($emp->id):null;
            $signin_bounds=$this->sign_in_window_bounds($emp?$emp->id:0);
            $hours_start_label=$this->format_time_label($hours['start']);
            $hours_end_label=$this->format_time_label($hours['end']);
            $sign_in_status_label=isset($ev['sign_in'])?$this->sign_in_classification_label($this->sign_in_classification($ev['sign_in']->event_at,$emp?(int)$emp->id:0)):'';
            $face_enrolled=(bool)$this->face_template_for_employee($emp?$emp->id:0);
            $face_settings=$this->face_signin_settings();
            $face_vendor_url=$this->plugin_url('assets/vendor/face-api/');
            $qr_enabled=$this->presence_qr_enabled();
            $presence_enabled=$this->presence_verification_enabled();
            $presence_url=$this->app_view_url('presence');
            // The picture at the top of the page (display only): avatar, where the employee is in the day, the ring.
            $clock_state=\WorkforceOne\Ui\ClockFace::state($ev,$break_data && !empty($break_data['open']));
            $signed_in_at=isset($ev['sign_in'])?$ev['sign_in']->event_at:(isset($ev['late_sign_in'])?$ev['late_sign_in']->event_at:null);
            $clock_progress=\WorkforceOne\Ui\ClockFace::progress($clock_state,current_time('timestamp'),$signin_bounds,$signed_in_at?(int)strtotime($signed_in_at):null);
            $picture=$this->employee_picture_url($emp);
            $initials=$emp?(\WorkforceOne\Employees\ProfileSummary::initials((string)$emp->name)?:'ME'):'';
            wp_enqueue_style('workforce-one'); // already enqueued on the app page; needed when only [employee_attendance] is used
            wp_enqueue_style('workforce-one-time-page');
            wp_enqueue_script('workforce-one-time');
            if($qr_enabled)wp_enqueue_script('workforce-one-jsqr');
            return $this->render_template('app/time',compact('emp','sch','ev','working','today','sign_in_open','hours','grace_period','face_signin_enabled','requires_location','break_data',
                'signin_bounds','hours_start_label','hours_end_label','sign_in_status_label','face_enrolled','face_settings','face_vendor_url','qr_enabled','presence_enabled','presence_url',
                'clock_state','clock_progress','signed_in_at','picture','initials'));
        }
    private function redirect($args=[]){
            $u=wp_get_referer()?:$this->app_home_url();
            /*
             * Flash messages are one-shot. Clear any previous flash params
             * before adding the new result, otherwise a stale success/error
             * from an earlier action can survive and reappear on an
             * unrelated redirect (e.g. an old swap_sent leaking into an
             * attendance grid save result).
             */
            $u=remove_query_arg(['preview','time_success','time_error','time_reset','time_saved',
                'saved','imported','rejected','grid_saved','grid_skipped','grid_invalid','grid_conflict',
                'leave_sent','leave_error','leave_done','leave_cancel_sent','early_error',
                'break_success','break_error','overtime_sent','overtime_error','overtime_done','overtime_rejected',
                'swap_error','swap_sent','swap_done',
                'vacation_sent','vacation_error','vacation_done','vacation_rejected'], $u);
            wp_safe_redirect(add_query_arg($args,$u));exit;
        }

    public function reports(){return $this->app();}

    public function schedule(){return $this->app();}

    public function attendance(){return $this->app();}
}
