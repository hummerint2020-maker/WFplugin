"""A substitute while a manager is away (3.31.107, approvals stage 4; owner decisions 2026-10-17,
docs/tasks/approvals.md, mockup docs/mockups/substitutes/): off by default; while one is active the
requests that would wait for the manager wait for the substitute, "for" the manager (new ones, the
next level, the ones already waiting); the manager can still decide (a setting); what still waits goes
back on return (or stays, a setting) with a summary; a substitute's own request stays with the
manager; set with a leave (follows the leave), with "I'll be away" (any start and end time) or by HR;
refused when the substitute is away, overlapping, not allowed by the "who" setting.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_substitutes.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, d, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
ids = json.loads(php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    foreach(['ews_leave_requests','ews_leave_balances','ews_overtime_requests','ews_approval_requests','ews_approval_steps','ews_approval_workflow_steps','ews_employee_relationships','ews_approval_substitutes','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0");
    foreach(['ews_feature_substitutes','ews_substitute_settings','ews_substitutes_next_sync','ews_approval_settings','ews_approval_deadlines','ews_approval_self_rule','ews_approval_reject_note'] as $o) delete_option($o);
    update_option('ews_feature_my_approvals',1,false); update_option('ews_working_days',[0,1,2,3,4,5,6],false); update_option('ews_feature_overtime',1,false);
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','big','nour','sami')");
    $wpdb->query("DELETE FROM {$p}ews_departments WHERE code IN ('sb-a','sb-b')");
    $wpdb->insert($p.'ews_departments',['name'=>'SB A','code'=>'sb-a','active'=>1]);$da=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_departments',['name'=>'SB B','code'=>'sb-b','active'=>1]);$db=(int)$wpdb->insert_id;
    $wpdb->update($p.'ews_employees',['department_id'=>$da],['id'=>$eid]);
    $out=['emp'=>$eid,'emp_uid'=>(int)get_user_by('login','emp1')->ID];
    foreach(['mona'=>['Mona Adel',$da],'big'=>['Big Boss',$da],'nour'=>['Nour Sami',$da],'sami'=>['Sami Other',$db]] as $login=>[$name,$dep]){
        $uid=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>$name,'domain_name'=>$login,'email'=>$login.'@example.com','wp_user_id'=>$uid,'active'=>1,'attendance_enabled'=>1,'department_id'=>$dep]);
        $out[$login]=(int)$wpdb->insert_id;$out[$login.'_uid']=(int)$uid;
    }
    // emp1 and Nour report to Mona; Mona to Big; Sami (another department) to Big.
    foreach([[$eid,$out['mona']],[$out['nour'],$out['mona']],[$out['mona'],$out['big']],[$out['sami'],$out['big']]] as [$e,$s]) $wpdb->insert($p.'ews_employee_relationships',['employee_id'=>$e,'relationship_type'=>'supervisor','related_employee_id'=>$s,'active'=>1]);
    $type=(int)$wpdb->get_var("SELECT id FROM {$p}ews_leave_types WHERE name='Annual Leave'");
    $wpdb->query("UPDATE {$p}ews_leave_types SET active=1,deduct_balance=1,annual_entitlement=21,kind='planned',after_days=0 WHERE id=$type");
    foreach([$eid,$out['mona'],$out['nour']] as $e) foreach([(int)current_time('Y'),(int)current_time('Y')+1] as $y) $wpdb->insert($p.'ews_leave_balances',['employee_id'=>$e,'leave_type_id'=>$type,'leave_year'=>$y,'entitlement'=>21,'used'=>0,'pending'=>0]);
    $out['type']=$type;
    echo wp_json_encode($out);
""").splitlines()[-1])
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')
S = {k: Session(k, k + 'pass') for k in ('mona', 'big', 'nour', 'sami')}
PAGE = '/wp-admin/admin.php?page=ews31-substitutes'


def save_workflow(key, mode, **levels):
    st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-approvals')
    m = re.search(r'data-approval-card="ews-approval-workflow-%s".*?name="_wpnonce" value="([^"]+)"' % key, page, re.S)
    data = {'action': 'ews_approval_workflow_save', '_wpnonce': m.group(1) if m else 'x', 'workflow_key': key, 'approval_mode': mode, 'self_rule': 'allow', 'remind_hours': 0, 'escalate_hours': 0, 'overdue_hours': 0}
    data.update({k: str(v) for k, v in levels.items()})
    adm.req('/wp-admin/admin-post.php', data)


