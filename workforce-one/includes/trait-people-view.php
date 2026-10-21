<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Employees\ProfileSummary;

/**
 * The employee app's People directory (?ews_view=people) and a colleague's profile
 * (?ews_view=employee), whose sections follow wp-admin → Employee Profile settings. Kudos are
 * sent by recognition_submit() (trait-recognition.php). Views: templates/app/people.php,
 * templates/app/employee.php; script: assets/js/people.js. Behaviour: tests/e2e_people.py.
 */
trait EWS_People_View_Trait {

    /** Photo or avatar URL of an employee row ('' = show initials). */
    private function people_picture($row){
        if(($row->profile_image_type??'')==='photo' && !empty($row->profile_image_url))return (string)$row->profile_image_url;
        if(($row->profile_image_type??'')==='avatar' && !empty($row->avatar_key))return $this->profile_avatar_url($row->avatar_key);
        return '';
    }

    private function people_content(){
        global $wpdb;
        if(!$this->can('ews_view_people'))return $this->ews_empty_state('Access denied','People is not available for your account.');
        $search=sanitize_text_field(wp_unslash($_GET['people_search']??''));
        $team_id=absint($_GET['people_team']??0);
        $where=['active=1'];$args=[];
        if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where[]='(name LIKE %s OR domain_name LIKE %s OR email LIKE %s)';array_push($args,$like,$like,$like);}
        $tt=$this->team_tables();
        if($team_id){
            $ids=array_map('absint',(array)$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$tt['members']} WHERE team_id=%d AND active=1",$team_id)))?:[0];
            $where[]='id IN ('.implode(',',array_fill(0,count($ids),'%d')).')';$args=array_merge($args,$ids);
        }
        $sql="SELECT id,name,domain_name,profile_image_type,profile_image_url,avatar_key FROM {$this->employees} WHERE ".implode(' AND ',$where).' ORDER BY name ASC LIMIT 500';
        $people=[];
        foreach((array)($args?$wpdb->get_results($wpdb->prepare($sql,...$args)):$wpdb->get_results($sql)) as $r){
            $people[]=['name'=>(string)$r->name,'domain'=>(string)$r->domain_name,'initials'=>ProfileSummary::initials((string)$r->name)?:'ME','picture'=>$this->people_picture($r),
                'url'=>add_query_arg(['ews_view'=>'employee','employee_id'=>(int)$r->id],$this->app_view_url('people'))];
        }
        wp_enqueue_style('workforce-one-people-page');
        return $this->render_template('app/people',[
            'people'=>$people,'search'=>$search,'team_id'=>$team_id,
            'teams'=>(array)$wpdb->get_results("SELECT id,name FROM {$tt['teams']} WHERE active=1 ORDER BY name ASC"),
            'clear_url'=>$this->app_view_url('people'),
        ]);
    }

    private function employee_profile_content($employee_id=0){
        global $wpdb;
        $cfg=$this->employee_profile_settings();
        if(!$this->can('ews_view_people') || empty($cfg['enabled']))return $this->ews_empty_state('Profiles unavailable','Employee profiles are not available for your account.');
        if(!$employee_id)return $this->ews_empty_state('Employee not found','No employee was selected.');
        $emp=$wpdb->get_row($wpdb->prepare("SELECT id,name,email,profile_image_type,profile_image_url,avatar_key FROM {$this->employees} WHERE id=%d AND active=1 LIMIT 1",$employee_id));
        if(!$emp)return $this->ews_empty_state('Employee not found','This employee is unavailable.');
        $eid=(int)$emp->id;

        $teams=[];
        if(!empty($cfg['show_team'])){
            $ids=$this->team_ids_for_employee($eid);
            if($ids){$tt=$this->team_tables();$teams=(array)$wpdb->get_col($wpdb->prepare("SELECT name FROM {$tt['teams']} WHERE id IN (".implode(',',array_fill(0,count($ids),'%d')).") AND active=1 ORDER BY name ASC",...$ids));}
        }
        // Supervisors are approval relationships (wp-admin → Employees), not an employee column.
        $supervisor=!empty($cfg['show_supervisor'])?$this->approval_related_employee($eid,'supervisor'):null;

        $recognition=null;
        if(!empty($cfg['show_recognition']) && $this->recognition_enabled()){
            $viewer=$this->current_employee();
            $notice_key='wfo_kudos_notice_'.get_current_user_id().'_'.$eid;
            $notice=(string)get_transient($notice_key);
            if($notice!=='')delete_transient($notice_key);
            $recognition=['rows'=>(array)$this->recognition_rows($eid),'categories'=>$this->recognition_categories(),
                'can_kudos'=>$viewer && (int)$viewer->id!==$eid && $this->recognition_allow_kudos(),
                'sent'=>$notice==='success'||!empty($_GET['kudos_sent']),
                'error'=>strpos($notice,'error')===0?(substr($notice,6)?:'error'):sanitize_key($_GET['kudos_error']??'')];
            foreach($recognition['rows'] as $row)$row->category_label=$this->recognition_category_label($row->category);
        }

        wp_enqueue_script('workforce-one-people');
        wp_enqueue_style('workforce-one-profile-page');
        wp_enqueue_style('workforce-one-people-page');
        return $this->render_template('app/employee',[
            'cfg'=>$cfg,'emp'=>$emp,'initials'=>ProfileSummary::initials((string)$emp->name)?:'ME','picture'=>!empty($cfg['show_photo'])?$this->people_picture($emp):'',
            'teams'=>$teams,'supervisor'=>$supervisor?(string)$supervisor->name:'',
            'achievements'=>!empty($cfg['show_achievements']) && $this->achievements_enabled()?(array)$this->employee_achievements($eid):null,
            'recognition'=>$recognition,'back_url'=>$this->app_view_url('people'),'post_url'=>admin_url('admin-post.php'),
        ]);
    }
}
