<?php
if (!defined('ABSPATH')) exit;

/**
 * Face Sign In: settings, encrypted face templates per employee and one-time verification tokens.
 */
trait EWS_Face_Trait {


private function face_signin_enabled(){ return (bool)get_option('ews_feature_face_signin',false); }

    private function face_signin_settings(){
        $saved=get_option('ews_face_signin_settings',array());
        return wp_parse_args(is_array($saved)?$saved:array(),\WorkforceOne\Settings\FeatureSettings::FACE_DEFAULTS);
    }

    private function face_signin_setting($key,$fallback=null){
        $s=$this->face_signin_settings();
        return array_key_exists($key,$s)?$s[$key]:$fallback;
    }

    private function face_biometric_key(){
        $salt = defined('AUTH_SALT') && AUTH_SALT ? AUTH_SALT : wp_salt('auth');
        return hash('sha256', $salt.'|workforce-one|face-biometric-v1', true);
    }

    private function face_encrypt_template($template){
        if(!is_array($template) || !$template || !function_exists('openssl_encrypt')) return false;
        $json=wp_json_encode(array_values(array_map('floatval',$template)));
        if($json===false) return false;
        try{$iv=random_bytes(16);}catch(Throwable $e){return false;}
        $cipher=openssl_encrypt($json,'AES-256-CBC',$this->face_biometric_key(),OPENSSL_RAW_DATA,$iv);
        return $cipher===false?false:base64_encode($iv.$cipher);
    }

    private function face_decrypt_template($payload){
        if(!$payload || !function_exists('openssl_decrypt')) return false;
        $raw=base64_decode($payload,true);
        if($raw===false || strlen($raw)<17) return false;
        $json=openssl_decrypt(substr($raw,16),'AES-256-CBC',$this->face_biometric_key(),OPENSSL_RAW_DATA,substr($raw,0,16));
        if($json===false) return false;
        $data=json_decode($json,true);
        return is_array($data)?array_values(array_map('floatval',$data)):false;
    }

    /* Face matching happens in /face/verify; a successful match issues a short-lived,
       single-use token bound to the current user. Sign In trusts that token only,
       never a client-supplied "face_verified" flag. */
    private function face_issue_token(){
        $uid=get_current_user_id();
        if(!$uid)return '';
        $token=wp_generate_password(40,false,false);
        set_transient('ews_face_token_'.$uid,hash('sha256',$token),120);
        return $token;
    }

    private function face_consume_token($token){
        $uid=get_current_user_id();
        $token=is_string($token)?trim($token):'';
        if(!$uid||$token==='')return false;
        $stored=get_transient('ews_face_token_'.$uid);
        if(!is_string($stored)||$stored==='')return false;
        if(!hash_equals($stored,hash('sha256',$token)))return false;
        delete_transient('ews_face_token_'.$uid);
        return true;
    }

    private function face_template_for_employee($employee_id){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id) return false;
        $table=$wpdb->prefix.'ews_face_profiles';
        $payload=$wpdb->get_var($wpdb->prepare(
            "SELECT face_template FROM {$table} WHERE employee_id=%d AND status='active' LIMIT 1",
            $employee_id
        ));
        return $payload?$this->face_decrypt_template($payload):false;
    }

    private function face_save_template($employee_id,$template,$model_version='face-api-0.22.2'){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id || !is_array($template) || count($template)<64) return false;
        $encrypted=$this->face_encrypt_template($template);
        if($encrypted===false) return false;
        $table=$wpdb->prefix.'ews_face_profiles';
        $now=current_time('mysql');
        $data=array(
            'employee_id'=>$employee_id,
            'face_template'=>$encrypted,
            'model_version'=>sanitize_text_field($model_version),
            'status'=>'active',
            'updated_at'=>$now
        );
        $existing=$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE employee_id=%d LIMIT 1",$employee_id
        ));
        if($existing){
            $ok=$wpdb->update($table,$data,array('employee_id'=>$employee_id));
        }else{
            $data['enrolled_at']=$now;
            $ok=$wpdb->insert($table,$data);
        }
        return $ok!==false;
    }

    private function face_delete_template($employee_id){
        global $wpdb;
        $employee_id=absint($employee_id);
        if(!$employee_id) return false;
        return $wpdb->delete(
            $wpdb->prefix.'ews_face_profiles',
            array('employee_id'=>$employee_id),
            array('%d')
        )!==false;
    }
}
