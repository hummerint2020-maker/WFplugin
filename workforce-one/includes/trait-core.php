<?php
if (!defined('ABSPATH')) exit;

trait EWS_Core_Trait {

    /* Request-level performance caches. These are intentionally scoped to the
       current PHP request so writes remain immediately visible on the next request. */
    private static $ews_current_employee_cache = [];
    private static $ews_shifts_cache = null;
    private static $ews_shift_for_employee_cache = [];
    /** @var array<int,int> employees.default_shift_id by employee, for one request (3.31.113); cleared whenever it is written. */
    private static $ews_people_shift_memo = [];
    private static $ews_working_hours_cache = [];
    // Shift history (3.31.108): each employee's shift periods, and every shift's dated times.
    private static $ews_shift_periods_cache = [];
    private static $ews_shift_versions_cache = null;
    /** @var array<int,object|null> each employee's Work Location (or the default one), primed for a page that lists many (3.31.126) */
    private static $ews_employee_location_cache = [];
    /** @var array<string,bool> the shift periods tables found in this request, by name (3.31.126) */
    private static $ews_shift_periods_table = [];
    private static $ews_schedule_types_cache = null;
    private static $ews_schedule_type_by_name_cache = [];
    private static $ews_company_leave_cache = [];
    private static $ews_emps_cache = null;

    private function invalidate_employee_runtime_cache($employee_id=0,$wp_user_id=0){
            $employee_id=absint($employee_id); $wp_user_id=absint($wp_user_id);
            if($employee_id){unset(self::$ews_shift_for_employee_cache[$employee_id],self::$ews_shift_periods_cache[$employee_id],self::$ews_people_shift_memo[$employee_id],self::$ews_employee_location_cache[$employee_id]);self::$ews_working_hours_cache=[];}
            if($wp_user_id)unset(self::$ews_current_employee_cache[$wp_user_id]);
            self::$ews_emps_cache=null;
        }

    private function invalidate_schedule_runtime_cache(){
            self::$ews_shifts_cache=null;
            self::$ews_shift_for_employee_cache=[];
            self::$ews_working_hours_cache=[];
            self::$ews_shift_periods_cache=[];
            self::$ews_shift_versions_cache=null;
            self::$ews_schedule_types_cache=null;
            self::$ews_schedule_type_by_name_cache=[];
        }

    private function invalidate_working_hours_runtime_cache(){
            self::$ews_working_hours_cache=[];
            self::$ews_shift_versions_cache=null;
        }

    private function invalidate_company_leave_runtime_cache(){
            self::$ews_company_leave_cache=[];
        }

    /** A Workforce One setting, or its default from src/Settings/Options.php when none is saved. */
    /**
     * Loads every Workforce One setting (and the few core ones read on each app page) with one query.
     * Most of them are not autoloaded, so without this each get_option() was its own query: about 20
     * per page. Settings that were never saved are remembered as missing in the same pass.
     */
    private function prime_options(){
        if(!function_exists('wp_prime_option_caches'))return; // WordPress 6.4+
        wp_prime_option_caches(array_merge(array_keys(\WorkforceOne\Settings\Options::all()),['timezone_string','site_logo','theme_switched']));
    }

    private function option($name){
            return get_option($name,\WorkforceOne\Settings\Options::defaultOf($name));
        }


