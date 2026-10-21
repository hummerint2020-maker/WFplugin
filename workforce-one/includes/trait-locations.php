<?php
if (!defined('ABSPATH')) exit;

use WorkforceOne\Locations\LocationRules;

/**
 * Work locations: the locations table, the default location, each employee's assigned location
 * (used by Sign In, QR kiosks and the reports) and wp-admin → Work Locations.
 * Rules: src/Locations/LocationRules.php; page: templates/admin/locations.php.
 */

trait EWS_Locations_Trait {

    private function ews_v321_ensure_locations_table(){
            global $wpdb;
            $table=$this->locations;
            $schema_current=$this->ews_schema_is_current();
            if(!$schema_current && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table){
                require_once ABSPATH.'wp-admin/includes/upgrade.php';
                $c=$wpdb->get_charset_collate();
                dbDelta("CREATE TABLE {$table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(190) NOT NULL,
                    latitude DECIMAL(10,7) NOT NULL,
                    longitude DECIMAL(10,7) NOT NULL,
                    radius INT UNSIGNED NOT NULL DEFAULT 200,
                    enforcement TINYINT(1) NOT NULL DEFAULT 0,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    active TINYINT(1) NOT NULL DEFAULT 1,
                    seats INT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(id), KEY active(active), KEY name(name)
                ) $c;");
            }
            // Backfill the column on installations created by earlier releases.
            $has_default = $schema_current || $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'is_default'");
            if(!$has_default){
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER enforcement");
            }
            // Seats (3.31.30): how many people the location holds; NULL = no limit (Location Capacity report).
            if(!$schema_current && !$wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'seats'")){
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN seats INT UNSIGNED NULL AFTER active");
            }
            // The first active location is always the default when no default exists.
            $default_id=(int)$wpdb->get_var("SELECT id FROM {$table} WHERE is_default=1 AND active=1 ORDER BY id ASC LIMIT 1");
            if(!$default_id){
                $first_id=(int)$wpdb->get_var("SELECT id FROM {$table} WHERE active=1 ORDER BY id ASC LIMIT 1");
                if($first_id){$ok=$wpdb->update($table,['is_default'=>1],['id'=>$first_id],['%d'],['%d']);if($ok===false)error_log('Workforce One: failed to set default location: '.$wpdb->last_error);}
            } else {
                // Keep the data clean: only one active default.
                $wpdb->query($wpdb->prepare("UPDATE {$table} SET is_default=0 WHERE id<>%d AND is_default=1",$default_id));
            }
            return $table;
        }

    private function ews_default_location(){
            global $wpdb;
            // Hot path (every Sign In / time view): schema and default normalization are handled by
            // maybe_upgrade_schema() and the location admin handlers.
            if(!$this->ews_schema_is_current())$this->ews_v321_ensure_locations_table();
            return $wpdb->get_row("SELECT * FROM {$this->locations} WHERE active=1 AND is_default=1 ORDER BY id ASC LIMIT 1");
        }

    private function ews_v321_ensure_employee_map(){
            global $wpdb;
            $table=$wpdb->prefix.'ews_employee_locations_v321';
            if(!$this->ews_schema_is_current() && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table){
                require_once ABSPATH.'wp-admin/includes/upgrade.php';
                $c=$wpdb->get_charset_collate();
                dbDelta("CREATE TABLE {$table} (
                    employee_id BIGINT UNSIGNED NOT NULL,
                    location_id BIGINT UNSIGNED NOT NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(employee_id), KEY location_id(location_id)
                ) $c;");
            }
            return $table;
        }

