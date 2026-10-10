"""Behaviour tests for daily workers (3.31.76), HTTP on a real site.

Pins: the feature switch; quick-add on site without a WordPress user; national ID validation,
encryption at rest (the raw column never holds the digits), masking, the reveal permission and its
Audit Log entry, search by the full number; the foreman records only his sites and only from inside
the site, with a group photo; a saved day is locked and a change is a request a manager approves (the
original values kept); each recording mode (foreman / self / both) and the worker's own sign-in by
mobile + PIN; pay for full, half and extra hours; advances; the payout sheet PDF; a paid period is
locked and its amounts never reach the Audit Log; daily workers never appear in staff pick lists,
Attendance, Payroll or staff reports; report totals equal the sum of the days; Excel and the
insurance report; settings; the API routes; deleting a worker.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_daily_workers.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, json, os, re, sys, time, urllib.parse, urllib.request, uuid

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, php, q, results, today, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))

TODAY = today.isoformat()
NID1, NID2, NID3 = '29001150101234', '28505010201457', '30503012101245'
setup = json.loads(php("""
    foreach(['workers','sites','foremen','moves','days','sheets','advances','payouts','lines','changes'] as $t) $wpdb->query("DROP TABLE IF EXISTS {$p}ews_dw_$t");
    $wpdb->query("DELETE FROM {$p}ews_audit_log"); $wpdb->query("DELETE FROM {$p}ews_notifications");
    delete_option('ews_dw_settings'); update_option('ews_feature_daily_workers',0,false);
    $wpdb->query("DELETE FROM {$p}ews_locations WHERE name IN ('Fifth Settlement','Sheikh Zayed','No Map')");
    $wpdb->insert($p.'ews_locations',['name'=>'Fifth Settlement','latitude'=>30.0300000,'longitude'=>31.4700000,'radius'=>150,'active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]); $a=(int)$wpdb->insert_id;
    $wpdb->insert($p.'ews_locations',['name'=>'Sheikh Zayed','latitude'=>30.0400000,'longitude'=>31.4800000,'radius'=>150,'active'=>1,'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]); $b=(int)$wpdb->insert_id;
    $mk=function($login,$caps){$u=username_exists($login)?:wp_create_user($login,$login.'pass',$login.'@example.com');$o=new WP_User($u);$o->set_role('subscriber');foreach($caps as $c)$o->add_cap($c);return (int)$u;};
    $fm=$mk('dwfm',['ews_dw_foreman']); $cash=$mk('dwcash',['ews_dw_cashier']); $hr=$mk('dwhr',['ews_manage_daily_workers']);
    echo wp_json_encode(['a'=>$a,'b'=>$b,'fm'=>$fm,'cash'=>$cash,'hr'=>$hr,'users'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),'emps'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}ews_employees")]);
""").splitlines()[-1])
A, Bsite = setup['a'], setup['b']

adm, fm, cash, hr, emp = Session('admin', 'admin'), Session('dwfm', 'dwfmpass'), Session('dwcash', 'dwcashpass'), Session('dwhr', 'dwhrpass'), Session('emp1', 'emp1pass')
PNG = b'\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\xff\xff?\x00\x05\xfe\x02\xfe\xa7\x35\x81\x84\x00\x00\x00\x00IEND\xaeB`\x82'


def page(s, path):
    return s.req(path)[1]


def app(s, view, **args):
    return page(s, '/app/?' + urllib.parse.urlencode(dict(ews_view=view, **args)))


def form_nonce(pg, action, extra=''):
    for f in re.findall(r'<form.*?</form>', pg, re.S):
        if 'name="action" value="%s"' % action in f and extra in f:
            m = re.search(r'name="_wpnonce" value="([^"]+)"', f)
            if m:
                return m.group(1)
    return ''


def post(s, fields, files=None):
    """POST to admin-post.php (multipart when there are files); returns the redirect's query."""
    if files is None:
        _, qs, _ = s.post(fields.pop('action'), **fields)
        return qs
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in fields.items():
        for vv in (v if isinstance(v, list) else [v]):
            body += ('--%s\r\nContent-Disposition: form-data; name="%s"\r\n\r\n%s\r\n' % (boundary, k, vv)).encode()
    for k, data in files.items():
        body += ('--%s\r\nContent-Disposition: form-data; name="%s"; filename="photo.png"\r\nContent-Type: image/png\r\n\r\n' % (boundary, k)).encode() + data + b'\r\n'
    body += ('--%s--\r\n' % boundary).encode()
    req = urllib.request.Request(B + '/wp-admin/admin-post.php', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        loc = s.op.open(req).headers.get('Location', '')
    except urllib.error.HTTPError as e:
        loc = e.headers.get('Location', '')
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(loc).query).items()}


LOGINS = {id(adm): 'admin', id(fm): 'dwfm', id(cash): 'dwcash', id(hr): 'dwhr', id(emp): 'emp1'}


def nonce_for(sess, action):
    """The nonce this logged-in session would get for an action (to show the server refuses it anyway)."""
    jar = [h for h in sess.op.handlers if hasattr(h, 'cookiejar')][0].cookiejar
    c = [x for x in jar if x.name.startswith('wordpress_logged_in_')][0]
    return php("$_COOKIE[LOGGED_IN_COOKIE]=%s; wp_set_current_user(%d); echo wp_create_nonce('%s');" % (json.dumps(urllib.parse.unquote(c.value)), int(php("echo username_exists('%s');" % LOGINS[id(sess)])), action)).strip()


def link(pg, pattern):
    m = re.search(r'href="([^"]*%s[^"]*)"' % pattern, pg)
    return m.group(1).replace('&#038;', '&').replace('&amp;', '&').replace(B, '') if m else ''


def call(method, *args):
    return php("$m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'%s'); $r->setAccessible(true); echo wp_json_encode($r->invoke($m,%s));" % (method, ','.join(args)))


def pos(lat=30.0301, lng=31.4701, stale=False):
    ts = int(time.time() * 1000) - (3600000 if stale else 0)
    return {'latitude': lat, 'longitude': lng, 'accuracy': 12, 'location_timestamp': ts}


def wid(name):
    return int(q("SELECT id FROM {p}ews_dw_workers WHERE name='%s'" % name)[0]['id'])


def day_row(w):
    r = q("SELECT * FROM {p}ews_dw_days WHERE worker_id=%d AND work_date='%s'" % (w, TODAY))
    return r[0] if r else None


def audit_text():
    return ' '.join(r['details'] or '' for r in q("SELECT details FROM {p}ews_audit_log"))


# ---------------------------------------------------------------- switched off
check('off: no My sites for the foreman (Home instead)', 'wfo-dw-site' not in app(fm, 'sites'))
check('off: no Daily Workers page in wp-admin', 'ews31-daily-workers' not in page(adm, '/wp-admin/admin.php?page=ews31'))
check('off: the worker page says it is switched off', 'switched off' in app(adm, 'worker'))
check('off: a foreman cannot save a sheet', fm.post('ews_dw_sheet', _wpnonce=nonce_for(fm, 'ews_dw_sheet'), site=A)[1].get('dw_error') == 'disabled')

# Switch on from Feature Configuration (the form keeps the other switches).
fpg = page(adm, '/wp-admin/admin.php?page=ews31-features')
check('Feature Configuration has the Daily Workers switch, off', 'name="daily_workers_enabled"' in fpg and 'name="daily_workers_present"' in fpg)
php("update_option('ews_feature_daily_workers',1,false); $m=new EWS_Manager_V31_1(); $r=new ReflectionMethod($m,'ensure_dw_schema'); $r->setAccessible(true); $r->invoke($m);")
check('on: the ten tables exist', len(q("SHOW TABLES LIKE '{p}ews_dw_%'")) == 10)

# Settings: both locations become project sites, the foreman on A, the cashier on A.
spg = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&tab=settings')
n = form_nonce(spg, 'ews_dw_settings')
check('settings: the manager sees the settings form', bool(n))
qs = post(hr, {'action': 'ews_dw_settings', '_wpnonce': n, 'dw_mode': 'foreman', 'dw_period': 'weekly', 'dw_week_start': '6', 'dw_hourly_rate': '50', 'dw_photo': '1',
                'dw_trades': 'Formwork carpenter\nSteel fixer\nHelper', 'dw_subcontractors': 'Al-Amal Contracting',
                'sites[%d][on]' % A: '1', 'sites[%d][project]' % A: 'Garden Heights', 'sites[%d][foremen][]' % A: [setup['fm'], setup['cash']],
                'sites[%d][on]' % Bsite: '1', 'sites[%d][mode]' % Bsite: 'self'}, files={})
check('settings: saved', qs.get('saved') == '1', qs)
st = json.loads(php("echo wp_json_encode(get_option('ews_dw_settings'));").splitlines()[-1])
check('settings: stored (mode, period, hourly rate, trades, subcontractors)', st['mode'] == 'foreman' and st['period'] == 'weekly' and st['hourly_rate'] == 50 and st['trades'] == ['Formwork carpenter', 'Steel fixer', 'Helper'] and st['subcontractors'] == ['Al-Amal Contracting'], st)
check('settings: two project sites, the foreman and the cashier on A, B self-service', len(q("SELECT * FROM {p}ews_dw_sites WHERE active=1")) == 2
      and sorted(int(r['user_id']) for r in q("SELECT user_id FROM {p}ews_dw_foremen WHERE location_id=%d" % A)) == sorted([setup['fm'], setup['cash']])
      and q("SELECT mode FROM {p}ews_dw_sites WHERE location_id=%d" % Bsite)[0]['mode'] == 'self')
check('settings: an employee cannot save them', post(emp, {'action': 'ews_dw_settings', '_wpnonce': n, 'dw_mode': 'self'}) == {} and json.loads(php("echo wp_json_encode(get_option('ews_dw_settings'));").splitlines()[-1])['mode'] == 'foreman')

# ---------------------------------------------------------------- the foreman: my sites and quick-add
sites = app(fm, 'sites')
check('My sites: the foreman sees his site only', 'Fifth Settlement' in sites and 'Sheikh Zayed' not in sites, sites[:300])
check('My sites: the employee does not', 'wfo-dw-site' not in app(emp, 'sites'))
dpg = app(fm, 'sites', site=A)
check('day sheet: empty list with "New worker"', 'There are no workers at this site today' in dpg and 'data-wfo-sheet="wfo-dw-add"' in dpg)
check('the foreman cannot open another site', 'Sheikh Zayed' not in app(fm, 'sites', site=Bsite))

na = form_nonce(dpg, 'ews_dw_worker_add')
add = lambda **kw: post(fm, dict({'action': 'ews_dw_worker_add', '_wpnonce': na, 'site': A, 'trade': 'Helper', 'daily_rate': '300'}, **kw))
check('quick-add: invalid national ID (birth month 13) is refused with its reason', add(name='Bad Id', nid='29013450101234').get('dw_error') == 'nid_birth')
check('quick-add: wrong length is refused', add(name='Bad Id', nid='2900115010123').get('dw_error') == 'nid_length')
check('quick-add: unknown governorate is refused', add(name='Bad Id', nid='29001155001234').get('dw_error') == 'nid_governorate')
check('quick-add: no daily rate is refused', add(name='No Rate', daily_rate='0').get('dw_error') == 'rate')
qs = add(name='Mohamed Abdallah', mobile='0100 445 2189', trade='Formwork carpenter', daily_rate='450', nid=NID1)
check('quick-add: a worker is added', 'dw_added' in qs, qs)
W1 = wid('Mohamed Abdallah')
add(name='Ahmed Shaaban', mobile='01124567890', daily_rate='300', nid=NID2)
add(name='Ali Hassan', daily_rate='300')
W2, W3 = wid('Ahmed Shaaban'), wid('Ali Hassan')
check('quick-add: the same national ID twice is refused', add(name='Copy', nid='2900-1150-1012-34').get('dw_error') == 'nid_taken')
check('quick-add: no WordPress user and no staff employee row', int(q("SELECT COUNT(*) n FROM {p}users")[0]['n']) == setup['users']
      and int(q("SELECT COUNT(*) n FROM {p}ews_employees")[0]['n']) == setup['emps'])
w = q("SELECT * FROM {p}ews_dw_workers WHERE id=%d" % W1)[0]
check('national ID: encrypted at rest (no digits in any column)', NID1 not in json.dumps(w) and w['nid_enc'].startswith('v1:'), w['nid_enc'][:20])
check('national ID: keyed hash, mask parts, birth date and governorate', len(w['nid_hash']) == 64 and w['nid_first'] == '2900' and w['nid_last'] == '1234' and w['birth_date'] == '1990-01-15' and w['governorate'] == '01')
check('national ID: never in the Audit Log', NID1 not in audit_text() and '0101234' not in audit_text())
check('the worker is at site A from today (a move row)', q("SELECT location_id FROM {p}ews_dw_moves WHERE worker_id=%d" % W1)[0]['location_id'] == str(A))
check('a foreman cannot add at a site that is not his', post(fm, {'action': 'ews_dw_worker_add', '_wpnonce': na, 'site': Bsite, 'name': 'X', 'daily_rate': '300'}).get('dw_error') == 'access')

# ---------------------------------------------------------------- wp-admin: list, search, profile, reveal
lst = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers')
check('list: workers with the masked ID', 'Mohamed Abdallah' in lst and '2900••••••1234' in lst and NID1 not in lst)
check('search: the full national ID finds the worker', 'Mohamed Abdallah' in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&q=' + NID1) and 'Ahmed Shaaban' not in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&q=' + NID1))
check('search: a part of the number does not', 'Mohamed Abdallah' not in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&q=29001150'))
check('search: by mobile and by trade filter', 'Ahmed Shaaban' in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&q=01124567890') and 'Ahmed Shaaban' not in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&trade=Formwork+carpenter'))
prof = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&worker=%d' % W1)
check('profile: masked, no Show without "View national IDs"', '2900••••••1234' in prof and '&amp;reveal=1' not in prof and NID1 not in prof)
nonce = nonce_for(hr, 'ews_dw_reveal_%d' % W1)
check('profile: a forged reveal link shows nothing without the permission', NID1 not in page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&worker=%d&reveal=1&_wpnonce=%s' % (W1, nonce)))
aprof = page(adm, '/wp-admin/admin.php?page=ews31-daily-workers&worker=%d' % W1)
rlink = link(aprof, 'reveal=1')
check('profile: the administrator (View national IDs) has Show', bool(rlink))
before = int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='dw_national_id_view'")[0]['n'])
rev = page(adm, rlink) if rlink else ''
check('reveal: the full number is shown', NID1 in rev)
check('reveal: written to the Audit Log (who, which worker), without the number', int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='dw_national_id_view' AND entity_id=%d" % W1)[0]['n']) == before + 1 and NID1 not in audit_text())

# Admin edit: other nationality, PIN, rating.
ep = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&edit=%d' % W3)
ne = form_nonce(ep, 'ews_dw_admin_worker')
qs = post(hr, {'action': 'ews_dw_admin_worker', '_wpnonce': ne, 'id': W3, 'name': 'Ali Hassan', 'mobile': '01011112222', 'trade': 'Helper', 'daily_rate': '300', 'hourly_rate': '40',
               'nid_kind': 'other', 'nid': 'A1234567', 'rating': '4', 'status': 'active', 'pin': '4321', 'site': Bsite}, files={})
check('admin edit: saved (passport number, PIN, rating, moved to B)', qs.get('done') == 'saved', qs)
w3 = q("SELECT * FROM {p}ews_dw_workers WHERE id=%d" % W3)[0]
check('admin edit: passport stored sealed and masked', w3['nid_kind'] == 'other' and 'A1234567' not in json.dumps(w3) and w3['nid_last'] == '67' and w3['pin_hash'] and '4321' not in w3['pin_hash'])
check('admin edit: PIN must be digits', post(hr, {'action': 'ews_dw_admin_worker', '_wpnonce': ne, 'id': W3, 'name': 'Ali Hassan', 'daily_rate': '300', 'pin': '12a'}, files={}).get('dw_error') == 'pin')

# ---------------------------------------------------------------- the day sheet
dpg = app(fm, 'sites', site=A)
check('day sheet: two workers (Ali moved to B)', 'Mohamed Abdallah' in dpg and 'Ahmed Shaaban' in dpg and 'Ali Hassan' not in dpg)
check('day sheet: the camera photo input', 'capture="environment"' in dpg)
ns = form_nonce(dpg, 'ews_dw_sheet')
marks = {'mark[%d]' % W1: 'in', 'extra[%d]' % W1: '2', 'mark[%d]' % W2: 'half', 'extra[%d]' % W2: '0'}
base = dict({'action': 'ews_dw_sheet', '_wpnonce': ns, 'site': A}, **marks)
check('sheet: outside the site is refused', post(fm, dict(base, **pos(30.05, 31.47)), {'photo': PNG}).get('dw_error') == 'outside')
check('sheet: a stale phone clock is refused', post(fm, dict(base, **pos(stale=True)), {'photo': PNG}).get('dw_error') == 'location')
check('sheet: no position is refused', post(fm, dict(base), {'photo': PNG}).get('dw_error') == 'location')
check('sheet: no group photo is refused', post(fm, dict(base, **pos()), {}).get('dw_error') == 'photo')
check('sheet: a bad mark is refused', post(fm, dict(base, **dict(pos(), **{'mark[%d]' % W2: 'maybe'})), {'photo': PNG}).get('dw_error') == 'mark')
check('sheet: the foreman cannot record site B', post(fm, dict(base, site=Bsite, **pos(30.0401, 31.4801)), {'photo': PNG}).get('dw_error') == 'access')
check('nothing was saved by the refused attempts', not q("SELECT * FROM {p}ews_dw_sheets") and not q("SELECT * FROM {p}ews_dw_days"))
qs = post(fm, dict(base, **pos()), {'photo': PNG})
check('sheet: saved from inside with a photo', 'dw_saved' in qs, qs)
d1, d2 = day_row(W1), day_row(W2)
check('pay: present with 2 extra hours = 450 + 2 × 50 (company hourly rate)', d1['mark'] == 'in' and float(d1['amount']) == 550.0, d1)
check('pay: half day = 300 ÷ 2', d2['mark'] == 'half' and float(d2['amount']) == 150.0, d2)
sh = q("SELECT * FROM {p}ews_dw_sheets")[0]
check('sheet: position, distance, integrity and the photo kept outside the public uploads', sh['integrity_status'] == 'verified' and float(sh['distance_meters']) < 150 and sh['photo_key']
      and php("$d=trailingslashit(wp_upload_dir()['basedir']).'workforce-one-daily-workers/'; echo (file_exists($d.'.htaccess') && file_exists($d.%s))?'yes':'no';" % json.dumps(sh['photo_key'])).strip() == 'yes')
check('sheet: saving again is refused (locked)', post(fm, dict(base, **pos()), {'photo': PNG}).get('dw_error') == 'locked')
locked = app(fm, 'sites', site=A)
furl = link(locked, 'ews_dw_file')
check('sheet photo: opens for the foreman, not for an employee', bool(furl) and fm.req(furl)[0] == 200 and emp.req(furl)[0] != 200)
check('day sheet: locked, with "Ask to change"', 'wfo-dw-locked' in locked and 'data-dw-more="change"' in locked and 'wfo-dw-confirm' not in locked)
check('popup after saving', 'Day sheet saved' in app(fm, 'sites', site=A, dw_saved=sh['id']))
check('My sites: recorded', 'Recorded' in app(fm, 'sites'))

# A change to the saved day: a request, approved by a manager; the original values stay with it.
nc = form_nonce(locked, 'ews_dw_change')
check('change: a reason is required', post(fm, {'action': 'ews_dw_change', '_wpnonce': nc, 'day': d2['id'], 'mark': 'in', 'extra': '0', 'reason': ''}).get('dw_error') == 'reason')
qs = post(fm, {'action': 'ews_dw_change', '_wpnonce': nc, 'day': d2['id'], 'mark': 'in', 'extra': '1', 'reason': 'He stayed the whole day'})
check('change: requested; the day is unchanged until approved', 'dw_change' in qs and day_row(W2)['mark'] == 'half')
check('change: a second request for the same day waits', post(fm, {'action': 'ews_dw_change', '_wpnonce': nc, 'day': d2['id'], 'mark': 'out', 'extra': '0', 'reason': 'x'}).get('dw_error') == 'pending')
cpg = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&tab=changes')
cid = int(q("SELECT id FROM {p}ews_dw_changes")[0]['id'])
nd = form_nonce(cpg, 'ews_dw_change_decide', 'value="%d"' % cid)
check('changes tab lists it', 'He stayed the whole day' in cpg and bool(nd))
check('reject needs a note', post(hr, {'action': 'ews_dw_change_decide', '_wpnonce': nd, 'change': cid, 'decision': 'reject', 'note': ''}).get('dw_error') == 'note')
check('the foreman cannot decide', post(fm, {'action': 'ews_dw_change_decide', '_wpnonce': nd, 'change': cid, 'decision': 'approve'}) == {} and q("SELECT status FROM {p}ews_dw_changes")[0]['status'] == 'pending')
qs = post(hr, {'action': 'ews_dw_change_decide', '_wpnonce': nd, 'change': cid, 'decision': 'approve', 'note': ''})
c = q("SELECT * FROM {p}ews_dw_changes")[0]
check('approved: the day is changed and the amount recomputed (300 + 1 × 50)', qs.get('done') == 'approved' and day_row(W2)['mark'] == 'in' and float(day_row(W2)['amount']) == 350.0)
check('approved: the original values stay with the request', c['old_mark'] == 'half' and c['new_mark'] == 'in' and c['status'] == 'approved')

# ---------------------------------------------------------------- recording modes and the worker's own page
check('mode self: the foreman cannot record site B even when assigned',
      php("$wpdb->insert($p.'ews_dw_foremen',['location_id'=>%d,'user_id'=>%d]); echo 1;" % (Bsite, setup['fm'])) and
      post(fm, dict({'action': 'ews_dw_sheet', '_wpnonce': ns, 'site': Bsite, 'mark[%d]' % W3: 'in'}, **pos(30.0401, 31.4801)), {'photo': PNG}).get('dw_error') == 'mode_foreman')
anon = Session.__new__(Session)
anon.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(), __import__('e2e_support').NoRedirect)


def anon_req(path, data=None):
    try:
        r = anon.op.open(urllib.request.Request(B + path, data=urllib.parse.urlencode(data).encode() if data else None))
        return r.status, r.read().decode('utf-8', 'replace'), r.headers
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), e.headers


st_, login, _ = anon_req('/app/')
check('login page: "Daily worker? Sign in with your mobile" (a site allows self-service)', 'ews_view=worker' in login)
st_, wpg, _ = anon_req('/app/?ews_view=worker')
nl = form_nonce(wpg, 'ews_dw_worker_login')
check('worker page: mobile + PIN form, no WordPress login', 'name="pin"' in wpg and bool(nl))


def anon_post(data):
    _, _, h = anon_req('/wp-admin/admin-post.php', data)
    return {k: v[0] for k, v in urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query).items()}


check('worker login: a wrong PIN is refused', anon_post({'action': 'ews_dw_worker_login', '_wpnonce': nl, 'mobile': '01011112222', 'pin': '1111'}).get('dw_error') == 'login')
anon_post({'action': 'ews_dw_worker_login', '_wpnonce': nl, 'mobile': '+20 101 111 2222', 'pin': '٤٣٢١'})
st_, me, _ = anon_req('/app/?ews_view=worker')
check('worker login: mobile (any format) + PIN (Arabic digits) signs him in', 'Ali Hassan' in me and 'I am at the site' in me, me[:400])
nself = form_nonce(me, 'ews_dw_self')
check('self sign-in: outside the site is refused', anon_post(dict({'action': 'ews_dw_self', '_wpnonce': nself}, **pos(30.06, 31.48))).get('dw_error') == 'outside')
qs = anon_post(dict({'action': 'ews_dw_self', '_wpnonce': nself}, **pos(30.0401, 31.4801)))
d3 = day_row(W3)
check('self sign-in: recorded at site B as present (source self), his own hourly rate kept', qs.get('dw_done') == '1' and d3 and d3['source'] == 'self' and d3['location_id'] == str(Bsite) and float(d3['hourly_rate']) == 40.0, qs)
check('self sign-in: twice is refused', anon_post(dict({'action': 'ews_dw_self', '_wpnonce': nself}, **pos(30.0401, 31.4801))).get('dw_error') == 'recorded')
check('self sign-in: a forged cookie is not a session', 'name="pin"' in (lambda o: o.open(urllib.request.Request(B + '/app/?ews_view=worker', headers={'Cookie': 'wfo_dw_worker=%d|9999999999|abc' % W3})).read().decode())(urllib.request.build_opener()))
php("$wpdb->update($p.'ews_dw_sites',['mode'=>'foreman'],['location_id'=>%d]); $wpdb->delete($p.'ews_dw_days',['worker_id'=>%d]);" % (Bsite, W3))
check('mode foreman: the worker cannot sign in himself', anon_post(dict({'action': 'ews_dw_self', '_wpnonce': nself}, **pos(30.0401, 31.4801))).get('dw_error') == 'mode_self')
php("$wpdb->update($p.'ews_dw_sites',['mode'=>'both'],['location_id'=>%d]);" % Bsite)
anon_post(dict({'action': 'ews_dw_self', '_wpnonce': nself}, **pos(30.0401, 31.4801)))
check('mode both: the worker signs in himself', day_row(W3) is not None and day_row(W3)['source'] == 'self')
bpg0 = app(fm, 'sites', site=Bsite)
check('impossible movement: site A seconds ago, site B 1.4 km away now: refused', post(fm, {'action': 'ews_dw_sheet', '_wpnonce': form_nonce(bpg0, 'ews_dw_sheet'), 'site': Bsite, 'mark[%d]' % W3: 'half', 'extra[%d]' % W3: '0', **pos(30.0401, 31.4801)}, {'photo': PNG}).get('dw_error') == 'suspicious')
# The foreman saved site A seconds ago, 1.4 km away: as if that was an hour ago (else it is an impossible movement).
php("$wpdb->query(\"UPDATE {$p}ews_dw_sheets SET saved_at='%s'\");" % (datetime.datetime.now() - datetime.timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S'))
bpg = app(fm, 'sites', site=Bsite)
check('mode both: the foreman sees "self sign-in" and can still confirm the day', 'self sign-in' in bpg and 'wfo-dw-confirm' in bpg)
qs = post(fm, {'action': 'ews_dw_sheet', '_wpnonce': form_nonce(bpg, 'ews_dw_sheet'), 'site': Bsite, 'mark[%d]' % W3: 'half', 'extra[%d]' % W3: '0', **pos(30.0401, 31.4801)}, {'photo': PNG})
check('mode both: the foreman\'s sheet sets the day (half day, his own rate)', 'dw_saved' in qs and day_row(W3)['mark'] == 'half' and day_row(W3)['source'] == 'foreman' and float(day_row(W3)['amount']) == 150.0, qs)
php("$wpdb->delete($p.'ews_dw_foremen',['location_id'=>%d,'user_id'=>%d]);" % (Bsite, setup['fm']))

# ---------------------------------------------------------------- move
php("$wpdb->query(\"DELETE FROM {$p}ews_dw_sheets WHERE location_id=%d\");" % Bsite)
# A worker added fresh at A, moved to B from tomorrow (history stays at A).
add(name='Ramadan Awad', daily_rate='450', trade='Formwork carpenter')
W4 = wid('Ramadan Awad')
check('move: the foreman cannot move from a sheet that is already saved today', 'data-dw-more="move"' not in app(fm, 'sites', site=A))
nm = nonce_for(fm, 'ews_dw_move')
check('move: a past date is refused', post(fm, {'action': 'ews_dw_move', '_wpnonce': nm, 'worker': W4, 'to': Bsite, 'from': '2020-01-01'}).get('dw_error') == 'date')
tomorrow = (today + datetime.timedelta(days=1)).isoformat()
check('move: to B from tomorrow', post(fm, {'action': 'ews_dw_move', '_wpnonce': nm, 'worker': W4, 'to': Bsite, 'from': tomorrow}).get('dw_moved') == '1')
check('move: still at A today, at B tomorrow, both rows kept', call('dw_worker_site', str(W4), "'%s'" % TODAY).strip() == str(A) and call('dw_worker_site', str(W4), "'%s'" % tomorrow).strip() == str(Bsite)
      and len(q("SELECT * FROM {p}ews_dw_moves WHERE worker_id=%d" % W4)) == 2)

# ---------------------------------------------------------------- advances and payout
ppg = app(cash, 'payout', site=A)
check('payout: the cashier sees the site\'s week', 'Mohamed Abdallah' in ppg and 'Not paid yet' in ppg)
check('payout: the employee cannot', 'wfo-dw-pay' not in app(emp, 'payout'))
nadv = form_nonce(ppg, 'ews_dw_advance')
check('advance: zero is refused', post(cash, {'action': 'ews_dw_advance', '_wpnonce': nadv, 'site': A, 'worker': W1, 'amount': '0'}).get('dw_error') == 'amount')
check('advance: recorded', post(cash, {'action': 'ews_dw_advance', '_wpnonce': nadv, 'site': A, 'worker': W1, 'amount': '200', 'note': 'transport'}).get('dw_adv') == '1')
check('advance: the Audit Log says so without the amount', 'Advance recorded' in audit_text() and ' 200' not in audit_text())
p = json.loads(call('dw_payout', str(A), "'%s'" % TODAY).splitlines()[-1])
l1 = p['lines'][str(W1)]
check('payout: amount 550, advance 200, net 350 for the worker with the advance', (l1['amount'], l1['advance'], l1['net']) == (550, 200, 350), l1)
check('payout: totals = 900 − 200 = 700', (p['amount'], p['advance'], p['net']) == (900, 200, 700), (p['amount'], p['advance'], p['net']))
ppg = app(cash, 'payout', site=A)
pdf_url = link(ppg, 'ews_dw_payout_pdf')
pst, pbody, ph = cash.req(pdf_url)
check('payout sheet: a PDF', pst == 200 and ph.get('Content-Type', '').startswith('application/pdf') and pbody.startswith('%PDF'), (pst, ph.get('Content-Type')))
npd = form_nonce(ppg, 'ews_dw_paid')
# Paying locks the period, so a week that is still running cannot be paid yet (3.31.83).
if p['end'] > TODAY:
    check('paid: a week not over yet is refused', post(cash, {'action': 'ews_dw_paid', '_wpnonce': npd, 'site': A, 'start': p['start']}, {'photo': PNG}).get('dw_error') == 'open')
    check('paid: the page says why instead of the Paid button', 'not over yet' in ppg and 'data-dw-paid-form' not in ppg)
# The rest pays the site daily: today is the period's last day and today's sheet is saved.
php("$wpdb->update($p.'ews_dw_sites',['period'=>'daily'],['location_id'=>%d]);" % A)
p = json.loads(call('dw_payout', str(A), "'%s'" % TODAY).splitlines()[-1])
check('paid: the day\'s payout has the same figures', (p['amount'], p['advance'], p['net']) == (900, 200, 700), (p['amount'], p['advance'], p['net']))
check('paid: the signed sheet photo is required', post(cash, {'action': 'ews_dw_paid', '_wpnonce': npd, 'site': A, 'start': p['start']}, {}).get('dw_error') == 'photo')
check('paid: an employee cannot', post(emp, {'action': 'ews_dw_paid', '_wpnonce': nonce_for(emp, 'ews_dw_paid'), 'site': A, 'start': p['start']}, {'photo': PNG}).get('dw_error') == 'access')
qs = post(cash, {'action': 'ews_dw_paid', '_wpnonce': npd, 'site': A, 'start': p['start']}, {'photo': PNG})
check('paid: recorded', 'dw_paid' in qs, qs)
po = q("SELECT * FROM {p}ews_dw_payouts")[0]
check('paid: the payout and its lines are stored; the days carry the payout', float(po['net']) == 700 and len(q("SELECT * FROM {p}ews_dw_lines WHERE payout_id=%s" % po['id'])) == 2
      and q("SELECT COUNT(*) n FROM {p}ews_dw_days WHERE location_id=%d AND payout_id=%s" % (A, po['id']))[0]['n'] == '2')
check('paid: the Audit Log says "Payout recorded" without the figures', 'Payout recorded' in audit_text() and '700' not in audit_text() and '550' not in audit_text())
check('paid: paying again is refused', post(cash, {'action': 'ews_dw_paid', '_wpnonce': npd, 'site': A, 'start': p['start']}, {'photo': PNG}).get('dw_error') == 'paid')
check('paid: a change to a paid day is refused', post(fm, {'action': 'ews_dw_change', '_wpnonce': nc, 'day': d1['id'], 'mark': 'half', 'extra': '0', 'reason': 'x'}).get('dw_error') == 'paid')
check('paid: the page shows it locked', 'Paid · locked' in app(cash, 'payout', site=A))
check('paid: the advance is used up (none outstanding)', json.loads(call('dw_outstanding', '[%d]' % W1, "'%s'" % TODAY).splitlines()[-1]).get(str(W1), 0) == 0)

# ---------------------------------------------------------------- never mixed with staff
# The administrator's own notifications (change requests) name workers by design; clear them so only the screens count.
php("$wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
names = ['Mohamed Abdallah', 'Ahmed Shaaban', 'Ali Hassan', 'Ramadan Awad']
for label, s, path in [('wp-admin Employees', adm, '/wp-admin/admin.php?page=ews31-employees'), ('app Attendance', adm, '/app/?ews_view=attendance'),
                       ('app Employees', adm, '/app/?ews_view=employees'), ('Payroll', adm, '/wp-admin/admin.php?page=ews31-payroll'),
                       ('Reports', adm, '/app/?ews_view=reports'), ('Sign In / Out Report', adm, '/wp-admin/admin.php?page=ews31-time-report'),
                       ('People', adm, '/app/?ews_view=people'), ('Team Schedule', adm, '/app/?ews_view=schedule')]:
    pg_ = page(s, path)
    check('staff screens: no daily worker in %s' % label, not any(nm_ in pg_ for nm_ in names))

# ---------------------------------------------------------------- reports and exports
rpg = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&tab=reports&from=%s&to=%s' % (TODAY[:8] + '01', TODAY))
check('reports: by site, trade, subcontractor, heat map, unpaid', 'Workers per site and day' in rpg and 'By trade' in rpg and 'Unpaid balances' in rpg and 'Garden Heights' in rpg)
rep = json.loads(call('dw_report', "'%s'" % (TODAY[:8] + '01'), "'%s'" % TODAY).splitlines()[-1])
total = float(q("SELECT COALESCE(SUM(amount),0) s FROM {p}ews_dw_days WHERE work_date BETWEEN '%s' AND '%s'" % (TODAY[:8] + '01', TODAY))[0]['s'])
check('reports: the total equals the sum of the days', abs(rep['total'] - total) < 0.01 and abs(sum(float(r['amount']) for r in rep['by_site']) - total) < 0.01 and abs(sum(float(r['amount']) for r in rep['by_trade']) - total) < 0.01, (rep['total'], total))
check('reports: the unpaid list is site B\'s half day only', [r['name'] for r in rep['unpaid']] == ['Ali Hassan'])
for fmt, sig in [('xlsx', 'PK'), ('pdf', '%PDF')]:
    u = link(rpg, 'format=%s' % fmt)
    st_, body_, h_ = hr.req(u)
    check('export %s' % fmt, st_ == 200 and body_.startswith(sig), (st_, h_.get('Content-Type')))
u = link(rpg, 'format=insurance')
csv_hr = hr.req(u)[1]
check('insurance report: masked for a manager without "View national IDs"', 'Mohamed Abdallah' in csv_hr and '2900••••••1234' in csv_hr and NID1 not in csv_hr)
csv_adm = adm.req(link(page(adm, '/wp-admin/admin.php?page=ews31-daily-workers&tab=reports'), 'format=insurance'))[1]
check('insurance report: full for the administrator, and audited', NID1 in csv_adm and 'with full national IDs' in audit_text())

# ---------------------------------------------------------------- API, delete, uninstall
st_, idx, _ = adm.req('/wp-json/workforce-one/v1')
check('API: the daily workers routes are registered', all(r in idx for r in ['daily-workers\\/sites', 'day', 'payout']))
dp = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&worker=%d' % W1)
check('delete: a worker with paid days is kept', post(hr, {'action': 'ews_dw_worker_delete', '_wpnonce': form_nonce(dp, 'ews_dw_worker_delete'), 'id': W1}).get('dw_error') == 'has_paid')
dp = page(hr, '/wp-admin/admin.php?page=ews31-daily-workers&worker=%d' % W4)
qs = post(hr, {'action': 'ews_dw_worker_delete', '_wpnonce': form_nonce(dp, 'ews_dw_worker_delete'), 'id': W4})
check('delete: a worker without paid days goes with his national ID, days and moves', qs.get('done') == 'deleted' and not q("SELECT * FROM {p}ews_dw_workers WHERE id=%d" % W4) and not q("SELECT * FROM {p}ews_dw_moves WHERE worker_id=%d" % W4))
un = open(os.path.join(HERE, '..', 'workforce-one', 'uninstall.php')).read()
check('uninstall removes the tables and the photo folder', 'ews_dw_workers' in un and 'ews_dw_changes' in un and 'workforce-one-daily-workers' in un)
dbg = php("$f=WP_CONTENT_DIR.'/debug.log'; echo file_exists($f)?substr((string)file_get_contents($f),-20000):'';")
check('no PHP fatal errors', 'PHP Fatal' not in dbg)

# Leave the switch off for the next tests.
php("update_option('ews_feature_daily_workers',0,false);")
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)
