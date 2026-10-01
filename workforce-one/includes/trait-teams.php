<?php
if (!defined('ABSPATH')) exit;

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

    public function admin_teams(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        $this->ensure_teams_schema();
        global $wpdb; $t=$this->team_tables();
        $teams=$wpdb->get_results("SELECT tm.*,e.name manager_name,(SELECT COUNT(*) FROM {$t['members']} m WHERE m.team_id=tm.id AND m.active=1) member_count FROM {$t['teams']} tm LEFT JOIN {$this->employees} e ON e.id=tm.manager_employee_id WHERE tm.active=1 ORDER BY tm.name ASC");
        $this->ensure_departments_schema(); $employees=$wpdb->get_results("SELECT id,name,domain_name,department_id FROM {$this->employees} WHERE active=1 ORDER BY name ASC"); $departments=$wpdb->get_results("SELECT id,name FROM {$wpdb->prefix}ews_departments WHERE active=1 ORDER BY name ASC");
        $edit_id=absint($_GET['team_id']??0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['teams']} WHERE id=%d AND active=1 LIMIT 1",$edit_id)):null;
        $selected=[];
        if($edit){$selected=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT employee_id FROM {$t['members']} WHERE team_id=%d AND active=1",(int)$edit->id)));}
        echo '<div class="wrap"><h1>Teams</h1>';
        if(isset($_GET['team_saved']))echo '<div class="notice notice-success is-dismissible"><p>Team saved successfully.</p></div>';
        if(isset($_GET['team_deleted']))echo '<div class="notice notice-success is-dismissible"><p>Team archived successfully.</p></div>';
        if(isset($_GET['team_error']))echo '<div class="notice notice-error is-dismissible"><p>Could not save the team. Please verify the team name, manager and members.</p></div>';
        echo '<p style="max-width:900px">Create work teams and assign one Team Manager to each team. The Team Manager is an organizational relationship, not a WordPress role, and the manager is automatically included as a team member.</p>';
        echo '<div style="display:grid;grid-template-columns:minmax(320px,520px) 1fr;gap:20px;align-items:start">';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">'.($edit?'Edit Team':'Create Team').'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_team_save','_wpnonce',true,false).'<input type="hidden" name="action" value="ews_team_save"><input type="hidden" name="team_id" value="'.(int)($edit?$edit->id:0).'">';
        echo '<p><label><strong>Name</strong><br><input type="text" name="name" value="'.esc_attr($edit?$edit->name:'').'" required style="width:100%;max-width:480px"></label></p>';
        echo '<p><label><strong>Description</strong><br><textarea name="description" rows="4" style="width:100%;max-width:480px">'.esc_textarea($edit?$edit->description:'').'</textarea></label></p>';
        echo '<p><label><strong>Team Manager</strong><br><select name="manager_employee_id" required style="width:100%;max-width:480px"><option value="0">— Select manager —</option>';foreach($employees as $e)echo '<option value="'.(int)$e->id.'"'.selected((int)($edit?$edit->manager_employee_id:0),(int)$e->id,false).'>'.esc_html($e->name).' · '.esc_html($e->domain_name).'</option>';echo '</select></label></p>';
        echo '<p><strong>Team Members</strong><br><span class="description">Select the employees who belong to this team. The manager will always be added automatically.</span></p><div style="max-height:300px;overflow:auto;border:1px solid #dcdcde;padding:10px">';foreach($employees as $e){echo '<label style="display:block;padding:6px 0"><input type="checkbox" name="member_ids[]" value="'.(int)$e->id.'"'.(in_array((int)$e->id,$selected,true)?' checked':'').'> '.esc_html($e->name).' · '.esc_html($e->domain_name).'</label>';}echo '</div>';
        echo '<p><label><strong>Department</strong><br><select name="department_id" required style="min-width:240px"><option value="">-- Select Department --</option>';foreach($departments as $dep)echo '<option value="'.(int)$dep->id.'"'.selected((int)($edit->department_id??0),(int)$dep->id,false).'>'.esc_html($dep->name).'</option>';echo '</select></label></p><p><button class="button button-primary" type="submit">'.($edit?'Save Team':'Create Team').'</button> '.($edit?'<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-teams')).'">Cancel</a>':'').'</p></form></div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:22px"><h2 style="margin-top:0">Active Teams</h2>';if(!$teams){echo '<p>No teams created yet.</p>';}else{echo '<table class="widefat striped"><thead><tr><th>Team</th><th>Manager</th><th>Members</th><th>Actions</th></tr></thead><tbody>';foreach($teams as $team){echo '<tr><td><strong>'.esc_html($team->name).'</strong>'.($team->description?'<br><span style="color:#646970">'.esc_html($team->description).'</span>':'').'</td><td>'.esc_html($team->manager_name?:'—').'</td><td>'.(int)$team->member_count.'</td><td><a class="button button-small" href="'.esc_url(add_query_arg(['page'=>'ews31-teams','team_id'=>(int)$team->id],admin_url('admin.php'))).'">Edit</a> <form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline">'.wp_nonce_field('ews_team_delete_'.$team->id,'_wpnonce',true,false).'<input type="hidden" name="action" value="ews_team_delete"><input type="hidden" name="team_id" value="'.(int)$team->id.'"><button class="button button-small" type="submit" onclick="return confirm(\'Archive this team?\')">Archive</button></form></td></tr>'; }echo '</tbody></table>';}
        echo '</div></div></div>';
    }

    public function team_save(){
        if(!current_user_can('manage_options'))wp_die('Access denied');
        check_admin_referer('ews_team_save'); $this->ensure_teams_schema();
        global $wpdb; $t=$this->team_tables();
        $id=absint($_POST['team_id']??0); $department_id=absint($_POST['department_id']??0); $name=sanitize_text_field(wp_unslash($_POST['name']??'')); $description=sanitize_textarea_field(wp_unslash($_POST['description']??'')); $manager=absint($_POST['manager_employee_id']??0);
        $members=isset($_POST['member_ids'])&&is_array($_POST['member_ids'])?array_values(array_unique(array_filter(array_map('absint',$_POST['member_ids'])))):[];
        if(!$name||!$manager||!$department_id)return $this->team_redirect_error();
        $this->ensure_departments_schema();
        $department_exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}ews_departments WHERE id=%d AND active=1 LIMIT 1",$department_id));
        if(!$department_exists)return $this->team_redirect_error();
        $manager_exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$manager,$department_id));
        if(!$manager_exists)return $this->team_redirect_error();
        $dup=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['teams']} WHERE name=%s AND id<>%d AND active=1 LIMIT 1",$name,$id));
        if($dup)return $this->team_redirect_error();
        $now=current_time('mysql');
        if($wpdb->query('START TRANSACTION')===false)return $this->team_redirect_error();
        if($id){$ok=$wpdb->update($t['teams'],['name'=>$name,'description'=>$description,'manager_employee_id'=>$manager,'department_id'=>$department_id,'updated_at'=>$now],['id'=>$id],['%s','%s','%d','%d','%s'],['%d']);}
        else{$ok=$wpdb->insert($t['teams'],['name'=>$name,'description'=>$description,'manager_employee_id'=>$manager,'department_id'=>$department_id,'active'=>1,'created_at'=>$now,'updated_at'=>$now],['%s','%s','%d','%d','%d','%s','%s']);$id=(int)$wpdb->insert_id;}
        if($ok===false||!$id){$wpdb->query('ROLLBACK');return $this->team_redirect_error();}
        $members[]=$manager; $members=array_values(array_unique($members));
        $valid=[];foreach($members as $eid){if($wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->employees} WHERE id=%d AND active=1 AND department_id=%d LIMIT 1",$eid,$department_id)))$valid[]=$eid;}if(count($valid)!==count($members)){ $wpdb->query('ROLLBACK'); return $this->team_redirect_error(); }
        $wpdb->query($wpdb->prepare("UPDATE {$t['members']} SET active=0,updated_at=%s WHERE team_id=%d AND active=1",$now,$id));
        foreach($valid as $eid){$existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['members']} WHERE team_id=%d AND employee_id=%d LIMIT 1",$id,$eid));if($existing){$ok=$wpdb->update($t['members'],['active'=>1,'updated_at'=>$now],['id'=>(int)$existing],['%d','%s'],['%d']);}else{$ok=$wpdb->insert($t['members'],['team_id'=>$id,'employee_id'=>$eid,'active'=>1,'joined_at'=>$now,'updated_at'=>$now],['%d','%d','%d','%s','%s']);}if($ok===false){$wpdb->query('ROLLBACK');return $this->team_redirect_error();}}
        if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');return $this->team_redirect_error();}
        $this->audit($id?'team_update':'team_create','team',$id,$name.' / manager='.$manager.' / members='.count($valid));
        wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_saved=1'));exit;
    }

    private function team_redirect_error(){
        if(isset($GLOBALS['wpdb']))$GLOBALS['wpdb']->query('ROLLBACK');
        wp_safe_redirect(admin_url('admin.php?page=ews31-teams&team_error=1'));exit;
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
