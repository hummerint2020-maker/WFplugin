"""Behaviour tests for Web Push subscriptions and delivery (3.31.45 hardening): only public HTTPS push
services are accepted or contacted (src/Notifications/PushEndpoint.php), checked when subscribing
and again just before each delivery.

Usage: WP_CLI="wp --path=/path/to/wp" python3 tests/e2e_push.py <state-dir>
Needs tests/e2e_setup.php users (admin/admin, emp1/emp1pass) at http://127.0.0.1:8080.
Wipes Workforce One test data; never run against a real site.
"""
import json, os, re, sys, time

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from e2e_support import Session, check, ids, php, q, results, wp, HERE  # noqa: E402

wp('eval-file', os.path.join(HERE, 'e2e_setup.php'))
I = ids()
php("$wpdb->query(\"DELETE FROM {$p}ews_push_subscriptions\"); $wpdb->query(\"DELETE FROM {$p}ews_audit_log\"); delete_option('ews_push_last_delivery');")
# A real P-256 public key and auth secret, so a payload can be encrypted for these test devices.
KEYS = json.loads(php("$k=openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]);$d=openssl_pkey_get_details($k);$raw=\"\\x04\".str_pad($d['ec']['x'],32,\"\\0\",STR_PAD_LEFT).str_pad($d['ec']['y'],32,\"\\0\",STR_PAD_LEFT);$b=function($v){return rtrim(strtr(base64_encode($v),'+/','-_'),'=');};echo wp_json_encode(['p256dh'=>$b($raw),'auth'=>$b(random_bytes(16))]);"))

emp = Session('emp1', 'emp1pass')
st, page, _ = emp.req('/app/?ews_view=notifications')
m = re.search(r'nonce=("[^"]+")', page)
NONCE = json.loads(m.group(1)) if m else ''
check('the Notifications page carries the push subscription nonce', bool(NONCE))


def subscribe(endpoint):
    t = time.time()
    st, body, _ = emp.req('/wp-admin/admin-post.php', {'action': 'ews_push_subscribe', '_wpnonce': NONCE,
                                                       'subscription': json.dumps({'endpoint': endpoint, 'keys': KEYS, 'contentEncoding': 'aes128gcm'})})
    return st, body, time.time() - t


def stored(endpoint):
    return bool(q("SELECT id FROM {p}ews_push_subscriptions WHERE endpoint='%s'" % endpoint.replace("'", "''")))


LEGIT = ['https://fcm.googleapis.com/fcm/send/e2e-test-token', 'https://updates.push.services.mozilla.com/wpush/v2/e2e-test',
         'https://web.push.apple.com/e2e-test', 'https://wns2-par02p.notify.windows.com/w/?token=e2e-test']
for ep in LEGIT:
    st, body, _ = subscribe(ep)
    check('a %s subscription is accepted' % ep.split('/')[2], st == 200 and '"subscribed":true' in body and stored(ep), (st, body[:200]))

BAD = {
    'http (not HTTPS)': 'http://fcm.googleapis.com/fcm/send/x',
    'localhost': 'https://localhost/push',
    '127.0.0.1': 'https://127.0.0.1/push',
    '::1': 'https://[::1]/push',
    'private IPv4 10/8': 'https://10.0.0.5/push',
    'private IPv4 192.168/16': 'https://192.168.1.1/push',
    'cloud metadata 169.254.169.254': 'https://169.254.169.254/latest/meta-data/',
    'private IPv6 fd00::/8': 'https://[fd00::1]/push',
    'link-local IPv6 fe80::': 'https://[fe80::1]/push',
    'IPv4-mapped IPv6 of 127.0.0.1': 'https://[::ffff:127.0.0.1]/push',
    'another port': 'https://fcm.googleapis.com:8443/push',
    'user info': 'https://user@fcm.googleapis.com/push',
    'not a URL': 'not a url',
    'shorthand 127.1': 'https://127.1/push',
}
for name, ep in BAD.items():
    st, body, _ = subscribe(ep)
    check('a %s endpoint is refused and not saved' % name, st == 400 and not stored(ep) and '"subscribed":true' not in body, (st, body[:160]))
check('...none of them is stored', int(q("SELECT COUNT(*) c FROM {p}ews_push_subscriptions")[0]['c']) == len(LEGIT))

# A public-looking host name that resolves to a private address (DNS) is refused too.
resolved = php("echo wp_json_encode(@gethostbynamel('localtest.me.')?:[]);").strip()
if json.loads(resolved or '[]'):
    st, body, _ = subscribe('https://localtest.me/push')
    check('a host name that resolves to 127.0.0.1 (localtest.me) is refused', st == 400 and not stored('https://localtest.me/push'), (st, body[:160]))