    private function ews_v321_employee_location($employee_id){
            global $wpdb;
            $table=$wpdb->prefix.'ews_employee_locations_v321';
            if(!$this->ews_schema_is_current() && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$table))!==$table)return null;
            $lid=(int)$wpdb->get_var($wpdb->prepare("SELECT location_id FROM {$table} WHERE employee_id=%d",(int)$employee_id));
            if($lid){
                $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->locations} WHERE id=%d AND active=1",$lid));
                if($row)return $row;
            }
            return $this->ews_default_location();
        }

    public function admin_multi_location_save_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            check_admin_referer('ews_multi_location_save_v321');
            global $wpdb;
            $this->ews_v321_ensure_locations_table();
            $id=absint($_POST['location_id']??0);
            $name=sanitize_text_field(wp_unslash($_POST['location_name']??''));
            $lat=sanitize_text_field(wp_unslash($_POST['location_latitude']??''));
            $lng=sanitize_text_field(wp_unslash($_POST['location_longitude']??''));
            if(!LocationRules::valid($name,$lat,$lng))wp_die('Please enter a valid location name, latitude and longitude.');
            $active_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->locations} WHERE active=1");
            $is_default=LocationRules::isDefault(!empty($_POST['location_default']),!$id,$active_count)?1:0;
            $data=['name'=>$name,'latitude'=>(float)$lat,'longitude'=>(float)$lng,'radius'=>LocationRules::radius(absint($_POST['location_radius']??200)),
                'enforcement'=>!empty($_POST['location_enforcement'])?1:0,'is_default'=>$is_default,'active'=>!empty($_POST['active'])?1:0,
                'seats'=>LocationRules::seats(absint($_POST['location_seats']??0)),'updated_at'=>current_time('mysql')];
            // Saving a default location is a two-write operation. Keep it atomic so a
            // failure while clearing the previous default cannot leave inconsistent state.
            if($wpdb->query('START TRANSACTION')===false)wp_die('Unable to start location save.');
            if($id)$ok=$wpdb->update($this->locations,$data,['id'=>$id]);else{$data['created_at']=current_time('mysql');$ok=$wpdb->insert($this->locations,$data);$id=(int)$wpdb->insert_id;}
            if($ok===false){$wpdb->query('ROLLBACK');wp_die('Unable to save location. '.esc_html($wpdb->last_error));}
            if($is_default && $id){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$this->locations} SET is_default=0 WHERE id<>%d",$id));
                if($q===false){$wpdb->query('ROLLBACK');wp_die('Unable to finalize default location. '.esc_html($wpdb->last_error));}
            }
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');wp_die('Unable to finalize location save. '.esc_html($wpdb->last_error));}
            $this->redirect(['page'=>'ews31-multi-locations','saved'=>1]);
        }

    /** Archive a location (kept for history). An archived location is never the default: the next active one takes over. */
    public function admin_multi_location_archive_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            check_admin_referer('ews_multi_location_archive_v321');
            global $wpdb;$this->ews_v321_ensure_locations_table();
            $id=absint($_POST['location_id']??0);
            if($id){$ok=$wpdb->update($this->locations,['active'=>0,'is_default'=>0,'updated_at'=>current_time('mysql')],['id'=>$id]);if($ok===false)wp_die('Unable to archive location. '.esc_html($wpdb->last_error));}
            $this->ews_v321_ensure_locations_table(); // picks the next active location as the default
            $this->redirect(['page'=>'ews31-multi-locations','archived'=>1]);
        }

    /**
     * Saves an employee's main branch (location_id; 0 = the default location) and, from the branches
     * form (3.31.72), their other branches. Administrators and "Manage Locations"; a manager with
     * "Manage Employee Branches" for their department's people, with the branches they may give.
     */
    public function admin_employee_location_save_v321(){
            check_admin_referer('ews_employee_location_save_v321');
            global $wpdb;
            $table=$this->ews_v321_ensure_employee_map();
            $this->ews_v321_ensure_locations_table();
            $employee_id=absint($_POST['employee_id']??0);$location_id=absint($_POST['location_id']??0);
            if(!$employee_id||!$this->branch_can_edit($employee_id))wp_die('Access denied');
            $choices=$this->branch_choices();
            if($location_id&&!isset($choices[$location_id]))wp_die('That branch is not available.');
            $was_assigned=(int)$wpdb->get_var($wpdb->prepare("SELECT location_id FROM {$table} WHERE employee_id=%d",$employee_id));
            if($location_id)$wpdb->replace($table,['employee_id'=>$employee_id,'location_id'=>$location_id,'updated_at'=>current_time('mysql')]);
            else{$ok=$wpdb->delete($table,['employee_id'=>$employee_id],['%d']);if($ok===false)wp_die('Unable to clear employee location. '.esc_html($wpdb->last_error));}
            if($was_assigned!==$location_id){$locs=$this->branch_locations();$this->audit('employee_branches_update','employee',$employee_id,'Main branch: '.($was_assigned&&isset($locs[$was_assigned])?$locs[$was_assigned]->name:'default').' -> '.($location_id&&isset($locs[$location_id])?$locs[$location_id]->name:'default'));}
            if(isset($_POST['branches_form'])){
                $others=isset($_POST['other_location_ids'])&&is_array($_POST['other_location_ids'])?array_map('absint',$_POST['other_location_ids']):[];
                // A manager may change only the branches they can give; others the employee has stay.
                $keep=array_values(array_diff($this->branch_other_ids($employee_id),array_keys($choices)));
                $this->branch_set_others($employee_id,array_merge($keep,array_values(array_intersect($others,array_keys($choices)))));
            }
            $back=['employee_saved'=>1];parse_str((string)wp_parse_url((string)wp_get_referer(),PHP_URL_QUERY),$q);
            foreach(['paged','s'] as $k)if(!empty($q[$k]))$back[$k]=$k==='paged'?absint($q[$k]):sanitize_text_field((string)$q[$k]);
            $page=(!empty($q['page'])&&$q['page']==='ews31-employee-branches')?'ews31-employee-branches':'ews31-multi-locations';
            wp_safe_redirect(add_query_arg($back,admin_url('admin.php?page='.$page)).'#ews-employee-branches');exit;
        }

    /** The Employee Branches list (Work Locations, and its own page for managers): 50 a page, a search, each row one form. */
    private function employee_branches_section(){
            global $wpdb;
            $map=$this->ews_v321_ensure_employee_map();
            $scoped=!(current_user_can('manage_options')||current_user_can('ews_manage_locations'));
            $search=sanitize_text_field(wp_unslash($_GET['s']??''));$per=50;
            $where=" WHERE e.active=1";
            if($scoped){$ids=$this->department_employee_ids();$where.=$ids?" AND e.id IN (".implode(',',array_map('intval',$ids)).")":" AND 1=0";}
            if($search!==''){$like='%'.$wpdb->esc_like($search).'%';$where.=$wpdb->prepare(" AND (e.name LIKE %s OR e.domain_name LIKE %s)",$like,$like);}
            $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->employees} e{$where}");
            $pages=max(1,(int)ceil($total/$per));$paged=min(max(1,absint($_GET['paged']??1)),$pages);
            $rows=(array)$wpdb->get_results($wpdb->prepare("SELECT e.id,e.name,e.domain_name,m.location_id FROM {$this->employees} e LEFT JOIN {$map} m ON m.employee_id=e.id{$where} ORDER BY e.name ASC LIMIT %d OFFSET %d",$per,($paged-1)*$per));
            $others=$this->branch_other_ids_bulk(array_map(function($r){return (int)$r->id;},$rows));
            $default=$this->ews_default_location();
            $slug=sanitize_key($_GET['page']??'')==='ews31-employee-branches'?'ews31-employee-branches':'ews31-multi-locations';
            return $this->render_template('admin/employee-branches',[
                'rows'=>$rows,'others'=>$others,'choices'=>$this->branch_choices(),'locations'=>$this->branch_locations(),'default_name'=>$default?(string)$default->name:'',
                'search'=>$search,'paged'=>$paged,'pages'=>$pages,'total'=>$total,'mode'=>$this->branch_settings()['mode'],
                'page_slug'=>$slug,'page_url'=>admin_url('admin.php?page='.$slug),
                'post_url'=>admin_url('admin-post.php'),'features_url'=>admin_url('admin.php?page=ews31-features#ews-branches'),
            ]);
        }

    /** wp-admin → Employee Branches: the same list for a manager with "Manage Employee Branches" (their department). */
    public function admin_employee_branches(){
            if(!$this->can('ews_manage_employee_branches')&&!$this->can('ews_manage_locations'))wp_die('Access denied');
            echo '<div class="wrap"><h1>Employee Branches</h1>';
            if(isset($_GET['employee_saved']))echo '<div class="notice notice-success is-dismissible"><p>Branches saved.</p></div>';
            echo $this->employee_branches_section(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
            echo '</div>';
        }

    /** wp-admin → Work Locations: add / edit a location, assign employees, the list of locations. */
    public function admin_multi_locations_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            global $wpdb;
            $this->ews_v321_ensure_locations_table();
            $map=$this->ews_v321_ensure_employee_map();
            $edit_id=absint($_GET['edit_location']??0);
            $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->locations} WHERE id=%d",$edit_id)):null;
            $rows=(array)$wpdb->get_results("SELECT * FROM {$this->locations} ORDER BY active DESC,name ASC");
            echo $this->render_template('admin/locations',[ // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the template
                'edit'=>$edit,'rows'=>$rows,
                'form'=>['name'=>$edit?$edit->name:'','lat'=>$edit?$edit->latitude:'','lng'=>$edit?$edit->longitude:'','radius'=>$edit?(int)$edit->radius:200,'seats'=>$edit&&$edit->seats?(int)$edit->seats:'',
                    'enforcement'=>$edit?(int)$edit->enforcement:0,'active'=>$edit?(int)$edit->active:1,'is_default'=>$edit?(int)$edit->is_default:(!$rows?1:0)],
                'branches_html'=>$this->employee_branches_section(),
                'active_locations'=>(array)$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC"),
                'warn_pct'=>\WorkforceOne\Reports\Capacity::warnPercent($this->option('ews_capacity_warn_percent')),
                'notice'=>isset($_GET['capacity_saved'])?'Capacity warning saved.':(isset($_GET['saved'])?'Location saved successfully.':(isset($_GET['archived'])?'Location archived successfully.':(isset($_GET['employee_saved'])?'Employee location saved successfully.':''))),
            ]);
        }

    /** Work Locations → when a day counts as "near capacity" in the Location Capacity report. */
    public function admin_capacity_settings_save(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            check_admin_referer('ews_capacity_settings_save');
            $pct=\WorkforceOne\Reports\Capacity::warnPercent($_POST['capacity_warn_percent']??90);
            update_option('ews_capacity_warn_percent',$pct,false);
            $this->audit('capacity_warning_saved','settings',0,$pct.'%');
            wp_safe_redirect(admin_url('admin.php?page=ews31-multi-locations&capacity_saved=1'));
            exit;
        }
}
