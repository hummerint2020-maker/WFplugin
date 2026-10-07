<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Api\Auth\Bearer;
use WorkforceOne\Api\Auth\LoginInput;
use WorkforceOne\Api\Auth\LoginThrottle;
use WorkforceOne\Api\Auth\Tokens;
use WorkforceOne\Api\ErrorMap;
use WorkforceOne\Api\Meta;
use WorkforceOne\Api\Response;

/**
 * Native app authentication (API Phase 0B, 3.31.47): sign in with the WordPress user name and
 * password, get a short-lived access token and a rotating refresh token for one device session.
 *
 * - WordPress stays the identity source: passwords are checked by wp_authenticate() (so lock-out and
 *   two-factor plugins on the "authenticate" filter still apply) and never stored. WordPress
 *   application passwords are not accepted here.
 * - Tokens are opaque (src/Api/Auth/Tokens.php); the database keeps only HMACs of them.
 * - A token authenticates only the native routes of workforce-one/v1 (src/Api/Routes.php: their
 *   permission callback is the only code that reads it). Nothing here filters
 *   determine_current_user, so wp-admin, admin-post.php, /wp/v2 and other plugins never see it.
 *   The WordPress user set for a native request is put back when the handler returns.
 * - The browser's WordPress login is not touched: no cookies are set or read for these routes.
 *
 * Tables: ews_api_devices (device sessions), ews_api_tokens, ews_api_rate_limits (trait-schema.php).
 */
trait EWS_Api_Auth_Trait {

    /** @var array<string,mixed>|null the device session of the current native request */
    private static $api_session = null;
    /** @var int|null the WordPress user to put back after a native request (null: nothing to restore) */
    private static $api_previous_user = null;
    /** @var string|null */
    private static $api_request_id = null;
    /** @var array<string,array<string,mixed>|string> access token hash => resolved session or error code, per request */
    private static $api_token_cache = [];

    // ------------------------------------------------------------------ plumbing

    private function api_tables(){
        global $wpdb;
        return ['devices'=>$wpdb->prefix.'ews_api_devices','tokens'=>$wpdb->prefix.'ews_api_tokens','limits'=>$wpdb->prefix.'ews_api_rate_limits'];
    }

    private function api_site_id(){ return Tokens::siteId(home_url('/')); }

    /** HMAC key for token and rate-limit hashes: a site secret that is not in the database dump alone when salts are in wp-config.php. */
    private function api_secret(){ return wp_salt('auth').'|workforce-one-api'; }

    private function api_utc($ts){ return gmdate('Y-m-d H:i:s',(int)$ts); }

    private function api_unix($mysql_utc){ return $mysql_utc?(int)strtotime($mysql_utc.' UTC'):0; }

    /** ISO 8601 in the site's time zone, e.g. 2026-10-05T08:00:00+03:00. */
    private function api_iso($mysql_utc){
        if(!$mysql_utc)return null;
        return (new DateTimeImmutable('@'.$this->api_unix($mysql_utc)))->setTimezone(wp_timezone())->format(DATE_ATOM);
    }

    private function api_request_id(){
        if(self::$api_request_id===null)self::$api_request_id=Response::requestId((int)floor(microtime(true)*1000),random_bytes(10));
        return self::$api_request_id;
    }

    private function api_server_time(){ return (new DateTimeImmutable('now',wp_timezone()))->format(DATE_ATOM); }

