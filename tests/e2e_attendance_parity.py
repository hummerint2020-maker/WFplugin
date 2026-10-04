"""Attendance parity: pins the Web Sign In / Sign Out, QR Sign-In, Face token and Break behaviour
end to end and writes a normalised trace of what each action did, so two versions of the code
can be compared exactly (written for the 3.31.46 AttendanceService extraction).

For every step the trace records the redirect message, the new ews_time_logs rows, Audit Log rows,
break sessions, achievement awards, notifications and break cron events, with times and ids
replaced by stable placeholders.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_attendance_parity.py <state-dir> [trace.json]
Needs tests/e2e_setup.php users (emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, json, os, re, subprocess, sys, time, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import S, WP, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

TRACE_FILE = sys.argv[2] if len(sys.argv) > 2 else os.path.join(S, 'attendance_trace.json')
CAIRO = {'latitude': '30.0445', 'longitude': '31.2358'}   # ~15 m from Cairo HQ (radius 200 m)
ALEX = {'latitude': '31.2001', 'longitude': '29.9187'}    # ~180 km away
trace = []


def utc_now():
    return datetime.datetime.utcnow()


def hhmm(dt):
    return dt.strftime('%H:%M')


# ---------------------------------------------------------------- site state
def seed(start=None, end='23:59', status='Office', offset_hours=None, **options):
    """Fresh employee, today's schedule, Cairo HQ; hours start 1 h ago unless given (site time)."""
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    # Run at a fixed time of day (site time around 12:00) whatever the real clock says.
    set_offset(BASE_OFFSET if offset_hours is None else offset_hours)
    php("$wpdb->update($p.'ews_schedule',['work_date'=>current_time('Y-m-d')],['employee_id'=>%d]);" % ids()['eid'])
    start = start or hhmm(local_now() - datetime.timedelta(hours=1))
    opts = {'ews_feature_face_signin': 0, 'ews_presence_qr_signin': 0, 'ews_feature_breaks': 0, 'ews_breaks_per_day': 2,
            'ews_grace_period': 10, 'ews_allow_overnight_shift': 0, 'ews_feature_achievements': 0, **options}
    php("update_option('ews_working_hours',['start'=>'%s','end'=>'%s'],false);" % (start, end)
        + ''.join("update_option('%s',%s,false);" % (k, json.dumps(v)) for k, v in opts.items())
        + "foreach(['ews_company_calendar','ews_break_sessions','ews_audit_log','ews_notifications','ews_employee_achievements'] as $t)$wpdb->query(\"DELETE FROM {$p}$t\");"
        + "$wpdb->query(\"DELETE FROM {$p}ews_achievements WHERE slug LIKE 'parity-%'\");"
        + "delete_option('ews_shifts');"
        + "$wpdb->update($p.'ews_schedule',['status'=>'%s'],['employee_id'=>%d]);" % (status, ids()['eid'])
        + "foreach((array)_get_cron_array() as $ts=>$hooks){foreach(['ews_break_duration_reminder','ews_break_manager_escalation'] as $h){if(isset($hooks[$h]))wp_unschedule_hook($h);}}")


LOCAL_OFFSET = [0.0]


def offset_for(local_minutes):
    """UTC offset (hours) that makes the site's time of day `local_minutes` after midnight now."""
    u = utc_now()
    h = ((local_minutes - (u.hour * 60 + u.minute)) % 1440) / 60.0
    return h - 24 if h > 14 else h


BASE_OFFSET = offset_for(12 * 60)


def local_now():
    return utc_now() + datetime.timedelta(hours=LOCAL_OFFSET[0])


def set_offset(hours):
    LOCAL_OFFSET[0] = hours
    php("update_option('timezone_string','',false); update_option('gmt_offset',%s);" % repr(hours))


