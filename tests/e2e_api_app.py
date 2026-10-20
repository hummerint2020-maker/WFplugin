"""Mobile app API 1 (3.31.113): GET /me/bootstrap, GET /me/schedule, GET /attendance/today.

Read only, off until the Mobile app API switch is on; the employee is always the one of the token; the same rules and the same days as the
Web (docs/tasks/mobile-api-1.md). Every real response body is also validated against docs/api/openapi.yaml (tests/check_openapi.py).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_api_app.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data and changes the site time zone (restored at the end); never run against a real site.
"""
import json, os, sys, time, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, check, php, q, results, wp, HERE  # noqa: E402
import check_openapi  # noqa: E402

API = B + '/wp-json/workforce-one/v1'
SPEC = check_openapi.load()
opener = urllib.request.build_opener(type('NoRedirect', (urllib.request.HTTPRedirectHandler,), {'redirect_request': lambda *a, **k: None}))
DEVICE = {'installation_id': 'e2e-app-install-0001', 'platform': 'android', 'model': 'Pixel 8', 'app_version': '1.0.0'}


def call(path, body=None, token=None):
    h = {'Content-Type': 'application/json'}
    if token is not None:
        h['Authorization'] = 'Bearer ' + token
    r = urllib.request.Request(API + path, data=json.dumps(body).encode() if body is not None else None, headers=h, method='POST' if body is not None else 'GET')
    try:
        resp = opener.open(r)
        status, raw, hd = resp.status, resp.read().decode(), dict(resp.headers)
    except urllib.error.HTTPError as e:
        status, raw, hd = e.code, e.read().decode(), dict(e.headers)
    try:
        data = json.loads(raw) if raw else None
    except ValueError:
        data = raw
    return status, data, hd


def code(r):
    st, body, _ = r
    return (st, body['error']['code']) if isinstance(body, dict) and 'error' in body else (st, 'ok' if isinstance(body, dict) and body.get('success') else body)


def login(user, pwd, n=1):
    st, body, _ = call('/auth/login', {'username': user, 'password': pwd, 'device': dict(DEVICE, installation_id='e2e-app-install-%04d' % n)})
    return body['data']['access_token']


def contract(name, path, method, r):
    """The real body validates against the documented schema."""
    problems = check_openapi.validate_response(SPEC, path, method, r[0], r[1])
    check('%s: the response matches docs/api/openapi.yaml' % name, not problems, problems[:3])


