<?php
if (!defined('ABSPATH')) exit;

/**
 * Private files: correction photos, task comment files, daily workers' photos (3.31.91).
 *
 * Every file is served through an action that checks access; none is linked directly. By default the
 * folders are in uploads (so site backups keep them) with .htaccess / web.config rules that refuse a
 * direct link and a random 32-character file name. Nginx ignores .htaccess, so on Nginx a file whose
 * name leaked could be opened directly. Two things cover that:
 *  - define('WFO_PRIVATE_DIR', '/path/outside/the/web/root') in wp-config.php (or the wfo_private_dir
 *    filter): new files are stored there; files saved before are still read from uploads;
 *  - a check, once a week when an administrator opens a Workforce One page (or on "Check again"),
 *    asks the site for a test file by its direct link. When it opens, a warning says how to fix it.
 * Behaviour: tests/e2e_private_files.py.
 */
trait EWS_Private_Files_Trait {

    /** The base folder set for private files ('' = uploads). */
    private function private_files_base(){
        $base=defined('WFO_PRIVATE_DIR')?(string)WFO_PRIVATE_DIR:'';
        $base=(string)apply_filters('wfo_private_dir',$base);
        return $base!==''?untrailingslashit(wp_normalize_path($base)):'';
    }

    /** The uploads folder used before 3.31.91 (and by default). */
    private function private_files_legacy_dir($folder){
        return trailingslashit(wp_normalize_path(wp_upload_dir()['basedir'])).$folder;
    }

    /** Where new files of this kind are stored (created, with its protective files, the first time). */
    private function private_files_dir($folder){
        $base=$this->private_files_base();
        $dir=$base!==''?$base.'/'.$folder:$this->private_files_legacy_dir($folder);
        if(!is_dir($dir)){
            wp_mkdir_p($dir);
            // Refused by URL on Apache and IIS; every file goes through an action that checks access.
            @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/index.php',"<?php\n// Silence is golden.\n"); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            @file_put_contents($dir.'/web.config','<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        }
        return $dir;
    }

    /** A stored file: where it is (the new folder, else the uploads one it was saved in before). */
    private function private_files_path($folder,$key){
        $path=$this->private_files_dir($folder).'/'.$key;
        if(is_file($path) || $this->private_files_base()==='')return $path;
        $old=$this->private_files_legacy_dir($folder).'/'.$key;
        return is_file($old)?$old:$path;
    }

    /** Removes a stored file wherever it is. */
    private function private_files_delete($folder,$key){
        foreach(array_unique([$this->private_files_dir($folder).'/'.$key,$this->private_files_legacy_dir($folder).'/'.$key]) as $p){
            if(is_file($p))wp_delete_file($p);
        }
    }

    /**
     * With WFO_PRIVATE_DIR set, moves the files saved in uploads before into it (on the weekly check and
     * on "Check again"), so none is left where a direct link could open it.
     * @return int files moved
     */
    private function private_files_move_legacy(){
        if($this->private_files_base()==='')return 0;
        $n=0;
        foreach(['workforce-one-corrections','workforce-one-tasks','workforce-one-daily-workers','workforce-one-face-photos'] as $folder){
            $old=$this->private_files_legacy_dir($folder);
            if(!is_dir($old) || is_link($old))continue;
            $new=$this->private_files_dir($folder);
            foreach((array)scandir($old) as $name){
                if(!preg_match('/^[A-Za-z0-9]{32}(_t)?\.[a-z]{2,5}$/',(string)$name))continue;
                if(!is_file($new.'/'.$name) && @rename($old.'/'.$name,$new.'/'.$name))$n++; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename
            }
        }
        return $n;
    }