# ---------------------------------------------------------------- trace
def snapshot():
    return {
        'log': int(php("echo (int)$wpdb->get_var(\"SELECT COALESCE(MAX(id),0) FROM {$p}ews_time_logs\");")),
        'audit': int(php("echo (int)$wpdb->get_var(\"SELECT COALESCE(MAX(id),0) FROM {$p}ews_audit_log\");")),
        'break': int(php("echo (int)$wpdb->get_var(\"SELECT COALESCE(MAX(id),0) FROM {$p}ews_break_sessions\");")),
        'note': int(php("echo (int)$wpdb->get_var(\"SELECT COALESCE(MAX(id),0) FROM {$p}ews_notifications\");")),
        'award': int(php("echo (int)$wpdb->get_var(\"SELECT COALESCE(MAX(id),0) FROM {$p}ews_employee_achievements\");")),
    }


T = re.compile(r'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}')
CLOCK = re.compile(r'\b\d{1,2}:\d{2} ?[AP]M\b')


def norm(text):
    text = re.sub(r'qr_kiosk_%d\b' % ids()['kid'], 'qr_kiosk_<kiosk>', str(text))
    return CLOCK.sub('<clock>', T.sub('<datetime>', text))


def day_label(d):
    local = local_now().date()
    return {local.isoformat(): 'today', (local - datetime.timedelta(days=1)).isoformat(): 'yesterday'}.get(d, d)


def changes(before):
    I = ids()
    logs = q("SELECT id,user_id,work_date,event_type,scheduled_status,latitude,longitude,accuracy,location_status,ROUND(distance_meters) distance,location_timestamp,integrity_status,integrity_reason,ip_address FROM {p}ews_time_logs WHERE id>%d ORDER BY id" % before['log'])
    log_ids = [int(r['id']) for r in logs]
    breaks = q("SELECT id,work_date,status,actual_minutes,end_at FROM {p}ews_break_sessions WHERE id>%d ORDER BY id" % before['break'])
    break_ids = [int(r['id']) for r in breaks]
    audit = q("SELECT action,entity,entity_id,details,user_id FROM {p}ews_audit_log WHERE id>%d ORDER BY id" % before['audit'])
    notes = q("SELECT user_id,title,message,type,entity,entity_id FROM {p}ews_notifications WHERE id>%d ORDER BY id" % before['note'])
    awards = q("SELECT employee_id,achievement_id FROM {p}ews_employee_achievements WHERE id>%d ORDER BY id" % before['award'])

    def ref(eid):
        eid = int(eid or 0)
        if eid in log_ids:
            return 'new_log#%d' % log_ids.index(eid)
        if eid in break_ids:
            return 'new_break#%d' % break_ids.index(eid)
        return 'zero' if eid == 0 else 'other'

    return {
        'logs': [{'user': 'emp1' if int(r['user_id']) == I['uid'] else r['user_id'], 'work_date': day_label(r['work_date']), 'event': r['event_type'],
                  'scheduled': r['scheduled_status'], 'lat': r['latitude'], 'lng': r['longitude'], 'acc': r['accuracy'], 'location': r['location_status'],
                  'distance': r['distance'], 'has_ts': r['location_timestamp'] is not None, 'integrity': r['integrity_status'], 'reason': r['integrity_reason'],
                  'has_ip': bool(r['ip_address'])} for r in logs],
        'audit': [{'action': r['action'], 'entity': r['entity'], 'ref': ref(r['entity_id']), 'details': norm(r['details']), 'by': 'emp1' if int(r['user_id'] or 0) == I['uid'] else str(r['user_id'])} for r in audit],
        'breaks': [{'work_date': day_label(r['work_date']), 'status': r['status'], 'minutes': r['actual_minutes'], 'ended': r['end_at'] is not None} for r in breaks],
        'notifications': [{'title': r['title'], 'message': norm(r['message']), 'type': r['type'], 'entity': r['entity'], 'ref': ref(r['entity_id'])} for r in notes],
        'awards': len(awards),
    }


def record(scenario, step, message, before, **extra):
    entry = {'scenario': scenario, 'step': step, 'message': norm(message), **changes(before), **extra}
    trace.append(entry)
    return entry


# ---------------------------------------------------------------- the employee's browser
emp = None
NONCES = {}


def login():
    global emp
    emp = Session('emp1', 'emp1pass')
    refresh_nonces()


