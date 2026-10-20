"""Query batching (3.31.112): the approval paths shown on My requests, My approvals and the wp-admin Requests page are found
together, not with several queries per request. approval_paths() must give exactly what approval_path() gives for each request,
and the number of queries must not grow with the number of requests (the System health screen flagged 31 identical queries).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_query_batching.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import check, php, results, wp, HERE  # noqa: E402

os.environ['WFO_FIXTURES'] = os.path.join(HERE, 'fixtures')

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
out = php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_approval_allow_self','ews_approval_self_rule'] as $o) delete_option($o);
    update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'approval_start');$m->setAccessible(true);
    $cw=new ReflectionMethod($o,'approval_configure_workflow');$cw->setAccessible(true);
    foreach(['vacation','overtime'] as $wk){ $r=$cw->invoke($o,$wk,'LEVEL_1',[1=>['resolver_type'=>'SUPERVISOR','resolver_value'=>null]]); if(is_wp_error($r)){echo $r->get_error_message();exit;} }
    $n=0;
    for($i=0;$i<30;$i++){ $r=$m->invoke($o,$i%2?'vacation':'overtime',$i%2?'leave':'overtime_request',5000+$i,$eid); if(!is_wp_error($r))$n++; }
    echo $n;
""").splitlines()[-1]
check('30 approval requests were started on the engine', out == '30', out)

# Parity: the batch against approval_path() exactly as it was in 3.31.111 (tests/fixtures), for the cases a path can show.
seed = php("""
    $admin=(int)get_user_by('login','admin')->ID; wp_set_current_user($admin);
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $u=function($login,$name){ $id=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com'); wp_update_user(['ID'=>$id,'display_name'=>$name]); return (int)$id; };
    $sub=$u('qb_sami','WP Sami'); $mona=$u('qb_mona','WP Mona'); $two=$u('qb_two','WP Two'); $plain=$u('qb_plain','Plain User');
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'qb_%'");
    $emp=function($dn,$name,$uid,$active=1)use($wpdb,$p){ $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$dn,'email'=>$dn.'@example.com','wp_user_id'=>$uid,'active'=>$active,'attendance_enabled'=>1]); return (int)$wpdb->insert_id; };
    $emp('qb_sami','Sami Ali',$sub); $mona_e=$emp('qb_mona','Mona Adel',$mona); $emp('qb_two_a','Two Lower',$two); $emp('qb_two_b','Two Higher',$two); $emp('qb_two_c','Two Archived',$two,0);
    $wf=function($k)use($wpdb,$p){ return (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}ews_approval_workflows WHERE workflow_key=%s",$k)); };
    $req=function($wk,$et,$id,$status,$steps)use($wpdb,$p,$wf,$eid){
        $wpdb->insert($p.'ews_approval_requests',['workflow_id'=>$wf($wk),'entity_type'=>$et,'entity_id'=>$id,'requester_employee_id'=>$eid,'status'=>$status,'current_step'=>1]);
        $r=(int)$wpdb->insert_id;
        foreach($steps as $i=>$st){ $wpdb->insert($p.'ews_approval_steps',array_merge(['approval_request_id'=>$r,'step_order'=>$i+1,'resolver_type'=>'SUPERVISOR','status'=>'WAITING'],$st)); }
        return $r;
    };
    $ago=date('Y-m-d H:i:s',strtotime(current_time('mysql'))-3*86400);
    // A substitute decided for Mona, a level not needed, then a level waiting for a user with two employees, moved up from Plain.
    $req('vacation','leave',7001,'PENDING',[
        ['status'=>'APPROVED','approver_wp_user_id'=>$sub,'acted_by_wp_user_id'=>$sub,'acted_at'=>$ago,'for_employee_id'=>$mona_e],
        ['status'=>'SKIPPED','skip_reason'=>'condition','resolver_type'=>'FINANCE'],
        ['status'=>'PENDING','approver_wp_user_id'=>$two,'pending_since'=>$ago,'escalations'=>1,'escalated_from_wp_user_id'=>$plain,'resolver_type'=>'HR'],
        ['status'=>'WAITING','approver_wp_user_id'=>$plain,'resolver_type'=>'SPECIFIC_USER']]);
    // An administrator decided for the substitute's manager; rejected with a reason.
    $req('overtime','overtime_request',7002,'REJECTED',[
        ['status'=>'REJECTED','approver_wp_user_id'=>$plain,'acted_by_wp_user_id'=>$admin,'acted_at'=>$ago,'comment'=>'Not this week','for_employee_id'=>$mona_e]]);
    // Sent to administrators (no approver), skipped because the approver made it, then next.
    $req('vacation','leave',7003,'PENDING',[
        ['status'=>'SKIPPED','skip_reason'=>'self'],['status'=>'PENDING','approver_wp_user_id'=>null,'rerouted'=>1,'pending_since'=>$ago],['status'=>'WAITING','skip_reason'=>null]]);
    // Withdrawn by the employee, and one with no level at all.
    $req('overtime','overtime_request',7004,'CANCELLED',[['status'=>'CANCELLED','approver_wp_user_id'=>$sub],['status'=>'SKIPPED']]);
    $req('vacation','leave_cancellation',7005,'APPROVED',[]);
    echo 'ok';
""").splitlines()[-1]
check('the parity cases were seeded', seed == 'ok', seed)

