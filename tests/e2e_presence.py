"""Behaviour tests for wp-admin → Presence Kiosks and Presence Verification (a manager asks an
employee to prove they are at a work location by scanning that location's kiosk QR).
The QR Sign In and kiosk security checks live in tests/e2e_presence_face.py.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_presence.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import datetime, hashlib, html, json, os, re, sys, urllib.parse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

KIOSKS = '/wp-admin/admin.php?page=ews31-presence-kiosks'


def seed():
    wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
    out = php("""
        foreach(['ews_presence_verifications','ews_notifications','ews_audit_log'] as $t) $wpdb->query("DELETE FROM {$p}$t");
        update_option('ews_presence_verification',1,false);
        $wpdb->insert($p.'ews_locations',['name'=>'Branch','latitude'=>31.2,'longitude'=>29.9,'radius'=>200,'enforcement'=>0,'is_default'=>0,'active'=>1]);
        echo wp_json_encode(['branch'=>$wpdb->insert_id]);
    """)
    return json.loads(out.splitlines()[-1])


def forms(page, action):
    return [f for f in re.findall(r'<form.*?</form>', page, re.S) if 'name="action" value="%s"' % action in f]


def nonce(form):
    m = re.search(r'name="_wpnonce" value="([^"]+)"', form)
    return m.group(1) if m else ''


def loc(h):
    """The redirect target as a path (still URL-encoded, ready to request)."""
    return html.unescape(h.get('Location', '')).split('127.0.0.1:8080')[-1]


extra = seed()
I = ids()
adm, emp = Session('admin', 'admin'), Session('emp1', 'emp1pass')

# ---------------------------------------------------------------- kiosks
st, page, _ = adm.req(KIOSKS)
check('the Kiosks page lists the kiosk with its display link', st == 200 and 'Reception' in page and 'ews_kiosk=%d' % I['kid'] in page)
create = forms(page, 'ews_presence_kiosk_save')
check('...and offers the active locations for a new kiosk', create and '>Branch</option>' in create[0] and '>Cairo HQ</option>' in create[0])
st, _, h = adm.req('/wp-admin/admin-post.php', {'action': 'ews_presence_kiosk_save', '_wpnonce': nonce(create[0]), 'name': 'Branch Door', 'location_id': extra['branch']})
k = q("SELECT id,credential_hash,status FROM {p}ews_kiosks WHERE name='Branch Door'")
secret = php("echo get_option('ews_presence_kiosk_key_%s','');" % (k[0]['id'] if k else 0)).strip()
check('a kiosk is created with a secret, and only its hash is in the kiosks table', k and k[0]['status'] == 'active' and secret and k[0]['credential_hash'] == hashlib.sha256(secret.encode()).hexdigest(), k)
bk = int(k[0]['id']) if k else 0
check('...the creation is in the Audit Log', q("SELECT details FROM {p}ews_audit_log WHERE action='kiosk_created'")[-1]['details'] == 'Branch Door / location=Branch')
st, body, _ = adm.req('/?ews_kiosk=%d&kiosk_key=%s' % (bk, secret))
check('...and its display opens', st == 200 and 'Branch' in body)
check('the kiosk display loads its script from a file (no inline script)', 'assets/js/kiosk.js' in body and 'function draw(' not in body, body[-600:])
st, body, _ = adm.req('/wp-admin/admin-post.php', {'action': 'ews_presence_kiosk_save', '_wpnonce': nonce(create[0]), 'name': 'Nowhere', 'location_id': 0})
check('a kiosk needs a location', 'Invalid kiosk.' in body and not q("SELECT id FROM {p}ews_kiosks WHERE name='Nowhere'"))
st, body, _ = emp.req(KIOSKS)
check('an employee cannot open the Kiosks page', 'Configured Kiosks' not in body)

# ---------------------------------------------------------------- a presence request
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d' % I['eid'])
rq = forms(page, 'ews_presence_request')
check('the employee profile offers a presence request', bool(rq))
adm.req('/wp-admin/admin-post.php', {'action': 'ews_presence_request', '_wpnonce': nonce(rq[0]) if rq else '', 'employee_id': I['eid'], 'location_id': I['lid']})
r = q("SELECT id,status,location_id,expires_at FROM {p}ews_presence_verifications ORDER BY id DESC LIMIT 1")
rid = int(r[0]['id']) if r else 0
ttl = (datetime.datetime.strptime(r[0]['expires_at'], '%Y-%m-%d %H:%M:%S') - datetime.datetime.utcnow()).total_seconds() if r else 0
check('a request is created, pending, expiring in about 3 minutes', r and r[0]['status'] == 'pending' and 150 <= ttl <= 181, (r, ttl))
note = q("SELECT title FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % I['uid'])
check('...and the employee is notified', note and note[0]['title'] == 'Presence Verification', note)

st, page, _ = emp.req('/app/?ews_view=presence&presence_request=%d' % rid)
check('the employee sees where to scan and how long is left', 'Cairo HQ' in page and 'Expires in' in page and forms(page, 'ews_presence_verify'))
check('...with the scanner script in a file (no inline script)', 'assets/js/presence-scan.js' in page and 'getUserMedia' not in page)
vf = forms(page, 'ews_presence_verify')
vnonce = nonce(vf[0]) if vf else ''


def verify(payload):
    st, _, h = emp.req('/wp-admin/admin-post.php', {'action': 'ews_presence_verify', '_wpnonce': vnonce, 'request_id': rid, 'qr_payload': payload})
    return loc(h)


def qr(kid, key):
    return adm.req('/?ews_kiosk=%d&kiosk_key=%s&kiosk_payload=1' % (kid, key))[1]


l = verify('wfo1|1|2|' + 'a' * 64)
check('an invalid QR is refused', 'presence_error' in l and q("SELECT status FROM {p}ews_presence_verifications WHERE id=%d" % rid)[0]['status'] == 'pending', l)
st, page, _ = emp.req(l)
check('...with a clear message', 'Invalid QR' in page or 'This kiosk is inactive' in page)
l = verify(qr(bk, secret))
st, page, _ = emp.req(l)
check('a QR of another location is refused', 'This QR belongs to a different work location.' in page and q("SELECT status FROM {p}ews_presence_verifications WHERE id=%d" % rid)[0]['status'] == 'pending')
check('failed attempts are in the Audit Log', len(q("SELECT id FROM {p}ews_audit_log WHERE action='presence_verification_failed'")) == 2)
l = verify(qr(I['kid'], I['secret']))
v = q("SELECT status,kiosk_id FROM {p}ews_presence_verifications WHERE id=%d" % rid)[0]
check('the right kiosk\'s QR verifies presence', 'presence_success=1' in l and v['status'] == 'verified' and str(v['kiosk_id']) == str(I['kid']), (l, v))
st, page, _ = emp.req('/app/?ews_view=presence&presence_request=%d' % rid)
check('...and the page shows it as verified', 'Verified' in page and 'Verified at' in page)
l = verify(qr(I['kid'], I['secret']))
st, page, _ = emp.req(l)
check('verifying twice is refused', 'already been processed' in page)

php("$wpdb->insert($p.'ews_presence_verifications',['employee_id'=>%d,'location_id'=>%d,'requested_by'=>1,'status'=>'pending','expires_at'=>gmdate('Y-m-d H:i:s',time()-5),'created_at'=>current_time('mysql')]);" % (I['eid'], I['lid']))
rid = int(q("SELECT id FROM {p}ews_presence_verifications ORDER BY id DESC LIMIT 1")[0]['id'])
l = verify(qr(I['kid'], I['secret']))
st, page, _ = emp.req(l)
check('an expired request cannot be verified', 'This presence request has expired.' in page and q("SELECT status FROM {p}ews_presence_verifications WHERE id=%d" % rid)[0]['status'] == 'expired')

st, page, _ = emp.req('/app/?ews_view=presence&presence_request=%d&presence_error=%s' % (rid, urllib.parse.quote('Your account was hacked, call 0100')))
check('the page shows only its own messages, not text from the link', 'call 0100' not in page)

php("update_option('ews_presence_request_minutes',10,false); $wpdb->query(\"DELETE FROM {$p}ews_notifications\");")
st, page, _ = adm.req('/wp-admin/admin.php?page=ews31-employee-profile&employee_id=%d' % I['eid'])
rq = forms(page, 'ews_presence_request')
adm.req('/wp-admin/admin-post.php', {'action': 'ews_presence_request', '_wpnonce': nonce(rq[0]) if rq else '', 'employee_id': I['eid'], 'location_id': I['lid']})
r = q("SELECT expires_at FROM {p}ews_presence_verifications ORDER BY id DESC LIMIT 1")
ttl = (datetime.datetime.strptime(r[0]['expires_at'], '%Y-%m-%d %H:%M:%S') - datetime.datetime.utcnow()).total_seconds() if r else 0
note = q("SELECT message FROM {p}ews_notifications WHERE user_id=%d ORDER BY id DESC LIMIT 1" % I['uid'])
check('the time to answer follows the setting (10 minutes)', 570 <= ttl <= 601 and note and '10 minutes' in note[0]['message'], (ttl, note))
php("delete_option('ews_presence_request_minutes');")

# ---------------------------------------------------------------- disable a kiosk
st, page, _ = adm.req(KIOSKS)
dis = next((f for f in forms(page, 'ews_presence_kiosk_revoke') if 'name="kiosk_id" value="%d"' % bk in f), '')
adm.req('/wp-admin/admin-post.php', {'action': 'ews_presence_kiosk_revoke', '_wpnonce': nonce(dis), 'kiosk_id': bk})
check('a kiosk can be disabled', q("SELECT status FROM {p}ews_kiosks WHERE id=%d" % bk)[0]['status'] == 'disabled')
check('...its display and its QR stop working', adm.req('/?ews_kiosk=%d&kiosk_key=%s' % (bk, secret))[0] == 403 and qr(bk, secret) == 'denied')
st, page, _ = adm.req(KIOSKS)
row = re.search(r'<strong>Branch Door</strong>.*?</tr>', page, re.S)
check('...and it is listed as Disabled without a display link', row and 'Disabled' in row.group(0) and 'Open Kiosk' not in row.group(0))

print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
