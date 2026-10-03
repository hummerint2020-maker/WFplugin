<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Attendance\LocationAssessment;
use WorkforceOne\Presence\QrCode;

/**
 * Presence: kiosks (a screen at a work location showing a rotating QR), QR Sign In, and presence
 * verification (a manager asks an employee to scan the QR of a location within the time set on Feature Configuration, 3 minutes by default;
 * since 3.31.45 the employee's device location must also be inside that location, as for QR Sign In).
 * QR rules: src/Presence/QrCode.php; pages: templates/admin/presence-kiosks.php, templates/kiosk.php,
 * templates/app/presence.php.
 */

trait EWS_Presence_Trait {
    private function presence_qr_enabled(){ return (bool)$this->option('ews_presence_qr_signin'); }
    private function presence_verification_enabled(){ return (bool)$this->option('ews_presence_verification'); }
    private function presence_tables(){ global $wpdb; return [$wpdb->prefix.'ews_kiosks',$wpdb->prefix.'ews_presence_verifications']; }
    private function ensure_presence_schema(){
        if($this->ews_schema_is_current())return;
        global $wpdb;
        $c=$wpdb->get_charset_collate();
        list($k,$v)=$this->presence_tables();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $k (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,location_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',credential_hash CHAR(64) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY credential_hash(credential_hash),KEY location_id(location_id),KEY status(status)) $c;");
        dbDelta("CREATE TABLE $v (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,requested_by BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',expires_at DATETIME NOT NULL,verified_at DATETIME NULL,kiosk_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY employee_status(employee_id,status),KEY expires_at(expires_at),KEY location_id(location_id),KEY requested_by(requested_by)) $c;");
    }
    /*
     * The kiosks table stores only sha256(secret). The raw secret is kept in a
     * non-autoloaded option and is returned only when it matches the stored hash
     * of an active kiosk, so disabling a kiosk (or tampering with either value)
     * immediately invalidates its display URL and its QR signatures.
     */
    private function presence_secret($kiosk_id){
        global $wpdb; list($kt)= $this->presence_tables();
        $kiosk_id=absint($kiosk_id); if(!$kiosk_id)return '';
        $hash=(string)$wpdb->get_var($wpdb->prepare("SELECT credential_hash FROM $kt WHERE id=%d AND status='active' LIMIT 1",$kiosk_id));
        $secret=(string)get_option('ews_presence_kiosk_key_'.$kiosk_id,'');
        if($hash===''||$secret===''||!hash_equals($hash,hash('sha256',$secret)))return '';
        return $secret;
    }
    private function presence_qr_slot_seconds(){ return QrCode::SLOT_SECONDS; }
    private function presence_kiosk_authorized($id){
        $key=sanitize_text_field(wp_unslash($_GET['kiosk_key']??''));
        $secret=$this->presence_secret($id);
        return ($secret!==''&&$key!==''&&hash_equals($secret,$key))?$secret:'';
    }
    private function presence_location($location_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->locations} WHERE id=%d AND active=1 LIMIT 1",absint($location_id)));
    }
    private function presence_qr_payload($kiosk_id,$secret,$slot=null){
        return QrCode::payload((int)$kiosk_id,$slot===null?QrCode::slot(time()):(int)$slot,$secret.AUTH_KEY);
    }
    /** A scanned kiosk code (src/Presence/QrCode.php): the active kiosk, or why it is refused. @return object|WP_Error */
    private function presence_validate_qr($raw){
        $qr=QrCode::parse(sanitize_text_field($raw));
        if(!$qr) return new WP_Error('invalid_qr',__('Invalid QR code.','workforce-one'));
        $secret=$this->presence_secret($qr['kiosk_id']); if($secret==='') return new WP_Error('inactive_kiosk',__('This kiosk is inactive.','workforce-one'));
        if(!QrCode::fresh($qr['slot'],QrCode::slot(time()))) return new WP_Error('expired_qr',__('This QR code has expired. Please scan the current code.','workforce-one'));
        if(!QrCode::signatureValid($qr,$secret.AUTH_KEY)) return new WP_Error('invalid_qr',__('Invalid QR signature.','workforce-one'));
        global $wpdb; list($kt)= $this->presence_tables();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $kt WHERE id=%d AND status='active' LIMIT 1",$qr['kiosk_id']));
        if(!$row)return new WP_Error('inactive_kiosk',__('This kiosk is inactive.','workforce-one'));
        return $row;
    }
    private function presence_employee_for_user(){ return $this->current_employee(); }