def refresh_nonces():
    st, page, _ = emp.req('/app/?ews_view=time')
    # Nonces belong to the login session, not to the page state: keep the ones seen earlier
    # (the break forms are only shown while a break is possible).
    for form in re.findall(r'<form.*?</form>', page, re.S):
        a = re.search(r'name="action" value="([^"]+)"', form)
        n = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        t = re.search(r'name="event_type" value="([^"]+)"', form)
        if a and n:
            NONCES[a.group(1) + (':' + t.group(1) if t else '')] = n.group(1)
    m = re.search(r'data-wp-nonce="([^"]+)"', page)
    if m:
        NONCES['rest'] = m.group(1)
    return page


def flash(h):
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    for k in ('time_success', 'time_error', 'break_success', 'break_error'):
        if k in qs:
            return k + ': ' + qs[k][0]
    return 'status-only: ' + h.get('Location', '')[-60:]


def post(data, loc=CAIRO, accuracy='20', ts=None):
    body = dict(data)
    if loc is not None:
        body.update(loc)
        if accuracy is not None:
            body['accuracy'] = accuracy
        body['location_timestamp'] = str(ts if ts is not None else int(time.time() * 1000))
    st, text, h = emp.req('/wp-admin/admin-post.php', body)
    return st, flash(h) if st in (301, 302, 303) else 'http %d: %s' % (st, re.sub(r'<[^>]+>', ' ', text)[:160].strip())


def time_event(scenario, step, kind, loc=CAIRO, accuracy='20', ts=None, **extra):
    before = snapshot()
    st, msg = post({'action': 'ews31_time_event', 'event_type': kind, '_wpnonce': NONCES.get('ews31_time_event:' + kind, ''), **extra}, loc, accuracy, ts)
    return record(scenario, step, msg, before)


def break_action(scenario, step, action):
    before = snapshot()
    st, msg = post({'action': action, '_wpnonce': NONCES.get(action, '')}, loc=None)
    entry = record(scenario, step, msg, before)
    entry['cron'] = json.loads(php("$o=[];foreach((array)_get_cron_array() as $t=>$hooks){foreach(['ews_break_duration_reminder','ews_break_manager_escalation'] as $h){foreach((array)($hooks[$h]??[]) as $e)$o[]=$h;}} sort($o); echo wp_json_encode($o);"))
    return entry


def qr_payload():
    I = ids()
    return emp.req('/?ews_kiosk=%d&kiosk_key=%s&kiosk_payload=1' % (I['kid'], I['secret']))[1]


def face_token():
    """Enrol (once) and verify through the Face REST routes; returns the single-use token."""
    tpl = [0.01 * i for i in range(128)]
    h = {'Content-Type': 'application/json', 'X-WP-Nonce': NONCES.get('rest', '')}
    import urllib.request
    def rest(path, payload):
        r = urllib.request.Request('http://127.0.0.1:8080/wp-json/workforce-one/v1/' + path, data=json.dumps(payload).encode(), headers=h)
        try:
            resp = emp.op.open(r)
            return resp.status, json.loads(resp.read().decode() or 'null')
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode() or 'null')
    rest('face/enroll', {'template': tpl})
    st, body = rest('face/verify', {'template': tpl})
    return (body or {}).get('face_token', '')


def msg(entry):
    return entry['message']


# ================================================================ scenarios
seed()
login()
check('the Sign In page has both forms', 'ews31_time_event:sign_in' in NONCES and 'ews31_time_event:sign_out' in NONCES, NONCES)

