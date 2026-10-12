"""Sign In evidence (3.31.92, docs/mockups/signin-attempts/ and face-photo/): refused Sign In / Out
attempts kept with their reason, the manager told after N refusals in a day, attempts that never
reached the server sent by the phone later (timed from the server's clock), the report's refused rows,
"Record it manually" and one employee's timeline; and the photo kept with each Face Sign In.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_signin_evidence.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import base64, html, json, os, random, re, sys, time, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

TR = '/wp-admin/admin.php?page=ews31-time-report'
CAIRO = {'latitude': '30.0445', 'longitude': '31.2358'}
ALEX = {'latitude': '31.2001', 'longitude': '29.9187'}
IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'


def evidence(**over):
    cfg = {'attempts': 0, 'attempt_days': 60, 'alert': 2, 'photo': 0, 'photo_days': 30, 'photo_viewers': 'hr_manager'}
    cfg.update(over)
    php("update_option('ews_signin_evidence',json_decode('%s',true),false);" % json.dumps(cfg))


wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
I = ids()
php("""foreach(['ews_signin_attempts','ews_notifications','ews_audit_log','ews_departments','ews_face_profiles','ews_break_sessions','ews_company_calendar'] as $t) $wpdb->query("DELETE FROM {$p}$t");
    update_option('ews_working_hours',['start'=>date('H:i',max(strtotime('today',current_time('timestamp')),current_time('timestamp')-3600)),'end'=>'23:59'],false); update_option('ews_working_days',[0,1,2,3,4,5,6],false);
    update_option('ews_feature_face_signin',0,false); update_option('ews_presence_qr_signin',0,false); update_option('ews_feature_breaks',0,false);
    $wpdb->update($p.'ews_locations',['enforcement'=>1],['id'=>%d]);
    $u=get_user_by('login','evmgr'); if($u){require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID);}
    $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='evmgr'");""" % I['lid'])
TODAY = php("echo current_time('Y-m-d');").strip()
evidence()


def attempts(where='1=1'):
    return q("SELECT * FROM {p}ews_signin_attempts WHERE %s ORDER BY id" % where)


def app_nonces(sess):
    st, page, _ = sess.req('/app/?ews_view=time')
    out = {}
    for form in re.findall(r'<form.*?</form>', page, re.S):
        a = re.search(r'name="action" value="([^"]+)"', form)
        n = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        t = re.search(r'name="event_type" value="([^"]+)"', form)
        if a and n:
            out[a.group(1) + (':' + t.group(1) if t else '')] = n.group(1)
    return out, page


emp = Session('emp1', 'emp1pass')
emp.op.addheaders = [('User-Agent', IPHONE)]
N, _ = app_nonces(emp)


def event(kind, loc=CAIRO, **extra):
    data = {'_wpnonce': N['ews31_time_event:' + kind], 'event_type': kind, 'accuracy': '35', 'location_timestamp': str(int(time.time() * 1000)), **loc, **extra}
    _, qs, _ = emp.post('ews31_time_event', **data)
    return qs


# ---------------------------------------------------------------- off: nothing kept
qs = event('sign_in', ALEX)
check('off: a refused Sign In is refused as before and nothing is kept', 'outside your assigned work location' in qs.get('time_error', '') and not attempts(), qs)

