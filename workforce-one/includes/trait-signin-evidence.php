<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\DeviceLabel;
use WorkforceOne\Attendance\EvidenceRules;
use WorkforceOne\Settings\SignInEvidence;

/**
 * Sign In evidence (3.31.92, owner-approved mockups docs/mockups/signin-attempts/ and face-photo/).
 * Both parts are off until turned on in Features (option ews_signin_evidence, Settings\SignInEvidence).
 *
 * Refused attempts: every Sign In / Out the app refuses (outside the work location, no face check,
 * not a working day, ...) and every face check (matched or not) is kept in ews_signin_attempts with
 * the reason, where the phone was, the device and the IP. An attempt that never reached the server
 * (no network) is noted on the phone when the button is pressed and sent the next time the app opens;
 * it is timed from the server's clock (Settings\SignInEvidence::offlineTime) and shown apart: it never
 * counts as attendance. The department's managers are told when one employee is refused N times a
 * day. Kept N days.
 *
 * Face photo: with Face Sign In on, a small photo from the same camera frame as the face check is kept
 * with the Sign In / Out (ews_time_logs.photo_key) or with the refused face check, in the private
 * files folder (trait-private-files.php), for N days. Seen by HR and the department manager (or HR
 * only); each opening is written to the Audit Log. The employee is told on the camera screen.
 *
 * Shown in wp-admin → Sign In / Out (trait-time-report.php). Behaviour: tests/e2e_signin_evidence.py.
 */
trait EWS_Signin_Evidence_Trait {

    private function evidence_settings(){
        return SignInEvidence::config(get_option('ews_signin_evidence'));
    }

    private function attempts_enabled(){ return (bool)$this->evidence_settings()['attempts']; }

    /** The photo needs Face Sign In: it is taken from the face check's frame. */
    private function face_photo_enabled(){ return $this->face_signin_enabled() && (bool)$this->evidence_settings()['photo']; }

    private function attempts_table(){ global $wpdb; return $wpdb->prefix.'ews_signin_attempts'; }

