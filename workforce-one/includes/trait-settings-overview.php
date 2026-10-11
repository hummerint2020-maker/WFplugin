<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Settings\FeatureSettings;
use WorkforceOne\Settings\Navigation;
use WorkforceOne\Settings\Options;
use WorkforceOne\Settings\SmartNudges;

/**
 * wp-admin → Settings Overview: every setting in src/Settings/Options.php with its current value,
 * its default and whether it was changed, and a JSON export for support. Read-only: settings are
 * still edited on their own pages. Secrets and employee data are never shown or exported.
 * Output: templates/admin/settings-overview.php.
 */
trait EWS_Settings_Overview_Trait {

    public function admin_settings_overview(){
            if(!current_user_can('manage_options')) wp_die('Access denied');
            $rows=$this->settings_overview_rows();
            $only_changed=!empty($_GET['changed']);
            echo $this->render_template('admin/settings-overview',[
                'rows'=>$only_changed?array_values(array_filter($rows,function($r){return $r['changed'];})):$rows,
                'groups'=>Options::GROUPS,
                'changed_count'=>count(array_filter($rows,function($r){return $r['changed'];})),
                'total'=>count($rows),
                'only_changed'=>$only_changed,
                'unregistered'=>$this->settings_overview_unregistered(),
                'page_url'=>admin_url('admin.php?page=ews31-settings-overview'),
            ]);
        }

    public function settings_overview_export(){
            if(!current_user_can('manage_options')) wp_die('Access denied',403);
            check_admin_referer('ews_settings_export');
            global $wp_version;
            $settings=[];
            foreach($this->settings_overview_rows() as $r){
                $settings[]=['name'=>$r['name'],'group'=>$r['group'],'label'=>$r['label'],'stored'=>$r['stored'],
                    'value'=>$r['name']===Options::KIOSK_KEYS?['count'=>$r['count']]:Options::exportValue($r['name'],$r['stored']?$r['raw']:$r['default']),
                    'default'=>$r['kind']==='grouped'?$r['builtin']:Options::exportValue($r['name'],$r['default']),
                    'changed'=>$r['changed']];
            }
            $data=['plugin'=>'Workforce One','version'=>defined('EWS_VERSION')?EWS_VERSION:'','site'=>home_url('/'),
                'wordpress'=>(string)$wp_version,'php'=>PHP_VERSION,'exported_at'=>current_time('mysql'),
                'settings'=>$settings,'unregistered'=>$this->settings_overview_unregistered()];
            $this->audit('settings_exported','settings',0,count($settings).' settings');
            nocache_headers();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="workforce-one-settings-'.sanitize_file_name((string)wp_parse_url(home_url('/'),PHP_URL_HOST)).'-'.current_time('Y-m-d').'.json"');
            echo wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            exit;
        }

    /**
     * One row per setting: label, current and default text, changed, and the raw values (for the
     * export). A setting that is not stored shows its default.
     * @return array<int,array<string,mixed>>
     */
    private function settings_overview_rows(){
            $missing=new stdClass();
            $rows=[];
            foreach(Options::all() as $name=>$d){
                if($name===Options::KIOSK_KEYS){
                    $count=$this->settings_overview_kiosk_keys();
                    $rows[]=$d+['name'=>$name,'raw'=>null,'stored'=>$count>0,'count'=>$count,'changed'=>false,'status'=>'secret',
                        'current'=>sprintf('%d kiosk %s',$count,$count===1?'secret':'secrets'),'default_text'=>'—'];
                    continue;
                }
                $raw=get_option($name,$missing);
                $stored=$raw!==$missing;
                $default=$name==='ews_vapid_subject'?'mailto:'.get_option('admin_email','admin@example.com'):$d['default'];
                $value=$stored?$raw:$default;
                if($d['internal']){
                    $changed=false;
                    $current=Options::display($name,$value);
                    $default_text='—';
                }elseif($d['kind']==='grouped'){
                    [$changed,$summary]=$this->settings_overview_grouped($name,$stored?$raw:null);
                    $current=($changed?'Customised':'Default').($summary!==''?' — '.$summary:'');
                    $default_text=(string)$d['builtin'];
                }else{
                    $changed=$stored&&Options::isChanged($name,$raw,$default);
                    $current=Options::display($name,$value);
                    $default_text=in_array($d['kind'],['secret','data'],true)?'—':Options::display($name,$default);
                }
                $status=$d['kind']==='secret'?'secret':($d['internal']?($stored?'internal':'unset'):($d['legacy']?'legacy':($changed?'changed':($stored?'default':'unset'))));
                $rows[]=$d+['name'=>$name,'raw'=>$raw===$missing?null:$raw,'stored'=>$stored,'default'=>$default,
                    'changed'=>$changed,'status'=>$status,'current'=>$current,'default_text'=>$default_text];
            }
            return $rows;
        }

