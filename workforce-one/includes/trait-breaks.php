<?php
if (!defined('ABSPATH')) exit;

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

        public function break_start(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_start');
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id))$this->break_redirect(['break_error'=>rawurlencode('Attendance tracking is disabled for this employee.')]);
            if(!$emp)$this->break_redirect(['break_error'=>rawurlencode('Employee account is not linked.')]);
            $sch=$this->today_schedule_for_employee($emp->id); $ev=$this->today_events($emp->id);
            if(!$sch || !$this->schedule_type_requires_sign_in($sch->status))$this->break_redirect(['break_error'=>rawurlencode('Breaks are available only on a working day.')]);
            if(!isset($ev['sign_in'])&&!isset($ev['late_sign_in']))$this->break_redirect(['break_error'=>rawurlencode('You must Sign In before starting a break.')]);
            if(isset($ev['sign_out']))$this->break_redirect(['break_error'=>rawurlencode('You have already signed out today.')]);
            if($this->break_open_session($emp->id))$this->break_redirect(['break_error'=>rawurlencode('You are already on a break.')]);
            if($this->break_remaining($emp->id)<=0)$this->break_redirect(['break_error'=>rawurlencode('No break sessions remain today.')]);
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions'; $now=current_time('mysql');
            $ok=$wpdb->insert($table,[
                'employee_id'=>(int)$emp->id,'user_id'=>(int)$emp->wp_user_id,'work_date'=>$this->attendance_day($emp->id),
                'start_at'=>$now,'status'=>'Open','created_at'=>$now
            ],['%d','%d','%s','%s','%s','%s']);
            if(!$ok)$this->break_redirect(['break_error'=>rawurlencode('Unable to start your break. Please try again.')]);
            $id=(int)$wpdb->insert_id;
            $now_utc=current_time('timestamp',true);
            wp_schedule_single_event($now_utc+($this->break_duration_minutes()*60),'ews_break_duration_reminder',[$id]);
            wp_schedule_single_event($now_utc+($this->break_escalation_minutes()*60),'ews_break_manager_escalation',[$id]);
            $this->audit('break_start','break',$id,$emp->name.' / '.$now);
            $this->break_redirect(['break_success'=>rawurlencode('Break started successfully.')]);
        }

        public function break_resume(){
            if(!$this->break_enabled())$this->break_redirect(['break_error'=>rawurlencode('Break Management is currently disabled.')]);
            if(!is_user_logged_in())wp_die('You must be logged in.');
            check_admin_referer('ews_break_resume');
            $emp=$this->current_employee();
            if($emp && !$this->employee_attendance_enabled((int)$emp->id))$this->break_redirect(['break_error'=>rawurlencode('Attendance tracking is disabled for this employee.')]);
            if(!$emp)$this->break_redirect(['break_error'=>rawurlencode('Employee account is not linked.')]);
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $session=$this->break_open_session($emp->id);
            if(!$session)$this->break_redirect(['break_error'=>rawurlencode('No open break was found.')]);
            $now=current_time('mysql');
            $start=strtotime($session->start_at); $end=strtotime($now);
            $minutes=max(0,(int)floor(($end-$start)/60));
            $wpdb->update($table,['end_at'=>$now,'actual_minutes'=>$minutes,'status'=>'Completed'],['id'=>(int)$session->id],['%s','%d','%s'],['%d']);
            $msg='Your break ended. Duration: '.$minutes.' minute'.($minutes===1?'':'s').'.';
            $this->notify_user((int)$emp->wp_user_id,'Break Ended',$msg,'break','break',(int)$session->id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Break Ended',$msg,'break',(int)$session->id,add_query_arg('ews_view','time',$this->app_home_url()));
            $this->audit('break_resume','break',(int)$session->id,$emp->name.' / '.$now.' / '.$minutes.'m');
            $this->break_redirect(['break_success'=>rawurlencode($msg)]);
        }

        public function break_duration_reminder($session_id){
            if(!$this->break_enabled())return;
            global $wpdb; $table=$wpdb->prefix.'ews_break_sessions';
            $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d LIMIT 1",(int)$session_id));
            if(!$s||$s->status!=='Open'||(int)$s->duration_notified===1)return;
            $wpdb->update($table,['duration_notified'=>1],['id'=>(int)$s->id],['%d'],['%d']);
            $msg='Your '.$this->break_duration_minutes().'-minute break has ended. Please resume work.';
            $this->notify_user((int)$s->user_id,'Break Time Ended',$msg,'break','break',(int)$s->id);
            if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$s->user_id,'Break Time Ended',$msg,'break',(int)$s->id,add_query_arg('ews_view','time',$this->app_home_url()));
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
                $this->notify_user($uid,'Extended Break Alert',$msg,'break','break',(int)$s->id);
                if(method_exists($this,'push_custom_notification'))$this->push_custom_notification($uid,'Extended Break Alert',$msg,'break',(int)$s->id,add_query_arg('ews_view','time',$this->app_home_url()));
            }
        }

        private function break_ui_data($employee_id){
            $sessions=$this->break_sessions_today($employee_id);
            $open=null; foreach($sessions as $s){if($s->status==='Open'){$open=$s;break;}}
            return ['sessions'=>$sessions,'open'=>$open,'remaining'=>max(0,$this->break_per_day()-count($sessions)),
                'allowed'=>$this->break_per_day(),'duration'=>$this->break_duration_minutes(),'escalation'=>$this->break_escalation_minutes()];
        }
}
