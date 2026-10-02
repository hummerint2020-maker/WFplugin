<?php
if (!defined('ABSPATH')) exit;

trait EWS_Frontend_Trait {

    public function face_rest_routes(){
        register_rest_route('workforce-one/v1','/face/enroll',array('methods'=>'POST','callback'=>array($this,'face_rest_enroll'),'permission_callback'=>function(){return is_user_logged_in();}));
        register_rest_route('workforce-one/v1','/face/verify',array('methods'=>'POST','callback'=>array($this,'face_rest_verify'),'permission_callback'=>function(){return is_user_logged_in();}));
        register_rest_route('workforce-one/v1','/face/reset-request',array('methods'=>'POST','callback'=>array($this,'face_rest_reset_request'),'permission_callback'=>function(){return is_user_logged_in();}));
        register_rest_route('workforce-one/v1','/face/delete',array('methods'=>'POST','callback'=>array($this,'face_rest_delete'),'permission_callback'=>function(){return is_user_logged_in();}));
    }
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
        if(count($tpl)!==count($stored))return new WP_Error('invalid_template',__('Template size mismatch','workforce-one'),array('status'=>400));
        $sum=0;foreach($tpl as $i=>$v){$d=(float)$v-(float)$stored[$i];$sum+=$d*$d;}
        $distance=sqrt($sum);$threshold=(float)$this->face_signin_setting('face_match_threshold',.60);
        $ok=$distance<$threshold;
        $out=array('ok'=>$ok,'distance'=>round($distance,4),'threshold'=>$threshold);
        if($ok)$out['face_token']=$this->face_issue_token();
        return $out;
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
            return ['global'=>(bool)get_option('ews_confirm_global',1),'actions'=>\WorkforceOne\Settings\FeatureSettings::confirmState(get_option('ews_confirmation_actions',[]))];
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
            wp_register_style('workforce-one', $root.'assets/css/workforce-one.css', [], $ver);
            wp_style_add_data('workforce-one', 'rtl', 'replace');
            if(!$this->pwa_is_employee_app_page()) return;
            wp_enqueue_style('workforce-one', $root.'assets/css/workforce-one.css', [], $ver);
            // assets/css/*-rtl.css are generated with rtlcss from the LTR files (see assets/css/README.md).
            wp_style_add_data('workforce-one', 'rtl', 'replace');
            wp_enqueue_script('workforce-one', $root.'assets/js/workforce-one.js', ['wp-i18n'], $ver, true);
            wp_set_script_translations('workforce-one', 'workforce-one', dirname(__DIR__).'/languages');
            wp_add_inline_script('workforce-one','window.ewsConfirmationConfig='.wp_json_encode($this->frontend_confirmation_config()).';','before');
        }