# 1. A normal day, with an attendance achievement for the Sign Out.
seed(ews_feature_achievements=1)
php("$wpdb->insert($p.'ews_achievements',['slug'=>'parity-first-day','name'=>'First Day','description'=>'One day','category'=>'attendance','rule_type'=>'streak','threshold'=>1,'active'=>1]);")
e = time_event('normal', 'sign out before sign in', 'sign_out')
check('Sign Out before Sign In is refused', msg(e) == 'time_error: You cannot sign out before signing in.' and not e['logs'], e)
e = time_event('normal', 'sign in (an hour after the start: late)', 'sign_in')
check('Sign In is recorded as Late Arrival with its row and audit', msg(e) == 'time_success: Late Arrival recorded successfully.' and len(e['logs']) == 1 and e['audit'][0]['action'] == 'time_sign_in' and 'Late Arrival' in e['audit'][0]['details'] and e['audit'][0]['ref'] == 'new_log#0', e)
check('...inside, verified, by emp1, with IP and device time', e['logs'][0]['location'] == 'inside' and e['logs'][0]['integrity'] == 'verified' and e['logs'][0]['has_ip'] and e['logs'][0]['has_ts'] and e['logs'][0]['user'] == 'emp1', e['logs'])
e = time_event('normal', 'duplicate sign in', 'sign_in')
check('a second Sign In is refused, nothing written', msg(e) == 'time_error: You have already signed in today.' and not e['logs'] and not e['audit'], e)
e = time_event('normal', 'sign out', 'sign_out')
check('Sign Out is recorded', msg(e) == 'time_success: Sign Out recorded successfully.' and len(e['logs']) == 1 and e['logs'][0]['event'] == 'sign_out', e)
check('...and evaluates attendance achievements (award + notification)', e['awards'] == 1 and any('Achievement' in n['title'] for n in e['notifications']), e)
check('...audit row last (its id is whatever was inserted last, as before)', [a['action'] for a in e['audit']][-1] == 'time_sign_out', e['audit'])
e = time_event('normal', 'duplicate sign out', 'sign_out')
check('a second Sign Out is refused', msg(e) == 'time_error: You have already signed out today.' and not e['logs'], e)

# 2. Grace: one minute inside it is On Time, one minute after it is Late (exact seconds: tests/unit/LatenessTest.php).
seed(start=hhmm(local_now() - datetime.timedelta(minutes=9)))
e = time_event('grace', 'start 9 min ago, grace 10', 'sign_in')
check('Sign In inside the grace is On Time', msg(e) == 'time_success: Sign In recorded successfully.' and 'On Time' in e['audit'][0]['details'], e)
seed(start=hhmm(local_now() - datetime.timedelta(minutes=11)))
e = time_event('grace', 'start 11 min ago, grace 10', 'sign_in')
check('Sign In after the grace is Late Arrival', msg(e) == 'time_success: Late Arrival recorded successfully.', e)
seed(start=hhmm(local_now() + datetime.timedelta(minutes=2)))
if True:
    e = time_event('grace', 'start in two minutes', 'sign_in')
    check('Sign In two minutes before the start is refused (window not open)', msg(e).startswith('time_error: Sign In is not available yet.'), e)

# 3. Schedules: WFH, Vacation (leave), General Leave, attendance off.
seed(status='WFH')
php("$wpdb->update($p.'ews_locations',['enforcement'=>1],['id'=>%d]);" % ids()['lid'])
e = time_event('schedule', 'WFH far from the office, enforcement on', 'sign_in', loc=ALEX)
check('WFH does not require the location (recorded as outside)', msg(e).startswith('time_success:') and e['logs'][0]['location'] == 'outside' and e['logs'][0]['scheduled'] == 'WFH', e)
seed(status='Vacation')
e = time_event('schedule', 'Vacation', 'sign_in')
check('no Sign In on leave', msg(e) == 'time_error: Today is not a working day for you.', e)
seed()
php("$wpdb->insert($p.'ews_company_calendar',['event_date'=>current_time('Y-m-d'),'title'=>'Holiday','event_type'=>'general_leave','active'=>1]);")
e = time_event('schedule', 'General Leave', 'sign_in')
check('no Sign In on General Leave', msg(e) == 'time_error: Today is a General Leave day. Sign In is not required.', e)
seed()
php("$wpdb->update($p.'ews_employees',['attendance_enabled'=>0],['id'=>%d]);" % ids()['eid'])
e = time_event('schedule', 'attendance off', 'sign_in')
check('attendance off for the employee', msg(e) == 'time_error: Attendance tracking is disabled for your employee profile.', e)

