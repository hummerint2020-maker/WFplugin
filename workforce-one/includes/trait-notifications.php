<?php
if (!defined('ABSPATH')) exit;

trait EWS_Notifications_Trait {

    private $notifications;
    private static $notifications_schema_ready = false;
    private static $ews_notification_unread_cache = [];
    private static $ews_vapid_public_key_cache = null;

    private function invalidate_notification_unread_cache($user_id=0){
        $user_id=absint($user_id);
        if($user_id)unset(self::$ews_notification_unread_cache[$user_id]);
        else self::$ews_notification_unread_cache=[];
    }

    private function notification_policy_categories(){
        return [
            'schedule'=>['label'=>'Schedule','description'=>'Schedule changes and updates.','push_default'=>1],
            'leave'=>['label'=>'Leave','description'=>'Leave requests and decisions.','push_default'=>0],
            'vacation'=>['label'=>'Vacation','description'=>'Vacation requests and decisions.','push_default'=>0],
            'early_leave'=>['label'=>'Early Leave','description'=>'Early leave requests and decisions.','push_default'=>0],
            'overtime'=>['label'=>'Overtime','description'=>'Overtime requests and decisions.','push_default'=>0],
            'swap'=>['label'=>'Shift Swap','description'=>'Shift swap requests and outcomes.','push_default'=>1],
            'break'=>['label'=>'Break','description'=>'Important break alerts and updates.','push_default'=>0],
            'task'=>['label'=>'Tasks','description'=>'Task assignments and status changes.','push_default'=>0],
            'face_reset'=>['label'=>'Face Reset','description'=>'Face reset requests and decisions.','push_default'=>0],
            'system'=>['label'=>'System','description'=>'General administrative system notifications.','push_default'=>0],
            'smart_nudge'=>['label'=>'Smart Nudges','description'=>'Actionable employee reminders and attention prompts.','push_default'=>1],
            'achievement'=>['label'=>'Achievements','description'=>'Employee achievement and recognition notifications.','push_default'=>1],
            'recognition'=>['label'=>'Recognition','description'=>'Kudos and employee recognition notifications.','push_default'=>1],
        ];
    }

    private function notification_policy_defaults(){
        $defaults=[];
        foreach($this->notification_policy_categories() as $key=>$cat){
            $defaults[$key]=['in_app'=>1,'push'=>(int)$cat['push_default'],'mandatory'=>0];
        }
        return $defaults;
    }

    private function notification_policy(){
        $defaults=$this->notification_policy_defaults();
        $saved=get_option('ews_notification_policy',[]);
        if(!is_array($saved))$saved=[];
        foreach($defaults as $key=>$row){
            if(isset($saved[$key]) && is_array($saved[$key])){
                $defaults[$key]['in_app']=!empty($saved[$key]['in_app'])?1:0;
                $defaults[$key]['push']=!empty($saved[$key]['push'])?1:0;
                $defaults[$key]['mandatory']=!empty($saved[$key]['mandatory'])?1:0;
            }
        }
        return $defaults;
    }

    private function notification_policy_for($category){
        $category=sanitize_key($category);
        $policy=$this->notification_policy();
        return isset($policy[$category])?$policy[$category]:['in_app'=>1,'push'=>0,'mandatory'=>0];
    }

    private function notification_push_allowed($category){
        $p=$this->notification_policy_for($category);
        return !empty($p['push']) || !empty($p['mandatory']);
    }

    private function notification_in_app_allowed($category){
        return !empty($this->notification_policy_for($category)['in_app']);
    }

