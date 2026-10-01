<?php
if (!defined('ABSPATH')) exit;

trait EWS_Presence_Trait {
    private function presence_qr_enabled(){ return (bool)get_option('ews_presence_qr_signin',0); }
    private function presence_verification_enabled(){ return (bool)get_option('ews_presence_verification',0); }
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
    private function presence_qr_slot_seconds(){ return 15; }
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
        $slot=$slot===null?(int)floor(time()/$this->presence_qr_slot_seconds()):(int)$slot;
        $payload='wfo1|'.$kiosk_id.'|'.$slot;
        $sig=hash_hmac('sha256',$payload,$secret.AUTH_KEY);
        return $payload.'|'.$sig;
    }
    private function presence_validate_qr($raw){
        $raw=sanitize_text_field($raw);
        $parts=explode('|',$raw);
        if(count($parts)!==4 || $parts[0]!=='wfo1') return new WP_Error('invalid_qr',__('Invalid QR code.','workforce-one'));
        $kid=absint($parts[1]); $slot=(int)$parts[2]; $sig=$parts[3];
        if(!$kid || !preg_match('/^[a-f0-9]{64}$/',$sig)) return new WP_Error('invalid_qr',__('Invalid QR code.','workforce-one'));
        $secret=$this->presence_secret($kid); if($secret==='') return new WP_Error('inactive_kiosk',__('This kiosk is inactive.','workforce-one'));
        $now=(int)floor(time()/$this->presence_qr_slot_seconds());
        if(abs($now-$slot)>1) return new WP_Error('expired_qr',__('This QR code has expired. Please scan the current code.','workforce-one'));
        $expected=hash_hmac('sha256','wfo1|'.$kid.'|'.$slot,$secret.AUTH_KEY);
        if(!hash_equals($expected,$sig)) return new WP_Error('invalid_qr',__('Invalid QR signature.','workforce-one'));
        global $wpdb; list($kt)= $this->presence_tables();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $kt WHERE id=%d AND status='active' LIMIT 1",$kid));
        if(!$row)return new WP_Error('inactive_kiosk',__('This kiosk is inactive.','workforce-one'));
        return $row;
    }
    private function presence_employee_for_user(){ return $this->current_employee(); }

    public function admin_presence_kiosks(){
        if(!$this->can('ews_manage_locations')) wp_die(__('Access denied','workforce-one'));
        global $wpdb; list($kt)= $this->presence_tables();
        $locations=$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC");
        $rows=$wpdb->get_results("SELECT k.*,l.name location_name FROM $kt k LEFT JOIN {$this->locations} l ON l.id=k.location_id ORDER BY k.id DESC");
        echo '<div class="wrap"><h1>Presence Kiosks</h1>';
        if(isset($_GET['presence_saved']))echo '<div class="notice notice-success is-dismissible"><p>Kiosk saved successfully.</p></div>';
        if(isset($_GET['presence_revoked']))echo '<div class="notice notice-success is-dismissible"><p>Kiosk disabled successfully.</p></div>';
        echo '<div class="wfo-features-shell" style="max-width:1100px;margin-top:18px"><div class="wfo-feature-section"><h2 style="margin-top:0">Create Kiosk</h2><p style="color:#667085">A Kiosk only displays the rotating QR code. It never contains employee credentials.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_presence_kiosk_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_presence_kiosk_save"><table class="form-table"><tr><th>Kiosk Name</th><td><input class="regular-text" name="name" required placeholder="Cairo HQ Reception"></td></tr><tr><th>Work Location</th><td><select name="location_id" required><option value="">Select location</option>'; foreach($locations as $l)echo '<option value="'.(int)$l->id.'">'.esc_html($l->name).'</option>'; echo '</select></td></tr></table><p><button class="button button-primary">Create Kiosk</button></p></form></div>';
        echo '<div class="wfo-feature-section"><h2 style="margin-top:0">Configured Kiosks</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Location</th><th>Status</th><th>Last Seen</th><th>Display</th><th>Action</th></tr></thead><tbody>';
        if(!$rows)echo '<tr><td colspan="6">No kiosks configured.</td></tr>';
        foreach($rows as $r){
            $display=add_query_arg(['ews_kiosk'=>(int)$r->id,'kiosk_key'=>get_option('ews_presence_kiosk_key_'.$r->id,'')],home_url('/'));
            echo '<tr><td><strong>'.esc_html($r->name).'</strong></td><td>'.esc_html($r->location_name?:'—').'</td><td>'.esc_html(ucfirst($r->status)).'</td><td>'.esc_html($r->last_seen_at?:'—').'</td><td>'.($r->status==='active'?'<a class="button button-small" target="_blank" rel="noopener" href="'.esc_url($display).'">Open Kiosk</a>':'—').'</td><td>';
            if($r->status==='active')echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('ews_presence_kiosk_revoke','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_presence_kiosk_revoke"><input type="hidden" name="kiosk_id" value="'.(int)$r->id.'"><button class="button button-small">Disable</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div></div>';
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
        list(,$vt)=$this->presence_tables();$expires=gmdate('Y-m-d H:i:s',time()+180);$ok=$wpdb->insert($vt,['employee_id'=>$eid,'location_id'=>$location_id,'requested_by'=>get_current_user_id(),'status'=>'pending','expires_at'=>$expires,'created_at'=>current_time('mysql')],['%d','%d','%d','%s','%s','%s']);if($ok===false)wp_die(__('Could not create presence verification request.','workforce-one'));$id=(int)$wpdb->insert_id;
        $this->audit('presence_verification_requested','presence_verification',$id,$emp->name.' / '.$loc->name.' / expires='.$expires);
        if(!empty($emp->wp_user_id)){
            $msg='Please verify your presence at <strong>'.esc_html($loc->name).'</strong>. The request expires in 3 minutes.';$url=add_query_arg(['ews_view'=>'presence','presence_request'=>$id],home_url('/'));$this->notify_user((int)$emp->wp_user_id,'Presence Verification',$msg,'presence',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Presence Verification',$msg,'presence',$id,$url);
        }
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=ews31-requests'));exit;
    }
    public function presence_verify(){
        if(!is_user_logged_in())wp_die(__('Please log in.','workforce-one')); check_admin_referer('ews_presence_verify');
        $id=absint($_POST['request_id']??0);$raw=wp_unslash($_POST['qr_payload']??'');$emp=$this->presence_employee_for_user();if(!$emp)wp_die(__('Your account is not linked to an active employee.','workforce-one'));
        global $wpdb;list(,$vt)=$this->presence_tables();$req=$wpdb->get_row($wpdb->prepare("SELECT * FROM $vt WHERE id=%d AND employee_id=%d LIMIT 1",$id,(int)$emp->id));if(!$req)wp_die(__('Presence request not found.','workforce-one'));
        if($req->status!=='pending'){ $this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode(__('This presence request has already been processed.','workforce-one'))]);return; }
        if(strtotime($req->expires_at)<time()){$wpdb->update($vt,['status'=>'expired'],['id'=>$id,'status'=>'pending'],['%s'],['%d','%s']);$this->audit('presence_verification_expired','presence_verification',$id,$emp->name);$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode(__('This presence request has expired.','workforce-one'))]);return;}
        $k=$this->presence_validate_qr($raw);if(is_wp_error($k)){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / '.$k->get_error_code());$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode($k->get_error_message())]);return;}
        if((int)$k->location_id!==(int)$req->location_id){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / wrong_location');$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode(__('This QR belongs to a different work location.','workforce-one'))]);return;}
        $now=current_time('mysql');$updated=$wpdb->query($wpdb->prepare("UPDATE $vt SET status='verified',verified_at=%s,kiosk_id=%d WHERE id=%d AND status='pending'",$now,(int)$k->id,$id));if($updated!==1){$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode(__('The request changed before it could be verified.','workforce-one'))]);return;}
        $this->audit('presence_verification_verified','presence_verification',$id,$emp->name.' / kiosk='.$k->name);$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_success'=>1]);
    }
    public function presence_kiosk_route(){
        if(!isset($_GET['ews_kiosk'])||isset($_GET['kiosk_payload']))return;
        $id=absint($_GET['ews_kiosk']);$secret=$this->presence_kiosk_authorized($id);if($secret==='')wp_die(__('Kiosk access denied.','workforce-one'),'Kiosk',['response'=>403]);
        global $wpdb;list($kt)=$this->presence_tables();$k=$wpdb->get_row($wpdb->prepare("SELECT k.*,l.name location_name FROM $kt k LEFT JOIN {$this->locations} l ON l.id=k.location_id WHERE k.id=%d AND k.status='active' LIMIT 1",$id));if(!$k)wp_die(__('Kiosk is inactive.','workforce-one'),'Kiosk',['response'=>403]);
        $payload=$this->presence_qr_payload($id,$secret);$slot_seconds=$this->presence_qr_slot_seconds();
        $payload_url=add_query_arg(['ews_kiosk'=>$id,'kiosk_key'=>$secret,'kiosk_payload'=>1],home_url('/'));
        $qr_lib=plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').'assets/vendor/qrcode-generator-1.4.4.js';
        nocache_headers();header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex, nofollow');header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html '.get_language_attributes().'><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Workforce One Kiosk</title><style>html,body{margin:0;height:100%;background:#f7f8fb;font-family:Arial,sans-serif}body{display:flex;align-items:center;justify-content:center}.k{width:min(760px,92vw);text-align:center;background:#fff;border:1px solid #e4e7ec;border-radius:24px;padding:34px;box-shadow:0 12px 40px rgba(16,24,40,.08)}.brand{font-weight:800;font-size:24px;color:#101828}.sub{color:#667085;margin:8px 0 24px}.qr{width:min(420px,75vw);height:min(420px,75vw);margin:0 auto}.qr svg{width:100%;height:100%;display:block}.timer{font-size:18px;font-weight:700;margin-top:18px;color:#344054}.loc{margin-top:8px;color:#667085}.offline{display:none;color:#b42318;margin-top:10px}</style></head><body><main class="k"><div class="brand">WORKFORCE ONE</div><div class="sub">'.esc_html__('Scan to continue','workforce-one').'</div><div id="qr" class="qr" role="img" aria-label="'.esc_attr__('Dynamic Workforce One QR','workforce-one').'"></div><div id="timer" class="timer"></div><div class="loc">'.esc_html($k->location_name).'</div><div id="offline" class="offline">'.esc_html__('Connection unavailable. Please check this kiosk connection.','workforce-one').'</div></main>';
        echo '<script src="'.esc_url($qr_lib).'"></script>';
        echo '<script>(function(){var CHG='.wp_json_encode(/* translators: %d: seconds */__('Changes in %ds','workforce-one')).',SLOT='.(int)$slot_seconds.',URL_='.wp_json_encode($payload_url).',current='.wp_json_encode($payload).',lastSlot=-1,box=document.getElementById("qr"),timer=document.getElementById("timer"),offline=document.getElementById("offline");'
            .'function draw(v){var q=qrcode(0,"M");q.addData(v);q.make();box.innerHTML=q.createSvgTag({cellSize:8,margin:2,scalable:true});}'
            .'function tick(){var now=Math.floor(Date.now()/1000),slot=Math.floor(now/SLOT);timer.textContent=CHG.replace("%d",SLOT-(now%SLOT));if(slot===lastSlot)return;lastSlot=slot;'
            .'fetch(URL_+"&t="+slot,{cache:"no-store",credentials:"omit"}).then(function(r){if(!r.ok)throw new Error();return r.text();}).then(function(v){if(v.indexOf("wfo1|")!==0)throw new Error();current=v;draw(v);offline.style.display="none";}).catch(function(){offline.style.display="block";lastSlot=-1;});}'
            .'draw(current);lastSlot=Math.floor(Date.now()/1000/SLOT);setInterval(tick,1000);tick();})();</script></body></html>';
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
    private function presence_content(){
        if(!$this->presence_verification_enabled())return $this->ews_empty_state(__('Presence Verification is disabled','workforce-one'),__('Please contact your administrator.','workforce-one'));
        $emp=$this->presence_employee_for_user();if(!$emp)return $this->ews_empty_state(__('Employee profile required','workforce-one'),__('Your account is not linked to an active employee.','workforce-one'));
        $id=absint($_GET['presence_request']??0);global $wpdb;list(,$vt)=$this->presence_tables();$req=$id?$wpdb->get_row($wpdb->prepare("SELECT v.*,l.name location_name,k.name kiosk_name FROM $vt v LEFT JOIN {$this->locations} l ON l.id=v.location_id LEFT JOIN {$wpdb->prefix}ews_kiosks k ON k.id=v.kiosk_id WHERE v.id=%d AND v.employee_id=%d LIMIT 1",$id,(int)$emp->id)):null;
        ob_start();echo '<div class="wfo-card" style="max-width:720px;margin:0 auto"><h2 style="margin-top:0">'.esc_html__('Presence Verification','workforce-one').'</h2>';
        if(isset($_GET['presence_success']))echo '<div class="ews-notice">'.esc_html__('Presence verified successfully.','workforce-one').'</div>';
        if(isset($_GET['presence_error']))echo '<div class="ews-notice ews-notice-error">'.esc_html(wp_unslash($_GET['presence_error'])).'</div>';
        if(!$req){echo '<p>'.esc_html__('No active presence verification request was found.','workforce-one').'</p></div>';return ob_get_clean();}
        if($req->status==='pending'){
            $left=max(0,strtotime($req->expires_at)-time());echo '<p>'.sprintf(/* translators: %s: work location name */esc_html__('Please scan the QR code displayed at %s.','workforce-one'),'<strong>'.esc_html($req->location_name).'</strong>').'</p><p id="wfo-presence-countdown" style="font-weight:700">'.sprintf(/* translators: %s: mm:ss */esc_html__('Expires in %s','workforce-one'),esc_html(gmdate('i:s',$left))).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="wfo-presence-form">'.wp_nonce_field('ews_presence_verify','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_presence_verify"><input type="hidden" name="request_id" value="'.(int)$req->id.'"><input type="hidden" name="qr_payload" id="wfo-presence-payload"><div id="wfo-presence-scanner" style="background:#101828;border-radius:16px;overflow:hidden;min-height:280px;display:flex;align-items:center;justify-content:center;color:#fff">'.esc_html__('Starting camera…','workforce-one').'</div><p style="color:#667085;font-size:12px">'.esc_html__('Camera scanning uses the browser camera permission. If scanning is unavailable on this device, use a QR-capable browser/device.','workforce-one').'</p><button class="button button-primary" type="submit">'.esc_html__('Verify Presence','workforce-one').'</button></form><script src="'.esc_url(plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').'assets/vendor/jsQR-1.4.0.js').'"></script><script>(function(){var video=document.createElement("video"),canvas=document.createElement("canvas"),box=document.getElementById("wfo-presence-scanner"),input=document.getElementById("wfo-presence-payload"),form=document.getElementById("wfo-presence-form");canvas.width=640;canvas.height=480;function fail(){box.textContent='.wp_json_encode(__('Camera scanning is unavailable. Please use a QR-capable device.','workforce-one')).';}if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||typeof jsQR!=="function"){fail();return;}navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}},audio:false}).then(function(stream){video.setAttribute("playsinline","");video.autoplay=true;video.srcObject=stream;box.innerHTML="";box.appendChild(video);var ctx=canvas.getContext("2d",{willReadFrequently:true});function scan(){if(video.readyState>=2){canvas.width=video.videoWidth||640;canvas.height=video.videoHeight||480;ctx.drawImage(video,0,0,canvas.width,canvas.height);var d=ctx.getImageData(0,0,canvas.width,canvas.height);var code=jsQR(d.data,d.width,d.height,{inversionAttempts:"dontInvert"});if(code&&code.data&&code.data.indexOf("wfo1|")===0){input.value=code.data;stream.getTracks().forEach(function(t){t.stop();});form.submit();return;}}requestAnimationFrame(scan);}scan();}).catch(fail);})();</script>';
        }else{echo '<p>'.esc_html__('Status:','workforce-one').' <strong>'.esc_html(ucfirst($req->status)).'</strong></p><p>'.esc_html__('Location:','workforce-one').' '.esc_html($req->location_name).'</p>'.($req->verified_at?'<p>'.esc_html__('Verified at:','workforce-one').' '.esc_html($req->verified_at).'</p>':'');}
        echo '</div>';return ob_get_clean();
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
