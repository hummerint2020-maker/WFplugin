<?php
if (!defined('ABSPATH')) exit;

trait EWS_Core_Trait {

    /* Request-level performance caches. These are intentionally scoped to the
       current PHP request so writes remain immediately visible on the next request. */
    private static $ews_current_employee_cache = [];
    private static $ews_shifts_cache = null;
    private static $ews_shift_for_employee_cache = [];
    private static $ews_working_hours_cache = [];
    private static $ews_schedule_types_cache = null;
    private static $ews_schedule_type_by_name_cache = [];
    private static $ews_company_leave_cache = [];
    private static $ews_emps_cache = null;

    private function invalidate_employee_runtime_cache($employee_id=0,$wp_user_id=0){
            $employee_id=absint($employee_id); $wp_user_id=absint($wp_user_id);
            if($employee_id){unset(self::$ews_shift_for_employee_cache[$employee_id],self::$ews_working_hours_cache[$employee_id]);}
            if($wp_user_id)unset(self::$ews_current_employee_cache[$wp_user_id]);
            self::$ews_emps_cache=null;
        }

    private function invalidate_schedule_runtime_cache(){
            self::$ews_shifts_cache=null;
            self::$ews_shift_for_employee_cache=[];
            self::$ews_working_hours_cache=[];
            self::$ews_schedule_types_cache=null;
            self::$ews_schedule_type_by_name_cache=[];
        }

    private function invalidate_working_hours_runtime_cache(){
            self::$ews_working_hours_cache=[];
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
            self::$ews_current_employee_cache[$uid]=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->employees} WHERE wp_user_id=%d AND active=1 LIMIT 1",$uid));
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


        private function save_att_if_unchanged($eid,$date,$status,$expected_status){
            global $wpdb;
            $row=$wpdb->get_row($wpdb->prepare(
                "SELECT id,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                $eid,$date
            ));

            /*
             * Concurrency guard for the Attendance grid:
             * - expected_status === null means the cell was empty when the page loaded.
             * - otherwise the manager loaded this exact schedule status.
             *
             * The actual UPDATE is conditional on the expected status, so a
             * second manager cannot silently overwrite a newer value.
             */
            if($expected_status===null){
                if($row){
                    return ['ok'=>false,'conflict'=>true,'id'=>(int)$row->id];
                }

                $data=[
                    'employee_id'=>$eid,
                    'work_date'=>$date,
                    'status'=>$status,
                    'note'=>'',
                    'updated_by'=>get_current_user_id(),
                    'updated_at'=>current_time('mysql')
                ];
                $inserted=$wpdb->insert($this->schedule,$data);
                if($inserted===false){
                    /*
                     * The UNIQUE employee_date key protects the insert if two
                     * managers create the same previously-empty cell at once.
                     * If another insert won the race, report a conflict rather
                     * than treating it as a generic database failure.
                     */
                    $winner=$wpdb->get_row($wpdb->prepare(
                        "SELECT id,status,note FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s LIMIT 1",
                        $eid,$date
                    ));
                    if($winner){
                        return ['ok'=>false,'conflict'=>true,'id'=>(int)$winner->id];
                    }
                    $this->audit('schedule_insert_failed','schedule',0,$wpdb->last_error?:'Database insert failed.');
                    return ['ok'=>false,'conflict'=>false,'id'=>0];
                }
                $id=(int)$wpdb->insert_id;
                $old_status=null;
                $old_note='';
                $row=$wpdb->get_row($wpdb->prepare(
                    "SELECT id,status,note FROM {$this->schedule} WHERE id=%d",
                    $id
                ));
            }else{
                if(!$row || (string)$row->status!==(string)$expected_status){
                    return ['ok'=>false,'conflict'=>true,'id'=>$row?(int)$row->id:0];
                }

                /*
                 * Keep the current note. The Attendance grid edits schedule
                 * status only and has no note input, so it must never erase a
                 * note that another workflow/user has stored.
                 */
                $old_status=(string)$row->status;
                $old_note=(string)$row->note;
                $data=[
                    'status'=>$status,
                    'note'=>$old_note,
                    'updated_by'=>get_current_user_id(),
                    'updated_at'=>current_time('mysql')
                ];

                /*
                 * Atomic compare-and-update. The status in the WHERE clause
                 * is the value the manager saw when the grid was loaded.
                 */
                $updated=$wpdb->query($wpdb->prepare(
                    "UPDATE {$this->schedule}
                     SET status=%s,note=%s,updated_by=%d,updated_at=%s
                     WHERE id=%d AND status=%s",
                    $status,$old_note,get_current_user_id(),current_time('mysql'),
                    (int)$row->id,$expected_status
                ));

                if($updated===false){
                    $this->audit('schedule_update_failed','schedule',(int)$row->id,$wpdb->last_error?:'Database update failed.');
                    return ['ok'=>false,'conflict'=>false,'id'=>(int)$row->id];
                }

                /*
                 * 0 rows can mean a concurrent manager changed the value
                 * between the pre-check and the UPDATE. Re-read and classify
                 * it as a conflict only if the current value is different.
                 */
                if((int)$updated===0){
                    $latest=$wpdb->get_row($wpdb->prepare(
                        "SELECT status FROM {$this->schedule} WHERE id=%d",
                        (int)$row->id
                    ));
                    if(!$latest || (string)$latest->status!==(string)$expected_status){
                        return ['ok'=>false,'conflict'=>true,'id'=>(int)$row->id];
                    }
                    /*
                     * The requested value is already the same value. Treat
                     * this as a harmless no-op rather than a false conflict.
                     */
                    return ['ok'=>true,'conflict'=>false,'id'=>(int)$row->id,'changed'=>false,'old_status'=>(string)$row->status];
                }

                $id=(int)$row->id;
            }

            $emp=$wpdb->get_row($wpdb->prepare(
                "SELECT name,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1",
                $eid
            ));
            $changed=($old_status!==$status);
            if($emp && $emp->wp_user_id && $changed){
                $label=date_i18n('l, d M Y',strtotime($date));
                $this->notify(
                    (int)$emp->wp_user_id,
                    'schedule',
                    'Schedule Updated',
                    'Your schedule for <strong>'.esc_html($label).'</strong> is now <strong>'.esc_html($status).'</strong>.',
                    ['type'=>'info','entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','notifications',$this->app_home_url())]
                );
            }

