<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Organization\OrgRules;

/**
 * Workforce One Teams foundation.
 *
 * A Team is an organizational unit with:
 * - a name/description
 * - exactly one active Team Manager (who is also a member)
 * - one or more employee memberships
 *
 * Team Manager is NOT a WordPress role. It is an organizational relationship.
 */
trait EWS_Teams_Trait {

    private function team_tables(){
        global $wpdb;
        return [
            'teams'   => $wpdb->prefix.'ews_teams',
            'members' => $wpdb->prefix.'ews_team_members',
        ];
    }

    private function ensure_teams_schema(){
        if($this->ews_schema_is_current())return true;
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $t=$this->team_tables();
        dbDelta("CREATE TABLE {$t['teams']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            manager_employee_id BIGINT UNSIGNED NULL,
            department_id BIGINT UNSIGNED NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY team_name(name),
            KEY manager_employee(manager_employee_id),
            KEY active(active)
        ) {$c};");
        dbDelta("CREATE TABLE {$t['members']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY team_employee(team_id,employee_id),
            KEY team_active(team_id,active),
            KEY employee_active(employee_id,active)
        ) {$c};");
        return true;
    }

    private function team_manager_for_team($team_id){
        global $wpdb; $t=$this->team_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT e.* FROM {$t['teams']} tm JOIN {$this->employees} e ON e.id=tm.manager_employee_id WHERE tm.id=%d AND tm.active=1 AND e.active=1 LIMIT 1",absint($team_id)));
    }

    /** Resolve the active manager of the first active team membership for an employee. */
    private function team_manager_for_employee($employee_id){
        global $wpdb; $t=$this->team_tables();
        return $wpdb->get_row($wpdb->prepare("SELECT e.*,tm.id AS team_id,tm.name AS team_name FROM {$t['teams']} tm JOIN {$t['members']} m ON m.team_id=tm.id AND m.active=1 JOIN {$this->employees} e ON e.id=tm.manager_employee_id AND e.active=1 WHERE m.employee_id=%d AND tm.active=1 ORDER BY tm.id ASC LIMIT 1",absint($employee_id)));
    }

    private function team_ids_for_employee($employee_id){
        global $wpdb; $t=$this->team_tables();
        $this->ensure_teams_schema();
        return array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT team_id FROM {$t['members']} WHERE employee_id=%d AND active=1 ORDER BY team_id ASC",absint($employee_id))));
    }

    /** team_ids_for_employee() for many employees in one query. @param list<int> $employee_ids @return array<int,list<int>> */
    private function team_ids_by_employee(array $employee_ids){
        global $wpdb; $t=$this->team_tables();
        $this->ensure_teams_schema();
        $ids=array_values(array_unique(array_filter(array_map('intval',$employee_ids))));
        $out=array_fill_keys($ids,[]);
        foreach(array_chunk($ids,500) as $chunk){
            $ph=implode(',',array_fill(0,count($chunk),'%d'));
            foreach((array)$wpdb->get_results($wpdb->prepare("SELECT employee_id,team_id FROM {$t['members']} WHERE employee_id IN ($ph) AND active=1 ORDER BY team_id ASC",$chunk)) as $r)$out[(int)$r->employee_id][]=(int)$r->team_id;
        }
        return $out;
    }