# ---------------------------------------------------------------- refused attempts
evidence(attempts=1)
qs = event('sign_in', ALEX)
a = attempts()
check('on: the refused Sign In is kept with its reason', len(a) == 1 and a[0]['result'] == 'refused' and a[0]['reason'] == 'outside_location' and a[0]['event_type'] == 'sign_in', a)
check('...where the phone was and how far (about 180 km)', a and a[0]['location_status'] == 'outside' and 150000 < float(a[0]['distance_meters']) < 200000 and a[0]['latitude'].startswith('31.2'), a)
check('...the device and the IP', a and a[0]['device'] == 'iPhone · Safari' and a[0]['ip_address'] == '127.0.0.1', a and (a[0]['device'], a[0]['ip_address']))
check('...and what the employee was told', a and 'outside your assigned work location' in (a[0]['detail'] or ''), a and a[0]['detail'])
check('no alert after one refusal', not q("SELECT id FROM {p}ews_notifications WHERE title='Sign In refused several times'"))
event('sign_in', ALEX)
alerts = q("SELECT user_id,message,url FROM {p}ews_notifications WHERE title='Sign In refused several times'") if php("global $wpdb; echo in_array('url',$wpdb->get_col(\"SHOW COLUMNS FROM {$wpdb->prefix}ews_notifications\",0))?1:0;").strip() == '1' else q("SELECT user_id,message FROM {p}ews_notifications WHERE title='Sign In refused several times'")
check('the 2nd refusal of the day (alert after 2): the managers are told once (no department: administrators)', len(alerts) == 1 and int(alerts[0]['user_id']) == 1 and 'Emp One' in alerts[0]['message'], alerts)
event('sign_in', ALEX)
check('...not again on the 3rd', len(q("SELECT id FROM {p}ews_notifications WHERE title='Sign In refused several times'")) == 1)
time.sleep(1.1)   # a second later: the day's order is by the second
qs = event('sign_in', CAIRO)
check('a Sign In inside is recorded as before (not an attempt)', 'time_success' in qs and len(attempts()) == 3, (qs, len(attempts())))

# ---------------------------------------------------------------- the report
adm = Session('admin', 'admin')
rng = '&start=%s&end=%s' % (TODAY, TODAY)
pg = adm.req(TR + rng)[1]
check('report: recorded and refused together, the refused ones marked', pg.count('data-attempt="refused"') == 3 and 'Outside the work location' in pg and 'Refused' in pg)
check('...with a summary of refusals by employee', 'data-refused-summary' in pg and '3 refused attempt(s) in this period' in pg and 'Emp One 3' in pg)
check('...the device and the IP of each', 'iPhone · Safari' in pg and '<td data-ip>127.0.0.1</td>' in pg)
check('..."Show" filters: refused only', adm.req(TR + rng + '&show=refused')[1].count('data-attempt=') == 3 and 'data-ip>127.0.0.1</td>' in adm.req(TR + rng + '&show=refused')[1])
rec_only = adm.req(TR + rng + '&show=recorded')[1]
check('...recorded only', 'data-attempt=' not in rec_only and 'Sign In' in rec_only)
m = re.search(r'href="([^"]*prefill_employee[^"]*)" data-record-manually', pg)
form = adm.req(html.unescape(m.group(1)).replace(B, ''))[1] if m else ''
check('"Record it manually" opens the add form with the employee, the event and the time filled in', bool(m) and re.search(r'<option value="%d"\s+selected' % I['eid'], form) is not None and 'name="event_at" value="%sT' % TODAY in form, m and m.group(1))
tl = adm.req(TR + rng + '&timeline=%d&tdate=%s' % (I['eid'], TODAY))[1]
kinds = re.findall(r'data-kind="([a-z_]+)"', tl)
check('the employee\'s day, in order: three refusals, then the Sign In', 'data-timeline' in tl and kinds == ['refused', 'refused', 'refused', 'log'], kinds)
check('...each refusal says why and from what device', tl.count('Sign In refused: Outside the work location') == 3 and 'iPhone · Safari' in tl)

# A manager sees their own department only (as the rest of the report, 3.31.90).
php("""$wpdb->insert($p.'ews_departments',['name'=>'Ops','code'=>'EVOPS','active'=>1]); $d=$wpdb->insert_id; $wpdb->update($p.'ews_employees',['department_id'=>$d],['id'=>%d]);
    $id=wp_create_user('evmgr','evmgrpass','evmgr@example.com'); $u=new WP_User($id); $u->set_role('subscriber'); $u->add_cap('ews_manage_time');
    $wpdb->insert($p.'ews_departments',['name'=>'Other','code'=>'EVOTH','active'=>1]); $d2=$wpdb->insert_id;
    $wpdb->insert($p.'ews_employees',['name'=>'Ev Manager','domain_name'=>'evmgr','email'=>'evmgr@example.com','wp_user_id'=>$id,'active'=>1,'attendance_enabled'=>1,'department_id'=>$d2]);""" % I['eid'])