    /** wp-admin → Presence Kiosks: create a kiosk for a work location, open or disable it. */
    public function admin_presence_kiosks(){
        if(!$this->can('ews_manage_locations')) wp_die(__('Access denied','workforce-one'));
        global $wpdb; list($kt)= $this->presence_tables();
        $rows=(array)$wpdb->get_results("SELECT k.*,l.name location_name FROM $kt k LEFT JOIN {$this->locations} l ON l.id=k.location_id ORDER BY k.id DESC");
        foreach($rows as $r)$r->display_url=$r->status==='active'?add_query_arg(['ews_kiosk'=>(int)$r->id,'kiosk_key'=>get_option('ews_presence_kiosk_key_'.$r->id,'')],home_url('/')):'';
        echo $this->render_template('admin/presence-kiosks',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'locations'=>(array)$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC"),'rows'=>$rows,
            'notice'=>isset($_GET['presence_saved'])?'Kiosk saved successfully.':(isset($_GET['presence_revoked'])?'Kiosk disabled successfully.':''),
        ]);
    }

    public function presence_kiosk_save(){
        if(!$this->can('ews_manage_locations'))wp_die(__('Access denied','workforce-one')); check_admin_referer('ews_presence_kiosk_save');
        $name=sanitize_text_field(wp_unslash($_POST['name']??''));$location_id=absint($_POST['location_id']??0); if($name===''||!$location_id)wp_die(__('Invalid kiosk.','workforce-one'));
        $loc=$this->presence_location($location_id); if(!$loc)wp_die(__('Invalid work location.','workforce-one'));
        global $wpdb; list($kt)=$this->presence_tables();
        $secret=bin2hex(random_bytes(32));
        $hash=hash('sha256',$secret);
        $ok=$wpdb->insert($kt,['location_id'=>$location_id,'name'=>$name,'status'=>'active','credential_hash'=>$hash,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%s','%s','%s','%s','%s']);
        if($ok===false)wp_die(__('Could not create kiosk.','workforce-one'));
        $id=(int)$wpdb->insert_id; update_option('ews_presence_kiosk_key_'.$id,$secret,false);
        $this->audit('kiosk_created','kiosk',$id,$name.' / location='.$loc->name);
        wp_safe_redirect(admin_url('admin.php?page=ews31-presence-kiosks&presence_saved=1'));exit;
    }
    public function presence_kiosk_revoke(){
        if(!$this->can('ews_manage_locations'))wp_die(__('Access denied','workforce-one')); check_admin_referer('ews_presence_kiosk_revoke'); $id=absint($_POST['kiosk_id']??0);global $wpdb;list($kt)=$this->presence_tables();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $kt WHERE id=%d",$id));if(!$row)wp_die(__('Kiosk not found.','workforce-one'));$wpdb->update($kt,['status'=>'disabled','updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);$this->audit('kiosk_disabled','kiosk',$id,$row->name);wp_safe_redirect(admin_url('admin.php?page=ews31-presence-kiosks&presence_revoked=1'));exit;
    }
    public function presence_request(){
        if(!$this->can('ews_manage_attendance'))wp_die(__('Access denied','workforce-one')); check_admin_referer('ews_presence_request');
        $eid=absint($_POST['employee_id']??0);$location_id=absint($_POST['location_id']??0);if(!$eid||!$location_id)wp_die(__('Invalid employee or location.','workforce-one'));
        if(method_exists($this,'department_scope_allows_employee')&&!$this->department_scope_allows_employee($eid))wp_die(__('You cannot manage presence outside your Department.','workforce-one'));
        global $wpdb;$emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$eid));$loc=$this->presence_location($location_id);if(!$emp||!$loc)wp_die(__('Employee or location not found.','workforce-one'));
        list(,$vt)=$this->presence_tables();$minutes=\WorkforceOne\Settings\FeatureSettings::presenceMinutes($this->option('ews_presence_request_minutes'));$expires=gmdate('Y-m-d H:i:s',time()+$minutes*60);$ok=$wpdb->insert($vt,['employee_id'=>$eid,'location_id'=>$location_id,'requested_by'=>get_current_user_id(),'status'=>'pending','expires_at'=>$expires,'created_at'=>current_time('mysql')],['%d','%d','%d','%s','%s','%s']);if($ok===false)wp_die(__('Could not create presence verification request.','workforce-one'));$id=(int)$wpdb->insert_id;
        $this->audit('presence_verification_requested','presence_verification',$id,$emp->name.' / '.$loc->name.' / expires='.$expires);
        if(!empty($emp->wp_user_id)){
            $msg='Please verify your presence at <strong>'.esc_html($loc->name).'</strong>. The request expires in '.$minutes.' '.($minutes===1?'minute':'minutes').'.';$url=add_query_arg(['ews_view'=>'presence','presence_request'=>$id],$this->app_home_url());$this->notify((int)$emp->wp_user_id,'presence','Presence Verification',$msg,['entity_id'=>$id,'url'=>$url]);
        }
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=ews31-requests'));exit;
    }
    /** Messages of the presence page, by the code in its link (never text taken from the link). */
    private function presence_error_message($code){
        $m=[
            'processed'=>__('This presence request has already been processed.','workforce-one'),
            'expired'=>__('This presence request has expired.','workforce-one'),
            'invalid_qr'=>__('Invalid QR code.','workforce-one'),
            'expired_qr'=>__('This QR code has expired. Please scan the current code.','workforce-one'),
            'inactive_kiosk'=>__('This kiosk is inactive.','workforce-one'),
            'wrong_location'=>__('This QR belongs to a different work location.','workforce-one'),
            'changed'=>__('The request changed before it could be verified.','workforce-one'),
            LocationAssessment::QR_LOCATION_REQUIRED=>__('Location access is required to verify your presence. Please allow Location Services and try again.','workforce-one'),
            LocationAssessment::QR_OUTSIDE=>__('You must be at the requested work location to verify your presence.','workforce-one'),
            LocationAssessment::QR_KIOSK_NO_COORDS=>__('This work location has no coordinates configured. Please contact your administrator.','workforce-one'),
        ];
        return $m[sanitize_key((string)$code)]??'';
    }

    public function presence_verify(){
        if(!is_user_logged_in())wp_die(__('Please log in.','workforce-one')); check_admin_referer('ews_presence_verify');
        $id=absint($_POST['request_id']??0);$raw=wp_unslash($_POST['qr_payload']??'');$emp=$this->presence_employee_for_user();if(!$emp)wp_die(__('Your account is not linked to an active employee.','workforce-one'));
        global $wpdb;list(,$vt)=$this->presence_tables();$req=$wpdb->get_row($wpdb->prepare("SELECT * FROM $vt WHERE id=%d AND employee_id=%d LIMIT 1",$id,(int)$emp->id));if(!$req)wp_die(__('Presence request not found.','workforce-one'));
        $back=function($args)use($id){$this->redirect(array_merge(['ews_view'=>'presence','presence_request'=>$id],$args));};
        if($req->status!=='pending'){$back(['presence_error'=>'processed']);return;}
        if(strtotime($req->expires_at)<time()){$wpdb->update($vt,['status'=>'expired'],['id'=>$id,'status'=>'pending'],['%s'],['%d','%s']);$this->audit('presence_verification_expired','presence_verification',$id,$emp->name);$back(['presence_error'=>'expired']);return;}
        $k=$this->presence_validate_qr($raw);if(is_wp_error($k)){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / '.$k->get_error_code());$back(['presence_error'=>$k->get_error_code()]);return;}
        if((int)$k->location_id!==(int)$req->location_id){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / wrong_location');$back(['presence_error'=>'wrong_location']);return;}
        // Presence means being there: a QR can be photographed and forwarded, so the employee's own
        // device location must also be inside the requested work location - the same rule as QR Sign In.
        [$lat,$lng,$acc,$location_ts]=$this->posted_device_location();
        [$integrity,$integrity_reason]=LocationAssessment::integrity($lat,$lng,$acc,$location_ts,time(),$lat!==null&&$lng!==null?$this->last_device_location((int)$emp->id):null);
        $loc=$this->presence_location((int)$req->location_id);
        [$where,$distance]=LocationAssessment::qrCheck($lat,$lng,$loc?$loc->latitude:null,$loc?$loc->longitude:null,(float)($loc&&$loc->radius?$loc->radius:200),$integrity,$integrity_reason);
        $at=$distance!==null?' / distance='.round($distance).'m':'';
        if($where!==null){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / '.$where.$at.($integrity_reason?' / '.$integrity_reason:''));$back(['presence_error'=>$where]);return;}
        $now=current_time('mysql');$updated=$wpdb->query($wpdb->prepare("UPDATE $vt SET status='verified',verified_at=%s,kiosk_id=%d WHERE id=%d AND status='pending'",$now,(int)$k->id,$id));if($updated!==1){$back(['presence_error'=>'changed']);return;}
        $this->audit('presence_verification_verified','presence_verification',$id,$emp->name.' / kiosk='.$k->name.$at.' / '.$integrity);$back(['presence_success'=>1]);
    }
    /** The kiosk's screen (?ews_kiosk=<id>&kiosk_key=<secret>): the rotating QR of its work location. */
    public function presence_kiosk_route(){
        if(!isset($_GET['ews_kiosk'])||isset($_GET['kiosk_payload']))return;
        $id=absint($_GET['ews_kiosk']);$secret=$this->presence_kiosk_authorized($id);if($secret==='')wp_die(__('Kiosk access denied.','workforce-one'),'Kiosk',['response'=>403]);
        global $wpdb;list($kt)=$this->presence_tables();$k=$wpdb->get_row($wpdb->prepare("SELECT k.*,l.name location_name FROM $kt k LEFT JOIN {$this->locations} l ON l.id=k.location_id WHERE k.id=%d AND k.status='active' LIMIT 1",$id));if(!$k)wp_die(__('Kiosk is inactive.','workforce-one'),'Kiosk',['response'=>403]);
        nocache_headers();header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        echo $this->render_template('kiosk',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'location_name'=>(string)$k->location_name,'payload'=>$this->presence_qr_payload($id,$secret),'slot_seconds'=>QrCode::SLOT_SECONDS,
            'payload_url'=>add_query_arg(['ews_kiosk'=>$id,'kiosk_key'=>$secret,'kiosk_payload'=>1],home_url('/')),
            'qr_lib'=>$this->plugin_url('assets/vendor/qrcode-generator-1.4.4.js'),'script'=>$this->plugin_url('assets/js/kiosk.js'),'style'=>$this->plugin_url('assets/css/kiosk.css'),'version'=>EWS_VERSION,
        ]);
        exit;
    }
    public function presence_kiosk_payload_route(){
        if(!isset($_GET['ews_kiosk'])||!isset($_GET['kiosk_payload']))return;
        $id=absint($_GET['ews_kiosk']);$secret=$this->presence_kiosk_authorized($id);if($secret===''){status_header(403);nocache_headers();header('Content-Type:text/plain; charset=utf-8');echo 'denied';exit;}
        global $wpdb;list($kt)=$this->presence_tables();
        // Throttle the heartbeat write to once a minute per kiosk.
        if(!get_transient('ews_kiosk_seen_'.$id)){set_transient('ews_kiosk_seen_'.$id,1,60);$wpdb->update($kt,['last_seen_at'=>current_time('mysql')],['id'=>$id],['%s'],['%d']);}
        nocache_headers();header('Content-Type:text/plain; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');echo $this->presence_qr_payload($id,$secret);exit;
    }
    /** Employee app → Presence Verification: scan the kiosk QR of the requested work location. */
    private function presence_content(){
        if(!$this->presence_verification_enabled())return $this->ews_empty_state(__('Presence Verification is disabled','workforce-one'),__('Please contact your administrator.','workforce-one'));
        $emp=$this->presence_employee_for_user();if(!$emp)return $this->ews_empty_state(__('Employee profile required','workforce-one'),__('Your account is not linked to an active employee.','workforce-one'));
        $id=absint($_GET['presence_request']??0);global $wpdb;list($kt,$vt)=$this->presence_tables();
        $req=$id?$wpdb->get_row($wpdb->prepare("SELECT v.*,l.name location_name,k.name kiosk_name FROM $vt v LEFT JOIN {$this->locations} l ON l.id=v.location_id LEFT JOIN $kt k ON k.id=v.kiosk_id WHERE v.id=%d AND v.employee_id=%d LIMIT 1",$id,(int)$emp->id)):null;
        if($req&&$req->status==='pending'){wp_enqueue_script('workforce-one-presence-scan');}
        return $this->render_template('app/presence',[
            'req'=>$req,'left'=>$req?max(0,strtotime($req->expires_at)-time()):0,
            'success'=>isset($_GET['presence_success']),'error'=>$this->presence_error_message($_GET['presence_error']??''),
        ]);
    }
    public function presence_qr_signin(){
        if(!$this->presence_qr_enabled())wp_die(__('Dynamic QR Sign-In is disabled.','workforce-one'));
        if(!is_user_logged_in())wp_die(__('Please log in.','workforce-one'));
        check_admin_referer('ews_presence_qr_signin');
        $emp=$this->current_employee(); if(!$emp)wp_die(__('Your account is not linked to an active employee.','workforce-one'));
        $k=$this->presence_validate_qr(wp_unslash($_POST['qr_payload']??''));
        if(is_wp_error($k)){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode($k->get_error_message())]);return;}
        $loc=$this->presence_location((int)$k->location_id);if(!$loc){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode(__('The Kiosk location is unavailable.','workforce-one'))]);return;}
        // The employee's own latitude/longitude/accuracy/location_timestamp are posted by the form and
        // validated against the kiosk location inside record_time_event().
        $this->record_time_event('sign_in',[
            'face_ok'=>$this->face_consume_token(wp_unslash($_POST['face_token']??'')),
            'qr_kiosk'=>$k,
            'qr_location'=>$loc,
        ]);
    }

}
