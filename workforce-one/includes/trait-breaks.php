<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\BreakRules;

/**
 * Breaks: settings, today's sessions, start / resume from the Sign In page, and the reminder and
 * manager escalation cron events.
 */
trait EWS_Breaks_Trait {


        private function break_enabled(){
            return (bool)$this->option('ews_feature_breaks');
        }

        private function break_per_day(){
            $n=(int)$this->option('ews_breaks_per_day');
            return max(1,min(20,$n));
        }

        private function break_duration_minutes(){
            $n=(int)$this->option('ews_break_duration_minutes');
            return max(1,min(480,$n));
        }

        private function break_escalation_minutes(){
            $n=(int)$this->option('ews_break_manager_alert_minutes');
            return max($this->break_duration_minutes()+1,min(1440,$n));
        }

        private function break_sessions_today($employee_id){
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND work_date=%s ORDER BY id ASC",(int)$employee_id,$this->attendance_day($employee_id)));
        }

        private function break_open_session($employee_id){
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE employee_id=%d AND work_date=%s AND status='Open' ORDER BY id DESC LIMIT 1",(int)$employee_id,$this->attendance_day($employee_id)));
        }

        private function break_remaining($employee_id){
            return max(0,$this->break_per_day()-count($this->break_sessions_today($employee_id)));
        }

        private function break_redirect($args=[]){
            $url=wp_get_referer(); if(!$url||strpos($url,'admin-post.php')!==false)$url=$this->app_home_url();
            $url=remove_query_arg(['break_success','break_error','leave_sent','leave_error','early_error','time_success','time_error','overtime_sent','overtime_error','saved','imported','grid_saved','time_reset','time_saved'],$url);
            $url=add_query_arg('ews_view','time',$url);
            if($args)$url=add_query_arg($args,$url);
            wp_safe_redirect($url); exit;
        }

        private function break_manager_user_ids(){
            $ids=[];
            $users=get_users(['role__in'=>['ews_manager','ews_supervisor','ews_administrator'],'fields'=>['ID']]);
            foreach($users as $u)$ids[]=(int)$u->ID;
            // Native administrators can manage Workforce One too.
            $admins=get_users(['role__in'=>['administrator'],'fields'=>['ID']]);
            foreach($admins as $u)$ids[]=(int)$u->ID;
            return array_values(array_unique(array_filter($ids)));
        }

        /*
         * Start / Resume from the Sign In page: adapters over AttendanceService (startBreak(),
         * resumeBreak(); rules in src/Attendance/BreakRules.php). Same checks, order and messages
         * as before 3.31.46; a concurrent second request can no longer open a second break.
         */
        public function break_start(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_start');
            $r=$this->attendance_service()->startBreak();
            if(!$r->ok)$this->break_redirect(['break_error'=>rawurlencode($this->break_error_message($r->code))]);
            $this->break_redirect(['break_success'=>rawurlencode('Break started successfully.')]);
        }

        public function break_resume(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_resume');
            $r=$this->attendance_service()->resumeBreak();
            if(!$r->ok)$this->break_redirect(['break_error'=>rawurlencode($this->break_error_message($r->code))]);
            $this->break_redirect(['break_success'=>rawurlencode($this->break_ended_message((int)$r->details['minutes']))]);
        }

        /** Text of the "Break Ended" notification and of the Resume message. */
        private function break_ended_message($minutes){
            $minutes=(int)$minutes;
            return 'Your break ended. Duration: '.$minutes.' minute'.($minutes===1?'':'s').'.';
        }

        /** The Web message for a BreakRules code (the texts of 3.31.45). */
        private function break_error_message($code){
            $m=[
                BreakRules::BREAKS_DISABLED=>'Break Management is currently disabled.',
                BreakRules::ATTENDANCE_DISABLED=>'Attendance tracking is disabled for this employee.',
                BreakRules::NO_EMPLOYEE=>'Employee account is not linked.',
                BreakRules::NOT_WORKING_DAY=>'Breaks are available only on a working day.',
                BreakRules::NOT_SIGNED_IN=>'You must Sign In before starting a break.',
                BreakRules::ALREADY_SIGNED_OUT=>'You have already signed out today.',
                BreakRules::ALREADY_ON_BREAK=>'You are already on a break.',
                BreakRules::NO_BREAKS_LEFT=>'No break sessions remain today.',
                BreakRules::NO_OPEN_BREAK=>'No open break was found.',
                BreakRules::SAVE_FAILED=>'Unable to start your break. Please try again.',
            ];
            return $m[$code]??'Unable to record this action.';
        }

        public function break_duration_reminder($session_id){
            if(!$this->break_enabled())return;
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",(int)$session_id));
            if(!$s||$s->status!=='Open'||(int)$s->duration_notified===1)return;
            $wpdb->update($table,['duration_notified'=>1],['id'=>(int)$s->id],['%d'],['%d']);
            $msg='Your '.$this->break_duration_minutes().'-minute break has ended. Please resume work.';
            $this->notify((int)$s->user_id,'break','Break Time Ended',$msg,['entity_id'=>(int)$s->id,'url'=>add_query_arg('ews_view','time',$this->app_home_url())]);
        }

        public function break_manager_escalation($session_id){
            if(!$this->break_enabled())return;
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",(int)$session_id));
            if(!$s||$s->status!=='Open'||(int)$s->escalation_notified===1)return;
            if(!$this->employee_attendance_enabled((int)$s->employee_id))return;
            $wpdb->update($table,['escalation_notified'=>1],['id'=>(int)$s->id],['%d'],['%d']);
            $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d LIMIT 1",(int)$s->employee_id));
            if(!$emp)return;
            $elapsed=max(0,(int)floor((current_time('timestamp',true)-strtotime(get_gmt_from_date($s->start_at)))/60));
            $msg=$emp->name.' has been on break for '.$elapsed.' minutes and has not resumed work.';
            foreach($this->break_manager_user_ids() as $uid){
                $this->notify($uid,'break','Extended Break Alert',$msg,['entity_id'=>(int)$s->id,'url'=>add_query_arg('ews_view','time',$this->app_home_url())]);
            }
        }

        private function break_ui_data($employee_id){
            $sessions=$this->break_sessions_today($employee_id);
            $open=null; foreach($sessions as $s){if($s->status==='Open'){$open=$s;break;}}
            return ['sessions'=>$sessions,'open'=>$open,'remaining'=>max(0,$this->break_per_day()-count($sessions)),
                'allowed'=>$this->break_per_day(),'duration'=>$this->break_duration_minutes(),'escalation'=>$this->break_escalation_minutes()];
        }
}
