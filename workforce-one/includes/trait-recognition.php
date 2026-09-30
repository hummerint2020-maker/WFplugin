<?php
if (!defined('ABSPATH')) exit;

trait EWS_Recognition_Trait {
    private function recognition_enabled(){ return (bool)get_option('ews_feature_recognition',true); }
    private function recognition_allow_kudos(){ return (bool)get_option('ews_recognition_allow_kudos',true); }
    private function recognition_weekly_limit(){
        $mode=get_option('ews_recognition_weekly_limit_mode','limited');
        if($mode==='unlimited')return 0;
        return max(1,min(1000,(int)get_option('ews_recognition_weekly_limit',5)));
    }

    private static $ews_recognition_schema_ready = false;

    private function ensure_recognition_schema(){
        if($this->ews_schema_is_current())return;
        if(self::$ews_recognition_schema_ready)return;
        global $wpdb;
        $table=$wpdb->prefix.'ews_kudos';
        $charset=$wpdb->get_charset_collate();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            sender_employee_id BIGINT UNSIGNED NOT NULL,
            recipient_employee_id BIGINT UNSIGNED NOT NULL,
            category VARCHAR(60) NOT NULL,
            message TEXT NULL,
            context_type VARCHAR(40) NULL,
            context_id BIGINT UNSIGNED NULL,
            visibility VARCHAR(20) NOT NULL DEFAULT 'profile',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY recipient_date(recipient_employee_id,created_at),
            KEY sender_date(sender_employee_id,created_at),
            KEY status_date(status,created_at),
            KEY context(context_type,context_id)
        ) {$charset};");
        self::$ews_recognition_schema_ready=true;
    }

    private function recognition_categories(){
        return [
            'teamwork'=>'Teamwork',
            'help_support'=>'Help & Support',
            'great_idea'=>'Great Idea',
            'great_job'=>'Great Job',
            'extra_mile'=>'Going the Extra Mile',
            'appreciation'=>'Appreciation',
        ];
    }

    private function recognition_category_label($key){
        $cats=$this->recognition_categories();
        return isset($cats[$key])?$cats[$key]:ucwords(str_replace('_',' ',sanitize_key($key)));
    }

    private function recognition_redirect($employee_id,$args=[]){
        $fallback=add_query_arg('ews_view','people',home_url('/'));
        $referer=wp_get_referer();
        $base=$referer?wp_validate_redirect($referer,$fallback):$fallback;
        $base=remove_query_arg(['kudos_error','kudos_sent'],$base);
        if(isset($args['kudos_error'])){
            $notice_key='wfo_kudos_notice_'.get_current_user_id().'_'.absint($employee_id);
            set_transient($notice_key,'error',60);
            unset($args['kudos_error']);
        }
        $url=add_query_arg(array_merge(['ews_view'=>'employee','employee_id'=>absint($employee_id)],$args),$base);
        wp_safe_redirect($url);exit;
    }

    public function recognition_submit(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        if(!$this->can('ews_view_people'))wp_die('Access denied.');
        if(!$this->recognition_enabled() || !$this->recognition_allow_kudos())wp_die('Recognition is currently disabled.');
        check_admin_referer('ews_kudos_submit','ews_kudos_nonce');
        global $wpdb;
        $this->ensure_recognition_schema();
        $sender=$this->current_employee();
        $recipient_id=absint($_POST['recipient_employee_id']??0);
        $category=sanitize_key($_POST['category']??'');
        $message=trim(sanitize_textarea_field(wp_unslash($_POST['message']??'')));
        $cats=$this->recognition_categories();
        if(!$sender||!$recipient_id||$recipient_id===(int)$sender->id)$this->recognition_redirect($recipient_id,['kudos_error'=>'invalid']);
        if(!isset($cats[$category]))$this->recognition_redirect($recipient_id,['kudos_error'=>'category']);
        if(mb_strlen($message)>500)$message=mb_substr($message,0,500);
        $recipient=$wpdb->get_row($wpdb->prepare("SELECT id,name,active,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$recipient_id));
        if(!$recipient)$this->recognition_redirect($recipient_id,['kudos_error'=>'employee']);
        $today=current_time('Y-m-d H:i:s');
        $duplicate=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_kudos WHERE sender_employee_id=%d AND recipient_employee_id=%d AND category=%s AND message=%s AND status='active' AND created_at >= %s LIMIT 1",(int)$sender->id,$recipient_id,$category,$message,date('Y-m-d H:i:s',strtotime('-1 day',current_time('timestamp')))));
        if($duplicate)$this->recognition_redirect($recipient_id,['kudos_error'=>'duplicate']);
        $weekly_limit=$this->recognition_weekly_limit();
        if($weekly_limit>0){
            $week_start=strtotime('monday this week',current_time('timestamp'));
            $week_start=date('Y-m-d 00:00:00',$week_start);
            $count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}ews_kudos WHERE sender_employee_id=%d AND status='active' AND created_at >= %s",(int)$sender->id,$week_start));
            if((int)$count >= $weekly_limit)$this->recognition_redirect($recipient_id,['kudos_error'=>'limit']);
        }
        $ok=$wpdb->insert($wpdb->prefix.'ews_kudos',[
            'sender_employee_id'=>(int)$sender->id,
            'recipient_employee_id'=>$recipient_id,
            'category'=>$category,
            'message'=>$message,
            'context_type'=>null,
            'context_id'=>null,
            'visibility'=>'profile',
            'status'=>'active',
            'created_at'=>$today,
        ],['%d','%d','%s','%s','%s','%d','%s','%s','%s']);
        if($ok===false){$this->audit('kudos_create_failed','kudos',0,$wpdb->last_error);$this->recognition_redirect($recipient_id,['kudos_error'=>'save']);}
        $id=(int)$wpdb->insert_id;
        $sender_name=(string)$sender->name;
        $label=$this->recognition_category_label($category);
        $title='You received a Kudos';
        $msg=$sender_name.' recognized you for '.$label.'.';
        if($message!=='')$msg.=' “'.wp_strip_all_tags($message).'”';
        if(!empty($recipient->wp_user_id)){
            $this->notify_user((int)$recipient->wp_user_id,$title,$msg,'recognition','kudos',$id);
            if(method_exists($this,'push_custom_notification')){
                $url=add_query_arg(['ews_view'=>'employee','employee_id'=>$recipient_id],$this->app_view_url('people'));
                $this->push_custom_notification((int)$recipient->wp_user_id,$title,$msg,'recognition',$id,$url);
            }
        }
        $this->audit('kudos_created','kudos',$id,'sender_employee_id='.(int)$sender->id.'; recipient_employee_id='.$recipient_id.'; category='.$category);
        // Keep the success notice one-time and out of the persistent URL query string.
        set_transient('wfo_kudos_notice_'.get_current_user_id().'_'.$recipient_id,'success',60);
        $this->recognition_redirect($recipient_id);
    }

    private function recognition_rows($employee_id,$limit=20){
        global $wpdb;
        $this->ensure_recognition_schema();
        $limit=max(1,min(100,absint($limit)));
        return $wpdb->get_results($wpdb->prepare("SELECT k.*,s.name sender_name FROM {$wpdb->prefix}ews_kudos k LEFT JOIN {$this->employees} s ON s.id=k.sender_employee_id WHERE k.recipient_employee_id=%d AND k.status='active' ORDER BY k.created_at DESC LIMIT %d",absint($employee_id),$limit));
    }

    private function recognition_delete($id){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        global $wpdb;$this->ensure_recognition_schema();
        $id=absint($id);if(!$id)return;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}ews_kudos WHERE id=%d LIMIT 1",$id));
        if(!$row)return;
        $wpdb->update($wpdb->prefix.'ews_kudos',['status'=>'deleted'],['id'=>$id],['%s'],['%d']);
        $this->audit('kudos_deleted','kudos',$id,'Recognition removed by admin.');
    }
}
