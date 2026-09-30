<?php
if (!defined('ABSPATH')) exit;

trait EWS_Polls_Trait {

    private function ensure_polls_schema(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();
        $polls=$wpdb->prefix.'ews_polls';
        $options=$wpdb->prefix.'ews_poll_options';
        $votes=$wpdb->prefix.'ews_poll_votes';

        dbDelta("CREATE TABLE {$polls} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question TEXT NOT NULL,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            show_homepage TINYINT(1) NOT NULL DEFAULT 1,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY(id),
            KEY status(status),
            KEY homepage(show_homepage,status),
            KEY dates(starts_at,ends_at)
        ) {$c};");

        dbDelta("CREATE TABLE {$options} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_text VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY(id),
            KEY poll_order(poll_id,sort_order),
            KEY poll_id(poll_id)
        ) {$c};");

        dbDelta("CREATE TABLE {$votes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id BIGINT UNSIGNED NOT NULL,
            option_id BIGINT UNSIGNED NOT NULL,
            employee_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            voted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY employee_poll(poll_id,employee_id),
            KEY poll_option(poll_id,option_id),
            KEY employee_id(employee_id)
        ) {$c};");
    }

    private function polls_table_names(){
        global $wpdb;
        return [
            'polls'=>$wpdb->prefix.'ews_polls',
            'options'=>$wpdb->prefix.'ews_poll_options',
            'votes'=>$wpdb->prefix.'ews_poll_votes',
        ];
    }

    private function polls_now(){
        return current_time('mysql');
    }

    private function poll_is_visible($poll){
        if(!$poll || (string)$poll->status!=='active') return false;
        $now=current_time('timestamp');
        if(!empty($poll->starts_at) && strtotime($poll->starts_at)>$now) return false;
        if(!empty($poll->ends_at) && strtotime($poll->ends_at)<$now) return false;
        return true;
    }