            return ['ok'=>true,'conflict'=>false,'id'=>$id,'changed'=>$changed,'old_status'=>$old_status];
        }

    private function save_att($eid,$date,$status,$note=''){global $wpdb;$id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->schedule} WHERE employee_id=%d AND work_date=%s",$eid,$date));$old_status=$id?$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->schedule} WHERE id=%d",$id)):null;$old_note=$id?$wpdb->get_var($wpdb->prepare("SELECT note FROM {$this->schedule} WHERE id=%d",$id)):null;$data=['status'=>$status,'note'=>$note,'updated_by'=>get_current_user_id(),'updated_at'=>current_time('mysql')];if($id){$updated=$wpdb->update($this->schedule,$data,['id'=>$id]);if($updated===false){$this->audit('schedule_update_failed','schedule',(int)$id,$wpdb->last_error?:'Database update failed.');return 0;}}else{$data['employee_id']=$eid;$data['work_date']=$date;$inserted=$wpdb->insert($this->schedule,$data);if($inserted===false){$this->audit('schedule_insert_failed','schedule',0,$wpdb->last_error?:'Database insert failed.');return 0;}$id=$wpdb->insert_id;}$emp=$wpdb->get_row($wpdb->prepare("SELECT name,wp_user_id FROM {$this->employees} WHERE id=%d AND active=1",$eid));$changed=($old_status!==$status)||((string)$old_note!==(string)$note);if($emp&&$emp->wp_user_id&&$changed){$label=date_i18n('l, d M Y',strtotime($date));$this->notify((int)$emp->wp_user_id,'schedule','Schedule Updated','Your schedule for <strong>'.esc_html($label).'</strong> is now <strong>'.esc_html($status).'</strong>.',['type'=>'info','entity_id'=>(int)$id,'url'=>add_query_arg('ews_view','notifications',$this->app_home_url())]);}return $id;}
}