def leave(sess, start, end=None, **extra):
    n = sess.nonce('ews_vacation_request_create')
    st, qs, _ = sess.post('ews_vacation_request_create', _wpnonce=n, leave_type_id=ids['type'], start_date=start, end_date=end or start, reason='trip', **extra)
    r = q("SELECT id FROM {p}ews_leave_requests WHERE start_date='%s' AND employee_id=%d ORDER BY id DESC LIMIT 1" % (start, {'emp1': ids['emp'], 'mona': ids['mona'], 'nour': ids['nour']}[sess.login]))
    return (int(r[0]['id']) if r else 0), qs


def step(leave_id):
    r = q("SELECT i.* FROM {p}ews_approval_steps i JOIN {p}ews_approval_requests r ON r.id=i.approval_request_id WHERE r.entity_type='leave' AND r.entity_id=%d AND i.status='PENDING'" % leave_id)
    return r[0] if r else {}


def ar(leave_id):
    return int(q("SELECT id FROM {p}ews_approval_requests WHERE entity_type='leave' AND entity_id=%d" % leave_id)[0]['id'])


def inbox(sess):
    return sess.req('/app/?ews_view=approvals')[1]


def form_of(page, approval_id):
    for i, body in re.findall(r'<article class="wfo-apv-item[^"]*" data-apv-item="(\d+)">(.*?)</article>', page, re.S):
        if int(i) == approval_id:
            f = re.search(r'<form method="post" action="[^"]+" data-apv-form[^>]*>(.*?)</form>', body, re.S)
            return {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', f.group(1))} if f else {}
    return {}


def card(page, approval_id):
    m = re.search(r'data-apv-item="%d">(.*?)</article>' % approval_id, page, re.S)
    return m.group(1) if m else ''


def admin_nonce(action):
    st, page, _ = adm.req(PAGE)
    m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="%s"' % action, page)
    return m.group(1) if m else 'x'


def hr_set(manager, sub, starts, ends, reason=''):
    st, qs, _ = adm.post('ews_substitute_save', _wpnonce=admin_nonce('ews_substitute_save'), manager_employee_id=manager, substitute_employee_id=sub, starts_at=starts, ends_at=ends, reason=reason)
    return qs


def sub_row(sid):
    return q("SELECT * FROM {p}ews_approval_substitutes WHERE id=%d" % sid)[0]


def last_sub():
    r = q("SELECT * FROM {p}ews_approval_substitutes ORDER BY id DESC LIMIT 1")
    return r[0] if r else {}


def notes(uid):
    return [r['title'] + ' | ' + r['message'] for r in q("SELECT title,message FROM {p}ews_notifications WHERE user_id=%d ORDER BY id" % uid)]


def at(minutes):
    """Local time now + minutes, as a datetime-local value."""
    return php("echo wp_date('Y-m-d\\\\TH:i',time()+%d*60);" % minutes).splitlines()[-1]


def session_nonce(s, uid, action):
    """The nonce this logged-in session would get for an action (its form is not shown to it)."""
    jar = [h for h in s.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), uid, action)).strip().splitlines()[-1]


for k, s in S.items():
    s.login = k
emp.login = 'emp1'
save_workflow('vacation', 'LEVEL_1', level_1_type='SUPERVISOR')

# ---------------------------------------------------------------- off by default
lv0, _ = leave(emp, d(20))
check('off by default: no question in the manager\'s leave form, no Substitutes on the menu, the request waits for the manager',
      'name="substitute_employee_id"' not in S['mona'].req('/app/?ews_view=vacation')[1] and int(step(lv0).get('approver_employee_id') or 0) == ids['mona'])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-features')
check('...the switch is on the Features page, off, with its settings', 'name="substitutes_enabled"' in page and 'name="substitute_on_return"' in page and 'name="substitute_who"' in page)
check('...the Substitutes page says it is off', 'Turn them on in Features' in adm.req(PAGE)[1])
php("update_option('ews_feature_substitutes',1,false);")

# ---------------------------------------------------------------- the forms
mona_leave = S['mona'].req('/app/?ews_view=vacation')[1]
check('on: a manager\'s leave form asks who decides for them, their manager and department managers suggested first',
      'name="substitute_employee_id"' in mona_leave and re.search(r'<optgroup label="Suggested"><option value="%d">Big Boss' % ids['big'], mona_leave) is not None)
