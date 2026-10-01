<?php
global $wpdb; $p=$wpdb->prefix;
$uid=username_exists('emp1')?:wp_create_user('emp1','emp1pass','emp1@example.com');
$wpdb->query("DELETE FROM {$p}ews_employees"); $wpdb->query("DELETE FROM {$p}ews_schedule"); $wpdb->query("DELETE FROM {$p}ews_time_logs"); $wpdb->query("DELETE FROM {$p}ews_locations"); $wpdb->query("DELETE FROM {$p}ews_kiosks"); $wpdb->query("DELETE FROM {$p}ews_employee_locations_v321"); $wpdb->query("DELETE FROM {$p}ews_face_profiles"); delete_option('ews_face_reset_requests');
$wpdb->insert("{$p}ews_employees",['name'=>'Emp One','domain_name'=>'emp1','email'=>'emp1@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1]);
$eid=$wpdb->insert_id;
$wpdb->insert("{$p}ews_schedule",['employee_id'=>$eid,'work_date'=>current_time('Y-m-d'),'status'=>'Office']);
$wpdb->insert("{$p}ews_locations",['name'=>'Cairo HQ','latitude'=>30.0444,'longitude'=>31.2357,'radius'=>200,'enforcement'=>0,'is_default'=>1,'active'=>1]);
$lid=$wpdb->insert_id;
$h=(int)current_time('G'); update_option('ews_working_hours',['start'=>sprintf('%02d:00',max(0,$h-1)),'end'=>'23:59'],false);
update_option('ews_working_days',[0,1,2,3,4,5,6],false);
update_option('ews_presence_qr_signin',1,false); update_option('ews_feature_face_signin',1,false);
$secret=bin2hex(random_bytes(32));
$wpdb->insert("{$p}ews_kiosks",['location_id'=>$lid,'name'=>'Reception','status'=>'active','credential_hash'=>hash('sha256',$secret),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
$kid=$wpdb->insert_id; update_option('ews_presence_kiosk_key_'.$kid,$secret,false);
$pid=wp_insert_post(['post_title'=>'App','post_name'=>'app','post_content'=>'[employee_app]','post_status'=>'publish','post_type'=>'page']);
file_put_contents((getenv('S')?:sys_get_temp_dir()).'/ids.json',json_encode(compact('uid','eid','lid','kid','secret','pid')));
echo "ok ".json_encode(compact('eid','kid','pid'));
