<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Pwa\Manifest;
use WorkforceOne\Settings\OfflineScreen;

/**
 * The installed app's offline screen (3.31.93, off by default; Features → Installed App).
 *
 * The page (?ews_pwa=offline) holds no employee data and is the same for everyone, so the service
 * worker keeps a copy and shows it when an app page cannot be loaded: no internet, the server down
 * (502/503/504, or any 5xx when opening a page) or no answer within the set time. On the phone, the
 * page reads two things the app saved there earlier: the employee's schedule for the coming week
 * (ewsOfflineSchedule, written by the app's footer, removed on logout) and a Sign In / Out that did not
 * reach the server (ewsPendingAttempt, 3.31.92). It checks ?ews_pwa=ping every few seconds and opens the
 * app again as soon as it answers; the app then says the attempt was sent.
 * Settings: src/Settings/OfflineScreen.php. Page: templates/pwa/offline.php. Behaviour: tests/e2e_offline_screen.py.
 */
trait EWS_Offline_Screen_Trait {

    /** @return array{enabled:int,timeout:int,schedule:int,keep_note:int,texts:array<string,string>,saved:array<string,string>} */
    private function offline_settings(){
        return OfflineScreen::config($this->option('ews_offline_screen'));
    }

    private function offline_enabled(){
        return (bool)$this->offline_settings()['enabled'];
    }

    /** What the page shows besides the texts: the app's name and colour (as on its icon) and where to go back to. */
    private function offline_page_vars(){
        $c=$this->offline_settings();
        return [
            'texts'=>$c['texts'],'keep_note'=>$c['keep_note'],'schedule'=>$c['schedule'],
            'app_name'=>Manifest::NAME,'color'=>Manifest::THEME_COLOR,
            'app'=>Manifest::startUrl((string)$this->app_home_url()),
            'ping'=>add_query_arg('ews_pwa','ping',home_url('/')),
            'icon'=>$this->pwa_plugin_root().'assets/icons/workforce-one-192.png',
        ];
    }

    /** The page's URL as the service worker keeps it: its revision changes with anything shown on it. */
    private function offline_page_url(){
        if(!$this->offline_enabled())return '';
        return add_query_arg(['ews_pwa'=>'offline','rev'=>OfflineScreen::revision($this->offline_page_vars())],home_url('/'));
    }

    /** ?ews_pwa=offline (the page) and ?ews_pwa=ping (is the server answering?). */
    public function pwa_offline_route(){
        $what=isset($_GET['ews_pwa'])?sanitize_key(wp_unslash($_GET['ews_pwa'])):''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public routes, no data changed
        if($what==='ping'){
            nocache_headers();
            status_header(204);
            exit;
        }
        if($what!=='offline')return;
        // wp-admin's preview (Features), also while the screen is off: sample data, the state asked for.
        $preview=sanitize_key(wp_unslash($_GET['preview']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview
        if($preview!==''){
            if(!$this->can('ews_manage_settings')||!in_array($preview,['off','press','down'],true))wp_die(esc_html__('Access denied','workforce-one'),'',['response'=>403]);
            nocache_headers();
            header('X-Robots-Tag: noindex');
            echo $this->render_template('pwa/offline',['preview'=>$preview]+$this->offline_page_vars()); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            exit;
        }
        if(!$this->offline_enabled()){
            status_header(404);
            nocache_headers();
            exit;
        }
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->render_template('pwa/offline',['preview'=>'']+$this->offline_page_vars()); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
        exit;
    }

    /**
     * What the app's footer needs: whether the screen is on, the texts of the "back online" message and
     * the signed-in employee's schedule from today for 7 days (kept on the phone for the offline page).
     * @return array<string,mixed> empty when the screen is off
     */
    private function offline_app_config(){
        $c=$this->offline_settings();
        if(!$c['enabled'])return [];
        $user=get_current_user_id();
        $out=['user'=>$user,'toast'=>$c['texts']['toast'],'toast0'=>$c['texts']['toast0'],'days'=>[]];
        $emp=$user?$this->current_employee():null;
        if($c['schedule'] && $emp)$out['days']=$this->offline_schedule_days((int)$emp->id,$c['texts']);
        return $out;
    }

    /**
     * The employee's schedule from today for 7 days: date (Y-m-d, matched with the phone's date), day,
     * status, branch (when there is more than one) and hours (for statuses that need a Sign In).
     * @param array<string,string> $texts the screen's texts (a day off, a day not planned yet)
     * @return array<int,array{date:string,day:string,label:string,status:string,place:string,hours:string}>
     */
    private function offline_schedule_days($eid,array $texts){
        global $wpdb;
        $start=$this->attendance_day($eid);
        $end=gmdate('Y-m-d',(int)strtotime($start.' +6 days'));
        $rows=[];
        foreach((array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->schedule} WHERE employee_id=%d AND work_date BETWEEN %s AND %s",$eid,$start,$end)) as $r)$rows[(string)$r->work_date]=$r;
        $locs=$this->branch_locations();
        $h=$this->working_hours($eid);
        $out=[];
        for($i=0;$i<7;$i++){
            $date=gmdate('Y-m-d',(int)strtotime($start.' +'.$i.' days'));
            $t=(int)strtotime($date);
            $r=$rows[$date]??null;
            $status=$r?(string)$r->status:($this->is_working_day($date)?$texts['not_set']:$texts['day_off']);
            $place='';
            if($r && count($locs)>1){
                $lid=(int)($r->location_id??0);
                if(!$lid && $this->schedule_type_requires_location($status))$lid=$this->branch_main_id($eid);
                if($lid && isset($locs[$lid]))$place=(string)$locs[$lid]->name;
            }
            $hours=$r && $this->schedule_type_requires_sign_in($status)?$h['start'].' – '.$h['end']:'';
            $out[]=['date'=>$date,'day'=>gmdate('D',$t),'label'=>gmdate('D j M',$t),'status'=>$status,'place'=>$place,'hours'=>$hours];
        }
        return $out;
    }
}
