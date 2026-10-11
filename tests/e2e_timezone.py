"""Behaviour tests for a site that is not on UTC (Cairo, UTC+3) and for overnight shifts.

K3: the phone's location timestamp is Unix time (UTC). It must be compared with time(), not with
    WordPress local time, or every location on a UTC+3 site looks three hours old: Sign In is
    recorded as "unreliable / stale_timestamp" and QR Sign In is refused.
K4: after midnight, an overnight shift (e.g. 22:00-06:00) still belongs to the day it started, so
    Sign Out finds the Sign In and is recorded on that day.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_timezone.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data and changes the site time zone (restored at the end); never run
against a real site.
"""
import json, os, re, sys, time, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

CAIRO = {'latitude': '30.0445', 'longitude': '31.2358', 'accuracy': '20'}


def setup(shift=None):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    php("""
        update_option('timezone_string','Africa/Cairo'); update_option('gmt_offset','');
        update_option('ews_feature_face_signin',0,false); update_option('ews_presence_qr_signin',1,false); update_option('ews_feature_breaks',0,false);
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $wpdb->query("DELETE FROM {$p}ews_time_logs"); $wpdb->query("DELETE FROM {$p}ews_schedule"); $wpdb->query("DELETE FROM {$p}ews_company_calendar");
        $h=(int)current_time('G'); update_option('ews_working_hours',['start'=>sprintf('%02d:00',max(0,$h-1)),'normal_until'=>'23:59','end'=>'23:59'],false);
        delete_option('ews_shifts'); $wpdb->update($p.'ews_employees',['default_shift_id'=>0],['id'=>$eid]);
        $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>current_time('Y-m-d'),'status'=>'Office']);
    """)


def nonces(sess):
    st, page, _ = sess.req('/app/?ews_view=time')
    out = {}
    for form in re.findall(r'<form.*?</form>', page, re.S):
        a = re.search(r'name="action" value="([^"]+)"', form)
        n = re.search(r'name="_wpnonce" value="([^"]+)"', form)
        t = re.search(r'name="event_type" value="([^"]+)"', form)
        if a and n:
            out[a.group(1) + (':' + t.group(1) if t else '')] = n.group(1)
    return out, page


def post(sess, data):
    st, _, h = sess.req('/wp-admin/admin-post.php', data)
    qs = urllib.parse.parse_qs(urllib.parse.urlparse(h.get('Location', '')).query)
    return (qs.get('time_success') or [''])[0], urllib.parse.unquote((qs.get('time_error') or [''])[0])


def last_log():
    rows = q("SELECT event_type,work_date,integrity_status,integrity_reason FROM {p}ews_time_logs ORDER BY id DESC LIMIT 1")
    return rows[0] if rows else {}


now_ms = lambda: str(int(time.time() * 1000))  # noqa: E731

# ---------------------------------------------------------------- K3: location time on a UTC+3 site
setup()
check('the site runs on Cairo time (UTC+3)', php("echo get_option('timezone_string');").strip() == 'Africa/Cairo')
emp = Session('emp1', 'emp1pass')
n, _ = nonces(emp)
ok, err = post(emp, {'action': 'ews31_time_event', 'event_type': 'sign_in', '_wpnonce': n.get('ews31_time_event:sign_in', ''), 'location_timestamp': now_ms(), **CAIRO})
log = last_log()
check('Sign In with a fresh location is recorded as verified (not "stale_timestamp")', ok and (log.get('integrity_status'), log.get('integrity_reason')) == ('verified', ''), (ok, err, log))

php("$wpdb->query(\"DELETE FROM {$p}ews_time_logs\");")
kid, secret = ids()['kid'], ids()['secret']
st, payload, _ = emp.req('/?ews_kiosk=%s&kiosk_key=%s&kiosk_payload=1' % (kid, secret))
n, _ = nonces(emp)
ok, err = post(emp, {'action': 'ews_presence_qr_signin', '_wpnonce': n.get('ews_presence_qr_signin', ''), 'qr_payload': payload, 'location_timestamp': now_ms(), **CAIRO})
check('QR Sign In at the kiosk location succeeds', ok != '' and not err, (ok, err))

# ---------------------------------------------------------------- K4: Sign Out after midnight
local_h = int(php("echo current_time('G');").strip())
today = php("echo current_time('Y-m-d');").strip()
yesterday = php("echo date('Y-m-d',strtotime(current_time('Y-m-d').' -1 day'));").strip()


