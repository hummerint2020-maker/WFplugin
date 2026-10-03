<?php
if (!defined('ABSPATH')) exit;

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
            $radius=max(10,min(5000,absint($_POST['location_radius']??200)));
            $enforcement=!empty($_POST['location_enforcement'])?1:0;
            $active=!empty($_POST['active'])?1:0;
            $make_default=!empty($_POST['location_default'])?1:0;
            $seats=absint($_POST['location_seats']??0);
            if(!$name||!is_numeric($lat)||!is_numeric($lng)||$lat<-90||$lat>90||$lng<-180||$lng>180)wp_die('Please enter a valid location name, latitude and longitude.');
            $existing_count=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->locations} WHERE active=1");
            $is_default=($make_default || (!$id && $existing_count===0))?1:0;
            $data=['name'=>$name,'latitude'=>(float)$lat,'longitude'=>(float)$lng,'radius'=>$radius,'enforcement'=>$enforcement,'is_default'=>$is_default,'active'=>$active,'seats'=>$seats?:null,'updated_at'=>current_time('mysql')];
            // Saving a default location is a two-write operation. Keep it atomic so a
            // failure while clearing the previous default cannot leave inconsistent state.
            if($wpdb->query('START TRANSACTION')===false)wp_die('Unable to start location save.');
            if($id)$ok=$wpdb->update($this->locations,$data,['id'=>$id]);else{$data['created_at']=current_time('mysql');$ok=$wpdb->insert($this->locations,$data);$id=(int)$wpdb->insert_id;}
            if($ok===false){$wpdb->query('ROLLBACK');wp_die('Unable to save location. '.$wpdb->last_error);}
            if($is_default && $id){
                $q=$wpdb->query($wpdb->prepare("UPDATE {$this->locations} SET is_default=0 WHERE id<>%d",$id));
                if($q===false){$wpdb->query('ROLLBACK');wp_die('Unable to finalize default location. '.$wpdb->last_error);}
            }
            if($wpdb->query('COMMIT')===false){$wpdb->query('ROLLBACK');wp_die('Unable to finalize location save. '.$wpdb->last_error);}
            $this->redirect(['page'=>'ews31-multi-locations','saved'=>1]);
        }

    public function admin_multi_location_archive_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            check_admin_referer('ews_multi_location_archive_v321');
            global $wpdb;$this->ews_v321_ensure_locations_table();
            $id=absint($_POST['location_id']??0);
            if($id){$ok=$wpdb->update($this->locations,['active'=>0,'updated_at'=>current_time('mysql')],['id'=>$id]);if($ok===false)wp_die('Unable to archive location. '.$wpdb->last_error);}
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
                else{$ok=$wpdb->delete($table,['employee_id'=>$employee_id],['%d']);if($ok===false)wp_die('Unable to clear employee location. '.$wpdb->last_error);}
            }
            $this->redirect(['page'=>'ews31-multi-locations','employee_saved'=>1]);
        }

    public function admin_multi_locations_v321(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            global $wpdb;
            $this->ews_v321_ensure_locations_table();
            $this->ews_v321_ensure_employee_map();
    
            $edit_id=absint($_GET['edit_location']??0);
            $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->locations} WHERE id=%d",$edit_id)):null;
            $rows=$wpdb->get_results("SELECT * FROM {$this->locations} ORDER BY active DESC,name ASC");
            echo '<div class="wrap"><h1>Work Locations</h1>';
            if(isset($_GET['saved']))echo '<div class="notice notice-success is-dismissible"><p>Location saved successfully.</p></div>';
            if(isset($_GET['archived']))echo '<div class="notice notice-success is-dismissible"><p>Location archived successfully.</p></div>';
            if(isset($_GET['employee_saved']))echo '<div class="notice notice-success is-dismissible"><p>Employee location saved successfully.</p></div>';
    
            $name=$edit?$edit->name:'';$lat=$edit?$edit->latitude:'';$lng=$edit?$edit->longitude:'';
            $radius=$edit?$edit->radius:200;$enf=$edit?(int)$edit->enforcement:0;$active=$edit?(int)$edit->active:1;$is_default=$edit?(int)$edit->is_default:(!$rows?1:0);
            echo '<div style="background:#fff;border:1px solid #dcdcde;padding:20px;max-width:760px"><h2>'.($edit?'Edit Location':'Add Location').'</h2>';
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews_multi_location_save_v321');
            echo '<input type="hidden" name="action" value="ews_multi_location_save_v321">'.($edit?'<input type="hidden" name="location_id" value="'.(int)$edit->id.'">':'');
            echo '<table class="form-table">';
            echo '<tr><th>Location Name</th><td><input class="regular-text" name="location_name" required value="'.esc_attr($name).'"></td></tr>';
            echo '<tr><th>Latitude</th><td><input class="regular-text" type="number" step="0.0000001" name="location_latitude" required value="'.esc_attr($lat).'"></td></tr>';
            echo '<tr><th>Longitude</th><td><input class="regular-text" type="number" step="0.0000001" name="location_longitude" required value="'.esc_attr($lng).'"></td></tr>';
            echo '<tr><th>Allowed Radius</th><td><input type="number" min="10" max="5000" name="location_radius" value="'.esc_attr($radius).'"> meters</td></tr>';
            echo '<tr><th>Seats</th><td><input type="number" min="0" max="100000" name="location_seats" value="'.esc_attr($edit&&$edit->seats?(int)$edit->seats:'').'" placeholder="No limit"><p class="description">How many people this location holds. Reports → Location Capacity warns when the plan reaches 90% and when it goes over. Leave empty for no limit.</p></td></tr>';
            echo '<tr><th>Location Enforcement</th><td><label><input type="checkbox" name="location_enforcement" value="1" '.checked($enf,1,false).'> Require attendance to be inside this location</label></td></tr>';
            echo '<tr><th>Default Location</th><td><label><input type="checkbox" name="location_default" value="1" '.checked($is_default,1,false).'> Use this as the system default location</label><p class="description">The first location added is automatically set as the default. Only one location can be the default.</p></td></tr>';
            echo '<tr><th>Status</th><td><label><input type="checkbox" name="active" value="1" '.checked($active,1,false).'> Active</label></td></tr>';
            echo '</table><p><button class="button button-primary">'.($edit?'Save Changes':'Add Location').'</button> ';
            if($edit)echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-multi-locations')).'">Cancel</a>';
            echo '</p></form></div>';
    
            echo '<h2 style="margin-top:30px">Employees by Location</h2>';
            $map=$wpdb->prefix.'ews_employee_locations_v321';
            $employees=$wpdb->get_results("SELECT e.id,e.name,e.domain_name,m.location_id FROM {$this->employees} e LEFT JOIN {$map} m ON m.employee_id=e.id WHERE e.active=1 ORDER BY e.name ASC");
            $locations=$wpdb->get_results("SELECT id,name FROM {$this->locations} WHERE active=1 ORDER BY name ASC");
            echo '<table class="widefat striped"><thead><tr><th>Employee</th><th>Domain</th><th>Assigned Location</th><th>Action</th></tr></thead><tbody>';
            if(!$employees)echo '<tr><td colspan="4">No active employees configured.</td></tr>';
            foreach($employees as $emp){
                echo '<tr><td>'.esc_html($emp->name).'</td><td>'.esc_html($emp->domain_name).'</td><td>';
                echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
                wp_nonce_field('ews_employee_location_save_v321');
                echo '<input type="hidden" name="action" value="ews_employee_location_save_v321"><input type="hidden" name="employee_id" value="'.(int)$emp->id.'">';
                echo '<select name="location_id"><option value="0">-- No default location --</option>';
                foreach($locations as $loc)echo '<option value="'.(int)$loc->id.'" '.selected((int)$emp->location_id,(int)$loc->id,false).'>'.esc_html($loc->name).'</option>';
                echo '</select> <button class="button button-small">Save</button></form></td><td></td></tr>';
            }
            echo '</tbody></table>';
    
            echo '<h2 style="margin-top:30px">Configured Locations</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Coordinates</th><th>Radius</th><th>Seats</th><th>Enforcement</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
            if(!$rows)echo '<tr><td colspan="7">No locations configured.</td></tr>';
            foreach($rows as $r){
                echo '<tr><td><strong>'.esc_html($r->name).'</strong>'.($r->is_default?'<br><span style="color:#2563eb;font-weight:600">Default</span>':'').'</td><td>'.esc_html($r->latitude.', '.$r->longitude).'</td><td>'.esc_html($r->radius).' m</td><td>'.(!empty($r->seats)?(int)$r->seats.' seats':'No limit').'</td><td>'.($r->enforcement?'Enabled':'Disabled').'</td><td>'.($r->active?'Active':'Archived').'</td><td>';
                echo '<a class="button button-small" href="'.esc_url(admin_url('admin.php?page=ews31-multi-locations&edit_location='.(int)$r->id)).'">Edit</a> ';
                if($r->active){
                    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline" onsubmit="return confirm(\'Archive this location?\');">';
                    wp_nonce_field('ews_multi_location_archive_v321');
                    echo '<input type="hidden" name="action" value="ews_multi_location_archive_v321"><input type="hidden" name="location_id" value="'.(int)$r->id.'"><button class="button button-small">Archive</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

    public function admin_location_save(){
            if(!$this->can('ews_manage_locations')) wp_die('Access denied');
            check_admin_referer('ews31_location_save');
            $enabled=!empty($_POST['location_enforcement'])?1:0;
            $name=sanitize_text_field(wp_unslash($_POST['location_name']??'Office HQ'));
            $lat=sanitize_text_field(wp_unslash($_POST['location_latitude']??''));
            $lng=sanitize_text_field(wp_unslash($_POST['location_longitude']??''));
            $radius=absint($_POST['location_radius']??200);
            if($radius<10)$radius=10;
            if($radius>5000)$radius=5000;
            if($lat!==''&&!is_numeric($lat))$lat='';
            if($lng!==''&&!is_numeric($lng))$lng='';
            if($lat!==''&&((float)$lat<-90||(float)$lat>90))$lat='';
            if($lng!==''&&((float)$lng<-180||(float)$lng>180))$lng='';
            update_option('ews_location_enforcement',$enabled);
            update_option('ews_location_name',$name);
            update_option('ews_location_latitude',$lat);
            update_option('ews_location_longitude',$lng);
            update_option('ews_location_radius',$radius);
            $this->redirect(['page'=>'ews31-location','location_saved'=>1]);
        }

    public function admin_location_settings(){
            if(!$this->can('ews_manage_locations'))wp_die('Access denied');
            $enabled=(int)get_option('ews_location_enforcement',0);
            $name=get_option('ews_location_name','Office HQ');
            $lat=get_option('ews_location_latitude','');
            $lng=get_option('ews_location_longitude','');
            $radius=(int)get_option('ews_location_radius',200);
            echo '<div class="wrap"><h1>Location Settings</h1>';
            if(isset($_GET['location_saved'])){echo '<div class="notice notice-success is-dismissible"><p>Location settings saved.</p></div>';echo '<script>if(window.history&&window.history.replaceState){var u=new URL(window.location.href);u.searchParams.delete("location_saved");window.history.replaceState({},document.title,u.toString());}</script>';}
            echo '<p>Phase 1 records the employee location at Sign In / Sign Out. Location enforcement is optional and is <strong>'.($enabled?'enabled':'disabled').'</strong>.</p>
            <form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
            wp_nonce_field('ews31_location_save');
            echo '<input type="hidden" name="action" value="ews31_location_save">
            <table class="form-table"><tr><th>Enable location enforcement</th><td><label><input type="checkbox" name="location_enforcement" value="1" '.checked($enabled,1,false).'> Require the employee to be inside the configured location when signing in.</label><p class="description">Recommended to keep OFF during Phase 1 while collecting location data.</p></td></tr>
            <tr><th>Location name</th><td><input type="text" class="regular-text" name="location_name" value="'.esc_attr($name).'"></td></tr>
            <tr><th>Latitude</th><td><input type="text" class="regular-text" name="location_latitude" value="'.esc_attr($lat).'" placeholder="e.g. 30.0444"></td></tr>
            <tr><th>Longitude</th><td><input type="text" class="regular-text" name="location_longitude" value="'.esc_attr($lng).'" placeholder="e.g. 31.2357"></td></tr>
            <tr><th>Allowed radius (meters)</th><td><input type="number" min="10" max="5000" name="location_radius" value="'.esc_attr($radius).'"> <p class="description">Used only when enforcement is enabled.</p></td></tr></table>
            <p><button class="button button-primary">Save Location Settings</button></p></form></div>';
        }
}
