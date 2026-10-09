"""Behaviour tests for the 3.31.46 API foundation: the Face verify route no longer returns the match
distance and limits failed attempts, the REST routes come from one versioned table, and the
AttendanceService's concurrency guard and idempotency keys work against the real database
(MySQL GET_LOCK in CI; the check after the insert where there is no lock, e.g. SQLite).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_api_foundation.py <state-dir>
Needs tests/e2e_setup.php users (emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, subprocess, sys, time, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, S, WP, Session, check, ids, php, q, results, wp, HERE  # noqa: E402


def seed(**options):
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    h = int(php("echo (int)current_time('G');"))
    opts = {'ews_feature_face_signin': 1, 'ews_feature_breaks': 1, 'ews_breaks_per_day': 3, **options}
    php("update_option('ews_working_hours',['start'=>'%s','end'=>'23:59'],false);" % ('%02d:00' % max(0, h - 1))
        + ''.join("update_option('%s',%s,false);" % (k, json.dumps(v)) for k, v in opts.items())
        + "foreach(['ews_audit_log','ews_break_sessions','ews_api_idempotency','ews_notifications'] as $t)$wpdb->query(\"DELETE FROM {$p}$t\");"
        + "wp_unschedule_hook('ews_break_duration_reminder'); wp_unschedule_hook('ews_break_manager_escalation');"
        + "delete_user_meta(%d,'ews_face_verify_failures');" % ids()['uid'])
    I.update(ids())  # the setup creates a new employee row each time


I = {}
seed()
emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req('/app/?ews_view=time')
m = re.search(r'data-wp-nonce="([^"]+)"', page)
REST_NONCE = m.group(1) if m else ''
check('the Sign In page carries the REST nonce for Face', bool(REST_NONCE))


def rest(path, payload, method='POST'):
    r = urllib.request.Request(B + '/wp-json/workforce-one/v1/' + path, data=json.dumps(payload).encode() if payload is not None else None,
                               headers={'Content-Type': 'application/json', 'X-WP-Nonce': REST_NONCE}, method=method)
    try:
        resp = emp.op.open(r)
        return resp.status, json.loads(resp.read().decode() or 'null'), dict(resp.headers)
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or 'null'), dict(e.headers)


# ---------------------------------------------------------------- routes
st, index, _ = rest('', None, 'GET')
routes = sorted((index or {}).get('routes', {}).keys()) if isinstance(index, dict) else []
check('the v1 namespace lists the four Face routes, the native auth routes (3.31.47) and the corrections routes (3.31.74), nothing else', routes == sorted(['/workforce-one/v1', '/workforce-one/v1/face/delete', '/workforce-one/v1/face/enroll', '/workforce-one/v1/face/reset-request', '/workforce-one/v1/face/verify',
      '/workforce-one/v1/meta', '/workforce-one/v1/auth/login', '/workforce-one/v1/auth/refresh', '/workforce-one/v1/auth/logout', '/workforce-one/v1/me', '/workforce-one/v1/me/devices', '/workforce-one/v1/me/devices/(?P<id>[a-f0-9]{32})',
      '/workforce-one/v1/corrections', '/workforce-one/v1/corrections/days', '/workforce-one/v1/corrections/pending', '/workforce-one/v1/corrections/(?P<id>\\d+)/decision']), routes)
anon = urllib.request.build_opener()
try:
    anon.open(urllib.request.Request(B + '/wp-json/workforce-one/v1/face/verify', data=b'{}', headers={'Content-Type': 'application/json'}))
    anon_status = 200
except urllib.error.HTTPError as e:
    anon_status = e.code
check('...and still need a signed-in user', anon_status == 401, anon_status)

# ---------------------------------------------------------------- Face: no oracle, limited attempts
tpl = [0.01 * i for i in range(128)]
far = [0.01 * i + 0.2 for i in range(128)]
st, body, _ = rest('face/enroll', {'template': tpl})
check('enrolment works as before', st == 200 and body == {'ok': True}, (st, body))
st, body, _ = rest('face/verify', {'template': tpl})
check('a match returns ok and a token, nothing else', st == 200 and set(body) == {'ok', 'face_token'} and body['ok'] is True and len(body['face_token']) == 40, body)
st, body, _ = rest('face/verify', {'template': far})
check('a mismatch returns only ok:false (no distance, no threshold)', st == 200 and body == {'ok': False}, body)
st, body, _ = rest('face/verify', {'template': [0.1, 0.2]})
check('a descriptor of the wrong size is still refused (400)', st == 400 and body.get('code') == 'invalid_template', (st, body))
for _ in range(3):
    rest('face/verify', {'template': far})
st, body, h = rest('face/verify', {'template': tpl})
check('after 5 failures even the right face is refused: 429 with Retry-After', st == 429 and body.get('code') == 'rate_limited' and 0 < int(h.get('Retry-After', 0)) <= 600 and 'distance' not in json.dumps(body), (st, body, h.get('Retry-After')))
locked = q("SELECT details FROM {p}ews_audit_log WHERE action='face_verify_locked'")
check('...and the lock-out is in the Audit Log once', len(locked) == 1 and '5 failed Face verifications' in locked[0]['details'], locked)
php("update_user_meta(%d,'ews_face_verify_failures',['since'=>time()-601,'failures'=>5]);" % I['uid'])
st, body, _ = rest('face/verify', {'template': tpl})
check('after 10 minutes verification works again, and a success clears the count', st == 200 and body.get('ok') is True and php("echo wp_json_encode(get_user_meta(%d,'ews_face_verify_failures',true));" % I['uid']) == '""', (st, body))
js = open(os.path.join(HERE, '..', 'workforce-one', 'assets', 'js', 'workforce-one.js'), encoding='utf-8').read()
check('the Sign In page script no longer reads or shows a distance', 'vj.distance' not in js and 'match distance' not in js)

# ---------------------------------------------------------------- AttendanceService on the real database
seed(ews_feature_face_signin=0)
SERVICE = ("wp_set_current_user(%d); $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod('EWS_Manager_V31_1','attendance_service'); $m->setAccessible(true); $svc=$m->invoke($o);" % I['uid'])


def service(code):
    return json.loads(php(SERVICE + code) or 'null')


TS = int(time.time() * 1000)  # a retry resends the same request, device time included


def command(key=None, lat='30.0445'):
    return ("$c=new WorkforceOne\\Attendance\\AttendanceCommand('sign_in'); $c->latitude=%s; $c->longitude=31.2358; $c->accuracy=20.0; $c->locationTimestampMs=%d; %s"
            % (lat, TS, ("$c->idempotencyKey='%s';" % key) if key else ''))


r1 = service(command('retry-1') + "$r=$svc->signIn($c); echo wp_json_encode(['ok'=>$r->ok,'code'=>$r->code,'id'=>$r->details['event_id']??null,'replayed'=>$r->replayed]);")
r2 = service(command('retry-1') + "$r=$svc->signIn($c); echo wp_json_encode(['ok'=>$r->ok,'code'=>$r->code,'id'=>$r->details['event_id']??null,'replayed'=>$r->replayed]);")
rows = q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d AND event_type IN ('sign_in','late_sign_in')" % I['eid'])
check('the same idempotency key twice: one Sign In, the retry gets the first result', r1['ok'] and r2['ok'] and r2['replayed'] and r1['id'] == r2['id'] and len(rows) == 1, (r1, r2, rows))
r3 = service(command('retry-1', lat='30.1') + "$r=$svc->signIn($c); echo wp_json_encode(['code'=>$r->code]);")
check('the same key with a different request is a conflict', r3['code'] == 'idempotency_conflict', r3)
stored = q("SELECT scope,LENGTH(key_hash) l,response FROM {p}ews_api_idempotency")
check('keys are stored hashed, with the result', len(stored) == 1 and stored[0]['scope'] == 'attendance.sign_in' and int(stored[0]['l']) == 64 and '"ok":true' in stored[0]['response'], stored)
php("$wpdb->query(\"UPDATE {$p}ews_api_idempotency SET created_at='2000-01-01 00:00:00'\"); $o=new EWS_Manager_V31_1(); $o->cleanup_notifications();")
check('the daily cleanup removes keys older than 24 hours', not q("SELECT id FROM {p}ews_api_idempotency"))


def race(code, n=6):
    """Runs `code` in n PHP processes that start at the same moment."""
    at = time.time() + 4
    full = "$at=%f; while(microtime(true)<$at)usleep(500); %s" % (at, SERVICE + code)
    procs = [subprocess.Popen(WP + ['eval', 'global $wpdb; $p=$wpdb->prefix; ' + full], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env={**os.environ, 'S': S}) for _ in range(n)]
    return [p.communicate(timeout=120)[0].strip() for p in procs]


seed(ews_feature_face_signin=0)
outs = race(command('same-key') + "$r=$svc->signIn($c); echo $r->code;")
rows = q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d" % I['eid'])
check('6 concurrent Sign Ins with the same key: one row; the others replay it or are told it is in progress', len(rows) == 1 and outs.count('ok') >= 1 and all(o in ('ok', 'in_progress', 'already_signed_in') for o in outs), (outs, rows))

seed(ews_feature_face_signin=0)
service(command() + "$svc->signIn($c); echo 'null';")
outs = race("$r=$svc->startBreak(); echo $r->code;")
open_breaks = q("SELECT id FROM {p}ews_break_sessions WHERE employee_id=%d AND status='Open'" % I['eid'])
cron = int(php("$n=0;foreach((array)_get_cron_array() as $t=>$h){$n+=count((array)($h['ews_break_duration_reminder']??[]));} echo $n;"))
check('6 concurrent break starts: one open break, one reminder, the others "already on a break"', len(open_breaks) == 1 and outs.count('ok') == 1 and outs.count('already_on_break') == 5 and cron == 1, (outs, open_breaks, cron))
outs = race("$r=$svc->resumeBreak(); echo $r->code;")
audits = q("SELECT id FROM {p}ews_audit_log WHERE action='break_resume'")
notes = q("SELECT id FROM {p}ews_notifications WHERE user_id=%d AND title='Break Ended'" % I['uid'])
check('6 concurrent resumes: the break ends once (one audit row, one notification)', outs.count('ok') == 1 and outs.count('no_open_break') == 5 and len(audits) == 1 and len(notes) == 1, (outs, audits, notes))

seed(ews_feature_face_signin=0)
service(command() + "$svc->signIn($c); echo 'null';")
outs = race("$c=new WorkforceOne\\Attendance\\AttendanceCommand('sign_out'); $r=$svc->signOut($c); echo $r->code;")
outs_rows = q("SELECT id FROM {p}ews_time_logs WHERE employee_id=%d AND event_type='sign_out'" % I['eid'])
check('6 concurrent Sign Outs: one row', len(outs_rows) == 1 and outs.count('ok') == 1 and outs.count('already_signed_out') == 5, (outs, outs_rows))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
