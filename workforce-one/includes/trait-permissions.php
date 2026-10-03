<?php
if (!defined('ABSPATH')) exit;

/**
 * Permissions: the plugin's capabilities and roles, can() and wp-admin access.
 */
trait EWS_Permissions_Trait {


    private function permission_defs(){
            return [
                'ews_view_dashboard'=>'View Dashboard',
                'ews_view_people'=>'View People',
                'ews_manage_employees'=>'Manage Employees',
                'ews_manage_schedule'=>'Manage Weekly Schedule',
                'ews_manage_attendance'=>'Manage Attendance',
                'ews_view_reports'=>'View Reports',
                'ews_manage_time'=>'Manage Sign In / Out',
                'ews_manage_auto_attendance'=>'Manage Auto Attendance',
                'ews_view_audit_log'=>'View Audit Log',
                'ews_manage_locations'=>'Manage Locations',
                'ews_manage_settings'=>'Manage Settings',
                'ews_manage_tasks'=>'Manage Tasks',
                'ews_manage_roles'=>'Manage Roles & Permissions',
                'ews_manage_payroll'=>'Manage Payroll',
            ];
        }

    private function role_defs(){
            return [
                // Salaries: only WordPress administrators until a role is given "Manage Payroll" on the Roles page.
                'ews_administrator'=>['name'=>'EWS Administrator','caps'=>array_values(array_diff(array_keys($this->permission_defs()),['ews_manage_payroll']))],
                'ews_manager'=>['name'=>'EWS Manager','caps'=>['ews_view_dashboard','ews_view_people','ews_manage_employees','ews_manage_schedule','ews_manage_attendance','ews_view_reports','ews_manage_time','ews_manage_auto_attendance','ews_view_audit_log','ews_manage_locations','ews_manage_settings','ews_manage_tasks']],
                'ews_supervisor'=>['name'=>'EWS Supervisor','caps'=>['ews_view_dashboard','ews_view_people','ews_manage_schedule','ews_manage_attendance','ews_view_reports','ews_manage_time']],
                'ews_employee'=>['name'=>'EWS Employee','caps'=>['ews_view_people']],
            ];
        }

    private function can($cap){
            return current_user_can('manage_options') || current_user_can($cap);
        }

    private function has_ews_admin_access(){
            if(!is_user_logged_in())return false;
            if(current_user_can('manage_options'))return true;
            foreach(array_keys($this->permission_defs()) as $cap){
                if(current_user_can($cap))return true;
            }
            return false;
        }
}