    /**
     * Whether a grouped setting differs from its built-in defaults, and a short description.
     * @param mixed $raw the stored value (null when not stored)
     * @return array{0:bool,1:string}
     */
    private function settings_overview_grouped($name,$raw){
            switch($name){
                case 'ews_shifts':
                    $list=is_array($raw)&&$raw?$raw:$this->default_shifts();
                    $names=array_map(function($s){return is_array($s)?(string)($s['name']??'').' '.($s['start']??'').'–'.($s['end']??''):'';},$list);
                    return [is_array($raw)&&$raw&&$raw!=$this->default_shifts(),count($list).' '.(count($list)===1?'shift':'shifts').': '.implode(', ',array_filter($names))];
                case 'ews_schedule_types_config':
                    $list=is_array($raw)&&$raw?$raw:$this->default_schedule_types_config();
                    $active=array_filter($list,function($t){return is_array($t)&&!empty($t['active']);});
                    return [is_array($raw)&&$raw&&$raw!=$this->default_schedule_types_config(),implode(', ',array_map(function($t){return (string)($t['name']??'');},$active))];
                case 'ews_working_hours':
                    $h=array_replace($this->default_working_hours(),is_array($raw)?$raw:[]);
                    return [Options::differsFrom($raw,$this->default_working_hours()),$h['start'].'–'.$h['end']];
                case 'ews_confirmation_actions':
                    $state=FeatureSettings::confirmState($raw);
                    $off=count(array_filter($state,function($v){return !$v;}));
                    return [$off>0,$off?$off.' of '.count($state).' actions do not ask':''];
                case 'ews_face_signin_settings':
                    return [Options::differsFrom($raw,FeatureSettings::FACE_DEFAULTS),''];
                case 'ews_pwa_splash_settings':
                    return [Options::differsFrom($raw,FeatureSettings::SPLASH_DEFAULTS),''];
                case 'ews_frontend_navigation':
                    $nav=Navigation::config($raw);
                    return [$nav!=Navigation::config([]),''];
                case 'ews_appearance':
                    $a=\WorkforceOne\Settings\Appearance::config($raw);
                    return [is_array($raw)&&$a!=\WorkforceOne\Settings\Appearance::config([]),(\WorkforceOne\Settings\Appearance::PRESETS[$a['preset']]['label']??'Custom colours').', '.\WorkforceOne\Settings\Appearance::FONTS[$a['font']]['label']];
                case 'ews_employee_profile_settings':
                    return [Options::differsFrom($raw,$this->employee_profile_defaults()),''];
                case 'ews_smart_nudges':
                    return [SmartNudges::config($raw)!=SmartNudges::config([]),''];
                case 'ews_notification_policy':
                    return [$this->notification_policy()!=$this->notification_policy_defaults(),''];
                case 'ews_payroll_late_tiers':
                    $n=is_array($raw)?count($raw):0;
                    return [$n>0,$n?implode(', ',array_map(function($t){return '> '.(int)($t['after']??0).' min = '.(float)($t['days']??0).' day';},$raw)):''];
                case 'ews_role_permissions':
                    $n=is_array($raw)?count($raw):0;
                    return [$n>0,$n?$n.' '.($n===1?'role':'roles').' set':''];
                default:
                    return [false,is_array($raw)?count($raw).' '.(count($raw)===1?'entry':'entries'):''];
            }
        }

    private function settings_overview_kiosk_keys(){
            global $wpdb;
            return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(Options::KIOSK_KEY_PREFIX).'%'));
        }

    /** Stored ews_* options this version does not know (left by an old version or another plugin). @return string[] */
    private function settings_overview_unregistered(){
            global $wpdb;
            $names=(array)$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name",$wpdb->esc_like('ews_').'%',$wpdb->esc_like(Options::KIOSK_KEY_PREFIX).'%'));
            return array_values(array_filter($names,function($n){return !Options::has((string)$n);}));
        }
}