    private function ensure_evidence_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->attempts_table()} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            work_date DATE NOT NULL,
            event_type VARCHAR(20) NOT NULL,
            result VARCHAR(20) NOT NULL,
            reason VARCHAR(40) NOT NULL DEFAULT '',
            detail VARCHAR(255) NULL,
            attempted_at DATETIME NOT NULL,
            phone_time DATETIME NULL,
            clock_skew INT NULL,
            time_sure TINYINT(1) NOT NULL DEFAULT 1,
            reported_at DATETIME NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            accuracy FLOAT NULL,
            distance_meters FLOAT NULL,
            location_status VARCHAR(20) NULL,
            ip_address VARCHAR(64) NULL,
            device VARCHAR(190) NULL,
            source VARCHAR(20) NOT NULL DEFAULT 'app',
            photo_key VARCHAR(64) NULL,
            PRIMARY KEY  (id),
            KEY employee_date (employee_id,work_date),
            KEY attempted_at (attempted_at),
            KEY result (result)
        ) $c;");
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->time_logs))===$this->time_logs){
            $have=(array)$wpdb->get_col("SHOW COLUMNS FROM {$this->time_logs}",0);
            if(!in_array('photo_key',$have,true))$wpdb->query("ALTER TABLE {$this->time_logs} ADD COLUMN photo_key VARCHAR(64) NULL");
        }
    }

    private function ensure_evidence_schema_once(){
        static $done=false;
        if($done||$this->ews_schema_is_current())return;
        $done=true;$this->ensure_evidence_schema();
    }

    /* ------------------------------------------------------------------ keeping attempts */

    private function evidence_client_ip(){
        return isset($_SERVER['REMOTE_ADDR'])?substr(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])),0,64):'';
    }

    private function evidence_device(){
        return DeviceLabel::from(isset($_SERVER['HTTP_USER_AGENT'])?sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])):'');
    }

    /**
     * Keeps one attempt. $a: event_type, result (refused | face_ok | face_failed | not_reached), reason,
     * detail, and optionally latitude, longitude, accuracy, distance_meters, location_status, source,
     * photo_key, attempted_at, phone_time, clock_skew, time_sure, reported_at.
     * @return int the attempt's id (0: not kept)
     */
    private function evidence_keep($emp,array $a){
        return $this->services()->evidence()->keep(is_object($emp)?$emp:null,$a,get_current_user_id(),$this->evidence_client_ip(),$this->evidence_device());
    }

    /** A Sign In / Out the app refused (record_time_event). */
    private function evidence_refused($type,$cmd,$r,$message){
        if(!$this->attempts_enabled())return;
        $emp=$this->current_employee();
        if(!$emp)return;
        $d=(array)$r->details;
        $this->evidence_keep($emp,[
            'event_type'=>$type,'result'=>'refused','reason'=>(string)$r->code,'detail'=>wp_strip_all_tags((string)$message),
            'latitude'=>$cmd->latitude,'longitude'=>$cmd->longitude,'accuracy'=>$cmd->accuracy,
            'distance_meters'=>isset($d['distance'])&&is_numeric($d['distance'])?(float)$d['distance']:null,
            'location_status'=>$r->code==='outside_location'||$r->code==='outside_kiosk'?'outside':null,
            'source'=>$cmd->qrKiosk?'qr':'app','photo_key'=>$this->face_photo_take(),
        ]);
    }

    /** Tells the employee's department managers (the administrators when there is none) that the day's refusals reached N (EvidenceService decides when). */
    private function evidence_alert_send($emp,$day,$n){
        global $wpdb;
        $users=[];
        foreach(get_users(['capability'=>'ews_manage_time','fields'=>['ID']]) as $u){
            $m=$wpdb->get_row($wpdb->prepare("SELECT department_id FROM {$this->employees} WHERE wp_user_id=%d LIMIT 1",(int)$u->ID));
            if($m && (int)$m->department_id && (int)$m->department_id===(int)($emp->department_id??0))$users[]=(int)$u->ID;
        }
        if(!$users)foreach(get_users(['capability'=>'manage_options','fields'=>['ID']]) as $u)$users[]=(int)$u->ID;
        $url=add_query_arg(['page'=>'ews31-time-report','timeline'=>(int)$emp->id,'tdate'=>$day],admin_url('admin.php'));
        foreach(array_unique($users) as $uid){
            if($uid===(int)($emp->wp_user_id??0))continue;
            /* translators: 1: employee, 2: number of refused attempts */
            $this->notify($uid,'attendance',__('Sign In refused several times','workforce-one'),sprintf(__('%1$s was refused %2$d times today. See what happened.','workforce-one'),(string)$emp->name,$n),['url'=>$url]);
        }
    }

    /* ------------------------------------------------------------------ attempts that never reached us */

    /** Sent by the app the next time it opens (templates/pwa/footer.php): a Sign In / Out pressed with no network. */
    public function evidence_offline_report(){
        if(!is_user_logged_in())wp_send_json_error(['reason'=>'login'],401);
        check_ajax_referer('ews_signin_offline','_wpnonce');
        if(!$this->attempts_enabled())wp_send_json_success(['kept'=>false,'reason'=>'off']);
        // Only the account that pressed the button (3.31.118): a note left by another account on a shared phone, or saved before
        // 3.31.118 with no account, is dropped. A 200, so the phone clears it.
        if(!SignInEvidence::noteBelongs($_POST['user']??null,get_current_user_id()))wp_send_json_success(['kept'=>false,'reason'=>'account']);
        $emp=$this->current_employee();
        if(!$emp)wp_send_json_success(['kept'=>false,'reason'=>'employee']);
        $type=sanitize_key($_POST['event_type']??'');
        if(!in_array($type,['sign_in','sign_out'],true))wp_send_json_error(['reason'=>'type'],400);
        $num=function($k){return isset($_POST[$k])&&is_numeric($_POST[$k])?$_POST[$k]+0:null;};
        $now=current_time('timestamp');$offset=$now-time();   // local time = Unix + offset
        $t=SignInEvidence::offlineTime((int)$num('phone_ms'),(int)$num('server_ms'),(int)$num('elapsed_ms'),time());
        if($t['at']<time()-7*DAY_IN_SECONDS)wp_send_json_success(['kept'=>false,'reason'=>'old']);
        $at=gmdate('Y-m-d H:i:s',$t['at']+$offset);
        // It did reach us after all (the answer was lost): a record or a refusal of the same kind is there.
        $from=gmdate('Y-m-d H:i:s',$t['at']+$offset-900);$to=gmdate('Y-m-d H:i:s',$t['at']+$offset+900);
        $kinds=$type==='sign_in'?['sign_in','late_sign_in']:['sign_out'];
        // The checks and the write: EvidenceService (reached / at most 5 a day / kept).
        $r=$this->services()->evidence()->offline($emp,$type,$from,$to,$kinds,gmdate('Y-m-d 00:00:00',$now),[
            'event_type'=>$type,'result'=>'not_reached','reason'=>'no_network',
            'attempted_at'=>$at,'phone_time'=>$t['phone']>0?gmdate('Y-m-d H:i:s',$t['phone']+$offset):null,'clock_skew'=>(int)$t['skew'],'time_sure'=>$t['sure']?1:0,
            'reported_at'=>current_time('mysql'),'latitude'=>$num('latitude'),'longitude'=>$num('longitude'),'accuracy'=>$num('accuracy'),'source'=>'offline',
        ],get_current_user_id(),$this->evidence_client_ip(),$this->evidence_device());
        if($r==='reached')wp_send_json_success(['kept'=>false,'reason'=>'reached']);
        if($r==='limit')wp_send_json_success(['kept'=>false,'reason'=>'limit']);
        wp_send_json_success(['kept'=>$r==='kept']);
    }

    /** What the app page needs to note an attempt and send it later (empty when off). @return array<string,mixed> */
    private function evidence_app_config(){
        if(!$this->attempts_enabled() || !is_user_logged_in())return [];
        return ['url'=>admin_url('admin-post.php'),'nonce'=>wp_create_nonce('ews_signin_offline'),'serverMs'=>(int)round(microtime(true)*1000),'user'=>get_current_user_id()];
    }

    /* ------------------------------------------------------------------ face photo */

    /** Saves the photo sent with a face check. @param mixed $data a data: URL (JPEG / WebP) @return string key ('' = none) */
    private function face_photo_save($data){
        if(!$this->face_photo_enabled() || !is_string($data))return '';
        if(!preg_match('#^data:image/(jpeg|webp);base64,([A-Za-z0-9+/=]+)$#',$data,$m))return '';
        if(strlen($m[2])>400000)return '';   // about 300 KB: a small frame, never a full photo
        $bin=base64_decode($m[2],true);
        if($bin===false || strlen($bin)<100)return '';
        $info=@getimagesizefromstring($bin); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if(!$info || !in_array($info[2],[IMAGETYPE_JPEG,defined('IMAGETYPE_WEBP')?IMAGETYPE_WEBP:-1],true))return '';
        $key=wp_generate_password(32,false).($m[1]==='webp'?'.webp':'.jpg');
        if(@file_put_contents($this->private_files_dir('workforce-one-face-photos').'/'.$key,$bin)===false)return ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return $key;
    }

    /** Kept with the face token: the Sign In / Out that uses the token takes it (face_photo_take). */
    private function face_photo_hold($key){
        $uid=get_current_user_id();
        if(!$uid)return;
        $old=get_transient('ews_face_photo_'.$uid);
        if(is_string($old) && $old!=='' && $old!==$key)$this->private_files_delete('workforce-one-face-photos',$old);
        if($key!=='')set_transient('ews_face_photo_'.$uid,$key,120);
    }

    /** The photo of this user's last face check, once. @return ?string */
    private function face_photo_take(){
        $uid=get_current_user_id();
        $key=$uid?get_transient('ews_face_photo_'.$uid):false;
        if(!is_string($key) || $key==='')return null;
        delete_transient('ews_face_photo_'.$uid);
        return $key;
    }

    /** May the current user see this employee's photos? HR (Manage Settings) always; the department's managers when allowed. */
    private function face_photo_may_view($employee_id){
        if(current_user_can('manage_options') || $this->can('ews_manage_settings'))return true;
        if($this->evidence_settings()['photo_viewers']==='hr')return false;
        return $this->can('ews_manage_time') && $this->time_report_may_manage((int)$employee_id);
    }

    private function face_photo_url($kind,$id,$full=false){
        $args=['action'=>'ews_face_photo','kind'=>$kind,'id'=>(int)$id];
        if($full)$args['full']=1;
        return wp_nonce_url(add_query_arg($args,admin_url('admin-post.php')),'ews_face_photo_'.$kind.'_'.(int)$id);
    }

    /** A Sign In photo (kind log) or a refused face check's photo (kind attempt). Never stored by the browser. */
    public function face_photo_serve(){
        if(!is_user_logged_in())auth_redirect();
        $kind=sanitize_key($_GET['kind']??'');$id=absint($_GET['id']??0);
        check_admin_referer('ews_face_photo_'.$kind.'_'.$id);
        $row=$this->services()->evidence()->photo($kind,$id);
        if(!$row || !$this->face_photo_may_view((int)$row->employee_id))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        $key=(string)$row->photo_key;
        if(!preg_match('/^[A-Za-z0-9]{32}\.(jpg|webp)$/',$key))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $path=$this->private_files_path('workforce-one-face-photos',$key);
        if(!is_readable($path))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        if(!empty($_GET['full'])){
            $name=(string)$this->people_name((int)$row->employee_id);
            $this->audit('face_photo_viewed','employee',(int)$row->employee_id,$name.' / '.$row->at.' / '.$kind.' #'.$id);
        }
        \WorkforceOne\Support\Download::image((string)file_get_contents($path),str_ends_with($key,'.webp')?'image/webp':'image/jpeg','face-'.$kind.'-'.$id.substr($key,-4)); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /* ------------------------------------------------------------------ cleanup */

    /** Daily (ews_privacy_cleanup): attempts and photos older than their days are deleted. */
    public function evidence_cleanup(){
        $this->services()->evidence()->cleanup(function(){return (int)current_time('timestamp');},function(){return time();});
    }

    /** The photo files nothing points to, older than the Unix time, are removed (EvidenceService asks last). */
    private function evidence_sweep_orphans($limit){
        foreach(array_unique([$this->private_files_dir('workforce-one-face-photos'),$this->private_files_legacy_dir('workforce-one-face-photos')]) as $dir){
            foreach((array)glob($dir.'/*.{jpg,webp}',GLOB_BRACE) as $f){if(is_file($f) && filemtime($f)<$limit)wp_delete_file($f);}
        }
    }

    /* ------------------------------------------------------------------ shown in the report */

    /** Why an attempt was refused, in words (wp-admin is in English). */
    private function evidence_reason_label($a){
        $dist=$a->distance_meters!==null?' ('.$this->format_distance((float)$a->distance_meters).' away)':'';
        return EvidenceRules::reasonLabel((string)$a->reason,$dist);
    }

    /** One attempt as a report row. @return array<string,mixed> */
    private function evidence_row($a){
        $out=[
            'kind'=>'attempt','id'=>(int)$a->id,'employee_id'=>(int)$a->employee_id,'work_date'=>(string)$a->work_date,'name'=>(string)($a->name??''),'domain'=>(string)($a->domain_name??''),
            'event'=>$a->event_type==='face'?'Face check':$this->time_event_label((string)$a->event_type),'result'=>(string)$a->result,'reason'=>$this->evidence_reason_label($a),
            'time'=>date_i18n('Y-m-d h:i A',strtotime((string)$a->attempted_at)),'time_short'=>date_i18n('H:i',strtotime((string)$a->attempted_at)),
            'location'=>$a->location_status==='outside'?'Outside'.($a->distance_meters!==null?' · '.$this->format_distance((float)$a->distance_meters):''):($a->latitude!==null?'Shared':'—'),
            'accuracy'=>$a->accuracy!==null?round((float)$a->accuracy,1).' m':'',
            'device'=>(string)$a->device,'ip'=>(string)$a->ip_address!==''?(string)$a->ip_address:'—','source'=>(string)$a->source,
            'late_report'=>$a->result==='not_reached'?[
                'reported'=>$a->reported_at?date_i18n('Y-m-d h:i A',strtotime((string)$a->reported_at)):'',
                'skew'=>(int)$a->clock_skew,'sure'=>(int)$a->time_sure===1,
                'phone'=>$a->phone_time?date_i18n('h:i A',strtotime((string)$a->phone_time)):'',
            ]:null,
            'photo'=>$a->photo_key&&$this->face_photo_may_view((int)$a->employee_id)?$this->face_photo_url('attempt',(int)$a->id):'',
            'photo_full'=>$a->photo_key&&$this->face_photo_may_view((int)$a->employee_id)?$this->face_photo_url('attempt',(int)$a->id,true):'',
        ];
        return $out;
    }

    /** "Record it manually": the add form with this employee, event and time filled in. */
    private function evidence_record_url($a,$range_url){
        $at=strtotime((string)$a->attempted_at);
        return add_query_arg(['add_time'=>1,'prefill_employee'=>(int)$a->employee_id,'prefill_type'=>$a->event_type==='sign_out'?'sign_out':'sign_in','prefill_at'=>date('Y-m-d\TH:i',$at),'prefill_date'=>(string)$a->work_date],$range_url);
    }

    /** One employee's day, everything in order: records, refusals, face checks. @return array<string,mixed>|null */
    private function evidence_timeline($employee_id,$day){
        if(!$this->time_report_may_manage($employee_id) || !$this->valid_date($day))return null;
        $emp=$this->people_employee((int)$employee_id);
        if(!$emp)return null;
        $items=[];
        $evidence=$this->services()->evidence();
        foreach($evidence->dayLogs((int)$employee_id,$day) as $l){
            $items[]=['at'=>(string)$l->event_at,'kind'=>'log','title'=>$this->time_event_label((string)$l->event_type).' recorded',
                'detail'=>trim(($l->location_status?ucfirst((string)$l->location_status):'').($l->distance_meters!==null?' · '.$this->format_distance((float)$l->distance_meters):'').' · '.$this->time_source_label((string)($l->source??'')),' ·'),
                'device'=>'','ip'=>(string)($l->ip_address??''),'photo'=>!empty($l->photo_key)&&$this->face_photo_may_view((int)$employee_id)?$this->face_photo_url('log',(int)$l->id,true):''];
        }
        if($evidence->tableExists()){
            foreach($evidence->dayAttempts((int)$employee_id,$day) as $a){
                $r=$this->evidence_row($a);
                $title=$a->result==='face_ok'?'Face check matched':($a->result==='not_reached'?$r['event'].' pressed, did not reach the server':$r['event'].' refused: '.$r['reason']);
                $detail=$a->result==='not_reached'?'Reported by the phone '.($r['late_report']['reported']??'').(abs((int)$a->clock_skew)>120?' · the phone\'s clock was off by '.round(abs((int)$a->clock_skew)/60).' min':'').((int)$a->time_sure!==1?' · time not certain':''):($a->accuracy!==null?'GPS accuracy '.round((float)$a->accuracy).' m':'');
                $items[]=['at'=>(string)$a->attempted_at,'kind'=>$a->result,'title'=>$title,'detail'=>$detail,'device'=>(string)$a->device,'ip'=>(string)$a->ip_address,'photo'=>$r['photo_full']];
            }
        }
        // In time order; within the same second an attempt comes before a record (seconds are all we keep).
        usort($items,function($x,$y){$c=strcmp($x['at'],$y['at']);return $c!==0?$c:(($x['kind']==='log')<=>($y['kind']==='log'));});
        return ['employee'=>$emp,'day'=>$day,'items'=>$items];
    }
}