# 4. Sign In window: closed (cutoff 4 h after the start).
if True:
    seed(start=hhmm(local_now() - datetime.timedelta(hours=5)))
    e = time_event('window', 'start 5 h ago', 'sign_in')
    check('Sign In after the cutoff is refused', msg(e).startswith('time_error: Sign In is no longer available.'), e)

# 5. Office geofence with enforcement: outside, missing GPS, inside; Sign Out never blocked.
seed()
php("$wpdb->update($p.'ews_locations',['enforcement'=>1],['id'=>%d]);" % ids()['lid'])
e = time_event('geofence', 'outside', 'sign_in', loc=ALEX)
check('outside the enforced location: refused with the distance', msg(e).startswith('time_error: You are outside your assigned work location. Distance:') and not e['logs'], e)
e = time_event('geofence', 'no GPS', 'sign_in', loc={}, accuracy=None)
check('no GPS under enforcement: refused, distance not available', msg(e) == 'time_error: You are outside your assigned work location. Distance: Not available' and not e['logs'], e)
e = time_event('geofence', 'inside', 'sign_in')
check('inside: recorded', msg(e).startswith('time_success:') and e['logs'][0]['location'] == 'inside', e)
e = time_event('geofence', 'sign out far away', 'sign_out', loc=ALEX)
check('Sign Out is not blocked by the geofence (recorded outside, impossible movement)', msg(e) == 'time_success: Sign Out recorded successfully.' and e['logs'][0]['location'] == 'outside' and e['logs'][0]['reason'] == 'impossible_movement', e)
seed()
e = time_event('geofence', 'no GPS, no enforcement', 'sign_in', loc={}, accuracy=None)
check('no GPS without enforcement: recorded as not available', msg(e).startswith('time_success:') and e['logs'][0]['location'] == 'not_available' and e['logs'][0]['reason'] == 'location_not_available', e)
seed()
e = time_event('geofence', 'stale device time', 'sign_in', ts=int(time.time() * 1000) - 3600 * 1000)
check('a stale location is recorded (not blocking on a normal Sign In)', msg(e).startswith('time_success:') and e['logs'][0]['reason'] == 'stale_timestamp', e)
seed()
e = time_event('geofence', 'low accuracy', 'sign_in', accuracy='350')
check('low accuracy is recorded (not blocking)', msg(e).startswith('time_success:') and e['logs'][0]['reason'] == 'low_accuracy', e)

# 6. Face required: no token, a token, the token is single use and consumed even when the Sign In fails.
seed(ews_feature_face_signin=1)
refresh_nonces()
e = time_event('face', 'no token (face_verified flag only)', 'sign_in', face_verified='1')
check('Face required: a flag without a token is refused', msg(e).startswith('time_error: Face verification is required'), e)
e = time_event('face', 'sign out without a token', 'sign_out')
check('Face is not required for Sign Out (normal rules apply)', msg(e) == 'time_error: You cannot sign out before signing in.', e)
tok = face_token()
check('the Face verify route issues a token for a match', bool(tok))
php("$wpdb->update($p.'ews_schedule',['status'=>'Vacation'],['employee_id'=>%d]);" % ids()['eid'])
e = time_event('face', 'valid token on a leave day', 'sign_in', face_token=tok)
check('...a Sign In that fails for another reason', msg(e) == 'time_error: Today is not a working day for you.', e)
php("$wpdb->update($p.'ews_schedule',['status'=>'Office'],['employee_id'=>%d]);" % ids()['eid'])
e = time_event('face', 'the same token again', 'sign_in', face_token=tok)
check('...still used the token up (consumed before the rules run)', msg(e).startswith('time_error: Face verification is required'), e)
tok = face_token()
e = time_event('face', 'fresh token', 'sign_in', face_token=tok)
check('a fresh token signs in, audited face_verified=yes', msg(e).startswith('time_success:') and 'face_verified=yes' in e['audit'][0]['details'], e)