mgr = Session('evmgr', 'evmgrpass')
check('a manager of another department sees none of these refusals, nor the timeline', 'data-attempt=' not in mgr.req(TR + rng)[1] and 'data-timeline' not in mgr.req(TR + rng + '&timeline=%d&tdate=%s' % (I['eid'], TODAY))[1])

# ---------------------------------------------------------------- an attempt that never reached the server
st, page, _ = emp.req('/app/?ews_view=time')
cfg = re.search(r'\{"url":"[^"]+","nonce":"([^"]+)","serverMs":(\d+)\}', page)
check('the app page carries what the phone needs to note an attempt (with the server\'s time)', bool(cfg) and 'ewsNoteAttempt' in page)
NONCE, SERVER_MS = (cfg.group(1), int(cfg.group(2))) if cfg else ('', 0)
SERVER_MS -= 10 * 60 * 1000   # as if the page had opened 10 minutes ago (a time in the past, not clipped)
FOUR = 4 * 60 * 1000


def offline(**data):
    body = {'action': 'ews_signin_offline', '_wpnonce': NONCE, 'event_type': 'sign_out', 'server_ms': SERVER_MS, 'elapsed_ms': FOUR, 'phone_ms': SERVER_MS + FOUR, **data}
    st, b, _ = emp.req('/wp-admin/admin-post.php', body)
    try:
        return json.loads(b).get('data', {})
    except ValueError:
        return {'raw': b[:200]}