else:
    print('SKIP localtest.me does not resolve here')

# Delivery checks again, so rows saved before 3.31.45 cannot reach the inside of the network.
php("""foreach(['http://169.254.169.254/latest','https://127.0.0.1/x','https://[::1]/x'] as $e)$wpdb->insert($p.'ews_push_subscriptions',['user_id'=>%d,'endpoint'=>$e,'endpoint_hash'=>hash('sha256',$e),'p256dh'=>'x','auth'=>'y','content_encoding'=>'aes128gcm','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);""" % I['uid'])
out = json.loads(php("""
    $o=new EWS_Manager_V31_1(); $t=new ReflectionMethod('EWS_Manager_V31_1','push_delivery_target'); $t->setAccessible(true);
    $s=new ReflectionMethod('EWS_Manager_V31_1','send_push_payload'); $s->setAccessible(true);
    $r=[];
    foreach($wpdb->get_results("SELECT * FROM {$p}ews_push_subscriptions WHERE user_id=%d ORDER BY id") as $row){
        $start=microtime(true); $res=$s->invoke($o,$row,['title'=>'t','body'=>'b','url'=>home_url('/')]);
        $r[]=['endpoint'=>$row->endpoint,'target'=>$t->invoke($o,$row->endpoint),'blocked'=>!empty($res['blocked']),'seconds'=>round(microtime(true)-$start,1),'error'=>$res['error']??''];
    }
    echo wp_json_encode($r);""" % I['uid']))
by = {r['endpoint']: r for r in out}
fcm = by.get(LEGIT[0], {})
check('an existing FCM subscription still passes the delivery check (connects to a checked public address)', fcm.get('target', {}).get('ok') and re.match(r'^fcm\.googleapis\.com:443:', fcm['target'].get('resolve', '')) and not fcm['blocked'], fcm)
check('...and every legitimate provider does', all(by[e]['target'].get('ok') for e in LEGIT if e in by), [by.get(e, {}).get('target') for e in LEGIT])
check('...each delivery attempt is bounded (5 s timeout instead of 15 s)', all(r['seconds'] <= 9 for r in out), [(r['endpoint'], r['seconds']) for r in out])
bad = [r for r in out if r['endpoint'] not in LEGIT]
check('stored endpoints to private addresses are never contacted at delivery', len(bad) == 3 and all(r['blocked'] and r['seconds'] < 1 for r in bad), bad)
check('...and are removed, with an Audit Log entry each', not q("SELECT id FROM {p}ews_push_subscriptions WHERE endpoint NOT LIKE 'https://%%.com/%%'") and len(q("SELECT id FROM {p}ews_audit_log WHERE action='push_endpoint_removed'")) == 3)

# 3.31.71: a batch goes out in parallel (curl_multi, 20 at a time); every device gets an outcome.
many = json.loads(php("""
    $k=openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]); $d=openssl_pkey_get_details($k);
    $pub=rtrim(strtr(base64_encode("\\x04".str_pad($d['ec']['x'],32,"\\0",STR_PAD_LEFT).str_pad($d['ec']['y'],32,"\\0",STR_PAD_LEFT)),'+/','-_'),'=');
    $auth=rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'=');
    $wpdb->query("DELETE FROM {$p}ews_push_subscriptions");
    for($i=0;$i<30;$i++){$e='https://fcm.googleapis.com/fcm/send/wfo-load-'.$i;$wpdb->insert($p.'ews_push_subscriptions',['user_id'=>%d,'endpoint'=>$e,'endpoint_hash'=>hash('sha256',$e),'p256dh'=>$pub,'auth'=>$auth,'content_encoding'=>'aes128gcm','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);}
    $o=new EWS_Manager_V31_1(); $m=new ReflectionMethod('EWS_Manager_V31_1','push_send_many'); $m->setAccessible(true);
    $jobs=[]; foreach($wpdb->get_results("SELECT * FROM {$p}ews_push_subscriptions ORDER BY id") as $row)$jobs['d'.$row->id]=[$row,['title'=>'t','body'=>'b','url'=>home_url('/')]];
    $t=microtime(true); $res=$m->invoke($o,$jobs);
    echo wp_json_encode(['jobs'=>count($jobs),'results'=>count($res),'same_keys'=>!array_diff_key($jobs,$res),'seconds'=>round(microtime(true)-$t,1),'sample'=>array_slice($res,0,2)]);""" % I['uid']))