# 7. QR Sign-In: valid inside, outside, no location, invalid code; Face required.
seed(ews_presence_qr_signin=1)
refresh_nonces()
e_before = snapshot()
st, m = post({'action': 'ews_presence_qr_signin', '_wpnonce': NONCES.get('ews_presence_qr_signin', ''), 'qr_payload': 'wfo1|1|2|' + 'a' * 64})
e = record('qr', 'invalid code', m, e_before)
check('an invalid QR is refused', msg(e).startswith('time_error:') and not e['logs'], e)
for step, loc in (('outside', ALEX), ('no location', None)):
    e_before = snapshot()
    st, m = post({'action': 'ews_presence_qr_signin', '_wpnonce': NONCES.get('ews_presence_qr_signin', ''), 'qr_payload': qr_payload()}, loc=loc)
    e = record('qr', step, m, e_before)
    check('a QR Sign In %s is refused' % step, msg(e).startswith('time_error:') and not e['logs'], e)
e_before = snapshot()
st, m = post({'action': 'ews_presence_qr_signin', '_wpnonce': NONCES.get('ews_presence_qr_signin', ''), 'qr_payload': qr_payload()})
e = record('qr', 'valid inside', m, e_before)
check('a valid QR inside the location signs in, audited with the kiosk', msg(e).startswith('time_success:') and 'source=qr_kiosk_<kiosk>' in e['audit'][0]['details'], e)
seed(ews_presence_qr_signin=1, ews_feature_face_signin=1)
refresh_nonces()
e_before = snapshot()
st, m = post({'action': 'ews_presence_qr_signin', '_wpnonce': NONCES.get('ews_presence_qr_signin', ''), 'qr_payload': qr_payload()})
e = record('qr', 'face required, no token', m, e_before)
check('QR Sign-In needs the Face token too', msg(e).startswith('time_error: Face verification is required'), e)

# 8. Breaks.
seed(ews_feature_breaks=1)
time_event('breaks-setup', 'sign in to see the break forms', 'sign_in')
refresh_nonces()
check('the break start form was seen', 'ews_break_start' in NONCES, NONCES)
trace.pop()
seed(ews_feature_breaks=1)
refresh_nonces()
e = break_action('breaks', 'start before sign in', 'ews_break_start')
check('a break needs a Sign In', msg(e) == 'break_error: You must Sign In before starting a break.', e)
time_event('breaks', 'sign in', 'sign_in')
refresh_nonces()
e = break_action('breaks', 'start', 'ews_break_start')
check('a break starts, with its two cron events and audit', msg(e) == 'break_success: Break started successfully.' and len(e['breaks']) == 1 and e['cron'] == ['ews_break_duration_reminder', 'ews_break_manager_escalation'] and e['audit'][0]['ref'] == 'new_break#0', e)
e = break_action('breaks', 'start again', 'ews_break_start')
check('only one open break', msg(e) == 'break_error: You are already on a break.' and not e['breaks'], e)
e = time_event('breaks', 'sign out on break', 'sign_out')
check('no Sign Out during a break', msg(e) == 'time_error: Please Resume Work before signing out.', e)
refresh_nonces()
e = break_action('breaks', 'resume', 'ews_break_resume')
check('resume ends the break, notifies and audits', msg(e).startswith('break_success: Your break ended. Duration: 0 minute') and e['notifications'] and e['notifications'][0]['title'] == 'Break Ended' and e['audit'][0]['action'] == 'break_resume', e)
e = break_action('breaks', 'resume again', 'ews_break_resume')
check('nothing to resume', msg(e) == 'break_error: No open break was found.', e)
refresh_nonces()
break_action('breaks', 'second break', 'ews_break_start')
refresh_nonces()
break_action('breaks', 'resume second', 'ews_break_resume')
refresh_nonces()
e = break_action('breaks', 'third break (2 a day)', 'ews_break_start')
check('the daily break limit applies', msg(e) == 'break_error: No break sessions remain today.', e)
time_event('breaks', 'sign out', 'sign_out')
refresh_nonces()
e = break_action('breaks', 'start after sign out', 'ews_break_start')
check('no break after Sign Out', msg(e) == 'break_error: You have already signed out today.', e)
seed(ews_feature_breaks=1, status='WFH')
php("$wpdb->update($p.'ews_schedule',['status'=>'Vacation'],['employee_id'=>%d]);" % ids()['eid'])
e = break_action('breaks', 'leave day', 'ews_break_start')
check('no break on a non-working day', msg(e) == 'break_error: Breaks are available only on a working day.', e)
php("update_option('ews_feature_breaks',0,false);")
e = break_action('breaks', 'feature off', 'ews_break_start')
check('breaks off', msg(e) == 'break_error: Break Management is currently disabled.', e)

