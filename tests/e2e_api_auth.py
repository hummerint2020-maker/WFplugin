"""Security tests for native app authentication (API Phase 0B, 3.31.47): login, access tokens,
rotating refresh tokens with reuse detection, device sessions, login rate limits, revocation, and the
isolation of API tokens from the rest of WordPress (wp-admin, admin-post.php, /wp/v2, Face routes).

The site's wp-config.php must allow HTTP for the API only when the request does not send
X-E2E-Require-Https (CI and the local runner define
WORKFORCE_ONE_API_ALLOW_HTTP as empty($_SERVER['HTTP_X_E2E_REQUIRE_HTTPS'])).

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_api_auth.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, time, urllib.parse, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, Session, check, ids, php, q, results, wp, HERE  # noqa: E402

API = B + '/wp-json/workforce-one/v1'
opener = urllib.request.build_opener(type('NoRedirect', (urllib.request.HTTPRedirectHandler,), {'redirect_request': lambda *a, **k: None}))


def call(method, path, body=None, token=None, header='Authorization', headers=None, url=None):
    h = {'Content-Type': 'application/json'}
    if token is not None:
        h[header] = 'Bearer ' + token
    h.update(headers or {})
    r = urllib.request.Request(url or (API + path), data=json.dumps(body).encode() if body is not None else None, headers=h, method=method)
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


def code(resp):
    st, body, _ = resp
    return (st, body['error']['code']) if isinstance(body, dict) and 'error' in body else (st, 'ok' if isinstance(body, dict) and body.get('success') else body)


DEVICE = {'installation_id': 'e2e-install-0001', 'platform': 'android', 'model': 'Pixel 8', 'app_version': '1.0.0'}


def login(user, pwd, device=None, headers=None):
    return call('POST', '/auth/login', {'username': user, 'password': pwd, 'device': device or DEVICE}, headers=headers)


def tokens(resp):
    return resp[1]['data']['access_token'], resp[1]['data']['refresh_token'], resp[1]['data']['device']['id']


def device_row(public_id):
    rows = q("SELECT revoked_at,revoked_reason FROM {p}ews_api_devices WHERE public_id='%s'" % public_id)
    return rows[0] if rows else None


def audits(action):
    return [r['details'] for r in q("SELECT details FROM {p}ews_audit_log WHERE action='%s' ORDER BY id" % action)]


def clear_limits():
    php("$wpdb->query(\"DELETE FROM {$p}ews_api_rate_limits\");")


# ---------------------------------------------------------------- setup
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
I = ids()
php("""foreach(['ews_api_devices','ews_api_tokens','ews_api_rate_limits','ews_audit_log'] as $t)$wpdb->query("DELETE FROM {$p}$t");
foreach(['emp2','gone','loner','boss','temp'] as $u){ if($id=username_exists($u)){ require_once ABSPATH.'wp-admin/includes/user.php'; wp_delete_user($id); } }
$mk=function($login,$role='subscriber')use($wpdb,$p){$id=wp_create_user($login,$login.'pass',$login.'@example.com');(new WP_User($id))->set_role($role);return $id;};
$e2=$mk('emp2'); $g=$mk('gone'); $mk('loner'); $mk('boss','ews_manager'); $t=$mk('temp');
$wpdb->insert($p.'ews_employees',['name'=>'Emp Two','domain_name'=>'emp2','email'=>'emp2@example.com','wp_user_id'=>$e2,'active'=>1,'attendance_enabled'=>1]);
$wpdb->insert($p.'ews_employees',['name'=>'Gone Away','domain_name'=>'gone','email'=>'gone@example.com','wp_user_id'=>$g,'active'=>0,'attendance_enabled'=>1]);
$wpdb->insert($p.'ews_employees',['name'=>'Temp Worker','domain_name'=>'temp','email'=>'temp@example.com','wp_user_id'=>$t,'active'=>1,'attendance_enabled'=>1]);""")
browser = Session('emp1', 'emp1pass')
st, page, _ = browser.req('/app/?ews_view=time')
check('the Web app works for emp1 in a browser (WordPress login)', st == 200 and 'ews31_time_event' in page)

# ---------------------------------------------------------------- /meta
st, body, h = call('GET', '/meta')
data = body['data'] if st == 200 else {}
check('GET /meta is public and describes the backend', st == 200 and data['product']['name'] == 'Workforce One' and data['api']['version'] == '1' and re.fullmatch(r'[0-9a-f]{8}', data['site']['id'] or '') and data['auth'] == {'type': 'bearer', 'https_required': True, 'access_token_ttl': 900}, body)
flat = json.dumps(body).lower()
check('...without counts, secrets, paths, nonces or platform names', not any(w in flat for w in ('count', 'secret', 'password', 'nonce', 'wp_', 'wp-', 'admin-post', '/home/', 'mysql', 'salt')), flat[:400])
check('...in the envelope, with no-store caching and a request id', body['meta']['api_version'] == '1.0' and h.get('Cache-Control') == 'no-store' and h.get('X-WFO-Request-Id') == body['meta']['request_id'])

# ---------------------------------------------------------------- login
r = login('emp1', 'emp1pass')
check('a linked active employee signs in', code(r) == (200, 'ok'), r[1])
AT, RT, DEV = tokens(r)
site = data['site']['id']
check('...gets an opaque access token and refresh token for this site (no JWT)', re.fullmatch(r'wfo_at_%s_[A-Za-z0-9_-]{43}' % site, AT) and re.fullmatch(r'wfo_rt_%s_[A-Za-z0-9_-]{43}' % site, RT) and AT.count('.') == 0, (AT, RT))
check('...15 minutes / 60 days, with its device session', r[1]['data']['expires_in'] == 900 and 59 * 86400 < r[1]['data']['refresh_expires_in'] <= 60 * 86400 and r[1]['data']['device']['current'] is True and re.fullmatch(r'[0-9a-f]{32}', DEV))
stored = q("SELECT kind,token_hash FROM {p}ews_api_tokens")
check('the database keeps only hashes of the tokens', len(stored) == 2 and all(len(x['token_hash']) == 64 and x['token_hash'] not in (AT, RT) for x in stored) and AT[16:] not in json.dumps(stored) and RT[16:] not in json.dumps(stored), stored)
check('the login is in the Audit Log, without the tokens or the password', len(audits('api_login')) == 1 and 'Pixel 8' in audits('api_login')[0] and AT not in json.dumps(audits('api_login')) and 'emp1pass' not in json.dumps(q("SELECT details FROM {p}ews_audit_log")))
wrong = login('emp1', 'nope')
unknown = login('nobody-here', 'nope')
check('a wrong password and an unknown user get the same answer: 401 INVALID_CREDENTIALS', code(wrong) == code(unknown) == (401, 'INVALID_CREDENTIALS') and wrong[1]['error'] == unknown[1]['error'], (wrong[1], unknown[1]))
check('an archived employee with the right password: 403 ACCOUNT_DISABLED', code(login('gone', 'gonepass')) == (403, 'ACCOUNT_DISABLED'))
check('a WordPress user without an employee or Workforce One role: 403 ACCOUNT_NOT_LINKED', code(login('loner', 'lonerpass')) == (403, 'ACCOUNT_NOT_LINKED'))
boss = login('boss', 'bosspass')
check('a Workforce One manager without an employee record may sign in (administrator path)', code(boss) == (200, 'ok'), boss[1])
check('HTTPS is required (403 HTTPS_REQUIRED) unless the development override allows HTTP', code(login('emp1', 'emp1pass', headers={'X-E2E-Require-Https': '1'})) == (403, 'HTTPS_REQUIRED') and code(call('GET', '/me', token=AT, headers={'X-E2E-Require-Https': '1'})) == (403, 'HTTPS_REQUIRED'))
bad = call('POST', '/auth/login', {'username': 'emp1', 'password': 'emp1pass', 'device': {'platform': 'nokia'}})
check('a login without a valid device is 400 VALIDATION_FAILED with the fields', code(bad) == (400, 'VALIDATION_FAILED') and set(bad[1]['error']['details']['fields']) == {'device.installation_id', 'device.platform', 'device.model', 'device.app_version'}, bad[1])
# Application passwords: switched on for this check only (this test site is HTTP), so the password
# below really works for WordPress's own REST API, and the native login must still refuse it.
php("file_put_contents(WPMU_PLUGIN_DIR.'/e2e-app-passwords.php','<?php add_filter(\"wp_is_application_passwords_available\",\"__return_true\");');")
try:
    app_pw = wp('user', 'application-password', 'create', 'emp1', 'e2e', '--porcelain').strip().splitlines()[-1]
    import base64
    st, body, _ = call('GET', '', url=B + '/wp-json/wp/v2/users/me', headers={'Authorization': 'Basic ' + base64.b64encode(('emp1:' + app_pw).encode()).decode()})
    check('a WordPress application password signs in to /wp/v2 (Basic auth)...', st == 200 and body.get('slug') == 'emp1', (st, body))
    check('...but is not accepted by the native login', code(login('emp1', app_pw)) == (401, 'INVALID_CREDENTIALS'))
finally:
    php("@unlink(WPMU_PLUGIN_DIR.'/e2e-app-passwords.php');")
clear_limits()

# ---------------------------------------------------------------- login rate limits
for _ in range(5):
    login('emp2', 'wrong')
r = login('emp2', 'emp2pass')
check('5 failures for a user name: even the right password is 429 RATE_LIMITED with Retry-After', code(r) == (429, 'RATE_LIMITED') and 840 <= int(r[2].get('Retry-After', 0)) <= 900, (r[1], r[2].get('Retry-After')))
check('...an unknown user name is limited the same way (no hint it does not exist)', [code(login('ghost-user', 'x'))[1] for _ in range(6)][-1] == 'RATE_LIMITED')
check('...other user names are not limited by it', code(login('emp1', 'emp1pass', device={**DEVICE, 'installation_id': 'e2e-install-rate'})) == (200, 'ok'))
locked = audits('api_login_locked')
check('...each lock-out is in the Audit Log once (no passwords)', len(locked) == 2 and 'emp2' in locked[0] and 'wrong' not in json.dumps(locked), locked)
php("$wpdb->query(\"UPDATE {$p}ews_api_rate_limits SET window_start='2000-01-01 00:00:00'\");")
check('after 15 minutes the user name may sign in again', code(login('emp2', 'emp2pass')) == (200, 'ok'))
clear_limits()
for i in range(20):
    login('nobody-%d' % i, 'x')
check('20 failures from one address: every login from it is 429 for 15 minutes', code(login('emp1', 'emp1pass')) == (429, 'RATE_LIMITED') and len(audits('api_login_locked')) == 3)
clear_limits()

# ---------------------------------------------------------------- access tokens and the two headers
r = login('emp1', 'emp1pass')
AT, RT, DEV = tokens(r)
st, me, _ = call('GET', '/me', token=AT)
check('GET /me with the token: who I am, my employee record and this device', st == 200 and me['data']['user']['email'] == 'emp1@example.com' and me['data']['employee']['name'] == 'Emp One' and me['data']['device']['id'] == DEV and me['data']['device']['current'], me)
check('...without WordPress internals (no ids, hashes, logins, sessions, capabilities)', not re.search(r'"(id|ID|user_id|wp_user_id|user_pass|user_login|session|caps|allcaps)"\s*:\s*\d', json.dumps(me['data'])) and 'user_pass' not in json.dumps(me) and '$P$' not in json.dumps(me) and '$wp$' not in json.dumps(me))
check('X-WFO-Authorization works when Authorization is stripped', code(call('GET', '/me', token=AT, header='X-WFO-Authorization')) == (200, 'ok'))
check('Authorization wins when both are sent', code(call('GET', '/me', token='wfo_at_%s_%s' % (site, 'A' * 43), headers={'X-WFO-Authorization': 'Bearer ' + AT})) == (401, 'TOKEN_INVALID') and code(call('GET', '/me', token=AT, headers={'X-WFO-Authorization': 'Bearer junk'})) == (200, 'ok'))
check('no token: 401 TOKEN_MISSING', code(call('GET', '/me')) == (401, 'TOKEN_MISSING'))
check('a token in the query string is ignored', code(call('GET', '/me?access_token=' + AT)) == (401, 'TOKEN_MISSING') and code(call('GET', '', url=API + '/me?Authorization=Bearer%20' + AT)) == (401, 'TOKEN_MISSING'))
check('a token in a cookie is ignored', code(call('GET', '/me', headers={'Cookie': 'Authorization=Bearer ' + AT + '; access_token=' + AT})) == (401, 'TOKEN_MISSING'))
check('a malformed token: 401 TOKEN_INVALID', code(call('GET', '/me', token='abc')) == (401, 'TOKEN_INVALID') and code(call('GET', '/me', headers={'Authorization': 'Basic ' + AT})) == (401, 'TOKEN_MISSING'))
check('a random well-formed token: 401 TOKEN_INVALID', code(call('GET', '/me', token='wfo_at_%s_%s' % (site, 'x' * 43))) == (401, 'TOKEN_INVALID'))
check('a token of another site: 401 TOKEN_INVALID', code(call('GET', '/me', token='wfo_at_00000000_' + AT[16:])) == (401, 'TOKEN_INVALID'))
check('a refresh token is not an access token', code(call('GET', '/me', token=RT)) == (401, 'TOKEN_INVALID'))
dev_id = int(q("SELECT id FROM {p}ews_api_devices WHERE public_id='%s'" % DEV)[0]['id'])
php("$wpdb->query(\"UPDATE {$p}ews_api_tokens SET expires_at='2000-01-01 00:00:00' WHERE device_id=%d AND kind='access'\");" % dev_id)
check('an expired access token: 401 TOKEN_EXPIRED (the app refreshes)', code(call('GET', '/me', token=AT)) == (401, 'TOKEN_EXPIRED'))

# ---------------------------------------------------------------- refresh rotation and reuse
r2 = call('POST', '/auth/refresh', {'refresh_token': RT})
check('refresh gives a new pair', code(r2) == (200, 'ok') and r2[1]['data']['access_token'] != AT and r2[1]['data']['refresh_token'] != RT and r2[1]['data']['device']['id'] == DEV, r2[1])
AT2, RT2, _ = tokens(r2)
check('...the new access token works', code(call('GET', '/me', token=AT2)) == (200, 'ok'))
check('...the previous access token is revoked (401 TOKEN_INVALID)', code(call('GET', '/me', token=AT)) == (401, 'TOKEN_INVALID'))
check('an access token cannot be used to refresh', code(call('POST', '/auth/refresh', {'refresh_token': AT2})) == (401, 'REFRESH_INVALID'))
check('a random refresh token: 401 REFRESH_INVALID', code(call('POST', '/auth/refresh', {'refresh_token': 'wfo_rt_%s_%s' % (site, 'y' * 43)})) == (401, 'REFRESH_INVALID'))
check('one Audit Log row for the first refresh of the day', len(audits('api_refresh')) == 1)
r3 = call('POST', '/auth/refresh', {'refresh_token': RT2})
AT3, RT3, _ = tokens(r3)
check('...and none for the next ones that day', len(audits('api_refresh')) == 1)
reuse = call('POST', '/auth/refresh', {'refresh_token': RT})
check('the first, already used refresh token again: 401 REFRESH_REUSED', code(reuse) == (401, 'REFRESH_REUSED'), reuse[1])
check('...the whole device session ends: its newest tokens stop working', code(call('GET', '/me', token=AT3)) == (401, 'DEVICE_REVOKED') and code(call('POST', '/auth/refresh', {'refresh_token': RT3})) == (401, 'DEVICE_REVOKED') and device_row(DEV)['revoked_reason'] == 'refresh_reuse')
check('...and it is in the Audit Log', len(audits('api_refresh_reuse')) == 1)
r = login('emp1', 'emp1pass')
AT, RT, DEV = tokens(r)
check('signing in again works after a reuse', code(call('GET', '/me', token=AT)) == (200, 'ok'))
dev_id = int(q("SELECT id FROM {p}ews_api_devices WHERE public_id='%s'" % DEV)[0]['id'])
php("$wpdb->query(\"UPDATE {$p}ews_api_tokens SET expires_at='2000-01-01 00:00:00' WHERE device_id=%d AND kind='refresh'\");" % dev_id)
check('an expired refresh token: 401 REFRESH_INVALID', code(call('POST', '/auth/refresh', {'refresh_token': RT})) == (401, 'REFRESH_INVALID'))
php("$wpdb->update($p.'ews_api_devices',['expires_at'=>'2000-01-01 00:00:00'],['id'=>%d]);" % dev_id)
check('after 180 days the session is over whatever the tokens say', code(call('GET', '/me', token=AT)) == (401, 'TOKEN_EXPIRED'))

# ---------------------------------------------------------------- devices
php("$wpdb->query(\"UPDATE {$p}ews_api_devices SET revoked_at=UTC_TIMESTAMP(),revoked_reason='e2e' WHERE revoked_at IS NULL\");")  # start from no signed-in devices
A = tokens(login('emp1', 'emp1pass', device={**DEVICE, 'installation_id': 'e2e-phone-a', 'model': 'Phone A'}))
Bd = tokens(login('emp1', 'emp1pass', device={**DEVICE, 'installation_id': 'e2e-phone-b', 'platform': 'ios', 'model': 'Phone B'}))
E2 = tokens(login('emp2', 'emp2pass', device={**DEVICE, 'installation_id': 'e2e-phone-c', 'model': 'Phone C'}))
st, lst, _ = call('GET', '/me/devices', token=A[0])
mine = {d['model']: d['current'] for d in lst['data']}
check('GET /me/devices lists my active devices and marks this one', st == 200 and mine == {'Phone A': True, 'Phone B': False}, lst)
check('...never another user\'s', 'Phone C' not in json.dumps(lst) and [d['model'] for d in call('GET', '/me/devices', token=E2[0])[1]['data']] == ['Phone C'])
again = tokens(login('emp1', 'emp1pass', device={**DEVICE, 'installation_id': 'e2e-phone-a', 'model': 'Phone A'}))
check('signing in again on the same installation replaces its session', code(call('GET', '/me', token=A[0])) == (401, 'DEVICE_REVOKED') and device_row(A[2])['revoked_reason'] == 'replaced' and code(call('GET', '/me', token=again[0])) == (200, 'ok'))
A = again
check('I cannot sign out another user\'s device: 404 NOT_FOUND, and it stays signed in', code(call('DELETE', '/me/devices/' + E2[2], token=A[0])) == (404, 'NOT_FOUND') and code(call('GET', '/me', token=E2[0])) == (200, 'ok'))
check('...nor a device id that does not exist', code(call('DELETE', '/me/devices/' + 'f' * 32, token=A[0])) == (404, 'NOT_FOUND'))
st, rv, _ = call('DELETE', '/me/devices/' + Bd[2], token=A[0])
check('I can sign out my other device', st == 200 and rv['data'] == {'revoked': True, 'current': False})
check('...its access and refresh tokens stop working at once', code(call('GET', '/me', token=Bd[0])) == (401, 'DEVICE_REVOKED') and code(call('POST', '/auth/refresh', {'refresh_token': Bd[1]})) == (401, 'DEVICE_REVOKED'))
check('...this device keeps working, and it is in the Audit Log', code(call('GET', '/me', token=A[0])) == (200, 'ok') and len(audits('api_device_revoked')) == 1)

# ---------------------------------------------------------------- logout
st, out, _ = call('POST', '/auth/logout', token=A[0])
check('POST /auth/logout ends this device session', st == 200 and out['data'] == {'signed_out': True} and code(call('GET', '/me', token=A[0])) == (401, 'DEVICE_REVOKED') and code(call('POST', '/auth/refresh', {'refresh_token': A[1]})) == (401, 'DEVICE_REVOKED'))
check('...and only it (another user\'s device is untouched)', code(call('GET', '/me', token=E2[0])) == (200, 'ok') and len(audits('api_logout')) == 1)
st, page, _ = browser.req('/app/?ews_view=time')
check('...and not the WordPress login in the browser', st == 200 and 'ews31_time_event' in page)

# ---------------------------------------------------------------- revocation events
C = tokens(login('emp1', 'emp1pass', device={**DEVICE, 'installation_id': 'e2e-phone-pw'}))
wp('user', 'update', 'emp1', '--user_pass=emp1new')
check('a password change ends every session of the user', code(call('GET', '/me', token=C[0])) == (401, 'DEVICE_REVOKED') and device_row(C[2])['revoked_reason'] == 'password_changed' and any('password_changed' in a for a in audits('api_sessions_revoked')))
wp('user', 'update', 'emp1', '--user_pass=emp1pass')
browser = Session('emp1', 'emp1pass')
T = tokens(login('temp', 'temppass'))
wp('user', 'delete', 'temp', '--yes')
check('a deleted WordPress user\'s sessions end', code(call('GET', '/me', token=T[0])) == (401, 'DEVICE_REVOKED') and device_row(T[2])['revoked_reason'] == 'user_deleted')
D = tokens(login('emp2', 'emp2pass', device={**DEVICE, 'installation_id': 'e2e-phone-arch'}))
php("$wpdb->update($p.'ews_employees',['active'=>0],['wp_user_id'=>%d]);" % int(wp('user', 'get', 'emp2', '--field=ID')))
check('an employee made inactive directly in the database is refused on the next request (403 ACCOUNT_DISABLED)', code(call('GET', '/me', token=D[0])) == (403, 'ACCOUNT_DISABLED'))
check('...and cannot refresh (the session ends)', code(call('POST', '/auth/refresh', {'refresh_token': D[1]})) == (403, 'ACCOUNT_DISABLED') and device_row(D[2])['revoked_reason'] == 'account_disabled')
php("$wpdb->update($p.'ews_employees',['active'=>1],['wp_user_id'=>%d]);" % int(wp('user', 'get', 'emp2', '--field=ID')))
F = tokens(login('emp2', 'emp2pass', device={**DEVICE, 'installation_id': 'e2e-phone-arch2'}))
adm = Session('admin', 'admin')
emp2_id = int(q("SELECT id FROM {p}ews_employees WHERE domain_name='emp2'")[0]['id'])
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employees')
form = next((f for f in re.findall(r'<form.*?</form>', page, re.S) if 'value="ews31_employee_archive"' in f and 'name="id" value="%d"' % emp2_id in f), '')
nonce = re.search(r'name="_wpnonce" value="([^"]+)"', form)
adm.req('/wp-admin/admin-post.php', {'action': 'ews31_employee_archive', '_wpnonce': nonce.group(1) if nonce else '', 'id': emp2_id})
check('archiving the employee on the Employees page ends their app sessions', bool(nonce) and device_row(F[2])['revoked_reason'] == 'employee_archived' and code(call('GET', '/me', token=F[0])) == (401, 'DEVICE_REVOKED'))
G = tokens(login('boss', 'bosspass'))
wp('user', 'set-role', 'boss', 'subscriber')
check('a manager without an employee record who loses the role is refused (403 ACCOUNT_NOT_LINKED)', code(call('GET', '/me', token=G[0])) == (403, 'ACCOUNT_NOT_LINKED'))

# ---------------------------------------------------------------- isolation: a token is only for the native API
wp('user', 'set-role', 'boss', 'administrator')
ADM = tokens(login('boss', 'bosspass'))
st, me, _ = call('GET', '/me', token=ADM[0])
check('a WordPress administrator\'s token works on the native API', st == 200 and 'site_administrator' in me['data']['roles'] and 'manage_settings' in me['data']['permissions'])
for hdr in ('Authorization', 'X-WFO-Authorization'):
    st, body, _ = call('GET', '', token=ADM[0], header=hdr, url=B + '/wp-json/wp/v2/users/me')
    check('...but not on /wp/v2 (%s): 401 not logged in' % hdr, st == 401 and body.get('code') == 'rest_not_logged_in', (st, body))
st, body, _ = call('GET', '', token=ADM[0], url=B + '/wp-json/wp/v2/settings')
check('...not on /wp/v2/settings either', st == 401, (st, body))
st, body, h = call('GET', '', token=ADM[0], url=B + '/wp-admin/')
check('...and it does not open wp-admin (redirect to the login page)', st == 302 and 'wp-login.php' in h.get('Location', ''), (st, h.get('Location')))
before = len(q("SELECT id FROM {p}ews_time_logs"))
st, body, h = call('POST', '', token=ADM[0], url=B + '/wp-admin/admin-post.php?action=ews31_time_event', headers={'Content-Type': 'application/x-www-form-urlencoded'})
check('...nor admin-post.php (nothing recorded)', len(q("SELECT id FROM {p}ews_time_logs")) == before and 'recorded successfully' not in str(body), (st, str(body)[:200]))
st, body, _ = call('POST', '/face/verify', {'template': [0.1] * 128}, token=ADM[0])
check('...nor the Web Face routes, which keep WordPress login + nonce', st == 401 and body.get('code') == 'rest_forbidden', (st, body))
st, body, _ = call('POST', '', {'requests': [{'method': 'POST', 'path': '/workforce-one/v1/auth/logout'}, {'method': 'POST', 'path': '/wp/v2/posts', 'body': {'title': 'x'}}]}, token=ADM[0], url=B + '/wp-json/batch/v1')
check('...nor a batch request (native routes are not batchable; /wp/v2 stays anonymous)', code(call('GET', '/me', token=ADM[0])) == (200, 'ok') and not q("SELECT ID FROM {p}posts WHERE post_title='x' AND post_type='post'"), (st, body))
out = php("""$r=new WP_REST_Request('GET','/workforce-one/v1/me'); $r->set_header('Authorization','Bearer %s'); $res=rest_do_request($r); $after=get_current_user_id();
$r2=new WP_REST_Request('GET','/wp/v2/users/me'); $res2=rest_do_request($r2); echo wp_json_encode([$res->get_status(),$after,$res2->get_status()]);""" % ADM[0])
check('...and after a native request the WordPress user is put back (a later request in the same PHP process is anonymous)', json.loads(out.splitlines()[-1]) == [200, 0, 401], out)

# ---------------------------------------------------------------- the Web still works
st, page, _ = browser.req('/app/?ews_view=time')
check('the browser session (WordPress cookies) is unaffected by all of this', st == 200 and 'ews31_time_event' in page)
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31')
check('...and wp-admin still works with WordPress login', st == 200 and 'Employee Schedule Dashboard' in page)
wp('user', 'set-role', 'boss', 'ews_manager')

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
