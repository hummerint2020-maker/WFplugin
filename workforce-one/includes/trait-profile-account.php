<?php
if (!defined('ABSPATH')) exit;

/**
 * The signed-in employee's account: avatar catalogue, password change, profile photo, and the
 * employee profile settings.
 */
trait EWS_Profile_Account_Trait {


    private function profile_avatar_catalog(){
        return [
            'man-beard'=>['label'=>'Professional Beard','category'=>'Men','style'=>'Professional'],
            'man-short'=>['label'=>'Short Hair','category'=>'Men','style'=>'Casual'],
            'man-glasses'=>['label'=>'Glasses','category'=>'Men','style'=>'Professional'],
            'man-curly'=>['label'=>'Curly Hair','category'=>'Men','style'=>'Casual'],
            'man-hoodie'=>['label'=>'Hoodie','category'=>'Men','style'=>'Casual'],
            'man-cap'=>['label'=>'Cap','category'=>'Men','style'=>'Casual'],
            'man-tie'=>['label'=>'Business','category'=>'Men','style'=>'Professional'],
            'man-headphones'=>['label'=>'Headphones','category'=>'Men','style'=>'Fun'],
            'woman-long'=>['label'=>'Long Hair','category'=>'Women','style'=>'Professional'],
            'woman-glasses'=>['label'=>'Glasses','category'=>'Women','style'=>'Professional'],
            'woman-hijab'=>['label'=>'Hijab','category'=>'Women','style'=>'Professional'],
            'woman-curly'=>['label'=>'Curly Hair','category'=>'Women','style'=>'Casual'],
            'woman-bob'=>['label'=>'Bob Hair','category'=>'Women','style'=>'Casual'],
            'woman-cap'=>['label'=>'Cap','category'=>'Women','style'=>'Casual'],
            'woman-smile'=>['label'=>'Smile','category'=>'Women','style'=>'Casual'],
            'woman-headphones'=>['label'=>'Headphones','category'=>'Women','style'=>'Fun'],
            'cat'=>['label'=>'Cat','category'=>'Fun','style'=>'Fun'],
            'dog'=>['label'=>'Dog','category'=>'Fun','style'=>'Fun'],
            'panda'=>['label'=>'Panda','category'=>'Fun','style'=>'Fun'],
            'robot'=>['label'=>'Robot','category'=>'Fun','style'=>'Fun'],
            'ninja'=>['label'=>'Ninja','category'=>'Fun','style'=>'Fun'],
            'astronaut'=>['label'=>'Astronaut','category'=>'Fun','style'=>'Fun'],
            'gamer'=>['label'=>'Gamer','category'=>'Fun','style'=>'Casual'],
            'traveler'=>['label'=>'Traveler','category'=>'Fun','style'=>'Casual'],
        ];
    }
    private function profile_avatar_url($key){
        $key=sanitize_key($key); $catalog=$this->profile_avatar_catalog();
        if(!isset($catalog[$key]))return '';
        return plugins_url('assets/avatars/'.$key.'.svg',dirname(__DIR__).'/employee-schedule-manager.php');
    }

