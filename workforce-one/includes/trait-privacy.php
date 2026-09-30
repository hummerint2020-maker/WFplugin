<?php
if (!defined('ABSPATH')) exit;

/*
 * Privacy & data retention.
 *
 * - Location retention: after N days, the raw GPS coordinates, accuracy, device
 *   timestamp and IP address are removed from attendance logs. The attendance
 *   record itself (date, time, inside/outside status, distance) is kept.
 * - Face data of inactive employees: biometric templates of archived employees
 *   are deleted by the daily cleanup.
 * - Uninstall: all plugin data is removed only when the administrator opts in
 *   (see uninstall.php).
 */
trait EWS_Privacy_Trait {

    private function privacy_location_retention_days(){
        return max(0,min(3650,(int)get_option('ews_location_retention_days',0)));
    }

    private function privacy_face_delete_inactive(){
        return (int)get_option('ews_face_delete_inactive',1)===1;
    }

    private function privacy_schedule(){
        if(wp_next_scheduled('ews_privacy_cleanup')===false) wp_schedule_event(time()+900,'daily','ews_privacy_cleanup');
    }

    public function privacy_cleanup_cron(){
        global $wpdb;
        $days=$this->privacy_location_retention_days();
        if($days>0){
            $cutoff=gmdate('Y-m-d H:i:s',current_time('timestamp')-$days*DAY_IN_SECONDS);
            // Batched so a large backlog cannot lock the table for long.
            for($i=0;$i<50;$i++){
                $n=$wpdb->query($wpdb->prepare(
                    "UPDATE {$this->time_logs} SET latitude=NULL,longitude=NULL,accuracy=NULL,location_timestamp=NULL,ip_address=NULL
                     WHERE event_at<%s AND (latitude IS NOT NULL OR longitude IS NOT NULL OR ip_address IS NOT NULL) LIMIT 1000",
                    $cutoff
                ));
                if(!$n)break;
            }
        }
        if($this->privacy_face_delete_inactive()){
            $faces=$wpdb->prefix.'ews_face_profiles';
            $ids=$wpdb->get_col("SELECT f.employee_id FROM {$faces} f LEFT JOIN {$this->employees} e ON e.id=f.employee_id WHERE e.id IS NULL OR e.active=0");
            if($ids){
                $requests=get_option('ews_face_reset_requests',array());
                foreach($ids as $id){
                    $id=(int)$id;
                    if($this->face_delete_template($id)){
                        if(is_array($requests))unset($requests[$id]);
                        $this->audit('face_profile_deleted','employee',$id,'Retention policy: employee inactive');
                    }
                }
                if(is_array($requests))update_option('ews_face_reset_requests',$requests,false);
            }
        }
        update_option('ews_privacy_cleanup_last_run',current_time('mysql'),false);
    }

    private function privacy_settings_section(){
        $days=$this->privacy_location_retention_days();
        $face=$this->privacy_face_delete_inactive()?1:0;
        $delete_all=(int)get_option('ews_delete_data_on_uninstall',0);
        $last=get_option('ews_privacy_cleanup_last_run','');
        $choices=[0=>'Keep indefinitely',30=>'30 days',90=>'90 days',180=>'180 days',365=>'1 year',730=>'2 years'];
        if(!isset($choices[$days]))$choices[$days]=$days.' days';
        $html='<div class="wfo-feature-section"><div><div class="wfo-feature-title">Privacy &amp; Data Retention</div><div class="wfo-feature-desc">Attendance collects location, IP address and (when Face Sign In is used) biometric face templates. Limit how long this personal data is kept.</div></div>';
        $html.='<p><label>Remove GPS coordinates and IP address from attendance logs after <select name="location_retention_days">';
        foreach($choices as $v=>$label)$html.='<option value="'.(int)$v.'" '.selected($days,$v,false).'>'.esc_html($label).'</option>';
        $html.='</select></label><br><span class="description">The attendance record, time and inside/outside status are kept.</span></p>';
        $html.='<p><label><input type="checkbox" name="face_delete_inactive" value="1" '.checked($face,1,false).'> Delete face templates of inactive (archived) employees</label></p>';
        $html.='<p><label><input type="checkbox" name="delete_data_on_uninstall" value="1" '.checked($delete_all,1,false).'> <strong>Delete all Workforce One data when the plugin is deleted</strong></label><br><span class="description">Removes all tables, settings, roles and Kiosk keys. This cannot be undone.</span></p>';
        if($last)$html.='<p class="description">Last cleanup run: '.esc_html($last).'</p>';
        $html.='</div>';
        return $html;
    }

    private function privacy_settings_save(){
        $allowed=[0,30,90,180,365,730];
        $days=(int)($_POST['location_retention_days']??0);
        if(!in_array($days,$allowed,true))$days=$this->privacy_location_retention_days();
        update_option('ews_location_retention_days',$days,false);
        update_option('ews_face_delete_inactive',!empty($_POST['face_delete_inactive'])?1:0,false);
        update_option('ews_delete_data_on_uninstall',!empty($_POST['delete_data_on_uninstall'])?1:0,false);
    }
}
