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

    public function admin_employee_location_save_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            check_admin_referer('ews_employee_location_save_v321');
            global $wpdb;
            $table=$this->ews_v321_ensure_employee_map();
            $this->ews_v321_ensure_locations_table();
            $employee_id=absint($_POST['employee_id']??0);$location_id=absint($_POST['location_id']??0);
            if($employee_id){
                if($location_id)$wpdb->replace($table,['employee_id'=>$employee_id,'location_id'=>$location_id,'updated_at'=>current_time('mysql')]);
                else{$ok=$wpdb->delete($table,['employee_id'=>$employee_id],['%d']);if($ok===false)wp_die('Unable to clear employee location. '.esc_html($wpdb->last_error));}
            }
            $this->redirect(['page'=>'ews31-multi-locations','employee_saved'=>1]);
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
                'employees'=>(array)$wpdb->get_results("SELECT e.id,e.name,e.domain_name,m.location_id FROM {$this->employees} e LEFT JOIN {$map} m ON m.employee_id=e.id WHERE e.active=1 ORDER BY e.name ASC"),
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
