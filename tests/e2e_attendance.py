"""Behaviour tests for Sign In / Sign Out (record_time_event), run over HTTP against a real site.

They pin down the current rules so refactoring cannot silently change them:
precedence of error messages, sign-in window, geofence enforcement, location
integrity flags, late arrival, breaks, general leave and per-employee opt-out.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_attendance.py <state-dir>
Needs the site seeded by tests/e2e_setup.php (emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, http.cookiejar, json, os, re, shlex, subprocess, sys, time, urllib.parse, urllib.request

B = 'http://127.0.0.1:8080'
S = sys.argv[1]
WP = shlex.split(os.environ.get('WP_CLI', 'wp'))
HERE = os.path.dirname(os.path.abspath(__file__))
CAIRO = {'latitude': '30.0445', 'longitude': '31.2358'}          # ~15 m from the seeded location
ALEX = {'latitude': '31.2001', 'longitude': '29.9187'}           # ~180 km away
results = []


def wp(*args):
    r = subprocess.run(WP + list(args), capture_output=True, text=True, env={**os.environ, 'S': S})
    if r.returncode:
        raise RuntimeError('wp ' + ' '.join(args) + '\n' + r.stderr[-2000:])
    return r.stdout.strip()


def php(code):
    return wp('eval', code)


def check(name, cond, extra=''):
    results.append(bool(cond))
    print(('PASS ' if cond else 'FAIL ') + name + ('' if cond else '  | ' + str(extra)[:300]))


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


cj = http.cookiejar.CookieJar()
op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj), NoRedirect)


def req(path, data=None):
    if isinstance(data, dict):
        data = urllib.parse.urlencode(data).encode()
    try:
        r = op.open(urllib.request.Request(B + path, data=data))
        return r.status, r.read().decode('utf-8', 'replace'), dict(r.headers)
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace'), dict(e.headers)


def now_utc():
    return datetime.datetime.utcnow()


def hhmm(dt):
    return dt.strftime('%H:%M')


def seed(start=None, end='23:59', status='Office', **options):
    """Fresh employee/schedule/location; working hours start 1h ago unless given."""
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    start = start or hhmm(now_utc() - datetime.timedelta(hours=1))
    opts = {'ews_feature_face_signin': 0, 'ews_presence_qr_signin': 0, 'ews_feature_breaks': 0,
            'ews_grace_period': 10, **options}
    php("update_option('ews_working_hours',['start'=>'%s','end'=>'%s'],false);" % (start, end)
        + ''.join("update_option('%s',%s,false);" % (k, json.dumps(v)) for k, v in opts.items())
        + "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}ews_company_calendar\");"
        + "$wpdb->query(\"DELETE FROM {$wpdb->prefix}ews_break_sessions\");"
        + ("$wpdb->update($wpdb->prefix.'ews_schedule',['status'=>'%s'],['employee_id'=>json_decode(file_get_contents(getenv('S').'/ids.json'))->eid]);" % status))


def ids():
    return json.load(open(os.path.join(S, 'ids.json')))


def nonces():
    st, page, _ = req('/app/?ews_view=time')
    out = {}
    for form in re.findall(r'<form.*?</form>', page, re.S):
        a = re.search(r'name="action" value="([^"]+)"', form)
        n = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        t = re.search(r'name="event_type" value="([^"]+)"', form)
        if a and n:
            out[a.group(1) + (':' + t.group(1) if t else '')] = n.group(1)
    return out


NONCES = {}


def event(kind, loc=CAIRO, accuracy='20', ts=None, **extra):
    data = {'action': 'ews31_time_event', 'event_type': kind, '_wpnonce': NONCES['ews31_time_event:' + kind],
            'accuracy': accuracy, 'location_timestamp': str(ts if ts is not None else int(time.time() * 1000)), **loc, **extra}
    if accuracy is None:
        del data['accuracy']
    st, _, h = req('/wp-admin/admin-post.php', data)
    q = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    return (q.get('time_success') or [''])[0], (q.get('time_error') or [''])[0]


def last_log():
    return json.loads(php("global $wpdb; echo wp_json_encode($wpdb->get_row(\"SELECT event_type,location_status,integrity_status,integrity_reason,ROUND(distance_meters) d FROM {$wpdb->prefix}ews_time_logs ORDER BY id DESC LIMIT 1\"));") or 'null')


def log_count():
    return int(php("global $wpdb; echo (int)$wpdb->get_var(\"SELECT COUNT(*) FROM {$wpdb->prefix}ews_time_logs\");"))


# --- login and collect the (session-bound) nonces once -------------------------------------
seed()
req('/wp-login.php')
req('/wp-login.php', {'log': 'emp1', 'pwd': 'emp1pass', 'testcookie': '1'})
NONCES.update(nonces())
check('time page exposes sign-in and sign-out forms', 'ews31_time_event:sign_in' in NONCES and 'ews31_time_event:sign_out' in NONCES, NONCES)

# 1. normal day
ok, err = event('sign_out')
check('sign out before sign in is rejected', err == 'You cannot sign out before signing in.', err)
ok, err = event('sign_in')
check('sign in succeeds (on time within grace? no: started 1h ago -> late)', ok == 'Late Arrival recorded successfully.', (ok, err))
row = last_log()
check('sign-in row: inside, verified, ~15 m', row and row['location_status'] == 'inside' and row['integrity_status'] == 'verified' and int(float(row['d'])) < 50, row)
ok, err = event('sign_in')
check('second sign in is rejected', err == 'You have already signed in today.', err)
ok, err = event('sign_out')
check('sign out succeeds', ok == 'Sign Out recorded successfully.', (ok, err))
ok, err = event('sign_out')
check('second sign out is rejected', err == 'You have already signed out today.', err)

# 2. on time (start 2 minutes ago, grace 10)
seed(start=hhmm(now_utc() - datetime.timedelta(minutes=2)))
ok, err = event('sign_in')
check('sign in within grace period is on time', ok == 'Sign In recorded successfully.', (ok, err))

# 3. not a working day / general leave / attendance disabled
seed(status='Vacation')
ok, err = event('sign_in')
check('no sign in on a non-working schedule', err == 'Today is not a working day for you.', err)
seed()
php("global $wpdb; $wpdb->insert($wpdb->prefix.'ews_company_calendar',['event_date'=>current_time('Y-m-d'),'title'=>'Holiday','event_type'=>'general_leave','active'=>1]);")
ok, err = event('sign_in')
check('no sign in on a General Leave day', err == 'Today is a General Leave day. Sign In is not required.', err)
seed()
php("global $wpdb; $wpdb->update($wpdb->prefix.'ews_employees',['attendance_enabled'=>0],['id'=>%d]);" % ids()['eid'])
ok, err = event('sign_in')
check('attendance disabled for the employee', err == 'Attendance tracking is disabled for your employee profile.', err)

# 4. sign-in window
n = now_utc()
if n.hour <= 21:
    seed(start=hhmm(n + datetime.timedelta(hours=1)))
    ok, err = event('sign_in')
    check('sign in before working hours is rejected', err.startswith('Sign In is not available yet.'), err)
if n.hour >= 5:
    seed(start=hhmm(n - datetime.timedelta(hours=5)))
    ok, err = event('sign_in')
    check('sign in after the 4h cutoff is rejected', err.startswith('Sign In is no longer available.'), err)

# 5. face verification required (message precedence: other errors win)
seed(ews_feature_face_signin=1)
ok, err = event('sign_in', face_verified='1')
check('face required: flag without token is rejected', err.startswith('Face verification is required'), err)
ok, err = event('sign_out')
check('face not required for sign out (falls through to normal rules)', err == 'You cannot sign out before signing in.', err)

# 6. geofence enforcement
seed()
php("global $wpdb; $wpdb->update($wpdb->prefix.'ews_locations',['enforcement'=>1],['id'=>%d]);" % ids()['lid'])
ok, err = event('sign_in', loc=ALEX)
check('enforced geofence rejects a far sign in', err.startswith('You are outside your assigned work location.'), err)
check('rejected sign in writes no row', log_count() == 0, log_count())
ok, err = event('sign_in', loc=CAIRO)
check('enforced geofence accepts a sign in inside', ok.endswith('recorded successfully.'), (ok, err))
ok, err = event('sign_out', loc=ALEX)
check('sign out is never blocked by the geofence', ok == 'Sign Out recorded successfully.', (ok, err))
row = last_log()
check('...but it is recorded as outside and flagged impossible_movement', row['location_status'] == 'outside' and row['integrity_reason'] == 'impossible_movement', row)

# 7. integrity flags (not enforced; recorded only)
seed()
ok, err = event('sign_in', accuracy=None)
check('missing accuracy is recorded as unreliable/missing_accuracy', last_log()['integrity_reason'] == 'missing_accuracy', last_log())
seed()
ok, err = event('sign_in', accuracy='350')
check('low accuracy is recorded as unreliable/low_accuracy', last_log()['integrity_reason'] == 'low_accuracy', last_log())
seed()
ok, err = event('sign_in', ts=int(time.time() * 1000) - 3600 * 1000)
check('stale device timestamp is recorded as stale_timestamp', last_log()['integrity_reason'] == 'stale_timestamp', last_log())
seed()
ok, err = event('sign_in', loc={})
row = last_log()
check('no location: recorded as not_available / location_not_available', row['location_status'] == 'not_available' and row['integrity_reason'] == 'location_not_available', row)

# 8. breaks
seed(ews_feature_breaks=1)
event('sign_in')
NONCES.update(nonces())
st, _, h = req('/wp-admin/admin-post.php', {'action': 'ews_break_start', '_wpnonce': NONCES.get('ews_break_start', '')})
ok, err = event('sign_out')
check('cannot sign out while on break', err == 'Please Resume Work before signing out.', (ok, err, NONCES.get('ews_break_start')))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