    private function employee_has_poll_vote($poll_id,$employee_id){
        global $wpdb; $t=$this->polls_table_names();
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['votes']} WHERE poll_id=%d AND employee_id=%d LIMIT 1",$poll_id,$employee_id));
    }

    private function poll_results($poll_id){
        global $wpdb; $t=$this->polls_table_names();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT o.id,o.option_text,o.sort_order,COUNT(v.id) votes FROM {$t['options']} o LEFT JOIN {$t['votes']} v ON v.option_id=o.id AND v.poll_id=o.poll_id WHERE o.poll_id=%d GROUP BY o.id,o.option_text,o.sort_order ORDER BY o.sort_order ASC,o.id ASC",$poll_id));
        $total=0; foreach($rows as $r)$total+=(int)$r->votes;
        foreach($rows as $r)$r->percentage=$total>0?round(((int)$r->votes/$total)*100):0;
        return ['rows'=>$rows,'total'=>$total];
    }

    private function current_employee_poll_context($poll){
        $emp=$this->current_employee();
        if(!$emp || !$poll || !$this->poll_is_visible($poll)) return null;
        $t=$this->polls_table_names(); global $wpdb;
        $options=$wpdb->get_results($wpdb->prepare("SELECT id,option_text,sort_order FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order ASC,id ASC",(int)$poll->id));
        if(!$options) return null;
        $voted=$this->employee_has_poll_vote((int)$poll->id,(int)$emp->id);
        return ['employee'=>$emp,'options'=>$options,'voted'=>$voted,'results'=>$voted?$this->poll_results((int)$poll->id):null];
    }

    private function employee_poll_markup(){
        global $wpdb; $t=$this->polls_table_names();
        if(!$this->current_employee()) return '';
        $poll=$wpdb->get_row("SELECT * FROM {$t['polls']} WHERE status='active' AND show_homepage=1 ORDER BY id DESC LIMIT 1");
        if(!$poll || !$this->poll_is_visible($poll)) return '';
        $ctx=$this->current_employee_poll_context($poll); if(!$ctx)return '';
        $notice='';
        if(isset($_GET['poll_voted']) && absint($_GET['poll_voted'])===(int)$poll->id)$notice='Thanks for voting!';
        $action=admin_url('admin-post.php');
        ob_start(); ?>
        <section class="ews-poll-card">
            <div class="ews-poll-head">
                <div><span class="ews-poll-kicker">EMPLOYEE POLL</span><h3><?php echo esc_html($poll->question); ?></h3><?php if(!empty($poll->description)): ?><p><?php echo esc_html($poll->description); ?></p><?php endif; ?></div>
                <span class="ews-poll-icon">🗳️</span>
            </div>
            <?php if($notice): ?><div class="ews-poll-success">✓ <?php echo esc_html($notice); ?></div><?php endif; ?>
            <?php if($ctx['voted']): ?>
                <div class="ews-poll-voted">Your vote has been recorded. Here’s the current result.</div>
                <div class="ews-poll-results">
                    <?php foreach($ctx['results']['rows'] as $r): ?><div class="ews-poll-result"><div class="ews-poll-result-top"><span><?php echo esc_html($r->option_text); ?></span><b><?php echo (int)$r->percentage; ?>%</b></div><div class="ews-poll-bar"><i style="width:<?php echo (int)$r->percentage; ?>%"></i></div><small><?php echo (int)$r->votes; ?> vote<?php echo ((int)$r->votes===1?'':'s'); ?></small></div><?php endforeach; ?>
                </div>
                <div class="ews-poll-total"><?php echo (int)$ctx['results']['total']; ?> people have voted</div>
            <?php else: ?>
                <form method="post" action="<?php echo esc_url($action); ?>" class="ews-poll-form">
                    <?php wp_nonce_field('ews_poll_vote_'.$poll->id,'ews_poll_nonce'); ?><input type="hidden" name="action" value="ews_poll_vote"><input type="hidden" name="poll_id" value="<?php echo (int)$poll->id; ?>">
                    <div class="ews-poll-options">
                        <?php foreach($ctx['options'] as $i=>$o): ?><label class="ews-poll-option"><input type="radio" name="option_id" value="<?php echo (int)$o->id; ?>" <?php checked($i,0); ?>><span class="ews-poll-radio"></span><span><?php echo esc_html($o->option_text); ?></span></label><?php endforeach; ?>
                    </div>
                    <div class="ews-poll-footer"><span>Be part of the decision ❤️</span><button class="ews-btn" type="submit">Vote</button></div>
                </form>
            <?php endif; ?>
        </section>
        <?php return ob_get_clean();
    }

    public function admin_polls(){
        if(!$this->can('ews_manage_settings')) wp_die('Access denied.');
        global $wpdb; $this->ensure_polls_schema(); $t=$this->polls_table_names();
        $polls=$wpdb->get_results("SELECT * FROM {$t['polls']} ORDER BY id DESC");
        $edit_id=absint($_GET['poll_id']??0); $edit=$edit_id?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$edit_id)):null;
        $edit_options=$edit?$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order,id",$edit_id)):[];
        echo '<div class="wrap"><h1>Employee Polls</h1>';
        if(isset($_GET['poll_saved']))echo '<div class="notice notice-success is-dismissible"><p>Poll saved successfully.</p></div>';
        if(isset($_GET['poll_status']))echo '<div class="notice notice-success is-dismissible"><p>Poll visibility/status updated.</p></div>';
        if(isset($_GET['poll_deleted']))echo '<div class="notice notice-success is-dismissible"><p>Poll archived.</p></div>';
        if(isset($_GET['poll_error']))echo '<div class="notice notice-error is-dismissible"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['poll_error']))).'</p></div>';
        echo '<style>.wfo-poll-admin{max-width:1180px}.wfo-poll-admin .card{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:22px;margin:16px 0;box-shadow:0 2px 8px rgba(16,24,40,.04)}.wfo-poll-grid{display:grid;grid-template-columns:2fr 1fr;gap:16px}.wfo-poll-admin label{font-weight:600;display:block;margin-bottom:6px}.wfo-poll-admin input[type=text],.wfo-poll-admin textarea,.wfo-poll-admin input[type=datetime-local]{width:100%;box-sizing:border-box}.wfo-poll-option-row{display:flex;gap:8px;margin:8px 0}.wfo-poll-option-row input{flex:1}.wfo-poll-admin table{width:100%;border-collapse:collapse}.wfo-poll-admin th,.wfo-poll-admin td{padding:11px 9px;border-bottom:1px solid #eef0f3;text-align:left;vertical-align:top}.wfo-poll-pill{display:inline-block;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:700}.wfo-poll-active{background:#ecfdf3;color:#15803d}.wfo-poll-inactive{background:#f2f4f7;color:#667085}.wfo-poll-home{background:#eef4ff;color:#3158c8}.wfo-poll-resultbar{height:8px;background:#eef2f6;border-radius:99px;overflow:hidden;min-width:120px}.wfo-poll-resultbar i{display:block;height:100%;background:#3158c8;border-radius:99px}.wfo-poll-muted{color:#667085;font-size:12px}.wfo-poll-actions{display:flex;gap:7px;flex-wrap:wrap}.wfo-poll-add{margin-top:8px}.wfo-poll-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0}.wfo-poll-stat{background:#f8fafc;border:1px solid #eaecf0;border-radius:10px;padding:12px}.wfo-poll-stat b{display:block;font-size:22px}.wfo-poll-stat span{color:#667085;font-size:12px}@media(max-width:800px){.wfo-poll-grid{grid-template-columns:1fr}.wfo-poll-summary{grid-template-columns:1fr}}</style>';
        echo '<div class="wfo-poll-admin">';
        echo '<div class="card"><h2>'.($edit?'Edit Poll':'Create New Poll').'</h2><p class="wfo-poll-muted">Create a question, add choices, then control whether it is active and visible on the employee homepage.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('ews_poll_save','ews_poll_nonce',true,false).'<input type="hidden" name="action" value="ews_poll_save"><input type="hidden" name="poll_id" value="'.($edit?(int)$edit->id:0).'">';
        echo '<div class="wfo-poll-grid"><div><label>Question</label><input type="text" name="question" required maxlength="500" value="'.esc_attr($edit->question??'').'" placeholder="What should we choose for Thursday?"><label style="margin-top:14px">Description <span class="wfo-poll-muted">(optional)</span></label><textarea name="description" rows="3" maxlength="1000" placeholder="Let’s make it a great day!"></textarea>';
        if($edit)echo '<script>document.addEventListener("DOMContentLoaded",function(){var x=document.querySelector("textarea[name=description]");if(x)x.value='.wp_json_encode((string)$edit->description).';});</script>';
        echo '<label style="margin-top:14px">Choices</label><div id="wfo-poll-options">';
        $option_values=[]; foreach($edit_options as $o)$option_values[]=$o->option_text; if(!$option_values)$option_values=['',''];
        foreach($option_values as $v)echo '<div class="wfo-poll-option-row"><input type="text" name="options[]" maxlength="255" required value="'.esc_attr($v).'" placeholder="Choice"><button type="button" class="button wfo-poll-remove">Remove</button></div>';
        echo '</div><button type="button" class="button wfo-poll-add" id="wfo-poll-add">+ Add choice</button></div><div><label>Status</label><label style="font-weight:400"><input type="checkbox" name="active" value="1" '.checked(!$edit||$edit->status==='active',true,false).'> Active</label><label style="font-weight:600;margin-top:16px">Show on employee homepage</label><label style="font-weight:400"><input type="checkbox" name="show_homepage" value="1" '.checked(!$edit||!empty($edit->show_homepage),true,false).'> Visible</label><label style="margin-top:16px">Start <span class="wfo-poll-muted">(optional)</span></label><input type="datetime-local" name="starts_at" value="'.esc_attr($edit&&!empty($edit->starts_at)?date('Y-m-d\TH:i',strtotime($edit->starts_at)):'').'"> <label style="margin-top:12px">End <span class="wfo-poll-muted">(optional)</span></label><input type="datetime-local" name="ends_at" value="'.esc_attr($edit&&!empty($edit->ends_at)?date('Y-m-d\TH:i',strtotime($edit->ends_at)):'').'">';
        echo '</div></div><p style="margin-bottom:0"><button class="button button-primary" type="submit">'.($edit?'Save Changes':'Create Poll').'</button>'.($edit?' <a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-polls')).'">Cancel</a>':'').'</p></form></div>';
        echo '<div class="card"><h2>Polls</h2><p class="wfo-poll-muted">The newest homepage-visible active poll is shown to employees.</p><table><thead><tr><th>Poll</th><th>Status</th><th>Homepage</th><th>Results</th><th>Actions</th></tr></thead><tbody>';
        if(!$polls)echo '<tr><td colspan="5">No polls created yet.</td></tr>';
        foreach($polls as $p){$res=$this->poll_results((int)$p->id);$toggle=wp_nonce_url(admin_url('admin-post.php?action=ews_poll_toggle&poll_id='.(int)$p->id),'ews_poll_toggle_'.$p->id,'ews_poll_nonce');$archive=wp_nonce_url(admin_url('admin-post.php?action=ews_poll_archive&poll_id='.(int)$p->id),'ews_poll_archive_'.$p->id,'ews_poll_nonce');$home=wp_nonce_url(admin_url('admin-post.php?action=ews_poll_homepage&poll_id='.(int)$p->id),'ews_poll_homepage_'.$p->id,'ews_poll_nonce');
            echo '<tr><td><strong>'.esc_html($p->question).'</strong>'.(!empty($p->description)?'<br><span class="wfo-poll-muted">'.esc_html($p->description).'</span>':'').'<br><span class="wfo-poll-muted">Created '.esc_html(date_i18n('d M Y',strtotime($p->created_at))).'</span></td><td><span class="wfo-poll-pill '.($p->status==='active'?'wfo-poll-active':'wfo-poll-inactive').'">'.($p->status==='active'?'Active':'Hidden').'</span></td><td>'.(!empty($p->show_homepage)?'<span class="wfo-poll-pill wfo-poll-home">Shown</span>':'<span class="wfo-poll-pill wfo-poll-inactive">Hidden</span>').'</td><td><strong>'.(int)$res['total'].'</strong> votes';
            foreach($res['rows'] as $r)echo '<div style="margin-top:7px"><div style="display:flex;justify-content:space-between;font-size:11px"><span>'.esc_html($r->option_text).'</span><b>'.(int)$r->percentage.'%</b></div><div class="wfo-poll-resultbar"><i style="width:'.(int)$r->percentage.'%"></i></div></div>';
            echo '</td><td><div class="wfo-poll-actions"><a class="button" href="'.esc_url(admin_url('admin.php?page=ews31-polls&poll_id='.(int)$p->id)).'">Edit</a><a class="button" href="'.esc_url($toggle).'">'.($p->status==='active'?'Hide':'Activate').'</a><a class="button" href="'.esc_url($home).'">'.(!empty($p->show_homepage)?'Hide from Home':'Show on Home').'</a>'.($p->status==='active'?'<a class="button" href="'.esc_url($archive).'" onclick="return confirm(\'Archive this poll? Votes will be kept.\');">Archive</a>':'').'</div></td></tr>';
        }
        echo '</tbody></table></div></div><script>(function(){var box=document.getElementById("wfo-poll-options"),add=document.getElementById("wfo-poll-add");if(!box||!add)return;add.addEventListener("click",function(){var row=document.createElement("div");row.className="wfo-poll-option-row";row.innerHTML="<input type=\"text\" name=\"options[]\" maxlength=\"255\" required placeholder=\"Choice\"><button type=\"button\" class=\"button wfo-poll-remove\">Remove</button>";box.appendChild(row);});box.addEventListener("click",function(e){if(e.target.classList.contains("wfo-poll-remove")){var rows=box.querySelectorAll(".wfo-poll-option-row");if(rows.length>2)e.target.parentNode.remove();}});})();</script>';
    }

    public function poll_save(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');
        check_admin_referer('ews_poll_save','ews_poll_nonce');
        global $wpdb; $this->ensure_polls_schema(); $t=$this->polls_table_names();
        $id=absint($_POST['poll_id']??0); $question=sanitize_text_field(wp_unslash($_POST['question']??'')); $description=sanitize_textarea_field(wp_unslash($_POST['description']??''));
        $raw=isset($_POST['options'])&&is_array($_POST['options'])?$_POST['options']:[]; $options=[]; foreach($raw as $v){$v=sanitize_text_field(wp_unslash($v));if($v!=='')$options[]=$v;}
        if($question===''||count($options)<2){wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_error='.rawurlencode('A poll needs a question and at least two choices.')));exit;}
        $starts=sanitize_text_field(wp_unslash($_POST['starts_at']??''));$ends=sanitize_text_field(wp_unslash($_POST['ends_at']??''));
        $starts=$starts?gmdate('Y-m-d H:i:s',strtotime($starts)):null;$ends=$ends?gmdate('Y-m-d H:i:s',strtotime($ends)):null;
        if($starts&&$ends&&strtotime($ends)<strtotime($starts)){wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_error='.rawurlencode('End time cannot be before start time.')));exit;}
        $data=['question'=>$question,'description'=>$description,'status'=>!empty($_POST['active'])?'active':'inactive','show_homepage'=>!empty($_POST['show_homepage'])?1:0,'starts_at'=>$starts,'ends_at'=>$ends,'updated_at'=>$this->polls_now()];
        if($id){
            $existing_vote_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t['votes']} WHERE poll_id=%d",$id));
            if($existing_vote_count>0){
                $existing_options=$wpdb->get_col($wpdb->prepare("SELECT option_text FROM {$t['options']} WHERE poll_id=%d ORDER BY sort_order ASC,id ASC",$id));
                if($existing_options!==$options){
                    wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_error='.rawurlencode('Choices cannot be changed after employees have voted. You can still edit the question, visibility, status and dates.')));exit;
                }
            }
            $wpdb->update($t['polls'],$data,['id'=>$id],['%s','%s','%s','%d','%s','%s','%s'],['%d']);
            if($existing_vote_count===0){
                $wpdb->delete($t['options'],['poll_id'=>$id],['%d']);
                foreach($options as $i=>$text)$wpdb->insert($t['options'],['poll_id'=>$id,'option_text'=>$text,'sort_order'=>$i],['%d','%s','%d']);
            }
        } else {
            $data['created_by']=get_current_user_id();$data['created_at']=$this->polls_now();$wpdb->insert($t['polls'],$data,['%s','%s','%s','%d','%s','%s','%s','%d','%s']);$id=(int)$wpdb->insert_id;
            foreach($options as $i=>$text)$wpdb->insert($t['options'],['poll_id'=>$id,'option_text'=>$text,'sort_order'=>$i],['%d','%s','%d']);
        }
        $this->audit('poll_saved','poll',$id,($id?'Updated':'Created').' employee poll: '.$question);
        wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_saved=1'));exit;
    }

    public function poll_toggle(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_toggle_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();$wpdb->query($wpdb->prepare("UPDATE {$t['polls']} SET status=CASE WHEN status='active' THEN 'inactive' ELSE 'active' END, updated_at=%s WHERE id=%d",$this->polls_now(),$id));wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_status=1'));exit;
    }
    public function poll_homepage(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_homepage_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();$row=$wpdb->get_row($wpdb->prepare("SELECT show_homepage FROM {$t['polls']} WHERE id=%d",$id));$wpdb->update($t['polls'],['show_homepage'=>$row&&!empty($row->show_homepage)?0:1,'updated_at'=>$this->polls_now()],['id'=>$id],['%d','%s'],['%d']);wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_status=1'));exit;
    }
    public function poll_archive(){
        if(!$this->can('ews_manage_settings'))wp_die('Access denied.');$id=absint($_GET['poll_id']??0);check_admin_referer('ews_poll_archive_'.$id,'ews_poll_nonce');global $wpdb;$t=$this->polls_table_names();$wpdb->update($t['polls'],['status'=>'inactive','show_homepage'=>0,'updated_at'=>$this->polls_now()],['id'=>$id],['%s','%d','%s'],['%d']);wp_safe_redirect(admin_url('admin.php?page=ews31-polls&poll_deleted=1'));exit;
    }

    public function poll_vote(){
        if(!is_user_logged_in())wp_die('You must be logged in to vote.');
        $id=absint($_POST['poll_id']??0);
        check_admin_referer('ews_poll_vote_'.$id,'ews_poll_nonce');
        $this->ensure_polls_schema();
        global $wpdb;
        $t=$this->polls_table_names();
        $emp=$this->current_employee();
        $poll=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['polls']} WHERE id=%d",$id));
        $option=absint($_POST['option_id']??0);
        if(!$emp||!$poll||!$this->poll_is_visible($poll))wp_die('This poll is no longer available.');
        $valid=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['options']} WHERE id=%d AND poll_id=%d",$option,$id));
        if(!$valid)wp_die('Invalid poll choice.');
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t['votes']} WHERE poll_id=%d AND employee_id=%d LIMIT 1",$id,(int)$emp->id));
        if(!$existing){
            $inserted=$wpdb->insert($t['votes'],[
                'poll_id'=>$id,
                'option_id'=>$option,
                'employee_id'=>(int)$emp->id,
                'user_id'=>get_current_user_id(),
                'voted_at'=>$this->polls_now()
            ],['%d','%d','%d','%d','%s']);
            if($inserted!==false)$this->audit('poll_vote','poll',$id,$emp->name.' voted in employee poll #'.$id);
        }

        // admin-post.php is the current request URL here. Do not build the
        // redirect from app_view_url(), otherwise the employee can be sent
        // back to admin-post.php with ews_view=dashboard and see a blank page.
        $target=$this->notification_app_view_url('dashboard');
        $target=add_query_arg('poll_voted',$id,$target);
        wp_safe_redirect($target);
        exit;
    }
}