r = offline(phone_ms=SERVER_MS + FOUR - 47 * 60 * 1000)   # the phone's clock 47 minutes behind
nr = attempts("result='not_reached'")
check('a Sign Out pressed with no network, sent later: kept as "did not reach the server"', r.get('kept') is True and len(nr) == 1 and nr[0]['reason'] == 'no_network' and nr[0]['source'] == 'offline', (r, nr))
srv_local = int(php("echo %d+ (current_time('timestamp')-time());" % (SERVER_MS // 1000 + 240)).strip())
check('...timed from the server\'s clock, not the phone\'s', nr and php("echo strtotime('%s');" % nr[0]['attempted_at']).strip() == php("echo strtotime(gmdate('Y-m-d H:i:s',%d));" % srv_local).strip(), nr and nr[0]['attempted_at'])
check('...and the phone\'s clock gap is kept (47 min behind)', nr and int(nr[0]['clock_skew']) == -47 * 60 and nr[0]['time_sure'] == '1', nr and nr[0]['clock_skew'])
pg = adm.req(TR + rng)[1]
check('the report shows it apart, with the gap', 'data-attempt="not_reached"' in pg and 'Did not reach the server' in pg and "off by 47 min" in pg and 'Phone (sent later)' in pg)
check('...it never counts as attendance (no Sign Out recorded)', not q("SELECT id FROM {p}ews_time_logs WHERE event_type='sign_out'"))
r = offline(event_type='sign_in', phone_ms=SERVER_MS + 1000, elapsed_ms=1000)
check('a Sign In that did reach us (recorded within 15 minutes): not kept again', r.get('kept') is False and r.get('reason') == 'reached', r)
r = offline(phone_ms=SERVER_MS + FOUR, elapsed_ms=8 * 3600 * 1000)
check('a page open for hours: the time is marked not certain', attempts("result='not_reached' AND time_sure=0") != [] or r.get('reason') == 'reached', r)
evidence(attempts=0)
check('with the feature off nothing is kept', offline(phone_ms=SERVER_MS).get('kept') is False)
evidence(attempts=1)

# ---------------------------------------------------------------- photo with Face Sign In
JPEG = php("$im=imagecreatetruecolor(32,40); imagefill($im,0,0,imagecolorallocate($im,120,140,200)); ob_start(); imagejpeg($im,null,80); echo base64_encode(ob_get_clean());").strip()
php("update_option('ews_feature_face_signin',1,false); $wpdb->query(\"DELETE FROM {$p}ews_time_logs\"); delete_user_meta(%d,'ews_face_recent'); delete_user_meta(%d,'ews_face_verify_failures');" % (I['uid'], I['uid']))
evidence(attempts=1, photo=1)
N, page = app_nonces(emp)
check('the camera screen says a photo is kept, who sees it and for how long', 'data-face-photo="1"' in page and 'data-face-photo-note' in page and 'Only HR and your manager can see it' in page and '30 days' in page)
REST = re.search(r'data-wp-nonce="([^"]+)"', page).group(1)
H = {'Content-Type': 'application/json', 'X-WP-Nonce': REST}
TPL = [0.01 * i for i in range(128)]


def rest(path, payload=None):
    import urllib.request
    r = urllib.request.Request(B + '/wp-json/workforce-one/v1/' + path, data=json.dumps(payload).encode() if payload is not None else None, headers=H)
    try:
        resp = emp.op.open(r)
        return resp.status, json.loads(resp.read().decode() or 'null')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or 'null')


def verify(tpl, photo=True):
    c = rest('face/challenge')[1].get('challenge', '')
    return rest('face/verify', {'template': tpl, 'challenge': c, 'photo': ('data:image/jpeg;base64,' + JPEG) if photo else ''})


rest('face/enroll', {'template': TPL})
st, body = verify([v + random.uniform(-0.01, 0.01) for v in TPL])
qs = event('sign_in', CAIRO, face_token=body.get('face_token', ''))
log = q("SELECT id,photo_key FROM {p}ews_time_logs WHERE event_type IN ('sign_in','late_sign_in') ORDER BY id DESC LIMIT 1")
check('a Face Sign In keeps the photo of its face check', 'time_success' in qs and log and re.match(r'^[A-Za-z0-9]{32}\.jpg$', log[0]['photo_key'] or ''), (qs, log))
FILE = php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'private_files_path'); $r->setAccessible(true); echo $r->invoke($m,'workforce-one-face-photos','%s');" % (log[0]['photo_key'] if log else 'x')).strip()
check('...in the private files folder', os.path.isfile(FILE) and '/workforce-one-face-photos/' in FILE, FILE)
ok_face = attempts("result='face_ok'")
check('the face check itself is in the employee\'s day (not a refusal)', len(ok_face) == 1 and not ok_face[0]['photo_key'])
pg = adm.req(TR + rng)[1]
m = re.search(r'<a href="([^"]+)" target="_blank" rel="noopener" data-photo><img src="([^"]+)"', pg)
check('report: a Photo column with the picture', '<th>Photo</th>' in pg and bool(m))
thumb, full = (html.unescape(m.group(2)).replace(B, ''), html.unescape(m.group(1)).replace(B, '')) if m else ('/x', '/x')
st, body_, h = adm.req(thumb)
check('...served to HR as an image, never stored by the browser', st == 200 and h.get('Content-Type', '').startswith('image/jpeg') and 'no-store' in h.get('Cache-Control', ''), (st, h.get('Content-Type'), h.get('Cache-Control')))
check('...a thumbnail is not an "opening"', not q("SELECT id FROM {p}ews_audit_log WHERE action='face_photo_viewed'"))
adm.req(full)
check('...opening it is written to the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='face_photo_viewed'")) == 1)
check('the employee cannot open it', emp.req(thumb)[0] in (403, 500))
php("$wpdb->update($p.'ews_employees',['department_id'=>(int)$wpdb->get_var(\"SELECT department_id FROM {$p}ews_employees WHERE domain_name='evmgr'\")],['id'=>%d]);" % I['eid'])
mp = mgr.req(TR + rng)[1]
mm = re.search(r'data-photo><img src="([^"]+)"', mp)
check('the department manager can (HR and department manager)', bool(mm) and mgr.req(html.unescape(mm.group(1)).replace(B, ''))[0] == 200)
evidence(attempts=1, photo=1, photo_viewers='hr')
check('...not with "HR only"', 'data-photo><img' not in mgr.req(TR + rng)[1] and mgr.req(html.unescape(mm.group(1)).replace(B, '') if mm else '/x')[0] in (403, 500))
evidence(attempts=1, photo=1)
st, body = verify([v + 0.3 for v in TPL])
fail = attempts("result='face_failed'")
check('a face that does not match: kept as a refusal, with its photo', body == {'ok': False} and len(fail) == 1 and fail[0]['reason'] == 'face_mismatch' and re.match(r'^[A-Za-z0-9]{32}\.jpg$', fail[0]['photo_key'] or ''), (body, fail))
evidence(attempts=0, photo=1)
php("delete_user_meta(%d,'ews_face_verify_failures');" % I['uid'])
before = len(os.listdir(os.path.dirname(FILE)))
verify([v + 0.3 for v in TPL])
check('...with refusals not kept, its photo is not kept either', len(os.listdir(os.path.dirname(FILE))) == before)
evidence(attempts=1, photo=1)

# ---------------------------------------------------------------- after N days
php("$wpdb->query(\"UPDATE {$p}ews_time_logs SET event_at=DATE_SUB(event_at, INTERVAL 40 DAY)\"); $wpdb->query(\"UPDATE {$p}ews_signin_attempts SET attempted_at=DATE_SUB(attempted_at, INTERVAL 70 DAY) WHERE result='refused'\");")
php("$m=new EWS_Manager_V31_1(); $m->evidence_cleanup();")
check('after 30 days the photo is deleted (file and link)', not os.path.isfile(FILE) and not q("SELECT id FROM {p}ews_time_logs WHERE photo_key IS NOT NULL"))
check('after 60 days the refused attempts are deleted, newer ones stay', not attempts("result='refused'") and attempts("result='not_reached'"))

# ---------------------------------------------------------------- the settings
fpg = adm.req('/wp-admin/admin.php?page=ews31-features')[1]
check('Features: both parts are there, with their days and options', 'name="evidence_attempts"' in fpg and 'name="evidence_attempt_days"' in fpg and 'name="evidence_alert"' in fpg
      and 'name="evidence_photo"' in fpg and 'name="evidence_photo_days"' in fpg and 'name="evidence_photo_viewers"' in fpg)
fn = re.search(r'name="_wpnonce" value="([^"]+)"[^<]*<input type="hidden" name="action" value="ews31_features_save"', fpg) or re.search(r'value="ews31_features_save".*?name="_wpnonce" value="([^"]+)"', fpg, re.S)
for form in re.findall(r'<form.*?</form>', fpg, re.S):
    if 'value="ews31_features_save"' in form:
        fn = re.search(r'name="_wpnonce" value="([^"]+)"', form)
adm.post('ews31_features_save', _wpnonce=fn.group(1) if fn else '', evidence_present=1, evidence_attempts=1, evidence_attempt_days=90, evidence_alert=0, evidence_photo_days=14, evidence_photo_viewers='hr', face_signin_enabled=1)
saved = json.loads(php("echo wp_json_encode(get_option('ews_signin_evidence'));"))
check('...saved from the form (photo off when unticked)', saved == {'attempts': 1, 'attempt_days': 90, 'alert': 0, 'photo': 0, 'photo_days': 14, 'photo_viewers': 'hr'}, saved)
check('...and the change is in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='signin_evidence_update'")) == 1)

evidence()
php("""$wpdb->query("DELETE FROM {$p}ews_signin_attempts"); $wpdb->update($p.'ews_locations',['enforcement'=>0],['id'=>%d]); $wpdb->update($p.'ews_employees',['department_id'=>null],['id'=>%d]);
    $u=get_user_by('login','evmgr'); if($u){require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($u->ID);} $wpdb->query("DELETE FROM {$p}ews_employees WHERE domain_name='evmgr'"); $wpdb->query("DELETE FROM {$p}ews_departments");""" % (I['lid'], I['eid']))
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