check('...not an employee without a team', 'name="substitute_employee_id"' not in emp.req('/app/?ews_view=vacation')[1])
check('...and in My requests → New request too', 'name="substitute_employee_id"' in S['mona'].req('/app/?ews_view=requests')[1])
pg = inbox(S['mona'])
check('My approvals has "I\'ll be away" for a manager', 'data-wfo-sheet="wfo-away-sheet"' in pg and 'name="starts_at"' in pg and 'type="datetime-local"' in pg)
check('...not for an employee', 'wfo-away-sheet' not in emp.req('/app/?ews_view=approvals')[1])

# ---------------------------------------------------------------- refused
qs = hr_set(ids['mona'], ids['mona'], at(-60), at(120))
check('refused: a manager as their own substitute', qs.get('sub_error') == 'same', qs)
qs = hr_set(ids['mona'], ids['nour'], at(120), at(60))
check('refused: the end before the start', qs.get('sub_error') == 'dates', qs)
qs = hr_set(ids['mona'], ids['nour'], at(-300), at(-120))
check('refused: already over', qs.get('sub_error') == 'past', qs)
php("update_option('ews_substitute_settings',['who'=>'department'],false);")
qs = hr_set(ids['mona'], ids['sami'], at(-60), at(120))
check('refused: "same department" and the substitute is in another one', qs.get('sub_error') == 'who', qs)
php("update_option('ews_substitute_settings',['who'=>'managers'],false);")
qs = hr_set(ids['mona'], ids['nour'], at(-60), at(120))
check('refused: "managers only" and nobody reports to the substitute', qs.get('sub_error') == 'who', qs)
php("delete_option('ews_substitute_settings');")
php("""$wpdb->insert($p.'ews_leave_requests',['employee_id'=>%d,'leave_type_id'=>%d,'start_date'=>'%s','end_date'=>'%s','requested_days'=>1,'status'=>'Approved','requested_by'=>1]);""" % (ids['nour'], ids['type'], d(3), d(3)))
# 3 days ahead: d() is the test machine's (UTC) date, a day behind the site's (Cairo) before 3am there.
qs = hr_set(ids['mona'], ids['nour'], d(3) + 'T00:00', d(4) + 'T00:00')
check('refused: the substitute is on leave themselves then', qs.get('sub_error') == 'away', qs)
st, qs, _ = emp.post('ews_substitute_save', _wpnonce=session_nonce(emp, ids['emp_uid'], 'ews_substitute_save'), substitute_employee_id=ids['nour'], starts_at=at(-60), ends_at=at(120))
check('refused: an employee without a team cannot set one', qs.get('sub_error') == 'access' and not q("SELECT id FROM {p}ews_approval_substitutes"), qs)

# ---------------------------------------------------------------- HR sets one: the waiting ones move to the substitute
qs = hr_set(ids['mona'], ids['nour'], at(-60), at(180), 'Business trip')
sid = int(last_sub().get('id') or 0)
check('HR sets one for now: saved and active at once', qs.get('sub_done') == 'saved' and last_sub().get('status') == 'active' and last_sub().get('source') == 'hr', qs)
s0 = step(lv0)
check('...the request already waiting for Mona now waits for Nour, "for" Mona', int(s0['approver_employee_id']) == ids['nour'] and int(s0['for_employee_id']) == ids['mona'] and int(s0['substitute_id']) == sid, s0)
check('...Nour was told (picked, then started, with the number waiting)', any('You decide for Mona Adel' in n and '1 is waiting now' in n for n in notes(ids['nour_uid'])), notes(ids['nour_uid']))
check('...and it is on the Substitutes page, active', re.search(r'data-substitute="%d">.*?Mona Adel.*?Nour Sami.*?Active now' % sid, adm.req(PAGE)[1], re.S) is not None)
qs = hr_set(ids['mona'], ids['big'], at(30), at(240))
check('refused: a second one for some of the same time', qs.get('sub_error') == 'overlap', qs)

lv1, _ = leave(emp, d(22))
s1 = step(lv1)
check('a new request while she is away goes straight to Nour, "for" Mona', int(s1['approver_employee_id']) == ids['nour'] and int(s1['for_employee_id']) == ids['mona'], s1)
check('...and Nour is the one told about it', any('Vacation Request' in n for n in notes(ids['nour_uid'])))
lvn, _ = leave(S['nour'], d(24))
check('Nour\'s own request stays with Mona', int(step(lvn)['approver_employee_id']) == ids['mona'] and not step(lvn).get('for_employee_id'), step(lvn))

