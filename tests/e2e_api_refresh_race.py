"""Refresh-token race (3.31.114, docs/tasks/refresh-race.md), with "Safer app sign-in refresh" on (off by default; off is the
3.31.111 behaviour, which tests/e2e_api_auth.py keeps covering).

Serial checks on the normal server (8080): a used refresh token is accepted up to 4 more times within 30 s and a 6th use ends the
device; 31 s after its use it is a reuse; a logout or a password change during the grace is DEVICE_REVOKED (no reuse in the Audit
Log); a disabled account is ACCOUNT_DISABLED; a database error is 500 and never ends the session; a rotation revokes only the access
tokens issued before the rotated refresh token; switched off, the third use is a reuse as before.
Concurrent checks on a multi-worker server (WFO_RACE_URL, default http://127.0.0.1:8081, started with PHP_CLI_SERVER_WORKERS=6):
2, 3 and 5 refreshes with one token at the same moment all succeed and the device stays; 6 give exactly five pairs and one reuse.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_api_refresh_race.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass). Wipes Workforce One test data; never run against a real site.
"""
import json, os, shutil, sys, threading, time, urllib.error, urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import B, check, php, q, results, wp, HERE  # noqa: E402

RACE = os.environ.get('WFO_RACE_URL', 'http://127.0.0.1:8081')
opener = urllib.request.build_opener(type('NoRedirect', (urllib.request.HTTPRedirectHandler,), {'redirect_request': lambda *a, **k: None}))


def call(method, path, body=None, token=None, headers=None, base=B):
    h = {'Content-Type': 'application/json'}
    if token:
        h['Authorization'] = 'Bearer ' + token
    h.update(headers or {})
    r = urllib.request.Request(base + '/wp-json/workforce-one/v1' + path, data=json.dumps(body).encode() if body is not None else None, headers=h, method=method)
    try:
        resp = opener.open(r, timeout=60)
        status, raw = resp.status, resp.read().decode()
    except urllib.error.HTTPError as e:
        status, raw = e.code, e.read().decode()
    try:
        data = json.loads(raw) if raw else None
    except ValueError:
        data = raw
    return status, data


def code(resp):
    st, body = resp
    return (st, body['error']['code']) if isinstance(body, dict) and 'error' in body else (st, 'ok' if isinstance(body, dict) and body.get('success') else body)


n_login = [0]


def login(user='emp1', pwd='emp1pass'):
    n_login[0] += 1
    r = call('POST', '/auth/login', {'username': user, 'password': pwd, 'device': {'installation_id': 'race-%03d' % n_login[0], 'platform': 'android', 'model': 'Race', 'app_version': '1.0.0'}})
    assert r[0] == 200, r
    d = r[1]['data']
    return d['access_token'], d['refresh_token'], d['device']['id']


def refresh(rt, headers=None, base=B):
    return call('POST', '/auth/refresh', {'refresh_token': rt}, headers=headers, base=base)


def device(public_id):
    return q("SELECT id,revoked_at,revoked_reason FROM {p}ews_api_devices WHERE public_id='%s'" % public_id)[0]


def reuses():
    return int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='api_refresh_reuse'")[0]['n'])


def grace_uses(rt_public):
    return q("SELECT grace_uses,used_at FROM {p}ews_api_tokens WHERE device_id=%d AND kind='refresh' ORDER BY id LIMIT 1" % int(device(rt_public)['id']))[0]


def switch(on):
    php("update_option('ews_feature_api_refresh_safe',%d,false);" % (1 if on else 0))


def clear():
    php("""foreach(['ews_api_devices','ews_api_tokens','ews_api_rate_limits'] as $t)$wpdb->query("DELETE FROM {$p}$t"); $wpdb->query("DELETE FROM {$p}ews_audit_log WHERE action LIKE 'api_%'");""")


# ---------------------------------------------------------------- setup
wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
mu = php("echo WPMU_PLUGIN_DIR;").strip().splitlines()[-1]
os.makedirs(mu, exist_ok=True)
shutil.copy(os.path.join(HERE, 'e2e_refresh_mu.php'), os.path.join(mu, 'ews-e2e-refresh-mu.php'))
clear()
cols = php("global $wpdb; echo wp_json_encode($wpdb->get_col(\"SHOW COLUMNS FROM {$wpdb->prefix}ews_api_tokens\"));").strip().splitlines()[-1]
check('the upgrade adds ews_api_tokens.grace_uses', 'grace_uses' in json.loads(cols), cols)