    /**
     * Can a private file be opened by its direct link? Puts a test file in the folder where new files
     * go and asks the site for it.
     * @return array{at:int,status:string,where:string} status: exposed | protected | outside | unknown
     */
    private function private_files_check(){
        $base=$this->private_files_base();
        $moved=$this->private_files_move_legacy();
        if($moved)$this->audit('private_files_moved','settings',0,$moved.' private file(s) moved to WFO_PRIVATE_DIR');
        $dir=$this->private_files_dir('workforce-one-corrections');
        $root=trailingslashit(wp_normalize_path(ABSPATH));
        $up=wp_upload_dir();
        $updir=trailingslashit(wp_normalize_path($up['basedir']));
        if(strpos(trailingslashit($dir),$updir)===0)$url=trailingslashit($up['baseurl']).substr(trailingslashit($dir),strlen($updir));
        elseif(strpos(trailingslashit($dir),$root)===0)$url=site_url('/'.substr(trailingslashit($dir),strlen($root)));
        else $url='';
        $out=['at'=>time(),'status'=>'outside','where'=>$base!==''?'custom':'uploads'];
        if($url!==''){
            $token=wp_generate_password(24,false);
            $name='wfo-check-'.wp_generate_password(16,false).'.txt';
            if(@file_put_contents($dir.'/'.$name,$token)===false){ // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                $out['status']='unknown';
            }else{
                $r=wp_remote_get(trailingslashit($url).$name,['timeout'=>4,'redirection'=>0,'sslverify'=>false]);
                wp_delete_file($dir.'/'.$name);
                if(is_wp_error($r))$out['status']='unknown';
                else $out['status']=((int)wp_remote_retrieve_response_code($r)===200 && trim((string)wp_remote_retrieve_body($r))===$token)?'exposed':'protected';
            }
        }
        // No answer (a busy or single-process server, a firewall): an earlier warning stays.
        $last=get_option('ews_private_files_check');
        if($out['status']==='unknown' && is_array($last) && ($last['status']??'')==='exposed' && ($last['where']??'')===$out['where'])$out['status']='exposed';
        update_option('ews_private_files_check',$out,false);
        return $out;
    }

    /** Once a week, when an administrator opens a Workforce One page. */
    public function private_files_maybe_check(){
        if(!current_user_can('manage_options'))return;
        $page=sanitize_key($_GET['page']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page is open
        if(strpos($page,'ews31')!==0)return;
        $last=get_option('ews_private_files_check');
        if(is_array($last) && time()-(int)($last['at']??0)<7*DAY_IN_SECONDS)return;
        $this->private_files_check();
    }

    /** "Check again" on the warning. */
    public function private_files_check_now(){
        if(!current_user_can('manage_options'))wp_die(esc_html__('Access denied','workforce-one'));
        check_admin_referer('ews_private_files_check');
        $r=$this->private_files_check();
        wp_safe_redirect(add_query_arg('wfo_files_checked',$r['status'],wp_get_referer()?:admin_url('admin.php?page=ews31')));
        exit;
    }

    /** The warning on Workforce One pages while private files open by direct link. */
    public function private_files_notice(){
        if(!current_user_can('manage_options'))return;
        $page=sanitize_key($_GET['page']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which page is open
        if(strpos($page,'ews31')!==0)return;
        $last=get_option('ews_private_files_check');
        $checked=sanitize_key($_GET['wfo_files_checked']??''); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- message after the check
        if($checked==='unknown'){
            echo '<div class="notice notice-warning is-dismissible" data-private-files-unknown><p>'.esc_html__('Private files could not be checked: the site did not answer. Try again later.','workforce-one').'</p></div>';
            return;
        }
        if($checked!=='' && $checked!=='exposed'){
            echo '<div class="notice notice-success is-dismissible" data-private-files-ok><p>'.esc_html__('Private files checked: they cannot be opened by a direct link.','workforce-one').'</p></div>';
            return;
        }
        if(!is_array($last) || ($last['status']??'')!=='exposed')return;
        $again=wp_nonce_url(admin_url('admin-post.php?action=ews_private_files_check'),'ews_private_files_check');
        $rule='location ~* ^/wp-content/uploads/workforce-one-(corrections|tasks|daily-workers|face-photos)/ { deny all; }';
        echo '<div class="notice notice-error" data-private-files-exposed><p><strong>'.esc_html__('Private files can be opened by a direct link on this server.','workforce-one').'</strong> '
            .esc_html__('Correction photos, task files, daily workers\' photos and Face Sign In photos are protected by .htaccess, which this server ignores (Nginx, for example). Anyone with a file\'s link could open it.','workforce-one').'</p><p>'
            .esc_html__('Fix it in one of two ways, then press "Check again":','workforce-one').'</p><ol><li>'
            .esc_html__('Ask your host to add this rule to the site\'s Nginx configuration:','workforce-one').' <code>'.esc_html($rule).'</code></li><li>'
            /* translators: %s: the line to add */
            .sprintf(esc_html__('Or keep new files outside the website folder: add %s to wp-config.php (a folder the web server does not publish; include it in your backups).','workforce-one'),'<code>define(\'WFO_PRIVATE_DIR\', \'/home/…/workforce-one-private\');</code>')
            .'</li></ol><p><a class="button" href="'.esc_url($again).'">'.esc_html__('Check again','workforce-one').'</a></p></div>';
    }
}
