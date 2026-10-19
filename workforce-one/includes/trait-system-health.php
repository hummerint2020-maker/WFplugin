<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Health\Alerts;
use WorkforceOne\Health\Findings;
use WorkforceOne\Health\Messages;
use WorkforceOne\Health\QueryShape;
use WorkforceOne\Health\Routes;
use WorkforceOne\Health\Settings as HealthSettings;
use WorkforceOne\Health\Stats;

/**
 * System health (3.31.110; design docs/tasks/system-health.md, mockup docs/mockups/system-health/): how long
 * the plugin's pages, actions, API routes and scheduled jobs take, their database queries, peak memory and PHP
 * errors, kept in this site's database (nothing is sent anywhere) and shown on wp-admin → Settings → System
 * health with a ranked "Look at this first" list, an admin notice and an optional email.
 *
 * Off by default (wp-admin → Features). While off nothing here runs: no hook, no query, no table, no cron.
 * The first time it is switched on the four tables are created and the hourly rollup is scheduled.
 *
 * On, every Workforce One request costs one UPDATE at shutdown: the route, its time, queries, memory and
 * status are added to one hourly row. A sample of requests (10% by default; always the heavy operations and
 * scheduled jobs) also keeps SAVEQUERIES to find slow and repeated queries; only a query's shape is stored,
 * with its literals removed. Nothing personal is stored (no user, employee, IP, URL or parameter).
 * Rules: src/Health/. Behaviour: tests/e2e_system_health.py.
 */
trait EWS_System_Health_Trait {

    /** @var array<string,mixed> the current request's measurements */
    private $health=[];

    private function health_enabled(){ return (bool)$this->option('ews_feature_health'); }

    /** @return array<string,int|string> */
    private function health_settings(){ return HealthSettings::settings($this->option('ews_health_settings')); }

    /** Who sees the screen: site administrators, or who was given "View System Health"; on a network, super admins only. */
    private function health_can_view(){
        if(!is_user_logged_in())return false;
        if(is_multisite())return is_super_admin();
        return $this->can('ews_view_system_health');
    }

    /** Thresholds and deleting data also need "Manage Settings". */
    private function health_can_change(){ return $this->health_can_view() && $this->can('ews_manage_settings'); }

    /** @return array{hourly:string,daily:string,queries:string,errors:string} */
    private function health_tables(){
        global $wpdb;
        return ['hourly'=>$wpdb->prefix.'ews_health_hourly','daily'=>$wpdb->prefix.'ews_health_daily','queries'=>$wpdb->prefix.'ews_health_queries','errors'=>$wpdb->prefix.'ews_health_errors'];
    }

    /** The counters both aggregate tables share. */
    private function health_counter_columns(){
        $cols=['n INT UNSIGNED NOT NULL DEFAULT 0','sampled INT UNSIGNED NOT NULL DEFAULT 0','failed INT UNSIGNED NOT NULL DEFAULT 0','fatal INT UNSIGNED NOT NULL DEFAULT 0','ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0','ms_max INT UNSIGNED NOT NULL DEFAULT 0'];
        for($i=0;$i<Stats::BUCKETS;$i++)$cols[]='b'.$i.' INT UNSIGNED NOT NULL DEFAULT 0';
        return array_merge($cols,['q_sum BIGINT UNSIGNED NOT NULL DEFAULT 0','q_max INT UNSIGNED NOT NULL DEFAULT 0','pq_sum BIGINT UNSIGNED NOT NULL DEFAULT 0','pq_us_sum BIGINT UNSIGNED NOT NULL DEFAULT 0','pq_n INT UNSIGNED NOT NULL DEFAULT 0',
            'mem_sum BIGINT UNSIGNED NOT NULL DEFAULT 0','mem_max INT UNSIGNED NOT NULL DEFAULT 0','mem_pct_max SMALLINT UNSIGNED NOT NULL DEFAULT 0','mem_n INT UNSIGNED NOT NULL DEFAULT 0','err INT UNSIGNED NOT NULL DEFAULT 0',"last_at DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'"]);
    }

