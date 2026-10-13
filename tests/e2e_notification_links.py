"""Where a notification takes its reader (3.31.94, owner-approved list): the same place from the phone
(push) and from the app's Notifications page; a wp-admin page only for someone who may open it, the
Notifications page with the notification shown for anyone else (never WordPress's white "not allowed"
page); opened from the phone it is marked read; the open handler never loops or dies; links built in a
cron job point at the app, not at wp-cron.php; Refused Sign In, polls and presence checks can be pushed.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_notification_links.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
php("""$wpdb->query("DELETE FROM {$p}ews_notifications"); delete_option('ews_notification_policy');
    $u=get_user_by('login','nlmgr'); if(!$u){$id=wp_create_user('nlmgr','nlmgrpass','nlmgr@example.com'); $u=get_user_by('id',$id);} $u->set_role('ews_supervisor');""")
UID = {n: int(php("echo get_user_by('login','%s')->ID;" % n).splitlines()[-1]) for n in ('emp1', 'admin', 'nlmgr')}
APP = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'app_home_url'); $r->setAccessible(true); echo $r->invoke($m);").splitlines()[-1]

# ---------------------------------------------------------------- the push settings
pol = json.loads(php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'notification_policy'); $r->setAccessible(true); echo wp_json_encode($r->invoke($m));").splitlines()[-1])
check('Refused Sign In, polls and presence checks are in the Notifications settings, pushed by default',
      all(pol.get(k, {}).get('push') == 1 for k in ('attendance', 'poll', 'presence')), {k: pol.get(k) for k in ('attendance', 'poll', 'presence')})
# Every kind pushed, so each push link can be checked.
php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'notification_policy_categories'); $r->setAccessible(true); $o=[]; foreach(array_keys($r->invoke($m)) as $k)$o[$k]=['in_app'=>1,'push'=>1,'mandatory'=>0]; update_option('ews_notification_policy',$o,false);")


def send(user, category, **opt):
    """notify() as the plugin calls it; returns the notification id, its saved link and the push link."""
    out = php("""$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'notify'); $r->setAccessible(true);
        $id=$r->invoke($m,%d,'%s','Title','Message',json_decode('%s',true));
        $qp=new ReflectionProperty('EWS_Manager_V31_1','ews_push_queue'); $qp->setAccessible(true); $all=$qp->getValue(); $last=$all?end($all):null; $qp->setValue(null,[]);
        echo wp_json_encode(['id'=>$id,'link'=>$wpdb->get_var($wpdb->prepare("SELECT link FROM {$p}ews_notifications WHERE id=%%d",$id)),'push'=>$last?$last['url']:null]);"""
              % (UID[user], category, json.dumps(opt).replace("'", "\\'")))
    return json.loads(out.splitlines()[-1])


def args(url):
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(url).query).items()}


sess = {n: Session(n, p) for n, p in (('emp1', 'emp1pass'), ('admin', 'admin'), ('nlmgr', 'nlmgrpass'))}


def follow(user, url):
    st, body, h = sess[user].req(url.replace(B, '').split('#')[0])
    return st, body


def white(st, body):
    return st != 200 or 'WordPress &rsaquo; Error' in body or 'An error occurred.' in body


# ---------------------------------------------------------------- each kind: its own app page
EXPECT = [('schedule', {}, 'schedule'), ('swap', {}, 'schedule'), ('leave', {}, 'vacation'), ('vacation', {}, 'vacation'),
          ('early_leave', {}, 'vacation'), ('overtime', {}, 'overtime'), ('correction', {}, 'corrections'), ('break', {}, 'time'),
          ('face_reset', {}, 'time'), ('payroll', {}, 'pay'), ('achievement', {}, 'profile'), ('recognition', {'entity': 'kudos'}, 'profile'),
          ('poll', {}, 'polls'), ('presence', {}, 'presence'), ('daily_workers', {}, 'sites'), ('system', {}, 'notifications')]
bad = []
for cat, opt, view in EXPECT:
    r = send('emp1', cat, **opt)
    a, pa = args(r['link'] or ''), args(r['push'] or '')
    if a.get('ews_view') != view or pa.get('ews_view') != view or pa.get('ews_nid') != str(r['id']) or not (r['link'] or '').startswith(APP):
        bad.append((cat, r))
check('without a link of its own, each kind opens its app page, from the phone and from the list alike (%d kinds)' % len(EXPECT), not bad, bad)
r = send('emp1', 'task', entity_id=77)
check('a task notification opens the task\'s details', args(r['link']).get('ews_view') == 'tasks' and args(r['link']).get('task') == '77' and args(r['push']).get('task') == '77', r)

# ---------------------------------------------------------------- pages only some people may open
TR = B + '/wp-admin/admin.php?page=ews31-time-report&timeline=1&tdate=2026-10-12'
r_emp = send('emp1', 'attendance', url=TR)
r_mgr = send('nlmgr', 'attendance', url=TR)
r_adm = send('admin', 'attendance', url=TR)
check('a wp-admin page someone may not open: the Notifications page with the notification shown instead',
      args(r_emp['link']).get('ews_view') == 'notifications' and args(r_emp['link']).get('nid') == str(r_emp['id']) and args(r_emp['push']).get('nid') == str(r_emp['id']), r_emp)
check('...someone who may open it (a supervisor for the Sign In report, the administrator) gets the page', r_mgr['link'] == TR and r_adm['link'] == TR, (r_mgr, r_adm))
FR = B + '/wp-admin/admin.php?page=ews31-face-reset-requests'
r = send('nlmgr', 'face_reset', url=FR)
check('...each page by its own permission (a supervisor cannot open Face Reset Requests)', args(r['link']).get('ews_view') == 'notifications', r)
r = send('emp1', 'office_minimum')
r2 = send('nlmgr', 'office_minimum')
check('an app page that needs a permission works the same (the attendance planner)', args(r['link']).get('ews_view') == 'notifications' and args(r2['link']).get('ews_view') == 'attendance', (r, r2))

# ---------------------------------------------------------------- following the links: never a white page
cases = [('emp1', send('emp1', cat, **opt)['push']) for cat, opt, _ in EXPECT] + [('emp1', r_emp['push']), ('nlmgr', r_mgr['push']), ('admin', r_adm['push']), ('emp1', send('emp1', 'task', entity_id=77)['push'])]
whites = [(u, url) for u, url in cases if white(*follow(u, url))]
check('every push link opens a page, none WordPress\'s white error page (%d links)' % len(cases), not whites, whites)
st, body = follow('emp1', r_emp['push'])
check('...the notification is shown on the Notifications page', 'id="ews-notification-%d" class="ews-notification wfo-notif-item' % r_emp['id'] in body and 'is-focus' in body)
check('opened from the phone, it is marked read', q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % r_emp['id'])[0]['is_read'] == '1')
r = send('emp1', 'payroll')
sess['nlmgr'].req(r['push'].replace(B, ''))
check('...only by its own reader', q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % r['id'])[0]['is_read'] == '0')
st, body, _ = sess['admin'].req(r_adm['push'].replace(B, ''))
check('...also when it opens a wp-admin page', st == 200 and q("SELECT is_read FROM {p}ews_notifications WHERE id=%d" % r_adm['id'])[0]['is_read'] == '1')

# ---------------------------------------------------------------- the Notifications page
php("""$wpdb->insert($p.'ews_notifications',['user_id'=>%d,'title'=>'Old swap','message'=>'from before 3.31.94','type'=>'info','entity'=>'swap','entity_id'=>3,'is_read'=>0,'created_at'=>current_time('mysql')]);""" % UID['emp1'])
OLD = int(q("SELECT id FROM {p}ews_notifications WHERE title='Old swap'")[0]['id'])
st, page, _ = sess['emp1'].req('/app/?ews_view=notifications')
opens = {int(m.group(2)): html.unescape(m.group(1)) for m in re.finditer(r'href="([^"]*action=ews_notification_open[^"]*notification_id=(\d+)[^"]*)"', page)}


def opened(nid):
    st, body, h = sess['emp1'].req(opens[nid].replace(B, '')) if nid in opens else (0, '', {})
    return h.get('Location', '')


r = send('emp1', 'correction')
st, page, _ = sess['emp1'].req('/app/?ews_view=notifications')
opens = {int(m.group(2)): html.unescape(m.group(1)) for m in re.finditer(r'href="([^"]*action=ews_notification_open[^"]*notification_id=(\d+)[^"]*)"', page)}
check('from the list, a notification opens the same page as from the phone', opened(r['id']) == r['link'], (opened(r['id']), r['link']))
check('...one saved before (no link) opens the app page of its kind (a swap: the Schedule)', args(opened(OLD)).get('ews_view') == 'schedule' and 'admin-post' not in opened(OLD), opened(OLD))
st, body, h = sess['emp1'].req('/wp-admin/admin-post.php?action=ews_notification_open')
check('the open handler without a notification goes to the Notifications page (it used to fail with an error page)', st in (301, 302) and args(h.get('Location', '')).get('ews_view') == 'notifications' and 'admin-post' not in h.get('Location', ''), (st, h.get('Location')))

# ---------------------------------------------------------------- real senders
EID = ids()['eid']
php("""$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'save_att'); $r->setAccessible(true); $r->invoke($m,%d,date('Y-m-d',strtotime(current_time('Y-m-d').' +3 days')),'WFH');""" % EID)
row = q("SELECT link FROM {p}ews_notifications WHERE title='Schedule Updated' ORDER BY id DESC LIMIT 1")
check('a schedule change opens the Schedule (it opened the Notifications page)', row and args(row[0]['link']).get('ews_view') == 'schedule', row)
out = php("""$_SERVER['REQUEST_URI']='/wp-cron.php?doing_wp_cron=1'; $m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'smart_nudge_for_employee'); $r->setAccessible(true);
    $emp=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}ews_employees WHERE id=%%d",%d)); echo wp_json_encode(array_column((array)$r->invoke($m,$emp,%d),'url'));""" % (EID, UID['emp1']))
urls = json.loads(out.splitlines()[-1])
check('smart reminders sent from the cron job link to the app, not to wp-cron.php', all(u.startswith(APP) and 'wp-cron' not in u for u in urls), urls)

php("""$wpdb->query("DELETE FROM {$p}ews_notifications"); delete_option('ews_notification_policy'); $wpdb->query($wpdb->prepare("DELETE FROM {$p}ews_schedule WHERE employee_id=%%d AND work_date>%%s",%d,current_time('Y-m-d')));
    require_once ABSPATH.'wp-admin/includes/user.php'; $u=get_user_by('login','nlmgr'); if($u) wp_delete_user($u->ID);""" % EID)
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
