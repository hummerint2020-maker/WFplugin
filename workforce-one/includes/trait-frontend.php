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

        private function ux_modal($title,$message,$type='error',$confirm=false,$confirm_text='Confirm',$cancel_text='Cancel'){
            $type=in_array($type,['error','success','warning','info'],true)?$type:'info';
            $id='ews-ux-modal-'.wp_generate_uuid4();
            $icon=['error'=>'!','success'=>'✓','warning'=>'!','info'=>'i'][$type];
            $actions=$confirm?'<button type="button" class="ews-ux-cancel">'.$cancel_text.'</button><button type="button" class="ews-ux-confirm">'.$confirm_text.'</button>':'<button type="button" class="ews-ux-ok">OK</button>';
            return '<div id="'.esc_attr($id).'" class="ews-ux-modal" data-type="'.esc_attr($type).'" role="dialog" aria-modal="true"><div class="ews-ux-modal-card"><button type="button" class="ews-ux-close" aria-label="Close">×</button><div class="ews-ux-icon">'.$icon.'</div><h3>'.esc_html($title).'</h3><p>'.esc_html($message).'</p><div class="ews-ux-actions">'.$actions.'</div></div></div>';
        }
        private function ux_notice_from_query(){
            if(isset($_GET['grid_conflict'])){
                $count=absint($_GET['grid_conflict']);
                $saved=absint($_GET['grid_saved']??0);
                $message=$count.' schedule change'.($count===1?' was':'s were').' not saved because another manager changed the same cell before you saved.';
                if($saved>0)$message.=' '.$saved.' other change'.($saved===1?' was':'s were').' saved successfully.';
                $message.=' Please refresh the Attendance page and review the latest schedule.';
                return $this->ux_modal('Schedule Conflict',$message,'warning');
            }
            $map=[

                'saved'=>['success','Saved Successfully','Your changes have been saved.'],
                'leave_sent'=>['success','Request Submitted','Your leave request has been submitted.'],
                'leave_done'=>['success','Request Updated','The leave request has been updated.'],
                'leave_cancel_sent'=>['success','Cancellation Submitted','Your cancellation request has been submitted.'],
                'time_success'=>['success','Attendance Updated','Your attendance action was completed successfully.'],
                'time_reset'=>['success','Day Reset','The selected attendance day has been reset successfully.'],
                'grid_saved'=>['success','Attendance Saved','The attendance schedule has been updated.'],
                'overtime_done'=>['success','Overtime Updated','The overtime request has been updated.'],
                'overtime_rejected'=>['success','Overtime Rejected','The overtime request has been rejected.'],
            ];
            foreach($map as $key=>$v){if(isset($_GET[$key]))return $this->ux_modal($v[1],$v[2],$v[0]);}
            if(isset($_GET['time_error']))return $this->ux_modal('Attendance Action Not Completed',rawurldecode(sanitize_text_field(wp_unslash($_GET['time_error']))),'error');
            if(isset($_GET['break_error']))return $this->ux_modal('Break Action Not Completed',rawurldecode(sanitize_text_field(wp_unslash($_GET['break_error']))),'error');
            if(isset($_GET['break_success']))return $this->ux_modal('Break Updated',rawurldecode(sanitize_text_field(wp_unslash($_GET['break_success']))),'success');
            if(isset($_GET['overtime_error'])){
                $m=['disabled'=>'Overtime Requests are currently disabled.','access'=>'You do not have permission to perform this action.','invalid'=>'Please review the overtime request and try again.','not_found'=>'The overtime request could not be found.','save'=>'The overtime request could not be updated. Please try again.','employee'=>'Your account is not linked to an active employee.','date'=>'Please select a valid future date.','time'=>'Please enter a valid overtime time range.','reason'=>'Please enter a reason for the overtime request.','overlap'=>'The requested overtime overlaps another overtime request.'];
                return $this->ux_modal('Overtime Action Not Completed',$m[sanitize_key($_GET['overtime_error'])]??'Please review the overtime request and try again.','error');
            }
            if(isset($_GET['overtime_sent']))return $this->ux_modal('Overtime Request Submitted','Your overtime request has been submitted.','success');
            if(isset($_GET['vacation_error'])){
                $m=['date'=>'Please select a valid future date range.','no_working_days'=>'The selected range contains no working days.','overlap'=>'You already have a pending or approved vacation overlapping this period.','employee'=>'Your account is not linked to an active employee.','save'=>'We could not save the vacation request. Please try again.'];
                return $this->ux_modal('Vacation Request Not Submitted',$m[sanitize_key($_GET['vacation_error'])]??'Please review the vacation details and try again.','error');
            }
            if(isset($_GET['vacation_sent']))return $this->ux_modal('Vacation Request Submitted','Your vacation request has been submitted.','success');
            if(isset($_GET['vacation_done']))return $this->ux_modal('Vacation Request Updated','The vacation request has been updated.','success');
            $ews_view=sanitize_key($_GET['ews_view']??'');
            if($ews_view==='schedule' && isset($_GET['swap_error'])){
                $m=['invalid_request'=>'Please select a valid employee and work date for the shift swap.','employee'=>'The selected employee could not be found.','not_swappable'=>'These schedules cannot be swapped. Both employees must have different Office/WFH schedules on this day.','pending'=>'A pending swap request already exists for this day.','save'=>'The shift swap could not be saved. Please try again.','not_found'=>'The shift swap request could not be found.','changed'=>'The schedule changed after the swap request was created, so it can no longer be accepted.','expired'=>'The swap request has already been processed or expired.'];
                return $this->ux_modal('Shift Swap Not Completed',$m[sanitize_key($_GET['swap_error'])]??'Please review the shift swap and try again.','error');
            }
            if($ews_view==='schedule' && isset($_GET['swap_sent']))return $this->ux_modal('Shift Swap Submitted','Your shift swap request has been submitted.','success');
            if($ews_view==='schedule' && isset($_GET['swap_done'])){
                $m=['accepted'=>'The shift swap was accepted and both schedules were updated.','rejected'=>'The shift swap request was rejected.','cancelled'=>'The shift swap request was cancelled.'];
                return $this->ux_modal('Shift Swap Updated',$m[sanitize_key($_GET['swap_done'])]??'The shift swap has been updated.','success');
            }
            if(isset($_GET['leave_error'])){
                $m=['date'=>'Please select a valid future date range.','no_working_days'=>'The selected range contains no working days.','overlap'=>'You already have a pending or approved leave overlapping this period.','balance'=>'Insufficient available balance for this Leave Type.','employee'=>'Your account is not linked to an active employee.'];
                return $this->ux_modal('Leave Request Not Submitted',$m[sanitize_key($_GET['leave_error'])]??'Please review the leave details and try again.','error');
            }
            if(isset($_GET['early_error'])){
                $m=['future_date'=>'Please select a future date for Early Leave.','working_day'=>'Early Leave is available only on a configured working day.','office_only'=>'Early Leave is available only on an Office workday. It cannot be requested on Work From Home or Leave/Vacation days.','max_duration'=>'The requested Early Leave exceeds the maximum duration allowed for one request.','monthly_limit'=>'The requested Early Leave exceeds the remaining monthly allowance.','save'=>'We could not submit the Early Leave request. Please try again.','approval_conflict'=>'This request can no longer be approved because the employee is no longer scheduled for an eligible Office workday.'];
                return $this->ux_modal('Early Leave Not Available',$m[sanitize_key($_GET['early_error'])]??'Please review the Early Leave details and try again.','error');
            }
            return '';
        }
        private function ux_clean_url(){
            $url=remove_query_arg(['leave_error','leave_sent','leave_done','leave_cancel_sent','early_error','time_success','time_error','break_success','break_error','overtime_sent','overtime_error','saved','imported','grid_saved','time_reset','time_saved']);
            return $url;
        }
        private function frontend_confirmation_config(){
            $defaults=[
                'swap_cancel'=>1,'swap_reject'=>1,'leave_cancel'=>1,'leave_cancel_reject'=>1,
                'overtime_reject'=>1,'early_leave_reject'=>1,'attendance_reset'=>1,'general_leave_delete'=>1,
                'employee_delete'=>1,'feature_disable'=>1
            ];
            $cfg=get_option('ews_confirmation_actions',[]);
            if(!is_array($cfg))$cfg=[];
            foreach($defaults as $k=>$v){
                if(!array_key_exists($k,$cfg))$cfg[$k]=$v;
            }
            return ['global'=>(bool)get_option('ews_confirm_global',1),'actions'=>$cfg];
        }
        private function ews_empty_state($title,$text='',$action_url='',$action_label=''){
            $html='<div class="ews-empty-state" role="status"><div class="ews-empty-icon" aria-hidden="true">○</div><div class="ews-empty-title">'.esc_html($title).'</div>';
            if($text!=='')$html.='<div class="ews-empty-text">'.esc_html($text).'</div>';
            if($action_url && $action_label)$html.='<a class="ews-btn ews-empty-action" href="'.esc_url($action_url).'">'.esc_html($action_label).'</a>';
            return $html.'</div>';
        }





    public function enqueue_frontend_assets(){
            if(!$this->pwa_is_employee_app_page()) return;
            $root=plugin_dir_url(dirname(__DIR__) . '/employee-schedule-manager.php');
            $ver=defined('EWS_VERSION') ? EWS_VERSION : '3.22.81';
            wp_enqueue_style('workforce-one', $root.'assets/css/workforce-one.css', [], $ver);
            // assets/css/*-rtl.css are generated with rtlcss from the LTR files (see assets/css/README.md).
            wp_style_add_data('workforce-one', 'rtl', 'replace');
            wp_enqueue_script('workforce-one', $root.'assets/js/workforce-one.js', ['wp-i18n'], $ver, true);
            wp_set_script_translations('workforce-one', 'workforce-one', dirname(__DIR__).'/languages');
            wp_add_inline_script('workforce-one','window.ewsConfirmationConfig='.wp_json_encode($this->frontend_confirmation_config()).';','before');
        }