    private function ensure_notifications_schema($force=false){
        global $wpdb;
        $this->notifications=$wpdb->prefix.'ews_notifications';
        if(self::$notifications_schema_ready && !$force) return;
        if(!$force && $this->ews_schema_is_current()) return;
        $charset=$wpdb->get_charset_collate();
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->notifications));
        if(!$exists || $force){
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            dbDelta("CREATE TABLE {$this->notifications} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                title VARCHAR(190) NOT NULL,
                message TEXT NOT NULL,
                type VARCHAR(30) NOT NULL DEFAULT 'info',
                entity VARCHAR(80) NULL,
                entity_id BIGINT UNSIGNED NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                PRIMARY KEY(id),
                KEY user_unread(user_id,is_read),
                KEY user_read_date(user_id,is_read,created_at),
                KEY created_at(created_at),
                KEY entity(entity,entity_id)
            ) {$charset};");
        }
        self::$notifications_schema_ready=true;
    }

    private function notify_user($user_id,$title,$message,$type='info',$entity=null,$entity_id=null){
        global $wpdb;
        $user_id=absint($user_id);
        if(!$user_id)return 0;
        $category=$entity?sanitize_key($entity):sanitize_key($type);
        if(!$this->notification_in_app_allowed($category))return 0;
        $this->ensure_notifications_schema();
        $ok=$wpdb->insert($this->notifications,[
            'user_id'=>$user_id,
            'title'=>sanitize_text_field($title),
            'message'=>wp_kses_post($message),
            'type'=>sanitize_key($type),
            'entity'=>$entity?sanitize_key($entity):null,
            'entity_id'=>$entity_id?absint($entity_id):null,
            'is_read'=>0,
            'created_at'=>current_time('mysql')
        ],['%d','%s','%s','%s','%s','%d','%d','%s']);
        if($ok===false){
            if(method_exists($this,'audit')) $this->audit('notification_insert_failed','notification',0,'user_id='.$user_id.'; '.$wpdb->last_error);
            if(defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) error_log('[Workforce One] notification_insert_failed: '.$wpdb->last_error);
            return 0;
        }
        $this->invalidate_notification_unread_cache($user_id);
        return (int)$wpdb->insert_id;
    }

    public function push_custom_notification($user_id,$title,$message,$type='info',$entity_id=0,$url=''){
            $category=sanitize_key($type);
            if(!$this->notification_push_allowed($category))return 0;
            if(!function_exists('curl_init'))return 0;
            $this->ensure_push_schema();
            global $wpdb;
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->push_table()} WHERE user_id=%d ORDER BY updated_at DESC",$user_id));
            $resolved_url=$url;
            if(!$resolved_url){
                $resolved_url=$this->notification_app_view_url($category);
            }
            $payload=['title'=>$title,'body'=>wp_strip_all_tags($message),'url'=>$resolved_url,'notification_id'=>0,'type'=>sanitize_key($type),'entity_id'=>absint($entity_id)];
            $sent=0;
            foreach($rows as $row){
                $result=$this->send_push_payload($row,$payload);
                if(!empty($result['ok']))$sent++;
                if(!empty($result['expired']))$wpdb->delete($this->push_table(),['id'=>(int)$row->id],['%d']);
            }
            return $sent;
        }

    public function push_schedule_update($user_id){
        if(!$this->notification_push_allowed('schedule'))return;
        if(!function_exists('curl_init'))return;
        $this->ensure_notifications_schema();
        $this->ensure_push_schema();
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->push_table()} WHERE user_id=%d ORDER BY updated_at DESC",$user_id));
        $note=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->notifications} WHERE user_id=%d AND entity='schedule' ORDER BY id DESC LIMIT 1",$user_id));
        $payload=[
            'title'=>$note?$note->title:'Schedule Updated',
            'body'=>$note?wp_strip_all_tags($note->message):'Your schedule has been updated.',
            'url'=>add_query_arg('ews_view','notifications',home_url('/')),
            'notification_id'=>$note?(int)$note->id:0,
            'type'=>'schedule'
        ];
        foreach($rows as $row){
            $result=$this->send_push_payload($row,$payload);
            $this->push_debug('Schedule push result',['user_id'=>(int)$user_id,'device_id'=>(int)$row->id,'result'=>$result]);
            if(!empty($result['expired'])){
                $wpdb->delete($this->push_table(),['id'=>(int)$row->id],['%d']);
            }
        }
    }

    private function notification_unread_count($user_id=null){
        global $wpdb;
        $user_id=$user_id===null?get_current_user_id():absint($user_id);
        if(!$user_id)return 0;
        if(array_key_exists($user_id,self::$ews_notification_unread_cache))return self::$ews_notification_unread_cache[$user_id];
        $this->ensure_notifications_schema();
        self::$ews_notification_unread_cache[$user_id]=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->notifications} WHERE user_id=%d AND is_read=0",$user_id));
        return self::$ews_notification_unread_cache[$user_id];
    }

    private function notification_app_view_url($view){
        // notification_open() runs through admin-post.php. app_view_url() builds
        // URLs from the current request URL, which would otherwise preserve
        // action=ews_notification_open and redirect back into the handler.
        $ref=wp_get_referer();
        if($ref){
            $path=(string)wp_parse_url($ref,PHP_URL_PATH);
            // Never derive an app notification URL from wp-admin. Achievement
            // awards can be triggered from an Admin request, whose referer is
            // the Admin Achievements page. Using that referer would send the
            // employee's PWA notification back into wp-admin.
            $is_admin_path = (strpos($path, '/wp-admin/') !== false || basename($path)==='admin-post.php');
            if($path && !$is_admin_path){
                $ref=remove_query_arg([
                    'ews_view','action','notification_id','_wpnonce'
                ],$ref);
                return add_query_arg('ews_view',sanitize_key($view),$ref);
            }
        }
        return add_query_arg('ews_view',sanitize_key($view),home_url('/'));
    }

    private function notification_url($notification){
        $entity=sanitize_key($notification->entity??'');
        $id=absint($notification->entity_id??0);
        switch($entity){
            case 'task':
                $url=$this->notification_app_view_url('tasks');
                return $id?add_query_arg('edit_task',$id,$url):$url;
            case 'schedule':
            case 'swap':
                return $this->notification_app_view_url('schedule');
            case 'leave':
            case 'vacation':
                return $this->notification_app_view_url('vacation');
            case 'overtime':
                return $this->notification_app_view_url('overtime');
            case 'break':
                return $this->notification_app_view_url('time');
            case 'face_reset':
                return $this->notification_app_view_url('employees');
            default:
                return $this->notification_app_view_url('notifications');
        }
    }

    private function notification_rows($user_id=null,$limit=30,$filter='all'){
        global $wpdb;
        $user_id=$user_id===null?get_current_user_id():absint($user_id);
        if(!$user_id)return [];
        $this->ensure_notifications_schema();
        $limit=max(1,min(100,absint($limit)));
        $filter=$filter==='unread'?'unread':'all';
        $where="user_id=%d";
        $args=[$user_id];
        if($filter==='unread'){ $where.=' AND is_read=0'; }
        $args[]=$limit;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->notifications} WHERE {$where} ORDER BY is_read ASC, created_at DESC LIMIT %d",$args));
    }

    public function notification_read(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        $id=absint($_POST['notification_id']??0);
        check_admin_referer('ews_notification_read_'.$id);
        global $wpdb;
        $this->ensure_notifications_schema();
        $wpdb->query($wpdb->prepare("UPDATE {$this->notifications} SET is_read=1,read_at=%s WHERE id=%d AND user_id=%d",current_time('mysql'),$id,get_current_user_id()));
        $this->invalidate_notification_unread_cache(get_current_user_id());
        $redirect=wp_get_referer()?:remove_query_arg('action');
        wp_safe_redirect($redirect);exit;
    }

    public function notification_open(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        $id=absint($_GET['notification_id']??0);
        if(!$id)wp_safe_redirect($this->app_view_url('notifications'));
        check_admin_referer('ews_notification_open_'.$id);
        global $wpdb;
        $this->ensure_notifications_schema();
        $note=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->notifications} WHERE id=%d AND user_id=%d LIMIT 1",$id,get_current_user_id()));
        if(!$note)wp_safe_redirect($this->app_view_url('notifications'));
        if(!(int)$note->is_read){
            $wpdb->query($wpdb->prepare("UPDATE {$this->notifications} SET is_read=1,read_at=%s WHERE id=%d AND user_id=%d AND is_read=0",current_time('mysql'),$id,get_current_user_id()));
            $this->invalidate_notification_unread_cache(get_current_user_id());
        }
        wp_safe_redirect($this->notification_url($note));exit;
    }

    public function notification_read_all(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        check_admin_referer('ews_notification_read_all');
        global $wpdb;
        $this->ensure_notifications_schema();
        $wpdb->query($wpdb->prepare("UPDATE {$this->notifications} SET is_read=1,read_at=%s WHERE user_id=%d AND is_read=0",current_time('mysql'),get_current_user_id()));
        $this->invalidate_notification_unread_cache(get_current_user_id());
        $redirect=wp_get_referer()?:home_url('/');
        wp_safe_redirect($redirect);exit;
    }

    public function notifications_content(){
        if(!is_user_logged_in())return $this->login_page();
        $push_public_key=$this->get_vapid_public_key();
        $push_url=admin_url('admin-post.php');
        $push_nonce=wp_create_nonce('ews_push_subscription');
        $filter=sanitize_key($_GET['notification_tab']??'all');
        if(!in_array($filter,['all','unread'],true))$filter='all';
        $rows=$this->notification_rows(null,50,$filter);
        $unread=$this->notification_unread_count();
        $all_url=$this->app_view_url('notifications');
        $unread_url=add_query_arg('notification_tab','unread',$all_url);
        ob_start(); ?>
        <div class="ews-notifications-page">
            <div class="ews-notif-intro">
                <div class="ews-notif-intro-icon">🔔</div>
                <div><h2>Notifications</h2><p>Stay updated with your latest alerts and activities.</p></div>
            </div>

            <div id="ews-notification-push-settings" class="ews-push-card">
                <div class="ews-push-main">
                    <div class="ews-push-icon">🔔</div>
                    <div class="ews-push-copy"><strong>Push Notifications</strong><span>Receive notifications on this device.</span></div>
                    <span id="ews-notification-push-status" class="ews-push-status">Enabled</span>
                    <button id="ews-notification-push-enable" type="button" style="display:none;">Enable</button>
                    <button id="ews-notification-push-disable" type="button" style="display:none;">Disable</button>
                </div>
                <div class="ews-push-device">▯ &nbsp; <strong>This device</strong></div>
            </div>

            <div class="ews-card ews-notifications-card">
                <div class="ews-notifications-head">
                    <div class="ews-notifications-title-wrap"><div><h2 style="margin:0;display:inline-block;">Your Notifications</h2><span class="ews-unread-count"><?php echo absint($unread); ?> Unread</span></div><div class="ews-notification-tabs" role="tablist" aria-label="Notification filter"><a class="<?php echo $filter==='all'?'active':''; ?>" href="<?php echo esc_url($all_url); ?>" role="tab" aria-selected="<?php echo $filter==='all'?'true':'false'; ?>">All</a><a class="<?php echo $filter==='unread'?'active':''; ?>" href="<?php echo esc_url($unread_url); ?>" role="tab" aria-selected="<?php echo $filter==='unread'?'true':'false'; ?>">Unread<?php echo $unread?' ('.absint($unread).')':''; ?></a></div></div>
                    <?php if($unread): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('ews_notification_read_all'); ?>
                        <input type="hidden" name="action" value="ews_notification_read_all">
                        <button class="ews-notification-read" type="submit">Mark all as read</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if(!$rows): ?>
                    <div class="ews-empty-notifications"><?php if($filter==='unread'): ?><div class="ews-empty-notifications-icon">✓</div><strong>You're all caught up.</strong><span>No unread notifications.</span><?php else: ?><div class="ews-empty-notifications-icon">🔔</div><strong>No notifications yet.</strong><span>You're all caught up.</span><?php endif; ?></div>
                <?php else: foreach($rows as $n):
                    $icon=$n->type==='success'?'✓':($n->type==='warning'?'!':($n->type==='error'?'×':($n->entity==='schedule'?'▣':'i')));
                    $open_url=wp_nonce_url(add_query_arg(['action'=>'ews_notification_open','notification_id'=>(int)$n->id],admin_url('admin-post.php')),'ews_notification_open_'.(int)$n->id);
                ?>
                <div class="ews-notification <?php echo $n->is_read?'read':'unread'; ?>" data-notification-open="<?php echo esc_url($open_url); ?>" tabindex="0" role="link" aria-label="Open notification: <?php echo esc_attr($n->title); ?>">
                    <div><?php if(!$n->is_read): ?><span style="display:block;width:7px;height:7px;border-radius:50%;background:#5b21b6;"></span><?php endif; ?></div>
                    <div class="ews-notification-icon <?php echo esc_attr($n->type); ?>"><?php echo esc_html($icon); ?></div>
                    <div class="ews-notification-body">
                        <div class="ews-notification-title"><?php echo esc_html($n->title); ?></div>
                        <div class="ews-notification-message"><?php echo wp_kses_post($n->message); ?></div>
                        <div class="ews-notification-time"><?php echo esc_html(human_time_diff(strtotime($n->created_at),current_time('timestamp')).' ago'); ?></div>

                    </div>
                    <?php if(!$n->is_read): ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('ews_notification_read_'.(int)$n->id); ?>
                        <input type="hidden" name="action" value="ews_notification_read">
                        <input type="hidden" name="notification_id" value="<?php echo (int)$n->id; ?>">
                        <button class="ews-notification-read" type="submit">Mark read</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
        <script>
        (function(){
          var status=document.getElementById('ews-notification-push-status'),en=document.getElementById('ews-notification-push-enable'),dis=document.getElementById('ews-notification-push-disable');
          var publicKey=<?php echo wp_json_encode($push_public_key); ?>,postUrl=<?php echo wp_json_encode($push_url); ?>,nonce=<?php echo wp_json_encode($push_nonce); ?>;
          function b64(s){var p='='.repeat((4-s.length%4)%4),x=(s+p).replace(/-/g,'+').replace(/_/g,'/'),r=atob(x),a=new Uint8Array(r.length);for(var i=0;i<r.length;i++)a[i]=r.charCodeAt(i);return a;}
          function state(){if(!('serviceWorker'in navigator)||!('PushManager'in window)||!('Notification'in window)){status.style.display='none';en.style.display='none';dis.style.display='none';return;}if(!publicKey){status.style.display='none';return;}navigator.serviceWorker.ready.then(function(reg){return reg.pushManager.getSubscription();}).then(function(sub){if(sub){status.classList.add('enabled');status.style.display='inline-flex';en.style.display='none';dis.style.display='inline-block';}else{status.classList.remove('enabled');status.style.display='none';en.style.display='inline-block';dis.style.display='none';}}).catch(function(){});}
          en.addEventListener('click',function(){en.disabled=true;navigator.serviceWorker.ready.then(function(reg){return Notification.requestPermission().then(function(p){if(p!=='granted')throw new Error('Notification permission was not granted.');return reg.pushManager.getSubscription().then(function(s){return s||reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64(publicKey)});});});}).then(function(sub){var body=new URLSearchParams();body.set('action','ews_push_subscribe');body.set('_wpnonce',nonce);body.set('subscription',JSON.stringify(sub.toJSON()));return fetch(postUrl,{method:'POST',credentials:'same-origin',body:body});}).then(function(r){if(!r.ok)throw new Error('Unable to save device subscription.');return r.text();}).then(function(){en.disabled=false;state();}).catch(function(){en.disabled=false;state();});});
          dis.addEventListener('click',function(){dis.disabled=true;navigator.serviceWorker.ready.then(function(reg){return reg.pushManager.getSubscription();}).then(function(sub){if(!sub)return null;var body=new URLSearchParams();body.set('action','ews_push_unsubscribe');body.set('_wpnonce',nonce);body.set('endpoint',sub.endpoint);return fetch(postUrl,{method:'POST',credentials:'same-origin',body:body}).then(function(){return sub.unsubscribe();});}).then(function(){dis.disabled=false;state();}).catch(function(){dis.disabled=false;state();});});
          if('serviceWorker'in navigator){navigator.serviceWorker.ready.then(state).catch(state);}else state();
          document.querySelectorAll('.ews-notification[data-notification-open]').forEach(function(card){
            card.addEventListener('click',function(e){if(e.target.closest('a,button,form'))return;window.location.href=card.getAttribute('data-notification-open');});
            card.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){if(e.target!==card)return;e.preventDefault();window.location.href=card.getAttribute('data-notification-open');}});
          });
        })();
        </script>
        <?php return ob_get_clean();
    }

    public function cleanup_notifications(){
        global $wpdb;
        $this->ensure_notifications_schema();
        $days=(int)get_option('ews_notification_retention_days',90);
        if($days<=0)return;
        $cutoff=gmdate('Y-m-d H:i:s',time()-($days*DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->notifications} WHERE created_at < %s",$cutoff));
    }


    private function push_table(){
        global $wpdb;
        return $wpdb->prefix.'ews_push_subscriptions';
    }

    private function ensure_push_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        $table=$this->push_table();
        $exists=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table));
        if(!$exists){
            require_once ABSPATH.'wp-admin/includes/upgrade.php';
            $charset=$wpdb->get_charset_collate();
            dbDelta("CREATE TABLE {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                endpoint TEXT NOT NULL,
                endpoint_hash CHAR(64) NOT NULL,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL,
                content_encoding VARCHAR(30) NULL,
                user_agent TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                UNIQUE KEY endpoint_hash(endpoint_hash),
                KEY user_id(user_id)
            ) {$charset};");
        }
    }

    private function base64url_decode($data){
        return base64_decode(strtr($data,'-_','+/').str_repeat('=',(4-strlen($data)%4)%4));
    }

    private function get_vapid_public_key(){
        if(self::$ews_vapid_public_key_cache!==null)return self::$ews_vapid_public_key_cache;
        $this->ensure_vapid_keys();
        self::$ews_vapid_public_key_cache=(string)get_option('ews_vapid_public_key','');
        return self::$ews_vapid_public_key_cache;
    }

    private function ensure_vapid_keys(){
        if(get_option('ews_vapid_public_key','') && get_option('ews_vapid_private_key','')) return true;
        if(!function_exists('openssl_pkey_new')) return false;
        $key=openssl_pkey_new([
            'private_key_type'=>OPENSSL_KEYTYPE_EC,
            'curve_name'=>'prime256v1'
        ]);
        if(!$key)return false;
        if(!openssl_pkey_export($key,$private_pem))return false;
        $details=openssl_pkey_get_details($key);
        if(empty($details['ec']['x'])||empty($details['ec']['y']))return false;
        $public="\x04".$details['ec']['x'].$details['ec']['y'];
        $b64=function($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');};
        update_option('ews_vapid_public_key',$b64($public),false);
        update_option('ews_vapid_private_key',$private_pem,false);
        return true;
    }

    public function push_subscribe(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        check_admin_referer('ews_push_subscription');
        $raw=wp_unslash($_POST['subscription']??'');
        $data=json_decode($raw,true);
        if(!is_array($data)||empty($data['endpoint'])||empty($data['keys']['p256dh'])||empty($data['keys']['auth']))wp_die('Invalid push subscription.');
        $endpoint=esc_url_raw($data['endpoint']);
        if(!$endpoint)wp_die('Invalid push endpoint.');
        $this->ensure_push_schema();
        global $wpdb;$table=$this->push_table();$hash=hash('sha256',$endpoint);
        $row=$wpdb->get_row($wpdb->prepare("SELECT id FROM {$table} WHERE endpoint_hash=%s",$hash));
        $payload=[
            'user_id'=>get_current_user_id(),
            'endpoint'=>$endpoint,
            'endpoint_hash'=>$hash,
            'p256dh'=>sanitize_text_field($data['keys']['p256dh']),
            'auth'=>sanitize_text_field($data['keys']['auth']),
            'content_encoding'=>sanitize_text_field($data['contentEncoding']??'aesgcm'),
            'user_agent'=>sanitize_text_field($_SERVER['HTTP_USER_AGENT']??''),
            'updated_at'=>current_time('mysql')
        ];
        if($row)$wpdb->update($table,$payload,['id'=>(int)$row->id]);
        else{$payload['created_at']=current_time('mysql');$wpdb->insert($table,$payload);}
        wp_send_json_success(['subscribed'=>true]);
    }

    public function push_unsubscribe(){
        if(!is_user_logged_in())wp_die('You must be logged in.');
        check_admin_referer('ews_push_subscription');
        $endpoint=esc_url_raw(wp_unslash($_POST['endpoint']??''));
        $this->ensure_push_schema();
        if($endpoint){
            global $wpdb;$wpdb->delete($this->push_table(),['endpoint_hash'=>hash('sha256',$endpoint),'user_id'=>get_current_user_id()],['%s','%d']);
        }
        wp_send_json_success(['subscribed'=>false]);
    }

    private function b64url_encode($value){
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private function vapid_jwt($audience){
        $private=get_option('ews_vapid_private_key','');
        $public=get_option('ews_vapid_public_key','');
        $subject=get_option('ews_vapid_subject','mailto:'.get_option('admin_email','admin@example.com'));
        if(!$private||!$public)return false;
        $now=time();
        $header=$this->b64url_encode(wp_json_encode(['typ'=>'JWT','alg'=>'ES256']));
        $payload=$this->b64url_encode(wp_json_encode(['aud'=>$audience,'exp'=>$now+43200,'sub'=>$subject]));
        $signing=$header.'.'.$payload;
        if(!openssl_sign($signing,$signature,$private,OPENSSL_ALGO_SHA256))return false;
        $raw=$this->ecdsa_der_to_raw($signature,32);
        return $raw===false?false:$signing.'.'.$this->b64url_encode($raw);
    }

    private function ecdsa_der_to_raw($der,$size){
        $pos=0;$len=strlen($der);
        if($len<8||ord($der[$pos++])!==0x30)return false;
        $seqLen=ord($der[$pos++]);
        if($seqLen&0x80){$n=$seqLen&0x7f;$seqLen=0;for($i=0;$i<$n;$i++)$seqLen=($seqLen<<8)|ord($der[$pos++]);}
        if(ord($der[$pos++])!==0x02)return false;
        $rLen=ord($der[$pos++]);$r=substr($der,$pos,$rLen);$pos+=$rLen;
        if(ord($der[$pos++])!==0x02)return false;
        $sLen=ord($der[$pos++]);$s=substr($der,$pos,$sLen);
        $r=ltrim($r,"\x00");$s=ltrim($s,"\x00");
        if(strlen($r)>$size||strlen($s)>$size)return false;
        return str_repeat("\x00",$size-strlen($r)).$r.str_repeat("\x00",$size-strlen($s)).$s;
    }

    private function push_endpoint_audience($endpoint){
        $parts=wp_parse_url($endpoint);
        if(empty($parts['scheme'])||empty($parts['host']))return false;
        return $parts['scheme'].'://'.$parts['host'].(!empty($parts['port'])?':'.$parts['port']:'');
    }

    private function hkdf_extract($salt,$ikm){
        return hash_hmac('sha256',$ikm,$salt,true);
    }

    private function hkdf_expand($prk,$info,$length){
        $out='';$t='';$counter=1;
        while(strlen($out)<$length){
            $t=hash_hmac('sha256',$t.$info.chr($counter),$prk,true);
            $out.=$t;$counter++;
            if($counter>255)return false;
        }
        return substr($out,0,$length);
    }

    private function raw_p256_public_key($details){
        if(empty($details['ec']['x'])||empty($details['ec']['y']))return false;
        return "\x04".$details['ec']['x'].$details['ec']['y'];
    }

    private function p256_public_pem($raw){
        if(strlen($raw)!==65||$raw[0]!=="\x04")return false;
        // SubjectPublicKeyInfo for id-ecPublicKey / prime256v1.
        $der=hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$raw;
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der),64,"\n")."-----END PUBLIC KEY-----\n";
    }

    private function encrypt_webpush_payload($row,$payload){
        if(!function_exists('openssl_pkey_new')||!function_exists('openssl_pkey_derive')||!function_exists('openssl_encrypt')){
            return ['ok'=>false,'error'=>'PHP OpenSSL EC/AES-GCM support is not available.'];
        }
        $ua_public=$this->base64url_decode($row->p256dh);
        $auth_secret=$this->base64url_decode($row->auth);
        if(strlen($ua_public)!==65||$ua_public[0]!=="\x04"||strlen($auth_secret)<16){
            return ['ok'=>false,'error'=>'Invalid device push keys.'];
        }
        $ua_pem=$this->p256_public_pem($ua_public);
        if(!$ua_pem)return ['ok'=>false,'error'=>'Unable to build device public key.'];
        $ua_key=openssl_pkey_get_public($ua_pem);
        if(!$ua_key)return ['ok'=>false,'error'=>'Unable to parse device public key.'];

        // Force the ephemeral key back through a PEM private-key export/import.
        // Some cPanel/OpenSSL combinations return an EC key object that
        // openssl_pkey_derive() treats as public-only unless re-imported.
        $server_key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);
        if(!$server_key)return ['ok'=>false,'error'=>'Unable to generate ephemeral EC key.'];
        $server_private_pem='';
        if(!@openssl_pkey_export($server_key,$server_private_pem))return ['ok'=>false,'error'=>'Unable to export ephemeral EC private key.'];
        $server_private=openssl_pkey_get_private($server_private_pem);
        if(!$server_private)return ['ok'=>false,'error'=>'Unable to re-import ephemeral EC private key.'];
        $server_details=openssl_pkey_get_details($server_private);
        $server_public=$this->raw_p256_public_key($server_details);
        if(!$server_public)return ['ok'=>false,'error'=>'Unable to read ephemeral EC public key.'];

        $ecdh=@openssl_pkey_derive($ua_key,$server_private);
        if($ecdh===false)return ['ok'=>false,'error'=>'ECDH key agreement failed.'];

        $prk_key=$this->hkdf_extract($auth_secret,$ecdh);
        $info="WebPush: info\x00".$ua_public.$server_public;
        $ikm=$this->hkdf_expand($prk_key,$info,32);
        if($ikm===false)return ['ok'=>false,'error'=>'HKDF key derivation failed.'];

        $salt=random_bytes(16);
        $prk=$this->hkdf_extract($salt,$ikm);
        $cek=$this->hkdf_expand($prk,"Content-Encoding: aes128gcm\x00",16);
        $nonce=$this->hkdf_expand($prk,"Content-Encoding: nonce\x00",12);
        if($cek===false||$nonce===false)return ['ok'=>false,'error'=>'Payload key derivation failed.'];

        $json=wp_json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if($json===false)return ['ok'=>false,'error'=>'Unable to encode push payload.'];
        $plaintext=$json."\x02";
        $rs=4096;
        if(strlen($plaintext)+16>$rs)return ['ok'=>false,'error'=>'Push payload is too large.'];
        $tag='';
        $cipher=openssl_encrypt($plaintext,'aes-128-gcm',$cek,OPENSSL_RAW_DATA,$nonce,$tag,'',16);
        if($cipher===false)return ['ok'=>false,'error'=>'AES-GCM encryption failed.'];
        $body=$salt.pack('N',$rs).chr(strlen($server_public)).$server_public.$cipher.$tag;
        return ['ok'=>true,'body'=>$body];
    }

    private function push_debug($message,$context=[]){
        if(defined('WP_DEBUG') && WP_DEBUG){
            error_log('[Workforce One Push] '.$message.' '.wp_json_encode($context));
        }
    }

    private function send_push_payload($row,$payload){
        if(!function_exists('curl_init'))return ['ok'=>false,'error'=>'PHP cURL extension is not available.'];
        $aud=$this->push_endpoint_audience($row->endpoint);
        $jwt=$aud?$this->vapid_jwt($aud):false;
        if(!$jwt)return ['ok'=>false,'error'=>'Unable to create VAPID token. Check OpenSSL and VAPID settings.'];
        $enc=$this->encrypt_webpush_payload($row,$payload);
        if(!$enc['ok']){ $this->push_debug('Encryption failed',['error'=>$enc['error']??'unknown']); return $enc; }
        $ch=curl_init($row->endpoint);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$enc['body'],
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HEADER=>false,
            CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>[
                'TTL: 300',
                'Urgency: normal',
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'Authorization: vapid t='.$jwt.', k='.get_option('ews_vapid_public_key',''),
                'Content-Length: '.strlen($enc['body'])
            ]
        ]);
        curl_exec($ch);
        $errno=curl_errno($ch);$error=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($errno){ $this->push_debug('cURL failed',['error'=>$error]); return ['ok'=>false,'error'=>'cURL error: '.$error]; }
        if($code>=200&&$code<300){ $this->push_debug('Push accepted',['code'=>$code]); return ['ok'=>true,'code'=>$code]; }
        if($code===404||$code===410)return ['ok'=>false,'expired'=>true,'code'=>$code,'error'=>'Push subscription is expired or no longer valid.'];
        $this->push_debug('Push rejected',['code'=>$code]); return ['ok'=>false,'code'=>$code,'error'=>'Push service returned HTTP '.$code.'.'];
    }

    private function send_empty_push($row){
        return $this->send_push_payload($row,[
            'title'=>'Workforce One',
            'body'=>'You have a new notification.',
            'url'=>home_url('/')
        ]);
    }

    public function push_test(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_push_test');
        $this->ensure_push_schema();
        global $wpdb;
        $count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->push_table()}");
        $this->redirect(['page'=>'ews31-notifications','push_devices'=>$count]);
    }

    public function push_send_test(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied');
        check_admin_referer('ews_push_send_test');
        $this->ensure_push_schema();
        global $wpdb;
        $rows=$wpdb->get_results("SELECT * FROM {$this->push_table()} ORDER BY updated_at DESC");
        $sent=0;$expired=0;$errors=[];
        foreach($rows as $row){
            $result=$this->send_push_payload($row,['title'=>'Workforce One','body'=>'This is a test notification.','url'=>add_query_arg('ews_view','notifications',home_url('/'))]);
            if($result['ok']){$sent++;continue;}
            if(!empty($result['expired'])){$expired++;$wpdb->delete($this->push_table(),['id'=>(int)$row->id],['%d']);continue;}
            $errors[]=$result['error'];
        }
        $this->redirect(['page'=>'ews31-notifications','push_sent'=>$sent,'push_expired'=>$expired,'push_error'=>count($errors)]);
    }

    private function notification_bell(){
        if(!is_user_logged_in())return '';
        $count=$this->notification_unread_count();
        $all_url=$this->app_view_url('notifications');
        $rows=$this->notification_rows(null,5,'all');
        $items='';
        if($rows){
            foreach($rows as $note){
                $id=absint($note->id);
                $open_url=wp_nonce_url(add_query_arg(['action'=>'ews_notification_open','notification_id'=>$id],admin_url('admin-post.php')),'ews_notification_open_'.$id);
                $title=wp_trim_words(wp_strip_all_tags((string)$note->title),9,'…');
                $message=wp_trim_words(wp_strip_all_tags((string)$note->message),13,'…');
                $time=sprintf(/* translators: %s: human-readable time difference */__('%s ago','workforce-one'),human_time_diff(strtotime($note->created_at),current_time('timestamp')));
                $items.='<a class="ews-bell-item'.(!(int)$note->is_read?' unread':''). '" href="'.esc_url($open_url).'">'
                    .'<span class="ews-bell-item-icon" aria-hidden="true">🔔</span>'
                    .'<span class="ews-bell-item-body"><strong>'.esc_html($title).'</strong><span>'.esc_html($message).'</span><small>'.esc_html($time).'</small></span>'
                    .(!(int)$note->is_read?'<i class="ews-bell-item-dot" aria-hidden="true"></i>':'')
                    .'</a>';
            }
        }else{
            $items='<div class="ews-bell-empty"><strong>'.esc_html__('You’re all caught up','workforce-one').'</strong><span>'.esc_html__('No new notifications.','workforce-one').'</span></div>';
        }
        return '<div class="ews-notification-bell-wrap">'
            .'<button type="button" class="ews-notification-bell" aria-label="'.esc_attr__('Notifications','workforce-one').'" title="'.esc_attr__('Notifications','workforce-one').'" aria-expanded="false" aria-controls="ews-notification-dropdown">'
            .'<span class="ews-bell-icon" aria-hidden="true">🔔</span>'.($count?'<span class="ews-bell-count">'.$count.'</span>':'').'</button>'
            .'<div id="ews-notification-dropdown" class="ews-notification-dropdown" hidden>'
            .'<div class="ews-bell-head"><strong>'.esc_html__('Notifications','workforce-one').'</strong>'.($count?'<span>'.esc_html(sprintf(/* translators: %d: unread count */_n('%d unread','%d unread',$count,'workforce-one'),$count)).'</span>':'<span>'.esc_html__('All caught up','workforce-one').'</span>').'</div>'
            .'<div class="ews-bell-list">'.$items.'</div>'
            .'<a class="ews-bell-footer" href="'.esc_url($all_url).'">'.esc_html__('View all notifications →','workforce-one').'</a>'
            .'</div></div>';
    }
}