    private function sync_employee_teams($employee_id,$team_ids){
        global $wpdb; $t=$this->team_tables();
        $employee_id=absint($employee_id);
        $team_ids=array_values(array_unique(array_filter(array_map('absint',(array)$team_ids))));
        $now=current_time('mysql');
        $employee_department=(int)$wpdb->get_var($wpdb->prepare("SELECT department_id FROM {$this->employees} WHERE id=%d LIMIT 1",$employee_id));
        foreach($team_ids as $team_id){
            $team_department=$wpdb->get_var($wpdb->prepare("SELECT department_id FROM {$t['teams']} WHERE id=%d AND active=1 LIMIT 1",$team_id));
            if($team_department===null || (int)$team_department!==$employee_department) return new WP_Error('team_department_mismatch','Selected team does not belong to the employee department.');
        }
        $managed=(array)$wpdb->get_col($wpdb->prepare("SELECT id FROM {$t['teams']} WHERE manager_employee_id=%d AND active=1 AND department_id=%d",$employee_id,$employee_department));
        $team_ids=array_values(array_unique(array_merge($team_ids,array_map('intval',$managed))));
        $wpdb->query($wpdb->prepare("UPDATE {$t['members']} SET active=0,updated_at=%s WHERE employee_id=%d AND active=1",$now,$employee_id));
        foreach($team_ids as $team_id){
            $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['teams']} WHERE id=%d AND active=1",$team_id));
            if(!$exists) continue;
            $member_id=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['members']} WHERE team_id=%d AND employee_id=%d LIMIT 1",$team_id,$employee_id));
            if($member_id){
                $ok=$wpdb->update($t['members'],['active'=>1,'updated_at'=>$now],['id'=>(int)$member_id],['%d','%s'],['%d']);
            }else{
                $ok=$wpdb->insert($t['members'],['team_id'=>$team_id,'employee_id'=>$employee_id,'active'=>1,'joined_at'=>$now,'updated_at'=>$now],['%d','%d','%d','%s','%s']);
            }
            if($ok===false) return new WP_Error('team_membership_save','Could not save team membership: '.$wpdb->last_error);
        }
        return true;
    }

    private function team_is_member($team_id,$employee_id){
        global $wpdb; $t=$this->team_tables();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['members']} WHERE team_id=%d AND employee_id=%d AND active=1 LIMIT 1",absint($team_id),absint($employee_id)));
    }

    /** wp-admin → Teams: create / edit a team (department, manager, members); archive teams. */
    public function admin_teams(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $this->ensure_teams_schema(); $this->ensure_departments_schema();
        global $wpdb; $t=$this->team_tables();
        $edit_id=absint($_GET['team_id']??0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['teams']} WHERE id=%d AND active=1 LIMIT 1",$edit_id)):null;
        echo $this->render_template('admin/teams',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'edit'=>$edit,
            'selected'=>$edit?array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$t['members']} WHERE team_id=%d AND active=1",(int)$edit->id))):[],
            'teams'=>(array)$wpdb->get_results("SELECT tm.*,e.name manager_name,d.name department_name,(SELECT COUNT(*) FROM {$t['members']} m WHERE m.team_id=tm.id AND m.active=1) member_count FROM {$t['teams']} tm LEFT JOIN {$this->employees} e ON e.id=tm.manager_employee_id LEFT JOIN {$wpdb->prefix}ews_departments d ON d.id=tm.department_id WHERE tm.active=1 ORDER BY tm.name ASC"),
            'employees'=>(array)$wpdb->get_results("SELECT e.id,e.name,e.domain_name,e.department_id,d.name department_name FROM {$this->employees} e LEFT JOIN {$wpdb->prefix}ews_departments d ON d.id=e.department_id AND d.active=1 WHERE e.active=1 ORDER BY e.name ASC"),
            'departments'=>(array)$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_departments WHERE active=1 ORDER BY name ASC"),
            'notice'=>isset($_GET['team_saved'])?'Team saved successfully.':(isset($_GET['team_deleted'])?'Team archived successfully.':''),
            'error'=>OrgRules::message('team',sanitize_key(wp_unslash($_GET['team_error']??''))),
        ]);
    }

    public function team_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        check_admin_referer('ews_team_save'); $this->ensure_teams_schema(); $this->ensure_departments_schema();
        global $wpdb; $t=$this->team_tables();
        $id=absint($_POST['team_id']??0); $department_id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        $members=isset($_POST['member_ids'])&&is_array($_POST['member_ids'])?array_map('absint',wp_unslash($_POST['member_ids'])):[];
        $members[]=$manager; $members=array_values(array_unique(array_filter($members)));
        $in_department=function($eid)use($wpdb,$department_id){return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$eid,$department_id));};
        $error=OrgRules::teamError([
            'name'=>$name,'department'=>$department_id,'manager'=>$manager,
            'department_active'=>(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_departments WHERE id=%d AND active=1 LIMIT 1",$department_id)),
            'manager_in_department'=>$manager&&$in_department($manager),
            'members_in_department'=>count(array_filter($members,$in_department))===count($members),
            'duplicate'=>OrgRules::duplicate((array)$wpdb->get_col($wpdb->prepare("SELECT active FROM {$t['teams']} WHERE name=%s AND id<>%d",$name,$id))),
        ]);
        if($error!=='')$this->team_redirect_error($error);
        $now=current_time('mysql');$is_new=!$id;
        if($wpdb->query('START TRANSACTION')===false)$this->team_redirect_error('save');
        if($id){$ok=$wpdb->update($t['teams'],['name'=>$name,'description'=>$description,'manager_employee_id'=>$manager,'department_id'=>$department_id,'updated_at'=>$now],['id'=>$id],['%s','%s','%d','%d','%s'],['%d']);}
        else{$ok=$wpdb->insert($t['teams'],['name'=>$name,'description'=>$description,'manager_employee_id'=>$manager,'department_id'=>$department_id,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%s','%s','%d','%d','%d','%s','%s']);$id=(int)$wpdb->insert_id;}
        if($ok===false||!$id)$this->team_redirect_error('save');
        $wpdb->query($wpdb->prepare("UPDATE {$t['members']} SET active=0,updated_at=%s WHERE team_id=%d AND active=1",$now,$id));
        foreach($members as $eid){$existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['members']} WHERE team_id=%d AND employee_id=%d LIMIT 1",$id,$eid));if($existing){$ok=$wpdb->update($t['members'],['active'=>1,'updated_at'=>$now],['id'=>(int)$existing],['%d','%s'],['%d']);}else{$ok=$wpdb->insert($t['members'],['team_id'=>$id,'employee_id'=>$eid,'active'=>1,'joined_at'=>$now,'updated_at'=>$now],['%d','%d','%d','%s','%s']);}if($ok===false)$this->team_redirect_error('save');}
        if($wpdb->query('COMMIT')===false)$this->team_redirect_error('save');
        $this->audit($is_new?'team_create':'team_update','team',$id,$name.' / manager='.$manager.' / members='.count($members));
        wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_saved=1'));exit;
    }

    /** Back to the Teams page with the reason (OrgRules::MESSAGES['team']); undoes an open transaction. */
    private function team_redirect_error($code='save'){
        if(isset($GLOBALS['wpdb']))$GLOBALS['wpdb']->query('ROLLBACK');
        wp_safe_redirect(add_query_arg('team_error',$code,admin_url('admin.php?page=ews31-teams')));exit;
    }

    public function team_delete(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $id=absint($_POST['team_id']??0);check_admin_referer('ews_team_delete_'.$id);$this->ensure_teams_schema();global $wpdb;$t=$this->team_tables();
        $ok=$wpdb->update($t['teams'],['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id],['%d','%s'],['%d']);
        if($ok===false)wp_die('Could not archive team. Database error: '.esc_html($wpdb->last_error));
        $wpdb->update($t['members'],['active'=>0,'updated_at'=>current_time('mysql')],['team_id'=>$id],['%d','%s'],['%d']);
        $this->audit('team_archive','team',$id);wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_deleted=1'));exit;
    }

}