# 9. Overnight shift 22:00-06:00, signing in at 00:30 site time (counts against yesterday).
seed(start='22:00', end='06:00', offset_hours=offset_for(30), ews_allow_overnight_shift=1)
local = local_now().date()
php("$wpdb->query(\"DELETE FROM {$p}ews_schedule WHERE employee_id=%d\"); foreach(['%s','%s'] as $d)$wpdb->insert($p.'ews_schedule',['employee_id'=>%d,'work_date'=>$d,'status'=>'Office']);"
    % (ids()['eid'], (local - datetime.timedelta(days=1)).isoformat(), local.isoformat(), ids()['eid']))
refresh_nonces()
e = time_event('overnight', 'sign in at 00:30', 'sign_in')
check('overnight: Sign In after midnight counts against yesterday and is late', msg(e) == 'time_success: Late Arrival recorded successfully.' and e['logs'] and e['logs'][0]['work_date'] == 'yesterday', e)
e = time_event('overnight', 'sign out at 00:30', 'sign_out')
check('overnight: Sign Out goes to the same day', msg(e) == 'time_success: Sign Out recorded successfully.' and e['logs'] and e['logs'][0]['work_date'] == 'yesterday', e)

# ---------------------------------------------------------------- concurrency (not part of the trace)
seed()
login()
I = ids()
start_at = time.time() + 4
code = ("$at=%f; while(microtime(true)<$at)usleep(500); wp_set_current_user(%d); $_POST=['latitude'=>'30.0445','longitude'=>'31.2358','accuracy'=>'20','location_timestamp'=>(string)(int)(microtime(true)*1000)];"
        "add_filter('wp_redirect',function($l){echo 'REDIRECT '.$l;return false;}); $m=new ReflectionMethod('EWS_Manager_V31_1','record_time_event'); $m->setAccessible(true); $m->invoke(new EWS_Manager_V31_1(),'sign_in',['face_ok'=>false]);") % (start_at, I['uid'])
procs = [subprocess.Popen(WP + ['eval', code], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env={**os.environ, 'S': S}) for _ in range(6)]
outs = [p.communicate(timeout=120) for p in procs]
signins = int(php("echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$p}ews_time_logs WHERE employee_id=%d AND event_type IN ('sign_in','late_sign_in')\");" % I['eid']))
audits = int(php("echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$p}ews_audit_log WHERE action='time_sign_in'\");"))
ok_count = sum(1 for o in outs if 'time_success' in o[0])
dup_count = sum(1 for o in outs if 'already+signed+in' in o[0].replace('%20', '+') or 'already%20signed%20in' in o[0] or 'already signed in' in urllib.parse.unquote(o[0]))
print('concurrency: %d processes, %d Sign In rows, %d audit rows, %d success, %d "already signed in"' % (len(procs), signins, audits, ok_count, dup_count))
check('concurrent duplicate Sign Ins (6 at once) leave exactly one Sign In and one audit row', signins == 1 and audits == 1, (signins, audits, [o[0][-200:] + o[1][-200:] for o in outs]))
check('...the others are told they already signed in', ok_count == 1 and dup_count == len(procs) - 1, [urllib.parse.unquote(o[0])[-120:] for o in outs])

json.dump(trace, open(TRACE_FILE, 'w'), indent=1, ensure_ascii=False)
print('trace: %d steps -> %s' % (len(trace), TRACE_FILE))
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