try:
    # ---------------------------------------------------------------- off: as in 3.31.111
    switch(False)
    at, rt, dev = login()
    check('off: a refresh rotates', code(refresh(rt)) == (200, 'ok'))
    check('off: the same token again within the grace works once', code(refresh(rt)) == (200, 'ok'))
    check('off: the third use is a reuse and ends the device, as before', code(refresh(rt)) == (401, 'REFRESH_REUSED') and device(dev)['revoked_reason'] == 'refresh_reuse')
    check('off: the new column is not touched', int(q("SELECT COALESCE(MAX(grace_uses),0) n FROM {p}ews_api_tokens")[0]['n']) == 0)

    # ---------------------------------------------------------------- on: sequential grace uses
    clear(); switch(True)
    at, rt, dev = login()
    first = refresh(rt)
    check('on: a refresh rotates', code(first) == (200, 'ok'))
    at1 = first[1]['data']['access_token']
    seq = [code(refresh(rt)) for _ in range(4)]
    check('on: the used token is accepted 4 more times within 30 s (retries)', seq == [(200, 'ok')] * 4, seq)
    check('...the device stays, the first pair still works, no reuse in the Audit Log', device(dev)['revoked_at'] is None and code(call('GET', '/me', token=at1)) == (200, 'ok') and reuses() == 0)
    check('...the token counts its 4 grace uses', int(grace_uses(dev)['grace_uses']) == 4)
    check('a 6th use is a reuse: 401 REFRESH_REUSED, the device ends, one Audit Log row', code(refresh(rt)) == (401, 'REFRESH_REUSED') and device(dev)['revoked_reason'] == 'refresh_reuse' and reuses() == 1)

    clear()
    at, rt, dev = login()
    refresh(rt)
    php("$wpdb->query(\"UPDATE {$p}ews_api_tokens SET used_at='%s' WHERE kind='refresh' AND used_at IS NOT NULL\");" % php("echo gmdate('Y-m-d H:i:s',time()-31);").strip().splitlines()[-1])
    check('used 31 s ago: a reuse', code(refresh(rt)) == (401, 'REFRESH_REUSED'))
    clear()
    at, rt, dev = login()
    refresh(rt)
    php("$wpdb->query(\"UPDATE {$p}ews_api_tokens SET used_at='\".gmdate('Y-m-d H:i:s',time()-20).\"' WHERE kind='refresh' AND used_at IS NOT NULL\");")
    check('used 20 s ago: still a retry (the 30 s boundary itself is in the unit test)', code(refresh(rt)) == (200, 'ok'))

    # ---------------------------------------------------------------- the session ended during the grace
    clear()
    at, rt, dev = login()
    r1 = refresh(rt)
    check('logout during the grace...', code(call('POST', '/auth/logout', {}, token=r1[1]['data']['access_token'])) == (200, 'ok'))
    check('...then the used token: 401 DEVICE_REVOKED, not a theft in the Audit Log', code(refresh(rt)) == (401, 'DEVICE_REVOKED') and reuses() == 0)
    clear()
    at, rt, dev = login()
    refresh(rt)
    php("$u=get_user_by('login','emp1'); wp_set_password('emp1pass-new',$u->ID); wp_set_password('emp1pass',$u->ID);")
    check('a password change during the grace: DEVICE_REVOKED, no reuse row', code(refresh(rt)) == (401, 'DEVICE_REVOKED') and reuses() == 0)
    clear()
    at, rt, dev = login()
    refresh(rt)
    php("$wpdb->update($p.'ews_employees',['active'=>0],['domain_name'=>'emp1']);")
    r = refresh(rt)
    php("$wpdb->update($p.'ews_employees',['active'=>1],['domain_name'=>'emp1']);")
    check('an account disabled during the grace: ACCOUNT_DISABLED and the device ends, not a reuse', code(r)[1] == 'ACCOUNT_DISABLED' and device(dev)['revoked_reason'] == 'account_disabled' and reuses() == 0, r)

    # ---------------------------------------------------------------- a database error never ends the session
    clear()
    at, rt, dev = login()
    r = refresh(rt, headers={'X-E2E-Fail-Refresh': 'winner'})
    check('a database error on the rotation: 500 INTERNAL_ERROR, the device stays, no reuse', code(r) == (500, 'INTERNAL_ERROR') and device(dev)['revoked_at'] is None and reuses() == 0, r)
    check('...and the same token works on the retry', code(refresh(rt)) == (200, 'ok'))
    r = refresh(rt, headers={'X-E2E-Fail-Refresh': 'grace'})
    check('a database error on a grace use: 500, the device stays, the use is not counted', code(r) == (500, 'INTERNAL_ERROR') and device(dev)['revoked_at'] is None and int(grace_uses(dev)['grace_uses']) == 0, r)
    r = refresh(rt, headers={'X-E2E-Fail-Refresh': 'gate'})
    check('a database error while checking the account: 500, the device stays (not "not linked")', code(r) == (500, 'INTERNAL_ERROR') and device(dev)['revoked_at'] is None, r)
    r = refresh(rt, headers={'X-E2E-Fail-Refresh': 'issue'})
    check('new tokens that could not be saved are never handed out: 500', code(r) == (500, 'INTERNAL_ERROR') and device(dev)['revoked_at'] is None, r)
    check('...and the next retry gets a working pair', code(refresh(rt)) == (200, 'ok'))

    # ---------------------------------------------------------------- the rotation revokes only older access tokens
    clear()
    at, rt, dev = login()
    d_id = int(device(dev)['id'])
    php("""$wpdb->insert($p.'ews_api_tokens',['device_id'=>%d,'user_id'=>1,'kind'=>'access','token_hash'=>str_repeat('e',64),'expires_at'=>gmdate('Y-m-d H:i:s',time()+900),'created_at'=>gmdate('Y-m-d H:i:s')]);""" % d_id)
    refresh(rt)
    rows = q("SELECT token_hash,revoked_at FROM {p}ews_api_tokens WHERE device_id=%d AND kind='access' ORDER BY id" % d_id)
    check('a rotation revokes the access tokens issued before the refresh token, not one issued after it (a parallel pair)',
          code(call('GET', '/me', token=at)) == (401, 'TOKEN_INVALID') and [r['revoked_at'] is None for r in rows if r['token_hash'] == 'e' * 64] == [True], rows)

    # ---------------------------------------------------------------- truly at the same moment (multi-worker server)
    def burst(rt, n, headers=None):
        barrier, out = threading.Barrier(n), [None] * n

        def go(i):
            barrier.wait()
            out[i] = refresh(rt, headers=headers, base=RACE)
        ts = [threading.Thread(target=go, args=(i,)) for i in range(n)]
        [t.start() for t in ts]
        [t.join() for t in ts]
        return out


    try:
        reachable = call('GET', '/meta', base=RACE)[0] == 200
    except Exception:  # noqa: BLE001
        reachable = False
    if not reachable and os.environ.get('CI'):
        check('the multi-worker server for the concurrent checks is up (%s)' % RACE, False)
    elif not reachable:
        print('SKIP concurrent checks: no multi-worker server at %s' % RACE)
    else:
        for n in (2, 3, 5):
            clear()
            at, rt, dev = login()
            out = burst(rt, n)
            ok = [o for o in out if code(o) == (200, 'ok')]
            works = all(code(call('GET', '/me', token=o[1]['data']['access_token'])) == (200, 'ok') for o in ok)
            follow = code(refresh(ok[0][1]['data']['refresh_token'])) if ok else None
            check('%d refreshes with one token at the same moment: all get a working pair, the device stays, no reuse' % n,
                  len(ok) == n and works and follow == (200, 'ok') and device(dev)['revoked_at'] is None and reuses() == 0, [code(o) for o in out])
        clear()
        at, rt, dev = login()
        out = burst(rt, 6)
        codes = sorted(code(o) for o in out)
        check('6 at the same moment: exactly five pairs and one REFRESH_REUSED, the device ends, one Audit Log row',
              codes == [(200, 'ok')] * 5 + [(401, 'REFRESH_REUSED')] and device(dev)['revoked_reason'] == 'refresh_reuse' and reuses() == 1, codes)
        clear()
        at, rt, dev = login()
        out = burst(rt, 8)
        codes = sorted(code(o)[1] for o in out)
        check('8 at the same moment: five pairs, the device ends, and the theft is in the Audit Log once (not once per refused request)',
              codes.count('ok') == 5 and device(dev)['revoked_reason'] == 'refresh_reuse' and reuses() == 1, (codes, reuses()))
        # Each request waits 1 s on the server: 5 one after another would take at least 5 s; together about 2 s with WordPress itself (under 4 s is accepted).
        clear()
        at, rt, dev = login()
        t0 = time.time()
        out = burst(rt, 5, headers={'X-E2E-Slow': '1'})
        took = time.time() - t0
        check('...and the burst really runs at the same moment (5 requests of 1 s each in %.2f s; one after another would take 5 s or more)' % took, took < 4.0 and all(code(o) == (200, 'ok') for o in out), (took, [code(o) for o in out]))
        php("$wpdb->query(\"DELETE FROM {$p}ews_audit_log WHERE action='api_refresh'\");")
        clear()
        at, rt, dev = login()
        php("$wpdb->query(\"UPDATE {$p}ews_api_devices SET refresh_audited_at=NULL\");")
        burst(rt, 4)
        n_aud = int(q("SELECT COUNT(*) n FROM {p}ews_audit_log WHERE action='api_refresh'")[0]['n'])
        check('...and the first refreshes of the day at the same moment write one api_refresh row', n_aud == 1, n_aud)

finally:
    # ---------------------------------------------------------------- clean up (also when a step raised)
    os.remove(os.path.join(mu, 'ews-e2e-refresh-mu.php'))
    php("delete_option('ews_feature_api_refresh_safe');")
    clear()
print('%d / %d' % (sum(results), len(results)))
sys.exit(0 if all(results) else 1)