res = php("""
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $o=new EWS_Manager_V31_1();
    $old=Closure::bind(require getenv('WFO_FIXTURES').'/approval_path_3_31_111.php',$o,get_class($o));
    $many=new ReflectionMethod($o,'approval_paths');$many->setAccessible(true);
    $act=new ReflectionMethod($o,'approval_act');$act->setAccessible(true);
    $find=new ReflectionMethod($o,'approval_find_request');$find->setAccessible(true);
    foreach([5000,5001] as $e){ $et=$e%2?'leave':'overtime_request'; $wk=$e%2?'vacation':'overtime'; $ar=$find->invoke($o,$wk,$et,$e); if($ar)$act->invoke($o,(int)$ar->id,'approve','',true,true); }
    $keys=[]; for($i=0;$i<30;$i++)$keys[]=[$i%2?'vacation':'overtime',$i%2?'leave':'overtime_request',5000+$i];
    foreach([['vacation','leave',7001],['overtime','overtime_request',7002],['vacation','leave',7003],['overtime','overtime_request',7004],['vacation','leave_cancellation',7005],
             ['Vacation','LEAVE',7001],['vacation','leave',999999]] as $k)$keys[]=$k;
    $now=current_time('mysql');
    $count=0; add_filter('query',function($q)use(&$count){$count++;return $q;});
    $batch=$many->invoke($o,$keys,$now); $batch_q=$count;
    $count=0; $ref=[]; foreach($keys as $k)$ref[]=$old($k[0],$k[1],$k[2],$now); $single_q=$count;
    $bad=[]; $kf=new ReflectionMethod($o,'approval_request_key');$kf->setAccessible(true);
    foreach($keys as $i=>$k){ $a=$ref[$i]; $kk=$kf->invoke($o,$k[0],$k[1],$k[2]); $b=array_key_exists($kk,$batch)?$batch[$kk]:'missing';
        if(json_encode($a)!==json_encode($b))$bad[]=['key'=>$k,'old'=>$a,'new'=>$b]; }
    $p7001=$batch['vacation|leave|7001'];
    echo wp_json_encode(['batch_q'=>$batch_q,'single_q'=>$single_q,'bad'=>array_slice($bad,0,2),'nbad'=>count($bad),'n'=>count($keys),
        'nonnull'=>count(array_filter($batch)),'sample'=>$batch['overtime|overtime_request|5000']??null,'p7001'=>$p7001,'p7002'=>$batch['overtime|overtime_request|7002'],'p7003'=>$batch['vacation|leave|7003']]);
""")
r = json.loads(res.splitlines()[-1])
check('the batch gives exactly what 3.31.111 gave, request by request (%d keys, every field incl. hours)' % r['n'], r['nbad'] == 0, r['bad'])
p1, p2, p3 = r['p7001'], r['p7002'], r['p7003']
check('...and the cases are really there: a substitute "Sami Ali for Mona Adel", a level not needed, moved up from Plain User',
      p1 and p1['steps'][0]['who'] == 'Sami Ali for Mona Adel' and p1['steps'][1]['state'] == 'skipped' and p1['moved_from'] == 'Plain User' and p1['level'] == 2 and p1['levels'] == 3, p1)
check('...a user with two employees shows the active one with the lowest id, a user with none their WordPress name',
      p1 and p1['steps'][2]['who'] == 'Two Lower' and p1['steps'][3]['who'] == 'Plain User', p1 and p1['steps'])
check('...a rejection keeps its reason and who decided for whom; a level sent to administrators says so',
      p2 and p2['note'] == 'Not this week' and 'for Mona Adel' in p2['steps'][0]['sub'] and p3 and p3['steps'][1]['who'] == 'Administrators' and p3['hours'] > 0, (p2, p3))