def overnight(end):
    """An overnight shift 23:59 → end, and an open Sign In yesterday at 23:59."""
    setup()
    php("""
        $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
        $s=get_option('ews_shifts'); $s=is_array($s)?$s:[]; $s[]=['id'=>88,'name'=>'Night','start'=>'23:59','end'=>'%(end)s','grace'=>10,'overnight'=>1,'active'=>1]; update_option('ews_shifts',$s,false);
        $wpdb->update($p.'ews_employees',['default_shift_id'=>88],['id'=>$eid]);
        $wpdb->query("DELETE FROM {$p}ews_schedule");
        $wpdb->insert($p.'ews_schedule',['employee_id'=>$eid,'work_date'=>'%(y)s','status'=>'Office']);
        $wpdb->insert($p.'ews_time_logs',['employee_id'=>$eid,'user_id'=>1,'work_date'=>'%(y)s','event_type'=>'sign_in','event_at'=>'%(y)s 23:59:00','scheduled_status'=>'Office']);
    """ % {'end': end, 'y': yesterday})


if local_h <= 21:
    # Now is "after midnight" of last night's shift: the shift ends one hour from now.
    overnight('%02d:59' % (local_h + 1))
    emp = Session('emp1', 'emp1pass')
    n, page = nonces(emp)
    check('the Sign In page shows last night\'s shift as signed in', 'ews31_time_event:sign_out' in n, sorted(n))
    ok, err = post(emp, {'action': 'ews31_time_event', 'event_type': 'sign_out', '_wpnonce': n.get('ews31_time_event:sign_out', ''), 'location_timestamp': now_ms(), **CAIRO})
    log = last_log()
    check('Sign Out before the shift end is accepted', ok != '' and not err, (ok, err))
    check('...and recorded on the day the shift started', (log.get('event_type'), log.get('work_date')) == ('sign_out', yesterday), log)
else:
    print('SKIP Sign Out before the shift end (needs the local hour <= 21)')

if 1 <= local_h <= 22:
    # The shift ended an hour ago but was never closed: a late Sign Out still closes it.
    overnight('%02d:00' % (local_h - 1))
    emp = Session('emp1', 'emp1pass')
    n, _ = nonces(emp)
    ok, err = post(emp, {'action': 'ews31_time_event', 'event_type': 'sign_out', '_wpnonce': n.get('ews31_time_event:sign_out', ''), 'location_timestamp': now_ms(), **CAIRO})
    check('a late Sign Out closes last night\'s open shift', ok != '' and not err and last_log().get('work_date') == yesterday, (ok, err, last_log()))
else:
    print('SKIP late Sign Out (needs the local hour between 1 and 22)')

# ---------------------------------------------------------------- old rows saved with the wrong verdict
ms = int(time.time() * 1000)
php("""
    $eid=(int)json_decode(file_get_contents(getenv('S').'/ids.json'))->eid;
    $wpdb->query("DELETE FROM {$p}ews_time_logs");
    $now=current_time('mysql');
    $row=function($ts)use($wpdb,$p,$eid,$now){$wpdb->insert($p.'ews_time_logs',['employee_id'=>$eid,'user_id'=>1,'work_date'=>current_time('Y-m-d'),'event_type'=>'sign_in','event_at'=>$now,'scheduled_status'=>'Office','latitude'=>30.0445,'longitude'=>31.2358,'accuracy'=>20,'location_timestamp'=>$ts,'integrity_status'=>'unreliable','integrity_reason'=>'stale_timestamp']);};
    $row(%d); $row(%d);
    update_option('ews_schema_version','3.31.10',false); delete_option('ews_integrity_repair_done');
""" % (ms, ms - 2 * 3600 * 1000))
Session('admin', 'admin').req('/wp-admin/')
fixed = q("SELECT integrity_status,integrity_reason FROM {p}ews_time_logs ORDER BY id ASC")
check('the upgrade repairs rows wrongly saved as stale, and keeps really stale ones', [(r['integrity_status'], r['integrity_reason']) for r in fixed] == [('verified', ''), ('unreliable', 'stale_timestamp')], fixed)

# ---------------------------------------------------------------- back to UTC for the other tests
php("update_option('timezone_string','UTC'); update_option('gmt_offset',0);")

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