private function layout($title,$body){
            $nav_defaults=\WorkforceOne\Settings\Navigation::DEFAULTS;
            $nav_cfg=get_option('ews_frontend_navigation',[]);
            if(!is_array($nav_cfg))$nav_cfg=[];
            $items=[];
            foreach($nav_defaults as $key=>$def){
                $saved=(isset($nav_cfg[$key])&&is_array($nav_cfg[$key]))?$nav_cfg[$key]:[];
                $item=array_merge($def,$saved);
                if($key==='overtime' && !$this->overtime_enabled())continue;
                if($key==='tasks' && !$this->tasks_enabled())continue;
                if($key==='attendance' && !$this->can('ews_manage_attendance'))continue;
                if($key==='people' && (!$this->can('ews_view_people') || !$this->employee_profile_settings()['enabled']))continue;
                if(($key==='reports'||$key==='attendance-insights') && !$this->can('ews_view_reports'))continue;
                $desktop_label=isset($item['label'])?sanitize_text_field($item['label']):$def['label'];
                $mobile_label=isset($item['mobile_label'])?sanitize_text_field($item['mobile_label']):$desktop_label;
                if($desktop_label==='')$desktop_label=$def['label'];
                if($mobile_label==='')$mobile_label=$def['mobile_label'];
                // Labels still equal to the built-in English defaults are translated; custom labels are shown as entered.
                $nav_i18n=['Dashboard'=>__('Dashboard','workforce-one'),'Schedule'=>__('Schedule','workforce-one'),'Sign In / Out'=>__('Sign In / Out','workforce-one'),'Leave'=>__('Leave','workforce-one'),'Overtime'=>__('Overtime','workforce-one'),'Tasks'=>__('Tasks','workforce-one'),'Attendance'=>__('Attendance','workforce-one'),'Reports'=>__('Reports','workforce-one'),'Attendance Insights'=>__('Attendance Insights','workforce-one'),'People'=>__('People','workforce-one')];
                $desktop_label=$nav_i18n[$desktop_label]??$desktop_label;
                $mobile_label=$nav_i18n[$mobile_label]??$mobile_label;
                $items[]=[
                    'key'=>$key,'icon'=>$def['icon'],
                    'desktop_label'=>$desktop_label,'mobile_label'=>$mobile_label,
                    'desktop_visible'=>!empty($item['desktop_visible']),'mobile_visible'=>!empty($item['mobile_visible']),
                    'desktop_order'=>isset($item['desktop_order'])?(int)$item['desktop_order']:$def['desktop_order'],
                    'mobile_order'=>isset($item['mobile_order'])?(int)$item['mobile_order']:$def['mobile_order'],
                ];
            }
            $desktop_items=array_values(array_filter($items,function($x){return $x['desktop_visible'];}));
            $mobile_items=array_values(array_filter($items,function($x){return $x['mobile_visible'];}));
            usort($desktop_items,function($a,$b){return $a['desktop_order']<=>$b['desktop_order'];});
            usort($mobile_items,function($a,$b){return $a['mobile_order']<=>$b['mobile_order'];});
            // My Profile and Notifications are top-right actions. Employee management is admin-only.
            ob_start();

        echo '';

            echo $this->css();
            
            
            ?>
            <div class="ews-app">
                <div class="ews-mobile-nav" aria-label="<?php esc_attr_e('Workforce One navigation','workforce-one'); ?>">
                    <?php foreach($mobile_items as $x): ?>
                        <a class="<?php echo (($_GET['ews_view']??'dashboard')===$x['key']?'active':''); ?>" href="<?php echo esc_url($this->app_view_url($x['key'])); ?>">
                            <span class="ews-mobile-icon" aria-hidden="true"><?php echo esc_html($x['icon']); ?></span><span><?php echo esc_html($x['mobile_label']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="ews-shell">
                    <aside class="ews-side">
                        <a class="ews-brand" href="<?php echo esc_url(remove_query_arg(["ews_view","leave_error","leave_sent","leave_done","leave_cancel_sent","early_error","time_success","time_error","break_success","break_error","overtime_sent","overtime_error","saved","imported","grid_saved","time_reset","time_saved","swap_error","swap_sent","swap_done","vacation_sent","vacation_error","vacation_done","vacation_rejected"])); ?>" style="display:block;color:inherit;text-decoration:none;cursor:pointer" title="Open Workforce One Dashboard"><span class="ews-brand-name">BA Team</span><span class="ews-brand-product">Workforce One</span></a>
                        <nav class="ews-nav">
                            <?php foreach($desktop_items as $x): ?>
                                <a class="<?php echo (($_GET['ews_view']??'dashboard')===$x['key']?'active':''); ?>" href="<?php echo esc_url($this->app_view_url($x['key'])); ?>"><?php echo esc_html($x['icon'].' '.$x['desktop_label']); ?></a>
                            <?php endforeach; ?>
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink()));?>"><?php esc_html_e('Log out','workforce-one'); ?></a></nav>
                    </aside>
                    <main class="ews-main"><?php $ews_ux=$this->ux_notice_from_query(); if($ews_ux) echo $ews_ux; ?>
                        <div class="ews-pwa-help" style="display:none;margin:0 0 12px;padding:11px 13px;border:1px solid #d0d5dd;border-radius:12px;background:#f8fafc;font-size:12px;color:#475467;">
                <strong>📱 <?php esc_html_e('Mobile shortcut:','workforce-one'); ?></strong> <?php printf(/* translators: %s: "Add to Home Screen" */esc_html__('Use your browser menu → %s to keep Workforce One one tap away.','workforce-one'),'<strong>'.esc_html__('Add to Home Screen','workforce-one').'</strong>'); ?>
            </div>
            <div class="ews-top">
                            <div>
                                <h1 class="ews-title"><?php echo esc_html($title); ?></h1>
                                <div class="ews-sub"><?php esc_html_e('Workforce Management Platform','workforce-one'); ?> <span class="ews-brand-tagline"><?php esc_html_e('One Platform. One Team. One Goal.','workforce-one'); ?></span></div>
                            </div>
                            <div class="ews-mobile-top-actions">
                                <?php echo $this->notification_bell(); ?>
                                <?php
                                $top_emp=$this->current_employee();
                                $top_image_type=$top_emp && !empty($top_emp->profile_image_type)?$top_emp->profile_image_type:'initials';
                                $top_image_url=$top_emp && !empty($top_emp->profile_image_url)?$top_emp->profile_image_url:'';
                                $top_avatar_key=$top_emp && !empty($top_emp->avatar_key)?$top_emp->avatar_key:'';
                                $top_avatar_url=($top_image_type==='avatar'&&$top_avatar_key)?$this->profile_avatar_url($top_avatar_key):'';
                                $top_initials='';
                                if($top_emp){ foreach(preg_split('/\s+/',trim((string)$top_emp->name)) as $top_part){ if($top_part!=='')$top_initials.=mb_strtoupper(mb_substr($top_part,0,1)); if(mb_strlen($top_initials)>=2)break; } }
                                if($top_initials==='')$top_initials='ME';
                                ?>
                                <div class="ews-profile-menu">
                                    <button type="button" class="ews-profile-menu-trigger" aria-label="<?php esc_attr_e('My Profile menu','workforce-one'); ?>" aria-expanded="false" aria-controls="ews-profile-menu-dropdown">
                                        <?php if($top_image_type==='photo' && $top_image_url): ?>
                                            <img src="<?php echo esc_url($top_image_url); ?>" alt="" class="ews-profile-menu-avatar">
                                        <?php elseif($top_avatar_url): ?>
                                            <img src="<?php echo esc_url($top_avatar_url); ?>" alt="" class="ews-profile-menu-avatar">
                                        <?php else: ?>
                                            <span class="ews-profile-menu-initials" aria-hidden="true"><?php echo esc_html($top_initials); ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <div id="ews-profile-menu-dropdown" class="ews-profile-menu-dropdown" hidden>
                                        <a href="<?php echo esc_url($this->app_view_url('profile')); ?>"><span class="ews-profile-menu-item-icon">👤</span><span><?php esc_html_e('My Profile','workforce-one'); ?></span></a>
                                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>"><span class="ews-profile-menu-item-icon">↪</span><span><?php esc_html_e('Log out','workforce-one'); ?></span></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php echo $body; ?>
                    </main>
                </div>
            </div>
            <?php
            echo '<script>
    document.addEventListener("DOMContentLoaded",function(){
        var profileTrigger=document.querySelector(".ews-profile-menu-trigger");
        var profileMenu=document.getElementById("ews-profile-menu-dropdown");
        if(profileTrigger && profileMenu){
            profileTrigger.addEventListener("click",function(e){
                e.preventDefault();
                var open=profileTrigger.getAttribute("aria-expanded")==="true";
                profileTrigger.setAttribute("aria-expanded",open?"false":"true");
                profileMenu.hidden=open;
            });
            document.addEventListener("click",function(e){
                if(!e.target.closest(".ews-profile-menu")){
                    profileTrigger.setAttribute("aria-expanded","false");
                    profileMenu.hidden=true;
                }
            });
        }
        /* Flash messages are one-shot. Remove their query parameters immediately
           so refresh/navigation cannot replay an old success/error message. */
        try{
            var u=new URL(window.location.href);
            ["leave_error","leave_sent","leave_done","leave_cancel_sent","early_error","time_success","time_error","time_reset","time_saved","saved","imported","grid_saved","profile_updated","profile_error","password_updated","password_error"].forEach(function(k){u.searchParams.delete(k);});
            window.history.replaceState({},document.title,u.toString());
        }catch(e){}
        document.querySelectorAll(".ews-notice,.ews-time-success,.ews-time-error,.ews-profile-notice").forEach(function(el){
            setTimeout(function(){
                el.style.transition="opacity .35s ease, max-height .35s ease, margin .35s ease, padding .35s ease";
                el.style.opacity="0";
                el.style.maxHeight="0";
                el.style.marginTop="0";
                el.style.marginBottom="0";
                el.style.paddingTop="0";
                el.style.paddingBottom="0";
                setTimeout(function(){if(el&&el.parentNode)el.parentNode.removeChild(el);},400);
            },3000);
        });
    });
            </script>';
            return ob_get_clean();
        
}

        public function app(){
            if(!is_user_logged_in()) return $this->login_page();
            return $this->render_app_view(sanitize_key($_GET['ews_view']??'dashboard'));
        }

        public function app_forced_view($view){
            if(!is_user_logged_in()) return $this->login_page();
            return $this->render_app_view(sanitize_key($view));
        }

        private function render_app_view($view){
            $allowed=['dashboard','schedule','time','attendance','employees','reports','attendance-insights','vacation','overtime','tasks','notifications','profile','people','employee','presence'];
            if(!in_array($view,$allowed,true))$view='dashboard';
            if($view==='schedule')$content=$this->schedule_content();
            elseif($view==='attendance'&&$this->can('ews_manage_attendance'))$content=$this->attendance_content();
            elseif($view==='time')$content=$this->time_content();
            elseif($view==='employees'&&$this->can('ews_manage_employees'))$content=$this->employees_content();
            elseif($view==='reports'&&$this->can('ews_view_reports'))$content=$this->reports_content();
            elseif($view==='attendance-insights'&&$this->can('ews_view_reports'))$content=$this->attendance_insights_content();
            elseif($view==='vacation')$content=$this->vacation_content();
            elseif($view==='overtime')$content=$this->overtime_content();
            elseif($view==='tasks')$content=$this->tasks_content();
            elseif($view==='notifications')$content=$this->notifications_content();
            elseif($view==='profile')$content=$this->my_profile_content();
            elseif($view==='people' && $this->can('ews_view_people') && $this->employee_profile_settings()['enabled'])$content=$this->people_content();
            elseif($view==='employee' && $this->can('ews_view_people') && $this->employee_profile_settings()['enabled'])$content=$this->employee_profile_content(absint($_GET['employee_id']??0));
            elseif($view==='presence')$content=$this->presence_content();
            else $content=$this->dashboard_content();
            $titles=['dashboard'=>__('Dashboard','workforce-one'),'schedule'=>__('Schedule','workforce-one'),'time'=>__('Time','workforce-one'),'attendance'=>__('Attendance','workforce-one'),'employees'=>__('Employees','workforce-one'),'reports'=>__('Reports','workforce-one'),'attendance-insights'=>__('Attendance Insights','workforce-one'),'vacation'=>__('Leave','workforce-one'),'overtime'=>__('Overtime','workforce-one'),'tasks'=>__('Tasks','workforce-one'),'notifications'=>__('Notifications','workforce-one'),'profile'=>__('My Profile','workforce-one'),'people'=>__('People','workforce-one'),'employee'=>__('Employee Profile','workforce-one'),'presence'=>__('Presence Verification','workforce-one')];
            $layout_title=$titles[$view]??ucwords(str_replace('-',' ',$view));
            return $this->layout($layout_title,$content);
        }

    private function people_content(){
            global $wpdb;
            if(!$this->can('ews_view_people'))return $this->ews_empty_state('Access denied','People is not available for your account.');
            $search=sanitize_text_field(wp_unslash($_GET['people_search']??''));
            $team_id=absint($_GET['people_team']??0);
            $where=["active=1"];$args=[];
            if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(name LIKE %s OR domain_name LIKE %s OR email LIKE %s)';$args[]=$like;$args[]=$like;$args[]=$like;}
            if($team_id && method_exists($this,'team_tables')){
                $tt=$this->team_tables();
                $ids=$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$tt['members']} WHERE team_id=%d AND active=1",$team_id));
                if(!$ids)$ids=[0];
                $ph=implode(',',array_fill(0,count($ids),'%d'));$where[]="id IN ($ph)";$args=array_merge($args,array_map('absint',$ids));
            }
            $sql="SELECT id,name,domain_name,profile_image_type,profile_image_url,avatar_key FROM {$this->employees} WHERE ".implode(' AND ',$where)." ORDER BY name ASC LIMIT 500";
            $rows=$args?$wpdb->get_results($wpdb->prepare($sql,...$args)):$wpdb->get_results($sql);
            $teams=[];
            if(method_exists($this,'team_tables')){$tt=$this->team_tables();$teams=$wpdb->get_results("SELECT id,name FROM {$tt['teams']} WHERE active=1 ORDER BY name ASC");}
            ob_start();
            ?>
            <div class="ews-card wfo-people-head">
                <div><h3 style="margin:0">People</h3><p class="wfo-people-muted">Find colleagues and view their work profile.</p></div>
                <form method="get" class="wfo-people-filters">
                    <input type="hidden" name="ews_view" value="people">
                    <input type="search" name="people_search" value="<?php echo esc_attr($search); ?>" placeholder="Search people..." aria-label="Search people">
                    <select name="people_team" aria-label="Filter by team"><option value="0">All Teams</option><?php foreach((array)$teams as $t): ?><option value="<?php echo (int)$t->id; ?>" <?php selected($team_id,(int)$t->id); ?>><?php echo esc_html($t->name); ?></option><?php endforeach; ?></select>
                    <button class="ews-btn" type="submit">Search</button>
                    <?php if($search!==''||$team_id): ?><a class="ews-btn secondary" href="<?php echo esc_url($this->app_view_url('people')); ?>">Clear</a><?php endif; ?>
                </form>
            </div>
            <div class="wfo-people-grid">
            <?php foreach((array)$rows as $r):
                $initials='';foreach(preg_split('/\s+/',trim((string)$r->name)) as $part){if($part!=='')$initials.=mb_strtoupper(mb_substr($part,0,1));if(mb_strlen($initials)>=2)break;}if($initials==='')$initials='ME';
                $img='';if($r->profile_image_type==='photo'&&!empty($r->profile_image_url))$img=$r->profile_image_url;elseif($r->profile_image_type==='avatar'&&!empty($r->avatar_key))$img=$this->profile_avatar_url($r->avatar_key);
                $url=add_query_arg(['ews_view'=>'employee','employee_id'=>(int)$r->id],$this->app_view_url('people'));
            ?>
                <a class="wfo-person-card" href="<?php echo esc_url($url); ?>">
                    <div class="wfo-person-avatar"><?php if($img): ?><img src="<?php echo esc_url($img); ?>" alt=""><?php else: ?><span><?php echo esc_html($initials); ?></span><?php endif; ?></div>
                    <div class="wfo-person-main"><strong><?php echo esc_html($r->name); ?></strong><span><?php echo esc_html($r->domain_name); ?></span></div><span class="wfo-person-arrow">›</span>
                </a>
            <?php endforeach; ?>
            </div>
            <?php if(!$rows): ?><div class="ews-card"><div class="ews-empty-state"><div class="ews-empty-icon">👥</div><div class="ews-empty-title">No people found</div><div class="ews-empty-text">Try another name or team.</div></div></div><?php endif; ?>
            <?php
            return ob_get_clean();
        }

        private function employee_profile_content($employee_id=0){
            global $wpdb;
            $cfg=$this->employee_profile_settings();
            if(!$this->can('ews_view_people') || empty($cfg['enabled']))return $this->ews_empty_state('Profiles unavailable','Employee profiles are not available for your account.');
            if(!$employee_id)return $this->ews_empty_state('Employee not found','No employee was selected.');
            $select=['id','name','active'];
            if(!empty($cfg['show_photo']))$select=array_merge($select,['profile_image_type','profile_image_url','avatar_key']);
            if(!empty($cfg['show_email']))$select[]='email';
            if(!empty($cfg['show_supervisor']))$select[]='supervisor_id';
            $emp=$wpdb->get_row($wpdb->prepare("SELECT ".implode(',',array_unique($select))." FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
            if(!$emp)return $this->ews_empty_state('Employee not found','This employee is unavailable.');
            $teams=[];
            if(!empty($cfg['show_team'])&&method_exists($this,'team_ids_for_employee')&&method_exists($this,'team_tables')){
                $ids=$this->team_ids_for_employee((int)$emp->id);
                if($ids){$tt=$this->team_tables();$ph=implode(',',array_fill(0,count($ids),'%d'));$teams=$wpdb->get_results($wpdb->prepare("SELECT id,name FROM {$tt['teams']} WHERE id IN ($ph) AND active=1 ORDER BY name ASC",...$ids));}
            }
            $supervisor='';
            if(!empty($cfg['show_supervisor'])&&!empty($emp->supervisor_id)){
                $sup=$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",absint($emp->supervisor_id)));
                $supervisor=$sup?(string)$sup:'';
            }
            $initials='';foreach(preg_split('/\s+/',trim((string)$emp->name)) as $part){if($part!=='')$initials.=mb_strtoupper(mb_substr($part,0,1));if(mb_strlen($initials)>=2)break;}if($initials==='')$initials='ME';
            $img='';if(!empty($cfg['show_photo'])&&isset($emp->profile_image_type)){if($emp->profile_image_type==='photo'&&!empty($emp->profile_image_url))$img=$emp->profile_image_url;elseif($emp->profile_image_type==='avatar'&&!empty($emp->avatar_key))$img=$this->profile_avatar_url($emp->avatar_key);}
            ob_start(); ?>
            <style>
            .wfo-employee-profile-head{display:flex;align-items:center;gap:16px;margin:0 0 16px;padding:22px 24px;border:1px solid #e0e7ff;border-radius:18px;background:linear-gradient(135deg,#eef4ff 0%,#f7f5ff 58%,#eef2ff 100%);position:relative;overflow:hidden}.wfo-employee-profile-head:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;right:-80px;top:-125px;background:rgba(99,102,241,.10)}.wfo-employee-profile-person{display:flex;align-items:center;gap:16px;min-width:0;position:relative;z-index:1}.wfo-employee-profile-avatar{width:84px;height:84px;border-radius:24px;background:#eef2ff;border:1px solid #dbe4ff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#3158c8;overflow:hidden;flex:0 0 84px;box-shadow:0 8px 22px rgba(49,88,200,.10)}.wfo-employee-profile-avatar img{width:100%;height:100%;object-fit:cover}.wfo-employee-profile-title{min-width:0}.wfo-employee-profile-title h2{margin:0;font-size:29px;line-height:1.15;color:#101828}.wfo-employee-profile-empty-head{min-height:18px}.wfo-employee-profile-work{margin-bottom:16px}.wfo-employee-profile-work h3{margin-bottom:14px}.wfo-employee-profile-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.wfo-employee-profile-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e4e7ec;background:rgba(255,255,255,.78);color:#475467;font-size:12px}.ews-my-profile-panel{background:#fff;border:1px solid #e4e7ec;border-radius:14px;overflow:hidden;margin-bottom:16px;box-shadow:0 3px 12px rgba(16,24,40,.025)}.ews-section-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 18px;border-bottom:1px solid #eef0f3}.ews-section-head h3{font-size:16px;margin:0;color:#101828}.ews-section-head p{margin:3px 0 0;color:#667085;font-size:11px}.ews-my-profile-section-link{color:#3158c8;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap;padding:6px 8px;border-radius:8px}.ews-my-profile-body{padding:16px 18px}.ews-achievements-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ews-achievement-card{display:flex;gap:11px;align-items:flex-start;border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd}.ews-achievement-icon{width:42px;height:42px;border-radius:12px;background:#fff7e6;display:flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 auto}.ews-achievement-name{font-weight:800;color:#101828;font-size:13px}.ews-achievement-desc{font-size:11px;color:#667085;margin-top:3px;line-height:1.45}.ews-achievement-date{font-size:10px;color:#98a2b3;margin-top:6px}.wfo-kudos-action{margin-bottom:12px}.wfo-kudos-form{border:1px solid #e4e7ec;border-radius:12px;background:#fcfcfd;padding:14px;margin-bottom:14px}.wfo-kudos-form label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:10px}.wfo-kudos-form select,.wfo-kudos-form textarea{display:block;width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #d0d5dd;border-radius:9px;padding:10px;background:#fff;font:inherit;font-size:13px}.wfo-kudos-optional{font-weight:400;color:#98a2b3}.wfo-kudos-form-actions{display:flex;gap:8px}.wfo-kudos-form-actions .secondary{background:#fff}.wfo-recognition-list{display:grid;gap:10px}.wfo-recognition-item{display:flex;gap:11px;align-items:flex-start;border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd}.wfo-recognition-icon{width:40px;height:40px;border-radius:12px;background:#fff7e6;display:flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 auto}.wfo-recognition-title{font-size:12px;color:#475467}.wfo-recognition-title strong{color:#101828}.wfo-recognition-message{font-size:12px;color:#344054;margin-top:4px;line-height:1.45}.wfo-recognition-date{font-size:10px;color:#98a2b3;margin-top:5px}.wfo-recognition-notice{border-radius:9px;padding:9px 11px;font-size:12px;margin-bottom:12px}.wfo-recognition-notice.success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.wfo-recognition-notice.error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}
            @media(max-width:700px){.wfo-employee-profile-head{padding:18px}.wfo-employee-profile-avatar{width:68px;height:68px;flex-basis:68px;border-radius:20px}.wfo-employee-profile-title h2{font-size:23px}.ews-achievements-grid{grid-template-columns:1fr}.ews-section-head{padding:12px 14px}.ews-section-head h3{font-size:15px}.ews-my-profile-body{padding:12px 14px}}
            </style>
            <div class="wfo-profile-back"><a href="<?php echo esc_url($this->app_view_url('people')); ?>">← Back to People</a></div>
            <?php if(!empty($cfg['show_photo']) || !empty($cfg['show_name'])): ?>
            <div class="wfo-employee-profile-head ews-card">
                <div class="wfo-employee-profile-person">
                    <?php if(!empty($cfg['show_photo'])): ?><div class="wfo-employee-profile-avatar"><?php if($img): ?><img src="<?php echo esc_url($img); ?>" alt=""><?php else: ?><span><?php echo esc_html($initials); ?></span><?php endif; ?></div><?php endif; ?>
                    <?php if(!empty($cfg['show_name'])): ?><div class="wfo-employee-profile-title"><h2><?php echo esc_html($emp->name); ?></h2></div><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if(!empty($cfg['show_team']) || !empty($cfg['show_email']) || !empty($cfg['show_supervisor'])): ?>
            <div class="ews-card wfo-employee-profile-work"><h3>Work Profile</h3><div class="wfo-profile-fields">
                <?php if(!empty($cfg['show_team'])): ?><div><span>Teams</span><strong><?php echo $teams?esc_html(implode(', ',array_map(function($t){return $t->name;},$teams))):'No team assigned'; ?></strong></div><?php endif; ?>
                <?php if(!empty($cfg['show_email'])): ?><div><span>Work Email</span><strong><?php echo !empty($emp->email)?esc_html($emp->email):'Not provided'; ?></strong></div><?php endif; ?>
                <?php if(!empty($cfg['show_supervisor'])): ?><div><span>Supervisor</span><strong><?php echo $supervisor?esc_html($supervisor):'Not assigned'; ?></strong></div><?php endif; ?>
            </div></div>
            <?php endif; ?>
            <?php if(!empty($cfg['show_achievements'])): $profile_achievements=method_exists($this,'employee_achievements')?$this->employee_achievements((int)$emp->id):[]; ?>
            <section class="ews-my-profile-panel">
                <div class="ews-section-head"><div><h3>🏆 Achievements</h3><p>Your earned milestones</p></div><span class="ews-my-profile-section-link"><?php echo (int)count($profile_achievements); ?> earned</span></div>
                <div class="ews-my-profile-body"><div class="ews-achievements-grid">
                    <?php if(!$profile_achievements): ?><div class="ews-profile-photo-help">Your achievements will appear here as you reach milestones.</div>
                    <?php else: foreach((array)$profile_achievements as $achievement): ?><div class="ews-achievement-card"><div class="ews-achievement-icon <?php echo esc_attr($achievement->badge_style?:'circle'); ?>"><?php echo esc_html($achievement->icon); ?></div><div><div class="ews-achievement-name"><?php echo esc_html($achievement->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($achievement->description); ?></div><div class="ews-achievement-date">Earned <?php echo esc_html(date_i18n(get_option('date_format'),strtotime($achievement->earned_at))); ?></div></div></div><?php endforeach; endif; ?>
                </div></div>
            </section><?php endif; ?>
            <?php if(!empty($cfg['show_recognition']) && method_exists($this,'recognition_enabled') && $this->recognition_enabled()):
                $recognition_rows=method_exists($this,'recognition_rows')?$this->recognition_rows((int)$emp->id):[];
                $viewer=$this->current_employee(); $can_kudos=$viewer && (int)$viewer->id!==(int)$emp->id && method_exists($this,'recognition_allow_kudos') && $this->recognition_allow_kudos();
                $kudos_error=sanitize_key($_GET['kudos_error']??''); $kudos_sent=!empty($_GET['kudos_sent']);
                $kudos_notice_key='wfo_kudos_notice_'.get_current_user_id().'_'.(int)$emp->id;
                $kudos_notice=get_transient($kudos_notice_key);
                if($kudos_notice==='success'){ $kudos_sent=true; $kudos_error=''; delete_transient($kudos_notice_key); }
                elseif($kudos_notice==='error'){ $kudos_error='error'; $kudos_sent=false; delete_transient($kudos_notice_key); }
            ?>
            <section class="ews-my-profile-panel wfo-recognition-panel">
                <div class="ews-section-head"><div><h3>👏 Recognition</h3><p>Appreciation from your colleagues</p></div><span class="ews-my-profile-section-link"><?php echo (int)count($recognition_rows); ?> received</span></div>
                <div class="ews-my-profile-body">
                    <?php if($kudos_sent): ?><div class="wfo-recognition-notice success">Kudos sent successfully.</div><?php elseif($kudos_error): ?><div class="wfo-recognition-notice error">Unable to send Kudos. Please check the details and try again.</div><?php endif; ?>
                    <?php if($can_kudos): ?>
                    <div class="wfo-kudos-action"><button type="button" class="ews-btn" onclick="var f=document.getElementById('wfo-kudos-form');f.hidden=!f.hidden;if(!f.hidden)f.scrollIntoView({behavior:'smooth',block:'nearest'});">👏 Give Kudos</button></div>
                    <form id="wfo-kudos-form" class="wfo-kudos-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
                        <?php echo wp_nonce_field('ews_kudos_submit','ews_kudos_nonce',true,false); ?><input type="hidden" name="action" value="ews_kudos_submit"><input type="hidden" name="recipient_employee_id" value="<?php echo (int)$emp->id; ?>">
                        <label>What are you recognizing?<select name="category" required><?php foreach($this->recognition_categories() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                        <label>Message <span class="wfo-kudos-optional">(optional)</span><textarea name="message" rows="3" maxlength="500" placeholder="Write a short thank-you..."></textarea></label>
                        <div class="wfo-kudos-form-actions"><button type="submit" class="ews-btn">Send Kudos</button><button type="button" class="ews-btn secondary" onclick="document.getElementById('wfo-kudos-form').hidden=true;">Cancel</button></div>
                    </form>
                    <?php endif; ?>
                    <?php if($recognition_rows): ?><div class="wfo-recognition-list"><?php foreach($recognition_rows as $row): ?><article class="wfo-recognition-item"><div class="wfo-recognition-icon">👏</div><div><div class="wfo-recognition-title"><strong><?php echo esc_html($row->sender_name?:'A colleague'); ?></strong> · <?php echo esc_html($this->recognition_category_label($row->category)); ?></div><?php if($row->message!==''): ?><div class="wfo-recognition-message">“<?php echo esc_html($row->message); ?>”</div><?php endif; ?><div class="wfo-recognition-date"><?php echo esc_html(date_i18n(get_option('date_format'),strtotime($row->created_at))); ?></div></div></article><?php endforeach; ?></div><?php elseif(!$can_kudos): ?><div class="ews-empty-state"><div class="ews-empty-icon">👏</div><div class="ews-empty-title">No recognition yet</div><div class="ews-empty-text">Recognition from colleagues will appear here.</div></div><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
            <?php return ob_get_clean();
        }

    public function login_page(){
            if(is_user_logged_in()){
                $url=remove_query_arg(['login','loggedout','login_error']);
                if(!$url) $url=home_url("/");
                return '<div class="ews-login-wrap"><div class="ews-login-card"><div class="ews-login-brand">Workforce One</div><h2>You are already signed in</h2><p><a class="ews-login-btn" href="'.esc_url($url).'">Open Workforce One</a> <a class="ews-login-link" href="'.esc_url(wp_logout_url($url)).'">Log out</a></p></div></div>';
            }
    
            $redirect=esc_url_raw(wp_unslash($_GET['redirect_to']??get_permalink()?:home_url("/")));
            $error='';
            if(isset($_GET['login_error'])) $error='The username or password is incorrect. Please try again.';
            $message=isset($_GET['loggedout'])?'You have been logged out successfully.':'';
    
            ob_start();
            echo $this->login_css();
            ?>
            <div class="ews-login-wrap">
                <div class="ews-login-card">
                    <div class="ews-login-logo">BA Team<span>Workforce One</span></div>
                    <h1>Welcome back</h1>
                    <p class="ews-login-sub">Workforce Management Platform</p>
                    <?php if($error): ?><div class="ews-login-error"><?php echo esc_html($error); ?></div><?php endif; ?>
                    <?php if($message): ?><div class="ews-login-success"><?php echo esc_html($message); ?></div><?php endif; ?>
                    <?php
                    wp_login_form([
                        'echo'=>true,
                        'redirect'=>$redirect,
                        'remember'=>true,
                        'label_username'=>'Username or Email',
                        'label_password'=>'Password',
                        'label_remember'=>'Remember me',
                        'label_log_in'=>'Sign In',
                        'id_username'=>'ews_login_username',
                        'id_password'=>'ews_login_password',
                        'id_remember'=>'ews_login_remember',
                        'id_submit'=>'ews_login_submit',
                        'value_username'=>'',
                        'value_remember'=>false
                    ]);
                    ?>
                    <div class="ews-login-forgot">
                        <a href="<?php echo esc_url(wp_lostpassword_url($redirect)); ?>">Forgot your password?</a>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

    private function login_css(){
            return '<style>
            .ews-login-wrap{min-height:620px;background:#f6f8fb;display:flex;align-items:center;justify-content:center;padding:35px 15px;font-family:Inter,Arial,sans-serif}
            .ews-login-card{width:100%;max-width:430px;background:#fff;border:1px solid #e6eaf0;border-radius:20px;padding:38px;box-shadow:0 15px 45px rgba(16,24,40,.10)}
            .ews-login-logo{font-size:25px;font-weight:800;color:#172033;margin-bottom:25px;text-align:center}
            .ews-login-card h1{text-align:center;margin:0;color:#172033;font-size:28px}.ews-login-sub{text-align:center;color:#667085;margin:8px 0 25px}
            .ews-login-card form p{margin:0 0 15px}.ews-login-card label{display:block;font-size:13px;font-weight:700;color:#344054;margin-bottom:6px}
            .ews-login-card input[type=text],.ews-login-card input[type=password]{width:100%;padding:12px;border:1px solid #d0d5dd;border-radius:9px;background:#fff;font-size:15px}
            .ews-login-card input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
            .ews-login-card .login-remember{display:flex;align-items:center;gap:7px;font-size:13px;color:#475467;margin:12px 0 18px}
            .ews-login-card .login-remember input{width:auto}.ews-login-card .login-submit{margin-top:8px}
            .ews-login-card input[type=submit],.ews-login-btn{width:100%;display:inline-block;text-align:center;border:0;background:#2563eb;color:#fff;text-decoration:none;border-radius:9px;padding:12px;font-size:15px;font-weight:800;cursor:pointer}
            .ews-login-error{background:#fef2f2;color:#b42318;border:1px solid #fecdca;border-radius:9px;padding:10px 12px;margin-bottom:15px;font-size:13px}
            .ews-login-success{background:#ecfdf3;color:#027a48;border:1px solid #abefc6;border-radius:9px;padding:10px 12px;margin-bottom:15px;font-size:13px}
            .ews-login-forgot{text-align:center;margin-top:18px}.ews-login-forgot a,.ews-login-link{color:#2563eb;text-decoration:none;font-size:13px}
            </style>';
        }

    public function after_logout(){
            // WordPress handles the actual session termination. The Workforce One page
            // will automatically show the login screen on the next request.
        }


    private function smart_nudge_settings(){
        return \WorkforceOne\Settings\SmartNudges::config(get_option('ews_smart_nudges',[]));
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
        $ref=wp_get_referer();if(!$ref)$ref=home_url('/');wp_safe_redirect($ref);exit;
    }

    private function employee_moments_for_today(){
        global $wpdb;
        if(!(int)get_option('ews_employee_moments_enabled',1)) return [];
        $saved=get_option('ews_employee_moments',[]);
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
            if($emp && !$this->employee_attendance_enabled((int)$emp->id))return '<div class="ews-time-card"><div class="ews-time-block"><div class="ews-time-icon">📋</div><h2>'.esc_html__('Attendance tracking is disabled','workforce-one').'</h2><p>'.esc_html__('Attendance is not required for your employee profile.','workforce-one').'</p></div></div>';
            $sch=$emp?$this->today_schedule_for_employee($emp->id):null;$ev=$emp?$this->today_events($emp->id):[];
            $working=$sch&&$this->schedule_type_requires_sign_in($sch->status);
            $today=current_time('Y-m-d');
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
            wp_enqueue_style('workforce-one'); // already enqueued on the app page; needed when only [employee_attendance] is used
            wp_enqueue_script('workforce-one-time');
            if($qr_enabled)wp_enqueue_script('workforce-one-jsqr');
            return $this->render_template('app/time',compact('emp','sch','ev','working','today','sign_in_open','hours','grace_period','face_signin_enabled','requires_location','break_data',
                'signin_bounds','hours_start_label','hours_end_label','sign_in_status_label','face_enrolled','face_settings','face_vendor_url','qr_enabled','presence_enabled','presence_url'));
        }
    private function redirect($args=[]){
            $u=wp_get_referer()?:home_url("/");
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
