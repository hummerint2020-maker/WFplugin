<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\DailyWorkers\NationalId;
use WorkforceOne\DailyWorkers\PayRules;
use WorkforceOne\DailyWorkers\PayoutPdf;
use WorkforceOne\DailyWorkers\SiteRules;
use WorkforceOne\Settings\DailyWorkerSettings;
use WorkforceOne\Support\Download;

/**
 * Daily workers (3.31.76, Feature Configuration → Daily Workers).
 *
 * Day labourers with no email and often no smartphone, at project sites, paid daily or weekly in
 * cash. They live in their own tables (ews_dw_*), not in ews_employees: none of the staff screens,
 * pick lists, Sign In rules, Payroll or staff reports can ever list them, and nothing built for staff
 * had to change. A site is a work location (ews_locations) flagged as a project site (ews_dw_sites).
 *
 * - Foremen (permission "Foreman (daily workers)", assigned to sites) record the day sheet of each of
 *   their sites from inside the site (LocationAssessment rules) with a group photo from the camera;
 *   a saved day is locked, and a later change is a request with a reason that a manager approves
 *   (the original values stay in ews_dw_changes).
 * - Workers can sign in themselves (mobile + PIN, no WordPress account) where the company or the
 *   site allows it (DailyWorkerSettings::MODES).
 * - Pay: src/DailyWorkers/PayRules.php; the payout sheet PDF: src/DailyWorkers/PayoutPdf.php. A paid
 *   period is locked; amounts never go to the Audit Log.
 * - National IDs: src/DailyWorkers/NationalId.php — sealed with a key derived from the wp-config
 *   salts (never stored), searched by a keyed hash, shown masked; the full number only with "View
 *   national IDs", and every reveal or export is audited.
 *
 * Behaviour: tests/e2e_daily_workers.py.
 */
trait EWS_Daily_Workers_Trait {

    private function dw_enabled(){ return (bool)$this->option('ews_feature_daily_workers'); }

    private function dw_settings(){ return DailyWorkerSettings::config(get_option('ews_dw_settings',[])); }

    /** A daily workers table: workers, sites, foremen, moves, days, sheets, advances, payouts, lines, changes. */
    private function dw_t($name){ global $wpdb; return $wpdb->prefix.'ews_dw_'.$name; }

    /** A 32-byte key for one purpose, derived from the wp-config salts (never stored). */
    private function dw_key($purpose){
        $salt=defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : wp_salt('auth');
        return hash('sha256',$salt.'|workforce-one|daily-workers-'.$purpose.'-v1',true);
    }

