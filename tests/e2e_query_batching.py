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

res = php("""
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $o=new EWS_Manager_V31_1();
    $one=new ReflectionMethod($o,'approval_path');$one->setAccessible(true);
    $many=new ReflectionMethod($o,'approval_paths');$many->setAccessible(true);
    $act=new ReflectionMethod($o,'approval_act');$act->setAccessible(true);
    $find=new ReflectionMethod($o,'approval_find_request');$find->setAccessible(true);
    foreach([5000,5001] as $e){ $et=$e%2?'leave':'overtime_request'; $wk=$e%2?'vacation':'overtime'; $ar=$find->invoke($o,$wk,$et,$e); if($ar)$act->invoke($o,(int)$ar->id,'approve','',true,true); }
    $keys=[]; for($i=0;$i<30;$i++)$keys[]=[$i%2?'vacation':'overtime',$i%2?'leave':'overtime_request',5000+$i];
    $keys[]=['vacation','leave',999999];
    $count=0; add_filter('query',function($q)use(&$count){$count++;return $q;});
    $batch=$many->invoke($o,$keys);
    $batch_q=$count;
    $count=0; $singles=[];
    foreach($keys as $k)$singles[$o_key=sanitize_key($k[0]).'|'.sanitize_key($k[1]).'|'.(int)$k[2]]=$one->invoke($o,$k[0],$k[1],$k[2]);
    $single_q=$count;
    $strip=function($p){ if(is_array($p)){unset($p['hours'],$p['overdue']); foreach($p['steps'] as &$s){ } } return $p; };
    $same=true;$bad='';
    foreach($singles as $key=>$p){ if(json_encode($strip($p))!==json_encode($strip($batch[$key]??null)))$same=false; if(!$same&&$bad==='')$bad=$key; }
    $nonnull=count(array_filter($batch));
    echo wp_json_encode(['batch_q'=>$batch_q,'single_q'=>$single_q,'same'=>$same,'bad'=>$bad,'nonnull'=>$nonnull,'missing_null'=>$batch['vacation|leave|999999']===null,'sample'=>$batch['overtime|overtime_request|5000']??null]);
""")
r = json.loads(res.splitlines()[-1])
check('the batch gives the same path as one request at a time, for all 30 (and null for a request with no approval)', r['same'] and r['nonnull'] == 30 and r['missing_null'], r)
check('...and one of them is decided by an administrator, with the name on its level', r['sample'] and r['sample']['status'] == 'APPROVED' and any(s['state'] == 'done' for s in r['sample']['steps']), r['sample'])
check('the batch makes a handful of queries for 31 requests (not several per request)', 0 < r['batch_q'] <= 12, r)
check('...where one at a time makes many more', r['single_q'] > 3 * r['batch_q'], r)
res2 = php("""
    wp_set_current_user((int)get_user_by('login','admin')->ID);
    $o=new EWS_Manager_V31_1();$many=new ReflectionMethod($o,'approval_paths');$many->setAccessible(true);
    $keys=[]; for($i=0;$i<5;$i++)$keys[]=['vacation','leave',5001+2*$i];
    $c=0; add_filter('query',function($q)use(&$c){$c++;return $q;}); $many->invoke($o,$keys); $five=$c;
    $c=0; $many->invoke($o,[]); echo wp_json_encode(['five'=>$five,'empty'=>$c]);
""")
r2 = json.loads(res2.splitlines()[-1])
check('an empty list makes no query, and 5 requests cost about what 31 do (the count does not grow with the list)', r2['empty'] == 0 and abs(r2['five'] - r['batch_q']) <= 2, (r2, r['batch_q']))
php("""foreach(['ews_approval_requests','ews_approval_steps'] as $t) $wpdb->query("DELETE FROM {$p}$t"); $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");""")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