def reset():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    return json.loads(php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        foreach(['ews_api_devices','ews_api_tokens','ews_api_rate_limits'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('timezone_string','Africa/Cairo'); update_option('gmt_offset','');
        foreach(['ews_feature_app_api','ews_feature_tasks','ews_feature_overtime','ews_feature_corrections','ews_feature_breaks','ews_feature_branches'] as $o) delete_option($o);
        delete_option('ews_frontend_navigation'); delete_option('ews_shifts'); delete_option('ews_branch_settings');
        update_option('ews_feature_face_signin',0,false); update_option('ews_presence_qr_signin',0,false);
        $wpdb->query("DELETE FROM {$p}ews_company_calendar"); $wpdb->query("DELETE FROM {$p}ews_schedule"); $wpdb->query("DELETE FROM {$p}ews_time_logs");
        $wpdb->update($p.'ews_employees',['active'=>1,'attendance_enabled'=>1,'default_shift_id'=>0],['id'=>$eid]);
        $uid2=username_exists('emp2')?:wp_create_user('emp2','emp2pass','emp2@example.com');
        $wpdb->insert($p.'ews_employees',['name'=>'Emp Two','domain_name'=>'emp2','email'=>'emp2@example.com','wp_user_id'=>$uid2,'active'=>1,'attendance_enabled'=>1]);
        $e2=(int)$wpdb->insert_id;
        echo wp_json_encode(['eid'=>$eid,'e2'=>$e2,'today'=>current_time('Y-m-d'),'h'=>(int)current_time('G')]);
    """).splitlines()[-1])


def seed_days(eid, start, n, status='Office'):
    php("""$eid=%d; for($i=0;$i<%d;$i++){ $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>date('Y-m-d',strtotime('%s +'.$i.' day')),'status'=>'%s']); }""" % (eid, n, start, status))


def count_queries(token, route, params=None):
    out = php("""$c=0; add_filter('query',function($q)use(&$c){$c++;return $q;});
        $r=new WP_REST_Request('GET','/workforce-one/v1%s'); $r->set_header('Authorization','Bearer %s'); $r->set_query_params(json_decode('%s',true)?:array()); $res=rest_do_request($r); echo 'N='.$c.' S='.$res->get_status();"""
              % (route, token, json.dumps(params or {})))
    last = out.splitlines()[-1]
    return int(last.split()[0][2:]), int(last.split()[1][2:])


ids = reset()
eid, e2, today = ids['eid'], ids['e2'], ids['today']
ADM = login('admin', 'admin', 9)
EMP = login('emp1', 'emp1pass', 1)
ROUTES = [('/me/bootstrap', 'get'), ('/me/schedule', 'get'), ('/attendance/today', 'get')]

# ---------------------------------------------------------------- off by default
for path, m in ROUTES:
    r = call(path, token=EMP)
    check('switch off: %s is 403 FORBIDDEN, details.reason "disabled"' % path, code(r) == (403, 'FORBIDDEN') and r[1]['error']['details'] == {'reason': 'disabled'}, r[1])
    contract('switch off ' + path, path, m, r)
me_off = call('/me', token=EMP)
check('...and /me and /meta are unchanged', code(me_off) == (200, 'ok') and code(call('/meta'))[0] == 200)
php("update_option('ews_feature_app_api',1,false);")

# ---------------------------------------------------------------- bootstrap
seed_days(eid, today, 1)
st, boot, hd = call('/me/bootstrap', token=EMP)
r = (st, boot, hd)
check('bootstrap: 200', code(r) == (200, 'ok'), boot)
contract('bootstrap', '/me/bootstrap', 'get', r)
me = call('/me', token=EMP)[1]['data']
d = boot['data']
check('...it is the /me document on user, employee, roles, permissions and device', all(d[k] == me[k] for k in ['user', 'employee', 'roles', 'permissions', 'device']))
meta = call('/meta')[1]['data']
check('...the features are the ones /meta publishes', d['features'] == meta['features'])
check('...site facts are those of the site (Cairo time, today, working days)', d['site']['timezone'] == 'Africa/Cairo' and d['site']['today'] == today and d['site']['working_days'] == [0, 1, 2, 3, 4, 5, 6], d['site'])
check('...Cache-Control no-store and a request id', 'no-store' in hd.get('Cache-Control', '') and hd.get('X-WFO-Request-Id'))
keys = [s['key'] for s in d['screens']]
check('...the screens are the phone menu: dashboard, schedule, Sign In, leave; no approvals count or link', keys[:4] == ['dashboard', 'schedule', 'time', 'vacation'] and all(set(s) == {'key', 'label', 'order'} for s in d['screens']), d['screens'])
check('...tasks, overtime and corrections are not there while off', not ({'tasks', 'overtime', 'corrections'} & set(keys)), keys)
php("""update_option('ews_feature_overtime',1,false); update_option('ews_feature_corrections',1,false);
       update_option('ews_frontend_navigation',['overtime'=>['mobile_visible'=>1],'corrections'=>['mobile_visible'=>1],'schedule'=>['mobile_label'=>'My week']],false);""")
keys2 = {s['key']: s['label'] for s in call('/me/bootstrap', token=EMP)[1]['data']['screens']}
check('...overtime and corrections appear when on and visible on the phone, and a View Navigation label is followed', 'overtime' in keys2 and 'corrections' in keys2 and keys2.get('schedule') == 'My week', keys2)
check('...the flags follow the switches', call('/me/bootstrap', token=EMP)[1]['data']['features']['overtime'] is True)
check('...a colleague\'s menu entry that needs permission is not listed for an employee', 'attendance' not in keys2 and 'employees' not in keys2, keys2)
adm_boot = call('/me/bootstrap', token=ADM)
contract('admin bootstrap', '/me/bootstrap', 'get', adm_boot)
check('an administrator with no employee record: 200, employee null, same permissions as /me', code(adm_boot) == (200, 'ok') and adm_boot[1]['data']['employee'] is None
      and adm_boot[1]['data']['permissions'] == call('/me', token=ADM)[1]['data']['permissions'])
for path in ['/me/schedule', '/attendance/today']:
    r = call(path, token=ADM)
    check('...%s for them is 403 ACCOUNT_NOT_LINKED' % path, code(r) == (403, 'ACCOUNT_NOT_LINKED'), r[1])
php("delete_option('ews_feature_overtime'); delete_option('ews_feature_corrections'); delete_option('ews_frontend_navigation');")

# ---------------------------------------------------------------- inactive account
php("$wpdb->update($p.'ews_employees',['active'=>0],['id'=%d]);".replace("['id'=%d]", "['id'=>%d]") % eid)
for path, m in ROUTES:
    r = call(path, token=EMP)
    check('an archived employee: %s is 403 ACCOUNT_DISABLED' % path, code(r) == (403, 'ACCOUNT_DISABLED'), r[1])
php("$wpdb->update($p.'ews_employees',['active'=>1],['id'=>%d]);" % eid)
EMP = login('emp1', 'emp1pass', 2)

# ---------------------------------------------------------------- schedule: another employee's data, no schedule, range
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule\");")
for i in range(3):
    php("""$d=date('Y-m-d',strtotime('%s +%d day'));
           $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>$d,'status'=>'Office']);
           $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>$d,'status'=>'WFH']);""" % (today, i, eid, e2))
r = call('/me/schedule?from=%s&to=%s' % (today, php("echo date('Y-m-d',strtotime('%s +2 day'));" % today).strip()), token=EMP)
contract('schedule', '/me/schedule', 'get', r)
days = r[1]['data']['days']
check('schedule: the three days are the employee\'s own (Office), not the colleague\'s (WFH)', code(r) == (200, 'ok') and [x['status'] for x in days] == ['Office'] * 3, [x['status'] for x in days])
check('...each has a shift with ISO times at +02:00/+03:00 and the site timezone', all(x['shift'] and x['shift']['starts_at'][-6:] in ('+02:00', '+03:00') for x in days) and r[1]['data']['timezone'] == 'Africa/Cairo', days[0]['shift'])
for param in ['employee_id=%d' % e2, 'user_id=1', 'employee=%d' % e2, 'user=1', 'eid=%d' % e2]:
    for path in ['/me/schedule', '/attendance/today', '/me/bootstrap']:
        r = call(path + '?' + param, token=EMP)
        name = param.split('=')[0]
        check('%s?%s is refused (400, fields.%s not_allowed)' % (path, param, name), code(r) == (400, 'VALIDATION_FAILED') and r[1]['error']['details']['fields'] == {name: 'not_allowed'}, r[1])
        if param.startswith('employee_id'):
            contract('identity parameter ' + path, path, 'get', r)
leak = json.dumps(call('/me/schedule?from=%s&to=%s' % (today, today), token=EMP)[1])
check('...and no colleague data in the answer', 'WFH' not in leak and 'Emp Two' not in leak)
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule\");")
r = call('/me/schedule', token=EMP)
check('an employee with no schedule: 200, seven days, all "Not Set", no shift, no branch', code(r) == (200, 'ok') and len(r[1]['data']['days']) == 7 and all(x['status'] == 'Not Set' and x['shift'] is None and x['branch'] is None and not x['requires_sign_in'] for x in r[1]['data']['days']), r[1])
check('...the week is the Web\'s week (a calendar week that contains today)', r[1]['data']['from'] <= today <= r[1]['data']['to'])
contract('schedule without parameters', '/me/schedule', 'get', r)
check('from alone: seven days from it', len(call('/me/schedule?from=2026-10-19', token=EMP)[1]['data']['days']) == 7)
for qs, field in [('?to=2026-10-19', 'from'), ('?from=2026-02-30&to=2026-03-02', 'from'), ('?from=19-10-2026', 'from'), ('?from=2026-10-20&to=2026-10-19', 'to'), ('?from=2026-10-01&to=2026-11-12', 'to')]:
    r = call('/me/schedule' + qs, token=EMP)
    check('/me/schedule%s: 400 on "%s"' % (qs, field), code(r) == (400, 'VALIDATION_FAILED') and field in r[1]['error']['details']['fields'], r[1])
    contract('schedule error ' + qs, '/me/schedule', 'get', r)
check('42 days are allowed', len(call('/me/schedule?from=2026-10-01&to=2026-11-11', token=EMP)[1]['data']['days']) == 42)
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>'%s','title'=>'Armed Forces Day','event_type'=>'general_leave','active'=>1]);" % today)
seed_days(eid, today, 1)
r = call('/me/schedule?from=%s&to=%s' % (today, today), token=EMP)
x = r[1]['data']['days'][0]
check('a company holiday wins over the schedule row: "General Leave", no shift', x['status'] == 'General Leave' and x['general_leave'] and x['shift'] is None and not x['requires_sign_in'], x)
php("$wpdb->query(\"DELETE FROM {$p}ews_company_calendar\");")

# ---------------------------------------------------------------- query counts
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule\");")
seed_days(eid, today + ' -10 day', 70)
EMP = login('emp1', 'emp1pass', 3)
n7, s7 = count_queries(EMP, '/me/schedule', {'from': today, 'to': php("echo date('Y-m-d',strtotime('%s +6 day'));" % today).strip()})
n42, s42 = count_queries(EMP, '/me/schedule', {'from': today, 'to': php("echo date('Y-m-d',strtotime('%s +41 day'));" % today).strip()})
nb, sb = count_queries(EMP, '/me/bootstrap')
nt, st_ = count_queries(EMP, '/attendance/today')
check('queries: all three answer 200 in-process', (s7, s42, sb, st_) == (200, 200, 200, 200), (s7, s42, sb, st_))
check('queries: a week costs %d and six weeks %d: the same, whatever the number of days' % (n7, n42), n7 <= 40 and n42 == n7, (n7, n42))
check('queries: bootstrap %d and today %d stay small' % (nb, nt), nb <= 30 and nt <= 30, (nb, nt))
before = count_queries(login('emp1', 'emp1pass', 4), '/attendance/today')[0]
php("""for($i=0;$i<300;$i++){ $wpdb->insert($p.'ews_employees',['name'=>'Bulk '.$i,'domain_name'=>'bulk'.$i,'email'=>'bulk'.$i.'@example.com','active'=>1,'attendance_enabled'=>1]); }""")
after = count_queries(login('emp1', 'emp1pass', 5), '/attendance/today')[0]
check('queries: 300 more employees change nothing (%d vs %d)' % (before, after), before == after, (before, after))
php("$wpdb->query(\"DELETE FROM {$p}ews_employees WHERE domain_name LIKE 'bulk%\"); ")

# ---------------------------------------------------------------- today
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule\"); $wpdb->query(\"DELETE FROM {$p}ews_time_logs\");")
EMP = login('emp1', 'emp1pass', 6)
r = call('/attendance/today', token=EMP)
contract('today without a schedule', '/attendance/today', 'get', r)
t = r[1]['data']
check('today, no schedule: day_type not_working, Sign In refused with NOT_SCHEDULED', t['day_type'] == 'not_working' and t['schedule'] is None and t['actions']['sign_in'] == {'allowed': False, 'reason': 'NOT_SCHEDULED'} and t['clock'] == 'out', t)
php("""$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>current_time('Y-m-d'),'status'=>'Office']);
       update_option('ews_working_hours',['start'=>sprintf('%%02d:00',max(0,(int)current_time('G')-1)),'normal_until'=>'23:59','end'=>'23:59'],false);""" % eid)
r = call('/attendance/today', token=EMP)
contract('today, open window', '/attendance/today', 'get', r)
t = r[1]['data']
check('today, Office day inside the window: Sign In allowed, Sign Out not (NOT_SIGNED_IN), work_date is today', t['day_type'] == 'working' and t['actions']['sign_in']['allowed'] and t['actions']['sign_out']['reason'] == 'NOT_SIGNED_IN' and t['work_date'] == today and not t['carried_over'], t)
check('...the shift and the window carry the site offset', t['shift']['starts_at'][-6:] in ('+02:00', '+03:00') and t['sign_in_window']['open'] is True, t['shift'])
check('...breaks are off', t['breaks'] == {'enabled': False} and t['actions']['break_start']['reason'] == 'BREAKS_DISABLED')
php("""$wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>1,'work_date'=>current_time('Y-m-d'),'event_type'=>'sign_in','event_at'=>current_time('mysql'),'scheduled_status'=>'Office']);""" % eid)
t = call('/attendance/today', token=EMP)[1]['data']
check('signed in: clock "in", Sign Out allowed, Sign In refused (already signed in), sign_in has a time', t['clock'] == 'in' and t['actions']['sign_out']['allowed'] and t['actions']['sign_in']['reason'] == 'ALREADY_SIGNED_IN' and t['sign_in']['at'], t)
php("""update_option('ews_feature_breaks',1,false);""")
t = call('/attendance/today', token=EMP)[1]['data']
check('breaks on: Break allowed, no open break to end, counts shown', t['breaks']['enabled'] and t['actions']['break_start']['allowed'] and t['actions']['break_end']['reason'] == 'NO_OPEN_BREAK' and t['breaks']['used'] == 0 and t['breaks']['remaining'] == t['breaks']['per_day'], t['breaks'])
php("""$wpdb->insert($p.'ews_break_sessions',['employee_id'=>%d,'user_id'=>1,'work_date'=>current_time('Y-m-d'),'start_at'=>current_time('mysql'),'status'=>'Open','created_at'=>current_time('mysql')]);""" % eid)
t = call('/attendance/today', token=EMP)[1]['data']
contract('today on a break', '/attendance/today', 'get', (200, {'success': True, 'data': t, 'meta': {'request_id': 'x', 'server_time': '2026-10-20T10:00:00+03:00', 'api_version': '1.0'}}))
check('on a break: clock "break", End break allowed, an open break with its start', t['clock'] == 'break' and t['actions']['break_end']['allowed'] and t['breaks']['open'] and t['actions']['sign_out']['reason'] == 'ON_BREAK', t['breaks'])
php("""$wpdb->query("DELETE FROM {$p}ews_break_sessions"); $wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>1,'work_date'=>current_time('Y-m-d'),'event_type'=>'sign_out','event_at'=>current_time('mysql'),'scheduled_status'=>'Office']);""" % eid)
t = call('/attendance/today', token=EMP)[1]['data']
check('signed out: clock "done", Sign Out refused (already signed out), sign_out has a time', t['clock'] == 'done' and t['actions']['sign_out']['reason'] == 'ALREADY_SIGNED_OUT' and t['sign_out'] and t['sign_in'], t)
php("update_option('ews_feature_breaks',0,false); $wpdb->query(\"DELETE FROM {$p}ews_time_logs\"); $wpdb->update($p.'ews_employees',['attendance_enabled'=>0],['id'=>%d]);" % eid)
t = call('/attendance/today', token=EMP)[1]['data']
check('attendance switched off for the employee (breaks off too): 200, day_type attendance_disabled, Sign In / Out ATTENDANCE_DISABLED, the break rules say BREAKS_DISABLED first, as the Web', t['day_type'] == 'attendance_disabled' and t['actions']['sign_in']['reason'] == 'ATTENDANCE_DISABLED' and t['actions']['sign_out']['reason'] == 'ATTENDANCE_DISABLED' and t['actions']['break_start']['reason'] == 'BREAKS_DISABLED' and t['actions']['break_end']['reason'] == 'BREAKS_DISABLED', t['actions'])
php("update_option('ews_feature_breaks',1,false);")
t = call('/attendance/today', token=EMP)[1]['data']
check('...with breaks on, the break actions say ATTENDANCE_DISABLED', t['actions']['break_start']['reason'] == 'ATTENDANCE_DISABLED' and t['actions']['break_end']['reason'] == 'ATTENDANCE_DISABLED', t['actions'])
php("update_option('ews_feature_breaks',0,false);")
php("$wpdb->update($p.'ews_employees',['attendance_enabled'=>1],['id'=>%d]);" % eid)

# ---------------------------------------------------------------- the same answer as the Web: overnight carry-over
local_h = int(ids['h'])
yesterday = php("echo date('Y-m-d',strtotime(current_time('Y-m-d').' -1 day'));").strip()
if local_h <= 21:
    end = '%02d:59' % (local_h + 1)
    php("""$s=array(['id'=>88,'name'=>'Night','start'=>'23:59','end'=>'%s','grace'=>10,'overnight'=>1,'active'=>1]); update_option('ews_shifts',$s,false);
        $wpdb->update($p.'ews_employees',['default_shift_id'=>88],['id'=>%d]);
        $wpdb->query("DELETE FROM {$p}ews_schedule"); $wpdb->query("DELETE FROM {$p}ews_time_logs");
        $wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>'%s','status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>%d,'user_id'=>1,'work_date'=>'%s','event_type'=>'sign_in','event_at'=>'%s 23:59:00','scheduled_status'=>'Office']);""" % (end, eid, eid, yesterday, eid, yesterday, yesterday))
    EMP = login('emp1', 'emp1pass', 7)
    r = call('/attendance/today', token=EMP)
    contract('today, overnight', '/attendance/today', 'get', r)
    t = r[1]['data']
    web_day = php("wp_set_current_user(%d); $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod($o,'attendance_day'); $m->setAccessible(true); echo $m->invoke($o,%d);" % (json.loads(open(os.path.join(os.environ['S'], 'ids.json')).read())['uid'], eid)).strip()
    check('overnight shift after midnight: the work day is yesterday (the Web\'s attendance_day()), carried over', t['work_date'] == yesterday == web_day and t['carried_over'] is True, (t['work_date'], web_day, yesterday))
    check('...clock "in", Sign Out allowed, and the shift ends today at the shift end with the site offset', t['clock'] == 'in' and t['actions']['sign_out']['allowed'] and t['shift']['overnight'] is True
          and t['shift']['starts_at'].startswith(yesterday + 'T23:59:00') and t['shift']['ends_at'].startswith(today + 'T' + end + ':00'), t['shift'])
    sched = call('/me/schedule?from=%s&to=%s' % (yesterday, yesterday), token=EMP)[1]['data']['days'][0]
    check('...the schedule gives that night the same span', sched['shift'] == t['shift'], (sched['shift'], t['shift']))
else:
    print('SKIP overnight carry-over (needs the local hour <= 21)')

php("update_option('timezone_string','UTC'); update_option('gmt_offset',0); delete_option('ews_feature_app_api'); delete_option('ews_shifts');")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
