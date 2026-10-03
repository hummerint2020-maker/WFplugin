<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Achievements\ManualGrant;

/**
 * wp-admin "Achievements": feature switch, progress overview, manual grants, and removing an award
 * (from the employee profile). Rules: src/Achievements/ManualGrant.php.
 * Behaviour: tests/e2e_achievements.py.
 */
trait EWS_Achievements_Admin_Trait {

    private function achievements_admin_redirect($args){
        wp_safe_redirect(add_query_arg($args,admin_url('admin.php?page=ews31-achievements')));
        exit;
    }

    /** One progress column for the template, or null when the filter hides it. */
    private function achievements_admin_cell($p,$category,$defs,$show){
        if(!$show)return null;
        if(empty($p['achievement']))return ['achievement'=>null];
        $progress=ManualGrant::progress((int)$p['current'],(int)$p['target']);
        $earned=[];
        foreach($defs as $d)if($d->category===$category && isset($p['earned'][(int)$d->id]))$earned[]=$d->icon.' '.$d->name;
        return ['achievement'=>$p['achievement']->icon.' '.$p['achievement']->name,'current'=>(int)$p['current'],'target'=>(int)$p['target'],'remaining'=>(int)$p['remaining'],'pct'=>$progress['pct'],'near'=>$progress['near'],'earned'=>$earned];
    }

    public function admin_achievements(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        global $wpdb;
        $this->ensure_achievements_schema();
        $enabled=$this->achievements_enabled();
        $search=sanitize_text_field(wp_unslash($_GET['achievement_search']??''));
        $type=sanitize_key($_GET['achievement_type']??'all');
        if(!in_array($type,['all','attendance','swap'],true))$type='all';
        $sql="SELECT id,name,domain_name FROM {$this->employees} WHERE active=1";
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$sql=$wpdb->prepare($sql." AND (name LIKE %s OR domain_name LIKE %s)",$like,$like);}
        $employees=(array)$wpdb->get_results($sql." ORDER BY name ASC");
        $defs=(array)$wpdb->get_results("SELECT * FROM {$wpdb->prefix}ews_achievements WHERE active=1 ORDER BY sort_order ASC,id ASC");
        $rows=[];$stats=['tracked'=>0,'near'=>0,'earned'=>0];
        foreach($enabled?$this->achievement_admin_progress_data($employees,$defs):[] as $p){
            $stats['tracked']++;
            $stats['earned']+=count($p['attendance']['earned']??[])+count($p['swap']['earned']??[]);
            $att=$this->achievements_admin_cell($p['attendance'],'attendance',$defs,true);
            $swap=$this->achievements_admin_cell($p['swap'],'collaboration',$defs,true);
            foreach([$att,$swap] as $c)if(!empty($c['near']))$stats['near']++;
            $e=$p['employee'];$initials='';
            foreach(preg_split('/\s+/',trim((string)$e->name)) as $part)if($part!=='')$initials.=mb_strtoupper(mb_substr($part,0,1));
            $rows[]=['name'=>$e->name,'domain'=>$e->domain_name?:'Employee','initials'=>mb_substr($initials,0,2)?:'?',
                'attendance'=>$type==='swap'?null:$att,'swap'=>$type==='attendance'?null:$swap];
        }
        $error=sanitize_key($_GET['achievement_error']??'');
        echo $this->render_template('admin/achievements',[
            'enabled'=>$enabled,'search'=>$search,'progress_type'=>$type,'stats'=>$stats,'rows'=>$rows,'employees'=>$employees,
            // Manual grants are stored as definitions too; they are not automatic achievements.
            'automatic'=>array_values(array_filter($defs,function($d){return $d->rule_type!=='manual';})),
            'icons'=>ManualGrant::ICONS,'styles'=>ManualGrant::STYLES,
            'notice'=>ManualGrant::noticeMessage(sanitize_key($_GET['achievement_notice']??'')),
            'error'=>$error!==''?ManualGrant::errorMessage($error):null,
            'page_url'=>admin_url('admin.php?page=ews31-achievements'),'post_url'=>admin_url('admin-post.php'),
        ]);
    }

    public function achievements_settings_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_achievements_settings_save');
        $enabled=!empty($_POST['achievements_enabled']);
        update_option('ews_feature_achievements',$enabled,false);
        $this->audit('achievement_feature_update','settings',0,$enabled?'enabled':'disabled');
        $this->achievements_admin_redirect(['achievement_notice'=>'saved']);
    }

    public function achievement_manual_grant(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_achievement_manual_grant');
        global $wpdb;
        $employee_id=absint($_POST['employee_id']??0);
        [$v,$error]=ManualGrant::check([
            'name'=>sanitize_text_field(wp_unslash($_POST['achievement_name']??'')),
            'description'=>sanitize_textarea_field(wp_unslash($_POST['achievement_description']??'')),
            'icon'=>(string)wp_unslash($_POST['achievement_icon']??''),
            'style'=>sanitize_key($_POST['badge_style']??''),
            'employee_found'=>$employee_id&&(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1",$employee_id)),
            'enabled'=>$this->achievements_enabled(),
        ]);
        if($error)$this->achievements_admin_redirect(['achievement_error'=>$error]);
        if(!$this->create_manual_achievement($employee_id,$v['name'],$v['description'],$v['icon'],$v['style']))$this->achievements_admin_redirect(['achievement_error'=>'save']);
        $this->achievements_admin_redirect(['achievement_notice'=>'granted']);
    }

    /** Removes one earned award (link on the employee profile). A manual achievement's definition is retired with it. */
    public function achievement_award_delete(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        $award_id=absint($_GET['award_id']??0);
        $employee_id=absint($_GET['employee_id']??0);
        check_admin_referer('ews_achievement_award_delete_'.$award_id);
        global $wpdb;
        $awards=$wpdb->prefix.'ews_employee_achievements';$defs=$wpdb->prefix.'ews_achievements';
        $row=$wpdb->get_row($wpdb->prepare("SELECT a.id,a.achievement_id,d.name,d.slug,d.rule_type FROM {$awards} a INNER JOIN {$defs} d ON d.id=a.achievement_id WHERE a.id=%d AND a.employee_id=%d LIMIT 1",$award_id,$employee_id));
        if(!$row)wp_die('Achievement award not found.');
        if($wpdb->delete($awards,['id'=>$award_id,'employee_id'=>$employee_id],['%d','%d'])===false)wp_die('Could not delete the achievement award.');
        if($row->rule_type==='manual'){
            $wpdb->update($defs,['active'=>0],['id'=>(int)$row->achievement_id],['%d'],['%d']);
            $this->invalidate_achievement_definitions_cache();
        }
        $name=$wpdb->get_var($wpdb->prepare("SELECT name FROM {$this->employees} WHERE id=%d",$employee_id))?:'Employee #'.$employee_id;
        $this->audit('achievement_award_deleted','employee_achievement',$award_id,sprintf('%s achievement award removed: %s (%s)',$name,$row->name,$row->slug));
        wp_safe_redirect(add_query_arg(['page'=>'ews31-employee-profile','employee_id'=>$employee_id,'achievement_deleted'=>1],admin_url('admin.php')));
        exit;
    }
}