pg = inbox(S['nour'])
check('Nour\'s My approvals: "Deciding for Mona Adel" and "For Mona Adel" on each card', 'Deciding for Mona Adel' in pg and 'For Mona Adel' in card(pg, ar(lv0)) and 'For Mona Adel' in card(pg, ar(lv1)), pg[:300])
pg = inbox(S['mona'])
check('Mona still sees them, "With Nour Sami while you are away"', 'With Nour Sami while you are away' in card(pg, ar(lv1)))
check('...and her own substitute with End now', 'Nour Sami decides for you' in pg and 'End now' in pg)

f = form_of(inbox(S['nour']), ar(lv1)); f['decision'] = 'approve'
st, body, h = S['nour'].req('/wp-admin/admin-post.php', f)
check('Nour approves one: the leave is approved', q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lv1)[0]['status'] == 'Approved', h.get('Location'))
mine = emp.req('/app/?ews_view=requests')[1]
check('...the employee\'s path says "Nour Sami for Mona Adel"', 'Nour Sami for Mona Adel approved' in mine, re.findall(r'<small>[^<]*approved[^<]*</small>', mine)[:3])
check('...counted on the substitute', int(sub_row(sid)['decided']) == 1)
lv2, _ = leave(emp, d(26))
f = form_of(inbox(S['mona']), ar(lv2)); f['decision'] = 'approve'
S['mona'].req('/wp-admin/admin-post.php', f)
check('Mona decides one herself while away (the setting allows it)', q("SELECT status FROM {p}ews_leave_requests WHERE id=%d" % lv2)[0]['status'] == 'Approved')
php("update_option('ews_substitute_settings',['manager_decides'=>0],false);")
lv3, _ = leave(emp, d(28))
check('...with the setting off she no longer sees or decides them', 'data-apv-item="%d"' % ar(lv3) not in inbox(S['mona']) and php("""wp_set_current_user(%d);$o=new EWS_Manager_V31_1();$m=new ReflectionMethod($o,'approval_can_act');$m->setAccessible(true);echo $m->invoke($o,%d)?'yes':'no';""" % (ids['mona_uid'], ar(lv3))).splitlines()[-1] == 'no')
php("delete_option('ews_substitute_settings');")

# ---------------------------------------------------------------- the end: back to the manager, with a summary
php("$wpdb->update($p.'ews_approval_substitutes',['ends_at'=>'%s'],['id'=>%d]);" % (php("echo wp_date('Y-m-d H:i:s',time()-60);").splitlines()[-1], sid))
php("$o=new EWS_Manager_V31_1();$o->substitutes_sync();")
check('its time over: ended, and what still waits went back to Mona', sub_row(sid)['status'] == 'ended' and int(step(lv0)['approver_employee_id']) == ids['mona'] and not step(lv0).get('for_employee_id') and int(step(lv3)['approver_employee_id']) == ids['mona'])
check('...Mona was told what Nour decided and what waits for her', any('Nour Sami decided 1 request for you' in n and '2 still wait for you' in n for n in notes(ids['mona_uid'])), notes(ids['mona_uid'])[-2:])

# "stays": a second one, ended now from the app by Mona herself ("I'll be away" first)
php("update_option('ews_substitute_settings',['on_return'=>'stay'],false);")
n = S['mona'].nonce('ews_substitute_save', 'approvals')
st, qs, _ = S['mona'].post('ews_substitute_save', _wpnonce=n, substitute_employee_id=ids['big'], starts_at=at(-5), ends_at=at(600), reason='Course')
s2 = last_sub()
check('Mona: "I\'ll be away" with a start and end time, saved and active, back on My approvals', qs.get('sub_done') == 'saved' and s2.get('source') == 'self' and s2.get('status') == 'active' and int(step(lv0)['approver_employee_id']) == ids['big'], (qs, s2))
st, page, _ = S['mona'].req('/app/?ews_view=approvals')
m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="ews_substitute_end"><input type="hidden" name="id" value="%d"' % int(s2['id']), page)
st, qs, _ = S['mona'].post('ews_substitute_end', _wpnonce=m.group(1) if m else 'x', id=s2['id'])
check('...she ends it now: with "stays", what waits stays with Big', qs.get('sub_done') == 'ended' and sub_row(int(s2['id']))['status'] == 'ended' and int(step(lv0)['approver_employee_id']) == ids['big'], qs)
php("delete_option('ews_substitute_settings');")
st, qs, _ = S['nour'].post('ews_substitute_end', _wpnonce='x', id=s2['id'])
check('someone else cannot end or cancel it', st in (403, 200) and 'sub_done' not in qs)