check('a batch to 30 devices returns an outcome for every device, by its key', many['jobs'] == 30 and many['results'] == 30 and many['same_keys'], many)
check('...and takes about one request time, not thirty (sent 20 at a time)', many['seconds'] <= 15, many['seconds'])

# 3.31.87: subscriptions stay current. The browser renews a subscription now and then; the service
# worker reports it (no nonce: the old endpoint is the proof), and the app re-saves its own.
import urllib.request, urllib.parse, urllib.error


def anon_post(fields):
    try:
        r = urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8080/wp-admin/admin-post.php', data=urllib.parse.urlencode(fields).encode()))
        return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def sub_json(endpoint):
    return json.dumps({'endpoint': endpoint, 'keys': KEYS, 'contentEncoding': 'aes128gcm'})


php("$wpdb->query(\"DELETE FROM {$p}ews_push_subscriptions\");")
st, page, _ = emp.req('/app/?ews_view=notifications')
check('the app tells the page the user has no device yet (it re-saves its subscription at once)', 'window.EWS_PUSH_DEVICES=0;' in page and 'function ewsPushSync' in page, re.findall(r'EWS_PUSH_DEVICES=\d+', page))
OLD, NEW = 'https://fcm.googleapis.com/fcm/send/e2e-old', 'https://fcm.googleapis.com/fcm/send/e2e-renewed'
subscribe(OLD)
dev = q("SELECT id,user_id FROM {p}ews_push_subscriptions WHERE endpoint='%s'" % OLD)
st, page, _ = emp.req('/app/?ews_view=notifications')
check('...and that it has one once saved (then it re-saves at most once a day)', 'window.EWS_PUSH_DEVICES=1;' in page)
st, body = anon_post({'action': 'ews_push_resubscribe', 'old_endpoint': OLD, 'subscription': sub_json(NEW)})
row = q("SELECT id,user_id,endpoint FROM {p}ews_push_subscriptions")
check('renewed subscription (service worker, no login): the same device, same user, the new endpoint', st == 200 and len(row) == 1 and dev and row[0]['id'] == dev[0]['id'] and row[0]['user_id'] == dev[0]['user_id'] and row[0]['endpoint'] == NEW, (st, body[:120], row))
st, body = anon_post({'action': 'ews_push_resubscribe', 'old_endpoint': 'https://fcm.googleapis.com/fcm/send/never-seen', 'subscription': sub_json('https://fcm.googleapis.com/fcm/send/e2e-intruder')})
check('...an unknown old endpoint changes nothing (404)', st == 404 and not stored('https://fcm.googleapis.com/fcm/send/e2e-intruder'), (st, body[:120]))
st, body = anon_post({'action': 'ews_push_resubscribe', 'old_endpoint': NEW, 'subscription': sub_json('https://127.0.0.1/push')})
check('...a new endpoint to a private address is refused, the device kept', st == 400 and stored(NEW) and not stored('https://127.0.0.1/push'), (st, body[:120]))
st, body = anon_post({'action': 'ews_push_resubscribe', 'old_endpoint': '', 'subscription': sub_json(OLD)})
check('...no old endpoint is refused', st == 400 and not stored(OLD), st)
subscribe(OLD)  # the app saved the renewed one first, then the service worker reports the change
anon_post({'action': 'ews_push_resubscribe', 'old_endpoint': NEW, 'subscription': sub_json(OLD)})
check('...the same endpoint saved twice ends as one device', int(q("SELECT COUNT(*) c FROM {p}ews_push_subscriptions")[0]['c']) == 1 and stored(OLD))
sw = emp.req('/?ews_pwa=sw')[1]
check('the service worker handles a renewed subscription and knows where to report it', "addEventListener('pushsubscriptionchange'" in sw and 'ews_push_resubscribe' in sw and 'const PUSH_URL=' in sw and 'const VAPID_KEY="' in sw)
ttl = php("$f=file_get_contents(WP_PLUGIN_DIR.'/workforce-one/includes/trait-notifications.php');echo (int)preg_match(\"/'TTL: 86400'/\",$f).(int)preg_match(\"/'TTL: 300'/\",$f);").strip()
check('a push is kept a day for a phone that is asleep or offline (TTL 86400, was 300)', ttl == '10', ttl)

php("$wpdb->query(\"DELETE FROM {$p}ews_push_subscriptions\");")
print(f'{sum(results)} / {len(results)}')
sys.exit(0 if all(results) else 1)