    public function profile_password_change(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'ews_profile_password_change'))wp_die('Security check failed.');
            $emp=$this->current_employee();
            if(!$emp)wp_die('Employee account not found.');
            $user_id=get_current_user_id();
            $user=get_user_by('id',$user_id);
            if(!$user)wp_die('User account not found.');
            $current=(string)wp_unslash($_POST['current_password']??'');
            $new=(string)wp_unslash($_POST['new_password']??'');
            $confirm=(string)wp_unslash($_POST['confirm_password']??'');
            $redirect=remove_query_arg(['password_updated','password_error'],wp_get_referer()?:home_url('/'));
            $redirect=add_query_arg('ews_view','profile',$redirect);
            if(!wp_check_password($current,$user->user_pass,$user_id)){
                wp_safe_redirect(add_query_arg('password_error','current',$redirect));exit;
            }
            if($new!==$confirm){
                wp_safe_redirect(add_query_arg('password_error','mismatch',$redirect));exit;
            }
            $strong=(strlen($new)>=8 && preg_match('/[A-Z]/',$new) && preg_match('/[a-z]/',$new) && preg_match('/\d/',$new) && preg_match('/[^A-Za-z0-9]/',$new));
            if(!$strong){
                wp_safe_redirect(add_query_arg('password_error','weak',$redirect));exit;
            }
            if(wp_check_password($new,$user->user_pass,$user_id)){
                wp_safe_redirect(add_query_arg('password_error','same',$redirect));exit;
            }
            $updated=wp_update_user(['ID'=>$user_id,'user_pass'=>$new]);
            if(is_wp_error($updated)){
                wp_safe_redirect(add_query_arg('password_error','update',$redirect));exit;
            }
            $this->audit('profile_password_change','employee',(int)$emp->id,$emp->name.' changed account password');
            wp_safe_redirect(add_query_arg('password_updated','1',$redirect));exit;
        }

    public function profile_photo_save(){
            if(!is_user_logged_in())wp_die('You must be logged in.');
            if(!isset($_POST['_wpnonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])),'ews_profile_photo_save'))wp_die('Security check failed.');
            $emp=$this->current_employee();
            if(!$emp)wp_die('Employee account not found.');
            global $wpdb;
            $table=$this->employees;
            $action=sanitize_key($_POST['profile_photo_action']??'upload');
            $old_url=(string)($emp->profile_image_url??'');
            if($action==='avatar') {
                $key=sanitize_key($_POST['avatar_key']??''); $catalog=$this->profile_avatar_catalog();
                if(!isset($catalog[$key])) wp_die('Invalid avatar selection.');
                $url=$this->profile_avatar_url($key);
                $ok=$wpdb->update($table,['profile_image_type'=>'avatar','profile_image_url'=>$url,'avatar_key'=>$key],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
                if($ok===false) wp_die('Could not save the avatar.');
                $this->delete_employee_profile_image_file($old_url);
                $this->audit('profile_avatar_update','employee',(int)$emp->id,$emp->name.' selected avatar '.$key);
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'avatar'],$redirect)); exit;
            }
            if($action==='reset'){
                $ok=$wpdb->update($table,['profile_image_type'=>'initials','profile_image_url'=>'','avatar_key'=>null],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
                if($ok===false)wp_die('Could not reset the profile picture.');
                $this->delete_employee_profile_image_file($old_url);
                $this->audit('profile_photo_reset','employee',(int)$emp->id,$emp->name.' reset profile picture');
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'reset'],$redirect));exit;
            }
            if(empty($_FILES['profile_photo'])||!empty($_FILES['profile_photo']['error'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
            }
            $file=$_FILES['profile_photo'];
            if((int)$file['size']>2*1024*1024){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'size'],$redirect));exit;
            }
            $check=wp_check_filetype_and_ext($file['tmp_name'],$file['name'],['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']);
            if(empty($check['type'])||empty($check['ext'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'type'],$redirect));exit;
            }
            $img=@getimagesize($file['tmp_name']);
            if(!$img||empty($img['mime'])||!in_array($img['mime'],['image/jpeg','image/png','image/webp'],true)){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'type'],$redirect));exit;
            }
            require_once ABSPATH.'wp-admin/includes/file.php';
            $overrides=['test_form'=>false,'mimes'=>['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp']];
            $uploaded=wp_handle_upload($file,$overrides);
            if(isset($uploaded['error'])||empty($uploaded['url'])){
                $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
            }
            $editor=wp_get_image_editor($uploaded['file']);
            if(!is_wp_error($editor)){
                $size=$editor->get_size();
                if(!empty($size['width'])&&!empty($size['height'])&&($size['width']>512||$size['height']>512)){
                    $editor->resize(512,512,false);
                    $saved=$editor->save($uploaded['file']);
                    if(is_wp_error($saved)){
                        @unlink($uploaded['file']);
                        $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
                wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_error'=>'upload'],$redirect));exit;
                    }
                }
            }
            $url=esc_url_raw($uploaded['url']);
            $ok=$wpdb->update($table,['profile_image_type'=>'photo','profile_image_url'=>$url,'avatar_key'=>null],['id'=>(int)$emp->id],['%s','%s','%s'],['%d']);
            if($ok===false){
                $this->delete_employee_profile_image_file($url);
                wp_die('Could not save the profile picture.');
            }
            $this->delete_employee_profile_image_file($old_url);
            $this->audit('profile_photo_update','employee',(int)$emp->id,$emp->name.' updated profile picture');
            $redirect=remove_query_arg(['profile_updated','profile_error'],wp_get_referer()?:home_url('/'));
            wp_safe_redirect(add_query_arg(['ews_view'=>'profile','profile_updated'=>'photo'],$redirect));exit;
        }

        private function delete_employee_profile_image_file($url){
            $url=trim((string)$url); if($url==='')return;
            $uploads=wp_upload_dir();
            if(empty($uploads['baseurl'])||empty($uploads['basedir']))return;
            $base=rtrim((string)$uploads['baseurl'],'/');
            if(strpos($url,$base.'/')!==0)return;
            $relative=ltrim(substr($url,strlen($base)),'/');
            if($relative==='')return;
            $path=wp_normalize_path(trailingslashit($uploads['basedir']).$relative);
            $root=wp_normalize_path(trailingslashit($uploads['basedir']));
            if(strpos($path,$root)!==0)return;
            if(is_file($path))@unlink($path);
        }

    private function employee_profile_settings(){
            $defaults=[
                'enabled'=>1,
                'show_photo'=>1,
                'show_name'=>1,
                'show_team'=>1,
                'show_email'=>0,
                'show_supervisor'=>0,
                'show_achievements'=>1,
                'show_recognition'=>0,
            ];
            $cfg=get_option('ews_employee_profile_settings',[]);
            if(!is_array($cfg))$cfg=[];
            foreach($defaults as $key=>$value){
                if(!array_key_exists($key,$cfg))$cfg[$key]=$value;
                $cfg[$key]=!empty($cfg[$key])?1:0;
            }
            return array_merge($defaults,$cfg);
        }
}