    private function api_client_ip(){
        // REMOTE_ADDR only: forwarded-for headers are set by the client unless a trusted proxy rewrites them.
        return isset($_SERVER['REMOTE_ADDR'])?sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])):'';
    }

    /** Display text for an API code (the app branches on the code; this is for people). */
    private function api_message($code){
        $m=[
            ErrorMap::INVALID_CREDENTIALS=>__('The user name or password is incorrect.','workforce-one'),
            ErrorMap::RATE_LIMITED=>__('Too many sign-in attempts. Please try again later.','workforce-one'),
            ErrorMap::TOKEN_MISSING=>__('Please sign in to continue.','workforce-one'),
            ErrorMap::TOKEN_INVALID=>__('Your session is not valid. Please sign in again.','workforce-one'),
            ErrorMap::TOKEN_EXPIRED=>__('Your session has expired.','workforce-one'),
            ErrorMap::REFRESH_INVALID=>__('Your session has ended. Please sign in again.','workforce-one'),
            ErrorMap::REFRESH_REUSED=>__('For your security this session was ended. Please sign in again.','workforce-one'),
            ErrorMap::DEVICE_REVOKED=>__('This device was signed out. Please sign in again.','workforce-one'),
            ErrorMap::ACCOUNT_DISABLED=>__('Your account is not active in Workforce One.','workforce-one'),
            ErrorMap::ACCOUNT_NOT_LINKED=>__('Your account is not linked to an employee in Workforce One.','workforce-one'),
            ErrorMap::HTTPS_REQUIRED=>__('A secure connection (HTTPS) is required.','workforce-one'),
            ErrorMap::VALIDATION_FAILED=>__('Some fields are missing or invalid.','workforce-one'),
            ErrorMap::NOT_FOUND=>__('Not found.','workforce-one'),
            ErrorMap::FORBIDDEN=>__('You do not have access to this.','workforce-one'),
        ];
        return $m[$code]??__('Something went wrong. Please try again.','workforce-one');
    }

    /** @param mixed $data */
    private function api_ok($data,$status=200){
        return Response::success($data,$this->api_request_id(),$this->api_server_time(),[],$status);
    }

    /** @param array<string,mixed> $details */
    private function api_fail($code,$details=[],$retry_after=0){
        $r=Response::error($code,$this->api_message($code),$this->api_request_id(),$this->api_server_time(),$details);
        if($retry_after>0)$r['retry_after']=(int)$retry_after;
        return $r;
    }

    /** @param array{status:int,body:array<string,mixed>,retry_after?:int} $r */
    private function api_rest_response(array $r){
        $resp=new WP_REST_Response($r['body'],$r['status']);
        $resp->header('Cache-Control','no-store');
        $resp->header('X-WFO-Request-Id',$this->api_request_id());
        if(!empty($r['retry_after']))$resp->header('Retry-After',(string)(int)$r['retry_after']);
        return $resp;
    }

    /** Runs a native handler; an unexpected failure is INTERNAL_ERROR with the request id (details only in the PHP error log). */
    public function api_dispatch($handler,$request){
        try{
            $r=$this->$handler($request);
        }catch(\Throwable $e){
            error_log('[Workforce One API] '.$this->api_request_id().' '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            $r=Response::internalError($this->api_request_id(),$this->api_server_time(),$this->api_message(ErrorMap::INTERNAL_ERROR));
        }
        return $this->api_rest_response($r);
    }

    /**
     * rest_request_after_callbacks: a refused permission check on a native route becomes the API
     * envelope too (WordPress would answer {code, message, data}); then the WordPress user set by the
     * token is put back, so nothing after the handler runs as that user.
     * @param mixed $response
     * @param array<string,mixed> $handler
     * @return mixed
     */
    public function api_after_callbacks($response,$handler){
        if(($handler['wfo_kind']??'')==='native' && is_wp_error($response)){
            $data=(array)$response->get_error_data();
            $code=isset($data['wfo_code'])?(string)$data['wfo_code']:ErrorMap::FORBIDDEN;
            $response=$this->api_rest_response($this->api_fail($code,[],(int)($data['retry_after']??0)));
        }
        $this->api_restore_user();
        return $response;
    }

    private function api_restore_user(){
        if(self::$api_previous_user===null)return;
        wp_set_current_user(self::$api_previous_user);
        self::$api_previous_user=null;
        self::$api_session=null;
    }

    private function api_error($code,$status){
        return new WP_Error('wfo_'.strtolower($code),$this->api_message($code),['status'=>$status,'wfo_code'=>$code]);
    }

    private function api_https_ok(){
        return is_ssl() || (defined('WORKFORCE_ONE_API_ALLOW_HTTP') && WORKFORCE_ONE_API_ALLOW_HTTP);
    }

    /** Permission callback of login and refresh: HTTPS only (WORKFORCE_ONE_API_ALLOW_HTTP in wp-config.php allows HTTP for development). */
    public function api_require_https($request){
        return $this->api_https_ok()?true:$this->api_error(ErrorMap::HTTPS_REQUIRED,403);
    }

    /**
     * Permission callback of the token routes: the only place an access token is read. Sets the
     * token's WordPress user for this request (put back by api_after_callbacks()).
     */
    public function api_authenticate($request){
        if(!$this->api_https_ok())return $this->api_error(ErrorMap::HTTPS_REQUIRED,403);
        $token=Bearer::fromHeaders($request->get_header(Bearer::HEADER),$request->get_header(Bearer::FALLBACK_HEADER));
        if($token===null)return $this->api_error(ErrorMap::TOKEN_MISSING,401);
        $session=$this->api_resolve_access_token($token);
        if(is_string($session))return $this->api_error($session,$session===ErrorMap::ACCOUNT_DISABLED||$session===ErrorMap::ACCOUNT_NOT_LINKED?403:401);
        self::$api_session=$session;
        self::$api_previous_user=get_current_user_id();
        wp_set_current_user((int)$session['user_id']);
        $this->api_touch_device($session);
        return true;
    }

    /**
     * The device session of an access token (with its user and employee), or an API error code.
     * One query for the token and its device; the user and employee are then loaded once and cached
     * for the request (current_employee() reuses the employee).
     * @return array<string,mixed>|string
     */
    private function api_resolve_access_token($token){
        $hash=Tokens::hash($token,$this->api_secret());
        if(isset(self::$api_token_cache[$hash]))return self::$api_token_cache[$hash];
        if(Tokens::kindOf($token,$this->api_site_id(),Tokens::ACCESS)===null)return self::$api_token_cache[$hash]=ErrorMap::TOKEN_INVALID;
        global $wpdb;$t=$this->api_tables();
        $row=$wpdb->get_row($wpdb->prepare("SELECT k.id,k.device_id,k.user_id,k.expires_at,k.revoked_at,d.public_id,d.platform,d.model,d.app_version,d.created_at device_created_at,d.last_seen_at,d.revoked_at device_revoked_at,d.expires_at session_expires_at FROM {$t['tokens']} k JOIN {$t['devices']} d ON d.id=k.device_id WHERE k.token_hash=%s AND k.kind='access' LIMIT 1",$hash),ARRAY_A);
        if(!$row)return self::$api_token_cache[$hash]=ErrorMap::TOKEN_INVALID;
        $status=Tokens::accessStatus(['expires_at'=>min($this->api_unix($row['expires_at']),$this->api_unix($row['session_expires_at'])),'revoked_at'=>$row['revoked_at'],'device_revoked_at'=>$row['device_revoked_at']],time());
        $map=[Tokens::DEVICE_REVOKED=>ErrorMap::DEVICE_REVOKED,Tokens::REVOKED=>ErrorMap::TOKEN_INVALID,Tokens::EXPIRED=>ErrorMap::TOKEN_EXPIRED];
        if(isset($map[$status]))return self::$api_token_cache[$hash]=$map[$status];
        $user=get_userdata((int)$row['user_id']);
        if(!$user)return self::$api_token_cache[$hash]=ErrorMap::TOKEN_INVALID;
        // The account is checked on every request: an archived employee or a removed role stops at once.
        [$gate,$employee]=$this->api_account_gate($user);
        if($gate!=='ok')return self::$api_token_cache[$hash]=$gate;
        self::$ews_current_employee_cache[(int)$user->ID]=$employee;
        return self::$api_token_cache[$hash]=$row+['user'=>$user,'employee'=>$employee];
    }

    /** last_seen_at at most every 5 minutes, so a request does not always write. */
    private function api_touch_device(array $session){
        if(time()-$this->api_unix($session['last_seen_at'])<300)return;
        global $wpdb;
        $wpdb->update($this->api_tables()['devices'],['last_seen_at'=>$this->api_utc(time())],['id'=>(int)$session['device_id']],['%s'],['%d']);
    }

    /**
     * May this WordPress user use Workforce One from the app?
     * - an active employee linked to the user: yes;
     * - an employee record that is archived (inactive): no, ACCOUNT_DISABLED (archiving is meant to end access);
     * - no employee record: yes for Workforce One administrators and managers (a WordPress administrator,
     *   or any Workforce One permission other than View People), otherwise ACCOUNT_NOT_LINKED.
     * @return array{0:string,1:object|null} ['ok' or an API code, the active employee row or null]
     */
    private function api_account_gate(WP_User $user){
        global $wpdb;
        $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d ORDER BY active DESC, id ASC LIMIT 1",(int)$user->ID));
        if($emp && (int)$emp->active===1)return ['ok',$emp];
        if($emp)return [ErrorMap::ACCOUNT_DISABLED,null];
        if($this->api_user_is_ews_admin($user))return ['ok',null];
        return [ErrorMap::ACCOUNT_NOT_LINKED,null];
    }

    private function api_user_is_ews_admin(WP_User $user){
        if(user_can($user,'manage_options'))return true;
        foreach(array_diff(array_keys($this->permission_defs()),['ews_view_people']) as $cap){
            if(user_can($user,$cap))return true;
        }
        return false;
    }

    /** Audit Log row for an API event, by the given user (the request has no signed-in user yet at login). */
    private function api_audit($user_id,$action,$device_id,$details){
        global $wpdb;
        $wpdb->insert($this->audit,['user_id'=>(int)$user_id,'action'=>$action,'entity'=>'api_device','entity_id'=>(int)$device_id,'details'=>$details,'created_at'=>current_time('mysql')]);
    }

    // ------------------------------------------------------------------ GET /meta

    public function api_meta($request){
        $icon=function_exists('get_site_icon_url')?(string)get_site_icon_url(192):'';
        return $this->api_ok(Meta::document([
            'site_id'=>$this->api_site_id(),'site_name'=>wp_specialchars_decode((string)get_bloginfo('name'),ENT_QUOTES),'site_url'=>home_url('/'),
            'timezone'=>wp_timezone_string(),'locale'=>get_locale(),'server_version'=>defined('EWS_VERSION')?EWS_VERSION:'',
            'features'=>[
                'breaks'=>$this->break_enabled(),'face_sign_in'=>$this->face_signin_enabled(),'qr_sign_in'=>(bool)$this->option('ews_presence_qr_signin'),
                'presence_verification'=>(bool)$this->option('ews_presence_verification'),'overtime'=>(bool)$this->option('ews_feature_overtime'),
                'tasks'=>(bool)$this->option('ews_feature_tasks'),'achievements'=>(bool)$this->option('ews_feature_achievements'),
                'recognition'=>(bool)$this->option('ews_feature_recognition'),'my_pay'=>(bool)$this->option('ews_payroll_employee_view'),
            ],
            'app_name'=>\WorkforceOne\Pwa\Manifest::NAME,'theme_color'=>\WorkforceOne\Pwa\Manifest::THEME_COLOR,'icon'=>$icon,
        ]));
    }

    // ------------------------------------------------------------------ POST /auth/login

    public function api_auth_login($request){
        [$in,$errors]=LoginInput::parse($request->get_json_params());
        if($in===null)return $this->api_fail(ErrorMap::VALIDATION_FAILED,['fields'=>$errors]);
        $now=time();$ip=$this->api_client_ip();
        $keys=[LoginThrottle::USER=>LoginThrottle::key(LoginThrottle::USER,$in['username'],$this->api_secret()),LoginThrottle::IP=>LoginThrottle::key(LoginThrottle::IP,$ip,$this->api_secret())];
        foreach($keys as $bucket=>$key){
            $row=$this->api_limit_row($bucket,$key);
            // Refused before the password is checked: right or wrong, existing user or not, the answer is the same.
            if(LoginThrottle::blocked($bucket,$row,$now))return $this->api_fail(ErrorMap::RATE_LIMITED,[],LoginThrottle::retryAfter($row,$now));
        }
        $user=$this->api_check_password($in['username'],$in['password']);
        if(!$user){
            $this->api_login_failed($keys,$in['username'],$ip,$now);
            return $this->api_fail(ErrorMap::INVALID_CREDENTIALS);
        }
        [$gate]=$this->api_account_gate($user);
        if($gate!=='ok'){
            $this->api_audit($user->ID,'api_login_refused',0,$user->user_login.' / '.$gate.' / ip='.$ip);
            return $this->api_fail($gate);
        }
        $this->api_limit_clear(LoginThrottle::USER,$keys[LoginThrottle::USER]);
        $device=$this->api_device_create($user,$in['device'],$now);
        if(!$device)return Response::internalError($this->api_request_id(),$this->api_server_time(),$this->api_message(ErrorMap::INTERNAL_ERROR));
        $pair=$this->api_issue_pair($device,$now);
        $this->api_audit($user->ID,'api_login',$device['id'],$user->user_login.' / '.$device['platform'].' '.$device['model'].' / app '.$device['app_version'].' / ip='.$ip);
        return $this->api_ok($this->api_session_payload($pair,$device));
    }

    /**
     * The WordPress password check (wp_authenticate(): the "authenticate" filters run, so lock-out and
     * two-factor plugins apply). Application passwords are switched off for this check. A user name
     * that does not exist costs a password hash too, so timing does not tell it apart.
     * @return WP_User|null
     */
    private function api_check_password($username,$password){
        $no_app_passwords=static function(){return false;};
        add_filter('application_password_is_api_request',$no_app_passwords,PHP_INT_MAX);
        $user=wp_authenticate($username,$password);
        remove_filter('application_password_is_api_request',$no_app_passwords,PHP_INT_MAX);
        if(is_wp_error($user)){
            if(in_array($user->get_error_code(),['invalid_username','invalid_email'],true))wp_hash_password($password);
            return null;
        }
        return $user instanceof WP_User?$user:null;
    }

    /** @param array<string,string> $keys */
    private function api_login_failed(array $keys,$username,$ip,$now){
        $user_count=$this->api_limit_hit(LoginThrottle::USER,$keys[LoginThrottle::USER],$now);
        $ip_count=$this->api_limit_hit(LoginThrottle::IP,$keys[LoginThrottle::IP],$now);
        // One Audit Log row when a bucket fills up, not one per failed attempt.
        if($user_count===LoginThrottle::LIMITS[LoginThrottle::USER])$this->api_audit(0,'api_login_locked',0,'user name "'.sanitize_user((string)$username,true).'" / '.$user_count.' failed sign-ins in '.(LoginThrottle::WINDOW/60).' minutes / ip='.$ip);
        if($ip_count===LoginThrottle::LIMITS[LoginThrottle::IP])$this->api_audit(0,'api_login_locked',0,'ip='.$ip.' / '.$ip_count.' failed sign-ins in '.(LoginThrottle::WINDOW/60).' minutes');
    }

    // ------------------------------------------------------------------ POST /auth/refresh

    public function api_auth_refresh($request){
        $body=$request->get_json_params();
        $token=is_array($body)&&is_string($body['refresh_token']??null)?$body['refresh_token']:'';
        if(Tokens::kindOf($token,$this->api_site_id(),Tokens::REFRESH)===null)return $this->api_fail(ErrorMap::REFRESH_INVALID);
        global $wpdb;$t=$this->api_tables();$now=time();
        $row=$wpdb->get_row($wpdb->prepare("SELECT k.id,k.device_id,k.user_id,k.expires_at,k.used_at,k.revoked_at,d.revoked_at device_revoked_at,d.expires_at session_expires_at FROM {$t['tokens']} k JOIN {$t['devices']} d ON d.id=k.device_id WHERE k.token_hash=%s AND k.kind='refresh' LIMIT 1",Tokens::hash($token,$this->api_secret())),ARRAY_A);
        if(!$row)return $this->api_fail(ErrorMap::REFRESH_INVALID);
        $status=Tokens::refreshStatus(['expires_at'=>$this->api_unix($row['expires_at']),'used_at'=>$row['used_at'],'revoked_at'=>$row['revoked_at'],'device_revoked_at'=>$row['device_revoked_at'],'session_expires_at'=>$this->api_unix($row['session_expires_at'])],$now);
        if($status===Tokens::DEVICE_REVOKED)return $this->api_fail(ErrorMap::DEVICE_REVOKED);
        if($status===Tokens::USED)return $this->api_refresh_reused($row);
        if($status!==Tokens::OK)return $this->api_fail(ErrorMap::REFRESH_INVALID);
        $user=get_userdata((int)$row['user_id']);
        if(!$user){$this->api_revoke_device((int)$row['device_id'],'user_deleted');return $this->api_fail(ErrorMap::REFRESH_INVALID);}
        [$gate]=$this->api_account_gate($user);
        if($gate!=='ok'){$this->api_revoke_device((int)$row['device_id'],'account_disabled',(int)$user->ID);return $this->api_fail($gate);}
        // Rotation: exactly one request can use a refresh token; a second one (even concurrent) is a reuse.
        $used=$wpdb->query($wpdb->prepare("UPDATE {$t['tokens']} SET used_at=%s WHERE id=%d AND used_at IS NULL AND revoked_at IS NULL",$this->api_utc($now),(int)$row['id']));
        if($used!==1)return $this->api_refresh_reused($row);
        $wpdb->query($wpdb->prepare("UPDATE {$t['tokens']} SET revoked_at=%s WHERE device_id=%d AND kind='access' AND revoked_at IS NULL",$this->api_utc($now),(int)$row['device_id']));
        $device=$this->api_device((int)$row['device_id']);
        $pair=$this->api_issue_pair($device,$now);
        $wpdb->update($t['devices'],['last_seen_at'=>$this->api_utc($now)],['id'=>(int)$device['id']],['%s'],['%d']);
        // Refreshes happen every 15 minutes: one Audit Log row per device per day is enough.
        if($now-$this->api_unix($device['refresh_audited_at'])>=DAY_IN_SECONDS){
            $wpdb->update($t['devices'],['refresh_audited_at'=>$this->api_utc($now)],['id'=>(int)$device['id']],['%s'],['%d']);
            $this->api_audit($user->ID,'api_refresh',$device['id'],$user->user_login.' / '.$device['platform'].' '.$device['model'].' / ip='.$this->api_client_ip());
        }
        return $this->api_ok($this->api_session_payload($pair,$device));
    }

    /** A refresh token used twice: it may have been stolen. The whole device session ends. */
    private function api_refresh_reused(array $row){
        $this->api_revoke_device((int)$row['device_id'],'refresh_reuse');
        $this->api_audit((int)$row['user_id'],'api_refresh_reuse',(int)$row['device_id'],'A refresh token was used again: the device session was ended / ip='.$this->api_client_ip());
        return $this->api_fail(ErrorMap::REFRESH_REUSED);
    }

    // ------------------------------------------------------------------ POST /auth/logout, GET /me, devices

    public function api_auth_logout($request){
        $s=self::$api_session;
        $this->api_revoke_device((int)$s['device_id'],'logout');
        $this->api_audit((int)$s['user_id'],'api_logout',(int)$s['device_id'],$s['user']->user_login.' / '.$s['platform'].' '.$s['model']);
        return $this->api_ok(['signed_out'=>true]);
    }

    public function api_me($request){
        $s=self::$api_session;$user=$s['user'];$emp=$s['employee'];
        $employee=null;
        if($emp){
            global $wpdb;
            $dept=!empty($emp->department_id)?$wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}ews_departments WHERE id=%d",(int)$emp->department_id)):null;
            $employee=['name'=>(string)$emp->name,'email'=>(string)($emp->email??''),'department'=>$dept!==null?(string)$dept:null,'attendance_enabled'=>(int)($emp->attendance_enabled??1)===1];
        }
        $permissions=[];
        foreach(array_keys($this->permission_defs()) as $cap){
            if(user_can($user,'manage_options')||user_can($user,$cap))$permissions[]=substr($cap,4);
        }
        $roles=[];
        foreach(array_keys($this->role_defs()) as $role){ if(in_array($role,(array)$user->roles,true))$roles[]=substr($role,4); }
        if(user_can($user,'manage_options'))$roles[]='site_administrator';
        return $this->api_ok([
            'user'=>['name'=>(string)$user->display_name,'email'=>(string)$user->user_email,'locale'=>get_user_locale($user)],
            'employee'=>$employee,
            'roles'=>$roles,
            'permissions'=>$permissions,
            'device'=>$this->api_device_view($s,true),
        ]);
    }

    public function api_me_devices($request){
        global $wpdb;$t=$this->api_tables();$s=self::$api_session;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id,public_id,platform,model,app_version,created_at,last_seen_at FROM {$t['devices']} WHERE user_id=%d AND revoked_at IS NULL AND expires_at>%s ORDER BY last_seen_at DESC,id DESC",(int)$s['user_id'],$this->api_utc(time())),ARRAY_A);
        $out=[];
        foreach((array)$rows as $r)$out[]=$this->api_device_view($r+['device_created_at'=>$r['created_at']],(int)$r['id']===(int)$s['device_id']);
        return $this->api_ok($out);
    }

    public function api_me_device_delete($request){
        global $wpdb;$t=$this->api_tables();$s=self::$api_session;
        $public=(string)$request->get_param('id');
        // Only the caller's own devices; anyone else's is "not found" (no hint that it exists).
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['devices']} WHERE public_id=%s AND user_id=%d AND revoked_at IS NULL LIMIT 1",$public,(int)$s['user_id']));
        if(!$id)return $this->api_fail(ErrorMap::NOT_FOUND);
        $this->api_revoke_device($id,'revoked_by_user');
        $this->api_audit((int)$s['user_id'],'api_device_revoked',$id,$s['user']->user_login.' signed out a device'.($id===(int)$s['device_id']?' (this one)':''));
        return $this->api_ok(['revoked'=>true,'current'=>$id===(int)$s['device_id']]);
    }

    /** @param array<string,mixed> $d */
    private function api_device_view(array $d,$current){
        return ['id'=>(string)$d['public_id'],'platform'=>(string)$d['platform'],'model'=>(string)$d['model'],'app_version'=>(string)$d['app_version'],
            'signed_in_at'=>$this->api_iso($d['device_created_at']??null),'last_seen_at'=>$this->api_iso($d['last_seen_at']??null),'current'=>(bool)$current];
    }

    // ------------------------------------------------------------------ device sessions and tokens

    /** @param array{installation_id:string,platform:string,model:string,app_version:string} $info @return array<string,mixed>|null */
    private function api_device_create(WP_User $user,array $info,$now){
        global $wpdb;$t=$this->api_tables();
        // Signing in again on the same app installation replaces that installation's session.
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['devices']} WHERE user_id=%d AND installation_id=%s AND revoked_at IS NULL",(int)$user->ID,$info['installation_id'])) as $old)$this->api_revoke_device((int)$old,'replaced');
        $ok=$wpdb->insert($t['devices'],[
            'public_id'=>bin2hex(random_bytes(16)),'user_id'=>(int)$user->ID,'installation_id'=>$info['installation_id'],'platform'=>$info['platform'],
            'model'=>$info['model'],'app_version'=>$info['app_version'],'created_at'=>$this->api_utc($now),'last_seen_at'=>$this->api_utc($now),
            'expires_at'=>$this->api_utc($now+Tokens::SESSION_MAX),
        ],['%s','%d','%s','%s','%s','%s','%s','%s','%s']);
        return $ok?$this->api_device((int)$wpdb->insert_id):null;
    }

    /** @return array<string,mixed>|null */
    private function api_device($id){
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->api_tables()['devices']} WHERE id=%d",(int)$id),ARRAY_A);
        return $row?:null;
    }

    /** @param array<string,mixed> $device @return array{access:string,access_expires:int,refresh:string,refresh_expires:int,issued_at:int} */
    private function api_issue_pair(array $device,$now){
        global $wpdb;$t=$this->api_tables();$site=$this->api_site_id();$session_end=$this->api_unix($device['expires_at']);
        $pair=['access'=>Tokens::make(Tokens::ACCESS,$site,random_bytes(32)),'access_expires'=>Tokens::accessExpiry($now,$session_end),
            'refresh'=>Tokens::make(Tokens::REFRESH,$site,random_bytes(32)),'refresh_expires'=>Tokens::refreshExpiry($now,$session_end),'issued_at'=>(int)$now];
        foreach([[Tokens::ACCESS,$pair['access'],$pair['access_expires']],[Tokens::REFRESH,$pair['refresh'],$pair['refresh_expires']]] as [$kind,$token,$exp]){
            $wpdb->insert($t['tokens'],['device_id'=>(int)$device['id'],'user_id'=>(int)$device['user_id'],'kind'=>$kind,'token_hash'=>Tokens::hash($token,$this->api_secret()),
                'expires_at'=>$this->api_utc($exp),'created_at'=>$this->api_utc($now)],['%d','%d','%s','%s','%s','%s']);
        }
        return $pair;
    }

    /** @param array{access:string,access_expires:int,refresh:string,refresh_expires:int,issued_at:int} $pair @param array<string,mixed> $device */
    private function api_session_payload(array $pair,array $device){
        $now=$pair['issued_at']; // the lifetimes as issued (time() here could be a second later: 899 instead of 900)
        return [
            'token_type'=>'Bearer',
            'access_token'=>$pair['access'],'expires_in'=>max(0,$pair['access_expires']-$now),
            'refresh_token'=>$pair['refresh'],'refresh_expires_in'=>max(0,$pair['refresh_expires']-$now),
            'device'=>$this->api_device_view($device+['device_created_at'=>$device['created_at']],true),
        ];
    }

    /** Ends a device session and every token of it. @return int 1 when it was active */
    private function api_revoke_device($device_id,$reason,$user_id=0){
        global $wpdb;$t=$this->api_tables();$now=$this->api_utc(time());
        $n=(int)$wpdb->query($wpdb->prepare("UPDATE {$t['devices']} SET revoked_at=%s,revoked_reason=%s WHERE id=%d AND revoked_at IS NULL",$now,substr((string)$reason,0,40),(int)$device_id));
        $wpdb->query($wpdb->prepare("UPDATE {$t['tokens']} SET revoked_at=%s WHERE device_id=%d AND revoked_at IS NULL",$now,(int)$device_id));
        if($user_id&&$n&&$reason==='account_disabled')$this->api_audit($user_id,'api_sessions_revoked',(int)$device_id,'account no longer active');
        return $n;
    }

    /**
     * Ends every device session of a user (password changed, user deleted, employee archived...).
     * This is also what a future "Sign out all devices" will call. @return int sessions ended
     */
    public function api_revoke_user_sessions($user_id,$reason){
        global $wpdb;$t=$this->api_tables();$user_id=(int)$user_id;
        if(!$user_id)return 0;
        $ids=(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['devices']} WHERE user_id=%d AND revoked_at IS NULL",$user_id));
        $n=0;
        foreach($ids as $id)$n+=$this->api_revoke_device((int)$id,$reason);
        if($n)$this->api_audit($user_id,'api_sessions_revoked',0,$n.' device session(s) ended: '.$reason);
        return $n;
    }

    // ------------------------------------------------------------------ revocation hooks (src/Api/Hooks.php)

    /** wp_set_password (WordPress 6.2+): any password change or reset, including wp_update_user(). */
    public function api_on_password_set($password,$user_id){ $this->api_revoke_user_sessions((int)$user_id,'password_changed'); }

    /** after_password_reset (WordPress 4.4+): the "lost password" reset, for WordPress 6.0 / 6.1. */
    public function api_on_password_reset($user){ if($user instanceof WP_User)$this->api_revoke_user_sessions((int)$user->ID,'password_changed'); }

    /** profile_update: a profile save that changed the password hash, for WordPress 6.0 / 6.1. */
    public function api_on_profile_update($user_id,$old_user_data=null){
        if(!$old_user_data instanceof WP_User)return;
        $new=get_userdata((int)$user_id);
        if($new && $new->user_pass!==$old_user_data->user_pass)$this->api_revoke_user_sessions((int)$user_id,'password_changed');
    }

    public function api_on_user_deleted($user_id){ $this->api_revoke_user_sessions((int)$user_id,'user_deleted'); }

    public function api_on_user_removed_from_site($user_id){ $this->api_revoke_user_sessions((int)$user_id,'removed_from_site'); }

    // ------------------------------------------------------------------ login rate limits

    /** @return array{window_start:int,count:int}|null */
    private function api_limit_row($bucket,$key){
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT window_start,count FROM {$this->api_tables()['limits']} WHERE bucket=%s AND key_hash=%s LIMIT 1",$bucket,$key),ARRAY_A);
        return $r?['window_start'=>$this->api_unix($r['window_start']),'count'=>(int)$r['count']]:null;
    }

    /** Counts one failure; returns the bucket's count in the current window. */
    private function api_limit_hit($bucket,$key,$now){
        global $wpdb;$table=$this->api_tables()['limits'];
        $row=$this->api_limit_row($bucket,$key);
        if(LoginThrottle::count($row,$now)>0){
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET count=count+1 WHERE bucket=%s AND key_hash=%s",$bucket,$key));
            return $row['count']+1;
        }
        // A new window (the unique key makes a concurrent first failure update instead).
        if($row)$wpdb->update($table,['window_start'=>$this->api_utc($now),'count'=>1],['bucket'=>$bucket,'key_hash'=>$key],['%s','%d'],['%s','%s']);
        else{
            $prev=$wpdb->suppress_errors(true);
            $ok=$wpdb->insert($table,['bucket'=>$bucket,'key_hash'=>$key,'window_start'=>$this->api_utc($now),'count'=>1],['%s','%s','%s','%d']);
            $wpdb->suppress_errors($prev);
            if(!$ok){$wpdb->query($wpdb->prepare("UPDATE {$table} SET count=count+1 WHERE bucket=%s AND key_hash=%s",$bucket,$key));return (int)($this->api_limit_row($bucket,$key)['count']??1);}
        }
        return 1;
    }

    private function api_limit_clear($bucket,$key){
        global $wpdb;
        $wpdb->delete($this->api_tables()['limits'],['bucket'=>$bucket,'key_hash'=>$key],['%s','%s']);
    }

    // ------------------------------------------------------------------ cleanup (daily, ews_notifications_cleanup)

    /**
     * Expired tokens go a day after they expire (a used refresh token stays until then, so reusing it
     * is still recognised as a reuse); device sessions 90 days after they ended (the list of signed-in
     * devices and the Audit Log keep the history); rate-limit buckets a day after their window.
     */
    public function api_auth_cleanup(){
        global $wpdb;$t=$this->api_tables();$now=time();
        $this->ensure_api_auth_schema();
        $wpdb->query($wpdb->prepare("DELETE FROM {$t['tokens']} WHERE expires_at < %s",$this->api_utc($now-DAY_IN_SECONDS)));
        $old=$this->api_utc($now-90*DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$t['devices']} WHERE (revoked_at IS NOT NULL AND revoked_at < %s) OR expires_at < %s",$old,$old));
        $wpdb->query($wpdb->prepare("DELETE FROM {$t['limits']} WHERE window_start < %s",$this->api_utc($now-DAY_IN_SECONDS)));
    }
}
