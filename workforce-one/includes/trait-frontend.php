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

    private function vacation_content(){
            if(!is_user_logged_in())return $this->login_page();
            $this->ensure_leave_schema(); global $wpdb; $emp=$this->current_employee(); $today=current_time('Y-m-d');
            $types=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY id ASC");
            $balances=[]; if($emp){foreach($types as $lt){$b=$this->ensure_leave_balance($emp->id,$lt->id);$balances[$lt->id]=$b;}}
            $html='<style>
            .ews-vac{max-width:1050px;margin:0 auto}.ews-vac-hero{background:linear-gradient(135deg,#f7f4ff,#fff);border:1px solid #e7e1f7;border-radius:20px;padding:24px 26px;margin-bottom:18px}.ews-vac-kicker{font-size:11px;letter-spacing:.12em;font-weight:800;color:#7c5cff;text-transform:uppercase}.ews-vac-hero h2{margin:4px 0;font-size:28px}.ews-vac-hero p{margin:7px 0;color:#667085}.ews-vac-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px}.ews-vac-card{background:#fff;border:1px solid #eaecf0;border-radius:18px;padding:22px;box-shadow:0 5px 18px rgba(16,24,40,.05)}.ews-vac-fields{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:18px}.ews-vac-field label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:6px}.ews-vac-field input,.ews-vac-field select,.ews-vac-field textarea{box-sizing:border-box;width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;background:#fff}.ews-vac-count{margin-top:14px;padding:13px 14px;border-radius:12px;background:#f7f4ff;border:1px solid #e7e1f7;color:#4c3a9e;font-weight:700}.ews-vac-submit{width:100%;margin-top:16px!important;justify-content:center}.ews-bal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}.ews-bal{padding:12px;border-radius:12px;background:#f8fafc;border:1px solid #eaecf0}.ews-bal strong{display:block;font-size:20px;color:#101828}.ews-bal span{font-size:11px;color:#667085}.ews-vac-row{padding:15px 0;border-top:1px solid #eaecf0;display:flex;justify-content:space-between;gap:15px;align-items:center}.ews-vac-date{font-weight:800}.ews-vac-meta{font-size:12px;color:#667085;margin-top:4px}.ews-vac-status{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap}.ews-modal-backdrop{position:fixed;inset:0;z-index:99999;background:rgba(16,24,40,.48);display:flex;align-items:center;justify-content:center;padding:20px;box-sizing:border-box}.ews-modal-card{position:relative;width:min(440px,100%);background:#fff;border-radius:18px;padding:28px 26px 24px;box-shadow:0 24px 70px rgba(16,24,40,.25);text-align:center}.ews-modal-card h3{margin:12px 0 8px;font-size:21px;color:#101828}.ews-modal-card p{margin:0;color:#475467;font-size:14px;line-height:1.6}.ews-modal-icon{width:42px;height:42px;margin:0 auto;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#f04438;color:#fff;font-weight:800;font-size:20px}.ews-modal-close{position:absolute;right:12px;top:10px;border:0;background:transparent;color:#667085;font-size:26px;line-height:1;cursor:pointer}.ews-modal-ok{margin-top:20px;min-width:100px;border:0;border-radius:10px;padding:10px 20px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}.ews-modal-ok:hover{filter:brightness(.96)}.ews-inline-alert{display:flex;align-items:flex-start;gap:12px;margin:0 0 18px;padding:14px 16px;border-radius:12px;border:1px solid}.ews-inline-alert-error{background:#fff7f7;border-color:#fecdca;color:#b42318}.ews-inline-alert-icon{display:flex;align-items:center;justify-content:center;flex:0 0 22px;height:22px;border-radius:50%;background:#f04438;color:#fff;font-size:13px;font-weight:800}.ews-inline-alert strong{display:block;margin-bottom:3px;color:#912018}.ews-inline-alert div div{color:#7a271a;font-size:13px;line-height:1.5}.ews-vac-pending{background:#fff7ed;color:#9a3412}.ews-vac-approved{background:#ecfdf3;color:#027a48}.ews-vac-rejected{background:#fef3f2;color:#b42318}.ews-vac-cancel{background:#fff7ed;color:#9a3412}.ews-vac-actions{display:flex;gap:7px;margin-top:9px}.ews-vac-actions form{margin:0}@media(max-width:760px){.ews-vac-grid{grid-template-columns:1fr}.ews-vac-fields{grid-template-columns:1fr}.ews-bal-grid{grid-template-columns:1fr}}
            </style><div class="ews-page ews-vac"><div class="ews-vac-hero"><div class="ews-vac-kicker">'.esc_html__('TIME OFF','workforce-one').'</div><h2>'.esc_html__('Leave Management','workforce-one').'</h2><p>'.esc_html__('Request time off, track your balance and follow approvals in one place. Leave Types marked “No balance deduction” are recorded without consuming leave balance.','workforce-one').'</p></div>';
            if(isset($_GET['leave_error'])){$m=['date'=>__('Please select a valid future date range.','workforce-one'),'no_working_days'=>__('The selected range contains no working days.','workforce-one'),'overlap'=>__('You already have a pending or approved leave overlapping this period.','workforce-one'),'balance'=>__('Insufficient available balance for this Leave Type.','workforce-one'),'cross_year'=>__('A leave cannot span two calendar years. Please submit one request for each year.','workforce-one'),'employee'=>__('Your account is not linked to an active employee.','workforce-one')];$html.='<div class="ews-login-error">'.esc_html($m[$_GET['leave_error']]??__('Could not submit the leave request.','workforce-one')).'</div>';}
            if(isset($_GET['leave_sent']))$html.='<div class="ews-notice ews-notice-success">'.esc_html__('Leave request submitted successfully.','workforce-one').'</div>';
            if(isset($_GET['leave_cancel_sent']))$html.='<div class="ews-notice ews-notice-success">'.esc_html__('Cancellation request submitted to your Manager.','workforce-one').'</div>';            if(isset($_GET['early_error'])){
                $early_messages=[
                    'future_date'=>__('Please select a future date for Early Leave.','workforce-one'),
                    'working_day'=>__('Early Leave is available only on a configured working day.','workforce-one'),
                    'office_only'=>__('Early Leave is available only on an Office workday. It cannot be requested on Work From Home or Leave/Vacation days.','workforce-one'),
                    'max_duration'=>__('The requested Early Leave exceeds the maximum duration allowed for one request.','workforce-one'),
                    'monthly_limit'=>__('The requested Early Leave exceeds the remaining monthly allowance.','workforce-one'),
                    'save'=>__('We could not submit the Early Leave request. Please try again.','workforce-one'),
                    'approval_conflict'=>__('This request can no longer be approved because the employee is no longer scheduled for an eligible Office workday.','workforce-one')
                ];
                $early_msg=$early_messages[sanitize_key($_GET['early_error'])]??__('Please review the Early Leave details and try again.','workforce-one');
                $html.='<div id="ews-early-leave-modal" class="ews-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="ews-early-title"><div class="ews-modal-card"><button type="button" class="ews-modal-close" aria-label="Close">×</button><div class="ews-modal-icon">!</div><h3 id="ews-early-title">'.esc_html__('Early Leave Not Available','workforce-one').'</h3><p>'.esc_html($early_msg).'</p><button type="button" class="ews-modal-ok">'.esc_html__('OK','workforce-one').'</button></div></div>';
            }

            if($emp){
                $html.='<div class="ews-vac-grid"><div class="ews-vac-card"><h3>'.esc_html__('Request Leave','workforce-one').'</h3><p style="color:#667085">'.esc_html__('Select a Leave Type and future date range. Working days are calculated automatically.','workforce-one').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_request_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_create"><div class="ews-vac-field" style="margin-top:15px"><label>'.esc_html__('Leave Type','workforce-one').'</label><select name="leave_type_id" required>';
                foreach($types as $lt){$b=$balances[$lt->id];$av=max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending);$html.='<option value="'.(int)$lt->id.'">'.esc_html($lt->name).($lt->deduct_balance?' — '.sprintf(/* translators: %s: available days */__('Available: %s','workforce-one'),rtrim(rtrim(number_format($av,2,'.',''), '0'),'.')):' — '.__('No balance deduction','workforce-one')).'</option>';}
                $html.='</select></div><div class="ews-vac-fields"><div class="ews-vac-field"><label>'.esc_html__('From','workforce-one').'</label><input id="ews-vac-start" type="date" name="start_date" min="'.esc_attr($today).'" required></div><div class="ews-vac-field"><label>'.esc_html__('To','workforce-one').'</label><input id="ews-vac-end" type="date" name="end_date" min="'.esc_attr($today).'" required></div></div><div id="ews-vac-days" class="ews-vac-count">'.esc_html__('Select dates to calculate working days.','workforce-one').'</div><div class="ews-vac-field" style="margin-top:14px"><label>'.esc_html__('Reason','workforce-one').' <span style="color:#667085">'.esc_html__('(optional)','workforce-one').'</span></label><textarea name="reason" rows="3"></textarea></div><button class="ews-btn ews-vac-submit" type="submit">'.esc_html__('Submit Leave Request','workforce-one').'</button></form>';
                $html.='<div class="ews-bal-grid">';
                foreach($types as $lt){$b=$balances[$lt->id];$av=max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending);$html.='<div class="ews-bal"><strong>'.($lt->deduct_balance?esc_html(rtrim(rtrim(number_format($av,2,'.',''), '0'),'.')):'—').'</strong><span>'.esc_html($lt->name).' '.esc_html__('Available','workforce-one').'</span></div>';}$html.='</div></div>';
                $rt=$wpdb->prefix.'ews_leave_requests';
                $show_all=(isset($_GET['leave_history']) && sanitize_key($_GET['leave_history'])==='all');
                $leave_sql="SELECT r.*,lt.name type_name,lt.deduct_balance FROM {$rt} r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC";
                if(!$show_all)$leave_sql.=" LIMIT 6";
                $my=$wpdb->get_results($wpdb->prepare($leave_sql,$emp->id));
                $html.='<div class="ews-vac-card"><div class="ews-vac-section-head"><h3>'.esc_html__('My Leave Requests','workforce-one').'</h3>';
                if($show_all){
                    $recent_url=remove_query_arg('leave_history',$this->app_view_url('vacation'));
                    $html.='<a class="ews-vac-view-all" href="'.esc_url($recent_url).'">← '.esc_html__('Back to recent requests','workforce-one').'</a>';
                }
                $html.='</div><div>';
                if(!$my){
                    $html.=$this->ews_empty_state(__('No Leave Requests Yet','workforce-one'),__('You haven\'t submitted any leave requests yet.','workforce-one'),$this->app_view_url('vacation'),__('Request Leave','workforce-one'));
                }
                foreach($my as $r){$cls=strtolower($r->status)==='approved'?'ews-vac-approved':(strtolower($r->status)==='pending'?'ews-vac-pending':'ews-vac-rejected');$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->type_name).' · '.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-meta">'.(float)$r->requested_days.' working day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div>';
                    if($r->cancellation_status==='Pending')$html.='<div class="ews-vac-meta">'.esc_html__('Cancellation Pending','workforce-one').'</div>';
                    elseif($r->cancellation_status==='Rejected')$html.='<div class="ews-vac-meta">'.esc_html__('Cancellation Rejected — cannot be submitted again.','workforce-one').'</div>';
                    elseif($this->leave_cancellation_allowed($r))$html.='<div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button" type="submit">'.esc_html__('Request Cancellation','workforce-one').'</button></form></div>';
                    elseif($r->status==='Approved' && !empty($r->start_date) && current_time('Y-m-d') >= (string)$r->start_date)$html.='<div class="ews-vac-meta">'.esc_html__('Cancellation unavailable — leave has started.','workforce-one').'</div>';
                    $html.='</div><span class="ews-vac-status '.$cls.'">'.esc_html($this->status_label($r->status)).'</span></div>';}$html.='</div>';
                if(!$show_all && count($my)===6){
                    $all_url=add_query_arg('leave_history','all',remove_query_arg('leave_history',$this->app_view_url('vacation')));
                    $html.='<div class="ews-vac-view-all-wrap"><a class="ews-vac-view-all-button" href="'.esc_url($all_url).'">'.esc_html__('View all leave requests','workforce-one').' <span aria-hidden="true">→</span></a></div>';
                }
                $html.='</div></div>';
                $wd=wp_json_encode(array_values($this->working_days()));$html.='<script>(function(){var a=document.getElementById("ews-vac-start"),b=document.getElementById("ews-vac-end"),o=document.getElementById("ews-vac-days");function f(){if(!a.value||!b.value||b.value<a.value){o.textContent="Select a valid date range.";return}var s=new Date(a.value+"T00:00:00"),e=new Date(b.value+"T00:00:00"),n=0,wd='.$wd.';for(var d=new Date(s);d<=e;d.setDate(d.getDate()+1))if(wd.indexOf(d.getDay())!==-1)n++;o.textContent=n+" working day"+(n===1?"":"s")+" requested.";};a.onchange=f;b.onchange=f;})();</script>';
            }
            $approval_workflow=$this->approval_workflow('vacation');
            if($approval_workflow && (int)$approval_workflow->active===1){
                $rt=$wpdb->prefix.'ews_leave_requests';$at=$this->approval_tables();$uid=get_current_user_id();
                $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,lt.name type_name,ai.step_order FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id JOIN {$at['requests']} ar ON ar.entity_type='leave' AND ar.entity_id=r.id JOIN {$at['instances']} ai ON ai.approval_request_id=ar.id AND ai.status='PENDING' AND ai.approver_wp_user_id=%d WHERE r.status='Pending' ORDER BY r.requested_at ASC",$uid));
                if($rows){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Leave Requests Awaiting Your Approval','workforce-one').'</h3>';foreach($rows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).' · Level '.(int)$r->step_order.'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).' · '.(float)$r->requested_days.' day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">'.esc_html__('Approve','workforce-one').'</button><button class="button" name="decision" value="reject">'.esc_html__('Reject','workforce-one').'</button></form></div></div><span class="ews-vac-status ews-vac-pending">'.esc_html__('Pending','workforce-one').'</span></div>';}$html.='</div>';}
            }elseif($this->can('ews_manage_settings')){
                $rt=$wpdb->prefix.'ews_leave_requests';$rows=$wpdb->get_results("SELECT r.*,e.name employee_name,lt.name type_name FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Pending Leave Requests','workforce-one').'</h3>';foreach($rows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).' · '.(float)$r->requested_days.' day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">'.esc_html__('Approve','workforce-one').'</button><button class="button" name="decision" value="reject">'.esc_html__('Reject','workforce-one').'</button></form></div></div><span class="ews-vac-status ews-vac-pending">'.esc_html__('Pending','workforce-one').'</span></div>';}$html.='</div>';
            }
            $approval_workflow=$this->approval_workflow('vacation');
            if($approval_workflow && (int)$approval_workflow->active===1){
                $rt=$wpdb->prefix.'ews_leave_requests';$at=$this->approval_tables();$uid=get_current_user_id();
                $crows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,lt.name type_name,ai.step_order FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id JOIN {$at['requests']} ar ON ar.entity_type='leave_cancellation' AND ar.entity_id=r.id JOIN {$at['instances']} ai ON ai.approval_request_id=ar.id AND ai.status='PENDING' AND ai.approver_wp_user_id=%d WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC",$uid));
                if($crows){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Leave Cancellations Awaiting Your Approval','workforce-one').'</h3>';foreach($crows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).' · Level '.(int)$r->step_order.'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">'.esc_html__('Approve Cancellation','workforce-one').'</button><button class="button" name="decision" value="reject">'.esc_html__('Reject','workforce-one').'</button></form></div></div><span class="ews-vac-status ews-vac-pending">'.esc_html__('Pending','workforce-one').'</span></div>';}$html.='</div>';}
            }elseif($this->can('ews_manage_settings')){
                $rt=$wpdb->prefix.'ews_leave_requests';$crows=$wpdb->get_results("SELECT r.*,e.name employee_name,lt.name type_name FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Cancellation Requests','workforce-one').'</h3>'; if($crows){foreach($crows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">'.esc_html__('Approve Cancellation','workforce-one').'</button><button class="button" name="decision" value="reject">'.esc_html__('Reject','workforce-one').'</button></form></div></div></div>';}$html.='</div>';}
            }
            if($emp){
                $monthly=(int)get_option('ews_early_leave_monthly_minutes',240);$used_early=$this->early_leave_month_usage($emp->id,substr($today,0,7));$remaining_early=max(0,$monthly-$used_early);
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Early Leave','workforce-one').'</h3><p style="color:#667085">'.esc_html(sprintf(/* translators: 1: max hours per request, 2: monthly allowance in hours, 3: remaining hours */__('Available only on Office workdays · Maximum per request: %1$s hours · Monthly allowance: %2$s hours · Remaining this month: %3$s hours.','workforce-one'),((int)get_option('ews_early_leave_max_minutes',120)/60),($monthly/60),($remaining_early/60))).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_early_leave_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_early_leave_create"><input type="hidden" id="ews-early-remaining" value="'.$remaining_early.'"><input type="hidden" id="ews-early-working-days" value="'.esc_attr(wp_json_encode(array_values($this->working_days()))).'"><div class="ews-vac-fields"><div class="ews-vac-field"><label>'.esc_html__('Date','workforce-one').'</label><input type="date" id="ews-early-date" name="work_date" min="'.esc_attr($today).'" required></div><div class="ews-vac-field"><label>'.esc_html__('Duration (minutes)','workforce-one').'</label><input type="number" id="ews-early-minutes" name="leave_minutes" min="1" max="'.(int)get_option('ews_early_leave_max_minutes',120).'" required></div></div><div class="ews-vac-field" style="margin-top:12px"><label>'.esc_html__('Reason','workforce-one').'</label><textarea name="reason" rows="2"></textarea></div><button class="ews-btn ews-vac-submit" type="submit">'.esc_html__('Request Early Leave','workforce-one').'</button></form></div>';
            }
            if($this->can('ews_manage_time')){
                $et=$wpdb->prefix.'ews_early_leave_requests';$ers=$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$et} r JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                if($ers){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>'.esc_html__('Pending Early Leave','workforce-one').'</h3>';foreach($ers as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->work_date).'</div><div class="ews-vac-meta">'.floor($r->leave_minutes/60).'h '.($r->leave_minutes%60).'m'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_early_leave_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_early_leave_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">'.esc_html__('Approve','workforce-one').'</button><button class="button" name="decision" value="reject">'.esc_html__('Reject','workforce-one').'</button></form></div></div></div>';}$html.='</div>';}
            }
            if(isset($_GET['early_error'])){
                $html.='<script>(function(){var m=document.getElementById("ews-early-leave-modal");if(!m)return;var close=function(){m.remove();try{window.history.replaceState({},document.title,window.location.pathname+window.location.search.replace(/([?&])early_error=[^&]*(&|$)/,"$1").replace(/[?&]$/,""));}catch(e){}};m.querySelector(".ews-modal-close").onclick=close;m.querySelector(".ews-modal-ok").onclick=close;m.addEventListener("click",function(e){if(e.target===m)close()});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&document.getElementById("ews-early-leave-modal"))close()},{once:true});var b=m.querySelector(".ews-modal-ok");if(b)b.focus()})();</script>';
            }
            $html.='<script>(function(){
                function ewsShowEarlyModal(message){
                    var existing=document.getElementById("ews-early-leave-modal");if(existing)existing.remove();
                    var m=document.createElement("div");m.id="ews-early-leave-modal";m.className="ews-modal-backdrop";m.setAttribute("role","dialog");m.setAttribute("aria-modal","true");
                    m.innerHTML="<div class=\\"ews-modal-card\\"><button type=\\"button\\" class=\\"ews-modal-close\\" aria-label=\\"Close\\">×</button><div class=\\"ews-modal-icon\\">!</div><h3>'.esc_html__('Early Leave Not Available','workforce-one').'</h3><p></p><button type=\\"button\\" class=\\"ews-modal-ok\\">'.esc_html__('OK','workforce-one').'</button></div>";
                    m.querySelector("p").textContent=message;document.body.appendChild(m);
                    var close=function(){m.remove()};m.querySelector(".ews-modal-close").onclick=close;m.querySelector(".ews-modal-ok").onclick=close;m.onclick=function(e){if(e.target===m)close()};m.querySelector(".ews-modal-ok").focus();
                }
                window.ewsShowEarlyModal=ewsShowEarlyModal;
                var f=document.querySelector("form input[name=\\"action\\"][value=\\"ews_early_leave_create\\"]");
                if(f){
                    f=f.closest("form");f.addEventListener("submit",function(e){
                        var d=document.getElementById("ews-early-date"),mins=document.getElementById("ews-early-minutes"),rem=document.getElementById("ews-early-remaining"),wd=document.getElementById("ews-early-working-days");
                        if(!d||!mins)return;var max=parseInt(mins.getAttribute("max")||"120",10),v=parseInt(mins.value||"0",10),r=parseInt(rem&&rem.value||"0",10);
                        if(!d.value){e.preventDefault();ewsShowEarlyModal('.wp_json_encode(__('Please select a date for Early Leave.','workforce-one')).');return}
                        var chosen=new Date(d.value+"T00:00:00"),today=new Date();today.setHours(0,0,0,0);
                        if(!(chosen>today)){e.preventDefault();ewsShowEarlyModal('.wp_json_encode(__('Early Leave must be requested for a future date.','workforce-one')).');return}
                        var days=wd?JSON.parse(wd.value||"[]"):[],dow=chosen.getDay();
                        if(days.length&&days.indexOf(dow)===-1){e.preventDefault();ewsShowEarlyModal('.wp_json_encode(__('Early Leave is available only on a configured working day.','workforce-one')).');return}
                        if(!(v>=1)||v>max){e.preventDefault();ewsShowEarlyModal('.wp_json_encode(__('The requested Early Leave exceeds the maximum duration allowed for one request.','workforce-one')).');return}
                        if(v>r){e.preventDefault();ewsShowEarlyModal('.wp_json_encode(__('The requested Early Leave exceeds the remaining monthly allowance.','workforce-one')).');return}
                    });
                }
            })();</script>';
            return $html.'</div>';
        }


        private function overtime_content(){
            if(!$this->overtime_enabled())return '<div class="ews-page"><div class="ews-login-error">'.esc_html__('Overtime Requests are currently disabled.','workforce-one').'</div></div>';
            if(!is_user_logged_in())return $this->login_page();
            $this->ensure_overtime_schema();global $wpdb;$today=current_time('Y-m-d');$emp=$this->current_employee();
            $html='<style>
                .ews-ot{max-width:980px;margin:0 auto}.ews-ot-hero{background:linear-gradient(135deg,#eef8ff 0%,#fff 65%);border:1px solid #d9edf9;border-radius:20px;padding:24px 26px;margin-bottom:18px}.ews-ot-kicker{font-size:11px;letter-spacing:.12em;font-weight:800;color:#0875b1;text-transform:uppercase;margin-bottom:6px}.ews-ot-hero h2{margin:0;font-size:28px}.ews-ot-hero p{margin:7px 0 0;color:#667085}.ews-ot-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:18px}.ews-ot-card{background:#fff;border:1px solid #eaecf0;border-radius:18px;padding:22px;box-shadow:0 5px 18px rgba(16,24,40,.05)}.ews-ot-card h3{margin:0 0 6px;font-size:18px}.ews-ot-muted{color:#667085;font-size:13px}.ews-ot-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:18px}.ews-ot-field label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:6px}.ews-ot-field input,.ews-ot-field textarea{box-sizing:border-box;width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;background:#fff}.ews-ot-field input:focus,.ews-ot-field textarea:focus{outline:none;border-color:#0875b1;box-shadow:0 0 0 3px rgba(8,117,177,.12)}.ews-ot-count{display:flex;align-items:center;gap:10px;margin-top:14px;padding:13px 14px;border-radius:12px;background:#eef8ff;border:1px solid #d9edf9;color:#075985;font-weight:700}.ews-ot-submit{width:100%;margin-top:16px!important;justify-content:center}.ews-ot-list{margin-top:18px}.ews-ot-row{padding:15px 0;border-top:1px solid #eaecf0;display:flex;justify-content:space-between;gap:15px;align-items:center}.ews-ot-date{font-weight:800;color:#101828}.ews-ot-meta{font-size:13px;color:#667085;margin-top:4px}.ews-ot-status{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap}.ews-ot-pending{background:#fff7ed;color:#9a3412}.ews-ot-approved{background:#ecfdf3;color:#027a48}.ews-ot-rejected{background:#fef3f2;color:#b42318}.ews-ot-empty{text-align:center;padding:28px 12px;color:#667085}@media(max-width:760px){.ews-ot{max-width:none}.ews-ot-hero{padding:20px;border-radius:16px}.ews-ot-hero h2{font-size:24px}.ews-ot-grid{grid-template-columns:1fr}.ews-ot-fields{grid-template-columns:1fr}.ews-ot-row{align-items:flex-start}.ews-ot-status{display:inline-block;margin-top:2px}}
            </style><div class="ews-page ews-ot"><div class="ews-ot-hero"><div class="ews-ot-kicker">'.esc_html__('EXTRA HOURS','workforce-one').'</div><h2>'.esc_html__('Overtime','workforce-one').'</h2><p>'.esc_html__('Request overtime in advance and know exactly what you are asking to work.','workforce-one').'</p></div>';
            if(isset($_GET['overtime_error'])){$m=['date'=>__('Please select today or a future date.','workforce-one'),'time'=>__('Please select a valid time range.','workforce-one'),'reason'=>__('Please enter a reason for the overtime request.','workforce-one'),'overlap'=>__('You already have a pending or approved overtime request overlapping this period.','workforce-one'),'employee'=>__('Your account is not linked to an active employee.','workforce-one')];$html.='<div class="ews-login-error">'.esc_html($m[$_GET['overtime_error']]??__('Could not submit the overtime request.','workforce-one')).'</div>';}
            if(isset($_GET['overtime_sent']))$html.='<div class="ews-notice ews-notice-success">'.esc_html(sprintf(/* translators: %s: requested hours */__('Overtime request submitted. You are requesting %s hour(s).','workforce-one'),sanitize_text_field(wp_unslash($_GET['overtime_sent'])))).'</div>';
            if(isset($_GET['overtime_done']))$html.='<div class="ews-notice ews-notice-success">'.esc_html__('Overtime request approved.','workforce-one').'</div>';
            if(isset($_GET['overtime_rejected']))$html.='<div class="ews-login-error">'.esc_html__('Overtime request rejected.','workforce-one').'</div>';
            if($emp){
                $min=$today;
                $html.='<div class="ews-ot-grid"><div class="ews-ot-card"><h3>'.esc_html__('Request Overtime','workforce-one').'</h3><p class="ews-ot-muted">'.esc_html__('Submit your overtime request before you work the extra hours. Manager approval is required.','workforce-one').'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_request_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_create"><div class="ews-ot-fields"><div class="ews-ot-field"><label>'.esc_html__('Date','workforce-one').'</label><input id="ews-ot-date" type="date" name="overtime_date" min="'.esc_attr($min).'" required></div><div class="ews-ot-field"><label>'.esc_html__('From','workforce-one').'</label><input id="ews-ot-start" type="time" name="start_time" required></div><div class="ews-ot-field"><label>'.esc_html__('To','workforce-one').'</label><input id="ews-ot-end" type="time" name="end_time" required></div></div><div id="ews-ot-count" class="ews-ot-count">🕐 <span>'.esc_html__('Select the times to see the requested duration.','workforce-one').'</span></div><div class="ews-ot-field" style="margin-top:14px"><label>'.esc_html__('Reason','workforce-one').'</label><textarea name="reason" rows="3" placeholder="Why is overtime required?" required></textarea></div><button class="ews-btn ews-ot-submit" type="submit">'.esc_html__('Submit Overtime Request','workforce-one').'</button></form></div>';
                $table=$wpdb->prefix.'ews_overtime_requests';$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT 20",(int)$emp->id));
                $html.='<div class="ews-ot-card"><h3>'.esc_html__('My Overtime Requests','workforce-one').'</h3><p class="ews-ot-muted">'.esc_html__('Track your pending and completed overtime requests.','workforce-one').'</p><div class="ews-ot-list">';
                if(!$rows)$html.=$this->ews_empty_state(__('No Overtime Requests Yet','workforce-one'),__('You haven\'t submitted any overtime requests yet.','workforce-one'),$this->app_view_url('overtime'),__('Request Overtime','workforce-one'));
                else foreach($rows as $r){$status=sanitize_key(strtolower($r->status));$sc=$status==='pending'?'ews-ot-pending':($status==='approved'?'ews-ot-approved':'ews-ot-rejected');$hours=floor($r->requested_minutes/60);$mins=$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->overtime_date).' · '.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).'</div><div class="ews-ot-meta">'.esc_html($duration).' · '.esc_html($r->reason).'</div></div><span class="ews-ot-status '.$sc.'">'.esc_html($this->status_label($r->status)).'</span></div>';}
                $html.='</div></div></div>';
                $html.='<script>(function(){var T='.wp_json_encode(['req'=>/* translators: %s: duration */__('You are requesting %s.','workforce-one'),'h'=>/* translators: %d: hours */__('%d h','workforce-one'),'hm'=>/* translators: 1: hours, 2: minutes */__('%1$d h %2$d min','workforce-one')]).',s=document.getElementById("ews-ot-start"),e=document.getElementById("ews-ot-end"),o=document.getElementById("ews-ot-count");function f(){if(!s.value||!e.value){o.innerHTML="🕐 <span>'.esc_html__('Select the times to see the requested duration.','workforce-one').'</span>";return;}var a=s.value.split(":"),b=e.value.split(":"),x=(+a[0])*60+(+a[1]),y=(+b[0])*60+(+b[1]);if(y<=x){o.innerHTML="⚠️ <span>'.esc_html__('End time must be after start time.','workforce-one').'</span>";return;}var n=y-x,h=Math.floor(n/60),m=n%60;var d=m?T.hm.replace("%1$d",h).replace("%2$d",m):T.h.replace("%d",h);o.innerHTML="🕐 <span>"+T.req.replace("%s","<strong>"+d+"</strong>")+"</span>";}s.addEventListener("change",f);e.addEventListener("change",f);})();</script>';
            }
            $ot_workflow=$this->approval_workflow('overtime');
            $ot_approval_active=$ot_workflow && (int)$ot_workflow->active===1 && in_array(strtoupper((string)$ot_workflow->approval_mode),['LEVEL_1','LEVEL_2'],true);
            if($ot_approval_active){
                $table=$wpdb->prefix.'ews_overtime_requests';$at=$this->approval_tables();
                $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,ars.id approval_step_id,ars.step_order FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id INNER JOIN {$at['requests']} ar ON ar.entity_type='overtime_request' AND ar.entity_id=r.id INNER JOIN {$at['instances']} ars ON ars.approval_request_id=ar.id AND ars.status='PENDING' AND ars.approver_wp_user_id=%d WHERE r.status='Pending' ORDER BY r.requested_at ASC",get_current_user_id()));
                $html.='<div class="ews-ot-card" style="margin-top:18px"><h3>'.esc_html__('My Pending Overtime Approvals','workforce-one').'</h3><p class="ews-ot-muted">'.esc_html__('Requests currently assigned to you by the approval workflow.','workforce-one').'</p>';
                if(!$rows)$html.=$this->ews_empty_state(__('No Pending Overtime Approvals','workforce-one'),__('You\'re all caught up.','workforce-one'));
                foreach($rows as $r){$hours=floor((int)$r->requested_minutes/60);$mins=(int)$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->employee_name).' · '.esc_html($r->overtime_date).' · Level '.(int)$r->step_order.'</div><div class="ews-ot-meta">'.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).' · <strong>'.esc_html($duration).'</strong></div><div class="ews-ot-meta" style="margin-top:7px">“'.esc_html($r->reason).'”</div><div style="display:flex;gap:8px;margin-top:12px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="approve"><button class="ews-btn" type="submit">'.esc_html__('Approve','workforce-one').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="reject"><button class="ews-btn secondary" type="submit">'.esc_html__('Reject','workforce-one').'</button></form></div></div><span class="ews-ot-status ews-ot-pending">'.esc_html__('Level','workforce-one').' '.(int)$r->step_order.'</span></div>';}
                $html.='</div>';
            }elseif($this->can('ews_manage_settings')){
                $table=$wpdb->prefix.'ews_overtime_requests';
                $rows=$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-ot-card" style="margin-top:18px"><h3>'.esc_html__('Pending Overtime Requests','workforce-one').'</h3><p class="ews-ot-muted">'.esc_html__('Review overtime requests. Any Manager can approve or reject.','workforce-one').'</p>';
                if(!$rows)$html.=$this->ews_empty_state(__('No Pending Overtime Requests','workforce-one'),__('You\'re all caught up.','workforce-one'));
                foreach($rows as $r){$hours=floor((int)$r->requested_minutes/60);$mins=(int)$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->employee_name).' · '.esc_html($r->overtime_date).'</div><div class="ews-ot-meta">'.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).' · <strong>'.esc_html($duration).'</strong></div><div class="ews-ot-meta" style="margin-top:7px">“'.esc_html($r->reason).'”</div><div style="display:flex;gap:8px;margin-top:12px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="approve"><button class="ews-btn" type="submit">'.esc_html__('Approve','workforce-one').'</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="reject"><button class="ews-btn secondary" type="submit">'.esc_html__('Reject','workforce-one').'</button></form></div></div><span class="ews-ot-status ews-ot-pending">'.esc_html__('Pending','workforce-one').'</span></div>';}
                $html.='</div>';
            }
            return $html.'</div>';
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

    private function my_profile_content(){
            global $wpdb;
            $emp=$this->current_employee();
            if(!$emp)return $this->ews_empty_state('Profile unavailable','Your Workforce One employee account is not linked to this login.');
            $today=current_time('Y-m-d');
            $schedule=$this->today_schedule_for_employee((int)$emp->id);
            $events=$this->today_events((int)$emp->id);
            $sign_in=$events['sign_in']->event_at??'';
            $sign_out=$events['sign_out']->event_at??'';
            $planned=$schedule?$schedule->status:'Not Set';
            $today_result=$sign_in?($this->sign_in_classification($sign_in,(int)$emp->id)):($this->schedule_type_requires_sign_in($planned)?'Not Signed In':$planned);
            $teams=[];
            if(method_exists($this,'team_ids_for_employee') && method_exists($this,'team_tables')){
                $team_ids=$this->team_ids_for_employee((int)$emp->id);
                if($team_ids){
                    $tt=$this->team_tables();
                    $placeholders=implode(',',array_fill(0,count($team_ids),'%d'));
                    $teams=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$tt['teams']} WHERE id IN ($placeholders) AND active=1 ORDER BY name ASC",...$team_ids));
                }
            }
            $supervisor=$this->approval_related_employee((int)$emp->id,'supervisor');
            $hours=$this->working_hours((int)$emp->id);
            $shift=$this->shift_for_employee((int)$emp->id);
            $week_cfg=$this->week_dates_configured($today);
            $week_dates=(array)($week_cfg[0]??[]);
            $week_rows=[];
            if($week_dates){
                $week_rows=$wpdb->get_results($wpdb->prepare("SELECT work_date,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY work_date",(int)$emp->id,$week_dates[0],$week_dates[count($week_dates)-1]));
            }
            $week_map=[];foreach((array)$week_rows as $r)$week_map[$r->work_date]=$r;
            $recent_start=date('Y-m-d',strtotime($today.' -6 days'));
            $recent_logs=$wpdb->get_results($wpdb->prepare("SELECT work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id=%d AND work_date BETWEEN %s AND %s ORDER BY work_date DESC,event_at ASC",(int)$emp->id,$recent_start,$today));
            $recent=[];foreach((array)$recent_logs as $r){$d=$r->work_date;if(!isset($recent[$d]))$recent[$d]=['in'=>'','out'=>''];if($r->event_type==='sign_in')$recent[$d]['in']=$r->event_at;if($r->event_type==='sign_out')$recent[$d]['out']=$r->event_at;}
            $year=(int)current_time('Y');
            $leave_balances=[];
            if(method_exists($this,'ensure_leave_schema')){$this->ensure_leave_schema();$types=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_leave_types WHERE active=1 ORDER BY name");foreach((array)$types as $t){$b=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_leave_balances WHERE employee_id=%d AND leave_type_id=%d AND leave_year=%d LIMIT 1",(int)$emp->id,(int)$t->id,$year));if($b) $leave_balances[]=['name'=>$t->name,'entitlement'=>(float)$b->entitlement,'available'=>max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending),'used'=>(float)$b->used,'pending'=>(float)$b->pending];}}
            $image_type=!empty($emp->profile_image_type)?$emp->profile_image_type:'initials';
            $image_url=!empty($emp->profile_image_url)?$emp->profile_image_url:'';
            $profile_updated=sanitize_key($_GET['profile_updated']??'');
            $profile_error=sanitize_key($_GET['profile_error']??'');
            $password_updated=sanitize_key($_GET['password_updated']??'');
            $password_error=sanitize_key($_GET['password_error']??'');
            $avatar_key=!empty($emp->avatar_key)?$emp->avatar_key:'';
            $avatar_url=$image_type==='avatar'&&$avatar_key?$this->profile_avatar_url($avatar_key):'';
            $initials='';foreach(preg_split('/\s+/',trim((string)$emp->name)) as $part){if($part!=='')$initials.=mb_strtoupper(mb_substr($part,0,1));if(mb_strlen($initials)>=2)break;}if($initials==='')$initials='ME';
            $display_image=(($image_type==='photo'&&$image_url)||($image_type==='avatar'&&$avatar_url))?($image_type==='avatar'?$avatar_url:$image_url):'';
            $team_label=$teams?implode(', ',array_map(function($x){return $x->name;},(array)$teams)):'—';
            $catalog=$this->profile_avatar_catalog();
            ob_start(); ?>
            <style>
            .ews-my-profile{max-width:1100px}.ews-my-profile-head{display:flex;justify-content:space-between;align-items:center;gap:20px;margin:8px 0 20px;padding:22px 24px;border:1px solid #e0e7ff;border-radius:18px;background:linear-gradient(135deg,#eef4ff 0%,#f7f5ff 58%,#eef2ff 100%);position:relative;overflow:hidden}.ews-my-profile-head:after{content:"";position:absolute;width:220px;height:220px;border-radius:50%;right:-70px;top:-120px;background:rgba(99,102,241,.10)}.ews-my-profile-person{display:flex;align-items:center;gap:16px;min-width:0;position:relative;z-index:1}.ews-my-profile-avatar{width:84px;height:84px;border-radius:24px;background:#eef2ff;border:1px solid #dbe4ff;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:800;color:#3158c8;overflow:hidden;position:relative;flex:0 0 auto;box-shadow:0 8px 22px rgba(49,88,200,.10)}.ews-my-profile-avatar img{width:100%;height:100%;object-fit:cover}.ews-profile-avatar-edit{position:absolute;right:4px;bottom:4px;width:28px;height:28px;border-radius:50%;border:2px solid #fff;background:#3158c8;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer}.ews-my-profile-title{min-width:0}.ews-my-profile-title h2{margin:0 0 7px;font-size:29px;line-height:1.15;color:#101828}.ews-my-profile-title p{margin:0;color:#667085;overflow-wrap:anywhere}.ews-my-profile-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.ews-my-profile-pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;border:1px solid #e4e7ec;background:rgba(255,255,255,.78);color:#475467;font-size:12px}.ews-my-profile-pill.active{color:#067647;background:#ecfdf3;border-color:#abefc6}.ews-my-profile-quote{margin-left:auto;position:relative;z-index:1;text-align:right;color:#475467;font-size:13px;line-height:1.5;padding-right:8px}.ews-my-profile-quote:after{content:"";display:block;width:52px;height:2px;background:#635bff;margin:7px 0 0 auto;border-radius:2px}.ews-my-profile-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:16px}.ews-my-profile-card,.ews-my-profile-panel{background:#fff;border:1px solid #e4e7ec;border-radius:14px}.ews-my-profile-card{padding:16px;display:flex;align-items:center;gap:12px;min-width:0;box-shadow:0 4px 14px rgba(16,24,40,.035)}.ews-my-profile-card.planned{background:linear-gradient(180deg,#fff 0%,#f7f8ff 100%)}.ews-my-profile-card.attendance{background:linear-gradient(180deg,#fff 0%,#fffaf2 100%)}.ews-my-profile-card.hours{background:linear-gradient(180deg,#fff 0%,#f5fcf8 100%)}.ews-my-profile-card-icon{width:44px;height:44px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 auto;background:#eef2ff;color:#3158c8}.attendance .ews-my-profile-card-icon{background:#fff0db;color:#9a4f00}.hours .ews-my-profile-card-icon{background:#e7f8ee;color:#087443}.ews-my-profile-card .label{font-size:12px;color:#667085}.ews-my-profile-card .value{font-size:21px;font-weight:800;color:#101828;margin-top:5px}.ews-my-profile-card .sub{font-size:12px;color:#667085;margin-top:4px}.ews-my-profile-panel{overflow:hidden;margin-bottom:16px;box-shadow:0 3px 12px rgba(16,24,40,.025)}.ews-my-profile-card,.ews-my-profile-panel{transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.ews-my-profile-card:hover,.ews-my-profile-panel:hover{border-color:#d9def0;box-shadow:0 8px 22px rgba(16,24,40,.055)}.ews-my-profile-section-link{transition:background .15s ease,color .15s ease;padding:6px 8px;border-radius:8px}.ews-my-profile-section-link:hover{background:#eef2ff;color:#2448b8}.ews-profile-security-btn,.ews-profile-photo-btn,.ews-profile-avatar-edit{transition:transform .15s ease,box-shadow .15s ease,background .15s ease}.ews-profile-security-btn:hover,.ews-profile-photo-btn:hover{box-shadow:0 5px 12px rgba(49,88,200,.12);transform:translateY(-1px)}.ews-profile-avatar-edit:hover{transform:scale(1.06);box-shadow:0 3px 8px rgba(49,88,200,.22)}.ews-my-day{transition:transform .15s ease,box-shadow .15s ease}.ews-my-day:hover{transform:translateY(-2px);box-shadow:0 5px 12px rgba(16,24,40,.06)}.ews-my-day.current-day{box-shadow:inset 0 0 0 2px #635bff,0 4px 12px rgba(99,91,255,.10);position:relative}.ews-my-day.current-day:after{content:"Today";position:absolute;top:6px;right:7px;font-size:8px;font-weight:800;color:#635bff;text-transform:uppercase;letter-spacing:.03em}.ews-achievements-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ews-achievement-card{display:flex;gap:11px;align-items:flex-start;border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd}.ews-achievement-icon{width:42px;height:42px;border-radius:12px;background:#fff7e6;display:flex;align-items:center;justify-content:center;font-size:22px;flex:0 0 auto}.ews-achievement-name{font-weight:800;color:#101828;font-size:13px}.ews-achievement-desc{font-size:11px;color:#667085;margin-top:3px;line-height:1.45}.ews-achievement-date{font-size:10px;color:#98a2b3;margin-top:6px}.wfo-kudos-action{margin-bottom:12px}.wfo-kudos-form{border:1px solid #e4e7ec;border-radius:12px;background:#fcfcfd;padding:14px;margin-bottom:14px}.wfo-kudos-form label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:10px}.wfo-kudos-form select,.wfo-kudos-form textarea{display:block;width:100%;box-sizing:border-box;margin-top:6px;border:1px solid #d0d5dd;border-radius:9px;padding:10px;background:#fff;font:inherit;font-size:13px}.wfo-kudos-optional{font-weight:400;color:#98a2b3}.wfo-kudos-form-actions{display:flex;gap:8px}.wfo-kudos-form-actions .secondary{background:#fff}.wfo-recognition-list{display:grid;gap:10px}.wfo-recognition-item{display:flex;gap:11px;align-items:flex-start;border:1px solid #eaecf0;border-radius:12px;padding:12px;background:#fcfcfd}.wfo-recognition-icon{width:40px;height:40px;border-radius:12px;background:#fff7e6;display:flex;align-items:center;justify-content:center;font-size:20px;flex:0 0 auto}.wfo-recognition-title{font-size:12px;color:#475467}.wfo-recognition-title strong{color:#101828}.wfo-recognition-message{font-size:12px;color:#344054;margin-top:4px;line-height:1.45}.wfo-recognition-date{font-size:10px;color:#98a2b3;margin-top:5px}.wfo-recognition-notice{border-radius:9px;padding:9px 11px;font-size:12px;margin-bottom:12px}.wfo-recognition-notice.success{background:#ecfdf3;border:1px solid #abefc6;color:#067647}.wfo-recognition-notice.error{background:#fef3f2;border:1px solid #fecdca;color:#b42318}.ews-my-profile-table tbody tr{transition:background .12s ease}.ews-my-profile-table tbody tr:hover{background:#f8f9fd}.ews-profile-notice{animation:ews-profile-notice-in .2s ease}@keyframes ews-profile-notice-in{from{opacity:0;transform:translateY(-3px)}to{opacity:1;transform:translateY(0)}}.ews-section-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:15px 18px;border-bottom:1px solid #eef0f3}.ews-section-head h3{font-size:16px;margin:0;color:#101828}.ews-section-head p{margin:3px 0 0;color:#667085;font-size:11px}.ews-section-head-icon{width:34px;height:34px;border-radius:10px;background:#eef2ff;color:#3158c8;display:flex;align-items:center;justify-content:center}.ews-my-profile-section-link{color:#3158c8;text-decoration:none;font-size:12px;font-weight:700;white-space:nowrap}.ews-my-profile-body{padding:16px 18px}.ews-my-profile-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 34px}.ews-my-profile-field .k{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#98a2b3}.ews-my-profile-field .v{font-size:14px;color:#344054;margin-top:4px;word-break:break-word}.ews-my-week{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}.ews-my-day{border:1px solid #eaecf0;border-radius:11px;padding:12px;text-align:center;background:#fafbff}.ews-my-day.wfh{background:#f2f6ff;border-color:#dbe5ff}.ews-my-day.office{background:#f5f9ff;border-color:#dbe8f5}.ews-my-day .d{font-size:11px;color:#667085}.ews-my-day .s{font-weight:800;margin-top:6px;font-size:13px;color:#101828}.ews-my-profile-table{width:100%;border-collapse:collapse}.ews-my-profile-table th,.ews-my-profile-table td{padding:10px 12px;border-bottom:1px solid #f0f2f5;text-align:left;font-size:12px}.ews-my-profile-table th{background:#fafbfc;color:#667085;font-weight:600}.ews-att-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.ews-att-status.present{background:#ecfdf3;color:#067647}.ews-att-status.incomplete{background:#fff7ed;color:#b54708}.ews-profile-security{display:flex;align-items:center;justify-content:space-between;gap:16px}.ews-profile-security-main{display:flex;align-items:center;gap:12px}.ews-profile-security-icon{width:42px;height:42px;border-radius:12px;background:#eef2ff;display:flex;align-items:center;justify-content:center;font-size:19px}.ews-profile-security-title{font-weight:800;color:#101828}.ews-profile-security-sub{font-size:12px;color:#667085;margin-top:2px}.ews-profile-security-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;border:1px solid #3158c8;background:#f8f8ff;color:#3158c8;font-size:12px;font-weight:800;cursor:pointer}.ews-leave-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.ews-leave-card{border:1px solid #eaecf0;border-radius:12px;padding:14px;background:#fcfcfd}.ews-leave-top{display:flex;justify-content:space-between;gap:12px}.ews-leave-card .k{font-size:12px;color:#667085}.ews-leave-card .v{font-size:14px;color:#344054;margin-top:4px}.ews-leave-percent{font-size:11px;color:#667085;white-space:nowrap}.ews-leave-bar{height:7px;background:#e7ebf3;border-radius:999px;overflow:hidden;margin-top:12px}.ews-leave-bar span{display:block;height:100%;background:#3158c8;border-radius:999px;min-width:0}.ews-profile-notice{margin:0 0 14px;padding:10px 12px;border-radius:10px;font-size:13px;border:1px solid #abefc6;background:#ecfdf3;color:#067647}.ews-profile-notice.error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.ews-profile-modal-backdrop{position:fixed;inset:0;background:rgba(16,24,40,.48);z-index:99999;display:flex;align-items:center;justify-content:center;padding:20px}.ews-profile-modal{width:min(560px,100%);max-height:90vh;overflow:auto;background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(16,24,40,.25)}.ews-profile-modal-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #eef0f3}.ews-profile-modal-head h3{margin:0;font-size:17px}.ews-profile-modal-close{border:0;background:#f2f4f7;width:32px;height:32px;border-radius:9px;font-size:20px;cursor:pointer}.ews-profile-modal-body{padding:18px}.ews-profile-modal-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.ews-profile-photo-btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:9px;border:1px solid #3158c8;background:#3158c8;color:#fff;font-size:12px;font-weight:700;cursor:pointer}.ews-profile-photo-btn.secondary{background:#fff;color:#3158c8}.ews-profile-photo-help{font-size:12px;color:#667085;margin-top:8px}.ews-profile-picture-preview{display:flex;align-items:center;gap:14px}.ews-profile-picture-preview .ews-my-profile-avatar{width:76px;height:76px;border-radius:20px;font-size:24px}.ews-avatar-picker{margin-top:14px;border:1px solid #e4e7ec;border-radius:12px;padding:14px;background:#fcfcfd}.ews-avatar-picker-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px}.ews-avatar-filters{display:flex;gap:7px;flex-wrap:wrap;margin:14px 0}.ews-avatar-filter{border:1px solid #e4e7ec;background:#fff;color:#475467;border-radius:999px;padding:6px 10px;font-size:12px;cursor:pointer}.ews-avatar-filter.active{background:#3158c8;color:#fff;border-color:#3158c8}.ews-avatar-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.ews-avatar-choice{position:relative;border:1px solid #e4e7ec;background:#fff;border-radius:12px;padding:4px;cursor:pointer;aspect-ratio:1}.ews-avatar-choice img{width:100%;height:100%;display:block}.ews-avatar-choice.selected{border:2px solid #3158c8;padding:3px}.ews-avatar-check{display:none;position:absolute;right:3px;top:3px;width:20px;height:20px;border-radius:50%;background:#3158c8;color:#fff;font-size:12px;line-height:20px}.ews-avatar-choice.selected .ews-avatar-check{display:block}.ews-photo-upload-panel{margin-top:10px;padding:10px;border:1px dashed #d0d5dd;border-radius:10px;background:#fff}.ews-avatar-picker[hidden],.ews-photo-upload-panel[hidden],.ews-profile-modal-backdrop[hidden]{display:none}.ews-password-form{display:grid;gap:12px}.ews-password-field{display:grid;gap:6px}.ews-password-field label{font-size:12px;font-weight:600;color:#344054}.ews-password-field input{width:100%;box-sizing:border-box;padding:10px 11px;border:1px solid #d0d5dd;border-radius:9px;font-size:14px}.ews-password-requirements{background:#f8f9fc;border:1px solid #eaecf0;border-radius:10px;padding:11px 12px;font-size:12px;color:#667085}.ews-password-requirements strong{display:block;color:#344054;margin-bottom:5px}.ews-password-requirements ul{margin:0;padding-left:18px}.ews-password-submit{display:flex;justify-content:flex-end;gap:8px;margin-top:2px}
            @media(max-width:900px){.ews-my-profile-grid{grid-template-columns:1fr 1fr}.ews-my-week{grid-template-columns:repeat(5,minmax(110px,1fr));overflow:auto}.ews-my-profile-quote{display:none}.ews-my-profile-head{padding:18px}}
            @media(max-width:700px){.ews-my-profile{width:100%;max-width:none;box-sizing:border-box}.ews-my-profile-head{align-items:flex-start;margin-bottom:12px;padding:14px;border-radius:15px;gap:10px}.ews-my-profile-head:after{width:150px;height:150px;right:-55px;top:-85px}.ews-my-profile-person{gap:10px;min-width:0}.ews-my-profile-avatar{width:60px;height:60px;min-width:60px;border-radius:16px;font-size:21px}.ews-profile-avatar-edit{width:23px;height:23px;font-size:10px;right:2px;bottom:2px}.ews-my-profile-title h2{font-size:21px;white-space:nowrap}.ews-my-profile-title p{display:none}.ews-my-profile-meta{gap:5px;margin-top:6px}.ews-my-profile-pill{padding:5px 8px;font-size:10px}.ews-my-profile-quote{display:none!important}.ews-my-profile-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.ews-my-profile-card{padding:9px 7px;display:block}.ews-my-profile-card-icon{width:32px;height:32px;border-radius:9px;font-size:16px;margin-bottom:6px}.ews-my-profile-card .label{font-size:8.5px;white-space:nowrap}.ews-my-profile-card .value{font-size:13px;line-height:1.2;white-space:nowrap;letter-spacing:-.02em}.ews-my-profile-card .sub{font-size:8.5px;white-space:nowrap}.ews-my-profile-card.attendance .value{font-size:12px}.ews-my-profile-card.hours .value{font-size:12px}.ews-my-profile-panel{border-radius:12px;margin-bottom:12px}.ews-section-head{padding:12px 14px}.ews-section-head h3{font-size:15px}.ews-section-head p{font-size:10px}.ews-section-head-icon{display:none}.ews-my-profile-body{padding:12px 14px}.ews-my-profile-fields{grid-template-columns:1fr;gap:11px}.ews-my-profile-field .v{font-size:13px}.ews-my-week{grid-template-columns:repeat(5,minmax(84px,1fr));gap:6px;overflow:auto;padding-bottom:2px}.ews-my-day{padding:10px 7px}.ews-my-day .s{font-size:12px}.ews-achievements-grid{grid-template-columns:1fr}.ews-leave-grid{grid-template-columns:1fr;gap:8px}.ews-profile-security{align-items:flex-start}.ews-profile-security-btn{white-space:nowrap}.ews-profile-modal-backdrop{padding:10px;align-items:flex-end}.ews-profile-modal{max-height:92vh;border-radius:16px 16px 10px 10px}.ews-profile-modal-body{padding:14px}.ews-avatar-filters{overflow-x:auto;flex-wrap:nowrap;padding-bottom:3px}.ews-avatar-filter{flex:0 0 auto;white-space:nowrap}.ews-avatar-grid{grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}.ews-profile-picture-preview .ews-my-profile-avatar{width:64px;height:64px}.ews-password-submit .ews-profile-photo-btn{flex:1}.ews-my-profile-table{min-width:620px}.ews-my-profile-table th,.ews-my-profile-table td{padding:9px 7px;font-size:10.5px}}
            </style>
            <div class="ews-my-profile">
                <?php if($profile_updated): ?><div class="ews-profile-notice"><?php echo ($profile_updated==='reset'?'Profile picture reset to initials.':($profile_updated==='avatar'?'Avatar selected successfully.':'Profile picture updated successfully.')); ?></div><?php endif; ?>
                <?php if($profile_error): ?><div class="ews-profile-notice error"><?php echo $profile_error==='size'?'Image must be 2 MB or smaller.':($profile_error==='type'?'Please upload a JPG, PNG, or WebP image.':'Could not upload the profile picture. Please try again.'); ?></div><?php endif; ?>
                <?php if($password_updated): ?><div class="ews-profile-notice">Password updated successfully.</div><?php endif; ?>
                <?php if($password_error): ?><div class="ews-profile-notice error"><?php echo $password_error==='current'?'Current password is incorrect.':($password_error==='mismatch'?'New passwords do not match.':($password_error==='weak'?'New password must be at least 8 characters and include uppercase, lowercase, number and special character.':($password_error==='same'?'New password must be different from the current password.':'Could not update your password. Please try again.'))); ?></div><?php endif; ?>

                <div class="ews-my-profile-head">
                    <div class="ews-my-profile-person">
                        <div class="ews-my-profile-avatar" title="Edit profile picture">
                            <?php if($display_image): ?><img src="<?php echo esc_url($display_image); ?>" alt="<?php echo esc_attr($emp->name); ?>"><?php else: ?><?php echo esc_html($initials); ?><?php endif; ?>
                            <button type="button" class="ews-profile-avatar-edit" id="ews-open-profile-picture" aria-label="Edit profile picture">✎</button>
                        </div>
                        <div class="ews-my-profile-title"><h2><?php echo esc_html($emp->name); ?></h2><div class="ews-my-profile-meta"><span class="ews-my-profile-pill active">● Active</span><span class="ews-my-profile-pill">👥 <?php echo esc_html($team_label); ?></span></div></div><div class="ews-my-profile-quote"><strong>One Platform.<br>One Team. One Goal.</strong></div>
                    </div>
                </div>

                <div class="ews-my-profile-grid">
                    <div class="ews-my-profile-card planned"><div class="ews-my-profile-card-icon">⌂</div><div><div class="label">Today · Planned</div><div class="value"><?php echo esc_html($planned); ?></div><div class="sub">Schedule status</div></div></div>
                    <div class="ews-my-profile-card attendance"><div class="ews-my-profile-card-icon">◷</div><div><div class="label">Today · Attendance</div><div class="value"><?php echo esc_html($today_result); ?></div><div class="sub"><?php echo $sign_in?'In '.esc_html(date_i18n('g:i A',strtotime($sign_in))): 'Not signed in'; ?></div></div></div>
                    <div class="ews-my-profile-card hours"><div class="ews-my-profile-card-icon">▣</div><div><div class="label">Working Hours</div><div class="value"><?php echo esc_html($this->format_time_label($hours['start']).' – '.$this->format_time_label($hours['end'])); ?></div><div class="sub"><?php echo esc_html($shift?$shift['name']:'Company Default'); ?></div></div></div>
                </div>

                <section class="ews-my-profile-panel ews-info-panel"><div class="ews-section-head"><div><h3>My Information</h3><p>Your personal and work information</p></div><span class="ews-section-head-icon">♙</span></div><div class="ews-my-profile-body"><div class="ews-my-profile-fields">
                    <div class="ews-my-profile-field"><div class="k">Name</div><div class="v"><?php echo esc_html($emp->name); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Domain</div><div class="v"><?php echo esc_html($emp->domain_name); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Email</div><div class="v"><?php echo esc_html($emp->email?:'—'); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Team</div><div class="v"><?php echo esc_html($team_label); ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Supervisor</div><div class="v"><?php echo $supervisor?esc_html($supervisor->name):'—'; ?></div></div>
                    <div class="ews-my-profile-field"><div class="k">Shift</div><div class="v"><?php echo esc_html($shift?$shift['name']:'Company Default'); ?></div></div>
                </div></div></section>

                <section class="ews-my-profile-panel ews-security-panel"><div class="ews-my-profile-body"><div class="ews-profile-security"><div class="ews-profile-security-main"><div class="ews-profile-security-icon">🔒</div><div><div class="ews-profile-security-title">Security</div><div class="ews-profile-security-sub">Keep your Workforce One account secure.</div></div></div><button type="button" class="ews-profile-security-btn" id="ews-open-password">Reset Password&nbsp; →</button></div></div></section>

                <?php if($this->achievements_enabled()): $achievements=$this->employee_achievements((int)$emp->id); ?>
                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>🏆 Achievements</h3><p>Your earned milestones</p></div><span class="ews-my-profile-section-link"><?php echo (int)count($achievements); ?> earned</span></div><div class="ews-my-profile-body"><div class="ews-achievements-grid"><?php if(!$achievements): ?><div class="ews-profile-photo-help">Your achievements will appear here as you reach milestones.</div><?php else: foreach($achievements as $a): ?><div class="ews-achievement-card"><div class="ews-achievement-icon <?php echo esc_attr($a->badge_style?:'circle'); ?>"><?php echo esc_html($a->icon); ?></div><div><div class="ews-achievement-name"><?php echo esc_html($a->name); ?></div><div class="ews-achievement-desc"><?php echo esc_html($a->description); ?></div><div class="ews-achievement-date">Earned <?php echo esc_html(date_i18n(get_option('date_format'),strtotime($a->earned_at))); ?></div></div></div><?php endforeach; endif; ?></div></div></section><?php endif; ?>

                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>My Week</h3><p>Your schedule for this week</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($this->app_view_url('schedule')); ?>">View Schedule&nbsp; →</a></div><div class="ews-my-profile-body"><div class="ews-my-week">
                    <?php foreach($week_dates as $d): $r=$week_map[$d]??null; $status=$r?$r->status:'Not Set'; $status_class=strtolower(str_replace(' ','-',(string)$status)); $day_class=$d===$today?' current-day':''; ?><div class="ews-my-day <?php echo esc_attr($status_class.$day_class); ?>"><div class="d"><?php echo esc_html(date('D',strtotime($d))); ?><br><?php echo esc_html(date('d M',strtotime($d))); ?></div><div class="s"><?php echo esc_html($status); ?></div></div><?php endforeach; ?>
                </div></div></section>

                <?php if($leave_balances): ?><section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>Leave Balance · <?php echo esc_html($year); ?></h3><p>Your leave entitlement and usage</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($this->app_view_url('leave')); ?>">View Leave&nbsp; →</a></div><div class="ews-my-profile-body"><div class="ews-leave-grid"><?php foreach($leave_balances as $lb): $pct=$lb['entitlement']>0?min(100,round(($lb['used']/$lb['entitlement'])*100)):0; ?><div class="ews-leave-card"><div class="ews-leave-top"><div><div class="k"><?php echo esc_html($lb['name']); ?></div><div class="v"><strong><?php echo esc_html(number_format($lb['available'],2)); ?></strong> available</div><div class="ews-my-profile-photo-note">Used <?php echo esc_html(number_format($lb['used'],2)); ?> · Pending <?php echo esc_html(number_format($lb['pending'],2)); ?></div></div><span class="ews-leave-percent"><?php echo esc_html($pct); ?>% used</span></div><div class="ews-leave-bar"><span style="width:<?php echo esc_attr($pct); ?>%"></span></div></div><?php endforeach; ?></div></div></section><?php endif; ?>

                <section class="ews-my-profile-panel"><div class="ews-section-head"><div><h3>Recent Attendance</h3><p>Your latest attendance records</p></div><a class="ews-my-profile-section-link" href="<?php echo esc_url($this->app_view_url('time')); ?>">View All&nbsp; →</a></div><div class="ews-my-profile-body" style="padding:0"><div style="overflow:auto"><table class="ews-my-profile-table"><thead><tr><th>Date</th><th>Sign In</th><th>Sign Out</th><th>Total Hours</th><th>Status</th></tr></thead><tbody><?php if($recent): foreach($recent as $d=>$rr): $total='—'; $att_status='Incomplete'; if($rr['in']&&$rr['out']){$mins=max(0,round((strtotime($rr['out'])-strtotime($rr['in']))/60));$total=floor($mins/60).'h '.($mins%60).'m';$att_status='Present';} elseif(!$rr['in']){$att_status='No Sign In';} ?><tr><td><?php echo esc_html(date('F j, Y',strtotime($d))); ?></td><td><?php echo $rr['in']?esc_html(date_i18n('g:i A',strtotime($rr['in']))):'—'; ?></td><td><?php echo $rr['out']?esc_html(date_i18n('g:i A',strtotime($rr['out']))):'—'; ?></td><td><?php echo esc_html($total); ?></td><td><span class="ews-att-status <?php echo $att_status==='Present'?'present':'incomplete'; ?>"><?php echo esc_html($att_status); ?></span></td></tr><?php endforeach; else: ?><tr><td colspan="5" class="ews-my-profile-muted">No attendance records in the selected period.</td></tr><?php endif; ?></tbody></table></div></div></section>

                <div class="ews-profile-modal-backdrop" id="ews-profile-picture-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-picture-title"><div class="ews-profile-modal-head"><h3 id="ews-picture-title">Profile Picture</h3><button type="button" class="ews-profile-modal-close" id="ews-close-profile-picture" aria-label="Close">×</button></div><div class="ews-profile-modal-body">
                    <div class="ews-profile-picture-preview"><div class="ews-my-profile-avatar"><?php if($display_image): ?><img src="<?php echo esc_url($display_image); ?>" alt="<?php echo esc_attr($emp->name); ?>"><?php else: ?><?php echo esc_html($initials); ?><?php endif; ?></div><div><strong>Choose how you appear across Workforce One.</strong><div class="ews-profile-photo-help">Your profile picture belongs to your Workforce One employee profile, not the WordPress user profile.</div></div></div>
                    <div class="ews-profile-modal-actions"><button type="button" class="ews-profile-photo-btn" id="ews-open-avatar-picker">Choose Avatar</button><button type="button" class="ews-profile-photo-btn secondary" id="ews-open-photo-upload">Upload Photo</button><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="reset"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn secondary">Use Initials</button></form></div>
                    <div id="ews-avatar-picker" class="ews-avatar-picker" hidden><div class="ews-avatar-picker-head"><div><strong>Choose your avatar</strong><div class="ews-profile-photo-help">Pick an avatar to use across Workforce One.</div></div></div><div class="ews-avatar-filters"><button type="button" class="ews-avatar-filter active" data-filter="all">All</button><button type="button" class="ews-avatar-filter" data-filter="Men">Men</button><button type="button" class="ews-avatar-filter" data-filter="Women">Women</button><button type="button" class="ews-avatar-filter" data-filter="Professional">Professional</button><button type="button" class="ews-avatar-filter" data-filter="Casual">Casual</button><button type="button" class="ews-avatar-filter" data-filter="Fun">Fun</button></div><div class="ews-avatar-grid"><?php foreach($catalog as $key=>$meta): ?><button type="button" class="ews-avatar-choice<?php echo $avatar_key===$key?' selected':''; ?>" data-avatar-key="<?php echo esc_attr($key); ?>" data-category="<?php echo esc_attr($meta['category']); ?>" data-style="<?php echo esc_attr($meta['style']); ?>" title="<?php echo esc_attr($meta['label']); ?>"><img src="<?php echo esc_url($this->profile_avatar_url($key)); ?>" alt="<?php echo esc_attr($meta['label']); ?>"><span class="ews-avatar-check">✓</span></button><?php endforeach; ?></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ews-avatar-form"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="avatar"><input type="hidden" name="avatar_key" id="ews-avatar-key" value="<?php echo esc_attr($avatar_key); ?>"><?php wp_nonce_field('ews_profile_photo_save'); ?><button type="submit" class="ews-profile-photo-btn" id="ews-save-avatar" <?php echo $avatar_key?'':'disabled'; ?>>Save Avatar</button></form></div>
                    <div id="ews-photo-upload-panel" class="ews-photo-upload-panel" hidden><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="ews-profile-photo-actions"><input type="hidden" name="action" value="ews_profile_photo_save"><input type="hidden" name="profile_photo_action" value="upload"><?php wp_nonce_field('ews_profile_photo_save'); ?><input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required><button type="submit" class="ews-profile-photo-btn">Upload Photo</button></form><div class="ews-profile-photo-help">JPG, PNG or WebP · maximum 2 MB</div></div>
                </div></div></div>

                <div class="ews-profile-modal-backdrop" id="ews-password-modal" hidden><div class="ews-profile-modal" role="dialog" aria-modal="true" aria-labelledby="ews-password-title"><div class="ews-profile-modal-head"><h3 id="ews-password-title">Reset Password</h3><button type="button" class="ews-profile-modal-close" id="ews-close-password" aria-label="Close">×</button></div><div class="ews-profile-modal-body"><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-password-form"><input type="hidden" name="action" value="ews_profile_password_change"><?php wp_nonce_field('ews_profile_password_change'); ?><div class="ews-password-field"><label for="ews-current-password">Current Password</label><input id="ews-current-password" name="current_password" type="password" autocomplete="current-password" required></div><div class="ews-password-field"><label for="ews-new-password">New Password</label><input id="ews-new-password" name="new_password" type="password" autocomplete="new-password" required></div><div class="ews-password-field"><label for="ews-confirm-password">Confirm New Password</label><input id="ews-confirm-password" name="confirm_password" type="password" autocomplete="new-password" required></div><div class="ews-password-requirements"><strong>Password requirements</strong><ul><li>At least 8 characters</li><li>One uppercase letter</li><li>One lowercase letter</li><li>One number</li><li>One special character</li></ul></div><div class="ews-password-submit"><button type="button" class="ews-profile-photo-btn secondary" id="ews-cancel-password">Cancel</button><button type="submit" class="ews-profile-photo-btn">Update Password</button></div></form></div></div></div>
            </div>
            <script>(function(){function modal(id,open,close){var m=document.getElementById(id);if(!m)return;document.getElementById(open)&&document.getElementById(open).addEventListener('click',function(){m.hidden=false;document.body.style.overflow='hidden';});document.getElementById(close)&&document.getElementById(close).addEventListener('click',function(){m.hidden=true;document.body.style.overflow='';});m.addEventListener('click',function(e){if(e.target===m){m.hidden=true;document.body.style.overflow='';}});}modal('ews-profile-picture-modal','ews-open-profile-picture','ews-close-profile-picture');modal('ews-password-modal','ews-open-password','ews-close-password');document.getElementById('ews-cancel-password')&&document.getElementById('ews-cancel-password').addEventListener('click',function(){document.getElementById('ews-password-modal').hidden=true;document.body.style.overflow='';});var picker=document.getElementById('ews-avatar-picker'),openA=document.getElementById('ews-open-avatar-picker'),photo=document.getElementById('ews-open-photo-upload'),upload=document.getElementById('ews-photo-upload-panel'),key=document.getElementById('ews-avatar-key'),save=document.getElementById('ews-save-avatar');openA&&openA.addEventListener('click',function(){picker.hidden=false;});photo&&photo.addEventListener('click',function(){upload.hidden=!upload.hidden;});document.querySelectorAll('.ews-avatar-filter').forEach(function(b){b.addEventListener('click',function(){document.querySelectorAll('.ews-avatar-filter').forEach(function(x){x.classList.remove('active');});b.classList.add('active');var f=b.getAttribute('data-filter');document.querySelectorAll('.ews-avatar-choice').forEach(function(c){var cat=c.getAttribute('data-category'),style=c.getAttribute('data-style');c.hidden=!(f==='all'||cat===f||style===f);});});});document.querySelectorAll('.ews-avatar-choice').forEach(function(c){c.addEventListener('click',function(){document.querySelectorAll('.ews-avatar-choice').forEach(function(x){x.classList.remove('selected');});c.classList.add('selected');key.value=c.getAttribute('data-avatar-key');save.disabled=false;});});})();</script>
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

    private function smart_nudges_markup($emp){
        // Sign In Reminder is push-only. It must never render as a Smart Nudge card.
        $nudges=$this->smart_nudge_for_employee($emp);
        if($nudges){
            $nudges=array_values(array_filter($nudges,function($n){return ($n['kind']??'')!=='attendance';}));
        }
        if(!$nudges)return '';
        ob_start(); ?>
        <div class="ews-smart-nudges-card">
            <div class="ews-smart-nudges-head"><div><span class="ews-personal-label">SMART NUDGES</span><h3>Things that need your attention</h3></div><span>🔔</span></div>
            <div class="ews-smart-nudges-list">
                <?php foreach($nudges as $n): $dismiss=wp_nonce_url(add_query_arg(['action'=>'ews_smart_nudge_dismiss','nudge_id'=>$n['id']],admin_url('admin-post.php')),'ews_smart_nudge_dismiss_'.$n['id']); ?>
                <div class="ews-smart-nudge ews-smart-nudge-<?php echo esc_attr($n['kind']); ?>">
                    <div class="ews-smart-nudge-icon"><?php echo esc_html($n['icon']); ?></div>
                    <div class="ews-smart-nudge-body"><strong><?php echo esc_html($n['title']); ?></strong><p><?php echo esc_html($n['message']); ?></p><div class="ews-smart-nudge-actions"><a class="ews-btn" href="<?php echo esc_url($n['url']); ?>"><?php echo esc_html($n['action']); ?></a><a class="ews-smart-nudge-dismiss" href="<?php echo esc_url($dismiss); ?>">Dismiss</a></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php return ob_get_clean();
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

    private function employee_moments_markup(){
        $moments=$this->employee_moments_for_today();
        if(!$moments) return '';
        ob_start(); ?>
        <div class="ews-dash-card ews-moments-card">
            <div class="ews-dash-card-head"><div><h3>✨ Today's Moments</h3><p>A little celebration for the team.</p></div><span>🎊</span></div>
            <div class="ews-moments-list">
                <?php foreach($moments as $m): ?><div class="ews-moment-item"><div class="ews-moment-icon"><?php echo esc_html($m['icon']); ?></div><div><strong><?php echo esc_html($m['title']); ?></strong><p><?php echo esc_html($m['message']); ?></p></div></div><?php endforeach; ?>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    private function dashboard_content(){
            global $wpdb;
            $unread=$this->notification_unread_count();

            /* Managers/Admins get the workforce overview. */
            if($this->can('ews_view_dashboard')){
                $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->employees} WHERE active=1");
                $today=current_time('Y-m-d');
                $snapshot=$wpdb->get_row($wpdb->prepare(
                    "SELECT
                        SUM(status='Office') AS office_count,
                        SUM(status='WFH') AS wfh_count,
                        SUM(status IN ('Vacation','Business Trip')) AS leave_count
                     FROM {$this->schedule}
                     WHERE work_date=%s",
                    $today
                ));
                $office=(int)($snapshot->office_count??0);
                $wfh=(int)($snapshot->wfh_count??0);
                $leave=(int)($snapshot->leave_count??0);
                ob_start(); ?>
                <div class="ews-dashboard">
                    <div class="ews-dash-welcome">
                        <div><div class="ews-dash-kicker"><?php esc_html_e('BA Team · Workforce One','workforce-one'); ?></div><h2><?php esc_html_e('Good to see you.','workforce-one'); ?></h2><p><?php echo esc_html(date_i18n('l, d F Y')); ?> · <?php esc_html_e('Here’s today’s workforce snapshot.','workforce-one'); ?></p></div>
                    </div>
                    <div class="ews-dash-stats">
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon purple">👥</div><div><div class="n"><?php echo $count; ?></div><div class="l"><?php esc_html_e('Active Employees','workforce-one'); ?></div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon green">🏢</div><div><div class="n"><?php echo $office; ?></div><div class="l"><?php esc_html_e('Office Today','workforce-one'); ?></div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon blue">🏠</div><div><div class="n"><?php echo $wfh; ?></div><div class="l"><?php esc_html_e('WFH Today','workforce-one'); ?></div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon orange">✈</div><div><div class="n"><?php echo $leave; ?></div><div class="l"><?php esc_html_e('Leave / Mission','workforce-one'); ?></div></div></div>
                    </div>
                    <?php echo $this->employee_moments_markup(); ?>
                    <?php echo $this->employee_poll_markup(); ?>
                    <div class="ews-dash-grid">
                        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('This Week','workforce-one'); ?></h3><p><?php $wfo_days=$this->working_days(); $wfo_names=$this->working_day_names(); echo esc_html($wfo_days?implode(' → ',array_map(function($d)use($wfo_names){return $wfo_names[$d]??'';},$wfo_days)):__('No working days configured','workforce-one')); ?></p></div><span>📅</span></div><div class="ews-dash-week-line"><div><strong><?php esc_html_e('Plan your week','workforce-one'); ?></strong><small><?php esc_html_e('Review schedules and keep your team aligned.','workforce-one'); ?></small></div><a class="ews-btn" href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><?php esc_html_e('View Schedule','workforce-one'); ?></a></div></div>
                        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('Quick Actions','workforce-one'); ?></h3><p><?php esc_html_e('Common tasks','workforce-one'); ?></p></div><span>⚡</span></div><div class="ews-dash-actions"><a href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><span>📅</span> <?php esc_html_e('Schedule','workforce-one'); ?></a><?php if($this->can('ews_manage_attendance')): ?><a href="<?php echo esc_url($this->app_view_url('attendance')); ?>"><span>📝</span> <?php esc_html_e('Attendance','workforce-one'); ?></a><?php endif; ?><a href="<?php echo esc_url($this->app_view_url('notifications')); ?>"><span>🔔</span> <?php esc_html_e('Notifications','workforce-one'); ?><?php if($unread): ?><b><?php echo absint($unread); ?></b><?php endif; ?></a></div></div>
                    </div>
                    <div class="ews-dash-card ews-dash-status"><div class="ews-dash-card-head"><div><h3><?php esc_html_e('Today at a glance','workforce-one'); ?></h3><p><?php esc_html_e('Current schedule distribution','workforce-one'); ?></p></div><span>✓</span></div><div class="ews-dash-bars"><div><div><span><?php esc_html_e('Office','workforce-one'); ?></span><b><?php echo $office; ?></b></div><i><em style="width:<?php echo $count?min(100,round(($office/$count)*100)):0; ?>%"></em></i></div><div><div><span><?php esc_html_e('WFH','workforce-one'); ?></span><b><?php echo $wfh; ?></b></div><i><em class="blue" style="width:<?php echo $count?min(100,round(($wfh/$count)*100)):0; ?>%"></em></i></div><div><div><span><?php esc_html_e('Leave / Mission','workforce-one'); ?></span><b><?php echo $leave; ?></b></div><i><em class="orange" style="width:<?php echo $count?min(100,round(($leave/$count)*100)):0; ?>%"></em></i></div></div></div>
                </div>
                </div>
            <?php return ob_get_clean();
            }

            /* Employees get a personal dashboard. */
            $emp=$this->current_employee();
            if(!$emp){
                return '<div class="ews-dashboard"><div class="ews-dash-card ews-employee-dashboard-empty"><h2>'.esc_html__('Employee account not linked','workforce-one').'</h2><p>'.esc_html__('Your WordPress account is not linked to an active employee record. Please contact your manager.','workforce-one').'</p></div></div>';
            }
            $today=current_time('Y-m-d');
            $sch=$this->today_schedule_for_employee($emp->id);
            $events=$this->today_events($emp->id);
            $loc=method_exists($this,'ews_v321_employee_location')?$this->ews_v321_employee_location($emp->id):null;
            if(!$loc && method_exists($this,'ews_default_location'))$loc=$this->ews_default_location();

            $next=$wpdb->get_results($wpdb->prepare("SELECT work_date,status FROM {$this->schedule} WHERE employee_id=%d AND work_date>%s ORDER BY work_date ASC LIMIT 5",$emp->id,$today));
            $first_name=trim(explode(' ',trim((string)$emp->name))[0]);
            $status=$sch?$sch->status:'Not Set';
            $is_working=$sch?$this->schedule_type_requires_sign_in($status):false;
            if($this->company_leave_dates($today,$today))$is_working=false;
            $sign_in=$events['sign_in']->event_at??($events['late_sign_in']->event_at??null);
            $sign_out=$events['sign_out']->event_at??null;
            $ot_summary=$this->overtime_attendance_summary((int)$emp->id,$today);
            ob_start(); ?>
            <div class="ews-dashboard ews-employee-dashboard">
                <div class="ews-dash-welcome">
                    <div><div class="ews-dash-kicker"><?php esc_html_e('BA Team · Workforce One','workforce-one'); ?></div><h2><?php printf(/* translators: %s: employee first name */esc_html__('Good to see you, %s.','workforce-one'),esc_html($first_name?:$emp->name)); ?></h2><p><?php echo esc_html(date_i18n('l, d F Y')); ?> · <?php esc_html_e('Here’s your day at a glance.','workforce-one'); ?></p></div>
                </div>

                <?php echo $this->employee_moments_markup(); ?>

                <?php echo $this->employee_poll_markup(); ?>

                <?php echo $this->smart_nudges_markup($emp); ?>

                <div class="ews-employee-today">
                    <div class="ews-employee-today-head">
                        <div><span class="ews-personal-label"><?php esc_html_e('TODAY\'S SCHEDULE','workforce-one'); ?></span><h3><?php echo esc_html($status==='Not Set'?__('Not Set','workforce-one'):$status); ?></h3><p><?php echo $loc?esc_html($loc->name):esc_html__('Default location','workforce-one'); ?></p></div>
                        <span class="ews-personal-status <?php echo $is_working?'working':'neutral'; ?>"><?php echo esc_html($is_working?__('Working day','workforce-one'):__('No work scheduled','workforce-one')); ?></span>
                    </div>
                    <div class="ews-personal-meta">
                        <div><span><?php esc_html_e('Work Location','workforce-one'); ?></span><b><?php echo $loc?esc_html($loc->name):esc_html__('Default','workforce-one'); ?></b></div>
                        <div><span><?php esc_html_e('Sign In','workforce-one'); ?></span><b><?php echo $sign_in?esc_html(date_i18n('h:i A',strtotime($sign_in))):esc_html__('Not recorded','workforce-one'); ?></b></div>
                        <div><span><?php esc_html_e('Sign Out','workforce-one'); ?></span><b><?php echo $sign_out?esc_html(date_i18n('h:i A',strtotime($sign_out))):esc_html__('Not recorded','workforce-one'); ?></b></div>
                    </div>
                    <a class="ews-btn ews-personal-action" href="<?php echo esc_url($this->app_view_url('time')); ?>"><?php echo esc_html($sign_in?__('View Attendance','workforce-one'):__('Sign In / Out','workforce-one')); ?></a>
                </div>

                <div class="ews-dash-grid">
                    <?php if($this->overtime_enabled() && ($ot_summary['approved_minutes']>0 || $ot_summary['actual_approved_minutes']>0 || $ot_summary['unapproved_extra_minutes']>0 || !empty($ot_summary['approved_requests']))): ?>
                    <div class="ews-dash-card ews-overtime-attendance-card">
                        <div class="ews-dash-card-head"><div><h3><?php esc_html_e('Overtime Today','workforce-one'); ?></h3><p><?php esc_html_e('Approved overtime and actual attendance','workforce-one'); ?></p></div><span>⏱️</span></div>
                        <div class="ews-personal-meta">
                            <div><span><?php esc_html_e('Approved OT','workforce-one'); ?></span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['approved_minutes'])); ?></b></div>
                            <div><span><?php esc_html_e('Actual OT','workforce-one'); ?></span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['actual_approved_minutes'])); ?></b></div>
                            <?php if($ot_summary['unapproved_extra_minutes']>0): ?><div><span><?php esc_html_e('Unapproved Extra','workforce-one'); ?></span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['unapproved_extra_minutes'])); ?></b></div><?php endif; ?>
                        </div>
                        <?php if($ot_summary['sign_out']): ?><div style="margin-top:10px;color:#667085;font-size:13px"><?php esc_html_e('Sign Out:','workforce-one'); ?> <strong><?php echo esc_html(date_i18n('g:i A',strtotime($ot_summary['sign_out']))); ?></strong></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="ews-dash-card">
                        <div class="ews-dash-card-head"><div><h3><?php esc_html_e('My Week','workforce-one'); ?></h3><p><?php esc_html_e('Your upcoming schedule','workforce-one'); ?></p></div><span>📅</span></div>
                        <?php if($next): ?><div class="ews-my-week-list"><?php foreach($next as $r): ?><div><span><?php echo esc_html(date_i18n('D, d M',strtotime($r->work_date))); ?></span><b><?php echo esc_html($r->status); ?></b></div><?php endforeach; ?></div><?php else: ?><?php echo $this->ews_empty_state(__('No Upcoming Schedule','workforce-one'),__('No schedule has been set for your upcoming days.','workforce-one'),$this->app_view_url('schedule'),__('View Schedule','workforce-one')); ?><?php endif; ?>
                        <a class="ews-dash-inline-link" href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><?php esc_html_e('View full schedule','workforce-one'); ?> →</a>
                    </div>
                    <div class="ews-dash-card">
                        <div class="ews-dash-card-head"><div><h3><?php esc_html_e('Quick Actions','workforce-one'); ?></h3><p><?php esc_html_e('What do you need?','workforce-one'); ?></p></div><span>⚡</span></div>
                        <div class="ews-dash-actions"><a href="<?php echo esc_url($this->app_view_url('time')); ?>"><span>🕘</span> <?php esc_html_e('Sign In / Out','workforce-one'); ?></a><a href="<?php echo esc_url($this->app_view_url('notifications')); ?>"><span>🔔</span> <?php esc_html_e('Notifications','workforce-one'); ?><?php if($unread): ?><b><?php echo absint($unread); ?></b><?php endif; ?></a><a href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><span>📅</span> <?php esc_html_e('My Schedule','workforce-one'); ?></a></div>
                    </div>
                </div>
            </div>
            <?php return ob_get_clean();
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
