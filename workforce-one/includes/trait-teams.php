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

    /** Resolve the active manager of the first active team membership for an employee. */
    private function team_manager_for_employee($employee_id){
        return $this->services()->org()->teamManagerFor(absint($employee_id));
    }

    /** The active members of a team (3.31.105). @return list<int> */
    private function team_member_ids($team_id){
        $this->ensure_teams_schema();
        return $this->services()->org()->teamMemberIds(absint($team_id));
    }

    private function team_ids_for_employee($employee_id){
        $this->ensure_teams_schema();
        return $this->services()->org()->teamIdsOf(absint($employee_id));
    }

    /** team_ids_for_employee() for many employees in one query. @param list<int> $employee_ids @return array<int,list<int>> */
    private function team_ids_by_employee(array $employee_ids){
        $this->ensure_teams_schema();
        return $this->services()->org()->teamIdsByEmployee($employee_ids);
    }

    private function sync_employee_teams($employee_id,$team_ids){
        $error=$this->services()->teams()->syncEmployee(absint($employee_id),array_values(array_unique(array_filter(array_map('absint',(array)$team_ids)))));
        return $error===null?true:new WP_Error($error['code'],$error['message']);
    }

    /** wp-admin → Teams: create / edit a team (department, manager, members); archive teams. */
    public function admin_teams(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $this->ensure_teams_schema(); $this->ensure_departments_schema();
        $teams=$this->services()->teams();
        $edit_id=absint($_GET['team_id']??0); $edit=$edit_id?$teams->activeById($edit_id):null;
        echo $this->render_template('admin/teams',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            'edit'=>$edit,
            'selected'=>$edit?$teams->memberIds((int)$edit->id):[],
            'teams'=>$teams->adminList(),
            'employees'=>$teams->pageEmployees(),
            'departments'=>$teams->pageDepartments(),
            'notice'=>isset($_GET['team_saved'])?'Team saved successfully.':(isset($_GET['team_deleted'])?'Team archived successfully.':''),
            'error'=>OrgRules::message('team',sanitize_key(wp_unslash($_GET['team_error']??''))),
        ]);
    }

    public function team_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        check_admin_referer('ews_team_save'); $this->ensure_teams_schema(); $this->ensure_departments_schema();
        $id=absint($_POST['team_id']??0); $department_id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        $members=isset($_POST['member_ids'])&&is_array($_POST['member_ids'])?array_map('absint',wp_unslash($_POST['member_ids'])):[];
        $members[]=$manager; $members=array_values(array_unique(array_filter($members)));
        $r=$this->services()->teams()->save($id,$department_id,$name,$description,$manager,$members);
        if($r['error']!=='')$this->team_redirect_error($r['error'],!$r['rolled_back']);
        wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_saved=1'));exit;
    }

    /** Back to the Teams page with the reason (OrgRules::MESSAGES['team']); undoes an open transaction (unless the service has just done it). */
    private function team_redirect_error($code='save',$rollback=true){
        if($rollback&&isset($GLOBALS['wpdb']))$GLOBALS['wpdb']->query('ROLLBACK');
        wp_safe_redirect(add_query_arg('team_error',$code,admin_url('admin.php?page=ews31-teams')));exit;
    }

    public function team_delete(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $id=absint($_POST['team_id']??0);check_admin_referer('ews_team_delete_'.$id);$this->ensure_teams_schema();
        $error=$this->services()->teams()->archive($id);
        if($error!==null)wp_die('Could not archive team. Database error: '.esc_html($error));
        wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_deleted=1'));exit;
    }

}