    /** Creates the four tables; only when the feature is first switched on (never in the global schema upgrade). */
    private function ensure_health_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t=$this->health_tables();$c=$wpdb->get_charset_collate();
        $counters=implode(",\n            ",$this->health_counter_columns());
        dbDelta("CREATE TABLE {$t['hourly']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hour DATETIME NOT NULL,
            route VARCHAR(120) NOT NULL,
            slot TINYINT UNSIGNED NOT NULL DEFAULT 0,
            version VARCHAR(20) NOT NULL DEFAULT '',
            {$counters},
            PRIMARY KEY  (id),
            UNIQUE KEY route_hour_slot_version (route,hour,slot,version),
            KEY hour (hour)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['daily']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            route VARCHAR(120) NOT NULL,
            version VARCHAR(20) NOT NULL DEFAULT '',
            {$counters},
            PRIMARY KEY  (id),
            UNIQUE KEY route_day_version (route,day,version),
            KEY day (day)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['queries']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            route VARCHAR(120) NOT NULL,
            fp CHAR(16) NOT NULL,
            caller VARCHAR(190) NOT NULL DEFAULT '',
            shape VARCHAR(500) NOT NULL DEFAULT '',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            requests INT UNSIGNED NOT NULL DEFAULT 0,
            us_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
            us_max INT UNSIGNED NOT NULL DEFAULT 0,
            max_repeat INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY day_route_fp (day,route,fp),
            KEY day (day)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['errors']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day DATE NOT NULL,
            fp CHAR(16) NOT NULL,
            level VARCHAR(12) NOT NULL DEFAULT '',
            file VARCHAR(190) NOT NULL DEFAULT '',
            line INT UNSIGNED NOT NULL DEFAULT 0,
            message VARCHAR(255) NOT NULL DEFAULT '',
            route VARCHAR(120) NOT NULL DEFAULT '',
            n INT UNSIGNED NOT NULL DEFAULT 0,
            first_at DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
            last_at DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY day_fp_route (day,fp,route),
            KEY day (day)
        ) {$c};");
        update_option('ews_health_schema',HealthSettings::SCHEMA,false);
    }

    /** The Features switch changed: tables and the hourly rollup come with it; data is kept when it goes off. */
    private function health_switch_changed($on){
        if($on){
            $this->ensure_health_schema();
            if(wp_next_scheduled('ews_health_rollup')===false)wp_schedule_event(time()+300,'hourly','ews_health_rollup');
        }else{
            wp_clear_scheduled_hook('ews_health_rollup');
        }
        $this->audit($on?'health_enabled':'health_disabled','system_health',0,$on?'System health switched on':'System health switched off');
    }

    /* ------------------------------------------------------------------ the collector */

    /** Constructor: does nothing at all while the feature is off. */
    private function health_boot(){
        if(!$this->health_enabled())return;
        // The rollup, the notice and the handlers are there whenever the feature is on (the rollup also repairs the schema);
        // only the collector waits for the tables.
        add_action('ews_health_rollup',[$this,'health_rollup']);
        add_action('admin_notices',[$this,'health_notice']);
        add_action('admin_post_ews_health_settings_save',[$this,'health_settings_save']);
        add_action('admin_post_ews_health_purge',[$this,'health_purge_now']);
        add_action('admin_post_ews_health_dismiss',[$this,'health_dismiss']);
        if(wp_next_scheduled('ews_health_rollup')===false)wp_schedule_event(time()+300,'hourly','ews_health_rollup');
        if((string)get_option('ews_health_schema','')!==HealthSettings::SCHEMA)return;
        $rate=HealthSettings::rate($this->option('ews_health_sample_rate'));
        $this->health=['t0'=>isset($_SERVER['REQUEST_TIME_FLOAT'])?(float)$_SERVER['REQUEST_TIME_FLOAT']:microtime(true),'sampled'=>false,'own_sq'=>false,'errors'=>[],'agg'=>[],'folded'=>0,'rest'=>null,'cron'=>[],'cron_done'=>[],'prev'=>null,'done'=>false,'broken'=>false];
        // Sample: the share chosen; always a heavy operation asked by a signed-in person, and every cron request (queries are written
        // only for a plugin job that ran). Never because of what an anonymous request asks for.
        $script=isset($_SERVER['SCRIPT_NAME'])?basename((string)$_SERVER['SCRIPT_NAME']):'';
        $action=$script==='admin-post.php' && isset($_REQUEST['action'])?sanitize_key(wp_unslash($_REQUEST['action'])):'';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only names the request
        $signed_in=defined('LOGGED_IN_COOKIE') && isset($_COOKIE[LOGGED_IN_COOKIE]);
        $always=wp_doing_cron() || ($signed_in && $action!=='' && Routes::alwaysSampled('post:'.$action));
        if($always || mt_rand(1,10000)<=$rate*100){
            if(!defined('SAVEQUERIES')){define('SAVEQUERIES',true);$this->health['sampled']=true;$this->health['own_sq']=true;}
            elseif(SAVEQUERIES)$this->health['sampled']=true;   // the site already records them: only read
        }
        if($this->health['sampled'])add_filter('query',[$this,'health_query_tick'],10,1);
        add_action('shutdown',[$this,'health_shutdown'],PHP_INT_MAX);
        // WordPress's own handler ends the request inside its shutdown function, so a fatal error is written from its filter.
        add_filter('wp_should_handle_php_error',[$this,'health_on_fatal'],10,2);
        $this->health['prev']=set_error_handler([$this,'health_on_php_error']);
        add_filter('rest_dispatch_request',[$this,'health_rest_route'],10,3);
        if(wp_doing_cron()){
            foreach(Routes::CRON_HOOKS as $hook){
                add_action($hook,function()use($hook){$this->health_cron_start($hook);},PHP_INT_MIN);
                add_action($hook,function()use($hook){$this->health_cron_end($hook);},PHP_INT_MAX);
            }
        }
    }

    /** Filter 'query' (sampled requests only): keeps the query log from growing without bound. */
    public function health_query_tick($sql){
        global $wpdb;
        if($this->health['own_sq'] && count((array)$wpdb->queries)>=500)$this->health_fold();
        return $sql;
    }

    /** Folds the logged queries into per-shape totals, then (when we turned logging on) empties the log. */
    private function health_fold(){
        global $wpdb;
        $q=(array)$wpdb->queries;
        for($i=(int)$this->health['folded'];$i<count($q);$i++){
            $caller=QueryShape::caller((string)($q[$i][2]??''));
            $shape=QueryShape::normalize((string)$q[$i][0],$wpdb->prefix);
            $fp=QueryShape::fingerprint($shape,$caller);
            $us=(int)round(((float)($q[$i][1]??0))*1000000);
            $a=&$this->health['agg'][$fp];
            if(!$a)$a=['shape'=>$shape,'caller'=>$caller,'n'=>0,'us'=>0,'max'=>0];
            $a['n']++;$a['us']+=$us;$a['max']=max($a['max'],$us);
            unset($a);
        }
        $this->health['folded']=$this->health['own_sq']?0:count($q);
        if($this->health['own_sq'])$wpdb->queries=[];
    }

    /** set_error_handler: counts PHP errors raised in this plugin's files; never changes what PHP does. */
    public function health_on_php_error($errno,$errstr,$errfile='',$errline=0){
        if((error_reporting() & $errno) && count($this->health['errors'])<20){
            $rel=Messages::relative((string)$errfile,dirname(__DIR__));
            if($rel!==''){
                $level=Messages::level((int)$errno);
                $fp=Messages::fingerprint($level,$rel,(int)$errline);
                if(isset($this->health['errors'][$fp]))$this->health['errors'][$fp]['n']++;
                else $this->health['errors'][$fp]=['level'=>$level,'file'=>$rel,'line'=>(int)$errline,'message'=>Messages::normalize((string)$errstr),'n'=>1];
                if($errno & (E_ERROR|E_WARNING|E_USER_ERROR|E_USER_WARNING|E_RECOVERABLE_ERROR)){foreach($this->health['cron'] as $h=>$c)$this->health['cron'][$h]['errors']++;}
            }
        }
        $prev=$this->health['prev'];
        return $prev?call_user_func($prev,$errno,$errstr,$errfile,$errline):false;
    }

    /** Filter rest_dispatch_request: notes the matched route, changes nothing. */
    public function health_rest_route($result,$request,$route){
        $this->health['rest']=['route'=>(string)$route,'method'=>is_object($request)?(string)$request->get_method():'GET'];
        return $result;
    }

    private function health_cron_start($hook){
        if($this->health['sampled'])$this->health_fold();
        $this->health['agg']=[];
        $this->health['cron'][$hook]=['t0'=>microtime(true),'errors'=>0];
    }

    private function health_cron_end($hook){
        $c=$this->health['cron'][$hook]??null;
        if(!$c)return;
        unset($this->health['cron'][$hook]);
        if($this->health['sampled'])$this->health_fold();
        if(count($this->health['cron_done'])<200)$this->health['cron_done'][]=['hook'=>$hook,'ms'=>(int)round((microtime(true)-$c['t0'])*1000),'ok'=>$c['errors']===0,'agg'=>$this->health['agg']];
        $this->health['agg']=[];
    }

    /** The classified name of this request (docs/tasks/system-health.md §2.5). */
    private function health_route(){
        if(wp_doing_cron())return 'cron:other';
        $script=isset($_SERVER['SCRIPT_NAME'])?basename((string)$_SERVER['SCRIPT_NAME']):'';
        $f=['script'=>$script,'is_admin'=>is_admin()];
        if(!empty($this->health['rest']))$f+=['rest_route'=>$this->health['rest']['route'],'rest_method'=>$this->health['rest']['method']];
        if($script==='admin-post.php'){
            $a=isset($_REQUEST['action'])?sanitize_key(wp_unslash($_REQUEST['action'])):'';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $f['action']=$a;$f['action_known']=$a!=='' && (has_action('admin_post_'.$a) || has_action('admin_post_nopriv_'.$a));
        }
        if(is_admin()){
            $p=isset($_GET['page'])?sanitize_key(wp_unslash($_GET['page'])):'';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $f['page']=$p;$f['page_known']=$p==='ews31' || $p==='ews31-employee-profile' || in_array($p,\WorkforceOne\Ui\AdminNav::slugs(),true);
        }elseif(function_exists('is_singular') && did_action('wp') && is_singular()){
            $o=get_queried_object();
            if($o && isset($o->post_content) && has_shortcode((string)$o->post_content,'employee_app')){
                $f['app_page']=true;$f['view']=isset($_GET['ews_view'])?sanitize_key(wp_unslash($_GET['ews_view'])):'';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $f['views']=array_merge(array_keys(\WorkforceOne\Settings\Navigation::DEFAULTS),['worker']);
            }
        }
        return Routes::classify($f);
    }

    /** shutdown, last: the request's figures. */
    public function health_shutdown(){
        $this->health_finish(error_get_last());
    }

    /** wp_should_handle_php_error: WordPress is about to end a request that died on a fatal error; this is the last place to write it. */
    public function health_on_fatal($should,$error){
        if(is_array($error))$this->health_finish($error);
        return $should;
    }

    /** One UPDATE for the request (and a few rows more for a sampled one); never lets a problem here reach the request. @param array<string,mixed>|null $err */
    private function health_finish($err){
        global $wpdb;
        if($this->health['done']||$this->health['broken'])return;
        $this->health['done']=true;
        $quiet=$wpdb->suppress_errors(true);   // a missing table must not print or log an error on every request
        try{
            $this->health_finish_inner($err);
        }catch(\Throwable $e){   // the collector never breaks a page
            $this->health['broken']=true;
        }
        $wpdb->suppress_errors($quiet);
    }

    /** @param array<string,mixed>|null $err */
    private function health_finish_inner($err){
        global $wpdb;
        $ms=(int)round((microtime(true)-$this->health['t0'])*1000);
        $dead=is_array($err) && in_array($err['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR],true);
        $fatal=$dead && Messages::relative((string)$err['file'],dirname(__DIR__))!=='';
        $status=function_exists('http_response_code')?(int)http_response_code():0;
        $failed=$dead || $status>=500;
        $nq=(int)$wpdb->num_queries;   // read before this collector's own queries
        $peak=memory_get_peak_usage(true);$mem=(int)round($peak/1024);
        $limit=ini_get('memory_limit');$limit_b=$limit!==false && $limit!=='' && (int)$limit!==-1?(int)wp_convert_hr_to_bytes((string)$limit):0;   // this request's own limit (wp-admin raises it)
        $mem_pct=$limit_b>0?min(999,(int)round($peak/$limit_b*100)):0;
        $sampled=!empty($this->health['sampled']) && defined('SAVEQUERIES') && SAVEQUERIES;
        $at=gmdate('Y-m-d H:i:s');
        if($fatal){
            $rel=Messages::relative((string)$err['file'],dirname(__DIR__));
            $fp=Messages::fingerprint('error',$rel,(int)$err['line']);
            $this->health['errors'][$fp]=['level'=>'error','file'=>$rel,'line'=>(int)$err['line'],'message'=>Messages::normalize((string)$err['message']),'n'=>1];
        }
        if(wp_doing_cron()){
            foreach($this->health['cron'] as $hook=>$c)$this->health['cron_done'][]=['hook'=>$hook,'ms'=>(int)round((microtime(true)-$c['t0'])*1000),'ok'=>false,'agg'=>[]];   // started, never finished
            if(!$this->health['cron_done'])return;   // not one of the plugin's jobs: nothing to say, nothing written
            $last=get_option('ews_health_cron_last',[]);$last=is_array($last)?$last:[];
            $ok=[];
            foreach($this->health['cron_done'] as $d){   // every run counts, also when one hook ran several times (a queue)
                $pq=0;$pq_us=0;
                foreach($d['agg'] as $a)if($a['caller']!==''){$pq+=$a['n'];$pq_us+=$a['us'];}
                $this->health_record('cron:'.$d['hook'],$d['ms'],0,$mem,$mem_pct,!$d['ok'],false,$sampled,$pq,$pq_us,0);
                if($sampled)$this->health_write_queries('cron:'.$d['hook'],$d['agg']);
                $ok[$d['hook']]=($ok[$d['hook']]??true) && $d['ok'];
                $last[$d['hook']]=['at'=>time(),'ms'=>$d['ms'],'ok'=>$ok[$d['hook']]];
            }
            update_option('ews_health_cron_last',$last,false);
            $this->health_write_errors('cron:other',$at);
            return;
        }
        $route=$this->health_route();
        // Not the plugin's (another plugin's page, REST route or action) and not sampled: nothing to say unless it raised an error of ours.
        if(in_array($route,['rest:other','post:other'],true) || strpos($route,'other:')===0){
            if(!$sampled && !$this->health['errors'])return;
        }
        $pq=0;$pq_us=0;$agg=[];
        if($sampled){
            $this->health_fold();
            $agg=$this->health['agg'];
            foreach($agg as $a)if($a['caller']!==''){$pq+=$a['n'];$pq_us+=$a['us'];}
        }
        $own=!in_array($route,['rest:other','post:other'],true) && strpos($route,'other:')!==0;
        if($own || $sampled)$this->health_record($route,$ms,$nq,$mem,$mem_pct,$failed,$fatal,$sampled,$pq,$pq_us,count($this->health['errors']));
        $this->health_write_queries($route,$agg);
        $this->health_write_errors($route,$at);
    }

    /** Adds one request to its hourly row (a few slots, so a burst does not queue on one row). */
    private function health_record($route,$ms,$nq,$mem,$mem_pct,$failed,$fatal,$sampled,$pq,$pq_us,$errors){
        $hour=gmdate('Y-m-d H:00:00');
        $inc=['n'=>1,'sampled'=>$sampled?1:0,'failed'=>$failed?1:0,'fatal'=>$fatal?1:0,'ms_sum'=>max(0,$ms),'b'.Stats::bucket(max(0,$ms))=>1,'q_sum'=>$nq,'pq_sum'=>$pq,'pq_us_sum'=>$pq_us,'pq_n'=>$sampled?1:0,'mem_sum'=>$mem,'mem_n'=>1,'err'=>$errors];
        $max=['ms_max'=>max(0,$ms),'q_max'=>$nq,'mem_max'=>$mem,'mem_pct_max'=>$mem_pct];
        $this->health_upsert($this->health_tables()['hourly'],['route'=>substr($route,0,120),'hour'=>$hour,'slot'=>mt_rand(0,3),'version'=>substr((string)EWS_VERSION,0,20)],$inc,$max,['last_at'=>gmdate('Y-m-d H:i:s')]);
    }

    /**
     * UPDATE … SET n=n+1 on the unique key; INSERT when no row yet; UPDATE again when another request inserted it first.
     * Portable (MySQL and SQLite): no ON DUPLICATE KEY, no GREATEST. $init is written only by the INSERT.
     * @param array<string,int|string> $keys $inc $max $set $init
     */
    private function health_upsert($table,array $keys,array $inc,array $max,array $set,array $init=[]){
        global $wpdb;
        if($this->health['broken'])return;
        $sets=[];$args=[];
        foreach($inc as $c=>$v){$sets[]="`{$c}`=`{$c}`+%d";$args[]=(int)$v;}
        foreach($max as $c=>$v){$sets[]="`{$c}`=CASE WHEN `{$c}`<%d THEN %d ELSE `{$c}` END";$args[]=(int)$v;$args[]=(int)$v;}
        foreach($set as $c=>$v){$sets[]="`{$c}`=%s";$args[]=(string)$v;}
        $where=[];
        foreach($keys as $c=>$v){$where[]="`{$c}`=".(is_int($v)?'%d':'%s');$args[]=$v;}
        $sql="UPDATE {$table} SET ".implode(',',$sets).' WHERE '.implode(' AND ',$where);
        $changed=$wpdb->query($wpdb->prepare($sql,$args));
        if($changed)return;
        $row=$keys+$inc+$max+$set+$init;
        $wpdb->suppress_errors(true);
        $ok=$wpdb->insert($table,$row);
        $wpdb->suppress_errors(false);
        if(!$ok){
            $wpdb->query($wpdb->prepare($sql,$args));
            if($wpdb->last_error!=='')$this->health['broken']=true;   // the table is missing: stop trying on this request
        }
    }

    /** The sampled request's slow and repeated query shapes: the top ones by time, and any repeated enough. */
    private function health_write_queries($route,array $agg){
        if(!$agg || $this->option('ews_health_full'))return;
        $s=$this->health_settings();
        uasort($agg,function($a,$b){return $b['us']<=>$a['us'];});
        $day=gmdate('Y-m-d');$kept=0;
        foreach($agg as $fp=>$a){
            $rep=$a['n']>=$s['repeat'];
            if($a['caller']==='' && !$rep)continue;
            if($kept>=10 && !$rep)continue;
            if(++$kept>20)break;
            $this->health_upsert($this->health_tables()['queries'],['day'=>$day,'route'=>substr($route,0,120),'fp'=>(string)$fp],['n'=>$a['n'],'requests'=>1,'us_sum'=>$a['us']],['us_max'=>$a['max'],'max_repeat'=>$a['n']],[],['shape'=>$a['shape'],'caller'=>$a['caller']]);
        }
    }

    private function health_write_errors($route,$at){
        if(!$this->health['errors'])return;
        if($this->option('ews_health_full'))return;
        $day=gmdate('Y-m-d');
        foreach($this->health['errors'] as $fp=>$e){
            $this->health_upsert($this->health_tables()['errors'],['day'=>$day,'fp'=>(string)$fp,'route'=>substr($route,0,120)],['n'=>$e['n']],[],['last_at'=>$at],['level'=>$e['level'],'file'=>$e['file'],'line'=>$e['line'],'message'=>$e['message'],'first_at'=>$at]);
        }
    }

    /* ------------------------------------------------------------------ the hourly rollup */

    private function health_sum_columns(){
        $c=['n','sampled','failed','fatal','ms_sum','q_sum','pq_sum','pq_us_sum','pq_n','mem_sum','mem_n','err'];
        for($i=0;$i<Stats::BUCKETS;$i++)$c[]='b'.$i;
        return $c;
    }

    /** Cron ews_health_rollup (hourly, only while on): daily rows, purge, findings, alerts, email. */
    public function health_rollup(){
        if(!$this->health_enabled())return;
        if((string)get_option('ews_health_schema','')!==HealthSettings::SCHEMA)$this->ensure_health_schema();
        global $wpdb;
        $lastday=(string)$wpdb->get_var("SELECT MAX(day) FROM {$this->health_tables()['daily']}");
        $from=$lastday!==''?max(gmdate('Y-m-d',time()-6*86400),gmdate('Y-m-d',strtotime($lastday.' UTC')-86400)):gmdate('Y-m-d',time()-6*86400);
        for($t=strtotime($from.' UTC');$t<=time();$t+=86400)$this->health_build_daily(gmdate('Y-m-d',$t));   // days missed while cron was off included
        if((string)get_option('ews_health_purged','')!==gmdate('Y-m-d')){
            $this->health_purge();
            update_option('ews_health_purged',gmdate('Y-m-d'),false);
        }
        $findings=Findings::evaluate($this->health_findings_input(),$this->health_settings());
        update_option('ews_health_findings',array_map(function($f){return ['key'=>$f['key'],'severity'=>$f['severity'],'rule'=>$f['rule'],'route'=>$f['route'],'args'=>$f['args']];},$findings),false);
        $open=get_option('ews_health_alerts',[]);$open=is_array($open)?$open:[];
        $u=Alerts::update($open,$findings,time());
        $this->health_email($u['open']);
        update_option('ews_health_alerts',$u['open'],false);
        update_option('ews_health_rollup_at',time(),false);
    }

    /** Rebuilds one day's rows from its hourly rows, one row per route and release (a release in the middle of a day splits it). */
    private function health_build_daily($day){
        global $wpdb;
        $t=$this->health_tables();$cols=$this->health_sum_columns();
        $sum=implode(',',array_map(function($c){return "SUM({$c}) {$c}";},$cols));
        $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT route,version,{$sum},MAX(ms_max) ms_max,MAX(q_max) q_max,MAX(mem_max) mem_max,MAX(mem_pct_max) mem_pct_max FROM {$t['hourly']} WHERE hour>=%s AND hour<=%s GROUP BY route,version",$day.' 00:00:00',$day.' 23:59:59'),ARRAY_A);
        if(!$rows)return;
        usort($rows,function($a,$b){return [$a['route'],0]<=>[$b['route'],0] ?: version_compare((string)$a['version'],(string)$b['version']);});   // older release first
        $wpdb->delete($t['daily'],['day'=>$day]);
        foreach($rows as $r){
            $route=(string)$r['route'];$version=(string)$r['version'];unset($r['route'],$r['version']);
            $wpdb->insert($t['daily'],['day'=>$day,'route'=>$route,'version'=>$version!==''?$version:substr((string)EWS_VERSION,0,20),'last_at'=>gmdate('Y-m-d H:i:s')]+array_map('intval',$r));
        }
    }

    /** Old rows out, in batches; a hard cap on the rows kept sets ews_health_full. */
    private function health_purge(){
        global $wpdb;
        $t=$this->health_tables();
        $keep=HealthSettings::retention($this->option('ews_health_retention_days'));
        $cut=[ 'hourly'=>['hour',gmdate('Y-m-d H:i:s',time()-7*86400)],'daily'=>['day',gmdate('Y-m-d',time()-$keep*86400)],
            'queries'=>['day',gmdate('Y-m-d',time()-min(30,$keep)*86400)],'errors'=>['day',gmdate('Y-m-d',time()-min(30,$keep)*86400)] ];
        foreach($cut as $k=>[$col,$before]){
            for($i=0;$i<25;$i++){
                $ids=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t[$k]} WHERE {$col}<%s ORDER BY id LIMIT 2000",$before)));
                if(!$ids)break;
                $wpdb->query("DELETE FROM {$t[$k]} WHERE id IN (".implode(',',$ids).')');
            }
        }
        $rows=0;foreach($t as $table)$rows+=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        update_option('ews_health_full',$rows>300000?1:0,false);
    }

    /** "Delete collected data" (screen). */
    public function health_purge_now(){
        if(!$this->health_can_change())wp_die('Access denied');
        check_admin_referer('ews_health_purge');
        global $wpdb;
        foreach($this->health_tables() as $table)$wpdb->query("DELETE FROM {$table}");
        foreach(['ews_health_findings','ews_health_alerts','ews_health_cron_last'] as $o)update_option($o,[],false);
        update_option('ews_health_full',0,false);
        $this->audit('health_data_deleted','system_health',0,'Collected System health data deleted');
        wp_safe_redirect(add_query_arg(['page'=>'ews31-system-health','tab'=>'settings','health_done'=>'purged'],admin_url('admin.php')));exit;
    }

    /**
     * The next event of one of the plugin's jobs, whatever its arguments (a break's reminder or a push batch is scheduled with them, and
     * wp_next_scheduled() without them finds nothing). The arguments are never read out. @return array{next:int,every:string}
     */
    private function health_cron_info($hook){
        foreach((array)_get_cron_array() as $time=>$hooks){
            if(isset($hooks[$hook]) && is_array($hooks[$hook])){
                $first=reset($hooks[$hook]);
                return ['next'=>(int)$time,'every'=>is_array($first) && !empty($first['schedule'])?(string)$first['schedule']:''];
            }
        }
        return ['next'=>0,'every'=>''];
    }

    /** The findings' input: the last 7 days, the last 3 against the 7 before, queries, new errors, jobs. @return array<string,mixed> */
    private function health_findings_input(){
        global $wpdb;
        $t=$this->health_tables();$day=function($ago){return gmdate('Y-m-d',time()-$ago*86400);};
        $hist=function($r){$h=[];for($i=0;$i<Stats::BUCKETS;$i++)$h[]=(int)$r['b'.$i];return $h;};
        $routes=[];
        $cols=implode(',',$this->health_sum_columns());
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT route,day,version,{$cols},ms_max,mem_pct_max FROM {$t['daily']} WHERE day>=%s ORDER BY day,id",$day(9)),ARRAY_A) as $r){
            $route=(string)$r['route'];$recent=$r['day']>=$day(2);$week=$r['day']>=$day(6);
            $x=$routes[$route]??null;
            if(!$x)$x=['n'=>0,'failed'=>0,'fatal'=>0,'hist'=>Stats::empty(),'ms_max'=>0,'pq_sum'=>0,'pq_n'=>0,'mem_pct'=>0,'max_repeat'=>0,'repeat_caller'=>'','rh'=>Stats::empty(),'rn'=>0,'bh'=>Stats::empty(),'bn'=>0,'rv'=>'','bv'=>''];
            if($week){
                $x['n']+=(int)$r['n'];$x['failed']+=(int)$r['failed'];$x['fatal']+=(int)$r['fatal'];$x['hist']=Stats::merge($x['hist'],$hist($r));
                $x['ms_max']=max($x['ms_max'],(int)$r['ms_max']);$x['pq_sum']+=(int)$r['pq_sum'];$x['pq_n']+=(int)$r['pq_n'];$x['mem_pct']=max($x['mem_pct'],(int)$r['mem_pct_max']);
            }
            if($recent){$x['rh']=Stats::merge($x['rh'],$hist($r));$x['rn']+=(int)$r['n'];$x['rv']=(string)$r['version'];}
            else{$x['bh']=Stats::merge($x['bh'],$hist($r));$x['bn']+=(int)$r['n'];$x['bv']=(string)$r['version'];}
            $routes[$route]=$x;
        }
        foreach($routes as $route=>$x){
            $routes[$route]['recent_n']=$x['rn'];$routes[$route]['before_n']=$x['bn'];
            $routes[$route]['recent_p50']=Stats::percentile($x['rh'],0.5,$x['ms_max']);$routes[$route]['before_p50']=Stats::percentile($x['bh'],0.5,$x['ms_max']);
            $routes[$route]['version']=$x['rv']!==$x['bv']?$x['rv']:'';
        }
        $queries=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT route,caller,MAX(us_max) us_max,MAX(max_repeat) max_repeat FROM {$t['queries']} WHERE day>=%s AND caller<>'' GROUP BY route,caller",$day(6)),ARRAY_A) as $q){
            $ms=(int)round((int)$q['us_max']/1000);
            if(isset($routes[$q['route']]) && (int)$q['max_repeat']>$routes[$q['route']]['max_repeat']){$routes[$q['route']]['max_repeat']=(int)$q['max_repeat'];$routes[$q['route']]['repeat_caller']=(string)$q['caller'];}
            $queries[]=['route'=>(string)$q['route'],'caller'=>(string)$q['caller'],'ms_max'=>$ms];
        }
        $errors=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT file,line,message,SUM(n) n FROM {$t['errors']} GROUP BY fp,file,line,message HAVING MIN(first_at)>=%s ORDER BY SUM(n) DESC LIMIT 20",gmdate('Y-m-d H:i:s',time()-86400)),ARRAY_A) as $e)$errors[]=['file'=>(string)$e['file'],'line'=>(int)$e['line'],'message'=>(string)$e['message'],'n'=>(int)$e['n']];
        $last=get_option('ews_health_cron_last',[]);$last=is_array($last)?$last:[];$cron=[];
        // A job late at two summaries in a row is late; one late only now is usually waiting for the cron run this summary is part of.
        $seen=get_option('ews_health_late',[]);$seen=is_array($seen)?$seen:[];$now_late=[];
        foreach(Routes::CRON_HOOKS as $hook){
            $next=$this->health_cron_info($hook)['next'];
            $late=$next && $next<time()?(int)floor((time()-$next)/60):0;
            if($late>(int)$this->health_settings()['cron_late_min'])$now_late[$hook]=true;
            $cron[$hook]=['overdue_min'=>!empty($seen[$hook])?$late:0,'failed'=>isset($last[$hook]) && empty($last[$hook]['ok'])];
        }
        update_option('ews_health_late',$now_late,false);
        return ['routes'=>$routes,'queries'=>$queries,'errors'=>$errors,'cron'=>$cron];
    }

    /* ------------------------------------------------------------------ wording */

    /** A route as a person reads it (menu title, or the technical name). */
    private function health_route_label($route){
        if(strpos($route,'admin:')===0){
            $slug=substr($route,6);
            $names=$this->admin_nav_tab_labels();
            return $names[$slug]??($slug==='ews31'?__('Home','workforce-one'):$slug);
        }
        if(strpos($route,'app:')===0){$v=substr($route,4);$d=\WorkforceOne\Settings\Navigation::DEFAULTS[$v]['label']??null;return $d?sprintf(/* translators: %s: a page of the employee app */__('App: %s','workforce-one'),__($d,'workforce-one')):__('App: other page','workforce-one');}
        if(strpos($route,'cron:')===0)return sprintf(/* translators: %s: a scheduled job */__('Job: %s','workforce-one'),substr($route,5));
        return $route;
    }

    private function health_seconds($ms){
        $ms=(int)$ms;
        return $ms>=1000?number_format_i18n($ms/1000,$ms>=10000?0:1).' s':number_format_i18n($ms).' ms';
    }

    /** One finding in words: [title, sentence, numbers]. @param array<string,mixed> $f @return array{0:string,1:string,2:list<string>} */
    private function health_finding_text($f,$for_mail=false){
        $a=(array)($f['args']??[]);$r=$this->health_route_label((string)$f['route']);
        switch($f['rule']){
            case 'fatal': return [sprintf(/* translators: 1: page, 2: times */_n('%1$s stopped with a PHP fatal error %2$d time','%1$s stopped with a PHP fatal error %2$d times',(int)$a['n'],'workforce-one'),$r,(int)$a['n']),__('Look at the PHP errors list for the file and line.','workforce-one'),[]];
            case 'cron_overdue': return [sprintf(/* translators: 1: job, 2: duration */__('%1$s has not run for %2$s','workforce-one'),$r,$this->health_duration((int)$a['minutes'])),__('WP-Cron may not be running on this site (for example DISABLE_WP_CRON is set and no server cron calls it).','workforce-one'),[]];
            case 'cron_failed': return [sprintf(/* translators: %s: job */__('%s failed on its last run','workforce-one'),$r),__('It stopped with a PHP error or did not finish.','workforce-one'),[]];
            case 'fail_rate': return [sprintf(/* translators: 1: page, 2: failed, 3: runs */__('%1$s ended with a server error %2$d times in %3$d','workforce-one'),$r,(int)$a['failed'],(int)$a['n']),__('A server error is a PHP fatal error or an HTTP 500.','workforce-one'),[]];
            case 'slow': return [sprintf(/* translators: 1: page, 2: time */__('1 in 20 loads of %1$s takes longer than %2$s','workforce-one'),$r,$this->health_seconds((int)$a['p95'])),sprintf(/* translators: %s: limit */__('The limit for this page is %s.','workforce-one'),$this->health_seconds((int)$a['limit'])),[sprintf(/* translators: %s: time */__('Worst %s','workforce-one'),$this->health_seconds((int)$a['max']))]];
            case 'regression': return [sprintf(/* translators: %s: page */__('%s is slower than before','workforce-one'),$r),$a['version']!==''?sprintf(/* translators: %s: version */__('It started with version %s.','workforce-one'),$a['version']):__('Typical load time went up over the last days.','workforce-one'),[sprintf(/* translators: 1: before, 2: after */__('Typical %1$s → %2$s','workforce-one'),$this->health_seconds((int)$a['before']),$this->health_seconds((int)$a['after']))]];
            case 'repeat': return [sprintf(/* translators: 1: page, 2: times */__('%1$s runs the same query %2$d times in one load','workforce-one'),$r,(int)$a['times']),$a['caller']!==''?sprintf(/* translators: %s: function */__('It comes from %s, most likely inside a loop. One query for all rows would do.','workforce-one'),$a['caller'].'()'):__('Most likely inside a loop. One query for all rows would do.','workforce-one'),[]];
            case 'many_queries': return [sprintf(/* translators: 1: page, 2: queries */__('%1$s makes %2$d database queries per load','workforce-one'),$r,(int)$a['avg']),'',[]];
            case 'slow_query': return [$for_mail?sprintf(/* translators: %s: time */__('One query took %s','workforce-one'),$this->health_seconds((int)$a['ms'])):sprintf(/* translators: 1: time, 2: function */__('One query took %1$s (from %2$s)','workforce-one'),$this->health_seconds((int)$a['ms']),(string)$a['caller'].'()'),sprintf(/* translators: %s: page */__('Seen on %s.','workforce-one'),$r),[]];
            case 'memory': return [sprintf(/* translators: 1: page, 2: percent */__('%1$s used %2$d%% of its memory limit','workforce-one'),$r,(int)$a['pct']),__('The limit is the one that request ran under (wp-admin and scheduled jobs often have a larger one).','workforce-one'),[]];
            case 'new_error': if($for_mail)return [__('New PHP warning in Workforce One code','workforce-one'),'',[]];
                return [sprintf(/* translators: 1: file, 2: line */__('New PHP warning in %1$s:%2$d','workforce-one'),(string)$a['file'],(int)$a['line']),(string)$a['message'],[sprintf(/* translators: %d: times */_n('%d time','%d times',(int)$a['n'],'workforce-one'),(int)$a['n'])]];
            case 'other_cost': return [__('Workforce One adds database queries to other pages of the site','workforce-one'),sprintf(/* translators: %d: queries */__('About %d queries on each page that is not its own.','workforce-one'),(int)$a['avg']),[]];
        }
        return [(string)$f['rule'],'',[]];
    }

    private function health_duration($minutes){
        return $minutes>=120?sprintf(/* translators: %d: hours */__('%d h','workforce-one'),(int)floor($minutes/60)):sprintf(/* translators: %d: minutes */__('%d min','workforce-one'),$minutes);
    }

    /* ------------------------------------------------------------------ alerts: notice, hide, email */

    /** @return array<string,array<string,mixed>> */
    private function health_open_alerts(){ $o=get_option('ews_health_alerts',[]);return is_array($o)?$o:[]; }

    /** @return array<string,int> */
    private function health_hidden(){ $h=get_user_meta(get_current_user_id(),'ews_health_dismissed',true);return is_array($h)?$h:[]; }

    /** admin_notices: one line on Workforce One pages and the Dashboard, for who may see the screen. */
    public function health_notice(){
        if(!$this->health_can_view() || !$this->health_settings()['notice'])return;
        $screen=function_exists('get_current_screen')?get_current_screen():null;
        $id=$screen?(string)$screen->id:'';
        if(strpos($id,'ews31')===false && $id!=='dashboard')return;
        if(strpos($id,'ews31-system-health')!==false)return;
        $v=Alerts::visible($this->health_open_alerts(),$this->health_hidden(),time());
        if(!$v)return;
        $c=Alerts::counts($v);$parts=[];
        if($c['critical'])$parts[]=sprintf(/* translators: %d: count */_n('%d critical','%d critical',$c['critical'],'workforce-one'),$c['critical']);
        if($c['warning'])$parts[]=sprintf(/* translators: %d: count */_n('%d warning','%d warnings',$c['warning'],'workforce-one'),$c['warning']);
        if($c['note'])$parts[]=sprintf(/* translators: %d: count */_n('%d note','%d notes',$c['note'],'workforce-one'),$c['note']);
        $class=$c['critical']?'notice-error':'notice-warning';
        echo '<div class="notice '.esc_attr($class).'" data-health-notice><p><strong>'.esc_html__('System health:','workforce-one').'</strong> '.esc_html(implode(', ',$parts)).'. <a href="'.esc_url(admin_url('admin.php?page=ews31-system-health')).'">'.esc_html__('Look at this first →','workforce-one').'</a> ';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">';
        wp_nonce_field('ews_health_dismiss');
        echo '<input type="hidden" name="action" value="ews_health_dismiss"><button class="button-link" type="submit">'.esc_html__('Hide for 7 days','workforce-one').'</button></form></p></div>';
    }

    public function health_dismiss(){
        if(!$this->health_can_view())wp_die('Access denied');
        check_admin_referer('ews_health_dismiss');
        $keys=array_map(function($a){return $a['key'];},Alerts::visible($this->health_open_alerts(),$this->health_hidden(),time()));
        update_user_meta(get_current_user_id(),'ews_health_dismissed',Alerts::hide($this->health_hidden(),$keys,time()));
        wp_safe_redirect(wp_get_referer()?:admin_url('admin.php?page=ews31-system-health'));exit;
    }

    /** The optional email: new alerts only, names and numbers, at most 5 a day. @param array<string,array<string,mixed>> $open */
    private function health_email(array &$open){
        $s=$this->health_settings();
        if(!$s['email'])return;
        $mail=get_option('ews_health_mail',[]);$mail=is_array($mail)?$mail:[];
        $today=gmdate('Y-m-d');$sent=(($mail['day']??'')===$today)?(int)($mail['n']??0):0;
        $keys=Alerts::due($open,time(),$sent);
        if(!$keys)return;
        $lines=[];
        foreach($keys as $k){
            [$title]=$this->health_finding_text(['rule'=>$open[$k]['rule'],'route'=>$open[$k]['route'],'args'=>$open[$k]['args']],true);
            $lines[]='- '.wp_strip_all_tags($title);
        }
        $to=$s['email_to']!==''?$s['email_to']:get_option('admin_email');
        $body=sprintf(/* translators: %s: site name */__('System health found these on %s:','workforce-one'),wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES))."\n\n".implode("\n",$lines)."\n\n".admin_url('admin.php?page=ews31-system-health');
        if(wp_mail($to,'['.wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES).'] '.__('System health','workforce-one'),$body)){
            $open=Alerts::markEmailed($open,$keys,time());
            update_option('ews_health_mail',['day'=>$today,'n'=>$sent+1],false);
        }
    }

    /** admin-post ews_health_settings_save: thresholds and alerts (the switch, sample rate and retention are on Features). */
    public function health_settings_save(){
        if(!$this->health_can_change())wp_die('Access denied');
        check_admin_referer('ews_health_settings_save');
        update_option('ews_health_settings',HealthSettings::fromPost(wp_unslash($_POST)),false);   // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- every value is clamped in Settings::fromPost
        $this->audit('health_settings','system_health',0,'System health thresholds saved');
        wp_safe_redirect(add_query_arg(['page'=>'ews31-system-health','tab'=>'settings','health_done'=>'saved'],admin_url('admin.php')));exit;
    }

    /* ------------------------------------------------------------------ the screen */

    /** One route's figures, per route, from the hourly rows (24 hours) or the daily rows. @return array<string,array<string,mixed>> */
    private function health_route_stats($days,$only=''){
        global $wpdb;
        $t=$this->health_tables();
        $cols=implode(',',array_map(function($c){return "SUM({$c}) {$c}";},$this->health_sum_columns()));
        if($days<=1){$from=gmdate('Y-m-d H:i:s',time()-86400);$sql="SELECT route,{$cols},MAX(ms_max) ms_max,MAX(q_max) q_max,MAX(mem_max) mem_max FROM {$t['hourly']} WHERE hour>=%s";}
        else{$from=gmdate('Y-m-d',time()-($days-1)*86400);$sql="SELECT route,{$cols},MAX(ms_max) ms_max,MAX(q_max) q_max,MAX(mem_max) mem_max FROM {$t['daily']} WHERE day>=%s";}
        $args=[$from];
        if($only!==''){$sql.=' AND route=%s';$args[]=$only;}
        $out=[];
        foreach((array)$wpdb->get_results($wpdb->prepare($sql.' GROUP BY route',$args),ARRAY_A) as $r){
            $n=max(1,(int)$r['n']);$h=[];for($i=0;$i<Stats::BUCKETS;$i++)$h[]=(int)$r['b'.$i];
            $out[(string)$r['route']]=['route'=>(string)$r['route'],'n'=>(int)$r['n'],'failed'=>(int)$r['failed'],'fatal'=>(int)$r['fatal'],'err'=>(int)$r['err'],'ms_max'=>(int)$r['ms_max'],'cost'=>(int)$r['ms_sum'],
                'p50'=>Stats::percentile($h,0.5,(int)$r['ms_max']),'p95'=>Stats::percentile($h,0.95,(int)$r['ms_max']),'q_avg'=>(int)round((int)$r['q_sum']/$n),
                'pq_avg'=>(int)$r['pq_n']>0?(int)round((int)$r['pq_sum']/(int)$r['pq_n']):null,'mem_max'=>(int)$r['mem_max']];
        }
        return $out;
    }

    /** The last days of one route (or all): day => [p50, p95, queries per request, version of its last row, runs]; a day split by a release is merged. @return array<string,array<string,mixed>> */
    private function health_route_days($route,$days=14){
        global $wpdb;
        $t=$this->health_tables();$cols=implode(',',array_map(function($i){return 'b'.$i;},range(0,Stats::BUCKETS-1)));
        $acc=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT day,version,n,q_sum,ms_max,{$cols} FROM {$t['daily']} WHERE route=%s AND day>=%s ORDER BY day,id",$route,gmdate('Y-m-d',time()-($days-1)*86400)),ARRAY_A) as $r){
            $h=[];for($i=0;$i<Stats::BUCKETS;$i++)$h[]=(int)$r['b'.$i];
            $d=(string)$r['day'];
            $a=&$acc[$d];
            if(!$a)$a=['h'=>Stats::empty(),'n'=>0,'q'=>0,'max'=>0,'version'=>''];
            $a['h']=Stats::merge($a['h'],$h);$a['n']+=(int)$r['n'];$a['q']+=(int)$r['q_sum'];$a['max']=max($a['max'],(int)$r['ms_max']);$a['version']=(string)$r['version'];
            unset($a);
        }
        $out=[];
        foreach($acc as $d=>$a)$out[$d]=['p50'=>Stats::percentile($a['h'],0.5,$a['max']),'p95'=>Stats::percentile($a['h'],0.95,$a['max']),'q'=>$a['n']>0?(int)round($a['q']/$a['n']):0,'version'=>$a['version'],'n'=>$a['n']];
        return $out;
    }

    /** A small line over values (inline SVG), the last point marked; $marks are indexes where the version changed. @param list<int|float> $vals @param list<int> $marks */
    private function health_spark(array $vals,array $marks=[]){
        if(count($vals)<2)return '<span style="color:#646970">—</span>';
        $w=96;$h=26;$mx=max($vals);$mn=min($vals);$n=count($vals);$pts=[];
        foreach($vals as $i=>$v)$pts[]=[round($i*($w-6)/($n-1)+3,1),round($h-4-($v-$mn)/(($mx-$mn)?:1)*($h-10),1)];
        $line='M'.implode(' L',array_map(function($p){return $p[0].' '.$p[1];},$pts));
        $svg='<svg viewBox="0 0 '.$w.' '.$h.'" width="'.$w.'" height="'.$h.'" role="img" aria-label="'.esc_attr__('Typical time over the last days','workforce-one').'">';
        foreach($marks as $i)if(isset($pts[$i]))$svg.='<line x1="'.$pts[$i][0].'" x2="'.$pts[$i][0].'" y1="0" y2="'.$h.'" stroke="#8c8f94" stroke-dasharray="2 2"/>';
        $last=end($pts);
        return $svg.'<path d="'.esc_attr($line).'" fill="none" stroke="#3858e9" stroke-width="1.6" stroke-linejoin="round"/><circle cx="'.$last[0].'" cy="'.$last[1].'" r="2.4" fill="#3858e9"/></svg>';
    }

    /** A bigger chart with one or two series over days. @param array<string,array<int|float>> $series name => values @param list<string> $labels @param list<int> $marks */
    private function health_chart(array $series,array $labels,array $marks,$unit){
        $w=420;$h=170;$l=44;$r=10;$tp=14;$b=26;
        $all=[];foreach($series as $v)$all=array_merge($all,$v);
        if(count($labels)<2 || !$all)return '<p class="description">'.esc_html__('Not enough days yet.','workforce-one').'</p>';
        $mx=max($all);$mx=$mx>0?$mx*1.1:1;$n=count($labels);
        $x=function($i)use($w,$l,$r,$n){return $l+$i*($w-$l-$r)/($n-1);};
        $y=function($v)use($h,$tp,$b,$mx){return $tp+($h-$tp-$b)*(1-$v/$mx);};
        $svg='<svg viewBox="0 0 '.$w.' '.$h.'" width="100%" role="img" aria-label="'.esc_attr(implode(', ',array_keys($series))).'" style="max-width:520px">';
        for($i=0;$i<=3;$i++){$v=$mx*$i/3;$yy=round($y($v),1);$svg.='<line x1="'.$l.'" x2="'.($w-$r).'" y1="'.$yy.'" y2="'.$yy.'" stroke="#dcdcde"/><text x="'.($l-4).'" y="'.($yy+3).'" text-anchor="end" font-size="10" fill="#646970">'.esc_html($unit==='ms'?$this->health_seconds((int)$v):(string)(int)round($v)).'</text>';}
        foreach($marks as $i)$svg.='<line x1="'.round($x($i),1).'" x2="'.round($x($i),1).'" y1="'.$tp.'" y2="'.($h-$b).'" stroke="#8c8f94" stroke-dasharray="3 3"/>';
        $colors=['#3858e9','#b32d2e'];$k=0;
        foreach($series as $name=>$vals){
            $d='M'.implode(' L',array_map(function($v,$i)use($x,$y){return round($x($i),1).' '.round($y($v),1);},$vals,array_keys($vals)));
            $svg.='<path d="'.esc_attr($d).'" fill="none" stroke="'.$colors[$k%2].'" stroke-width="2" stroke-linejoin="round"'.($k?' stroke-dasharray="5 3"':'').'/><text x="'.($w-$r).'" y="'.round($y(end($vals))-6,1).'" text-anchor="end" font-size="10" fill="#1d2327">'.esc_html($name).'</text>';
            $k++;
        }
        foreach([0,(int)floor(($n-1)/2),$n-1] as $i)$svg.='<text x="'.round($x($i),1).'" y="'.($h-8).'" text-anchor="middle" font-size="10" fill="#646970">'.esc_html($labels[$i]).'</text>';
        return $svg.'</svg>';
    }

    /** wp-admin → System health. */
    public function admin_system_health(){
        if(!$this->health_can_view())wp_die('Access denied');
        global $wpdb;
        $on=$this->health_enabled();
        $t=$this->health_tables();
        if($on && (string)get_option('ews_health_schema','')!==HealthSettings::SCHEMA)$this->ensure_health_schema();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display filters only
        $tab=sanitize_key($_GET['tab']??'overview');
        if(!in_array($tab,['overview','route','jobs','queries','errors','settings'],true))$tab='overview';
        if($tab==='route' && !isset($_GET['route']))$tab='overview';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $days=in_array((int)($_GET['days']??7),[1,7,30,90],true)?(int)($_GET['days']??7):7;
        $areas=['','wp-admin','App','Actions','API','Jobs','Other'];
        $area=isset($_GET['area']) && in_array((string)wp_unslash($_GET['area']),$areas,true)?(string)wp_unslash($_GET['area']):'';
        $sort=sanitize_key($_GET['sort']??'cost');
        $route=isset($_GET['route'])?preg_replace('/[^A-Za-z0-9_:\-\/{} .]/','',(string)wp_unslash($_GET['route'])):'';
        $done=sanitize_key($_GET['health_done']??'');
        // phpcs:enable
        $vars=['on'=>$on,'tab'=>$tab,'days'=>$days,'area'=>$area,'areas'=>$areas,'sort'=>$sort,'route'=>$route,'base'=>admin_url('admin.php?page=ews31-system-health'),'features_url'=>admin_url('admin.php?page=ews31-features#ews-system-health'),
            'can_change'=>$this->health_can_change(),'post_url'=>admin_url('admin-post.php'),'settings'=>$this->health_settings(),'done'=>$done,'findings'=>[],'rows'=>[],'facts'=>[],'jobs'=>[],'queries'=>[],'errors'=>[],'detail'=>null,
            'rate'=>HealthSettings::rate($this->option('ews_health_sample_rate')),'retention'=>HealthSettings::retention($this->option('ews_health_retention_days')),'full'=>(bool)$this->option('ews_health_full'),'multisite'=>is_multisite(),
            'seconds'=>function($ms){return $this->health_seconds($ms);},'label'=>function($r){return $this->health_route_label($r);},'spark'=>function($v,$m=[]){return $this->health_spark($v,$m);}];
        if(!$on){echo $this->render_template('admin/system-health',$vars);return;}
        $rows=0;foreach($t as $table)$rows+=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $first=(string)$wpdb->get_var("SELECT MIN(day) FROM {$t['daily']}")?:(string)$wpdb->get_var("SELECT MIN(hour) FROM {$t['hourly']}");
        $vars['facts']=['since'=>$first!==''?date_i18n(get_option('date_format'),strtotime($first.' UTC')):'—','rows'=>$rows,'rollup'=>(int)get_option('ews_health_rollup_at',0),
            'detail_off'=>defined('SAVEQUERIES') && !SAVEQUERIES];
        if($tab==='overview'){
            foreach(array_slice((array)get_option('ews_health_findings',[]),0,7) as $f){
                [$title,$sentence,$nums]=$this->health_finding_text($f);
                $vars['findings'][]=['severity'=>$f['severity'],'title'=>$title,'sentence'=>$sentence,'nums'=>$nums,'route'=>$f['route'],'rule'=>$f['rule']];
            }
            $stats=$this->health_route_stats($days);
            foreach($stats as $k=>$r){if($area!=='' && Routes::area($k)!==$area)unset($stats[$k]);}
            $order=['cost'=>function($a,$b){return $b['cost']<=>$a['cost'];},'runs'=>function($a,$b){return $b['n']<=>$a['n'];},'slow'=>function($a,$b){return $b['p95']<=>$a['p95'];},'name'=>function($a,$b){return strcmp($a['route'],$b['route']);}];
            uasort($stats,$order[$sort]??$order['cost']);
            $acc=[];   // route => day => [histogram, max, version]: a day split by a release is one point
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT route,day,version,ms_max,".implode(',',array_map(function($i){return 'b'.$i;},range(0,Stats::BUCKETS-1)))." FROM {$t['daily']} WHERE day>=%s ORDER BY day,id",gmdate('Y-m-d',time()-13*86400)),ARRAY_A) as $d){
                $h=[];for($i=0;$i<Stats::BUCKETS;$i++)$h[]=(int)$d['b'.$i];
                $a=&$acc[$d['route']][$d['day']];
                $a=['h'=>Stats::merge($a['h']??Stats::empty(),$h),'max'=>max((int)($a['max']??0),(int)$d['ms_max']),'version'=>(string)$d['version']];
                unset($a);
            }
            $spark=[];
            foreach($acc as $k=>$days_)foreach($days_ as $a)$spark[$k][]=['p50'=>Stats::percentile($a['h'],0.5,$a['max']),'version'=>$a['version']];
            foreach(array_slice($stats,0,80,true) as $k=>$r){
                $series=$spark[$k]??[];$marks=[];
                $prev=null;foreach($series as $i=>$p){if($prev!==null && $p['version']!==$prev)$marks[]=$i;$prev=$p['version'];}
                $vars['rows'][]=$r+['spark'=>array_column($series,'p50'),'marks'=>$marks,'area'=>Routes::area($k)];
            }
        }elseif($tab==='route' && $route!==''){
            $d=$this->health_route_days($route,30);
            $marks=[];$prev=null;$i=0;
            foreach($d as $day=>$x){if($prev!==null && $x['version']!==$prev)$marks[]=$i;$prev=$x['version'];$i++;}
            $stat=$this->health_route_stats($days,$route)[$route]??null;
            $vars['detail']=['stat'=>$stat,'chart_time'=>$this->health_chart(['typical'=>array_column($d,'p50'),'slow (1 in 20)'=>array_column($d,'p95')],array_map(function($k){return date_i18n('j M',strtotime($k));},array_keys($d)),$marks,'ms'),
                'chart_q'=>$this->health_chart([__('queries per load','workforce-one')=>array_column($d,'q')],array_map(function($k){return date_i18n('j M',strtotime($k));},array_keys($d)),$marks,'n')];
            $vars['queries']=(array)$wpdb->get_results($wpdb->prepare("SELECT caller,shape,SUM(n) n,SUM(requests) requests,SUM(us_sum) us_sum,MAX(us_max) us_max,MAX(max_repeat) max_repeat FROM {$t['queries']} WHERE route=%s AND day>=%s GROUP BY caller,shape ORDER BY SUM(us_sum) DESC LIMIT 30",$route,gmdate('Y-m-d',time()-29*86400)),ARRAY_A);
            $vars['errors']=(array)$wpdb->get_results($wpdb->prepare("SELECT level,file,line,message,SUM(n) n,MAX(last_at) last_at FROM {$t['errors']} WHERE route=%s AND day>=%s GROUP BY level,file,line,message ORDER BY SUM(n) DESC LIMIT 30",$route,gmdate('Y-m-d',time()-29*86400)),ARRAY_A);
        }elseif($tab==='queries'){
            $vars['queries']=(array)$wpdb->get_results($wpdb->prepare("SELECT route,caller,shape,SUM(n) n,SUM(requests) requests,SUM(us_sum) us_sum,MAX(us_max) us_max,MAX(max_repeat) max_repeat FROM {$t['queries']} WHERE day>=%s GROUP BY route,caller,shape ORDER BY SUM(us_sum) DESC LIMIT 50",gmdate('Y-m-d',time()-29*86400)),ARRAY_A);
        }elseif($tab==='errors'){
            $vars['errors']=(array)$wpdb->get_results($wpdb->prepare("SELECT level,file,line,message,SUM(n) n,MIN(first_at) first_at,MAX(last_at) last_at FROM {$t['errors']} WHERE day>=%s GROUP BY level,file,line,message ORDER BY MAX(last_at) DESC LIMIT 100",gmdate('Y-m-d',time()-29*86400)),ARRAY_A);
        }elseif($tab==='jobs'){
            $last=get_option('ews_health_cron_last',[]);$last=is_array($last)?$last:[];
            foreach(Routes::CRON_HOOKS as $hook){
                $r='cron:'.$hook;$info=$this->health_cron_info($hook);$next=$info['next'];
                $day=$wpdb->get_row($wpdb->prepare("SELECT SUM(n) n,SUM(failed) failed FROM {$t['hourly']} WHERE route=%s AND hour>=%s",$r,gmdate('Y-m-d H:i:s',time()-86400)),ARRAY_A);
                $week=$wpdb->get_var($wpdb->prepare("SELECT SUM(failed) FROM {$t['daily']} WHERE route=%s AND day>=%s",$r,gmdate('Y-m-d',time()-6*86400)));
                $st=$this->health_route_stats(7,$r)[$r]??null;
                $overdue=$next && $next<time()?(int)floor((time()-$next)/60):0;
                $status=!$next?(in_array($hook,Routes::QUEUE_HOOKS,true)?'idle':'none'):(!empty($last[$hook]) && empty($last[$hook]['ok'])?'failed':($overdue>(int)$vars['settings']['cron_late_min']?'overdue':'ok'));
                $vars['jobs'][]=['hook'=>$hook,'every'=>$info['every'],'last'=>$last[$hook]??null,'runs'=>(int)($day['n']??0),'failed'=>(int)$week,'p95'=>$st['p95']??null,'next'=>$next?:0,'status'=>$status,'overdue'=>$overdue];
            }
            $vars['wp_cron_off']=defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        }
        echo $this->render_template('admin/system-health',$vars);
    }
}