# ---------------------------------------------------------------- with a leave: follows the leave
lm, qs = leave(S['mona'], d(10), d(12), substitute_employee_id=ids['nour'])
s3 = last_sub()
check('Mona asks for leave with Nour as substitute: saved, waiting for the leave', lm and int(s3.get('leave_request_id') or 0) == lm and s3.get('status') == 'pending' and s3['starts_at'] == d(10) + ' 00:00:00' and s3['ends_at'] == d(13) + ' 00:00:00', (qs, s3))
f = form_of(inbox(S['big']), ar(lm)); f['decision'] = 'approve'
S['big'].req('/wp-admin/admin-post.php', f)
check('...Big approves the leave: the substitute is scheduled for those days, and Nour told', sub_row(int(s3['id']))['status'] == 'scheduled' and any('You will decide for a manager' in n for n in notes(ids['nour_uid'])))
lm2, qs = leave(S['mona'], d(15), d(15), substitute_employee_id=ids['nour'])
s4 = last_sub()
n = S['mona'].req('/app/?ews_view=requests')[1]
m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*>\s*<input type="hidden" name="action" value="ews_request_withdraw"><input type="hidden" name="kind" value="leave"><input type="hidden" name="id" value="%d"' % lm2, n)
S['mona'].post('ews_request_withdraw', _wpnonce=m.group(1) if m else 'x', kind='leave', id=lm2)
check('...a leave withdrawn: its substitute is cancelled', sub_row(int(s4['id']))['status'] == 'cancelled', (m is not None, sub_row(int(s4['id']))['status']))
lm3, qs = leave(S['mona'], d(17), d(17), substitute_employee_id=ids['sami'])
php("update_option('ews_substitute_settings',['who'=>'department'],false);")
lm4, qs = leave(S['mona'], d(18), d(18), substitute_employee_id=ids['sami'])
check('...a substitute the setting does not allow: the leave is refused with a message', qs.get('leave_error') == 'substitute_bad' and not lm4, qs)
php("update_option('ews_substitute_settings',['ask_on_leave'=>'required'],false);")
lm5, qs = leave(S['mona'], d(19), d(19))
check('..."required": a manager\'s leave without one is refused', qs.get('leave_error') == 'substitute' and not lm5, qs)
php("update_option('ews_substitute_settings',['ask_on_leave'=>'no'],false);")
check('...and "do not ask": no question', 'name="substitute_employee_id"' not in S['mona'].req('/app/?ews_view=vacation')[1])
php("delete_option('ews_substitute_settings');")

# ---------------------------------------------------------------- time: scheduled → active at its minute
qs = hr_set(ids['big'], ids['mona'], at(90), at(200))
s5 = last_sub()
check('one starting later is scheduled', s5.get('status') == 'scheduled', (qs, s5))
php("$o=new EWS_Manager_V31_1();$o->substitutes_sync(wp_date('Y-m-d H:i:s',time()+95*60));")
check('...and active once its start time comes (the check runs on page loads and hourly)', sub_row(int(s5['id']))['status'] == 'active')
st, page, _ = adm.req(PAGE)
m = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="_wp_http_referer"[^>]*><input type="hidden" name="action" value="ews_substitute_end"><input type="hidden" name="id" value="%d"' % int(s5['id']), page)
st, qs, _ = adm.post('ews_substitute_end', _wpnonce=m.group(1) if m else 'x', id=s5['id'])
check('HR ends it from the Substitutes page', qs.get('sub_done') == 'ended' and sub_row(int(s5['id']))['status'] == 'ended', qs)
check('the Audit Log has them', len(q("SELECT id FROM {p}ews_audit_log WHERE action LIKE 'substitute_%'")) >= 6)

php("""foreach(['ews_approval_substitutes','ews_leave_requests','ews_leave_balances','ews_approval_requests','ews_approval_steps','ews_employee_relationships'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    $wpdb->query("UPDATE {$p}ews_approval_workflows SET approval_mode='NONE',active=0"); $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name IN ('mona','big','nour','sami')");
    foreach(['ews_feature_substitutes','ews_substitute_settings','ews_feature_my_approvals'] as $o) delete_option($o); update_option('ews_feature_overtime',0,false);""")
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)