    private function ensure_dw_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->dw_t('workers')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            mobile VARCHAR(40) NULL,
            mobile_norm VARCHAR(20) NULL,
            trade VARCHAR(60) NULL,
            daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            hourly_rate DECIMAL(10,2) NULL,
            subcontractor VARCHAR(60) NULL,
            notes TEXT NULL,
            rating TINYINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            nid_kind VARCHAR(8) NULL,
            nid_enc TEXT NULL,
            nid_hash CHAR(64) NULL,
            nid_first VARCHAR(4) NULL,
            nid_last VARCHAR(4) NULL,
            nid_length TINYINT UNSIGNED NULL,
            birth_date DATE NULL,
            governorate CHAR(2) NULL,
            photo_key VARCHAR(64) NULL,
            pin_hash VARCHAR(255) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY nid_hash (nid_hash),
            KEY mobile_norm (mobile_norm),
            KEY status (status)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('sites')} (
            location_id BIGINT UNSIGNED NOT NULL,
            project VARCHAR(190) NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            mode VARCHAR(10) NOT NULL DEFAULT '',
            period VARCHAR(10) NOT NULL DEFAULT '',
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (location_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('foremen')} (
            location_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (location_id,user_id),
            KEY user_id (user_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('moves')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            from_date DATE NOT NULL,
            by_user BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY worker_date (worker_id,from_date),
            KEY location_id (location_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('days')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            mark VARCHAR(6) NOT NULL,
            extra_hours DECIMAL(4,1) NOT NULL DEFAULT 0,
            daily_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            source VARCHAR(10) NOT NULL DEFAULT 'foreman',
            sheet_id BIGINT UNSIGNED NULL,
            payout_id BIGINT UNSIGNED NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            by_user BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY worker_date (worker_id,work_date),
            KEY site_date (location_id,work_date),
            KEY payout_id (payout_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('sheets')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            by_user BIGINT UNSIGNED NOT NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            accuracy DECIMAL(10,2) NULL,
            distance_meters DECIMAL(12,2) NULL,
            integrity_status VARCHAR(30) NULL,
            photo_key VARCHAR(64) NULL,
            saved_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_date (location_id,work_date)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('advances')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            given_on DATE NOT NULL,
            note VARCHAR(190) NULL,
            by_user BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY worker_id (worker_id),
            KEY location_id (location_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('payouts')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            location_id BIGINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            advance DECIMAL(12,2) NOT NULL DEFAULT 0,
            net DECIMAL(12,2) NOT NULL DEFAULT 0,
            photo_key VARCHAR(64) NULL,
            paid_by BIGINT UNSIGNED NOT NULL,
            paid_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_period (location_id,period_start)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('lines')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payout_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            days DECIMAL(5,1) NOT NULL DEFAULT 0,
            extra_hours DECIMAL(6,1) NOT NULL DEFAULT 0,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            advance DECIMAL(10,2) NOT NULL DEFAULT 0,
            net DECIMAL(10,2) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY payout_id (payout_id),
            KEY worker_id (worker_id)
        ) $c;");
        dbDelta("CREATE TABLE {$this->dw_t('changes')} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL,
            location_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            old_mark VARCHAR(6) NOT NULL,
            old_extra DECIMAL(4,1) NOT NULL DEFAULT 0,
            new_mark VARCHAR(6) NOT NULL,
            new_extra DECIMAL(4,1) NOT NULL DEFAULT 0,
            reason TEXT NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'pending',
            requested_by BIGINT UNSIGNED NOT NULL,
            requested_at DATETIME NOT NULL,
            decided_by BIGINT UNSIGNED NULL,
            decided_at DATETIME NULL,
            note TEXT NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY day_id (day_id)
        ) $c;");
    }

    private function ensure_dw_schema_once(){
        static $done=false;
        if($done)return;
        $done=true;
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->dw_t('changes')))!==$this->dw_t('changes'))$this->ensure_dw_schema();
    }

    /* ------------------------------------------------------------------ who may do what */

    private function dw_is_manager(){ return $this->can('ews_manage_daily_workers'); }

    private function dw_is_foreman(){ return $this->can('ews_dw_foreman'); }

    private function dw_is_cashier(){ return $this->can('ews_dw_cashier'); }

    /** Location ids of the current user's sites (managers: every active site). @return int[] */
    private function dw_my_site_ids(){
        global $wpdb;
        if($this->dw_is_manager())return array_map('intval',(array)$wpdb->get_col("SELECT location_id FROM {$this->dw_t('sites')} WHERE active=1"));
        if(!$this->dw_is_foreman() && !$this->dw_is_cashier())return [];
        return array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT f.location_id FROM {$this->dw_t('foremen')} f JOIN {$this->dw_t('sites')} s ON s.location_id=f.location_id AND s.active=1 WHERE f.user_id=%d",get_current_user_id())));
    }

    /** May the current user record this site's day sheet? Its foremen and the managers. */
    private function dw_can_record($location_id){
        if(!$this->dw_is_manager() && !$this->dw_is_foreman())return false;
        return in_array((int)$location_id,$this->dw_my_site_ids(),true);
    }

    /** May the current user pay this site? Its foremen, cashiers assigned to it, managers; cashiers without a site: every site. */
    private function dw_can_pay($location_id){
        if($this->dw_is_manager())return in_array((int)$location_id,$this->dw_my_site_ids(),true);
        if($this->dw_is_cashier()){
            global $wpdb;
            $assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('foremen')} WHERE user_id=%d",get_current_user_id()));
            if(!$assigned)return (bool)$this->dw_site((int)$location_id);
        }
        return in_array((int)$location_id,$this->dw_my_site_ids(),true);
    }

    private function dw_can_view_ids(){ return current_user_can('ews_view_national_ids'); }

    /* ------------------------------------------------------------------ lookups */

    /** A project site with its location (name, coordinates, radius) and its own mode / period. */
    private function dw_site($location_id){
        global $wpdb;
        static $cache=[];
        $id=(int)$location_id;
        if(!array_key_exists($id,$cache)){
            $s=$wpdb->get_row($wpdb->prepare("SELECT s.*,l.name,l.latitude,l.longitude,l.radius FROM {$this->dw_t('sites')} s JOIN {$this->locations} l ON l.id=s.location_id AND l.active=1 WHERE s.location_id=%d AND s.active=1",$id));
            if($s){
                $r=DailyWorkerSettings::forSite($this->dw_settings(),(string)$s->mode,(string)$s->period);
                $s->eff_mode=$r['mode'];$s->eff_period=$r['period'];
            }
            $cache[$id]=$s?:null;
        }
        return $cache[$id];
    }

    /** @return array<int,object> active project sites by location id */
    private function dw_sites(){
        global $wpdb;
        $out=[];
        foreach((array)$wpdb->get_results("SELECT s.location_id FROM {$this->dw_t('sites')} s JOIN {$this->locations} l ON l.id=s.location_id AND l.active=1 WHERE s.active=1 ORDER BY l.name") as $r){
            $s=$this->dw_site((int)$r->location_id);
            if($s)$out[(int)$s->location_id]=$s;
        }
        return $out;
    }

    private function dw_worker($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('workers')} WHERE id=%d",(int)$id));
    }

    /** SQL: the site a worker (column $col) is at on a date (the last move on or before it). */
    private function dw_site_sql($col,$date){
        global $wpdb;
        return $wpdb->prepare("(SELECT m.location_id FROM {$this->dw_t('moves')} m WHERE m.worker_id={$col} AND m.from_date<=%s ORDER BY m.from_date DESC,m.id DESC LIMIT 1)",$date);
    }

    private function dw_worker_site($worker_id,$date){
        global $wpdb;
        return (int)$wpdb->get_var("SELECT ".$this->dw_site_sql((int)$worker_id,$date));
    }

    /**
     * The workers of a site on a date: active workers whose site that day is this one, plus anyone
     * already recorded there that day. Each with ->day (the day row or null).
     * @return array<int,object>
     */
    private function dw_site_workers($location_id,$date){
        global $wpdb;
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT w.* FROM {$this->dw_t('workers')} w WHERE (w.status='active' AND ".$this->dw_site_sql('w.id',$date)."=%d) OR w.id IN (SELECT d.worker_id FROM {$this->dw_t('days')} d WHERE d.location_id=%d AND d.work_date=%s) ORDER BY w.name ASC",(int)$location_id,(int)$location_id,$date));
        $days=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->dw_t('days')} WHERE work_date=%s AND worker_id IN (SELECT id FROM {$this->dw_t('workers')})",$date)) as $d)$days[(int)$d->worker_id]=$d;
        $out=[];
        foreach($rows as $w){$w->day=$days[(int)$w->id]??null;$out[(int)$w->id]=$w;}
        return $out;
    }

    private function dw_sheet($location_id,$date){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('sheets')} WHERE location_id=%d AND work_date=%s",(int)$location_id,$date));
    }

    /** The paid payout covering a date at a site, or null. */
    private function dw_paid_for($location_id,$date){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('payouts')} WHERE location_id=%d AND period_start<=%s AND period_end>=%s LIMIT 1",(int)$location_id,$date,$date));
    }

    /** Hourly rate for extra hours: the worker's own, else the company default. */
    private function dw_hourly($w){
        return ($w->hourly_rate!==null && (float)$w->hourly_rate>0)?(float)$w->hourly_rate:(float)$this->dw_settings()['hourly_rate'];
    }

    /** A trade's label: the built-in ones translated, the company's own as entered. */
    private function dw_trade_label($trade){
        $t=(string)$trade;
        $map=['Formwork carpenter'=>__('Formwork carpenter','workforce-one'),'Steel fixer'=>__('Steel fixer','workforce-one'),'Helper'=>__('Helper','workforce-one'),
            'Plasterer'=>__('Plasterer','workforce-one'),'Plumber'=>__('Plumber','workforce-one'),'Electrician'=>__('Electrician','workforce-one'),'Painter'=>__('Painter','workforce-one')];
        return $map[$t]??$t;
    }

    private function dw_money($v){
        $v=(float)$v;
        $n=number_format_i18n($v,abs($v-round($v))>0.001?2:0);
        /* translators: %s: amount */
        return sprintf(__('EGP %s','workforce-one'),$n);
    }

    private function dw_days_text($days){
        $d=(float)$days;
        $n=abs($d-round($d))>0.01?number_format_i18n($d,1):number_format_i18n($d);
        /* translators: %s: number of days, may be 4.5 */
        return sprintf(__('%s days','workforce-one'),$n);
    }

    private function dw_mark_label($mark){
        return ['in'=>__('Present','workforce-one'),'half'=>__('Half day','workforce-one'),'out'=>__('Absent','workforce-one')][$mark]??(string)$mark;
    }

    private function dw_mode_label($mode){
        return ['foreman'=>__('Foreman only records attendance','workforce-one'),'self'=>__('Workers sign in themselves (mobile + PIN, no email)','workforce-one'),'both'=>__('Both','workforce-one')][$mode]??'';
    }

    private function dw_period_label($p){ return $p==='daily'?__('Daily','workforce-one'):__('Weekly','workforce-one'); }

    private function dw_initials($name){
        $out='';
        foreach(array_slice(preg_split('/\s+/u',trim((string)$name))?:[],0,2) as $p)$out.=mb_substr($p,0,1);
        return $out;
    }

    /** The masked national ID, or '' when none. */
    private function dw_nid_masked($w){
        if(!$w || !$w->nid_hash)return '';
        $m=NationalId::mask((string)$w->nid_first,(string)$w->nid_last,(int)$w->nid_length);
        return $w->nid_kind==='other'?__('Other ID','workforce-one').' '.$m:$m;
    }

    /** The full national ID: only with "View national IDs"; every reveal is in the Audit Log. */
    private function dw_nid_reveal($w,$where){
        if(!$w || !$w->nid_enc || !$this->dw_can_view_ids())return '';
        $plain=NationalId::open((string)$w->nid_enc,$this->dw_key('nid-seal'));
        if($plain===null)return '';
        $this->audit('dw_national_id_view','daily_worker',(int)$w->id,'National ID shown: '.$w->name.' ('.$where.')');
        return $plain;
    }

    /* ------------------------------------------------------------------ files (photos) */

    private function dw_file_dir(){
        $up=wp_upload_dir();$dir=trailingslashit($up['basedir']).'workforce-one-daily-workers';
        if(!is_dir($dir)){
            wp_mkdir_p($dir);
            // Not reachable by URL: every photo goes through ews_dw_file (access checked).
            @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/index.php',"<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/web.config','<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $dir;
    }

    /** @param mixed $f an entry of $_FILES @return array{0:?string,1:string} [key, error: '' | photo | photo_file | photo_size] */
    private function dw_save_photo($f){
        if(!is_array($f) || (int)($f['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return [null,'photo'];
        if((int)$f['error']!==UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']))return [null,'photo_file'];
        if((int)$f['size']>10*1048576)return [null,'photo_size'];
        $mimes=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        $check=wp_check_filetype_and_ext((string)$f['tmp_name'],sanitize_file_name(wp_basename((string)$f['name'])),$mimes);
        if(empty($check['ext']) || !isset($mimes[strtolower((string)$check['ext'])]))return [null,'photo_file'];
        $key=wp_generate_password(32,false).'.'.strtolower((string)$check['ext']);
        if(!@move_uploaded_file((string)$f['tmp_name'],$this->dw_file_dir().'/'.$key))return [null,'photo_file']; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,Generic.PHP.ForbiddenFunctions.Found
        return [$key,''];
    }

    private function dw_delete_file($key){
        if($key && preg_match('/^[A-Za-z0-9]{32}\.[a-z]{3,4}$/',(string)$key))@unlink($this->dw_file_dir().'/'.$key); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /** A photo: kind sheet (the day's group photo), payout (the signed sheet) or worker. */
    private function dw_file_url($kind,$id){
        return wp_nonce_url(add_query_arg(['action'=>'ews_dw_file','kind'=>$kind,'id'=>(int)$id],admin_url('admin-post.php')),'ews_dw_file_'.$kind.'_'.(int)$id);
    }

    public function dw_file(){
        if(!is_user_logged_in())auth_redirect();
        $kind=sanitize_key($_GET['kind']??'');$id=absint($_GET['id']??0);
        check_admin_referer('ews_dw_file_'.$kind.'_'.$id);
        global $wpdb;$key='';$ok=false;
        if($kind==='sheet'){$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('sheets')} WHERE id=%d",$id));if($r){$key=(string)$r->photo_key;$ok=$this->dw_can_record((int)$r->location_id)||$this->dw_can_pay((int)$r->location_id);}}
        elseif($kind==='payout'){$r=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('payouts')} WHERE id=%d",$id));if($r){$key=(string)$r->photo_key;$ok=$this->dw_can_pay((int)$r->location_id);}}
        elseif($kind==='worker'){$r=$this->dw_worker($id);if($r){$key=(string)$r->photo_key;$ok=$this->dw_is_manager()||($this->dw_is_foreman()&&in_array($this->dw_worker_site($id,current_time('Y-m-d')),$this->dw_my_site_ids(),true));}}
        if(!$ok)wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        if(!preg_match('/^[A-Za-z0-9]{32}\.[a-z]{3,4}$/',$key))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $path=$this->dw_file_dir().'/'.$key;
        if(!is_readable($path))wp_die(esc_html__('File not found.','workforce-one'),'',['response'=>404]);
        $ext=strtolower((string)pathinfo($key,PATHINFO_EXTENSION));
        $all=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
        Download::send((string)file_get_contents($path),$all[$ext]??'application/octet-stream',$kind.'-'.$id.'.'.$ext); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    }

    /* ------------------------------------------------------------------ messages */

    private function dw_error_message($code){
        $m=[
            'disabled'=>__('Daily workers are switched off.','workforce-one'),
            'access'=>__('You do not have access to this site.','workforce-one'),
            'site'=>__('This site has no map position. Ask the site manager to set it in Work Locations.','workforce-one'),
            'location'=>__('Your position could not be read. Turn on location (GPS) and try again.','workforce-one'),
            'suspicious'=>__('Your position does not look real (it moved too fast). Try again from the site.','workforce-one'),
            'outside'=>__('You are outside the site. The day sheet is saved from inside the site only.','workforce-one'),
            'photo'=>__('Take the group photo first. A photo is needed to confirm the day, from the camera, not the gallery.','workforce-one'),
            'photo_file'=>__('The photo could not be read. Take it again (JPG, PNG or WebP).','workforce-one'),
            'photo_size'=>__('The photo is larger than 10 MB.','workforce-one'),
            'locked'=>__('This day is already saved and locked. Ask for a change instead.','workforce-one'),
            'paid'=>__('This period is paid and locked.','workforce-one'),
            'mode_foreman'=>__('At this site the workers sign in themselves; the foreman does not record the day.','workforce-one'),
            'mode_self'=>__('At this site the foreman records the day; workers do not sign in themselves.','workforce-one'),
            'mark'=>__('Choose present, half day or absent for every worker.','workforce-one'),
            'extra'=>__('Extra hours are between 0 and 12, in half hours.','workforce-one'),
            'empty'=>__('There are no workers at this site today.','workforce-one'),
            'elsewhere'=>__('A worker in the list was already recorded at another site today.','workforce-one'),
            'name'=>__('Enter the worker\'s name.','workforce-one'),
            'rate'=>__('Enter a daily rate above zero.','workforce-one'),
            'hourly'=>__('The hourly rate cannot be negative.','workforce-one'),
            'mobile'=>__('The mobile number is not valid (8 to 15 digits).','workforce-one'),
            'mobile_taken'=>__('Another active worker has this mobile number.','workforce-one'),
            'nid_digits'=>__('The national ID is not valid: it must contain digits only.','workforce-one'),
            'nid_length'=>__('The national ID is not valid: it must be 14 digits.','workforce-one'),
            'nid_century'=>__('The national ID is not valid: the first digit must be 2 or 3.','workforce-one'),
            'nid_birth'=>__('The national ID is not valid: the birth date in it is not a real date.','workforce-one'),
            'nid_governorate'=>__('The national ID is not valid: the governorate code is unknown.','workforce-one'),
            'nid_other'=>__('Enter the ID number (4 to 30 letters or digits).','workforce-one'),
            'nid_taken'=>__('A worker with this national ID is already registered.','workforce-one'),
            'no_rehire'=>__('This national ID belongs to a worker marked "Do not rehire".','workforce-one'),
            'pin'=>__('The PIN must be 4 to 6 digits.','workforce-one'),
            'trade'=>__('Choose a trade from the list.','workforce-one'),
            'date'=>__('Choose today or a later day.','workforce-one'),
            'same'=>__('The worker is already at that site.','workforce-one'),
            'amount'=>__('Enter an amount above zero.','workforce-one'),
            'nothing'=>__('There is nothing to pay in this period.','workforce-one'),
            'reason'=>__('Write the reason for the change.','workforce-one'),
            'no_change'=>__('Nothing was changed.','workforce-one'),
            'pending'=>__('A change for this day is already waiting.','workforce-one'),
            'note'=>__('Write why you reject the change.','workforce-one'),
            'done'=>__('This change was already decided.','workforce-one'),
            'has_paid'=>__('This worker has paid days, so the record stays for the accounts. Mark him "Do not rehire" instead.','workforce-one'),
            'worker'=>__('Worker not found.','workforce-one'),
            'login'=>__('The mobile number or PIN is incorrect.','workforce-one'),
            'too_many'=>__('Too many attempts. Try again in 15 minutes.','workforce-one'),
            'no_site'=>__('You are not assigned to a site today. Ask your foreman.','workforce-one'),
            'recorded'=>__('Your day is already recorded.','workforce-one'),
            'save'=>__('Could not save. Please try again.','workforce-one'),
        ];
        return $m[$code]??__('Could not save. Please try again.','workforce-one');
    }

    /** Back to where the form was, with the outcome in the query. */
    private function dw_back($args,$view='sites'){
        $url=wp_get_referer();
        if(!$url||strpos($url,'admin-post.php')!==false)$url=add_query_arg('ews_view',$view,$this->app_home_url());
        $url=remove_query_arg(['dw_error','dw_saved','dw_done','dw_moved','dw_added','dw_paid','dw_adv','dw_change','dw_open'],$url);
        wp_safe_redirect(add_query_arg($args,$url));exit;
    }

    /** The popup after an app form (ux_notice_from_query). */
    private function dw_notice(){
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        if(isset($_GET['dw_error']))return $this->ux_modal(__('Not saved','workforce-one'),$this->dw_error_message(sanitize_key($_GET['dw_error'])),'error');
        if(isset($_GET['dw_saved'])){
            global $wpdb;
            $sheet=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('sheets')} WHERE id=%d",absint($_GET['dw_saved'])));
            if(!$sheet)return '';
            $c=$wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n,SUM(mark='in') p,SUM(mark='half') h,SUM(mark='out') a,SUM(amount) t FROM {$this->dw_t('days')} WHERE location_id=%d AND work_date=%s",(int)$sheet->location_id,$sheet->work_date));
            $site=$this->dw_site((int)$sheet->location_id);
            /* translators: 1: workers, 2: site, 3: present, 4: half day, 5: absent, 6: amount */
            $msg=sprintf(__('%1$d workers at %2$s: %3$d present, %4$d half day, %5$d absent. Cost of the day %6$s.','workforce-one'),(int)$c->n,$site?$site->name:'',(int)$c->p,(int)$c->h,(int)$c->a,$this->dw_money((float)$c->t));
            return $this->ux_modal(__('Day sheet saved','workforce-one'),$msg,'success');
        }
        if(isset($_GET['dw_added']))return $this->ux_modal(__('Worker added','workforce-one'),__('No email or account needed. He is in today\'s list of this site.','workforce-one'),'success');
        if(isset($_GET['dw_moved']))return $this->ux_modal(__('Worker moved','workforce-one'),__('His days at the old site stay in his history and in that project\'s cost.','workforce-one'),'success');
        if(isset($_GET['dw_adv']))return $this->ux_modal(__('Advance recorded','workforce-one'),__('It comes off his next payout.','workforce-one'),'success');
        if(isset($_GET['dw_paid']))return $this->ux_modal(__('Payout recorded','workforce-one'),__('The period is paid and locked. A new advance comes off the next payout.','workforce-one'),'success');
        if(isset($_GET['dw_change']))return $this->ux_modal(__('Change requested','workforce-one'),__('The day stays as saved until a manager approves the change.','workforce-one'),'success');
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return '';
    }

    /* ------------------------------------------------------------------ workers */

    private function dw_norm_mobile($m){
        $m=NationalId::normalize((string)$m);
        $m=(string)preg_replace('/[^0-9+]/','',$m);
        if(strpos($m,'+20')===0)$m='0'.substr($m,3);
        elseif(strpos($m,'0020')===0)$m='0'.substr($m,4);
        return ltrim($m,'+');
    }

    /** The worker fields from a form or the API. @param array<string,mixed> $src */
    private function dw_input(array $src){
        $g=function($k)use($src){return trim(sanitize_text_field(wp_unslash((string)($src[$k]??''))));};
        return ['name'=>$g('name'),'mobile'=>$g('mobile'),'trade'=>$g('trade'),'daily_rate'=>$g('daily_rate'),'hourly_rate'=>$g('hourly_rate'),
            'subcontractor'=>$g('subcontractor'),'notes'=>trim(sanitize_textarea_field(wp_unslash((string)($src['notes']??'')))),'rating'=>$g('rating'),'status'=>sanitize_key((string)($src['status']??'')),
            'nid'=>$g('nid'),'nid_kind'=>sanitize_key((string)($src['nid_kind']??'eg')),'pin'=>$g('pin')];
    }

    /**
     * Creates or updates a worker. National ID: '' keeps the saved one (on an update).
     * @param array<string,mixed> $in dw_input()
     * @return array{id?:int,error?:string}
     */
    private function dw_save_worker(array $in,$id=0,$full=false){
        global $wpdb;
        $s=$this->dw_settings();
        if($in['name']==='')return ['error'=>'name'];
        if(!is_numeric($in['daily_rate']) || (float)$in['daily_rate']<=0)return ['error'=>'rate'];
        if($in['hourly_rate']!=='' && (!is_numeric($in['hourly_rate']) || (float)$in['hourly_rate']<0))return ['error'=>'hourly'];
        $mobile=$in['mobile']!==''?$this->dw_norm_mobile($in['mobile']):'';
        if($in['mobile']!=='' && !preg_match('/^[0-9]{8,15}$/',$mobile))return ['error'=>'mobile'];
        if($mobile!=='' && (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('workers')} WHERE mobile_norm=%s AND status='active' AND id<>%d",$mobile,(int)$id)))return ['error'=>'mobile_taken'];
        if($in['trade']!=='' && !in_array($in['trade'],$s['trades'],true)){
            $old=$id?$this->dw_worker($id):null;
            if(!$old || (string)$old->trade!==$in['trade'])return ['error'=>'trade'];
        }
        if($in['pin']!=='' && !preg_match('/^[0-9]{4,6}$/',NationalId::normalize($in['pin'])))return ['error'=>'pin'];
        $now=current_time('mysql');
        $row=['name'=>mb_substr($in['name'],0,190),'mobile'=>$in['mobile']!==''?mb_substr($in['mobile'],0,40):null,'mobile_norm'=>$mobile!==''?$mobile:null,
            'trade'=>$in['trade']!==''?$in['trade']:null,'daily_rate'=>round((float)$in['daily_rate'],2),
            'hourly_rate'=>$in['hourly_rate']!==''?round((float)$in['hourly_rate'],2):null,
            'subcontractor'=>$in['subcontractor']!==''?mb_substr($in['subcontractor'],0,60):null,'updated_at'=>$now];
        if($full){
            $row['notes']=$in['notes']!==''?mb_substr($in['notes'],0,2000):null;
            $row['rating']=max(0,min(5,(int)$in['rating']));
            $row['status']=$in['status']==='no_rehire'?'no_rehire':'active';
        }
        if($in['pin']!=='')$row['pin_hash']=wp_hash_password(NationalId::normalize($in['pin']));
        if($in['nid']!==''){
            $kind=$in['nid_kind']==='other'?'other':'eg';
            $v=NationalId::normalize($in['nid']);
            $birth=null;$gov=null;
            if($kind==='eg'){
                $chk=NationalId::checkEgyptian($v,current_time('Y-m-d'));
                if(!$chk['ok'])return ['error'=>'nid_'.$chk['error']];
                $birth=$chk['birth'];$gov=$chk['governorate'];
            }elseif(!preg_match('/^[A-Za-z0-9]{4,30}$/',$v))return ['error'=>'nid_other'];
            $hash=NationalId::hash($v,$this->dw_key('nid-hash'));
            $dup=$wpdb->get_row($wpdb->prepare("SELECT id,status FROM {$this->dw_t('workers')} WHERE nid_hash=%s AND id<>%d LIMIT 1",$hash,(int)$id));
            if($dup)return ['error'=>$dup->status==='no_rehire'?'no_rehire':'nid_taken','worker'=>(int)$dup->id];
            $p=NationalId::maskParts($v);
            $row+=['nid_kind'=>$kind,'nid_enc'=>NationalId::seal($v,$this->dw_key('nid-seal')),'nid_hash'=>$hash,'nid_first'=>$p['first'],'nid_last'=>$p['last'],
                'nid_length'=>$p['length'],'birth_date'=>$birth,'governorate'=>$gov];
        }
        if($id){
            if($wpdb->update($this->dw_t('workers'),$row,['id'=>(int)$id])===false)return ['error'=>'save'];
            return ['id'=>(int)$id];
        }
        $row+=['created_by'=>get_current_user_id(),'created_at'=>$now,'status'=>'active'];
        if($wpdb->insert($this->dw_t('workers'),$row)===false)return ['error'=>'save'];
        return ['id'=>(int)$wpdb->insert_id];
    }

    /** A worker's code, shown instead of an account name. */
    private function dw_code($id){ return 'dw-'.(int)$id; }

    /** Puts a worker at a site from a date (the history stays). */
    private function dw_move_to($worker_id,$location_id,$from){
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->dw_t('moves')} WHERE worker_id=%d AND from_date=%s",(int)$worker_id,$from));
        return $wpdb->insert($this->dw_t('moves'),['worker_id'=>(int)$worker_id,'location_id'=>(int)$location_id,'from_date'=>$from,'by_user'=>get_current_user_id(),'created_at'=>current_time('mysql')])!==false;
    }

    /** Quick-add on site (foreman): the worker is at this site from today. @return array{id?:int,error?:string} */
    private function dw_quick_add($location_id,array $in){
        if(!$this->dw_enabled())return ['error'=>'disabled'];
        if(!$this->dw_site($location_id) || !$this->dw_can_record($location_id))return ['error'=>'access'];
        $r=$this->dw_save_worker($in);
        if(isset($r['error']))return $r;
        $this->dw_move_to((int)$r['id'],(int)$location_id,current_time('Y-m-d'));
        $site=$this->dw_site($location_id);
        $this->audit('dw_worker_add','daily_worker',(int)$r['id'],$in['name'].' added at '.$site->name);
        return $r;
    }

    /* ------------------------------------------------------------------ the day sheet */

    /** Position fields from a form or the API. @param array<string,mixed> $src */
    private function dw_position(array $src){
        $f=function($k)use($src){$v=$src[$k]??'';return is_numeric($v)?(float)$v:null;};
        $ts=$src['location_timestamp']??'';
        return ['lat'=>$f('latitude'),'lng'=>$f('longitude'),'accuracy'=>$f('accuracy'),'ts'=>is_numeric($ts)?(int)$ts:null];
    }

    /** The last position the current user's sheets (or the worker's own sign-ins) had, for the movement check. */
    private function dw_previous_position($by_user,$worker_id=0){
        global $wpdb;
        $r=$worker_id
            ?$wpdb->get_row($wpdb->prepare("SELECT latitude,longitude,created_at at FROM {$this->dw_t('days')} WHERE worker_id=%d AND source='self' AND latitude IS NOT NULL ORDER BY id DESC LIMIT 1",(int)$worker_id))
            :$wpdb->get_row($wpdb->prepare("SELECT latitude,longitude,saved_at at FROM {$this->dw_t('sheets')} WHERE by_user=%d AND latitude IS NOT NULL ORDER BY id DESC LIMIT 1",(int)$by_user));
        if(!$r)return null;
        // Saved in site time; the movement check compares Unix milliseconds.
        $ts=strtotime(get_gmt_from_date((string)$r->at).' UTC');
        return $ts?['latitude'=>$r->latitude,'longitude'=>$r->longitude,'location_timestamp'=>$ts*1000]:null;
    }

    /**
     * Saves and locks today's sheet of a site.
     * @param array<int,string> $marks worker id => in | half | out
     * @param array<int,mixed>  $extra worker id => hours
     * @param array{lat:?float,lng:?float,accuracy:?float,ts:?int} $pos
     * @param mixed $photo an entry of $_FILES
     * @return array{id?:int,error?:string}
     */
    private function dw_save_sheet($location_id,array $marks,array $extra,array $pos,$photo){
        if(!$this->dw_enabled())return ['error'=>'disabled'];
        $this->ensure_dw_schema_once();
        $site=$this->dw_site($location_id);
        if(!$site || !$this->dw_can_record($location_id))return ['error'=>'access'];
        if(!SiteRules::allows($site->eff_mode,'foreman'))return ['error'=>'mode_foreman'];
        $date=current_time('Y-m-d');
        if($this->dw_sheet($location_id,$date))return ['error'=>'locked'];
        if($this->dw_paid_for($location_id,$date))return ['error'=>'paid'];
        $workers=$this->dw_site_workers($location_id,$date);
        if(!$workers)return ['error'=>'empty'];
        $plan=[];
        foreach($workers as $wid=>$w){
            // Recorded at another site today (moved there, or signed in there): not this sheet's.
            if($w->day && (int)$w->day->location_id!==(int)$location_id)continue;
            $mark=(string)($marks[$wid]??'');
            if(!in_array($mark,PayRules::MARKS,true))return ['error'=>'mark'];
            $x=PayRules::extra($extra[$wid]??0);
            if($x===null)return ['error'=>'extra'];
            $plan[$wid]=[$mark,$mark==='out'?0.0:$x];
        }
        if(!$plan)return ['error'=>'empty'];
        $loc=SiteRules::location($pos['lat'],$pos['lng'],$pos['accuracy'],$pos['ts'],time(),$site->latitude,$site->longitude,(float)max(1,(int)$site->radius),$this->dw_previous_position(get_current_user_id()));
        if($loc['error']!=='')return ['error'=>$loc['error']];
        $key=null;
        if((int)$this->dw_settings()['photo']){
            [$key,$perr]=$this->dw_save_photo($photo);
            if($perr!=='')return ['error'=>$perr];
        }
        global $wpdb;$now=current_time('mysql');
        $wpdb->query('START TRANSACTION');
        // The unique (site, date) key makes a second save of the same day fail here.
        $ok=$wpdb->insert($this->dw_t('sheets'),['location_id'=>(int)$location_id,'work_date'=>$date,'by_user'=>get_current_user_id(),'latitude'=>$pos['lat'],'longitude'=>$pos['lng'],
            'accuracy'=>$pos['accuracy'],'distance_meters'=>$loc['distance'],'integrity_status'=>$loc['integrity'],'photo_key'=>$key,'saved_at'=>$now]);
        if(!$ok){$wpdb->query('ROLLBACK');$this->dw_delete_file($key);return ['error'=>'locked'];}
        $sheet_id=(int)$wpdb->insert_id;
        foreach($plan as $wid=>[$mark,$x]){
            $w=$workers[$wid];
            $rate=(float)$w->daily_rate;$hr=$this->dw_hourly($w);
            $data=['mark'=>$mark,'extra_hours'=>$x,'daily_rate'=>$rate,'hourly_rate'=>$hr,'amount'=>PayRules::dayAmount($mark,$x,$rate,$hr),'source'=>'foreman','sheet_id'=>$sheet_id,'by_user'=>get_current_user_id(),'updated_at'=>$now];
            $r=$w->day
                ?$wpdb->update($this->dw_t('days'),$data,['id'=>(int)$w->day->id])
                :$wpdb->insert($this->dw_t('days'),$data+['worker_id'=>$wid,'location_id'=>(int)$location_id,'work_date'=>$date,'created_at'=>$now]);
            if($r===false){$wpdb->query('ROLLBACK');$this->dw_delete_file($key);return ['error'=>'save'];}
        }
        $wpdb->query('COMMIT');
        $this->audit('dw_sheet_save','daily_site',(int)$location_id,$site->name.' '.$date.': '.count($plan).' workers');
        return ['id'=>$sheet_id];
    }

    public function dw_sheet_save(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_sheet');
        $site=absint($_POST['site']??0);
        $marks=array_map(function($v){return sanitize_key((string)$v);},(array)wp_unslash($_POST['mark']??[]));
        $extra=array_map(function($v){return sanitize_text_field((string)$v);},(array)wp_unslash($_POST['extra']??[]));
        $r=$this->dw_save_sheet($site,array_combine(array_map('intval',array_keys($marks)),$marks)?:[],array_combine(array_map('intval',array_keys($extra)),$extra)?:[],$this->dw_position($_POST),$_FILES['photo']??null);
        if(isset($r['error']))$this->dw_back(['dw_error'=>$r['error']]);
        $this->dw_back(['dw_saved'=>(int)$r['id']]);
    }

    public function dw_worker_add(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_worker_add');
        $r=$this->dw_quick_add(absint($_POST['site']??0),$this->dw_input($_POST));
        if(isset($r['error']))$this->dw_back(['dw_error'=>$r['error'],'dw_open'=>'add']);
        $this->dw_back(['dw_added'=>(int)$r['id']]);
    }

    /** @return string '' or an error code */
    private function dw_move($worker_id,$to,$from){
        if(!$this->dw_enabled())return 'disabled';
        $w=$this->dw_worker($worker_id);
        if(!$w)return 'worker';
        $today=current_time('Y-m-d');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || $from<$today)return 'date';
        $now_site=$this->dw_worker_site((int)$w->id,$today);
        if(!$this->dw_is_manager() && !($now_site && $this->dw_can_record($now_site)))return 'access';
        if(!$this->dw_site($to))return 'access';
        if($this->dw_worker_site((int)$w->id,$from)===(int)$to)return 'same';
        if(!$this->dw_move_to((int)$w->id,(int)$to,$from))return 'save';
        $old=$now_site?$this->dw_site($now_site):null;
        $this->audit('dw_worker_move','daily_worker',(int)$w->id,$w->name.': '.($old?$old->name:'—').' -> '.$this->dw_site($to)->name.' from '.$from);
        return '';
    }

    public function dw_move_save(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_move');
        $e=$this->dw_move(absint($_POST['worker']??0),absint($_POST['to']??0),sanitize_text_field(wp_unslash((string)($_POST['from']??''))));
        if($e!=='')$this->dw_back(['dw_error'=>$e]);
        $this->dw_back(['dw_moved'=>1]);
    }

    /* ------------------------------------------------------------------ a change to a saved day */

    /** @return array{id?:int,error?:string} */
    private function dw_request_change($day_id,$mark,$extra,$reason){
        global $wpdb;
        if(!$this->dw_enabled())return ['error'=>'disabled'];
        $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('days')} WHERE id=%d",(int)$day_id));
        if(!$d || !$this->dw_can_record((int)$d->location_id))return ['error'=>'access'];
        if($d->payout_id || $this->dw_paid_for((int)$d->location_id,(string)$d->work_date))return ['error'=>'paid'];
        if(!in_array($mark,PayRules::MARKS,true))return ['error'=>'mark'];
        $x=PayRules::extra($extra);
        if($x===null)return ['error'=>'extra'];
        if($mark==='out')$x=0.0;
        if(trim((string)$reason)==='')return ['error'=>'reason'];
        if($mark===$d->mark && abs($x-(float)$d->extra_hours)<0.01)return ['error'=>'no_change'];
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('changes')} WHERE day_id=%d AND status='pending'",(int)$d->id)))return ['error'=>'pending'];
        $ok=$wpdb->insert($this->dw_t('changes'),['day_id'=>(int)$d->id,'worker_id'=>(int)$d->worker_id,'location_id'=>(int)$d->location_id,'work_date'=>$d->work_date,
            'old_mark'=>$d->mark,'old_extra'=>(float)$d->extra_hours,'new_mark'=>$mark,'new_extra'=>$x,'reason'=>mb_substr(trim((string)$reason),0,1000),
            'requested_by'=>get_current_user_id(),'requested_at'=>current_time('mysql')]);
        if(!$ok)return ['error'=>'save'];
        $id=(int)$wpdb->insert_id;
        $w=$this->dw_worker((int)$d->worker_id);
        $this->audit('dw_change_request','daily_worker',(int)$d->worker_id,($w?$w->name:'').' '.$d->work_date.': '.$d->mark.' -> '.$mark);
        foreach(get_users(['capability'=>'ews_manage_daily_workers','fields'=>['ID']]) as $u){
            if((int)$u->ID===get_current_user_id())continue;
            $this->notify((int)$u->ID,'daily_workers',__('Day sheet change to review','workforce-one'),
                /* translators: 1: worker, 2: date */
                sprintf(__('A foreman asks to change %1$s\'s day on %2$s.','workforce-one'),$w?$w->name:'',$d->work_date),['entity_id'=>$id,'url'=>admin_url('admin.php?page=ews31-daily-workers&tab=changes')]);
        }
        return ['id'=>$id];
    }

    public function dw_change_save(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_change');
        $r=$this->dw_request_change(absint($_POST['day']??0),sanitize_key((string)($_POST['mark']??'')),sanitize_text_field(wp_unslash((string)($_POST['extra']??'0'))),sanitize_textarea_field(wp_unslash((string)($_POST['reason']??''))));
        if(isset($r['error']))$this->dw_back(['dw_error'=>$r['error']]);
        $this->dw_back(['dw_change'=>(int)$r['id']]);
    }

    /** A manager's decision. @return string approved | rejected, or an error code */
    private function dw_decide_change($id,$decision,$note){
        global $wpdb;
        if(!$this->dw_is_manager())return 'access';
        $c=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('changes')} WHERE id=%d",(int)$id));
        if(!$c)return 'worker';
        if($c->status!=='pending')return 'done';
        if((int)$c->requested_by===get_current_user_id() && !current_user_can('manage_options'))return 'access';
        $note=trim((string)$note);
        if($decision==='reject' && $note==='')return 'note';
        $now=current_time('mysql');
        if($decision==='approve'){
            $d=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('days')} WHERE id=%d",(int)$c->day_id));
            if(!$d || $d->payout_id || $this->dw_paid_for((int)$d->location_id,(string)$d->work_date))return 'paid';
            $ok=$wpdb->update($this->dw_t('changes'),['status'=>'approved','decided_by'=>get_current_user_id(),'decided_at'=>$now,'note'=>$note!==''?$note:null],['id'=>(int)$id,'status'=>'pending']);
            if($ok!==1)return 'done';
            $wpdb->update($this->dw_t('days'),['mark'=>$c->new_mark,'extra_hours'=>(float)$c->new_extra,'amount'=>PayRules::dayAmount((string)$c->new_mark,(float)$c->new_extra,(float)$d->daily_rate,(float)$d->hourly_rate),'updated_at'=>$now],['id'=>(int)$d->id]);
            $this->audit('dw_change_approve','daily_worker',(int)$c->worker_id,$c->work_date.': '.$c->old_mark.' -> '.$c->new_mark);
        }else{
            $ok=$wpdb->update($this->dw_t('changes'),['status'=>'rejected','decided_by'=>get_current_user_id(),'decided_at'=>$now,'note'=>$note],['id'=>(int)$id,'status'=>'pending']);
            if($ok!==1)return 'done';
            $this->audit('dw_change_reject','daily_worker',(int)$c->worker_id,$c->work_date.': '.$note);
        }
        $this->notify((int)$c->requested_by,'daily_workers',$decision==='approve'?__('Day sheet change approved','workforce-one'):__('Day sheet change rejected','workforce-one'),
            $decision==='approve'?__('The day is changed. The original values are kept with the request.','workforce-one'):$note,['entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','sites',$this->app_home_url())]);
        return $decision==='approve'?'approved':'rejected';
    }

    public function dw_change_decide(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_dw_change_decide');
        $r=$this->dw_decide_change(absint($_POST['change']??0),sanitize_key((string)($_POST['decision']??'')),sanitize_textarea_field(wp_unslash((string)($_POST['note']??''))));
        $args=in_array($r,['approved','rejected'],true)?['done'=>$r]:['dw_error'=>$r];
        wp_safe_redirect(add_query_arg($args,$this->dw_admin_url(['tab'=>'changes'])));exit;
    }

    /* ------------------------------------------------------------------ advances and payouts */

    /** Advances not yet deducted, per worker (all sites), given on or before a date. @param int[] $worker_ids @return array<int,float> */
    private function dw_outstanding(array $worker_ids,$until){
        global $wpdb;
        $ids=array_values(array_filter(array_map('intval',$worker_ids)));
        if(!$ids)return [];
        $in=implode(',',$ids);
        $out=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT worker_id,SUM(amount) a FROM {$this->dw_t('advances')} WHERE worker_id IN ($in) AND given_on<=%s GROUP BY worker_id",$until)) as $r)$out[(int)$r->worker_id]=(float)$r->a;
        foreach((array)$wpdb->get_results("SELECT worker_id,SUM(advance) a FROM {$this->dw_t('lines')} WHERE worker_id IN ($in) GROUP BY worker_id") as $r)$out[(int)$r->worker_id]=($out[(int)$r->worker_id]??0)-(float)$r->a;
        return $out;
    }

    /**
     * A site's payout for a period: the paid one (locked lines) or what is owed now.
     * @return array{site:object,start:string,end:string,paid:?object,lines:array<int,array<string,mixed>>,amount:float,advance:float,net:float}
     */
    private function dw_payout($location_id,$start){
        global $wpdb;
        $site=$this->dw_site($location_id);
        [$start,$end]=PayRules::period($start,$site->eff_period,(int)$this->dw_settings()['week_start']);
        $paid=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('payouts')} WHERE location_id=%d AND period_start=%s",(int)$location_id,$start));
        $names=[];
        if($paid){
            $lines=[];
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT l.*,w.name,w.trade,w.daily_rate FROM {$this->dw_t('lines')} l LEFT JOIN {$this->dw_t('workers')} w ON w.id=l.worker_id WHERE l.payout_id=%d ORDER BY w.name",(int)$paid->id)) as $l){
                $lines[(int)$l->worker_id]=['worker_id'=>(int)$l->worker_id,'name'=>(string)$l->name,'trade'=>(string)$l->trade,'rate'=>(float)$l->daily_rate,'days'=>(float)$l->days,'extra_hours'=>(float)$l->extra_hours,'amount'=>(float)$l->amount,'advance'=>(float)$l->advance,'net'=>(float)$l->net];
            }
            return ['site'=>$site,'start'=>$start,'end'=>$end,'paid'=>$paid,'lines'=>$lines,'amount'=>(float)$paid->amount,'advance'=>(float)$paid->advance,'net'=>(float)$paid->net];
        }
        $days=(array)$wpdb->get_results($wpdb->prepare("SELECT d.id,d.worker_id,d.mark,d.extra_hours,d.amount,w.name,w.trade,w.daily_rate FROM {$this->dw_t('days')} d JOIN {$this->dw_t('workers')} w ON w.id=d.worker_id WHERE d.location_id=%d AND d.work_date BETWEEN %s AND %s AND d.payout_id IS NULL ORDER BY w.name",(int)$location_id,$start,$end),ARRAY_A);
        foreach($days as $d)$names[(int)$d['worker_id']]=$d;
        $p=PayRules::payout($days,$this->dw_outstanding(array_keys($names),$end));
        $lines=[];
        foreach($p['lines'] as $w=>$l)$lines[$w]=$l+['name'=>(string)$names[$w]['name'],'trade'=>(string)$names[$w]['trade'],'rate'=>(float)$names[$w]['daily_rate']];
        return ['site'=>$site,'start'=>$start,'end'=>$end,'paid'=>null,'lines'=>$lines,'amount'=>$p['amount'],'advance'=>$p['advance'],'net'=>$p['net'],'day_ids'=>array_map('intval',array_column($days,'id'))];
    }

    /** Earlier periods of a site with unpaid days. @return list<string> period starts */
    private function dw_unpaid_periods($location_id,$before){
        global $wpdb;
        $site=$this->dw_site($location_id);$out=[];
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT work_date FROM {$this->dw_t('days')} WHERE location_id=%d AND payout_id IS NULL AND amount>0 AND work_date<%s ORDER BY work_date DESC LIMIT 60",(int)$location_id,$before)) as $d){
            $s=PayRules::period((string)$d,$site->eff_period,(int)$this->dw_settings()['week_start'])[0];
            if(!in_array($s,$out,true))$out[]=$s;
        }
        return array_slice($out,0,8);
    }

    /** @return string '' or an error code */
    private function dw_add_advance($worker_id,$location_id,$amount,$note){
        global $wpdb;
        if(!$this->dw_enabled())return 'disabled';
        if(!$this->dw_site($location_id) || !$this->dw_can_pay($location_id))return 'access';
        $w=$this->dw_worker($worker_id);
        if(!$w)return 'worker';
        if(!is_numeric($amount) || (float)$amount<=0 || (float)$amount>1000000)return 'amount';
        $ok=$wpdb->insert($this->dw_t('advances'),['worker_id'=>(int)$w->id,'location_id'=>(int)$location_id,'amount'=>round((float)$amount,2),'given_on'=>current_time('Y-m-d'),
            'note'=>$note!==''?mb_substr((string)$note,0,190):null,'by_user'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
        if(!$ok)return 'save';
        // Amounts never go to the Audit Log (same rule as salaries).
        $this->audit('dw_advance','daily_worker',(int)$w->id,'Advance recorded: '.$w->name.' at '.$this->dw_site($location_id)->name);
        return '';
    }

    public function dw_advance_save(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_advance');
        $e=$this->dw_add_advance(absint($_POST['worker']??0),absint($_POST['site']??0),sanitize_text_field(wp_unslash((string)($_POST['amount']??''))),sanitize_text_field(wp_unslash((string)($_POST['note']??''))));
        if($e!=='')$this->dw_back(['dw_error'=>$e],'payout');
        $this->dw_back(['dw_adv'=>1],'payout');
    }

    /**
     * Marks a period paid and locks it (the signed sheet's photo is required).
     * @param mixed $photo an entry of $_FILES
     * @return array{id?:int,error?:string}
     */
    private function dw_mark_paid($location_id,$start,$photo){
        global $wpdb;
        if(!$this->dw_enabled())return ['error'=>'disabled'];
        $this->ensure_dw_schema_once();
        if(!$this->dw_site($location_id) || !$this->dw_can_pay($location_id))return ['error'=>'access'];
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$start) || $start>current_time('Y-m-d'))return ['error'=>'date'];
        $p=$this->dw_payout($location_id,$start);
        if($p['paid'])return ['error'=>'paid'];
        if(!$p['lines'] || $p['amount']<=0)return ['error'=>'nothing'];
        [$key,$perr]=$this->dw_save_photo($photo);
        if($perr!=='')return ['error'=>$perr];
        $wpdb->query('START TRANSACTION');
        $ok=$wpdb->insert($this->dw_t('payouts'),['location_id'=>(int)$location_id,'period_start'=>$p['start'],'period_end'=>$p['end'],'amount'=>$p['amount'],'advance'=>$p['advance'],'net'=>$p['net'],
            'photo_key'=>$key,'paid_by'=>get_current_user_id(),'paid_at'=>current_time('mysql')]);
        if(!$ok){$wpdb->query('ROLLBACK');$this->dw_delete_file($key);return ['error'=>'paid'];}
        $id=(int)$wpdb->insert_id;
        foreach($p['lines'] as $l){
            $wpdb->insert($this->dw_t('lines'),['payout_id'=>$id,'worker_id'=>(int)$l['worker_id'],'days'=>$l['days'],'extra_hours'=>$l['extra_hours'],'amount'=>$l['amount'],'advance'=>$l['advance'],'net'=>$l['net']]);
        }
        $ids=implode(',',array_map('intval',$p['day_ids']??[]));
        if($ids!=='')$wpdb->query($wpdb->prepare("UPDATE {$this->dw_t('days')} SET payout_id=%d WHERE id IN ($ids) AND payout_id IS NULL",$id));
        $wpdb->query('COMMIT');
        $this->audit('dw_payout','daily_site',(int)$location_id,'Payout recorded: '.$p['site']->name.' '.$p['start'].' – '.$p['end']);
        return ['id'=>$id];
    }

    public function dw_paid_save(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_paid');
        $r=$this->dw_mark_paid(absint($_POST['site']??0),sanitize_text_field(wp_unslash((string)($_POST['start']??''))),$_FILES['photo']??null);
        if(isset($r['error']))$this->dw_back(['dw_error'=>$r['error']],'payout');
        $this->dw_back(['dw_paid'=>(int)$r['id']],'payout');
    }

    private function dw_period_text($start,$end){
        if($start===$end)return date_i18n('l j F Y',strtotime($start));
        /* translators: 1: first day, 2: last day */
        return sprintf(__('%1$s – %2$s','workforce-one'),date_i18n('j M',strtotime($start)),date_i18n('j M Y',strtotime($end)));
    }

    private function dw_pdf_url($location_id,$start){
        return wp_nonce_url(add_query_arg(['action'=>'ews_dw_payout_pdf','site'=>(int)$location_id,'start'=>$start],admin_url('admin-post.php')),'ews_dw_payout_pdf');
    }

    /** The printed payout sheet. */
    public function dw_payout_pdf(){
        if(!is_user_logged_in())auth_redirect();
        check_admin_referer('ews_dw_payout_pdf');
        $loc=absint($_GET['site']??0);
        if(!$this->dw_enabled() || !$this->dw_site($loc) || !$this->dw_can_pay($loc))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
        $p=$this->dw_payout($loc,sanitize_text_field(wp_unslash((string)($_GET['start']??current_time('Y-m-d')))));
        $num=function($v){return number_format_i18n((float)$v,abs((float)$v-round((float)$v))>0.001?2:0);};
        $rows=[];$i=0;
        foreach($p['lines'] as $l){
            $i++;
            $rows[]=[(string)$i,$l['name'],$this->dw_trade_label($l['trade']),number_format_i18n((float)$l['days'],abs((float)$l['days']-round((float)$l['days']))>0.01?1:0),$num($l['rate']),$l['extra_hours']>0?$num($l['extra_hours']):'—',$num($l['amount']),$l['advance']>0?$num($l['advance']):'—',$num($l['net']),''];
        }
        $foremen=$this->dw_site_foremen($loc);
        $look=$this->appearance();
        $company=(string)($look['company_name']??'')?:get_bloginfo('name');
        $font=\WorkforceOne\Pdf\TrueTypeFont::fromFile(dirname(__DIR__).'/assets/vendor/dejavu/DejaVuSans.ttf');
        $pdf=PayoutPdf::render($font,[
            'rtl'=>is_rtl(),'company'=>$company,'title'=>__('Daily wages sheet','workforce-one'),
            /* translators: %s: company name */
            'subtitle'=>sprintf(__('%s · amounts in Egyptian pounds','workforce-one'),$company),
            'meta'=>[[__('Site','workforce-one'),$p['site']->name],[__('Period','workforce-one'),$this->dw_period_text($p['start'],$p['end'])],[__('Foreman','workforce-one'),implode('، ',array_column($foremen,'name'))?:'—'],
                [__('Sheet no.','workforce-one'),$p['paid']?'DW-'.substr($p['start'],0,4).'-'.str_pad((string)$p['paid']->id,3,'0',STR_PAD_LEFT):__('Not paid yet','workforce-one')]],
            'heads'=>['#',__('Name','workforce-one'),__('Trade','workforce-one'),__('Days','workforce-one'),__('Rate','workforce-one'),__('Extra h','workforce-one'),__('Amount','workforce-one'),__('Advance','workforce-one'),__('Net','workforce-one'),__('Signature / thumbprint','workforce-one')],
            'rows'=>$rows,'total_label'=>__('Total','workforce-one'),'totals'=>[$num($p['amount']),$num($p['advance']),$num($p['net'])],
            'note'=>__('By signing next to my name I confirm I received the net amount shown for my work at this site in this period. Deducted advances were paid before.','workforce-one'),
            'signatures'=>[__('Foreman','workforce-one'),__('Cashier','workforce-one'),__('Site manager','workforce-one')],
            'generated'=>current_time('Y-m-d H:i'),'empty'=>__('There is nothing to pay in this period.','workforce-one'),
        ]);
        Download::send($pdf,'application/pdf','daily-wages-'.$loc.'-'.$p['start'].'.pdf');
    }

    /** @return list<array{id:int,name:string}> */
    private function dw_site_foremen($location_id){
        global $wpdb;$out=[];
        foreach((array)$wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$this->dw_t('foremen')} WHERE location_id=%d",(int)$location_id)) as $uid){
            $u=get_userdata((int)$uid);
            if($u)$out[]=['id'=>(int)$u->ID,'name'=>(string)$u->display_name];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ worker self-service (mobile + PIN) */

    private function dw_cookie_name(){ return 'wfo_dw_worker'; }

    /** The signed-in worker (self-service), or null. */
    private function dw_worker_session(){
        $raw=isset($_COOKIE[$this->dw_cookie_name()])?sanitize_text_field(wp_unslash((string)$_COOKIE[$this->dw_cookie_name()])):'';
        $parts=explode('|',$raw);
        if(count($parts)!==3)return null;
        [$id,$exp,$sig]=$parts;
        if(!ctype_digit($id) || !ctype_digit($exp) || (int)$exp<time())return null;
        $w=$this->dw_worker((int)$id);
        if(!$w || $w->status!=='active' || !$w->pin_hash)return null;
        $want=hash_hmac('sha256',$id.'|'.$exp.'|'.substr((string)$w->pin_hash,-16),$this->dw_key('session'));
        return hash_equals($want,$sig)?$w:null;
    }

    private function dw_set_cookie($value,$exp){
        if(headers_sent())return;
        setcookie($this->dw_cookie_name(),$value,['expires'=>$exp,'path'=>defined('COOKIEPATH')&&constant('COOKIEPATH')?(string)constant('COOKIEPATH'):'/','domain'=>defined('COOKIE_DOMAIN')&&constant('COOKIE_DOMAIN')?(string)constant('COOKIE_DOMAIN'):'','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
    }

    /** Can any worker sign in himself (the company's mode or a site's)? */
    private function dw_self_anywhere(){
        if(!$this->dw_enabled())return false;
        if(SiteRules::allows($this->dw_settings()['mode'],'self'))return true;
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$this->dw_t('sites')))!==$this->dw_t('sites'))return false;
        return (bool)$wpdb->get_var("SELECT COUNT(*) FROM {$this->dw_t('sites')} WHERE active=1 AND mode IN ('self','both')");
    }

    private function dw_worker_url($args=[]){ return add_query_arg(array_merge(['ews_view'=>'worker'],$args),$this->app_home_url()); }

    public function dw_worker_login(){
        check_admin_referer('ews_dw_worker_login');
        if(!$this->dw_enabled()){wp_safe_redirect($this->app_home_url());exit;}
        $mobile=$this->dw_norm_mobile(sanitize_text_field(wp_unslash((string)($_POST['mobile']??''))));
        $pin=NationalId::normalize(sanitize_text_field(wp_unslash((string)($_POST['pin']??''))));
        $ip=sanitize_text_field(wp_unslash((string)($_SERVER['REMOTE_ADDR']??'')));
        $tk='ews_dw_login_'.md5($ip.'|'.$mobile);
        $tries=(int)get_transient($tk);
        if($tries>=5){wp_safe_redirect($this->dw_worker_url(['dw_error'=>'too_many']));exit;}
        global $wpdb;$found=null;
        if($mobile!=='' && $pin!==''){
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->dw_t('workers')} WHERE mobile_norm=%s AND status='active' AND pin_hash IS NOT NULL",$mobile)) as $w){
                if(wp_check_password($pin,(string)$w->pin_hash)){$found=$w;break;}
            }
        }
        if(!$found){
            set_transient($tk,$tries+1,15*MINUTE_IN_SECONDS);
            wp_safe_redirect($this->dw_worker_url(['dw_error'=>'login']));exit;
        }
        delete_transient($tk);
        $exp=time()+30*DAY_IN_SECONDS;
        $sig=hash_hmac('sha256',$found->id.'|'.$exp.'|'.substr((string)$found->pin_hash,-16),$this->dw_key('session'));
        $this->dw_set_cookie($found->id.'|'.$exp.'|'.$sig,$exp);
        wp_safe_redirect($this->dw_worker_url());exit;
    }

    public function dw_worker_logout(){
        check_admin_referer('ews_dw_worker_logout');
        $this->dw_set_cookie('',time()-3600);
        wp_safe_redirect($this->dw_worker_url());exit;
    }

    /** @param array{lat:?float,lng:?float,accuracy:?float,ts:?int} $pos @return string '' or an error code */
    private function dw_self_record($w,array $pos){
        global $wpdb;
        if(!$this->dw_enabled())return 'disabled';
        $this->ensure_dw_schema_once();
        $date=current_time('Y-m-d');
        $loc=$this->dw_worker_site((int)$w->id,$date);
        $site=$loc?$this->dw_site($loc):null;
        if(!$site)return 'no_site';
        if(!SiteRules::allows($site->eff_mode,'self'))return 'mode_self';
        if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->dw_t('days')} WHERE worker_id=%d AND work_date=%s",(int)$w->id,$date)))return 'recorded';
        if($this->dw_sheet($loc,$date))return 'locked';
        if($this->dw_paid_for($loc,$date))return 'paid';
        $chk=SiteRules::location($pos['lat'],$pos['lng'],$pos['accuracy'],$pos['ts'],time(),$site->latitude,$site->longitude,(float)max(1,(int)$site->radius),$this->dw_previous_position(0,(int)$w->id));
        if($chk['error']!=='')return $chk['error'];
        $rate=(float)$w->daily_rate;$hr=$this->dw_hourly($w);$now=current_time('mysql');
        $ok=$wpdb->insert($this->dw_t('days'),['worker_id'=>(int)$w->id,'location_id'=>$loc,'work_date'=>$date,'mark'=>'in','extra_hours'=>0,'daily_rate'=>$rate,'hourly_rate'=>$hr,
            'amount'=>PayRules::dayAmount('in',0,$rate,$hr),'source'=>'self','latitude'=>$pos['lat'],'longitude'=>$pos['lng'],'created_at'=>$now,'updated_at'=>$now]);
        return $ok?'':'recorded';
    }

    public function dw_self_signin(){
        check_admin_referer('ews_dw_self');
        $w=$this->dw_worker_session();
        if(!$w){wp_safe_redirect($this->dw_worker_url());exit;}
        $e=$this->dw_self_record($w,$this->dw_position($_POST));
        wp_safe_redirect($this->dw_worker_url($e!==''?['dw_error'=>$e]:['dw_done'=>1]));exit;
    }

    /** The worker's page: the mobile + PIN form, or today's site, the Sign In button and his recent days. */
    private function dw_worker_page(){
        $this->enqueue_login_assets();
        wp_enqueue_style('workforce-one-daily-workers');
        wp_enqueue_script('workforce-one-daily-workers');
        $cfg=$this->appearance();
        $w=$this->dw_enabled()?$this->dw_worker_session():null;
        $data=['enabled'=>$this->dw_enabled(),'worker'=>$w,'post_url'=>admin_url('admin-post.php'),'app_name'=>(string)$cfg['app_name'],
            'error'=>isset($_GET['dw_error'])?$this->dw_error_message(sanitize_key($_GET['dw_error'])):'', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
            'done'=>isset($_GET['dw_done']),'site'=>null,'today'=>null,'days'=>[],'owed'=>0.0,'can_self'=>false,'staff_url'=>$this->app_home_url()]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        if($w){
            global $wpdb;
            $date=current_time('Y-m-d');
            $loc=$this->dw_worker_site((int)$w->id,$date);
            $site=$loc?$this->dw_site($loc):null;
            $data['site']=$site;
            $data['can_self']=$site && SiteRules::allows($site->eff_mode,'self');
            $data['today']=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->dw_t('days')} WHERE worker_id=%d AND work_date=%s",(int)$w->id,$date));
            $data['days']=(array)$wpdb->get_results($wpdb->prepare("SELECT d.*,l.name site_name FROM {$this->dw_t('days')} d LEFT JOIN {$this->locations} l ON l.id=d.location_id WHERE d.worker_id=%d ORDER BY d.work_date DESC LIMIT 14",(int)$w->id));
            $data['owed']=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->dw_t('days')} WHERE worker_id=%d AND payout_id IS NULL",(int)$w->id));
            $data['mark_label']=function($m){return $this->dw_mark_label($m);};
            $data['money']=function($v){return $this->dw_money($v);};
        }
        return $this->render_template('app/dw-worker',$data);
    }

    /* ------------------------------------------------------------------ app views */

    private function dw_view_allowed($view){
        if(!$this->dw_enabled())return false;
        if($view==='sites')return $this->dw_is_manager()||$this->dw_is_foreman();
        if($view==='payout')return $this->dw_is_manager()||$this->dw_is_foreman()||$this->dw_is_cashier();
        return false;
    }

    private function dw_enqueue(){
        wp_enqueue_style('workforce-one-requests');
        wp_enqueue_style('workforce-one-daily-workers');
        wp_enqueue_script('workforce-one-sheet');
        wp_enqueue_script('workforce-one-daily-workers');
    }

    /** My sites, or one site's day sheet (?site=). */
    private function dw_sites_content(){
        $this->ensure_dw_schema_once();
        $this->dw_enqueue();
        $site_id=absint($_GET['site']??0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation
        if($site_id && in_array($site_id,$this->dw_my_site_ids(),true))return $this->dw_day_content($site_id);
        global $wpdb;
        $date=current_time('Y-m-d');$cards=[];$todo=0;$workers=0;
        foreach($this->dw_my_site_ids() as $id){
            $s=$this->dw_site($id);
            if(!$s)continue;
            $n=count($this->dw_site_workers($id,$date));
            $sheet=$this->dw_sheet($id,$date);
            $present=$sheet?(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('days')} WHERE location_id=%d AND work_date=%s AND mark<>'out'",$id,$date)):0;
            $foreman=SiteRules::allows($s->eff_mode,'foreman');
            if(!$sheet && $foreman)$todo++;
            $workers+=$n;
            $cards[]=['site'=>$s,'workers'=>$n,'sheet'=>$sheet,'present'=>$present,'foreman'=>$foreman,'url'=>add_query_arg(['ews_view'=>'sites','site'=>$id],$this->app_home_url())];
        }
        return $this->render_template('app/dw-sites',['cards'=>$cards,'todo'=>$todo,'workers'=>$workers,'today'=>date_i18n('l j F',current_time('timestamp')),
            'period_label'=>function($p){return $this->dw_period_label($p);}]);
    }

    private function dw_day_content($site_id){
        global $wpdb;
        $s=$this->dw_site($site_id);
        $date=current_time('Y-m-d');
        $workers=$this->dw_site_workers($site_id,$date);
        $sheet=$this->dw_sheet($site_id,$date);
        $rows=[];
        foreach($workers as $w){
            $d=$w->day&&(int)$w->day->location_id===(int)$site_id?$w->day:null;
            $rows[]=['id'=>(int)$w->id,'name'=>(string)$w->name,'initials'=>$this->dw_initials($w->name),'trade'=>$this->dw_trade_label($w->trade),
                'rate'=>(float)$w->daily_rate,'hourly'=>$this->dw_hourly($w),'sub'=>(string)$w->subcontractor,'mark'=>$d?(string)$d->mark:'in','extra'=>$d?(float)$d->extra_hours:0.0,
                'self'=>$d&&$d->source==='self','elsewhere'=>$w->day&&!$d,'day_id'=>$d?(int)$d->id:0,
                'pending'=>$d?(bool)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('changes')} WHERE day_id=%d AND status='pending'",(int)$d->id)):false];
        }
        $others=[];
        foreach($this->dw_sites() as $id=>$o)if($id!==(int)$site_id)$others[$id]=$o->name;
        $set=$this->dw_settings();
        $by=$sheet?get_userdata((int)$sheet->by_user):null;
        return $this->render_template('app/dw-day',[
            'site'=>$s,'rows'=>$rows,'sheet'=>$sheet,'saved_by'=>$by?$by->display_name:'','today'=>date_i18n('l j F',current_time('timestamp')),
            'foreman_ok'=>SiteRules::allows($s->eff_mode,'foreman'),'paid'=>(bool)$this->dw_paid_for($site_id,$date),'photo'=>(int)$set['photo']===1,
            'photo_url'=>$sheet&&$sheet->photo_key?$this->dw_file_url('sheet',(int)$sheet->id):'','others'=>$others,'trades'=>$set['trades'],'subs'=>$set['subcontractors'],
            'trade_label'=>function($t){return $this->dw_trade_label($t);},'money'=>function($v){return $this->dw_money($v);},
            'post_url'=>admin_url('admin-post.php'),'back_url'=>add_query_arg('ews_view','sites',$this->app_home_url()),
            'open'=>sanitize_key($_GET['dw_open']??''), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
            'tomorrow'=>date('Y-m-d',strtotime($date.' +1 day')),'date'=>$date,
        ]);
    }

    /** The payout of a site (?site=, ?start=), or the choice of site. */
    private function dw_payout_content(){
        $this->ensure_dw_schema_once();
        $this->dw_enqueue();
        $ids=array_values(array_filter($this->dw_my_site_ids(),function($id){return $this->dw_can_pay($id);}));
        if($this->dw_is_cashier() && !$ids)$ids=array_keys($this->dw_sites());
        $site_id=absint($_GET['site']??0); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation
        if(!$site_id && count($ids)===1)$site_id=$ids[0];
        $sites=[];
        foreach($ids as $id){$s=$this->dw_site($id);if($s)$sites[$id]=$s;}
        if(!$site_id || !isset($sites[$site_id]))return $this->render_template('app/dw-payout',['sites'=>$sites,'site'=>null,'url'=>function($id){return add_query_arg(['ews_view'=>'payout','site'=>$id],$this->app_home_url());}]);
        $today=current_time('Y-m-d');
        $start=sanitize_text_field(wp_unslash((string)($_GET['start']??$today))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start) || $start>$today)$start=$today;
        $p=$this->dw_payout($site_id,$start);
        global $wpdb;
        $by=$p['paid']?get_userdata((int)$p['paid']->paid_by):null;
        $workers=$this->dw_site_workers($site_id,$today);
        return $this->render_template('app/dw-payout',[
            'sites'=>$sites,'site'=>$p['site'],'p'=>$p,'period'=>$this->dw_period_text($p['start'],$p['end']),'paid_by'=>$by?$by->display_name:'',
            'earlier'=>array_map(function($s)use($site_id){$r=$this->dw_payout($site_id,$s);return ['start'=>$r['start'],'label'=>$this->dw_period_text($r['start'],$r['end']),'url'=>add_query_arg(['ews_view'=>'payout','site'=>$site_id,'start'=>$r['start']],$this->app_home_url())];},$this->dw_unpaid_periods($site_id,$p['start'])),
            'pdf_url'=>$this->dw_pdf_url($site_id,$p['start']),'photo_url'=>$p['paid']&&$p['paid']->photo_key?$this->dw_file_url('payout',(int)$p['paid']->id):'',
            'workers'=>$workers,'money'=>function($v){return $this->dw_money($v);},'days_text'=>function($d){return $this->dw_days_text($d);},
            'post_url'=>admin_url('admin-post.php'),'url'=>function($id){return add_query_arg(['ews_view'=>'payout','site'=>$id],$this->app_home_url());},
            'advances'=>(array)$wpdb->get_results($wpdb->prepare("SELECT a.*,w.name FROM {$this->dw_t('advances')} a JOIN {$this->dw_t('workers')} w ON w.id=a.worker_id WHERE a.location_id=%d AND a.given_on BETWEEN %s AND %s ORDER BY a.id DESC",$site_id,$p['start'],$p['end'])),
        ]);
    }

    /* ------------------------------------------------------------------ wp-admin */

    private function dw_admin_url($args=[]){ return add_query_arg($args,admin_url('admin.php?page=ews31-daily-workers')); }

    /** Workers for the list, with today's site. @param array<string,mixed> $f @return array{rows:array<int,object>,total:int} */
    private function dw_list(array $f,$page=1,$per=50){
        global $wpdb;
        $today=current_time('Y-m-d');
        $where=['1=1'];$args=[];
        if($f['q']!==''){
            $digits=NationalId::normalize($f['q']);
            $like='%'.$wpdb->esc_like($f['q']).'%';
            $or=['w.name LIKE %s','w.mobile LIKE %s'];$args[]=$like;$args[]=$like;
            $m=$this->dw_norm_mobile($f['q']);
            if($m!==''){$or[]='w.mobile_norm LIKE %s';$args[]='%'.$wpdb->esc_like($m).'%';}
            // A full national ID matches through its keyed hash (never by decrypting rows).
            if(preg_match('/^[A-Za-z0-9]{4,30}$/',$digits)){$or[]='w.nid_hash=%s';$args[]=NationalId::hash($digits,$this->dw_key('nid-hash'));}
            if(preg_match('/^dw-(\d+)$/i',$f['q'],$mm)){$or[]='w.id=%d';$args[]=(int)$mm[1];}
            $where[]='('.implode(' OR ',$or).')';
        }
        if($f['trade']!==''){$where[]='w.trade=%s';$args[]=$f['trade'];}
        if($f['sub']==='-')$where[]="(w.subcontractor IS NULL OR w.subcontractor='')";
        elseif($f['sub']!==''){$where[]='w.subcontractor=%s';$args[]=$f['sub'];}
        if(in_array($f['status'],['active','no_rehire'],true)){$where[]='w.status=%s';$args[]=$f['status'];}
        if($f['rating']>0){$where[]='w.rating>=%d';$args[]=$f['rating'];}
        if($f['site']>0){$where[]=$this->dw_site_sql('w.id',$today).'=%d';$args[]=$f['site'];}
        $sql=implode(' AND ',$where);
        $total=(int)$wpdb->get_var($args?$wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('workers')} w WHERE $sql",$args):"SELECT COUNT(*) FROM {$this->dw_t('workers')} w WHERE $sql");
        $q="SELECT w.*,".$this->dw_site_sql('w.id',$today)." site_id FROM {$this->dw_t('workers')} w WHERE $sql ORDER BY w.status ASC,w.name ASC LIMIT %d OFFSET %d";
        $rows=(array)$wpdb->get_results($wpdb->prepare($q,array_merge($args,[$per,($page-1)*$per])));
        return ['rows'=>$rows,'total'=>$total];
    }

    public function admin_daily_workers(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        $this->ensure_dw_schema_once();
        global $wpdb;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- filters and navigation
        $tab=sanitize_key($_GET['tab']??'list');
        $wid=absint($_GET['worker']??0);
        if($wid)$tab='worker';
        if(isset($_GET['edit']))$tab='edit';
        $set=$this->dw_settings();$sites=$this->dw_sites();
        $data=['tab'=>$tab,'settings'=>$set,'sites'=>$sites,'post_url'=>admin_url('admin-post.php'),'url'=>function($a=[]){return $this->dw_admin_url($a);},
            'money'=>function($v){return $this->dw_money($v);},'trade_label'=>function($t){return $this->dw_trade_label($t);},'masked'=>function($w){return $this->dw_nid_masked($w);},
            'days_text'=>function($d){return $this->dw_days_text($d);},'mark_label'=>function($m){return $this->dw_mark_label($m);},
            'notice'=>sanitize_key($_GET['done']??''),'error'=>isset($_GET['dw_error'])?$this->dw_error_message(sanitize_key($_GET['dw_error'])):'',
            'pending_changes'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->dw_t('changes')} WHERE status='pending'")];
        if($tab==='list'){
            $f=['q'=>sanitize_text_field(wp_unslash((string)($_GET['q']??''))),'trade'=>sanitize_text_field(wp_unslash((string)($_GET['trade']??''))),'sub'=>sanitize_text_field(wp_unslash((string)($_GET['sub']??''))),
                'site'=>absint($_GET['site']??0),'status'=>sanitize_key($_GET['status']??''),'rating'=>absint($_GET['rating']??0)];
            $page=max(1,absint($_GET['paged']??1));
            $data+=['filters'=>$f,'page'=>$page]+$this->dw_list($f,$page);
        }elseif($tab==='worker'){
            $w=$this->dw_worker($wid);
            if(!$w)wp_die(esc_html__('Worker not found.','workforce-one'));
            $revealed='';
            if(isset($_GET['reveal']) && wp_verify_nonce(sanitize_text_field(wp_unslash((string)($_GET['_wpnonce']??''))),'ews_dw_reveal_'.$wid))$revealed=$this->dw_nid_reveal($w,'worker profile');
            $data+=['w'=>$w,'revealed'=>$revealed,'can_reveal'=>$this->dw_can_view_ids()&&(bool)$w->nid_enc,'reveal_url'=>wp_nonce_url($this->dw_admin_url(['worker'=>$wid,'reveal'=>1]),'ews_dw_reveal_'.$wid),
                'photo_url'=>$w->photo_key?$this->dw_file_url('worker',$wid):'','gov'=>$w->governorate&&isset(NationalId::GOVERNORATES[$w->governorate])?NationalId::GOVERNORATES[$w->governorate][is_rtl()?0:1]:'',
                'history'=>(array)$wpdb->get_results($wpdb->prepare("SELECT d.location_id,l.name,MIN(d.work_date) first,MAX(d.work_date) last,SUM(CASE d.mark WHEN 'in' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END) days,SUM(d.amount) amount FROM {$this->dw_t('days')} d LEFT JOIN {$this->locations} l ON l.id=d.location_id WHERE d.worker_id=%d GROUP BY d.location_id,l.name ORDER BY last DESC",$wid)),
                'payouts'=>(array)$wpdb->get_results($wpdb->prepare("SELECT l.*,p.period_start,p.period_end,p.location_id,s.name site_name FROM {$this->dw_t('lines')} l JOIN {$this->dw_t('payouts')} p ON p.id=l.payout_id LEFT JOIN {$this->locations} s ON s.id=p.location_id WHERE l.worker_id=%d ORDER BY p.period_start DESC LIMIT 20",$wid)),
                'unpaid'=>(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$this->dw_t('days')} WHERE worker_id=%d AND payout_id IS NULL",$wid)),
                'outstanding'=>(float)($this->dw_outstanding([$wid],current_time('Y-m-d'))[$wid]??0),'site_today'=>$this->dw_worker_site($wid,current_time('Y-m-d'))];
        }elseif($tab==='edit'){
            $data+=['w'=>absint($_GET['edit']??0)?$this->dw_worker(absint($_GET['edit'])):null];
        }elseif($tab==='reports'){
            $to=sanitize_text_field(wp_unslash((string)($_GET['to']??current_time('Y-m-d'))));
            $from=sanitize_text_field(wp_unslash((string)($_GET['from']??current_time('Y-m-01'))));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=current_time('Y-m-01');
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=current_time('Y-m-d');
            if($from>$to)[$from,$to]=[$to,$from];
            $site=absint($_GET['site']??0);
            $data+=['report'=>$this->dw_report($from,$to,$site),'from'=>$from,'to'=>$to,'site'=>$site,
                'export'=>function($fmt)use($from,$to,$site){return wp_nonce_url(add_query_arg(['action'=>'ews_dw_export','format'=>$fmt,'from'=>$from,'to'=>$to,'site'=>$site],admin_url('admin-post.php')),'ews_dw_export');}];
        }elseif($tab==='settings'){
            $locs=(array)$wpdb->get_results("SELECT l.id,l.name,s.location_id is_site,s.project,s.start_date,s.end_date,s.mode,s.period,s.active FROM {$this->locations} l LEFT JOIN {$this->dw_t('sites')} s ON s.location_id=l.id WHERE l.active=1 ORDER BY l.name");
            $fm=[];foreach((array)$wpdb->get_results("SELECT * FROM {$this->dw_t('foremen')}") as $r)$fm[(int)$r->location_id][]=(int)$r->user_id;
            $data+=['locations'=>$locs,'foremen'=>$fm,'people'=>get_users(['capability__in'=>['ews_dw_foreman','ews_dw_cashier'],'fields'=>['ID','display_name'],'orderby'=>'display_name']),
                'saved'=>isset($_GET['saved']),'roles_url'=>admin_url('admin.php?page=ews31-roles')];
        }elseif($tab==='changes'){
            $data+=['changes'=>(array)$wpdb->get_results("SELECT c.*,w.name,l.name site_name FROM {$this->dw_t('changes')} c LEFT JOIN {$this->dw_t('workers')} w ON w.id=c.worker_id LEFT JOIN {$this->locations} l ON l.id=c.location_id ORDER BY c.status='pending' DESC,c.requested_at DESC LIMIT 100")];
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        echo $this->render_template('admin/daily-workers',$data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template
    }

    public function dw_admin_worker_save(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_dw_admin_worker');
        $this->ensure_dw_schema_once();
        $id=absint($_POST['id']??0);
        $in=$this->dw_input($_POST);
        $r=$this->dw_save_worker($in,$id,true);
        if(isset($r['error'])){wp_safe_redirect($this->dw_admin_url(['edit'=>$id,'dw_error'=>$r['error']]));exit;}
        $id=(int)$r['id'];
        if(!empty($_FILES['photo']) && (int)($_FILES['photo']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            [$key,$e]=$this->dw_save_photo($_FILES['photo']);
            if($e===''){$old=$this->dw_worker($id);global $wpdb;$wpdb->update($this->dw_t('workers'),['photo_key'=>$key],['id'=>$id]);if($old)$this->dw_delete_file($old->photo_key);}
        }
        $site=absint($_POST['site']??0);
        if($site && $this->dw_site($site) && $this->dw_worker_site($id,current_time('Y-m-d'))!==$site)$this->dw_move_to($id,$site,current_time('Y-m-d'));
        $this->audit(absint($_POST['id']??0)?'dw_worker_update':'dw_worker_add','daily_worker',$id,$in['name'].($in['nid']!==''?' (national ID set)':'').($in['pin']!==''?' (PIN set)':''));
        wp_safe_redirect($this->dw_admin_url(['worker'=>$id,'done'=>'saved']));exit;
    }

    public function dw_worker_delete(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        $id=absint($_POST['id']??0);
        check_admin_referer('ews_dw_worker_delete_'.$id);
        global $wpdb;
        $w=$this->dw_worker($id);
        if(!$w){wp_safe_redirect($this->dw_admin_url());exit;}
        if((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->dw_t('lines')} WHERE worker_id=%d",$id))){wp_safe_redirect($this->dw_admin_url(['worker'=>$id,'dw_error'=>'has_paid']));exit;}
        foreach(['days','moves','advances','changes'] as $t)$wpdb->delete($this->dw_t($t),['worker_id'=>$id]);
        $wpdb->delete($this->dw_t('workers'),['id'=>$id]);
        $this->dw_delete_file($w->photo_key);
        $this->audit('dw_worker_delete','daily_worker',$id,$w->name.' deleted with his national ID');
        wp_safe_redirect($this->dw_admin_url(['done'=>'deleted']));exit;
    }

    public function dw_settings_save(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_dw_settings');
        $this->ensure_dw_schema_once();
        $post=[];
        foreach(['dw_mode','dw_period','dw_hourly_rate','dw_week_start','dw_photo'] as $k)$post[$k]=sanitize_text_field(wp_unslash((string)($_POST[$k]??'')));
        foreach(['dw_trades','dw_subcontractors'] as $k)$post[$k]=sanitize_textarea_field(wp_unslash((string)($_POST[$k]??'')));
        [$s,$err]=DailyWorkerSettings::fromPost($post);
        if($err!==''){wp_safe_redirect($this->dw_admin_url(['tab'=>'settings','dw_error'=>$err==='rate'?'hourly':($err==='trades'?'trade':'save')]));exit;}
        update_option('ews_dw_settings',$s,false);
        global $wpdb;$now=current_time('mysql');
        $valid=array_map('intval',(array)$wpdb->get_col("SELECT id FROM {$this->locations} WHERE active=1"));
        $allowed=array_map('intval',wp_list_pluck(get_users(['capability__in'=>['ews_dw_foreman','ews_dw_cashier'],'fields'=>['ID']]),'ID'));
        foreach((array)($_POST['sites']??[]) as $loc=>$row){
            $loc=(int)$loc;
            if(!in_array($loc,$valid,true) || !is_array($row))continue;
            $g=function($k)use($row){return sanitize_text_field(wp_unslash((string)($row[$k]??'')));};
            $on=!empty($row['on']);
            $exists=$wpdb->get_var($wpdb->prepare("SELECT location_id FROM {$this->dw_t('sites')} WHERE location_id=%d",$loc));
            if(!$on && !$exists)continue;
            $date=function($v){return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:null;};
            $data=['project'=>$g('project')!==''?mb_substr($g('project'),0,190):null,'start_date'=>$date($g('start')),'end_date'=>$date($g('end')),
                'mode'=>in_array($g('mode'),DailyWorkerSettings::MODES,true)?$g('mode'):'','period'=>in_array($g('period'),DailyWorkerSettings::PERIODS,true)?$g('period'):'','active'=>$on?1:0];
            if($exists)$wpdb->update($this->dw_t('sites'),$data,['location_id'=>$loc]);
            else $wpdb->insert($this->dw_t('sites'),$data+['location_id'=>$loc,'created_at'=>$now]);
            $wpdb->delete($this->dw_t('foremen'),['location_id'=>$loc]);
            foreach(array_unique(array_map('intval',(array)($row['foremen']??[]))) as $uid)if(in_array($uid,$allowed,true))$wpdb->insert($this->dw_t('foremen'),['location_id'=>$loc,'user_id'=>$uid]);
        }
        $this->audit('dw_settings','settings',0,'mode='.$s['mode'].';period='.$s['period'].';photo='.$s['photo']);
        wp_safe_redirect($this->dw_admin_url(['tab'=>'settings','saved'=>1]));exit;
    }

    /* ------------------------------------------------------------------ reports */

    /** @return array<string,mixed> */
    private function dw_report($from,$to,$site=0){
        global $wpdb;
        $d=$this->dw_t('days');$w=$this->dw_t('workers');
        $siteSql=$site?$wpdb->prepare(' AND d.location_id=%d',$site):'';
        $range=$wpdb->prepare('d.work_date BETWEEN %s AND %s',$from,$to).$siteSql;
        $daysExpr="SUM(CASE d.mark WHEN 'in' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END)";
        $by=function($group,$label)use($wpdb,$d,$w,$range,$daysExpr){
            return (array)$wpdb->get_results("SELECT {$group} k,{$label} label,SUM(d.amount) amount,{$daysExpr} days,COUNT(DISTINCT d.worker_id) workers FROM {$d} d JOIN {$w} w ON w.id=d.worker_id LEFT JOIN {$this->locations} l ON l.id=d.location_id WHERE {$range} GROUP BY {$group},{$label} ORDER BY amount DESC");
        };
        $bySite=$by('d.location_id','l.name');
        foreach($bySite as $r){$s=$this->dw_site((int)$r->k);$r->project=$s&&$s->project?(string)$s->project:'';}
        $byTrade=$by('w.trade','w.trade');
        $bySub=$by('w.subcontractor','w.subcontractor');
        $total=(float)$wpdb->get_var("SELECT COALESCE(SUM(d.amount),0) FROM {$d} d WHERE {$range}");
        // The project so far: the chosen site, else the one that cost most in the period.
        $heroSite=$site?:($bySite?(int)$bySite[0]->k:0);
        $hero=$heroSite?(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount),0) FROM {$d} WHERE location_id=%d",$heroSite)):0.0;
        $owed=(float)$wpdb->get_var("SELECT COALESCE(SUM(d.amount),0) FROM {$d} d WHERE d.payout_id IS NULL".$siteSql);
        // Workers per site and day (the last 31 days of the range).
        $gridFrom=max($from,date('Y-m-d',strtotime($to.' -30 days')));
        $grid=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT d.location_id,d.work_date,COUNT(*) n FROM {$d} d WHERE d.mark<>'out' AND d.work_date BETWEEN %s AND %s".$siteSql." GROUP BY d.location_id,d.work_date",$gridFrom,$to)) as $r)$grid[(int)$r->location_id][(string)$r->work_date]=(int)$r->n;
        $dates=[];for($t=strtotime($gridFrom);$t<=strtotime($to);$t+=86400)$dates[]=date('Y-m-d',$t);
        $unpaid=(array)$wpdb->get_results("SELECT d.worker_id,w.name,w.trade,SUM(d.amount) amount,{$daysExpr} days FROM {$d} d JOIN {$w} w ON w.id=d.worker_id WHERE d.payout_id IS NULL AND d.amount>0".$siteSql." GROUP BY d.worker_id,w.name,w.trade ORDER BY amount DESC LIMIT 200");
        $out=$this->dw_outstanding(array_map(function($r){return (int)$r->worker_id;},$unpaid),current_time('Y-m-d'));
        foreach($unpaid as $r){$r->advance=min(max(0,(float)($out[(int)$r->worker_id]??0)),(float)$r->amount);$r->net=(float)$r->amount-$r->advance;}
        return ['by_site'=>$bySite,'by_trade'=>$byTrade,'by_sub'=>$bySub,'total'=>$total,'hero'=>$hero,'hero_site'=>$heroSite?$this->dw_site($heroSite):null,'owed'=>$owed,
            'grid'=>$grid,'dates'=>$dates,'unpaid'=>$unpaid,'days'=>(float)$wpdb->get_var("SELECT COALESCE({$daysExpr},0) FROM {$d} d WHERE {$range}")];
    }

    /** Excel (several sheets), PDF, or the social insurance report for the accountant (CSV). */
    public function dw_export(){
        if(!$this->dw_is_manager())wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_dw_export');
        global $wpdb;
        $from=sanitize_text_field(wp_unslash((string)($_GET['from']??'')));$to=sanitize_text_field(wp_unslash((string)($_GET['to']??'')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))wp_die('Invalid dates.');
        $site=absint($_GET['site']??0);$fmt=sanitize_key($_GET['format']??'xlsx');
        $siteSql=$site?$wpdb->prepare(' AND d.location_id=%d',$site):'';
        if($fmt==='insurance'){
            // National IDs in full only for users who may see them; the export itself is audited.
            $full=$this->dw_can_view_ids();
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT DATE_FORMAT(d.work_date,'%%Y-%%m') month,w.*,SUM(CASE d.mark WHEN 'in' THEN 1 WHEN 'half' THEN 0.5 ELSE 0 END) days,SUM(d.amount) amount FROM {$this->dw_t('days')} d JOIN {$this->dw_t('workers')} w ON w.id=d.worker_id WHERE d.work_date BETWEEN %s AND %s".$siteSql." AND d.mark<>'out' GROUP BY month,w.id ORDER BY month,w.name",$from,$to));
            $f=fopen('php://temp','w+');
            fputcsv($f,\WorkforceOne\Support\Csv::row(['Month','Worker','Code','National ID','Birth date','Trade','Days','Amount']));
            $key=$this->dw_key('nid-seal');
            foreach($rows as $r){
                $nid=$full&&$r->nid_enc?(string)NationalId::open((string)$r->nid_enc,$key):$this->dw_nid_masked($r);
                fputcsv($f,\WorkforceOne\Support\Csv::row([$r->month,$r->name,$this->dw_code($r->id),$nid,(string)$r->birth_date,$this->dw_trade_label($r->trade),(float)$r->days,round((float)$r->amount,2)]));
            }
            rewind($f);$csv=(string)stream_get_contents($f);fclose($f);
            $this->audit('dw_insurance_export','daily_workers',0,'Insurance report '.$from.' – '.$to.($full?' with full national IDs':' with masked national IDs'));
            Download::send("\xEF\xBB\xBF".$csv,'text/csv; charset=utf-8','daily-workers-insurance-'.$from.'-'.$to.'.csv');
        }
        $r=$this->dw_report($from,$to,$site);
        $num=function($v){return round((float)$v,2);};
        $sheets=[
            [__('By site','workforce-one'),[__('Site','workforce-one'),__('Project','workforce-one'),__('Workers','workforce-one'),__('Days','workforce-one'),__('Amount','workforce-one')],array_map(function($x)use($num){return [(string)$x->label,(string)$x->project,(int)$x->workers,$num($x->days),$num($x->amount)];},$r['by_site']),[30,26,10,10,14]],
            [__('By trade','workforce-one'),[__('Trade','workforce-one'),__('Workers','workforce-one'),__('Days','workforce-one'),__('Amount','workforce-one')],array_map(function($x)use($num){return [$this->dw_trade_label($x->label)?:'—',(int)$x->workers,$num($x->days),$num($x->amount)];},$r['by_trade']),[26,10,10,14]],
            [__('By subcontractor','workforce-one'),[__('Subcontractor','workforce-one'),__('Workers','workforce-one'),__('Days','workforce-one'),__('Amount','workforce-one')],array_map(function($x)use($num){return [(string)$x->label?:__('None (direct)','workforce-one'),(int)$x->workers,$num($x->days),$num($x->amount)];},$r['by_sub']),[26,10,10,14]],
            [__('Unpaid','workforce-one'),[__('Worker','workforce-one'),__('Trade','workforce-one'),__('Days','workforce-one'),__('Amount','workforce-one'),__('Advance','workforce-one'),__('Net','workforce-one')],array_map(function($x)use($num){return [(string)$x->name,$this->dw_trade_label($x->trade),$num($x->days),$num($x->amount),$num($x->advance),$num($x->net)];},$r['unpaid']),[26,18,10,14,12,14]],
        ];
        $days=(array)$wpdb->get_results($wpdb->prepare("SELECT d.*,w.name,w.trade,l.name site FROM {$this->dw_t('days')} d JOIN {$this->dw_t('workers')} w ON w.id=d.worker_id LEFT JOIN {$this->locations} l ON l.id=d.location_id WHERE d.work_date BETWEEN %s AND %s".$siteSql." ORDER BY d.work_date,l.name,w.name",$from,$to));
        $sheets[]=[__('Days','workforce-one'),[__('Date','workforce-one'),__('Site','workforce-one'),__('Worker','workforce-one'),__('Trade','workforce-one'),__('Attendance','workforce-one'),__('Extra h','workforce-one'),__('Amount','workforce-one'),__('Paid','workforce-one')],
            array_map(function($x)use($num){return [(string)$x->work_date,(string)$x->site,(string)$x->name,$this->dw_trade_label($x->trade),$this->dw_mark_label($x->mark),$num($x->extra_hours),$num($x->amount),$x->payout_id?__('Yes','workforce-one'):__('No','workforce-one')];},$days),[12,26,24,18,12,8,12,8]];
        $this->audit('dw_report_export','daily_workers',0,'Daily workers report '.$from.' – '.$to.' ('.$fmt.')');
        if($fmt==='pdf'){
            $font=\WorkforceOne\Pdf\TrueTypeFont::fromFile(dirname(__DIR__).'/assets/vendor/dejavu/DejaVuSans.ttf');
            Download::send($this->dw_report_pdf($font,$r,$from,$to),'application/pdf','daily-workers-'.$from.'-'.$to.'.pdf');
        }
        if(!class_exists('ZipArchive'))wp_die('The PHP ZipArchive extension is required for Excel export.');
        Download::send($this->payroll_xlsx($sheets),'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','daily-workers-'.$from.'-'.$to.'.xlsx');
    }

    /** The report as a PDF: the totals and the cost tables. @param array<string,mixed> $r */
    private function dw_report_pdf($font,array $r,$from,$to){
        $doc=new \WorkforceOne\Pdf\Document($font,['Title'=>__('Daily workers reports','workforce-one')]);
        $rtl=is_rtl();$L=32.0;$R=563.0;
        $x=function($side)use($rtl,$L,$R){return ($side==='start')===!$rtl?$L:$R;};
        $al=function($side)use($rtl){return ($side==='start')===!$rtl?'left':'right';};
        $doc->addPage();$y=48.0;
        $doc->text($x('start'),$y,__('Daily workers reports','workforce-one'),18,$al('start'),[0.07,0.09,0.2],true);$y+=20;
        $doc->text($x('start'),$y,$this->dw_period_text($from,$to),10,$al('start'),[0.4,0.42,0.5]);$y+=26;
        $kp=[[__('Labour cost in the period','workforce-one'),$this->dw_money($r['total'])],[__('Owed, not paid yet','workforce-one'),$this->dw_money($r['owed'])]];
        if($r['hero_site'])$kp[]=[sprintf(/* translators: %s: site */__('%s: labour so far','workforce-one'),$r['hero_site']->project?:$r['hero_site']->name),$this->dw_money($r['hero'])];
        foreach($kp as [$k,$v]){$doc->text($x('start'),$y,$k,10,$al('start'),[0.4,0.42,0.5]);$doc->text($x('end'),$y,$v,11,$al('end'),[0.07,0.09,0.2],true);$y+=18;}
        foreach([[__('By project / site','workforce-one'),$r['by_site']],[__('By trade','workforce-one'),$r['by_trade']],[__('By subcontractor','workforce-one'),$r['by_sub']]] as [$title,$rows]){
            $y+=16;
            if($y>760){$doc->addPage();$y=48.0;}
            $doc->text($x('start'),$y,$title,12,$al('start'),[0.07,0.09,0.2],true);$y+=8;
            foreach($rows as $row){
                $y+=18;
                if($y>800){$doc->addPage();$y=48.0;}
                $label=(string)$row->label;
                if($title===__('By trade','workforce-one'))$label=$this->dw_trade_label($label);
                if($label==='')$label=$title===__('By subcontractor','workforce-one')?__('None (direct)','workforce-one'):'—';
                $doc->text($x('start'),$y,$label,10,$al('start'));
                $doc->text($x('end'),$y,$this->dw_money($row->amount).'  ·  '.$this->dw_days_text($row->days),10,$al('end'));
                $doc->line($L,$y+6,$R,$y+6,[0.9,0.91,0.95]);
            }
        }
        $doc->text($L,822,'Workforce One · '.current_time('Y-m-d H:i'),7.5,'left',[0.4,0.42,0.5]);
        return $doc->output();
    }

    /* ------------------------------------------------------------------ API (native, token) */

    private function dw_api_guard(){
        if(!$this->dw_enabled())return $this->api_fail(\WorkforceOne\Api\ErrorMap::FORBIDDEN,['reason'=>'disabled']);
        if(!$this->dw_is_manager() && !$this->dw_is_foreman() && !$this->dw_is_cashier())return $this->api_fail(\WorkforceOne\Api\ErrorMap::FORBIDDEN,['reason'=>'access']);
        $this->ensure_dw_schema_once();
        return null;
    }

    private function dw_api_error($code){
        $status=$code==='access'?\WorkforceOne\Api\ErrorMap::FORBIDDEN:\WorkforceOne\Api\ErrorMap::VALIDATION_FAILED;
        return $this->api_fail($status,['reason'=>$code,'message'=>$this->dw_error_message($code)]);
    }

    public function api_dw_sites($request){
        if($e=$this->dw_api_guard())return $e;
        $date=current_time('Y-m-d');$out=[];
        foreach($this->dw_my_site_ids() as $id){
            $s=$this->dw_site($id);
            if(!$s)continue;
            $out[]=['id'=>$id,'name'=>(string)$s->name,'project'=>(string)$s->project,'mode'=>$s->eff_mode,'period'=>$s->eff_period,'workers_today'=>count($this->dw_site_workers($id,$date)),
                'recorded_today'=>(bool)$this->dw_sheet($id,$date),'latitude'=>(float)$s->latitude,'longitude'=>(float)$s->longitude,'radius'=>(int)$s->radius];
        }
        return $this->api_ok(['date'=>$date,'sites'=>$out,'settings'=>['photo_required'=>(bool)$this->dw_settings()['photo'],'trades'=>$this->dw_settings()['trades'],'subcontractors'=>$this->dw_settings()['subcontractors']]]);
    }

    public function api_dw_day($request){
        if($e=$this->dw_api_guard())return $e;
        $id=absint($request['id']);
        if(!$this->dw_site($id) || !in_array($id,$this->dw_my_site_ids(),true))return $this->dw_api_error('access');
        $date=current_time('Y-m-d');$sheet=$this->dw_sheet($id,$date);$rows=[];
        foreach($this->dw_site_workers($id,$date) as $w){
            $d=$w->day&&(int)$w->day->location_id===$id?$w->day:null;
            $rows[]=['id'=>(int)$w->id,'code'=>$this->dw_code($w->id),'name'=>(string)$w->name,'trade'=>$this->dw_trade_label($w->trade),'daily_rate'=>(float)$w->daily_rate,'hourly_rate'=>$this->dw_hourly($w),
                'mark'=>$d?(string)$d->mark:null,'extra_hours'=>$d?(float)$d->extra_hours:0.0,'source'=>$d?(string)$d->source:null,'recorded_elsewhere'=>$w->day&&!$d];
        }
        return $this->api_ok(['date'=>$date,'locked'=>(bool)$sheet,'saved_at'=>$sheet?(string)$sheet->saved_at:null,'workers'=>$rows]);
    }

    public function api_dw_day_save($request){
        if($e=$this->dw_api_guard())return $e;
        $p=(array)$request->get_params();
        $marks=[];$extra=[];
        foreach((array)($p['marks']??[]) as $wid=>$m)$marks[(int)$wid]=sanitize_key((string)$m);
        foreach((array)($p['extra']??[]) as $wid=>$x)$extra[(int)$wid]=sanitize_text_field((string)$x);
        $files=$request->get_file_params();
        $r=$this->dw_save_sheet(absint($request['id']),$marks,$extra,$this->dw_position($p),$files['photo']??null);
        if(isset($r['error']))return $this->dw_api_error($r['error']);
        return $this->api_ok(['sheet_id'=>(int)$r['id']],201);
    }

    public function api_dw_worker_add($request){
        if($e=$this->dw_api_guard())return $e;
        $r=$this->dw_quick_add(absint($request['id']),$this->dw_input((array)$request->get_params()));
        if(isset($r['error']))return $this->dw_api_error($r['error']);
        $w=$this->dw_worker((int)$r['id']);
        return $this->api_ok(['worker'=>['id'=>(int)$w->id,'code'=>$this->dw_code($w->id),'name'=>(string)$w->name,'national_id'=>$this->dw_nid_masked($w)?:null]],201);
    }

    public function api_dw_payout($request){
        if($e=$this->dw_api_guard())return $e;
        $id=absint($request['id']);
        if(!$this->dw_site($id) || !$this->dw_can_pay($id))return $this->dw_api_error('access');
        $start=sanitize_text_field((string)($request->get_param('start')?:current_time('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))$start=current_time('Y-m-d');
        $p=$this->dw_payout($id,$start);
        return $this->api_ok(['start'=>$p['start'],'end'=>$p['end'],'paid'=>(bool)$p['paid'],'amount'=>$p['amount'],'advance'=>$p['advance'],'net'=>$p['net'],
            'lines'=>array_values(array_map(function($l){return ['worker_id'=>(int)$l['worker_id'],'name'=>$l['name'],'days'=>$l['days'],'extra_hours'=>$l['extra_hours'],'amount'=>$l['amount'],'advance'=>$l['advance'],'net'=>$l['net']];},$p['lines']))]);
    }

    public function api_dw_payout_paid($request){
        if($e=$this->dw_api_guard())return $e;
        $files=$request->get_file_params();
        $r=$this->dw_mark_paid(absint($request['id']),sanitize_text_field((string)$request->get_param('start')),$files['photo']??null);
        if(isset($r['error']))return $this->dw_api_error($r['error']);
        return $this->api_ok(['payout_id'=>(int)$r['id']],201);
    }
}