    private function employee_attendance_enabled($employee_id){
            global $wpdb;
            $employee_id=absint($employee_id);
            if(!$employee_id)return false;
            $value=$wpdb->get_var($wpdb->prepare("SELECT attendance_enabled FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
            return $value===null ? true : ((int)$value===1);
        }

function current_employee(){
            if(!is_user_logged_in())return null;
            global $wpdb;
            $uid=get_current_user_id();
            if(!$uid)return null;
            if(array_key_exists($uid,self::$ews_current_employee_cache))return self::$ews_current_employee_cache[$uid];
            self::$ews_current_employee_cache[$uid]=$this->people_employee_by_user($uid);
            return self::$ews_current_employee_cache[$uid];
        }

        private function format_duration_minutes($minutes){
            $minutes=max(0,(int)$minutes);$h=floor($minutes/60);$m=$minutes%60;
            if(!$h)return $m.' min';
            return $h.($h===1?' hour':' hours').($m?' '.$m.' min':'');
        }

    private function time_event_label($type){return $type==='sign_out'?'Sign Out':'Sign In';}

    /** URL of a file inside the plugin, e.g. plugin_url('assets/js/time.js'). Safe to call from templates. */
    /**
     * The employee app's page (the published page with the [employee_app] shortcode), for links made
     * outside the app: push notifications, emails, and redirects without a referer. The home page
     * when no such page exists. The page id is remembered in ews_app_page_id and re-checked.
     */
    private function app_home_url(){
        static $url=null;
        if($url!==null)return $url;
        $id=(int)$this->option('ews_app_page_id');
        $post=$id?get_post($id):null;
        if(!$post||$post->post_status!=='publish'||!has_shortcode((string)$post->post_content,'employee_app')){
            global $wpdb;
            $id=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[employee_app%' ORDER BY ID ASC LIMIT 1");
            update_option('ews_app_page_id',$id,false);
        }
        $url=$id?(string)get_permalink($id):home_url('/');
        return $url;
    }

    private function plugin_url($path=''){
            return plugin_dir_url(dirname(__DIR__).'/employee-schedule-manager.php').ltrim($path,'/');
        }

    /**
     * Render templates/<name>.php with $vars as local variables and return the HTML.
     * Templates run inside this object, so they can call its helpers via $this.
     */
    /** The query values a server-side list search keeps (Ui\ListPage, 3.31.71): the view, the week, the page. @return array<string,string> */
    private function list_keep_args($view){
        $out=['ews_view'=>(string)$view];
        foreach(['page_id','p','week','scope','branch'] as $k)if(isset($_GET[$k])&&is_scalar($_GET[$k])&&$_GET[$k]!=='')$out[$k]=sanitize_text_field(wp_unslash((string)$_GET[$k]));
        return $out;
    }

    private function render_template($name,array $vars=[]){
            $file=dirname(__DIR__).'/templates/'.$name.'.php';
            if(!is_file($file))return '';
            extract($vars,EXTR_SKIP);
            ob_start();
            include $file;
            return ob_get_clean();
        }

    private function location_distance_meters($lat1,$lon1,$lat2,$lon2){
            return \WorkforceOne\Support\Geo::distanceMeters($lat1,$lon1,$lat2,$lon2);
        }

    private function format_distance($m){
            if($m===null)return 'Not available';
            return $m<1000?round($m).' m':number_format($m/1000,2).' km';
        }

    private function format_time_label($time){
            $time=is_string($time)?trim($time):'';
            if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$time))return $time;
            $ts=strtotime('1970-01-01 '.$time.':00');
            return $ts?date_i18n('g:i A',$ts):$time;
        }

    public function prevent_dynamic_page_cache(){
            // Workforce One frontend pages are user-specific and contain live state
            // (authentication, attendance, notifications, requests, schedules, etc.).
            // Never allow a page cache to serve one user's rendered page to another user.
            $dynamic=false;
            if(isset($_GET['ews_view']))$dynamic=true;
            if(is_user_logged_in() && function_exists('is_singular') && is_singular()){
                global $post;
                if($post && !empty($post->post_content) && has_shortcode($post->post_content,'employee_app'))$dynamic=true;
            }
            if(!$dynamic)return;
            if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
            if(!defined('DONOTCACHEDB'))define('DONOTCACHEDB',true);
            if(!defined('DONOTMINIFY'))define('DONOTMINIFY',true);
            if(function_exists('nocache_headers'))nocache_headers();
            $GLOBALS['ews31_dynamic_no_cache']=true;
        }

        public function send_dynamic_no_cache_headers(){
            if(empty($GLOBALS['ews31_dynamic_no_cache']))return;
            if(!headers_sent()){
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            }
        }

    private function pwa_splash_settings(){
            $defaults=\WorkforceOne\Settings\FeatureSettings::SPLASH_DEFAULTS;
            $saved=$this->option('ews_pwa_splash_settings');
            $s=wp_parse_args(is_array($saved)?$saved:[],$defaults);
            $s['enabled']=!empty($s['enabled'])?1:0;
            $s['duration_ms']=max(0,min(3000,(int)$s['duration_ms']));
            $s['title']=sanitize_text_field($s['title']);
            $s['subtitle']=sanitize_text_field($s['subtitle']);
            $s['background']=sanitize_hex_color($s['background'])?:$defaults['background'];
            $s['accent']=sanitize_hex_color($s['accent'])?:$defaults['accent'];
            $s['logo']=esc_url($s['logo']);
            return $s;
        }

    private function normalize_date($value){
            $value=trim((string)$value);
            if($value==='') return '';
            if(preg_match('/^(\\d{4})-(\\d{1,2})-(\\d{1,2})$/',$value,$m)){
                $y=(int)$m[1];$mo=(int)$m[2];$d=(int)$m[3];
                return checkdate($mo,$d,$y)?sprintf('%04d-%02d-%02d',$y,$mo,$d):'';
            }
            if(preg_match('/^(\\d{1,2})[\\/.-](\\d{1,2})[\\/.-](\\d{4})$/',$value,$m)){
                $mo=(int)$m[1];$d=(int)$m[2];$y=(int)$m[3];
                if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
            }
            if(preg_match('/^(\\d{1,2})[\\/.-](\\d{1,2})[\\/.-](\\d{2})$/',$value,$m)){
                $mo=(int)$m[1];$d=(int)$m[2];$y=2000+(int)$m[3];
                if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
            }
            if(ctype_digit($value)){
                $serial=(int)$value;
                if($serial>=1 && $serial<=60000){
                    $base=new DateTime('1899-12-30');
                    $base->modify('+'.$serial.' days');
                    return $base->format('Y-m-d');
                }
            }
            return '';
        }

    private function valid_date($d){if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))return false;[$y,$m,$day]=array_map('intval',explode('-',$d));return checkdate($m,$day,$y);}

    private function audit($a,$e,$id=0,$d=''){global $wpdb;$wpdb->insert($this->audit,['user_id'=>get_current_user_id(),'action'=>$a,'entity'=>$e,'entity_id'=>$id,'details'=>$d,'created_at'=>current_time('mysql')]);}

    private function emps(){
            if(self::$ews_emps_cache!==null)return self::$ews_emps_cache;
            global $wpdb;
            self::$ews_emps_cache=$wpdb->get_results("SELECT * FROM {$this->employees} WHERE active=1 ORDER BY name ASC");
            // Their shifts come with the rows: no query per employee in lists (Attendance, Insights, …).
            if(method_exists($this,'prime_shift_cache'))$this->prime_shift_cache((array)self::$ews_emps_cache);
            return self::$ews_emps_cache;
        }

    private function emp_domain($d){global $wpdb;return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE domain_name=%s AND active=1 LIMIT 1",trim($d)));}


        /** The Attendance grid's guarded save (ScheduleDayService::saveIfUnchanged(), 3.31.127). */
        private function save_att_if_unchanged($eid,$date,$status,$expected_status){
            return $this->services()->scheduleDays()->saveIfUnchanged((int)$eid,(string)$date,(string)$status,$expected_status===null?null:(string)$expected_status,get_current_user_id());
        }

    /** Save a schedule day whatever it was (ScheduleDayService::save(), 3.31.127). @return int|string the row id, 0 on a database error */
    private function save_att($eid,$date,$status,$note=''){return $this->services()->scheduleDays()->save((int)$eid,(string)$date,(string)$status,$note,get_current_user_id());}
}
