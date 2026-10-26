"""Speed (3.31.126): the pages that went through everyone one query at a time now cost the same for 8 people as for 78.

On a real site System Health showed the same query again and again in one load:
people_shift_id() (Attendance, Attendance Insights, the Sign In / Out report), the shift history (a SHOW TABLES and a SELECT per
person), each person's Work Location (the Sign In / Out report, the branch filter), people_name() (the colleagues of the New
request sheet on My requests) and push_jobs_for_user() (one query per person in a push batch). Each is now read for everyone at
once. Nothing on the pages changes (checked byte for byte against 3.31.125 on the test site, see the PR), and the values read in
one go are the ones read one by one before (checked here).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_speed.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080. Seeds with tests/e2e_attendance_cards.py.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, subprocess, sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, php, results, wp, HERE, S  # noqa: E402

ctx = json.loads(subprocess.run([sys.executable, os.path.join(HERE, 'e2e_attendance_cards.py'), S, '--seed', 'big'], capture_output=True, text=True, check=True).stdout.strip().splitlines()[-1])
php("""delete_option('ews_feature_attendance_cards'); $u=new WP_User((int)get_user_by('login','emp1')->ID); $u->remove_cap('ews_manage_attendance');""")
WEEK = ctx['week']
D0 = ctx['dates'][0]

# The query count of a page, printed at its end by a test-only mu-plugin.
php("""$d=WPMU_PLUGIN_DIR; if(!is_dir($d)) wp_mkdir_p($d); file_put_contents($d.'/ews-e2e-speed-q.php','<?php if(defined("WP_CLI")&&WP_CLI) return; add_action("shutdown",function(){ global $wpdb; if(!wp_doing_ajax() && !(defined("REST_REQUEST")&&REST_REQUEST)) echo "<!--EWSQ=".(int)$wpdb->num_queries."-->"; },PHP_INT_MAX);');""")


def big(on):
    php("$wpdb->query(\"UPDATE {$p}ews_employees SET active=%d WHERE domain_name LIKE 'acbig%%'\");" % (1 if on else 0))


def queries(sess, url):
    page = sess.req(url)[1]
    m = re.findall(r'<!--EWSQ=(\d+)-->', page)
    return int(m[-1]) if m else -1


adm = Session('admin', 'admin')
PAGES = {
    'Attendance': '/app/?ews_view=attendance&week=' + WEEK,
    'Attendance Insights (app)': '/app/?ews_view=attendance-insights&week=' + WEEK,
    'Attendance Insights (wp-admin)': '/wp-admin/admin.php?page=ews31-attendance-insights&week=' + WEEK,
    'Reports: Daily Log': '/app/?ews_view=reports&report_type=attendance&start=%s&end=%s' % (D0, ctx['dates'][-1]),
}


def measure():
    for u in PAGES.values():
        queries(adm, u)   # warm (first request after a change builds caches)
    return {k: queries(adm, u) for k, u in PAGES.items()}


big(False)
small = measure()
big(True)
large = measure()
for k in PAGES:
    check('%s: the same queries for 8 people as for 78 (%d / %d)' % (k, small[k], large[k]), 0 < large[k] <= small[k] + 2, (small[k], large[k]))

# The report lists archived people's records too (their history), so it is measured on a day only the 8 signed in and on the
# day the 70 more did (100 records a page: 78 people on it).
REPORT = '/wp-admin/admin.php?page=ews31-time-report&start=%s&end=%s'
queries(adm, REPORT % (ctx['dates'][1], ctx['dates'][1]))
r_small, r_large = queries(adm, REPORT % (ctx['dates'][1], ctx['dates'][1])), queries(adm, REPORT % (D0, D0))
check('Sign In / Out report: the same queries for the records of 8 people as for 78 (%d / %d)' % (r_small, r_large), 0 < r_large <= r_small + 2, (r_small, r_large))

# ---------------------------------------------------------------- branches: the filter and the Work Location radius
ids = php("""global $wpdb; $p=$wpdb->prefix;
    $wpdb->insert($p.'ews_locations',['name'=>'Alex Branch','latitude'=>31.2,'longitude'=>29.9,'radius'=>350,'enforcement'=>0,'is_default'=>0,'active'=>1]); $alex=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_locations',['name'=>'Old Branch','latitude'=>30.1,'longitude'=>31.1,'radius'=>90,'enforcement'=>0,'is_default'=>0,'active'=>0]); $old=(int)$wpdb->insert_id;
    $big=array_map('intval',$wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE domain_name LIKE 'acbig%' ORDER BY id"));
    foreach($big as $i=>$id){ if($i%3===0) $wpdb->replace($p.'ews_employee_locations_v321',['employee_id'=>$id,'location_id'=>$alex]); elseif($i%3===1) $wpdb->replace($p.'ews_employee_locations_v321',['employee_id'=>$id,'location_id'=>$old]); }
    update_option('ews_branch_settings',['mode'=>'schedule'],false);
    echo wp_json_encode(['alex'=>$alex,'old'=>$old,'big'=>$big]);""")
ids = json.loads(ids)
# The same location for each person, read in one go and one at a time: assigned and active, assigned but archived (the
# default then), and not assigned (the default).
same = php("""$o=new EWS_Manager_V31_1(); $ids=json_decode('%s',true);
    $one=new ReflectionMethod($o,'ews_v321_employee_location'); $one->setAccessible(true);
    $prime=new ReflectionMethod($o,'ews_v321_employee_locations_prime'); $prime->setAccessible(true);
    $c=new ReflectionProperty('EWS_Manager_V31_1','ews_employee_location_cache'); $c->setAccessible(true);
    $c->setValue(null,[]); $before=[]; foreach($ids as $id){ $l=$one->invoke($o,$id); $before[$id]=$l?(array)$l:null; $c->setValue(null,[]); }
    $prime->invoke($o,$ids); global $wpdb; $q=$wpdb->num_queries; $after=[]; foreach($ids as $id){ $l=$one->invoke($o,$id); $after[$id]=$l?(array)$l:null; }
    echo wp_json_encode(['same'=>$before===$after,'queries'=>$wpdb->num_queries-$q,'names'=>array_values(array_unique(array_map(function($l){return $l['name']??'';},$after)))]);""" % json.dumps(ids['big'][:9]))
same = json.loads(same)
check('Work Locations read in one go are the ones read one at a time (assigned, archived → default, none → default)', same['same'] and same['queries'] == 0 and sorted(same['names']) == ['Alex Branch', 'Cairo HQ'], same)
moved = php("""$o=new EWS_Manager_V31_1(); $id=%d;
    $one=new ReflectionMethod($o,'ews_v321_employee_location'); $one->setAccessible(true);
    $prime=new ReflectionMethod($o,'ews_v321_employee_locations_prime'); $prime->setAccessible(true);
    $prime->invoke($o,[$id]); $a=$one->invoke($o,$id)->name;
    $e=new ReflectionMethod($o,'ews_v321_ensure_locations_table'); $e->setAccessible(true);
    global $wpdb; $wpdb->replace($wpdb->prefix.'ews_employee_locations_v321',['employee_id'=>$id,'location_id'=>%d]); $e->invoke($o);
    echo $a.' -> '.$one->invoke($o,$id)->name;""" % (ids['big'][2], ids['alex']))
check('...a change to the locations or assignments clears what was read in one go (in the same request)', moved.endswith('Cairo HQ -> Alex Branch'), moved)
php("$wpdb->query(\"DELETE FROM {$p}ews_employee_locations_v321 WHERE employee_id=%d\");" % ids['big'][2])

BR = '/app/?ews_view=attendance&week=%s&branch=%d' % (WEEK, ids['alex'])


def some(n):
    """The first n of the 70 more people active (with people in the branch either way)."""
    php("$wpdb->query(\"UPDATE {$p}ews_employees SET active=0 WHERE domain_name LIKE 'acbig%%'\"); $wpdb->query(\"UPDATE {$p}ews_employees SET active=1 WHERE domain_name LIKE 'acbig%%' ORDER BY id LIMIT %d\");" % n)


some(35)
queries(adm, BR)
s_br, s_rep = queries(adm, BR), queries(adm, REPORT % (ctx['dates'][1], ctx['dates'][1]))
some(70)
queries(adm, BR)
l_br, l_rep = queries(adm, BR), queries(adm, REPORT % (D0, D0))
check('Attendance with a branch filter: the same queries for 43 people as for 78 (%d / %d)' % (s_br, l_br), 0 < l_br <= s_br + 2, (s_br, l_br))
check('the Sign In / Out report with branches: the same queries for the records of 8 people as for 78 (%d / %d)' % (s_rep, l_rep), 0 < l_rep <= s_rep + 2, (s_rep, l_rep))
page = adm.req(BR)[1]
check('...and the filter still keeps the people of that branch (23 of the 70 + none of the 8)', len(re.findall(r'Big Person \d\d', page)) >= 1 and 'Omar Ontime' not in page, len(re.findall(r'Big Person \d\d', page)))
php("""global $wpdb; $p=$wpdb->prefix; $wpdb->query("DELETE FROM {$p}ews_employee_locations_v321"); $wpdb->query("DELETE FROM {$p}ews_locations WHERE name IN ('Alex Branch','Old Branch')"); delete_option('ews_branch_settings');""")

# ---------------------------------------------------------------- the New request sheet: colleagues' names
names = php("""global $wpdb; $p=$wpdb->prefix; $o=new EWS_Manager_V31_1();
    $me=$wpdb->get_row("SELECT * FROM {$p}ews_employees WHERE domain_name='acomar'");
    $t=gmdate('Y-m-d',strtotime(current_time('Y-m-d').' +3 days')); $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE work_date=%s",$t));
    foreach($wpdb->get_col("SELECT id FROM {$p}ews_employees WHERE department_id=".(int)$me->department_id) as $id) $wpdb->insert($p.'ews_schedule',['employee_id'=>(int)$id,'work_date'=>$t,'status'=>'Office']);
    $m=new ReflectionMethod($o,'swap_new_choices'); $m->setAccessible(true);
    $q=$wpdb->num_queries; $r=$m->invoke($o,$me); $n=$wpdb->num_queries-$q;
    $ok=true; foreach($r['people'] as $x) if($x['name']!==$wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}ews_employees WHERE id=%d",$x['id']))) $ok=false;
    $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE work_date=%s",$t));
    echo wp_json_encode(['n'=>$n,'people'=>count($r['people']),'ok'=>$ok,'first'=>$r['people'][0]['name']??'']);""")
names = json.loads(names)
check('New request → Shift swap: %d colleagues and their names in %d queries (it was one more per colleague)' % (names['people'], names['n']), names['people'] >= 70 and names['n'] <= 12 and names['ok'], names)

# ---------------------------------------------------------------- a push batch: everyone's devices at once
push = php("""global $wpdb; $o=new EWS_Manager_V31_1(); $e=new ReflectionMethod($o,'ensure_push_schema'); $e->setAccessible(true); $e->invoke($o);
    $t=new ReflectionMethod($o,'push_table'); $t->setAccessible(true); $table=$t->invoke($o);
    $wpdb->query("DELETE FROM {$table}"); $users=[];
    for($i=1;$i<=12;$i++){ $users[]=1000+$i; foreach([0,1] as $k) $wpdb->insert($table,['user_id'=>1000+$i,'endpoint'=>'https://push.example.invalid/'.$i.'-'.$k,'endpoint_hash'=>hash('sha256','https://push.example.invalid/'.$i.'-'.$k),'p256dh'=>'k','auth'=>'a','updated_at'=>gmdate('Y-m-d H:i:s',time()-$k*60)]); }
    $al=new ReflectionMethod($o,'notification_push_allowed'); $al->setAccessible(true); $cat='';
    foreach(['system','approval','attendance','tasks','info'] as $c) if($cat==='' && $al->invoke($o,$c)) $cat=$c;
    $one=new ReflectionMethod($o,'push_jobs_for_user'); $one->setAccessible(true);
    $by=new ReflectionMethod($o,'push_devices_by_user'); $by->setAccessible(true);
    $before=[]; foreach($users as $u) $before[$u]=array_keys($one->invoke($o,$u,'T','M',$cat,0,''));
    $q=$wpdb->num_queries; $dev=$by->invoke($o,$users); $after=[]; foreach($users as $u) $after[$u]=array_keys($one->invoke($o,$u,'T','M',$cat,0,'',$dev)); $n=$wpdb->num_queries-$q;
    $wpdb->query("DELETE FROM {$table}");
    echo wp_json_encode(['same'=>$before===$after,'n'=>$n,'devices'=>array_sum(array_map('count',$after)),'category'=>$cat]);""")
push = json.loads(push)
check('a push batch for 12 people reads their 24 devices in one query, the same devices in the same order', push['same'] and push['n'] <= 2 and push['devices'] == 24, push)

php("@unlink(WPMU_PLUGIN_DIR.'/ews-e2e-speed-q.php');")
php("""global $wpdb; $p=$wpdb->prefix; $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'ac%' AND domain_name<>'emp1'"); $wpdb->query("DELETE FROM {$p}ews_departments WHERE code LIKE 'ac-%'");
    $wpdb->query("DELETE FROM {$p}ews_employee_shift_periods"); delete_option('ews_shifts');""")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