private function layout($title,$body){
            $nav_defaults=[
                'dashboard'=>['label'=>'Dashboard','mobile_label'=>'Dashboard','icon'=>'🏠','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>10,'mobile_order'=>10],
                'schedule'=>['label'=>'Schedule','mobile_label'=>'Schedule','icon'=>'📅','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>20,'mobile_order'=>20],
                'time'=>['label'=>'Sign In / Out','mobile_label'=>'Sign In / Out','icon'=>'🕘','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>30,'mobile_order'=>30],
                'vacation'=>['label'=>'Leave','mobile_label'=>'Leave','icon'=>'📝','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>40,'mobile_order'=>40],
                'overtime'=>['label'=>'Overtime','mobile_label'=>'Overtime','icon'=>'⏱️','desktop_visible'=>1,'mobile_visible'=>0,'desktop_order'=>50,'mobile_order'=>50],
                'tasks'=>['label'=>'Tasks','mobile_label'=>'Tasks','icon'=>'✅','desktop_visible'=>1,'mobile_visible'=>0,'desktop_order'=>60,'mobile_order'=>60],
                'attendance'=>['label'=>'Attendance','mobile_label'=>'Attendance','icon'=>'📝','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>70,'mobile_order'=>50],
                'reports'=>['label'=>'Reports','mobile_label'=>'Reports','icon'=>'📊','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>80,'mobile_order'=>60],
                'attendance-insights'=>['label'=>'Attendance Insights','mobile_label'=>'Attendance Insights','icon'=>'📈','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>90,'mobile_order'=>70],
                'people'=>['label'=>'People','mobile_label'=>'People','icon'=>'👥','desktop_visible'=>1,'mobile_visible'=>1,'desktop_order'=>75,'mobile_order'=>55],
            ];
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
                <div class="ews-mobile-nav" aria-label="Workforce One navigation">
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
                        <a href="<?php echo esc_url(wp_logout_url(get_permalink()));?>">Log out</a></nav>
                    </aside>
                    <main class="ews-main"><?php $ews_ux=$this->ux_notice_from_query(); if($ews_ux) echo $ews_ux; ?>
                        <div class="ews-pwa-help" style="display:none;margin:0 0 12px;padding:11px 13px;border:1px solid #d0d5dd;border-radius:12px;background:#f8fafc;font-size:12px;color:#475467;">
                <strong>📱 Mobile shortcut:</strong> Use your browser menu → <strong>Add to Home Screen</strong> to keep Workforce One one tap away.
            </div>
            <div class="ews-top">
                            <div>
                                <h1 class="ews-title"><?php echo esc_html($title); ?></h1>
                                <div class="ews-sub">Workforce Management Platform <span class="ews-brand-tagline">One Platform. One Team. One Goal.</span></div>
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
                                    <button type="button" class="ews-profile-menu-trigger" aria-label="My Profile menu" aria-expanded="false" aria-controls="ews-profile-menu-dropdown">
                                        <?php if($top_image_type==='photo' && $top_image_url): ?>
                                            <img src="<?php echo esc_url($top_image_url); ?>" alt="" class="ews-profile-menu-avatar">
                                        <?php elseif($top_avatar_url): ?>
                                            <img src="<?php echo esc_url($top_avatar_url); ?>" alt="" class="ews-profile-menu-avatar">
                                        <?php else: ?>
                                            <span class="ews-profile-menu-initials" aria-hidden="true"><?php echo esc_html($top_initials); ?></span>
                                        <?php endif; ?>
                                    </button>
                                    <div id="ews-profile-menu-dropdown" class="ews-profile-menu-dropdown" hidden>
                                        <a href="<?php echo esc_url($this->app_view_url('profile')); ?>"><span class="ews-profile-menu-item-icon">👤</span><span>My Profile</span></a>
                                        <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>"><span class="ews-profile-menu-item-icon">↪</span><span>Log out</span></a>
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
    function ewsShareScheduleWhatsApp(){
        var message="Employee Weekly Schedule - PDF\n\nThe weekly schedule PDF has been prepared. Please attach the downloaded PDF to the WhatsApp group.";
        var wa="https://wa.me/?text="+encodeURIComponent(message);
        var popup=window.open(wa,"_blank","noopener");
        if(!popup){
            window.location.href=wa;
        }
    }
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
            </style><div class="ews-page ews-vac"><div class="ews-vac-hero"><div class="ews-vac-kicker">TIME OFF</div><h2>Leave Management</h2><p>Request time off, track your balance and follow approvals in one place. Leave Types marked “No balance deduction” are recorded without consuming leave balance.</p></div>';
            if(isset($_GET['leave_error'])){$m=['date'=>'Please select a valid future date range.','no_working_days'=>'The selected range contains no working days.','overlap'=>'You already have a pending or approved leave overlapping this period.','balance'=>'Insufficient available balance for this Leave Type.','employee'=>'Your account is not linked to an active employee.'];$html.='<div class="ews-login-error">'.esc_html($m[$_GET['leave_error']]??'Could not submit the leave request.').'</div>';}
            if(isset($_GET['leave_sent']))$html.='<div class="ews-notice ews-notice-success">Leave request submitted successfully.</div>';
            if(isset($_GET['leave_cancel_sent']))$html.='<div class="ews-notice ews-notice-success">Cancellation request submitted to your Manager.</div>';            if(isset($_GET['early_error'])){
                $early_messages=[
                    'future_date'=>'Please select a future date for Early Leave.',
                    'working_day'=>'Early Leave is available only on a configured working day.',
                    'office_only'=>'Early Leave is available only on an Office workday. It cannot be requested on Work From Home or Leave/Vacation days.',
                    'max_duration'=>'The requested Early Leave exceeds the maximum duration allowed for one request.',
                    'monthly_limit'=>'The requested Early Leave exceeds the remaining monthly allowance.',
                    'save'=>'We could not submit the Early Leave request. Please try again.',
                    'approval_conflict'=>'This request can no longer be approved because the employee is no longer scheduled for an eligible Office workday.'
                ];
                $early_msg=$early_messages[sanitize_key($_GET['early_error'])]??'Please review the Early Leave details and try again.';
                $html.='<div id="ews-early-leave-modal" class="ews-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="ews-early-title"><div class="ews-modal-card"><button type="button" class="ews-modal-close" aria-label="Close">×</button><div class="ews-modal-icon">!</div><h3 id="ews-early-title">Early Leave Not Available</h3><p>'.esc_html($early_msg).'</p><button type="button" class="ews-modal-ok">OK</button></div></div>';
            }

            if($emp){
                $html.='<div class="ews-vac-grid"><div class="ews-vac-card"><h3>Request Leave</h3><p style="color:#667085">Select a Leave Type and future date range. Working days are calculated automatically.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_request_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_create"><div class="ews-vac-field" style="margin-top:15px"><label>Leave Type</label><select name="leave_type_id" required>';
                foreach($types as $lt){$b=$balances[$lt->id];$av=max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending);$html.='<option value="'.(int)$lt->id.'">'.esc_html($lt->name).($lt->deduct_balance?' — Available: '.rtrim(rtrim(number_format($av,2,'.',''), '0'),'.'):' — No balance deduction').'</option>';}
                $html.='</select></div><div class="ews-vac-fields"><div class="ews-vac-field"><label>From</label><input id="ews-vac-start" type="date" name="start_date" min="'.esc_attr($today).'" required></div><div class="ews-vac-field"><label>To</label><input id="ews-vac-end" type="date" name="end_date" min="'.esc_attr($today).'" required></div></div><div id="ews-vac-days" class="ews-vac-count">Select dates to calculate working days.</div><div class="ews-vac-field" style="margin-top:14px"><label>Reason <span style="color:#667085">(optional)</span></label><textarea name="reason" rows="3"></textarea></div><button class="ews-btn ews-vac-submit" type="submit">Submit Leave Request</button></form>';
                $html.='<div class="ews-bal-grid">';
                foreach($types as $lt){$b=$balances[$lt->id];$av=max(0,(float)$b->entitlement-(float)$b->used-(float)$b->pending);$html.='<div class="ews-bal"><strong>'.($lt->deduct_balance?esc_html(rtrim(rtrim(number_format($av,2,'.',''), '0'),'.')):'—').'</strong><span>'.esc_html($lt->name).' Available</span></div>';}$html.='</div></div>';
                $rt=$wpdb->prefix.'ews_leave_requests';
                $show_all=(isset($_GET['leave_history']) && sanitize_key($_GET['leave_history'])==='all');
                $leave_sql="SELECT r.*,lt.name type_name,lt.deduct_balance FROM {$rt} r JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.employee_id=%d ORDER BY r.requested_at DESC";
                if(!$show_all)$leave_sql.=" LIMIT 6";
                $my=$wpdb->get_results($wpdb->prepare($leave_sql,$emp->id));
                $html.='<div class="ews-vac-card"><div class="ews-vac-section-head"><h3>My Leave Requests</h3>';
                if($show_all){
                    $recent_url=remove_query_arg('leave_history',$this->app_view_url('vacation'));
                    $html.='<a class="ews-vac-view-all" href="'.esc_url($recent_url).'">← Back to recent requests</a>';
                }
                $html.='</div><div>';
                if(!$my){
                    $html.=$this->ews_empty_state('No Leave Requests Yet','You haven\'t submitted any leave requests yet.',$this->app_view_url('vacation'),'Request Leave');
                }
                foreach($my as $r){$cls=strtolower($r->status)==='approved'?'ews-vac-approved':(strtolower($r->status)==='pending'?'ews-vac-pending':'ews-vac-rejected');$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->type_name).' · '.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-meta">'.(float)$r->requested_days.' working day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div>';
                    if($r->cancellation_status==='Pending')$html.='<div class="ews-vac-meta">Cancellation Pending</div>';
                    elseif($r->cancellation_status==='Rejected')$html.='<div class="ews-vac-meta">Cancellation Rejected — cannot be submitted again.</div>';
                    elseif($this->leave_cancellation_allowed($r))$html.='<div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button" type="submit">Request Cancellation</button></form></div>';
                    elseif($r->status==='Approved' && !empty($r->start_date) && current_time('Y-m-d') >= (string)$r->start_date)$html.='<div class="ews-vac-meta">Cancellation unavailable — leave has started.</div>';
                    $html.='</div><span class="ews-vac-status '.$cls.'">'.esc_html($r->status).'</span></div>';}$html.='</div>';
                if(!$show_all && count($my)===6){
                    $all_url=add_query_arg('leave_history','all',remove_query_arg('leave_history',$this->app_view_url('vacation')));
                    $html.='<div class="ews-vac-view-all-wrap"><a class="ews-vac-view-all-button" href="'.esc_url($all_url).'">View all leave requests <span aria-hidden="true">→</span></a></div>';
                }
                $html.='</div></div>';
                $wd=wp_json_encode(array_values($this->working_days()));$html.='<script>(function(){var a=document.getElementById("ews-vac-start"),b=document.getElementById("ews-vac-end"),o=document.getElementById("ews-vac-days");function f(){if(!a.value||!b.value||b.value<a.value){o.textContent="Select a valid date range.";return}var s=new Date(a.value+"T00:00:00"),e=new Date(b.value+"T00:00:00"),n=0,wd='.$wd.';for(var d=new Date(s);d<=e;d.setDate(d.getDate()+1))if(wd.indexOf(d.getDay())!==-1)n++;o.textContent=n+" working day"+(n===1?"":"s")+" requested.";};a.onchange=f;b.onchange=f;})();</script>';
            }
            $approval_workflow=$this->approval_workflow('vacation');
            if($approval_workflow && (int)$approval_workflow->active===1){
                $rt=$wpdb->prefix.'ews_leave_requests';$at=$this->approval_tables();$uid=get_current_user_id();
                $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,lt.name type_name,ai.step_order FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id JOIN {$at['requests']} ar ON ar.entity_type='leave' AND ar.entity_id=r.id JOIN {$at['instances']} ai ON ai.approval_request_id=ar.id AND ai.status='PENDING' AND ai.approver_wp_user_id=%d WHERE r.status='Pending' ORDER BY r.requested_at ASC",$uid));
                if($rows){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Leave Requests Awaiting Your Approval</h3>';foreach($rows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).' · Level '.(int)$r->step_order.'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).' · '.(float)$r->requested_days.' day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">Approve</button><button class="button" name="decision" value="reject">Reject</button></form></div></div><span class="ews-vac-status ews-vac-pending">Pending</span></div>';}$html.='</div>';}
            }elseif($this->can('ews_manage_settings')){
                $rt=$wpdb->prefix.'ews_leave_requests';$rows=$wpdb->get_results("SELECT r.*,e.name employee_name,lt.name type_name FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Pending Leave Requests</h3>';foreach($rows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).' · '.(float)$r->requested_days.' day(s)'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_vacation_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_vacation_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">Approve</button><button class="button" name="decision" value="reject">Reject</button></form></div></div><span class="ews-vac-status ews-vac-pending">Pending</span></div>';}$html.='</div>';
            }
            $approval_workflow=$this->approval_workflow('vacation');
            if($approval_workflow && (int)$approval_workflow->active===1){
                $rt=$wpdb->prefix.'ews_leave_requests';$at=$this->approval_tables();$uid=get_current_user_id();
                $crows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,lt.name type_name,ai.step_order FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id JOIN {$at['requests']} ar ON ar.entity_type='leave_cancellation' AND ar.entity_id=r.id JOIN {$at['instances']} ai ON ai.approval_request_id=ar.id AND ai.status='PENDING' AND ai.approver_wp_user_id=%d WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC",$uid));
                if($crows){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Leave Cancellations Awaiting Your Approval</h3>';foreach($crows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).' · Level '.(int)$r->step_order.'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">Approve Cancellation</button><button class="button" name="decision" value="reject">Reject</button></form></div></div><span class="ews-vac-status ews-vac-pending">Pending</span></div>';}$html.='</div>';}
            }elseif($this->can('ews_manage_settings')){
                $rt=$wpdb->prefix.'ews_leave_requests';$crows=$wpdb->get_results("SELECT r.*,e.name employee_name,lt.name type_name FROM {$rt} r JOIN {$this->employees} e ON e.id=r.employee_id JOIN {$wpdb->prefix}ews_leave_types lt ON lt.id=r.leave_type_id WHERE r.status='Approved' AND r.cancellation_status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Cancellation Requests</h3>'; if($crows){foreach($crows as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->type_name).'</div><div class="ews-vac-meta">'.esc_html($r->start_date).' → '.esc_html($r->end_date).'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_leave_cancel_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_leave_cancel_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">Approve Cancellation</button><button class="button" name="decision" value="reject">Reject</button></form></div></div></div>';}$html.='</div>';}
            }
            if($emp){
                $monthly=(int)get_option('ews_early_leave_monthly_minutes',240);$used_early=$this->early_leave_month_usage($emp->id,substr($today,0,7));$remaining_early=max(0,$monthly-$used_early);
                $html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Early Leave</h3><p style="color:#667085">Available only on Office workdays · Maximum per request: '.((int)get_option('ews_early_leave_max_minutes',120)/60).' hours · Monthly allowance: '.($monthly/60).' hours · Remaining this month: '.($remaining_early/60).' hours.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_early_leave_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_early_leave_create"><input type="hidden" id="ews-early-remaining" value="'.$remaining_early.'"><input type="hidden" id="ews-early-working-days" value="'.esc_attr(wp_json_encode(array_values($this->working_days()))).'"><div class="ews-vac-fields"><div class="ews-vac-field"><label>Date</label><input type="date" id="ews-early-date" name="work_date" min="'.esc_attr($today).'" required></div><div class="ews-vac-field"><label>Duration (minutes)</label><input type="number" id="ews-early-minutes" name="leave_minutes" min="1" max="'.(int)get_option('ews_early_leave_max_minutes',120).'" required></div></div><div class="ews-vac-field" style="margin-top:12px"><label>Reason</label><textarea name="reason" rows="2"></textarea></div><button class="ews-btn ews-vac-submit" type="submit">Request Early Leave</button></form></div>';
            }
            if($this->can('ews_manage_time')){
                $et=$wpdb->prefix.'ews_early_leave_requests';$ers=$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$et} r JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                if($ers){$html.='<div class="ews-vac-card" style="margin-top:18px"><h3>Pending Early Leave</h3>';foreach($ers as $r){$html.='<div class="ews-vac-row"><div><div class="ews-vac-date">'.esc_html($r->employee_name).' · '.esc_html($r->work_date).'</div><div class="ews-vac-meta">'.floor($r->leave_minutes/60).'h '.($r->leave_minutes%60).'m'.($r->reason?' · '.esc_html($r->reason):'').'</div><div class="ews-vac-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_early_leave_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_early_leave_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><button class="button button-primary" name="decision" value="approve">Approve</button><button class="button" name="decision" value="reject">Reject</button></form></div></div></div>';}$html.='</div>';}
            }
            if(isset($_GET['early_error'])){
                $html.='<script>(function(){var m=document.getElementById("ews-early-leave-modal");if(!m)return;var close=function(){m.remove();try{window.history.replaceState({},document.title,window.location.pathname+window.location.search.replace(/([?&])early_error=[^&]*(&|$)/,"$1").replace(/[?&]$/,""));}catch(e){}};m.querySelector(".ews-modal-close").onclick=close;m.querySelector(".ews-modal-ok").onclick=close;m.addEventListener("click",function(e){if(e.target===m)close()});document.addEventListener("keydown",function(e){if(e.key==="Escape"&&document.getElementById("ews-early-leave-modal"))close()},{once:true});var b=m.querySelector(".ews-modal-ok");if(b)b.focus()})();</script>';
            }
            $html.='<script>(function(){
                function ewsShowEarlyModal(message){
                    var existing=document.getElementById("ews-early-leave-modal");if(existing)existing.remove();
                    var m=document.createElement("div");m.id="ews-early-leave-modal";m.className="ews-modal-backdrop";m.setAttribute("role","dialog");m.setAttribute("aria-modal","true");
                    m.innerHTML="<div class=\"ews-modal-card\"><button type=\"button\" class=\"ews-modal-close\" aria-label=\"Close\">×</button><div class=\"ews-modal-icon\">!</div><h3>Early Leave Not Available</h3><p></p><button type=\"button\" class=\"ews-modal-ok\">OK</button></div>";
                    m.querySelector("p").textContent=message;document.body.appendChild(m);
                    var close=function(){m.remove()};m.querySelector(".ews-modal-close").onclick=close;m.querySelector(".ews-modal-ok").onclick=close;m.onclick=function(e){if(e.target===m)close()};m.querySelector(".ews-modal-ok").focus();
                }
                window.ewsShowEarlyModal=ewsShowEarlyModal;
                var f=document.querySelector("form input[name=\"action\"][value=\"ews_early_leave_create\"]");
                if(f){
                    f=f.closest("form");f.addEventListener("submit",function(e){
                        var d=document.getElementById("ews-early-date"),mins=document.getElementById("ews-early-minutes"),rem=document.getElementById("ews-early-remaining"),wd=document.getElementById("ews-early-working-days");
                        if(!d||!mins)return;var max=parseInt(mins.getAttribute("max")||"120",10),v=parseInt(mins.value||"0",10),r=parseInt(rem&&rem.value||"0",10);
                        if(!d.value){e.preventDefault();ewsShowEarlyModal("Please select a date for Early Leave.");return}
                        var chosen=new Date(d.value+"T00:00:00"),today=new Date();today.setHours(0,0,0,0);
                        if(chosen<=today){e.preventDefault();ewsShowEarlyModal("Early Leave must be requested for a future date.");return}
                        var days=wd?JSON.parse(wd.value||"[]"):[],dow=chosen.getDay();
                        if(days.length&&days.indexOf(dow)===-1){e.preventDefault();ewsShowEarlyModal("Early Leave is available only on a configured working day.");return}
                        if(v<1||v>max){e.preventDefault();ewsShowEarlyModal("The requested Early Leave exceeds the maximum duration allowed for one request.");return}
                        if(v>r){e.preventDefault();ewsShowEarlyModal("The requested Early Leave exceeds the remaining monthly allowance.");return}
                    });
                }
            })();</script>';
            return $html.'</div>';
        }


        private function overtime_content(){
            if(!$this->overtime_enabled())return '<div class="ews-page"><div class="ews-login-error">Overtime Requests are currently disabled.</div></div>';
            if(!is_user_logged_in())return $this->login_page();
            $this->ensure_overtime_schema();global $wpdb;$today=current_time('Y-m-d');$emp=$this->current_employee();
            $html='<style>
                .ews-ot{max-width:980px;margin:0 auto}.ews-ot-hero{background:linear-gradient(135deg,#eef8ff 0%,#fff 65%);border:1px solid #d9edf9;border-radius:20px;padding:24px 26px;margin-bottom:18px}.ews-ot-kicker{font-size:11px;letter-spacing:.12em;font-weight:800;color:#0875b1;text-transform:uppercase;margin-bottom:6px}.ews-ot-hero h2{margin:0;font-size:28px}.ews-ot-hero p{margin:7px 0 0;color:#667085}.ews-ot-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(280px,.85fr);gap:18px}.ews-ot-card{background:#fff;border:1px solid #eaecf0;border-radius:18px;padding:22px;box-shadow:0 5px 18px rgba(16,24,40,.05)}.ews-ot-card h3{margin:0 0 6px;font-size:18px}.ews-ot-muted{color:#667085;font-size:13px}.ews-ot-fields{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-top:18px}.ews-ot-field label{display:block;font-size:12px;font-weight:700;color:#344054;margin-bottom:6px}.ews-ot-field input,.ews-ot-field textarea{box-sizing:border-box;width:100%;border:1px solid #d0d5dd;border-radius:10px;padding:10px 12px;background:#fff}.ews-ot-field input:focus,.ews-ot-field textarea:focus{outline:none;border-color:#0875b1;box-shadow:0 0 0 3px rgba(8,117,177,.12)}.ews-ot-count{display:flex;align-items:center;gap:10px;margin-top:14px;padding:13px 14px;border-radius:12px;background:#eef8ff;border:1px solid #d9edf9;color:#075985;font-weight:700}.ews-ot-submit{width:100%;margin-top:16px!important;justify-content:center}.ews-ot-list{margin-top:18px}.ews-ot-row{padding:15px 0;border-top:1px solid #eaecf0;display:flex;justify-content:space-between;gap:15px;align-items:center}.ews-ot-date{font-weight:800;color:#101828}.ews-ot-meta{font-size:13px;color:#667085;margin-top:4px}.ews-ot-status{padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800;white-space:nowrap}.ews-ot-pending{background:#fff7ed;color:#9a3412}.ews-ot-approved{background:#ecfdf3;color:#027a48}.ews-ot-rejected{background:#fef3f2;color:#b42318}.ews-ot-empty{text-align:center;padding:28px 12px;color:#667085}@media(max-width:760px){.ews-ot{max-width:none}.ews-ot-hero{padding:20px;border-radius:16px}.ews-ot-hero h2{font-size:24px}.ews-ot-grid{grid-template-columns:1fr}.ews-ot-fields{grid-template-columns:1fr}.ews-ot-row{align-items:flex-start}.ews-ot-status{display:inline-block;margin-top:2px}}
            </style><div class="ews-page ews-ot"><div class="ews-ot-hero"><div class="ews-ot-kicker">EXTRA HOURS</div><h2>Overtime</h2><p>Request overtime in advance and know exactly what you are asking to work.</p></div>';
            if(isset($_GET['overtime_error'])){$m=['date'=>'Please select today or a future date.','time'=>'Please select a valid time range.','reason'=>'Please enter a reason for the overtime request.','overlap'=>'You already have a pending or approved overtime request overlapping this period.','employee'=>'Your account is not linked to an active employee.'];$html.='<div class="ews-login-error">'.esc_html($m[$_GET['overtime_error']]??'Could not submit the overtime request.').'</div>';}
            if(isset($_GET['overtime_sent']))$html.='<div class="ews-notice ews-notice-success">Overtime request submitted. You are requesting '.esc_html($_GET['overtime_sent']).' hour(s).</div>';
            if(isset($_GET['overtime_done']))$html.='<div class="ews-notice ews-notice-success">Overtime request approved.</div>';
            if(isset($_GET['overtime_rejected']))$html.='<div class="ews-login-error">Overtime request rejected.</div>';
            if($emp){
                $min=$today;
                $html.='<div class="ews-ot-grid"><div class="ews-ot-card"><h3>Request Overtime</h3><p class="ews-ot-muted">Submit your overtime request before you work the extra hours. Manager approval is required.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_request_create','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_create"><div class="ews-ot-fields"><div class="ews-ot-field"><label>Date</label><input id="ews-ot-date" type="date" name="overtime_date" min="'.esc_attr($min).'" required></div><div class="ews-ot-field"><label>From</label><input id="ews-ot-start" type="time" name="start_time" required></div><div class="ews-ot-field"><label>To</label><input id="ews-ot-end" type="time" name="end_time" required></div></div><div id="ews-ot-count" class="ews-ot-count">🕐 <span>Select the times to see the requested duration.</span></div><div class="ews-ot-field" style="margin-top:14px"><label>Reason</label><textarea name="reason" rows="3" placeholder="Why is overtime required?" required></textarea></div><button class="ews-btn ews-ot-submit" type="submit">Submit Overtime Request</button></form></div>';
                $table=$wpdb->prefix.'ews_overtime_requests';$rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d ORDER BY requested_at DESC LIMIT 20",(int)$emp->id));
                $html.='<div class="ews-ot-card"><h3>My Overtime Requests</h3><p class="ews-ot-muted">Track your pending and completed overtime requests.</p><div class="ews-ot-list">';
                if(!$rows)$html.=$this->ews_empty_state('No Overtime Requests Yet','You haven\'t submitted any overtime requests yet.',$this->app_view_url('overtime'),'Request Overtime');
                else foreach($rows as $r){$status=sanitize_key(strtolower($r->status));$sc=$status==='pending'?'ews-ot-pending':($status==='approved'?'ews-ot-approved':'ews-ot-rejected');$hours=floor($r->requested_minutes/60);$mins=$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->overtime_date).' · '.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).'</div><div class="ews-ot-meta">'.esc_html($duration).' · '.esc_html($r->reason).'</div></div><span class="ews-ot-status '.$sc.'">'.esc_html($r->status).'</span></div>';}
                $html.='</div></div></div>';
                $html.='<script>(function(){var s=document.getElementById("ews-ot-start"),e=document.getElementById("ews-ot-end"),o=document.getElementById("ews-ot-count");function f(){if(!s.value||!e.value){o.innerHTML="🕐 <span>Select the times to see the requested duration.</span>";return;}var a=s.value.split(":"),b=e.value.split(":"),x=(+a[0])*60+(+a[1]),y=(+b[0])*60+(+b[1]);if(y<=x){o.innerHTML="⚠️ <span>End time must be after start time.</span>";return;}var n=y-x,h=Math.floor(n/60),m=n%60;o.innerHTML="🕐 <span>You are requesting <strong>"+h+" hour"+(h===1?"":"s")+(m?" "+m+" min":"")+"</strong>.</span>";}s.addEventListener("change",f);e.addEventListener("change",f);})();</script>';
            }
            $ot_workflow=$this->approval_workflow('overtime');
            $ot_approval_active=$ot_workflow && (int)$ot_workflow->active===1 && in_array(strtoupper((string)$ot_workflow->approval_mode),['LEVEL_1','LEVEL_2'],true);
            if($ot_approval_active){
                $table=$wpdb->prefix.'ews_overtime_requests';$at=$this->approval_tables();
                $rows=$wpdb->get_results($wpdb->prepare("SELECT r.*,e.name employee_name,ars.id approval_step_id,ars.step_order FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id INNER JOIN {$at['requests']} ar ON ar.entity_type='overtime_request' AND ar.entity_id=r.id INNER JOIN {$at['instances']} ars ON ars.approval_request_id=ar.id AND ars.status='PENDING' AND ars.approver_wp_user_id=%d WHERE r.status='Pending' ORDER BY r.requested_at ASC",get_current_user_id()));
                $html.='<div class="ews-ot-card" style="margin-top:18px"><h3>My Pending Overtime Approvals</h3><p class="ews-ot-muted">Requests currently assigned to you by the approval workflow.</p>';
                if(!$rows)$html.=$this->ews_empty_state('No Pending Overtime Approvals','You\'re all caught up.');
                foreach($rows as $r){$hours=floor((int)$r->requested_minutes/60);$mins=(int)$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->employee_name).' · '.esc_html($r->overtime_date).' · Level '.(int)$r->step_order.'</div><div class="ews-ot-meta">'.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).' · <strong>'.esc_html($duration).'</strong></div><div class="ews-ot-meta" style="margin-top:7px">“'.esc_html($r->reason).'”</div><div style="display:flex;gap:8px;margin-top:12px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="approve"><button class="ews-btn" type="submit">Approve</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="reject"><button class="ews-btn secondary" type="submit">Reject</button></form></div></div><span class="ews-ot-status ews-ot-pending">Level '.(int)$r->step_order.'</span></div>';}
                $html.='</div>';
            }elseif($this->can('ews_manage_settings')){
                $table=$wpdb->prefix.'ews_overtime_requests';
                $rows=$wpdb->get_results("SELECT r.*,e.name employee_name FROM {$table} r INNER JOIN {$this->employees} e ON e.id=r.employee_id WHERE r.status='Pending' ORDER BY r.requested_at ASC");
                $html.='<div class="ews-ot-card" style="margin-top:18px"><h3>Pending Overtime Requests</h3><p class="ews-ot-muted">Review overtime requests. Any Manager can approve or reject.</p>';
                if(!$rows)$html.=$this->ews_empty_state('No Pending Overtime Requests','You\'re all caught up.');
                foreach($rows as $r){$hours=floor((int)$r->requested_minutes/60);$mins=(int)$r->requested_minutes%60;$duration=$hours.((int)$hours===1?' hour':' hours').($mins?' '.$mins.' min':'');$html.='<div class="ews-ot-row"><div><div class="ews-ot-date">'.esc_html($r->employee_name).' · '.esc_html($r->overtime_date).'</div><div class="ews-ot-meta">'.esc_html(substr($r->start_time,0,5)).' → '.esc_html(substr($r->end_time,0,5)).' · <strong>'.esc_html($duration).'</strong></div><div class="ews-ot-meta" style="margin-top:7px">“'.esc_html($r->reason).'”</div><div style="display:flex;gap:8px;margin-top:12px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="approve"><button class="ews-btn" type="submit">Approve</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_overtime_respond','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_overtime_request_respond"><input type="hidden" name="request_id" value="'.(int)$r->id.'"><input type="hidden" name="decision" value="reject"><button class="ews-btn secondary" type="submit">Reject</button></form></div></div><span class="ews-ot-status ews-ot-pending">Pending</span></div>';}
                $html.='</div>';
            }
            return $html.'</div>';
        }

        private function attendance_insights_content(){
            if(!$this->can('ews_view_reports')) return $this->ews_empty_state('Access denied','Attendance Insights is not available for your account.');
            global $wpdb;

            $today=current_time('Y-m-d');
            $focus_date=sanitize_text_field($_GET['focus_date']??$today);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$focus_date) || strtotime($focus_date)===false)$focus_date=$today;
            $selected_team=sanitize_text_field($_GET['team']??'all');

            [$dates,$sun]=$this->week_dates_configured($focus_date);
            $week_start=$dates[0]??$focus_date;
            $week_end=$dates[count($dates)-1]??$week_start;

            $this->ensure_departments_schema();
            $team_options=[];$team_names_by_emp=[];$team_map=[];
            if(method_exists($this,'team_tables')){
                $this->ensure_teams_schema();
                $tt=$this->team_tables();
                $team_where='active=1';$team_args=[];
                if(!current_user_can('manage_options')){
                    $dept=(int)$this->current_department_id();
                    if(!$dept)$team_where='1=0';else{$team_where.=' AND department_id=%d';$team_args[]=$dept;}
                }
                $team_sql="SELECT id,name FROM {$tt['teams']} WHERE {$team_where} ORDER BY name ASC";
                $team_rows=$team_args?$wpdb->get_results($wpdb->prepare($team_sql,...$team_args)):$wpdb->get_results($team_sql);
                foreach((array)$team_rows as $tm){$team_map[(int)$tm->id]=(string)$tm->name;$team_options[(string)$tm->name]=(string)$tm->name;}
                $member_rows=$wpdb->get_results("SELECT team_id,employee_id FROM {$tt['members']} WHERE active=1 ORDER BY team_id ASC,employee_id ASC");
                foreach((array)$member_rows as $mr){$eid=(int)$mr->employee_id;$tid=(int)$mr->team_id;if(isset($team_map[$tid]))$team_names_by_emp[$eid][]=$team_map[$tid];}
                foreach($team_names_by_emp as $eid=>$names)$team_names_by_emp[$eid]=array_values(array_unique($names));
            }
            natcasesort($team_options);

            $emps=[];
            $scoped_employees=$this->department_scoped_employees($this->emps());
            foreach((array)$scoped_employees as $e){
                $names=$team_names_by_emp[(int)$e->id]??[];
                if($selected_team!=='all'&&!in_array($selected_team,$names,true))continue;
                $e->_insight_teams=$names;$emps[]=$e;
            }

            $ids=$emps?implode(',',array_map('intval',wp_list_pluck($emps,'id'))):'0';
            $schedule=[];$events=[];
            if($emps){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s",$week_start,$week_end));
                foreach((array)$rows as $r)$schedule[(int)$r->employee_id][$r->work_date]=$r;
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at,latitude,longitude,accuracy,location_status,distance_meters FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date BETWEEN %s AND %s ORDER BY event_at ASC",$week_start,$week_end));
                foreach((array)$rows as $r)$events[(int)$r->employee_id][$r->work_date][$r->event_type]=$r;
            }

            $company_leave=$this->company_leave_dates($week_start,$week_end);
            $company_leave_today=$this->company_leave_dates($today,$today);
            if(!empty($company_leave_today))$company_leave=array_merge($company_leave,$company_leave_today);

            $status_for=function($planned,$ev,$date,$employee_id)use($today,$company_leave){
                if(isset($company_leave[$date]))return ['Leave',$company_leave[$date]];
                $rule=$this->schedule_type_attendance_rule($planned);
                if($rule==='leave')return ['Leave',''];
                if($rule==='business_trip')return [$planned,''];
                if(!$this->schedule_type_requires_sign_in($planned))return ['Not Scheduled',''];
                if(!empty($ev['sign_in'])){
                    $classification=$this->sign_in_classification($ev['sign_in']->event_at,$employee_id);
                    return [$classification==='Late Arrival'?'Late':'Present',date_i18n('H:i',strtotime($ev['sign_in']->event_at))];
                }
                if(!empty($ev['late_sign_in']))return ['Late',date_i18n('H:i',strtotime($ev['late_sign_in']->event_at))];
                if($date<$today)return ['Absent',''];
                if($date===$today){
                    $hours=$this->working_hours($employee_id);$now_ts=current_time('timestamp');
                    $cutoff_ts=strtotime($date.' '.$hours['normal_until'].':00');
                    if(!empty($hours['overnight'])&&$hours['start']>$hours['end']&&$hours['normal_until']<$hours['start'])$cutoff_ts=strtotime(date('Y-m-d',strtotime($date.' +1 day')).' '.$hours['normal_until'].':00');
                    if($cutoff_ts!==false&&$now_ts>=$cutoff_ts)return ['Absent',''];
                }
                return ['Pending',''];
            };

            $planned_labels=['Office','WFH','Leave','Business Trip','Absent','Not Set'];
            $actual_labels=['Present','Late','Absent','Pending','Leave'];
            $day_plan=[];$day_actual=[];
            foreach($dates as $d){$day_plan[$d]=array_fill_keys($planned_labels,0);$day_actual[$d]=array_fill_keys($actual_labels,0);}

            foreach($emps as $e){
                foreach($dates as $d){
                    $planned=trim((string)($schedule[(int)$e->id][$d]->status??''));
                    if($planned==='Vacation')$planned='Leave';
                    $pk=$planned!==''?$planned:'Not Set';
                    if(!isset($day_plan[$d][$pk]))$pk='Not Set';
                    $day_plan[$d][$pk]++;
                    [$actual]=$status_for($planned,$events[(int)$e->id][$d]??[],$d,(int)$e->id);
                    if(isset($day_actual[$d][$actual]))$day_actual[$d][$actual]++;
                }
            }

            $focus_schedule=[];$focus_events=[];
            if($emps){
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date=%s",$focus_date));
                foreach((array)$rows as $r)$focus_schedule[(int)$r->employee_id]=$r;
                $rows=$wpdb->get_results($wpdb->prepare("SELECT employee_id,work_date,event_type,event_at,latitude,longitude,accuracy,location_status,distance_meters FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date=%s ORDER BY event_at ASC",$focus_date));
                foreach((array)$rows as $r)$focus_events[(int)$r->employee_id][$r->event_type]=$r;
            }

            $focus_plan=array_fill_keys($planned_labels,0);$focus_actual=array_fill_keys($actual_labels,0);$focus_missing_sign_out=[];
            $focus_names=['plan'=>[],'actual'=>[]];
            foreach($planned_labels as $x)$focus_names['plan'][$x]=[];
            foreach($actual_labels as $x)$focus_names['actual'][$x]=[];
            foreach($emps as $e){
                $p=trim((string)($focus_schedule[(int)$e->id]->status??''));if($p==='Vacation')$p='Leave';$pk=$p!==''?$p:'Not Set';if(!isset($focus_plan[$pk]))$pk='Not Set';$focus_plan[$pk]++;$focus_names['plan'][$pk][]=(string)$e->name;
                $ev=$focus_events[(int)$e->id]??[];[$a]=$status_for($p,$ev,$focus_date,(int)$e->id);if(isset($focus_actual[$a])){$focus_actual[$a]++;$focus_names['actual'][$a][]=(string)$e->name;}
                if(!empty($ev['sign_in'])&&empty($ev['sign_out']))$focus_missing_sign_out[]=(string)$e->name;
            }
            $focus_names['actual']['Missing Sign-out']=$focus_missing_sign_out;

            $url=function($date=null,$team=null){
                $args=['ews_view'=>'attendance-insights','focus_date'=>$date?:current_time('Y-m-d'),'team'=>$team===null?sanitize_text_field($_GET['team']??'all'):$team];
                return add_query_arg($args,$this->app_view_url('attendance-insights'));
            };
            $focus_prev=$url(date('Y-m-d',strtotime('-1 day',strtotime($focus_date))));
            $focus_next=$url(date('Y-m-d',strtotime('+1 day',strtotime($focus_date))));
            $focus_is_today=($focus_date===$today);
            $week_prev=$url(date('Y-m-d',strtotime('-7 days',strtotime($week_start))));
            $week_next=$url(date('Y-m-d',strtotime('+7 days',strtotime($week_start))));

            ob_start();
            ?>
            <style>
            .ews-fi{max-width:1480px}.ews-fi *{box-sizing:border-box}.ews-fi-card{background:#fff;border:1px solid #e4e7ec;border-radius:16px;overflow:hidden;box-shadow:0 4px 16px rgba(16,24,40,.045);margin-bottom:18px}.ews-fi-head{padding:18px 20px 14px;border-bottom:1px solid #edf0f4}.ews-fi-head h2{margin:0 0 4px;font-size:18px;color:#172033}.ews-fi-head p{margin:0;color:#667085;font-size:12px}.ews-fi-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:18px}.ews-fi-controls a,.ews-fi-controls select,.ews-fi-controls input{min-height:40px;border:1px solid #d0d5dd;border-radius:9px;background:#fff;color:#172033;padding:8px 11px;text-decoration:none}.ews-fi-controls .nav{width:40px;text-align:center;font-size:20px;padding:5px}.ews-fi-controls form{display:flex;gap:8px;align-items:center}.ews-fi-date-label{font-size:11px;font-weight:700;color:#667085}.ews-fi-day-title{font-size:14px;font-weight:800;color:#172033;padding:0 4px}.ews-fi-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;padding:18px 20px}.ews-fi-tile{border:1px solid #e4e7ec;border-radius:12px;padding:13px 12px;background:#fff;cursor:pointer;text-align:left}.ews-fi-tile:hover{border-color:#c4b5fd;box-shadow:0 3px 10px rgba(91,33,182,.08)}.ews-fi-tile b{display:block;font-size:23px;color:#172033;line-height:1}.ews-fi-tile small{display:block;margin-top:5px;color:#667085;font-size:10px;font-weight:700}.ews-fi-office{background:#eff6ff;border-color:#bfdbfe}.ews-fi-wfh{background:#f5f3ff;border-color:#ddd6fe}.ews-fi-leave{background:#fffbeb;border-color:#fde68a}.ews-fi-trip{background:#ecfeff;border-color:#a5f3fc}.ews-fi-absent{background:#fff7ed;border-color:#fed7aa}.ews-fi-notset{background:#f8fafc}.ews-fi-att{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;padding:18px 20px}.ews-fi-att button{min-height:70px;border:1px solid #e4e7ec;border-radius:12px;background:#fff;text-align:left;padding:12px;cursor:pointer}.ews-fi-att button:hover{border-color:#c4b5fd}.ews-fi-att b{display:block;font-size:22px;color:#172033}.ews-fi-att span{font-size:11px;color:#667085;font-weight:700}.ews-fi-missing{background:#fff7ed!important;border-color:#fed7aa!important}.ews-fi-week{overflow:auto}.ews-fi-week table{width:100%;border-collapse:separate;border-spacing:0;min-width:720px}.ews-fi-week th{padding:11px 9px;background:#f8fafc;border-bottom:1px solid #e4e7ec;text-align:left;font-size:11px;color:#475467}.ews-fi-week td{padding:8px 9px;border-bottom:1px solid #edf0f4}.ews-fi-week tr:last-child td{border-bottom:0}.ews-fi-week td:first-child,.ews-fi-week th:first-child{position:sticky;left:0;background:#fff;z-index:1}.ews-fi-week th:first-child{background:#f8fafc}.ews-fi-count{width:100%;min-height:42px;border:1px solid #e4e7ec;border-radius:9px;background:#fff;font-weight:800;cursor:pointer;color:#344054}.ews-fi-count:hover{border-color:#a78bfa}.ews-fi-rowlabel{font-weight:700;color:#344054;white-space:nowrap}.ews-fi-drawer{position:fixed;inset:0;z-index:100000;display:none;background:rgba(16,24,40,.35)}.ews-fi-drawer.open{display:block}.ews-fi-panel{position:absolute;right:0;top:0;height:100%;width:min(430px,94vw);background:#fff;box-shadow:-12px 0 35px rgba(16,24,40,.18);padding:24px;overflow:auto}.ews-fi-panel-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:18px}.ews-fi-panel-head h2{margin:0;font-size:20px;color:#172033}.ews-fi-panel-head p{margin:4px 0 0;color:#667085;font-size:12px}.ews-fi-close{border:0;background:#f2f4f7;border-radius:8px;width:34px;height:34px;font-size:20px;cursor:pointer}.ews-fi-names{display:flex;flex-direction:column;gap:7px}.ews-fi-name{padding:10px 12px;border:1px solid #edf0f4;border-radius:9px;background:#f8fafc;font-weight:600;color:#344054}.ews-fi-empty{padding:18px 20px;color:#667085;text-align:center}.ews-fi-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;background:#f5f3ff;color:#6d28d9;font-size:11px;font-weight:700}
            @media(max-width:1000px){.ews-fi-grid,.ews-fi-att{grid-template-columns:repeat(3,minmax(0,1fr))}}
            @media(max-width:700px){.ews-fi-grid,.ews-fi-att{grid-template-columns:repeat(2,minmax(0,1fr))}.ews-fi-controls form{width:100%}.ews-fi-controls input,.ews-fi-controls select{flex:1}}
            </style>
            <div class="ews-fi">
                <div class="ews-fi-controls">
                    <a class="nav" href="<?php echo esc_url($week_prev); ?>" title="Previous week">‹</a>
                    <span class="ews-fi-badge">Week <?php echo esc_html(date('d M',strtotime($week_start)).' – '.date('d M Y',strtotime($week_end))); ?></span>
                    <a class="nav" href="<?php echo esc_url($week_next); ?>" title="Next week">›</a>
                    <form method="get">
                        <input type="hidden" name="ews_view" value="attendance-insights">
                        <select name="team" onchange="this.form.submit()"><option value="all">All Teams</option><?php foreach($team_options as $team_name): ?><option value="<?php echo esc_attr($team_name); ?>" <?php selected($selected_team,$team_name); ?>><?php echo esc_html($team_name); ?></option><?php endforeach; ?></select>
                        <label class="ews-fi-date-label">Day</label><input type="date" name="focus_date" value="<?php echo esc_attr($focus_date); ?>" onchange="this.form.submit()">
                    </form>
                    <a class="nav" href="<?php echo esc_url($focus_prev); ?>" title="Previous day">‹</a>
                    <span class="ews-fi-day-title"><?php echo esc_html(date_i18n('D, d M Y',strtotime($focus_date))); ?></span>
                    <a class="nav" href="<?php echo esc_url($focus_next); ?>" title="Next day">›</a>
                    <?php if(!$focus_is_today): ?><a href="<?php echo esc_url($url($today)); ?>">Today</a><?php endif; ?>
                </div>

                <div class="ews-fi-card">
                    <div class="ews-fi-head"><h2><?php echo $focus_is_today?'Today':'Selected Day'; ?> · Workforce Overview</h2><p>Planned workforce distribution. Click a count to see the employees behind it.</p></div>
                    <div class="ews-fi-grid">
                        <?php $tiles=[['Office','🏢','ews-fi-office'],['WFH','🏠','ews-fi-wfh'],['Leave','🌴','ews-fi-leave'],['Business Trip','✈️','ews-fi-trip'],['Absent','⚠️','ews-fi-absent'],['Not Set','—','ews-fi-notset']]; foreach($tiles as $t): $names=$focus_names['plan'][$t[0]]??[]; ?>
                        <button type="button" class="ews-fi-tile <?php echo esc_attr($t[2]); ?>" data-label="<?php echo esc_attr($t[0]); ?>" data-date="<?php echo esc_attr(date_i18n('D, d M Y',strtotime($focus_date))); ?>" data-names="<?php echo esc_attr(wp_json_encode($names)); ?>"><span><?php echo esc_html($t[1]); ?></span><b><?php echo count($names); ?></b><small><?php echo esc_html($t[0]); ?></small></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="ews-fi-card">
                    <div class="ews-fi-head"><h2><?php echo $focus_is_today?'Today':'Selected Day'; ?> · Attendance</h2><p>Actual attendance classification based on schedule and time logs. Planned Office/WFH is not inferred from sign-in.</p></div>
                    <div class="ews-fi-att">
                        <?php foreach($actual_labels as $label): $names=$focus_names['actual'][$label]??[]; ?><button type="button" class="ews-fi-<?php echo esc_attr(strtolower($label)); ?>" data-label="<?php echo esc_attr($label); ?>" data-date="<?php echo esc_attr(date_i18n('D, d M Y',strtotime($focus_date))); ?>" data-names="<?php echo esc_attr(wp_json_encode($names)); ?>"><b><?php echo count($names); ?></b><span><?php echo esc_html($label); ?></span></button><?php endforeach; ?>
                        <button type="button" class="ews-fi-missing" data-label="Missing Sign-out" data-date="<?php echo esc_attr(date_i18n('D, d M Y',strtotime($focus_date))); ?>" data-names="<?php echo esc_attr(wp_json_encode($focus_missing_sign_out)); ?>"><b><?php echo count($focus_missing_sign_out); ?></b><span>Missing Sign-out</span></button>
                    </div>
                </div>

                <div class="ews-fi-card">
                    <div class="ews-fi-head"><h2>Weekly Workforce Overview</h2><p>Configured working days for the selected week. Counts are planned schedule values.</p></div>
                    <div class="ews-fi-week"><table><thead><tr><th>Workforce</th><?php foreach($dates as $d): ?><th><?php echo esc_html(date_i18n('D',strtotime($d))); ?><br><small><?php echo esc_html(date_i18n('d M',strtotime($d))); ?></small></th><?php endforeach; ?></tr></thead><tbody><?php foreach($planned_labels as $label): ?><tr><td class="ews-fi-rowlabel"><?php echo esc_html($label); ?></td><?php foreach($dates as $d): $names=[];foreach($emps as $e){$p=trim((string)($schedule[(int)$e->id][$d]->status??''));if($p==='Vacation')$p='Leave';$p=$p!==''?$p:'Not Set';if($p===$label||($label==='Not Set'&&!isset($day_plan[$d][$p])))$names[]=(string)$e->name;} ?><td><button type="button" class="ews-fi-count" data-label="<?php echo esc_attr($label); ?>" data-date="<?php echo esc_attr(date_i18n('D, d M Y',strtotime($d))); ?>" data-names="<?php echo esc_attr(wp_json_encode($names)); ?>"><?php echo (int)$day_plan[$d][$label]; ?></button></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
                </div>
            </div>
            <div class="ews-fi-drawer" id="ews-fi-drawer" aria-hidden="true"><div class="ews-fi-panel"><div class="ews-fi-panel-head"><div><h2 id="ews-fi-title">Employees</h2><p id="ews-fi-subtitle"></p></div><button type="button" class="ews-fi-close" aria-label="Close">×</button></div><div class="ews-fi-names" id="ews-fi-names"></div></div></div>
            <script>
            (function(){
                var drawer=document.getElementById('ews-fi-drawer'),namesEl=document.getElementById('ews-fi-names'),titleEl=document.getElementById('ews-fi-title'),subEl=document.getElementById('ews-fi-subtitle');
                if(!drawer)return;
                function openDrawer(btn){var names=[];try{names=JSON.parse(btn.getAttribute('data-names')||'[]')}catch(e){};titleEl.textContent=(btn.getAttribute('data-label')||'Employees');subEl.textContent=btn.getAttribute('data-date')||'';namesEl.innerHTML='';if(!names.length){namesEl.innerHTML='<div class="ews-fi-empty">No employees in this category.</div>';}else{names.forEach(function(n){var d=document.createElement('div');d.className='ews-fi-name';d.textContent=n;namesEl.appendChild(d);});}drawer.classList.add('open');drawer.setAttribute('aria-hidden','false');}
                document.querySelectorAll('.ews-fi-tile,.ews-fi-att button,.ews-fi-count').forEach(function(btn){btn.addEventListener('click',function(){openDrawer(btn);});});
                function closeDrawer(){drawer.classList.remove('open');drawer.setAttribute('aria-hidden','true');}
                drawer.addEventListener('click',function(e){if(e.target===drawer||e.target.classList.contains('ews-fi-close'))closeDrawer();});document.addEventListener('keydown',function(e){if(e.key==='Escape')closeDrawer();});
            })();
            </script>
            <?php
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
            $layout_title=($view==='profile'?'My Profile':($view==='employee'?'Employee Profile':($view==='presence'?'Presence Verification':ucwords(str_replace('-',' ',$view)))));
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
            $today_result=$sign_in?($this->sign_in_classification($sign_in)):($this->schedule_type_requires_sign_in($planned)?'Not Signed In':$planned);
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
        $defaults=['enabled'=>1,'items'=>['attendance'=>1,'tasks'=>1,'leave'=>1,'schedule'=>1],'attendance_after'=>15,'attendance_repeat'=>0,'attendance_repeat_interval'=>15,'attendance_max_reminders'=>3];
        $saved=get_option('ews_smart_nudges',[]);
        if(!is_array($saved))$saved=[];
        $out=$defaults;
        $out['enabled']=array_key_exists('enabled',$saved)?(!empty($saved['enabled'])?1:0):1;
        if(isset($saved['items'])&&is_array($saved['items']))foreach($defaults['items'] as $k=>$v)$out['items'][$k]=array_key_exists($k,$saved['items'])?(!empty($saved['items'][$k])?1:0):$v;
        $out['attendance_after']=isset($saved['attendance_after'])?max(1,min(240,absint($saved['attendance_after']))):15;
        $out['attendance_repeat']=!empty($saved['attendance_repeat'])?1:0;
        $out['attendance_repeat_interval']=isset($saved['attendance_repeat_interval'])?max(5,min(240,absint($saved['attendance_repeat_interval']))):15;
        $out['attendance_max_reminders']=isset($saved['attendance_max_reminders'])?max(1,min(10,absint($saved['attendance_max_reminders']))):3;
        return $out;
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
        $today=current_time('Y-m-d');
        $today_md=date('m-d',strtotime($today));
        $out=[];
        $ids=array_map('absint',array_keys($saved));
        if(!$ids) return [];
        $rows=$wpdb->get_results("SELECT id,name FROM {$this->employees} WHERE active=1 AND id IN (".implode(',',array_map('intval',$ids)).") ORDER BY name ASC");
        foreach($rows as $e){
            $id=(int)$e->id; $row=$saved[$id]??[];
            $birthday=(string)($row['birthday']??''); $join=(string)($row['join_date']??'');
            if($birthday && substr($birthday,5,5)===$today_md) $out[]=['type'=>'birthday','employee_id'=>$id,'name'=>$e->name,'icon'=>'🎂','title'=>'Birthday','message'=>'Happy birthday, '.(string)$e->name.'!'];
            if($join && substr($join,5,5)===$today_md){ $years=(int)date('Y',strtotime($today))-(int)date('Y',strtotime($join)); if($years>0) $out[]=['type'=>'anniversary','employee_id'=>$id,'name'=>$e->name,'icon'=>'🎉','title'=>'Work Anniversary','message'=>$e->name.' is celebrating '.$years.' '.($years===1?'year':'years').' with the team!']; }
            if($join && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$join)){ $join_ts=strtotime($join.' 00:00:00'); $today_ts=strtotime($today.' 00:00:00'); $days=(int)floor(($today_ts-$join_ts)/DAY_IN_SECONDS); if($days>=0 && $days<30) $out[]=['type'=>'welcome','employee_id'=>$id,'name'=>$e->name,'icon'=>'👋','title'=>'Welcome','message'=>'Welcome to Workforce One, '.$e->name.'!']; }
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
                        <div><div class="ews-dash-kicker">BA Team · Workforce One</div><h2>Good to see you.</h2><p><?php echo esc_html(current_time('l, d F Y')); ?> · Here’s today’s workforce snapshot.</p></div>
                    </div>
                    <div class="ews-dash-stats">
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon purple">👥</div><div><div class="n"><?php echo $count; ?></div><div class="l">Active Employees</div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon green">🏢</div><div><div class="n"><?php echo $office; ?></div><div class="l">Office Today</div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon blue">🏠</div><div><div class="n"><?php echo $wfh; ?></div><div class="l">WFH Today</div></div></div>
                        <div class="ews-dash-stat"><div class="ews-dash-stat-icon orange">✈</div><div><div class="n"><?php echo $leave; ?></div><div class="l">Leave / Mission</div></div></div>
                    </div>
                    <?php echo $this->employee_moments_markup(); ?>
                    <?php echo $this->employee_poll_markup(); ?>
                    <div class="ews-dash-grid">
                        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3>This Week</h3><p><?php $wfo_days=$this->working_days(); $wfo_names=$this->working_day_names(); echo esc_html($wfo_days?implode(' → ',array_map(function($d)use($wfo_names){return $wfo_names[$d]??'';},$wfo_days)):'No working days configured'); ?></p></div><span>📅</span></div><div class="ews-dash-week-line"><div><strong>Plan your week</strong><small>Review schedules and keep your team aligned.</small></div><a class="ews-btn" href="<?php echo esc_url($this->app_view_url('schedule')); ?>">View Schedule</a></div></div>
                        <div class="ews-dash-card"><div class="ews-dash-card-head"><div><h3>Quick Actions</h3><p>Common tasks</p></div><span>⚡</span></div><div class="ews-dash-actions"><a href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><span>📅</span> Schedule</a><?php if($this->can('ews_manage_attendance')): ?><a href="<?php echo esc_url($this->app_view_url('attendance')); ?>"><span>📝</span> Attendance</a><?php endif; ?><a href="<?php echo esc_url($this->app_view_url('notifications')); ?>"><span>🔔</span> Notifications<?php if($unread): ?><b><?php echo absint($unread); ?></b><?php endif; ?></a></div></div>
                    </div>
                    <div class="ews-dash-card ews-dash-status"><div class="ews-dash-card-head"><div><h3>Today at a glance</h3><p>Current schedule distribution</p></div><span>✓</span></div><div class="ews-dash-bars"><div><div><span>Office</span><b><?php echo $office; ?></b></div><i><em style="width:<?php echo $count?min(100,round(($office/$count)*100)):0; ?>%"></em></i></div><div><div><span>WFH</span><b><?php echo $wfh; ?></b></div><i><em class="blue" style="width:<?php echo $count?min(100,round(($wfh/$count)*100)):0; ?>%"></em></i></div><div><div><span>Leave / Mission</span><b><?php echo $leave; ?></b></div><i><em class="orange" style="width:<?php echo $count?min(100,round(($leave/$count)*100)):0; ?>%"></em></i></div></div></div>
                </div>
                </div>
            <?php return ob_get_clean();
            }

            /* Employees get a personal dashboard. */
            $emp=$this->current_employee();
            if(!$emp){
                return '<div class="ews-dashboard"><div class="ews-dash-card ews-employee-dashboard-empty"><h2>Employee account not linked</h2><p>Your WordPress account is not linked to an active employee record. Please contact your manager.</p></div></div>';
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
                    <div><div class="ews-dash-kicker">BA Team · Workforce One</div><h2>Good to see you, <?php echo esc_html($first_name?:$emp->name); ?>.</h2><p><?php echo esc_html(current_time('l, d F Y')); ?> · Here’s your day at a glance.</p></div>
                </div>

                <?php echo $this->employee_moments_markup(); ?>

                <?php echo $this->employee_poll_markup(); ?>

                <?php echo $this->smart_nudges_markup($emp); ?>

                <div class="ews-employee-today">
                    <div class="ews-employee-today-head">
                        <div><span class="ews-personal-label">TODAY'S SCHEDULE</span><h3><?php echo esc_html($status); ?></h3><p><?php echo $loc?esc_html($loc->name):'Default location'; ?></p></div>
                        <span class="ews-personal-status <?php echo $is_working?'working':'neutral'; ?>"><?php echo $is_working?'Working day':'No work scheduled'; ?></span>
                    </div>
                    <div class="ews-personal-meta">
                        <div><span>Work Location</span><b><?php echo $loc?esc_html($loc->name):'Default'; ?></b></div>
                        <div><span>Sign In</span><b><?php echo $sign_in?esc_html(date('h:i A',strtotime($sign_in))):'Not recorded'; ?></b></div>
                        <div><span>Sign Out</span><b><?php echo $sign_out?esc_html(date('h:i A',strtotime($sign_out))):'Not recorded'; ?></b></div>
                    </div>
                    <a class="ews-btn ews-personal-action" href="<?php echo esc_url($this->app_view_url('time')); ?>"><?php echo $sign_in?'View Attendance':'Sign In / Out'; ?></a>
                </div>

                <div class="ews-dash-grid">
                    <?php if($this->overtime_enabled() && ($ot_summary['approved_minutes']>0 || $ot_summary['actual_approved_minutes']>0 || $ot_summary['unapproved_extra_minutes']>0 || !empty($ot_summary['approved_requests']))): ?>
                    <div class="ews-dash-card ews-overtime-attendance-card">
                        <div class="ews-dash-card-head"><div><h3>Overtime Today</h3><p>Approved overtime and actual attendance</p></div><span>⏱️</span></div>
                        <div class="ews-personal-meta">
                            <div><span>Approved OT</span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['approved_minutes'])); ?></b></div>
                            <div><span>Actual OT</span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['actual_approved_minutes'])); ?></b></div>
                            <?php if($ot_summary['unapproved_extra_minutes']>0): ?><div><span>Unapproved Extra</span><b><?php echo esc_html($this->format_duration_minutes($ot_summary['unapproved_extra_minutes'])); ?></b></div><?php endif; ?>
                        </div>
                        <?php if($ot_summary['sign_out']): ?><div style="margin-top:10px;color:#667085;font-size:13px">Sign Out: <strong><?php echo esc_html(date_i18n('g:i A',strtotime($ot_summary['sign_out']))); ?></strong></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="ews-dash-card">
                        <div class="ews-dash-card-head"><div><h3>My Week</h3><p>Your upcoming schedule</p></div><span>📅</span></div>
                        <?php if($next): ?><div class="ews-my-week-list"><?php foreach($next as $r): ?><div><span><?php echo esc_html(date('D, d M',strtotime($r->work_date))); ?></span><b><?php echo esc_html($r->status); ?></b></div><?php endforeach; ?></div><?php else: ?><?php echo $this->ews_empty_state('No Upcoming Schedule','No schedule has been set for your upcoming days.',$this->app_view_url('schedule'),'View Schedule'); ?><?php endif; ?>
                        <a class="ews-dash-inline-link" href="<?php echo esc_url($this->app_view_url('schedule')); ?>">View full schedule →</a>
                    </div>
                    <div class="ews-dash-card">
                        <div class="ews-dash-card-head"><div><h3>Quick Actions</h3><p>What do you need?</p></div><span>⚡</span></div>
                        <div class="ews-dash-actions"><a href="<?php echo esc_url($this->app_view_url('time')); ?>"><span>🕘</span> Sign In / Out</a><a href="<?php echo esc_url($this->app_view_url('notifications')); ?>"><span>🔔</span> Notifications<?php if($unread): ?><b><?php echo absint($unread); ?></b><?php endif; ?></a><a href="<?php echo esc_url($this->app_view_url('schedule')); ?>"><span>📅</span> My Schedule</a></div>
                    </div>
                </div>
            </div>
            <?php return ob_get_clean();
        }

    private function week_dates(){
            $raw=sanitize_text_field($_GET['week']??current_time('Y-m-d'));
            return $this->week_dates_configured($raw);
        }

    /**
     * Order the shared Team Schedule by team, while keeping each employee visible only once.
     * Employees who belong to multiple teams are grouped under their first team alphabetically
     * and receive a compact team label in the employee cell.
     */
    private function schedule_employees_ordered($emps,$current_emp_id=0){
        global $wpdb;
        $emps=(array)$emps;
        if(!$emps || !method_exists($this,'team_tables')) return $emps;

        $t=$this->team_tables();
        // Team tables are created by maybe_upgrade_schema(); only probe on a not-yet-upgraded install.
        if(!$this->ews_schema_is_current()){
            $teams_exist=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['teams']));
            $members_exist=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$t['members']));
            if($teams_exist!==$t['teams'] || $members_exist!==$t['members']) return $emps;
        }

        $team_rows=$wpdb->get_results("SELECT tm.id,tm.name,tm.manager_employee_id FROM {$t['teams']} tm WHERE tm.active=1 ORDER BY tm.name ASC");
        if(!$team_rows) return $emps;

        $team_by_id=[];
        $manager_team_ids=[];
        foreach($team_rows as $team){
            $tid=(int)$team->id;
            $manager=(int)$team->manager_employee_id;
            $team_by_id[$tid]=['id'=>$tid,'name'=>(string)$team->name,'manager'=>$manager];
            if($manager>0) $manager_team_ids[$manager][]=$tid;
        }

        $member_rows=$wpdb->get_results("SELECT m.team_id,m.employee_id FROM {$t['members']} m WHERE m.active=1 ORDER BY m.team_id ASC,m.employee_id ASC");
        $emp_teams=[];
        foreach($member_rows as $m){
            $tid=(int)$m->team_id; $eid=(int)$m->employee_id;
            if(!isset($team_by_id[$tid])) continue;
            $emp_teams[$eid][]=$tid;
        }

        foreach($emps as $e){
            $eid=(int)$e->id;
            $team_ids=array_values(array_unique(array_map('intval',(array)($emp_teams[$eid]??[]))));
            usort($team_ids,function($a,$b)use($team_by_id){ return strcasecmp($team_by_id[$a]['name'],$team_by_id[$b]['name']); });

            $managed_ids=array_values(array_unique(array_map('intval',(array)($manager_team_ids[$eid]??[]))));
            usort($managed_ids,function($a,$b)use($team_by_id){ return strcasecmp($team_by_id[$a]['name'],$team_by_id[$b]['name']); });

            // If the employee manages a team, make the first managed team the
            // primary team so every manager is recognized against the team they manage.
            if($managed_ids){
                $primary=(int)$managed_ids[0];
                if(in_array($primary,$team_ids,true)){
                    $team_ids=array_values(array_unique(array_merge([$primary],$team_ids)));
                } else {
                    // Defensive fallback: a Team Manager should normally also
                    // be a member of the team, but still recognize the relationship.
                    array_unshift($team_ids,$primary);
                }
            }

            $names=[];
            foreach($team_ids as $tid){
                if(isset($team_by_id[$tid])) $names[]=$team_by_id[$tid]['name'];
            }

            $primary_name=$team_ids && isset($team_by_id[$team_ids[0]]) ? $team_by_id[$team_ids[0]]['name'] : '';
            $primary_manager=($team_ids && isset($team_by_id[$team_ids[0]]) && (int)$team_by_id[$team_ids[0]]['manager']===$eid) ? 1 : 0;

            $managed_names=[];
            foreach($managed_ids as $tid){
                if(isset($team_by_id[$tid])) $managed_names[]=$team_by_id[$tid]['name'];
            }

            $e->_schedule_team_names=$names;
            $e->_schedule_primary_team=$primary_name;
            $e->_schedule_team_manager=$primary_manager;
            $e->_schedule_manager_teams=$managed_names;
        }

        usort($emps,function($a,$b)use($current_emp_id){
            $ga=(string)($a->_schedule_primary_team??'');
            $gb=(string)($b->_schedule_primary_team??'');
            if($ga==='' && $gb!=='') return 1;
            if($ga!=='' && $gb==='') return -1;
            $c=strcasecmp($ga,$gb);
            if($c!==0) return $c;

            // The Team Manager for this employee's primary team always comes first.
            $ma=(int)($a->_schedule_team_manager??0);
            $mb=(int)($b->_schedule_team_manager??0);
            if($ma!==$mb) return $mb<=>$ma;

            // Keep the logged-in employee first among other members of the same team.
            $ca=(int)($a->id)===(int)$current_emp_id ? 1 : 0;
            $cb=(int)($b->id)===(int)$current_emp_id ? 1 : 0;
            if($ca!==$cb) return $cb<=>$ca;

            return strcasecmp((string)$a->name,(string)$b->name);
        });
        return $emps;
    }

    private function schedule_content(){
            global $wpdb;
            $current_emp=$this->current_employee();
            $current_emp_id=$current_emp?(int)$current_emp->id:0;
            $emps=$this->schedule_employees_ordered($this->department_scoped_employees(),$current_emp_id);
            [$dates,$sun]=$this->week_dates();
            $week_end=$dates[count($dates)-1]??$dates[0];
            $ph=implode(',',array_fill(0,count($dates),'%s'));
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE work_date IN ($ph)",...$dates));
            $map=[];
            foreach($rows as $r)$map[$r->employee_id][$r->work_date]=$r->status;
            $company_leave=$this->company_leave_dates($dates[0],$week_end);
            ob_start();
            if(isset($_GET['email_sent'])||isset($_GET['email_skipped'])||isset($_GET['email_failed'])){
                echo '<div class="ews-notice">Schedule emails sent: <strong>'.absint($_GET['email_sent']??0).'</strong> · skipped: <strong>'.absint($_GET['email_skipped']??0).'</strong> · failed: <strong>'.absint($_GET['email_failed']??0).'</strong></div>';
            }
            ?>
            <div class="ews-schedule-page">
                <div class="ews-schedule-head">
                    <div>
                        <div class="ews-dash-kicker">TEAM SCHEDULE</div>
                        <h2>Team Schedule</h2>
                        <p>Everyone's schedule · <?php echo esc_html(date('d M',strtotime($dates[0])).' – '.date('d M Y',strtotime($week_end))); ?></p>
                    </div>
                    <div class="ews-week-nav">
                        <a href="<?php echo esc_url(add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('-7 days',$sun))])); ?>" aria-label="Previous week">‹</a>
                        <span>This week</span>
                        <a href="<?php echo esc_url(add_query_arg(['ews_view'=>'schedule','week'=>date('Y-m-d',strtotime('+7 days',$sun))])); ?>" aria-label="Next week">›</a>
                    </div>
                </div>

                <div class="ews-schedule-tools ews-no-print">
                    <form>
                        <input type="hidden" name="ews_view" value="schedule">
                        <label>Week <input type="date" name="week" value="<?php echo esc_attr(date('Y-m-d',$sun)); ?>"></label>
                        <button class="ews-btn" type="submit">View Week</button>
                    </form>
                    <div class="ews-schedule-tool-actions">
                        <button type="button" class="ews-btn secondary ews-pdf-btn" onclick="(function(){document.title='Team Schedule - <?php echo esc_js(date('d M Y',strtotime($dates[0])));?> to <?php echo esc_js(date('d M Y',strtotime($week_end)));?>';window.print();})();">⬇ PDF</button>
                        <button type="button" class="ews-btn secondary ews-wa-btn" onclick="(function(){document.title='Team Weekly Schedule';window.print();setTimeout(ewsShareScheduleWhatsApp,1200);})()">WhatsApp</button>
                        <?php if($this->can('ews_view_reports')): ?>
                            <a class="ews-btn" href="<?php echo esc_url(wp_nonce_url(add_query_arg(['action'=>'ews31_report_email','start'=>$dates[0],'end'=>$week_end],admin_url("admin-post.php")),'ews31_report')); ?>" onclick="return confirm('Send each active employee their own schedule for this week?');">✉ Send schedules</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ews-schedule-legend">
                    <?php foreach($this->schedule_types_config(true) as $stype): echo $this->status_html($stype['name']); endforeach; ?>
                    <?php echo $this->status_html('General Leave'); ?>
                    <?php echo $this->status_html('Absent'); ?>
                    <?php echo $this->status_html('Not Set'); ?>
                </div>

                <div class="ews-schedule-info">
                    <span>👥</span>
                    <div><strong>Shared team schedule</strong><small>Everyone can view the team's schedule to coordinate coverage and arrange swaps with colleagues.</small></div>
                </div>

                <div class="ews-card ews-week ews-schedule-card">
                    <div class="ews-schedule-table-wrap">
                        <table class="ews-table ews-schedule-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <?php foreach($dates as $d): ?>
                                        <th class="<?php echo $d===current_time('Y-m-d')?'today':''; ?>">
                                            <?php echo esc_html(date('D',strtotime($d))); ?>
                                            <small><?php echo esc_html(date('d M',strtotime($d))); ?></small>
                                        </th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(!$emps): ?>
                                    <tr><td colspan="6"><div class="ews-schedule-empty"><div>📅</div><strong>No employees found</strong><span>There are no active employees to display.</span></div></td></tr>
                                <?php else: foreach($emps as $e): ?>
                                    <tr class="ews-att-employee-row <?php echo trim(($current_emp_id===(int)$e->id?'current-user ':'').(!empty($e->_schedule_team_manager)?'team-manager':'')); ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name.' '.$e->domain_name)); ?>">
                                        <td>
                                            <div class="ews-person"><?php echo esc_html($e->name); ?><?php if($current_emp_id===(int)$e->id): ?><span class="ews-me-badge">You</span><?php endif; ?><?php if(!empty($e->_schedule_team_manager)): ?><span class="ews-manager-badge">Manager</span><?php endif; ?></div>
                                            <div class="ews-domain"><?php echo esc_html($e->domain_name); ?></div>
                                            <?php if(!empty($e->_schedule_team_names)): ?>
                                                <div class="ews-domain" style="margin-top:2px;font-weight:600">👥 <?php echo esc_html(implode(' · ',$e->_schedule_team_names)); ?></div>
                                            <?php else: ?>
                                                <div class="ews-domain" style="margin-top:2px">No Team</div>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach($dates as $d):
                                            $s=isset($company_leave[$d])?'General Leave':($map[$e->id][$d]??'Not Set');
                                        ?>
                                            <td data-label="<?php echo esc_attr(date('D, d M',strtotime($d))); ?>" class="<?php echo $d===current_time('Y-m-d')?'today':''; ?>">
                                                <?php echo $this->status_html($s); ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if($current_emp_id): $swap_requests=$this->swap_requests_for_user($current_emp_id,$dates[0],$week_end); ?>
                <div class="ews-swap-panel ews-no-print">
                    <div class="ews-swap-panel-head">
                        <div><div class="ews-dash-kicker">SCHEDULE SWAP</div><h3>Need to swap a day?</h3><p>Choose a colleague and request a direct schedule swap. No approval is required.</p></div>
                        <button type="button" class="ews-btn" id="ews-open-swap">⇄ Request Swap</button>
                    </div>
                    <div id="ews-swap-form-wrap" class="ews-swap-form-wrap" style="display:none;">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-swap-form">
                            <?php wp_nonce_field('ews_swap_create'); ?>
                            <input type="hidden" name="action" value="ews_swap_create">
                            <div><label>Day</label><select name="work_date" required>
                                <?php foreach($dates as $d): $mine=$map[$current_emp_id][$d]??'Not Set'; if(in_array($mine,['Office','WFH'],true) && !isset($company_leave[$d])): ?>
                                    <option value="<?php echo esc_attr($d); ?>"><?php echo esc_html(date('l, d M',strtotime($d)).' — '.$mine); ?></option>
                                <?php endif; endforeach; ?>
                            </select></div>
                            <div><label>Swap with</label><select name="target_employee_id" required>
                                <option value="">Select colleague</option>
                                <?php foreach($emps as $e): if((int)$e->id!==$current_emp_id): ?><option value="<?php echo (int)$e->id; ?>"><?php echo esc_html($e->name); ?></option><?php endif; endforeach; ?>
                            </select></div>
                            <div class="ews-swap-form-actions"><button class="ews-btn" type="submit">Send Request</button><button class="ews-btn secondary" type="button" id="ews-close-swap">Cancel</button></div>
                        </form>
                    </div>

                    <div class="ews-swap-requests">
                        <div class="ews-swap-requests-title">Swap Requests · This Week</div>
                        <?php if($swap_requests): foreach($swap_requests as $sr): $incoming=(int)$sr->target_employee_id===$current_emp_id; ?>
                        <div class="ews-swap-request">
                            <div class="ews-swap-request-main"><div class="ews-swap-avatar">⇄</div><div><strong><?php echo esc_html($incoming?$sr->requester_name:$sr->target_name); ?></strong><span><?php echo esc_html(date('D, d M',strtotime($sr->work_date))); ?> · <?php echo esc_html($sr->requester_status.' ↔ '.$sr->target_status); ?></span></div></div>
                            <div class="ews-swap-request-right"><span class="ews-swap-state <?php echo esc_attr(strtolower($sr->status)); ?>"><?php echo esc_html($sr->status); ?></span>
                            <?php if($incoming && $sr->status==='Pending'): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_swap_respond_'.(int)$sr->id); ?><input type="hidden" name="action" value="ews_swap_respond"><input type="hidden" name="swap_id" value="<?php echo (int)$sr->id; ?>"><button class="ews-swap-small accept" name="decision" value="accept">Accept</button><button class="ews-swap-small reject" name="decision" value="reject">Reject</button></form>
                            <?php elseif(!$incoming && $sr->status==='Pending'): ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('ews_swap_cancel_'.(int)$sr->id); ?><input type="hidden" name="action" value="ews_swap_cancel"><input type="hidden" name="swap_id" value="<?php echo (int)$sr->id; ?>"><button class="ews-swap-small reject" type="submit">Cancel</button></form>
                            <?php endif; ?></div>
                        </div>
                        <?php endforeach; else: ?>
                            <?php echo $this->ews_empty_state('No Swap Requests','There are no swap requests for this week.'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <script>
                (function(){
                    var o=document.getElementById('ews-open-swap'),c=document.getElementById('ews-close-swap'),w=document.getElementById('ews-swap-form-wrap');
                    if(o)o.addEventListener('click',function(){w.style.display='block';o.style.display='none';});
                    if(c)c.addEventListener('click',function(){w.style.display='none';o.style.display='inline-block';});
                    var flash=document.querySelector('.ews-swap-message.success');
                    if(flash){
                        setTimeout(function(){
                            flash.style.transition='opacity .25s ease, transform .25s ease';
                            flash.style.opacity='0';
                            flash.style.transform='translateY(-4px)';
                            setTimeout(function(){ if(flash&&flash.parentNode) flash.parentNode.removeChild(flash); },300);
                        },3000);
                    }
                })();
                </script>

                <div class="ews-swap-hint">
                    <span>⇄</span>
                    <div><strong>Planning a swap?</strong><small>Use this shared schedule to find a colleague with a compatible day. You can request a swap directly from this page.</small></div>
                </div>
            </div>
            <?php return ob_get_clean();
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
            $hours=$this->working_hours($emp?$emp->id:0);$grace_period=$this->attendance_grace_period($emp?$emp->id:0);$face_signin_enabled=$this->face_signin_enabled();$requires_location=$sch?$this->schedule_type_requires_location($sch->status):false;$break_data=($emp&&$this->break_enabled())?$this->break_ui_data($emp->id):null;ob_start();
            if(isset($_GET['time_success']))echo '<div id="ews-time-flash" class="ews-notice ews-time-success">'.esc_html(wp_unslash($_GET['time_success'])).'</div>';
            if(isset($_GET['time_error']))echo '<div id="ews-time-flash" class="ews-notice ews-time-error">'.esc_html(wp_unslash($_GET['time_error'])).'</div>';
            ?>
            <?php if($working && $requires_location && !isset($ev['sign_in']) && !isset($ev['late_sign_in']) && !isset($ev['sign_out'])): ?>
            <div id="ews-location-gate" class="ews-location-gate" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e('Location permission required','workforce-one'); ?>">
                <div class="ews-location-gate-card">
                    <div class="ews-location-icon" aria-hidden="true">📍</div>
                    <h2><?php esc_html_e('Location Services Required','workforce-one'); ?></h2>
                    <p id="ews-location-message"><?php esc_html_e('Please allow location access when your browser asks. Your location is required to record Sign In / Sign Out.','workforce-one'); ?></p>
                    <div class="ews-location-spinner" id="ews-location-spinner" aria-hidden="true"></div>
                    <button type="button" id="ews-location-retry" class="ews-location-retry" hidden><?php esc_html_e('Allow Location / Try Again','workforce-one'); ?></button>
                </div>
            </div>
            <?php endif; ?>
            <div class="ews-time-card" data-location-required="<?php echo $working && $requires_location && !isset($ev['sign_in']) && !isset($ev['late_sign_in']) && !isset($ev['sign_out'])?'1':'0'; ?>"><div class="ews-time-date"><?php echo esc_html(date_i18n('l, d F Y')); ?></div>
            <?php if(!$emp): ?><div class="ews-time-block"><div class="ews-time-icon">👤</div><h2><?php esc_html_e('Employee account not linked','workforce-one'); ?></h2><p><?php esc_html_e('Your WordPress account is not linked to an active employee. Please contact your manager.','workforce-one'); ?></p></div>
            <?php elseif(!$working): ?><div class="ews-time-block"><div class="ews-time-icon">📅</div><h2><?php esc_html_e('Today is not a working day for you','workforce-one'); ?></h2><p><?php printf(/* translators: %s: today's schedule status */esc_html__('Your schedule today is %s. Sign In / Sign Out is only available on working days.','workforce-one'),'<strong>'.esc_html($sch?$sch->status:__('Not Set','workforce-one')).'</strong>'); ?></p></div>
            <?php else: ?><div class="ews-time-employee"><strong><?php echo esc_html($emp->name); ?></strong><span><?php echo esc_html($emp->domain_name); ?></span><b><?php echo esc_html($sch->status); ?></b></div>
            <div class="ews-time-actions">
            <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>" class="ews-face-signin-form" data-face-event="sign_in"><input type="hidden" name="face_verified" value="0"><?php wp_nonce_field('ews_time_event_sign_in'); ?><input type="hidden" name="action" value="ews31_time_event"><input type="hidden" name="event_type" value="sign_in"><input type="hidden" name="latitude" class="ews-lat"><input type="hidden" name="longitude" class="ews-lng"><input type="hidden" name="accuracy" class="ews-accuracy"><input type="hidden" name="location_timestamp" class="ews-location-timestamp"><button class="ews-time-btn sign-in" <?php disabled(isset($ev['sign_in'])||isset($ev['late_sign_in'])||!$sign_in_open); ?>>✓ <?php esc_html_e('Sign In','workforce-one'); ?></button></form>
            <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>"><?php wp_nonce_field('ews_time_event_sign_out'); ?><input type="hidden" name="action" value="ews31_time_event"><input type="hidden" name="event_type" value="sign_out"><input type="hidden" name="latitude" class="ews-lat"><input type="hidden" name="longitude" class="ews-lng"><input type="hidden" name="accuracy" class="ews-accuracy"><input type="hidden" name="location_timestamp" class="ews-location-timestamp"><button class="ews-time-btn out" <?php disabled((!isset($ev['sign_in'])&&!isset($ev['late_sign_in']))||isset($ev['sign_out'])); ?>>↪ <?php esc_html_e('Sign Out','workforce-one'); ?></button></form>
            </div>
            <div class="ews-time-window <?php echo (isset($ev['sign_out'])?'closed':((isset($ev['sign_in'])||isset($ev['late_sign_in']))?'open':($sign_in_open?'open':'closed'))); ?>">
                <?php if(isset($ev['sign_out'])): ?>
                    <strong><?php esc_html_e('You signed out today.','workforce-one'); ?></strong> <?php printf(/* translators: 1: sign-in time, 2: sign-out time */esc_html__('Sign In was recorded at %1$s and Sign Out at %2$s.','workforce-one'),esc_html(date_i18n('h:i A',strtotime(isset($ev['sign_in'])?$ev['sign_in']->event_at:$ev['late_sign_in']->event_at))),esc_html(date_i18n('h:i A',strtotime($ev['sign_out']->event_at)))); ?>
                <?php elseif(isset($ev['sign_in'])||isset($ev['late_sign_in'])): ?>
                    <strong><?php esc_html_e('You are signed in.','workforce-one'); ?></strong> <?php printf(/* translators: 1: "On Time" / "Late Arrival" / "Sign In", 2: time */esc_html__('%1$s recorded at %2$s.','workforce-one'),esc_html(isset($ev['sign_in'])?$this->sign_in_classification_label($this->sign_in_classification($ev['sign_in']->event_at)):__('Sign In','workforce-one')),esc_html(date_i18n('h:i A',strtotime(isset($ev['sign_in'])?$ev['sign_in']->event_at:$ev['late_sign_in']->event_at)))); ?>
                <?php elseif($sign_in_open): ?>
                    <?php $signin_bounds=$this->sign_in_window_bounds($emp?$emp->id:0); ?>
                    <strong><?php esc_html_e('Sign In is available now.','workforce-one'); ?></strong> <?php printf(/* translators: 1: start time, 2: end time, 3: sign-in cutoff time */esc_html__('Allowed window: %1$s – %2$s, with Sign In cutoff at %3$s.','workforce-one'),esc_html($this->format_time_label($hours['start'])),esc_html($hours['end']),esc_html($signin_bounds['cutoff']?date_i18n('g:i A',$signin_bounds['cutoff']):$this->format_time_label($hours['end']))); ?>
                <?php else: ?>
                    <?php $signin_bounds=$this->sign_in_window_bounds($emp?$emp->id:0); $now_ts=current_time('timestamp'); ?>
                    <?php if($signin_bounds['start'] && $now_ts<$signin_bounds['start']): ?>
                        <strong><?php esc_html_e('Sign In is not available yet.','workforce-one'); ?></strong> <?php printf(/* translators: %s: start time */esc_html__('Your working hours start at %s.','workforce-one'),esc_html(date_i18n('g:i A',$signin_bounds['start']))); ?>
                    <?php else: ?>
                        <strong><?php esc_html_e('Sign In is no longer available.','workforce-one'); ?></strong> <?php printf(/* translators: %s: cutoff time */esc_html__('The Sign In cutoff was %s.','workforce-one'),esc_html($signin_bounds['cutoff']?date_i18n('g:i A',$signin_bounds['cutoff']):$this->format_time_label($hours['end']))); ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if($break_data && (isset($ev['sign_in'])||isset($ev['late_sign_in'])) && !isset($ev['sign_out'])): ?>
            <div class="ews-break-card <?php echo $break_data['open']?'is-open':''; ?>">
                <div class="ews-break-head">
                    <div><span class="ews-break-kicker"><?php esc_html_e('BREAK MANAGEMENT','workforce-one'); ?></span><h3><?php echo esc_html($break_data['open']?__('You are on break','workforce-one'):__('Take a break','workforce-one')); ?></h3>
                    <p><?php if($break_data['open']): ?><?php printf(/* translators: %s: minutes */esc_html__('Your break is being tracked. The standard break duration is %s.','workforce-one'),'<strong>'.esc_html(sprintf(__('%d min','workforce-one'),(int)$break_data['duration'])).'</strong>'); ?><?php else: ?><?php esc_html_e('Each break session is limited to the configured duration. Breaks are informative and do not affect attendance or overtime calculations.','workforce-one'); ?><?php endif; ?></p></div>
                    <div class="ews-break-count"><strong><?php echo (int)$break_data['remaining']; ?></strong><span><?php esc_html_e('remaining','workforce-one'); ?></span></div>
                </div>
                <?php if($break_data['open']): $open_start_ts=strtotime(get_gmt_from_date($break_data['open']->start_at)); ?>
                    <div class="ews-break-live">
                        <div><span class="ews-break-live-label"><?php esc_html_e('ON BREAK','workforce-one'); ?></span><strong id="ews-break-timer">00:00</strong><small><?php esc_html_e('Elapsed time','workforce-one'); ?></small></div>
                        <div class="ews-break-limit"><span><?php esc_html_e('Standard duration','workforce-one'); ?></span><strong><?php echo esc_html(sprintf(/* translators: %d: minutes */__('%d min','workforce-one'),(int)$break_data['duration'])); ?></strong><small><?php echo esc_html(sprintf(/* translators: %d: minutes */__('Manager alert at %d min','workforce-one'),(int)$break_data['escalation'])); ?></small></div>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-break-form">
                        <?php wp_nonce_field('ews_break_resume'); ?><input type="hidden" name="action" value="ews_break_resume">
                        <button class="ews-break-btn resume" type="submit">✓ <?php esc_html_e('Resume Work','workforce-one'); ?></button>
                    </form>
                    <script>
                    (function(){
                        var start=<?php echo (int)$open_start_ts; ?>*1000, el=document.getElementById('ews-break-timer');
                        function tick(){if(!el)return;var sec=Math.max(0,Math.floor((Date.now()-start)/1000)),m=Math.floor(sec/60),s=sec%60;el.textContent=(m<10?'0':'')+m+':'+(s<10?'0':'')+s;}
                        tick();setInterval(tick,1000);
                    })();
                    </script>
                <?php elseif($break_data['remaining']>0): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ews-break-form">
                        <?php wp_nonce_field('ews_break_start'); ?><input type="hidden" name="action" value="ews_break_start">
                        <button class="ews-break-btn start" type="submit">☕ <?php esc_html_e('Start Break','workforce-one'); ?> <span><?php echo esc_html(sprintf(__('%d min','workforce-one'),(int)$break_data['duration'])); ?></span></button>
                    </form>
                <?php else: ?>
                    <div class="ews-break-exhausted">✓ <?php echo esc_html(sprintf(/* translators: %d: number of break sessions */__('You have used all %d break sessions available today.','workforce-one'),(int)$break_data['allowed'])); ?></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="ews-time-history"><h3><?php esc_html_e('Today\'s Record','workforce-one'); ?></h3>
            <div class="ews-time-row"><span><?php esc_html_e('Sign In','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_in'])?date_i18n('h:i A',strtotime($ev['sign_in']->event_at)):__('Not recorded','workforce-one')); ?></strong></div>
            <div class="ews-time-row"><span><?php esc_html_e('Attendance Status','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_in'])?$this->sign_in_classification_label($this->sign_in_classification($ev['sign_in']->event_at)):__('Not recorded','workforce-one')); ?></strong></div>
            <div class="ews-time-row"><span><?php esc_html_e('Sign Out','workforce-one'); ?></span><strong><?php echo esc_html(isset($ev['sign_out'])?date_i18n('h:i A',strtotime($ev['sign_out']->event_at)):__('Not recorded','workforce-one')); ?></strong></div>
            <?php if($break_data && !empty($break_data['sessions'])): ?>
                <div class="ews-break-history-title"><?php esc_html_e('Break Sessions','workforce-one'); ?></div>
                <?php foreach($break_data['sessions'] as $bs): ?>
                    <div class="ews-time-row ews-break-history-row"><span><?php echo esc_html(sprintf(/* translators: %d: break number */__('Break %d','workforce-one'),(int)$bs->id)); ?> · <?php echo esc_html($bs->status); ?></span><strong><?php echo esc_html(date_i18n('h:i A',strtotime($bs->start_at))); ?><?php echo $bs->end_at?' – '.esc_html(date_i18n('h:i A',strtotime($bs->end_at))):' · '.esc_html__('Open','workforce-one'); ?><?php if($bs->end_at!==null): ?> · <?php echo esc_html(sprintf(__('%d min','workforce-one'),(int)$bs->actual_minutes)); ?><?php endif; ?></strong></div>
                <?php endforeach; ?>
            <?php endif; ?></div><?php endif; ?></div>
            <style>
            .ews-face-lab{margin:18px 0;padding:18px;border:1px solid #e6e8ec;border-radius:16px;background:#fff}
            .ews-face-lab-head{display:flex;align-items:center;justify-content:space-between;gap:12px}
            .ews-face-lab h3{margin:0;font-size:17px}.ews-face-lab p{margin:6px 0 0;color:#667085;font-size:13px}
            .ews-face-lab-btn{margin-top:14px;border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;background:#111827;color:#fff}
            .ews-face-modal{display:none;position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.68);align-items:center;justify-content:center;padding:18px}
            .ews-face-modal.is-open{display:flex}.ews-face-card{width:min(520px,100%);background:#fff;border-radius:18px;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
            .ews-face-top{display:flex;justify-content:space-between;align-items:center}.ews-face-close{border:0;background:#f2f4f7;border-radius:9px;width:34px;height:34px;font-size:20px;cursor:pointer}
            .ews-face-stage{margin-top:14px;position:relative;background:#0b1220;border-radius:14px;overflow:hidden;aspect-ratio:4/3}
            .ews-face-stage video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
            .ews-face-guide{position:absolute;inset:17% 25%;border:2px solid rgba(255,255,255,.85);border-radius:50%}
            .ews-face-status{margin-top:12px;padding:10px 12px;border-radius:10px;background:#f2f4f7;font-size:13px}
            .ews-face-actions{display:flex;gap:8px;margin-top:12px}.ews-face-actions button{flex:1;border:0;border-radius:10px;padding:11px;font-weight:700;cursor:pointer}
            .ews-face-enroll{background:#111827;color:#fff}.ews-face-verify{background:#e9d5ff;color:#581c87}.ews-face-clear{background:#f2f4f7;color:#344054}
            </style>
            <?php if($working && $this->presence_qr_enabled() && !isset($ev['sign_in']) && !isset($ev['late_sign_in']) && $sign_in_open): ?>
            <div class="ews-face-lab" id="wfo-qr-signin-card">
              <div class="ews-face-lab-head"><div><h3><?php esc_html_e('QR Sign In','workforce-one'); ?></h3><p><?php esc_html_e('Scan the dynamic QR displayed at your workplace. This is an additional Sign In method; your normal Sign In remains available.','workforce-one'); ?></p></div><span style="font-size:12px;font-weight:700;color:#667085"><?php esc_html_e('Rotating QR','workforce-one'); ?></span></div>
              <button type="button" class="ews-face-lab-btn" id="wfo-qr-open">▦ <?php esc_html_e('Scan Workplace QR','workforce-one'); ?></button>
              <div class="ews-face-modal" id="wfo-qr-modal" aria-hidden="true"><div class="ews-face-card"><div class="ews-face-top"><div><h3 style="margin:0"><?php esc_html_e('Scan Workplace QR','workforce-one'); ?></h3><p style="margin:4px 0 0;color:#667085;font-size:12px"><?php esc_html_e('Point your camera at the QR displayed at your workplace.','workforce-one'); ?></p></div><button type="button" class="ews-face-close" id="wfo-qr-close">×</button></div><div class="ews-face-stage"><video id="wfo-qr-video" autoplay muted playsinline></video></div><div class="ews-face-status" id="wfo-qr-status"><?php esc_html_e('Starting camera…','workforce-one'); ?></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="wfo-qr-form" class="ews-face-signin-form" data-face-event="sign_in"><?php wp_nonce_field('ews_presence_qr_signin'); ?><input type="hidden" name="action" value="ews_presence_qr_signin"><input type="hidden" name="qr_payload" id="wfo-qr-payload"><input type="hidden" name="face_verified" value="0"><input type="hidden" name="face_token" value=""><input type="hidden" name="latitude" class="ews-lat" id="wfo-qr-lat"><input type="hidden" name="longitude" class="ews-lng" id="wfo-qr-lng"><input type="hidden" name="accuracy" class="ews-accuracy" id="wfo-qr-acc"><input type="hidden" name="location_timestamp" class="ews-location-timestamp" id="wfo-qr-ts"><button type="submit" class="ews-time-btn sign-in" id="wfo-qr-submit" disabled><?php esc_html_e('Sign In with QR','workforce-one'); ?></button></form></div></div>
            </div>
            <script src="<?php echo esc_url(plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').'assets/vendor/jsQR-1.4.0.js'); ?>"></script>
            <script>(function(){var open=document.getElementById('wfo-qr-open'),modal=document.getElementById('wfo-qr-modal'),close=document.getElementById('wfo-qr-close'),video=document.getElementById('wfo-qr-video'),status=document.getElementById('wfo-qr-status'),payload=document.getElementById('wfo-qr-payload'),submit=document.getElementById('wfo-qr-submit'),stream=null,raf=0,canvas=document.createElement('canvas'),hasQr=false,hasLoc=false;function sync(){submit.disabled=!(hasQr&&hasLoc);if(hasQr&&!hasLoc)status.textContent=<?php echo wp_json_encode(__('QR detected. Waiting for your location…','workforce-one')); ?>;else if(hasQr&&hasLoc)status.textContent=<?php echo wp_json_encode(__('QR detected. Review and tap Sign In with QR.','workforce-one')); ?>;}function locate(){if(!navigator.geolocation){status.textContent=<?php echo wp_json_encode(__('Location Services are required for QR Sign In.','workforce-one')); ?>;return;}navigator.geolocation.getCurrentPosition(function(p){var c=p.coords;document.getElementById('wfo-qr-lat').value=c.latitude;document.getElementById('wfo-qr-lng').value=c.longitude;document.getElementById('wfo-qr-acc').value=c.accuracy||'';document.getElementById('wfo-qr-ts').value=p.timestamp||Date.now();hasLoc=true;sync();},function(){hasLoc=false;status.textContent=<?php echo wp_json_encode(__('Location permission is required for QR Sign In. Please allow Location Services and try again.','workforce-one')); ?>;sync();},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}function stop(){if(raf)cancelAnimationFrame(raf);raf=0;if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}if(modal){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');}}function scan(){if(!stream)return;if(video.readyState>=2){canvas.width=video.videoWidth||640;canvas.height=video.videoHeight||480;var ctx=canvas.getContext('2d',{willReadFrequently:true});ctx.drawImage(video,0,0,canvas.width,canvas.height);var d=ctx.getImageData(0,0,canvas.width,canvas.height);var code=typeof jsQR==='function'?jsQR(d.data,d.width,d.height,{inversionAttempts:'dontInvert'}):null;if(code&&code.data&&code.data.indexOf('wfo1|')===0){payload.value=code.data;hasQr=true;stop();modal.classList.add('is-open');sync();return;}}raf=requestAnimationFrame(scan);}function start(){if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||typeof jsQR!=='function'){status.textContent=<?php echo wp_json_encode(__('Camera QR scanning is unavailable on this browser/device.','workforce-one')); ?>;return;}modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');status.textContent=<?php echo wp_json_encode(__('Starting camera…','workforce-one')); ?>;hasQr=false;payload.value='';submit.disabled=true;locate();navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false}).then(function(s){stream=s;video.srcObject=s;status.textContent=<?php echo wp_json_encode(__('Point the camera at the workplace QR.','workforce-one')); ?>;scan();}).catch(function(){status.textContent=<?php echo wp_json_encode(__('Camera permission was denied or unavailable.','workforce-one')); ?>;});}if(open)open.addEventListener('click',start);if(close)close.addEventListener('click',stop);if(modal)modal.addEventListener('click',function(e){if(e.target===modal)stop();});})();</script>
            <?php endif; ?>
            <?php if($working && $this->presence_verification_enabled() && $emp): ?>
            <div class="ews-face-lab"><div class="ews-face-lab-head"><div><h3><?php esc_html_e('Presence Verification','workforce-one'); ?></h3><p><?php esc_html_e('If a manager requests a physical presence check, the request will appear here and in your notifications.','workforce-one'); ?></p></div><span style="font-size:12px;font-weight:700;color:#667085"><?php esc_html_e('Optional','workforce-one'); ?></span></div><a class="ews-face-lab-btn" style="display:inline-block;text-decoration:none" href="<?php echo esc_url($this->app_view_url('presence')); ?>">Open Presence Verification</a></div>
            <?php endif; ?>
            <div class="ews-face-module" id="ews-face-module" data-vendor-base="<?php echo esc_attr(plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').'assets/vendor/face-api/'); ?>" data-api-base="<?php echo esc_attr(rest_url('workforce-one/v1/')); ?>" data-wp-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>" data-server-enrolled="<?php echo $this->face_template_for_employee($emp?$emp->id:0)?'1':'0'; ?>" data-face-required="<?php echo $face_signin_enabled?'1':'0'; ?>" data-face-config="<?php echo esc_attr(wp_json_encode($this->face_signin_settings())); ?>">
            <div class="ews-face-lab">
              <div class="ews-face-lab-head">
                <div><h3><?php esc_html_e('Face Sign In','workforce-one'); ?></h3><p><?php echo esc_html($face_signin_enabled?__('Face verification is required before Sign In.','workforce-one'):__('Face verification is available but currently optional.','workforce-one')); ?> <?php esc_html_e('Face processing runs in this browser.','workforce-one'); ?></p></div>
                <span id="ews-face-state" style="font-size:12px;font-weight:700;color:#667085"><?php esc_html_e('Not enrolled','workforce-one'); ?></span>
              </div>
              <button type="button" class="ews-face-lab-btn" id="ews-face-open">📷 <?php echo esc_html($this->face_template_for_employee($emp?$emp->id:0)?__('Verify Face','workforce-one'):__('Set Up / Verify Face','workforce-one')); ?></button>
            </div>
            <div class="ews-face-modal" id="ews-face-modal" aria-hidden="true">
              <div class="ews-face-card" role="dialog" aria-modal="true">
                <div class="ews-face-top"><div><h3 style="margin:0"><?php esc_html_e('Face Verification','workforce-one'); ?></h3><p style="margin:4px 0 0;color:#667085;font-size:12px"><?php esc_html_e('Blink once and gently move your head. Face processing stays on this device.','workforce-one'); ?></p></div><button type="button" class="ews-face-close" id="ews-face-close">×</button></div>
                <div class="ews-face-stage"><video id="ews-face-video" autoplay muted playsinline></video><div class="ews-face-guide"></div></div>
                <div class="ews-face-status" id="ews-face-status"><?php esc_html_e('Loading face model…','workforce-one'); ?></div>
                <div class="ews-face-actions">
                  <button type="button" class="ews-face-enroll" id="ews-face-enroll" <?php echo $this->face_template_for_employee($emp?$emp->id:0)?'style="display:none"':''; ?>><?php esc_html_e('Enroll Face','workforce-one'); ?></button>
                  <button type="button" class="ews-face-verify" id="ews-face-verify"><?php esc_html_e('Verify Face','workforce-one'); ?></button>
                  <button type="button" class="ews-face-clear" id="ews-face-reset"><?php esc_html_e('Request Reset','workforce-one'); ?></button>
                </div>
              </div>
            </div>
            
            <?php if($working && $requires_location && !isset($ev['sign_in']) && !isset($ev['late_sign_in']) && !isset($ev['sign_out'])): ?>
            <script>
            (function(){
                function initWorkforceLocationGate(){
                    var gate=document.getElementById('ews-location-gate');
                    if(!gate)return;
                    var message=document.getElementById('ews-location-message');
                    var retry=document.getElementById('ews-location-retry');
                    var spinner=document.getElementById('ews-location-spinner');
                    var forms=document.querySelectorAll('.ews-time-actions form');
                    var ready=false;
                    function setForms(enabled){forms.forEach(function(form){var b=form.querySelector('button');if(b)b.disabled=!enabled;});}
                    function finish(pos){
                        var c=pos.coords;
                        document.querySelectorAll('input.ews-lat').forEach(function(i){i.value=c.latitude;});
                        document.querySelectorAll('input.ews-lng').forEach(function(i){i.value=c.longitude;});
                        document.querySelectorAll('input.ews-accuracy').forEach(function(i){i.value=c.accuracy||'';});
                        document.querySelectorAll('input.ews-location-timestamp').forEach(function(i){i.value=c.timestamp||Date.now();});
                        ready=true;setForms(true);gate.classList.add('is-ready');gate.setAttribute('aria-hidden','true');
                        setTimeout(function(){if(gate.parentNode)gate.parentNode.removeChild(gate);},220);
                    }
                    function fail(text){ready=false;setForms(false);spinner.style.display='none';retry.hidden=false;message.textContent=text;}
                    function requestLocation(){
                        retry.hidden=true;spinner.style.display='block';message.textContent=<?php echo wp_json_encode(__('Please allow location access when your browser asks. Your location is required to record Sign In / Sign Out.','workforce-one')); ?>;
                        if(!window.isSecureContext){fail(<?php echo wp_json_encode(__('Location access requires HTTPS. Please open Workforce One using a secure connection.','workforce-one')); ?>);return;}
                        if(!navigator.geolocation){fail(<?php echo wp_json_encode(__('Location Services are not supported by this browser. Please use a modern browser.','workforce-one')); ?>);return;}
                        navigator.geolocation.getCurrentPosition(finish,function(err){
                            if(err&&err.code===1)fail(<?php echo wp_json_encode(__('Location permission was denied. Please allow Location Services for this site, then try again.','workforce-one')); ?>);
                            else if(err&&err.code===2)fail(<?php echo wp_json_encode(__('Your location could not be determined. Turn on Location Services/GPS and try again.','workforce-one')); ?>);
                            else if(err&&err.code===3)fail(<?php echo wp_json_encode(__('Location request timed out. Turn on Location Services/GPS and try again.','workforce-one')); ?>);
                            else fail(<?php echo wp_json_encode(__('We need your location to continue. Please allow Location Services for this site.','workforce-one')); ?>);
                        },{enableHighAccuracy:true,maximumAge:0,timeout:15000});
                    }
                    forms.forEach(function(form){form.addEventListener('submit',function(e){if(!ready){e.preventDefault();requestLocation();}});});
                    retry.addEventListener('click',requestLocation);
                    setForms(false);
                    requestLocation();
                }
                if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initWorkforceLocationGate);else initWorkforceLocationGate();
            })();
            </script>
            <?php endif; ?>
            <?php return ob_get_clean();
        }
    private function attendance_content(){
            if(!$this->can('ews_manage_attendance'))return $this->ews_empty_state('Attendance unavailable','You do not have permission to manage attendance.');

            global $wpdb;
            $current_emp_id=(int)(($this->current_employee()->id??0));
            $emps=$this->schedule_employees_ordered(array_values(array_filter($this->department_scoped_employees(),function($e){return !isset($e->attendance_enabled) || (int)$e->attendance_enabled===1;})),$current_emp_id);
            [$dates,$sun]=$this->week_dates();
            $week_end=$dates[count($dates)-1]??$dates[0];
            $week=date('Y-m-d',$sun);
            $today=current_time('Y-m-d');
            $schedule_statuses=$this->schedule_type_names(true);
            $company_leave=$this->company_leave_dates($dates[0],$week_end);

            $records=[];
            if($emps){
                $ids=implode(',',array_map('intval',wp_list_pluck($emps,'id')));
                $rows=$wpdb->get_results("SELECT employee_id,work_date,status,note FROM {$this->schedule} WHERE employee_id IN ($ids) AND work_date BETWEEN '".esc_sql($dates[0])."' AND '".esc_sql($week_end)."'");
                foreach($rows as $r)$records[(int)$r->employee_id][$r->work_date]=$r;
            }

            $events=[];
            if($emps && !empty($this->time_logs)){
                $ids=implode(',',array_map('intval',wp_list_pluck($emps,'id')));
                $rows=$wpdb->get_results("SELECT employee_id,work_date,event_type,event_at FROM {$this->time_logs} WHERE employee_id IN ($ids) AND work_date BETWEEN '".esc_sql($dates[0])."' AND '".esc_sql($week_end)."' ORDER BY event_at ASC");
                foreach($rows as $r)$events[(int)$r->employee_id][$r->work_date][$r->event_type]=$r;
            }

            $actual_status=function($planned,$ev,$date,$employee_id)use($today,$company_leave){
                if(isset($company_leave[$date]))return ['Leave',$company_leave[$date]];
                $rule=$this->schedule_type_attendance_rule($planned);
                if($rule==='leave')return ['Leave',''];
                if($rule==='business_trip')return [$planned,''];
                if(!$this->schedule_type_requires_sign_in($planned))return ['Not Scheduled',''];
                if(!empty($ev['sign_in'])){
                    $classification=$this->sign_in_classification($ev['sign_in']->event_at,$employee_id);
                    return [$classification==='Late Arrival'?'Late':'Present',date_i18n('H:i',strtotime($ev['sign_in']->event_at))];
                }
                if(!empty($ev['late_sign_in']))return ['Late',date_i18n('H:i',strtotime($ev['late_sign_in']->event_at))];
                if($date<$today)return ['Absent',''];
                if($date===$today){
                    // Use the same employee-specific working-hours configuration
                    // as Sign In classification. Never hard-code a global 10:00 cutoff.
                    $hours=$this->working_hours($employee_id);
                    $now_ts=current_time('timestamp');
                    $cutoff_ts=strtotime($date.' '.$hours['normal_until'].':00');
                    if(!empty($hours['overnight']) && $hours['start']>$hours['end'] && $hours['normal_until']<$hours['start']){
                        $cutoff_ts=strtotime(date('Y-m-d',strtotime($date.' +1 day')).' '.$hours['normal_until'].':00');
                    }
                    if($cutoff_ts!==false && $now_ts>=$cutoff_ts)return ['Absent',''];
                }
                return ['Pending',''];
            };

            $summary=['Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0];
            $day_summary=[];
            foreach($dates as $d)$day_summary[$d]=['Present'=>0,'Late'=>0,'Absent'=>0,'Leave'=>0,'Pending'=>0,'Other'=>0];
            foreach($emps as $e){
                foreach($dates as $d){
                    $planned=$records[(int)$e->id][$d]->status??'';
                    [$actual]=$actual_status($planned,$events[(int)$e->id][$d]??[],$d,(int)$e->id);
                    if(isset($summary[$actual]))$summary[$actual]++;
                    if(isset($day_summary[$d][$actual]))$day_summary[$d][$actual]++; else $day_summary[$d]['Other']++;
                }
            }

            $team_options=[];
            foreach($emps as $e){
                foreach((array)($e->_schedule_team_names??[]) as $team_name){
                    $team_name=trim((string)$team_name);
                    if($team_name!=='')$team_options[$team_name]=$team_name;
                }
                $primary_team=trim((string)($e->_schedule_primary_team??''));
                if($primary_team!=='')$team_options[$primary_team]=$primary_team;
            }
            natcasesort($team_options);
            $total_days=count($emps)*count($dates);
            $tracked_days=$summary['Present']+$summary['Late']+$summary['Absent'];
            $attendance_rate=$tracked_days>0?round((($summary['Present']+$summary['Late'])/$tracked_days)*100):0;

            $msg='';
            if(isset($_GET['saved']))$msg='Attendance saved.';
            if(isset($_GET['imported']))$msg='Imported '.absint($_GET['imported']).' row(s); rejected '.absint($_GET['rejected']).'.';
            if(isset($_GET['grid_saved']))$msg='Saved '.absint($_GET['grid_saved']).' schedule record(s). Skipped empty cells: '.absint($_GET['grid_skipped']??0).'.';

            $prev=add_query_arg(['ews_view'=>'attendance','week'=>date('Y-m-d',strtotime('-7 days',$sun))]);
            $next=add_query_arg(['ews_view'=>'attendance','week'=>date('Y-m-d',strtotime('+7 days',$sun))]);

            ob_start();
            if($msg)echo '<div class="ews-notice">'.esc_html($msg).'</div>';
            ?>
            <div class="ews-attendance-page">
                <div class="ews-att-head">
                    <div>
                        <div class="ews-dash-kicker">MANAGER CONTROL CENTER</div>
                        <h1>Attendance</h1>
                        <p>Manage your team schedule and see attendance results in one place.</p>
                    </div>
                    <div class="ews-att-week-nav">
                        <a class="ews-week-arrow" href="<?php echo esc_url($prev); ?>" aria-label="Previous week">‹</a>
                        <div class="ews-att-week-control">
                            <form method="get" class="ews-att-week-form">
                                <input type="hidden" name="ews_view" value="attendance">
                                <label class="ews-att-week-range" title="Choose a date">
                                    <span>📅</span>
                                    <strong><?php echo esc_html(date('d',strtotime($dates[0]))); ?> – <?php echo esc_html(date('d M Y',strtotime($week_end))); ?></strong>
                                    <input type="date" name="week" value="<?php echo esc_attr($dates[0]); ?>" onchange="this.form.submit()" aria-label="Choose week">
                                </label>
                            </form>
                        </div>
                        <a class="ews-week-arrow" href="<?php echo esc_url($next); ?>" aria-label="Next week">›</a>
                    </div>
                </div>

                <div class="ews-att-summary">
                    <div class="ews-att-stat people"><span>👥</span><div><b><?php echo count($emps); ?></b><small>Employees</small></div></div>
                    <div class="ews-att-stat present"><span>✓</span><div><b><?php echo (int)$summary['Present']; ?></b><small>Present days</small></div></div>
                    <div class="ews-att-stat late"><span>◷</span><div><b><?php echo (int)$summary['Late']; ?></b><small>Late days</small></div></div>
                    <div class="ews-att-stat absent"><span>×</span><div><b><?php echo (int)$summary['Absent']; ?></b><small>Absent days</small></div></div>
                    <div class="ews-att-stat rate"><span>%</span><div><b><?php echo (int)$attendance_rate; ?>%</b><small>Attendance rate</small></div></div>
                </div>

                <div class="ews-att-controls">
                    <div class="ews-att-control-head">
                        <div class="ews-att-control-title"><strong>Schedule Controls</strong><span>Set the planned schedule for everyone on a specific day.</span></div>
                        <div class="ews-att-tools">
                            <label class="ews-att-search"><span>⌕</span><input type="search" id="ews-att-search" placeholder="Search employee..." autocomplete="off"></label>
                            <label class="ews-att-filter"><span>Team</span><select id="ews-att-team">
                            <option value="all">All teams</option>
                            <?php foreach($team_options as $team_name): ?>
                                <option value="<?php echo esc_attr($team_name); ?>"><?php echo esc_html($team_name); ?></option>
                            <?php endforeach; ?>
                            </select></label>
                        </div>
                    </div>
                    <div class="ews-day-quick-actions">
                        <?php foreach($dates as $i=>$d): ?>
                            <div class="ews-day-quick <?php echo $d===$today?'is-today':''; ?>">
                                <label><?php echo esc_html(date('D',strtotime($d))); ?><small><?php echo esc_html(date('d M',strtotime($d))); ?></small></label>
                                <select onchange="ewsFillDay(<?php echo (int)$i; ?>,this.value);this.value='';">
                                    <option value="">Set…</option>
                                    <?php foreach($schedule_statuses as $s): ?><option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($s); ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="ews-att-plan-legend"><span><i class="office"></i> Office</span><span><i class="wfh"></i> WFH</span><span><i class="leave"></i> Leave</span><span><i class="trip"></i> Business Trip</span></div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>" id="ews-grid-form">
                    <input type="hidden" name="action" value="ews31_att_grid_save">
                    <input type="hidden" name="week" value="<?php echo esc_attr($week); ?>">
                    <input type="hidden" name="attendance_client" value="desktop">
                    <input type="hidden" name="att_changes_json" id="ews-att-changes-json" value="">
                    <?php wp_nonce_field('ews31_att_grid_save'); ?>

                    <div class="ews-att-table-wrap">
                        <table class="ews-att-table">
                            <thead><tr>
                                <th class="employee-col">Employee</th>
                                <?php foreach($dates as $d): ?>
                                    <th class="<?php echo $d===$today?'is-today':''; ?>">
                                        <div class="ews-att-day-name"><?php echo esc_html(date('D',strtotime($d))); ?></div>
                                        <small><?php echo esc_html(date('d M',strtotime($d))); ?></small>
                                        <?php if($d===$today): ?><em>Today</em><?php endif; ?>
                                        <div class="ews-att-day-meta"><b><?php echo (int)($day_summary[$d]['Present']+$day_summary[$d]['Late']); ?></b> recorded · <b><?php echo (int)$day_summary[$d]['Absent']; ?></b> absent</div>
                                    </th>
                                <?php endforeach; ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach($emps as $e): ?>
                                <tr class="ews-att-employee-row <?php echo trim(($current_emp_id===(int)$e->id?'current-user ':'').(!empty($e->_schedule_team_manager)?'team-manager':'')); ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name.' '.$e->domain_name)); ?>" data-team="<?php echo esc_attr(strtolower(implode('|',(array)($e->_schedule_team_names??[])))); ?>">
                                    <td class="ews-att-employee"><div class="ews-att-avatar"><?php echo esc_html(strtoupper(substr(trim($e->name),0,1))); ?></div><div><strong><?php echo esc_html($e->name); ?><?php if($current_emp_id===(int)$e->id): ?><span class="ews-me-badge">You</span><?php endif; ?><?php if(!empty($e->_schedule_team_manager)): ?><span class="ews-manager-badge">Manager</span><?php endif; ?></strong><small><?php echo esc_html($e->domain_name); ?></small><?php if(!empty($e->_schedule_primary_team)): ?><span class="ews-att-team-label"><?php echo esc_html($e->_schedule_primary_team); ?></span><?php endif; ?></div></td>
                                    <?php foreach($dates as $i=>$d):
                                        $planned=$records[(int)$e->id][$d]->status??'';
                                        [$actual,$tm]=$actual_status($planned,$events[(int)$e->id][$d]??[],$d,(int)$e->id);
                                    ?>
                                    <td class="ews-att-day <?php echo $d===$today?'is-today ':''; ?>ews-plan-<?php echo esc_attr(sanitize_title($planned?:'not-set')); ?>" data-status="<?php echo esc_attr($actual); ?>" data-planned="<?php echo esc_attr($planned); ?>">
                                        <select name="att[<?php echo (int)$e->id; ?>][<?php echo (int)$i; ?>]" class="ews-att-cell ews-schedule-select" data-employee="<?php echo (int)$e->id; ?>" data-day="<?php echo (int)$i; ?>">
                                            <option value="">Not Set</option>
                                            <?php foreach($schedule_statuses as $s): ?><option value="<?php echo esc_attr($s); ?>" <?php selected($planned,$s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?>
                                        </select>
                                        <?php if($actual==='Present'): ?><div class="ews-att-result present">✓ Present<?php if($tm): ?><small><?php echo esc_html($tm); ?></small><?php endif; ?></div>
                                        <?php elseif($actual==='Late'): ?><div class="ews-att-result late">◷ Late<?php if($tm): ?><small><?php echo esc_html($tm); ?></small><?php endif; ?></div>
                                        <?php elseif($actual==='Absent'): ?><div class="ews-att-result absent">× Absent</div>
                                        <?php elseif($actual==='Leave'): ?><div class="ews-att-result leave">▣ Leave</div>
                                        <?php elseif($actual==='Business Trip'): ?><div class="ews-att-result trip">✈ Business Trip</div>
                                        <?php elseif($actual==='Pending'): ?><div class="ews-att-result pending">Awaiting sign in</div>
                                        <?php else: ?><div class="ews-att-result muted">— Not scheduled</div><?php endif; ?>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="ews-att-mobile">
                        <?php foreach($emps as $e): ?>
                            <details class="ews-att-mobile-card <?php echo !empty($e->_schedule_team_manager)?'team-manager':''; ?> <?php echo $current_emp_id===(int)$e->id?'current-user':''; ?>" data-employee-name="<?php echo esc_attr(strtolower($e->name.' '.$e->domain_name)); ?>" data-team="<?php echo esc_attr(strtolower(implode('|',(array)($e->_schedule_team_names??[])))); ?>">
                                <summary><div class="ews-att-avatar"><?php echo esc_html(strtoupper(substr(trim($e->name),0,1))); ?></div><div><strong><?php echo esc_html($e->name); ?><?php if($current_emp_id===(int)$e->id): ?><span class="ews-me-badge">You</span><?php endif; ?><?php if(!empty($e->_schedule_team_manager)): ?><span class="ews-manager-badge">Manager</span><?php endif; ?></strong><small><?php echo esc_html($e->domain_name); ?><?php if(!empty($e->_schedule_primary_team)): ?> · <?php echo esc_html($e->_schedule_primary_team); ?><?php endif; ?></small></div><span>›</span></summary>
                                <div class="ews-att-mobile-days">
                                <?php foreach($dates as $i=>$d):
                                    $planned=$records[(int)$e->id][$d]->status??'';
                                    [$actual,$tm]=$actual_status($planned,$events[(int)$e->id][$d]??[],$d,(int)$e->id);
                                ?>
                                    <div class="ews-att-mobile-day <?php echo $d===$today?'is-today':''; ?>">
                                        <header><b><?php echo esc_html(date('D',strtotime($d))); ?></b><small><?php echo esc_html(date('d M',strtotime($d))); ?></small></header>
                                        <select name="mobile_att[<?php echo (int)$e->id; ?>][<?php echo (int)$i; ?>]" class="ews-mobile-att-cell ews-schedule-select" data-employee="<?php echo (int)$e->id; ?>" data-day="<?php echo (int)$i; ?>">
                                            <option value="">Not Set</option>
                                            <?php foreach($schedule_statuses as $s): ?><option value="<?php echo esc_attr($s); ?>" <?php selected($planned,$s); ?>><?php echo esc_html($s); ?></option><?php endforeach; ?>
                                        </select>
                                        <div class="ews-att-result <?php echo esc_attr(strtolower($actual==='Business Trip'?'trip':$actual)); ?>"><?php echo esc_html($actual); ?><?php if($tm): ?> · <?php echo esc_html($tm); ?><?php endif; ?></div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>

                    <div class="ews-att-savebar"><span>Empty cells are not changed.</span><button type="submit" name="attendance_save" value="1" class="ews-btn ews-save-all">✓ Save Schedule & Attendance</button></div>
                </form>

                <?php
                $preview_token=isset($_GET['preview'])?sanitize_text_field(wp_unslash($_GET['preview'])):'';
                $preview_rows=$preview_token?get_transient('ews31_preview_'.$preview_token.'_'.get_current_user_id()):false;
                if($preview_token && !is_array($preview_rows)): ?>
                <div class="ews-notice ews-notice-error">The CSV preview has expired. Please upload the file again.</div>
                <?php elseif(is_array($preview_rows)):
                    $preview_valid=count(array_filter($preview_rows,function($r){return !empty($r['valid']);}));
                    $preview_invalid=count($preview_rows)-$preview_valid; ?>
                <div class="ews-card ews-csv-preview">
                    <h3 style="margin-top:0">CSV Preview</h3>
                    <p><strong><?php echo (int)$preview_valid; ?></strong> row(s) ready to import<?php if($preview_invalid): ?>, <strong style="color:#b42318"><?php echo (int)$preview_invalid; ?></strong> row(s) will be skipped<?php endif; ?>. Existing entries for the same employee and date will be replaced.</p>
                    <div style="overflow:auto;max-height:420px">
                    <table class="ews-table ews-preview"><thead><tr><th>Line</th><th>Employee</th><th>Date</th><th>Status</th><th>Note</th><th>Result</th></tr></thead><tbody>
                    <?php foreach($preview_rows as $r): ?>
                        <tr class="<?php echo empty($r['valid'])?'ews-invalid':'ews-valid'; ?>">
                            <td><?php echo (int)$r['line']; ?></td>
                            <td><?php echo esc_html($r['employee']?:$r['domain']); ?></td>
                            <td><?php echo esc_html($r['normalized_date']?:$r['date']); ?></td>
                            <td><?php echo esc_html($r['status']); ?></td>
                            <td><?php echo esc_html($r['note']); ?></td>
                            <td><?php echo empty($r['valid'])?'<span class="ews-error">✕ '.esc_html($r['error']).'</span>':'<span style="color:#067647">✓ OK</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
                        <?php if($preview_valid): ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="ews31_att_import"><?php wp_nonce_field('ews31_att_import'); ?>
                            <input type="hidden" name="token" value="<?php echo esc_attr($preview_token); ?>">
                            <button class="ews-btn">✓ Import <?php echo (int)$preview_valid; ?> row(s)</button>
                        </form>
                        <?php endif; ?>
                        <a class="ews-btn secondary" href="<?php echo esc_url(remove_query_arg('preview')); ?>">Cancel</a>
                    </div>
                </div>
                <?php endif; ?>

                <details class="ews-card ews-csv-details">
                    <summary><strong>Advanced: Bulk CSV Import</strong><span>Use this when you have many rows from Excel.</span></summary>
                    <div class="ews-csv-inside">
                        <p>Download the sample, fill it in Excel, upload it, review validation, then import.</p>
                        <div class="ews-drop">📄 <b>Upload CSV</b>
                            <form method="post" action="<?php echo esc_url(admin_url("admin-post.php")); ?>" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="ews31_att_preview"><?php wp_nonce_field('ews31_att_preview'); ?>
                                <input type="file" name="attendance_csv" accept=".csv,text/csv" required>
                                <button class="ews-btn">Validate & Preview</button>
                            </form>
                        </div>
                        <p><a class="ews-btn secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ews31_att_sample'),'ews31_att_sample'));?>">Download Sample CSV</a></p>
                    </div>
                </details>

                <script>
                function ewsFillDay(dayIndex,status){
                    if(!status)return;
                    document.querySelectorAll('#ews-grid-form .ews-att-cell[data-day="'+dayIndex+'"]').forEach(function(el){el.value=status;});
                    document.querySelectorAll('#ews-grid-form .ews-mobile-att-cell[data-day="'+dayIndex+'"]').forEach(function(el){el.value=status;});
                }
                document.addEventListener('DOMContentLoaded',function(){
                    var form=document.getElementById('ews-grid-form');
                    if(!form)return;

                    var search=document.getElementById('ews-att-search');
                    var teamFilter=document.getElementById('ews-att-team');
                    function filterAttendance(){
                        var q=(search?search.value:'').trim().toLowerCase();
                        var team=teamFilter?teamFilter.value.trim().toLowerCase():'all';
                        document.querySelectorAll('.ews-att-employee-row').forEach(function(row){
                            var name=row.getAttribute('data-employee-name')||'';
                            var teams=row.getAttribute('data-team')||'';
                            var matchesName=!q||name.indexOf(q)!==-1;
                            var matchesTeam=team==='all'||teams.split('|').indexOf(team)!==-1;
                            row.style.display=(matchesName&&matchesTeam)?'':'none';
                        });
                        document.querySelectorAll('.ews-att-mobile-card').forEach(function(card){
                            var name=card.getAttribute('data-employee-name')||'';
                            var teams=card.getAttribute('data-team')||'';
                            var matchesName=!q||name.indexOf(q)!==-1;
                            var matchesTeam=team==='all'||teams.split('|').indexOf(team)!==-1;
                            card.style.display=(matchesName&&matchesTeam)?'':'none';
                        });
                    }
                    if(search)search.addEventListener('input',filterAttendance);
                    if(teamFilter)teamFilter.addEventListener('change',filterAttendance);

                    function planClass(value){return 'ews-plan-'+(value||'not-set').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'');}
                    form.querySelectorAll('.ews-att-cell').forEach(function(select){
                        select.addEventListener('change',function(){
                            var cell=this.closest('.ews-att-day');
                            if(!cell)return;
                            var planned=this.value||'Not Set';
                            Array.prototype.slice.call(cell.classList).forEach(function(c){if(c.indexOf('ews-plan-')===0)cell.classList.remove(c);});
                            cell.classList.add(planClass(planned));
                            cell.setAttribute('data-planned',planned);
                        });
                    });

                    /*
                     * Concurrency-safe grid submit:
                     * submit only cells whose value differs from the value that
                     * was loaded in this browser. This prevents a stale copy of
                     * the whole table from overwriting another manager's work.
                     */
                    form.querySelectorAll('.ews-att-cell,.ews-mobile-att-cell').forEach(function(select){
                        select.dataset.ewsOriginal=select.value||'';
                    });

                    form.addEventListener('submit',function(){
                        var mobile=window.matchMedia && window.matchMedia('(max-width: 700px)').matches;
                        var marker=form.querySelector('input[name="attendance_client"]');
                        if(marker)marker.value=mobile?'mobile':'desktop';

                        if(mobile){
                            form.querySelectorAll('.ews-mobile-att-cell').forEach(function(mob){
                                var eid=mob.getAttribute('data-employee');
                                var day=mob.getAttribute('data-day');
                                var desk=form.querySelector('.ews-att-cell[data-employee="'+eid+'"][data-day="'+day+'"]');
                                if(desk)desk.value=mob.value;
                            });
                        }

                        var changes=[];
                        form.querySelectorAll('.ews-att-cell').forEach(function(select){
                            var current=select.value||'';
                            var original=select.dataset.ewsOriginal||'';
                            if(current===original)return;

                            changes.push({
                                employee:parseInt(select.getAttribute('data-employee')||'0',10),
                                day:parseInt(select.getAttribute('data-day')||'-1',10),
                                status:current,
                                original:original===''?null:original
                            });
                        });

                        var payload=form.querySelector('#ews-att-changes-json');
                        if(payload)payload.value=JSON.stringify(changes);

                        /*
                         * The server now consumes att_changes_json. Disable the
                         * old full-grid controls so they do not add a stale
                         * snapshot to the POST body. If JS is unavailable, the
                         * legacy fallback remains available.
                         */
                        if(payload){
                            form.querySelectorAll('.ews-att-cell,.ews-mobile-att-cell').forEach(function(select){
                                select.disabled=true;
                            });
                        }
                    });
                });
                </script>
            </div>
            <?php
            return ob_get_clean();
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
                'saved','imported','rejected','grid_saved','grid_skipped','grid_invalid',
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