check('...and one of the engine requests was decided by an administrator', r['sample'] and r['sample']['status'] == 'APPROVED' and any(s['state'] == 'done' for s in r['sample']['steps']), r['sample'])
check('the batch makes a handful of queries for %d requests (not several per request)' % r['n'], 0 < r['batch_q'] <= 12, r)
check('...where one at a time makes many more', r['single_q'] > 3 * r['batch_q'], r)
res2 = php("""
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $o=new EWS_Manager_V31_1();$many=new ReflectionMethod($o,'approval_paths');$many->setAccessible(true);
    $keys=[]; for($i=0;$i<5;$i++)$keys[]=['vacation','leave',5001+2*$i];
    $all=[]; for($i=0;$i<30;$i++)$all[]=[$i%2?'vacation':'overtime',$i%2?'leave':'overtime_request',5000+$i];
    $c=0; add_filter('query',function($q)use(&$c){$c++;return $q;}); $many->invoke($o,$keys); $five=$c;
    $c=0; $many->invoke($o,$all); $thirty=$c;
    $c=0; $many->invoke($o,[]); echo wp_json_encode(['five'=>$five,'thirty'=>$thirty,'empty'=>$c]);
""")
r2 = json.loads(res2.splitlines()[-1])
check('an empty list makes no query, and 5 requests cost about what 30 do (the count does not grow with the list)', r2['empty'] == 0 and 0 < r2['five'] and abs(r2['thirty'] - r2['five']) <= 2, r2)
# The pages themselves (the design's proof): the wp-admin Requests hub and My requests cost about the same with 5 and 30 leave requests.
pages = php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $wpdb->query("DELETE FROM {$p}ews_leave_requests WHERE reason='qb'");
    $lt=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $add=function($n)use($wpdb,$p,$eid,$lt){ $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'approval_start');$m->setAccessible(true);
        for($i=0;$i<$n;$i++){ $d=date('Y-m-d',strtotime('+'.(60+$wpdb->get_var("SELECT COUNT(*) FROM {$p}ews_leave_requests WHERE reason='qb'")).' days'));
            $wpdb->insert($p.'ews_leave_requests',['employee_id'=>$eid,'leave_type_id'=>$lt,'start_date'=>$d,'end_date'=>$d,'requested_days'=>1,'reason'=>'qb','status'=>'Pending','requested_by'=>1,'requested_at'=>current_time('mysql')]);
            $m->invoke($o,'vacation','leave',(int)$wpdb->insert_id,$eid); } };
    $measure=function()use($eid){
        $c=0; $f=function($q)use(&$c){$c++;return $q;}; add_filter('query',$f);
        wp_set_current_user((int)get_user_by('login','admin')->ID); $o=new EWS_Manager_V31_1(); $h=new ReflectionMethod($o,'admin_request_rows');$h->setAccessible(true);
        $c=0; $rows=$h->invoke($o); $hub=$c;
        wp_set_current_user((int)get_user_by('login','emp1')->ID); $o=new EWS_Manager_V31_1(); $r=new ReflectionMethod($o,'requests_content');$r->setAccessible(true);
        $c=0; $html=$r->invoke($o); $mine=$c;
        remove_filter('query',$f);
        return ['hub'=>$hub,'mine'=>$mine,'rows'=>count(array_filter($rows,function($x){return $x['type']==='leave';}))];
    };
    $add(5); $five=$measure(); $add(25); $thirty=$measure();
    echo wp_json_encode(['five'=>$five,'thirty'=>$thirty]);
""")
pg = json.loads(pages.splitlines()[-1])
check('wp-admin Requests: 30 leave requests cost about what 5 do (the levels and names are read for the list)',
      pg['five']['rows'] == 5 and pg['thirty']['rows'] == 30 and pg['thirty']['hub'] - pg['five']['hub'] <= 5, pg)
check('My requests: 30 leave requests cost about what 5 do (the paths are read for the list)', pg['thirty']['mine'] - pg['five']['mine'] < 25, pg)
php("""foreach(['ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t"); $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'qb_%'"); $wpdb->query("DELETE FROM {$p}ews_leave_requests WHERE reason='qb'"); require_once ABSPATH.'wp-admin/includes/user.php';
    foreach(['qb_sami','qb_mona','qb_two','qb_plain'] as $l){ $id=username_exists($l); if($id)wp_delete_user($id); }""")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
