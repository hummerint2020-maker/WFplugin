<?php
if (!defined('ABSPATH')) exit;

trait EWS_Presence_Trait {
    private function presence_qr_enabled(){ return (bool)get_option('ews_presence_qr_signin',0); }
    private function presence_verification_enabled(){ return (bool)get_option('ews_presence_verification',0); }
    private function presence_tables(){ global $wpdb; return [$wpdb->prefix.'ews_kiosks',$wpdb->prefix.'ews_presence_verifications']; }
    private function ensure_presence_schema(){
        global $wpdb;
        $c=$wpdb->get_charset_collate();
        list($k,$v)=$this->presence_tables();
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE $k (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,location_id BIGINT UNSIGNED NOT NULL,name VARCHAR(190) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',credential_hash CHAR(64) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,last_seen_at DATETIME NULL,PRIMARY KEY(id),UNIQUE KEY credential_hash(credential_hash),KEY location_id(location_id),KEY status(status)) $c;");
        dbDelta("CREATE TABLE $v (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,employee_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,requested_by BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',expires_at DATETIME NOT NULL,verified_at DATETIME NULL,kiosk_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY employee_status(employee_id,status),KEY expires_at(expires_at),KEY location_id(location_id),KEY requested_by(requested_by)) $c;");
    }
    private function presence_secret($kiosk_id){
        global $wpdb; list($kt)= $this->presence_tables();
        return (string)$wpdb->get_var($wpdb->prepare("SELECT credential_hash FROM $kt WHERE id=%d AND status='active' LIMIT 1",absint($kiosk_id)));
    }
    private function presence_location($location_id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->locations} WHERE id=%d AND active=1 LIMIT 1",absint($location_id)));
    }
    private function presence_qr_payload($kiosk_id,$secret,$slot=null){
        $slot=$slot===null?floor(time()/30):(int)$slot;
        $payload='wfo1|'.$kiosk_id.'|'.$slot;
        $sig=hash_hmac('sha256',$payload,$secret.AUTH_KEY);
        return $payload.'|'.$sig;
    }
    private function presence_validate_qr($raw){
        $raw=sanitize_text_field($raw);
        $parts=explode('|',$raw);
        if(count($parts)!==4 || $parts[0]!=='wfo1') return new WP_Error('invalid_qr','Invalid QR code.');
        $kid=absint($parts[1]); $slot=(int)$parts[2]; $sig=$parts[3];
        if(!$kid || !preg_match('/^[a-f0-9]{64}$/',$sig)) return new WP_Error('invalid_qr','Invalid QR code.');
        $secret=$this->presence_secret($kid); if($secret==='') return new WP_Error('inactive_kiosk','This kiosk is inactive.');
        $now=floor(time()/30);
        if(abs($now-$slot)>1) return new WP_Error('expired_qr','This QR code has expired. Please scan the current code.');
        $expected=hash_hmac('sha256','wfo1|'.$kid.'|'.$slot,$secret.AUTH_KEY);
        if(!hash_equals($expected,$sig)) return new WP_Error('invalid_qr','Invalid QR signature.');
        global $wpdb; list($kt)= $this->presence_tables();
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $kt WHERE id=%d AND status='active' LIMIT 1",$kid));
        if(!$row)return new WP_Error('inactive_kiosk','This kiosk is inactive.');
        $wpdb->update($kt,['last_seen_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>$kid],['%s','%s'],['%d']);
        return $row;
    }
    private function presence_employee_for_user(){ return $this->current_employee(); }

    public function admin_presence_kiosks(){
        if(!$this->can('ews_manage_locations')) wp_die('Access denied');
        $this->ensure_presence_schema(); global $wpdb; list($kt)= $this->presence_tables();
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
        if(!$this->can('ews_manage_locations'))wp_die('Access denied'); check_admin_referer('ews_presence_kiosk_save');
        $name=sanitize_text_field($_POST['name']??'');$location_id=absint($_POST['location_id']??0); if($name===''||!$location_id)wp_die('Invalid kiosk.');
        $loc=$this->presence_location($location_id); if(!$loc)wp_die('Invalid work location.');
        $this->ensure_presence_schema(); global $wpdb; list($kt)=$this->presence_tables();
        $secret=bin2hex(random_bytes(32));
        $hash=hash('sha256',$secret);
        $ok=$wpdb->insert($kt,['location_id'=>$location_id,'name'=>$name,'status'=>'active','credential_hash'=>$hash,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['%d','%s','%s','%s','%s','%s']);
        if($ok===false)wp_die('Could not create kiosk.');
        $id=(int)$wpdb->insert_id; update_option('ews_presence_kiosk_key_'.$id,$secret,false);
        $this->audit('kiosk_created','kiosk',$id,$name.' / location='.$loc->name);
        wp_safe_redirect(admin_url('admin.php?page=ews31-presence-kiosks&presence_saved=1'));exit;
    }
    public function presence_kiosk_revoke(){
        if(!$this->can('ews_manage_locations'))wp_die('Access denied'); check_admin_referer('ews_presence_kiosk_revoke'); $id=absint($_POST['kiosk_id']??0);$this->ensure_presence_schema();global $wpdb;list($kt)=$this->presence_tables();$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM $kt WHERE id=%d",$id));if(!$row)wp_die('Kiosk not found.');$wpdb->update($kt,['status'=>'disabled','updated_at'=>current_time('mysql')],['id'=>$id],['%s','%s'],['%d']);$this->audit('kiosk_disabled','kiosk',$id,$row->name);wp_safe_redirect(admin_url('admin.php?page=ews31-presence-kiosks&presence_revoked=1'));exit;
    }
    public function presence_request(){
        if(!$this->can('ews_manage_attendance'))wp_die('Access denied'); check_admin_referer('ews_presence_request');
        $eid=absint($_POST['employee_id']??0);$location_id=absint($_POST['location_id']??0);if(!$eid||!$location_id)wp_die('Invalid employee or location.');
        if(method_exists($this,'department_scope_allows_employee')&&!$this->department_scope_allows_employee($eid))wp_die('You cannot manage presence outside your Department.');
        global $wpdb;$emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$eid));$loc=$this->presence_location($location_id);if(!$emp||!$loc)wp_die('Employee or location not found.');
        $this->ensure_presence_schema();list(,$vt)=$this->presence_tables();$expires=gmdate('Y-m-d H:i:s',time()+180);$ok=$wpdb->insert($vt,['employee_id'=>$eid,'location_id'=>$location_id,'requested_by'=>get_current_user_id(),'status'=>'pending','expires_at'=>$expires,'created_at'=>current_time('mysql')],['%d','%d','%d','%s','%s','%s']);if($ok===false)wp_die('Could not create presence verification request.');$id=(int)$wpdb->insert_id;
        $this->audit('presence_verification_requested','presence_verification',$id,$emp->name.' / '.$loc->name.' / expires='.$expires);
        if(!empty($emp->wp_user_id)){
            $msg='Please verify your presence at <strong>'.esc_html($loc->name).'</strong>. The request expires in 3 minutes.';$url=add_query_arg(['ews_view'=>'presence','presence_request'=>$id],home_url('/'));$this->notify_user((int)$emp->wp_user_id,'Presence Verification',$msg,'presence',$id);if(method_exists($this,'push_custom_notification'))$this->push_custom_notification((int)$emp->wp_user_id,'Presence Verification',$msg,'presence',$id,$url);
        }
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=ews31-requests'));exit;
    }
    public function presence_verify(){
        if(!is_user_logged_in())wp_die('Please log in.'); check_admin_referer('ews_presence_verify');
        $id=absint($_POST['request_id']??0);$raw=wp_unslash($_POST['qr_payload']??'');$emp=$this->presence_employee_for_user();if(!$emp)wp_die('Your account is not linked to an active employee.');
        $this->ensure_presence_schema();global $wpdb;list(,$vt)=$this->presence_tables();$req=$wpdb->get_row($wpdb->prepare("SELECT * FROM $vt WHERE id=%d AND employee_id=%d LIMIT 1",$id,(int)$emp->id));if(!$req)wp_die('Presence request not found.');
        if($req->status!=='pending'){ $this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode('This presence request has already been processed.')]);return; }
        if(strtotime($req->expires_at)<time()){$wpdb->update($vt,['status'=>'expired'],['id'=>$id,'status'=>'pending'],['%s'],['%d','%s']);$this->audit('presence_verification_expired','presence_verification',$id,$emp->name);$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode('This presence request has expired.')]);return;}
        $k=$this->presence_validate_qr($raw);if(is_wp_error($k)){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / '.$k->get_error_code());$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode($k->get_error_message())]);return;}
        if((int)$k->location_id!==(int)$req->location_id){$this->audit('presence_verification_failed','presence_verification',$id,$emp->name.' / wrong_location');$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode('This QR belongs to a different work location.')]);return;}
        $now=current_time('mysql');$updated=$wpdb->query($wpdb->prepare("UPDATE $vt SET status='verified',verified_at=%s,kiosk_id=%d WHERE id=%d AND status='pending'",$now,(int)$k->id,$id));if($updated!==1){$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_error'=>rawurlencode('The request changed before it could be verified.')]);return;}
        $this->audit('presence_verification_verified','presence_verification',$id,$emp->name.' / kiosk='.$k->name);$this->redirect(['ews_view'=>'presence','presence_request'=>$id,'presence_success'=>1]);
    }
    public function presence_kiosk_route(){
        if(!isset($_GET['ews_kiosk']))return;
        $id=absint($_GET['ews_kiosk']);$key=sanitize_text_field(wp_unslash($_GET['kiosk_key']??''));$this->ensure_presence_schema();$secret=$this->presence_secret($id);if($secret===''||$key===''||!hash_equals($secret,$key)){status_header(403);wp_die('Kiosk access denied.');}
        global $wpdb;list($kt)=$this->presence_tables();$k=$wpdb->get_row($wpdb->prepare("SELECT k.*,l.name location_name FROM $kt k LEFT JOIN {$this->locations} l ON l.id=k.location_id WHERE k.id=%d AND k.status='active' LIMIT 1",$id));if(!$k)wp_die('Kiosk is inactive.');
        $payload=$this->presence_qr_payload($id,$secret);$qr='https://api.qrserver.com/v1/create-qr-code/?size=420x420&data='.rawurlencode($payload);
        nocache_headers();header('Content-Type: text/html; charset=utf-8');echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Workforce One Kiosk</title><style>html,body{margin:0;height:100%;background:#f7f8fb;font-family:Arial,sans-serif}body{display:flex;align-items:center;justify-content:center}.k{width:min(760px,92vw);text-align:center;background:#fff;border:1px solid #e4e7ec;border-radius:24px;padding:34px;box-shadow:0 12px 40px rgba(16,24,40,.08)}.brand{font-weight:800;font-size:24px;color:#101828}.sub{color:#667085;margin:8px 0 24px}.qr{width:min(420px,75vw);height:min(420px,75vw);object-fit:contain;border:14px solid #fff}.timer{font-size:18px;font-weight:700;margin-top:18px;color:#344054}.loc{margin-top:8px;color:#667085}.offline{display:none;color:#b42318;margin-top:10px}</style></head><body><main class="k"><div class="brand">WORKFORCE ONE</div><div class="sub">Scan to continue</div><img id="qr" class="qr" src="'.esc_url($qr).'" alt="Dynamic Workforce One QR"><div id="timer" class="timer"></div><div class="loc">'.esc_html($k->location_name).'</div><div id="offline" class="offline">Connection unavailable. Please check this kiosk connection.</div></main><script>(function(){var id='.((int)$id).',key='.wp_json_encode($key).';function refresh(){var now=Math.floor(Date.now()/1000),slot=Math.floor(now/30),left=30-(now%30);document.getElementById("timer").textContent="Changes in "+left+"s";var p=new URL(window.location.href);p.searchParams.set("kiosk_refresh",slot);fetch(p.toString(),{cache:"no-store"}).then(function(r){if(!r.ok)throw new Error();return r.text();}).catch(function(){document.getElementById("offline").style.display="block";});var img=document.getElementById("qr");var payload="";fetch(window.location.href+"&kiosk_payload=1&t="+slot,{cache:"no-store"}).then(function(r){return r.text()}).then(function(v){if(v.indexOf("wfo1|")===0){img.src="https://api.qrserver.com/v1/create-qr-code/?size=420x420&data="+encodeURIComponent(v);document.getElementById("offline").style.display="none";}}).catch(function(){document.getElementById("offline").style.display="block";});}setInterval(refresh,1000);refresh();})();</script></body></html>';exit;
    }
    public function presence_kiosk_payload_route(){
        if(!isset($_GET['ews_kiosk'])||!isset($_GET['kiosk_payload']))return;
        $id=absint($_GET['ews_kiosk']);$key=sanitize_text_field(wp_unslash($_GET['kiosk_key']??''));$this->ensure_presence_schema();$secret=$this->presence_secret($id);if($secret===''||$key===''||!hash_equals($secret,$key))wp_die('Kiosk access denied.');nocache_headers();header('Content-Type:text/plain; charset=utf-8');echo $this->presence_qr_payload($id,$secret);exit;
    }
    private function presence_content(){
        if(!$this->presence_verification_enabled())return $this->ews_empty_state('Presence Verification is disabled','Please contact your administrator.');
        $emp=$this->presence_employee_for_user();if(!$emp)return $this->ews_empty_state('Employee profile required','Your account is not linked to an active employee.');
        $id=absint($_GET['presence_request']??0);global $wpdb;$this->ensure_presence_schema();list(,$vt)=$this->presence_tables();$req=$id?$wpdb->get_row($wpdb->prepare("SELECT v.*,l.name location_name,k.name kiosk_name FROM $vt v LEFT JOIN {$this->locations} l ON l.id=v.location_id LEFT JOIN {$wpdb->prefix}ews_kiosks k ON k.id=v.kiosk_id WHERE v.id=%d AND v.employee_id=%d LIMIT 1",$id,(int)$emp->id)):null;
        ob_start();echo '<div class="wfo-card" style="max-width:720px;margin:0 auto"><h2 style="margin-top:0">Presence Verification</h2>';
        if(isset($_GET['presence_success']))echo '<div class="ews-notice">Presence verified successfully.</div>';
        if(isset($_GET['presence_error']))echo '<div class="ews-notice ews-notice-error">'.esc_html(wp_unslash($_GET['presence_error'])).'</div>';
        if(!$req){echo '<p>No active presence verification request was found.</p></div>';return ob_get_clean();}
        if($req->status==='pending'){
            $left=max(0,strtotime($req->expires_at)-time());echo '<p>Please scan the QR code displayed at <strong>'.esc_html($req->location_name).'</strong>.</p><p id="wfo-presence-countdown" style="font-weight:700">Expires in '.esc_html(gmdate('i:s',$left)).'</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" id="wfo-presence-form">'.wp_nonce_field('ews_presence_verify','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_presence_verify"><input type="hidden" name="request_id" value="'.(int)$req->id.'"><input type="hidden" name="qr_payload" id="wfo-presence-payload"><div id="wfo-presence-scanner" style="background:#101828;border-radius:16px;overflow:hidden;min-height:280px;display:flex;align-items:center;justify-content:center;color:#fff">Starting camera…</div><p style="color:#667085;font-size:12px">Camera scanning uses the browser camera permission. If scanning is unavailable on this device, use a QR-capable browser/device.</p><button class="button button-primary" type="submit">Verify Presence</button></form><script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script><script>(function(){var video=document.createElement("video"),canvas=document.createElement("canvas"),box=document.getElementById("wfo-presence-scanner"),input=document.getElementById("wfo-presence-payload"),form=document.getElementById("wfo-presence-form");canvas.width=640;canvas.height=480;function fail(){box.textContent="Camera scanning is unavailable. Please use a QR-capable device.";}if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia||typeof jsQR!=="function"){fail();return;}navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:"environment"}},audio:false}).then(function(stream){video.setAttribute("playsinline","");video.autoplay=true;video.srcObject=stream;box.innerHTML="";box.appendChild(video);var ctx=canvas.getContext("2d",{willReadFrequently:true});function scan(){if(video.readyState>=2){canvas.width=video.videoWidth||640;canvas.height=video.videoHeight||480;ctx.drawImage(video,0,0,canvas.width,canvas.height);var d=ctx.getImageData(0,0,canvas.width,canvas.height);var code=jsQR(d.data,d.width,d.height,{inversionAttempts:"dontInvert"});if(code&&code.data&&code.data.indexOf("wfo1|")===0){input.value=code.data;stream.getTracks().forEach(function(t){t.stop();});form.submit();return;}}requestAnimationFrame(scan);}scan();}).catch(fail);})();</script>';
        }else{echo '<p>Status: <strong>'.esc_html(ucfirst($req->status)).'</strong></p><p>Location: '.esc_html($req->location_name).'</p>'.($req->verified_at?'<p>Verified at: '.esc_html($req->verified_at).'</p>':'');}
        echo '</div>';return ob_get_clean();
    }
    public function presence_qr_signin(){
        if(!$this->presence_qr_enabled())wp_die('Dynamic QR Sign-In is disabled.');
        if(!is_user_logged_in())wp_die('Please log in.');
        check_admin_referer('ews_presence_qr_signin');
        $emp=$this->current_employee(); if(!$emp)wp_die('Your account is not linked to an active employee.');
        if(!$this->employee_attendance_enabled((int)$emp->id) ){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode('Attendance tracking is disabled for your employee profile.')]);return;}
        $k=$this->presence_validate_qr(wp_unslash($_POST['qr_payload']??''));
        if(is_wp_error($k)){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode($k->get_error_message())]);return;}
        $loc=$this->presence_location((int)$k->location_id);if(!$loc){$this->redirect(['ews_view'=>'time','time_error'=>rawurlencode('The Kiosk location is unavailable.')]);return;}
        $_POST['event_type']='sign_in';
        $_POST['face_verified']=($_POST['face_verified']??'');
        $_POST['latitude']=$loc->latitude;
        $_POST['longitude']=$loc->longitude;
        $_POST['accuracy']='';
        $_POST['location_timestamp']='';
        $_POST['qr_verified']='1';
        $_POST['qr_kiosk_id']=(int)$k->id;
        $this->time_event();
    }

}
